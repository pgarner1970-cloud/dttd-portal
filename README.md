# Dance Thru the Decades Portal v150 - Platform Structure Update

## Changes

- Adds reusable public navigation header:
  - Home
  - Events
  - Gallery
  - Facebook
- Removes the floating public Home/DJ Login button styling from public pages.
- Keeps DJ/admin access through the `dj.dancethruthedecades.co.uk` subdomain.
- Adds support for stable public event slugs.
- Adds support for event status:
  - draft
  - scheduled
  - live
  - ended
  - cancelled
  - private
- Cancelled public events remain viewable and show a cancellation notice.
- Draft/private events are excluded from public event lists and slug lookup.
- Keeps event codes hidden and only available for QR/private access.
- Keeps SEO URLs:
  - `/events`
  - `/events/event-slug`
- Event detail pages display descriptions where available.
- Flyer images continue to use non-cropped contain display.
- Embedded venue map retained.

## SQL

Recommended SQL included:

```text
sql/v150_event_status_public_slug.sql
```

## Suggested Git commit title

v150 Platform Structure Update
