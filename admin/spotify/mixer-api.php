<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';
require_once __DIR__ . '/../../includes/track-history.php';

header('Content-Type: application/json; charset=utf-8');

function mx_setting($key, $default = '') {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) { return $default; }
}
function mx_set($key, $value) {
    $stmt = db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, (string)$value]);
}
function mx_json($key, $default = []) {
    $raw = mx_setting($key, '');
    if ($raw === '') return $default;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function mx_spotify_user_get($url) {
    $token = dttd_spotify_user_access_token();
    return dttd_spotify_http_get($url, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ]);
}
function mx_track_from_spotify_item($item, $source = 'spotify_playlist') {
    $artists = [];
    foreach (($item['artists'] ?? []) as $artist) if (!empty($artist['name'])) $artists[] = $artist['name'];
    $images = $item['album']['images'] ?? [];
    $image = '';
    if ($images) { $last = end($images); $image = $last['url'] ?? ($images[0]['url'] ?? ''); }
    return mx_clean_track([
        'id' => $item['id'] ?? '',
        'title' => $item['name'] ?? '',
        'artist' => implode(', ', $artists),
        'album' => $item['album']['name'] ?? '',
        'image' => $image,
        'url' => $item['external_urls']['spotify'] ?? '',
        'duration_ms' => $item['duration_ms'] ?? null,
        'source' => $source,
    ]);
}
function mx_spotify_playlists() {
    $data = mx_spotify_user_get('https://api.spotify.com/v1/me/playlists?limit=40');
    $out = [];
    foreach (($data['items'] ?? []) as $p) {
        $images = $p['images'] ?? [];
        $image = '';
        if ($images) { $last = end($images); $image = $last['url'] ?? ($images[0]['url'] ?? ''); }
        $out[] = [
            'id' => (string)($p['id'] ?? ''),
            'name' => (string)($p['name'] ?? 'Spotify playlist'),
            'description' => strip_tags((string)($p['description'] ?? '')),
            'image' => $image,
            'tracks_total' => (int)($p['tracks']['total'] ?? 0),
            'owner' => (string)($p['owner']['display_name'] ?? ''),
        ];
    }
    return $out;
}
function mx_spotify_playlist_tracks($playlist_id) {
    $playlist_id = trim((string)$playlist_id);
    if ($playlist_id === '') throw new RuntimeException('No Spotify playlist selected.');
    $url = 'https://api.spotify.com/v1/playlists/' . rawurlencode($playlist_id) . '/tracks?limit=50&fields=items(track(id,name,artists(name),album(name,images),external_urls,duration_ms))';
    $data = mx_spotify_user_get($url);
    $out = [];
    foreach (($data['items'] ?? []) as $row) {
        $track = $row['track'] ?? null;
        if (!is_array($track) || empty($track['id'])) continue;
        $out[] = mx_track_output(mx_track_from_spotify_item($track, 'spotify_playlist'));
    }
    return $out;
}
function mx_played_request_history_rows($eventId, $cutoff) {
    if ($eventId <= 0) return [];
    if (!mx_table_exists('song_requests')) return [];
    $select = ['id', 'song_title', 'artist', 'created_at'];
    foreach (['spotify_track_id','spotify_track_url','spotify_album_image','updated_at','request_group_id','guest_name','dedication','message','event_id','spotify_duration_ms'] as $column) {
        if (mx_has_column('song_requests', $column)) $select[] = $column;
    }
    $selectSql = implode(', ', array_map(function($column){ return '`' . str_replace('`', '', $column) . '`'; }, array_unique($select)));
    $dateExpr = mx_has_column('song_requests', 'updated_at') ? 'COALESCE(updated_at, created_at)' : 'created_at';
    try {
        $stmt = db()->prepare("SELECT {$selectSql}, {$dateExpr} AS played_at_calc FROM song_requests WHERE event_id = ? AND status = 'played' AND {$dateExpr} >= FROM_UNIXTIME(?) ORDER BY {$dateExpr} DESC, id DESC LIMIT 120");
        $stmt->execute([(int)$eventId, (int)$cutoff]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $ignored) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $trackId = trim((string)($row['spotify_track_id'] ?? ''));
        if ($trackId === '') continue;
        $msg = trim((string)($row['dedication'] ?? ''));
        if ($msg === '') $msg = trim((string)($row['message'] ?? ''));
        $out[] = [
            'id' => $trackId,
            'title' => (string)($row['song_title'] ?? ''),
            'artist' => (string)($row['artist'] ?? ''),
            'album' => '',
            'image' => (string)($row['spotify_album_image'] ?? ''),
            'url' => (string)($row['spotify_track_url'] ?? ''),
            'duration_ms' => isset($row['spotify_duration_ms']) ? (int)$row['spotify_duration_ms'] : null,
            'source' => 'public_request',
            'request_id' => (int)($row['id'] ?? 0),
            'request_group_id' => (string)($row['request_group_id'] ?? ''),
            'event_id' => (int)$eventId,
            'guest_name' => (string)($row['guest_name'] ?? ''),
            'message' => $msg,
            'played_at' => (string)($row['played_at_calc'] ?? ($row['updated_at'] ?? $row['created_at'] ?? date('Y-m-d H:i:s'))),
            'history_deck' => '',
        ];
    }
    return $out;
}


function mx_event_track_history_rows($eventId, $cutoff) {
    if ($eventId <= 0 || !function_exists('dttd_history_track_rows')) return [];
    $rows = dttd_history_track_rows((int)$eventId, 120, true, max(0, time() - (int)$cutoff));
    $out = [];
    foreach ($rows as $row) {
        $spotifyId = (string)($row['spotify_track_id'] ?? '');
        $out[] = [
            'id' => $spotifyId,
            'title' => (string)($row['track_name'] ?? ''),
            'artist' => (string)($row['artist_name'] ?? ''),
            'album' => (string)($row['album_name'] ?? ''),
            'image' => (string)($row['artwork_url'] ?? ''),
            'url' => $spotifyId !== '' ? 'https://open.spotify.com/track/' . $spotifyId : '',
            'duration_ms' => isset($row['duration_ms']) ? (int)$row['duration_ms'] : null,
            'source' => (string)($row['source_type'] ?? 'event_track_history'),
            'request_id' => !empty($row['request_id']) ? (int)$row['request_id'] : null,
            'event_id' => (int)$eventId,
            'played_at' => (string)($row['played_at'] ?? date('Y-m-d H:i:s')),
            'history_deck' => (string)($row['deck'] ?? ''),
        ];
    }
    return $out;
}

function mx_history() {
    $history = mx_json('spotify_mixer_history', []);
    $eventId = mx_current_event_id();
    $cutoff = time() - 86400;
    $candidateRows = [];

    foreach ((array)$history as $row) {
        if (!is_array($row)) continue;

        $playedAtRaw = (string)($row['played_at'] ?? '');
        $playedAt = $playedAtRaw !== '' ? strtotime($playedAtRaw) : false;
        if (!$playedAt || $playedAt < $cutoff) continue;

        $rowEventId = isset($row['event_id']) ? (int)$row['event_id'] : 0;
        if ($eventId > 0) {
            if ($rowEventId !== $eventId) continue;
        } elseif ($rowEventId > 0) {
            continue;
        }
        $candidateRows[] = $row;
    }

    if ($eventId > 0) {
        $candidateRows = array_merge($candidateRows, mx_event_track_history_rows($eventId, $cutoff));
        $candidateRows = array_merge($candidateRows, mx_played_request_history_rows($eventId, $cutoff));
    }

    usort($candidateRows, function($a, $b) {
        return strtotime((string)($b['played_at'] ?? '')) <=> strtotime((string)($a['played_at'] ?? ''));
    });

    $filtered = [];
    $seen = [];
    foreach ($candidateRows as $row) {
        $trackId = trim((string)($row['id'] ?? ''));
        $url = trim((string)($row['url'] ?? ''));
        $titleArtist = strtolower(trim((string)($row['title'] ?? '')) . '|' . trim((string)($row['artist'] ?? '')));
        $key = $trackId !== '' ? ('id:' . $trackId) : ($url !== '' ? ('url:' . $url) : ('text:' . $titleArtist));
        if ($key === 'text:|') continue;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $filtered[] = $row;
        if (count($filtered) >= 80) break;
    }

    return array_values(array_map('mx_track_output', $filtered));
}

function mx_db_crates_available() {
    return mx_table_exists('dj_crates') && mx_table_exists('dj_crate_tracks');
}

function mx_legacy_crates() {
    $crates = mx_json('spotify_mixer_crates', []);
    if (!$crates) {
        $crates = [
            ['id' => 'crate_80s', 'name' => '80s', 'tracks' => []],
            ['id' => 'crate_90s', 'name' => '90s', 'tracks' => []],
            ['id' => 'crate_floorfillers', 'name' => 'Floorfillers', 'tracks' => []],
        ];
        mx_save_legacy_crates($crates);
    }
    return array_values(array_map('mx_normalise_crate', (array)$crates));
}

function mx_crates() {
    if (mx_db_crates_available()) {
        mx_seed_mysql_crates_if_empty();
        $rows = mx_mysql_crates();
        if ($rows) return $rows;
    }
    return mx_legacy_crates();
}

function mx_normalise_crate($crate) {
    $id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($crate['id'] ?? ''));
    if ($id === '') $id = 'crate_' . bin2hex(random_bytes(4));
    $name = trim((string)($crate['name'] ?? 'DJ Crate'));
    $tracks = [];
    foreach ((array)($crate['tracks'] ?? []) as $track) {
        $clean = mx_clean_track($track);
        if ($clean['id'] !== '') $tracks[] = $clean;
        if (count($tracks) >= 200) break;
    }
    return ['id' => $id, 'name' => $name !== '' ? $name : 'DJ Crate', 'tracks' => $tracks];
}

function mx_save_legacy_crates($crates) {
    mx_set('spotify_mixer_crates', json_encode(array_values(array_map('mx_normalise_crate', (array)$crates))));
}

function mx_save_crates($crates) {
    // Legacy compatibility wrapper. New crate writes go to MySQL first.
    mx_save_legacy_crates($crates);
}

function mx_find_crate_index($crates, $crate_id) {
    foreach ((array)$crates as $idx => $crate) {
        if ((string)($crate['id'] ?? '') === (string)$crate_id) return $idx;
    }
    return -1;
}

