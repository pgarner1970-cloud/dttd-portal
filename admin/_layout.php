<?php
/**
 * Shared admin shell for the DJ Portal.
 *
 * Keep live-operation header/navigation and shared polling scripts here so every
 * admin page uses the same top bar without adding a second row or extra height.
 */

function admin_render_header($title = 'DJ Portal') {

    $time = date('H:i');
    $date = date('D, j M');
    $header_show_event_timer = app_setting('header_show_event_timer', '1') === '1';
    $header_show_request_timer = app_setting('header_show_request_timer', '1') === '1';
    $show_live_mixer_nav = app_setting('spotify_enabled', '0') === '1'
        && app_setting('spotify_queue_enabled', '0') === '1'
        && app_setting('spotify_queue_mode', 'standard') === 'mixer';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= dttd_cache_meta_tags() ?>
<title><?= h($title) ?></title>
<link rel="stylesheet" href="<?= h(dttd_asset_url('assets/admin-touch.css', true)) ?>">
<link rel="stylesheet" href="<?= h(dttd_asset_url('assets/admin-topbar-icons.css', true)) ?>">
</head>
<body class="admin-body">
<header class="touch-topbar">
  <div class="topbar-left">
    <a class="touch-brand" href="index.php">
      <span class="touch-brand-mark">♫</span>
      <span>DJ Portal</span>
    </a>
  </div>

  <div class="topbar-centre">
    <div class="touch-clock">
      <strong id="adminHeaderClock"><?= h($time) ?></strong>
      <span id="adminHeaderDate"><?= h($date) ?></span>
    </div>

    <?php if ($header_show_event_timer || $header_show_request_timer): ?>
      <div class="header-timer-cluster" id="headerTimerCluster">
        <?php if ($header_show_event_timer): ?>
          <div class="touch-clock touch-header-timer timer-loading" id="headerEventTimer">
            <strong id="headerEventTimerValue">--:--:--</strong>
            <span>Event timer</span>
          </div>
        <?php endif; ?>

        <?php if ($header_show_request_timer): ?>
          <div class="touch-clock touch-header-timer timer-loading" id="headerRequestTimer">
            <strong id="headerRequestTimerValue">--:--:--</strong>
            <span>Requests close</span>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="topbar-right">
    <div class="touch-top-actions">
      <nav class="header-admin-nav" aria-label="Admin navigation">
        <?php if ($show_live_mixer_nav): ?>
          <a class="header-admin-nav-btn admin-nav-mixer <?= admin_nav_active('mixer') ?>" href="<?= h(admin_url('spotify/mixer.php')) ?>" title="Live mixer" aria-label="Live mixer">
            <span class="admin-nav-icon admin-nav-icon-svg" aria-hidden="true">
              <svg viewBox="0 0 24 24" focusable="false" role="img">
                <path d="M5 4v16M12 4v16M19 4v16" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"/>
                <circle cx="5" cy="9" r="2.6" fill="currentColor"/>
                <circle cx="12" cy="15" r="2.6" fill="currentColor"/>
                <circle cx="19" cy="7" r="2.6" fill="currentColor"/>
              </svg>
            </span>
            <span class="admin-nav-text">Live Mixer</span>
          </a>
        <?php endif; ?>
        <a id="adminRequestsNavBtn" class="header-admin-nav-btn <?= admin_nav_active('requests') ?>" href="<?= h(admin_url('requests.php')) ?>" title="Requests" aria-label="Requests">
          <span class="admin-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 2a7 7 0 1 1 0 14 7 7 0 0 1 0-14Zm0 4a3 3 0 1 0 .01 0H12Zm0 2a1 1 0 1 1 0 2 1 1 0 0 1 0-2Z"/></svg>
          </span>
          <span class="admin-nav-text">Requests</span>
        </a>
        <a id="adminPhotosNavBtn" class="header-admin-nav-btn <?= admin_nav_active('photos') ?>" href="<?= h(admin_url('event-photos.php')) ?>" title="Photos" aria-label="Photos">
          <span class="admin-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M5 5h4l1.2-2h3.6L15 5h4a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm7 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0-2.1a1.9 1.9 0 1 1 0-3.8 1.9 1.9 0 0 1 0 3.8Z"/></svg>
          </span>
          <span class="admin-nav-text">Photos</span>
        </a>
        <a class="header-admin-nav-btn <?= admin_nav_active('tools') ?>" href="<?= h(admin_url('tools.php')) ?>" title="Admin tools" aria-label="Admin tools">
          <span class="admin-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M4 4h7v7H4V4Zm2 2v3h3V6H6Zm7-2h7v7h-7V4Zm2 2v3h3V6h-3ZM4 13h7v7H4v-7Zm2 2v3h3v-3H6Zm11-2 1.55 2.82L22 16.4l-2.42 2.28.5 3.32L17 20.42 13.92 22l.5-3.32L12 16.4l3.45-.58L17 13Z"/></svg>
          </span>
          <span class="admin-nav-text">Admin Tools</span>
        </a>
        <a class="header-admin-nav-btn <?= admin_nav_active('settings') ?>" href="<?= h(admin_url('settings.php')) ?>" title="Settings" aria-label="Settings">
          <span class="admin-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M19.43 12.98c.04-.32.07-.65.07-.98s-.02-.66-.07-.98l2.11-1.65-2-3.46-2.49 1a7.2 7.2 0 0 0-1.69-.98L15 3h-4l-.36 2.93c-.6.24-1.17.56-1.69.98l-2.49-1-2 3.46 2.11 1.65c-.04.32-.07.65-.07.98s.02.66.07.98l-2.11 1.65 2 3.46 2.49-1c.52.4 1.09.73 1.69.98L11 21h4l.36-2.93c.6-.24 1.17-.56 1.69-.98l2.49 1 2-3.46-2.11-1.65ZM12 15.5A3.5 3.5 0 1 1 12 8a3.5 3.5 0 0 1 0 7.5Z"/></svg>
          </span>
          <span class="admin-nav-text">Settings</span>
        </a>
      </nav>

      <a class="touch-icon-btn" href="https://dancethruthedecades.co.uk/" title="Public site" aria-label="Public site"><span class="touch-action-icon"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 3 3 10.5l1.3 1.52L6 10.62V20h5v-5h2v5h5v-9.38l1.7 1.4L21 10.5 12 3Zm4 15h-1v-5H9v5H8V8.95l4-3.34 4 3.34V18Z"/></svg></span></a>
      <a class="touch-icon-btn" href="<?= h(admin_url('logout.php')) ?>" title="Logout" aria-label="Logout"><span class="touch-action-icon"><svg viewBox="0 0 24 24" focusable="false"><path d="M13 3h-2v10h2V3Zm4.83 3.17-1.42 1.42A6.96 6.96 0 0 1 19 13a7 7 0 1 1-11.41-5.41L6.17 6.17A9 9 0 1 0 21 13a8.94 8.94 0 0 0-3.17-6.83Z"/></svg></span></a>
    </div>
  </div>
</header>
<?php
}

