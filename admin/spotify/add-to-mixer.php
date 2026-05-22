<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

function mx_redirect_back($fallback = '../requests.php') {
    $return = (string)($_POST['return'] ?? '');
    if ($return !== '' && preg_match('~^https://dj\.dancethruthedecades\.co\.uk/|^/|^[a-z0-9_./?=&%-]+$~i', $return)) {
        header('Location: ' . $return);
        exit;
    }
    header('Location: ' . $fallback);
    exit;
}

function mx_setting_local($key, $default = '') {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function mx_set_local($key, $value) {
    $stmt = db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, (string)$value]);
}

function mx_json_local($key, $default = []) {
    $raw = mx_setting_local($key, '');
    if ($raw === '') return $default;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function mx_has_column_local($table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mx_redirect_back();
}

if (mx_setting_local('spotify_enabled', '0') !== '1' || mx_setting_local('spotify_queue_enabled', '0') !== '1' || mx_setting_local('spotify_queue_mode', 'standard') !== 'mixer') {
    $_SESSION['spotify_flash'] = 'Live Mixer mode is not enabled.';
    mx_redirect_back();
}

$groupId = trim((string)($_POST['request_group_id'] ?? ''));
if ($groupId === '') {
    $_SESSION['spotify_flash'] = 'No request group was supplied.';
    mx_redirect_back();
}

try {
    $where = "status IN ('pending','maybe','duplicate') AND spotify_track_id IS NOT NULL AND spotify_track_id <> ''";
    $params = [];

    if (mx_has_column_local('song_requests', 'request_group_id')) {
        $where .= " AND request_group_id = ?";
        $params[] = $groupId;
    } else {
        $_SESSION['spotify_flash'] = 'Request grouping is not available.';
        mx_redirect_back();
    }

    $stmt = db()->prepare("SELECT id, guest_name, song_title, artist, message, dedication, created_at, spotify_track_id, spotify_track_url, spotify_album_image FROM song_requests WHERE $where ORDER BY created_at ASC, id ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $_SESSION['spotify_flash'] = 'No Spotify-matched request was found for that group.';
        mx_redirect_back();
    }

    $first = $rows[0];
    $messages = [];
    foreach ($rows as $r) {
        $name = trim((string)($r['guest_name'] ?? 'Guest')) ?: 'Guest';
        $msg = trim((string)($r['message'] ?? ($r['dedication'] ?? '')));
        if ($msg !== '') $messages[] = $name . ': ' . $msg;
    }

    $track = [
        'id' => (string)$first['spotify_track_id'],
        'title' => (string)$first['song_title'],
        'artist' => (string)$first['artist'],
        'album' => '',
        'image' => (string)($first['spotify_album_image'] ?? ''),
        'url' => (string)($first['spotify_track_url'] ?? ''),
        'duration_ms' => null,
        'source' => 'request',
        'request_id' => (int)$first['id'],
        'request_group_id' => $groupId,
        'guest_name' => (string)($first['guest_name'] ?? 'Guest'),
        'message' => implode("\n", $messages),
        'added_at' => date('Y-m-d H:i:s'),
    ];

    $playlist = mx_json_local('spotify_mixer_playlist', []);
    foreach ($playlist as $p) {
        if (!empty($p['request_group_id']) && (string)$p['request_group_id'] === $groupId) {
            $_SESSION['spotify_flash'] = 'That request is already in the Live Mixer DJ playlist.';
            mx_redirect_back();
        }
    }

    array_unshift($playlist, $track);
    mx_set_local('spotify_mixer_playlist', json_encode(array_values(array_slice($playlist, 0, 80))));

    $sets = [];
    $updateParams = [];
    if (mx_has_column_local('song_requests', 'spotify_queued_at')) { $sets[] = 'spotify_queued_at = NOW()'; }
    if (mx_has_column_local('song_requests', 'spotify_queue_status')) { $sets[] = 'spotify_queue_status = ?'; $updateParams[] = 'dj_playlist'; }
    if ($sets) {
        $updateParams[] = $groupId;
        $upd = db()->prepare('UPDATE song_requests SET ' . implode(', ', $sets) . ' WHERE request_group_id = ?');
        $upd->execute($updateParams);
    }

    $_SESSION['spotify_flash'] = 'Request sent to the Live Mixer DJ playlist.';
    mx_redirect_back();
} catch (Throwable $e) {
    $_SESSION['spotify_flash'] = 'Could not send request to Live Mixer: ' . $e->getMessage();
    mx_redirect_back();
}