function mx_seed_mysql_crates_if_empty() {
    if (!mx_db_crates_available()) return;
    try {
        $count = (int)db()->query("SELECT COUNT(*) FROM dj_crates")->fetchColumn();
        if ($count > 0) return;
    } catch (Throwable $e) {
        return;
    }

    $legacy = mx_json('spotify_mixer_crates', []);
    if (!$legacy) {
        $legacy = [
            ['id' => 'crate_80s', 'name' => '80s', 'tracks' => []],
            ['id' => 'crate_90s', 'name' => '90s', 'tracks' => []],
            ['id' => 'crate_floorfillers', 'name' => 'Floorfillers', 'tracks' => []],
        ];
    }

    $crateInsert = db()->prepare("INSERT INTO dj_crates (name, description, sort_order, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
    $trackInsert = db()->prepare("\n        INSERT INTO dj_crate_tracks\n        (crate_id, spotify_track_id, track_name, artist_name, album_name, artwork_url, duration_ms, preview_url, sort_order, added_at)\n        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n    ");

    $order = 0;
    foreach ((array)$legacy as $crate) {
        $normal = mx_normalise_crate($crate);
        $crateInsert->execute([$normal['name'], 'Imported from legacy mixer crate JSON', $order++]);
        $crateId = (int)db()->lastInsertId();
        $trackOrder = 0;
        foreach ((array)$normal['tracks'] as $track) {
            $clean = mx_clean_track($track);
            if ($clean['id'] === '') continue;
            $trackInsert->execute([
                $crateId,
                $clean['id'],
                $clean['title'],
                $clean['artist'],
                $clean['album'],
                $clean['image'],
                $clean['duration_ms'],
                '',
                $trackOrder++,
                $clean['added_at'] ?: date('Y-m-d H:i:s'),
            ]);
        }
    }
}

function mx_mysql_crates() {
    if (!mx_db_crates_available()) return [];
    try {
        $stmt = db()->query("\n            SELECT c.id, c.name, COUNT(t.id) AS track_count\n            FROM dj_crates c\n            LEFT JOIN dj_crate_tracks t ON t.crate_id = c.id\n            WHERE c.is_active = 1\n            GROUP BY c.id, c.name, c.sort_order, c.created_at\n            ORDER BY LOWER(c.name) ASC, c.name ASC, c.id ASC\n        ");
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'id' => (string)$row['id'],
                'name' => (string)$row['name'],
                'tracks' => [],
                'track_count' => (int)($row['track_count'] ?? 0),
            ];
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

function mx_crate_summaries() {
    if (mx_db_crates_available()) {
        mx_seed_mysql_crates_if_empty();
        $rows = mx_mysql_crates();
        if ($rows) {
            return array_map(function($crate) {
                return [
                    'id' => (string)$crate['id'],
                    'name' => (string)$crate['name'],
                    'track_count' => (int)($crate['track_count'] ?? 0),
                ];
            }, $rows);
        }
    }
    $legacyCrates = mx_legacy_crates();
    usort($legacyCrates, function($a, $b) {
        return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    return array_map(function($crate) {
        return [
            'id' => $crate['id'],
            'name' => $crate['name'],
            'track_count' => count($crate['tracks'] ?? []),
        ];
    }, $legacyCrates);
}

function mx_crate_tracks($crate_id) {
    if (mx_db_crates_available() && ctype_digit((string)$crate_id)) {
        mx_seed_mysql_crates_if_empty();
        $stmt = db()->prepare("\n            SELECT t.*, c.name AS crate_name\n            FROM dj_crate_tracks t\n            INNER JOIN dj_crates c ON c.id = t.crate_id\n            WHERE t.crate_id = ? AND c.is_active = 1\n            ORDER BY t.sort_order ASC, t.added_at DESC, t.id DESC\n            LIMIT 500\n        ");
        $stmt->execute([(int)$crate_id]);
        $tracks = [];
        foreach ($stmt->fetchAll() as $row) {
            $tracks[] = mx_track_output([
                'id' => (string)($row['spotify_track_id'] ?? ''),
                'title' => (string)($row['track_name'] ?? ''),
                'artist' => (string)($row['artist_name'] ?? ''),
                'album' => (string)($row['album_name'] ?? ''),
                'image' => (string)($row['artwork_url'] ?? ''),
                'url' => !empty($row['spotify_track_id']) ? 'https://open.spotify.com/track/' . $row['spotify_track_id'] : '',
                'duration_ms' => isset($row['duration_ms']) ? (int)$row['duration_ms'] : null,
                'source' => 'dj_crate',
                'loaded_origin' => 'dj_crate',
                'crate_id' => (int)$row['crate_id'],
                'crate_track_id' => (int)$row['id'],
                'source_ref_id' => (int)$row['id'],
                'added_at' => (string)($row['added_at'] ?? date('Y-m-d H:i:s')),
            ]);
        }
        return $tracks;
    }

    $crates = mx_legacy_crates();
    $idx = mx_find_crate_index($crates, $crate_id);
    if ($idx < 0) throw new RuntimeException('DJ crate not found.');
    return array_values(array_map('mx_track_output', (array)$crates[$idx]['tracks']));
}

function mx_create_crate($name) {
    $name = trim((string)$name);
    if ($name === '') throw new RuntimeException('Enter a crate name first.');

    if (mx_db_crates_available()) {
        mx_seed_mysql_crates_if_empty();
        $sort = 0;
        try { $sort = (int)db()->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM dj_crates")->fetchColumn(); } catch (Throwable $ignored) {}
        $stmt = db()->prepare("INSERT INTO dj_crates (name, description, sort_order, is_active, created_at) VALUES (?, NULL, ?, 1, NOW())");
        $stmt->execute([$name, $sort]);
        return (string)db()->lastInsertId();
    }

    $crates = mx_legacy_crates();
    $id = 'crate_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
    array_unshift($crates, ['id' => $id, 'name' => $name, 'tracks' => []]);
    mx_save_legacy_crates($crates);
    return $id;
}

function mx_delete_crate($crate_id) {
    if (mx_db_crates_available() && ctype_digit((string)$crate_id)) {
        $stmt = db()->prepare("UPDATE dj_crates SET is_active = 0 WHERE id = ?");
        $stmt->execute([(int)$crate_id]);
        return;
    }
    $crates = mx_legacy_crates();
    $crates = array_values(array_filter($crates, function($c) use ($crate_id) { return (string)($c['id'] ?? '') !== (string)$crate_id; }));
    mx_save_legacy_crates($crates);
}

function mx_add_track_to_crate($crate_id, $track) {
    $clean = mx_clean_track($track);
    if ($clean['id'] === '') throw new RuntimeException('Track is missing a Spotify ID.');

    if (mx_db_crates_available() && ctype_digit((string)$crate_id)) {
        mx_seed_mysql_crates_if_empty();
        $stmt = db()->prepare("SELECT id FROM dj_crates WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([(int)$crate_id]);
        if (!$stmt->fetch()) throw new RuntimeException('Choose a DJ crate first.');

        db()->prepare("DELETE FROM dj_crate_tracks WHERE crate_id = ? AND spotify_track_id = ?")->execute([(int)$crate_id, $clean['id']]);
        db()->prepare("UPDATE dj_crate_tracks SET sort_order = sort_order + 1 WHERE crate_id = ?")->execute([(int)$crate_id]);
        $insert = db()->prepare("\n            INSERT INTO dj_crate_tracks\n            (crate_id, spotify_track_id, track_name, artist_name, album_name, artwork_url, duration_ms, preview_url, sort_order, added_at)\n            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())\n        ");
        $insert->execute([
            (int)$crate_id,
            $clean['id'],
            $clean['title'],
            $clean['artist'],
            $clean['album'],
            $clean['image'],
            $clean['duration_ms'],
            '',
        ]);
        return;
    }

    $crates = mx_legacy_crates();
    $idx = mx_find_crate_index($crates, $crate_id);
    if ($idx < 0) throw new RuntimeException('Choose a DJ crate first.');
    $tracks = array_values(array_filter((array)$crates[$idx]['tracks'], function($t) use ($clean) {
        return (string)($t['id'] ?? '') !== $clean['id'];
    }));
    $clean['source'] = 'dj_crate';
    $clean['added_at'] = date('Y-m-d H:i:s');
    array_unshift($tracks, $clean);
    $crates[$idx]['tracks'] = array_slice($tracks, 0, 200);
    mx_save_legacy_crates($crates);
}

function mx_remove_track_from_crate($crate_id, $track_id) {
    if (mx_db_crates_available() && ctype_digit((string)$crate_id)) {
        if (ctype_digit((string)$track_id)) {
            $stmt = db()->prepare("DELETE FROM dj_crate_tracks WHERE crate_id = ? AND id = ?");
            $stmt->execute([(int)$crate_id, (int)$track_id]);
        } else {
            $stmt = db()->prepare("DELETE FROM dj_crate_tracks WHERE crate_id = ? AND spotify_track_id = ?");
            $stmt->execute([(int)$crate_id, (string)$track_id]);
        }
        return;
    }
    $crates = mx_legacy_crates();
    $idx = mx_find_crate_index($crates, $crate_id);
    if ($idx < 0) throw new RuntimeException('DJ crate not found.');
    $crates[$idx]['tracks'] = array_values(array_filter((array)$crates[$idx]['tracks'], function($t) use ($track_id) { return (string)($t['id'] ?? '') !== (string)$track_id; }));
    mx_save_legacy_crates($crates);
}

function mx_add_history($deck, $track) {
    if (!is_array($track) || empty($track['id'])) return;
    $item = mx_clean_track($track);
    if (empty($item['event_id'])) {
        $eventId = mx_current_event_id();
        if ($eventId > 0) $item['event_id'] = $eventId;
    }
    $item['history_deck'] = strtoupper($deck === 'b' ? 'b' : 'a');
    $item['played_at'] = date('Y-m-d H:i:s');

    if (function_exists('dttd_history_log_track')) {
        dttd_history_log_track([
            'event_id' => !empty($item['event_id']) ? (int)$item['event_id'] : null,
            'deck' => $item['history_deck'],
            'spotify_track_id' => $item['id'],
            'track_name' => $item['title'],
            'artist_name' => $item['artist'],
            'album_name' => $item['album'],
            'artwork_url' => $item['image'],
            'duration_ms' => $item['duration_ms'],
            'played_ms' => mx_loaded_track_progress_ms($track),
            'source_type' => ($item['loaded_origin'] !== '' ? $item['loaded_origin'] : $item['source']),
            'source_ref_id' => !empty($item['source_ref_id']) ? (int)$item['source_ref_id'] : (!empty($item['crate_track_id']) ? (int)$item['crate_track_id'] : (!empty($item['request_id']) ? (int)$item['request_id'] : null)),
            'request_id' => !empty($item['request_id']) ? (int)$item['request_id'] : null,
            'crate_id' => !empty($item['crate_id']) ? (int)$item['crate_id'] : null,
            'threshold_met' => 1,
            'played_at' => $item['played_at'],
        ]);
    }

    $history = mx_json('spotify_mixer_history', []);
    array_unshift($history, $item);
    $seen = [];
    $filtered = [];
    foreach ($history as $h) {
        $key = (string)($h['id'] ?? '') . '|' . (string)($h['played_at'] ?? '');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $filtered[] = $h;
        if (count($filtered) >= 100) break;
    }
    mx_set('spotify_mixer_history', json_encode($filtered));
}

function mx_save_playlist($playlist) {
    mx_set('spotify_mixer_playlist', json_encode(array_values(array_slice((array)$playlist, 0, 80))));
}
function mx_clean_track($track) {
    $source = strtolower(trim((string)($track['source'] ?? $track['source_type'] ?? 'search')));
    $id = (string)($track['id'] ?? '');
    $localTrackId = !empty($track['local_track_id']) ? (int)$track['local_track_id'] : null;
    if ($source === 'local' || strpos($id, 'local:') === 0 || $localTrackId) {
        $source = 'local';
        if (!$localTrackId && preg_match('/^local:(\d+)$/', $id, $m)) $localTrackId = (int)$m[1];
        if ($id === '' && $localTrackId) $id = 'local:' . $localTrackId;
    }

    return [
        'id' => $id,
        'title' => (string)($track['title'] ?? ''),
        'artist' => (string)($track['artist'] ?? ''),
        'album' => (string)($track['album'] ?? ''),
        'image' => (string)($track['image'] ?? ''),
        'url' => (string)($track['url'] ?? ''),
        'duration_ms' => isset($track['duration_ms']) ? (int)$track['duration_ms'] : null,
        'source' => $source !== '' ? $source : 'search',
        'source_label' => (string)($track['source_label'] ?? ''),
        'local_track_id' => $localTrackId,
        'local_path' => (string)($track['local_path'] ?? $track['local_relative_path'] ?? $track['relative_path'] ?? ''),
        'local_relative_path' => (string)($track['local_relative_path'] ?? $track['local_path'] ?? $track['relative_path'] ?? ''),
        'spotify_uri' => (string)($track['spotify_uri'] ?? ''),
        'spotify_url' => (string)($track['spotify_url'] ?? ''),
        'spotify_match_status' => (string)($track['spotify_match_status'] ?? ''),
        'needs_review' => !empty($track['needs_review']),
        'request_id' => !empty($track['request_id']) ? (int)$track['request_id'] : null,
        'request_group_id' => (string)($track['request_group_id'] ?? ''),
        'event_id' => !empty($track['event_id']) ? (int)$track['event_id'] : null,
        'request_count' => isset($track['request_count']) ? (int)$track['request_count'] : null,
        'requesters' => is_array($track['requesters'] ?? null) ? array_values($track['requesters']) : [],
        'request_notes' => is_array($track['request_notes'] ?? null) ? array_values($track['request_notes']) : [],
        'guest_name' => (string)($track['guest_name'] ?? ''),
        'message' => (string)($track['message'] ?? ''),
        'added_at' => (string)($track['added_at'] ?? date('Y-m-d H:i:s')),
        'played_on_deck' => !empty($track['played_on_deck']),
        'played_qualified' => !empty($track['played_qualified']),
        'loaded_origin' => (string)($track['loaded_origin'] ?? ''),
        'position_base_ms' => isset($track['position_base_ms']) ? max(0, (int)$track['position_base_ms']) : null,
        'position_updated_at' => isset($track['position_updated_at']) ? (int)$track['position_updated_at'] : null,
        'paused_position_ms' => isset($track['paused_position_ms']) ? max(0, (int)$track['paused_position_ms']) : null,
        'resume_locked' => !empty($track['resume_locked']),
        'end_seen_ms' => isset($track['end_seen_ms']) ? max(0, (int)$track['end_seen_ms']) : null,
        'end_armed_at' => isset($track['end_armed_at']) ? (int)$track['end_armed_at'] : null,
        'playback_started_at' => isset($track['playback_started_at']) ? (int)$track['playback_started_at'] : null,
        'expected_finish_at' => isset($track['expected_finish_at']) ? (int)$track['expected_finish_at'] : null,
        'local_is_playing' => !empty($track['local_is_playing']),
        'local_is_prepared' => !empty($track['local_is_prepared']),
        'local_prepare_requested_at' => isset($track['local_prepare_requested_at']) ? (int)$track['local_prepare_requested_at'] : null,
        'local_prepare_completed_at' => isset($track['local_prepare_completed_at']) ? (int)$track['local_prepare_completed_at'] : null,
        'local_prepare_command_id' => isset($track['local_prepare_command_id']) ? (int)$track['local_prepare_command_id'] : null,
        'local_prepare_error' => (string)($track['local_prepare_error'] ?? ''),
        'local_playback_mode' => (string)($track['local_playback_mode'] ?? ''),
        'local_autoplay_after_prepare' => !empty($track['local_autoplay_after_prepare']),
        'local_autoplay_requested_at' => isset($track['local_autoplay_requested_at']) ? (int)$track['local_autoplay_requested_at'] : null,
        'history_logged_at' => isset($track['history_logged_at']) ? (int)$track['history_logged_at'] : null,
        'crate_id' => !empty($track['crate_id']) ? (int)$track['crate_id'] : null,
        'crate_track_id' => !empty($track['crate_track_id']) ? (int)$track['crate_track_id'] : null,
        'source_ref_id' => !empty($track['source_ref_id']) ? (int)$track['source_ref_id'] : null,
        'release_year' => isset($track['release_year']) && $track['release_year'] !== null ? (int)$track['release_year'] : null,
        'decade' => (string)($track['decade'] ?? ''),
        'likely_original' => !empty($track['likely_original']),
        'version_tags' => is_array($track['version_tags'] ?? null) ? array_values($track['version_tags']) : [],
        'badges' => mx_normalise_track_badges($track['badges'] ?? []),
    ];
}
function mx_normalise_track_badges($badges) {
    if (!is_array($badges)) return [];
    $out = [];
    $seen = [];
    foreach ($badges as $badge) {
        if (!is_array($badge)) continue;
        $label = trim((string)($badge['label'] ?? ''));
        if ($label === '') continue;
        $type = strtolower(trim((string)($badge['type'] ?? '')));
        if ($type === '') $type = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
        $type = preg_replace('/[^a-z0-9_-]+/', '-', $type);
        $type = trim($type, '-_');
        if ($type === '') $type = 'tag';
        $key = strtolower($type . '|' . $label);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['type' => $type, 'label' => $label];
        if (count($out) >= 8) break;
    }
    return $out;
}
function mx_is_local_track($track) {
    if (!is_array($track)) return false;
    return strtolower((string)($track['source'] ?? '')) === 'local'
        || strpos((string)($track['id'] ?? ''), 'local:') === 0
        || !empty($track['local_track_id'])
        || trim((string)($track['local_path'] ?? $track['local_relative_path'] ?? '')) !== '';
}
function mx_request_select_columns($extra = []) {
    $base = ['id', 'guest_name', 'song_title', 'artist', 'created_at', 'spotify_track_id'];
    foreach (['spotify_track_url', 'spotify_album_image', 'status', 'message', 'dedication', 'spotify_queue_status', 'request_group_id', 'event_id'] as $col) {
        if (mx_has_column('song_requests', $col)) $base[] = $col;
    }
    foreach ((array)$extra as $col) {
        if (mx_has_column('song_requests', $col)) $base[] = $col;
    }
    return array_values(array_unique($base));
}
function mx_select_sql($cols) {
    return implode(', ', array_map(function($c) { return '`' . str_replace('`', '', $c) . '`'; }, $cols));
}
function mx_request_message_from_row($r) {
    if (isset($r['message']) && trim((string)$r['message']) !== '') return (string)$r['message'];
    if (isset($r['dedication']) && trim((string)$r['dedication']) !== '') return (string)$r['dedication'];
    return '';
}
function mx_request_group_rows_from_request($request_id, $request_group_id = '') {
    $request_id = (int)$request_id;
    $request_group_id = trim((string)$request_group_id);
    $cols = mx_request_select_columns();
    $selectSql = mx_select_sql($cols);

    if ($request_group_id !== '' && mx_has_column('song_requests', 'request_group_id')) {
        $stmt = db()->prepare("SELECT {$selectSql} FROM song_requests WHERE request_group_id = ? AND spotify_track_id IS NOT NULL AND spotify_track_id <> '' AND status IN ('pending','maybe','duplicate') ORDER BY created_at ASC, id ASC");
        $stmt->execute([$request_group_id]);
        $rows = $stmt->fetchAll();
        if ($rows) return $rows;
    }

    if ($request_id <= 0) return [];
    $stmt = db()->prepare("SELECT {$selectSql} FROM song_requests WHERE id = ? LIMIT 1");
    $stmt->execute([$request_id]);
    $first = $stmt->fetch();
    if (!$first || empty($first['spotify_track_id'])) return [];

    $groupId = trim((string)($first['request_group_id'] ?? ''));
    if ($groupId !== '' && mx_has_column('song_requests', 'request_group_id')) {
        $stmt = db()->prepare("SELECT {$selectSql} FROM song_requests WHERE request_group_id = ? AND spotify_track_id IS NOT NULL AND spotify_track_id <> '' AND status IN ('pending','maybe','duplicate') ORDER BY created_at ASC, id ASC");
        $stmt->execute([$groupId]);
        $rows = $stmt->fetchAll();
        if ($rows) return $rows;
    }

    return [$first];
}

function mx_track_from_request_group($request_id, $request_group_id = '') {
    $rows = mx_request_group_rows_from_request($request_id, $request_group_id);
    if (!$rows) return null;
    $r = $rows[0];
    if (empty($r['spotify_track_id'])) return null;

    $requesters = [];
    $notes = [];
    foreach ($rows as $row) {
        $name = trim((string)($row['guest_name'] ?? 'Guest'));
        if ($name === '') $name = 'Guest';
        if (!in_array($name, $requesters, true)) $requesters[] = $name;
        $msg = trim(mx_request_message_from_row($row));
        if ($msg !== '') {
            $notes[] = ['guest_name' => $name, 'message' => $msg, 'created_at' => (string)($row['created_at'] ?? '')];
        }
    }

    $messageParts = [];
    foreach (array_slice($notes, 0, 3) as $note) {
        $messageParts[] = $note['guest_name'] . ': ' . $note['message'];
    }
    if (count($notes) > 3) $messageParts[] = '+' . (count($notes) - 3) . ' more dedication' . ((count($notes) - 3) === 1 ? '' : 's');
    $message = implode(' • ', $messageParts);
    $requestCount = count($rows);
    $guestLabel = $requestCount === 1 ? ($requesters[0] ?? 'Guest') : ($requestCount . ' guests');

    return mx_clean_track([
        'id' => $r['spotify_track_id'],
        'title' => $r['song_title'] ?? '',
        'artist' => $r['artist'] ?? '',
        'album' => '',
        'image' => $r['spotify_album_image'] ?? '',
        'url' => $r['spotify_track_url'] ?? '',
        'source' => 'request',
        'request_id' => (int)$r['id'],
        'request_group_id' => (string)($r['request_group_id'] ?? $request_group_id),
        'event_id' => !empty($r['event_id']) ? (int)$r['event_id'] : null,
        'request_count' => $requestCount,
        'requesters' => $requesters,
        'request_notes' => $notes,
        'guest_name' => $guestLabel,
        'message' => $message,
    ]);
}

function mx_track_from_request($request_id) {
    return mx_track_from_request_group($request_id, '');
}

function mx_current_event_id() {
    if (function_exists('dttd_history_current_event_id_with_grace')) {
        return dttd_history_current_event_id_with_grace(3600);
    }
    try {
        if (function_exists('dttd_get_calculated_current_event')) {
            $event = dttd_get_calculated_current_event();
            if (!empty($event['id'])) return (int)$event['id'];
        }
    } catch (Throwable $ignored) {}
    return 0;
}
function mx_has_column($table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) { return $cache[$key] = false; }
}
function mx_table_exists($table) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') return false;
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = db()->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $cache[$table] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function mx_player_node_label($node) {
    $label = trim((string)($node['display_name'] ?? ''));
    if ($label === '') $label = trim((string)($node['spotify_name'] ?? ''));
    if ($label === '') $label = trim((string)($node['hostname'] ?? ''));
    if ($label === '') $label = trim((string)($node['node_key'] ?? ''));
    return $label !== '' ? $label : 'Unnamed node';
}

function mx_player_node_live_status($node) {
    if (empty($node['last_seen'])) return 'offline';
    $seen = strtotime((string)$node['last_seen']);
    if (!$seen) return 'offline';
    $age = time() - $seen;
    if ($age < 45) return 'online';
    if ($age < 90) return 'warning';
    return 'offline';
}

function mx_player_node_last_seen_label($node) {
    if (empty($node['last_seen'])) return 'never';
    $seen = strtotime((string)$node['last_seen']);
    if (!$seen) return (string)$node['last_seen'];
    $age = max(0, time() - $seen);
    if ($age < 60) return $age . ' sec ago';
    if ($age < 3600) return floor($age / 60) . ' min ago';
    if ($age < 86400) return floor($age / 3600) . ' hr ago';
    return date('d M H:i', $seen);
}

function mx_player_node_match_terms($node) {
    $terms = [];
    foreach (['spotify_name', 'display_name', 'hostname', 'node_key'] as $field) {
        $value = strtolower(trim((string)($node[$field] ?? '')));
        if ($value !== '') $terms[] = $value;
    }
    return array_values(array_unique($terms));
}

function mx_device_matches_node($device, $node) {
    $name = strtolower(trim((string)($device['name'] ?? '')));
    if ($name === '') return false;
    foreach (mx_player_node_match_terms($node) as $term) {
        if ($term !== '' && ($name === $term || str_contains($name, $term) || str_contains($term, $name))) {
            return true;
        }
    }
    return false;
}

function mx_player_nodes_for_mixer($devices = []) {
    if (!mx_table_exists('player_nodes')) {
        return ['all' => [], 'deck_a' => null, 'deck_b' => null];
    }

    try {
        $rows = db()->query("
            SELECT *
            FROM player_nodes
            ORDER BY
              CASE UPPER(COALESCE(assigned_deck, ''))
                WHEN 'A' THEN 0
                WHEN 'B' THEN 1
                ELSE 2
              END,
              COALESCE(display_name, spotify_name, hostname, node_key) ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        return ['all' => [], 'deck_a' => null, 'deck_b' => null];
    }

    $out = [];
    $deckA = null;
    $deckB = null;

    foreach ($rows as $row) {
        $matched = null;
        foreach ((array)$devices as $device) {
            if (mx_device_matches_node($device, $row)) {
                $matched = [
                    'id' => (string)($device['id'] ?? ''),
                    'name' => (string)($device['name'] ?? ''),
                    'is_active' => !empty($device['is_active']),
                ];
                break;
            }
        }

        $clean = [
            'node_key' => (string)($row['node_key'] ?? ''),
            'label' => mx_player_node_label($row),
            'display_name' => (string)($row['display_name'] ?? ''),
            'spotify_name' => (string)($row['spotify_name'] ?? ''),
            'hostname' => (string)($row['hostname'] ?? ''),
            'ip_address' => (string)($row['ip_address'] ?? ''),
            'assigned_deck' => strtoupper((string)($row['assigned_deck'] ?? '')),
            'raspotify_running' => !empty($row['raspotify_running']),
            'live_status' => mx_player_node_live_status($row),
            'last_seen_label' => mx_player_node_last_seen_label($row),
            'matched_device' => $matched,
        ];

        if ($clean['assigned_deck'] === 'A' && !$deckA) $deckA = $clean;
        if ($clean['assigned_deck'] === 'B' && !$deckB) $deckB = $clean;
        $out[] = $clean;
    }

    return ['all' => $out, 'deck_a' => $deckA, 'deck_b' => $deckB];
}

function mx_request_group_update_where($request_id, $request_group_id = '') {
    $request_id = (int)$request_id;
    $request_group_id = trim((string)$request_group_id);

    if ($request_group_id !== '' && mx_has_column('song_requests', 'request_group_id')) {
        return ['request_group_id = ?', [$request_group_id]];
    }

    if ($request_id > 0 && mx_has_column('song_requests', 'request_group_id')) {
        try {
            $stmt = db()->prepare("SELECT request_group_id FROM song_requests WHERE id = ? LIMIT 1");
            $stmt->execute([$request_id]);
            $gid = trim((string)$stmt->fetchColumn());
            if ($gid !== '') return ['request_group_id = ?', [$gid]];
        } catch (Throwable $ignored) {}
    }

    return ['id = ?', [$request_id]];
}

function mx_flag_request_group_in_playlist($request_id, $request_group_id = '', $status = 'dj_playlist') {
    if (!$request_id && trim((string)$request_group_id) === '') return;
    try {
        [$where, $whereParams] = mx_request_group_update_where($request_id, $request_group_id);
        $sets = [];
        $params = [];
        if (mx_has_column('song_requests', 'spotify_queued_at')) { $sets[] = 'spotify_queued_at = NOW()'; }
        if (mx_has_column('song_requests', 'spotify_queue_status')) { $sets[] = 'spotify_queue_status = ?'; $params[] = $status; }
        // Moving a public request group into the mixer/DJ playlist is a positive DJ decision.
        // Clear Maybe/Duplicate so both the DJ console and public event board show it as queued.
        if (mx_has_column('song_requests', 'status')) { $sets[] = "status = CASE WHEN status IN ('maybe','duplicate') THEN 'pending' ELSE status END"; }
        if (mx_has_column('song_requests', 'reject_reason')) { $sets[] = 'reject_reason = NULL'; }
        if (!$sets) return;
        $params = array_merge($params, $whereParams);
        $stmt = db()->prepare("UPDATE song_requests SET " . implode(', ', $sets) . " WHERE {$where} AND status IN ('pending','maybe','duplicate')");
        $stmt->execute($params);
    } catch (Throwable $ignored) {}
}

function mx_flag_request_in_playlist($request_id, $status = 'dj_playlist') {
    mx_flag_request_group_in_playlist($request_id, '', $status);
}

function mx_mark_request_played($request_id, $request_group_id = '') {
    if (!$request_id && trim((string)$request_group_id) === '') return;
    try {
        [$where, $whereParams] = mx_request_group_update_where($request_id, $request_group_id);
        $stmt = db()->prepare("UPDATE song_requests SET status = 'played' WHERE {$where}");
        $stmt->execute($whereParams);
    } catch (Throwable $ignored) {}
}

function mx_playback($deck = null) {
    try {
        if ($deck === 'a' || $deck === 'b') return dttd_spotify_current_playback_for_deck($deck);
        return dttd_spotify_current_playback();
    } catch (Throwable $e) { return null; }
}
function mx_decks_share_spotify_profile() {
    return function_exists('dttd_spotify_decks_share_profile') ? dttd_spotify_decks_share_profile() : true;
}
function mx_auto_start_opposite_enabled() {
    // Decks must not auto-start each other when a track naturally ends. A loaded
    // opposite deck may be a standby/cue track, especially in dual-account mode,
    // so end-of-track handling should only log/unload the finished deck. Starting
    // the next deck must remain an explicit DJ action.
    return false;
}
function mx_device_playing($device_id, $playback = null) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') return false;
    if ($playback === null) $playback = mx_playback();
    return !empty($playback['is_playing']) && (string)($playback['device']['id'] ?? '') === $device_id;
}
function mx_spotify_put($url, $body = '', $deck = null) {
    $token = ($deck === 'a' || $deck === 'b') ? dttd_spotify_user_access_token_for_deck($deck) : dttd_spotify_user_access_token();
    return dttd_spotify_http_put($url, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $body);
}
function mx_transfer_playback_to_device($device_id, $play = false, $deck = null) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') throw new RuntimeException('No Spotify device selected for this player.');
    mx_spotify_put('https://api.spotify.com/v1/me/player', json_encode([
        'device_ids' => [$device_id],
        'play' => (bool)$play,
    ]), $deck);
}

function mx_wait_for_active_device($device_id, $timeout_ms = 1800, $deck = null) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') return false;
    $deadline = microtime(true) + (max(250, (int)$timeout_ms) / 1000);
    do {
        try {
            $pb = mx_playback($deck);
            if ((string)($pb['device']['id'] ?? '') === $device_id) return true;
        } catch (Throwable $ignored) {}
        usleep(180000);
    } while (microtime(true) < $deadline);
    return false;
}
function mx_play_track($device_id, $track_id, $position_ms = null, $deck = null) {
    $device_id = trim((string)$device_id);
    $track_id = trim((string)$track_id);
    if ($device_id === '') throw new RuntimeException('No Spotify device selected for this player.');
    if ($track_id === '') throw new RuntimeException('No track loaded on this player.');
    $uri = strpos($track_id, 'spotify:track:') === 0 ? $track_id : 'spotify:track:' . $track_id;
    $wantedId = str_replace('spotify:track:', '', $uri);
    $playUrl = 'https://api.spotify.com/v1/me/player/play?device_id=' . rawurlencode($device_id);
    $position = is_numeric($position_ms) ? max(0, (int)$position_ms) : null;

    // Spotify Connect can briefly restore the account-wide active track on slower tablet clients
    // during handover. Stage the handover before sending the explicit track play command.
    try {
        mx_transfer_playback_to_device($device_id, false, $deck);
        mx_wait_for_active_device($device_id, 1800, $deck);
        // A quiet pause after transfer helps stop flaky clients from audibly resuming the old context.
        try { mx_pause($device_id, $deck); } catch (Throwable $ignoredPause) {}
        usleep(250000);
    } catch (Throwable $ignored) {
        // If transfer fails because the device is already active, still try the direct play below.
        usleep(250000);
    }

    $payload = ['uris' => [$uri]];
    if ($position !== null) $payload['position_ms'] = $position;
    mx_spotify_put($playUrl, json_encode($payload), $deck);

    // Some Android/tablet clients ignore position_ms during Connect handover. Follow with an
    // explicit seek to make pause/resume consistent across Lenovo-style devices.
    if ($position !== null && $position > 0) {
        usleep(250000);
        try { mx_seek($device_id, $position, $deck); } catch (Throwable $ignoredSeek) {}
    }

    // Verify and enforce the intended track after Connect has had time to settle.
    usleep(900000);
    try {
        $pb = mx_playback($deck);
        $activeDevice = (string)($pb['device']['id'] ?? '');
        $isPlaying = !empty($pb['is_playing']);
        $currentId = (string)($pb['item']['id'] ?? '');
        $progress = isset($pb['progress_ms']) ? (int)$pb['progress_ms'] : null;
        $resumeDrifted = ($position !== null && $position > 0 && $progress !== null && $progress < max(0, $position - 2500));
        if ($activeDevice !== $device_id || !$isPlaying || ($currentId !== '' && $wantedId !== '' && $currentId !== $wantedId) || $resumeDrifted) {
            mx_transfer_playback_to_device($device_id, false, $deck);
            mx_wait_for_active_device($device_id, 1200, $deck);
            usleep(200000);
            mx_spotify_put($playUrl, json_encode($payload), $deck);
            if ($position !== null && $position > 0) {
                usleep(250000);
                try { mx_seek($device_id, $position, $deck); } catch (Throwable $ignoredSeek2) {}
            }
        }
    } catch (Throwable $ignored) {}
}

function mx_confirm_track_playing_on_device($device_id, $track_id, $position_ms = null, $max_attempts = 4, $deck = null) {
    $device_id = trim((string)$device_id);
    $track_id = trim((string)$track_id);
    if ($device_id === '' || $track_id === '') return false;
    $wantedId = mx_extract_spotify_id($track_id);
    $position = $position_ms !== null ? max(0, (int)$position_ms) : null;

    for ($attempt = 0; $attempt < max(1, (int)$max_attempts); $attempt++) {
        usleep($attempt === 0 ? 250000 : 550000);
        $pb = mx_playback($deck);
        $activeDevice = (string)($pb['device']['id'] ?? '');
        $currentId = (string)($pb['item']['id'] ?? '');
        $isPlaying = !empty($pb['is_playing']);
        $sameTrack = ($wantedId === '' || $currentId === '' || mx_track_ids_match($currentId, $wantedId));

        if ($activeDevice === $device_id && $isPlaying && $sameTrack) return true;

        // Spotify Connect can briefly accept the transfer but leave the destination paused.
        // Re-assert the explicit track+position play command rather than relying on resume.
        try {
            mx_transfer_playback_to_device($device_id, false, $deck);
            usleep(200000);
            $payload = ['uris' => ['spotify:track:' . $wantedId]];
            if ($position !== null) $payload['position_ms'] = $position;
            mx_spotify_put('https://api.spotify.com/v1/me/player/play?device_id=' . rawurlencode($device_id), json_encode($payload), $deck);
            if ($position !== null) {
                usleep(200000);
                try { mx_seek($device_id, $position, $deck); } catch (Throwable $ignoredSeek) {}
            }
        } catch (Throwable $ignoredRetry) {}
    }
    return false;
}

function mx_seek($device_id, $position_ms, $deck = null) {
    $device_id = trim((string)$device_id);
    $position_ms = max(0, (int)$position_ms);
    if ($device_id === '') throw new RuntimeException('No Spotify device selected for this player.');
    mx_spotify_put('https://api.spotify.com/v1/me/player/seek?device_id=' . rawurlencode($device_id) . '&position_ms=' . $position_ms, '', $deck);
}
function mx_store_loaded_track($deck, $track) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!is_array($track) || empty($track['id'])) {
        mx_set('spotify_mixer_loaded_' . $deck, '');
        return;
    }
    mx_set('spotify_mixer_loaded_' . $deck, json_encode(mx_clean_track($track)));
}
function mx_loaded_position_fallback($track) {
    if (!is_array($track)) return null;
    if (isset($track['paused_position_ms']) && $track['paused_position_ms'] !== null) return max(0, (int)$track['paused_position_ms']);
    if (isset($track['position_base_ms']) && $track['position_base_ms'] !== null) return max(0, (int)$track['position_base_ms']);
    return null;
}

function mx_estimated_loaded_position($loaded) {
    if (!is_array($loaded) || empty($loaded['id'])) return null;
    if (isset($loaded['paused_position_ms']) && $loaded['paused_position_ms'] !== null) return max(0, (int)$loaded['paused_position_ms']);
    if (!isset($loaded['position_base_ms']) || $loaded['position_base_ms'] === null) return null;
    $base = max(0, (int)$loaded['position_base_ms']);
    $updated = isset($loaded['position_updated_at']) ? (int)$loaded['position_updated_at'] : 0;
    if ($updated > 0 && empty($loaded['resume_locked'])) {
        $base += max(0, time() - $updated) * 1000;
    }
    return $base;
}

function mx_played_threshold_ms($duration_ms) {
    $duration_ms = (int)$duration_ms;
    if ($duration_ms <= 0) return 90000;
    return (int)min(max(1, (int)round($duration_ms * 0.50)), 90000);
}
function mx_loaded_track_progress_ms($track) {
    if (!is_array($track)) return 0;
    $progress = 0;
    if (isset($track['end_seen_ms']) && $track['end_seen_ms'] !== null) $progress = max($progress, (int)$track['end_seen_ms']);
    $estimated = mx_estimated_loaded_position($track);
    if ($estimated !== null) $progress = max($progress, (int)$estimated);
    if (isset($track['position_base_ms']) && $track['position_base_ms'] !== null) $progress = max($progress, (int)$track['position_base_ms']);
    if (isset($track['paused_position_ms']) && $track['paused_position_ms'] !== null) $progress = max($progress, (int)$track['paused_position_ms']);
    return max(0, $progress);
}
function mx_track_reached_played_threshold($track) {
    if (!is_array($track) || empty($track['id'])) return false;
    if (!empty($track['played_qualified'])) return true;
    $duration = isset($track['duration_ms']) ? (int)$track['duration_ms'] : 0;
    $threshold = mx_played_threshold_ms($duration);
    return mx_loaded_track_progress_ms($track) >= $threshold;
}
function mx_log_loaded_history_once($deck, &$track) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!is_array($track) || empty($track['id'])) return false;
    if (!empty($track['history_logged_at'])) return false;
    mx_add_history($deck, $track);
    $track['history_logged_at'] = time();
    return true;
}
function mx_mark_loaded_played_if_threshold($deck, &$track) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!is_array($track) || empty($track['id'])) return false;
    if (!mx_track_reached_played_threshold($track)) return false;
    $track['played_qualified'] = true;
    if (!empty($track['request_id'])) mx_mark_request_played((int)$track['request_id'], (string)($track['request_group_id'] ?? ''));
    mx_log_loaded_history_once($deck, $track);
    mx_store_loaded_track($deck, $track);
    return true;
}
function mx_arm_loaded_track_for_playback($deck, &$track, $start_position_ms = 0) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!is_array($track) || empty($track['id'])) return false;
    $now = time();
    $position = max(0, (int)$start_position_ms);
    $duration = isset($track['duration_ms']) ? (int)$track['duration_ms'] : 0;
    $track['played_on_deck'] = true;
    $track['position_base_ms'] = $position;
    $track['position_updated_at'] = $now;
    $track['paused_position_ms'] = null;
    $track['resume_locked'] = false;
    $track['end_seen_ms'] = max((int)($track['end_seen_ms'] ?? 0), $position);
    $track['end_armed_at'] = $now;
    $track['playback_started_at'] = $now;
    if ($duration > 0) {
        $track['expected_finish_at'] = $now + max(1, (int)ceil(max(0, $duration - $position) / 1000)) + 3;
    }
    mx_store_loaded_track($deck, $track);
    return true;
}
function mx_sync_loaded_position_from_playback($deck, $loaded, $device_id, $playback = null) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!is_array($loaded) || empty($loaded['id'])) return $loaded;
    if ($playback === null) $playback = mx_playback();
    $activeDevice = (string)($playback['device']['id'] ?? '');
    $currentId = (string)($playback['item']['id'] ?? '');
    $sameDevice = trim((string)$device_id) !== '' && $activeDevice === (string)$device_id;
    $sameTrack = mx_track_ids_match($currentId, $loaded['id']);
    if ($sameDevice && $sameTrack && isset($playback['progress_ms'])) {
        $loaded['position_base_ms'] = max(0, (int)$playback['progress_ms']);
        $loaded['position_updated_at'] = time();
        if (!empty($playback['is_playing'])) {
            $loaded['paused_position_ms'] = null;
            $loaded['resume_locked'] = false;
            if (isset($playback['item']['duration_ms']) && (int)$playback['item']['duration_ms'] > 0) {
                $loaded['duration_ms'] = (int)$playback['item']['duration_ms'];
            }
            // Arm automatic unload only after this exact loaded track has been observed
            // playing on its assigned device. This prevents transient Spotify Connect
            // handover states from clearing the deck card too early.
            $loaded['end_seen_ms'] = max((int)($loaded['end_seen_ms'] ?? 0), (int)$playback['progress_ms']);
            $loaded['end_armed_at'] = time();
            if (empty($loaded['playback_started_at'])) $loaded['playback_started_at'] = time();
            if (!empty($loaded['duration_ms'])) {
                $loaded['expected_finish_at'] = time() + max(1, (int)ceil(max(0, (int)$loaded['duration_ms'] - (int)$playback['progress_ms']) / 1000)) + 3;
            }
            mx_mark_loaded_played_if_threshold($deck, $loaded);
        }
        mx_store_loaded_track($deck, $loaded);
    }
    return $loaded;
}
function mx_save_resume_position($deck, $device_id, $track) {
    $deck = $deck === 'b' ? 'b' : 'a';
    $device_id = trim((string)$device_id);
    $track_id = (string)($track['id'] ?? '');
    if ($device_id === '' || $track_id === '') {
        mx_set('spotify_mixer_resume_' . $deck, '');
        return;
    }
    $position = null;
    try {
        $pb = mx_playback($deck);
        $activeDevice = (string)($pb['device']['id'] ?? '');
        $currentId = (string)($pb['item']['id'] ?? '');
        if ($activeDevice === $device_id && mx_track_ids_match($currentId, $track_id) && isset($pb['progress_ms'])) {
            $position = max(0, (int)$pb['progress_ms'] - 500);
        }
    } catch (Throwable $ignored) {}

    // Lenovo/Android tablets can lose paused context after another deck takes over.
    // If Spotify does not return a reliable live progress value, fall back to the
    // last progress the mixer stored during polling/playback.
    if ($position === null) {
        $fallback = mx_loaded_position_fallback($track);
        if ($fallback !== null) $position = max(0, (int)$fallback - 500);
    }
    if ($position === null) {
        mx_set('spotify_mixer_resume_' . $deck, '');
        return;
    }

    $track['paused_position_ms'] = $position;
    $track['position_base_ms'] = $position;
    $track['position_updated_at'] = time();
    $track['resume_locked'] = true;
    mx_store_loaded_track($deck, $track);

    mx_set('spotify_mixer_resume_' . $deck, json_encode([
        'track_id' => $track_id,
        'position_ms' => $position,
        'saved_at' => time(),
    ]));
}
function mx_resume_position_for_track($deck, $track_id) {
    $deck = $deck === 'b' ? 'b' : 'a';
    $resume = mx_json('spotify_mixer_resume_' . $deck, []);
    if (!is_array($resume) || (string)($resume['track_id'] ?? '') !== (string)$track_id) return null;
    // Do not reuse stale resume points from an earlier test/session.
    if (!empty($resume['saved_at']) && (time() - (int)$resume['saved_at']) > 7200) return null;
    return isset($resume['position_ms']) ? max(0, (int)$resume['position_ms']) : null;
}
function mx_pause($device_id, $deck = null) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') throw new RuntimeException('No Spotify device selected for this player.');
    mx_spotify_put('https://api.spotify.com/v1/me/player/pause?device_id=' . rawurlencode($device_id), '', $deck);
}

