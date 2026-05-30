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

$stmt = $pdo->prepare($sql);

$stmt->execute([
    ':node_key' => $nodeKey,
    ':hostname' => $hostname,
    ':display_name' => $displayName,
    ':spotify_name' => $spotifyName,
    ':ip_address' => $ipAddress,
    ':raspotify_running' => $raspotifyRunning
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