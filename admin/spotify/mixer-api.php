<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

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
function mx_save_playlist($playlist) {
    mx_set('spotify_mixer_playlist', json_encode(array_values(array_slice((array)$playlist, 0, 80))));
}
function mx_clean_track($track) {
    return [
        'id' => (string)($track['id'] ?? ''),
        'title' => (string)($track['title'] ?? ''),
        'artist' => (string)($track['artist'] ?? ''),
        'album' => (string)($track['album'] ?? ''),
        'image' => (string)($track['image'] ?? ''),
        'url' => (string)($track['url'] ?? ''),
        'duration_ms' => isset($track['duration_ms']) ? (int)$track['duration_ms'] : null,
        'source' => (string)($track['source'] ?? 'search'),
        'request_id' => !empty($track['request_id']) ? (int)$track['request_id'] : null,
        'guest_name' => (string)($track['guest_name'] ?? ''),
        'message' => (string)($track['message'] ?? ''),
        'added_at' => (string)($track['added_at'] ?? date('Y-m-d H:i:s')),
        'played_on_deck' => !empty($track['played_on_deck']),
    ];
}
function mx_request_select_columns($extra = []) {
    $base = ['id', 'guest_name', 'song_title', 'artist', 'created_at', 'spotify_track_id'];
    foreach (['spotify_track_url', 'spotify_album_image', 'status', 'message', 'dedication', 'spotify_queue_status'] as $col) {
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
function mx_track_from_request($request_id) {
    $cols = mx_request_select_columns();
    $stmt = db()->prepare("SELECT " . mx_select_sql($cols) . " FROM song_requests WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$request_id]);
    $r = $stmt->fetch();
    if (!$r || empty($r['spotify_track_id'])) return null;
    return mx_clean_track([
        'id' => $r['spotify_track_id'],
        'title' => $r['song_title'] ?? '',
        'artist' => $r['artist'] ?? '',
        'album' => '',
        'image' => $r['spotify_album_image'] ?? '',
        'url' => $r['spotify_track_url'] ?? '',
        'source' => 'request',
        'request_id' => (int)$r['id'],
        'guest_name' => $r['guest_name'] ?? '',
        'message' => mx_request_message_from_row($r),
    ]);
}
function mx_current_event_id() {
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
function mx_flag_request_in_playlist($request_id, $status = 'dj_playlist') {
    if (!$request_id) return;
    try {
        $sets = [];
        $params = [];
        if (mx_has_column('song_requests', 'spotify_queued_at')) { $sets[] = 'spotify_queued_at = NOW()'; }
        if (mx_has_column('song_requests', 'spotify_queue_status')) { $sets[] = 'spotify_queue_status = ?'; $params[] = $status; }
        if (!$sets) return;
        $params[] = (int)$request_id;
        $stmt = db()->prepare("UPDATE song_requests SET " . implode(', ', $sets) . " WHERE id = ?");
        $stmt->execute($params);
    } catch (Throwable $ignored) {}
}
function mx_mark_request_played($request_id) {
    if (!$request_id) return;
    try {
        $stmt = db()->prepare("UPDATE song_requests SET status = 'played' WHERE id = ?");
        $stmt->execute([(int)$request_id]);
    } catch (Throwable $ignored) {}
}
function mx_playback() {
    try { return dttd_spotify_current_playback(); } catch (Throwable $e) { return null; }
}
function mx_device_playing($device_id, $playback = null) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') return false;
    if ($playback === null) $playback = mx_playback();
    return !empty($playback['is_playing']) && (string)($playback['device']['id'] ?? '') === $device_id;
}
function mx_spotify_put($url, $body = '') {
    $token = dttd_spotify_user_access_token();
    return dttd_spotify_http_put($url, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $body);
}
function mx_transfer_playback_to_device($device_id, $play = false) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') throw new RuntimeException('No Spotify device selected for this player.');
    mx_spotify_put('https://api.spotify.com/v1/me/player', json_encode([
        'device_ids' => [$device_id],
        'play' => (bool)$play,
    ]));
}

function mx_wait_for_active_device($device_id, $timeout_ms = 1800) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') return false;
    $deadline = microtime(true) + (max(250, (int)$timeout_ms) / 1000);
    do {
        try {
            $pb = mx_playback();
            if ((string)($pb['device']['id'] ?? '') === $device_id) return true;
        } catch (Throwable $ignored) {}
        usleep(180000);
    } while (microtime(true) < $deadline);
    return false;
}
function mx_play_track($device_id, $track_id) {
    $device_id = trim((string)$device_id);
    $track_id = trim((string)$track_id);
    if ($device_id === '') throw new RuntimeException('No Spotify device selected for this player.');
    if ($track_id === '') throw new RuntimeException('No track loaded on this player.');
    $uri = strpos($track_id, 'spotify:track:') === 0 ? $track_id : 'spotify:track:' . $track_id;
    $wantedId = str_replace('spotify:track:', '', $uri);
    $playUrl = 'https://api.spotify.com/v1/me/player/play?device_id=' . rawurlencode($device_id);

    // Spotify Connect can briefly restore the account-wide active track on slower tablet clients
    // during handover. Stage the handover before sending the explicit track play command.
    try {
        mx_transfer_playback_to_device($device_id, false);
        mx_wait_for_active_device($device_id, 1800);
        // A quiet pause after transfer helps stop flaky clients from audibly resuming the old context.
        try { mx_pause($device_id); } catch (Throwable $ignoredPause) {}
        usleep(250000);
    } catch (Throwable $ignored) {
        // If transfer fails because the device is already active, still try the direct play below.
        usleep(250000);
    }

    mx_spotify_put($playUrl, json_encode(['uris' => [$uri]]));

    // Verify and enforce the intended track after Connect has had time to settle.
    usleep(900000);
    try {
        $pb = mx_playback();
        $activeDevice = (string)($pb['device']['id'] ?? '');
        $isPlaying = !empty($pb['is_playing']);
        $currentId = (string)($pb['item']['id'] ?? '');
        if ($activeDevice !== $device_id || !$isPlaying || ($currentId !== '' && $wantedId !== '' && $currentId !== $wantedId)) {
            mx_transfer_playback_to_device($device_id, false);
            mx_wait_for_active_device($device_id, 1200);
            usleep(200000);
            mx_spotify_put($playUrl, json_encode(['uris' => [$uri]]));
        }
    } catch (Throwable $ignored) {}
}
function mx_pause($device_id) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') throw new RuntimeException('No Spotify device selected for this player.');
    mx_spotify_put('https://api.spotify.com/v1/me/player/pause?device_id=' . rawurlencode($device_id), '');
}
function mx_track_output($t) {
    $t = mx_clean_track($t);
    if ($t['image'] === '') $t['image'] = 'https://dancethruthedecades.co.uk/assets/glitter-ball-clean.png';
    return $t;
}
function mx_requests($playlist) {
    $already = [];
    foreach ($playlist as $p) if (!empty($p['request_id'])) $already[(int)$p['request_id']] = true;
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
        $stmt = db()->prepare("SELECT " . mx_select_sql($cols) . " FROM song_requests WHERE $where ORDER BY created_at ASC, id ASC LIMIT 30");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }
    $out = [];
    foreach ($rows as $r) {
        if (isset($already[(int)$r['id']])) continue;
        $out[] = [
            'id' => (int)$r['id'],
            'guest_name' => (string)($r['guest_name'] ?? 'Guest'),
            'title' => (string)($r['song_title'] ?? ''),
            'artist' => (string)($r['artist'] ?? ''),
            'message' => mx_request_message_from_row($r),
            'image' => (string)($r['spotify_album_image'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
            'status' => (string)($r['status'] ?? 'pending'),
            'queue_status' => (string)($r['spotify_queue_status'] ?? ''),
        ];
    }
    return $out;
}

function mx_track_ids_match($a, $b) {
    $a = trim((string)$a);
    $b = trim((string)$b);
    if ($a === '' || $b === '') return false;
    $a = str_replace('spotify:track:', '', $a);
    $b = str_replace('spotify:track:', '', $b);
    return $a === $b;
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

    // Once a mixer-loaded track has actually been played, clear the deck when Spotify
    // has naturally moved past it or reports it stopped at/near the end. Do not clear
    // a paused mid-track item, because the DJ may simply be cueing or pausing.
    $finished = false;
    if ($sameDevice && !$sameTrack && $currentId !== '') $finished = true;
    if ($sameDevice && $sameTrack && !$isPlaying && $nearEnd) $finished = true;

    // Some Spotify Connect devices report no active playback after a track ends. Treat
    // that as finished only when this deck had been started from the mixer and we no
    // longer have a current Spotify item to compare against.
    if ($activeDeviceId === '' && $currentId === '' && !$isPlaying) $finished = true;

    if ($finished) {
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
    $playback = null;
    if (dttd_spotify_queue_connected()) {
        try { $devices = dttd_spotify_get_devices(); } catch (Throwable $ignored) {}
        $playback = mx_playback();
    }
    $activeDeviceId = (string)($playback['device']['id'] ?? '');
    $isPlaying = !empty($playback['is_playing']);
    $item = $playback['item'] ?? [];

    // Keep deck cards tidy after a played track has finished. This runs during normal
    // mixer polling, so the UI updates without the DJ having to press Clear.
    $loadedA = mx_auto_unload_finished_deck('a', $loadedA, $deviceA, $playback);
    $loadedB = mx_auto_unload_finished_deck('b', $loadedB, $deviceB, $playback);
    $artists = [];
    foreach (($item['artists'] ?? []) as $artist) if (!empty($artist['name'])) $artists[] = $artist['name'];
    $images = $item['album']['images'] ?? [];
    $image = '';
    if ($images) { $last = end($images); $image = $last['url'] ?? ($images[0]['url'] ?? ''); }
    return [
        'configured' => dttd_spotify_config_loaded(),
        'connected' => dttd_spotify_queue_connected(),
        'server_time' => date('H:i:s'),
        'devices' => array_values(array_map(function($d){ return [
            'id' => (string)($d['id'] ?? ''), 'name' => (string)($d['name'] ?? 'Spotify device'), 'type' => (string)($d['type'] ?? ''), 'is_active' => !empty($d['is_active'])
        ]; }, $devices)),
        'device_a' => $deviceA,
        'device_b' => $deviceB,
        'player_a' => ['state' => ($activeDeviceId && $activeDeviceId === $deviceA && $isPlaying) ? 'playing' : 'standby', 'loaded' => mx_track_output($loadedA)],
        'player_b' => ['state' => ($activeDeviceId && $activeDeviceId === $deviceB && $isPlaying) ? 'playing' : 'standby', 'loaded' => mx_track_output($loadedB)],
        'active_device_id' => $activeDeviceId,
        'active_device_name' => (string)($playback['device']['name'] ?? ''),
        'is_playing' => $isPlaying,
        'track' => ['id' => (string)($item['id'] ?? ''), 'title' => (string)($item['name'] ?? ''), 'artist' => implode(', ', $artists), 'image' => $image, 'progress_ms' => $playback['progress_ms'] ?? null, 'duration_ms' => $item['duration_ms'] ?? null],
        'playlist' => array_values(array_map('mx_track_output', $playlist)),
        'requests' => mx_requests($playlist),
    ];
}
function mx_deck_has_loaded($deck) {
    $deck = $deck === 'b' ? 'b' : 'a';
    $loaded = mx_json('spotify_mixer_loaded_' . $deck, []);
    return is_array($loaded) && !empty($loaded['id']);
}
function mx_load_track_to_deck($track, $deck, $playback = null, &$playlist = null, $removeFromPlaylist = false) {
    $deck = $deck === 'b' ? 'b' : 'a';
    $deviceA = mx_setting('spotify_mixer_device_a', '');
    $deviceB = mx_setting('spotify_mixer_device_b', '');
    $device = $deck === 'b' ? $deviceB : $deviceA;
    if (!$device) throw new RuntimeException('Player ' . strtoupper($deck) . ' has no assigned Spotify device.');
    if (mx_device_playing($device, $playback)) throw new RuntimeException('Player ' . strtoupper($deck) . ' is currently playing. Loading is blocked.');
    $clean = mx_clean_track($track);
    if (is_array($playlist)) {
        mx_return_loaded_if_unplayed($deck, $playlist, $clean);
        if ($removeFromPlaylist) $playlist = mx_remove_track_from_playlist($playlist, $clean);
        mx_save_playlist($playlist);
    }
    $clean['played_on_deck'] = false;
    mx_set('spotify_mixer_loaded_' . $deck, json_encode($clean));
    return $deck;
}

function mx_track_key($track) {
    if (!empty($track['request_id'])) return 'request:' . (int)$track['request_id'];
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
    if (!empty($loaded['played_on_deck'])) return;
    if (is_array($newTrack) && mx_track_key($loaded) === mx_track_key($newTrack)) return;
    $loaded = mx_clean_track($loaded);
    $loaded['added_at'] = date('Y-m-d H:i:s');
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

function mx_json_out($data) { echo json_encode($data); exit; }

try {
    $action = $_POST['action'] ?? $_GET['action'] ?? 'state';
    $playlist = mx_json('spotify_mixer_playlist', []);

    if ($action === 'search') {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $tracks = strlen($q) >= 2 ? dttd_spotify_search_tracks($q, 8) : [];
        mx_json_out(['ok' => true, 'tracks' => array_values(array_map('mx_track_output', $tracks))]);
    }

    if ($action === 'add_track') {
        $track = json_decode((string)($_POST['track_json'] ?? ''), true);
        if (!is_array($track) || empty($track['id'])) throw new RuntimeException('No valid track selected.');
        array_unshift($playlist, mx_clean_track($track));
        mx_save_playlist($playlist);
        mx_json_out(['ok' => true, 'message' => 'Track added to DJ playlist.', 'state' => mx_state()]);
    }



    if ($action === 'load_track_direct' || $action === 'play_track_direct') {
        $track = json_decode((string)($_POST['track_json'] ?? ''), true);
        if (!is_array($track) || empty($track['id'])) throw new RuntimeException('No valid track selected.');
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $playback = mx_playback();
        mx_load_track_to_deck($track, $deck, $playback, $playlist, false);
        if ($action === 'play_track_direct') {
            $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
            mx_play_track($device, $track['id'] ?? '');
            mx_json_out(['ok' => true, 'message' => 'Track loaded and played on Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
        }
        mx_json_out(['ok' => true, 'message' => 'Track loaded to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    if ($action === 'accept_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        foreach ($playlist as $p) if (!empty($p['request_id']) && (int)$p['request_id'] === $requestId) throw new RuntimeException('That request is already in the DJ playlist.');
        $track = mx_track_from_request($requestId);
        if (!$track) throw new RuntimeException('That request has no Spotify track attached.');
        array_unshift($playlist, $track);
        mx_save_playlist($playlist);
        mx_flag_request_in_playlist($requestId);
        mx_json_out(['ok' => true, 'message' => 'Request moved to DJ playlist.', 'state' => mx_state()]);
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
        $playback = mx_playback();
        $deviceA = mx_setting('spotify_mixer_device_a', '');
        $deviceB = mx_setting('spotify_mixer_device_b', '');
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        if ($action === 'auto_load') {
            $aPlaying = mx_device_playing($deviceA, $playback);
            $bPlaying = mx_device_playing($deviceB, $playback);
            if ($deviceA && !$aPlaying) $deck = 'a';
            elseif ($deviceB && !$bPlaying) $deck = 'b';
            else throw new RuntimeException('No empty standby player found.');
        }
        mx_load_track_to_deck($playlist[$idx], $deck, $playback, $playlist, true);
        mx_json_out(['ok' => true, 'message' => 'Loaded to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    if ($action === 'load_request' || $action === 'auto_load_request') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $track = mx_track_from_request($requestId);
        if (!$track) throw new RuntimeException('That request has no Spotify track attached.');
        $playback = mx_playback();
        $deviceA = mx_setting('spotify_mixer_device_a', '');
        $deviceB = mx_setting('spotify_mixer_device_b', '');
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        if ($action === 'auto_load_request') {
            $aPlaying = mx_device_playing($deviceA, $playback);
            $bPlaying = mx_device_playing($deviceB, $playback);
            if ($deviceA && !$aPlaying) $deck = 'a';
            elseif ($deviceB && !$bPlaying) $deck = 'b';
            else throw new RuntimeException('No empty standby player found.');
        }
        mx_load_track_to_deck($track, $deck, $playback, $playlist, false);
        mx_flag_request_in_playlist($requestId, 'loaded_' . $deck);
        mx_json_out(['ok' => true, 'message' => 'Public request loaded to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }



    if ($action === 'play_request_direct') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $track = mx_track_from_request($requestId);
        if (!$track) throw new RuntimeException('That request has no Spotify track attached.');
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $playback = mx_playback();
        mx_load_track_to_deck($track, $deck, $playback, $playlist, false);
        mx_flag_request_in_playlist($requestId, 'loaded_' . $deck);
        $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
        mx_play_track($device, $track['id'] ?? '');
        mx_mark_request_played($requestId);
        mx_json_out(['ok' => true, 'message' => 'Public request loaded and played on Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    if ($action === 'clear_loaded') {
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
        if (mx_device_playing($device)) throw new RuntimeException('Player ' . strtoupper($deck) . ' is currently playing. Pause it before clearing.');
        mx_set('spotify_mixer_loaded_' . $deck, '');
        mx_json_out(['ok' => true, 'state' => mx_state()]);
    }

    if ($action === 'play') {
        $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
        $device = $deck === 'b' ? mx_setting('spotify_mixer_device_b', '') : mx_setting('spotify_mixer_device_a', '');
        $track = mx_json('spotify_mixer_loaded_' . $deck, []);
        mx_play_track($device, $track['id'] ?? '');
        if (is_array($track) && !empty($track['id'])) {
            $track['played_on_deck'] = true;
            mx_set('spotify_mixer_loaded_' . $deck, json_encode(mx_clean_track($track)));
        }
        if (!empty($track['request_id'])) mx_mark_request_played((int)$track['request_id']);
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
        mx_pause($device);
        mx_json_out(['ok' => true, 'message' => 'Pause command sent to Player ' . strtoupper($deck) . '.', 'state' => mx_state()]);
    }

    mx_json_out(['ok' => true, 'state' => mx_state()]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'state' => mx_state()]);
}
