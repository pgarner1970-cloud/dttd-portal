<?php
/**
 * Durable played-track history helpers.
 *
 * This layer is deliberately additive: existing mixer JSON/request behaviour can
 * continue while event_track_history becomes the long-term source of truth.
 */

function dttd_history_table_exists($table) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') return false;
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return $cache[$table] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function dttd_history_column_exists($table, $column) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if ($table === '' || $column === '') return false;
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function dttd_history_normalise_spotify_id($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (strpos($value, 'spotify:track:') === 0) return substr($value, strlen('spotify:track:'));
    if (preg_match('~/track/([A-Za-z0-9]+)~', $value, $m)) return $m[1];
    return $value;
}

function dttd_history_current_event_id_with_grace($graceSeconds = 3600) {
    try {
        if (function_exists('dttd_get_calculated_current_event')) {
            $event = dttd_get_calculated_current_event();
            if (!empty($event['id'])) return (int)$event['id'];
        }
    } catch (Throwable $ignored) {}

    if (!dttd_history_table_exists('events')) return 0;

    $graceSeconds = max(0, (int)$graceSeconds);
    try {
        $stmt = db()->prepare("\n            SELECT id\n            FROM events\n            WHERE TIMESTAMP(event_date, COALESCE(NULLIF(end_time, ''), '23:59:59')) >= DATE_SUB(NOW(), INTERVAL ? SECOND)\n              AND TIMESTAMP(event_date, COALESCE(NULLIF(start_time, ''), '00:00:00')) <= NOW()\n            ORDER BY event_date DESC, COALESCE(NULLIF(start_time, ''), '00:00:00') DESC, id DESC\n            LIMIT 1\n        ");
        $stmt->execute([$graceSeconds]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $ignored) {
        return 0;
    }
}

function dttd_history_event_request_exists($requestId) {
    $requestId = (int)$requestId;
    if ($requestId <= 0 || !dttd_history_table_exists('event_requests')) return false;
    try {
        $stmt = db()->prepare('SELECT id FROM event_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$requestId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $ignored) {
        return false;
    }
}

function dttd_history_log_track($data) {
    if (!dttd_history_table_exists('event_track_history')) return false;
    if (!is_array($data)) return false;

    $eventId = (int)($data['event_id'] ?? 0);
    if ($eventId <= 0) $eventId = dttd_history_current_event_id_with_grace(3600);
    if ($eventId <= 0) return false;

    $title = trim((string)($data['track_name'] ?? $data['title'] ?? $data['song_title'] ?? ''));
    $artist = trim((string)($data['artist_name'] ?? $data['artist'] ?? ''));
    $spotifyId = dttd_history_normalise_spotify_id($data['spotify_track_id'] ?? $data['id'] ?? '');
    if ($title === '' && $spotifyId === '') return false;

    $requestId = !empty($data['request_id']) ? (int)$data['request_id'] : null;
    $sourceType = trim((string)($data['source_type'] ?? $data['source'] ?? ''));
    if ($sourceType === '') $sourceType = $requestId ? 'request' : 'search';

    $playedAt = trim((string)($data['played_at'] ?? ''));
    if ($playedAt === '' || !strtotime($playedAt)) $playedAt = date('Y-m-d H:i:s');

    // Manual request actions should not create repeated rows if the DJ clicks Played again.
    // Update the existing played_at instead so the public list remains tidy.
    if ($requestId && in_array($sourceType, ['standard_request', 'public_request', 'request', 'mixer_request'], true)) {
        try {
            $stmt = db()->prepare("SELECT id FROM event_track_history WHERE event_id = ? AND request_id = ? LIMIT 1");
            $stmt->execute([$eventId, $requestId]);
            $existingId = (int)($stmt->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $upd = db()->prepare("\n                    UPDATE event_track_history\n                    SET deck = ?, track_name = ?, artist_name = ?, album_name = ?, artwork_url = ?,\n                        duration_ms = ?, played_ms = ?, source_type = ?, source_ref_id = ?,\n                        threshold_met = ?, played_at = ?\n                    WHERE id = ?\n                ");
                $upd->execute([
                    trim((string)($data['deck'] ?? '')) ?: null,
                    $title,
                    $artist ?: null,
                    trim((string)($data['album_name'] ?? $data['album'] ?? '')) ?: null,
                    trim((string)($data['artwork_url'] ?? $data['image'] ?? '')) ?: null,
                    isset($data['duration_ms']) ? (int)$data['duration_ms'] : null,
                    isset($data['played_ms']) ? (int)$data['played_ms'] : null,
                    $sourceType,
                    isset($data['source_ref_id']) ? (int)$data['source_ref_id'] : null,
                    !empty($data['threshold_met']) ? 1 : 0,
                    $playedAt,
                    $existingId,
                ]);
                return true;
            }
        } catch (Throwable $ignored) {}
    }

    $crateId = !empty($data['crate_id']) ? (int)$data['crate_id'] : null;
    $eventRequestId = dttd_history_event_request_exists($requestId) ? $requestId : null;

    try {
        $stmt = db()->prepare("\n            INSERT INTO event_track_history\n            (event_id, deck, spotify_track_id, track_name, artist_name, album_name, artwork_url,\n             duration_ms, played_ms, source_type, source_ref_id, request_id, crate_id, threshold_met, played_at)\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ");
        $stmt->execute([
            $eventId,
            trim((string)($data['deck'] ?? '')) ?: null,
            $spotifyId ?: null,
            $title,
            $artist ?: null,
            trim((string)($data['album_name'] ?? $data['album'] ?? '')) ?: null,
            trim((string)($data['artwork_url'] ?? $data['image'] ?? '')) ?: null,
            isset($data['duration_ms']) ? (int)$data['duration_ms'] : null,
            isset($data['played_ms']) ? (int)$data['played_ms'] : null,
            $sourceType,
            isset($data['source_ref_id']) ? (int)$data['source_ref_id'] : null,
            $eventRequestId,
            $crateId,
            !empty($data['threshold_met']) ? 1 : 0,
            $playedAt,
        ]);
        return true;
    } catch (Throwable $ignored) {
        return false;
    }
}

function dttd_history_track_rows($eventId, $limit = 80, $dedupe = false, $sinceSeconds = 86400) {
    if (!dttd_history_table_exists('event_track_history')) return [];
    $eventId = (int)$eventId;
    if ($eventId <= 0) return [];
    $limit = max(1, min(200, (int)$limit));
    $sinceSeconds = max(0, (int)$sinceSeconds);

    try {
        if ($dedupe) {
            $stmt = db()->prepare("\n                SELECT h.*\n                FROM event_track_history h\n                INNER JOIN (\n                    SELECT\n                      CASE\n                        WHEN spotify_track_id IS NOT NULL AND spotify_track_id <> '' THEN CONCAT('id:', spotify_track_id)\n                        ELSE CONCAT('txt:', LOWER(TRIM(track_name)), '|', LOWER(TRIM(COALESCE(artist_name, ''))))\n                      END AS track_key,\n                      MAX(played_at) AS latest_played_at,\n                      MAX(id) AS latest_id\n                    FROM event_track_history\n                    WHERE event_id = ?\n                      AND played_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)\n                    GROUP BY track_key\n                ) latest ON latest.latest_id = h.id\n                ORDER BY h.played_at DESC, h.id DESC\n                LIMIT " . $limit . "\n            ");
            $stmt->execute([$eventId, $sinceSeconds]);
        } else {
            $stmt = db()->prepare("\n                SELECT *\n                FROM event_track_history\n                WHERE event_id = ?\n                ORDER BY played_at DESC, id DESC\n                LIMIT " . $limit . "\n            ");
            $stmt->execute([$eventId]);
        }
        return $stmt->fetchAll();
    } catch (Throwable $ignored) {
        return [];
    }
}

function dttd_history_public_track_rows($eventId, $limit = 80) {
    $rows = dttd_history_track_rows($eventId, $limit, false, 0);
    $out = [];
    foreach ($rows as $row) {
        $ts = strtotime((string)($row['played_at'] ?? '')) ?: time();
        $spotifyId = (string)($row['spotify_track_id'] ?? '');
        $out[] = [
            'id' => (int)($row['id'] ?? 0),
            'song_title' => (string)($row['track_name'] ?? ''),
            'artist' => (string)($row['artist_name'] ?? ''),
            'spotify_track_id' => $spotifyId,
            'spotify_track_url' => $spotifyId !== '' ? 'https://open.spotify.com/track/' . $spotifyId : '',
            'spotify_album_image' => (string)($row['artwork_url'] ?? ''),
            'created_at' => (string)($row['played_at'] ?? ''),
            'updated_at' => (string)($row['played_at'] ?? ''),
            '_played_ts' => $ts,
            '_source' => 'event_track_history',
            'request_id' => !empty($row['request_id']) ? (int)$row['request_id'] : null,
        ];
    }
    return $out;
}
