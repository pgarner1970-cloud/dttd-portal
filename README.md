# Dance Thru the Decades Portal v148 - Public Events Detail Fix

## Changes

- Fixes public event detail links using `/event.php?id=...`.
- Rebuilds `event.php` to support:
  - public `id` links
  - private/QR `code` links
  - no public event-code display
- Removes duplicated/unstyled Home link on event pages.
- Improves event detail page styling to match the public site.
- Adds map button when venue/address/postcode exists.
- Adds buttons for:
  - Our Facebook
  - Venue Facebook, when available
  - Venue Website, when available
  - Tickets, when available
- Widens the public events list cards.
- Improves event image fallback if the uploaded flyer is missing/broken.

## SQL

No SQL changes.

## Suggested Git commit title

v148 Public Events Detail Fix
