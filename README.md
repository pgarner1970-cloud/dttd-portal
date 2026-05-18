# Dance Thru the Decades Portal v116 - Simplify Event Code Management

## Changes

- Removes the duplicate visible Event Code field from Portal Behaviour.
- Keeps the QR Code & Event Code section as the single visible location for the event code.
- Adds a hidden event_code field so existing values are preserved on save.
- Keeps automatic event code generation unchanged.
- Adds helper wording explaining the event code is generated automatically.
- Keeps `event-edit.php` as the only event edit page.
- Does not recreate `events-edit.php`.

## SQL

No SQL changes.

## Suggested Git commit title

v116 Simplify Event Code Management
