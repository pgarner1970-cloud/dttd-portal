<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/spotify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));

try {
    if (!dttd_spotify_config_loaded()) {
        echo json_encode([
            'ok' => false,
            'configured' => false,
            'message' => 'Spotify API is not configured yet.',
            'tracks' => [],
        ]);
        exit;
    }

    if ($q === '' || mb_strlen($q) < 2) {
        echo json_encode(['ok' => true, 'configured' => true, 'tracks' => []]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'configured' => true,
        'tracks' => dttd_spotify_search_tracks($q, 8),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'configured' => true,
        'message' => 'Spotify search is currently unavailable.',
        'tracks' => [],
    ]);
}
