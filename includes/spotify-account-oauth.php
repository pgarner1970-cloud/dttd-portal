<?php
/**
 * Multi-account Spotify OAuth helpers.
 *
 * Account connect buttons pass profile_slot=1/2/3. The slot is embedded in
 * the OAuth state and stored in session. The callback then saves the returned
 * OAuth token into the matching spotify_profiles row and redirects back to
 * Settings so the account card updates immediately.
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

function dttd_spotify_table_columns($table) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') {
        return [];
    }
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = db()->query('SHOW COLUMNS FROM `' . $table . '`');
        $cols = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['Field'])) {
                $cols[$row['Field']] = true;
            }
        }
        return $cache[$table] = $cols;
    } catch (Throwable $e) {
        return $cache[$table] = [];
    }
}

function dttd_spotify_profile_has_column($column) {
    $cols = dttd_spotify_table_columns('spotify_profiles');
    return isset($cols[$column]);
}

function dttd_spotify_profile_update($profileId, array $valuesByColumn) {
    $sets = [];
    $values = [];
    foreach ($valuesByColumn as $column => $value) {
        if (dttd_spotify_profile_has_column($column)) {
            $sets[] = '`' . $column . '` = ?';
            $values[] = $value;
        }
    }
    if (!$sets) {
        return;
    }
    $values[] = (int)$profileId;
    $stmt = db()->prepare('UPDATE spotify_profiles SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($values);
}

function dttd_spotify_profile_insert(array $valuesByColumn) {
    $cols = [];
    $marks = [];
    $values = [];
    foreach ($valuesByColumn as $column => $value) {
        if (dttd_spotify_profile_has_column($column)) {
            $cols[] = '`' . $column . '`';
            $marks[] = '?';
            $values[] = $value;
        }
    }
    if (!$cols) {
        throw new RuntimeException('spotify_profiles table does not have the expected columns.');
    }
    $stmt = db()->prepare('INSERT INTO spotify_profiles (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $marks) . ')');
    $stmt->execute($values);
    return (int)db()->lastInsertId();
}

function dttd_spotify_profile_id_for_slot($slot) {
    $slot = dttd_spotify_normalise_profile_slot($slot);
    if ($slot === 0) {
        throw new RuntimeException('Invalid Spotify account slot.');
    }

    // Prefer explicit profile_slot if the column exists. This avoids fragile
    // ordering when rows are edited or created out of sequence.
    if (dttd_spotify_profile_has_column('profile_slot')) {
        $stmt = db()->prepare('SELECT id FROM spotify_profiles WHERE profile_slot = ? LIMIT 1');
        $stmt->execute([$slot]);
        $row = $stmt->fetch();
        if (!empty($row['id'])) {
            return (int)$row['id'];
        }
    }

    // Legacy fallback: first three rows are Account 1/2/3.
    $rows = db()->query('SELECT id FROM spotify_profiles ORDER BY id ASC LIMIT 3')->fetchAll();
    if (!empty($rows[$slot - 1]['id'])) {
        $id = (int)$rows[$slot - 1]['id'];
        if (dttd_spotify_profile_has_column('profile_slot')) {
            dttd_spotify_profile_update($id, ['profile_slot' => $slot]);
        }
        return $id;
    }

    $label = 'Account ' . $slot;
    $role = $slot === 3 ? 'public_search' : 'playback';
    $enabled = $slot === 3 ? 0 : 1;

    return dttd_spotify_profile_insert([
        'profile_slot' => $slot,
        'label' => $label,
        'role' => $role,
        'enabled' => $enabled,
        'use_for_deck_a' => $slot === 1 ? 1 : 0,
        'use_for_deck_b' => $slot === 2 ? 1 : 0,
        'use_for_public_search' => $slot === 3 ? 1 : 0,
    ]);
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

    $expiresAt = date('Y-m-d H:i:s', time() + (int)($token['expires_in'] ?? 3600));
    $updates = [
        'access_token' => $token['access_token'],
        'granted_scopes' => trim((string)($token['scope'] ?? '')),
        'expires_at' => $expiresAt,
        'account_email' => $connectedText,
        'spotify_user_id' => $userId,
        'spotify_display_name' => $display,
        'enabled' => 1,
        'profile_slot' => $slot,
    ];
    if (!empty($token['refresh_token'])) {
        $updates['refresh_token'] = $token['refresh_token'];
    }
    dttd_spotify_profile_update($profileId, $updates);

    // Account 1 remains synced to legacy app_settings until the mixer is fully
    // Duo-aware. This keeps current playback/search features working.
    if ($slot === 1) {
        dttd_spotify_save_user_token($token);
    }

    return [
        'profile_id' => $profileId,
        'connected_text' => $connectedText,
    ];
}

function dttd_spotify_redirect_to_settings($params = '') {
    $url = '/admin/settings.php';
    if ($params !== '') {
        $url .= '?' . ltrim($params, '?');
    }
    return $url . '#spotify-accounts';
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

    // Ensure the row exists before leaving the site. This also helps the
    // Settings page immediately show the same three stable account slots.
    dttd_spotify_profile_id_for_slot($slot);

    $credentials = dttd_spotify_credentials();
    $payload = [
        'nonce' => bin2hex(random_bytes(16)),
        'profile_slot' => $slot,
        'return' => 'settings',
    ];
    $state = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

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
        throw new RuntimeException('Spotify security state check failed. Please retry Connect from Settings.');
    }

    $decoded = dttd_spotify_decode_oauth_state($state);
    $slot = dttd_spotify_normalise_profile_slot($_SESSION['spotify_oauth_profile_slot'] ?? ($decoded['profile_slot'] ?? 0));

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
            'redirect' => dttd_spotify_redirect_to_settings('spotify_account=' . $slot . '&connected=1'),
        ];
    }

    dttd_spotify_save_user_token($token);
    $_SESSION['spotify_flash'] = 'Spotify account connected. You can now test Add to Spotify Queue.';
    return [
        'slot' => 0,
        'redirect' => '/admin/spotify/index.php',
    ];
}
