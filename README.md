# Dance Thru the Decades Portal v62 - Requests Queue Update Integration

## Changes

- Integrates the proven queue update detection into the live Requests page.
- Does not edit requests.php directly, avoiding the fragile PHP/HTML structure.
- Adds external JavaScript:
  - /assets/request-update-check.js
- Shared admin footer loads this script only on requests.php.
- Script reads the currently visible stats from the page.
- Script calls /admin/request-ping.php every 5 seconds.
- If server counts differ from visible counts, a banner appears:
  - Queue updates available
  - Refresh queue
- No auto-refresh; DJ chooses when to reload.
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v62 Requests Queue Update Integration
