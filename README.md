# Dance Thru the Decades Portal v3

This version adds the first working PHP/MySQL song request system.

## What is included

- Branded portal homepage
- Manual song request form
- MySQL storage
- DJ/admin dashboard
- Event settings
- Privacy page
- Guest Wi-Fi terms page
- SQL schema

## Install on 20i

1. Upload/push these files to GitHub.
2. Deploy through 20i Git Version Control.
3. Create a MySQL database in 20i.
4. Import `sql/schema.sql` using phpMyAdmin.
5. Copy:

   `includes/config.example.php`

   to:

   `includes/config.php`

6. Edit `includes/config.php` with your MySQL database name, user and password.
7. Change `ADMIN_PASSWORD`.

## Admin URL

`/admin/`

## API song search later

The request system is structured with a `source` field, so later API lookups can store whether a request came from manual entry or a music API such as Spotify, Deezer, Last.fm or MusicBrainz.
