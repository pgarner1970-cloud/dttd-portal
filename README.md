# Dance Thru the Decades Portal v99 - Event Image Upload Corrected

## What was wrong

The upload section was being hidden behind the database-column check and/or inserted in the wrong place. This build inspects and patches the actual rendered `admin/events.php` edit form.

## Changes

- Adds a visible Event Image / Flyer card directly between:
  - Event Details
  - Timing
- The field is visible even if the database column has not been added yet.
- If the column is missing, the page shows the SQL warning instead of hiding the field.
- Adds file upload support to the form.
- Saves uploads into:

```text
uploads/events/
```

- Saves uploaded image path into:

```text
events.event_image
```

- No request queue/header layout changes.

## SQL

Run once if not already done:

```sql
ALTER TABLE events
ADD COLUMN event_image VARCHAR(255) NULL;
```

A copy is included at:

```text
sql/v92_event_image.sql
```

## Suggested Git commit title

v99 Event Image Upload Corrected
