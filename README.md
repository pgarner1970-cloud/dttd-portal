# Dance Thru the Decades Portal v96 - Event Image Upload Form Fix

## Why the upload field was not showing

The previous build patched the wrong file. The actual event add/edit form in this package is:

```text
admin/index.php
```

This version patches that file.

## Changes

- Adds event image/flyer upload to the event add/edit form.
- Updates the form to use `multipart/form-data`.
- Saves uploaded images to:

```text
uploads/events/
```

- Stores the image path in:

```text
events.event_image
```

- Shows existing image preview when editing an event.
- Adds optional thumbnail support on the Events list.
- Keeps request queue, merge, reject and header logic unchanged.

## SQL

If not already run, run:

```sql
ALTER TABLE events
ADD COLUMN event_image VARCHAR(255) NULL;
```

A copy is included at:

```text
sql/v92_event_image.sql
```

If you already added this column, no SQL is needed.

## Suggested Git commit title

v96 Event Image Upload Form Fix
