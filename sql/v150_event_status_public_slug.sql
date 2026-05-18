-- v150 optional but recommended database update

ALTER TABLE events
ADD COLUMN public_slug VARCHAR(255) NULL;

ALTER TABLE events
ADD COLUMN status ENUM('draft','scheduled','live','ended','cancelled','private') DEFAULT 'scheduled';

ALTER TABLE events
ADD COLUMN cancelled_message TEXT NULL;

-- Optional index for faster public slug lookup if you commit to using public_slug
CREATE INDEX idx_events_public_slug ON events (public_slug);
CREATE INDEX idx_events_status ON events (status);
