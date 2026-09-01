# Changelog

## 0.9.0-beta.2

- Added the stable `menu-contributions` CSS class to the manager Contributions menu item.
- Allows themes, including Potts Modern, to recognise the Contributions menu reliably and supply a dedicated icon.
- No database schema or contribution-data changes are required.

## 0.9.0-beta.1

- Promoted the tested alpha.13 feature set to the first beta release.
- Polished wording across contribution forms, settings, media actions and manager review screens.
- Reorganised Control Panel settings into Availability, contribution-link placement, Attachments and Email notifications.
- Improved the manager inbox with total/status counts, clearer target and review-status badges, record XREF display and explicitly private review notes.
- Added a cached latest-version check with a safe fallback to the installed version when the remote version file is unavailable.
- Fixed the fresh-install schema definition so `created_at` is declared only once.
- Retained the alpha.12+ rule that existing contribution tables are never altered with live DDL during a normal webtrees request.
- No database reset or migration is required for installations already working on alpha.13.

## 0.1.0-alpha.13

- Added contribution links directly to each family shown on an individual’s **Families** tab.
- Added **Identify people / add information** directly to media objects shown on an individual’s **Media** tab.
- Family/media forms opened from an individual page remember that browsing context and return the visitor to the same individual tab after submission or cancellation.
- Uses module-specific `return_person_xref` context instead of the generic `xref` query parameter, avoiding unrelated relationship-context banners.
- Standalone family-page and media-page contribution actions remain available.
- No database schema changes are introduced by this release.

## 0.1.0-alpha.12

- Removed the alpha.11 live `ALTER TABLE` migration that could implicitly end webtrees’ request transaction on MySQL/MariaDB and trigger “There is no active transaction”.
- Family/media contribution targeting now works with both earlier schemas and installations where alpha.11 had already created the optional `target_type` column.
- Existing contributions, attachments and settings remain intact.
- No existing contribution table is altered during normal requests.

## 0.1.0-alpha.11

- Added contributions directly from webtrees family pages.
- Added family fact-level correction suggestions for marriage, divorce and other family facts.
- Family fact suggestions preserve the original GEDCOM fact snapshot for review.
- Added media/photo identification workflow from webtrees media pages.
- Media contribution forms can preview the linked media item and default to an identification category.
- Added a dedicated “Identify a person, place or item in media” contribution category.
- Review inbox now distinguishes Person, Family and Media contributions and links back to the correct record type.
- Manager notification emails identify whether the contribution relates to a Person, Family or Media record.
- Added separate settings to enable/disable family-page and media-page contribution actions.
- Existing contribution data and attachments remain compatible; a target-type column is added automatically.

## 0.1.0-alpha.10

- Automatically redirects stale alpha.8/alpha.9 correction links that still contain the generic `xref` query parameter to a clean `person_xref` URL before the page renders.
- Removes the generic `xref` fallback from the rendered correction page so unrelated relationship-context modules cannot be triggered by Family Contributions.
- Keeps old links usable without requiring visitors to refresh an already-open Facts and events page first.

## 0.1.0-alpha.9

- Prevented unrelated relationship-context banners from appearing on standalone correction pages by replacing the generic `xref` query parameter with the module-specific `person_xref`.
- Kept backward compatibility for older alpha links/forms that still send `xref`.

## 0.1.0-alpha.8

- Reworked fact-level suggestion links so they sit inside the existing fact card rather than creating a separate table row.
- Uses a small, muted “Suggest correction” action aligned to the bottom-right of eligible facts.
- Preserves normal webtrees/theme fact rendering and keeps the Facts and events timeline visually compact.

## 0.1.0-alpha.7

- Fixes fact-level suggestion links not appearing in webtrees 2.2.x.
- Corrects custom-module internal name references to webtrees' wrapped form (`_potts_family_contributions_`).
- Corrects the inline suggestion route name so eligible facts now generate working contribution links.

## 0.1.0-alpha.6

- Changed inline fact contributions to integrate at the Facts and events tab level instead of replacing the core `fact` view.
- This avoids conflicts with themes and modules that customise fact-row rendering.
- Adds a small "Suggest a correction to this fact" action immediately after eligible individual and spouse-family facts.
- Continues to exclude synthetic relative, associate and historic rows.

## 0.1.0-alpha.5

- Fixed fact-level contribution links for family facts shown on an individual page, including marriage and divorce.
- Records the owning GEDCOM record XREF/type for each fact-level suggestion so duplicate fact IDs cannot be confused across records.
- Includes spouse-family facts in the optional fact selector on the Contribute tab.
- Keeps relative, historic and associate pseudo-facts excluded from this workflow.

## 0.1.0-alpha.4

- Added contextual **Suggest correction** links beside direct facts/events on individual pages.
- Added an optional fact/event selector to the normal Contribute tab.
- Fact-level submissions now preserve the fact ID, GEDCOM tag, human-readable summary and a GEDCOM snapshot of the original fact.
- Added a dedicated correction form when a visitor follows a fact-level Suggest correction link.
- Added fact context to manager notification emails.
- Added fact context and the preserved GEDCOM snapshot to the review inbox.
- Added a Control Panel setting to disable fact-level links if they conflict with another custom view/module.
- Existing contribution tables are upgraded automatically with nullable fact-context columns.
- No contribution is written to the GEDCOM automatically.

## 0.1.0-alpha.3

- Added optional PDF/JPG/PNG/WEBP attachments.
- Attachments are stored privately outside the module folder and are not automatically added to webtrees media.
- Added manager-only attachment downloads in the review inbox.
- Added configurable maximum attachment size.
- Added email notifications to tree contacts/support users.
- Added contributor acknowledgement emails.
- Continued UTC storage with user-timezone display.

## 0.1.0-alpha.2

- Wrapped manager pages in the normal webtrees layout.
- Corrected contribution timestamp display to use the current webtrees user's timezone.

## 0.1.0-alpha.1

- Initial test release.
- Individual-page contribution tab.
- Guest/member submissions.
- Manager review inbox and statuses.
- Contributions stored outside GEDCOM data.
