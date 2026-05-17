# Dance Thru the Decades Portal v79 - Restore Queue Safe Merge Candidates

## Changes

- Rolls back the broken v78 queue rendering approach.
- Keeps main queue cards and action buttons intact.
- Builds merge candidates per source group in hidden server-rendered templates.
- Current group is excluded in PHP before candidates are shown.
- Merge modal no longer uses one shared candidate list.
- Avoids JSON in HTML attributes, which caused layout/action issues.
- Status pill is no longer hidden unless inline placement succeeds.
- No SQL changes.

## SQL

No SQL to run.

## Suggested Git commit title

v79 Restore Queue Safe Merge Candidates