function mx_resume_current_device($device_id, $deck = null) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') throw new RuntimeException('No Spotify device selected for this player.');
    // Lightweight resume: do not send a new track context, transfer, pause or seek.
    // This avoids the audible Connect hiccup caused by the heavier mx_play_track() path.
    mx_spotify_put('https://api.spotify.com/v1/me/player/play?device_id=' . rawurlencode($device_id), '{}', $deck);
}

function mx_can_lightweight_resume($device_id, $track, $playback) {
    $device_id = trim((string)$device_id);
    $track_id = (string)($track['id'] ?? '');
    if ($device_id === '' || $track_id === '' || !is_array($playback)) return false;
    $activeDevice = (string)($playback['device']['id'] ?? '');
    $currentId = (string)($playback['item']['id'] ?? '');
    if ($activeDevice !== $device_id) return false;
    if ($currentId === '') return false;
    return mx_track_ids_match($currentId, $track_id);
}

function mx_other_deck($deck) {
    return $deck === 'b' ? 'a' : 'b';
}

function mx_deck_device_id($deck) {
    $deck = $deck === 'b' ? 'b' : 'a';
    return $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
}

function mx_playback_track_summary($playback) {
    if (!is_array($playback)) return null;
    $item = $playback['item'] ?? [];
    $artists = [];
    foreach (($item['artists'] ?? []) as $artist) {
        if (!empty($artist['name'])) $artists[] = $artist['name'];
    }
    return [
        'device_id' => (string)($playback['device']['id'] ?? ''),
        'device_name' => (string)($playback['device']['name'] ?? ''),
        'is_playing' => !empty($playback['is_playing']),
        'progress_ms' => isset($playback['progress_ms']) ? max(0, (int)$playback['progress_ms']) : null,
        'duration_ms' => isset($item['duration_ms']) ? max(0, (int)$item['duration_ms']) : null,
        'track' => [
            'id' => (string)($item['id'] ?? ''),
            'title' => (string)($item['name'] ?? ''),
            'artist' => implode(', ', $artists),
        ],
        'synced_at_ms' => (int)round(microtime(true) * 1000),
    ];
}

