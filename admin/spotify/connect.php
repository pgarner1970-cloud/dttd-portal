<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

if (!dttd_spotify_config_loaded()) {
    $_SESSION['spotify_flash'] = 'Spotify API is not configured in app_settings.';
    header('Location: index.php');
    exit;
}

header('Location: ' . dttd_spotify_authorize_url());
exit;
