# Dance Thru the Decades Portal v58 - Queue Update Visible Counts

## Changes

- Fixes queue update detection by comparing database counts against the counts currently rendered on the page.
- The Requests page stores the visible counts in data attributes.
- The ping endpoint returns current database status counts.
- If total/pending/maybe/played/duplicate/rejected counts differ, the refresh banner appears.
- Checks 1.5 seconds after page load, then every 10 seconds.
- No auto-refresh; DJ still taps Refresh queue.

## SQL

No SQL to run.

## Suggested Git commit title

v58 Queue Update Visible Counts
