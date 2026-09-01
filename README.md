# Potts Family Contributions

A custom module for webtrees 2.2.x that lets relatives and visitors contribute corrections, family information, stories, sources, media identifications and supporting documents without giving them direct GEDCOM edit access.

**Current release:** `0.9.0-beta.2`

The beta feature set is intentionally conservative: contributions are collected for review, but the module never changes the GEDCOM or webtrees media library automatically.

## What visitors can contribute

- General information from a person’s **Contribute** tab.
- Corrections to individual facts and spouse-family facts using **Suggest correction**.
- Family information from standalone family pages and from each family shown on an individual’s **Families** tab.
- Corrections to family facts such as marriage and divorce.
- Media identifications from standalone media pages and directly from media objects shown on an individual’s **Media** tab.
- Stories, research leads, source references, relationship information and other family-history material.
- One optional PDF, JPG, PNG or WEBP attachment with each contribution.

## Fact-level correction context

When a visitor comments on a specific fact or event, the submission preserves the context that existed at the time of submission, including:

- webtrees fact ID
- owning record XREF and record type
- GEDCOM tag
- fact label
- human-readable summary
- original GEDCOM fact snapshot

This means the manager can still see exactly what the contributor was questioning even if the tree is edited later.

## Family contributions

Family contribution links are available in two places when enabled:

- standalone family pages
- each family shown on an individual’s **Families** tab

A visitor can submit general family information or select a specific family fact. If the form was opened from an individual’s Families tab, cancelling or completing the contribution returns the visitor to that same tab.

## Media identification

Media contribution links are available in two places when enabled:

- standalone media pages
- media objects shown on an individual’s **Media** tab

The media form is linked to the exact webtrees media object and can be used to:

- identify people in a photograph
- identify a place or event
- suggest a date
- correct a description or attribution
- provide another photograph or document as supporting evidence

Where webtrees can render the linked media item, the contribution form includes a preview. The existing media object is never modified automatically.

## Manager review workflow

Tree managers receive a dedicated **Contributions** inbox with:

- New, Under review, Completed and Rejected or duplicate statuses
- Person, Family and Media target labels
- direct links back to the relevant webtrees record
- contributor name, email, relationship/connection and permission to contact
- fact/event context and the preserved GEDCOM snapshot where applicable
- source/evidence details
- private attachment download
- private review notes

The review inbox stores and tracks suggestions only. Any GEDCOM or media change remains a deliberate manager action.

## Email notifications

The module can optionally:

- notify the tree’s configured contact and support users when a contribution is received
- send the contributor an acknowledgement with a contribution reference number

Email uses the SMTP or sendmail settings already configured in webtrees. Email failure does not prevent the contribution from being stored.

## Attachment security

Attachments are validated using their actual MIME type rather than trusting the filename extension.

Allowed types:

- PDF
- JPEG
- PNG
- WEBP

Files are given random stored names and kept under:

`data/potts-family-contributions/attachments/`

They are not added to the webtrees media library automatically and can only be downloaded through a manager-authorised module route.

## Installation

1. Extract the archive so the module folder is `modules_v4/potts_family_contributions/`.
2. Open **Control Panel → Modules → All modules** in webtrees.
3. Enable **Potts Family Contributions**.
4. Open the module settings and choose who may contribute and where contribution links should appear.

## Upgrading from an alpha

Replace the existing `modules_v4/potts_family_contributions/` folder with the beta version.

Do **not** delete the contribution database tables or attachment folder. Existing contributions, attachments and settings are retained.

The module deliberately does not run `ALTER TABLE` operations on an existing contribution table during normal webtrees requests. This avoids the MySQL/MariaDB implicit-commit problem that affected alpha.11. The beta remains compatible with the known-good alpha.12/alpha.13 schema used during testing.

## Settings

Administrators can independently control:

- whether the module is enabled
- whether guests may contribute
- fact/event correction links
- family contribution links
- media identification links
- attachments and maximum attachment size
- manager notification emails
- contributor acknowledgement emails

## Compatibility

The beta targets **webtrees 2.2.x** and has been developed against webtrees 2.2.6.

It uses webtrees custom views for the individual Facts and events, Families and Media tabs plus standalone family and media pages. The customised views continue to call webtrees’ normal fact, family and media components so standard editing controls and theme styling are retained.

The module is configured for a cached latest-version check using `latest-version.txt` from the PottsNet repository when that file is available. If the remote check fails, the installed version is used as a safe fallback.

The manager **Contributions** menu item uses the stable CSS class `menu-contributions`, allowing themes such as Potts Modern to provide a dedicated navigation icon without depending on the module's internal name.

## Design principles

- No automatic GEDCOM edits.
- No automatic media-library insertion.
- Evidence and attachments remain private until reviewed.
- Contribution context is preserved for later checking.
- Browsing context is retained so visitors return to the person/tab they came from.
- Existing webtrees privacy and record visibility remain authoritative.

## Licence

GPL-3.0-or-later.