function mx_save_deck_position_from_playback($deck, $device_id, &$track, $playback = null, $lock = true) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!is_array($track) || empty($track['id'])) return null;
    $device_id = trim((string)$device_id);
    $position = null;
    if ($playback === null) $playback = mx_playback($deck);
    if (is_array($playback)) {
        $activeDevice = (string)($playback['device']['id'] ?? '');
        $currentId = (string)($playback['item']['id'] ?? '');
        if ($device_id !== '' && $activeDevice === $device_id && mx_track_ids_match($currentId, $track['id']) && isset($playback['progress_ms'])) {
            $position = max(0, (int)$playback['progress_ms'] - ($lock ? 500 : 0));
            if (isset($playback['item']['duration_ms']) && (int)$playback['item']['duration_ms'] > 0) {
                $track['duration_ms'] = (int)$playback['item']['duration_ms'];
            }
        }
    }
    if ($position === null) {
        $fallback = mx_loaded_position_fallback($track);
        if ($fallback !== null) $position = max(0, (int)$fallback - ($lock ? 500 : 0));
    }
    if ($position === null) return null;

    $track['position_base_ms'] = $position;
    $track['position_updated_at'] = time();
    $track['paused_position_ms'] = $lock ? $position : null;
    $track['resume_locked'] = (bool)$lock;
    $track['end_seen_ms'] = max((int)($track['end_seen_ms'] ?? 0), $position);
    mx_store_loaded_track($deck, $track);

    if ($lock) {
        mx_set('spotify_mixer_resume_' . $deck, json_encode([
            'track_id' => (string)$track['id'],
            'position_ms' => $position,
            'saved_at' => time(),
        ]));
    }
    return $position;
}

