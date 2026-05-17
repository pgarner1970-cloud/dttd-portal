ALTER TABLE song_requests
ADD COLUMN request_group_id VARCHAR(64) NULL;

CREATE INDEX idx_song_requests_group
ON song_requests (event_id, request_group_id);
