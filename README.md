# Dance Thru the Decades Portal v73 - Stable Grouping Modal Fix

## Changes

- Fixes played/rejected groups splitting back into individual records.
- If request_group_id exists, grouping always uses request_group_id regardless of status.
- Played groups remain grouped.
- Rejected groups remain grouped.
- Reject button now opens the reject reason modal from the actual action loop.
- Reject modal submits status=rejected and reject_reason.
- No SQL changes.

## SQL

No SQL to run.

## Suggested Git commit title

v73 Stable Grouping Modal Fix
