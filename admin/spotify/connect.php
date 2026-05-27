<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';
require_once __DIR__ . '/../../includes/spotify-account-oauth.php';

try {
    $slot = isset($_GET['profile_slot']) ? (int)$_GET['profile_slot'] : 0;
    if ($slot >= 1 && $slot <= 3) {
        header('Location: ' . dttd_spotify_start_account_oauth($slot));
        exit;
    }
    header('Location: ' . dttd_spotify_authorize_url());
    exit;
} catch (Throwable $e) {
    $_SESSION['settings_flash'] = 'Spotify connection could not start: ' . $e->getMessage();
    $_SESSION['spotify_flash'] = $_SESSION['settings_flash'];
    header('Location: /settings.php#spotify-accounts');
    exit;
}
