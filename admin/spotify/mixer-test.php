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
    $playlist = array_values(array_slice((array)$playlist, 0, 30));
    mixer_update_setting('spotify_mixer_playlist', json_encode($playlist));
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
    ];
}

$flash = '';
$error = '';
$playlist = mixer_json_setting('spotify_mixer_playlist', []);
$loadedA = mixer_json_setting('spotify_mixer_loaded_a', null);
$loadedB = mixer_json_setting('spotify_mixer_loaded_b', null);
$deviceA = mixer_setting('spotify_mixer_device_a', '');
$deviceB = mixer_setting('spotify_mixer_device_b', '');

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
            mixer_update_setting('spotify_mixer_loaded_' . $deck, json_encode(mixer_safe_track($playlist[$idx])));
            $flash = 'Loaded to Player ' . strtoupper($deck) . ' in the portal. Press Play to send it to Spotify.';
        } elseif ($action === 'clear_loaded') {
            $deck = ($_POST['deck'] ?? '') === 'b' ? 'b' : 'a';
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
    $loadedA = mixer_json_setting('spotify_mixer_loaded_a', null);
    $loadedB = mixer_json_setting('spotify_mixer_loaded_b', null);
    $deviceA = mixer_setting('spotify_mixer_device_a', '');
    $deviceB = mixer_setting('spotify_mixer_device_b', '');
}

$configured = function_exists('dttd_spotify_config_loaded') && dttd_spotify_config_loaded();
$connected = function_exists('dttd_spotify_queue_connected') && dttd_spotify_queue_connected();
$devices = [];
$playback = null;
$searchResults = [];
$q = trim((string)($_GET['q'] ?? ''));

try {
    if ($connected) {
        $devices = dttd_spotify_get_devices();
        try { $playback = dttd_spotify_current_playback(); } catch (Throwable $ignored) { $playback = null; }
    }
    if ($configured && strlen($q) >= 2) {
        $searchResults = dttd_spotify_search_tracks($q, 6);
    }
} catch (Throwable $e) {
    $error = $error ?: $e->getMessage();
}

function mixer_device_name($devices, $id) {
    foreach ($devices as $d) {
        if (($d['id'] ?? '') === $id) return (string)($d['name'] ?? 'Selected device');
    }
    return $id ? 'Selected device' : 'Not assigned';
}

$currentDeviceId = $playback['device']['id'] ?? '';
$isPlaying = !empty($playback['is_playing']);

$recentRequests = [];
try {
    $stmt = db()->query("SELECT id, guest_name, song_title, artist, message, created_at, spotify_track_id, spotify_track_url, spotify_album_image FROM song_requests WHERE spotify_track_id IS NOT NULL AND spotify_track_id <> '' ORDER BY id DESC LIMIT 8");
    $recentRequests = $stmt->fetchAll();
} catch (Throwable $ignored) {}

