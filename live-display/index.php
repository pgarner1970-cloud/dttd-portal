<?php
require_once __DIR__ . '/../includes/db.php';
dttd_no_cache_headers();
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
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
$displayMode = (isset($_GET['mode']) && strtolower((string)$_GET['mode']) === 'lite') ? 'lite' : 'full';
$bodyClass = 'display-body' . ($displayMode === 'lite' ? ' display-lite' : '');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?= dttd_cache_meta_tags() ?>
  <title>Dance Through The Decades — Event Display</title>
  <meta name="robots" content="noindex,nofollow,noarchive">
  <link rel="icon" href="/assets/favicon-dj-192.png">
  <link rel="stylesheet" href="/assets/display.css">
</head>
<body class="<?= h($bodyClass) ?>">
  <main class="display-shell" data-state-url="<?= h($stateUrl) ?>" data-now-playing-url="<?= h($nowPlayingUrl) ?>" data-display-mode="<?= h($displayMode) ?>">
    <div class="display-bg-orb one"></div>
    <div class="display-bg-orb two"></div>

    <header class="display-header">
      <div class="display-brand" aria-label="Dance Through The Decades Events">
        <span class="display-brand-logo">
          <img src="/assets/dttd-logo-inner.png" alt="Dance Through The Decades Events">
        </span>
        <span class="display-brand-wordmark">
          <strong>Dance Thru The Decades</strong>
          <em>Event Display</em>
        </span>
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
      <span class="display-progress-wrap"><span class="display-footer-dot"></span><span class="display-slide-countdown" data-slide-countdown>--</span></span>
      <span>Requests • Photos • Music • Memories</span>
    </footer>
  </main>
  <script src="/assets/display-player.js"></script>
</body>
</html>
