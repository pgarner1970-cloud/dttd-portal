CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_name VARCHAR(190) NOT NULL,
  venue_name VARCHAR(190) NOT NULL,
  event_type ENUM('public','private_party','wedding','corporate') NOT NULL DEFAULT 'public',
  event_date DATE NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  requests_close_minutes INT NOT NULL DEFAULT 30,
  portal_available_from DATETIME NULL,
  portal_available_until DATETIME NULL,
  requests_close_at DATETIME NULL,
  queue_visibility ENUM('venue','public','private') NOT NULL DEFAULT 'venue',
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS song_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  guest_name VARCHAR(120) NOT NULL,
  song_title VARCHAR(190) NOT NULL,
  artist VARCHAR(190) NOT NULL,
  dedication TEXT NULL,
  source ENUM('manual','api') NOT NULL DEFAULT 'manual',
  status ENUM('pending','played','rejected','duplicate','maybe') NOT NULL DEFAULT 'pending',
  admin_note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX(event_id),
  INDEX(status),
  CONSTRAINT fk_song_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
