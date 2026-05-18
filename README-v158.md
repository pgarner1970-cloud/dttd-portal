# v158 Request Queue Polling Fix

## Fixes

- Restores request queue polling attributes on the real `admin/requests.php` queue page.
- Restores the `requestUpdateBanner` on the real queue view.
- Changes polling endpoint from:

```text
/admin/request-ping.php
```

to relative:

```text
request-ping.php
```

This works on both:

```text
https://dj.dancethruthedecades.co.uk/requests.php
https://dancethruthedecades.co.uk/admin/requests.php
```

- External `request-update-check.js` also now reads `data-event-id`.

## SQL

No SQL changes.

## Suggested Git commit title

v158 Request Queue Polling Fix
