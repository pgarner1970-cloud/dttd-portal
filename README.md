# Dance Thru the Decades Portal v143 - Dynamic Homepage State

## Changes

- Adds dynamic homepage state detection:
  - no active event
  - active public event
  - active private event
- Homepage action cards now change depending on current event state.
- Request a Song is hidden/replaced when there is no active event.
- No-event mode shows:
  - Upcoming Events
  - Photos & Memories
  - Follow Us
- Public event mode shows:
  - Request a Song
  - This Event
  - Upload Photos
- Private event mode shows:
  - Guest Requests
  - Upload Photos
  - Event Info
- Adds a fixed starburst background test.
- Keeps mobile handling for the fixed background.
- Bumps public-site.css cache version to v143.

## Notes

This build references future routes:
- `/events.php`
- `/gallery.php`

These can be built in the next phases.

## SQL

No SQL changes.

## Suggested Git commit title

v143 Dynamic Homepage State
