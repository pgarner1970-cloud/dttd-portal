<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

if (!dttd_spotify_config_loaded()) {
    $_SESSION['spotify_flash'] = 'Spotify API is not configured in app_settings.';
    header('Location: index.php');
    exit;
}

// Force a clean OAuth grant. This avoids Spotify reusing an old refresh token
// that was created before playlist-read scopes were added.
dttd_spotify_clear_user_tokens();
header('Location: ' . dttd_spotify_authorize_url());
exit;
