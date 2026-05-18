# Dance Thru the Decades Portal v107 - QR Always Visible

## Changes

- Makes QR access visible even when an event has no `event_code` yet.
- Events page always shows a QR button beside Edit.
- Event edit page always shows a QR Code & Event Code card for existing events.
- If no event code exists, the QR card shows a warning instead of being hidden.
- Adds an Event Code field under Portal Behaviour if missing.
- If Event Code is left blank on save, the system auto-generates one.
- Keeps existing request queue/event image work unchanged.

## SQL

No SQL changes.

## Suggested Git commit title

v107 QR Always Visible
