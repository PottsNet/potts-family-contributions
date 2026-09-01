<?php

declare(strict_types=1);

namespace JasonPotts\WebtreesModules\FamilyContributions;

use DateTimeImmutable;
use DateTimeZone;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\TreeUser;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Webtrees;
use Fisharebest\Webtrees\Http\RequestHandlers\IndividualPage;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Module\ModuleTabInterface;
use Fisharebest\Webtrees\Module\ModuleTabTrait;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\MessageService;
use Illuminate\Database\Schema\Blueprint;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function e;
use function redirect;
use function response;
use function route;
use function view;

final class ContributionExternalUser implements UserInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $email_address,
    ) {
    }

    public function id(): int
    {
        return 0;
    }

    public function email(): string
    {
        return $this->email_address;
    }

    public function realName(): string
    {
        return $this->name;
    }

    public function userName(): string
    {
        return '';
    }

    public function getPreference(string $setting_name, string $default = ''): string
    {
        return $default;
    }

    public function setPreference(string $setting_name, string $setting_value): void
    {
    }
}

return new class extends AbstractModule implements ModuleCustomInterface, ModuleConfigInterface, ModuleMenuInterface, ModuleTabInterface, RequestHandlerInterface
{
    use ModuleCustomTrait;
    use ModuleConfigTrait;
    use ModuleMenuTrait;
    use ModuleTabTrait;

    private const VERSION = '0.9.0-beta.2';
    private const TABLE = 'potts_family_contributions';
    private const ATTACHMENT_TABLE = 'potts_family_contribution_files';
    private const ROUTE_URL = '/tree/{tree}/family-contributions';
    private const DATA_DIR = 'potts-family-contributions';
    private const DEFAULT_MAX_UPLOAD_MB = 10;
    private const MAX_UPLOAD_MB_LIMIT = 25;

    /** @var array<string,string> */
    private const CATEGORIES = [
        'correction'       => 'Correction',
        'new_information' => 'New information',
        'story'            => 'Family story',
        'source'           => 'Source or evidence',
        'photo'            => 'Photograph or document',
        'identification'   => 'Media identification',
        'relationship'     => 'Relationship or family connection',
        'other'            => 'Other',
    ];

    /** @var array<string,string> */
    private const STATUSES = [
        'new'       => 'New',
        'review'    => 'Under review',
        'completed' => 'Completed',
        'rejected'  => 'Rejected or duplicate',
    ];

    /** @var array<string,string> */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
    ];

    public function title(): string
    {
        return I18N::translate('Potts Family Contributions');
    }

    public function tabTitle(): string
    {
        return I18N::translate('Contribute');
    }

    public function description(): string
    {
        return I18N::translate('Let relatives and visitors suggest corrections, share family information, identify media and provide supporting evidence without direct GEDCOM edit access.');
    }

    public function customModuleAuthorName(): string
    {
        return 'Jason Potts';
    }

    public function customModuleVersion(): string
    {
        return self::VERSION;
    }

    public function customModuleLatestVersion(): string
    {
        return Registry::cache()->file()->remember(
            $this->name() . '-latest-version',
            function (): string {
                $latest = trim((string) @file_get_contents($this->customModuleLatestVersionUrl()));

                if (preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?)$/', $latest, $match) === 1) {
                    return $match[1];
                }

                return $this->customModuleVersion();
            },
            86400
        );
    }

    public function customModuleLatestVersionUrl(): string
    {
        return 'https://raw.githubusercontent.com/PottsNet/potts-family-contributions/main/latest-version.txt';
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/PottsNet/potts-family-contributions/issues';
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        if ($this->showFactLinks()) {
            View::registerCustomView('::modules/personal_facts/tab', $this->name() . '::personal-facts-tab');
        }
        if ($this->showFamilyLinks()) {
            View::registerCustomView('::family-page', $this->name() . '::family-page');
            View::registerCustomView('::modules/relatives/tab', $this->name() . '::relatives-tab');
        }
        if ($this->showMediaLinks()) {
            View::registerCustomView('::media-page', $this->name() . '::media-page');
            View::registerCustomView('::modules/media/tab', $this->name() . '::media-tab');
        }

        $route_map = Registry::routeFactory()->routeMap();
        $route_map->get($this->routeName(), self::ROUTE_URL, $this);
        $route_map->post($this->postRouteName(), self::ROUTE_URL, $this);

        $this->ensureSchema();
        $this->ensureAttachmentRoot();
    }

    private function routeName(): string
    {
        return $this->name() . '-route';
    }

    private function postRouteName(): string
    {
        return $this->name() . '-route-post';
    }

    private function enabled(): bool
    {
        return $this->getPreference('enabled', '1') === '1';
    }

    private function allowGuests(): bool
    {
        return $this->getPreference('allow_guests', '1') === '1';
    }

    private function allowAttachments(): bool
    {
        return $this->getPreference('allow_attachments', '1') === '1';
    }

    private function notifyManagers(): bool
    {
        return $this->getPreference('notify_managers', '1') === '1';
    }

    private function acknowledgeContributors(): bool
    {
        return $this->getPreference('acknowledge_contributors', '1') === '1';
    }

    private function showFactLinks(): bool
    {
        return $this->getPreference('show_fact_links', '1') === '1';
    }

    private function showFamilyLinks(): bool
    {
        return $this->getPreference('show_family_links', '1') === '1';
    }

    private function showMediaLinks(): bool
    {
        return $this->getPreference('show_media_links', '1') === '1';
    }

    private function maxUploadMb(): int
    {
        $value = (int) $this->getPreference('max_upload_mb', (string) self::DEFAULT_MAX_UPLOAD_MB);
        return max(1, min(self::MAX_UPLOAD_MB_LIMIT, $value));
    }

    /** @return array<string,string> */
    private function standardCategories(): array
    {
        $categories = self::CATEGORIES;
        unset($categories['identification']);
        return $categories;
    }

    public function defaultTabOrder(): int
    {
        return 95;
    }

    public function hasTabContent(Individual $individual): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        if (method_exists($individual, 'canShow') && !$individual->canShow()) {
            return false;
        }

        return Auth::check() || $this->allowGuests();
    }

    public function isGrayedOut(Individual $individual): bool
    {
        return !$this->hasTabContent($individual);
    }

    public function canLoadAjax(): bool
    {
        return false;
    }

    public function getPreLoadContent(): string
    {
        return '';
    }

    public function getTabContent(Individual $individual): string
    {
        $tree = $individual->tree();
        $user = Auth::user();

        return view($this->name() . '::contribute', [
            'individual'        => $individual,
            'tree'              => $tree,
            'action_url'        => route($this->postRouteName(), ['tree' => $tree->name()]),
            'categories'        => $this->standardCategories(),
            'contributor_name'  => Auth::check() ? $user->realName() : '',
            'contributor_email' => Auth::check() ? $user->email() : '',
            'is_logged_in'      => Auth::check(),
            'allow_attachments' => $this->allowAttachments(),
            'max_upload_mb'     => $this->maxUploadMb(),
            'facts'             => $this->factOptions($individual),
            'selected_fact'     => null,
            'standalone'        => false,
        ]);
    }

    public function defaultMenuOrder(): int
    {
        return 95;
    }

    public function getMenu(Tree $tree): ?Menu
    {
        if (!$this->enabled() || !Auth::isManager($tree)) {
            return null;
        }

        $url = route($this->routeName(), ['tree' => $tree->name()]) . '?manage=1';

        return new Menu(I18N::translate('Contributions'), e($url), 'menu-contributions');
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::settings', [
            'title'                    => I18N::translate('Potts Family Contributions settings'),
            'config_url'               => $this->getConfigLink(),
            'enabled'                  => $this->enabled(),
            'allow_guests'             => $this->allowGuests(),
            'allow_attachments'        => $this->allowAttachments(),
            'notify_managers'          => $this->notifyManagers(),
            'acknowledge_contributors' => $this->acknowledgeContributors(),
            'show_fact_links'          => $this->showFactLinks(),
            'show_family_links'        => $this->showFamilyLinks(),
            'show_media_links'         => $this->showMediaLinks(),
            'max_upload_mb'            => $this->maxUploadMb(),
            'table_ready'              => $this->tableReady(),
            'attachments_ready'        => $this->attachmentTableReady(),
            'attachment_root'          => $this->attachmentRoot(),
            'version'                  => $this->customModuleVersion(),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $max_upload_mb = max(1, min(self::MAX_UPLOAD_MB_LIMIT, (int) ($body['max_upload_mb'] ?? self::DEFAULT_MAX_UPLOAD_MB)));

        $this->setPreference('enabled', isset($body['enabled']) ? '1' : '0');
        $this->setPreference('allow_guests', isset($body['allow_guests']) ? '1' : '0');
        $this->setPreference('allow_attachments', isset($body['allow_attachments']) ? '1' : '0');
        $this->setPreference('notify_managers', isset($body['notify_managers']) ? '1' : '0');
        $this->setPreference('acknowledge_contributors', isset($body['acknowledge_contributors']) ? '1' : '0');
        $this->setPreference('show_fact_links', isset($body['show_fact_links']) ? '1' : '0');
        $this->setPreference('show_family_links', isset($body['show_family_links']) ? '1' : '0');
        $this->setPreference('show_media_links', isset($body['show_media_links']) ? '1' : '0');
        $this->setPreference('max_upload_mb', (string) $max_upload_mb);

        FlashMessages::addMessage(I18N::translate('Potts Family Contributions settings saved.'), 'success');

        return redirect($this->getConfigLink());
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);

        if (!$this->enabled()) {
            return response(I18N::translate('Potts Family Contributions is currently disabled.'))->withStatus(404);
        }
        if (strtoupper($request->getMethod()) === 'POST') {
            $body = $request->getParsedBody();
            $body = is_array($body) ? $body : [];
            $action = (string) ($body['action'] ?? '');

            if ($action === 'submit') {
                return $this->submitContribution($request, $tree, $body);
            }
            if ($action === 'submit_family') {
                return $this->submitFamilyContribution($request, $tree, $body);
            }
            if ($action === 'submit_media') {
                return $this->submitMediaContribution($request, $tree, $body);
            }

            if (in_array($action, ['update', 'delete'], true)) {
                return $this->manageContribution($tree, $body);
            }
        }

        $query = $request->getQueryParams();
        $attachment_id = (int) ($query['attachment'] ?? 0);
        if ($attachment_id > 0) {
            return $this->attachmentResponse($tree, $attachment_id);
        }

        if ((string) ($query['family'] ?? '') === '1') {
            return $this->familyContributionResponse(
                $tree,
                strtoupper(trim((string) ($query['family_xref'] ?? ''))),
                trim((string) ($query['fact_id'] ?? '')),
                strtoupper(trim((string) ($query['return_person_xref'] ?? '')))
            );
        }
        if ((string) ($query['media'] ?? '') === '1') {
            return $this->mediaContributionResponse(
                $tree,
                strtoupper(trim((string) ($query['media_xref'] ?? ''))),
                strtoupper(trim((string) ($query['return_person_xref'] ?? '')))
            );
        }

        if ((string) ($query['suggest'] ?? '') === '1') {
            if (
                isset($query['xref'])
                && !isset($query['person_xref'])
                && trim((string) $query['xref']) !== ''
            ) {
                $clean_url = route($this->routeName(), ['tree' => $tree->name()])
                    . '?suggest=1'
                    . '&person_xref=' . rawurlencode(strtoupper(trim((string) $query['xref'])))
                    . '&fact_record_xref=' . rawurlencode(strtoupper(trim((string) ($query['fact_record_xref'] ?? ''))))
                    . '&fact_id=' . rawurlencode(trim((string) ($query['fact_id'] ?? '')));

                return redirect($clean_url);
            }

            return $this->suggestFactResponse(
                $tree,
                strtoupper(trim((string) ($query['person_xref'] ?? ''))),
                trim((string) ($query['fact_id'] ?? '')),
                strtoupper(trim((string) ($query['fact_record_xref'] ?? '')))
            );
        }

        if ((string) ($query['manage'] ?? '') === '1') {
            return $this->inboxResponse($tree, (string) ($query['status'] ?? ''));
        }

        return $this->viewResponse($this->name() . '::landing', [
            'title' => $this->title(),
            'tree'  => $tree,
        ]);
    }

    /** @param array<string,mixed> $body */
    private function submitContribution(ServerRequestInterface $request, Tree $tree, array $body): ResponseInterface
    {
        $xref = strtoupper(trim((string) ($body['person_xref'] ?? $body['xref'] ?? '')));
        $return_url = $this->individualUrl($tree, $xref);

        if (!Auth::check() && !$this->allowGuests()) {
            FlashMessages::addMessage(I18N::translate('You must sign in before submitting a contribution.'), 'danger');
            return redirect($return_url);
        }

        if (trim((string) ($body['website'] ?? '')) !== '') {
            FlashMessages::addMessage(I18N::translate('Thank you. Your contribution has been received.'), 'success');
            return redirect($return_url);
        }

        $individual = Registry::individualFactory()->make($xref, $tree);
        if (!$individual instanceof Individual || (method_exists($individual, 'canShow') && !$individual->canShow())) {
            FlashMessages::addMessage(I18N::translate('The person linked to this contribution could not be found.'), 'danger');
            return redirect($return_url);
        }

        $category = strtolower(trim((string) ($body['category'] ?? 'other')));
        if (!array_key_exists($category, self::CATEGORIES)) {
            $category = 'other';
        }

        $name = $this->cleanSingleLine((string) ($body['contributor_name'] ?? ''), 255);
        $email = $this->cleanSingleLine((string) ($body['contributor_email'] ?? ''), 255);
        $relationship = $this->cleanSingleLine((string) ($body['relationship'] ?? ''), 255);
        $message = $this->cleanText((string) ($body['message'] ?? ''), 10000);
        $evidence = $this->cleanText((string) ($body['evidence'] ?? ''), 5000);
        $contact_ok = isset($body['contact_ok']) ? 1 : 0;

        $fact_id = $this->cleanSingleLine((string) ($body['fact_id'] ?? ''), 128);
        $fact_record_xref = strtoupper($this->cleanSingleLine((string) ($body['fact_record_xref'] ?? ''), 20));
        $fact_key = $this->cleanSingleLine((string) ($body['fact_key'] ?? ''), 180);
        if ($fact_key !== '' && str_contains($fact_key, '|')) {
            [$key_record_xref, $key_fact_id] = explode('|', $fact_key, 2);
            $fact_record_xref = strtoupper(trim($key_record_xref));
            $fact_id = trim($key_fact_id);
        }
        $fact_context = $fact_id !== '' ? $this->factContext($individual, $fact_id, $fact_record_xref) : null;

        $errors = [];
        if ($name === '') {
            $errors[] = I18N::translate('Please enter your name.');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = I18N::translate('Please enter a valid email address.');
        }
        if (mb_strlen($message) < 5) {
            $errors[] = I18N::translate('Please tell us a little more about your contribution.');
        }
        if ($fact_id !== '' && $fact_context === null) {
            $errors[] = I18N::translate('The fact or event linked to this suggestion could not be found. Please reopen the person and try again.');
        }

        $attachment = null;
        if ($this->allowAttachments()) {
            [$attachment, $attachment_error] = $this->prepareAttachment($request);
            if ($attachment_error !== '') {
                $errors[] = $attachment_error;
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                FlashMessages::addMessage($error, 'danger');
            }
            return redirect($return_url);
        }

        if (!$this->ensureSchema()) {
            FlashMessages::addMessage(I18N::translate('The contribution could not be stored because the module database table is unavailable.'), 'danger');
            return redirect($return_url);
        }

        $now = gmdate('Y-m-d H:i:s');
        $record_name = trim(html_entity_decode(strip_tags($individual->fullName()), ENT_QUOTES, 'UTF-8'));

        $insert = [
            'gedcom_id'        => $tree->id(),
            'xref'             => $xref,
            'record_name'      => mb_substr($record_name, 0, 255),
            'category'         => $category,
            'fact_id'          => $fact_context['id'] ?? null,
            'fact_record_xref' => $fact_context['record_xref'] ?? null,
            'fact_record_type' => $fact_context['record_type'] ?? null,
            'fact_tag'         => $fact_context['tag'] ?? null,
            'fact_label'       => $fact_context['label'] ?? null,
            'fact_summary'     => $fact_context['summary'] ?? null,
            'fact_snapshot'    => $fact_context['gedcom'] ?? null,
            'message'          => $message,
            'evidence'         => $evidence === '' ? null : $evidence,
            'contributor_name' => $name,
            'contributor_email'=> $email,
            'relationship'     => $relationship === '' ? null : $relationship,
            'contact_ok'       => $contact_ok,
            'user_id'          => Auth::id(),
            'status'           => 'new',
            'admin_notes'      => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        if ($this->hasTargetTypeColumn()) {
            $insert['target_type'] = 'INDI';
        }
        $contribution_id = (int) DB::table(self::TABLE)->insertGetId($insert, 'contribution_id');

        $attachment_name = '';
        if (is_array($attachment)) {
            try {
                $attachment_name = $this->storeAttachment($tree, $contribution_id, $attachment);
            } catch (\Throwable) {
                FlashMessages::addMessage(I18N::translate('Your contribution was saved, but the attachment could not be stored.'), 'warning');
            }
        }

        $this->sendSubmissionNotifications(
            tree: $tree,
            contribution_id: $contribution_id,
            record_name: $record_name !== '' ? $record_name : $xref,
            record_type_label: 'Person',
            category: self::CATEGORIES[$category] ?? 'Other',
            fact_summary: is_array($fact_context) ? (string) ($fact_context['summary'] ?? '') : '',
            contributor_name: $name,
            contributor_email: $email,
            relationship: $relationship,
            message: $message,
            evidence: $evidence,
            attachment_name: $attachment_name,
        );

        FlashMessages::addMessage(I18N::translate('Thank you. Your contribution about %s has been received for review.', $record_name !== '' ? $record_name : $xref), 'success');

        return redirect($return_url);
    }

    /** @param array<string,mixed> $body */
    private function submitFamilyContribution(ServerRequestInterface $request, Tree $tree, array $body): ResponseInterface
    {
        $xref = strtoupper(trim((string) ($body['family_xref'] ?? '')));
        $family = Registry::familyFactory()->make($xref, $tree);
        $return_person_xref = strtoupper(trim((string) ($body['return_person_xref'] ?? '')));
        $return_url = $this->individualTabUrl($tree, $return_person_xref, 'relatives')
            ?? ($family instanceof Family ? $family->url() : route($this->routeName(), ['tree' => $tree->name()]));

        if (!Auth::check() && !$this->allowGuests()) {
            FlashMessages::addMessage(I18N::translate('You must sign in before submitting a contribution.'), 'danger');
            return redirect($return_url);
        }

        if (trim((string) ($body['website'] ?? '')) !== '') {
            FlashMessages::addMessage(I18N::translate('Thank you. Your contribution has been received.'), 'success');
            return redirect($return_url);
        }

        if (!$family instanceof Family || (method_exists($family, 'canShow') && !$family->canShow())) {
            FlashMessages::addMessage(I18N::translate('The family linked to this contribution could not be found.'), 'danger');
            return redirect($return_url);
        }

        $category = strtolower(trim((string) ($body['category'] ?? 'other')));
        if (!array_key_exists($category, self::CATEGORIES)) {
            $category = 'other';
        }

        $name = $this->cleanSingleLine((string) ($body['contributor_name'] ?? ''), 255);
        $email = $this->cleanSingleLine((string) ($body['contributor_email'] ?? ''), 255);
        $relationship = $this->cleanSingleLine((string) ($body['relationship'] ?? ''), 255);
        $message = $this->cleanText((string) ($body['message'] ?? ''), 10000);
        $evidence = $this->cleanText((string) ($body['evidence'] ?? ''), 5000);
        $contact_ok = isset($body['contact_ok']) ? 1 : 0;
        $fact_id = $this->cleanSingleLine((string) ($body['fact_id'] ?? ''), 128);
        $fact_context = $fact_id !== '' ? $this->factContextForRecord($family, $fact_id) : null;

        $errors = [];
        if ($name === '') {
            $errors[] = I18N::translate('Please enter your name.');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = I18N::translate('Please enter a valid email address.');
        }
        if (mb_strlen($message) < 5) {
            $errors[] = I18N::translate('Please tell us a little more about your contribution.');
        }
        if ($fact_id !== '' && $fact_context === null) {
            $errors[] = I18N::translate('The family fact linked to this suggestion could not be found. Please reopen the family page and try again.');
        }

        $attachment = null;
        if ($this->allowAttachments()) {
            [$attachment, $attachment_error] = $this->prepareAttachment($request);
            if ($attachment_error !== '') {
                $errors[] = $attachment_error;
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                FlashMessages::addMessage($error, 'danger');
            }
            return redirect($return_url);
        }

        if (!$this->ensureSchema()) {
            FlashMessages::addMessage(I18N::translate('The contribution could not be stored because the module database table is unavailable.'), 'danger');
            return redirect($return_url);
        }

        $now = gmdate('Y-m-d H:i:s');
        $record_name = trim(html_entity_decode(strip_tags($family->fullName()), ENT_QUOTES, 'UTF-8'));

        $insert = [
            'gedcom_id'        => $tree->id(),
            'xref'             => $xref,
            'record_name'      => mb_substr($record_name, 0, 255),
            'category'         => $category,
            'fact_id'          => $fact_context['id'] ?? null,
            'fact_record_xref' => $fact_context['record_xref'] ?? $xref,
            'fact_record_type' => $fact_context['record_type'] ?? 'FAM',
            'fact_tag'         => $fact_context['tag'] ?? null,
            'fact_label'       => $fact_context['label'] ?? null,
            'fact_summary'     => $fact_context['summary'] ?? null,
            'fact_snapshot'    => $fact_context['gedcom'] ?? null,
            'message'          => $message,
            'evidence'         => $evidence === '' ? null : $evidence,
            'contributor_name' => $name,
            'contributor_email'=> $email,
            'relationship'     => $relationship === '' ? null : $relationship,
            'contact_ok'       => $contact_ok,
            'user_id'          => Auth::id(),
            'status'           => 'new',
            'admin_notes'      => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        if ($this->hasTargetTypeColumn()) {
            $insert['target_type'] = 'FAM';
        }
        $contribution_id = (int) DB::table(self::TABLE)->insertGetId($insert, 'contribution_id');

        $attachment_name = '';
        if (is_array($attachment)) {
            try {
                $attachment_name = $this->storeAttachment($tree, $contribution_id, $attachment);
            } catch (\Throwable) {
                FlashMessages::addMessage(I18N::translate('Your contribution was saved, but the attachment could not be stored.'), 'warning');
            }
        }

        $this->sendSubmissionNotifications(
            tree: $tree,
            contribution_id: $contribution_id,
            record_name: $record_name !== '' ? $record_name : $xref,
            record_type_label: 'Family',
            category: self::CATEGORIES[$category] ?? 'Other',
            fact_summary: is_array($fact_context) ? (string) ($fact_context['summary'] ?? '') : '',
            contributor_name: $name,
            contributor_email: $email,
            relationship: $relationship,
            message: $message,
            evidence: $evidence,
            attachment_name: $attachment_name,
        );

        FlashMessages::addMessage(I18N::translate('Thank you. Your contribution about %s has been received for review.', $record_name !== '' ? $record_name : $xref), 'success');

        return redirect($return_url);
    }

    /** @param array<string,mixed> $body */
    private function submitMediaContribution(ServerRequestInterface $request, Tree $tree, array $body): ResponseInterface
    {
        $xref = strtoupper(trim((string) ($body['media_xref'] ?? '')));
        $media = Registry::mediaFactory()->make($xref, $tree);
        $return_person_xref = strtoupper(trim((string) ($body['return_person_xref'] ?? '')));
        $return_url = $this->individualTabUrl($tree, $return_person_xref, 'media')
            ?? ($media instanceof Media ? $media->url() : route($this->routeName(), ['tree' => $tree->name()]));

        if (!Auth::check() && !$this->allowGuests()) {
            FlashMessages::addMessage(I18N::translate('You must sign in before submitting a contribution.'), 'danger');
            return redirect($return_url);
        }

        if (trim((string) ($body['website'] ?? '')) !== '') {
            FlashMessages::addMessage(I18N::translate('Thank you. Your contribution has been received.'), 'success');
            return redirect($return_url);
        }

        if (!$media instanceof Media || (method_exists($media, 'canShow') && !$media->canShow())) {
            FlashMessages::addMessage(I18N::translate('The media item linked to this contribution could not be found.'), 'danger');
            return redirect($return_url);
        }

        $category = strtolower(trim((string) ($body['category'] ?? 'identification')));
        if (!array_key_exists($category, self::CATEGORIES)) {
            $category = 'identification';
        }

        $name = $this->cleanSingleLine((string) ($body['contributor_name'] ?? ''), 255);
        $email = $this->cleanSingleLine((string) ($body['contributor_email'] ?? ''), 255);
        $relationship = $this->cleanSingleLine((string) ($body['relationship'] ?? ''), 255);
        $message = $this->cleanText((string) ($body['message'] ?? ''), 10000);
        $evidence = $this->cleanText((string) ($body['evidence'] ?? ''), 5000);
        $contact_ok = isset($body['contact_ok']) ? 1 : 0;

        $errors = [];
        if ($name === '') {
            $errors[] = I18N::translate('Please enter your name.');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = I18N::translate('Please enter a valid email address.');
        }
        if (mb_strlen($message) < 5) {
            $errors[] = I18N::translate('Please tell us a little more about what you can identify or add.');
        }

        $attachment = null;
        if ($this->allowAttachments()) {
            [$attachment, $attachment_error] = $this->prepareAttachment($request);
            if ($attachment_error !== '') {
                $errors[] = $attachment_error;
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                FlashMessages::addMessage($error, 'danger');
            }
            return redirect($return_url);
        }

        if (!$this->ensureSchema()) {
            FlashMessages::addMessage(I18N::translate('The contribution could not be stored because the module database table is unavailable.'), 'danger');
            return redirect($return_url);
        }

        $now = gmdate('Y-m-d H:i:s');
        $record_name = trim(html_entity_decode(strip_tags($media->fullName()), ENT_QUOTES, 'UTF-8'));

        $insert = [
            'gedcom_id'        => $tree->id(),
            'xref'             => $xref,
            'record_name'      => mb_substr($record_name, 0, 255),
            'category'         => $category,
            'fact_id'          => null,
            'fact_record_xref' => $xref,
            'fact_record_type' => 'OBJE',
            'fact_tag'         => null,
            'fact_label'       => null,
            'fact_summary'     => null,
            'fact_snapshot'    => null,
            'message'          => $message,
            'evidence'         => $evidence === '' ? null : $evidence,
            'contributor_name' => $name,
            'contributor_email'=> $email,
            'relationship'     => $relationship === '' ? null : $relationship,
            'contact_ok'       => $contact_ok,
            'user_id'          => Auth::id(),
            'status'           => 'new',
            'admin_notes'      => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
        if ($this->hasTargetTypeColumn()) {
            $insert['target_type'] = 'OBJE';
        }
        $contribution_id = (int) DB::table(self::TABLE)->insertGetId($insert, 'contribution_id');

        $attachment_name = '';
        if (is_array($attachment)) {
            try {
                $attachment_name = $this->storeAttachment($tree, $contribution_id, $attachment);
            } catch (\Throwable) {
                FlashMessages::addMessage(I18N::translate('Your contribution was saved, but the attachment could not be stored.'), 'warning');
            }
        }

        $this->sendSubmissionNotifications(
            tree: $tree,
            contribution_id: $contribution_id,
            record_name: $record_name !== '' ? $record_name : $xref,
            record_type_label: 'Media',
            category: self::CATEGORIES[$category] ?? 'Other',
            fact_summary: '',
            contributor_name: $name,
            contributor_email: $email,
            relationship: $relationship,
            message: $message,
            evidence: $evidence,
            attachment_name: $attachment_name,
        );

        FlashMessages::addMessage(I18N::translate('Thank you. Your contribution about %s has been received for review.', $record_name !== '' ? $record_name : $xref), 'success');

        return redirect($return_url);
    }

    private function familyContributionResponse(Tree $tree, string $xref, string $fact_id = '', string $return_person_xref = ''): ResponseInterface
    {
        if (!Auth::check() && !$this->allowGuests()) {
            FlashMessages::addMessage(I18N::translate('You must sign in before submitting a contribution.'), 'danger');
            return redirect(route($this->routeName(), ['tree' => $tree->name()]));
        }

        $family = Registry::familyFactory()->make($xref, $tree);
        if (!$family instanceof Family || (method_exists($family, 'canShow') && !$family->canShow())) {
            return response(I18N::translate('The family linked to this contribution could not be found.'))->withStatus(404);
        }

        $selected_fact = $fact_id !== '' ? $this->factContextForRecord($family, $fact_id) : null;
        if ($fact_id !== '' && $selected_fact === null) {
            return response(I18N::translate('The family fact linked to this suggestion could not be found.'))->withStatus(404);
        }

        $user = Auth::user();

        return $this->viewResponse($this->name() . '::contribute-family', [
            'title'             => $selected_fact !== null ? I18N::translate('Suggest a correction') : I18N::translate('Contribute family information'),
            'family'            => $family,
            'tree'              => $tree,
            'action_url'        => route($this->postRouteName(), ['tree' => $tree->name()]),
            'categories'        => $this->standardCategories(),
            'contributor_name'  => Auth::check() ? $user->realName() : '',
            'contributor_email' => Auth::check() ? $user->email() : '',
            'allow_attachments' => $this->allowAttachments(),
            'max_upload_mb'     => $this->maxUploadMb(),
            'facts'             => $this->familyFactOptions($family),
            'selected_fact'     => $selected_fact,
            'return_person_xref'=> $return_person_xref,
            'return_url'        => $this->individualTabUrl($tree, $return_person_xref, 'relatives') ?? $family->url(),
        ]);
    }

    private function mediaContributionResponse(Tree $tree, string $xref, string $return_person_xref = ''): ResponseInterface
    {
        if (!Auth::check() && !$this->allowGuests()) {
            FlashMessages::addMessage(I18N::translate('You must sign in before submitting a contribution.'), 'danger');
            return redirect(route($this->routeName(), ['tree' => $tree->name()]));
        }

        $media = Registry::mediaFactory()->make($xref, $tree);
        if (!$media instanceof Media || (method_exists($media, 'canShow') && !$media->canShow())) {
            return response(I18N::translate('The media item linked to this contribution could not be found.'))->withStatus(404);
        }

        $user = Auth::user();

        return $this->viewResponse($this->name() . '::contribute-media', [
            'title'             => I18N::translate('Identify or contribute information'),
            'media'             => $media,
            'tree'              => $tree,
            'action_url'        => route($this->postRouteName(), ['tree' => $tree->name()]),
            'categories'        => self::CATEGORIES,
            'contributor_name'  => Auth::check() ? $user->realName() : '',
            'contributor_email' => Auth::check() ? $user->email() : '',
            'allow_attachments' => $this->allowAttachments(),
            'max_upload_mb'     => $this->maxUploadMb(),
            'return_person_xref'=> $return_person_xref,
            'return_url'        => $this->individualTabUrl($tree, $return_person_xref, 'media') ?? $media->url(),
        ]);
    }

    /** @return array<int,array{id:string,label:string,summary:string}> */
    private function familyFactOptions(Family $family): array
    {
        $facts = [];
        try {
            foreach ($family->facts() as $fact) {
                $id = (string) $fact->id();
                $tag = (string) $fact->tag();
                if ($id === '' || in_array($tag, ['FAM:HUSB', 'FAM:WIFE', 'FAM:CHIL', 'FAM:CHAN', 'FAM:_UID', 'FAM:UID', 'FAM:SUBM'], true)) {
                    continue;
                }
                $context = $this->factContextFromFact($fact);
                $facts[] = [
                    'id'      => $context['id'],
                    'label'   => $context['label'],
                    'summary' => $context['summary'],
                ];
            }
        } catch (\Throwable) {
            return [];
        }
        return $facts;
    }

    /** @return array{id:string,record_xref:string,record_type:string,tag:string,label:string,summary:string,gedcom:string}|null */
    private function factContextForRecord(object $record, string $fact_id): ?array
    {
        $fact_id = trim($fact_id);
        if ($fact_id === '' || !method_exists($record, 'facts')) {
            return null;
        }
        try {
            foreach ($record->facts() as $fact) {
                if ((string) $fact->id() === $fact_id) {
                    return $this->factContextFromFact($fact);
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    private function suggestFactResponse(Tree $tree, string $xref, string $fact_id, string $fact_record_xref = ''): ResponseInterface
    {
        if (!Auth::check() && !$this->allowGuests()) {
            FlashMessages::addMessage(I18N::translate('You must sign in before submitting a contribution.'), 'danger');
            return redirect(route($this->routeName(), ['tree' => $tree->name()]));
        }

        $individual = Registry::individualFactory()->make($xref, $tree);
        if (!$individual instanceof Individual || (method_exists($individual, 'canShow') && !$individual->canShow())) {
            return response(I18N::translate('The person linked to this suggestion could not be found.'))->withStatus(404);
        }

        $fact_context = $this->factContext($individual, $fact_id, $fact_record_xref);
        if ($fact_context === null) {
            return response(I18N::translate('The fact or event linked to this suggestion could not be found.'))->withStatus(404);
        }

        $user = Auth::user();

        return $this->viewResponse($this->name() . '::contribute', [
            'title'             => I18N::translate('Suggest a correction'),
            'individual'        => $individual,
            'tree'              => $tree,
            'action_url'        => route($this->postRouteName(), ['tree' => $tree->name()]),
            'categories'        => $this->standardCategories(),
            'contributor_name'  => Auth::check() ? $user->realName() : '',
            'contributor_email' => Auth::check() ? $user->email() : '',
            'is_logged_in'      => Auth::check(),
            'allow_attachments' => $this->allowAttachments(),
            'max_upload_mb'     => $this->maxUploadMb(),
            'facts'             => $this->factOptions($individual),
            'selected_fact'     => $fact_context,
            'standalone'        => true,
        ]);
    }

    /**
     * Facts a visitor can comment on from this individual's Facts and events page.
     * This includes the individual's own facts plus facts on spouse-family records
     * (for example marriage and divorce). Relative/historic/associate pseudo-facts
     * are deliberately excluded because they need a different ownership context.
     *
     * @return array<int,array{key:string,id:string,record_xref:string,record_type:string,label:string,summary:string}>
     */
    private function factOptions(Individual $individual): array
    {
        $facts = [];

        try {
            foreach ($this->contributableFacts($individual) as $fact) {
                $id = (string) $fact->id();
                $tag = (string) $fact->tag();
                if ($id === '' || in_array($id, ['histo', 'asso'], true)) {
                    continue;
                }
                if (in_array($tag, ['INDI:NAME', 'INDI:SEX', 'INDI:OBJE', 'INDI:CHAN', 'INDI:_UID', 'INDI:UID', 'INDI:SUBM', 'FAM:CHAN', 'FAM:_UID', 'FAM:UID', 'FAM:SUBM'], true)) {
                    continue;
                }

                $context = $this->factContextFromFact($fact);
                $facts[] = [
                    'key'         => $context['record_xref'] . '|' . $context['id'],
                    'id'          => $context['id'],
                    'record_xref' => $context['record_xref'],
                    'record_type' => $context['record_type'],
                    'label'       => $context['label'],
                    'summary'     => $context['summary'],
                ];
            }
        } catch (\Throwable) {
            return [];
        }

        return $facts;
    }

    /** @return array<int,object> */
    private function contributableFacts(Individual $individual): array
    {
        $facts = [];

        foreach ($individual->facts() as $fact) {
            $facts[] = $fact;
        }

        foreach ($individual->spouseFamilies() as $family) {
            foreach ($family->facts() as $fact) {
                $facts[] = $fact;
            }
        }

        return $facts;
    }

    /** @return array{id:string,record_xref:string,record_type:string,tag:string,label:string,summary:string,gedcom:string}|null */
    private function factContext(Individual $individual, string $fact_id, string $fact_record_xref = ''): ?array
    {
        $fact_id = trim($fact_id);
        $fact_record_xref = strtoupper(trim($fact_record_xref));
        if ($fact_id === '') {
            return null;
        }

        try {
            $matches = [];
            foreach ($this->contributableFacts($individual) as $fact) {
                if ((string) $fact->id() !== $fact_id) {
                    continue;
                }

                $context = $this->factContextFromFact($fact);
                if ($fact_record_xref !== '' && strtoupper($context['record_xref']) !== $fact_record_xref) {
                    continue;
                }

                if ($fact_record_xref !== '') {
                    return $context;
                }

                $matches[] = $context;
            }

            if (count($matches) === 1) {
                return $matches[0];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** @return array{id:string,record_xref:string,record_type:string,tag:string,label:string,summary:string,gedcom:string} */
    private function factContextFromFact(object $fact): array
    {
        $gedcom = (string) $fact->gedcom();
        $label = trim(html_entity_decode(strip_tags((string) $fact->label()), ENT_QUOTES, 'UTF-8'));
        $value = trim(html_entity_decode(strip_tags((string) $fact->value()), ENT_QUOTES, 'UTF-8'));
        $record = $fact->record();

        $tag = (string) $fact->tag();
        $tag_parts = explode(':', $tag);
        $short_tag = (string) end($tag_parts);

        $date = '';
        $place = '';
        if (preg_match('/\n2 DATE (.+)(?:\n|$)/u', $gedcom, $match) === 1) {
            $date = trim((string) $match[1]);
        }
        if (preg_match('/\n2 PLAC (.+)(?:\n|$)/u', $gedcom, $match) === 1) {
            $place = trim((string) $match[1]);
        }

        $parts = [];
        if ($label !== '') {
            $parts[] = $label;
        } elseif ($short_tag !== '') {
            $parts[] = $short_tag;
        }
        if ($value !== '' && !str_starts_with($value, '@')) {
            $parts[] = $value;
        }
        if ($date !== '') {
            $parts[] = $date;
        }
        if ($place !== '') {
            $parts[] = $place;
        }

        $summary = implode(' — ', array_values(array_unique($parts)));
        if ($summary === '') {
            $summary = $short_tag !== '' ? $short_tag : 'Fact/event';
        }

        return [
            'id'          => (string) $fact->id(),
            'record_xref' => (string) $record->xref(),
            'record_type' => $record instanceof Family ? 'FAM' : 'INDI',
            'tag'         => $short_tag,
            'label'       => $label !== '' ? $label : $short_tag,
            'summary'     => mb_substr($summary, 0, 1000),
            'gedcom'      => mb_substr($gedcom, 0, 20000),
        ];
    }

    /**
     * @return array{0:array<string,mixed>|null,1:string}
     */
    private function prepareAttachment(ServerRequestInterface $request): array
    {
        $files = $request->getUploadedFiles();
        $file = $files['attachment'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            return [null, ''];
        }

        $error = $file->getError();
        if ($error === UPLOAD_ERR_NO_FILE) {
            return [null, ''];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return [null, I18N::translate('The attachment upload failed. Please try again.')];
        }

        $size = (int) ($file->getSize() ?? 0);
        $max_bytes = $this->maxUploadMb() * 1024 * 1024;
        if ($size <= 0 || $size > $max_bytes) {
            return [null, I18N::translate('Attachments must be no larger than %s MB.', (string) $this->maxUploadMb())];
        }

        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $contents = $stream->getContents();
        if ($contents === '' || strlen($contents) > $max_bytes) {
            return [null, I18N::translate('The attachment could not be read or is too large.')];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->buffer($contents);
        if (!array_key_exists($mime, self::ALLOWED_MIME_TYPES)) {
            return [null, I18N::translate('Attachments must be PDF, JPG, PNG or WEBP files.')];
        }

        $client_name = $this->safeFilename((string) ($file->getClientFilename() ?? 'attachment.' . self::ALLOWED_MIME_TYPES[$mime]));

        return [[
            'contents'      => $contents,
            'mime_type'     => $mime,
            'extension'     => self::ALLOWED_MIME_TYPES[$mime],
            'original_name' => $client_name,
            'size'          => strlen($contents),
        ], ''];
    }

    /** @param array<string,mixed> $attachment */
    private function storeAttachment(Tree $tree, int $contribution_id, array $attachment): string
    {
        if (!$this->attachmentTableReady() || !$this->ensureAttachmentRoot()) {
            throw new \RuntimeException('Attachment storage unavailable.');
        }
        $directory = $this->attachmentRoot() . DIRECTORY_SEPARATOR . $tree->id() . DIRECTORY_SEPARATOR . $contribution_id;
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create attachment directory.');
        }

        $extension = (string) ($attachment['extension'] ?? 'bin');
        $stored_name = bin2hex(random_bytes(20)) . '.' . $extension;
        $path = $directory . DIRECTORY_SEPARATOR . $stored_name;
        $contents = (string) ($attachment['contents'] ?? '');

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write attachment.');
        }

        DB::table(self::ATTACHMENT_TABLE)->insert([
            'contribution_id' => $contribution_id,
            'gedcom_id'       => $tree->id(),
            'original_name'   => (string) ($attachment['original_name'] ?? 'attachment'),
            'stored_name'     => $stored_name,
            'mime_type'       => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
            'file_size'       => (int) ($attachment['size'] ?? strlen($contents)),
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);

        return (string) ($attachment['original_name'] ?? 'attachment');
    }

    /** @param array<string,mixed> $body */
    private function manageContribution(Tree $tree, array $body): ResponseInterface
    {
        if (!Auth::isManager($tree)) {
            return response(I18N::translate('Access denied.'))->withStatus(403);
        }

        $id = (int) ($body['contribution_id'] ?? 0);
        if ($id <= 0 || !$this->tableReady()) {
            return redirect(route($this->routeName(), ['tree' => $tree->name()]) . '?manage=1');
        }

        $row = DB::table(self::TABLE)
            ->where('contribution_id', '=', $id)
            ->where('gedcom_id', '=', $tree->id())
            ->first();

        if ($row === null) {
            FlashMessages::addMessage(I18N::translate('Contribution not found.'), 'danger');
            return redirect(route($this->routeName(), ['tree' => $tree->name()]) . '?manage=1');
        }

        $action = (string) ($body['action'] ?? 'update');
        if ($action === 'delete') {
            $this->deleteContributionAttachments($tree, $id);
            DB::table(self::TABLE)
                ->where('contribution_id', '=', $id)
                ->where('gedcom_id', '=', $tree->id())
                ->delete();
            FlashMessages::addMessage(I18N::translate('Contribution deleted.'), 'success');
        } else {
            $status = strtolower(trim((string) ($body['status'] ?? 'new')));
            if (!array_key_exists($status, self::STATUSES)) {
                $status = 'new';
            }
            $notes = $this->cleanText((string) ($body['admin_notes'] ?? ''), 10000);

            DB::table(self::TABLE)
                ->where('contribution_id', '=', $id)
                ->where('gedcom_id', '=', $tree->id())
                ->update([
                    'status'      => $status,
                    'admin_notes' => $notes === '' ? null : $notes,
                    'updated_at'  => gmdate('Y-m-d H:i:s'),
                ]);

            FlashMessages::addMessage(I18N::translate('Contribution updated.'), 'success');
        }

        return redirect(route($this->routeName(), ['tree' => $tree->name()]) . '?manage=1');
    }

    private function inboxResponse(Tree $tree, string $status): ResponseInterface
    {
        if (!Auth::isManager($tree)) {
            return response(I18N::translate('Access denied.'))->withStatus(403);
        }

        $status = strtolower(trim($status));
        if ($status !== '' && !array_key_exists($status, self::STATUSES)) {
            $status = '';
        }

        $rows = [];
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);

        if ($this->tableReady()) {
            foreach (DB::table(self::TABLE)->where('gedcom_id', '=', $tree->id())->get() as $row) {
                $row_status = (string) ($row->status ?? 'new');
                if (array_key_exists($row_status, $counts)) {
                    $counts[$row_status]++;
                }
            }

            $query = DB::table(self::TABLE)
                ->where('gedcom_id', '=', $tree->id())
                ->orderByDesc('contribution_id');

            if ($status !== '') {
                $query->where('status', '=', $status);
            }

            foreach ($query->limit(250)->get() as $row) {
                $xref = strtoupper((string) ($row->xref ?? ''));
                $target_type = $this->targetTypeForRow($tree, $row, $xref);
                $attachment = $this->attachmentForContribution($tree, (int) $row->contribution_id);
                $rows[] = [
                    'id'               => (int) $row->contribution_id,
                    'xref'             => $xref,
                    'target_type'      => $target_type,
                    'target_label'     => $this->targetTypeLabel($target_type),
                    'record_name'      => (string) ($row->record_name ?? $xref),
                    'category'         => (string) ($row->category ?? 'other'),
                    'category_label'   => self::CATEGORIES[(string) ($row->category ?? 'other')] ?? 'Other',
                    'fact_id'          => (string) ($row->fact_id ?? ''),
                    'fact_record_xref' => (string) ($row->fact_record_xref ?? ''),
                    'fact_record_type' => (string) ($row->fact_record_type ?? ''),
                    'fact_tag'         => (string) ($row->fact_tag ?? ''),
                    'fact_label'       => (string) ($row->fact_label ?? ''),
                    'fact_summary'     => (string) ($row->fact_summary ?? ''),
                    'fact_snapshot'    => (string) ($row->fact_snapshot ?? ''),
                    'message'          => (string) ($row->message ?? ''),
                    'evidence'         => (string) ($row->evidence ?? ''),
                    'contributor_name' => (string) ($row->contributor_name ?? ''),
                    'contributor_email'=> (string) ($row->contributor_email ?? ''),
                    'relationship'     => (string) ($row->relationship ?? ''),
                    'contact_ok'       => (bool) ($row->contact_ok ?? false),
                    'status'           => (string) ($row->status ?? 'new'),
                    'admin_notes'      => (string) ($row->admin_notes ?? ''),
                    'created_at'       => $this->formatStoredTimestamp((string) ($row->created_at ?? '')),
                    'record_url'       => $this->targetUrl($tree, $target_type, $xref),
                    'attachment'       => $attachment,
                ];
            }
        }

        $base_url = route($this->routeName(), ['tree' => $tree->name()]);

        return $this->viewResponse($this->name() . '::inbox', [
            'title'       => I18N::translate('Family Contributions'),
            'tree'        => $tree,
            'rows'        => $rows,
            'statuses'    => self::STATUSES,
            'counts'      => $counts,
            'status'      => $status,
            'base_url'    => $base_url,
            'post_url'    => route($this->postRouteName(), ['tree' => $tree->name()]),
            'table_ready' => $this->tableReady(),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function attachmentForContribution(Tree $tree, int $contribution_id): ?array
    {
        if (!$this->attachmentTableReady()) {
            return null;
        }

        $row = DB::table(self::ATTACHMENT_TABLE)
            ->where('contribution_id', '=', $contribution_id)
            ->where('gedcom_id', '=', $tree->id())
            ->orderBy('attachment_id')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id'            => (int) $row->attachment_id,
            'original_name' => (string) $row->original_name,
            'mime_type'     => (string) $row->mime_type,
            'file_size'     => (int) $row->file_size,
            'file_size_text'=> $this->formatFileSize((int) $row->file_size),
            'url'           => route($this->routeName(), ['tree' => $tree->name()]) . '?attachment=' . (int) $row->attachment_id,
        ];
    }

    private function attachmentResponse(Tree $tree, int $attachment_id): ResponseInterface
    {
        if (!Auth::isManager($tree) || !$this->attachmentTableReady()) {
            return response(I18N::translate('Access denied.'))->withStatus(403);
        }

        $row = DB::table(self::ATTACHMENT_TABLE)
            ->where('attachment_id', '=', $attachment_id)
            ->where('gedcom_id', '=', $tree->id())
            ->first();

        if ($row === null) {
            return response(I18N::translate('Attachment not found.'))->withStatus(404);
        }

        $path = $this->attachmentRoot()
            . DIRECTORY_SEPARATOR . $tree->id()
            . DIRECTORY_SEPARATOR . (int) $row->contribution_id
            . DIRECTORY_SEPARATOR . basename((string) $row->stored_name);

        if (!is_file($path) || !is_readable($path)) {
            return response(I18N::translate('Attachment file is missing.'))->withStatus(404);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return response(I18N::translate('Attachment file could not be read.'))->withStatus(500);
        }

        $filename = $this->safeFilename((string) $row->original_name);

        return response($contents)
            ->withHeader('Content-Type', (string) $row->mime_type)
            ->withHeader('Content-Length', (string) strlen($contents))
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $filename) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function sendSubmissionNotifications(
        Tree $tree,
        int $contribution_id,
        string $record_name,
        string $record_type_label,
        string $category,
        string $fact_summary,
        string $contributor_name,
        string $contributor_email,
        string $relationship,
        string $message,
        string $evidence,
        string $attachment_name,
    ): void {
        try {
            $email_service = Registry::container()->get(EmailService::class);
            $message_service = Registry::container()->get(MessageService::class);
            $tree_user = new TreeUser($tree);
            $contributor = new ContributionExternalUser($contributor_name, $contributor_email);
            $review_url = route($this->routeName(), ['tree' => $tree->name()]) . '?manage=1';

            if ($this->notifyManagers()) {
                $seen = [];
                foreach ($message_service->validContacts($tree) as $recipient) {
                    $key = strtolower($recipient->email());
                    if ($key === '' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $subject = I18N::translate('New contribution: %s', $record_name);
                    $text = "A new family contribution has been submitted.\n\n"
                        . "{$record_type_label}: {$record_name}\n"
                        . "Type: {$category}\n"
                        . ($fact_summary !== '' ? "Fact/event: {$fact_summary}\n" : '')
                        . "Contributor: {$contributor_name} <{$contributor_email}>\n"
                        . ($relationship !== '' ? "Relationship: {$relationship}\n" : '')
                        . ($attachment_name !== '' ? "Attachment: {$attachment_name}\n" : '')
                        . "\nContribution:\n{$message}\n"
                        . ($evidence !== '' ? "\nSource or evidence:\n{$evidence}\n" : '')
                        . "\nReview contribution:\n{$review_url}\n";
                    $html = '<p>A new <strong>family contribution</strong> has been submitted.</p>'
                        . '<p><strong>' . e($record_type_label) . ':</strong> ' . e($record_name) . '<br>'
                        . '<strong>Type:</strong> ' . e($category) . '<br>'
                        . ($fact_summary !== '' ? '<strong>Fact/event:</strong> ' . e($fact_summary) . '<br>' : '')
                        . '<strong>Contributor:</strong> ' . e($contributor_name) . ' &lt;' . e($contributor_email) . '&gt;'
                        . ($relationship !== '' ? '<br><strong>Relationship:</strong> ' . e($relationship) : '')
                        . ($attachment_name !== '' ? '<br><strong>Attachment:</strong> ' . e($attachment_name) : '')
                        . '</p>'
                        . '<p><strong>Contribution</strong><br>' . nl2br(e($message)) . '</p>'
                        . ($evidence !== '' ? '<p><strong>Source or evidence</strong><br>' . nl2br(e($evidence)) . '</p>' : '')
                        . '<p><a href="' . e($review_url) . '">Review contribution #' . $contribution_id . '</a></p>';

                    $email_service->send($tree_user, $recipient, $contributor, $subject, $text, $html);
                }
            }

            if ($this->acknowledgeContributors()) {
                $contacts = $message_service->validContacts($tree);
                $reply_to = $contacts[0] ?? $tree_user;
                $subject = I18N::translate('Thank you for your contribution');
                $text = "Thank you for contributing information about {$record_name}.\n\n"
                    . "Your contribution has been received and will be reviewed by the family tree manager before any change is made to the genealogy records.\n\n"
                    . "Contribution reference: #{$contribution_id}\n\n"
                    . "Thank you for helping improve the family history.\n";
                $html = '<p>Thank you for contributing information about <strong>' . e($record_name) . '</strong>.</p>'
                    . '<p>Your contribution has been received and will be reviewed by the family tree manager before any change is made to the genealogy records.</p>'
                    . '<p><strong>Contribution reference:</strong> #' . $contribution_id . '</p>'
                    . '<p>Thank you for helping improve the family history.</p>';

                $email_service->send($tree_user, $contributor, $reply_to, $subject, $text, $html);
            }
        } catch (\Throwable) {
        }
    }

    private function formatStoredTimestamp(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            $utc = new DateTimeZone('UTC');
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $utc);
            if ($date === false) {
                return $value;
            }

            return Registry::timestampFactory()
                ->make($date->getTimestamp(), Auth::user())
                ->format('j M Y, H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function individualTabUrl(Tree $tree, string $xref, string $tab): ?string
    {
        $xref = strtoupper(trim($xref));
        if ($xref === '' || !in_array($tab, ['relatives', 'media'], true)) {
            return null;
        }

        try {
            $individual = Registry::individualFactory()->make($xref, $tree);
            if (!$individual instanceof Individual || (method_exists($individual, 'canShow') && !$individual->canShow())) {
                return null;
            }

            return $individual->url() . '#tab-' . $tab;
        } catch (\Throwable) {
            return null;
        }
    }

    private function individualUrl(Tree $tree, string $xref): string
    {
        if ($xref !== '') {
            try {
                return route(IndividualPage::class, [
                    'tree' => $tree->name(),
                    'xref' => $xref,
                ]);
            } catch (\Throwable) {
            }
        }

        return route($this->routeName(), ['tree' => $tree->name()]);
    }

    private function hasTargetTypeColumn(): bool
    {
        try {
            return DB::schema()->hasTable(self::TABLE) && DB::schema()->hasColumn(self::TABLE, 'target_type');
        } catch (\Throwable) {
            return false;
        }
    }

    private function targetTypeForRow(Tree $tree, object $row, string $xref): string
    {
        if ($this->hasTargetTypeColumn()) {
            $stored = strtoupper(trim((string) ($row->target_type ?? '')));
            if (in_array($stored, ['INDI', 'FAM', 'OBJE'], true)) {
                return $stored;
            }
        }

        try {
            if (Registry::individualFactory()->make($xref, $tree) instanceof Individual) {
                return 'INDI';
            }
            if (Registry::familyFactory()->make($xref, $tree) instanceof Family) {
                return 'FAM';
            }
            if (Registry::mediaFactory()->make($xref, $tree) instanceof Media) {
                return 'OBJE';
            }
        } catch (\Throwable) {
        }

        $context_type = strtoupper(trim((string) ($row->fact_record_type ?? '')));
        if (in_array($context_type, ['FAM', 'OBJE'], true)) {
            return $context_type;
        }

        return 'INDI';
    }

    private function targetTypeLabel(string $target_type): string
    {
        return match (strtoupper($target_type)) {
            'FAM'  => 'Family',
            'OBJE' => 'Media',
            default => 'Person',
        };
    }

    private function targetUrl(Tree $tree, string $target_type, string $xref): string
    {
        $target_type = strtoupper(trim($target_type));
        $xref = strtoupper(trim($xref));

        try {
            if ($target_type === 'FAM') {
                $record = Registry::familyFactory()->make($xref, $tree);
                if ($record instanceof Family) {
                    return $record->url();
                }
            } elseif ($target_type === 'OBJE') {
                $record = Registry::mediaFactory()->make($xref, $tree);
                if ($record instanceof Media) {
                    return $record->url();
                }
            } else {
                return $this->individualUrl($tree, $xref);
            }
        } catch (\Throwable) {
        }

        return route($this->routeName(), ['tree' => $tree->name()]);
    }

    private function ensureSchema(): bool
    {
        try {
            if (!DB::schema()->hasTable(self::TABLE)) {
                DB::schema()->create(self::TABLE, static function (Blueprint $table): void {
                    $table->integer('contribution_id', true);
                    $table->integer('gedcom_id');
                    $table->string('xref', 20);
                    $table->string('target_type', 12)->default('INDI');
                    $table->string('record_name', 255);
                    $table->string('category', 32);
                    $table->string('fact_id', 128)->nullable();
                    $table->string('fact_record_xref', 20)->nullable();
                    $table->string('fact_record_type', 12)->nullable();
                    $table->string('fact_tag', 80)->nullable();
                    $table->string('fact_label', 255)->nullable();
                    $table->string('fact_summary', 1000)->nullable();
                    $table->longText('fact_snapshot')->nullable();
                    $table->longText('message');
                    $table->longText('evidence')->nullable();
                    $table->string('contributor_name', 255);
                    $table->string('contributor_email', 255);
                    $table->string('relationship', 255)->nullable();
                    $table->integer('contact_ok')->default(0);
                    $table->integer('user_id')->nullable();
                    $table->string('status', 20)->default('new');
                    $table->longText('admin_notes')->nullable();
                    $table->timestamp('created_at')->nullable();
                    $table->timestamp('updated_at')->nullable();

                    $table->index(['gedcom_id', 'status']);
                    $table->index(['gedcom_id', 'xref']);
                    $table->index('created_at');
                });
            }

            if (!DB::schema()->hasTable(self::ATTACHMENT_TABLE)) {
                DB::schema()->create(self::ATTACHMENT_TABLE, static function (Blueprint $table): void {
                    $table->integer('attachment_id', true);
                    $table->integer('contribution_id');
                    $table->integer('gedcom_id');
                    $table->string('original_name', 255);
                    $table->string('stored_name', 120);
                    $table->string('mime_type', 100);
                    $table->integer('file_size');
                    $table->timestamp('created_at')->nullable();

                    $table->index(['gedcom_id', 'contribution_id']);
                    $table->index('created_at');
                });
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableReady(): bool
    {
        try {
            return DB::schema()->hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    private function attachmentTableReady(): bool
    {
        try {
            return DB::schema()->hasTable(self::ATTACHMENT_TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    private function dataRoot(): string
    {
        $data_dir = defined(Webtrees::class . '::DATA_DIR')
            ? Webtrees::DATA_DIR
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $data_dir), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . self::DATA_DIR;
    }

    private function attachmentRoot(): string
    {
        return $this->dataRoot() . DIRECTORY_SEPARATOR . 'attachments';
    }

    private function ensureAttachmentRoot(): bool
    {
        $root = $this->attachmentRoot();
        if (is_dir($root)) {
            return is_writable($root);
        }

        return @mkdir($root, 0775, true) || is_dir($root);
    }

    private function deleteContributionAttachments(Tree $tree, int $contribution_id): void
    {
        if ($this->attachmentTableReady()) {
            DB::table(self::ATTACHMENT_TABLE)
                ->where('contribution_id', '=', $contribution_id)
                ->where('gedcom_id', '=', $tree->id())
                ->delete();
        }

        $directory = $this->attachmentRoot() . DIRECTORY_SEPARATOR . $tree->id() . DIRECTORY_SEPARATOR . $contribution_id;
        if (is_dir($directory)) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($directory);
        }
    }

    private function safeFilename(string $value): string
    {
        $value = trim(str_replace(["\0", "\r", "\n", '/', '\\'], '_', basename($value)));
        $value = preg_replace('/[^\pL\pN._()\- ]+/u', '_', $value) ?? 'attachment';
        $value = trim($value, '. ');

        return mb_substr($value !== '' ? $value : 'attachment', 0, 180);
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' bytes';
    }

    private function cleanSingleLine(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
        return mb_substr($value, 0, $limit);
    }

    private function cleanText(string $value, int $limit): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace("/\r\n?|\n/u", "\n", $value) ?? '';
        return mb_substr($value, 0, $limit);
    }
};
