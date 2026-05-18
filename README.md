# Dance Thru the Decades Portal v146 - Hide Public Event Codes

## Changes

- Removes event-code exposure from public pages.
- Homepage no longer links to request/event/gallery pages using `?code=...`.
- Public events list no longer exposes event codes in URLs.
- Public event-code display/badge is hidden.
- Event codes remain for controlled QR/poster use from admin/DJ side.
- Bumps public-site.css cache version to v146.

## Important

Event codes should only be distributed through controlled event materials:
- printed QR posters
- table cards
- venue screens
- future protected Wi-Fi flow

Public event listing should use public IDs/slugs, not event codes.

## SQL

No SQL changes.

## Suggested Git commit title

v146 Hide Public Event Codes
