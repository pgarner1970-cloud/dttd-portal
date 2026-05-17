# Dance Thru the Decades Portal v54 - Apply Requests Layout

## Changes

- Requests page now applies the layout selected in Settings.
- Supported layouts:
  - Event card left, queue right
  - Queue left, event card right
  - Queue only
- Correctly patches the real Requests page markup:
  - section.touch-grid
  - aside.active-event-panel
  - section.request-queue-panel
- CSS moves/hides the cards based on app_settings.requests_layout.
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v54 Apply Requests Layout
