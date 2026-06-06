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

function dttd_playlist_test_token($accountKey) {
    $accountKey = (string)$accountKey;
    if ($accountKey === 'deck_b') {
        return dttd_spotify_user_access_token_for_deck('b');
    }
    if ($accountKey === 'legacy') {
        return dttd_spotify_user_access_token();
    }
    return dttd_spotify_user_access_token_for_deck('a');
}

function dttd_playlist_test_get($url, $token) {
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
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Spotify request failed' . ($error ? ': ' . $error : '.'));
    }

    $data = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300) {
        $message = 'Spotify API returned HTTP ' . $status;
        if (is_array($data) && !empty($data['error'])) {
            if (is_array($data['error'])) {
                $message .= ': ' . (string)($data['error']['message'] ?? json_encode($data['error']));
            } else {
                $message .= ': ' . (string)$data['error'];
            }
        }
        throw new RuntimeException($message);
    }

    return is_array($data) ? $data : [];
}

function dttd_playlist_test_collect_playlists($token, &$debug) {
    $playlists = [];
    $url = 'https://api.spotify.com/v1/me/playlists?limit=50';
    $pages = 0;
    while ($url && $pages < 5) {
        $pages++;
        $debug[] = 'GET ' . preg_replace('/\?.*/', '?…', $url);
        $data = dttd_playlist_test_get($url, $token);
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

function dttd_playlist_test_collect_tracks($playlistId, $token, &$debug) {
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
        $data = dttd_playlist_test_get($url, $token);
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

$account = isset($_GET['account']) ? (string)$_GET['account'] : 'deck_a';
if (!isset(dttd_playlist_test_account_options()[$account])) $account = 'deck_a';
$playlistId = isset($_GET['playlist_id']) ? trim((string)$_GET['playlist_id']) : '';

$error = '';
$debug = [];
$me = [];
$playlists = [];
$trackResult = ['total' => 0, 'tracks' => []];

try {
    if (!dttd_spotify_config_loaded()) {
        throw new RuntimeException('Spotify is not configured. Check the Spotify settings first.');
    }
    $token = dttd_playlist_test_token($account);
    $debug[] = 'Account selected: ' . dttd_playlist_test_account_label($account);
    $me = dttd_playlist_test_get('https://api.spotify.com/v1/me', $token);
    $playlists = dttd_playlist_test_collect_playlists($token, $debug);
    if ($playlistId !== '') {
        $trackResult = dttd_playlist_test_collect_tracks($playlistId, $token, $debug);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<style>
.playlist-test-wrap{max-width:1280px;margin:0 auto;padding:18px}.playlist-test-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:14px}.playlist-test-card{background:rgba(9,20,34,.94);border:1px solid rgba(91,140,192,.28);border-radius:18px;padding:15px;box-shadow:0 20px 50px rgba(0,0,0,.24);margin-bottom:14px}.playlist-test-card h1,.playlist-test-card h2{margin:0 0 8px}.playlist-test-card p{color:#b9cbe0}.playlist-test-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.playlist-test-field label{display:block;font-size:12px;font-weight:900;text-transform:uppercase;color:#9fb5cd;margin-bottom:5px}.playlist-test-field select{background:#0b1524;color:#fff;border:1px solid rgba(96,145,205,.38);border-radius:12px;padding:11px 12px;font-weight:800;min-width:260px}.playlist-test-btn{border:1px solid rgba(52,152,255,.6);background:rgba(52,152,255,.16);color:#bde1ff;border-radius:12px;padding:11px 14px;font-weight:1000;text-decoration:none;display:inline-flex;align-items:center;gap:8px;cursor:pointer}.playlist-test-btn.green{border-color:rgba(34,197,94,.6);background:rgba(34,197,94,.14);color:#9cffc2}.playlist-test-btn.dark{border-color:rgba(140,160,190,.32);background:rgba(16,28,44,.9);color:#dfe8f3}.playlist-test-error{border-color:rgba(255,70,85,.65);color:#ffb4bc;background:rgba(255,70,85,.1)}.playlist-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}.playlist-tile{display:grid;grid-template-columns:58px 1fr;gap:10px;align-items:center;padding:10px;border:1px solid rgba(96,145,205,.22);border-radius:14px;background:rgba(255,255,255,.03);text-decoration:none;color:#fff}.playlist-tile.active{border-color:rgba(34,197,94,.75);box-shadow:0 0 0 2px rgba(34,197,94,.12)}.playlist-tile img,.track-row img{width:58px;height:58px;border-radius:10px;object-fit:cover;background:rgba(255,255,255,.08)}.playlist-title,.track-title{font-weight:1000}.muted{color:#aebfd4}.mini{font-size:13px}.pill{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:1000;text-transform:uppercase;border:1px solid rgba(96,145,205,.35);background:rgba(52,152,255,.1);color:#a8d7ff;margin:3px 4px 0 0}.pill.green{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.12);color:#8dffbb}.pill.warn{border-color:rgba(245,158,11,.55);background:rgba(245,158,11,.12);color:#ffd178}.track-list{max-height:620px;overflow:auto;border:1px solid rgba(96,145,205,.18);border-radius:14px}.track-row{display:grid;grid-template-columns:58px 1fr auto;gap:10px;align-items:center;padding:10px;border-bottom:1px solid rgba(96,145,205,.14);background:rgba(255,255,255,.02)}.track-row:last-child{border-bottom:0}.debug-box{white-space:pre-wrap;font-family:ui-monospace,Consolas,monospace;font-size:12px;color:#b9cbe0;background:rgba(0,0,0,.22);border-radius:12px;padding:12px;overflow:auto}.account-summary{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.playlist-note{border-left:4px solid #facc15;padding:8px 12px;background:rgba(250,204,21,.08);border-radius:10px;color:#ffe99b}@media(max-width:700px){.playlist-test-head{display:block}.track-row{grid-template-columns:48px 1fr}.track-row img{width:48px;height:48px}.track-row .track-extra{grid-column:2}.playlist-test-field select{min-width:100%}}
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
      <input type="hidden" name="playlist_id" value="<?= h($playlistId) ?>">
      <button class="playlist-test-btn green" type="submit">Test account</button>
      <?php if ($playlistId !== ''): ?><a class="playlist-test-btn dark" href="?account=<?= h(rawurlencode($account)) ?>">Clear playlist selection</a><?php endif; ?>
    </form>
  </section>

  <?php if ($error !== ''): ?>
    <section class="playlist-test-card playlist-test-error">
      <h2>Spotify playlist read failed</h2>
      <p><?= h($error) ?></p>
      <p class="mini">If this mentions permissions/scopes, reconnect the Spotify account from Settings so it grants playlist-read-private and playlist-read-collaborative.</p>
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
    <h2>Debug</h2>
    <div class="debug-box"><?= h(implode("\n", $debug) ?: 'No Spotify API calls completed.') ?></div>
  </section>
</main>
<?php admin_footer(); ?>
