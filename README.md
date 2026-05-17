# Dance Thru the Decades Portal v97 - Explicit Header Columns

## Changes

- Replaces the shared header markup with explicit three-column structure:
  - Left: DJ Portal logo
  - Centre: clock/date and optional timers
  - Right: Requests, Events, Settings, Home and Logout
- Fixes nav buttons drifting into the centre when timers are off.
- Keeps nav buttons clickable.
- Keeps event image upload support from v96.
- No SQL changes beyond the event image column if not already applied.

## SQL

If not already run, run:

```sql
ALTER TABLE events
ADD COLUMN event_image VARCHAR(255) NULL;
```

If already run, no SQL is needed.

## Suggested Git commit title

v97 Explicit Header Columns
