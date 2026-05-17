ALTER TABLE events ADD COLUMN portal_available_from DATETIME NULL AFTER event_date;
ALTER TABLE events ADD COLUMN portal_available_until DATETIME NULL AFTER portal_available_from;
ALTER TABLE events ADD COLUMN notes TEXT NULL AFTER queue_visibility;
