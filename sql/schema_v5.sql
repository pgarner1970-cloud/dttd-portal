/*
Portal v5 migration.
Run these statements in Navicat/phpMyAdmin.

If your MySQL version does not support ADD COLUMN IF NOT EXISTS,
run the individual ALTER lines only for columns that do not already exist.
*/

ALTER TABLE events ADD COLUMN event_type ENUM('public','private_party','wedding','corporate') NOT NULL DEFAULT 'public' AFTER venue_name;
ALTER TABLE events ADD COLUMN start_time TIME NULL AFTER event_date;
ALTER TABLE events ADD COLUMN end_time TIME NULL AFTER start_time;
ALTER TABLE events ADD COLUMN requests_close_minutes INT NOT NULL DEFAULT 30 AFTER end_time;
ALTER TABLE events ADD COLUMN requests_close_at DATETIME NULL AFTER portal_available_until;

/*
If any of the columns above already exist and Navicat reports a duplicate column error,
skip that line and continue with the remaining lines.
*/
