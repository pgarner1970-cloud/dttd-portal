# Dance Thru the Decades Portal v77 - Merge Self Exclude Status Restore

## Changes

- Fixes merge modal still offering the current group as a merge target.
- Adds stable group_id values to source groups and merge candidates.
- Merge modal compares:
  - full group key
  - stripped gid
  - explicit group_id
- Restores the status pill beside the request count where possible.
- Stops hiding status without replacement.
- Keeps stricter merge matching to avoid unrelated tracks.
- No SQL changes.

## SQL

No SQL to run.

## Suggested Git commit title

v77 Merge Self Exclude Status Restore
