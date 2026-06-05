<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/spotify.php';
require_once __DIR__ . '/../includes/track-history.php';

dttd_no_cache_headers();
header('Content-Type: application/json; charset=utf-8');

function public_np_json($payload) {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function public_np_table_exists($table) {
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

function public_np_setting($key, $default = '') {
    if (!public_np_table_exists('app_settings')) return $default;
    try {
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([(string)$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function public_np_set($key, $value) {
    if (!public_np_table_exists('app_settings')) return false;
    try {
        $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([(string)$key, (string)$value]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function public_np_active_event() {
    try {
        $stmt = db()->query("\n            SELECT *,\n                TIMESTAMP(event_date, start_time) AS live_start_at,\n                CASE\n                    WHEN end_time IS NULL OR end_time = '' THEN TIMESTAMP(event_date, start_time)\n                    WHEN end_time < start_time THEN TIMESTAMP(DATE_ADD(event_date, INTERVAL 1 DAY), end_time)\n                    ELSE TIMESTAMP(event_date, end_time)\n                END AS live_end_at\n            FROM events\n            WHERE is_active = 1\n              AND event_date IS NOT NULL\n              AND start_time IS NOT NULL\n              AND start_time <> ''\n              AND NOW() >= TIMESTAMP(event_date, start_time)\n              AND NOW() <= CASE\n                    WHEN end_time IS NULL OR end_time = '' THEN DATE_ADD(TIMESTAMP(event_date, start_time), INTERVAL 6 HOUR)\n                    WHEN end_time < start_time THEN TIMESTAMP(DATE_ADD(event_date, INTERVAL 1 DAY), end_time)\n                    ELSE TIMESTAMP(event_date, end_time)\n              END\n            ORDER BY event_date ASC, start_time ASC, id ASC\n            LIMIT 1\n        ");
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function public_np_normalise_track_text($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function public_np_track_keys($track) {
    $keys = [];
    $id = strtolower(trim((string)($track['id'] ?? $track['spotify_track_id'] ?? '')));
    if ($id !== '') {
        $keys[] = 'id:' . $id;
    }

    $title = public_np_normalise_track_text($track['title'] ?? $track['song_title'] ?? '');
    $artist = public_np_normalise_track_text($track['artist'] ?? '');
    if ($title !== '' || $artist !== '') {
        $keys[] = 'txt:' . $title . '|' . $artist;
    }

    return array_values(array_unique($keys));
}

function public_np_track_seen($track, $seen) {
    foreach (public_np_track_keys($track) as $key) {
        if (isset($seen[$key])) return true;
    }
    return false;
}

function public_np_mark_track_seen($track, &$seen) {
    foreach (public_np_track_keys($track) as $key) {
        $seen[$key] = true;
    }
}

function public_np_public_track($track, $status, $playedAt = '') {
    $title = trim((string)($track['title'] ?? $track['song_title'] ?? ''));
    $artist = trim((string)($track['artist'] ?? ''));
    $id = trim((string)($track['id'] ?? $track['spotify_track_id'] ?? ''));
    $image = trim((string)($track['image'] ?? $track['spotify_album_image'] ?? ''));
    $url = trim((string)($track['url'] ?? $track['spotify_track_url'] ?? ''));
    if ($url === '' && $id !== '' && strpos($id, 'local:') !== 0) {
        $url = 'https://open.spotify.com/track/' . rawurlencode($id);
    }
    return [
        'id' => $id,
        'title' => $title !== '' ? $title : 'Unknown track',
        'artist' => $artist,
        'image' => $image,
        'url' => $url,
        'status' => $status,
        'played_at' => $playedAt,
    ];
}

function public_np_track_from_playback($playback, $deckLabel = '') {
    if (!is_array($playback) || empty($playback['is_playing'])) return null;
    $item = $playback['item'] ?? null;
    if (!is_array($item)) return null;
    $title = trim((string)($item['name'] ?? ''));
    $id = trim((string)($item['id'] ?? ''));
    if ($title === '' && $id === '') return null;

    $artists = [];
    foreach (($item['artists'] ?? []) as $artist) {
        if (!empty($artist['name'])) $artists[] = (string)$artist['name'];
    }

    $images = $item['album']['images'] ?? [];
    $image = '';
    if ($images) {
        $last = end($images);
        $image = (string)($last['url'] ?? ($images[0]['url'] ?? ''));
    }

    return [
        'id' => $id,
        'title' => $title,
        'artist' => implode(', ', $artists),
        'image' => $image,
        'url' => (string)($item['external_urls']['spotify'] ?? ($id !== '' ? 'https://open.spotify.com/track/' . $id : '')),
        'deck' => $deckLabel,
        'progress_ms' => isset($playback['progress_ms']) ? (int)$playback['progress_ms'] : null,
        'duration_ms' => isset($item['duration_ms']) ? (int)$item['duration_ms'] : null,
    ];
}

function public_np_current_spotify_track() {
    if (!function_exists('dttd_spotify_config_loaded') || !dttd_spotify_config_loaded()) return null;

    $deviceA = public_np_setting('spotify_mixer_device_a', '');
    $deviceB = public_np_setting('spotify_mixer_device_b', '');
    $playbackA = null;
    $playbackB = null;

    try { $playbackA = dttd_spotify_current_playback_for_deck('a'); } catch (Throwable $e) { $playbackA = null; }
    $shareProfile = function_exists('dttd_spotify_decks_share_profile') ? dttd_spotify_decks_share_profile() : true;
    if ($shareProfile) {
        $playbackB = $playbackA;
    } else {
        try { $playbackB = dttd_spotify_current_playback_for_deck('b'); } catch (Throwable $e) { $playbackB = null; }
    }

    $candidates = [
        ['deck' => 'A', 'device' => $deviceA, 'playback' => $playbackA],
        ['deck' => 'B', 'device' => $deviceB, 'playback' => $playbackB],
    ];

    foreach ($candidates as $candidate) {
        $playback = $candidate['playback'];
        if (!is_array($playback) || empty($playback['is_playing'])) continue;
        $activeDevice = (string)($playback['device']['id'] ?? '');
        $deckDevice = trim((string)$candidate['device']);
        if ($deckDevice !== '' && $activeDevice === $deckDevice) {
            $track = public_np_track_from_playback($playback, $candidate['deck']);
            if ($track) return $track;
        }
    }

    foreach ($candidates as $candidate) {
        $track = public_np_track_from_playback($candidate['playback'], $candidate['deck']);
        if ($track) return $track;
    }

    return null;
}

$event = public_np_active_event();
if (!$event || empty($event['id'])) {
    public_np_json(['ok' => true, 'active_event' => false, 'tracks' => [], 'generated_at' => date('c')]);
}

$eventId = (int)$event['id'];
$cacheKey = 'public_now_playing_cache_' . $eventId;
$cacheTtl = 6;
$cachedRaw = public_np_setting($cacheKey, '');
if ($cachedRaw !== '') {
    $cached = json_decode($cachedRaw, true);
    if (is_array($cached) && isset($cached['_cache_time']) && ((int)$cached['_cache_time'] >= time() - $cacheTtl)) {
        unset($cached['_cache_time']);
        public_np_json($cached);
    }
}

$current = public_np_current_spotify_track();
$historyRows = function_exists('dttd_history_public_track_rows') ? dttd_history_public_track_rows($eventId, 10) : [];
$tracks = [];
$seen = [];

if ($current) {
    $currentPublic = public_np_public_track($current, 'current', date('c'));
    if (public_np_track_keys($currentPublic)) {
        public_np_mark_track_seen($currentPublic, $seen);
        $tracks[] = $currentPublic;
    }
}

foreach ($historyRows as $row) {
    $track = public_np_public_track($row, $current ? 'recent' : (empty($tracks) ? 'latest' : 'recent'), (string)($row['created_at'] ?? $row['played_at'] ?? ''));
    if (!public_np_track_keys($track) || public_np_track_seen($track, $seen)) continue;
    public_np_mark_track_seen($track, $seen);
    $tracks[] = $track;
    if (count($tracks) >= 6) break;
}

$payload = [
    'ok' => true,
    'active_event' => true,
    'event_id' => $eventId,
    'has_live_current' => (bool)$current,
    'tracks' => $tracks,
    'generated_at' => date('c'),
];

public_np_set($cacheKey, json_encode($payload + ['_cache_time' => time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
public_np_json($payload);
