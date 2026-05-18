# Dance Thru the Decades Portal v149 - SEO Event URLs and Descriptions

## Changes

- Adds SEO-friendly public event routes:
  - `/events`
  - `/events/event-slug`
- Adds `.htaccess` rewrite rules:
  - `/events` -> `events.php`
  - `/events/{slug}` -> `event.php?slug={slug}`
- Public event list now links to SEO-friendly URLs.
- Public event detail page no longer uses numeric IDs.
- Event codes remain hidden and only work for QR/private access.
- Event detail page now displays a public description where available:
  - `public_description`
  - `event_description`
  - `description`
  - `public_notes`
- Flyers/images now use `object-fit: contain` so they are not cropped.
- Adds embedded Google Map below the event detail card.
- Mobile-friendly image and map sizing.

## SQL

No SQL changes required.

Optional future improvement:
- Add a dedicated `public_slug` column to events for fully controlled slugs.

## Suggested Git commit title

v149 SEO Event URLs and Descriptions
