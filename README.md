# Dance Thru the Decades Portal v151 - Public Footer

## Changes

- Adds reusable public footer:
  - `includes/public-footer.php`
- Adds footer to public pages.
- Footer includes:
  - brand/logo
  - Home
  - Events
  - Gallery
  - Privacy
  - Terms
  - Facebook link
  - Website provided by Yellow Arrow
- WhatsApp is supported as an optional future variable, but not shown unless a URL is explicitly configured.
- Avoids exposing a personal phone number.
- Bumps public-site.css cache version to v151.

## WhatsApp note

For now, WhatsApp is intentionally not displayed unless `$whatsappUrl` is set. This avoids putting a personal phone number directly on the public site.

## SQL

No SQL changes.

## Suggested Git commit title

v151 Public Footer
