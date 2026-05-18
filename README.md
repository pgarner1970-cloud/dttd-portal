# Dance Thru the Decades Portal v106 - Event QR Code Access

## Changes

- Adds QR access for events.
- Events page now has a QR action for events with an event code.
- Event edit page shows:
  - Event code
  - Public request URL
  - QR preview
  - Print QR
  - Download/open PNG
  - Copy link
- Adds a dedicated QR page:

```text
admin/event-qr.php?id=EVENT_ID
```

- QR code is generated in the browser using the public request URL.
- No SQL changes.

## Notes

The generated public request URL is:

```text
/request.php?code=EVENT_CODE
```

If a future public route differs, update the URL construction in `event-edit.php` and `event-qr.php`.

## SQL

No SQL to run.

## Suggested Git commit title

v106 Event QR Code Access
