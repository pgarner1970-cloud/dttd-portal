<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (!dttd_spotify_queue_connected()) {
        throw new RuntimeException('Spotify account is not connected yet.');
    }

    $devices = dttd_spotify_get_devices();
    $clean = [];

    foreach ($devices as $device) {
        $clean[] = [
            'id' => (string)($device['id'] ?? ''),
            'name' => (string)($device['name'] ?? 'Spotify device'),
            'type' => (string)($device['type'] ?? ''),
            'is_active' => !empty($device['is_active']),
        ];
    }

    echo json_encode([
        'ok' => true,
        'devices' => $clean,
    ]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'devices' => [],
    ]);
}
