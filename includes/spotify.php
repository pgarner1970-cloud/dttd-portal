<?php
/**
 * Spotify Web API helper for Dance Thru The Decades.
 *
 * Credentials are read from the existing app_settings table:
 * - spotify_enabled = 1
 * - spotify_client_id
 * - spotify_client_secret
 */

function dttd_spotify_setting($key, $default = '') {
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $cache[$key] = $row ? trim((string)$row['setting_value']) : $default;
    } catch (Throwable $e) {
        $cache[$key] = $default;
    }

    return $cache[$key];
}

function dttd_spotify_enabled() {
    $enabled = strtolower((string)dttd_spotify_setting('spotify_enabled', '0'));
    return in_array($enabled, ['1', 'true', 'yes', 'on'], true);
}

function dttd_spotify_credentials() {
    return [
        'client_id' => dttd_spotify_setting('spotify_client_id', ''),
        'client_secret' => dttd_spotify_setting('spotify_client_secret', ''),
    ];
}

function dttd_spotify_config_loaded() {
    $credentials = dttd_spotify_credentials();

    return dttd_spotify_enabled()
        && $credentials['client_id'] !== ''
        && $credentials['client_secret'] !== '';
}

function dttd_spotify_http_post($url, array $headers, $body) {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not available.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Spotify token request failed' . ($error ? ': ' . $error : '.'));
    }

    return json_decode($response, true) ?: [];
}

function dttd_spotify_http_get($url, array $headers) {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not available.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        $detail = 'Spotify API request failed';
        if ($status > 0) { $detail .= ' (HTTP ' . $status . ')'; }
        if ($error) { $detail .= ': ' . $error; }
        $body = trim((string)$response);
        if ($body !== '') { $detail .= ' — ' . mb_substr($body, 0, 300); }
        throw new RuntimeException($detail);
    }

    return json_decode($response, true) ?: [];
}

function dttd_spotify_access_token() {
    if (!dttd_spotify_config_loaded()) {
        throw new RuntimeException('Spotify API is not configured.');
    }

    $credentials = dttd_spotify_credentials();
    $cache_file = sys_get_temp_dir() . '/dttd_spotify_client_token_' . md5($credentials['client_id']) . '.json';

    if (is_file($cache_file)) {
        $cached = json_decode((string)file_get_contents($cache_file), true);
        if (!empty($cached['access_token']) && !empty($cached['expires_at']) && $cached['expires_at'] > time() + 60) {
            return $cached['access_token'];
        }
    }

    $auth = base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']);
    $data = dttd_spotify_http_post(
        'https://accounts.spotify.com/api/token',
        [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        'grant_type=client_credentials'
    );

    if (empty($data['access_token'])) {
        throw new RuntimeException('Spotify did not return an access token.');
    }

    $cache = [
        'access_token' => $data['access_token'],
        'expires_at' => time() + (int)($data['expires_in'] ?? 3600),
    ];
    @file_put_contents($cache_file, json_encode($cache));

    return $data['access_token'];
}

function dttd_spotify_search_tracks($query, $limit = 8) {
    $query = trim((string)$query);
    if ($query === '' || strlen($query) < 2) {
        return [];
    }

    $limit = max(1, min(10, (int)$limit));
    $token = dttd_spotify_access_token();
    $url = 'https://api.spotify.com/v1/search?type=track&limit=' . $limit . '&q=' . rawurlencode($query);

    $data = dttd_spotify_http_get($url, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);

    $items = $data['tracks']['items'] ?? [];
    $tracks = [];

    foreach ($items as $item) {
        $artists = [];
        foreach (($item['artists'] ?? []) as $artist) {
            if (!empty($artist['name'])) {
                $artists[] = $artist['name'];
            }
        }

        $images = $item['album']['images'] ?? [];
        $image = '';
        if (!empty($images)) {
            $last = end($images);
            $image = $last['url'] ?? ($images[0]['url'] ?? '');
        }

        $tracks[] = [
            'id' => $item['id'] ?? '',
            'title' => $item['name'] ?? '',
            'artist' => implode(', ', $artists),
            'album' => $item['album']['name'] ?? '',
            'image' => $image,
            'url' => $item['external_urls']['spotify'] ?? '',
            'duration_ms' => $item['duration_ms'] ?? null,
        ];
    }

    return $tracks;
}

function dttd_spotify_update_setting($key, $value) {
    $stmt = db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, (string)$value]);
}

