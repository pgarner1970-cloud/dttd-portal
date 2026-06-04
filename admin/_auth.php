<?php
session_start();

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_auth_cookie.php';

dttd_no_cache_headers();

if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', 'changeme');
}

if (isset($_GET['logout'])) {
    unset($_SESSION['dttd_admin']);
    dttd_admin_clear_auth_cookie();
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['dttd_admin'])) {
    if (dttd_admin_auth_cookie_valid()) {
        $_SESSION['dttd_admin'] = true;
    } else {
        header('Location: login.php');
        exit;
    }
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

    // Admin pages should stay explicit on the DJ subdomain.
    // Public clean URLs such as /events belong to the public site only.
    $dttd_admin_route_aliases = [
        'events' => 'events.php',
        'venues' => 'venues.php',
        'partners' => 'partners.php',
        'sponsors' => 'sponsors.php',
        'event-sponsors' => 'event-sponsors.php',
        'event-photos' => 'event-photos.php',
        'requests' => 'requests.php',
        'settings' => 'settings.php',
        'tools' => 'tools.php',
    ];

    if (isset($dttd_admin_route_aliases[$path])) {
        $path = $dttd_admin_route_aliases[$path];
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
        'photos' => ['event-photos.php'],
        'tools' => ['tools.php', 'local-music.php', 'events.php', 'event-edit.php', 'event-qr.php', 'venues.php', 'venue-edit.php', 'partners.php', 'partner-edit.php', 'sponsors.php', 'event-sponsors.php', 'quote-add.php', 'quotes.php', 'quote-save.php', 'quote-pdf.php', 'quote-convert.php', 'invoices.php', 'request-debug.php', 'events-diagnostic.php', 'regenerate-photo-frames.php', 'repair-upload-paths.php', 'upload-path-check.php'],
    ];

    return in_array($script, $map[$page] ?? [], true) ? 'active' : '';
}


function dttd_event_window($event) {
    if (!$event || empty($event['event_date']) || empty($event['start_time'])) {
        return null;
    }

    $date = $event['event_date'];
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
    require_once __DIR__ . '/_layout.php';
    admin_render_header($title);
}

function admin_footer() {
    require_once __DIR__ . '/_layout.php';
    admin_render_footer();
}

/* v110 nav patch placeholder */
