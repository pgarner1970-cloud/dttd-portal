# Dance Thru the Decades Portal v109 - Venue Social Details

## Changes

Adds a new **Venue Details & Social** section to the event add/edit page.

New fields supported:
- Venue address
- Venue postcode
- Venue Facebook URL
- Venue website URL
- Venue Instagram URL
- Social display label

Also adds a Google Maps link when a postcode is present.

## SQL

You said this SQL has already been run:

```sql
ALTER TABLE events
ADD COLUMN venue_address VARCHAR(255) NULL,
ADD COLUMN venue_postcode VARCHAR(32) NULL,
ADD COLUMN venue_facebook_url VARCHAR(255) NULL,
ADD COLUMN venue_website_url VARCHAR(255) NULL,
ADD COLUMN venue_instagram_url VARCHAR(255) NULL,
ADD COLUMN venue_social_label VARCHAR(100) NULL;
```

No further SQL required.

## Suggested Git commit title

v109 Venue Social Details
