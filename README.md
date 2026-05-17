# Dance Thru the Decades Portal v22 - Event Current State Fix

## Changes

- Only one event is shown visually as Current.
- Green row border is only used for the current event.
- Future/non-current events use amber row border.
- Past events use red row border.
- “Make Current” only appears on non-current events dated today or in the future.
- Past events cannot be made current from the Events page.
- Fixed Requests tile link so it explicitly opens `/admin/index.php`.
- Included `/admin/index.php` in the ZIP so the Requests page is present.

## File note

The Requests page is:

`/admin/index.php`

There is no `/admin/requests.php` in this build.

## SQL

No SQL to run.

## Suggested Git commit title

v22 Event Current State Fix