admin_header('Spotify Mixer Test - DJ Portal');
?>
<style>
.mixer-wrap{padding:18px;display:grid;grid-template-columns:minmax(250px,1fr) minmax(360px,1.25fr) minmax(250px,1fr);gap:16px;max-width:1600px;margin:0 auto}.mixer-panel{background:rgba(12,23,37,.88);border:1px solid rgba(84,130,180,.28);border-radius:18px;box-shadow:0 18px 45px rgba(0,0,0,.22);overflow:hidden}.mixer-head{padding:16px 18px;border-bottom:1px solid rgba(84,130,180,.22);display:flex;justify-content:space-between;gap:12px;align-items:center}.mixer-head h1,.mixer-head h2{margin:0;font-size:22px}.mixer-body{padding:16px 18px}.deck-a{border-color:rgba(255,164,18,.48)}.deck-b{border-color:rgba(41,150,255,.48)}.deck-letter{font-size:44px;font-weight:900;color:#ff9b00}.deck-b .deck-letter{color:#3bb4ff}.pill-ok{display:inline-flex;gap:6px;align-items:center;padding:7px 11px;border-radius:999px;background:rgba(34,197,94,.14);border:1px solid rgba(34,197,94,.45);color:#52ff91;font-weight:800}.pill-warn{display:inline-flex;gap:6px;align-items:center;padding:7px 11px;border-radius:999px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.45);color:#ffc247;font-weight:800}.device-select{width:100%;padding:12px;border-radius:12px;background:#0d1726;color:#fff;border:1px solid rgba(99,145,205,.35);font-weight:700}.track-card{display:grid;grid-template-columns:64px 1fr;gap:12px;align-items:center;padding:12px;border-radius:14px;border:1px solid rgba(99,145,205,.22);background:rgba(255,255,255,.035);margin-bottom:10px}.track-card img{width:64px;height:64px;object-fit:cover;border-radius:10px}.track-title{font-size:18px;font-weight:900}.muted{color:#b8c9dd}.mini{font-size:13px}.mixer-btn{border:1px solid rgba(99,145,205,.4);background:#111c2d;color:#fff;border-radius:12px;padding:11px 13px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px}.mixer-btn.green{border-color:#15c86f;color:#38ff95;background:rgba(21,200,111,.13)}.mixer-btn.blue{border-color:#348cff;color:#72b9ff;background:rgba(52,140,255,.12)}.mixer-btn.orange{border-color:#ff9b00;color:#ffc247;background:rgba(255,155,0,.11)}.mixer-btn.red{border-color:#ff4655;color:#ff6b76;background:rgba(255,70,85,.1)}.mixer-btn:disabled{opacity:.45;cursor:not-allowed}.btn-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}.search-row{display:grid;grid-template-columns:1fr auto;gap:10px}.search-input{padding:14px;border-radius:12px;background:#0d1726;color:#fff;border:1px solid rgba(99,145,205,.35);font-size:16px}.playlist-row{display:grid;grid-template-columns:40px 1fr auto;gap:10px;align-items:center;padding:10px;border-bottom:1px solid rgba(99,145,205,.16)}.playlist-row:last-child{border-bottom:0}.playlist-row img{width:40px;height:40px;object-fit:cover;border-radius:8px}.small-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.small-actions .mixer-btn{padding:8px 10px;font-size:12px;border-radius:9px}.notice{margin:12px auto;max-width:1600px}.request-pick{padding:10px;border-radius:12px;border:1px solid rgba(99,145,205,.18);background:rgba(255,255,255,.025);margin-bottom:8px}.deck-status{margin-top:8px;font-size:13px;color:#9fb4ca}.loaded-box{min-height:98px;border:1px dashed rgba(99,145,205,.35);border-radius:14px;padding:14px;margin-top:12px;background:rgba(255,255,255,.025)}@media(max-width:1100px){.mixer-wrap{grid-template-columns:1fr}.mixer-head h1,.mixer-head h2{font-size:20px}}
</style>
<?php if ($flash): ?><p class="notice success"><?= h($flash) ?></p><?php endif; ?>
<?php if ($error): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>
<main class="mixer-wrap">
  <section class="mixer-panel deck-a">
    <div class="mixer-head"><div><span class="deck-letter">A</span> <strong>Player A</strong><div class="muted mini"><?= h(mixer_device_name($devices, $deviceA)) ?></div></div><span class="<?= ($currentDeviceId === $deviceA && $isPlaying) ? 'pill-ok' : 'pill-warn' ?>"><?= ($currentDeviceId === $deviceA && $isPlaying) ? 'Playing' : 'Standby' ?></span></div>
    <div class="mixer-body">
      <form method="post"><input type="hidden" name="action" value="assign_devices"><label class="mini muted">Assign device</label><select class="device-select" name="device_a" onchange="this.form.submit()"><option value="">Choose device…</option><?php foreach($devices as $d): ?><option value="<?= h($d['id'] ?? '') ?>" <?= (($d['id'] ?? '') === $deviceA) ? 'selected' : '' ?>><?= h($d['name'] ?? 'Unnamed') ?> <?= !empty($d['is_active']) ? '— active' : '' ?></option><?php endforeach; ?></select><input type="hidden" name="device_b" value="<?= h($deviceB) ?>"></form>
      <h3>Loaded track</h3>
      <div class="loaded-box"><?php if (!empty($loadedA['id'])): ?><div class="track-card"><img src="<?= h($loadedA['image'] ?: 'https://dancethruthedecades.co.uk/assets/logo.png') ?>" alt=""><div><div class="track-title"><?= h($loadedA['title']) ?></div><div class="muted"><?= h($loadedA['artist']) ?></div></div></div><?php else: ?><p class="muted">No track loaded. Load from the DJ playlist.</p><?php endif; ?></div>
      <div class="btn-row"><form method="post"><input type="hidden" name="action" value="play"><input type="hidden" name="deck" value="a"><button class="mixer-btn green" <?= empty($loadedA['id']) || !$deviceA ? 'disabled' : '' ?>>▶ Play A</button></form><form method="post"><input type="hidden" name="action" value="pause"><input type="hidden" name="deck" value="a"><button class="mixer-btn orange" <?= !$deviceA ? 'disabled' : '' ?>>⏸ Pause A</button></form><form method="post"><input type="hidden" name="action" value="clear_loaded"><input type="hidden" name="deck" value="a"><button class="mixer-btn red">Clear A</button></form></div>
      <p class="deck-status">Note: this is a test. Spotify may transfer playback from the other device if both use the same account.</p>
    </div>
  </section>

  <section class="mixer-panel">
    <div class="mixer-head"><div><h1>Spotify Mixer Test</h1><div class="muted">Search or accept requests into a DJ playlist, then load to A/B.</div></div><a class="mixer-btn blue" href="index.php">Spotify Tools</a></div>
    <div class="mixer-body">
      <form class="search-row" method="get"><input class="search-input" name="q" value="<?= h($q) ?>" placeholder="Search track, artist or album…"><button class="mixer-btn blue">Search</button></form>
      <?php if ($q && !$searchResults): ?><p class="muted">No search results found, or Spotify search is unavailable.</p><?php endif; ?>
      <?php foreach($searchResults as $track): ?><div class="track-card"><img src="<?= h($track['image']) ?>" alt=""><div><div class="track-title"><?= h($track['title']) ?></div><div class="muted"><?= h($track['artist']) ?><?= $track['album'] ? ' • ' . h($track['album']) : '' ?></div></div><form method="post"><input type="hidden" name="action" value="add_search_track"><input type="hidden" name="track_json" value="<?= h(json_encode(mixer_safe_track($track))) ?>"><button class="mixer-btn green">+ DJ Playlist</button></form></div><?php endforeach; ?>
      <h2>Recent Spotify-matched requests</h2>
      <?php if (!$recentRequests): ?><p class="muted">No Spotify-matched requests found yet.</p><?php endif; ?>
      <?php foreach($recentRequests as $r): ?><div class="request-pick"><strong><?= h($r['song_title']) ?></strong> <span class="muted">— <?= h($r['artist']) ?></span><br><span class="mini muted"><?= h($r['guest_name'] ?? 'Guest') ?>: <?= h($r['message'] ?? '') ?></span><form method="post" style="margin-top:8px"><input type="hidden" name="action" value="add_request_track"><input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>"><button class="mixer-btn green">Accept into DJ Playlist</button></form></div><?php endforeach; ?>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px"><h2>DJ Playlist (<?= count($playlist) ?>)</h2><form method="post"><input type="hidden" name="action" value="clear_playlist"><button class="mixer-btn red">Clear</button></form></div>
      <div class="mixer-panel" style="box-shadow:none"><?php if (!$playlist): ?><p class="mixer-body muted">DJ playlist is empty.</p><?php endif; ?><?php foreach($playlist as $idx => $track): ?><div class="playlist-row"><img src="<?= h($track['image'] ?? '') ?>" alt=""><div><strong><?= h($track['title'] ?? '') ?></strong><br><span class="mini muted"><?= h($track['artist'] ?? '') ?> <?= mixer_duration($track['duration_ms'] ?? null) ? '• ' . h(mixer_duration($track['duration_ms'])) : '' ?></span></div><div class="small-actions"><form method="post"><input type="hidden" name="action" value="load"><input type="hidden" name="idx" value="<?= (int)$idx ?>"><input type="hidden" name="deck" value="a"><button class="mixer-btn orange">Load A</button></form><form method="post"><input type="hidden" name="action" value="load"><input type="hidden" name="idx" value="<?= (int)$idx ?>"><input type="hidden" name="deck" value="b"><button class="mixer-btn blue">Load B</button></form><form method="post"><input type="hidden" name="action" value="remove_playlist_item"><input type="hidden" name="idx" value="<?= (int)$idx ?>"><button class="mixer-btn red">×</button></form></div></div><?php endforeach; ?></div>
    </div>
  </section>

  <section class="mixer-panel deck-b">
    <div class="mixer-head"><div><span class="deck-letter">B</span> <strong>Player B</strong><div class="muted mini"><?= h(mixer_device_name($devices, $deviceB)) ?></div></div><span class="<?= ($currentDeviceId === $deviceB && $isPlaying) ? 'pill-ok' : 'pill-warn' ?>"><?= ($currentDeviceId === $deviceB && $isPlaying) ? 'Playing' : 'Standby' ?></span></div>
    <div class="mixer-body">
      <form method="post"><input type="hidden" name="action" value="assign_devices"><input type="hidden" name="device_a" value="<?= h($deviceA) ?>"><label class="mini muted">Assign device</label><select class="device-select" name="device_b" onchange="this.form.submit()"><option value="">Choose device…</option><?php foreach($devices as $d): ?><option value="<?= h($d['id'] ?? '') ?>" <?= (($d['id'] ?? '') === $deviceB) ? 'selected' : '' ?>><?= h($d['name'] ?? 'Unnamed') ?> <?= !empty($d['is_active']) ? '— active' : '' ?></option><?php endforeach; ?></select></form>
      <h3>Loaded track</h3>
      <div class="loaded-box"><?php if (!empty($loadedB['id'])): ?><div class="track-card"><img src="<?= h($loadedB['image'] ?: 'https://dancethruthedecades.co.uk/assets/logo.png') ?>" alt=""><div><div class="track-title"><?= h($loadedB['title']) ?></div><div class="muted"><?= h($loadedB['artist']) ?></div></div></div><?php else: ?><p class="muted">No track loaded. Load from the DJ playlist.</p><?php endif; ?></div>
      <div class="btn-row"><form method="post"><input type="hidden" name="action" value="play"><input type="hidden" name="deck" value="b"><button class="mixer-btn green" <?= empty($loadedB['id']) || !$deviceB ? 'disabled' : '' ?>>▶ Play B</button></form><form method="post"><input type="hidden" name="action" value="pause"><input type="hidden" name="deck" value="b"><button class="mixer-btn orange" <?= !$deviceB ? 'disabled' : '' ?>>⏸ Pause B</button></form><form method="post"><input type="hidden" name="action" value="clear_loaded"><input type="hidden" name="deck" value="b"><button class="mixer-btn red">Clear B</button></form></div>
      <p class="deck-status">Active device reported by Spotify: <strong><?= h($playback['device']['name'] ?? 'none') ?></strong></p>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
