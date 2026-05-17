# Dance Thru the Decades Portal v5 - Event Timing

## New in v5

- Simpler event timing:
  - event date
  - start time
  - end time
- Overnight events are calculated automatically.
  - Example: 19:30 to 01:30 becomes next-day finish.
- Requests can close before the event ends:
  - 15, 30, 45 or 60 minutes
- Event type added:
  - Public Night
  - Private Party
  - Wedding
  - Corporate Event
- DJ dashboard has a more tablet/laptop-friendly layout.
- Advanced/manual timing overrides are tucked away.

## Updating from v4

1. Back up your database.
2. Push/deploy these files.
3. Run `sql/schema_v5.sql` in Navicat/phpMyAdmin.
4. Keep your existing `includes/config.php`.
5. Visit `/admin/events.php` and edit your event to set the new fields.

## Fresh install

Run `sql/full_install.sql`.
