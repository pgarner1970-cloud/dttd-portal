<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';

$secret = 'DMX_NODE_SECRET_7f2c9e4a1b8d6f0c3e5a9d7b2f4c8e1';

$providedSecret =
    $_SERVER['HTTP_X_DMX_NODE_SECRET']
    ?? ($_SERVER['REDIRECT_HTTP_X_DMX_NODE_SECRET'] ?? '')
    ?? '';

if (!hash_equals($secret, $providedSecret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

function dmx_node_setting_get($key, $default = '') {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([(string)$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function dmx_node_setting_set($key, $value) {
    $stmt = db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([(string)$key, (string)$value]);
}

function dmx_node_json_get($key) {
    $raw = dmx_node_setting_get($key, '');
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function dmx_node_json_set($key, $value) {
    dmx_node_setting_set($key, json_encode($value, JSON_UNESCAPED_SLASHES));
}

function dmx_node_safe_relative_path($path) {
    $path = trim((string)$path);
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, "\0") !== false || $path === '..' || strpos($path, '../') === 0 || strpos($path, '/../') !== false) return '';
    return $path;
}

function dmx_node_track_relative_path($track) {
    if (!is_array($track)) return '';
    return dmx_node_safe_relative_path($track['local_relative_path'] ?? $track['local_path'] ?? $track['relative_path'] ?? '');
}

function dmx_node_complete_local_prepare($command, $status, $result) {
    if (!is_array($command) || (string)($command['command'] ?? '') !== 'local_prepare') return;
    $payload = json_decode((string)($command['payload'] ?? ''), true);
    if (!is_array($payload)) $payload = [];

    $deck = strtolower((string)($payload['deck'] ?? '')) === 'b' ? 'b' : 'a';
    $preparedPath = dmx_node_safe_relative_path($payload['relative_path'] ?? $payload['local_path'] ?? $payload['path'] ?? '');
    if ($preparedPath === '') return;

    $settingKey = 'spotify_mixer_loaded_' . $deck;
    $loaded = dmx_node_json_get($settingKey);
    if (!$loaded) return;

    $loadedPath = dmx_node_track_relative_path($loaded);
    if ($loadedPath === '' || $loadedPath !== $preparedPath) return;

    $ok = strtolower((string)$status) === 'completed';
    $loaded['local_is_prepared'] = $ok;
    $loaded['local_prepare_completed_at'] = time();
    $loaded['local_prepare_result'] = (string)$result;
    $loaded['local_prepare_command_id'] = (int)($command['id'] ?? 0);
    if ($ok) {
        $loaded['local_prepare_error'] = '';
    } else {
        $loaded['local_prepare_error'] = (string)$result;
    }
    dmx_node_json_set($settingKey, $loaded);
}

$data = json_decode(file_get_contents('php://input'), true);

$nodeKey = trim($data['node_key'] ?? '');
$mode = trim($data['mode'] ?? 'poll');

if ($nodeKey === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing node_key']);
    exit;
}

if ($mode === 'complete') {
    $commandId = (int)($data['command_id'] ?? 0);
    $status = trim($data['status'] ?? 'completed');
    $result = trim($data['result'] ?? '');

    if (!$commandId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing command_id']);
        exit;
    }

    $commandStmt = db()->prepare("SELECT id, node_key, command, payload FROM node_commands WHERE id = ? AND node_key = ? LIMIT 1");
    $commandStmt->execute([$commandId, $nodeKey]);
    $completedCommand = $commandStmt->fetch();

    $stmt = db()->prepare("\n        UPDATE node_commands\n        SET status = ?, result = ?, completed_at = NOW()\n        WHERE id = ? AND node_key = ?\n    ");
    $stmt->execute([$status, $result, $commandId, $nodeKey]);

    try {
        dmx_node_complete_local_prepare($completedCommand ?: [], $status, $result);
    } catch (Throwable $ignoredLocalPrepareStatus) {}

    echo json_encode(['ok' => true]);
    exit;
}

$stmt = db()->prepare("\n    SELECT id, command, payload\n    FROM node_commands\n    WHERE node_key = ?\n      AND status = 'pending'\n    ORDER BY created_at ASC\n    LIMIT 1\n");
$stmt->execute([$nodeKey]);
$command = $stmt->fetch();

db()->prepare("\n    UPDATE player_nodes\n    SET last_command_check = NOW()\n    WHERE node_key = ?\n")->execute([$nodeKey]);

if (!$command) {
    echo json_encode(['ok' => true, 'command' => null]);
    exit;
}

db()->prepare("\n    UPDATE node_commands\n    SET status = 'running', picked_up_at = NOW()\n    WHERE id = ?\n")->execute([$command['id']]);

echo json_encode([
    'ok' => true,
    'command' => [
        'id' => (int)$command['id'],
        'name' => $command['command'],
        'payload' => $command['payload'] ? json_decode($command['payload'], true) : null
    ]
]);
