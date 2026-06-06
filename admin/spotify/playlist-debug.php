<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

admin_header('Spotify Playlist Test - DJ Portal');

function dttd_playlist_test_account_options() {
    return [
        'deck_a' => 'Deck A Spotify account',
        'deck_b' => 'Deck B Spotify account',
        'legacy' => 'Legacy/default Spotify account',
    ];
}

function dttd_playlist_test_account_label($key) {
    $options = dttd_playlist_test_account_options();
    return isset($options[$key]) ? $options[$key] : $options['deck_a'];
}

function dttd_playlist_test_scope_list($scopeText) {
    $scopeText = trim((string)$scopeText);
    if ($scopeText === '') return [];
    $parts = preg_split('/\s+/', $scopeText);
    $scopes = [];
    foreach ($parts as $scope) {
        $scope = trim((string)$scope);
        if ($scope !== '') $scopes[$scope] = true;
    }
    return array_keys($scopes);
}

function dttd_playlist_test_required_scopes() {
    return ['playlist-read-private', 'playlist-read-collaborative'];
}

function dttd_playlist_test_missing_scopes($scopeText) {
    $granted = array_flip(dttd_playlist_test_scope_list($scopeText));
    $missing = [];
    foreach (dttd_playlist_test_required_scopes() as $scope) {
        if (!isset($granted[$scope])) $missing[] = $scope;
    }
    return $missing;
}

function dttd_playlist_test_profile_columns() {
    static $cols = null;
    if ($cols !== null) return $cols;
    $cols = [];
    try {
        $stmt = db()->query('SHOW COLUMNS FROM spotify_profiles');
        foreach ($stmt->fetchAll() as $row) {
            if (!empty($row['Field'])) $cols[(string)$row['Field']] = true;
        }
    } catch (Throwable $e) {}
    return $cols;
}

function dttd_playlist_test_profile_field(array $profile, $field, $default = '') {
    return array_key_exists($field, $profile) ? $profile[$field] : $default;
}

function dttd_playlist_test_legacy_account_info() {
    return [
        'account_key' => 'legacy',
        'label' => 'Legacy/default Spotify account',
        'source' => 'legacy_app_settings',
        'source_label' => 'Legacy app_settings token',
        'profile_id' => 0,
        'profile_slot' => '',
        'profile_label' => 'Legacy/default',
        'account_email' => '',
        'spotify_user_id' => '',
        'spotify_display_name' => '',
        'enabled' => true,
        'connected' => trim((string)dttd_spotify_setting('spotify_refresh_token', '')) !== '',
        'granted_scopes' => (string)dttd_spotify_setting('spotify_granted_scopes', ''),
        'expires_at' => (string)dttd_spotify_setting('spotify_token_expires_at', ''),
        'token_callback' => function () { return dttd_spotify_user_access_token(); },
    ];
}

function dttd_playlist_test_account_info($accountKey) {
    $accountKey = (string)$accountKey;
    if ($accountKey === 'legacy') return dttd_playlist_test_legacy_account_info();

    $deck = $accountKey === 'deck_b' ? 'b' : 'a';
    $label = $deck === 'b' ? 'Deck B Spotify account' : 'Deck A Spotify account';
    $profile = function_exists('dttd_spotify_profile_by_deck') ? dttd_spotify_profile_by_deck($deck) : null;
    if (is_array($profile) && !empty($profile)) {
        return [
            'account_key' => $accountKey,
            'label' => $label,
            'source' => 'spotify_profiles',
            'source_label' => 'spotify_profiles row #' . (int)($profile['id'] ?? 0),
            'profile_id' => (int)($profile['id'] ?? 0),
            'profile_slot' => (string)dttd_playlist_test_profile_field($profile, 'profile_slot', ''),
            'profile_label' => (string)dttd_playlist_test_profile_field($profile, 'label', $label),
            'account_email' => (string)dttd_playlist_test_profile_field($profile, 'account_email', ''),
            'spotify_user_id' => (string)dttd_playlist_test_profile_field($profile, 'spotify_user_id', ''),
            'spotify_display_name' => (string)dttd_playlist_test_profile_field($profile, 'spotify_display_name', ''),
            'enabled' => !empty($profile['enabled']),
            'connected' => trim((string)($profile['refresh_token'] ?? '')) !== '',
            'granted_scopes' => (string)dttd_playlist_test_profile_field($profile, 'granted_scopes', ''),
            'expires_at' => (string)dttd_playlist_test_profile_field($profile, 'expires_at', ''),
            'token_callback' => function () use ($profile) { return dttd_spotify_profile_access_token($profile); },
        ];
    }

    $legacy = dttd_playlist_test_legacy_account_info();
    $legacy['account_key'] = $accountKey;
    $legacy['label'] = $label;
    $legacy['source'] = 'legacy_fallback';
    $legacy['source_label'] = 'No deck profile assigned; using legacy fallback token';
    return $legacy;
}

