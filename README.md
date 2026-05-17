# Dance Thru the Decades Portal v72 - Reject Modal Markup Fix

## Changes

- Fixes the issue where Reject still submitted immediately.
- The visible Reject button now opens the rejection reason modal.
- Selecting a modal reason updates:
  - status = rejected
  - reject_reason = selected reason
- No SQL required because reject_reason already exists.

## SQL

No SQL to run.

## Suggested Git commit title

v72 Reject Modal Markup Fix
