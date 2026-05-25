<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/spotify.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], [
    'https://dj.dancethruthedecades.co.uk',
    'https://dancethruthedecades.co.uk',
], true)) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
}

function dttd_public_search_normalise($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9\s]+/i', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function dttd_public_cache_track_to_result(array $row) {
    $uri = (string)($row['spotify_uri'] ?? '');
    $id = $uri;
    if (strpos($id, 'spotify:track:') === 0) {
        $id = substr($id, strlen('spotify:track:'));
    }
    return [
        'id' => $id,
        'uri' => $uri,
        'title' => (string)($row['track_name'] ?? ''),
        'artist' => (string)($row['artist_name'] ?? ''),
        'album' => (string)($row['album_name'] ?? ''),
        'image' => (string)($row['artwork_url'] ?? ''),
        'url' => $id !== '' ? 'https://open.spotify.com/track/' . rawurlencode($id) : '',
        'duration_ms' => isset($row['duration_ms']) ? (int)$row['duration_ms'] : null,
        'source' => 'cache',
    ];
}

function dttd_public_search_cache($query, $limit = 8) {
    $needle = dttd_public_search_normalise($query);
    if ($needle === '') {
        return [];
    }

    $terms = array_values(array_filter(explode(' ', $needle), function ($term) {
        return strlen($term) >= 2;
    }));
    if (!$terms) {
        return [];
    }

    $where = [];
    $params = [];
    foreach (array_slice($terms, 0, 6) as $term) {
        $where[] = 'searchable_text LIKE ?';
        $params[] = '%' . $term . '%';
    }
    $params[] = max(1, min(20, (int)$limit));

    try {
        $sql = "SELECT spotify_uri, track_name, artist_name, album_name, artwork_url, duration_ms, popularity
                FROM spotify_track_cache
                WHERE " . implode(' AND ', $where) . "
                ORDER BY COALESCE(popularity, 0) DESC, last_seen_at DESC
                LIMIT ?";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map('dttd_public_cache_track_to_result', $rows);
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_public_cache_store_tracks(array $tracks) {
    if (!$tracks) {
        return;
    }

    try {
        $stmt = db()->prepare("INSERT INTO spotify_track_cache
            (spotify_uri, track_name, artist_name, album_name, artwork_url, duration_ms, popularity, searchable_text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                track_name = VALUES(track_name),
                artist_name = VALUES(artist_name),
                album_name = VALUES(album_name),
                artwork_url = VALUES(artwork_url),
                duration_ms = VALUES(duration_ms),
                popularity = COALESCE(VALUES(popularity), popularity),
                searchable_text = VALUES(searchable_text),
                last_seen_at = CURRENT_TIMESTAMP");

        foreach ($tracks as $track) {
            $id = trim((string)($track['id'] ?? ''));
            $uri = trim((string)($track['uri'] ?? ''));
            if ($uri === '' && $id !== '') {
                $uri = strpos($id, 'spotify:track:') === 0 ? $id : 'spotify:track:' . $id;
            }
            if ($uri === '') {
                continue;
            }

            $title = (string)($track['title'] ?? '');
            $artist = (string)($track['artist'] ?? '');
            $album = (string)($track['album'] ?? '');
            $searchable = dttd_public_search_normalise($title . ' ' . $artist . ' ' . $album);

            $stmt->execute([
                $uri,
                $title,
                $artist,
                $album,
                (string)($track['image'] ?? ''),
                isset($track['duration_ms']) ? (int)$track['duration_ms'] : null,
                isset($track['popularity']) ? (int)$track['popularity'] : null,
                $searchable,
            ]);
        }
    } catch (Throwable $e) {
        // Cache failures must never block public requests.
    }
}

function dttd_public_merge_tracks(array $primary, array $secondary, $limit = 8) {
    $seen = [];
    $merged = [];
    foreach (array_merge($primary, $secondary) as $track) {
        $key = (string)($track['uri'] ?? '');
        if ($key === '') {
            $key = (string)($track['id'] ?? '') . '|' . strtolower((string)($track['title'] ?? '')) . '|' . strtolower((string)($track['artist'] ?? ''));
        }
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $merged[] = $track;
        if (count($merged) >= $limit) {
            break;
        }
    }
    return $merged;
}

$q = trim((string)($_GET['q'] ?? ''));

try {
    if ($q === '' || strlen($q) < 3) {
        echo json_encode(['ok' => true, 'configured' => true, 'source' => 'none', 'tracks' => []]);
        exit;
    }

    $cached = dttd_public_search_cache($q, 8);

    $spotifyAvailable = dttd_spotify_profile_config_loaded('public') || dttd_spotify_profile_config_loaded('primary');

    if (!$spotifyAvailable) {
        echo json_encode([
            'ok' => true,
            'configured' => false,
            'source' => 'cache',
            'profile' => 'none',
            'message' => $cached ? 'Showing cached results. Spotify API is not configured.' : 'Spotify API is not configured yet. Manual entry still works.',
            'tracks' => $cached,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // If the cache already gives a strong set of results, avoid another live Spotify call.
    if (count($cached) >= 6) {
        echo json_encode([
            'ok' => true,
            'configured' => true,
            'source' => 'cache',
            'cached' => true,
            'message' => 'Showing cached Spotify matches.',
            'tracks' => $cached,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $profileName = dttd_spotify_public_search_profile_name();
        $spotify = dttd_spotify_search_tracks_for_public($q, 8);
        dttd_public_cache_store_tracks($spotify);
        $tracks = dttd_public_merge_tracks($spotify, $cached, 8);
        echo json_encode([
            'ok' => true,
            'configured' => true,
            'source' => 'spotify',
            'profile' => $profileName,
            'cached' => false,
            'tracks' => $tracks,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $spotifyError) {
        echo json_encode([
            'ok' => true,
            'configured' => true,
            'source' => 'cache',
            'profile' => dttd_spotify_public_search_profile_name(),
            'rate_limited' => true,
            'message' => $cached ? 'Spotify is cooling down. Showing cached matches.' : 'Spotify search is cooling down. You can still submit a manual request.',
            'tracks' => $cached,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'configured' => true,
        'message' => 'Spotify search is currently unavailable. Manual entry still works.',
        'tracks' => [],
    ]);
}
