# Dance Thru the Decades Portal v61 - Queue Update Debug Page

## Changes

- Adds a separate debug page:
  - /admin/request-debug.php
- Leaves /admin/requests.php untouched to avoid breaking the working queue page.
- Adds robust ping endpoint:
  - /admin/request-ping.php
- Debug page shows:
  - selected event
  - loaded counts
  - server counts
  - status
  - endpoint
  - check now button
- Polls every 5 seconds.
- Adds Queue Debug link on /admin/index.php.
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v61 Queue Update Debug Page
