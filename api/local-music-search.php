<?php
require_once __DIR__ . '/../includes/local-music.php';

dttd_no_cache_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], [
    'https://dj.dancethruthedecades.co.uk',
    'https://dancethruthedecades.co.uk',
], true)) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
}

$q = trim((string)($_GET['q'] ?? ''));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

try {
    $tracks = dttd_local_music_search($q, $limit);
    echo json_encode([
        'ok' => true,
        'configured' => dttd_local_music_table_exists('local_tracks'),
        'tracks' => $tracks,
        'source' => 'local',
        'message' => dttd_local_music_table_exists('local_tracks') ? '' : 'Local music table has not been created yet.',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'configured' => dttd_local_music_table_exists('local_tracks'),
        'tracks' => [],
        'source' => 'local',
        'message' => 'Local music search is unavailable.',
    ], JSON_UNESCAPED_SLASHES);
}
