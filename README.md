# Dance Thru the Decades Portal v68 - Safe Header Timers

## Changes

- Re-adds header timers safely without putting event/database timing logic directly into the shared header.
- Shared header only renders placeholder timer slots based on settings.
- New endpoint provides timer data:
  - /admin/header-timers.php
- New JavaScript updates timers:
  - /assets/header-timers.js
- Timer colours:
  - Green normally
  - Amber under 30 minutes
  - Red under 15 minutes
- Settings toggles continue to control:
  - header_show_event_timer
  - header_show_request_timer
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v68 Safe Header Timers
