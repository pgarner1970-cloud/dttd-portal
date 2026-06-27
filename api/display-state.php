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
        $stmt = db()->query("\n            SELECT *\n            FROM events\n            WHERE is_active = 1\n              AND COALESCE(status, 'scheduled') NOT IN ('draft','cancelled')\n            ORDER BY\n              CASE\n                WHEN event_date IS NOT NULL\n                 AND TIMESTAMP(event_date, COALESCE(NULLIF(start_time, ''), '00:00:00')) <= NOW()\n                 AND TIMESTAMP(event_date, COALESCE(NULLIF(end_time, ''), '23:59:59')) >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 0\n                ELSE 1\n              END ASC,\n              CASE WHEN event_date IS NULL THEN 1 ELSE 0 END ASC,\n              event_date ASC,\n              COALESCE(start_time, '00:00:00') ASC,\n              id ASC\n            LIMIT 1\n        ");
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
        'upcoming' => ['label' => 'What’s happening', 'enabled' => 1, 'duration_seconds' => 30, 'priority' => 'low', 'weight' => 1, 'sort_order' => 110],
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

function dttd_display_priority_loop_slides($slides, $settings) {
    $slides = array_values(array_unique((array)$slides));
    if (!$slides) return [];

    $byPriority = [
        'high' => [],
        'normal' => [],
        'low' => [],
    ];

    foreach ($slides as $slide) {
        $priority = strtolower((string)($settings[$slide]['priority'] ?? 'normal'));
        if (!isset($byPriority[$priority])) $priority = 'normal';
        $byPriority[$priority][] = $slide;
    }

    $out = [];
    for ($pass = 1; $pass <= 3; $pass++) {
        foreach ($byPriority['high'] as $slide) {
            $out[] = $slide;
        }
        foreach ($byPriority['normal'] as $slide) {
            $out[] = $slide;
        }
        if ($pass === 3) {
            foreach ($byPriority['low'] as $slide) {
                $out[] = $slide;
            }
        }
    }

    $cleaned = [];
    foreach ($out as $slide) {
        if ($slide === '' || (isset($cleaned[count($cleaned) - 1]) && $cleaned[count($cleaned) - 1] === $slide)) {
            continue;
        }
        $cleaned[] = $slide;
    }

    return $cleaned;
}

