# Dance Thru the Decades Portal v95 - Header Wrapper Fix

## Changes

- Rebuilds from the safer v92 base.
- Fixes header buttons moving into the centre when timers are switched off.
- Ensures the centre wrapper contains only:
  - live clock/date
  - optional event timer
  - optional request-close timer
- Keeps Requests, Events, Settings, Home and Logout in the independent right-hand button area.
- Keeps nav buttons clickable.
- Keeps event image upload support from v92.
- No SQL changes beyond v92 event image column.

## SQL

If you have not already run the v92 SQL, run:

```sql
ALTER TABLE events
ADD COLUMN event_image VARCHAR(255) NULL;
```

If already run, no SQL is needed.

## Suggested Git commit title

v95 Header Wrapper Fix
