# Dance Thru the Decades Portal v66 - Header Timers Queue Compact

## Changes

- Adds configurable live header timers:
  - Event timer
  - Requests close timer
- Timers appear beside the existing live clock/date in the admin header.
- Timer colours:
  - Green normally
  - Amber under 30 minutes
  - Red under 15 minutes
- Settings page now includes a Header section to turn those timers on/off.
- Settings are stored in app_settings:
  - header_show_event_timer
  - header_show_request_timer
- Further tightens request queue row/card padding and spacing.
- No database schema changes.

## SQL

No SQL to run.

## Suggested Git commit title

v66 Header Timers Queue Compact
