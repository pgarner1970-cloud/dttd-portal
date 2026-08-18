<?php
require_once __DIR__ . '/../includes/db.php';
dttd_no_cache_headers();
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
$scriptBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$scriptBase = $scriptBase === '/' ? '' : $scriptBase;
$liveAssetUrl = function($path) use ($scriptBase) {
    $path = ltrim((string)$path, '/');
    $fullPath = __DIR__ . '/' . $path;
    $version = is_file($fullPath) ? (string)filemtime($fullPath) : (string)time();
    return $scriptBase . '/' . $path . '?v=' . rawurlencode($version);
};
$eventParam = '';
if (isset($_GET['event_id'])) {
    $eventParam = 'event_id=' . rawurlencode((string)(int)$_GET['event_id']);
} elseif (isset($_GET['event'])) {
    $eventParam = 'event_id=' . rawurlencode((string)(int)$_GET['event']);
} elseif (!empty($_GET['code'])) {
    $eventParam = 'code=' . rawurlencode((string)$_GET['code']);
}
$stateUrl = $scriptBase . '/api/display-state.php' . ($eventParam !== '' ? '?' . $eventParam : '');
$nowPlayingUrl = $scriptBase . '/api/public-now-playing.php' . ($eventParam !== '' && str_starts_with($eventParam, 'event_id=') ? '?' . $eventParam : '');
$requestedMode = strtolower((string)($_GET['mode'] ?? 'full'));
$displayMode = $requestedMode === 'lite' ? 'lite' : 'full';
$initialOperatingMode = in_array($requestedMode, ['logo', 'blank'], true) ? $requestedMode : 'live';
$nodeKey = trim((string)($_GET['node'] ?? $_GET['node_key'] ?? ''));
$controlUrl = $scriptBase . '/api/display-control-state.php' . ($nodeKey !== '' ? '?node=' . rawurlencode($nodeKey) : '');
$bodyClass = 'display-body' . ($displayMode === 'lite' ? ' display-lite' : '') . ' display-operating-' . $initialOperatingMode;
$websiteUrl = rtrim(dttd_public_request_base_url('https://dancethruthedecades.co.uk'), '/') . '/';
$facebookUrl = defined('FACEBOOK_URL') && trim((string)FACEBOOK_URL) !== ''
    ? trim((string)FACEBOOK_URL)
    : 'https://www.facebook.com/profile.php?id=61579454050951';
$websiteQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=900x900&margin=24&data=' . rawurlencode($websiteUrl);
$facebookQrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=900x900&margin=24&data=' . rawurlencode($facebookUrl);
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <?= dttd_cache_meta_tags() ?>
  <title>Dance Through The Decades — Event Display</title>
  <meta name="robots" content="noindex,nofollow,noarchive">
  <link rel="icon" href="<?= h($liveAssetUrl('assets/favicon-dj-192.png')) ?>">
  <link rel="stylesheet" href="<?= h($liveAssetUrl('assets/display.css')) ?>">
</head>
<body class="<?= h($bodyClass) ?>">
  <main class="display-shell" data-state-url="<?= h($stateUrl) ?>" data-now-playing-url="<?= h($nowPlayingUrl) ?>" data-display-mode="<?= h($displayMode) ?>" data-operating-mode="<?= h($initialOperatingMode) ?>" data-control-url="<?= h($controlUrl) ?>" data-node-key="<?= h($nodeKey) ?>">
    <section class="display-mode-overlay display-logo-overlay" data-display-logo-overlay aria-hidden="true">
      <div class="display-logo-hold-grid">
        <div class="display-logo-hold-qr">
          <img src="<?= h($websiteQrUrl) ?>" alt="Dance Thru The Decades website QR code">
          <span>Website</span>
        </div>
        <div class="display-logo-hold-brand">
          <img src="<?= h($liveAssetUrl('assets/dttd-logo-inner.png')) ?>" alt="Dance Thru The Decades">
        </div>
        <div class="display-logo-hold-qr">
          <img src="<?= h($facebookQrUrl) ?>" alt="Dance Thru The Decades Facebook QR code">
          <span>Facebook</span>
        </div>
      </div>
    </section>
    <section class="display-mode-overlay display-blank-overlay" data-display-blank-overlay aria-hidden="true"></section>
    <div class="display-bg-orb one"></div>
    <div class="display-bg-orb two"></div>

    <header class="display-header">
      <div class="display-brand" aria-label="Dance Through The Decades Events">
        <span class="display-brand-logo">
          <img src="<?= h($liveAssetUrl('assets/dttd-logo-inner.png')) ?>" alt="Dance Through The Decades Events">
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
      <span class="display-progress-wrap"><span class="display-footer-dot"></span><span class="display-slide-countdown" data-slide-countdown>--</span><span class="display-slide-id" data-slide-id>loading</span></span>
      <span>Requests • Photos • Music • Memories</span>
    </footer>
  </main>
  <script src="<?= h($liveAssetUrl('assets/display-player.js')) ?>"></script>
  <script>
    (function(){
      var footerId = document.querySelector('[data-slide-id]');
      var stage = document.querySelector('.display-stage');
      if (!footerId || !stage) return;
      function syncSlideId(){
        var active = stage.querySelector('.display-slide.active[data-slide]:not([data-slide="loading"])') || stage.querySelector('.display-slide.active[data-slide]');
        footerId.textContent = active ? (active.getAttribute('data-slide') || 'loading') : 'loading';
      }
      syncSlideId();
      new MutationObserver(syncSlideId).observe(stage, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'data-slide'] });
      setInterval(syncSlideId, 500);
    })();
  </script>
</body>
</html>
