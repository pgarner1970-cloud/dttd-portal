# Dance Thru the Decades Portal v86 - Header Timers Final

## Changes

- Enables the existing shared header timer placeholders correctly.
- Adds live event and request-close timers beside the main clock.
- Timers appear on all admin pages using the shared header.
- Timers respect Settings toggles:
  - header_show_event_timer
  - header_show_request_timer
- Timer values update every second.
- Timer data refreshes from the server every 60 seconds.
- Timer colours:
  - Green normally
  - Amber under 30 minutes
  - Red under 15 minutes
- Event lookup is handled by /admin/header-timers.php.
- No SQL changes.

## SQL

No SQL to run.

## Suggested Git commit title

v86 Header Timers Final
