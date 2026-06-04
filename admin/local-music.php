<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/local-music.php';

$message = '';
$error = '';
$generatedKey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'generate_key') {
            $generatedKey = dttd_local_music_generate_sync_key();
            $message = 'Local music sync key generated. Copy it now and store it on the Lenovo agent.';
        } elseif ($action === 'clear_key') {
            dttd_local_music_set_setting('local_music_sync_key', '');
            $message = 'Local music sync key cleared.';
        } elseif ($action === 'toggle_track' && dttd_local_music_table_exists('local_tracks')) {
            $trackId = (int)($_POST['track_id'] ?? 0);
            $enabled = !empty($_POST['enabled']) ? 1 : 0;
            $stmt = db()->prepare("UPDATE local_tracks SET is_enabled = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$enabled, $trackId]);
            $message = 'Track updated.';
        }
    } catch (Throwable $e) {
        $error = 'Local music admin action failed.';
    }
}

$key = dttd_local_music_sync_key();
$counts = dttd_local_music_counts();
$tracks = dttd_local_music_recent_tracks(80);
$syncEndpoint = 'https://dancethruthedecades.co.uk/api/local-music-sync.php';
$searchEndpoint = 'https://dancethruthedecades.co.uk/api/local-music-search.php?q=test';

admin_header('Local Music - DJ Portal');
?>
<style>
.local-music-alert{margin:0 0 16px;padding:14px 16px;border-radius:14px;border:1px solid rgba(148,163,184,.28);background:rgba(15,23,42,.72);color:#e5e7eb;}
.local-music-alert.success{border-color:rgba(34,197,94,.45);background:rgba(34,197,94,.12);color:#bbf7d0;}
.local-music-alert.danger{border-color:rgba(239,68,68,.48);background:rgba(239,68,68,.12);color:#fecaca;}
.local-music-table{width:100%;border-collapse:separate;border-spacing:0 10px;}
.local-music-table th{color:#93c5fd;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.06em;padding:0 10px 6px;}
.local-music-table td{background:rgba(15,23,42,.72);border-top:1px solid rgba(148,163,184,.18);border-bottom:1px solid rgba(148,163,184,.18);padding:12px 10px;vertical-align:middle;}
.local-music-table td:first-child{border-left:1px solid rgba(148,163,184,.18);border-radius:14px 0 0 14px;}
.local-music-table td:last-child{border-right:1px solid rgba(148,163,184,.18);border-radius:0 14px 14px 0;}
.local-music-table code{white-space:normal;word-break:break-word;color:#bfdbfe;}
</style>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Local Music</h1>
        <p class="touch-panel-subtitle">Phase 1 foundation for indexing the Lenovo SSD music library into the hosted DJ database.</p>
      </div>
    </div>

    <?php if ($message): ?><div class="local-music-alert success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="local-music-alert danger"><?= h($error) ?></div><?php endif; ?>

    <?php if (!$counts['table_exists']): ?>
      <div class="local-music-alert danger">
        <strong>Database table missing.</strong><br>
        Run the supplied SQL for <code>local_tracks</code> in Navicat before using the scanner/sync endpoint.
      </div>
    <?php endif; ?>

    <div class="admin-home-grid">
      <div class="admin-home-card">
        <span class="admin-home-icon">♫</span>
        <strong><?= (int)$counts['total'] ?></strong>
        <span>Total local tracks indexed</span>
      </div>
      <div class="admin-home-card">
        <span class="admin-home-icon">✓</span>
        <strong><?= (int)$counts['enabled'] ?></strong>
        <span>Enabled for DJ search</span>
      </div>
      <div class="admin-home-card">
        <span class="admin-home-icon">!</span>
        <strong><?= (int)$counts['needs_review'] ?></strong>
        <span>Need metadata review</span>
      </div>
      <div class="admin-home-card">
        <span class="admin-home-icon">?</span>
        <strong><?= (int)$counts['missing'] ?></strong>
        <span>Missing from last scan</span>
      </div>
    </div>
  </section>

  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h2 class="touch-panel-title">Lenovo scanner connection</h2>
        <p class="touch-panel-subtitle">The hosted site cannot see the SSD directly. The local Lenovo agent will POST scan results here.</p>
      </div>
    </div>

    <div class="form-grid">
      <label class="form-field span-12">
        <span>Sync endpoint</span>
        <input type="text" value="<?= h($syncEndpoint) ?>" readonly onclick="this.select()">
      </label>
      <label class="form-field span-12">
        <span>Search endpoint test</span>
        <input type="text" value="<?= h($searchEndpoint) ?>" readonly onclick="this.select()">
      </label>
      <label class="form-field span-12">
        <span>Current sync key</span>
        <input type="text" value="<?= h(dttd_local_music_mask_key($key)) ?>" readonly>
      </label>
      <?php if ($generatedKey): ?>
        <label class="form-field span-12">
          <span>New sync key — copy now</span>
          <input type="text" value="<?= h($generatedKey) ?>" readonly onclick="this.select()">
        </label>
      <?php endif; ?>
    </div>

    <form method="post" class="form-actions" style="margin-top:1rem;">
      <button class="touch-btn primary" type="submit" name="action" value="generate_key"><?= $key ? 'Regenerate sync key' : 'Generate sync key' ?></button>
      <?php if ($key): ?><button class="touch-btn ghost" type="submit" name="action" value="clear_key">Clear sync key</button><?php endif; ?>
    </form>
  </section>

  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h2 class="touch-panel-title">Recently indexed tracks</h2>
        <p class="touch-panel-subtitle">This is an admin view only. Public guest search remains Spotify-only until we explicitly enable local public search.</p>
      </div>
    </div>

    <?php if (!$tracks): ?>
      <p class="muted">No local tracks have been indexed yet.</p>
    <?php else: ?>
      <div class="local-music-table-wrap">
        <table class="local-music-table">
          <thead>
            <tr>
              <th>Track</th>
              <th>Path</th>
              <th>Status</th>
              <th>Updated</th>
              <th>Enabled</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tracks as $track):
              $out = dttd_local_music_track_output($track);
            ?>
              <tr>
                <td>
                  <strong><?= h($out['title']) ?></strong><br>
                  <span class="muted"><?= h($out['artist']) ?><?= $out['album'] ? ' · ' . h($out['album']) : '' ?></span>
                </td>
                <td><code><?= h($track['relative_path'] ?? '') ?></code></td>
                <td>
                  <?= !empty($track['missing_since_at']) ? '<span class="status-badge rejected">Missing</span>' : '<span class="status-badge played">Seen</span>' ?>
                  <?= !empty($track['needs_review']) ? '<span class="status-badge pending">Review</span>' : '' ?>
                </td>
                <td><?= h($track['updated_at'] ?? '') ?></td>
                <td>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="toggle_track">
                    <input type="hidden" name="track_id" value="<?= (int)$track['id'] ?>">
                    <input type="hidden" name="enabled" value="<?= !empty($track['is_enabled']) ? '0' : '1' ?>">
                    <button class="touch-btn <?= !empty($track['is_enabled']) ? 'ghost' : 'primary' ?>" type="submit"><?= !empty($track['is_enabled']) ? 'Disable' : 'Enable' ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>
<?php admin_footer(); ?>
