# Dance Thru the Decades Portal v57 - 500 Fix Queue Detection

## Changes

- Fixes the 500 error caused by the previous queue detection update.
- Removes risky fingerprint generation from requests.php page render.
- Keeps robust queue change detection in /admin/request-ping.php.
- First ping sets the baseline fingerprint.
- Later pings show the update banner when the queue changes.
- request-ping.php now reads all request columns safely and tolerates column-name differences.
- No database changes.

## SQL

No SQL to run.

## Suggested Git commit title

v57 500 Fix Queue Detection
