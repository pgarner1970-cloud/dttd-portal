<?php
require_once __DIR__ . '/db.php';

function dttd_local_music_table_exists($table) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') return false;
    if (array_key_exists($table, $cache)) return $cache[$table];

    try {
        $stmt = db()->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function dttd_local_music_setting($key, $default = '') {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([(string)$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function dttd_local_music_set_setting($key, $value) {
    $stmt = db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([(string)$key, (string)$value]);
}

function dttd_local_music_sync_key() {
    return dttd_local_music_setting('local_music_sync_key', '');
}

function dttd_local_music_generate_sync_key() {
    $key = bin2hex(random_bytes(24));
    dttd_local_music_set_setting('local_music_sync_key', $key);
    return $key;
}

function dttd_local_music_mask_key($key) {
    $key = (string)$key;
    if ($key === '') return '';
    if (strlen($key) <= 10) return str_repeat('•', strlen($key));
    return substr($key, 0, 6) . str_repeat('•', max(6, strlen($key) - 12)) . substr($key, -6);
}

function dttd_local_music_normalise_path($path) {
    $path = str_replace('\\', '/', trim((string)$path));
    $path = preg_replace('#/+#', '/', $path);
    $path = ltrim($path, '/');
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') continue;
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function dttd_local_music_guess_title($relativePath, $fileName = '') {
    $name = $fileName !== '' ? $fileName : basename((string)$relativePath);
    $name = preg_replace('/\.[a-zA-Z0-9]{2,5}$/', '', $name);
    $name = preg_replace('/^[0-9]{1,3}[\s._-]+/', '', $name);
    $name = str_replace(['_', '.'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function dttd_local_music_track_output($row) {
    $title = trim((string)($row['display_title'] ?? ''));
    if ($title === '') $title = trim((string)($row['detected_title'] ?? ''));
    if ($title === '') $title = dttd_local_music_guess_title((string)($row['relative_path'] ?? ''), (string)($row['file_name'] ?? ''));

    $artist = trim((string)($row['display_artist'] ?? ''));
    if ($artist === '') $artist = trim((string)($row['detected_artist'] ?? ''));
    if ($artist === '') $artist = 'Local music';

    $album = trim((string)($row['display_album'] ?? ''));
    if ($album === '') $album = trim((string)($row['detected_album'] ?? ''));

    $durationMs = null;
    if (isset($row['duration_seconds']) && $row['duration_seconds'] !== null && $row['duration_seconds'] !== '') {
        $durationMs = max(0, (int)$row['duration_seconds']) * 1000;
    }

    $image = trim((string)($row['artwork_path'] ?? ''));
    if ($image === '') $image = 'https://dancethruthedecades.co.uk/assets/glitter-ball-clean.png';

    $spotifyUrl = trim((string)($row['spotify_match_url'] ?? ''));
    $spotifyUri = trim((string)($row['spotify_match_uri'] ?? ''));

    return [
        'id' => 'local:' . (int)($row['id'] ?? 0),
        'source' => 'local',
        'source_label' => 'Local',
        'local_track_id' => (int)($row['id'] ?? 0),
        'local_path' => (string)($row['relative_path'] ?? ''),
        'title' => $title,
        'artist' => $artist,
        'album' => $album,
        'image' => $image,
        'url' => $spotifyUrl,
        'spotify_uri' => $spotifyUri,
        'spotify_url' => $spotifyUrl,
        'duration_ms' => $durationMs,
        'needs_review' => !empty($row['needs_review']),
        'spotify_match_status' => (string)($row['spotify_match_status'] ?? 'unchecked'),
    ];
}

function dttd_local_music_search($query, $limit = 10) {
    if (!dttd_local_music_table_exists('local_tracks')) return [];

    $query = trim((string)$query);
    if ($query === '' || strlen($query) < 2) return [];

    $terms = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
    $terms = array_slice($terms ?: [], 0, 5);
    if (!$terms) return [];

    $where = ["is_enabled = 1", "missing_since_at IS NULL"];
    $params = [];
    foreach ($terms as $term) {
        $where[] = "(display_title LIKE ? OR detected_title LIKE ? OR display_artist LIKE ? OR detected_artist LIKE ? OR display_album LIKE ? OR detected_album LIKE ? OR relative_path LIKE ? OR file_name LIKE ?)";
        $like = '%' . $term . '%';
        for ($i = 0; $i < 8; $i++) $params[] = $like;
    }

    $limit = max(1, min(30, (int)$limit));
    $sql = "SELECT * FROM local_tracks WHERE " . implode(' AND ', $where) . " ORDER BY needs_review ASC, COALESCE(NULLIF(display_artist,''), NULLIF(detected_artist,''), '') ASC, COALESCE(NULLIF(display_title,''), NULLIF(detected_title,''), file_name) ASC LIMIT " . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('dttd_local_music_track_output', $stmt->fetchAll());
}

function dttd_local_music_counts() {
    $empty = [
        'table_exists' => dttd_local_music_table_exists('local_tracks'),
        'total' => 0,
        'enabled' => 0,
        'needs_review' => 0,
        'missing' => 0,
        'public_enabled' => 0,
        'spotify_matched' => 0,
        'last_seen_at' => '',
    ];
    if (!$empty['table_exists']) return $empty;

    try {
        $row = db()->query("SELECT COUNT(*) AS total, SUM(is_enabled = 1) AS enabled, SUM(needs_review = 1) AS needs_review, SUM(missing_since_at IS NOT NULL) AS missing, SUM(public_search_enabled = 1) AS public_enabled, SUM(spotify_match_status = 'matched') AS spotify_matched, MAX(last_seen_at) AS last_seen_at FROM local_tracks")->fetch();
        foreach (['total','enabled','needs_review','missing','public_enabled','spotify_matched'] as $key) {
            $empty[$key] = (int)($row[$key] ?? 0);
        }
        $empty['last_seen_at'] = (string)($row['last_seen_at'] ?? '');
    } catch (Throwable $e) {}

    return $empty;
}

function dttd_local_music_recent_tracks($limit = 50) {
    if (!dttd_local_music_table_exists('local_tracks')) return [];
    $limit = max(1, min(200, (int)$limit));
    try {
        return db()->query("SELECT * FROM local_tracks ORDER BY updated_at DESC, id DESC LIMIT " . $limit)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
