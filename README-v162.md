# v162 Events Blank Page Hotfix

## Fix

The admin Events page went blank after v161.

This hotfix:
- removes the risky shared upload helper include from `admin/events.php`
- adds a safe local display-only image URL helper
- keeps the upload repair helper available for `event-edit.php` and `repair-upload-paths.php`
- keeps the repair page available at:

```text
https://dj.dancethruthedecades.co.uk/repair-upload-paths.php
```

## SQL

No SQL changes.

## Suggested Git commit title

v162 Events Blank Page Hotfix