function dttd_spotify_redirect_uri() {
    return 'https://dj.dancethruthedecades.co.uk/spotify/callback.php';
}


function dttd_spotify_clear_user_tokens() {
    dttd_spotify_update_setting('spotify_access_token', '');
    dttd_spotify_update_setting('spotify_refresh_token', '');
    dttd_spotify_update_setting('spotify_token_expires_at', '0');
    dttd_spotify_update_setting('spotify_granted_scope', '');
}

function dttd_spotify_authorize_url() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $credentials = dttd_spotify_credentials();
    $state = bin2hex(random_bytes(16));
    $_SESSION['spotify_oauth_state'] = $state;
    $params = [
        'client_id' => $credentials['client_id'],
        'response_type' => 'code',
        'redirect_uri' => dttd_spotify_redirect_uri(),
        'scope' => 'user-read-playback-state user-read-currently-playing user-modify-playback-state playlist-read-private playlist-read-collaborative user-read-email',
        'state' => $state,
        'show_dialog' => 'true',
    ];
    return 'https://accounts.spotify.com/authorize?' . http_build_query($params);
}

function dttd_spotify_exchange_code($code) {
    $credentials = dttd_spotify_credentials();
    $auth = base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']);
    return dttd_spotify_http_post(
        'https://accounts.spotify.com/api/token',
        [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => dttd_spotify_redirect_uri(),
        ])
    );
}

function dttd_spotify_save_user_token(array $data) {
    if (empty($data['access_token'])) {
        throw new RuntimeException('Spotify did not return an access token.');
    }
    dttd_spotify_update_setting('spotify_access_token', $data['access_token']);
    if (!empty($data['refresh_token'])) {
        dttd_spotify_update_setting('spotify_refresh_token', $data['refresh_token']);
    }
    dttd_spotify_update_setting('spotify_token_expires_at', (string)(time() + (int)($data['expires_in'] ?? 3600)));
    if (isset($data['scope'])) {
        dttd_spotify_update_setting('spotify_granted_scope', (string)$data['scope']);
    }
    dttd_spotify_update_setting('spotify_queue_enabled', '1');
}

function dttd_spotify_refresh_user_token() {
    $refresh = dttd_spotify_setting('spotify_refresh_token', '');
    if ($refresh === '') {
        throw new RuntimeException('Spotify account is not connected.');
    }
    $credentials = dttd_spotify_credentials();
    $auth = base64_encode($credentials['client_id'] . ':' . $credentials['client_secret']);
    $data = dttd_spotify_http_post(
        'https://accounts.spotify.com/api/token',
        [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
        ])
    );
    dttd_spotify_save_user_token($data);
    return $data['access_token'] ?? '';
}

function dttd_spotify_user_access_token() {
    if (!dttd_spotify_config_loaded()) {
        throw new RuntimeException('Spotify API is not configured.');
    }
    $token = dttd_spotify_setting('spotify_access_token', '');
    $expires = (int)dttd_spotify_setting('spotify_token_expires_at', '0');
    if ($token !== '' && $expires > time() + 60) {
        return $token;
    }
    return dttd_spotify_refresh_user_token();
}

function dttd_spotify_queue_connected() {
    return dttd_spotify_config_loaded() && dttd_spotify_setting('spotify_refresh_token', '') !== '';
}

function dttd_spotify_queue_controls_enabled() {
    $enabled = strtolower((string)dttd_spotify_setting('spotify_queue_enabled', '0'));
    return in_array($enabled, ['1', 'true', 'yes', 'on'], true);
}

function dttd_spotify_queue_available() {
    return dttd_spotify_queue_controls_enabled() && dttd_spotify_queue_connected();
}

function dttd_spotify_http_put($url, array $headers, $body = '') {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not available.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Spotify playback request failed' . ($error ? ': ' . $error : '.'));
    }
    return $response !== '' ? (json_decode($response, true) ?: []) : [];
}

