<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/track-history.php';

dttd_no_cache_headers();
header('Content-Type: application/json; charset=utf-8');

function dttd_display_json($payload) {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function dttd_display_table_exists($table) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') return false;
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function dttd_display_col_exists($table, $column) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if ($table === '' || $column === '') return false;
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (!dttd_display_table_exists($table)) return $cache[$key] = false;
    try {
        $stmt = db()->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
        $stmt->execute([$column]);
        return $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function dttd_display_url($path) {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    return '/' . ltrim($path, '/');
}

function dttd_display_external_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . ltrim($url, '/');
    return $url;
}

function dttd_display_event_by_code($code) {
    $code = trim((string)$code);
    if ($code === '' || !dttd_display_table_exists('events')) return null;
    try {
        $stmt = db()->prepare('SELECT * FROM events WHERE event_code = ? LIMIT 1');
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function dttd_display_event() {
    $eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : (isset($_GET['event']) ? (int)$_GET['event'] : 0);
    if ($eventId > 0) {
        try {
            $event = get_event($eventId);
            if ($event) return $event;
        } catch (Throwable $e) {}
    }

    if (!empty($_GET['code'])) {
        $event = dttd_display_event_by_code($_GET['code']);
        if ($event) return $event;
    }

    try {
        $event = active_event();
        if ($event) return $event;
    } catch (Throwable $e) {}

    if (!dttd_display_table_exists('events')) return null;

    try {
        $stmt = db()->query("
            SELECT *
            FROM events
            WHERE is_active = 1
              AND status IN ('scheduled','live','completed')
              AND event_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
              AND event_date <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            ORDER BY
              CASE WHEN status = 'live' THEN 0 ELSE 1 END ASC,
              event_date DESC,
              COALESCE(start_time, '00:00:00') DESC,
              id DESC
            LIMIT 12
        ");

        $now = time();
        foreach ($stmt->fetchAll() as $candidate) {
            $startTime = !empty($candidate['start_time']) ? (string)$candidate['start_time'] : '00:00:00';
            $startTs = !empty($candidate['event_date']) ? strtotime((string)$candidate['event_date'] . ' ' . $startTime) : 0;
            $endTs = dttd_display_event_end_timestamp($candidate);
            $status = strtolower(trim((string)($candidate['status'] ?? '')));

            if ($status === 'live') return $candidate;

            if ($startTs && $endTs) {
                $goodnightStarted = dttd_display_goodnight_started_at($candidate);
                $goodnightUntil = $goodnightStarted ? ($goodnightStarted + dttd_display_goodnight_window_seconds()) : 0;

                if ($now >= $startTs && $now <= ($endTs + dttd_display_goodnight_pre_end_seconds())) return $candidate;
                if ($goodnightUntil && $now <= $goodnightUntil) return $candidate;
                if ($now > $endTs && !dttd_display_decks_are_clear()) return $candidate;
            }
        }
    } catch (Throwable $e) {}

    try {
        $stmt = db()->query("
            SELECT *
            FROM events
            WHERE is_active = 1
              AND status IN ('scheduled','live')
            ORDER BY
              CASE WHEN event_date IS NULL THEN 1 ELSE 0 END ASC,
              event_date ASC,
              COALESCE(start_time, '00:00:00') ASC,
              id ASC
            LIMIT 1
        ");
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function dttd_display_event_payload($event) {
    if (!$event) return null;
    $joinUrl = !empty($event['event_code']) ? dttd_public_event_join_url($event['event_code'], 'event') : '';
    return [
        'id' => (int)($event['id'] ?? 0),
        'event_name' => (string)($event['event_name'] ?? 'Tonight\'s Event'),
        'venue_name' => (string)($event['venue_name'] ?? ''),
        'event_code' => (string)($event['event_code'] ?? ''),
        'event_type' => (string)($event['event_type'] ?? ''),
        'event_type_label' => function_exists('event_type_label') ? event_type_label($event['event_type'] ?? '') : (string)($event['event_type'] ?? ''),
        'event_date' => (string)($event['event_date'] ?? ''),
        'start_time' => isset($event['start_time']) ? substr((string)$event['start_time'], 0, 5) : '',
        'end_time' => isset($event['end_time']) ? substr((string)$event['end_time'], 0, 5) : '',
        'requests_close_at' => (string)($event['requests_close_at'] ?? ''),
        'is_public' => !empty($event['is_public']),
        'is_live_now' => function_exists('dttd_event_live_now') ? dttd_event_live_now($event) : false,
        'join_url' => $joinUrl,
        'qr_image_url' => $joinUrl !== '' ? 'https://api.qrserver.com/v1/create-qr-code/?size=640x640&margin=18&data=' . rawurlencode($joinUrl) : '',
    ];
}

function dttd_display_venue_payload($event) {
    if (!$event) return null;

    $venue = [];
    $venueId = (int)($event['venue_id'] ?? 0);
    if ($venueId > 0 && dttd_display_table_exists('venues')) {
        try {
            $stmt = db()->prepare('SELECT * FROM venues WHERE id = ? LIMIT 1');
            $stmt->execute([$venueId]);
            $venue = $stmt->fetch() ?: [];
        } catch (Throwable $e) {
            $venue = [];
        }
    }

    $pick = function($key) use ($event, $venue) {
        $value = isset($venue[$key]) ? trim((string)$venue[$key]) : '';
        if ($value !== '') return $value;
        return isset($event[$key]) ? trim((string)$event[$key]) : '';
    };

    $name = $pick('venue_name');
    if ($name === '') return null;

    $address = $pick('venue_address');
    $postcode = $pick('venue_postcode');
    $phone = $pick('venue_phone');
    if ($phone === '') $phone = $pick('phone');
    if ($phone === '') $phone = $pick('venue_telephone');

    $socialLabel = $pick('venue_social_label');

    $links = [];
    $addLink = function($label, $url) use (&$links) {
        $url = trim((string)$url);
        if ($url === '') return;
        if (!preg_match('~^https?://~i', $url)) return;
        $links[] = [
            'label' => $label,
            'url' => $url,
            'qr_image_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=460x460&margin=14&data=' . rawurlencode($url),
        ];
    };

    $addLink('Facebook', $pick('venue_facebook_url'));
    $addLink('Instagram', $pick('venue_instagram_url'));
    $addLink($socialLabel !== '' ? $socialLabel : 'Website', $pick('venue_website_url'));

    return [
        'name' => $name,
        'address' => $address,
        'postcode' => $postcode,
        'phone' => $phone,
        'social_label' => $socialLabel,
        'links' => $links,
        'has_details' => ($address !== '' || $postcode !== '' || $phone !== '' || !empty($links)),
    ];
}


function dttd_display_normalise_track_text($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $value);
    return trim((string)$value);
}

function dttd_display_track_match_key($title, $artist = '') {
    $title = dttd_display_normalise_track_text($title);
    $artist = dttd_display_normalise_track_text($artist);
    if ($title === '') return '';
    return $title . '|' . $artist;
}

function dttd_display_played_track_lookup($eventId) {
    $eventId = (int)$eventId;
    if ($eventId <= 0) return ['keys' => [], 'spotify' => [], 'request_ids' => [], 'rows' => []];

    $rows = [];
    try {
        if (function_exists('dttd_history_public_track_rows')) {
            $rows = dttd_history_public_track_rows($eventId, 120);
        }
    } catch (Throwable $e) {
        $rows = [];
    }

    if (!$rows && dttd_display_table_exists('event_track_history')) {
        try {
            $trackCol = dttd_display_col_exists('event_track_history', 'track_name') ? 'track_name' : (dttd_display_col_exists('event_track_history', 'song_title') ? 'song_title' : '');
            $artistCol = dttd_display_col_exists('event_track_history', 'artist_name') ? 'artist_name' : (dttd_display_col_exists('event_track_history', 'artist') ? 'artist' : '');
            $spotifyCol = dttd_display_col_exists('event_track_history', 'spotify_track_id') ? 'spotify_track_id' : '';
            $artworkCol = dttd_display_col_exists('event_track_history', 'artwork_url') ? 'artwork_url' : (dttd_display_col_exists('event_track_history', 'spotify_album_image') ? 'spotify_album_image' : '');
            $requestCol = dttd_display_col_exists('event_track_history', 'request_id') ? 'request_id' : '';
            $playedCol = dttd_display_col_exists('event_track_history', 'played_at') ? 'played_at' : (dttd_display_col_exists('event_track_history', 'created_at') ? 'created_at' : '');

            if ($trackCol !== '') {
                $select = ["`$trackCol` AS song_title"];
                $select[] = $artistCol !== '' ? "`$artistCol` AS artist" : "'' AS artist";
                $select[] = $spotifyCol !== '' ? "`$spotifyCol` AS spotify_track_id" : "'' AS spotify_track_id";
                $select[] = $artworkCol !== '' ? "`$artworkCol` AS spotify_album_image" : "'' AS spotify_album_image";
                $select[] = $requestCol !== '' ? "`$requestCol` AS request_id" : "NULL AS request_id";
                $select[] = $playedCol !== '' ? "`$playedCol` AS created_at" : "NOW() AS created_at";

                $stmt = db()->prepare("
                    SELECT " . implode(', ', $select) . "
                    FROM event_track_history
                    WHERE event_id = ?
                    ORDER BY " . ($playedCol !== '' ? "`$playedCol`" : 'id') . " DESC, id DESC
                    LIMIT 120
                ");
                $stmt->execute([$eventId]);
                $rows = $stmt->fetchAll();
            }
        } catch (Throwable $e) {
            $rows = [];
        }
    }

    $lookup = ['keys' => [], 'spotify' => [], 'request_ids' => [], 'rows' => []];

    foreach ((array)$rows as $row) {
        $title = (string)($row['song_title'] ?? $row['track_name'] ?? $row['title'] ?? '');
        $artist = (string)($row['artist'] ?? $row['artist_name'] ?? '');
        $key = dttd_display_track_match_key($title, $artist);
        $fallbackKey = dttd_display_track_match_key($title, '');
        $spotify = trim((string)($row['spotify_track_id'] ?? $row['id'] ?? ''));
        $requestId = (int)($row['request_id'] ?? 0);
        $playedAt = (string)($row['created_at'] ?? $row['played_at'] ?? '');

        if ($key !== '') $lookup['keys'][$key] = $playedAt ?: date('c');
        if ($fallbackKey !== '') $lookup['keys'][$fallbackKey] = $playedAt ?: date('c');
        if ($spotify !== '') $lookup['spotify'][strtolower($spotify)] = $playedAt ?: date('c');
        if ($requestId > 0) $lookup['request_ids'][$requestId] = $playedAt ?: date('c');

        $lookup['rows'][] = $row;
    }

    return $lookup;
}

function dttd_display_request_played_at_from_lookup($row, $lookup) {
    $id = (int)($row['id'] ?? 0);
    if ($id > 0 && !empty($lookup['request_ids'][$id])) return (string)$lookup['request_ids'][$id];

    $spotify = strtolower(trim((string)($row['spotify_track_id'] ?? '')));
    if ($spotify !== '' && !empty($lookup['spotify'][$spotify])) return (string)$lookup['spotify'][$spotify];

    $title = (string)($row['track_name'] ?? $row['song_title'] ?? '');
    $artist = (string)($row['artist_name'] ?? $row['artist'] ?? '');
    $key = dttd_display_track_match_key($title, $artist);
    if ($key !== '' && !empty($lookup['keys'][$key])) return (string)$lookup['keys'][$key];

    $fallbackKey = dttd_display_track_match_key($title, '');
    if ($fallbackKey !== '' && !empty($lookup['keys'][$fallbackKey])) return (string)$lookup['keys'][$fallbackKey];

    return '';
}

function dttd_display_request_row_payload($row, $status = null, $playedAt = '') {
    $artwork = trim((string)($row['artwork_url'] ?? ''));
    return [
        'id' => (int)$row['id'],
        'spotify_track_id' => (string)($row['spotify_track_id'] ?? ''),
        'track_name' => (string)$row['track_name'],
        'artist_name' => (string)($row['artist_name'] ?? ''),
        'requester_name' => (string)($row['requester_name'] ?? ''),
        'dedication' => (string)($row['dedication'] ?? ''),
        'status' => (string)($status ?? ($row['status'] ?? '')),
        'played_at' => (string)$playedAt,
        'artwork_url' => dttd_display_url($artwork),
        'image_url' => dttd_display_url($artwork),
    ];
}


function dttd_display_all_requests($eventId, $limit = 40) {
    if (!dttd_display_table_exists('event_requests')) return [];
    $eventId = (int)$eventId;
    if ($eventId <= 0) return [];
    $limit = max(1, min(80, (int)$limit));

    $artworkCol = dttd_display_col_exists('event_requests', 'artwork_url') ? 'artwork_url' : '';
    if ($artworkCol === '' && dttd_display_col_exists('event_requests', 'spotify_album_image')) $artworkCol = 'spotify_album_image';
    if ($artworkCol === '' && dttd_display_col_exists('event_requests', 'image_url')) $artworkCol = 'image_url';
    $spotifyCol = dttd_display_col_exists('event_requests', 'spotify_track_id') ? 'spotify_track_id' : '';
    $playedCol = dttd_display_col_exists('event_requests', 'played_at') ? 'played_at' : '';

    try {
        $lookup = dttd_display_played_track_lookup($eventId);

        $stmt = db()->prepare("
            SELECT id, track_name, artist_name, requester_name, dedication, status, created_at, approved_at"
            . ($playedCol !== '' ? ", played_at" : ", NULL AS played_at")
            . ($spotifyCol !== '' ? ", `" . $spotifyCol . "` AS spotify_track_id" : ", '' AS spotify_track_id")
            . ($artworkCol !== '' ? ", `" . $artworkCol . "` AS artwork_url" : ", '' AS artwork_url") . "
            FROM event_requests
            WHERE event_id = ?
              AND LOWER(COALESCE(status, 'pending')) NOT IN ('rejected','removed','hidden')
            ORDER BY COALESCE(" . ($playedCol !== '' ? "played_at," : "") . " approved_at, created_at) DESC, id DESC
            LIMIT " . $limit . "
        ");
        $stmt->execute([$eventId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $status = strtolower(trim((string)($row['status'] ?? 'pending')));
            $playedAt = (string)($row['played_at'] ?? '');
            $historyPlayedAt = dttd_display_request_played_at_from_lookup($row, $lookup);

            if ($status === 'played' || $historyPlayedAt !== '') {
                if ($playedAt === '' && $historyPlayedAt !== '') $playedAt = $historyPlayedAt;
                $rows[] = dttd_display_request_row_payload($row, 'played', $playedAt);
            } else {
                $rows[] = dttd_display_request_row_payload($row, null, $playedAt);
            }
        }

        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_display_requests($eventId, $limit = 12) {
    if (!dttd_display_table_exists('event_requests')) return [];
    $eventId = (int)$eventId;
    if ($eventId <= 0) return [];
    $limit = max(1, min(20, (int)$limit));

    $artworkCol = dttd_display_col_exists('event_requests', 'artwork_url') ? 'artwork_url' : '';
    if ($artworkCol === '' && dttd_display_col_exists('event_requests', 'spotify_album_image')) $artworkCol = 'spotify_album_image';
    if ($artworkCol === '' && dttd_display_col_exists('event_requests', 'image_url')) $artworkCol = 'image_url';
    $spotifyCol = dttd_display_col_exists('event_requests', 'spotify_track_id') ? 'spotify_track_id' : '';

    try {
        $lookup = dttd_display_played_track_lookup($eventId);

        $stmt = db()->prepare("\n            SELECT id, track_name, artist_name, requester_name, dedication, status, created_at, approved_at"
            . ($spotifyCol !== '' ? ", `" . $spotifyCol . "` AS spotify_track_id" : ", '' AS spotify_track_id")
            . ($artworkCol !== '' ? ", `" . $artworkCol . "` AS artwork_url" : ", '' AS artwork_url") . "\n            FROM event_requests\n            WHERE event_id = ?\n              AND LOWER(COALESCE(status, 'pending')) NOT IN ('rejected','played')\n            ORDER BY\n              CASE LOWER(COALESCE(status, 'pending'))\n                WHEN 'queued' THEN 1\n                WHEN 'approved' THEN 2\n                WHEN 'pending' THEN 3\n                ELSE 4\n              END ASC,\n              COALESCE(approved_at, created_at) ASC,\n              id ASC\n            LIMIT " . ($limit * 3) . "\n        ");
        $stmt->execute([$eventId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            // If the requested track is now in event_track_history, it belongs on
            // the played side, regardless of the stale request status.
            if (dttd_display_request_played_at_from_lookup($row, $lookup) !== '') {
                continue;
            }

            $rows[] = dttd_display_request_row_payload($row);
            if (count($rows) >= $limit) break;
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_display_played_requests($eventId, $limit = 6) {
    if (!dttd_display_table_exists('event_requests')) return [];
    $eventId = (int)$eventId;
    if ($eventId <= 0) return [];
    $limit = max(1, min(12, (int)$limit));

    $playedCol = dttd_display_col_exists('event_requests', 'played_at') ? 'played_at' : '';
    $artworkCol = dttd_display_col_exists('event_requests', 'artwork_url') ? 'artwork_url' : '';
    if ($artworkCol === '' && dttd_display_col_exists('event_requests', 'spotify_album_image')) $artworkCol = 'spotify_album_image';
    if ($artworkCol === '' && dttd_display_col_exists('event_requests', 'image_url')) $artworkCol = 'image_url';
    $spotifyCol = dttd_display_col_exists('event_requests', 'spotify_track_id') ? 'spotify_track_id' : '';

    try {
        $lookup = dttd_display_played_track_lookup($eventId);

        $stmt = db()->prepare("
            SELECT id, track_name, artist_name, requester_name, dedication, status, created_at, approved_at"
            . ($playedCol !== '' ? ", played_at" : ", NULL AS played_at")
            . ($spotifyCol !== '' ? ", `" . $spotifyCol . "` AS spotify_track_id" : ", '' AS spotify_track_id")
            . ($artworkCol !== '' ? ", `" . $artworkCol . "` AS artwork_url" : ", '' AS artwork_url") . "
            FROM event_requests
            WHERE event_id = ?
              AND LOWER(COALESCE(status, 'pending')) NOT IN ('rejected','removed','hidden')
            ORDER BY COALESCE(" . ($playedCol !== '' ? "played_at," : "") . " approved_at, created_at) DESC, id DESC
            LIMIT " . ($limit * 8) . "
        ");
        $stmt->execute([$eventId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $playedAt = (string)($row['played_at'] ?? '');
            $historyPlayedAt = dttd_display_request_played_at_from_lookup($row, $lookup);

            if ($status !== 'played' && $historyPlayedAt === '') {
                continue;
            }

            if ($playedAt === '' && $historyPlayedAt !== '') $playedAt = $historyPlayedAt;
            $rows[] = dttd_display_request_row_payload($row, 'played', $playedAt);
            if (count($rows) >= $limit) break;
        }

        usort($rows, function($a, $b) {
            return strcmp((string)($b['played_at'] ?? ''), (string)($a['played_at'] ?? ''));
        });

        return array_slice($rows, 0, $limit);
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_display_recent_tracks($eventId, $limit = 8) {
    $eventId = (int)$eventId;
    if ($eventId <= 0 || !function_exists('dttd_history_public_track_rows')) return [];
    $out = [];
    foreach (dttd_history_public_track_rows($eventId, $limit) as $row) {
        $out[] = [
            'id' => (int)($row['id'] ?? 0),
            'track_name' => (string)($row['song_title'] ?? ''),
            'artist_name' => (string)($row['artist'] ?? ''),
            'artwork_url' => dttd_display_url($row['spotify_album_image'] ?? ''),
            'played_at' => (string)($row['created_at'] ?? ''),
        ];
    }
    return $out;
}

function dttd_display_photos($eventId, $limit = 12) {
    if (!dttd_display_table_exists('event_photo_uploads')) return [];
    $eventId = (int)$eventId;
    if ($eventId <= 0) return [];
    $limit = max(1, min(30, (int)$limit));
    try {
        $stmt = db()->prepare("\n            SELECT id, guest_name, file_path, original_path, framed_path, thumb_path, image_orientation, uploaded_at, approved_at\n            FROM event_photo_uploads\n            WHERE event_id = ? AND LOWER(COALESCE(status, 'pending')) = 'approved'\n            ORDER BY COALESCE(approved_at, uploaded_at, created_at) DESC, id DESC\n            LIMIT " . $limit . "\n        ");
        $stmt->execute([$eventId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $image = $row['framed_path'] ?: ($row['thumb_path'] ?: ($row['file_path'] ?: $row['original_path']));
            $rows[] = [
                'id' => (int)$row['id'],
                'guest_name' => (string)($row['guest_name'] ?? ''),
                'image_url' => dttd_display_url($image),
                'orientation' => (string)($row['image_orientation'] ?? ''),
            ];
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_display_upcoming_events($limit = 5, $currentEventId = 0) {
    if (!dttd_display_table_exists('events')) return [];
    $limit = max(1, min(12, (int)$limit));
    $currentEventId = (int)$currentEventId;

    try {
        $endCol = dttd_display_col_exists('events', 'end_time') ? 'end_time' : '';
        $stmt = db()->prepare("
            SELECT id, event_name, venue_name, event_date, start_time, status, event_code, public_slug"
            . ($endCol !== '' ? ", end_time" : "") . "
            FROM events
            WHERE is_public = 1
              AND is_active = 1
              AND status IN ('scheduled','live')
              AND event_date >= CURDATE()
            ORDER BY
              CASE WHEN id = ? THEN 0 WHEN status = 'live' THEN 1 ELSE 2 END ASC,
              event_date ASC, COALESCE(start_time, '00:00:00') ASC, id ASC
            LIMIT " . ($limit * 3) . "
        ");
        $stmt->execute([$currentEventId]);
        $rows = [];
        $now = time();

        foreach ($stmt->fetchAll() as $row) {
            $rowId = (int)$row['id'];
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $isCurrent = $currentEventId > 0 && $rowId === $currentEventId;

            try {
                if (!$isCurrent && function_exists('dttd_event_live_now') && dttd_event_live_now($row)) $isCurrent = true;
            } catch (Throwable $e) {}

            if (!$isCurrent && $status === 'live') $isCurrent = true;

            $endTs = 0;
            if (!empty($row['event_date'])) {
                $endTime = $endCol !== '' && !empty($row['end_time']) ? (string)$row['end_time'] : (!empty($row['start_time']) ? (string)$row['start_time'] : '23:59:59');
                $endTs = strtotime((string)$row['event_date'] . ' ' . $endTime);
                $middayTs = strtotime((string)$row['event_date'] . ' 12:00');
                if ($endTs && $middayTs && $endTs < $middayTs) $endTs = strtotime('+1 day', $endTs);
            }

            if (!$isCurrent && $endTs && $endTs < $now) continue;

            $rows[] = [
                'id' => $rowId,
                'event_name' => (string)$row['event_name'],
                'venue_name' => (string)($row['venue_name'] ?? ''),
                'event_date' => (string)($row['event_date'] ?? ''),
                'start_time' => isset($row['start_time']) ? substr((string)$row['start_time'], 0, 5) : '',
                'end_time' => $endCol !== '' && isset($row['end_time']) ? substr((string)$row['end_time'], 0, 5) : '',
                'status' => (string)($row['status'] ?? ''),
                'is_current_event' => $isCurrent,
                'display_label' => $isCurrent ? 'Current Event' : '',
            ];

            if (count($rows) >= $limit) break;
        }

        usort($rows, function($a, $b) {
            if (!empty($a['is_current_event']) !== !empty($b['is_current_event'])) return !empty($a['is_current_event']) ? -1 : 1;
            $ad = (string)($a['event_date'] ?? '') . ' ' . (string)($a['start_time'] ?? '');
            $bd = (string)($b['event_date'] ?? '') . ' ' . (string)($b['start_time'] ?? '');
            return strcmp($ad, $bd);
        });

        return array_slice($rows, 0, $limit);
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_display_partners($limit = 12) {
    if (!dttd_display_table_exists('partners')) return [];
    $limit = max(1, min(30, (int)$limit));

    try {
        $stmt = db()->prepare("
            SELECT id, partner_name, category, website_url, image_url, logo_background, notes
            FROM partners
            WHERE is_active = 1
            ORDER BY sort_order ASC, partner_name ASC, id ASC
            LIMIT " . $limit . "
        ");
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $website = dttd_display_external_url($row['website_url'] ?? '');
            $rows[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['partner_name'] ?? 'Partner'),
                'category' => (string)($row['category'] ?? ''),
                'summary' => trim(strip_tags((string)($row['notes'] ?? ''))),
                'image_url' => dttd_display_url($row['image_url'] ?? ''),
                'logo_background' => (string)($row['logo_background'] ?? 'dark'),
                'website_url' => $website,
                'qr_image_url' => $website !== '' ? 'https://api.qrserver.com/v1/create-qr-code/?size=520x520&margin=14&data=' . rawurlencode($website) : '',
            ];
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_display_sponsors($eventId, $limit = 6) {
    if (!dttd_display_table_exists('event_sponsors') || !dttd_display_table_exists('sponsors')) return [];
    $eventId = (int)$eventId;
    if ($eventId <= 0) return [];
    $limit = max(1, min(12, (int)$limit));
    try {
        $stmt = db()->prepare("\n            SELECT es.sponsor_title, es.sponsor_offer, es.sponsor_image_url, es.website_url,\n                   s.sponsor_name, s.logo_url, s.default_offer\n            FROM event_sponsors es\n            INNER JOIN sponsors s ON s.id = es.sponsor_id\n            WHERE es.event_id = ?\n              AND es.display_on_public = 1\n              AND s.is_active = 1\n            ORDER BY es.sort_order ASC, es.id ASC\n            LIMIT " . $limit . "\n        ");
        $stmt->execute([$eventId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'name' => (string)($row['sponsor_title'] ?: $row['sponsor_name']),
                'offer' => (string)($row['sponsor_offer'] ?: $row['default_offer']),
                'image_url' => dttd_display_url($row['sponsor_image_url'] ?: $row['logo_url']),
                'website_url' => (string)($row['website_url'] ?? ''),
            ];
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}


function dttd_display_slide_default_settings() {
    return [
        'welcome' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'venue' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'qr' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'music_board' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'now_playing' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'up_next' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'recent' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'requests' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'photos' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'upcoming' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'partners' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'sponsors' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'event_timer' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12],
        'goodnight' => ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 30],
    ];
}

function dttd_display_slide_settings_col($candidates) {
    if (!dttd_display_table_exists('display_slide_settings')) return '';
    foreach ((array)$candidates as $col) {
        if (dttd_display_col_exists('display_slide_settings', $col)) return $col;
    }
    return '';
}

function dttd_display_slide_settings() {
    $settings = dttd_display_slide_default_settings();

    if (!dttd_display_table_exists('display_slide_settings')) {
        return $settings;
    }

    $keyCol = dttd_display_slide_settings_col(['slide_key', 'slide', 'slide_id', 'key', 'name']);
    if ($keyCol === '') return $settings;

    $enabledCol = dttd_display_slide_settings_col(['enabled', 'is_enabled', 'visible', 'is_visible']);
    $priorityCol = dttd_display_slide_settings_col(['priority', 'slide_priority', 'weight']);
    $durationCol = dttd_display_slide_settings_col(['duration_seconds', 'display_seconds', 'seconds', 'duration']);

    $select = ['`' . $keyCol . '` AS slide_key'];
    if ($enabledCol !== '') $select[] = '`' . $enabledCol . '` AS enabled';
    if ($priorityCol !== '') $select[] = '`' . $priorityCol . '` AS priority';
    if ($durationCol !== '') $select[] = '`' . $durationCol . '` AS duration_seconds';

    try {
        $stmt = db()->query('SELECT ' . implode(', ', $select) . ' FROM display_slide_settings');
        foreach ($stmt->fetchAll() as $row) {
            $key = strtolower(trim((string)($row['slide_key'] ?? '')));
            if ($key === '') continue;

            if (!isset($settings[$key])) {
                $settings[$key] = ['enabled' => true, 'priority' => 'normal', 'duration_seconds' => 12];
            }

            if (array_key_exists('enabled', $row)) {
                $raw = strtolower(trim((string)$row['enabled']));
                $settings[$key]['enabled'] = !in_array($raw, ['0', 'false', 'no', 'off', 'disabled'], true);
            }

            if (array_key_exists('priority', $row)) {
                $priority = strtolower(trim((string)$row['priority']));
                if (in_array($priority, ['low', 'normal', 'medium', 'high'], true)) {
                    $settings[$key]['priority'] = $priority === 'medium' ? 'normal' : $priority;
                }
            }

            if (array_key_exists('duration_seconds', $row)) {
                $duration = (int)$row['duration_seconds'];
                if ($duration > 0) $settings[$key]['duration_seconds'] = max(5, min(60, $duration));
            }
        }
    } catch (Throwable $e) {
        return $settings;
    }

    return $settings;
}

function dttd_display_filter_enabled_slides($slides, $settings) {
    $out = [];
    foreach (array_values((array)$slides) as $slide) {
        $slide = strtolower(trim((string)$slide));
        if ($slide === '') continue;
        if (isset($settings[$slide]) && empty($settings[$slide]['enabled'])) continue;
        $out[] = $slide;
    }

    return array_values(array_unique($out));
}


function dttd_display_priority_slides($slides, $settings) {
    $base = array_values(array_unique(array_filter(array_map('strval', (array)$slides))));
    if (!$base) return [];

    $highExtras = [];
    foreach ($base as $slide) {
        $priority = strtolower((string)($settings[$slide]['priority'] ?? 'normal'));
        if ($priority === 'high') {
            $highExtras[] = $slide;
        }
    }

    if (!$highExtras) return $base;

    // Keep the loop predictable and avoid back-to-back duplicates. High-priority
    // slides receive one extra appearance, spread through the second half of the loop.
    $weighted = $base;
    $insertBase = max(1, (int)floor(count($weighted) / 2));
    foreach ($highExtras as $i => $slide) {
        $at = min(count($weighted), $insertBase + ($i * 2));
        if (($weighted[$at - 1] ?? null) === $slide) $at = min(count($weighted), $at + 1);
        array_splice($weighted, $at, 0, [$slide]);
    }

    return $weighted;
}

function dttd_display_slide_durations($settings) {
    $durations = [];
    foreach ((array)$settings as $slide => $row) {
        $durations[$slide] = max(5, min(60, (int)($row['duration_seconds'] ?? 12)));
    }
    return $durations;
}

function dttd_display_app_setting_value($key, $default = '') {
    try {
        if (!dttd_display_table_exists('app_settings')) return (string)$default;
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([(string)$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? (string)$default : (string)$value;
    } catch (Throwable $e) {
        return (string)$default;
    }
}

function dttd_display_setting_set_value($key, $value) {
    try {
        if (!dttd_display_table_exists('app_settings')) return false;
        $stmt = db()->prepare("
            INSERT INTO app_settings (setting_key, setting_value, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        $stmt->execute([(string)$key, (string)$value]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function dttd_display_setting_delete_value($key) {
    try {
        if (!dttd_display_table_exists('app_settings')) return false;
        $stmt = db()->prepare("DELETE FROM app_settings WHERE setting_key = ?");
        $stmt->execute([(string)$key]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function dttd_display_decode_setting_json($key) {
    try {
        if (!dttd_display_table_exists('app_settings')) return [];
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([(string)$key]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || trim((string)$raw) === '') return [];
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_display_decks_are_clear() {
    $loadedA = dttd_display_decode_setting_json('spotify_mixer_loaded_a');
    $loadedB = dttd_display_decode_setting_json('spotify_mixer_loaded_b');

    $aLoaded = is_array($loadedA) && trim((string)($loadedA['id'] ?? $loadedA['track_id'] ?? $loadedA['spotify_track_id'] ?? '')) !== '';
    $bLoaded = is_array($loadedB) && trim((string)($loadedB['id'] ?? $loadedB['track_id'] ?? $loadedB['spotify_track_id'] ?? '')) !== '';

    return !$aLoaded && !$bLoaded;
}

function dttd_display_event_end_timestamp($event) {
    if (!$event || empty($event['event_date']) || empty($event['end_time'])) return 0;

    $endTs = strtotime((string)$event['event_date'] . ' ' . (string)$event['end_time']);
    if (!$endTs) return 0;

    $middayTs = strtotime((string)$event['event_date'] . ' 12:00');
    if ($middayTs && $endTs < $middayTs) {
        $endTs = strtotime('+1 day', $endTs);
    }

    return $endTs ?: 0;
}

function dttd_display_goodnight_window_seconds() {
    return 10 * 60;
}

function dttd_display_goodnight_pre_end_seconds() {
    return 10 * 60;
}

function dttd_display_goodnight_started_key($event) {
    return 'display_goodnight_started_at_' . (int)($event['id'] ?? 0);
}

function dttd_display_goodnight_started_at($event) {
    if (!$event || empty($event['id'])) return 0;
    $raw = dttd_display_app_setting_value(dttd_display_goodnight_started_key($event), '');
    $ts = $raw !== '' ? strtotime($raw) : 0;
    return $ts ?: 0;
}

function dttd_display_set_goodnight_started_at($event, $timestamp = null) {
    if (!$event || empty($event['id'])) return 0;
    $timestamp = $timestamp ?: time();
    dttd_display_setting_set_value(dttd_display_goodnight_started_key($event), date('c', $timestamp));
    return $timestamp;
}

function dttd_display_clear_goodnight_started_at($event) {
    if (!$event || empty($event['id'])) return;
    dttd_display_setting_delete_value(dttd_display_goodnight_started_key($event));
}

function dttd_display_goodnight_window_active($event) {
    $startedAt = dttd_display_goodnight_started_at($event);
    if (!$startedAt) return false;
    $now = time();
    return $now >= $startedAt && $now <= ($startedAt + dttd_display_goodnight_window_seconds());
}

function dttd_display_goodnight_trigger_ready($event) {
    if (!$event || empty($event['id'])) return false;
    if (!dttd_display_decks_are_clear()) return false;
    $endTs = dttd_display_event_end_timestamp($event);
    if (!$endTs) return false;
    return time() >= ($endTs - dttd_display_goodnight_pre_end_seconds());
}

function dttd_display_event_has_started($event) {
    if (!$event || empty($event['id'])) return false;
    $status = strtolower(trim((string)($event['status'] ?? '')));
    if ($status === 'live') return true;
    if (empty($event['event_date'])) return false;
    $startTime = !empty($event['start_time']) ? (string)$event['start_time'] : '00:00:00';
    $startTs = strtotime((string)$event['event_date'] . ' ' . $startTime);
    return $startTs && time() >= $startTs;
}

function dttd_display_event_in_end_window_or_overrun($event) {
    $endTs = dttd_display_event_end_timestamp($event);
    if (!$endTs) return false;
    $now = time();

    if ($now >= ($endTs - dttd_display_goodnight_pre_end_seconds()) && $now < $endTs) {
        return true;
    }

    if ($now >= $endTs) {
        return dttd_display_goodnight_window_active($event) || !dttd_display_decks_are_clear();
    }

    return false;
}

function dttd_display_goodnight_active($event) {
    if (!$event || empty($event['id'])) return false;
    $endTs = dttd_display_event_end_timestamp($event);
    if (!$endTs) return false;

    $now = time();
    $windowStart = $endTs - dttd_display_goodnight_pre_end_seconds();

    // Before the end-window, this event is still normal live mode. Clear any
    // previous Goodnight marker so extending the finish time resets the procedure.
    if ($now < $windowStart) {
        dttd_display_clear_goodnight_started_at($event);
        return false;
    }

    // If music is still loaded, never start or show Goodnight. Keep the event
    // context alive during overrun.
    if (!dttd_display_decks_are_clear()) {
        return false;
    }

    $startedAt = dttd_display_goodnight_started_at($event);

    // Already shown and now expired: do not start it again. This allows standby.
    if ($startedAt && $now > ($startedAt + dttd_display_goodnight_window_seconds())) {
        return false;
    }

    // Already in its 10-minute Goodnight window.
    if ($startedAt) {
        return true;
    }

    // First time both decks are clear inside the end-window: start Goodnight once.
    if ($now >= $windowStart) {
        dttd_display_set_goodnight_started_at($event, $now);
        return true;
    }

    return false;
}

function dttd_display_standby_allowed_for_event($event) {
    if (!$event || empty($event['id'])) return true;

    // A loaded deck means the disco is still active, including overruns.
    if (!dttd_display_decks_are_clear()) return false;

    $endTs = dttd_display_event_end_timestamp($event);
    if (!$endTs) {
        return !dttd_display_event_has_started($event);
    }

    $now = time();
    $windowStart = $endTs - dttd_display_goodnight_pre_end_seconds();
    $startedAt = dttd_display_goodnight_started_at($event);

    // Before the end-window, standby is only allowed before the event starts.
    if ($now < $windowStart) {
        return !dttd_display_event_has_started($event);
    }

    // Inside/after the end-window but Goodnight has not started yet: do not go
    // directly to standby. Let dttd_display_goodnight_active() start Goodnight.
    if (!$startedAt) return false;

    // Goodnight is still active.
    if ($now <= ($startedAt + dttd_display_goodnight_window_seconds())) return false;

    // Goodnight has completed and both decks are clear.
    return true;
}

function dttd_display_goodnight_payload($event, $partners = []) {
    $website = 'https://dancethruthedecades.co.uk/';
    $facebook = 'https://www.facebook.com/profile.php?id=61579454050951';

    return [
        'ok' => true,
        'active_event' => false,
        'display_mode' => 'goodnight',
        'event' => dttd_display_event_payload($event),
        'slides' => ['goodnight'],
        'slide_durations' => ['goodnight' => 30],
        'goodnight' => [
            'website_url' => $website,
            'website_label' => 'dancethruthedecades.co.uk',
            'website_qr_image_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=560x560&margin=16&data=' . rawurlencode($website),
            'facebook_url' => $facebook,
            'facebook_label' => 'Facebook',
            'facebook_qr_image_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=560x560&margin=16&data=' . rawurlencode($facebook),
        ],
        'partners' => $partners,
        'generated_at' => date('c'),
    ];
}

function dttd_display_event_is_live($event) {
    if (!$event || empty($event['id'])) return false;

    $endTs = dttd_display_event_end_timestamp($event);
    $now = time();

    if ($endTs && $now >= $endTs) {
        return dttd_display_event_in_end_window_or_overrun($event);
    }

    try {
        if (function_exists('dttd_event_live_now') && dttd_event_live_now($event)) return true;
    } catch (Throwable $e) {}

    $status = strtolower(trim((string)($event['status'] ?? '')));
    if ($status === 'live') return true;

    return dttd_display_event_in_end_window_or_overrun($event);
}

function dttd_display_standby_payload($partners = []) {
    $website = 'https://dancethruthedecades.co.uk/';
    $facebook = 'https://www.facebook.com/profile.php?id=61579454050951';
    $upcoming = dttd_display_upcoming_events(8, 0);

    return [
        'ok' => true,
        'active_event' => false,
        'display_mode' => 'standby',
        'event' => null,
        'slides' => $upcoming ? ['standby', 'upcoming'] : ['standby'],
        'standby' => [
            'website_url' => $website,
            'website_label' => 'dancethruthedecades.co.uk',
            'website_qr_image_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=560x560&margin=16&data=' . rawurlencode($website),
            'facebook_url' => $facebook,
            'facebook_label' => 'Facebook',
            'facebook_qr_image_url' => 'https://api.qrserver.com/v1/create-qr-code/?size=560x560&margin=16&data=' . rawurlencode($facebook),
        ],
        'upcoming_events' => $upcoming,
        'partners' => $partners,
        'generated_at' => date('c'),
    ];
}

$event = dttd_display_event();
$partners = dttd_display_partners(12);

if ($event && !empty($event['id']) && dttd_display_goodnight_active($event)) {
    dttd_display_json(dttd_display_goodnight_payload($event, $partners));
}

if (!$event || empty($event['id']) || (!dttd_display_event_is_live($event) && dttd_display_standby_allowed_for_event($event))) {
    dttd_display_json(dttd_display_standby_payload($partners));
}

$eventId = (int)$event['id'];
$photos = dttd_display_photos($eventId, 12);
$allRequests = dttd_display_all_requests($eventId, 40);
$requests = dttd_display_requests($eventId, 12);
$playedRequests = dttd_display_played_requests($eventId, 10);
$recent = dttd_display_recent_tracks($eventId, 10);
$upcoming = dttd_display_upcoming_events(5, $eventId);
$sponsors = dttd_display_sponsors($eventId, 6);
$venue = dttd_display_venue_payload($event);
$slideSettings = dttd_display_slide_settings();

$availableSlides = [];
$availableSlides[] = 'welcome';
if ($venue && !empty($venue['has_details'])) $availableSlides[] = 'venue';
$availableSlides[] = 'qr';
$availableSlides[] = 'event_timer';
$availableSlides[] = 'music_board';
$availableSlides[] = 'now_playing';
$availableSlides[] = 'up_next';
if ($recent || $playedRequests) $availableSlides[] = 'recent';
if ($allRequests || $requests || $playedRequests) $availableSlides[] = 'requests';
if ($photos) $availableSlides[] = 'photos';
if ($upcoming) $availableSlides[] = 'upcoming';
if ($partners) $availableSlides[] = 'partners';
if ($sponsors) $availableSlides[] = 'sponsors';

$slides = dttd_display_filter_enabled_slides($availableSlides, $slideSettings);
if (!$slides) {
    $slides = ['welcome'];
}

$slides = dttd_display_priority_slides($slides, $slideSettings);
$slideDurations = dttd_display_slide_durations($slideSettings);

dttd_display_json([
    'ok' => true,
    'active_event' => true,
    'display_mode' => 'live_event',
    'event' => dttd_display_event_payload($event),
    'venue' => $venue,
    'slides' => $slides,
    'slide_settings' => $slideSettings,
    'slide_durations' => $slideDurations,
    'all_requests' => $allRequests,
    'requests' => $requests,
    'played_requests' => $playedRequests,
    'recent_tracks' => $recent,
    'photos' => $photos,
    'upcoming_events' => $upcoming,
    'partners' => $partners,
    'sponsors' => $sponsors,
    'generated_at' => date('c'),
]);
