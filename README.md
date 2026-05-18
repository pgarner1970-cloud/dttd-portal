# Dance Thru the Decades Portal v155 - Admin Assets Hotfix

## Urgent Fix

This hotfix restores admin asset paths so the DJ Portal styling/scripts work correctly when using:

```text
https://dj.dancethruthedecades.co.uk/
```

while keeping the fallback admin URL working:

```text
https://dancethruthedecades.co.uk/admin/
```

## What changed

- Admin navigation/redirects remain relative for the DJ subdomain.
- Shared assets are restored to root-relative paths:
  - `/assets/...`
  - `/uploads/...`

This is important because the `assets` folder is still at the main site root, not inside `/admin`.

## SQL

No SQL changes.

## Suggested Git commit title

v155 Admin Assets Hotfix
