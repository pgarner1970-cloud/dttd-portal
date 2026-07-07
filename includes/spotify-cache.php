<?php
/**
 * Spotify search cache helpers.
 *
 * This file is intentionally separate from includes/spotify.php so it can be
 * added without disturbing the existing playback/mixer Spotify logic.
 */

if (!function_exists('dttd_track_cache_has_table')) {
    function dttd_track_cache_has_table() {
        static $has = null;
        if ($has !== null) return $has;
        try {
            db()->query('SELECT 1 FROM spotify_track_cache LIMIT 1');
            $has = true;
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }
}


if (!function_exists('dttd_spotify_search_cache_has_table')) {
    function dttd_spotify_search_cache_has_table() {
        static $has = null;
        if ($has !== null) return $has;
        try {
            db()->query('SELECT 1 FROM spotify_search_cache LIMIT 1');
            $has = true;
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }
}

if (!function_exists('dttd_spotify_search_cache_normalise_query')) {
    function dttd_spotify_search_cache_normalise_query($query) {
        $q = mb_strtolower(trim((string)$query));
        $q = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q);
        $q = preg_replace('/\s+/u', ' ', $q);
        return trim($q);
    }
}

if (!function_exists('dttd_spotify_search_cache_hash')) {
    function dttd_spotify_search_cache_hash($query) {
        return hash('sha256', dttd_spotify_search_cache_normalise_query($query));
    }
}

if (!function_exists('dttd_spotify_search_cache_tracks_by_uris')) {
    function dttd_spotify_search_cache_tracks_by_uris(array $uris, $limit = 8) {
        if (!dttd_track_cache_has_table() || !$uris) return [];
        $limit = max(1, min(20, (int)$limit));
        $uris = array_values(array_unique(array_filter(array_map('strval', $uris))));
        $uris = array_slice($uris, 0, min(50, max($limit, count($uris))));
        if (!$uris) return [];

        try {
            $placeholders = implode(',', array_fill(0, count($uris), '?'));
            $stmt = db()->prepare("SELECT * FROM spotify_track_cache WHERE spotify_uri IN ($placeholders)");
            foreach ($uris as $i => $uri) {
                $stmt->bindValue($i + 1, $uri, PDO::PARAM_STR);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll() ?: [];
            $byUri = [];
            foreach ($rows as $row) {
                $byUri[(string)$row['spotify_uri']] = dttd_track_cache_row_to_track($row);
            }

            $tracks = [];
            foreach ($uris as $uri) {
                if (isset($byUri[$uri])) {
                    $tracks[] = $byUri[$uri];
                    if (count($tracks) >= $limit) break;
                }
            }
            return $tracks;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('dttd_spotify_search_cache_get')) {
    function dttd_spotify_search_cache_get($query, $limit = 8) {
        if (!dttd_spotify_search_cache_has_table()) return null;
        $normalised = dttd_spotify_search_cache_normalise_query($query);
        if ($normalised === '' || mb_strlen($normalised) < 3) return null;
        $limit = max(1, min(20, (int)$limit));
        $hash = hash('sha256', $normalised);

        try {
            $stmt = db()->prepare("\n                SELECT *\n                FROM spotify_search_cache\n                WHERE query_hash = ?\n                  AND expires_at > NOW()\n                LIMIT 1\n            ");
            $stmt->execute([$hash]);
            $row = $stmt->fetch();
            if (!$row) return null;

            $uris = json_decode((string)($row['result_uris_json'] ?? '[]'), true);
            if (!is_array($uris)) $uris = [];
            $tracks = dttd_spotify_search_cache_tracks_by_uris($uris, $limit);

            $update = db()->prepare("\n                UPDATE spotify_search_cache\n                SET hit_count = hit_count + 1, last_hit_at = NOW()\n                WHERE query_hash = ?\n            ");
            $update->execute([$hash]);

            return [
                'tracks' => $tracks,
                'row' => $row,
                'uris' => $uris,
                'usable' => count($tracks) > 0 || (int)($row['result_count'] ?? 0) === 0,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('dttd_spotify_search_cache_store')) {
    function dttd_spotify_search_cache_store($query, array $tracks, $ttlMinutes = 10080) {
        if (!dttd_spotify_search_cache_has_table()) return false;
        $raw = trim((string)$query);
        $normalised = dttd_spotify_search_cache_normalise_query($raw);
        if ($normalised === '' || mb_strlen($normalised) < 3) return false;
        $ttlMinutes = max(10, min(43200, (int)$ttlMinutes));
        $hash = hash('sha256', $normalised);

        $uris = [];
        foreach ($tracks as $track) {
            $t = dttd_track_cache_normalise((array)$track);
            if (!empty($t['uri'])) $uris[] = $t['uri'];
        }
        $uris = array_values(array_unique($uris));
        $json = json_encode($uris, JSON_UNESCAPED_SLASHES);

        try {
            $stmt = db()->prepare("\n                INSERT INTO spotify_search_cache\n                    (query_hash, normalised_query, raw_query_sample, result_uris_json, result_count, expires_at, created_at, updated_at)\n                VALUES\n                    (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW(), NOW())\n                ON DUPLICATE KEY UPDATE\n                    normalised_query = VALUES(normalised_query),\n                    raw_query_sample = VALUES(raw_query_sample),\n                    result_uris_json = VALUES(result_uris_json),\n                    result_count = VALUES(result_count),\n                    expires_at = VALUES(expires_at),\n                    updated_at = NOW()\n            ");
            return $stmt->execute([
                $hash,
                mb_substr($normalised, 0, 255),
                mb_substr($raw, 0, 255),
                $json,
                count($uris),
                $ttlMinutes,
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('dttd_track_cache_normalise')) {
    function dttd_track_cache_normalise(array $track) {
        $id = trim((string)($track['id'] ?? ''));
        $uri = trim((string)($track['uri'] ?? ''));
        if ($uri === '' && $id !== '') {
            $uri = strpos($id, 'spotify:track:') === 0 ? $id : 'spotify:track:' . $id;
        }
        if ($id === '' && strpos($uri, 'spotify:track:') === 0) {
            $id = substr($uri, strlen('spotify:track:'));
        }

        $base = [
            'id' => $id,
            'uri' => $uri,
            'title' => (string)($track['title'] ?? $track['track_name'] ?? ''),
            'artist' => (string)($track['artist'] ?? $track['artist_name'] ?? ''),
            'album' => (string)($track['album'] ?? $track['album_name'] ?? ''),
            'image' => (string)($track['image'] ?? $track['artwork_url'] ?? ''),
            'url' => (string)($track['url'] ?? ($id !== '' ? 'https://open.spotify.com/track/' . $id : '')),
            'duration_ms' => isset($track['duration_ms']) ? (int)$track['duration_ms'] : null,
            'popularity' => isset($track['popularity']) ? (int)$track['popularity'] : null,
            'release_date' => (string)($track['release_date'] ?? ''),
            'release_date_precision' => (string)($track['release_date_precision'] ?? ''),
        ];
        return function_exists('dttd_spotify_enrich_track') ? dttd_spotify_enrich_track($base) : $base;
    }
}

if (!function_exists('dttd_track_cache_store')) {
    function dttd_track_cache_store(array $track, $query = '') {
        if (!dttd_track_cache_has_table()) return false;
        $t = dttd_track_cache_normalise($track);
        if ($t['uri'] === '' || $t['title'] === '') return false;

        $searchable = trim(implode(' ', array_filter([
            $t['title'],
            $t['artist'],
            $t['album'],
            (string)$query,
        ])));

        try {
            $stmt = db()->prepare("\n                INSERT INTO spotify_track_cache\n                    (spotify_uri, track_name, artist_name, album_name, artwork_url, duration_ms, popularity, searchable_text, search_count, last_requested_at)\n                VALUES\n                    (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())\n                ON DUPLICATE KEY UPDATE\n                    track_name = VALUES(track_name),\n                    artist_name = VALUES(artist_name),\n                    album_name = VALUES(album_name),\n                    artwork_url = VALUES(artwork_url),\n                    duration_ms = VALUES(duration_ms),\n                    popularity = COALESCE(VALUES(popularity), popularity),\n                    searchable_text = VALUES(searchable_text),\n                    search_count = search_count + 1,\n                    last_requested_at = NOW()\n            ");
            return $stmt->execute([
                $t['uri'],
                $t['title'],
                $t['artist'],
                $t['album'],
                $t['image'],
                $t['duration_ms'],
                $t['popularity'],
                $searchable,
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('dttd_track_cache_row_to_track')) {
    function dttd_track_cache_row_to_track(array $row) {
        $uri = (string)($row['spotify_uri'] ?? '');
        $id = preg_replace('/^spotify:track:/', '', $uri);
        $base = [
            'id' => $id,
            'uri' => $uri,
            'title' => (string)($row['track_name'] ?? ''),
            'artist' => (string)($row['artist_name'] ?? ''),
            'album' => (string)($row['album_name'] ?? ''),
            'image' => (string)($row['artwork_url'] ?? ''),
            'url' => $id !== '' ? 'https://open.spotify.com/track/' . $id : '',
            'duration_ms' => isset($row['duration_ms']) ? (int)$row['duration_ms'] : null,
            'cached' => true,
        ];
        return function_exists('dttd_spotify_enrich_track') ? dttd_spotify_enrich_track($base) : $base;
    }
}

if (!function_exists('dttd_track_cache_search')) {
    function dttd_track_cache_search($query, $limit = 8) {
        if (!dttd_track_cache_has_table()) return [];
        $query = trim((string)$query);
        if ($query === '' || mb_strlen($query) < 2) return [];
        $limit = max(1, min(20, (int)$limit));

        $terms = preg_split('/\s+/', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_values(array_unique(array_filter($terms, function ($t) { return mb_strlen($t) >= 2; })));
        $terms = array_slice($terms, 0, 6);
        if (!$terms) return [];

        try {
            $where = [];
            $params = [];
            foreach ($terms as $term) {
                $where[] = 'LOWER(searchable_text) LIKE ?';
                $params[] = '%' . $term . '%';
            }

            $sql = "SELECT * FROM spotify_track_cache WHERE " . implode(' AND ', $where) . "\n                    ORDER BY search_count DESC, last_requested_at DESC, track_name ASC\n                    LIMIT ?";
            $stmt = db()->prepare($sql);
            $i = 1;
            foreach ($params as $param) {
                $stmt->bindValue($i++, $param, PDO::PARAM_STR);
            }
            $stmt->bindValue($i, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return array_map('dttd_track_cache_row_to_track', $stmt->fetchAll() ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('dttd_spotify_cached_search_tracks')) {
    function dttd_spotify_cached_search_tracks($query, $limit = 8, array $options = []) {
        $query = trim((string)$query);
        $limit = max(1, min(20, (int)$limit));
        $minLength = isset($options['min_length']) ? (int)$options['min_length'] : 3;
        $cacheEnough = isset($options['cache_enough']) ? (int)$options['cache_enough'] : min(5, $limit);

        $meta = [
            'source' => 'none',
            'cache_count' => 0,
            'spotify_used' => false,
            'rate_limited' => false,
            'message' => '',
        ];

        if ($query === '' || mb_strlen($query) < $minLength) {
            return ['tracks' => [], 'meta' => $meta];
        }

        $queryCached = dttd_spotify_search_cache_get($query, $limit);
        if ($queryCached && !empty($queryCached['usable'])) {
            $tracks = $queryCached['tracks'] ?? [];
            $meta['source'] = 'query_cache';
            $meta['cache_count'] = count($tracks);
            $meta['query_cache_hit'] = true;
            $meta['spotify_used'] = false;
            $meta['message'] = $tracks ? 'Showing saved Spotify search results.' : 'No Spotify matches found.';
            return ['tracks' => array_slice($tracks, 0, $limit), 'meta' => $meta];
        }

        $cached = dttd_track_cache_search($query, $limit);
        $meta['cache_count'] = count($cached);

        if (count($cached) >= $cacheEnough) {
            $meta['source'] = 'track_cache';
            $meta['query_cache_hit'] = false;
            $meta['message'] = 'Showing cached matches.';
            return ['tracks' => array_slice($cached, 0, $limit), 'meta' => $meta];
        }

        try {
            if (!function_exists('dttd_spotify_config_loaded') || !dttd_spotify_config_loaded()) {
                $meta['source'] = 'cache';
                $meta['message'] = $cached ? 'Spotify is not configured. Showing cached matches.' : 'Spotify is not configured.';
                return ['tracks' => array_slice($cached, 0, $limit), 'meta' => $meta];
            }

            $fresh = dttd_spotify_search_tracks($query, min(10, $limit));
            $meta['spotify_used'] = true;
            $meta['source'] = 'spotify';

            foreach ($fresh as $track) {
                dttd_track_cache_store($track, $query);
            }
            dttd_spotify_search_cache_store($query, $fresh, (int)($options['query_cache_ttl_minutes'] ?? 10080));

            if ($fresh) {
                $meta['query_cache_hit'] = false;
                return ['tracks' => $fresh, 'meta' => $meta];
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $meta['rate_limited'] = stripos($msg, '429') !== false || stripos($msg, 'too many') !== false || stripos($msg, 'cooling') !== false;
            $meta['source'] = $cached ? 'cache' : 'none';
            $meta['message'] = $cached ? 'Spotify search is cooling down. Showing cached matches.' : 'Spotify search is cooling down. Manual entry still works.';
            return ['tracks' => array_slice($cached, 0, $limit), 'meta' => $meta];
        }

        $meta['source'] = $cached ? 'cache' : 'none';
        $meta['message'] = $cached ? 'Showing cached matches.' : 'No Spotify matches found.';
        return ['tracks' => array_slice($cached, 0, $limit), 'meta' => $meta];
    }
}
