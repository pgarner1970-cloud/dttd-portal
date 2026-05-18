# Dance Thru the Decades Portal v103 - Test Request Dedication Fix

## Changes

- Fixes Add Test Request not saving Message / Dedication.
- The admin test request handler now reads:
  - dedication
  - message
  - test_message
- Saves the value into `song_requests.dedication`.
- Keeps request queue, event image upload and header layout unchanged.

## SQL

No SQL changes.

## Suggested Git commit title

v103 Test Request Dedication Fix
