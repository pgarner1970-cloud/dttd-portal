# Dance Thru the Decades Portal v100 - Event Image On Edit Page

## Fix

The image upload belongs on the add/edit event page, not `events.php`.

This build adds complete add/edit pages:

```text
admin/event-edit.php
admin/events-edit.php
```

Both files contain the Event Image / Flyer upload section.

## Changes

- Removes the misplaced upload section from `events.php`.
- Adds image upload to the event add/edit form.
- Supports both URL conventions:
  - `/admin/event-edit.php`
  - `/admin/events-edit.php`
- Saves uploaded images to:

```text
uploads/events/
```

- Saves image path to:

```text
events.event_image
```

- Keeps Events list clean.
- Keeps request queue/header changes untouched.

## SQL

Run once if not already done:

```sql
ALTER TABLE events
ADD COLUMN event_image VARCHAR(255) NULL;
```

If already run, no SQL is needed.

## Suggested Git commit title

v100 Event Image On Edit Page