function dttd_playlist_test_token(array $info) {
    $callback = $info['token_callback'];
    return $callback();
}

function dttd_playlist_test_get_status($url, $token) {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'PHP cURL is not available.', 'data' => []];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'error' => 'Spotify request failed' . ($error ? ': ' . $error : '.'), 'data' => []];
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) $data = [];

    if ($status < 200 || $status >= 300) {
        $message = 'Spotify API returned HTTP ' . $status;
        if (!empty($data['error'])) {
            if (is_array($data['error'])) {
                $message .= ': ' . (string)($data['error']['message'] ?? json_encode($data['error']));
            } else {
                $message .= ': ' . (string)$data['error'];
            }
        }
        return ['ok' => false, 'status' => $status, 'error' => $message, 'data' => $data];
    }

    return ['ok' => true, 'status' => $status, 'error' => '', 'data' => $data];
}

function dttd_playlist_test_collect_playlists($token, &$debug, &$apiChecks) {
    $playlists = [];
    $url = 'https://api.spotify.com/v1/me/playlists?limit=50';
    $pages = 0;
    while ($url && $pages < 5) {
        $pages++;
        $debug[] = 'GET ' . preg_replace('/\?.*/', '?…', $url);
        $result = dttd_playlist_test_get_status($url, $token);
        $apiChecks[] = ['name' => $pages === 1 ? 'List playlists' : 'List playlists page ' . $pages, 'url' => $url, 'result' => $result];
        if (!$result['ok']) {
            throw new RuntimeException($result['error']);
        }
        $data = $result['data'];
        foreach (($data['items'] ?? []) as $item) {
            if (!is_array($item) || empty($item['id'])) continue;
            $images = $item['images'] ?? [];
            $image = '';
            if (is_array($images) && $images) {
                $last = end($images);
                $image = (string)($last['url'] ?? ($images[0]['url'] ?? ''));
            }
            $playlists[] = [
                'id' => (string)$item['id'],
                'name' => (string)($item['name'] ?? 'Untitled playlist'),
                'owner' => (string)($item['owner']['display_name'] ?? ''),
                'owner_id' => (string)($item['owner']['id'] ?? ''),
                'description' => strip_tags((string)($item['description'] ?? '')),
                'tracks_total' => (int)($item['tracks']['total'] ?? 0),
                'public' => array_key_exists('public', $item) ? $item['public'] : null,
                'collaborative' => !empty($item['collaborative']),
                'image' => $image,
            ];
        }
        $url = !empty($data['next']) ? (string)$data['next'] : '';
    }
    return $playlists;
}