function dttd_spotify_add_to_queue($track_id, $device_id = '') {
    $track_id = trim((string)$track_id);
    if ($track_id === '') {
        throw new RuntimeException('No Spotify track ID was supplied.');
    }
    $token = dttd_spotify_user_access_token();
    $uri = strpos($track_id, 'spotify:track:') === 0 ? $track_id : 'spotify:track:' . $track_id;
    $url = 'https://api.spotify.com/v1/me/player/queue?uri=' . rawurlencode($uri);
    if (trim((string)$device_id) !== '') {
        $url .= '&device_id=' . rawurlencode(trim((string)$device_id));
    }
    return dttd_spotify_http_post($url, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/x-www-form-urlencoded',
    ], '');
}

function dttd_spotify_get_devices() {
    $token = dttd_spotify_user_access_token();
    $data = dttd_spotify_http_get('https://api.spotify.com/v1/me/player/devices', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
    return $data['devices'] ?? [];
}

function dttd_spotify_current_playback() {
    $token = dttd_spotify_user_access_token();
    return dttd_spotify_http_get('https://api.spotify.com/v1/me/player', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
}


function dttd_spotify_requested_scope() {
    return 'user-read-playback-state user-read-currently-playing user-modify-playback-state playlist-read-private playlist-read-collaborative user-read-email';
}

function dttd_spotify_http_get_debug($url, array $headers) {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'PHP cURL is not available.', 'body' => '', 'json' => null];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADER => true,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);
    $raw = $response === false ? '' : (string)$response;
    $responseHeaders = $raw !== '' && $headerSize > 0 ? substr($raw, 0, $headerSize) : '';
    $body = $raw !== '' && $headerSize > 0 ? substr($raw, $headerSize) : $raw;
    $json = $body !== '' ? json_decode($body, true) : null;
    return [
        'ok' => ($response !== false && $status >= 200 && $status < 300),
        'status' => $status,
        'error' => $error,
        'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
        'headers' => $responseHeaders,
        'body' => $body,
        'json' => is_array($json) ? $json : null,
    ];
}

