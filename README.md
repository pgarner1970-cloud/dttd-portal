# Dance Thru the Decades Portal v53 - Requests Layout Settings

## Changes

- Adds Requests Page Layout setting to Settings page.
- Layout options:
  - Event left, queue right
  - Queue left, event right
  - Queue only
- Each option has a simple visual layout icon.
- Requests page reads app_settings.requests_layout and adjusts layout automatically.
- Settings button remains linked to /admin/settings.php.
- No SQL included because app_settings table has already been created.

## SQL

No SQL to run.

## Suggested Git commit title

v53 Requests Layout Settings
