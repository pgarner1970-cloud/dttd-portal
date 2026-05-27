<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';
require_once __DIR__ . '/../../includes/spotify-account-oauth.php';

try {
    $result = dttd_spotify_finish_account_oauth();
    header('Location: ' . ($result['redirect'] ?? '/settings.php#spotify-accounts'));
    exit;
} catch (Throwable $e) {
    $_SESSION['settings_flash'] = 'Spotify connection failed: ' . $e->getMessage();
    $_SESSION['spotify_flash'] = $_SESSION['settings_flash'];
    header('Location: /settings.php#spotify-accounts');
    exit;
}