function dttd_playlist_test_collect_tracks($playlistId, $token, &$debug, &$apiChecks) {
    $playlistId = trim((string)$playlistId);
    if ($playlistId === '') return ['total' => 0, 'tracks' => []];

    $fields = 'next,total,items(track(id,name,type,uri,duration_ms,is_local,artists(name),album(name,release_date,images),external_urls(spotify)))';
    $url = 'https://api.spotify.com/v1/playlists/' . rawurlencode($playlistId) . '/tracks?limit=100&fields=' . rawurlencode($fields);
    $tracks = [];
    $total = 0;
    $pages = 0;
    while ($url && $pages < 5) {
        $pages++;
        $debug[] = 'GET ' . preg_replace('/\?.*/', '?…', $url);
        $result = dttd_playlist_test_get_status($url, $token);
        $apiChecks[] = ['name' => $pages === 1 ? 'Read selected playlist tracks' : 'Read selected playlist tracks page ' . $pages, 'url' => $url, 'result' => $result];
        if (!$result['ok']) {
            throw new RuntimeException($result['error']);
        }
        $data = $result['data'];
        $total = (int)($data['total'] ?? $total);
        foreach (($data['items'] ?? []) as $row) {
            $track = $row['track'] ?? null;
            if (!is_array($track)) continue;
            if (($track['type'] ?? '') !== 'track' && empty($track['is_local'])) continue;
            $artists = [];
            foreach (($track['artists'] ?? []) as $artist) {
                if (!empty($artist['name'])) $artists[] = (string)$artist['name'];
            }
            $images = $track['album']['images'] ?? [];
            $image = '';
            if (is_array($images) && $images) {
                $last = end($images);
                $image = (string)($last['url'] ?? ($images[0]['url'] ?? ''));
            }
            $tracks[] = [
                'id' => (string)($track['id'] ?? ''),
                'uri' => (string)($track['uri'] ?? ''),
                'title' => (string)($track['name'] ?? 'Untitled track'),
                'artist' => implode(', ', $artists),
                'album' => (string)($track['album']['name'] ?? ''),
                'release_date' => (string)($track['album']['release_date'] ?? ''),
                'duration_ms' => isset($track['duration_ms']) ? (int)$track['duration_ms'] : 0,
                'is_local' => !empty($track['is_local']),
                'url' => (string)($track['external_urls']['spotify'] ?? ''),
                'image' => $image,
            ];
        }
        $url = !empty($data['next']) ? (string)$data['next'] : '';
    }
    return ['total' => $total, 'tracks' => $tracks];
}

