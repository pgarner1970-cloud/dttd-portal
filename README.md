# Dance Thru the Decades Portal v35 - Request Queue Update Indicator

## Changes

- Adds a lightweight request queue update checker.
- Requests page now polls every 10 seconds in the background.
- It does not auto-refresh the page.
- If the queue changes, it shows a banner:
  - Queue updates available
  - Refresh queue
- Polling pauses while the DJ is interacting with buttons/forms.
- Adds endpoint:
  - /admin/request-ping.php
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v35 Request Queue Update Indicator
