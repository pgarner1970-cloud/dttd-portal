# Dance Thru the Decades Portal v92 - Event Image Upload

## Changes

- Adds support for uploading an event flyer/image.
- Uploads are stored in:
  - uploads/events/
- Supported formats:
  - JPG
  - PNG
  - WebP
  - GIF
- Event edit/create form includes an image upload field when the database column exists.
- Existing event images show a small preview in the form.
- Event list cards can show a small flyer thumbnail.
- Keeps request queue, merge, reject and header timer logic unchanged.

## SQL to run

Run once:

```sql
ALTER TABLE events
ADD COLUMN event_image VARCHAR(255) NULL;
```

A copy is included at:

```text
sql/v92_event_image.sql
```

## Suggested Git commit title

v92 Event Image Upload
