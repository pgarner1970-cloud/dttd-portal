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

if ($connected) {
    try {
        $devices = dttd_spotify_get_devices();
        try { $playback = dttd_spotify_current_playback(); } catch (Throwable $ignored) { $playback = null; }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
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
        <h2>Public search profile</h2>
        <?php $publicProfile = dttd_spotify_profile_by_role('public_search'); ?>
        <?php if ($publicProfile && !empty($publicProfile['client_id'])): ?>
          <p>Profile: <strong><?= h($publicProfile['label'] ?? 'Public Search') ?></strong></p>
          <p>Status: <strong><?= !empty($publicProfile['enabled']) ? 'enabled' : 'disabled' ?></strong></p>
          <p>Client ID: <strong><?= h(substr((string)$publicProfile['client_id'], 0, 8)) ?>…</strong></p>
        <?php else: ?>
          <p>No secondary public-search profile configured. Public search will use the primary DJ app, then cache/text-only fallback.</p>
        <?php endif; ?>
        <?php
          try {
            $cacheCount = db()->query('SELECT COUNT(*) FROM spotify_track_cache')->fetchColumn();
          } catch (Throwable $e) { $cacheCount = 'unavailable'; }
        ?>
        <p>Cached tracks: <strong><?= h((string)$cacheCount) ?></strong></p>
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
  </section>
</main>

<p style="margin-top:14px"><a class="btn btn-primary" href="<?= h(admin_url('spotify/mixer.php')) ?>">Open Spotify Mixer</a></p>

<?php admin_footer(); ?>
