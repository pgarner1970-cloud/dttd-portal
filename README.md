# Dance Thru the Decades Portal v44 - Active Card Countdown Fix

## Changes

- Fixes false “end not set” display on the Requests page active event card.
- Event Time now shows:
  - start time only if no end time exists
  - start time - end time if an end time exists
- If an end time exists, it shows a live countdown beside the event time.
- Requests Close shows a live countdown beside the close time.
- Countdown format includes hours, minutes and seconds.
- Handles events crossing midnight, such as 19:30 - 01:30.
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v44 Active Card Countdown Fix
