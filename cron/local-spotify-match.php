<?php
// DTTD local music Spotify matching cron job.
// Recommended 20i command:
// /usr/bin/php83 /home/sites/13b/5/53bf9eb76a/dttd-portal/cron/local-spotify-match.php --limit=20

$root = dirname(__DIR__);
require_once $root . '/includes/db.php';
require_once $root . '/includes/local-music.php';
require_once $root . '/includes/spotify.php';

function dttd_cron_arg($name, $default = null) {
    global $argv;
    foreach (($argv ?? []) as $arg) {
        if (strpos($arg, '--' . $name . '=') === 0) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return $default;
}

if (PHP_SAPI !== 'cli') {
    $key = (string)($_GET['key'] ?? '');
    $expected = dttd_local_music_setting('local_music_cron_key', '');
    if ($expected === '' || !hash_equals($expected, $key)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Forbidden.']);
        exit;
    }
    $limit = (int)($_GET['limit'] ?? 10);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(dttd_local_music_process_spotify_match_batch($limit));
    exit;
}

$limit = (int)dttd_cron_arg('limit', 20);
$result = dttd_local_music_process_spotify_match_batch($limit);
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