function dttd_spotify_user_get_debug($url) {
    $token = dttd_spotify_user_access_token();
    return dttd_spotify_http_get_debug($url, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
}


function dttd_spotify_client_get_debug($url) {
    try {
        $token = dttd_spotify_access_token();
    } catch (Throwable $e) {
        return ['ok' => false, 'status' => 0, 'error' => 'Client credentials token failed: ' . $e->getMessage(), 'body' => '', 'json' => null, 'token_source' => 'client_credentials'];
    }
    $debug = dttd_spotify_http_get_debug($url, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
    $debug['token_source'] = 'client_credentials';
    return $debug;
}

function dttd_spotify_token_diagnostics() {
    $access = dttd_spotify_setting('spotify_access_token', '');
    $refresh = dttd_spotify_setting('spotify_refresh_token', '');
    $expires = (int)dttd_spotify_setting('spotify_token_expires_at', '0');
    $granted = dttd_spotify_setting('spotify_granted_scope', '');
    $requested = dttd_spotify_requested_scope();
    $missing = [];
    foreach (preg_split('/\s+/', trim($requested)) as $scope) {
        if ($scope !== '' && !preg_match('/(^|\s)' . preg_quote($scope, '/') . '(\s|$)/', $granted)) {
            $missing[] = $scope;
        }
    }
    return [
        'user_access_token_present' => $access !== '',
        'user_access_token_prefix' => $access !== '' ? substr($access, 0, 8) . '…' : '',
        'user_access_token_length' => strlen($access),
        'refresh_token_present' => $refresh !== '',
        'expires_at' => $expires,
        'expires_in_seconds' => $expires > 0 ? ($expires - time()) : null,
        'missing_requested_scopes' => $missing,
    ];
}

function dttd_spotify_playlist_diagnostics() {
    $diag = [
        'requested_scope' => dttd_spotify_requested_scope(),
        'granted_scope' => dttd_spotify_setting('spotify_granted_scope', ''),
        'connected' => dttd_spotify_queue_connected(),
        'me' => null,
        'playlists' => null,
        'playlist_tracks' => null,
        'playlist_tracks_direct' => null,
        'playlist_tracks_no_market' => null,
        'playlist_object_tracks' => null,
        'first_playlist' => null,
        'token' => dttd_spotify_token_diagnostics(),
        'client_playlist_object' => null,
        'client_playlist_tracks' => null,
    ];
    if (!$diag['connected']) {
        return $diag;
    }
    $diag['me'] = dttd_spotify_user_get_debug('https://api.spotify.com/v1/me');
    $diag['playlists'] = dttd_spotify_user_get_debug('https://api.spotify.com/v1/me/playlists?limit=5');
    $items = $diag['playlists']['json']['items'] ?? [];
    $chosen = null;
    foreach ($items as $candidate) {
        if (!empty($candidate['id']) && (int)($candidate['tracks']['total'] ?? 0) > 0) { $chosen = $candidate; break; }
    }
    if ($chosen === null && !empty($items[0]['id'])) { $chosen = $items[0]; }
    if (!empty($chosen['id'])) {
        $id = (string)$chosen['id'];
        $tracksHref = (string)($chosen['tracks']['href'] ?? '');
        $diag['first_playlist'] = [
            'id' => $id,
            'name' => (string)($chosen['name'] ?? 'Unnamed playlist'),
            'owner' => (string)($chosen['owner']['display_name'] ?? ($chosen['owner']['id'] ?? '')),
            'reported_total' => (int)($chosen['tracks']['total'] ?? 0),
            'tracks_href' => $tracksHref,
        ];
        if ($tracksHref !== '') {
            $diag['playlist_tracks'] = dttd_spotify_user_get_debug($tracksHref . (strpos($tracksHref, '?') === false ? '?' : '&') . 'limit=5&market=from_token');
        } else {
            $diag['playlist_tracks'] = dttd_spotify_user_get_debug('https://api.spotify.com/v1/playlists/' . rawurlencode($id) . '/tracks?limit=5&market=from_token');
        }
        $diag['playlist_tracks_direct'] = dttd_spotify_user_get_debug('https://api.spotify.com/v1/playlists/' . rawurlencode($id) . '/tracks?limit=5&market=from_token');
        $diag['playlist_tracks_no_market'] = dttd_spotify_user_get_debug('https://api.spotify.com/v1/playlists/' . rawurlencode($id) . '/tracks?limit=5');
        $diag['playlist_object_tracks'] = dttd_spotify_user_get_debug('https://api.spotify.com/v1/playlists/' . rawurlencode($id) . '?market=from_token&fields=id,name,owner(id,display_name),tracks(total,href,items(track(id,name,type,is_local,artists(name),album(name,images),external_urls,duration_ms)),next)');
        // Compare the same playlist using an app/client-credentials token. This helps prove whether
        // the failing request is genuinely using the DJ user's OAuth token or accidentally falling
        // back to an app-only token.
        $diag['client_playlist_object'] = dttd_spotify_client_get_debug('https://api.spotify.com/v1/playlists/' . rawurlencode($id) . '?fields=id,name,owner(id,display_name),tracks(total,href)');
        $diag['client_playlist_tracks'] = dttd_spotify_client_get_debug('https://api.spotify.com/v1/playlists/' . rawurlencode($id) . '/tracks?limit=5');
    }
    return $diag;
}

function dttd_spotify_debug_error_text($debug) {
    if (!is_array($debug)) return 'Not checked.';
    if (!empty($debug['ok'])) return 'OK';
    $json = $debug['json'] ?? null;
    if (is_array($json) && isset($json['error'])) {
        if (is_array($json['error'])) {
            return trim((string)($json['error']['status'] ?? '') . ' ' . (string)($json['error']['message'] ?? ''));
        }
        return (string)$json['error'];
    }
    $body = trim((string)($debug['body'] ?? ''));
    if ($body !== '') return mb_substr($body, 0, 240);
    return (string)($debug['error'] ?? 'Unknown Spotify API error.');
}
