<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

$track_id = trim($_POST['spotify_track_id'] ?? $_GET['spotify_track_id'] ?? '');
$return = $_POST['return'] ?? $_GET['return'] ?? '../requests.php';

if ($return === '' || strpos($return, 'http') === 0 || strpos($return, '//') !== false) {
    $return = '../requests.php';
}

try {
    if (function_exists('dttd_spotify_queue_controls_enabled') && !dttd_spotify_queue_controls_enabled()) {
        throw new RuntimeException('Spotify queue controls are disabled in DJ Settings.');
    }

    if (!dttd_spotify_queue_connected()) {
        throw new RuntimeException('Spotify account is not connected yet. Use Spotify Tools first.');
    }
    dttd_spotify_add_to_queue($track_id);
    $_SESSION['spotify_flash'] = 'Track added to Spotify queue.';
} catch (Throwable $e) {
    $_SESSION['spotify_flash'] = 'Could not add to Spotify queue: ' . $e->getMessage();
}

header('Location: ' . $return);
exit;
