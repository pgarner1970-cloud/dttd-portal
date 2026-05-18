<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', 'changeme');
}

if (isset($_GET['logout'])) {
    unset($_SESSION['dttd_admin']);
    header('Location: /admin/login.php');
    exit;
}

if (empty($_SESSION['dttd_admin'])) {
    header('Location: /admin/login.php');
    exit;
}

function admin_current_page() {
    return basename($_SERVER['SCRIPT_NAME']);
}

function admin_nav_active($page) {
    $script = admin_current_page();

    $map = [
        'requests' => ['requests.php', 'index.php', 'request-debug.php'],
        'events' => ['events.php', 'event-edit.php', 'event-qr.php'],
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
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="/assets/admin-touch.css">
</head>
<body class="admin-body">
<header class="touch-topbar">
  <div class="topbar-left">
    <a class="touch-brand" href="/admin/index.php">
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
        <a class="header-admin-nav-btn <?= admin_nav_active('requests') ?>" href="/admin/requests.php">Requests</a>
        <a class="header-admin-nav-btn <?= admin_nav_active('events') ?>" href="/admin/events.php">Events</a>
        <a class="header-admin-nav-btn <?= admin_nav_active('settings') ?>" href="/admin/settings.php">Settings</a>
      </nav>

      <a class="touch-icon-btn" href="/" title="Public site">⌂</a>
      <a class="touch-icon-btn" href="/admin/logout.php" title="Logout">⏻</a>
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


<?php if (basename($_SERVER['SCRIPT_NAME']) === 'requests.php'): ?>
<script src="/assets/request-update-check.js?v=62"></script>
<?php endif; ?>
<script src="/assets/header-timers.js?v=97"></script>
<script src="/assets/event-qr.js?v=106"></script>
</body>
</html>
<?php
}

/* v110 nav patch placeholder */
