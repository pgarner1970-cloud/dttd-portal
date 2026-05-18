# Dance Thru the Decades Portal v104 - Test Request Save Message And Dedication

## Changes

- Fixes Add Test Request not saving the Message / Dedication text.
- The handler now reads the form field from:
  - message
  - dedication
  - test_message
- After inserting the test request, it updates the newly created row and writes the text into whichever columns exist:
  - song_requests.message
  - song_requests.dedication
- This avoids schema mismatch between older inserts and the queue display.
- No SQL changes.

## SQL

No SQL to run.

## Suggested Git commit title

v104 Test Request Save Message And Dedication