function dttd_playlist_test_ms($ms) {
    $seconds = max(0, (int)round(((int)$ms) / 1000));
    return floor($seconds / 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}

function dttd_playlist_test_mask($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (strlen($value) <= 8) return $value;
    return substr($value, 0, 4) . '…' . substr($value, -4);
}

$account = isset($_GET['account']) ? (string)$_GET['account'] : 'deck_a';
if (!isset(dttd_playlist_test_account_options()[$account])) $account = 'deck_a';
$playlistId = isset($_GET['playlist_id']) ? trim((string)$_GET['playlist_id']) : '';

$error = '';
$errorHint = '';
$debug = [];
$apiChecks = [];
$me = [];
$playlists = [];
$trackResult = ['total' => 0, 'tracks' => []];
$accountInfo = dttd_playlist_test_account_info($account);
$allAccountInfo = [
    'deck_a' => dttd_playlist_test_account_info('deck_a'),
    'deck_b' => dttd_playlist_test_account_info('deck_b'),
    'legacy' => dttd_playlist_test_account_info('legacy'),
];
$missingScopes = dttd_playlist_test_missing_scopes((string)($accountInfo['granted_scopes'] ?? ''));

try {
    if (!dttd_spotify_config_loaded()) {
        throw new RuntimeException('Spotify is not configured. Check the Spotify settings first.');
    }
    if (empty($accountInfo['connected'])) {
        throw new RuntimeException(dttd_playlist_test_account_label($account) . ' is not connected. Reconnect it in Settings first.');
    }

    $token = dttd_playlist_test_token($accountInfo);
    $debug[] = 'Account selected: ' . dttd_playlist_test_account_label($account);
    $debug[] = 'Token source: ' . (string)$accountInfo['source_label'];
    if (!empty($accountInfo['profile_id'])) $debug[] = 'Profile ID: ' . (int)$accountInfo['profile_id'];
    if (!empty($accountInfo['granted_scopes'])) $debug[] = 'Stored granted scopes: ' . (string)$accountInfo['granted_scopes'];

    $meResult = dttd_playlist_test_get_status('https://api.spotify.com/v1/me', $token);
    $apiChecks[] = ['name' => 'Read current Spotify user', 'url' => 'https://api.spotify.com/v1/me', 'result' => $meResult];
    $debug[] = 'GET https://api.spotify.com/v1/me';
    if (!$meResult['ok']) throw new RuntimeException($meResult['error']);
    $me = $meResult['data'];

    $playlists = dttd_playlist_test_collect_playlists($token, $debug, $apiChecks);
    if ($playlistId !== '') {
        $trackResult = dttd_playlist_test_collect_tracks($playlistId, $token, $debug, $apiChecks);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    if (stripos($error, '403') !== false || stripos($error, 'forbidden') !== false) {
        if ($playlistId !== '') {
            $errorHint = 'This can happen if the selected playlist belongs to a different Spotify account, or if this account was not reconnected after playlist scopes were added. Clear the playlist selection, retest the selected account, then choose one of that account’s playlists.';
        } else {
            $errorHint = 'This usually means the selected account token does not have playlist-read-private / playlist-read-collaborative. Reconnect this exact account from Settings, then retest.';
        }
    }
}
?>
<style>
.playlist-test-wrap{max-width:1280px;margin:0 auto;padding:18px}.playlist-test-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}.playlist-test-card{background:rgba(9,20,34,.94);border:1px solid rgba(91,140,192,.28);border-radius:18px;padding:15px;box-shadow:0 20px 50px rgba(0,0,0,.24);margin-bottom:14px}.playlist-test-card h1,.playlist-test-card h2{margin:0 0 8px}.playlist-test-card p{color:#b9cbe0}.playlist-test-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.playlist-test-field label{display:block;font-size:12px;font-weight:900;text-transform:uppercase;color:#9fb5cd;margin-bottom:5px}.playlist-test-field select{background:#0b1524;color:#fff;border:1px solid rgba(96,145,205,.38);border-radius:12px;padding:11px 12px;font-weight:800;min-width:260px}.playlist-test-btn{border:1px solid rgba(52,152,255,.6);background:rgba(52,152,255,.16);color:#bde1ff;border-radius:12px;padding:11px 14px;font-weight:1000;text-decoration:none;display:inline-flex;align-items:center;gap:8px;cursor:pointer}.playlist-test-btn.green{border-color:rgba(34,197,94,.6);background:rgba(34,197,94,.14);color:#9cffc2}.playlist-test-btn.dark{border-color:rgba(140,160,190,.32);background:rgba(16,28,44,.9);color:#dfe8f3}.playlist-test-error{border-color:rgba(255,70,85,.65);color:#ffb4bc;background:rgba(255,70,85,.1)}.playlist-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}.playlist-tile{display:grid;grid-template-columns:58px 1fr;gap:10px;align-items:center;padding:10px;border:1px solid rgba(96,145,205,.22);border-radius:14px;background:rgba(255,255,255,.03);text-decoration:none;color:#fff}.playlist-tile.active{border-color:rgba(34,197,94,.75);box-shadow:0 0 0 2px rgba(34,197,94,.12)}.playlist-tile img,.track-row img{width:58px;height:58px;border-radius:10px;object-fit:cover;background:rgba(255,255,255,.08)}.playlist-title,.track-title{font-weight:1000}.muted{color:#aebfd4}.mini{font-size:13px}.pill{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:1000;text-transform:uppercase;border:1px solid rgba(96,145,205,.35);background:rgba(52,152,255,.1);color:#a8d7ff;margin:3px 4px 0 0}.pill.green{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.12);color:#8dffbb}.pill.warn{border-color:rgba(245,158,11,.55);background:rgba(245,158,11,.12);color:#ffd178}.pill.bad{border-color:rgba(255,70,85,.55);background:rgba(255,70,85,.12);color:#ffb4bc}.track-list{max-height:620px;overflow:auto;border:1px solid rgba(96,145,205,.18);border-radius:14px}.track-row{display:grid;grid-template-columns:58px 1fr auto;gap:10px;align-items:center;padding:10px;border-bottom:1px solid rgba(96,145,205,.14);background:rgba(255,255,255,.02)}.track-row:last-child{border-bottom:0}.debug-box{white-space:pre-wrap;font-family:ui-monospace,Consolas,monospace;font-size:12px;color:#b9cbe0;background:rgba(0,0,0,.22);border-radius:12px;padding:12px;overflow:auto}.account-summary{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.playlist-note{border-left:4px solid #facc15;padding:8px 12px;background:rgba(250,204,21,.08);border-radius:10px;color:#ffe99b}.account-grid,.check-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}.account-box,.check-box{border:1px solid rgba(96,145,205,.22);border-radius:14px;background:rgba(255,255,255,.03);padding:12px}.account-box.active{border-color:rgba(34,197,94,.72);box-shadow:0 0 0 2px rgba(34,197,94,.1)}.account-box h3,.check-box h3{margin:0 0 8px}.scope-list{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.check-box.ok{border-color:rgba(34,197,94,.45)}.check-box.fail{border-color:rgba(255,70,85,.55)}.check-status{font-weight:1000}.check-status.ok{color:#8dffbb}.check-status.fail{color:#ffb4bc}@media(max-width:700px){.playlist-test-head{display:block}.track-row{grid-template-columns:48px 1fr}.track-row img{width:48px;height:48px}.track-row .track-extra{grid-column:2}.playlist-test-field select{min-width:100%}}
</style>
<main class="playlist-test-wrap">
  <div class="playlist-test-head">
    <div>
      <h1>Spotify Playlist Test</h1>
      <p class="muted">Temporary diagnostic page to check whether the connected Spotify account can list playlists and read the tracks inside them.</p>
    </div>
    <a class="playlist-test-btn dark" href="<?= h(admin_url('spotify/mixer.php')) ?>">Back to mixer</a>
  </div>

  <section class="playlist-test-card">
    <form class="playlist-test-form" method="get">
      <div class="playlist-test-field">
        <label for="account">Spotify account to test</label>
        <select id="account" name="account">
          <?php foreach (dttd_playlist_test_account_options() as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $key === $account ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="playlist-test-btn green" type="submit">Test selected account</button>
      <?php if ($playlistId !== ''): ?><a class="playlist-test-btn dark" href="?account=<?= h(rawurlencode($account)) ?>">Clear playlist selection</a><?php endif; ?>
      <a class="playlist-test-btn dark" href="<?= h(admin_url('settings.php#spotify-accounts')) ?>">Open Spotify settings</a>
    </form>
    <p class="mini muted" style="margin:10px 0 0;">Changing the account now clears any previous playlist selection, so this page will not accidentally test Deck A with a Deck B/private playlist ID.</p>
  </section>

  <section class="playlist-test-card">
    <h2>Stored account/token check</h2>
    <div class="account-grid">
      <?php foreach ($allAccountInfo as $key => $info): ?>
        <?php $miss = dttd_playlist_test_missing_scopes((string)($info['granted_scopes'] ?? '')); ?>
        <div class="account-box <?= $key === $account ? 'active' : '' ?>">
          <h3><?= h(dttd_playlist_test_account_label($key)) ?></h3>
          <div class="mini muted">Source: <?= h((string)$info['source_label']) ?></div>
          <?php if (!empty($info['profile_label'])): ?><div class="mini muted">Label: <?= h((string)$info['profile_label']) ?></div><?php endif; ?>
          <?php if (!empty($info['account_email'])): ?><div class="mini muted">Account: <?= h((string)$info['account_email']) ?></div><?php endif; ?>
          <?php if (!empty($info['spotify_user_id'])): ?><div class="mini muted">Spotify ID: <?= h(dttd_playlist_test_mask((string)$info['spotify_user_id'])) ?></div><?php endif; ?>
          <div class="scope-list">
            <span class="pill <?= !empty($info['connected']) ? 'green' : 'bad' ?>"><?= !empty($info['connected']) ? 'Connected' : 'Not connected' ?></span>
            <?php if ($miss): ?>
              <span class="pill bad">Missing playlist scopes</span>
            <?php elseif (trim((string)($info['granted_scopes'] ?? '')) !== ''): ?>
              <span class="pill green">Playlist scopes stored</span>
            <?php else: ?>
              <span class="pill warn">Scopes unknown</span>
            <?php endif; ?>
          </div>
          <?php if ($miss): ?>
            <p class="mini" style="color:#ffb4bc;margin:8px 0 0;">Missing: <?= h(implode(', ', $miss)) ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($error !== ''): ?>
    <section class="playlist-test-card playlist-test-error">
      <h2>Spotify playlist read failed</h2>
      <p><?= h($error) ?></p>
      <?php if ($errorHint !== ''): ?><p class="mini"><?= h($errorHint) ?></p><?php endif; ?>
      <?php if ($missingScopes): ?><p class="mini">Stored token scopes are missing: <?= h(implode(', ', $missingScopes)) ?>. Reconnect <?= h(dttd_playlist_test_account_label($account)) ?> from Settings.</p><?php endif; ?>
    </section>
  <?php else: ?>
    <section class="playlist-test-card">
      <h2>Connected account</h2>
      <div class="account-summary">
        <span class="pill green"><?= h(dttd_playlist_test_account_label($account)) ?></span>
        <span class="pill"><?= h((string)($me['display_name'] ?? $me['id'] ?? 'Spotify user')) ?></span>
        <?php if (!empty($me['email'])): ?><span class="pill"><?= h((string)$me['email']) ?></span><?php endif; ?>
        <span class="pill warn"><?= count($playlists) ?> playlists returned</span>
      </div>
      <p class="playlist-note mini">This page only reads Spotify data. It does not import anything into DJ crates yet.</p>
    </section>

    <section class="playlist-test-card">
      <h2>Playlists</h2>
      <?php if (!$playlists): ?>
        <p class="muted">No playlists were returned for this account.</p>
      <?php else: ?>
        <div class="playlist-grid">
          <?php foreach ($playlists as $playlist): ?>
            <a class="playlist-tile <?= $playlist['id'] === $playlistId ? 'active' : '' ?>" href="?account=<?= h(rawurlencode($account)) ?>&playlist_id=<?= h(rawurlencode($playlist['id'])) ?>">
              <img src="<?= h($playlist['image'] ?: '/assets/glitter-ball-clean.png') ?>" alt="">
              <span>
                <span class="playlist-title"><?= h($playlist['name']) ?></span><br>
                <span class="mini muted"><?= h($playlist['tracks_total']) ?> tracks<?= $playlist['owner'] !== '' ? ' · ' . h($playlist['owner']) : '' ?></span><br>
                <?php if ($playlist['collaborative']): ?><span class="pill warn">Collaborative</span><?php endif; ?>
                <?php if ($playlist['public'] === false): ?><span class="pill">Private</span><?php elseif ($playlist['public'] === true): ?><span class="pill green">Public</span><?php endif; ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($playlistId !== ''): ?>
      <section class="playlist-test-card">
        <h2>Playlist tracks</h2>
        <p class="mini muted">Spotify says this playlist contains <?= h((string)$trackResult['total']) ?> tracks. Showing up to <?= h((string)count($trackResult['tracks'])) ?> tracks on this diagnostic page.</p>
        <?php if (!$trackResult['tracks']): ?>
          <p class="muted">No readable track rows were returned for this playlist.</p>
        <?php else: ?>
          <div class="track-list">
            <?php foreach ($trackResult['tracks'] as $track): ?>
              <div class="track-row">
                <img src="<?= h($track['image'] ?: '/assets/glitter-ball-clean.png') ?>" alt="">
                <div>
                  <div class="track-title"><?= h($track['title']) ?></div>
                  <div class="mini muted"><?= h($track['artist'] ?: 'Unknown artist') ?><?= $track['album'] !== '' ? ' · ' . h($track['album']) : '' ?></div>
                  <?php if ($track['release_date'] !== ''): ?><span class="pill"><?= h(substr($track['release_date'], 0, 4)) ?></span><?php endif; ?>
                  <?php if ($track['is_local']): ?><span class="pill warn">Local item</span><?php endif; ?>
                </div>
                <div class="track-extra mini muted"><?= h(dttd_playlist_test_ms($track['duration_ms'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>

  <section class="playlist-test-card">
    <h2>API checks</h2>
    <?php if (!$apiChecks): ?>
      <p class="muted">No Spotify API checks completed.</p>
    <?php else: ?>
      <div class="check-grid">
        <?php foreach ($apiChecks as $check): ?>
          <?php $ok = !empty($check['result']['ok']); ?>
          <div class="check-box <?= $ok ? 'ok' : 'fail' ?>">
            <h3><?= h((string)$check['name']) ?></h3>
            <div class="check-status <?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? 'OK' : 'Failed' ?> · HTTP <?= h((string)($check['result']['status'] ?? 0)) ?></div>
            <?php if (!$ok): ?><p class="mini" style="color:#ffb4bc;"><?= h((string)($check['result']['error'] ?? 'Unknown error')) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="playlist-test-card">
    <h2>Debug</h2>
    <div class="debug-box"><?= h(implode("\n", $debug) ?: 'No Spotify API calls completed.') ?></div>
  </section>
</main>
<?php admin_footer(); ?>