function mx_pause_deck_for_controlled_handover($deck) {
    $deck = $deck === 'b' ? 'b' : 'a';
    $device = mx_deck_device_id($deck);
    $track = mx_json('spotify_mixer_loaded_' . $deck, []);
    if (!is_array($track) || empty($track['id'])) return false;

    if (mx_is_local_track($track)) {
        if (!empty($track['local_is_playing'])) {
            try { mx_queue_deck_node_command($deck, 'local_pause', []); } catch (Throwable $ignored) {}
            mx_local_pause_track($deck, $track);
        }
        return true;
    }

    $playback = mx_playback($deck);
    mx_save_deck_position_from_playback($deck, $device, $track, $playback, true);
    if ($device !== '' && mx_device_playing($device, $playback)) {
        try { mx_pause($device, $deck); } catch (Throwable $ignoredPause) {}
    }
    return true;
}

function mx_prepare_single_account_handover($targetDeck) {
    $targetDeck = $targetDeck === 'b' ? 'b' : 'a';
    if (!mx_decks_share_spotify_profile()) return;
    $otherDeck = mx_other_deck($targetDeck);
    mx_pause_deck_for_controlled_handover($otherDeck);
}

function mx_arm_started_track_state($deck, &$track, $position_ms = null, $resumeMode = 'explicit_play') {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!is_array($track) || empty($track['id'])) return;
    $position = $position_ms !== null ? max(0, (int)$position_ms) : mx_loaded_position_fallback($track);
    if ($position === null) $position = 0;
    $track['played_on_deck'] = true;
    $track['position_base_ms'] = $position;
    $track['position_updated_at'] = time();
    $track['paused_position_ms'] = null;
    $track['resume_locked'] = false;
    $track['end_seen_ms'] = max((int)($track['end_seen_ms'] ?? 0), $position);
    $track['end_armed_at'] = time();
    $track['resume_mode'] = $resumeMode;
    if (!empty($track['duration_ms'])) {
        $track['expected_finish_at'] = time() + max(1, (int)ceil(max(0, (int)$track['duration_ms'] - $position) / 1000)) + 3;
    }
    mx_store_loaded_track($deck, $track);
}
function mx_track_output($t) {
    $raw = is_array($t) ? $t : [];
    // Preserve the same search classification metadata used by the public request
    // search. Mixer search rows can then display decade/version pills such as
    // 80s, Original, Remix, Live, etc. without changing the underlying track
    // object used for deck loading.
    if (function_exists('dttd_spotify_enrich_track') && empty($raw['badges']) && !empty($raw['release_date'])) {
        try { $raw = dttd_spotify_enrich_track($raw); } catch (Throwable $ignoredBadgeEnrich) {}
    }
    $t = mx_clean_track($raw);
    if ($t['image'] === '') $t['image'] = 'https://dancethruthedecades.co.uk/assets/glitter-ball-clean.png';
    if (isset($raw['played_at'])) $t['played_at'] = (string)$raw['played_at'];
    if (isset($raw['history_deck'])) $t['history_deck'] = (string)$raw['history_deck'];
    if (isset($raw['history_logged_at'])) $t['history_logged_at'] = (int)$raw['history_logged_at'];
    if (!empty($raw['event_id'])) $t['event_id'] = (int)$raw['event_id'];
    if (!empty($raw['crate_id'])) $t['crate_id'] = (int)$raw['crate_id'];
    if (!empty($raw['crate_track_id'])) $t['crate_track_id'] = (int)$raw['crate_track_id'];
    if (!empty($raw['source_ref_id'])) $t['source_ref_id'] = (int)$raw['source_ref_id'];
    if (mx_is_local_track($t)) {
        $t['source'] = 'local';
        if ($t['source_label'] === '') $t['source_label'] = 'Local';
        if ($t['local_relative_path'] === '' && $t['local_path'] !== '') $t['local_relative_path'] = $t['local_path'];
        if ($t['local_path'] === '' && $t['local_relative_path'] !== '') $t['local_path'] = $t['local_relative_path'];
        if (!is_array($t['badges'] ?? null)) $t['badges'] = [];
        $t['badges'][] = ['type' => 'local', 'label' => 'Local'];
    }
    return $t;
}
function mx_requests($playlist) {
    $alreadyIds = [];
    $alreadyGroups = [];
    foreach ($playlist as $p) {
        if (!empty($p['request_id'])) $alreadyIds[(int)$p['request_id']] = true;
        if (!empty($p['request_group_id'])) $alreadyGroups[(string)$p['request_group_id']] = true;
    }
    try {
        $where = "spotify_track_id IS NOT NULL AND spotify_track_id <> '' AND status IN ('pending','maybe','duplicate')";
        $params = [];
        if (mx_has_column('song_requests', 'spotify_queue_status')) {
            // Full Mixer public feed should only show requests deliberately sent to the mixer.
            // This keeps the normal request page and mixer workflow separate.
            $where .= " AND spotify_queue_status = ?";
            $params[] = 'mixer_request';
        }
        $eventId = mx_current_event_id();
        if ($eventId > 0 && mx_has_column('song_requests', 'event_id')) {
            $where .= " AND event_id = ?";
            $params[] = $eventId;
        }
        $cols = mx_request_select_columns();
        $stmt = db()->prepare("SELECT " . mx_select_sql($cols) . " FROM song_requests WHERE $where ORDER BY created_at ASC, id ASC LIMIT 60");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    $groups = [];
    foreach ($rows as $r) {
        $gid = trim((string)($r['request_group_id'] ?? ''));
        $key = $gid !== '' ? ('gid:' . $gid) : ('track:' . strtolower(trim((string)($r['spotify_track_id'] ?? ''))) . '|' . strtolower(trim((string)($r['song_title'] ?? ''))) . '|' . strtolower(trim((string)($r['artist'] ?? ''))));
        if ($gid !== '' && isset($alreadyGroups[$gid])) continue;
        if ($gid === '' && isset($alreadyIds[(int)$r['id']])) continue;

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'key' => $key,
                'request_group_id' => $gid,
                'rows' => [],
                'first_created_at' => (string)($r['created_at'] ?? ''),
            ];
        }
        $groups[$key]['rows'][] = $r;
        if (!empty($r['created_at']) && (empty($groups[$key]['first_created_at']) || strtotime($r['created_at']) < strtotime($groups[$key]['first_created_at']))) {
            $groups[$key]['first_created_at'] = (string)$r['created_at'];
        }
    }

    $out = [];
    foreach ($groups as $group) {
        $rows = $group['rows'];
        if (!$rows) continue;
        $first = $rows[0];
        $requesters = [];
        $notes = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['guest_name'] ?? 'Guest'));
            if ($name === '') $name = 'Guest';
            if (!in_array($name, $requesters, true)) $requesters[] = $name;
            $msg = trim(mx_request_message_from_row($row));
            if ($msg !== '') $notes[] = ['guest_name' => $name, 'message' => $msg, 'created_at' => (string)($row['created_at'] ?? '')];
        }
        $messageParts = [];
        foreach (array_slice($notes, 0, 3) as $note) $messageParts[] = $note['guest_name'] . ': ' . $note['message'];
        if (count($notes) > 3) $messageParts[] = '+' . (count($notes) - 3) . ' more dedication' . ((count($notes) - 3) === 1 ? '' : 's');
        $count = count($rows);
        $out[] = [
            'id' => (int)$first['id'],
            'request_group_id' => (string)$group['request_group_id'],
            'request_count' => $count,
            'guest_name' => $count === 1 ? (string)($first['guest_name'] ?? 'Guest') : ($count . ' guests'),
            'requesters' => $requesters,
            'request_notes' => $notes,
            'title' => (string)($first['song_title'] ?? ''),
            'artist' => (string)($first['artist'] ?? ''),
            'message' => implode(' • ', $messageParts),
            'image' => (string)($first['spotify_album_image'] ?? ''),
            'created_at' => (string)$group['first_created_at'],
            'status' => (string)($first['status'] ?? 'pending'),
            'queue_status' => (string)($first['spotify_queue_status'] ?? ''),
        ];
    }

    usort($out, function($a, $b) {
        return strtotime((string)($a['created_at'] ?? '')) <=> strtotime((string)($b['created_at'] ?? ''));
    });

    return array_slice($out, 0, 30);
}

function mx_track_ids_match($a, $b) {
    $a = trim((string)$a);
    $b = trim((string)$b);
    if ($a === '' || $b === '') return false;
    $a = str_replace('spotify:track:', '', $a);
    $b = str_replace('spotify:track:', '', $b);
    return $a === $b;
}

function mx_remove_loaded_track_from_playlist($track) {
    if (!is_array($track) || empty($track['id'])) return;
    $playlist = mx_json('spotify_mixer_playlist', []);
    $playlist = array_values(array_filter((array)$playlist, function($p) use ($track) {
        if (!empty($track['request_id']) && !empty($p['request_id'])) return (int)$p['request_id'] !== (int)$track['request_id'];
        return (string)($p['id'] ?? '') !== (string)($track['id'] ?? '');
    }));
    mx_save_playlist($playlist);
}

function mx_start_loaded_deck_after_handover($deck, &$loaded, $device_id) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!is_array($loaded) || empty($loaded['id']) || trim((string)$device_id) === '') return false;
    $position = !empty($loaded['played_on_deck']) ? mx_loaded_position_fallback($loaded) : null;
    mx_play_track($device_id, $loaded['id'], $position, $deck);
    mx_arm_loaded_track_for_playback($deck, $loaded, $position !== null ? (int)$position : 0);
    mx_mark_loaded_played_if_threshold($deck, $loaded);
    mx_remove_loaded_track_from_playlist($loaded);
    return true;
}

function mx_auto_unload_finished_deck($deck, $loaded, $device_id, $playback) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!is_array($loaded) || empty($loaded['id']) || empty($loaded['played_on_deck'])) return $loaded;

    $activeDeviceId = (string)($playback['device']['id'] ?? '');
    $isPlaying = !empty($playback['is_playing']);
    $currentId = (string)($playback['item']['id'] ?? '');
    $progressMs = isset($playback['progress_ms']) ? (int)$playback['progress_ms'] : null;
    $durationMs = isset($playback['item']['duration_ms']) ? (int)$playback['item']['duration_ms'] : (isset($loaded['duration_ms']) ? (int)$loaded['duration_ms'] : null);

    $sameDevice = trim((string)$device_id) !== '' && $activeDeviceId === (string)$device_id;
    $sameTrack = mx_track_ids_match($currentId, $loaded['id']);
    $nearEnd = $durationMs && $progressMs !== null && $progressMs >= max(0, $durationMs - 5000);
    $estimatedMs = mx_estimated_loaded_position($loaded);
    $estimatedEnded = $durationMs && $estimatedMs !== null && $estimatedMs >= max(0, $durationMs - 2500);
    $expectedFinishAt = isset($loaded['expected_finish_at']) ? (int)$loaded['expected_finish_at'] : 0;
    $expectedEnded = $expectedFinishAt > 0 && time() >= $expectedFinishAt;

    $seenMs = isset($loaded['end_seen_ms']) ? (int)$loaded['end_seen_ms'] : 0;
    $armed = !empty($loaded['end_armed_at']) || ($durationMs && $seenMs >= max(0, (int)($durationMs * 0.25)));
    $seenNearEnd = $durationMs && $seenMs >= max(0, $durationMs - 5000);

    // Only unload after this exact loaded track has first been observed playing on its
    // own assigned device, and then has reached the end. This avoids false clears while
    // Spotify Connect briefly reports stale/wrong account playback during handovers.
    $finished = false;
    if ($armed && $sameDevice && $sameTrack && !$isPlaying && $nearEnd) $finished = true;
    if ($armed && $sameTrack && $nearEnd && !$isPlaying) $finished = true;

    // Fallback: the mixer keeps its own progress clock while a deck is playing. This
    // catches Spotify Connect devices that stop reporting cleanly right at track end.
    if ($armed && ($estimatedEnded || $expectedEnded) && empty($loaded['resume_locked'])) $finished = true;

    // If Spotify has moved on to another track on the same device, only treat that as
    // finished when we had already seen the loaded track near its end. A mid-track
    // mismatch is usually a temporary Connect state and must not wipe the deck details.
    if ($armed && $sameDevice && !$sameTrack && $currentId !== '' && $seenNearEnd) $finished = true;

    // Some Spotify Connect devices report no active playback after a track ends. Treat
    // that as finished only when this deck had been started from the mixer and our own
    // progress estimate says it reached the end.
    if ($armed && $activeDeviceId === '' && $currentId === '' && !$isPlaying && $estimatedEnded && empty($loaded['resume_locked'])) $finished = true;

    if ($finished) {
        $loaded['played_qualified'] = true;
        if (!empty($loaded['request_id'])) mx_mark_request_played((int)$loaded['request_id'], (string)($loaded['request_group_id'] ?? ''));
        mx_log_loaded_history_once($deck, $loaded);
        mx_set('spotify_mixer_loaded_' . $deck, '');
        return [];
    }
    return $loaded;
}

