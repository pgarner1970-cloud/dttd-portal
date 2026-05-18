# Dance Thru the Decades Portal v156 - Admin Asset Domain Hotfix

## Urgent demo fix

This fixes the unstyled DJ Portal on:

```text
https://dj.dancethruthedecades.co.uk/
```

## What changed

Because the `dj.` subdomain points directly at `/admin`, root-relative links such as:

```text
/assets/...
```

resolve incorrectly on the subdomain.

This build forces admin-side CSS/JS/images/uploads to use the full main-domain URL:

```text
https://dancethruthedecades.co.uk/assets/...
https://dancethruthedecades.co.uk/uploads/...
```

Admin page navigation remains relative, so links such as:

```text
events.php
requests.php
settings.php
```

continue to work on the `dj.` subdomain.

## SQL

No SQL changes.

## Suggested Git commit title

v156 Admin Asset Domain Hotfix
