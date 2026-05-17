# Dance Thru the Decades Portal v64 - Group Action Fix

## Changes

- Fixes action buttons on grouped requests after the open/final grouping change.
- Open groups can now be marked:
  - Played
  - Maybe
  - Duplicate
  - Reject
- Open group actions only update open requests:
  - pending
  - maybe
  - duplicate
- Old played/rejected requests are not changed when a new request for the same song comes in later.
- Final groups are handled by request ID when needed.
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v64 Group Action Fix
