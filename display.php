<?php
require_once __DIR__ . '/includes/db.php';
dttd_no_cache_headers();
$eventParam = '';
if (isset($_GET['event_id'])) {
    $eventParam = 'event_id=' . rawurlencode((string)(int)$_GET['event_id']);
} elseif (isset($_GET['event'])) {
    $eventParam = 'event_id=' . rawurlencode((string)(int)$_GET['event']);
} elseif (!empty($_GET['code'])) {
    $eventParam = 'code=' . rawurlencode((string)$_GET['code']);
}
$stateUrl = '/api/display-state.php' . ($eventParam !== '' ? '?' . $eventParam : '');
$nowPlayingUrl = '/api/public-now-playing.php' . ($eventParam !== '' && str_starts_with($eventParam, 'event_id=') ? '?' . $eventParam : '');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?= dttd_cache_meta_tags() ?>
  <title>Dance Through The Decades — Event Display</title>
  <link rel="icon" href="<?= h(dttd_asset_url('assets/favicon-dj-192.png')) ?>">
  <link rel="stylesheet" href="<?= h(dttd_asset_url('assets/display.css')) ?>">
</head>
<body class="display-body">
  <main class="display-shell" data-state-url="<?= h($stateUrl) ?>" data-now-playing-url="<?= h($nowPlayingUrl) ?>">
    <div class="display-bg-orb one"></div>
    <div class="display-bg-orb two"></div>

    <header class="display-header">
      <div class="display-brand">
        <img src="<?= h(dttd_asset_url('assets/dttd-neon-logo.png')) ?>" alt="Dance Through The Decades">
      </div>
      <div class="display-clock" data-display-clock>--:--</div>
    </header>

    <section class="display-stage" aria-live="polite">
      <article class="display-slide active" data-slide="loading">
        <div class="display-card display-card-centre">
          <p class="display-kicker">Dance Through The Decades</p>
          <h1>Loading event display</h1>
          <p class="display-muted">Preparing the event screen…</p>
        </div>
      </article>
    </section>

    <footer class="display-footer">
      <span data-display-footer-event>Dance Through The Decades</span>
      <span class="display-footer-dot"></span>
      <span>Requests • Photos • Music • Memories</span>
    </footer>
  </main>
  <script src="<?= h(dttd_asset_url('assets/display-player.js')) ?>"></script>
</body>
</html>