function mx_state() {
    $playlist = mx_json('spotify_mixer_playlist', []);
    $loadedA = mx_json('spotify_mixer_loaded_a', []);
    $loadedB = mx_json('spotify_mixer_loaded_b', []);
    $deviceA = mx_setting('spotify_mixer_device_a', '');
    $deviceB = mx_setting('spotify_mixer_device_b', '');
    $devices = [];
    $playbackA = null;
    $playbackB = null;
    if (dttd_spotify_config_loaded()) {
        try {
            $devicesA = dttd_spotify_get_devices_for_deck('a');
            foreach ($devicesA as $d) { $d['deck_account'] = 'A'; $devices[] = $d; }
        } catch (Throwable $ignored) {}
        try {
            $devicesB = dttd_spotify_get_devices_for_deck('b');
            foreach ($devicesB as $d) {
                $exists = false;
                foreach ($devices as $existing) {
                    if ((string)($existing['id'] ?? '') === (string)($d['id'] ?? '')) { $exists = true; break; }
                }
                if (!$exists) { $d['deck_account'] = 'B'; $devices[] = $d; }
            }
        } catch (Throwable $ignored) {}
        $playbackA = mx_playback('a');
        $playbackB = mx_decks_share_spotify_profile() ? $playbackA : mx_playback('b');
    }

    // Keep our own progress memory while a deck is active. Some Spotify Connect
    // tablets lose paused position after another device/deck takes over.
    $loadedA = mx_sync_loaded_position_from_playback('a', $loadedA, $deviceA, $playbackA);
    $loadedB = mx_sync_loaded_position_from_playback('b', $loadedB, $deviceB, $playbackB);

    // For local MPD tracks, Play Now can request playback as soon as the Pi confirms
    // the prepare command. This keeps Load as prepare-only, while Play Now waits for
    // a safe prepared state rather than starting immediately.
    $loadedA = mx_local_maybe_start_autoplay('a', $loadedA);
    $loadedB = mx_local_maybe_start_autoplay('b', $loadedB);

    // Keep deck cards tidy after a played track has finished. This runs during normal
    // mixer polling, so the UI updates without the DJ having to press Clear.
    $beforeUnloadA = $loadedA;
    $beforeUnloadB = $loadedB;
    $loadedA = mx_auto_unload_finished_deck('a', $loadedA, $deviceA, $playbackA);
    $loadedB = mx_auto_unload_finished_deck('b', $loadedB, $deviceB, $playbackB);
    $aFinished = !empty($beforeUnloadA['id']) && empty($loadedA['id']);
    $bFinished = !empty($beforeUnloadB['id']) && empty($loadedB['id']);

    // End-of-track is deliberately non-interactive: the finished deck is logged and
    // unloaded above, but the opposite loaded deck is not started automatically.
    // This avoids a standby/cue track unexpectedly taking over playback.

    $activeDeviceIdA = (string)($playbackA['device']['id'] ?? '');
    $activeDeviceIdB = (string)($playbackB['device']['id'] ?? '');
    $isPlayingA = !empty($playbackA['is_playing']);
    $isPlayingB = !empty($playbackB['is_playing']);
    $playbackSummaryA = mx_playback_track_summary($playbackA);
    $playbackSummaryB = mx_playback_track_summary($playbackB);
    $statusPlayback = $isPlayingA ? $playbackA : ($isPlayingB ? $playbackB : ($playbackA ?: $playbackB));
    $activeDeviceId = (string)($statusPlayback['device']['id'] ?? '');
    $isPlaying = !empty($statusPlayback['is_playing']);
    $item = $statusPlayback['item'] ?? [];
    $artists = [];
    foreach (($item['artists'] ?? []) as $artist) if (!empty($artist['name'])) $artists[] = $artist['name'];
    $images = $item['album']['images'] ?? [];
    $image = '';
    if ($images) { $last = end($images); $image = $last['url'] ?? ($images[0]['url'] ?? ''); }
    $cleanDevices = array_values(array_map(function($d){ return [
        'id' => (string)($d['id'] ?? ''),
        'name' => (string)($d['name'] ?? 'Spotify device'),
        'type' => (string)($d['type'] ?? ''),
        'is_active' => !empty($d['is_active']),
        'deck_account' => (string)($d['deck_account'] ?? '')
    ]; }, $devices));
    $playerNodes = mx_player_nodes_for_mixer($cleanDevices);
    return [
        'configured' => dttd_spotify_config_loaded(),
        'connected' => dttd_spotify_queue_connected_for_deck('a') || dttd_spotify_queue_connected_for_deck('b'),
        'duo_mode' => !mx_decks_share_spotify_profile(),
        'auto_start_opposite' => mx_auto_start_opposite_enabled(),
        'server_time' => date('H:i:s'),
        'server_unix_ms' => (int)round(microtime(true) * 1000),
        'accounts' => [
            'deck_a' => function_exists('dttd_spotify_profile_summary_for_deck') ? dttd_spotify_profile_summary_for_deck('a') : null,
            'deck_b' => function_exists('dttd_spotify_profile_summary_for_deck') ? dttd_spotify_profile_summary_for_deck('b') : null,
            'public_search' => function_exists('dttd_spotify_profile_summary_for_public_search') ? dttd_spotify_profile_summary_for_public_search() : null,
        ],
        'devices' => $cleanDevices,
        'player_nodes' => $playerNodes['all'],
        'deck_nodes' => [
            'a' => $playerNodes['deck_a'],
            'b' => $playerNodes['deck_b'],
        ],
        'device_a' => $deviceA,
        'device_b' => $deviceB,
        'player_a' => [
            'state' => (!empty($loadedA['local_is_playing']) || ($activeDeviceIdA && $activeDeviceIdA === $deviceA && $isPlayingA)) ? 'playing' : 'standby',
            'loaded' => mx_track_output($loadedA),
            'playback' => $playbackSummaryA,
        ],
        'player_b' => [
            'state' => (!empty($loadedB['local_is_playing']) || ($activeDeviceIdB && $activeDeviceIdB === $deviceB && $isPlayingB)) ? 'playing' : 'standby',
            'loaded' => mx_track_output($loadedB),
            'playback' => $playbackSummaryB,
        ],
        'active_device_id' => $activeDeviceId,
        'active_device_name' => (string)($statusPlayback['device']['name'] ?? ''),
        'is_playing' => $isPlaying,
        'track' => ['id' => (string)($item['id'] ?? ''), 'title' => (string)($item['name'] ?? ''), 'artist' => implode(', ', $artists), 'image' => $image, 'progress_ms' => $statusPlayback['progress_ms'] ?? null, 'duration_ms' => $item['duration_ms'] ?? null],
        'playlist' => array_values(array_map('mx_track_output', $playlist)),
        'requests' => mx_requests($playlist),
        'history' => mx_history(),
        'crates' => mx_crate_summaries(),
    ];
}
function mx_deck_has_loaded($deck) {
    $deck = $deck === 'b' ? 'b' : 'a';
    $loaded = mx_json('spotify_mixer_loaded_' . $deck, []);
    return is_array($loaded) && !empty($loaded['id']);
}
function mx_load_track_to_deck($track, $deck, $playback = null, &$playlist = null, $removeFromPlaylist = false, $loadedOrigin = '') {
    $deck = $deck === 'b' ? 'b' : 'a';
    $deviceA = mx_setting('spotify_mixer_device_a', '');
    $deviceB = mx_setting('spotify_mixer_device_b', '');
    $device = $deck === 'b' ? $deviceB : $deviceA;
    if (!$device) throw new RuntimeException('Player ' . strtoupper($deck) . ' has no assigned Spotify device.');
    if (mx_device_playing($device, $playback)) throw new RuntimeException('Player ' . strtoupper($deck) . ' is currently playing. Loading is blocked.');
    $clean = mx_clean_track($track);
    $clean['loaded_origin'] = $loadedOrigin !== '' ? (string)$loadedOrigin : ($removeFromPlaylist ? 'dj_playlist' : (string)($clean['source'] ?? 'search'));
    if (is_array($playlist)) {
        mx_return_loaded_if_unplayed($deck, $playlist, $clean);
        if ($removeFromPlaylist) $playlist = mx_remove_track_from_playlist($playlist, $clean);
        mx_save_playlist($playlist);
    }
    $clean['played_on_deck'] = false;
    $clean['played_qualified'] = false;
    $clean['position_base_ms'] = 0;
    $clean['position_updated_at'] = null;
    $clean['paused_position_ms'] = null;
    $clean['resume_locked'] = false;
    $clean['end_seen_ms'] = null;
    $clean['end_armed_at'] = null;
    if (mx_is_local_track($clean)) {
        // Loading a local track is prepare-only. Reset any stale runtime flags that
        // may have come from history/crates so a Load action cannot auto-start MPD.
        $clean['local_is_playing'] = false;
        $clean['local_is_prepared'] = false;
        $clean['local_prepare_requested_at'] = null;
        $clean['local_prepare_completed_at'] = null;
        $clean['local_prepare_command_id'] = null;
        $clean['local_prepare_error'] = '';
        $clean['local_autoplay_after_prepare'] = false;
        $clean['local_autoplay_requested_at'] = null;
    }
    mx_store_loaded_track($deck, $clean);
    if (mx_is_local_track($clean)) {
        try {
            $prepared = mx_json('spotify_mixer_loaded_' . $deck, $clean);
            mx_local_prepare_track($deck, is_array($prepared) && !empty($prepared) ? $prepared : $clean);
        } catch (Throwable $prepareError) {
            // Keep loading usable even if the node is temporarily unavailable.
            // The later Play command can still attempt a full local_play fallback.
            $clean['local_prepare_error'] = $prepareError->getMessage();
            mx_store_loaded_track($deck, $clean);
        }
    }
    return $deck;
}


function mx_deck_node($deck) {
    $deck = strtoupper($deck === 'b' ? 'b' : 'a');
    if (!mx_table_exists('player_nodes')) return null;
    try {
        $stmt = db()->prepare("SELECT * FROM player_nodes WHERE UPPER(COALESCE(assigned_deck, '')) = ? ORDER BY last_seen DESC LIMIT 1");
        $stmt->execute([$deck]);
        $node = $stmt->fetch();
        return $node ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function mx_queue_deck_node_command($deck, $command, $payload = []) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!mx_table_exists('player_nodes') || !mx_table_exists('node_commands')) {
        throw new RuntimeException('Player node command tables are not available.');
    }
    $node = mx_deck_node($deck);
    if (!$node || empty($node['node_key'])) {
        throw new RuntimeException('No Raspberry Pi node is assigned to Player ' . strtoupper($deck) . '.');
    }
    $payload = is_array($payload) ? $payload : [];
    $payload['deck'] = strtoupper($deck);
    $payload['created_by'] = 'spotify_mixer';
    $payload['created_at'] = date('c');
    $stmt = db()->prepare("INSERT INTO node_commands (node_key, command, payload, status) VALUES (?, ?, ?, 'pending')");
    $stmt->execute([(string)$node['node_key'], (string)$command, json_encode($payload)]);
    return (int)db()->lastInsertId();
}

function mx_local_relative_path($track) {
    $path = trim((string)($track['local_relative_path'] ?? $track['local_path'] ?? ''));
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false) {
        throw new RuntimeException('Local track path is missing or invalid.');
    }
    return $path;
}

function mx_local_pause_track($deck, $track) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!is_array($track) || empty($track['id'])) return $track;
    $base = isset($track['position_base_ms']) ? max(0, (int)$track['position_base_ms']) : 0;
    if (!empty($track['local_is_playing']) && !empty($track['position_updated_at'])) {
        $elapsed = max(0, (time() - (int)$track['position_updated_at']) * 1000);
        $base += $elapsed;
        $duration = isset($track['duration_ms']) ? max(0, (int)$track['duration_ms']) : 0;
        if ($duration > 0) $base = min($base, $duration);
    }
    $track['position_base_ms'] = $base;
    $track['paused_position_ms'] = $base;
    $track['position_updated_at'] = null;
    $track['local_is_playing'] = false;
    mx_store_loaded_track($deck, $track);
    return $track;
}



function mx_local_prepare_track($deck, $track) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!mx_is_local_track($track)) return $track;
    $path = mx_local_relative_path($track);

    // Queue a background MPD prepare as soon as the track is loaded to the deck.
    // This gives the Pi time to clear/add/buffer the network file before the DJ
    // presses Play, reducing the audible start delay from Samba/MPD buffering.
    $commandId = mx_queue_deck_node_command($deck, 'local_prepare', [
        'relative_path' => $path,
        'position_ms' => (int)($track['paused_position_ms'] ?? $track['position_base_ms'] ?? 0),
        'title' => (string)($track['title'] ?? ''),
        'artist' => (string)($track['artist'] ?? ''),
    ]);

    $track['local_is_prepared'] = false;
    $track['local_prepare_requested_at'] = time();
    $track['local_prepare_completed_at'] = null;
    $track['local_prepare_command_id'] = $commandId;
    $track['local_prepare_error'] = '';
    $track['local_playback_mode'] = 'mpd';
    if (!array_key_exists('local_autoplay_after_prepare', $track)) $track['local_autoplay_after_prepare'] = false;
    mx_store_loaded_track($deck, $track);
    return $track;
}

function mx_local_prepare_pending($track) {
    if (!is_array($track) || !mx_is_local_track($track)) return false;
    if (!empty($track['local_is_prepared']) || !empty($track['local_prepare_error'])) return false;
    if (empty($track['local_prepare_requested_at'])) return false;
    $age = time() - (int)$track['local_prepare_requested_at'];
    return $age >= 0 && $age < 60;
}

