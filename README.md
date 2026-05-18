# Dance Thru the Decades Portal v112 - Fix Event Edit Nav Active

## Changes

- Fixes `event-edit.php` incorrectly highlighting Settings in the top nav.
- Explicitly maps admin pages:
  - Requests: `requests.php`, `index.php`, `request-debug.php`
  - Events: `events.php`, `event-edit.php`, `event-qr.php`
  - Settings: `settings.php`
- Confirms `events-edit.php` remains removed.

## SQL

No SQL changes.

## Suggested Git commit title

v112 Fix Event Edit Nav Active
