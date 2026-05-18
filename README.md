# Dance Thru the Decades Portal v124 - Public/Admin CSS Separation

## Changes

- Adds a dedicated public-facing stylesheet:

```text
assets/public.css
```

- Public-facing PHP pages outside `/admin` now load `public.css` where they have a normal `<head>` section.
- Public pages no longer load `admin-touch.css`.
- Admin/DJ Portal pages continue to use the admin stylesheet only:

```text
assets/admin-touch.css
```

- Removes any accidental public stylesheet include from `/admin` PHP files.
- Keeps `event-edit.php`, `venues.php`, and `venue-edit.php` as admin-only pages.

## Public files updated

- event.php
- request.php

## SQL

No SQL changes.

## Suggested Git commit title

v124 Public Admin CSS Separation
