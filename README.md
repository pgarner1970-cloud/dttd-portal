# Dance Thru the Decades Portal v25 - Requests No Redirect Fix

## Changes

- Requests page no longer redirects back to Events when `active_event()` does not return an event.
- Requests page now loads:
  1. Event from `?event=ID`, if supplied
  2. Active event from the database
  3. Latest event as fallback
- Added/kept dedicated Requests page:
  - `/admin/requests.php`
- `/admin/index.php` also contains the Requests dashboard as a fallback.
- Event page Requests tile points to `/admin/requests.php`.
- POST status updates return to `/admin/requests.php`.

## SQL

No SQL to run.

## Suggested Git commit title

v25 Requests No Redirect Fix
