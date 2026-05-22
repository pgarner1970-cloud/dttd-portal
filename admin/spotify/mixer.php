<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

function mixer_setting($key, $default = '') {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function mixer_update_setting($key, $value) {
    $stmt = db()->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, (string)$value]);
}

function mixer_json_setting($key, $default = []) {
    $raw = mixer_setting($key, '');
    if ($raw === '') return $default;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function mixer_save_playlist($playlist) {
    $playlist = array_values(array_slice((array)$playlist, 0, 60));
    mixer_update_setting('spotify_mixer_playlist', json_encode($playlist));
}

function mixer_safe_track($track) {
    return [
        'id' => (string)($track['id'] ?? ''),
        'title' => (string)($track['title'] ?? ''),
        'artist' => (string)($track['artist'] ?? ''),
        'album' => (string)($track['album'] ?? ''),
        'image' => (string)($track['image'] ?? ''),
        'url' => (string)($track['url'] ?? ''),
        'duration_ms' => $track['duration_ms'] ?? null,
        'source' => (string)($track['source'] ?? 'search'),
        'request_id' => $track['request_id'] ?? null,
        'added_at' => $track['added_at'] ?? date('Y-m-d H:i:s'),
    ];
}

function mixer_track_from_request_id($request_id) {
    $stmt = db()->prepare("SELECT * FROM song_requests WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$request_id]);
    $r = $stmt->fetch();
    if (!$r || empty($r['spotify_track_id'])) return null;
    return [
        'id' => (string)$r['spotify_track_id'],
        'title' => (string)($r['song_title'] ?? ''),
        'artist' => (string)($r['artist'] ?? ''),
        'album' => '',
        'image' => (string)($r['spotify_album_image'] ?? ''),
        'url' => (string)($r['spotify_track_url'] ?? ''),
        'duration_ms' => null,
        'source' => 'request',
        'request_id' => (int)$r['id'],
        'guest_name' => (string)($r['guest_name'] ?? ''),
        'message' => (string)($r['message'] ?? ''),
    ];
}

function mixer_spotify_put($url, $body = '') {
    $token = dttd_spotify_user_access_token();
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is not available.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Spotify playback request failed' . ($error ? ': ' . $error : '') . ' (HTTP ' . $status . ').');
    }
    return $response;
}

function mixer_current_playback_safe() {
    try {
        return dttd_spotify_current_playback();
    } catch (Throwable $e) {
        return null;
    }
}

function mixer_is_device_playing($device_id, $playback = null) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') return false;
    if ($playback === null) $playback = mixer_current_playback_safe();
    return !empty($playback['is_playing']) && (string)($playback['device']['id'] ?? '') === $device_id;
}

function mixer_play_track_on_device($device_id, $track_id) {
    $device_id = trim((string)$device_id);
    $track_id = trim((string)$track_id);
    if ($device_id === '') throw new RuntimeException('No Spotify device selected for this player.');
    if ($track_id === '') throw new RuntimeException('No track loaded on this player.');
    $uri = strpos($track_id, 'spotify:track:') === 0 ? $track_id : 'spotify:track:' . $track_id;
    $url = 'https://api.spotify.com/v1/me/player/play?device_id=' . rawurlencode($device_id);
    mixer_spotify_put($url, json_encode(['uris' => [$uri]]));
}

function mixer_pause_device($device_id) {
    $device_id = trim((string)$device_id);
    if ($device_id === '') throw new RuntimeException('No Spotify device selected for this player.');
    $url = 'https://api.spotify.com/v1/me/player/pause?device_id=' . rawurlencode($device_id);
    mixer_spotify_put($url, '');
}

