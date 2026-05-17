# Dance Thru the Decades Portal v39 - Automatic Current Event

## Changes

- Requests page now automatically chooses the current event using event date/start/end time.
- Current event rule:
  - current from 1 hour before start time
  - current until end time
  - if end time is missing, current until start + 6 hours
- If no event is currently live, Requests page shows the next upcoming event.
- Removed the Selected Event dropdown from the Requests page.
- Events page now uses the same calculated state:
  - Green = current
  - Amber = upcoming
  - Red = past
- Removed manual Make Current action from Events page.
- Events page now shows Current / Upcoming / Past as labels.
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v39 Automatic Current Event