function admin_render_footer() {
    ?>

<!-- Admin Header Live Clock JS -->
<script>
(function(){
  const clock = document.getElementById('adminHeaderClock');
  const dateEl = document.getElementById('adminHeaderDate');

  if (!clock) return;

  function pad(value){
    return String(value).padStart(2, '0');
  }

  function updateClock(){
    const now = new Date();
    clock.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());

    if (dateEl) {
      dateEl.textContent = now.toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short'
      });
    }
  }

  updateClock();
  window.setInterval(updateClock, 1000);
})();
</script>


<script>
window.DTTD_REQUEST_PING_URL = "<?= h(admin_url('request-ping.php')) ?>";
window.DTTD_REQUESTS_URL = "<?= h(admin_url('requests.php')) ?>";
window.DTTD_IS_REQUESTS_PAGE = <?= basename($_SERVER['SCRIPT_NAME']) === 'requests.php' ? 'true' : 'false' ?>;
window.DTTD_IS_PHOTOS_PAGE = <?= basename($_SERVER['SCRIPT_NAME']) === 'event-photos.php' ? 'true' : 'false' ?>;
</script>
<script src="<?= h(dttd_asset_url('assets/request-update-check.js', true)) ?>"></script>
<script src="<?= h(dttd_asset_url('assets/header-timers.js', true)) ?>"></script>
<script src="<?= h(dttd_asset_url('assets/event-qr.js', true)) ?>"></script>
<script src="<?= h(dttd_asset_url('assets/venue-select.js', true)) ?>"></script>
<?= dttd_bfcache_reload_script() ?>
</body>
</html>
<?php
}

