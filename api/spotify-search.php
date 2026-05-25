<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/spotify.php';

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
    if ($q === '' || strlen($q) < 3) {
        echo json_encode(['ok' => true, 'configured' => true, 'tracks' => [], 'source' => 'none']);
        exit;
    }

    $result = dttd_spotify_public_search_tracks_cached($q, 8);
    $tracks = $result['tracks'] ?? [];
    $meta = $result['meta'] ?? [];

    echo json_encode([
        'ok' => true,
        'configured' => dttd_spotify_public_search_configured(),
        'tracks' => $tracks,
        'source' => !empty($meta['spotify_used']) ? 'spotify' : 'cache',
        'profile' => $meta['profile_label'] ?? null,
        'profile_source' => $meta['profile_source'] ?? null,
        'cache_count' => $meta['cache_count'] ?? 0,
        'rate_limited' => !empty($meta['rate_limited']),
        'fallback' => $meta['fallback'] ?? null,
        'message' => empty($tracks) ? 'No cached or Spotify matches found. Manual entry still works.' : '',
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(200);
    $cached = dttd_spotify_search_track_cache($q, 8);
    echo json_encode([
        'ok' => true,
        'configured' => dttd_spotify_public_search_configured(),
        'tracks' => $cached,
        'source' => 'cache',
        'rate_limited' => true,
        'message' => $cached ? 'Spotify search is cooling down. Showing cached matches.' : 'Spotify search is cooling down. Manual entry still works.',
    ], JSON_UNESCAPED_SLASHES);
}
