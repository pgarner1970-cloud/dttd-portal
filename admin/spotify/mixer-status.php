<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

header('Content-Type: application/json; charset=utf-8');

function mixer_status_setting($key, $default = '') {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

try {
    if (!dttd_spotify_queue_connected()) {
        throw new RuntimeException('Spotify account is not connected.');
    }

    $devices = dttd_spotify_get_devices();
    $playback = null;
    try {
        $playback = dttd_spotify_current_playback();
    } catch (Throwable $ignored) {
        $playback = null;
    }

    $deviceA = mixer_status_setting('spotify_mixer_device_a', '');
    $deviceB = mixer_status_setting('spotify_mixer_device_b', '');
    $activeDeviceId = (string)($playback['device']['id'] ?? '');
    $isPlaying = !empty($playback['is_playing']);
    $item = $playback['item'] ?? [];
    $artists = [];
    foreach (($item['artists'] ?? []) as $artist) {
        if (!empty($artist['name'])) {
            $artists[] = $artist['name'];
        }
    }

    $cleanDevices = [];
    foreach ($devices as $device) {
        $cleanDevices[] = [
            'id' => (string)($device['id'] ?? ''),
            'name' => (string)($device['name'] ?? 'Spotify device'),
            'type' => (string)($device['type'] ?? ''),
            'is_active' => !empty($device['is_active']),
        ];
    }

    echo json_encode([
        'ok' => true,
        'server_time' => date('H:i:s'),
        'active_device_id' => $activeDeviceId,
        'active_device_name' => (string)($playback['device']['name'] ?? ''),
        'is_playing' => $isPlaying,
        'player_a' => [
            'device_id' => $deviceA,
            'state' => ($activeDeviceId !== '' && $activeDeviceId === $deviceA && $isPlaying) ? 'playing' : 'standby',
        ],
        'player_b' => [
            'device_id' => $deviceB,
            'state' => ($activeDeviceId !== '' && $activeDeviceId === $deviceB && $isPlaying) ? 'playing' : 'standby',
        ],
        'track' => [
            'title' => (string)($item['name'] ?? ''),
            'artist' => implode(', ', $artists),
            'progress_ms' => $playback['progress_ms'] ?? null,
            'duration_ms' => $item['duration_ms'] ?? null,
        ],
        'devices' => $cleanDevices,
    ]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
