# Dance Thru the Decades Portal v63 - Open Grouping Fix

## Changes

- Fixes request grouping for songs requested again later in the evening.
- Open requests still group together when song title and artist match.
- Final requests no longer absorb new requests.
- Final statuses are:
  - played
  - rejected
- Open statuses are:
  - pending
  - maybe
  - duplicate
- Example:
  - September / Earth Wind and Fire is marked Played
  - someone requests September again later
  - the new request now appears as a separate Pending queue item
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v63 Open Grouping Fix
