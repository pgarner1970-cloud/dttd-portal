# Dance Thru the Decades Portal v80 - Merge Self Exclusion Fix

## Changes

- Fixes the merge modal showing the current group as a merge destination.
- Self-exclusion is now performed in PHP before candidate cards are generated.
- JavaScript also filters out the current group as a second safety check.
- Compares:
  - full group key
  - group_id
  - gid-stripped values
- Does not change the main queue rendering or button layout.
- No SQL changes.

## SQL

No SQL to run.

## Suggested Git commit title

v80 Merge Self Exclusion Fix
