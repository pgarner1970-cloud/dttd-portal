# Dance Thru the Decades Portal v59 - Queue Update Debug Fix

## Changes

- Queue update checker now polls every 5 seconds.
- Uses the existing page count data from v58.
- Ping endpoint uses simple COUNT/GROUP BY logic.
- Adds a small diagnostic line inside the update banner area.
- If server counts differ from the loaded page counts, the Refresh queue banner appears.
- No auto-refresh.
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v59 Queue Update Debug Fix
