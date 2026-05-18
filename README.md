# Dance Thru the Decades Portal v105 - Test Request Message Insert Fix

## Changes

- Fixes Add Test Request failing after the previous dedication patch.
- Restores the `$message` variable.
- Replaces the test request insert with schema-aware insert logic.
- Saves message text into whichever columns exist:
  - `message`
  - `dedication`
- Uses optional columns only if present:
  - `request_group_id`
  - `created_at`
  - `updated_at`
- No SQL changes.

## SQL

No SQL to run.

## Suggested Git commit title

v105 Test Request Message Insert Fix
