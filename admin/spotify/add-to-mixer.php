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

function mx_new_request_group_id_local() {
    try {
        return 'grp_' . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return 'grp_' . uniqid('', true);
    }
}

function mx_current_event_id_local() {
    try {
        if (function_exists('dttd_get_calculated_current_event')) {
            $event = dttd_get_calculated_current_event();
            if ($event && !empty($event['id'])) {
                return (int)$event['id'];
            }
        }
    } catch (Throwable $e) {
        return 0;
    }
    return 0;
}

function mx_resolve_request_group_id_local($submittedGroupId) {
    $submittedGroupId = trim((string)$submittedGroupId);
    if ($submittedGroupId === '' || !mx_has_column_local('song_requests', 'request_group_id')) {
        return $submittedGroupId;
    }

    if (strpos($submittedGroupId, 'gid:') === 0) {
        return substr($submittedGroupId, 4);
    }

    // Older queue cards may have posted the fallback display key, e.g.
    // "open|song title|artist", when the DB row had no request_group_id yet.
    // Convert that fallback key into a real group id so Add to Mixer can work.
    if (strpos($submittedGroupId, 'open|') !== 0) {
        return $submittedGroupId;
    }

    $parts = explode('|', $submittedGroupId, 3);
    if (count($parts) !== 3) {
        return $submittedGroupId;
    }

    $eventId = mx_current_event_id_local();
    if ($eventId <= 0) {
        return $submittedGroupId;
    }

    $baseKey = $parts[1] . '|' . $parts[2];

    try {
        $existing = db()->prepare("
            SELECT request_group_id
            FROM song_requests
            WHERE event_id = ?
            AND request_group_id IS NOT NULL
            AND request_group_id <> ''
            AND status IN ('pending','maybe','duplicate')
            AND CONCAT(LOWER(TRIM(song_title)), '|', LOWER(TRIM(artist))) = ?
            ORDER BY created_at ASC, id ASC
            LIMIT 1
        ");
        $existing->execute([$eventId, $baseKey]);
        $existingGroupId = $existing->fetchColumn();
        if ($existingGroupId) {
            return (string)$existingGroupId;
        }

        $newGroupId = mx_new_request_group_id_local();
        $update = db()->prepare("
            UPDATE song_requests
            SET request_group_id = ?
            WHERE event_id = ?
            AND (request_group_id IS NULL OR request_group_id = '')
            AND status IN ('pending','maybe','duplicate')
            AND CONCAT(LOWER(TRIM(song_title)), '|', LOWER(TRIM(artist))) = ?
        ");
        $update->execute([$newGroupId, $eventId, $baseKey]);

        return $update->rowCount() > 0 ? $newGroupId : $submittedGroupId;
    } catch (Throwable $e) {
        return $submittedGroupId;
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
$groupId = mx_resolve_request_group_id_local($groupId);

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

    $select = ['id', 'guest_name', 'song_title', 'artist', 'created_at', 'spotify_track_id'];
    foreach (['spotify_track_url', 'spotify_album_image', 'message', 'dedication'] as $optionalColumn) {
        if (mx_has_column_local('song_requests', $optionalColumn)) {
            $select[] = $optionalColumn;
        }
    }

    $selectSql = implode(', ', array_map(function($c) { return '`' . $c . '`'; }, $select));
    $stmt = db()->prepare("SELECT {$selectSql} FROM song_requests WHERE $where ORDER BY created_at ASC, id ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (!$rows) {
        $_SESSION['spotify_flash'] = 'No Spotify-matched request was found for that group.';
        mx_redirect_back();
    }

    // In Full DJ Mixer mode the Request Queue button should not add straight to the DJ playlist.
    // It marks the public request as available to the Live Mixer public-request feed.
    // The DJ then decides whether to add it to the DJ playlist, load it to A/B, or play it immediately.
    $sets = [];
    $updateParams = [];
    if (mx_has_column_local('song_requests', 'spotify_queued_at')) { $sets[] = 'spotify_queued_at = NOW()'; }
    if (mx_has_column_local('song_requests', 'spotify_queue_status')) { $sets[] = 'spotify_queue_status = ?'; $updateParams[] = 'mixer_request'; }
    if ($sets) {
        $updateParams[] = $groupId;
        $upd = db()->prepare('UPDATE song_requests SET ' . implode(', ', $sets) . ' WHERE request_group_id = ?');
        $upd->execute($updateParams);
    }

    $_SESSION['spotify_flash'] = 'Request sent to the Live Mixer public requests feed.';
    mx_redirect_back();
} catch (Throwable $e) {
    $_SESSION['spotify_flash'] = 'Could not send request to Live Mixer: ' . $e->getMessage();
    mx_redirect_back();
}
