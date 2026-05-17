# Dance Thru the Decades Portal v7 - Admin Action Fix

## Fixes

- Fixed admin request action buttons returning a blank page.
- Changed POST field from `action` to `request_action`.
- Added output buffering in admin auth.
- Improved request ordering.

## Sort order

Requests are sorted as:

1. pending
2. maybe
3. duplicate
4. played
5. rejected

Within each status, oldest requests are shown first.
This keeps pending requests as a proper DJ queue.

## SQL

No SQL changes.
