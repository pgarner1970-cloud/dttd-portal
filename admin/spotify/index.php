<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

admin_header('Spotify Tools - DJ Portal');
$flash = $_SESSION['spotify_flash'] ?? '';
unset($_SESSION['spotify_flash']);
$configured = dttd_spotify_config_loaded();
$connected = dttd_spotify_queue_connected();
$devices = [];
$playback = null;
$error = '';
$diag = null;

if ($connected) {
    try {
        $devices = dttd_spotify_get_devices();
        try { $playback = dttd_spotify_current_playback(); } catch (Throwable $ignored) { $playback = null; }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    try { $diag = dttd_spotify_playlist_diagnostics(); } catch (Throwable $e) { $diag = ['diagnostic_error' => $e->getMessage()]; }
}
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="panel-head">
      <div>
        <h1 class="touch-panel-title">Spotify Tools</h1>
        <p class="touch-subtitle">Connect the DJ Spotify account and test queue control safely.</p>
      </div>
    </div>

    <?php if ($flash): ?><p class="notice"><?= h($flash) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>

    <div class="settings-grid">
      <div class="setting-card">
        <h2>Connection</h2>
        <p>API config: <strong><?= $configured ? 'configured' : 'missing' ?></strong></p>
        <p>DJ account: <strong><?= $connected ? 'connected' : 'not connected' ?></strong></p>
        <p><a class="touch-button primary" href="connect.php">Connect / Reconnect Spotify</a></p>
        <p class="touch-subtitle">Requires Spotify Premium for playback queue control.</p>
      </div>

      <div class="setting-card">
        <h2>Available devices</h2>
        <?php if (!$connected): ?>
          <p>Connect Spotify first, then open Spotify on the tablet so it appears here.</p>
        <?php elseif (!$devices): ?>
          <p>No active Spotify Connect devices found. Open Spotify on the tablet and start playback.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($devices as $device): ?>
              <li>
                <strong><?= h($device['name'] ?? 'Unnamed device') ?></strong>
                <?= !empty($device['is_active']) ? ' — active' : '' ?>
                <small><?= h($device['type'] ?? '') ?></small>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="setting-card">
        <h2>Currently playing</h2>
        <?php if (!empty($playback['item'])): ?>
          <p><strong><?= h($playback['item']['name'] ?? '') ?></strong></p>
          <p><?= h(implode(', ', array_map(fn($a) => $a['name'] ?? '', $playback['item']['artists'] ?? []))) ?></p>
        <?php else: ?>
          <p>Nothing reported as currently playing.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="setting-card" style="margin-top:18px;">
      <h2>Spotify diagnostics</h2>
      <?php if (!$connected): ?>
        <p>Connect Spotify first to run playlist diagnostics.</p>
      <?php else: ?>
        <?php
          $requested = $diag['requested_scope'] ?? (function_exists('dttd_spotify_requested_scope') ? dttd_spotify_requested_scope() : '');
          $granted = $diag['granted_scope'] ?? dttd_spotify_setting('spotify_granted_scope', '');
          $me = $diag['me'] ?? null;
          $pls = $diag['playlists'] ?? null;
          $tracks = $diag['playlist_tracks'] ?? null;
          $objectTracks = $diag['playlist_object_tracks'] ?? null;
          $first = $diag['first_playlist'] ?? null;
          $playlistCount = is_array($pls) ? count($pls['json']['items'] ?? []) : 0;
          $trackCount = is_array($tracks) ? count($tracks['json']['items'] ?? []) : 0;
          $objectTrackCount = is_array($objectTracks) ? count($objectTracks['json']['tracks']['items'] ?? []) : 0;
        ?>
        <p><strong>Requested scopes:</strong><br><code><?= h($requested) ?></code></p>
        <p><strong>Last granted scopes:</strong><br><code><?= h($granted !== '' ? $granted : 'Not stored yet — reconnect once after this patch') ?></code></p>
        <p><strong>Profile endpoint:</strong> <?= !empty($me['ok']) ? 'OK' : h(dttd_spotify_debug_error_text($me)) ?></p>
        <p><strong>Playlist list endpoint:</strong> <?= !empty($pls['ok']) ? 'OK — ' . (int)$playlistCount . ' playlists returned in test' : h(dttd_spotify_debug_error_text($pls)) ?></p>
        <?php if ($first): ?>
          <p><strong>Track endpoint test playlist:</strong> <?= h($first['name']) ?> <small>(Spotify reports <?= (int)$first['reported_total'] ?> tracks)</small></p>
          <p><strong>Playlist tracks endpoint:</strong> <?= !empty($tracks['ok']) ? 'OK — ' . (int)$trackCount . ' track rows returned in test' : h(dttd_spotify_debug_error_text($tracks)) ?></p>
          <p><strong>Playlist object fallback:</strong> <?= !empty($objectTracks['ok']) ? 'OK — ' . (int)$objectTrackCount . ' embedded track rows returned in test' : h(dttd_spotify_debug_error_text($objectTracks)) ?></p>
          <?php if (!empty($tracks['ok']) && $trackCount === 0): ?>
            <p class="touch-subtitle">Spotify returned zero usable track rows for this playlist. Try a different playlist with normal Spotify tracks, not local files.</p>
          <?php endif; ?>
        <?php else: ?>
          <p><strong>Playlist tracks endpoint:</strong> Not checked because no playlist ID was returned.</p>
        <?php endif; ?>
        <?php if (!empty($diag['diagnostic_error'])): ?><p class="notice error"><?= h($diag['diagnostic_error']) ?></p><?php endif; ?>
        <p class="touch-subtitle">Use this section to confirm whether the portal token has playlist scopes and whether Spotify is allowing playlist track reads.</p>
      <?php endif; ?>
    </div>

  </section>
</main>

<p style="margin-top:14px"><a class="btn btn-primary" href="<?= h(admin_url('spotify/mixer.php')) ?>">Open Spotify Mixer</a></p>

<?php admin_footer(); ?>
