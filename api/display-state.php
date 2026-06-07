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
        $stmt = db()->query("\n            SELECT *\n            FROM events\n            WHERE is_active = 1\n              AND status IN ('scheduled','live')\n            ORDER BY\n              CASE WHEN event_date IS NULL THEN 1 ELSE 0 END ASC,\n              event_date ASC,\n              COALESCE(start_time, '00:00:00') ASC,\n              id ASC\n            LIMIT 1\n        ");
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}


function dttd_display_slide_table_exists() {
    static $exists = null;
    if ($exists !== null) return $exists;

    try {
        $stmt = db()->query("SHOW TABLES LIKE 'display_slide_settings'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function dttd_display_slide_defaults() {
    return [
        'venue' => ['label' => 'Venue / hosts', 'enabled' => 1, 'duration_seconds' => 20, 'priority' => 'low', 'weight' => 1, 'sort_order' => 10],
        'qr' => ['label' => 'Event QR code', 'enabled' => 1, 'duration_seconds' => 20, 'priority' => 'high', 'weight' => 3, 'sort_order' => 20],
        'event_timer' => ['label' => 'Keep dancing timer', 'enabled' => 1, 'duration_seconds' => 20, 'priority' => 'high', 'weight' => 3, 'sort_order' => 30],
        'music_board' => ['label' => 'Request board', 'enabled' => 1, 'duration_seconds' => 15, 'priority' => 'high', 'weight' => 3, 'sort_order' => 40],
        'now_playing' => ['label' => 'Now playing', 'enabled' => 1, 'duration_seconds' => 15, 'priority' => 'normal', 'weight' => 2, 'sort_order' => 50],
        'up_next' => ['label' => 'Up next', 'enabled' => 1, 'duration_seconds' => 15, 'priority' => 'normal', 'weight' => 2, 'sort_order' => 60],
        'recent' => ['label' => 'What we’ve played', 'enabled' => 1, 'duration_seconds' => 15, 'priority' => 'normal', 'weight' => 2, 'sort_order' => 70],
        'requests' => ['label' => 'DJ playlist / coming up', 'enabled' => 1, 'duration_seconds' => 15, 'priority' => 'normal', 'weight' => 2, 'sort_order' => 80],
        'photos' => ['label' => 'Photos', 'enabled' => 1, 'duration_seconds' => 15, 'priority' => 'normal', 'weight' => 2, 'sort_order' => 90],
        'partners' => ['label' => 'Partners', 'enabled' => 1, 'duration_seconds' => 20, 'priority' => 'low', 'weight' => 1, 'sort_order' => 100],
        'upcoming' => ['label' => 'What’s happening', 'enabled' => 1, 'duration_seconds' => 20, 'priority' => 'low', 'weight' => 1, 'sort_order' => 110],
        'sponsors' => ['label' => 'Sponsors', 'enabled' => 1, 'duration_seconds' => 20, 'priority' => 'low', 'weight' => 1, 'sort_order' => 120],
    ];
}

function dttd_display_slide_settings() {
    $settings = dttd_display_slide_defaults();

    if (!dttd_display_slide_table_exists()) {
        return $settings;
    }

    try {
        $rows = db()->query("SELECT * FROM display_slide_settings ORDER BY sort_order ASC, id ASC")->fetchAll();
        foreach ($rows as $row) {
            $key = (string)($row['slide_key'] ?? '');
            if ($key === '' || !isset($settings[$key])) continue;

            $settings[$key]['label'] = (string)($row['slide_label'] ?? $settings[$key]['label']);
            $settings[$key]['enabled'] = !empty($row['enabled']) ? 1 : 0;
            $settings[$key]['duration_seconds'] = max(5, min(60, (int)($row['duration_seconds'] ?? $settings[$key]['duration_seconds'])));
            $settings[$key]['priority'] = (string)($row['priority'] ?? $settings[$key]['priority']);
            $settings[$key]['weight'] = max(1, min(4, (int)($row['weight'] ?? $settings[$key]['weight'])));
            $settings[$key]['sort_order'] = (int)($row['sort_order'] ?? $settings[$key]['sort_order']);
        }
    } catch (Throwable $e) {}

    uasort($settings, function($a, $b) {
        return ((int)$a['sort_order']) <=> ((int)$b['sort_order']);
    });

    return $settings;
}

function dttd_display_available_slides($available, $settings) {
    $availableLookup = array_fill_keys((array)$available, true);
    $out = [];

    foreach ($settings as $key => $setting) {
        if (empty($setting['enabled'])) continue;
        if (!isset($availableLookup[$key])) continue;
        $out[] = $key;
    }

    return $out;
}

function dttd_display_weighted_slides($slides, $settings) {
    $slides = array_values(array_unique((array)$slides));
    if (!$slides) return [];

    $weighted = [];
    foreach ($slides as $slide) {
        $weight = max(1, min(4, (int)($settings[$slide]['weight'] ?? 1)));
        for ($i = 0; $i < $weight; $i++) {
            $weighted[] = $slide;
        }
    }

    if (count($weighted) <= 1) {
        return $weighted;
    }

    $result = [];
    $counts = array_count_values($weighted);
    $last = '';

    while (array_sum($counts) > 0) {
        arsort($counts);
        $picked = '';

        foreach ($counts as $slide => $count) {
            if ($count <= 0) continue;
            if ($slide !== $last || count(array_filter($counts)) === 1) {
                $picked = $slide;
                break;
            }
        }

        if ($picked === '') {
            break;
        }

        $result[] = $picked;
        $counts[$picked]--;
        $last = $picked;
    }

    return $result;
}

function dttd_display_slide_durations($settings) {
    $out = [];
    foreach ($settings as $key => $setting) {
        $out[$key] = max(5, min(60, (int)($setting['duration_seconds'] ?? 15)));
    }
    return $out;
}

function dttd_display_event_payload($event) {
    if (!$event) return null;
    $joinUrl = !empty($event['event_code']) ? dttd_public_event_join_url($event['event_code'], 'event') : '';

    $eventEndIso = '';
    if (!empty($event['event_date']) && !empty($event['end_time'])) {
        $endTs = strtotime((string)$event['event_date'] . ' ' . (string)$event['end_time']);
        $startOfDay = strtotime((string)$event['event_date'] . ' 12:00');
        if ($endTs && $startOfDay && $endTs < $startOfDay) {
            $endTs = strtotime('+1 day', $endTs);
        }
        if ($endTs) $eventEndIso = date('c', $endTs);
    }

    $requestsCloseIso = '';
    if (function_exists('dttd_event_request_close_iso')) {
        $requestsCloseIso = dttd_event_request_close_iso($event);
    } elseif (!empty($event['requests_close_at'])) {
        $closeTs = strtotime((string)$event['requests_close_at']);
        if ($closeTs) $requestsCloseIso = date('c', $closeTs);
    }

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
        'event_end_iso' => $eventEndIso,
        'requests_close_at' => (string)($event['requests_close_at'] ?? ''),
        'requests_close_iso' => $requestsCloseIso,
        'requests_open' => function_exists('event_requests_open') ? event_requests_open($event) : ($requestsCloseIso === '' || strtotime($requestsCloseIso) > time()),
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


function dttd_display_track_key($title, $artist = '', $spotifyId = '') {
    $spotifyId = trim((string)$spotifyId);
    if ($spotifyId !== '') return 'id:' . strtolower($spotifyId);
    $title = strtolower(trim(preg_replace('/\s+/', ' ', (string)$title)));
    $artist = strtolower(trim(preg_replace('/\s+/', ' ', (string)$artist)));
    return $title !== '' ? 'txt:' . $title . '|' . $artist : '';
}

function dttd_display_played_request_lookup($eventId) {
    $eventId = (int)$eventId;
    $lookup = ['ids' => [], 'tracks' => []];

    if ($eventId <= 0 || !dttd_display_table_exists('event_track_history')) {
        return $lookup;
    }

    try {
        $requestCol = dttd_display_col_exists('event_track_history', 'request_id') ? 'request_id' : '';
        $spotifyCol = dttd_display_col_exists('event_track_history', 'spotify_track_id') ? 'spotify_track_id' : '';
        $artworkCol = dttd_display_col_exists('event_track_history', 'artwork_url') ? 'artwork_url' : '';

        $stmt = db()->prepare("
            SELECT id, track_name, artist_name, played_at"
            . ($requestCol !== '' ? ", request_id" : "")
            . ($spotifyCol !== '' ? ", spotify_track_id" : "")
            . ($artworkCol !== '' ? ", artwork_url" : "") . "
            FROM event_track_history
            WHERE event_id = ?
            ORDER BY played_at DESC, id DESC
            LIMIT 200
        ");
        $stmt->execute([$eventId]);

        foreach ($stmt->fetchAll() as $row) {
            $requestId = (int)($row['request_id'] ?? 0);
            if ($requestId > 0) {
                $lookup['ids'][$requestId] = [
                    'played_at' => (string)($row['played_at'] ?? ''),
                    'artwork_url' => dttd_display_url($row['artwork_url'] ?? ''),
                ];
            }

            $key = dttd_display_track_key($row['track_name'] ?? '', $row['artist_name'] ?? '', $row['spotify_track_id'] ?? '');
            if ($key !== '' && !isset($lookup['tracks'][$key])) {
                $lookup['tracks'][$key] = [
                    'played_at' => (string)($row['played_at'] ?? ''),
                    'artwork_url' => dttd_display_url($row['artwork_url'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {}

    return $lookup;
}

function dttd_display_requests($eventId, $limit = 12) {
    if (!dttd_display_table_exists('event_requests')) return [];
    $eventId = (int)$eventId;
    if ($eventId <= 0) return [];
    $limit = max(1, min(20, (int)$limit));

    $artworkCol = '';
    foreach (['artwork_url', 'spotify_album_image', 'album_image', 'image_url', 'image'] as $candidate) {
        if (dttd_display_col_exists('event_requests', $candidate)) {
            $artworkCol = $candidate;
            break;
        }
    }
    $spotifyCol = dttd_display_col_exists('event_requests', 'spotify_track_id') ? 'spotify_track_id' : '';
    $playedCol = dttd_display_col_exists('event_requests', 'played_at') ? 'played_at' : '';
    $rejectedCol = dttd_display_col_exists('event_requests', 'rejected_at') ? 'rejected_at' : '';
    $playedLookup = dttd_display_played_request_lookup($eventId);

    try {
        $stmt = db()->prepare("
            SELECT id, track_name, artist_name, requester_name, dedication, status, created_at, approved_at"
            . ($artworkCol !== '' ? ", " . $artworkCol . " AS display_artwork_url" : "")
            . ($spotifyCol !== '' ? ", spotify_track_id" : "")
            . ($playedCol !== '' ? ", played_at" : "")
            . ($rejectedCol !== '' ? ", rejected_at" : "") . "
            FROM event_requests
            WHERE event_id = ?
            ORDER BY
              CASE
                WHEN LOWER(COALESCE(status, 'pending')) IN ('queued','approved','accepted','pending','new','requested') THEN 1
                WHEN LOWER(COALESCE(status, 'pending')) IN ('played','complete','completed') THEN 2
                WHEN LOWER(COALESCE(status, 'pending')) IN ('rejected','declined','cancelled','canceled') THEN 3
                ELSE 2
              END ASC,
              CASE
                WHEN LOWER(COALESCE(status, 'pending')) IN ('queued','approved','accepted') THEN 1
                WHEN LOWER(COALESCE(status, 'pending')) IN ('pending','new','requested') THEN 2
                ELSE 3
              END ASC,
              COALESCE(" . ($playedCol !== '' ? "played_at, " : "") . ($rejectedCol !== '' ? "rejected_at, " : "") . "approved_at, created_at) DESC,
              id DESC
            LIMIT " . $limit . "
        ");
        $stmt->execute([$eventId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $requestId = (int)$row['id'];
            $status = strtolower(trim((string)($row['status'] ?? '')));
            $status = $status !== '' ? $status : 'pending';

            $playedMatch = $playedLookup['ids'][$requestId] ?? null;
            if (!$playedMatch) {
                $trackKey = dttd_display_track_key($row['track_name'] ?? '', $row['artist_name'] ?? '', $row['spotify_track_id'] ?? '');
                $playedMatch = $trackKey !== '' ? ($playedLookup['tracks'][$trackKey] ?? null) : null;
            }

            $playedAt = (string)($row['played_at'] ?? '');
            $artworkUrl = dttd_display_url($row['display_artwork_url'] ?? '');

            if ($playedMatch && !in_array($status, ['rejected','declined','cancelled','canceled'], true)) {
                $status = 'played';
                if ($playedAt === '') $playedAt = (string)($playedMatch['played_at'] ?? '');
                if ($artworkUrl === '') $artworkUrl = (string)($playedMatch['artwork_url'] ?? '');
            }

            $rows[] = [
                'id' => $requestId,
                'track_name' => (string)$row['track_name'],
                'artist_name' => (string)($row['artist_name'] ?? ''),
                'requester_name' => (string)($row['requester_name'] ?? ''),
                'dedication' => (string)($row['dedication'] ?? ''),
                'status' => $status,
                'artwork_url' => $artworkUrl,
                'played_at' => $playedAt,
                'rejected_at' => (string)($row['rejected_at'] ?? ''),
            ];
        }

        usort($rows, function($a, $b) {
            $rank = function($status) {
                $s = strtolower((string)$status);
                if (in_array($s, ['queued','approved','accepted','pending','new','requested'], true)) return 1;
                if (in_array($s, ['played','complete','completed'], true)) return 2;
                if (in_array($s, ['rejected','declined','cancelled','canceled'], true)) return 3;
                return 2;
            };
            $ra = $rank($a['status'] ?? '');
            $rb = $rank($b['status'] ?? '');
            if ($ra !== $rb) return $ra <=> $rb;
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });

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
    $orderExpr = $playedCol !== '' ? 'COALESCE(played_at, approved_at, created_at)' : 'COALESCE(approved_at, created_at)';

    try {
        $stmt = db()->prepare("
            SELECT id, track_name, artist_name, requester_name, dedication, status, created_at, approved_at" . ($playedCol !== '' ? ", played_at" : "") . "
            FROM event_requests
            WHERE event_id = ?
              AND LOWER(COALESCE(status, 'pending')) = 'played'
            ORDER BY " . $orderExpr . " DESC, id DESC
            LIMIT " . $limit . "
        ");
        $stmt->execute([$eventId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int)$row['id'],
                'track_name' => (string)$row['track_name'],
                'artist_name' => (string)($row['artist_name'] ?? ''),
                'requester_name' => (string)($row['requester_name'] ?? ''),
                'dedication' => (string)($row['dedication'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'played_at' => (string)($row['played_at'] ?? ($row['approved_at'] ?? $row['created_at'] ?? '')),
            ];
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_display_recent_tracks($eventId, $limit = 10) {
    $eventId = (int)$eventId;
    if ($eventId <= 0 || !function_exists('dttd_history_public_track_rows')) return [];
    $out = [];
    foreach (dttd_history_public_track_rows($eventId, $limit) as $row) {
        $requesterName = '';
        $requestId = (int)($row['request_id'] ?? 0);
        if ($requestId > 0 && dttd_display_table_exists('event_requests')) {
            try {
                $stmt = db()->prepare('SELECT requester_name FROM event_requests WHERE id = ? LIMIT 1');
                $stmt->execute([$requestId]);
                $requesterName = (string)($stmt->fetchColumn() ?: '');
            } catch (Throwable $e) {}
        }

        $out[] = [
            'id' => (int)($row['id'] ?? 0),
            'spotify_track_id' => (string)($row['spotify_track_id'] ?? ''),
            'track_name' => (string)($row['song_title'] ?? ''),
            'artist_name' => (string)($row['artist'] ?? ''),
            'artwork_url' => dttd_display_url($row['spotify_album_image'] ?? ''),
            'played_at' => (string)($row['created_at'] ?? ''),
            'request_id' => $requestId,
            'requester_name' => $requesterName,
        ];
    }
    return $out;
}


function dttd_display_app_setting($key, $default = '') {
    try {
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([(string)$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : (string)$default;
    } catch (Throwable $e) {
        return (string)$default;
    }
}

function dttd_display_json_setting($key, $default = []) {
    $raw = dttd_display_app_setting($key, '');
    if ($raw === '') return $default;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function dttd_display_request_status_rank($status) {
    $status = strtolower(trim((string)$status));
    if (in_array($status, ['queued', 'approved', 'accepted', 'pending', 'new', 'requested'], true)) return 1;
    if (in_array($status, ['played', 'complete', 'completed'], true)) return 2;
    if (in_array($status, ['rejected', 'declined', 'cancelled', 'canceled'], true)) return 3;
    return 2;
}

function dttd_display_is_active_request_status($status) {
    return dttd_display_request_status_rank($status) === 1;
}

function dttd_display_clean_playlist_track($track) {
    if (!is_array($track)) return null;
    $title = trim((string)($track['title'] ?? $track['track_name'] ?? ''));
    $artist = trim((string)($track['artist'] ?? $track['artist_name'] ?? ''));
    $id = trim((string)($track['id'] ?? $track['spotify_track_id'] ?? ''));
    $image = trim((string)($track['image'] ?? $track['artwork_url'] ?? $track['spotify_album_image'] ?? ''));
    if ($title === '' && $id === '') return null;

    $requestId = !empty($track['request_id']) ? (int)$track['request_id'] : 0;
    $requester = trim((string)($track['guest_name'] ?? $track['requester_name'] ?? ''));
    if ($requester === '' && !empty($track['requesters']) && is_array($track['requesters'])) {
        $requester = trim((string)reset($track['requesters']));
    }

    return [
        'id' => $id,
        'track_name' => $title !== '' ? $title : 'Unknown track',
        'artist_name' => $artist,
        'artwork_url' => dttd_display_url($image),
        'source' => (string)($track['source'] ?? $track['source_type'] ?? 'dj_playlist'),
        'source_label' => (string)($track['source_label'] ?? ''),
        'request_id' => $requestId,
        'requester_name' => $requester,
        'is_request' => $requestId > 0 || $requester !== '',
        'status' => 'queued',
    ];
}

function dttd_display_coming_up_tracks($requests, $limit = 10) {
    $limit = max(1, min(20, (int)$limit));
    $playlist = dttd_display_json_setting('spotify_mixer_playlist', []);
    $out = [];
    $seenRequestIds = [];
    $seenTrackKeys = [];

    foreach ((array)$playlist as $track) {
        $clean = dttd_display_clean_playlist_track($track);
        if (!$clean) continue;
        $key = dttd_display_track_key($clean['track_name'] ?? '', $clean['artist_name'] ?? '', $clean['id'] ?? '');
        if ($key !== '') $seenTrackKeys[$key] = true;
        if (!empty($clean['request_id'])) $seenRequestIds[(int)$clean['request_id']] = true;
        $out[] = $clean;
        if (count($out) >= $limit) return $out;
    }

    foreach ((array)$requests as $req) {
        if (!is_array($req)) continue;
        if (!dttd_display_is_active_request_status($req['status'] ?? '')) continue;

        $requestId = (int)($req['id'] ?? 0);
        if ($requestId > 0 && isset($seenRequestIds[$requestId])) continue;

        $key = dttd_display_track_key($req['track_name'] ?? '', $req['artist_name'] ?? '', $req['spotify_track_id'] ?? '');
        if ($key !== '' && isset($seenTrackKeys[$key])) continue;

        $out[] = [
            'id' => (string)($req['spotify_track_id'] ?? ''),
            'track_name' => (string)($req['track_name'] ?? ''),
            'artist_name' => (string)($req['artist_name'] ?? ''),
            'artwork_url' => dttd_display_url($req['artwork_url'] ?? ''),
            'source' => 'request',
            'source_label' => 'Request',
            'request_id' => $requestId,
            'requester_name' => (string)($req['requester_name'] ?? ''),
            'is_request' => true,
            'status' => (string)($req['status'] ?? 'pending'),
        ];

        if (count($out) >= $limit) break;
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
              AND event_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
            ORDER BY
              CASE WHEN id = ? THEN 0 WHEN status = 'live' THEN 1 ELSE 2 END ASC,
              event_date ASC, COALESCE(start_time, '00:00:00') ASC, id ASC
            LIMIT " . $limit . "
        ");
        $stmt->execute([$currentEventId]);
        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rowId = (int)$row['id'];
            $isCurrent = $currentEventId > 0 && $rowId === $currentEventId;

            try {
                if (!$isCurrent && function_exists('dttd_event_live_now') && dttd_event_live_now($row)) {
                    $isCurrent = true;
                }
            } catch (Throwable $e) {}

            if (!$isCurrent && strtolower(trim((string)($row['status'] ?? ''))) === 'live') {
                $isCurrent = true;
            }

            $rows[] = [
                'id' => $rowId,
                'event_name' => (string)$row['event_name'],
                'venue_name' => (string)($row['venue_name'] ?? ''),
                'event_date' => (string)($row['event_date'] ?? ''),
                'start_time' => isset($row['start_time']) ? substr((string)$row['start_time'], 0, 5) : '',
                'end_time' => $endCol !== '' && isset($row['end_time']) ? substr((string)$row['end_time'], 0, 5) : '',
                'status' => (string)($row['status'] ?? ''),
                'is_current_event' => $isCurrent,
                'display_label' => $isCurrent ? 'This event' : '',
            ];
        }

        usort($rows, function($a, $b) {
            if (!empty($a['is_current_event']) !== !empty($b['is_current_event'])) {
                return !empty($a['is_current_event']) ? -1 : 1;
            }
            $ad = (string)($a['event_date'] ?? '') . ' ' . (string)($a['start_time'] ?? '');
            $bd = (string)($b['event_date'] ?? '') . ' ' . (string)($b['start_time'] ?? '');
            return strcmp($ad, $bd);
        });

        return $rows;
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

function dttd_display_event_is_live($event) {
    if (!$event || empty($event['id'])) return false;

    try {
        if (function_exists('dttd_event_live_now') && dttd_event_live_now($event)) return true;
    } catch (Throwable $e) {}

    $status = strtolower(trim((string)($event['status'] ?? '')));
    if ($status === 'live') return true;

    return false;
}

function dttd_display_standby_payload($partners = []) {
    $website = 'https://dancethruthedecades.co.uk/';
    $facebook = 'https://www.facebook.com/profile.php?id=61579454050951';
    $upcoming = dttd_display_upcoming_events(8, $eventId);

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

if (!$event || empty($event['id']) || !dttd_display_event_is_live($event)) {
    dttd_display_json(dttd_display_standby_payload($partners));
}

$eventId = (int)$event['id'];
$photos = dttd_display_photos($eventId, 12);
$requests = dttd_display_requests($eventId, 12);
$playedRequests = dttd_display_played_requests($eventId, 10);
$recent = dttd_display_recent_tracks($eventId, 10);
$comingUp = dttd_display_coming_up_tracks($requests, 10);
$upcoming = dttd_display_upcoming_events(5);
$sponsors = dttd_display_sponsors($eventId, 6);
$venue = dttd_display_venue_payload($event);

$slideSettings = dttd_display_slide_settings();

$availableSlides = [];
if ($venue && !empty($venue['has_details'])) $availableSlides[] = 'venue';
$availableSlides[] = 'qr';
$availableSlides[] = 'event_timer';
$availableSlides[] = 'music_board';
$availableSlides[] = 'now_playing';
if ($recent) $availableSlides[] = 'recent';
if ($comingUp) $availableSlides[] = 'requests';
if ($photos) $availableSlides[] = 'photos';
if ($upcoming) $availableSlides[] = 'upcoming';
if ($partners) $availableSlides[] = 'partners';
if ($sponsors) $availableSlides[] = 'sponsors';

$slides = dttd_display_available_slides($availableSlides, $slideSettings);
if (!$slides) {
    $slides = ['qr'];
}

$slides = dttd_display_weighted_slides($slides, $slideSettings);
$slideDurations = dttd_display_slide_durations($slideSettings);

dttd_display_json([
    'ok' => true,
    'active_event' => true,
    'display_mode' => 'live_event',
    'event' => dttd_display_event_payload($event),
    'venue' => $venue,
    'slides' => $slides,
    'slide_durations' => $slideDurations,
    'slide_settings' => $slideSettings,
    'requests' => $requests,
    'played_requests' => $playedRequests,
    'recent_tracks' => $recent,
    'coming_up_tracks' => $comingUp,
    'photos' => $photos,
    'upcoming_events' => $upcoming,
    'partners' => $partners,
    'sponsors' => $sponsors,
    'generated_at' => date('c'),
]);
