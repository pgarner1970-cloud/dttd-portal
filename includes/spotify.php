<?php
/**
 * Spotify Web API helper for Dance Thru The Decades.
 *
 * Copy includes/config.spotify.example.php to includes/config.spotify.php
 * and add your Spotify Client ID + Client Secret there. Do not commit secrets.
 */

function dttd_spotify_config_loaded() {
    $config = __DIR__ . '/config.spotify.php';
    if (is_file($config)) {
        require_once $config;
    }

    return defined('SPOTIFY_CLIENT_ID')
        && defined('SPOTIFY_CLIENT_SECRET')
        && SPOTIFY_CLIENT_ID !== ''
        && SPOTIFY_CLIENT_SECRET !== '';
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

    $cache_file = sys_get_temp_dir() . '/dttd_spotify_client_token.json';
    if (is_file($cache_file)) {
        $cached = json_decode((string)file_get_contents($cache_file), true);
        if (!empty($cached['access_token']) && !empty($cached['expires_at']) && $cached['expires_at'] > time() + 60) {
            return $cached['access_token'];
        }
    }

    $auth = base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET);
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
    if ($query === '' || mb_strlen($query) < 2) {
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
        $artists = array_map(fn($a) => $a['name'] ?? '', $item['artists'] ?? []);
        $artists = array_values(array_filter($artists));
        $images = $item['album']['images'] ?? [];
        $image = '';
        if (!empty($images)) {
            $image = $images[count($images) - 1]['url'] ?? ($images[0]['url'] ?? '');
        }

        $tracks[] = [
            'id' => $item['id'] ?? '',
            'title' => $item['name'] ?? '',
            'artist' => implode(', ', $artists),
            'album' => $item['album']['name'] ?? '',
            'url' => $item['external_urls']['spotify'] ?? '',
            'image' => $image,
            'duration_ms' => $item['duration_ms'] ?? null,
        ];
    }

    return $tracks;
}
