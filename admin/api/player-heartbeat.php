<?php
require_once __DIR__ . '/../../includes/db.php';

$secret = 'DMX_NODE_SECRET_7f2c9e4a1b8d6f0c3e5a9d7b2f4c8e1';

$headers = getallheaders();
if (($headers['X-DMX-Node-Secret'] ?? '') !== $secret) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$nodeKey = $data['node_key'] ?? '';
$hostname = $data['hostname'] ?? '';
$displayName = $data['display_name'] ?? $hostname;
$spotifyName = $data['spotify_name'] ?? $displayName;
$ipAddress = $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'];
$raspotifyRunning = !empty($data['raspotify_running']) ? 1 : 0;

if (!$nodeKey || !$hostname) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing node_key or hostname']);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO player_nodes
        (node_key, hostname, display_name, spotify_name, ip_address, raspotify_running, status, last_seen)
    VALUES
        (?, ?, ?, ?, ?, ?, 'online', NOW())
    ON DUPLICATE KEY UPDATE
        hostname = VALUES(hostname),
        display_name = VALUES(display_name),
        spotify_name = VALUES(spotify_name),
        ip_address = VALUES(ip_address),
        raspotify_running = VALUES(raspotify_running),
        status = 'online',
        last_seen = NOW()
");

$stmt->execute([
    $nodeKey,
    $hostname,
    $displayName,
    $spotifyName,
    $ipAddress,
    $raspotifyRunning
]);

header('Content-Type: application/json');
echo json_encode(['ok' => true]);