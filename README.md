# Dance Thru the Decades Portal v94 - Header Nav Position Fix

## Changes

- Rebuilds from the safer v92 base rather than v93.
- Fixes header navigation moving toward the centre when timers are disabled.
- Keeps:
  - DJ Portal logo pinned left
  - live clock/timers centred
  - nav/buttons pinned right
- Keeps navigation links clickable.
- Hides timers on narrower screens before layout becomes crowded.
- Keeps event image upload changes from v92.
- No SQL changes beyond v92 event image column.

## SQL

If you have not already run the v92 SQL, run:

```sql
ALTER TABLE events
ADD COLUMN event_image VARCHAR(255) NULL;
```

If already run, no SQL is needed.

## Suggested Git commit title

v94 Header Nav Position Fix
