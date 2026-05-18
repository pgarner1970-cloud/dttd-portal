# Dance Thru the Decades Portal v115 - Event Venue Cleanup Ticketing

## Changes

- Removes duplicate Venue name field from the top Event Details section.
- Moves/keeps Venue name within Venue Details & Social.
- Makes Notes larger to use the freed-up event detail space.
- When selecting **Create new / manual venue**, the saved venue fields are cleared.
- Renames confusing Social Display Label to **Venue display label**.
- Adds **Ticketing URL** field for venues/events.
- Keeps `event-edit.php` as the only event edit script.
- Does not recreate `events-edit.php`.

## SQL

Run this once if the columns do not already exist:

```sql
ALTER TABLE events
ADD COLUMN venue_ticket_url VARCHAR(255) NULL;

ALTER TABLE venues
ADD COLUMN venue_ticket_url VARCHAR(255) NULL;
```

A copy is included in:

```text
sql/v115_ticketing_url.sql
```

## Suggested Git commit title

v115 Event Venue Cleanup Ticketing
