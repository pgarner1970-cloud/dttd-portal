<?php
/**
 * Multi-account Spotify OAuth helpers.
 *
 * Connect buttons pass profile_slot=1/2/3. The slot is embedded in the
 * OAuth state and also stored in the admin session. The callback then stores
 * the returned OAuth token against the matching spotify_profiles row.
 */

function dttd_spotify_oauth_scopes() {
    return implode(' ', [
        'user-read-playback-state',
        'user-read-currently-playing',
        'user-modify-playback-state',
        'playlist-read-private',
        'playlist-read-collaborative',
        'user-read-email',
    ]);
}

function dttd_spotify_normalise_profile_slot($slot) {
    $slot = (int)$slot;
    return ($slot >= 1 && $slot <= 3) ? $slot : 0;
}

function dttd_spotify_profile_id_for_slot($slot) {
    $slot = dttd_spotify_normalise_profile_slot($slot);
    if ($slot === 0) {
        throw new RuntimeException('Invalid Spotify account slot.');
    }

    $profiles = db()->query("SELECT id FROM spotify_profiles ORDER BY id ASC LIMIT 3")->fetchAll();
    if (!empty($profiles[$slot - 1]['id'])) {
        return (int)$profiles[$slot - 1]['id'];
    }

    $label = 'Account ' . $slot;
    $role = $slot === 3 ? 'public_search' : 'playback';
    $enabled = $slot === 3 ? 0 : 1;
    $deckA = $slot === 1 ? 1 : 0;
    $deckB = $slot === 2 ? 1 : 0;
    $public = $slot === 3 ? 1 : 0;

    $stmt = db()->prepare("INSERT INTO spotify_profiles (label, role, enabled, use_for_deck_a, use_for_deck_b, use_for_public_search) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$label, $role, $enabled, $deckA, $deckB, $public]);
    return (int)db()->lastInsertId();
}

function dttd_spotify_fetch_me_from_token($accessToken) {
    try {
        return dttd_spotify_http_get('https://api.spotify.com/v1/me', [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_spotify_save_token_to_profile_slot($slot, array $token) {
    $slot = dttd_spotify_normalise_profile_slot($slot);
    if ($slot === 0) {
        throw new RuntimeException('Invalid Spotify account slot.');
    }
    if (empty($token['access_token'])) {
        throw new RuntimeException('Spotify did not return an access token.');
    }

    $profileId = dttd_spotify_profile_id_for_slot($slot);
    $me = dttd_spotify_fetch_me_from_token($token['access_token']);

    $email = trim((string)($me['email'] ?? ''));
    $display = trim((string)($me['display_name'] ?? ''));
    $userId = trim((string)($me['id'] ?? ''));
    $connectedText = $email !== '' ? $email : ($display !== '' ? $display : ($userId !== '' ? $userId : 'Connected'));

    $scopes = trim((string)($token['scope'] ?? ''));
    $expiresAt = date('Y-m-d H:i:s', time() + (int)($token['expires_in'] ?? 3600));

    $fields = [
        'access_token = ?',
        'granted_scopes = ?',
        'expires_at = ?',
        'account_email = ?',
        'enabled = 1',
    ];
    $values = [
        $token['access_token'],
        $scopes,
        $expiresAt,
        $connectedText,
    ];

    if (!empty($token['refresh_token'])) {
        $fields[] = 'refresh_token = ?';
        $values[] = $token['refresh_token'];
    }

    $values[] = $profileId;
    $stmt = db()->prepare('UPDATE spotify_profiles SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($values);

    // Keep legacy single-account token storage in sync with Account 1 so the
    // existing mixer/playback code keeps working until the Duo-aware patch lands.
    if ($slot === 1) {
        dttd_spotify_save_user_token($token);
    }

    return [
        'profile_id' => $profileId,
        'connected_text' => $connectedText,
    ];
}

function dttd_spotify_start_account_oauth($slot) {
    $slot = dttd_spotify_normalise_profile_slot($slot);
    if ($slot === 0) {
        throw new RuntimeException('Invalid Spotify account slot.');
    }
    if (!dttd_spotify_config_loaded()) {
        throw new RuntimeException('Spotify API is not configured in Settings.');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $credentials = dttd_spotify_credentials();
    $statePayload = [
        'nonce' => bin2hex(random_bytes(16)),
        'profile_slot' => $slot,
        'return' => 'settings',
    ];
    $state = rtrim(strtr(base64_encode(json_encode($statePayload)), '+/', '-_'), '=');

    $_SESSION['spotify_oauth_state'] = $state;
    $_SESSION['spotify_oauth_profile_slot'] = $slot;

    $params = [
        'client_id' => $credentials['client_id'],
        'response_type' => 'code',
        'redirect_uri' => dttd_spotify_redirect_uri(),
        'scope' => dttd_spotify_oauth_scopes(),
        'state' => $state,
        'show_dialog' => 'true',
    ];

    return 'https://accounts.spotify.com/authorize?' . http_build_query($params);
}

function dttd_spotify_decode_oauth_state($state) {
    $state = (string)$state;
    $padded = strtr($state, '-_', '+/');
    $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
    $decoded = json_decode((string)base64_decode($padded), true);
    return is_array($decoded) ? $decoded : [];
}

function dttd_spotify_finish_account_oauth() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!empty($_GET['error'])) {
        throw new RuntimeException('Spotify returned: ' . $_GET['error']);
    }

    $state = (string)($_GET['state'] ?? '');
    if (empty($_SESSION['spotify_oauth_state']) || !hash_equals((string)$_SESSION['spotify_oauth_state'], $state)) {
        throw new RuntimeException('Spotify security state check failed. Please try Connect again from Settings.');
    }

    $slot = dttd_spotify_normalise_profile_slot($_SESSION['spotify_oauth_profile_slot'] ?? 0);
    $decoded = dttd_spotify_decode_oauth_state($state);
    if ($slot === 0 && isset($decoded['profile_slot'])) {
        $slot = dttd_spotify_normalise_profile_slot($decoded['profile_slot']);
    }

    unset($_SESSION['spotify_oauth_state'], $_SESSION['spotify_oauth_profile_slot']);

    $code = (string)($_GET['code'] ?? '');
    if ($code === '') {
        throw new RuntimeException('Spotify did not provide an authorisation code.');
    }

    $token = dttd_spotify_exchange_code($code);

    if ($slot >= 1 && $slot <= 3) {
        $saved = dttd_spotify_save_token_to_profile_slot($slot, $token);
        $_SESSION['settings_flash'] = 'Spotify Account ' . $slot . ' connected: ' . $saved['connected_text'] . '.';
        $_SESSION['spotify_flash'] = $_SESSION['settings_flash'];
        return [
            'slot' => $slot,
            'redirect' => '/settings.php?spotify_account=' . $slot . '&connected=1#spotify-accounts',
        ];
    }

    // Legacy fallback for old connect links without a profile slot.
    dttd_spotify_save_user_token($token);
    $_SESSION['spotify_flash'] = 'Spotify account connected. You can now test Add to Spotify Queue.';
    return [
        'slot' => 0,
        'redirect' => '/spotify/index.php',
    ];
}
