<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/spotify.php';
require_once __DIR__ . '/../includes/spotify-cache.php';

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

try {
    if ($q === '' || mb_strlen($q) < 3) {
        echo json_encode([
            'ok' => true,
            'configured' => function_exists('dttd_spotify_config_loaded') ? dttd_spotify_config_loaded() : false,
            'tracks' => [],
            'source' => 'none',
            'message' => '',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $result = dttd_spotify_cached_search_tracks($q, 8, [
        'min_length' => 3,
        'cache_enough' => 5,
    ]);
    $tracks = $result['tracks'] ?? [];
    $meta = $result['meta'] ?? [];

    echo json_encode([
        'ok' => true,
        'configured' => function_exists('dttd_spotify_config_loaded') ? dttd_spotify_config_loaded() : true,
        'tracks' => $tracks,
        'source' => $meta['source'] ?? 'unknown',
        'cache_count' => $meta['cache_count'] ?? 0,
        'spotify_used' => !empty($meta['spotify_used']),
        'rate_limited' => !empty($meta['rate_limited']),
        'message' => $meta['message'] ?? '',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(200);
    $cached = dttd_track_cache_search($q, 8);
    echo json_encode([
        'ok' => true,
        'configured' => function_exists('dttd_spotify_config_loaded') ? dttd_spotify_config_loaded() : true,
        'tracks' => $cached,
        'source' => 'cache',
        'cache_count' => count($cached),
        'spotify_used' => false,
        'rate_limited' => true,
        'message' => $cached ? 'Spotify search is cooling down. Showing cached matches.' : 'Spotify search is unavailable. Manual entry still works.',
    ], JSON_UNESCAPED_SLASHES);
}
