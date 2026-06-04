<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/local-music.php';
require_once __DIR__ . '/../../includes/spotify.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'POST required.']);
        exit;
    }

    $limit = (int)($_POST['limit'] ?? $_GET['limit'] ?? 10);
    $result = dttd_local_music_process_spotify_match_batch($limit);
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Spotify matching failed.']);
}
