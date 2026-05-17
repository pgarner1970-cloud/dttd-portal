# Dance Thru the Decades Portal v74 - Merge Modal

## Changes

- Changes the Duplicate action label to Merge.
- Merge now opens a touch-friendly modal rather than setting status to duplicate.
- Modal shows open queue groups only:
  - pending
  - maybe
  - duplicate
- The current group is excluded from the target list.
- Likely matches are sorted higher in the modal.
- Submitting a merge updates the source group's request_group_id to the selected target group.
- Requester names, messages, timestamps and records are preserved.
- Played and rejected groups are not valid merge targets.
- No SQL changes.

## SQL

No SQL to run.

## Suggested Git commit title

v74 Merge Modal
