# Dance Thru the Decades Portal v21 - Event Validation Active Toggle

## Changes

- Event date is now required.
- Start time is now required.
- End time is optional.
- If no end time is supplied, request close time is not automatically calculated.
- Prevents overlapping events on the same date when both events have start and end times.
- Events page rows are more compact.
- Removed Active badge and request count badge from Events page.
- Events page now uses row border colour:
  - Green = active/current event
  - Amber = future/upcoming event
  - Red = past event
- Events page actions are now:
  - Edit
  - Set Active / Current
- Removed Requests and Guest buttons from the Events page rows.
- Set Active deactivates all other events and makes the selected event active.

## SQL

No SQL to run.

## Suggested Git commit title

v21 Event Validation Active Toggle
