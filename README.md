# Dance Thru the Decades Portal v154 - Admin Subdomain Paths

## Changes

- Updates admin/DJ Portal links to work when the admin folder is the document root of:

```text
https://dj.dancethruthedecades.co.uk
```

- Replaces common hardcoded `/admin/...` links with relative admin paths.
- Updates common redirects from `/admin/login.php` to `login.php`.
- Adds `admin/.htaccess` with:

```apache
DirectoryIndex index.php login.php
```

- Adds future helper file:

```text
includes/admin-paths.php
```

This helper can later be used for cleaner dual support:
- main domain `/admin/`
- DJ subdomain `/`

## Testing

Try:

```text
https://dj.dancethruthedecades.co.uk/
https://dj.dancethruthedecades.co.uk/login.php
```

Also check fallback still works:

```text
https://dancethruthedecades.co.uk/admin/
```

## SQL

No SQL changes.

## Suggested Git commit title

v154 Admin Subdomain Paths
