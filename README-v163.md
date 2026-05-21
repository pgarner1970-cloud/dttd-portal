# v163 Restore Admin Events Page

## Emergency Fix

Restores `admin/events.php` from the uploaded live ZIP and fixes the PHP opening tag issue.

Also keeps safe subdomain changes:
- admin links are relative
- assets load from main domain

## Diagnostic page

If `events.php` still appears blank, open:

```text
https://dj.dancethruthedecades.co.uk/events-diagnostic.php
```

That page should show the PHP error instead of a blank page.

Remove `events-diagnostic.php` after testing.

## SQL

No SQL changes.

## Suggested Git commit title

v163 Restore Admin Events Page
