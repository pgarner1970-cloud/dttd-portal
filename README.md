# Dance Thru the Decades Portal v145 - Current Event Time Detection

## Changes

- Fixes homepage live-event detection.
- `is_active = 1` is no longer treated as "currently live".
- Homepage only shows live-event actions when current server time is within:
  - event_date + start_time
  - event_date + end_time
- Handles events that end after midnight.
- If no event is live, homepage returns to:
  - Upcoming Events
  - Photos & Memories
  - Follow Us
- Adds note:
  - Song requests open automatically when an event is live.

## SQL

No SQL changes.

## Suggested Git commit title

v145 Current Event Time Detection
