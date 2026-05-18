# Dance Thru the Decades Portal v113 - Venue Database Selection

## Changes

Adds reusable venue support to `event-edit.php`.

### Event edit page
- Adds a **Select saved venue** dropdown in Venue Details & Social.
- Selecting a saved venue auto-fills:
  - Venue name
  - Address
  - Postcode
  - Facebook URL
  - Website URL
  - Instagram URL
  - Social display label
- Leaving the dropdown as **Create new / manual venue** creates a new venue record when saving the event.
- If an existing venue is selected, its details are updated from the form when the event is saved.
- Stores `events.venue_id` where that column exists.
- Keeps event-level venue fields as fallback for compatibility.

## SQL

You said the venues table has already been added.

This build expects:
- `venues` table
- `events.venue_id` column

No SQL included.

## Suggested Git commit title

v113 Venue Database Selection
