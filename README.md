# Dance Thru the Decades Portal v70 - Header Timers Visible Fix

## Changes

- Fixes header timers not appearing.
- Adds a dedicated header timer cluster beside the live clock/date.
- Timers render as visible placeholders first, then JavaScript fills them from:
  - /admin/header-timers.php
- Keeps event/timing lookup out of shared header rendering to avoid 500 errors.
- Timer colours:
  - Green normally
  - Amber under 30 minutes
  - Red under 15 minutes
- Timers respect Settings toggles:
  - header_show_event_timer
  - header_show_request_timer
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v70 Header Timers Visible Fix
