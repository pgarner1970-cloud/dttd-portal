# Dance Thru the Decades Portal v69 - Queue Density Stable Groups

## Changes

- Adds stable request grouping using request_group_id.
- A played group now stays grouped after being marked Played.
- Later requests for the same song create or join a new open group.
- Open statuses that group together:
  - pending
  - maybe
  - duplicate
- Final statuses no longer absorb later requests:
  - played
  - rejected
- Admin test request tool now assigns request_group_id when the column exists.
- Existing records are automatically assigned group IDs on Requests page load after SQL is applied.
- Further tightens request queue vertical padding and spacing.

## SQL to run

Run this once before deploying or immediately after deploying:

```sql
ALTER TABLE song_requests
ADD COLUMN request_group_id VARCHAR(64) NULL;

CREATE INDEX idx_song_requests_group
ON song_requests (event_id, request_group_id);
```

A copy is included at:

```text
sql/v69_request_group_id.sql
```

## Suggested Git commit title

v69 Queue Density Stable Groups