function dttd_display_slide_durations($settings) {
    $out = [];
    foreach ($settings as $key => $setting) {
        $out[$key] = max(5, min(60, (int)($setting['duration_seconds'] ?? 15)));
    }
    return $out;
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

function dttd_display_final_stretch_settings() {
    return [
        'enabled' => dttd_display_app_setting_value('display_final_stretch_enabled', '1') === '1',
        'trigger' => dttd_display_app_setting_value('display_final_stretch_trigger', 'requests_closed_or_30_minutes'),
        'duration_seconds' => max(5, min(30, (int)dttd_display_app_setting_value('display_final_stretch_duration_seconds', '10'))),
        'hide_low_priority' => dttd_display_app_setting_value('display_final_stretch_hide_low_priority', '1') === '1',
    ];
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

function dttd_display_requests_are_open_for_event($event) {
    if (!$event) return false;

    try {
        if (function_exists('event_requests_open')) {
            return (bool)event_requests_open($event);
        }
    } catch (Throwable $e) {}

    if (!empty($event['requests_close_at'])) {
        $ts = strtotime((string)$event['requests_close_at']);
        return !$ts || $ts > time();
    }

    return true;
}

function dttd_display_final_stretch_active($event, $settings = null) {
    $settings = $settings ?: dttd_display_final_stretch_settings();
    if (empty($settings['enabled']) || !$event) return false;

    $trigger = (string)($settings['trigger'] ?? 'requests_closed_or_30_minutes');
    $requestsClosed = !dttd_display_requests_are_open_for_event($event);

    $endTs = dttd_display_event_end_timestamp($event);
    $minutesLeft = $endTs > 0 ? (($endTs - time()) / 60) : null;
    $within30 = $minutesLeft !== null && $minutesLeft <= 30;
    $within15 = $minutesLeft !== null && $minutesLeft <= 15;

    if ($trigger === 'requests_closed') return $requestsClosed;
    if ($trigger === '30_minutes') return $within30;
    if ($trigger === '15_minutes') return $within15;

    return $requestsClosed || $within30;
}

function dttd_display_apply_final_stretch($slides, $settings, $finalSettings, $active) {
    $slides = array_values((array)$slides);
    if (!$active) {
        return [$slides, dttd_display_slide_durations($settings)];
    }

    if (!empty($finalSettings['hide_low_priority'])) {
        $slides = array_values(array_filter($slides, function($slide) use ($settings) {
            return strtolower((string)($settings[$slide]['priority'] ?? 'normal')) !== 'low';
        }));
    }

    if (!$slides) {
        $slides = ['event_timer'];
    }

    $duration = max(5, min(30, (int)($finalSettings['duration_seconds'] ?? 10)));
    $durations = dttd_display_slide_durations($settings);
    foreach ($durations as $key => $value) {
        $durations[$key] = $duration;
    }

    return [$slides, $durations];
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

    $artworkCol = '';
    foreach (['artwork_url', 'spotify_album_image', 'album_image', 'image_url', 'image'] as $candidate) {
        if (dttd_display_col_exists('event_requests', $candidate)) {
            $artworkCol = $candidate;
            break;
        }
    }
    $spotifyCol = dttd_display_col_exists('event_requests', 'spotify_track_id') ? 'spotify_track_id' : '';
    $playedCol = dttd_display_col_exists('event_requests', 'played_at') ? 'played_at' : '';
    $orderExpr = $playedCol !== '' ? 'COALESCE(played_at, approved_at, created_at)' : 'COALESCE(approved_at, created_at)';

    $rowsById = [];
    $addRow = function($row, $playedAt = '') use (&$rowsById, $artworkCol, $spotifyCol) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) return;
        $playedAt = trim((string)$playedAt);
        if ($playedAt === '') {
            $playedAt = (string)($row['played_at'] ?? ($row['approved_at'] ?? $row['created_at'] ?? ''));
        }
        $existing = $rowsById[$id] ?? null;
        if ($existing && strtotime((string)($existing['played_at'] ?? '')) >= strtotime($playedAt ?: '1970-01-01')) {
            return;
        }
        $rowsById[$id] = [
            'id' => $id,
            'track_name' => (string)$row['track_name'],
            'artist_name' => (string)($row['artist_name'] ?? ''),
            'requester_name' => (string)($row['requester_name'] ?? ''),
            'dedication' => (string)($row['dedication'] ?? ''),
            'status' => 'played',
            'artwork_url' => $artworkCol !== '' ? dttd_display_url($row['display_artwork_url'] ?? '') : '',
            'spotify_track_id' => $spotifyCol !== '' ? (string)($row['spotify_track_id'] ?? '') : '',
            'played_at' => $playedAt,
        ];
    };

    try {
        $stmt = db()->prepare("
            SELECT id, track_name, artist_name, requester_name, dedication, status, created_at, approved_at"
            . ($artworkCol !== '' ? ", " . $artworkCol . " AS display_artwork_url" : "")
            . ($spotifyCol !== '' ? ", spotify_track_id" : "")
            . ($playedCol !== '' ? ", played_at" : "") . "
            FROM event_requests
            WHERE event_id = ?
              AND LOWER(COALESCE(status, 'pending')) = 'played'
            ORDER BY " . $orderExpr . " DESC, id DESC
            LIMIT 80
        ");
        $stmt->execute([$eventId]);
        foreach ($stmt->fetchAll() as $row) {
            $addRow($row);
        }

        if (dttd_display_table_exists('event_track_history') && dttd_display_col_exists('event_track_history', 'request_id')) {
            $historyStmt = db()->prepare("
                SELECT r.id, r.track_name, r.artist_name, r.requester_name, r.dedication, r.status, r.created_at, r.approved_at"
                . ($artworkCol !== '' ? ", r." . $artworkCol . " AS display_artwork_url" : "")
                . ($spotifyCol !== '' ? ", r.spotify_track_id" : "") . ",
                       MAX(h.played_at) AS history_played_at
                FROM event_track_history h
                INNER JOIN event_requests r ON r.id = h.request_id AND r.event_id = ?
                WHERE h.event_id = ?
                  AND h.request_id IS NOT NULL
                  AND h.request_id > 0
                GROUP BY r.id, r.track_name, r.artist_name, r.requester_name, r.dedication, r.status, r.created_at, r.approved_at"
                . ($artworkCol !== '' ? ", r." . $artworkCol : "")
                . ($spotifyCol !== '' ? ", r.spotify_track_id" : "") . "
                ORDER BY history_played_at DESC, r.id DESC
                LIMIT 80
            ");
            $historyStmt->execute([$eventId, $eventId]);
            foreach ($historyStmt->fetchAll() as $row) {
                $status = strtolower(trim((string)($row['status'] ?? '')));
                if (in_array($status, ['rejected','declined','cancelled','canceled'], true)) continue;
                $addRow($row, (string)($row['history_played_at'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    $rows = array_values($rowsById);
    usort($rows, function($a, $b) {
        $at = strtotime((string)($a['played_at'] ?? '')) ?: 0;
        $bt = strtotime((string)($b['played_at'] ?? '')) ?: 0;
        if ($at !== $bt) return $bt <=> $at;
        return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
    });

    return array_slice($rows, 0, $limit);
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
    if (in_array($status, ['queued', 'approved', 'accepted', 'pending', 'new', 'requested', 'maybe', 'duplicate', 'mixer_request', 'dj_playlist', 'loaded_a', 'loaded_b'], true)) return 1;
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

function dttd_display_coming_up_tracks($requests, $limit = 10, $eventId = 0) {
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

    if ((int)$eventId > 0 && dttd_display_table_exists('song_requests')) {
        try {
            $titleExpr = dttd_display_col_exists('song_requests', 'song_title') ? 'song_title' : (dttd_display_col_exists('song_requests', 'track_name') ? 'track_name' : "''");
            $artistExpr = dttd_display_col_exists('song_requests', 'artist') ? 'artist' : (dttd_display_col_exists('song_requests', 'artist_name') ? 'artist_name' : "''");
            $guestExpr = dttd_display_col_exists('song_requests', 'guest_name') ? 'guest_name' : (dttd_display_col_exists('song_requests', 'requester_name') ? 'requester_name' : "''");
            $dedicationExpr = dttd_display_col_exists('song_requests', 'dedication') ? 'dedication' : (dttd_display_col_exists('song_requests', 'message') ? 'message' : "''");
            $statusExpr = dttd_display_col_exists('song_requests', 'status') ? 'status' : "'pending'";
            $queueExpr = dttd_display_col_exists('song_requests', 'spotify_queue_status') ? 'spotify_queue_status' : "''";
            $trackIdExpr = dttd_display_col_exists('song_requests', 'spotify_track_id') ? 'spotify_track_id' : "''";
            $artworkExpr = dttd_display_col_exists('song_requests', 'spotify_album_image') ? 'spotify_album_image' : (dttd_display_col_exists('song_requests', 'artwork_url') ? 'artwork_url' : "''");
            $createdExpr = dttd_display_col_exists('song_requests', 'created_at') ? 'created_at' : 'id';
            $where = [
                "(LOWER(COALESCE(" . $statusExpr . ", 'pending')) IN ('pending','maybe','duplicate','queued','approved','accepted','new','requested') OR LOWER(COALESCE(" . $queueExpr . ", '')) IN ('mixer_request','dj_playlist','queued','loaded_a','loaded_b'))"
            ];
            $params = [];
            if (dttd_display_col_exists('song_requests', 'event_id')) {
                $where[] = 'event_id = ?';
                $params[] = (int)$eventId;
            }
            $stmt = db()->prepare("
                SELECT id,
                       " . $titleExpr . " AS display_title,
                       " . $artistExpr . " AS display_artist,
                       " . $guestExpr . " AS display_guest,
                       " . $dedicationExpr . " AS display_dedication,
                       " . $statusExpr . " AS display_status,
                       " . $queueExpr . " AS display_queue_status,
                       " . $trackIdExpr . " AS display_spotify_track_id,
                       " . $artworkExpr . " AS display_artwork_url,
                       " . $createdExpr . " AS display_created_at
                FROM song_requests
                WHERE " . implode(' AND ', $where) . "
                ORDER BY
                  CASE
                    WHEN LOWER(COALESCE(" . $queueExpr . ", '')) IN ('dj_playlist','loaded_a','loaded_b') THEN 1
                    WHEN LOWER(COALESCE(" . $queueExpr . ", '')) IN ('mixer_request','queued') THEN 2
                    ELSE 3
                  END ASC,
                  " . $createdExpr . " ASC,
                  id ASC
                LIMIT " . max(20, $limit * 3) . "
            ");
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                $requestId = (int)($row['id'] ?? 0);
                if ($requestId > 0 && isset($seenRequestIds[$requestId])) continue;
                $key = dttd_display_track_key($row['display_title'] ?? '', $row['display_artist'] ?? '', $row['display_spotify_track_id'] ?? '');
                if ($key !== '' && isset($seenTrackKeys[$key])) continue;
                if ($key !== '') $seenTrackKeys[$key] = true;
                if ($requestId > 0) $seenRequestIds[$requestId] = true;

                $queueStatus = strtolower(trim((string)($row['display_queue_status'] ?? '')));
                $status = in_array($queueStatus, ['mixer_request','dj_playlist','queued','loaded_a','loaded_b'], true) ? 'queued' : (string)($row['display_status'] ?? 'pending');
                $out[] = [
                    'id' => (string)($row['display_spotify_track_id'] ?? ''),
                    'track_name' => (string)($row['display_title'] ?? ''),
                    'artist_name' => (string)($row['display_artist'] ?? ''),
                    'artwork_url' => dttd_display_url($row['display_artwork_url'] ?? ''),
                    'source' => 'request',
                    'source_label' => 'Request',
                    'request_id' => $requestId,
                    'requester_name' => (string)($row['display_guest'] ?? ''),
                    'dedication' => (string)($row['display_dedication'] ?? ''),
                    'is_request' => true,
                    'status' => $status,
                    'created_at' => (string)($row['display_created_at'] ?? ''),
                ];
                if (count($out) >= $limit) return $out;
            }
        } catch (Throwable $e) {}
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

    try {
        $endCol = dttd_display_col_exists('events', 'end_time') ? 'end_time' : '';
        $sqlLiveEndExpr = $endCol !== ''
            ? "TIMESTAMP(event_date, COALESCE(NULLIF(end_time, ''), '23:59:59'))"
            : "TIMESTAMP(event_date, '23:59:59')";
        $queryLimit = max(20, $limit * 4);
        $stmt = db()->prepare("
            SELECT id, event_name, venue_name, event_date, start_time, status, is_active, event_code, public_slug"
            . ($endCol !== '' ? ", end_time" : "") . "
            FROM events
            WHERE is_public = 1
              AND is_active = 1
              AND status IN ('scheduled','live')
              AND event_date IS NOT NULL
              AND (
                event_date >= CURDATE()
                OR (status = 'live' AND event_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY))
              )
            ORDER BY
              CASE
                WHEN event_date IS NOT NULL
                 AND TIMESTAMP(event_date, COALESCE(NULLIF(start_time, ''), '00:00:00')) <= NOW()
                 AND " . $sqlLiveEndExpr . " >= NOW()
                THEN 0
                ELSE 1
              END ASC,
              event_date ASC, COALESCE(start_time, '00:00:00') ASC, id ASC
            LIMIT " . $queryLimit . "
        ");
        $stmt->execute();
        $rows = [];
        $now = time();

        foreach ($stmt->fetchAll() as $row) {
            $rowId = (int)$row['id'];
            $isCurrent = false;

            try {
                if (function_exists('dttd_event_live_now') && dttd_event_live_now($row)) {
                    $isCurrent = true;
                }
            } catch (Throwable $e) {}

            if (!$isCurrent) {
                $endTs = dttd_display_event_end_timestamp($row);
                if ($endTs > 0) {
                    if ($endTs < $now) continue;
                } else {
                    $startTs = 0;
                    if (!empty($row['event_date'])) {
                        $startTs = strtotime((string)$row['event_date'] . ' ' . (string)($row['start_time'] ?? '00:00:00')) ?: 0;
                    }
                    if ($startTs > 0 && $startTs < $now) continue;
                }
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


function dttd_display_goodnight_window_seconds() {
    return 10 * 60;
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

function dttd_display_event_finished_timestamp($event) {
    $endTs = dttd_display_event_end_timestamp($event);
    return $endTs && time() >= $endTs ? $endTs : 0;
}

function dttd_display_event_phase($event) {
    if (!$event || empty($event['id'])) return 'standby';

    $status = strtolower(trim((string)($event['status'] ?? 'scheduled')));
    if (in_array($status, ['draft', 'cancelled'], true)) return 'standby';

    $startTs = 0;
    if (!empty($event['event_date'])) {
        $startTime = !empty($event['start_time']) ? (string)$event['start_time'] : '00:00:00';
        $startTs = strtotime((string)$event['event_date'] . ' ' . $startTime) ?: 0;
    }
    $endTs = dttd_display_event_end_timestamp($event);
    $now = time();
    $endTolerance = dttd_display_goodnight_window_seconds();

    if ($status === 'live') return 'live';
    if ($startTs && $now < $startTs) return 'standby';
    if ($endTs && $now >= ($endTs - $endTolerance) && $now <= ($endTs + $endTolerance) && dttd_display_decks_are_clear()) {
        return 'goodnight';
    }
    if ($endTs && $now > ($endTs + $endTolerance) && dttd_display_decks_are_clear()) {
        return 'standby';
    }
    if ($startTs && $now >= $startTs && (!$endTs || $now <= ($endTs + $endTolerance) || !dttd_display_decks_are_clear())) {
        return 'live';
    }

    return 'standby';
}

function dttd_display_event_in_post_finish_hold($event) {
    $endTs = dttd_display_event_finished_timestamp($event);
    if (!$endTs) return false;

    $now = time();
    $goodnightEnd = $endTs + dttd_display_goodnight_window_seconds();

    // From event end until the goodnight period has expired, or while a deck is
    // still loaded, keep the live-event display context rather than dropping to
    // the no-current-event standby page.
    if ($now <= $goodnightEnd) return true;

    return !dttd_display_decks_are_clear();
}

function dttd_display_standby_allowed_for_event($event) {
    if (!$event || empty($event['id'])) return true;

    $endTs = dttd_display_event_finished_timestamp($event);
    if ($endTs) {
        return time() > ($endTs + dttd_display_goodnight_window_seconds()) && dttd_display_decks_are_clear();
    }

    return !dttd_display_event_has_started($event) && !dttd_display_event_is_live($event);
}

function dttd_display_goodnight_active($event) {
    return dttd_display_event_phase($event) === 'goodnight';
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
    return dttd_display_event_phase($event) === 'live';
}

function dttd_display_standby_payload($partners = []) {
    $website = 'https://dancethruthedecades.co.uk/';
    $facebook = 'https://www.facebook.com/profile.php?id=61579454050951';
    $upcoming = dttd_display_upcoming_events(8, 0);
    $slideSettings = dttd_display_slide_settings();
    $slideDurations = dttd_display_slide_durations($slideSettings);

    // Standby is a system slide rather than a row in display_slide_settings.
    // Give it a sensible duration, and let the no-current-event upcoming slide
    // use the same configurable duration as the normal What's happening slide.
    if (empty($slideDurations['standby'])) {
        $slideDurations['standby'] = 20;
    }
    if (!empty($upcoming) && empty($slideDurations['upcoming'])) {
        $slideDurations['upcoming'] = 30;
    }

    return [
        'ok' => true,
        'active_event' => false,
        'display_mode' => 'standby',
        'event' => null,
        'slides' => $upcoming ? ['standby', 'upcoming'] : ['standby'],
        'slide_durations' => $slideDurations,
        'slide_settings' => $slideSettings,
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
$eventPhase = dttd_display_event_phase($event);

if ($event && !empty($event['id']) && $eventPhase === 'goodnight') {
    dttd_display_json(dttd_display_goodnight_payload($event, $partners));
}

if (!$event || empty($event['id']) || $eventPhase === 'standby') {
    dttd_display_json(dttd_display_standby_payload($partners));
}

$eventId = (int)$event['id'];
$photos = dttd_display_photos($eventId, 12);
$requests = dttd_display_requests($eventId, 12);
$playedRequests = dttd_display_played_requests($eventId, 10);
$recent = dttd_display_recent_tracks($eventId, 10);
$comingUp = dttd_display_coming_up_tracks($requests, 10, $eventId);
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

$finalStretchSettings = dttd_display_final_stretch_settings();
$finalStretchActive = dttd_display_final_stretch_active($event, $finalStretchSettings);

if ($finalStretchActive) {
    // End-of-night mode is deliberately simple and predictable:
    // hide low-priority slides, show each remaining slide once, and use the
    // final-stretch duration for every slide.
    [$slides, $slideDurations] = dttd_display_apply_final_stretch($slides, $slideSettings, $finalStretchSettings, true);
    $slides = array_values(array_unique($slides));
} else {
    $slides = dttd_display_priority_loop_slides($slides, $slideSettings);
    $slideDurations = dttd_display_slide_durations($slideSettings);
}

dttd_display_json([
    'ok' => true,
    'active_event' => true,
    'display_mode' => 'live_event',
    'event' => dttd_display_event_payload($event),
    'venue' => $venue,
    'slides' => $slides,
    'slide_durations' => $slideDurations,
    'slide_settings' => $slideSettings,
    'final_stretch' => $finalStretchSettings,
    'final_stretch_active' => $finalStretchActive,
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
