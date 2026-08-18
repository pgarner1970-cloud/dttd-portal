<?php
require_once __DIR__ . '/../../includes/db.php';
dttd_no_cache_headers();
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow, noarchive', true);

$nodeKey = trim((string)($_GET['node'] ?? $_GET['node_key'] ?? ''));
if ($nodeKey === '') {
    echo json_encode([
        'ok' => true,
        'node_key' => '',
        'mode' => 'live',
        'generated_at' => date('c'),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$safeNodeKey = preg_replace('/[^a-z0-9_-]+/i', '_', $nodeKey);
$settingKey = 'display_operating_mode_' . $safeNodeKey;
$mode = 'live';

try {
    $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$settingKey]);
    $stored = strtolower(trim((string)$stmt->fetchColumn()));
    if (in_array($stored, ['live', 'logo', 'blank'], true)) {
        $mode = $stored;
    }
} catch (Throwable $e) {
    $mode = 'live';
}

echo json_encode([
    'ok' => true,
    'node_key' => $nodeKey,
    'mode' => $mode,
    'generated_at' => date('c'),
], JSON_UNESCAPED_SLASHES);
