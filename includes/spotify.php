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
        throw new RuntimeException('Spotify search failed' . ($error ? ': ' . $error : '.'));
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
        if (!empty($item['id'])) {
            $tracks[] = dttd_spotify_normalise_track_item($item);
        }
    }

    return function_exists('dttd_spotify_enrich_and_sort_tracks') ? dttd_spotify_enrich_and_sort_tracks($tracks, $query) : $tracks;
}

function dttd_spotify_update_setting($key, $value) {
    $stmt = db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, (string)$value]);
}

function dttd_spotify_redirect_uri() {
    return 'https://dj.dancethruthedecades.co.uk/spotify/callback.php';
}

function dttd_spotify_authorize_scopes() {
    return implode(' ', [
        'user-read-playback-state',
        'user-read-currently-playing',
        'user-modify-playback-state',
        // Required for the playlist diagnostic/import workflow.
        // Existing refresh tokens do not automatically gain new scopes, so
        // Spotify accounts must be reconnected after this change is uploaded.
        'playlist-read-private',
        'playlist-read-collaborative',
    ]);
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
        'scope' => dttd_spotify_authorize_scopes(),
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


/**
 * Multi-account playback helpers for Duo-aware mixer decks.
 *
 * Account roles are stored in spotify_profiles:
 * - use_for_deck_a
 * - use_for_deck_b
 * - use_for_public_search
 *
 * If no role is assigned yet, these helpers fall back to the legacy primary
 * app_settings token so standard/single-account operation keeps working.
 */
function dttd_spotify_profile_by_deck($deck) {
    $deck = strtolower((string)$deck) === 'b' ? 'b' : 'a';
    $col = $deck === 'b' ? 'use_for_deck_b' : 'use_for_deck_a';
    try {
        $cols = [];
        $stmtCols = db()->query('SHOW COLUMNS FROM spotify_profiles');
        foreach ($stmtCols->fetchAll() as $row) {
            if (!empty($row['Field'])) $cols[$row['Field']] = true;
        }
        if (empty($cols[$col])) return null;
        $order = !empty($cols['profile_slot']) ? 'profile_slot ASC, id ASC' : 'id ASC';
        $stmt = db()->prepare("SELECT * FROM spotify_profiles WHERE enabled = 1 AND `$col` = 1 ORDER BY $order LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function dttd_spotify_profile_id_for_deck($deck) {
    $profile = dttd_spotify_profile_by_deck($deck);
    return $profile && !empty($profile['id']) ? (int)$profile['id'] : 0;
}

function dttd_spotify_decks_share_profile() {
    $a = dttd_spotify_profile_id_for_deck('a');
    $b = dttd_spotify_profile_id_for_deck('b');
    if ($a <= 0 || $b <= 0) return true; // legacy fallback = same account
    return $a === $b;
}

function dttd_spotify_refresh_profile_token(array $profile) {
    $refresh = trim((string)($profile['refresh_token'] ?? ''));
    if ($refresh === '') {
        throw new RuntimeException('Spotify account is not connected for this deck.');
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
    if (empty($data['access_token'])) {
        throw new RuntimeException('Spotify did not return a refreshed access token.');
    }
    $expiresAt = date('Y-m-d H:i:s', time() + (int)($data['expires_in'] ?? 3600));
    $newRefresh = !empty($data['refresh_token']) ? $data['refresh_token'] : $refresh;
    try {
        $stmt = db()->prepare("UPDATE spotify_profiles SET access_token = ?, refresh_token = ?, granted_scopes = COALESCE(NULLIF(?, ''), granted_scopes), expires_at = ? WHERE id = ?");
        $stmt->execute([
            $data['access_token'],
            $newRefresh,
            (string)($data['scope'] ?? ''),
            $expiresAt,
            (int)$profile['id'],
        ]);
    } catch (Throwable $e) {}
    return $data['access_token'];
}

function dttd_spotify_profile_access_token(array $profile) {
    $token = trim((string)($profile['access_token'] ?? ''));
    $expires = !empty($profile['expires_at']) ? strtotime((string)$profile['expires_at']) : 0;
    if ($token !== '' && $expires > time() + 60) return $token;
    return dttd_spotify_refresh_profile_token($profile);
}

function dttd_spotify_user_access_token_for_deck($deck) {
    $profile = dttd_spotify_profile_by_deck($deck);
    if ($profile) return dttd_spotify_profile_access_token($profile);
    return dttd_spotify_user_access_token();
}

function dttd_spotify_queue_connected_for_deck($deck) {
    if (!dttd_spotify_config_loaded()) return false;
    $profile = dttd_spotify_profile_by_deck($deck);
    if ($profile) return trim((string)($profile['refresh_token'] ?? '')) !== '';
    return dttd_spotify_queue_connected();
}

function dttd_spotify_get_devices_for_deck($deck) {
    $token = dttd_spotify_user_access_token_for_deck($deck);
    $data = dttd_spotify_http_get('https://api.spotify.com/v1/me/player/devices', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
    return $data['devices'] ?? [];
}

function dttd_spotify_current_playback_for_deck($deck) {
    $token = dttd_spotify_user_access_token_for_deck($deck);
    return dttd_spotify_http_get('https://api.spotify.com/v1/me/player', [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
}

/**
 * Secondary/public Spotify profile + track cache helpers.
 *
 * The DJ console continues to use the existing app_settings Spotify credentials.
 * Public request search can use a separate spotify_profiles row with role=public_search.
 */
function dttd_spotify_profile_by_role($role) {
    try {
        $role = (string)$role;
        if ($role === 'public_search') {
            $cols = [];
            $stmtCols = db()->query('SHOW COLUMNS FROM spotify_profiles');
            foreach ($stmtCols->fetchAll() as $row) if (!empty($row['Field'])) $cols[$row['Field']] = true;
            if (!empty($cols['use_for_public_search'])) {
                $order = !empty($cols['profile_slot']) ? 'profile_slot ASC, id ASC' : 'id ASC';
                $stmt = db()->query("SELECT * FROM spotify_profiles WHERE enabled = 1 AND use_for_public_search = 1 ORDER BY $order LIMIT 1");
                $row = $stmt->fetch();
                if ($row) return $row;
            }
        }
        $stmt = db()->prepare("SELECT * FROM spotify_profiles WHERE role = ? AND enabled = 1 ORDER BY id ASC LIMIT 1");
        $stmt->execute([$role]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function dttd_spotify_save_profile_credentials($role, $label, $client_id, $client_secret = null, $enabled = true) {
    $role = (string)$role;
    $label = trim((string)$label) ?: ucfirst(str_replace('_', ' ', $role));
    $client_id = trim((string)$client_id);
    $enabled_int = $enabled ? 1 : 0;

    try {
        $existing = dttd_spotify_profile_by_role($role);
        if ($existing) {
            if ($client_secret !== null && trim((string)$client_secret) !== '') {
                $stmt = db()->prepare("UPDATE spotify_profiles SET label=?, client_id=?, client_secret=?, enabled=? WHERE id=?");
                return $stmt->execute([$label, $client_id, (string)$client_secret, $enabled_int, (int)$existing['id']]);
            }
            $stmt = db()->prepare("UPDATE spotify_profiles SET label=?, client_id=?, enabled=? WHERE id=?");
            return $stmt->execute([$label, $client_id, $enabled_int, (int)$existing['id']]);
        }

        $stmt = db()->prepare("INSERT INTO spotify_profiles (label, role, client_id, client_secret, enabled) VALUES (?, ?, ?, ?, ?)");
        return $stmt->execute([$label, $role, $client_id, (string)($client_secret ?? ''), $enabled_int]);
    } catch (Throwable $e) {
        return false;
    }
}

function dttd_spotify_public_search_credentials() {
    $profile = dttd_spotify_profile_by_role('public_search');
    if ($profile && !empty($profile['client_id']) && !empty($profile['client_secret'])) {
        return [
            'source' => 'secondary_public_search',
            'label' => $profile['label'] ?: 'Secondary public search',
            'client_id' => trim((string)$profile['client_id']),
            'client_secret' => trim((string)$profile['client_secret']),
        ];
    }

    $primary = dttd_spotify_credentials();
    return [
        'source' => 'primary_fallback',
        'label' => 'Primary DJ Spotify fallback',
        'client_id' => trim((string)$primary['client_id']),
        'client_secret' => trim((string)$primary['client_secret']),
    ];
}

function dttd_spotify_public_search_configured() {
    $creds = dttd_spotify_public_search_credentials();
    return $creds['client_id'] !== '' && $creds['client_secret'] !== '';
}

function dttd_spotify_client_credentials_token_for(array $credentials) {
    if (empty($credentials['client_id']) || empty($credentials['client_secret'])) {
        throw new RuntimeException('Spotify public-search profile is not configured.');
    }

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
        throw new RuntimeException('Spotify did not return a public-search access token.');
    }

    @file_put_contents($cache_file, json_encode([
        'access_token' => $data['access_token'],
        'expires_at' => time() + (int)($data['expires_in'] ?? 3600),
    ]));

    return $data['access_token'];
}


function dttd_spotify_clean_token_text($text) {
    $text = mb_strtolower((string)$text);
    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function dttd_spotify_track_version_tags(array $track) {
    $haystack = dttd_spotify_clean_token_text(($track['title'] ?? '') . ' ' . ($track['album'] ?? ''));
    $tags = [];
    $checks = [
        'Remaster' => ['remaster', 'remastered', 'anniversary edition', 'deluxe edition'],
        'Live' => ['live at', 'live in', 'live from', 'live version', 'concert', 'mtv unplugged'],
        'Remix' => ['remix', 'club mix', 'extended mix', 'radio mix', 'dance mix', 'dub mix', 'vip mix', 'sped up', 'slowed', 'nightcore'],
        'Acoustic' => ['acoustic', 'unplugged', 'stripped'],
        'Karaoke' => ['karaoke', 'backing track', 'sing along'],
        'Instrumental' => ['instrumental'],
        'Cover' => ['cover', 'tribute', 're recorded', 'rerecorded', 're recording', 'version originally performed'],
        'Compilation' => ['greatest hits', 'best of', 'very best', 'essential', 'collection', 'anthology', 'summer songs', 'now that', 'hits', 'gold', 'ultimate'],
        'Soundtrack' => ['soundtrack', 'motion picture', 'original motion picture'],
    ];
    foreach ($checks as $label => $needles) {
        foreach ($needles as $needle) {
            if (strpos($haystack, $needle) !== false) {
                $tags[] = $label;
                break;
            }
        }
    }
    return array_values(array_unique($tags));
}

function dttd_spotify_decade_from_release_date($releaseDate) {
    if (!preg_match('/^(\d{4})/', (string)$releaseDate, $m)) {
        return null;
    }
    $year = (int)$m[1];
    if ($year >= 1960 && $year <= 1969) return '60s';
    if ($year >= 1970 && $year <= 1979) return '70s';
    if ($year >= 1980 && $year <= 1989) return '80s';
    if ($year >= 1990 && $year <= 1999) return '90s';
    if ($year >= 2000 && $year <= 2009) return '00s';
    return null;
}

function dttd_spotify_query_terms($query) {
    $q = dttd_spotify_clean_token_text($query);
    if ($q === '') return [];
    $drop = ['the', 'and', 'feat', 'ft', 'with', 'remaster', 'remastered', 'official', 'video'];
    $terms = [];
    foreach (explode(' ', $q) as $term) {
        if (mb_strlen($term) < 3 || in_array($term, $drop, true)) continue;
        $terms[] = $term;
    }
    return array_values(array_unique($terms));
}

function dttd_spotify_query_match_score(array $track, $query = '') {
    $terms = dttd_spotify_query_terms($query);
    if (!$terms) return 0;

    $title = dttd_spotify_clean_token_text($track['title'] ?? '');
    $artist = dttd_spotify_clean_token_text($track['artist'] ?? '');
    $album = dttd_spotify_clean_token_text($track['album'] ?? '');
    $score = 0;

    $titleHits = 0;
    $artistHits = 0;
    foreach ($terms as $term) {
        if (strpos($title, $term) !== false) { $score += 9; $titleHits++; }
        if (strpos($artist, $term) !== false) { $score += 6; $artistHits++; }
        if (strpos($album, $term) !== false) { $score += 2; }
    }
    if ($titleHits === count($terms)) $score += 18;
    if ($artistHits > 0) $score += 8;

    return $score;
}

function dttd_spotify_original_candidate_score(array $track, array $versionTags, $decade) {
    $score = 0;
    $badOriginalTags = ['Live', 'Remix', 'Acoustic', 'Karaoke', 'Instrumental', 'Cover'];
    $hasBadOriginalTag = count(array_intersect($versionTags, $badOriginalTags)) > 0;
    $isRemaster = in_array('Remaster', $versionTags, true);
    $isCompilation = in_array('Compilation', $versionTags, true);
    $albumType = strtolower((string)($track['album_type'] ?? ''));

    if ($decade !== null) $score += 52;
    if ($albumType === 'album' || $albumType === 'single') $score += 14;
    if (!$hasBadOriginalTag) $score += 28;
    if (!$isRemaster) $score += 18;
    if (!$isCompilation) $score += 12;

    if ($isRemaster) $score -= 28;
    if ($isCompilation) $score -= 14;
    foreach ($badOriginalTags as $tag) {
        if (in_array($tag, $versionTags, true)) $score -= 24;
    }

    return $score;
}

function dttd_spotify_enrich_track(array $track, $query = '') {
    $releaseDate = (string)($track['release_date'] ?? '');
    $decade = dttd_spotify_decade_from_release_date($releaseDate);
    $versionTags = dttd_spotify_track_version_tags($track);
    $score = 0;

    $score += dttd_spotify_query_match_score($track, $query);
    $score += dttd_spotify_original_candidate_score($track, $versionTags, $decade);

    if (isset($track['popularity']) && $track['popularity'] !== null) {
        $score += min(18, max(0, (int)$track['popularity']) / 6);
    }

    $hardNegative = ['Karaoke', 'Cover', 'Instrumental'];
    foreach ($hardNegative as $tag) {
        if (in_array($tag, $versionTags, true)) $score -= 35;
    }

    $badOriginalLabels = ['Live', 'Remix', 'Acoustic', 'Karaoke', 'Instrumental', 'Cover'];
    $isCompilation = in_array('Compilation', $versionTags, true);
    $isRemaster = in_array('Remaster', $versionTags, true);
    $likelyOriginal = $decade !== null
        && !$isCompilation
        && !$isRemaster
        && count(array_intersect($versionTags, $badOriginalLabels)) === 0;

    $badges = [];
    if ($decade !== null) $badges[] = ['type' => 'decade', 'label' => $decade];
    if ($likelyOriginal) {
        $score += 35;
        $badges[] = ['type' => 'original', 'label' => 'Original'];
    } elseif ($decade !== null && $isRemaster && count(array_intersect($versionTags, $badOriginalLabels)) === 0) {
        $badges[] = ['type' => 'original-era', 'label' => 'Original era'];
    }
    foreach ($versionTags as $tag) {
        $badges[] = ['type' => strtolower(str_replace(' ', '-', $tag)), 'label' => $tag];
    }

    $track['release_year'] = $releaseDate !== '' ? (int)substr($releaseDate, 0, 4) : null;
    $track['decade'] = $decade;
    $track['version_tags'] = $versionTags;
    $track['likely_original'] = $likelyOriginal;
    $track['search_score'] = round($score, 2);
    $track['badges'] = $badges;
    return $track;
}

function dttd_spotify_dedupe_key(array $track) {
    $id = trim((string)($track['id'] ?? ''));
    if ($id !== '') return 'id:' . $id;
    $url = trim((string)($track['url'] ?? ''));
    if ($url !== '') return 'url:' . $url;
    return 'text:' . dttd_spotify_clean_token_text(($track['title'] ?? '') . '|' . ($track['artist'] ?? ''));
}

function dttd_spotify_enrich_and_sort_tracks(array $tracks, $query = '') {
    $out = [];
    $seen = [];
    foreach ($tracks as $i => $track) {
        $track['_original_order'] = $i;
        $track = dttd_spotify_enrich_track($track, $query);
        $key = dttd_spotify_dedupe_key($track);
        if (isset($seen[$key])) {
            $existingIndex = $seen[$key];
            if ((float)($track['search_score'] ?? 0) > (float)($out[$existingIndex]['search_score'] ?? 0)) {
                $out[$existingIndex] = $track;
            }
            continue;
        }
        $seen[$key] = count($out);
        $out[] = $track;
    }
    usort($out, function($a, $b) {
        $scoreA = (float)($a['search_score'] ?? 0);
        $scoreB = (float)($b['search_score'] ?? 0);
        if ($scoreA === $scoreB) {
            $origA = !empty($a['likely_original']) ? 1 : 0;
            $origB = !empty($b['likely_original']) ? 1 : 0;
            if ($origA !== $origB) return $origB <=> $origA;
            $decA = !empty($a['decade']) ? 1 : 0;
            $decB = !empty($b['decade']) ? 1 : 0;
            if ($decA !== $decB) return $decB <=> $decA;
            return (int)($a['_original_order'] ?? 0) <=> (int)($b['_original_order'] ?? 0);
        }
        return $scoreA < $scoreB ? 1 : -1;
    });
    foreach ($out as &$track) {
        unset($track['_original_order']);
    }
    return $out;
}

function dttd_spotify_normalise_track_item(array $item) {
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

    $id = $item['id'] ?? '';
    $uri = $item['uri'] ?? ($id !== '' ? 'spotify:track:' . $id : '');

    return [
        'id' => $id,
        'uri' => $uri,
        'title' => $item['name'] ?? '',
        'artist' => implode(', ', $artists),
        'album' => $item['album']['name'] ?? '',
        'image' => $image,
        'url' => $item['external_urls']['spotify'] ?? '',
        'duration_ms' => $item['duration_ms'] ?? null,
        'popularity' => $item['popularity'] ?? null,
        'release_date' => $item['album']['release_date'] ?? '',
        'release_date_precision' => $item['album']['release_date_precision'] ?? '',
        'album_type' => $item['album']['album_type'] ?? '',
    ];
}

function dttd_spotify_cache_track(array $track, $query = '') {
    $uri = trim((string)($track['uri'] ?? ''));
    if ($uri === '') {
        $id = trim((string)($track['id'] ?? ''));
        if ($id !== '') {
            $uri = strpos($id, 'spotify:track:') === 0 ? $id : 'spotify:track:' . $id;
        }
    }
    if ($uri === '') {
        return false;
    }

    $searchable = trim(implode(' ', array_filter([
        $track['title'] ?? '',
        $track['artist'] ?? '',
        $track['album'] ?? '',
        $query,
    ])));

    try {
        $stmt = db()->prepare("\n            INSERT INTO spotify_track_cache\n                (spotify_uri, track_name, artist_name, album_name, artwork_url, duration_ms, popularity, searchable_text, search_count, last_requested_at)\n            VALUES\n                (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())\n            ON DUPLICATE KEY UPDATE\n                track_name = VALUES(track_name),\n                artist_name = VALUES(artist_name),\n                album_name = VALUES(album_name),\n                artwork_url = VALUES(artwork_url),\n                duration_ms = VALUES(duration_ms),\n                popularity = VALUES(popularity),\n                searchable_text = VALUES(searchable_text),\n                search_count = search_count + 1,\n                last_requested_at = NOW()\n        ");
        return $stmt->execute([
            $uri,
            $track['title'] ?? '',
            $track['artist'] ?? '',
            $track['album'] ?? '',
            $track['image'] ?? '',
            $track['duration_ms'] ?? null,
            $track['popularity'] ?? null,
            $searchable,
        ]);
    } catch (Throwable $e) {
        return false;
    }
}

function dttd_spotify_cache_result_row(array $row) {
    $uri = (string)($row['spotify_uri'] ?? '');
    $id = preg_replace('/^spotify:track:/', '', $uri);
    return [
        'id' => $id,
        'uri' => $uri,
        'title' => $row['track_name'] ?? '',
        'artist' => $row['artist_name'] ?? '',
        'album' => $row['album_name'] ?? '',
        'image' => $row['artwork_url'] ?? '',
        'url' => $id ? 'https://open.spotify.com/track/' . $id : '',
        'duration_ms' => $row['duration_ms'] ?? null,
        'cached' => true,
    ];
}

function dttd_spotify_search_track_cache($query, $limit = 8) {
    $query = trim((string)$query);
    if ($query === '' || strlen($query) < 2) {
        return [];
    }
    $limit = max(1, min(20, (int)$limit));

    try {
        $terms = preg_split('/\s+/', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_slice(array_unique($terms), 0, 6);
        if (!$terms) {
            return [];
        }
        $where = [];
        $params = [];
        foreach ($terms as $term) {
            $where[] = 'LOWER(searchable_text) LIKE ?';
            $params[] = '%' . $term . '%';
        }
        $params[] = $limit;
        $sql = "SELECT * FROM spotify_track_cache WHERE " . implode(' AND ', $where) . " ORDER BY search_count DESC, last_requested_at DESC, track_name ASC LIMIT ?";
        $stmt = db()->prepare($sql);
        foreach ($params as $i => $param) {
            $stmt->bindValue($i + 1, $param, $i === count($params) - 1 ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return array_map('dttd_spotify_cache_result_row', $stmt->fetchAll() ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_spotify_search_tracks_external_with_profile($query, $limit = 8, &$meta = []) {
    $query = trim((string)$query);
    if ($query === '' || strlen($query) < 2) {
        return [];
    }

    $limit = max(1, min(10, (int)$limit));
    $credentials = dttd_spotify_public_search_credentials();
    $meta['profile_source'] = $credentials['source'] ?? 'unknown';
    $meta['profile_label'] = $credentials['label'] ?? '';

    $token = dttd_spotify_client_credentials_token_for($credentials);
    $url = 'https://api.spotify.com/v1/search?type=track&limit=' . $limit . '&q=' . rawurlencode($query);

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not available.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Spotify public search failed' . ($error ? ': ' . $error : '.'));
    }

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $meta['http_status'] = $status;

    if ($status === 429) {
        if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
            $meta['retry_after'] = (int)$m[1];
        }
        throw new RuntimeException('Spotify search is cooling down. Cached results are being used.');
    }

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Spotify public search failed with HTTP ' . $status . '.');
    }

    $data = json_decode($body, true) ?: [];
    $items = $data['tracks']['items'] ?? [];
    $tracks = [];
    foreach ($items as $item) {
        if (!empty($item['id'])) {
            $tracks[] = dttd_spotify_normalise_track_item($item);
        }
    }
    return function_exists('dttd_spotify_enrich_and_sort_tracks') ? dttd_spotify_enrich_and_sort_tracks($tracks, $query) : $tracks;
}

function dttd_spotify_public_search_tracks_cached($query, $limit = 8) {
    $query = trim((string)$query);
    $limit = max(1, min(10, (int)$limit));
    $cache = dttd_spotify_search_track_cache($query, $limit);
    $meta = [
        'cache_count' => count($cache),
        'spotify_used' => false,
        'profile_source' => null,
        'profile_label' => null,
        'rate_limited' => false,
        'fallback' => null,
    ];

    // If the cache already has enough results, avoid Spotify completely.
    if (count($cache) >= min(5, $limit)) {
        $meta['fallback'] = 'cache_only';
        return ['tracks' => array_slice($cache, 0, $limit), 'meta' => $meta];
    }

    try {
        if (!dttd_spotify_public_search_configured()) {
            $meta['fallback'] = 'cache_no_spotify_config';
            return ['tracks' => array_slice($cache, 0, $limit), 'meta' => $meta];
        }
        $externalMeta = [];
        $fresh = dttd_spotify_search_tracks_external_with_profile($query, $limit, $externalMeta);
        $meta = array_merge($meta, $externalMeta, ['spotify_used' => true]);
        foreach ($fresh as $track) {
            dttd_spotify_cache_track($track, $query);
        }
        if ($fresh) {
            return ['tracks' => $fresh, 'meta' => $meta];
        }
    } catch (Throwable $e) {
        $meta['spotify_error'] = $e->getMessage();
        $meta['rate_limited'] = stripos($e->getMessage(), 'cooling down') !== false || stripos($e->getMessage(), '429') !== false;
        $meta['fallback'] = $cache ? 'cache_after_spotify_error' : 'text_only_after_spotify_error';
    }

    return ['tracks' => array_slice($cache, 0, $limit), 'meta' => $meta];
}


/**
 * Compact Spotify profile summary for mixer UI/status validation.
 */
function dttd_spotify_profile_summary_for_deck($deck) {
    $deck = strtolower((string)$deck) === 'b' ? 'b' : 'a';
    $profile = function_exists('dttd_spotify_profile_by_deck') ? dttd_spotify_profile_by_deck($deck) : null;
    if (!$profile) {
        return [
            'assigned' => false,
            'connected' => dttd_spotify_queue_connected(),
            'id' => 0,
            'slot' => 0,
            'label' => 'Primary / legacy Spotify account',
            'display' => 'Primary / legacy Spotify account',
            'email' => '',
            'warning' => dttd_spotify_queue_connected() ? '' : 'No connected Spotify account assigned to Deck ' . strtoupper($deck),
        ];
    }
    $label = trim((string)($profile['label'] ?? ''));
    $email = trim((string)($profile['account_email'] ?? ''));
    $slot = isset($profile['profile_slot']) ? (int)$profile['profile_slot'] : 0;
    $connected = trim((string)($profile['refresh_token'] ?? '')) !== '';
    $display = $label !== '' ? $label : ('Account ' . ($slot ?: (int)($profile['id'] ?? 0)));
    if ($email !== '') $display .= ' — ' . $email;
    return [
        'assigned' => true,
        'connected' => $connected,
        'id' => (int)($profile['id'] ?? 0),
        'slot' => $slot,
        'label' => $label,
        'display' => $display,
        'email' => $email,
        'warning' => $connected ? '' : 'Assigned Spotify account is not connected for Deck ' . strtoupper($deck),
    ];
}

function dttd_spotify_profile_summary_for_public_search() {
    $profile = null;
    try {
        $cols = [];
        $stmtCols = db()->query('SHOW COLUMNS FROM spotify_profiles');
        foreach ($stmtCols->fetchAll() as $row) if (!empty($row['Field'])) $cols[$row['Field']] = true;
        if (!empty($cols['use_for_public_search'])) {
            $order = !empty($cols['profile_slot']) ? 'profile_slot ASC, id ASC' : 'id ASC';
            $stmt = db()->query("SELECT * FROM spotify_profiles WHERE enabled = 1 AND use_for_public_search = 1 ORDER BY $order LIMIT 1");
            $profile = $stmt->fetch() ?: null;
        }
    } catch (Throwable $e) { $profile = null; }
    if (!$profile) return ['assigned' => false, 'connected' => false, 'display' => 'Not assigned'];
    $label = trim((string)($profile['label'] ?? ''));
    $email = trim((string)($profile['account_email'] ?? ''));
    $slot = isset($profile['profile_slot']) ? (int)$profile['profile_slot'] : 0;
    $display = $label !== '' ? $label : ('Account ' . ($slot ?: (int)($profile['id'] ?? 0)));
    if ($email !== '') $display .= ' — ' . $email;
    return [
        'assigned' => true,
        'connected' => trim((string)($profile['refresh_token'] ?? '')) !== '',
        'id' => (int)($profile['id'] ?? 0),
        'slot' => $slot,
        'label' => $label,
        'display' => $display,
        'email' => $email,
    ];
}
