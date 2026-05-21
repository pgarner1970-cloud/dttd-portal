<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

try {
    if (!empty($_GET['error'])) {
        throw new RuntimeException('Spotify returned: ' . $_GET['error']);
    }
    $state = $_GET['state'] ?? '';
    if (empty($_SESSION['spotify_oauth_state']) || !hash_equals($_SESSION['spotify_oauth_state'], $state)) {
        throw new RuntimeException('Spotify security state check failed.');
    }
    unset($_SESSION['spotify_oauth_state']);

    $code = $_GET['code'] ?? '';
    if ($code === '') {
        throw new RuntimeException('Spotify did not provide an authorisation code.');
    }

    $token = dttd_spotify_exchange_code($code);
    dttd_spotify_save_user_token($token);
    $_SESSION['spotify_flash'] = 'Spotify account connected. You can now test Add to Spotify Queue.';
} catch (Throwable $e) {
    $_SESSION['spotify_flash'] = 'Spotify connection failed: ' . $e->getMessage();
}

header('Location: index.php');
exit;