function mx_local_request_autoplay_after_prepare($deck, $track) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!mx_is_local_track($track)) throw new RuntimeException('Loaded track is not a local track.');

    $loaded = mx_json('spotify_mixer_loaded_' . $deck, []);
    if (is_array($loaded) && !empty($loaded['id'])) $track = $loaded;

    if (!empty($track['local_is_playing'])) return $track;

    if (!empty($track['local_is_prepared'])) {
        return mx_local_play_track($deck, $track, null);
    }

    if (!mx_local_prepare_pending($track)) {
        $track = mx_local_prepare_track($deck, $track);
    }

    $track['local_autoplay_after_prepare'] = true;
    $track['local_autoplay_requested_at'] = time();
    mx_store_loaded_track($deck, $track);
    return $track;
}

function mx_local_maybe_start_autoplay($deck, $track) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!mx_is_local_track($track) || empty($track['id'])) return $track;
    if (empty($track['local_autoplay_after_prepare']) || !empty($track['local_is_playing'])) return $track;
    if (!empty($track['local_prepare_error'])) return $track;

    if (!empty($track['local_is_prepared'])) {
        $track['local_autoplay_after_prepare'] = false;
        $track['local_autoplay_started_at'] = time();
        mx_store_loaded_track($deck, $track);
        return mx_local_play_track($deck, $track, null);
    }

    if (!mx_local_prepare_pending($track)) {
        try {
            $track = mx_local_prepare_track($deck, $track);
            $track['local_autoplay_after_prepare'] = true;
            $track['local_autoplay_requested_at'] = time();
            mx_store_loaded_track($deck, $track);
        } catch (Throwable $prepareError) {
            $track['local_prepare_error'] = $prepareError->getMessage();
            mx_store_loaded_track($deck, $track);
        }
    }

    return $track;
}

function mx_local_play_track($deck, $track, $positionMs = null) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!mx_is_local_track($track)) throw new RuntimeException('Loaded track is not a local track.');
    $path = mx_local_relative_path($track);
    $positionMs = $positionMs === null ? (int)($track['paused_position_ms'] ?? $track['position_base_ms'] ?? 0) : max(0, (int)$positionMs);

    // Stop any Spotify audio on the same physical deck before starting MPD.
    $spotifyDevice = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
    if ($spotifyDevice !== '') {
        try { mx_pause($spotifyDevice, $deck); } catch (Throwable $ignoredSpotifyPause) {}
    }

    mx_queue_deck_node_command($deck, 'local_play', [
        'relative_path' => $path,
        'position_ms' => $positionMs,
        'title' => (string)($track['title'] ?? ''),
        'artist' => (string)($track['artist'] ?? ''),
    ]);

    $track['played_on_deck'] = true;
    $track['local_is_prepared'] = true;
    if (empty($track['local_prepare_completed_at'])) $track['local_prepare_completed_at'] = time();
    $track['local_prepare_error'] = '';
    $track['position_base_ms'] = $positionMs;
    $track['position_updated_at'] = time();
    $track['paused_position_ms'] = null;
    $track['resume_locked'] = false;
    $track['end_seen_ms'] = max(0, $positionMs);
    $track['end_armed_at'] = time();
    $track['local_is_playing'] = true;
    $track['local_autoplay_after_prepare'] = false;
    $track['local_autoplay_started_at'] = time();
    $track['local_playback_mode'] = 'mpd';
    mx_store_loaded_track($deck, $track);
    return $track;
}

function mx_local_stop_track($deck, $track = null, $clearPlaying = true) {
    $deck = $deck === 'b' ? 'b' : 'a';
    mx_queue_deck_node_command($deck, 'local_stop', []);
    if ($clearPlaying && is_array($track) && !empty($track['id'])) {
        $track['local_is_playing'] = false;
        $track['local_autoplay_after_prepare'] = false;
        $track['position_updated_at'] = null;
        mx_store_loaded_track($deck, $track);
    }
}

function mx_local_seek_track($deck, $track, $targetMs) {
    $deck = $deck === 'b' ? 'b' : 'a';
    if (!mx_is_local_track($track)) throw new RuntimeException('Loaded track is not a local track.');
    $path = mx_local_relative_path($track);
    $targetMs = max(0, (int)$targetMs);
    mx_queue_deck_node_command($deck, 'local_seek', [
        'relative_path' => $path,
        'position_ms' => $targetMs,
        'play' => !empty($track['local_is_playing']),
    ]);
    $track['position_base_ms'] = $targetMs;
    $track['paused_position_ms'] = !empty($track['local_is_playing']) ? null : $targetMs;
    $track['position_updated_at'] = !empty($track['local_is_playing']) ? time() : null;
    $track['end_seen_ms'] = $targetMs;
    mx_store_loaded_track($deck, $track);
    return $track;
}

function mx_track_key($track) {
    if (!empty($track['request_group_id'])) return 'request_group:' . (string)$track['request_group_id'];
    if (!empty($track['request_id'])) return 'request:' . (int)$track['request_id'];
    if (mx_is_local_track($track)) {
        if (!empty($track['local_track_id'])) return 'local:' . (int)$track['local_track_id'];
        $path = trim((string)($track['local_relative_path'] ?? $track['local_path'] ?? ''));
        if ($path !== '') return 'local_path:' . $path;
    }
    return 'track:' . (string)($track['id'] ?? '');
}
function mx_playlist_contains_track($playlist, $track) {
    $key = mx_track_key($track);
    if ($key === 'track:' || $key === 'request:0') return false;
    foreach ((array)$playlist as $p) {
        if (mx_track_key($p) === $key) return true;
    }
    return false;
}
function mx_return_loaded_if_unplayed($deck, &$playlist, $newTrack = null) {
    $deck = $deck === 'b' ? 'b' : 'a';
    $loaded = mx_json('spotify_mixer_loaded_' . $deck, []);
    if (!is_array($loaded) || empty($loaded['id'])) return;
    if (mx_track_reached_played_threshold($loaded)) return;
    if (is_array($newTrack) && mx_track_key($loaded) === mx_track_key($newTrack)) return;
    $loaded = mx_clean_track($loaded);
    $origin = (string)($loaded['loaded_origin'] ?? '');

    // Public requests loaded directly from the public feed should return to the
    // public mixer feed if the DJ changes their mind before the played threshold.
    if ($origin === 'public_request' && !empty($loaded['request_id'])) {
        mx_flag_request_in_playlist((int)$loaded['request_id'], 'mixer_request');
        return;
    }

    // Anything else chosen by the DJ but not played should not be forgotten.
    // Tracks from search, crates and history are preserved by adding them to the
    // DJ Playlist; tracks originally from the DJ Playlist are restored there.
    $loaded['added_at'] = date('Y-m-d H:i:s');
    $loaded['loaded_origin'] = 'dj_playlist';
    $loaded['played_on_deck'] = false;
    $loaded['played_qualified'] = false;
    if (!mx_playlist_contains_track($playlist, $loaded)) {
        array_unshift($playlist, $loaded);
    }
    if (!empty($loaded['request_id'])) mx_flag_request_in_playlist((int)$loaded['request_id'], 'dj_playlist');
}
function mx_remove_track_from_playlist($playlist, $track) {
    $key = mx_track_key($track);
    return array_values(array_filter((array)$playlist, function($p) use ($key) {
        return mx_track_key($p) !== $key;
    }));
}


function mx_unload_loaded_as_played($deck) {
    $deck = $deck === 'b' ? 'b' : 'a';
    $loaded = mx_json('spotify_mixer_loaded_' . $deck, []);
    if (!is_array($loaded) || empty($loaded['id'])) throw new RuntimeException('No track loaded on Player ' . strtoupper($deck) . '.');
    $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
    if (mx_is_local_track($loaded)) {
        if (!empty($loaded['local_is_playing'])) throw new RuntimeException('Pause Player ' . strtoupper($deck) . ' before manually marking it played.');
    } elseif (mx_device_playing($device, mx_playback($deck))) throw new RuntimeException('Pause Player ' . strtoupper($deck) . ' before manually marking it played.');
    $loaded['played_qualified'] = true;
    if (!empty($loaded['request_id'])) mx_mark_request_played((int)$loaded['request_id']);
    mx_add_history($deck, $loaded);
    mx_set('spotify_mixer_loaded_' . $deck, '');
}

