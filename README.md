# Dance Thru the Decades Portal v102 - Event Image Preview By Edit

## Changes

- Moves the event image thumbnail on the Events list.
- Thumbnail now appears in the right-hand action area beside the Edit button.
- Prevents event row layout breaking when an image is present.
- Keeps the event image upload on `event-edit.php`.
- No SQL changes beyond the existing `event_image` column.

## SQL

If not already run:

```sql
ALTER TABLE events
ADD COLUMN event_image VARCHAR(255) NULL;
```

If already run, no SQL is needed.

## Suggested Git commit title

v102 Event Image Preview By Edit
