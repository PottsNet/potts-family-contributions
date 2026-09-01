# Potts Family Contributions 0.9.0-beta.2

This beta update improves theme integration for the manager **Contributions** menu item.

## Changes

- Adds the stable `menu-contributions` CSS class to the manager menu item.
- Allows themes such as Potts Modern to recognise the menu reliably and provide a dedicated icon.
- Makes no database schema changes.
- Existing contribution records, attachments and settings are retained.

## Compatibility

- webtrees 2.2.x
- Developed against webtrees 2.2.6

## Upgrade

Replace the existing `modules_v4/potts_family_contributions/` folder with the release ZIP. Do not delete the module's database tables or the `data/potts-family-contributions/` attachment folder.
