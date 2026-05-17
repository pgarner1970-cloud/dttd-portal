# Dance Thru the Decades Portal v101 - Event Edit Layout Restore

## Changes

- Keeps the correct `event-edit.php` page.
- Restores the add/edit form to a card-based layout similar to the original:
  - proper section cards
  - blue icon tiles
  - improved spacing
  - better input styling
- Keeps Event Image / Flyer upload on the edit page.
- Keeps `events-edit.php` as an alias.
- Does not move the upload back to the Events list page.
- No SQL changes beyond the event_image column if not already applied.

## SQL

Run once if not already done:

```sql
ALTER TABLE events
ADD COLUMN event_image VARCHAR(255) NULL;
```

If already run, no SQL is needed.

## Suggested Git commit title

v101 Event Edit Layout Restore
