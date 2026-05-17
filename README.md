# Dance Thru the Decades Portal v45 - Countdown Span Data Fix

## Changes

- Fixes active event countdowns getting stuck on “calculating…”.
- Countdown data is now stored directly on each countdown span:
  - event end countdown uses event date/start/end time
  - request close countdown uses request close datetime
- Event end countdown handles midnight-spanning events such as 17:30 - 01:30.
- Request close countdown updates every second.
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v45 Countdown Span Data Fix
