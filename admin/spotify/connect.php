<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

if (!dttd_spotify_config_loaded()) {
    $_SESSION['spotify_flash'] = 'Spotify API is not configured in app_settings.';
    header('Location: index.php');
    exit;
}

$profileSlot = isset($_GET['profile_slot']) ? max(1, min(3, (int)$_GET['profile_slot'])) : 0;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$credentials = dttd_spotify_credentials();
$statePayload = [
    'nonce' => bin2hex(random_bytes(16)),
    'profile_slot' => $profileSlot,
];
$state = base64_encode(json_encode($statePayload));
$_SESSION['spotify_oauth_state'] = $state;
$_SESSION['spotify_oauth_profile_slot'] = $profileSlot;

$scope = implode(' ', [
    'user-read-playback-state',
    'user-read-currently-playing',
    'user-modify-playback-state',
    'playlist-read-private',
    'playlist-read-collaborative',
    'user-read-email',
]);

$params = [
    'client_id' => $credentials['client_id'],
    'response_type' => 'code',
    'redirect_uri' => dttd_spotify_redirect_uri(),
    'scope' => $scope,
    'state' => $state,
    'show_dialog' => 'true',
];

header('Location: https://accounts.spotify.com/authorize?' . http_build_query($params));
exit;
