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

function dttd_display_requests($eventId, $limit = 12) {
    if (!dttd_display_table_exists('event_requests')) return [];
    $eventId = (int)$eventId;
    if ($eventId <= 0) return [];
    $limit = max(1, min(20, (int)$limit));

    $artworkCol = dttd_display_col_exists('event_requests', 'artwork_url') ? 'artwork_url' : '';
    if ($artworkCol === '' && dttd_display_col_exists('event_requests', 'spotify_album_image')) $artworkCol = 'spotify_album_image';
    if ($artworkCol === '' && dttd_display_col_exists('event_requests', 'image_url')) $artworkCol = 'image_url';

    try {
        $stmt = db()->prepare("\n            SELECT id, track_name, artist_name, requester_name, dedication, status, created_at, approved_at"
            . ($artworkCol !== '' ? ", `" . $artworkCol . "` AS artwork_url" : "") . "\n            FROM event_requests\n            WHERE event_id = ?\n              AND LOWER(COALESCE(status, 'pending')) NOT IN ('rejected','played')\n            ORDER BY\n              CASE LOWER(COALESCE(status, 'pending'))\n                WHEN 'queued' THEN 1\n                WHEN 'approved' THEN 2\n                WHEN 'pending' THEN 3\n                ELSE 4\n              END ASC,\n              COALESCE(approved_at, created_at) ASC,\n              id ASC\n            LIMIT " . $limit . "\n        ");
        $stmt->execute([$eventId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $artwork = trim((string)($row['artwork_url'] ?? ''));
            $rows[] = [
                'id' => (int)$row['id'],
                'track_name' => (string)$row['track_name'],
                'artist_name' => (string)($row['artist_name'] ?? ''),
                'requester_name' => (string)($row['requester_name'] ?? ''),
                'dedication' => (string)($row['dedication'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'artwork_url' => dttd_display_url($artwork),
                'image_url' => dttd_display_url($artwork),
            ];
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
    $orderExpr = $playedCol !== '' ? 'COALESCE(played_at, approved_at, created_at)' : 'COALESCE(approved_at, created_at)';
    $artworkCol = dttd_display_col_exists('event_requests', 'artwork_url') ? 'artwork_url' : '';
    if ($artworkCol === '' && dttd_display_col_exists('event_requests', 'spotify_album_image')) $artworkCol = 'spotify_album_image';
    if ($artworkCol === '' && dttd_display_col_exists('event_requests', 'image_url')) $artworkCol = 'image_url';

    try {
        $stmt = db()->prepare("
            SELECT id, track_name, artist_name, requester_name, dedication, status, created_at, approved_at"
            . ($playedCol !== '' ? ", played_at" : "")
            . ($artworkCol !== '' ? ", `" . $artworkCol . "` AS artwork_url" : "") . "
            FROM event_requests
            WHERE event_id = ?
              AND LOWER(COALESCE(status, 'pending')) = 'played'
            ORDER BY " . $orderExpr . " DESC, id DESC
            LIMIT " . $limit . "
        ");
        $stmt->execute([$eventId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $artwork = trim((string)($row['artwork_url'] ?? ''));
            $rows[] = [
                'id' => (int)$row['id'],
                'track_name' => (string)$row['track_name'],
                'artist_name' => (string)($row['artist_name'] ?? ''),
                'requester_name' => (string)($row['requester_name'] ?? ''),
                'dedication' => (string)($row['dedication'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'played_at' => (string)($row['played_at'] ?? ($row['approved_at'] ?? $row['created_at'] ?? '')),
                'artwork_url' => dttd_display_url($artwork),
                'image_url' => dttd_display_url($artwork),
            ];
        }
        return $rows;
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

function dttd_display_upcoming_events($limit = 5) {
    if (!dttd_display_table_exists('events')) return [];
    $limit = max(1, min(12, (int)$limit));
    try {
        $stmt = db()->prepare("\n            SELECT id, event_name, venue_name, event_date, start_time, event_code, public_slug\n            FROM events\n            WHERE is_public = 1\n              AND is_active = 1\n              AND status IN ('scheduled','live')\n              AND event_date >= CURDATE()\n            ORDER BY event_date ASC, COALESCE(start_time, '00:00:00') ASC, id ASC\n            LIMIT " . $limit . "\n        ");
        $stmt->execute();
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (int)$row['id'],
                'event_name' => (string)$row['event_name'],
                'venue_name' => (string)($row['venue_name'] ?? ''),
                'event_date' => (string)($row['event_date'] ?? ''),
                'start_time' => isset($row['start_time']) ? substr((string)$row['start_time'], 0, 5) : '',
            ];
        }
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
    $upcoming = dttd_display_upcoming_events(8);

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
$playedRequests = dttd_display_played_requests($eventId, 6);
$recent = dttd_display_recent_tracks($eventId, 10);
$upcoming = dttd_display_upcoming_events(5);
$sponsors = dttd_display_sponsors($eventId, 6);
$venue = dttd_display_venue_payload($event);

$slides = ['welcome'];
if ($venue && !empty($venue['has_details'])) $slides[] = 'venue';
$slides[] = 'qr';
$slides[] = 'music_board';
$slides[] = 'now_playing';

// Keep the dedicated music-board slide in every active event loop. It has its own
// empty-state messages, and requests can exist even when the DJ mixer is not running.
if ($recent) $slides[] = 'recent';
if ($requests) $slides[] = 'requests';
if ($photos) $slides[] = 'photos';
if ($upcoming) $slides[] = 'upcoming';
if ($partners) $slides[] = 'partners';
if ($sponsors) $slides[] = 'sponsors';

// Repeat the QR slide so guests regularly see the event code during the loop.
if (in_array('qr', $slides, true) && count($slides) > 5) {
    // Repeat the QR later in the loop, after the core information slides have appeared.
    $insertAt = min(6, count($slides));
    array_splice($slides, $insertAt, 0, ['qr']);
}

dttd_display_json([
    'ok' => true,
    'active_event' => true,
    'display_mode' => 'live_event',
    'event' => dttd_display_event_payload($event),
    'venue' => $venue,
    'slides' => $slides,
    'requests' => $requests,
    'played_requests' => $playedRequests,
    'recent_tracks' => $recent,
    'photos' => $photos,
    'upcoming_events' => $upcoming,
    'partners' => $partners,
    'sponsors' => $sponsors,
    'generated_at' => date('c'),
]);