function mx_json_out($data) { echo json_encode($data); exit; }

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? 'state';
    $playlist = mx_json('spotify_mixer_playlist', []);

    if ($action === 'search') {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $tracks = strlen($q) >= 2 ? dttd_spotify_search_tracks($q, 8) : [];
        mx_json_out(['ok' => true, 'tracks' => array_values(array_map('mx_track_output', $tracks))]);
    }


    if ($action === 'crates') {
        mx_json_out(['ok' => true, 'crates' => mx_crate_summaries()]);
    }
    if ($action === 'crate_tracks') {
        $crateId = (string)($_GET['crate_id'] ?? $_POST['crate_id'] ?? '');
        mx_json_out(['ok' => true, 'tracks' => mx_crate_tracks($crateId)]);
    }
    if ($action === 'create_crate') {
        $name = (string)($_POST['name'] ?? '');
        $crateId = mx_create_crate($name);
        mx_json_out(['ok' => true, 'message' => 'DJ crate created.', 'crate_id' => $crateId, 'state' => mx_state()]);
    }
    if ($action === 'delete_crate') {
        $crateId = (string)($_POST['crate_id'] ?? '');
        mx_delete_crate($crateId);
        mx_json_out(['ok' => true, 'message' => 'DJ crate deleted.', 'state' => mx_state()]);
    }
    if ($action === 'add_crate_track') {
        $crateId = (string)($_POST['crate_id'] ?? '');
        $track = json_decode((string)($_POST['track_json'] ?? ''), true);
        if (!is_array($track)) throw new RuntimeException('Invalid track data.');
        mx_add_track_to_crate($crateId, $track);
        mx_json_out(['ok' => true, 'message' => 'Track saved to DJ crate.', 'state' => mx_state()]);
    }
    if ($action === 'remove_crate_track') {
        $crateId = (string)($_POST['crate_id'] ?? '');
        $trackId = (string)($_POST['track_id'] ?? '');
        mx_remove_track_from_crate($crateId, $trackId);
        mx_json_out(['ok' => true, 'message' => 'Track removed from DJ crate.', 'state' => mx_state()]);
    }
    if ($action === 'spotify_playlists') {
        mx_json_out(['ok' => true, 'playlists' => mx_spotify_playlists()]);
    }

    if ($action === 'spotify_playlist_tracks') {
        $playlistId = (string)($_GET['playlist_id'] ?? $_POST['playlist_id'] ?? '');
        mx_json_out(['ok' => true, 'tracks' => mx_spotify_playlist_tracks($playlistId)]);
    }

    if ($action === 'add_track') {
        $track = json_decode((string)($_POST['track_json'] ?? ''), true);
        if (!is_array($track) || empty($track['id'])) throw new RuntimeException('No valid track selected.');
        $clean = mx_clean_track($track);
        if (mx_playlist_contains_track($playlist, $clean)) throw new RuntimeException('That track is already in the DJ playlist.');
        array_unshift($playlist, $clean);
        mx_save_playlist($playlist);
        mx_json_out(['ok' => true, 'message' => (mx_is_local_track($clean) ? 'Local track added to DJ playlist.' : 'Track added to DJ playlist.'), 'state' => mx_state()]);
    }



    if ($action === 'load_track_direct' || $action === 'play_track_direct') {
        $track = json_decode((string)($_POST['track_json'] ?? ''), true);
        if (!is_array($track) || empty($track['id'])) throw new RuntimeException('No valid track selected.');
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $playback = mx_playback($deck);
        mx_load_track_to_deck($track, $deck, $playback, $playlist, false, (string)($track['source'] ?? 'search'));
        if ($action === 'play_track_direct') {
            if (mx_is_local_track($track)) {
                $playedTrack = mx_json('spotify_mixer_loaded_' . $deck, []);
                mx_local_request_autoplay_after_prepare($deck, $playedTrack ?: mx_clean_track($track));
                mx_json_out(['ok' => true, 'message' => 'Local track loaded to Player ' . strtoupper($deck) . ' and will start after the Raspberry Pi confirms it is prepared.', 'state' => mx_state()]);
            }
            $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
            mx_prepare_single_account_handover($deck);
            mx_play_track($device, $track['id'] ?? '', null, $deck);
            $playedTrack = mx_json('spotify_mixer_loaded_' . $deck, []);
            if (is_array($playedTrack) && !empty($playedTrack['id'])) {
                mx_arm_loaded_track_for_playback($deck, $playedTrack, 0);
            }
            mx_json_out(['ok' => true, 'message' => 'Track loaded and played on Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
        }
        mx_json_out(['ok' => true, 'message' => 'Track loaded to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    if ($action === 'accept_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $requestGroupId = trim((string)($_POST['request_group_id'] ?? ''));
        $track = mx_track_from_request_group($requestId, $requestGroupId);
        if (!$track) throw new RuntimeException('That request has no Spotify track attached.');
        if (mx_playlist_contains_track($playlist, $track)) throw new RuntimeException('That request group is already in the DJ playlist.');
        array_unshift($playlist, $track);
        mx_save_playlist($playlist);
        mx_flag_request_group_in_playlist($requestId, $requestGroupId);
        mx_json_out(['ok' => true, 'message' => 'Request group moved to DJ playlist.', 'state' => mx_state()]);
    }

    if ($action === 'remove_playlist') {
        $idx = (int)($_POST['idx'] ?? -1);
        if (isset($playlist[$idx])) {
            $removed = $playlist[$idx];
            if (!empty($removed['request_id'])) mx_flag_request_in_playlist((int)$removed['request_id'], 'mixer_request');
            unset($playlist[$idx]);
            mx_save_playlist($playlist);
        }
        mx_json_out(['ok' => true, 'state' => mx_state()]);
    }

    if ($action === 'clear_playlist') {
        foreach ($playlist as $p) {
            if (!empty($p['request_id'])) mx_flag_request_in_playlist((int)$p['request_id'], 'mixer_request');
        }
        mx_save_playlist([]);
        mx_json_out(['ok' => true, 'state' => mx_state()]);
    }

    if ($action === 'assign_devices') {
        mx_set('spotify_mixer_device_a', $_POST['device_a'] ?? '');
        mx_set('spotify_mixer_device_b', $_POST['device_b'] ?? '');
        mx_json_out(['ok' => true, 'message' => 'Player devices updated.', 'state' => mx_state()]);
    }

    if ($action === 'load' || $action === 'auto_load') {
        $idx = (int)($_POST['idx'] ?? -1);
        if (!isset($playlist[$idx])) throw new RuntimeException('Playlist item not found.');
        $deviceA = mx_setting('spotify_mixer_device_a', '');
        $deviceB = mx_setting('spotify_mixer_device_b', '');
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        if ($action === 'auto_load') {
            $playbackA = mx_playback('a');
            $playbackB = mx_decks_share_spotify_profile() ? $playbackA : mx_playback('b');
            $aPlaying = mx_device_playing($deviceA, $playbackA);
            $bPlaying = mx_device_playing($deviceB, $playbackB);
            if ($deviceA && !$aPlaying) $deck = 'a';
            elseif ($deviceB && !$bPlaying) $deck = 'b';
            else throw new RuntimeException('No empty standby player found.');
        }
        $playback = mx_playback($deck);
        mx_load_track_to_deck($playlist[$idx], $deck, $playback, $playlist, true, 'dj_playlist');
        mx_json_out(['ok' => true, 'message' => 'Loaded to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    if ($action === 'load_request' || $action === 'auto_load_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $requestGroupId = trim((string)($_POST['request_group_id'] ?? ''));
        $track = mx_track_from_request_group($requestId, $requestGroupId);
        if (!$track) throw new RuntimeException('That request has no Spotify track attached.');
        $deviceA = mx_setting('spotify_mixer_device_a', '');
        $deviceB = mx_setting('spotify_mixer_device_b', '');
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        if ($action === 'auto_load_request') {
            $playbackA = mx_playback('a');
            $playbackB = mx_decks_share_spotify_profile() ? $playbackA : mx_playback('b');
            $aPlaying = mx_device_playing($deviceA, $playbackA);
            $bPlaying = mx_device_playing($deviceB, $playbackB);
            if ($deviceA && !$aPlaying) $deck = 'a';
            elseif ($deviceB && !$bPlaying) $deck = 'b';
            else throw new RuntimeException('No empty standby player found.');
        }
        $playback = mx_playback($deck);
        mx_load_track_to_deck($track, $deck, $playback, $playlist, false, 'public_request');
        mx_flag_request_group_in_playlist($requestId, $requestGroupId, 'loaded_' . $deck);
        mx_json_out(['ok' => true, 'message' => 'Public request group loaded to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }



    if ($action === 'play_request_direct') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $requestGroupId = trim((string)($_POST['request_group_id'] ?? ''));
        $track = mx_track_from_request_group($requestId, $requestGroupId);
        if (!$track) throw new RuntimeException('That request has no Spotify track attached.');
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $playback = mx_playback($deck);
        mx_load_track_to_deck($track, $deck, $playback, $playlist, false, 'public_request');
        mx_flag_request_group_in_playlist($requestId, $requestGroupId, 'loaded_' . $deck);
        $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
        mx_prepare_single_account_handover($deck);
        mx_play_track($device, $track['id'] ?? '', null, $deck);
        $playedTrack = mx_json('spotify_mixer_loaded_' . $deck, []);
        if (is_array($playedTrack) && !empty($playedTrack['id'])) {
            mx_arm_loaded_track_for_playback($deck, $playedTrack, 0);
        }
        mx_json_out(['ok' => true, 'message' => 'Public request group loaded and played on Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    if ($action === 'clear_loaded') {
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
        $loadedForClear = mx_json('spotify_mixer_loaded_' . $deck, []);
        if (mx_is_local_track($loadedForClear)) {
            if (!empty($loadedForClear['local_is_playing'])) throw new RuntimeException('Pause Player ' . strtoupper($deck) . ' before clearing.');
        } elseif (mx_device_playing($device, mx_playback($deck))) throw new RuntimeException('Player ' . strtoupper($deck) . ' is currently playing. Pause it before clearing.');
        mx_return_loaded_if_unplayed($deck, $playlist, null);
        mx_save_playlist($playlist);
        mx_set('spotify_mixer_loaded_' . $deck, '');
        mx_json_out(['ok' => true, 'state' => mx_state()]);
    }


    if ($action === 'return_loaded') {
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
        $loadedForReturn = mx_json('spotify_mixer_loaded_' . $deck, []);
        if (mx_is_local_track($loadedForReturn)) {
            if (!empty($loadedForReturn['local_is_playing'])) throw new RuntimeException('Pause Player ' . strtoupper($deck) . ' before returning it.');
        } elseif (mx_device_playing($device, mx_playback($deck))) throw new RuntimeException('Pause Player ' . strtoupper($deck) . ' before returning it.');
        mx_return_loaded_if_unplayed($deck, $playlist, null);
        mx_save_playlist($playlist);
        mx_set('spotify_mixer_loaded_' . $deck, '');
        mx_json_out(['ok' => true, 'message' => 'Unplayed track returned safely.', 'state' => mx_state()]);
    }

    if ($action === 'mark_loaded_played') {
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        mx_unload_loaded_as_played($deck);
        mx_json_out(['ok' => true, 'message' => 'Track marked as played and unloaded.', 'state' => mx_state()]);
    }

    if ($action === 'play_toggle') {
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
        $track = mx_json('spotify_mixer_loaded_' . $deck, []);
        if (mx_is_local_track($track)) {
            if (!empty($track['local_is_playing'])) {
                mx_queue_deck_node_command($deck, 'local_pause', []);
                mx_local_pause_track($deck, $track);
                mx_json_out(['ok' => true, 'message' => 'Local playback paused on Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
            }
            if (empty($track['local_is_prepared'])) {
                mx_local_request_autoplay_after_prepare($deck, $track);
                $playlist = mx_remove_track_from_playlist($playlist, $track);
                mx_save_playlist($playlist);
                mx_json_out(['ok' => true, 'message' => 'Local track is preparing on Player ' . strtoupper($deck) . ' and will start when ready.', 'state' => mx_state()]);
            }
            mx_local_play_track($deck, $track, null);
            $playlist = mx_remove_track_from_playlist($playlist, $track);
            mx_save_playlist($playlist);
            mx_json_out(['ok' => true, 'message' => 'Local MPD play command sent to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
        }
        $pb = mx_playback($deck);
        if (mx_device_playing($device, $pb)) {
            mx_save_resume_position($deck, $device, $track);
            mx_pause($device, $deck);
            mx_json_out(['ok' => true, 'message' => 'Paused Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
        }
        $resumePosition = mx_resume_position_for_track($deck, $track['id'] ?? '');
        if ($resumePosition === null) $resumePosition = mx_loaded_position_fallback($track);

        mx_prepare_single_account_handover($deck);
        $pb = mx_playback($deck);
        $usedLightweightResume = false;
        if (mx_can_lightweight_resume($device, $track, $pb)) {
            mx_resume_current_device($device, $deck);
            $usedLightweightResume = true;
        } else {
            mx_play_track($device, $track['id'] ?? '', $resumePosition, $deck);
        }

        mx_set('spotify_mixer_resume_' . $deck, '');
        mx_arm_started_track_state($deck, $track, $resumePosition, $usedLightweightResume ? 'native_resume' : 'explicit_play');
        mx_mark_loaded_played_if_threshold($deck, $track);
        $playlist = array_values(array_filter($playlist, function($p) use ($track) {
            if (!empty($track['request_id']) && !empty($p['request_id'])) return (int)$p['request_id'] !== (int)$track['request_id'];
            return (string)($p['id'] ?? '') !== (string)($track['id'] ?? '');
        }));
        mx_save_playlist($playlist);
        mx_json_out(['ok' => true, 'message' => 'Play command sent to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    if ($action === 'seek_relative' || $action === 'seek_start' || $action === 'seek_end') {
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
        $track = mx_json('spotify_mixer_loaded_' . $deck, []);
        if (empty($track['id'])) throw new RuntimeException('No track loaded on Player ' . strtoupper($deck) . '.');
        if (mx_is_local_track($track)) {
            $current = isset($track['position_base_ms']) ? (int)$track['position_base_ms'] : 0;
            if (!empty($track['local_is_playing']) && !empty($track['position_updated_at'])) {
                $current += max(0, (time() - (int)$track['position_updated_at']) * 1000);
            }
            $duration = isset($track['duration_ms']) ? (int)$track['duration_ms'] : 0;
            if ($action === 'seek_start') $target = 0;
            elseif ($action === 'seek_end') $target = max(0, $duration - 2500);
            else $target = max(0, $current + (int)($_POST['delta_ms'] ?? 0));
            if ($duration > 0) $target = min($target, max(0, $duration - 1000));
            mx_local_seek_track($deck, $track, $target);
            mx_json_out(['ok' => true, 'message' => 'Local seek command sent to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
        }
        $pb = mx_playback($deck);
        $current = mx_resume_position_for_track($deck, $track['id'] ?? '');
        if ($current === null) $current = mx_loaded_position_fallback($track);
        if ($current === null) $current = 0;
        $duration = isset($track['duration_ms']) ? (int)$track['duration_ms'] : (int)($pb['item']['duration_ms'] ?? 0);
        if ($action === 'seek_start') $target = 0;
        elseif ($action === 'seek_end') $target = max(0, $duration - 2500);
        else $target = max(0, $current + (int)($_POST['delta_ms'] ?? 0));
        if ($duration > 0) $target = min($target, max(0, $duration - 1000));
        mx_seek($device, $target, $deck);
        $track['position_base_ms'] = $target;
        $track['position_updated_at'] = time();
        $track['paused_position_ms'] = $target;
        mx_store_loaded_track($deck, $track);
        mx_json_out(['ok' => true, 'message' => 'Seek command sent to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    if ($action === 'emergency_swap') {
        $source = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $target = $source === 'a' ? 'b' : 'a';
        $sourceDevice = mx_deck_device_id($source);
        $targetDevice = mx_deck_device_id($target);
        $track = mx_json('spotify_mixer_loaded_' . $source, []);
        if (empty($track['id'])) throw new RuntimeException('No track loaded on Player ' . strtoupper($source) . '.');
        if (mx_is_local_track($track)) throw new RuntimeException('Local emergency swap will be enabled with MPD playback.');
        if (!$targetDevice) throw new RuntimeException('Player ' . strtoupper($target) . ' has no assigned Spotify device.');

        // Emergency swap is a controlled output handover: capture source position,
        // load/play the same track on the other deck, confirm destination playback,
        // then clear the source deck. Do not clear the source if the destination
        // cannot be confirmed.
        $sourcePlayback = mx_playback($source);
        $pos = mx_save_deck_position_from_playback($source, $sourceDevice, $track, $sourcePlayback, true);
        if ($pos === null) $pos = mx_resume_position_for_track($source, $track['id'] ?? '');
        if ($pos === null) $pos = mx_loaded_position_fallback($track);
        if ($pos === null) $pos = 0;

        $targetTrack = $track;
        mx_arm_started_track_state($target, $targetTrack, $pos, 'emergency_swap');
        mx_play_track($targetDevice, $targetTrack['id'] ?? '', $pos, $target);
        $confirmed = mx_confirm_track_playing_on_device($targetDevice, $targetTrack['id'] ?? '', $pos, 4, $target);

        if ($confirmed) {
            if (!mx_decks_share_spotify_profile() && $sourceDevice) {
                try { mx_pause($sourceDevice, $source); } catch (Throwable $ignoredSourcePause) {}
            }
            mx_set('spotify_mixer_loaded_' . $source, '');
            mx_set('spotify_mixer_resume_' . $source, '');
            mx_json_out(['ok' => true, 'message' => 'Emergency transfer completed from Player ' . strtoupper($source) . ' to Player ' . strtoupper($target) . '.', 'state' => mx_state()]);
        }

        mx_json_out(['ok' => false, 'message' => 'Emergency transfer was sent, but Player ' . strtoupper($target) . ' could not be confirmed. Player ' . strtoupper($source) . ' has been left loaded for safety.', 'state' => mx_state()]);
    }

    if ($action === 'play') {
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
        $track = mx_json('spotify_mixer_loaded_' . $deck, []);
        if (mx_is_local_track($track)) {
            if (empty($track['local_is_prepared'])) {
                mx_local_request_autoplay_after_prepare($deck, $track);
                $playlist = mx_remove_track_from_playlist($playlist, $track);
                mx_save_playlist($playlist);
                mx_json_out(['ok' => true, 'message' => 'Local track is preparing on Player ' . strtoupper($deck) . ' and will start when ready.', 'state' => mx_state()]);
            }
            mx_local_play_track($deck, $track, null);
            $playlist = mx_remove_track_from_playlist($playlist, $track);
            mx_save_playlist($playlist);
            mx_json_out(['ok' => true, 'message' => 'Local MPD play command sent to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
        }
        $resumePosition = mx_resume_position_for_track($deck, $track['id'] ?? '');
        if ($resumePosition === null) $resumePosition = mx_loaded_position_fallback($track);
        mx_prepare_single_account_handover($deck);
        mx_play_track($device, $track['id'] ?? '', $resumePosition, $deck);
        mx_set('spotify_mixer_resume_' . $deck, '');
        mx_arm_started_track_state($deck, $track, $resumePosition, 'explicit_play');
        mx_mark_loaded_played_if_threshold($deck, $track);
        // Remove the item from the DJ playlist once sent to play.
        $playlist = array_values(array_filter($playlist, function($p) use ($track) {
            if (!empty($track['request_id']) && !empty($p['request_id'])) return (int)$p['request_id'] !== (int)$track['request_id'];
            return (string)($p['id'] ?? '') !== (string)($track['id'] ?? '');
        }));
        mx_save_playlist($playlist);
        mx_json_out(['ok' => true, 'message' => 'Play command sent to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    if ($action === 'pause') {
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
        $track = mx_json('spotify_mixer_loaded_' . $deck, []);
        if (mx_is_local_track($track)) {
            if (!empty($track['local_is_playing'])) {
                mx_queue_deck_node_command($deck, 'local_pause', []);
                mx_local_pause_track($deck, $track);
            }
            mx_json_out(['ok' => true, 'message' => 'Local pause command sent to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
        }
        mx_save_resume_position($deck, $device, $track);
        mx_pause($device, $deck);
        mx_json_out(['ok' => true, 'message' => 'Pause command sent to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    mx_json_out(['ok' => true, 'state' => mx_state()]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'state' => mx_state()]);
}