function mixer_duration($ms) {
    if (!$ms) return '';
    $seconds = (int)round(((int)$ms) / 1000);
    return floor($seconds / 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}

function mixer_device_name($devices, $id) {
    foreach ($devices as $d) {
        if (($d['id'] ?? '') === $id) return (string)($d['name'] ?? 'Selected device');
    }
    return $id ? 'Selected device' : 'Not assigned';
}

function mixer_device_type_icon($type) {
    $type = strtolower((string)$type);
    if (strpos($type, 'smartphone') !== false) return '▯';
    if (strpos($type, 'tablet') !== false) return '▭';
    if (strpos($type, 'speaker') !== false) return '◉';
    return '◌';
}

$flash = '';
$error = '';
$playlist = mixer_json_setting('spotify_mixer_playlist', []);
$loadedA = mixer_json_setting('spotify_mixer_loaded_a', []);
$loadedB = mixer_json_setting('spotify_mixer_loaded_b', []);
$deviceA = mixer_setting('spotify_mixer_device_a', '');
$deviceB = mixer_setting('spotify_mixer_device_b', '');
$configured = function_exists('dttd_spotify_config_loaded') && dttd_spotify_config_loaded();
$connected = function_exists('dttd_spotify_queue_connected') && dttd_spotify_queue_connected();
$devices = [];
$playback = null;

try {
    if ($connected) {
        $devices = dttd_spotify_get_devices();
        $playback = mixer_current_playback_safe();
    }
} catch (Throwable $e) {
    $error = $error ?: $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'assign_devices') {
            mixer_update_setting('spotify_mixer_device_a', $_POST['device_a'] ?? '');
            mixer_update_setting('spotify_mixer_device_b', $_POST['device_b'] ?? '');
            $flash = 'Player devices updated.';
        } elseif ($action === 'add_search_track') {
            $track = json_decode((string)($_POST['track_json'] ?? ''), true);
            if (!is_array($track) || empty($track['id'])) throw new RuntimeException('No valid track selected.');
            array_unshift($playlist, mixer_safe_track($track));
            mixer_save_playlist($playlist);
            $flash = 'Track added to DJ playlist.';
        } elseif ($action === 'add_request_track') {
            $track = mixer_track_from_request_id($_POST['request_id'] ?? 0);
            if (!$track) throw new RuntimeException('That request has no Spotify track attached.');
            array_unshift($playlist, mixer_safe_track($track));
            mixer_save_playlist($playlist);
            $flash = 'Request added to DJ playlist.';
        } elseif ($action === 'remove_playlist_item') {
            $idx = (int)($_POST['idx'] ?? -1);
            if (isset($playlist[$idx])) {
                unset($playlist[$idx]);
                mixer_save_playlist($playlist);
                $flash = 'Removed from DJ playlist.';
            }
        } elseif ($action === 'clear_playlist') {
            mixer_save_playlist([]);
            $flash = 'DJ playlist cleared.';
        } elseif ($action === 'load') {
            $idx = (int)($_POST['idx'] ?? -1);
            $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
            if (!isset($playlist[$idx])) throw new RuntimeException('Playlist item not found.');
            $targetDevice = $deck === 'b' ? mixer_setting('spotify_mixer_device_b', '') : mixer_setting('spotify_mixer_device_a', '');
            if (mixer_is_device_playing($targetDevice)) {
                throw new RuntimeException('Player ' . strtoupper($deck) . ' is currently playing. Loading is blocked to avoid interrupting the live output.');
            }
            mixer_update_setting('spotify_mixer_loaded_' . $deck, json_encode(mixer_safe_track($playlist[$idx])));
            $flash = 'Loaded to Player ' . strtoupper($deck) . ' in the portal. Press Play when ready.';
        } elseif ($action === 'auto_load') {
            $idx = (int)($_POST['idx'] ?? -1);
            if (!isset($playlist[$idx])) throw new RuntimeException('Playlist item not found.');
            $deviceA_now = mixer_setting('spotify_mixer_device_a', '');
            $deviceB_now = mixer_setting('spotify_mixer_device_b', '');
            $playbackNow = mixer_current_playback_safe();
            $aPlaying = mixer_is_device_playing($deviceA_now, $playbackNow);
            $bPlaying = mixer_is_device_playing($deviceB_now, $playbackNow);
            if ($deviceA_now && !$aPlaying) {
                mixer_update_setting('spotify_mixer_loaded_a', json_encode(mixer_safe_track($playlist[$idx])));
                $flash = 'Auto-loaded to Player A.';
            } elseif ($deviceB_now && !$bPlaying) {
                mixer_update_setting('spotify_mixer_loaded_b', json_encode(mixer_safe_track($playlist[$idx])));
                $flash = 'Auto-loaded to Player B.';
            } else {
                throw new RuntimeException('No safe idle player found. Both assigned players appear active or unassigned.');
            }
        } elseif ($action === 'clear_loaded') {
            $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
            $targetDevice = $deck === 'b' ? mixer_setting('spotify_mixer_device_b', '') : mixer_setting('spotify_mixer_device_a', '');
            if (mixer_is_device_playing($targetDevice)) {
                throw new RuntimeException('Player ' . strtoupper($deck) . ' is currently playing. Pause it before clearing.');
            }
            mixer_update_setting('spotify_mixer_loaded_' . $deck, '');
            $flash = 'Cleared Player ' . strtoupper($deck) . ' loaded track.';
        } elseif ($action === 'play') {
            $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
            $device = $deck === 'b' ? mixer_setting('spotify_mixer_device_b', '') : mixer_setting('spotify_mixer_device_a', '');
            $track = mixer_json_setting('spotify_mixer_loaded_' . $deck, []);
            mixer_play_track_on_device($device, $track['id'] ?? '');
            $flash = 'Play command sent to Player ' . strtoupper($deck) . '. If both devices use the same Spotify account, this may transfer playback.';
        } elseif ($action === 'pause') {
            $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
            $device = $deck === 'b' ? mixer_setting('spotify_mixer_device_b', '') : mixer_setting('spotify_mixer_device_a', '');
            mixer_pause_device($device);
            $flash = 'Pause command sent to Player ' . strtoupper($deck) . '.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $playlist = mixer_json_setting('spotify_mixer_playlist', []);
    $loadedA = mixer_json_setting('spotify_mixer_loaded_a', []);
    $loadedB = mixer_json_setting('spotify_mixer_loaded_b', []);
    $deviceA = mixer_setting('spotify_mixer_device_a', '');
    $deviceB = mixer_setting('spotify_mixer_device_b', '');
    try {
        if ($connected) {
            $devices = dttd_spotify_get_devices();
            $playback = mixer_current_playback_safe();
        }
    } catch (Throwable $ignored) {}
}

$searchResults = [];
$q = trim((string)($_GET['q'] ?? ''));
try {
    if ($configured && strlen($q) >= 2) {
        $searchResults = dttd_spotify_search_tracks($q, 8);
    }
} catch (Throwable $e) {
    $error = $error ?: $e->getMessage();
}

$currentDeviceId = (string)($playback['device']['id'] ?? '');
$isPlaying = !empty($playback['is_playing']);
$aPlaying = $currentDeviceId !== '' && $currentDeviceId === $deviceA && $isPlaying;
$bPlaying = $currentDeviceId !== '' && $currentDeviceId === $deviceB && $isPlaying;

$recentRequests = [];
try {
    $stmt = db()->query("SELECT id, guest_name, song_title, artist, message, created_at, spotify_track_id, spotify_track_url, spotify_album_image FROM song_requests WHERE spotify_track_id IS NOT NULL AND spotify_track_id <> '' ORDER BY id DESC LIMIT 12");
    $recentRequests = $stmt->fetchAll();
} catch (Throwable $ignored) {}

admin_header('Spotify Mixer - DJ Portal');
?>
<style>
.mixer-shell{padding:16px;max-width:1800px;margin:0 auto}.mixer-grid{display:grid;grid-template-columns:minmax(300px,.95fr) minmax(520px,1.35fr) minmax(300px,.95fr);gap:14px}.mixer-panel{background:rgba(12,23,37,.9);border:1px solid rgba(84,130,180,.28);border-radius:18px;box-shadow:0 18px 45px rgba(0,0,0,.22);overflow:hidden}.mixer-panel-a{border-color:rgba(255,164,18,.5)}.mixer-panel-b{border-color:rgba(41,150,255,.5)}.mixer-head{padding:14px 16px;border-bottom:1px solid rgba(84,130,180,.22);display:flex;justify-content:space-between;gap:10px;align-items:center}.mixer-head h1,.mixer-head h2{margin:0;font-size:22px}.mixer-body{padding:14px 16px}.deck-title{display:flex;gap:10px;align-items:center}.deck-letter{display:inline-grid;place-items:center;width:52px;height:52px;border-radius:12px;font-size:34px;font-weight:950;background:rgba(255,155,0,.12);border:1px solid rgba(255,155,0,.35);color:#ff9b00}.mixer-panel-b .deck-letter{background:rgba(41,150,255,.12);border-color:rgba(41,150,255,.38);color:#3bb4ff}.pill-ok,.pill-warn,.pill-muted{display:inline-flex;gap:6px;align-items:center;padding:7px 11px;border-radius:999px;font-weight:900;font-size:13px}.pill-ok{background:rgba(34,197,94,.14);border:1px solid rgba(34,197,94,.45);color:#52ff91}.pill-warn{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.45);color:#ffc247}.pill-muted{background:rgba(148,163,184,.12);border:1px solid rgba(148,163,184,.28);color:#cbd5e1}.device-select,.search-input{width:100%;padding:12px;border-radius:12px;background:#0d1726;color:#fff;border:1px solid rgba(99,145,205,.35);font-weight:750}.search-input{font-size:16px}.track-card{display:grid;grid-template-columns:56px 1fr auto;gap:10px;align-items:center;padding:10px;border-radius:14px;border:1px solid rgba(99,145,205,.22);background:rgba(255,255,255,.035);margin-bottom:9px}.track-card img,.playlist-row img{width:56px;height:56px;object-fit:cover;border-radius:10px}.track-title{font-size:18px;font-weight:950}.muted{color:#b8c9dd}.mini{font-size:13px}.mixer-btn{border:1px solid rgba(99,145,205,.4);background:#111c2d;color:#fff;border-radius:12px;padding:10px 12px;font-weight:950;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap}.mixer-btn.green{border-color:#15c86f;color:#38ff95;background:rgba(21,200,111,.13)}.mixer-btn.blue{border-color:#348cff;color:#72b9ff;background:rgba(52,140,255,.12)}.mixer-btn.orange{border-color:#ff9b00;color:#ffc247;background:rgba(255,155,0,.11)}.mixer-btn.red{border-color:#ff4655;color:#ff6b76;background:rgba(255,70,85,.1)}.mixer-btn:disabled{opacity:.42;cursor:not-allowed}.btn-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.search-row{display:grid;grid-template-columns:1fr auto;gap:10px}.playlist-row{display:grid;grid-template-columns:40px 1fr auto;gap:10px;align-items:center;padding:9px 10px;border-bottom:1px solid rgba(99,145,205,.16)}.playlist-row:last-child{border-bottom:0}.playlist-row img{width:40px;height:40px;border-radius:8px}.small-actions{display:grid;grid-template-columns:repeat(3,auto);gap:6px;justify-content:end}.small-actions .mixer-btn{padding:7px 9px;font-size:12px;border-radius:9px}.notice{margin:0 0 12px}.request-pick{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;padding:10px;border-radius:12px;border:1px solid rgba(99,145,205,.18);background:rgba(255,255,255,.025);margin-bottom:8px}.loaded-box{min-height:108px;border:1px dashed rgba(99,145,205,.35);border-radius:14px;padding:12px;margin-top:10px;background:rgba(255,255,255,.025)}.status-card{margin-top:12px;padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(99,145,205,.18)}.now-playing{display:grid;grid-template-columns:72px 1fr;gap:12px;align-items:center}.now-playing img{width:72px;height:72px;object-fit:cover;border-radius:10px}.mixer-bottom{margin-top:14px;padding:12px 16px;border-radius:16px;background:rgba(12,23,37,.9);border:1px solid rgba(84,130,180,.25);display:flex;justify-content:space-between;gap:12px;align-items:center}.deck-status{margin-top:8px;font-size:13px;color:#9fb4ca}.blocked-note{color:#ffc247;font-size:13px;margin-top:8px}.mixer-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.mixer-tab{padding:8px 12px;border-radius:999px;border:1px solid rgba(99,145,205,.32);color:#b8d8ff;text-decoration:none;font-weight:900}.mixer-tab.active{border-color:#15c86f;color:#38ff95;background:rgba(21,200,111,.12)}@media(max-width:1200px){.mixer-grid{grid-template-columns:1fr}.small-actions{justify-content:start}.track-card{grid-template-columns:56px 1fr}.track-card form{grid-column:2}.mixer-bottom{flex-direction:column;align-items:flex-start}}@media(max-width:720px){.mixer-shell{padding:10px}.mixer-head{align-items:flex-start;flex-direction:column}.btn-row{grid-template-columns:1fr}.request-pick{grid-template-columns:1fr}.small-actions{grid-template-columns:1fr 1fr}.search-row{grid-template-columns:1fr}.deck-letter{width:44px;height:44px;font-size:28px}}
</style>
<main class="mixer-shell">
  <?php if ($flash): ?><p class="notice success"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>

  <div class="mixer-grid">
    <section class="mixer-panel mixer-panel-a" data-player="a" data-device-id="<?= h($deviceA) ?>">
      <div class="mixer-head">
        <div class="deck-title"><span class="deck-letter">A</span><div><h2>Player A</h2><div class="muted mini"><?= h(mixer_device_name($devices, $deviceA)) ?></div></div></div>
        <span class="<?= $aPlaying ? 'pill-ok' : 'pill-warn' ?> deck-state" id="deckAState"><?= $aPlaying ? 'Playing' : 'Standby' ?></span>
      </div>
      <div class="mixer-body">
        <form method="post"><input type="hidden" name="action" value="assign_devices"><label class="mini muted">Spotify device for A</label><select class="device-select" name="device_a" onchange="this.form.submit()"><option value="">Choose device…</option><?php foreach($devices as $d): ?><option value="<?= h($d['id'] ?? '') ?>" <?= (($d['id'] ?? '') === $deviceA) ? 'selected' : '' ?>><?= h(mixer_device_type_icon($d['type'] ?? '')) ?> <?= h($d['name'] ?? 'Unnamed') ?> <?= !empty($d['is_active']) ? '— active' : '' ?></option><?php endforeach; ?></select><input type="hidden" name="device_b" value="<?= h($deviceB) ?>"></form>
        <h3>Loaded on A</h3>
        <div class="loaded-box"><?php if (!empty($loadedA['id'])): ?><div class="track-card"><img src="<?= h($loadedA['image'] ?: 'https://dancethruthedecades.co.uk/assets/glitter-ball-clean.png') ?>" alt=""><div><div class="track-title"><?= h($loadedA['title']) ?></div><div class="muted"><?= h($loadedA['artist']) ?></div></div></div><?php else: ?><p class="muted">No track loaded. Load from the DJ playlist when A is safe.</p><?php endif; ?></div>
        <div class="btn-row"><form method="post"><input type="hidden" name="action" value="play"><input type="hidden" name="deck" value="a"><button class="mixer-btn green" <?= empty($loadedA['id']) || !$deviceA ? 'disabled' : '' ?>>▶ Play A</button></form><form method="post"><input type="hidden" name="action" value="pause"><input type="hidden" name="deck" value="a"><button class="mixer-btn orange" <?= !$deviceA ? 'disabled' : '' ?>>⏸ Pause A</button></form><form method="post"><input type="hidden" name="action" value="clear_loaded"><input type="hidden" name="deck" value="a"><button class="mixer-btn red" <?= $aPlaying ? 'disabled' : '' ?>>Clear A</button></form></div>
        <?php if ($aPlaying): ?><div class="blocked-note">A is playing. Loading/clearing A is blocked.</div><?php endif; ?>
      </div>
    </section>

    <section class="mixer-panel">
      <div class="mixer-head"><div><h1>Spotify Mixer</h1><div class="muted">Requests and search results feed a DJ playlist, then load safely to A or B.</div></div><a class="mixer-btn blue" href="<?= h(admin_url('spotify/index.php')) ?>">Tools</a></div>
      <div class="mixer-body">
        <?php if (!$connected): ?><p class="error">Spotify is not connected. Connect it in Spotify Tools first.</p><?php endif; ?>
        <form class="search-row" method="get"><input class="search-input" name="q" value="<?= h($q) ?>" placeholder="Search for a track, artist or album…"><button class="mixer-btn blue">Search</button></form>
        <?php foreach($searchResults as $track): ?><div class="track-card"><img src="<?= h($track['image']) ?>" alt=""><div><div class="track-title"><?= h($track['title']) ?></div><div class="muted"><?= h($track['artist']) ?><?= $track['album'] ? ' • ' . h($track['album']) : '' ?></div></div><form method="post"><input type="hidden" name="action" value="add_search_track"><input type="hidden" name="track_json" value="<?= h(json_encode(mixer_safe_track($track))) ?>"><button class="mixer-btn green">+ DJ Playlist</button></form></div><?php endforeach; ?>

        <div class="mixer-tabs"><a class="mixer-tab active" href="#requests">Public requests</a><a class="mixer-tab" href="#playlist">DJ playlist</a></div>
        <h2 id="requests">Recent Spotify-matched requests</h2>
        <?php if (!$recentRequests): ?><p class="muted">No Spotify-matched requests found yet.</p><?php endif; ?>
        <?php foreach($recentRequests as $r): ?><div class="request-pick"><div><strong><?= h($r['song_title']) ?></strong> <span class="muted">— <?= h($r['artist']) ?></span><br><span class="mini muted"><?= h($r['guest_name'] ?? 'Guest') ?><?= !empty($r['message']) ? ': ' . h($r['message']) : '' ?></span></div><form method="post"><input type="hidden" name="action" value="add_request_track"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><button class="mixer-btn green">Accept</button></form></div><?php endforeach; ?>

        <div id="playlist" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px"><h2>DJ Playlist (<?= count($playlist) ?>)</h2><form method="post"><input type="hidden" name="action" value="clear_playlist"><button class="mixer-btn red">Clear</button></form></div>
        <div class="mixer-panel" style="box-shadow:none">
          <?php if (!$playlist): ?><p class="mixer-body muted">DJ playlist is empty.</p><?php endif; ?>
          <?php foreach($playlist as $idx => $track): ?><div class="playlist-row"><img src="<?= h($track['image'] ?? '') ?>" alt=""><div><strong><?= h($track['title'] ?? '') ?></strong><br><span class="mini muted"><?= h($track['artist'] ?? '') ?><?= mixer_duration($track['duration_ms'] ?? null) ? ' • ' . h(mixer_duration($track['duration_ms'])) : '' ?></span></div><div class="small-actions"><form method="post"><input type="hidden" name="action" value="auto_load"><input type="hidden" name="idx" value="<?= (int)$idx ?>"><button class="mixer-btn green">Auto</button></form><form method="post"><input type="hidden" name="action" value="load"><input type="hidden" name="idx" value="<?= (int)$idx ?>"><input type="hidden" name="deck" value="a"><button class="mixer-btn orange load-a" <?= $aPlaying ? 'disabled' : '' ?>>Load A</button></form><form method="post"><input type="hidden" name="action" value="load"><input type="hidden" name="idx" value="<?= (int)$idx ?>"><input type="hidden" name="deck" value="b"><button class="mixer-btn blue load-b" <?= $bPlaying ? 'disabled' : '' ?>>Load B</button></form><form method="post"><input type="hidden" name="action" value="remove_playlist_item"><input type="hidden" name="idx" value="<?= (int)$idx ?>"><button class="mixer-btn red">×</button></form></div></div><?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="mixer-panel mixer-panel-b" data-player="b" data-device-id="<?= h($deviceB) ?>">
      <div class="mixer-head">
        <div class="deck-title"><span class="deck-letter">B</span><div><h2>Player B</h2><div class="muted mini"><?= h(mixer_device_name($devices, $deviceB)) ?></div></div></div>
        <span class="<?= $bPlaying ? 'pill-ok' : 'pill-warn' ?> deck-state" id="deckBState"><?= $bPlaying ? 'Playing' : 'Standby' ?></span>
      </div>
      <div class="mixer-body">
        <form method="post"><input type="hidden" name="action" value="assign_devices"><input type="hidden" name="device_a" value="<?= h($deviceA) ?>"><label class="mini muted">Spotify device for B</label><select class="device-select" name="device_b" onchange="this.form.submit()"><option value="">Choose device…</option><?php foreach($devices as $d): ?><option value="<?= h($d['id'] ?? '') ?>" <?= (($d['id'] ?? '') === $deviceB) ? 'selected' : '' ?>><?= h(mixer_device_type_icon($d['type'] ?? '')) ?> <?= h($d['name'] ?? 'Unnamed') ?> <?= !empty($d['is_active']) ? '— active' : '' ?></option><?php endforeach; ?></select></form>
        <h3>Loaded on B</h3>
        <div class="loaded-box"><?php if (!empty($loadedB['id'])): ?><div class="track-card"><img src="<?= h($loadedB['image'] ?: 'https://dancethruthedecades.co.uk/assets/glitter-ball-clean.png') ?>" alt=""><div><div class="track-title"><?= h($loadedB['title']) ?></div><div class="muted"><?= h($loadedB['artist']) ?></div></div></div><?php else: ?><p class="muted">No track loaded. Load from the DJ playlist when B is safe.</p><?php endif; ?></div>
        <div class="btn-row"><form method="post"><input type="hidden" name="action" value="play"><input type="hidden" name="deck" value="b"><button class="mixer-btn green" <?= empty($loadedB['id']) || !$deviceB ? 'disabled' : '' ?>>▶ Play B</button></form><form method="post"><input type="hidden" name="action" value="pause"><input type="hidden" name="deck" value="b"><button class="mixer-btn orange" <?= !$deviceB ? 'disabled' : '' ?>>⏸ Pause B</button></form><form method="post"><input type="hidden" name="action" value="clear_loaded"><input type="hidden" name="deck" value="b"><button class="mixer-btn red" <?= $bPlaying ? 'disabled' : '' ?>>Clear B</button></form></div>
        <?php if ($bPlaying): ?><div class="blocked-note">B is playing. Loading/clearing B is blocked.</div><?php endif; ?>
      </div>
    </section>
  </div>

  <div class="mixer-bottom">
    <div><strong>Spotify status:</strong> <span id="spotifyMixerStatus"><?= $isPlaying ? 'Playing on ' . h($playback['device']['name'] ?? 'active device') : 'Standby / no active playback' ?></span></div>
    <div class="muted mini">Auto-refreshes Spotify playback state every 5 seconds. API status can lag briefly.</div>
  </div>
</main>
<script>
(function(){
  const deckAState = document.getElementById('deckAState');
  const deckBState = document.getElementById('deckBState');
  const status = document.getElementById('spotifyMixerStatus');
  function setState(el, state){
    if(!el) return;
    el.textContent = state === 'playing' ? 'Playing' : 'Standby';
    el.classList.remove('pill-ok','pill-warn');
    el.classList.add(state === 'playing' ? 'pill-ok' : 'pill-warn');
  }
  function setButtons(selector, disabled){
    document.querySelectorAll(selector).forEach(btn => { btn.disabled = !!disabled; });
  }
  async function poll(){
    try{
      const res = await fetch('mixer-status.php?ts=' + Date.now(), {cache:'no-store'});
      const data = await res.json();
      if(!data.ok){ if(status) status.textContent = data.error || 'Spotify status unavailable'; return; }
      const aState = data.player_a && data.player_a.state ? data.player_a.state : 'standby';
      const bState = data.player_b && data.player_b.state ? data.player_b.state : 'standby';
      setState(deckAState, aState);
      setState(deckBState, bState);
      setButtons('.load-a', aState === 'playing');
      setButtons('.load-b', bState === 'playing');
      if(status){
        if(data.is_playing){
          const track = data.track && data.track.title ? ' — ' + data.track.title : '';
          status.textContent = 'Playing on ' + (data.active_device_name || 'active device') + track;
        } else {
          status.textContent = 'Standby / no active playback';
        }
      }
    }catch(e){ if(status) status.textContent = 'Spotify status check failed'; }
  }
  poll();
  setInterval(poll, 5000);
})();
</script>
<?php admin_footer(); ?>
