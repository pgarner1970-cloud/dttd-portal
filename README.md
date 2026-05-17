# Dance Thru the Decades Portal v71 - Reject Reason Modal

## Changes

- Replaces immediate Reject action with a touch-friendly modal.
- Reject reasons:
  - Not suitable
  - Explicit / inappropriate
  - Already played
  - Time constraints
  - Not available
- Submitting a reason sets:
  - status = rejected
  - reject_reason = selected reason
- Other queue actions remain unchanged.
- No SQL included because reject_reason has already been added.

## SQL

No SQL to run.

## Suggested Git commit title

v71 Reject Reason Modal
