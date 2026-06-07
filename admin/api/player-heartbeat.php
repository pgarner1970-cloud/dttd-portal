<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';

/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

$secret = 'DMX_NODE_SECRET_7f2c9e4a1b8d6f0c3e5a9d7b2f4c8e1';

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/

$providedSecret =
    $_SERVER['HTTP_X_DMX_NODE_SECRET']
    ?? ($_SERVER['REDIRECT_HTTP_X_DMX_NODE_SECRET'] ?? '')
    ?? '';

if (!hash_equals($secret, $providedSecret)) {

    http_response_code(403);

    echo json_encode([
        'ok' => false,
        'error' => 'Forbidden'
    ]);

    exit;
}


function dttd_player_hb_col_exists($table, $column) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if ($table === '' || $column === '') return false;
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = db()->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
        $stmt->execute([$column]);
        return $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function dttd_player_hb_optional_update($nodeKey, $values) {
    $sets = [];
    $params = [];
    foreach ($values as $column => $value) {
        if (!dttd_player_hb_col_exists('player_nodes', $column)) continue;
        $sets[] = '`' . $column . '` = ?';
        $params[] = $value;
    }
    if (!$sets) return;
    $params[] = $nodeKey;
    $stmt = db()->prepare('UPDATE player_nodes SET ' . implode(', ', $sets) . ' WHERE node_key = ?');
    $stmt->execute($params);
}

/*
|--------------------------------------------------------------------------
| READ JSON
|--------------------------------------------------------------------------
*/

$raw = file_get_contents('php://input');

$data = json_decode($raw, true);

if (!$data) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Invalid JSON'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| FIELDS
|--------------------------------------------------------------------------
*/

$nodeKey = trim($data['node_key'] ?? '');
$hostname = trim($data['hostname'] ?? '');
$displayName = trim($data['display_name'] ?? '');
$spotifyName = trim($data['spotify_name'] ?? '');
$ipAddress = trim($data['ip_address'] ?? $_SERVER['REMOTE_ADDR']);
$raspotifyRunning = !empty($data['raspotify_running']) ? 1 : 0;
$mpdRunning = !empty($data['mpd_running']) ? 1 : 0;
$localMusicMounted = !empty($data['local_music_mounted']) ? 1 : 0;
$displayBrowserRunning = !empty($data['display_browser_running']) ? 1 : 0;
$displayStatus = isset($data['display_status']) && is_array($data['display_status']) ? $data['display_status'] : [];
$displayMode = trim((string)($displayStatus['mode'] ?? ''));
$displayUrl = trim((string)($displayStatus['url'] ?? ''));
$displayStatusJson = json_encode($displayStatus, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (!$nodeKey || !$hostname) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => 'Missing required fields'
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| UPSERT NODE
|--------------------------------------------------------------------------
*/

$sql = "
    INSERT INTO player_nodes
    (
        node_key,
        hostname,
        display_name,
        spotify_name,
        ip_address,
        raspotify_running,
        status,
        last_seen
    )
    VALUES
    (
        :node_key,
        :hostname,
        :display_name,
        :spotify_name,
        :ip_address,
        :raspotify_running,
        'online',
        NOW()
    )
    ON DUPLICATE KEY UPDATE
        hostname = VALUES(hostname),
        display_name = VALUES(display_name),
        spotify_name = VALUES(spotify_name),
        ip_address = VALUES(ip_address),
        raspotify_running = VALUES(raspotify_running),
        status = 'online',
        last_seen = NOW()
";

$stmt = db()->prepare($sql);

$stmt->execute([
    ':node_key' => $nodeKey,
    ':hostname' => $hostname,
    ':display_name' => $displayName,
    ':spotify_name' => $spotifyName,
    ':ip_address' => $ipAddress,
    ':raspotify_running' => $raspotifyRunning
]);

dttd_player_hb_optional_update($nodeKey, [
    'mpd_running' => $mpdRunning,
    'local_music_mounted' => $localMusicMounted,
    'display_browser_running' => $displayBrowserRunning,
    'display_mode' => $displayMode,
    'display_url' => $displayUrl,
    'display_status_json' => $displayStatusJson,
]);

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

echo json_encode([
    'ok' => true,
    'node_key' => $nodeKey,
    'hostname' => $hostname,
    'time' => date('Y-m-d H:i:s')
]);