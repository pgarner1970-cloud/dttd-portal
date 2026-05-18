ALTER TABLE events
ADD COLUMN venue_ticket_url VARCHAR(255) NULL;

ALTER TABLE venues
ADD COLUMN venue_ticket_url VARCHAR(255) NULL;
