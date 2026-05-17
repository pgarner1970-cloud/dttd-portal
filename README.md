# Dance Thru the Decades Portal v56 - Queue Update Detection Fix

## Changes

- Fixes request queue update detection.
- Ping endpoint now creates a robust fingerprint from:
  - request IDs
  - statuses
  - guest names
  - song titles
  - artists
  - messages
  - status counts
- Detection no longer relies only on created_at/updated_at fields.
- Requests page initial fingerprint now uses the same logic as the ping endpoint.
- If another request is added in the database, the DJ should see the update banner.
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v56 Queue Update Detection Fix
