<?php
require_once __DIR__ . '/db.php';

function dttd_local_music_table_exists($table) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') return false;
    if (array_key_exists($table, $cache)) return $cache[$table];

    try {
        $stmt = db()->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function dttd_local_music_setting($key, $default = '') {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([(string)$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function dttd_local_music_set_setting($key, $value) {
    $stmt = db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([(string)$key, (string)$value]);
}

function dttd_local_music_sync_key() {
    return dttd_local_music_setting('local_music_sync_key', '');
}

function dttd_local_music_generate_sync_key() {
    $key = bin2hex(random_bytes(24));
    dttd_local_music_set_setting('local_music_sync_key', $key);
    return $key;
}

function dttd_local_music_mask_key($key) {
    $key = (string)$key;
    if ($key === '') return '';
    if (strlen($key) <= 10) return str_repeat('•', strlen($key));
    return substr($key, 0, 6) . str_repeat('•', max(6, strlen($key) - 12)) . substr($key, -6);
}

function dttd_local_music_normalise_path($path) {
    $path = str_replace('\\', '/', trim((string)$path));
    $path = preg_replace('#/+#', '/', $path);
    $path = ltrim($path, '/');
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') continue;
        $parts[] = $part;
    }
    return implode('/', $parts);
}

function dttd_local_music_guess_title($relativePath, $fileName = '') {
    $name = $fileName !== '' ? $fileName : basename((string)$relativePath);
    $name = preg_replace('/\.[a-zA-Z0-9]{2,5}$/', '', $name);
    $name = preg_replace('/^[0-9]{1,3}[\s._-]+/', '', $name);
    $name = str_replace(['_', '.'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function dttd_local_music_track_output($row) {
    $title = trim((string)($row['display_title'] ?? ''));
    if ($title === '') $title = trim((string)($row['detected_title'] ?? ''));
    if ($title === '') $title = dttd_local_music_guess_title((string)($row['relative_path'] ?? ''), (string)($row['file_name'] ?? ''));

    $artist = trim((string)($row['display_artist'] ?? ''));
    if ($artist === '') $artist = trim((string)($row['detected_artist'] ?? ''));
    if ($artist === '') $artist = 'Local music';

    $album = trim((string)($row['display_album'] ?? ''));
    if ($album === '') $album = trim((string)($row['detected_album'] ?? ''));

    $durationMs = null;
    if (isset($row['duration_seconds']) && $row['duration_seconds'] !== null && $row['duration_seconds'] !== '') {
        $durationMs = max(0, (int)$row['duration_seconds']) * 1000;
    }

    $image = trim((string)($row['artwork_path'] ?? ''));
    if ($image === '') $image = 'https://dancethruthedecades.co.uk/assets/glitter-ball-clean.png';

    $spotifyUrl = trim((string)($row['spotify_match_url'] ?? ''));
    $spotifyUri = trim((string)($row['spotify_match_uri'] ?? ''));

    return [
        'id' => 'local:' . (int)($row['id'] ?? 0),
        'source' => 'local',
        'source_label' => 'Local',
        'local_track_id' => (int)($row['id'] ?? 0),
        'local_path' => (string)($row['relative_path'] ?? ''),
        'title' => $title,
        'artist' => $artist,
        'album' => $album,
        'image' => $image,
        'url' => $spotifyUrl,
        'spotify_uri' => $spotifyUri,
        'spotify_url' => $spotifyUrl,
        'duration_ms' => $durationMs,
        'needs_review' => !empty($row['needs_review']),
        'spotify_match_status' => (string)($row['spotify_match_status'] ?? 'unchecked'),
    ];
}

function dttd_local_music_search($query, $limit = 10) {
    if (!dttd_local_music_table_exists('local_tracks')) return [];

    $query = trim((string)$query);
    if ($query === '' || strlen($query) < 2) return [];

    $terms = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
    $terms = array_slice($terms ?: [], 0, 5);
    if (!$terms) return [];

    $where = ["is_enabled = 1", "missing_since_at IS NULL"];
    $params = [];
    foreach ($terms as $term) {
        $where[] = "(display_title LIKE ? OR detected_title LIKE ? OR display_artist LIKE ? OR detected_artist LIKE ? OR display_album LIKE ? OR detected_album LIKE ? OR relative_path LIKE ? OR file_name LIKE ?)";
        $like = '%' . $term . '%';
        for ($i = 0; $i < 8; $i++) $params[] = $like;
    }

    $limit = max(1, min(30, (int)$limit));
    $sql = "SELECT * FROM local_tracks WHERE " . implode(' AND ', $where) . " ORDER BY needs_review ASC, COALESCE(NULLIF(display_artist,''), NULLIF(detected_artist,''), '') ASC, COALESCE(NULLIF(display_title,''), NULLIF(detected_title,''), file_name) ASC LIMIT " . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('dttd_local_music_track_output', $stmt->fetchAll());
}

function dttd_local_music_counts() {
    $empty = [
        'table_exists' => dttd_local_music_table_exists('local_tracks'),
        'total' => 0,
        'enabled' => 0,
        'needs_review' => 0,
        'missing' => 0,
        'public_enabled' => 0,
        'spotify_matched' => 0,
        'last_seen_at' => '',
    ];
    if (!$empty['table_exists']) return $empty;

    try {
        $row = db()->query("SELECT COUNT(*) AS total, SUM(is_enabled = 1) AS enabled, SUM(needs_review = 1) AS needs_review, SUM(missing_since_at IS NOT NULL) AS missing, SUM(public_search_enabled = 1) AS public_enabled, SUM(spotify_match_status = 'matched') AS spotify_matched, MAX(last_seen_at) AS last_seen_at FROM local_tracks")->fetch();
        foreach (['total','enabled','needs_review','missing','public_enabled','spotify_matched'] as $key) {
            $empty[$key] = (int)($row[$key] ?? 0);
        }
        $empty['last_seen_at'] = (string)($row['last_seen_at'] ?? '');
    } catch (Throwable $e) {}

    return $empty;
}

function dttd_local_music_recent_tracks($limit = 50) {
    if (!dttd_local_music_table_exists('local_tracks')) return [];
    $limit = max(1, min(200, (int)$limit));
    try {
        return db()->query("SELECT * FROM local_tracks ORDER BY updated_at DESC, id DESC LIMIT " . $limit)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_local_music_table_columns($table) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') return [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $cache[$table] = [];
    if (!dttd_local_music_table_exists($table)) return $cache[$table];
    try {
        $stmt = db()->query("SHOW COLUMNS FROM `" . $table . "`");
        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['Field'])) $cache[$table][(string)$row['Field']] = true;
        }
    } catch (Throwable $e) {}
    return $cache[$table];
}

function dttd_local_music_column_exists($table, $column) {
    $cols = dttd_local_music_table_columns($table);
    return isset($cols[(string)$column]);
}

function dttd_local_music_spotify_match_schema_missing() {
    $required = ['spotify_match_checked_at', 'spotify_match_attempts', 'spotify_match_error'];
    $missing = [];
    foreach ($required as $col) {
        if (!dttd_local_music_column_exists('local_tracks', $col)) $missing[] = $col;
    }
    return $missing;
}

function dttd_local_music_match_counts() {
    $out = [
        'unchecked' => 0,
        'checking' => 0,
        'matched' => 0,
        'needs_review' => 0,
        'no_match' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];
    if (!dttd_local_music_table_exists('local_tracks')) return $out;
    try {
        $rows = db()->query("SELECT COALESCE(NULLIF(spotify_match_status,''), 'unchecked') AS status, COUNT(*) AS total FROM local_tracks GROUP BY COALESCE(NULLIF(spotify_match_status,''), 'unchecked')")->fetchAll();
        foreach ($rows as $row) {
            $key = (string)($row['status'] ?? 'unchecked');
            if (!array_key_exists($key, $out)) $out[$key] = 0;
            $out[$key] = (int)($row['total'] ?? 0);
        }
    } catch (Throwable $e) {}
    return $out;
}

function dttd_local_music_plain_text($value) {
    $value = strtolower((string)$value);
    $value = preg_replace('/\([^)]*\)|\[[^]]*\]/', ' ', $value);
    $value = str_replace(['&'], ' and ', $value);
    $value = preg_replace('/\b(feat|ft|featuring|remaster|remastered|radio edit|single version|explicit|clean)\b/i', ' ', $value);
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function dttd_local_music_track_display_field($row, $display, $detected, $fallback = '') {
    $value = trim((string)($row[$display] ?? ''));
    if ($value !== '') return $value;
    $value = trim((string)($row[$detected] ?? ''));
    if ($value !== '') return $value;
    return $fallback;
}

function dttd_local_music_guess_artist_title_from_filename($relativePath, $fileName = '') {
    $title = dttd_local_music_guess_title($relativePath, $fileName);
    $artist = '';
    if (strpos($title, ' - ') !== false) {
        [$artist, $trackTitle] = explode(' - ', $title, 2);
        $artist = trim($artist);
        $title = trim($trackTitle);
    }
    return [$artist, $title];
}

function dttd_local_music_match_query_for_row($row) {
    [$guessArtist, $guessTitle] = dttd_local_music_guess_artist_title_from_filename((string)($row['relative_path'] ?? ''), (string)($row['file_name'] ?? ''));
    $title = dttd_local_music_track_display_field($row, 'display_title', 'detected_title', $guessTitle);
    $artist = dttd_local_music_track_display_field($row, 'display_artist', 'detected_artist', $guessArtist);
    $title = trim($title);
    $artist = trim($artist);

    if ($title === '' || strtolower($title) === 'unknown') return '';
    if ($artist === '' || strtolower($artist) === 'local music' || strtolower($artist) === 'unknown artist') return $title;
    return trim($artist . ' ' . $title);
}

function dttd_local_music_score_spotify_candidate($row, array $candidate) {
    [$guessArtist, $guessTitle] = dttd_local_music_guess_artist_title_from_filename((string)($row['relative_path'] ?? ''), (string)($row['file_name'] ?? ''));
    $localTitle = dttd_local_music_plain_text(dttd_local_music_track_display_field($row, 'display_title', 'detected_title', $guessTitle));
    $localArtist = dttd_local_music_plain_text(dttd_local_music_track_display_field($row, 'display_artist', 'detected_artist', $guessArtist));
    $spTitle = dttd_local_music_plain_text($candidate['title'] ?? '');
    $spArtist = dttd_local_music_plain_text($candidate['artist'] ?? '');

    $score = 0;
    if ($localTitle !== '' && $spTitle !== '') {
        if ($localTitle === $spTitle) $score += 46;
        elseif (strpos($spTitle, $localTitle) !== false || strpos($localTitle, $spTitle) !== false) $score += 34;
        else {
            similar_text($localTitle, $spTitle, $pct);
            $score += (int)round(min(28, $pct * 0.28));
        }
    }

    if ($localArtist !== '' && $spArtist !== '') {
        if ($localArtist === $spArtist) $score += 36;
        elseif (strpos($spArtist, $localArtist) !== false || strpos($localArtist, $spArtist) !== false) $score += 26;
        else {
            similar_text($localArtist, $spArtist, $pct);
            $score += (int)round(min(20, $pct * 0.20));
        }
    }

    $localDuration = isset($row['duration_seconds']) ? (int)$row['duration_seconds'] : 0;
    $spotifyDuration = isset($candidate['duration_ms']) ? (int)round(((int)$candidate['duration_ms']) / 1000) : 0;
    if ($localDuration > 0 && $spotifyDuration > 0) {
        $diff = abs($localDuration - $spotifyDuration);
        if ($diff <= 3) $score += 10;
        elseif ($diff <= 8) $score += 7;
        elseif ($diff <= 20) $score += 3;
        elseif ($diff >= 45) $score -= 10;
    }

    $popularity = isset($candidate['popularity']) ? (int)$candidate['popularity'] : 0;
    if ($popularity >= 70) $score += 3;
    elseif ($popularity >= 45) $score += 2;

    return max(0, min(100, $score));
}

function dttd_local_music_update_match_result($trackId, array $fields) {
    $allowed = [
        'spotify_match_uri','spotify_match_url','spotify_match_status','spotify_match_confidence',
        'spotify_match_checked_at','spotify_match_attempts','spotify_match_error','artwork_path','artwork_source',
        'display_album','display_year','duration_seconds','updated_at'
    ];
    $cols = dttd_local_music_table_columns('local_tracks');
    $sets = [];
    $params = [];
    foreach ($fields as $key => $value) {
        if (!in_array($key, $allowed, true)) continue;
        if (!isset($cols[$key])) continue;
        if ($key === 'updated_at' && $value === 'NOW()') {
            $sets[] = "updated_at = NOW()";
            continue;
        }
        if ($key === 'spotify_match_checked_at' && $value === 'NOW()') {
            $sets[] = "spotify_match_checked_at = NOW()";
            continue;
        }
        if ($key === 'spotify_match_attempts' && $value === 'INC') {
            $sets[] = "spotify_match_attempts = COALESCE(spotify_match_attempts, 0) + 1";
            continue;
        }
        $sets[] = "`$key` = ?";
        $params[] = $value;
    }
    if (!$sets) return false;
    $params[] = (int)$trackId;
    $stmt = db()->prepare("UPDATE local_tracks SET " . implode(', ', $sets) . " WHERE id = ?");
    return $stmt->execute($params);
}

function dttd_local_music_process_spotify_match_batch($limit = 10) {
    require_once __DIR__ . '/spotify.php';

    $summary = [
        'ok' => false,
        'processed' => 0,
        'matched' => 0,
        'needs_review' => 0,
        'no_match' => 0,
        'skipped' => 0,
        'failed' => 0,
        'messages' => [],
    ];

    if (!dttd_local_music_table_exists('local_tracks')) {
        $summary['messages'][] = 'local_tracks table is missing.';
        return $summary;
    }
    $missing = dttd_local_music_spotify_match_schema_missing();
    if ($missing) {
        $summary['messages'][] = 'Missing Spotify matching columns: ' . implode(', ', $missing);
        return $summary;
    }
    if (!function_exists('dttd_spotify_config_loaded') || !dttd_spotify_config_loaded()) {
        $summary['messages'][] = 'Spotify API is not configured.';
        return $summary;
    }

    $limit = max(1, min(50, (int)$limit));
    $sql = "SELECT * FROM local_tracks
            WHERE is_enabled = 1
              AND missing_since_at IS NULL
              AND COALESCE(spotify_match_status, 'unchecked') IN ('unchecked','failed')
              AND COALESCE(spotify_match_attempts, 0) < 5
            ORDER BY COALESCE(spotify_match_attempts, 0) ASC, updated_at ASC, id ASC
            LIMIT " . $limit;

    try {
        $tracks = db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        $summary['messages'][] = 'Unable to load local tracks for matching.';
        return $summary;
    }

    foreach ($tracks as $row) {
        $trackId = (int)($row['id'] ?? 0);
        if ($trackId <= 0) continue;
        $summary['processed']++;
        $query = dttd_local_music_match_query_for_row($row);
        if ($query === '' || strlen($query) < 2) {
            dttd_local_music_update_match_result($trackId, [
                'spotify_match_status' => 'skipped',
                'spotify_match_confidence' => '',
                'spotify_match_checked_at' => 'NOW()',
                'spotify_match_attempts' => 'INC',
                'spotify_match_error' => 'Not enough title/artist data to search Spotify.',
                'updated_at' => 'NOW()',
            ]);
            $summary['skipped']++;
            continue;
        }

        dttd_local_music_update_match_result($trackId, ['spotify_match_status' => 'checking', 'updated_at' => 'NOW()']);
        try {
            $candidates = dttd_spotify_search_tracks($query, 5);
            $best = null;
            $bestScore = 0;
            foreach ($candidates as $candidate) {
                $score = dttd_local_music_score_spotify_candidate($row, $candidate);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $candidate;
                }
            }

            if (!$best) {
                dttd_local_music_update_match_result($trackId, [
                    'spotify_match_uri' => '',
                    'spotify_match_url' => '',
                    'spotify_match_status' => 'no_match',
                    'spotify_match_confidence' => 'none',
                    'spotify_match_checked_at' => 'NOW()',
                    'spotify_match_attempts' => 'INC',
                    'spotify_match_error' => '',
                    'updated_at' => 'NOW()',
                ]);
                $summary['no_match']++;
                continue;
            }

            $status = $bestScore >= 80 ? 'matched' : ($bestScore >= 55 ? 'needs_review' : 'no_match');
            $confidence = $bestScore >= 80 ? 'high' : ($bestScore >= 55 ? 'medium' : 'low');
            $fields = [
                'spotify_match_uri' => (string)($best['uri'] ?? ''),
                'spotify_match_url' => (string)($best['url'] ?? ''),
                'spotify_match_status' => $status,
                'spotify_match_confidence' => $confidence,
                'spotify_match_checked_at' => 'NOW()',
                'spotify_match_attempts' => 'INC',
                'spotify_match_error' => '',
                'updated_at' => 'NOW()',
            ];
            if (!empty($best['image'])) {
                $fields['artwork_path'] = (string)$best['image'];
                $fields['artwork_source'] = 'spotify';
            }
            if (!empty($best['album']) && trim((string)($row['display_album'] ?? '')) === '') {
                $fields['display_album'] = (string)$best['album'];
            }
            if (!empty($best['release_date']) && trim((string)($row['display_year'] ?? '')) === '') {
                $fields['display_year'] = substr((string)$best['release_date'], 0, 4);
            }
            if (!empty($best['duration_ms']) && empty($row['duration_seconds'])) {
                $fields['duration_seconds'] = (int)round(((int)$best['duration_ms']) / 1000);
            }
            dttd_local_music_update_match_result($trackId, $fields);
            if ($status === 'matched') $summary['matched']++;
            elseif ($status === 'needs_review') $summary['needs_review']++;
            else $summary['no_match']++;
        } catch (Throwable $e) {
            dttd_local_music_update_match_result($trackId, [
                'spotify_match_status' => 'failed',
                'spotify_match_confidence' => '',
                'spotify_match_checked_at' => 'NOW()',
                'spotify_match_attempts' => 'INC',
                'spotify_match_error' => substr($e->getMessage(), 0, 500),
                'updated_at' => 'NOW()',
            ]);
            $summary['failed']++;
        }
    }

    $summary['ok'] = true;
    return $summary;
}
