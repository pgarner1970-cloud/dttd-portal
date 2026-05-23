<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', 'changeme');
}

if (isset($_GET['logout'])) {
    unset($_SESSION['dttd_admin']);
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['dttd_admin'])) {
    header('Location: login.php');
    exit;
}

function admin_url($path = '') {
    $path = ltrim((string)$path, '/');
    if ($path === '') {
        return '/';
    }

    // The DJ subdomain is rooted at /admin, while assets live on the public root.
    if (strpos($path, 'assets/') === 0) {
        return 'https://dancethruthedecades.co.uk/' . $path;
    }

    return '/' . $path;
}

function admin_current_page() {
    return basename($_SERVER['SCRIPT_NAME']);
}

function admin_nav_active($page) {
    $script = admin_current_page();

    $map = [
        'mixer' => ['mixer.php'],
        'requests' => ['requests.php', 'index.php', 'request-debug.php'],
        'events' => ['events.php', 'event-edit.php', 'event-qr.php'],
        'venues' => ['venues.php', 'venue-edit.php'],
        'settings' => ['settings.php'],
    ];

    return in_array($script, $map[$page] ?? [], true) ? 'active' : '';
}


function dttd_event_window($event) {
    if (!$event || empty($event['event_date']) || empty($event['start_time'])) {
        return null;
    }

    $date = $event['event_date'];

    $header_show_event_timer = app_setting('header_show_event_timer', '1') === '1';
    $header_show_request_timer = app_setting('header_show_request_timer', '1') === '1';
    $show_live_mixer_nav = app_setting('spotify_enabled', '0') === '1'
        && app_setting('spotify_queue_enabled', '0') === '1'
        && app_setting('spotify_queue_mode', 'standard') === 'mixer';
$start_time = input_time($event['start_time']);
    $end_time = !empty($event['end_time']) ? input_time($event['end_time']) : null;

    try {
        $start = new DateTime($date . ' ' . $start_time);
        $current_from = clone $start;
        $current_from->modify('-1 hour');

        if ($end_time) {
            $end = new DateTime($date . ' ' . $end_time);
            if ($end <= $start) {
                $end->modify('+1 day');
            }
        } else {
            $end = clone $start;
            $end->modify('+6 hours');
        }

        return [
            'start' => $start,
            'current_from' => $current_from,
            'end' => $end,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function dttd_calculated_event_state($event) {
    $window = dttd_event_window($event);
    if (!$window) {
        return 'upcoming';
    }

    $now = new DateTime('now');

    if ($now >= $window['current_from'] && $now <= $window['end']) {
        return 'current';
    }

    if ($now > $window['end']) {
        return 'past';
    }

    return 'upcoming';
}

function dttd_get_calculated_current_event() {
    $stmt = db()->query("
        SELECT *
        FROM events
        WHERE event_date IS NOT NULL
        AND start_time IS NOT NULL
        ORDER BY event_date ASC, start_time ASC, id ASC
    ");
    $events = $stmt->fetchAll();

    foreach ($events as $event) {
        if (dttd_calculated_event_state($event) === 'current') {
            return $event;
        }
    }

    foreach ($events as $event) {
        if (dttd_calculated_event_state($event) === 'upcoming') {
            return $event;
        }
    }

    if ($events) {
        return end($events);
    }

    return null;
}



function app_setting($key, $default = null) {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if ($row && array_key_exists('setting_value', $row)) {
            return $row['setting_value'];
        }
    } catch (Throwable $e) {
        return $default;
    }

    return $default;
}

function save_app_setting($key, $value) {
    try {
        $stmt = db()->prepare("
            INSERT INTO app_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        return $stmt->execute([$key, $value]);
    } catch (Throwable $e) {
        return false;
    }
}
function admin_header($title = 'DJ Portal') {
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
<title><?= h($title) ?></title>
<link rel="stylesheet" href="https://dancethruthedecades.co.uk/assets/admin-touch.css">
<link rel="stylesheet" href="https://dancethruthedecades.co.uk/assets/admin-topbar-icons.css?v=20260522-1810">
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
        <a class="header-admin-nav-btn <?= admin_nav_active('events') ?>" href="<?= h(admin_url('events.php')) ?>" title="Events" aria-label="Events">
          <span class="admin-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M7 2h2v3h6V2h2v3h3a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h3V2Zm13 8H4v10h16V10ZM4 8h16V7H4v1Zm3 4h3v3H7v-3Zm5 0h3v3h-3v-3Z"/></svg>
          </span>
          <span class="admin-nav-text">Events</span>
        </a>
        <a class="header-admin-nav-btn <?= admin_nav_active('venues') ?>" href="<?= h(admin_url('venues.php')) ?>" title="Venues" aria-label="Venues">
          <span class="admin-nav-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M4 21V8l8-5 8 5v13h-5v-6H9v6H4Zm2-2h1v-6h10v6h1V9.1l-6-3.75-6 3.75V19Zm4-8h4V8h-4v3Z"/></svg>
          </span>
          <span class="admin-nav-text">Venues</span>
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

function admin_footer() {
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
</script>
<script src="https://dancethruthedecades.co.uk/assets/request-update-check.js?v=20260523-request-page-seen-baseline"></script>
<script src="https://dancethruthedecades.co.uk/assets/header-timers.js?v=97"></script>
<script src="https://dancethruthedecades.co.uk/assets/event-qr.js?v=106"></script>
<script src="https://dancethruthedecades.co.uk/assets/venue-select.js?v=115"></script>
</body>
</html>
<?php
}

/* v110 nav patch placeholder */
