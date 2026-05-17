# Dance Thru the Decades Portal v27 - Gate Song Requests

## Changes

- Song requests now require a valid event access code or token.
- `/request.php` no longer accepts public/random direct access for guest submissions.
- Valid guest flow:
  - `/event.php?code=ABC123`
  - then Request a Song from that event page
- Event landing page now links to:
  - `/request.php?code=ABC123`
- Public homepage routes guests to the event portal instead of directly to request form.
- Existing admin dashboard is not changed.

## SQL

No SQL to run.

## Suggested Git commit title

v27 Gate Song Requests
