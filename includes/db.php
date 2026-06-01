<?php
require_once __DIR__ . '/config.php';


function dttd_no_cache_headers() {
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('X-DTTD-Cache-Policy: no-store');
}

function dttd_asset_version($asset_path) {
    $asset_path = ltrim((string)$asset_path, '/');
    $full_path = dirname(__DIR__) . '/' . $asset_path;

    if (is_file($full_path)) {
        return (string)filemtime($full_path);
    }

    return (string)time();
}

function dttd_asset_url($asset_path, $absolute = false) {
    $asset_path = ltrim((string)$asset_path, '/');
    $url = '/' . $asset_path . '?v=' . rawurlencode(dttd_asset_version($asset_path));

    if ($absolute) {
        return 'https://dancethruthedecades.co.uk' . $url;
    }

    return $url;
}

function dttd_cache_meta_tags() {
    return '<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">' . "\n"
        . '<meta http-equiv="Pragma" content="no-cache">' . "\n"
        . '<meta http-equiv="Expires" content="0">';
}

function dttd_bfcache_reload_script() {
    return '<script>(function(){window.addEventListener("pageshow",function(e){if(e.persisted){window.location.reload();}});})();</script>';
}

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function event_type_label($type) {
    $labels = [
        'public' => 'Public Night',
        'private_party' => 'Private Party',
        'wedding' => 'Wedding',
        'corporate' => 'Corporate Event'
    ];
    return $labels[$type] ?? 'Public Night';
}

function active_event() {
    $sql = "
        SELECT *
        FROM events
        WHERE is_active = 1
        AND (
            portal_available_from IS NULL
            OR portal_available_from <= NOW()
        )
        AND (
            portal_available_until IS NULL
            OR portal_available_until >= NOW()
        )
        ORDER BY event_date ASC, id DESC
        LIMIT 1
    ";
    return db()->query($sql)->fetch();
}

function get_event($id) {
    $stmt = db()->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([(int)$id]);
    return $stmt->fetch();
}

function request_event() {
    if (!empty($_GET['event'])) {
        $event = get_event((int)$_GET['event']);
        if ($event) return $event;
    }
    if (!empty($_POST['event_id'])) {
        $event = get_event((int)$_POST['event_id']);
        if ($event) return $event;
    }
    return active_event();
}

function event_is_available($event) {
    if (!$event) return false;
    if ((int)$event['is_active'] !== 1) return false;

    $now = time();

    if (!empty($event['portal_available_from']) && strtotime($event['portal_available_from']) > $now) {
        return false;
    }

    if (!empty($event['portal_available_until']) && strtotime($event['portal_available_until']) < $now) {
        return false;
    }

    return true;
}

function event_requests_open($event) {
    if (!event_is_available($event)) return false;

    $now = time();

    if (!empty($event['requests_close_at']) && strtotime($event['requests_close_at']) < $now) {
        return false;
    }

    return true;
}

function dttd_event_request_close_timestamp($event) {
    if (!$event || empty($event['requests_close_at'])) {
        return null;
    }

    $timestamp = strtotime((string)$event['requests_close_at']);
    return $timestamp ?: null;
}

function dttd_event_request_close_iso($event) {
    $timestamp = dttd_event_request_close_timestamp($event);
    return $timestamp ? date('c', $timestamp) : '';
}

function dttd_event_request_close_clock_label($event) {
    $timestamp = dttd_event_request_close_timestamp($event);
    return $timestamp ? date('H:i', $timestamp) : '';
}

function dttd_event_request_timer_label($event) {
    if (!$event) {
        return '';
    }

    $timestamp = dttd_event_request_close_timestamp($event);

    if (!$timestamp) {
        return event_requests_open($event) ? 'Requests open now' : 'Requests closed';
    }

    $remaining = $timestamp - time();

    if ($remaining <= 0) {
        return 'Requests closed';
    }

    $hours = intdiv($remaining, 3600);
    $minutes = (int)ceil(($remaining % 3600) / 60);

    if ($minutes === 60) {
        $hours++;
        $minutes = 0;
    }

    if ($hours > 0) {
        return 'Requests close in ' . $hours . 'h' . ($minutes > 0 ? ' ' . $minutes . 'm' : '');
    }

    return 'Requests close in ' . max(1, $minutes) . 'm';
}

function build_event_times($event_date, $start_time, $end_time, $close_minutes) {
    $start = new DateTime($event_date . ' ' . $start_time);
    $end = new DateTime($event_date . ' ' . $end_time);

    if ($end <= $start) {
        $end->modify('+1 day');
    }

    $requests_close = clone $end;
    $requests_close->modify('-' . (int)$close_minutes . ' minutes');

    return [
        'portal_available_from' => $start->format('Y-m-d H:i:s'),
        'portal_available_until' => $end->format('Y-m-d H:i:s'),
        'requests_close_at' => $requests_close->format('Y-m-d H:i:s')
    ];
}

function input_time($value) {
    if (!$value) return '';
    return substr((string)$value, 0, 5);
}

function html_dt($value) {
    return $value ? date('Y-m-d\TH:i', strtotime($value)) : '';
}

/*
 * Public event access helpers.
 * Guests do not log in; they receive a temporary, signed browser pass after
 * scanning the event QR code or entering the venue event code.
 */
function dttd_event_column_exists($column) {
    static $cache = [];
    $column = (string)$column;

    if (isset($cache[$column])) {
        return $cache[$column];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM events LIKE ?");
        $stmt->execute([$column]);
        $cache[$column] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function dttd_table_exists($table) {
    static $cache = [];
    $table = (string)$table;

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = db()->query("SHOW TABLES LIKE " . db()->quote($table));
        $cache[$table] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function dttd_table_column_exists($table, $column) {
    static $cache = [];
    $table = (string)$table;
    $column = (string)$column;
    $key = $table . '.' . $column;

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}


function dttd_public_primary_host() {
    if (defined('PUBLIC_SITE_HOST') && PUBLIC_SITE_HOST !== '') {
        return strtolower((string)PUBLIC_SITE_HOST);
    }

    return 'dancethruthedecades.co.uk';
}

function dttd_public_scheme_is_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    return false;
}


function dttd_public_request_base_url($fallback = '') {
    $configured = '';

    if (function_exists('app_setting')) {
        $configured = rtrim((string)app_setting('public_request_base_url', ''), '/');
    }

    $base = $configured !== '' ? $configured : rtrim((string)$fallback, '/');

    if ($base === '') {
        $scheme = dttd_public_scheme_is_https() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? dttd_public_primary_host();
        $base = $scheme . '://' . $host;
    }

    $host = strtolower((string)(parse_url($base, PHP_URL_HOST) ?: ''));
    $portalHosts = [
        'djdancethruthedecades.co.uk',
        'www.djdancethruthedecades.co.uk',
    ];

    if (in_array($host, $portalHosts, true)) {
        return 'https://' . dttd_public_primary_host();
    }

    return rtrim($base, '/');
}

function dttd_redirect_public_feature_to_primary_domain() {
    if (headers_sent()) {
        return;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    $primary = dttd_public_primary_host();

    // QR links have previously been generated on the DJ portal host. Redirect
    // public guest pages to the main public site so the access cookie is stored
    // where guests naturally browse later.
    $portalHosts = [
        'djdancethruthedecades.co.uk',
        'www.djdancethruthedecades.co.uk',
    ];

    if ($primary && $host && $host !== $primary && in_array($host, $portalHosts, true)) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: https://' . $primary . $requestUri, true, 302);
        exit;
    }
}

function dttd_event_access_cookie_name() {
    return 'dttd_event_access';
}

function dttd_event_access_secret() {
    if (defined('EVENT_ACCESS_SECRET') && EVENT_ACCESS_SECRET !== '') {
        return (string)EVENT_ACCESS_SECRET;
    }

    $parts = [
        defined('DB_NAME') ? DB_NAME : '',
        defined('DB_USER') ? DB_USER : '',
        defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : '',
        __DIR__,
    ];

    return hash('sha256', implode('|', $parts));
}

function dttd_b64url_encode($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function dttd_b64url_decode($value) {
    $value = strtr((string)$value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) {
        $value .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($value, true);
    return $decoded === false ? '' : $decoded;
}

function dttd_event_access_sign($payload) {
    return hash_hmac('sha256', (string)$payload, dttd_event_access_secret());
}

function dttd_normalise_event_code($code) {
    return strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$code));
}

function dttd_event_status_value($event) {
    return strtolower(trim((string)($event['status'] ?? 'scheduled'))) ?: 'scheduled';
}

function dttd_event_status_allows_access($event) {
    if (!$event) {
        return false;
    }

    $status = dttd_event_status_value($event);
    return !in_array($status, ['draft', 'cancelled', 'ended'], true);
}

function dttd_event_access_allowed($event) {
    return $event && event_is_available($event) && dttd_event_status_allows_access($event);
}

function dttd_find_event_by_code($code) {
    $code = dttd_normalise_event_code($code);

    if ($code === '' || !dttd_event_column_exists('event_code')) {
        return null;
    }

    try {
        $stmt = db()->prepare("SELECT * FROM events WHERE UPPER(event_code) = UPPER(?) LIMIT 1");
        $stmt->execute([$code]);
        $event = $stmt->fetch();
        return $event ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function dttd_find_event_by_token($token) {
    $token = trim((string)$token);

    if ($token === '') {
        return null;
    }

    foreach (['guest_token', 'public_token', 'qr_token'] as $column) {
        if (!dttd_event_column_exists($column)) {
            continue;
        }

        try {
            $stmt = db()->prepare("SELECT * FROM events WHERE `$column` = ? LIMIT 1");
            $stmt->execute([$token]);
            $event = $stmt->fetch();
            if ($event) {
                return $event;
            }
        } catch (Throwable $e) {
            // Try the next supported token column.
        }
    }

    return null;
}

function dttd_event_access_expires_at($event) {
    $now = time();

    if (!empty($event['portal_available_until'])) {
        $ts = strtotime((string)$event['portal_available_until']);
        if ($ts && $ts > $now) {
            return $ts;
        }
    }

    if (!empty($event['event_date'])) {
        $date = (string)$event['event_date'];
        $start = !empty($event['start_time']) ? substr((string)$event['start_time'], 0, 5) : '19:00';
        $end = !empty($event['end_time']) ? substr((string)$event['end_time'], 0, 5) : '';

        if ($end !== '') {
            $start_ts = strtotime($date . ' ' . $start);
            $end_ts = strtotime($date . ' ' . $end);

            if ($start_ts && $end_ts) {
                if ($end_ts <= $start_ts) {
                    $end_ts = strtotime('+1 day', $end_ts);
                }

                // Give guests time to browse/event gallery after the DJ set ends.
                return max($end_ts + (6 * 3600), $now + 3600);
            }
        }

        $fallback = strtotime($date . ' 06:00 +1 day');
        if ($fallback && $fallback > $now) {
            return $fallback;
        }
    }

    return $now + (8 * 3600);
}

function dttd_set_event_access_cookie($event) {
    if (!$event || empty($event['id']) || headers_sent()) {
        return false;
    }

    $expires = dttd_event_access_expires_at($event);
    $payload = dttd_b64url_encode(json_encode([
        'v' => 1,
        'event_id' => (int)$event['id'],
        'exp' => $expires,
    ]));
    $signature = dttd_event_access_sign($payload);
    $value = $payload . '.' . $signature;

    setcookie(dttd_event_access_cookie_name(), $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => dttd_public_scheme_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_COOKIE[dttd_event_access_cookie_name()] = $value;
    return true;
}

function dttd_clear_event_access_cookie() {
    if (!headers_sent()) {
        setcookie(dttd_event_access_cookie_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => dttd_public_scheme_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    unset($_COOKIE[dttd_event_access_cookie_name()]);
}

function dttd_event_from_access_cookie($require_available = true) {
    $raw = $_COOKIE[dttd_event_access_cookie_name()] ?? '';

    if ($raw === '' || !str_contains($raw, '.')) {
        return null;
    }

    [$payload, $signature] = explode('.', $raw, 2);
    $expected = dttd_event_access_sign($payload);

    if (!hash_equals($expected, $signature)) {
        dttd_clear_event_access_cookie();
        return null;
    }

    $data = json_decode(dttd_b64url_decode($payload), true);

    if (!is_array($data) || empty($data['event_id']) || empty($data['exp'])) {
        dttd_clear_event_access_cookie();
        return null;
    }

    if ((int)$data['exp'] < time()) {
        dttd_clear_event_access_cookie();
        return null;
    }

    $event = get_event((int)$data['event_id']);

    if (!$event) {
        dttd_clear_event_access_cookie();
        return null;
    }

    if ($require_available && !dttd_event_access_allowed($event)) {
        return null;
    }

    return $event;
}

function dttd_handle_event_access_submission($redirect_to = null) {
    $code = $_GET['code'] ?? $_POST['code'] ?? $_POST['event_code'] ?? $_POST['event_access_code'] ?? '';
    $token = $_GET['token'] ?? $_POST['token'] ?? $_GET['access'] ?? $_POST['access'] ?? '';

    $event = null;

    if (trim((string)$code) !== '') {
        $event = dttd_find_event_by_code($code);
    } elseif (trim((string)$token) !== '') {
        $event = dttd_find_event_by_token($token);
    }

    if (!$event) {
        return [null, 'Event code not recognised. Please check the code displayed at the venue.'];
    }

    if (!dttd_event_access_allowed($event)) {
        $status = dttd_event_status_value($event);
        if ($status === 'cancelled') {
            return [$event, 'This event has been cancelled.'];
        }
        if ($status === 'ended') {
            return [$event, 'This event has ended.'];
        }
        if ($status === 'draft') {
            return [$event, 'This event is not available yet.'];
        }
        return [$event, 'This event is not currently open.'];
    }

    dttd_set_event_access_cookie($event);

    if ($redirect_to && !headers_sent()) {
        header('Location: ' . $redirect_to);
        exit;
    }

    return [$event, ''];
}

function dttd_public_event_date($event) {
    if (empty($event['event_date'])) {
        return '';
    }

    try {
        return (new DateTime($event['event_date']))->format('D j M Y');
    } catch (Throwable $e) {
        return (string)$event['event_date'];
    }
}

function dttd_public_event_time_range($event) {
    $start = trim((string)($event['start_time'] ?? ''));
    $end = trim((string)($event['end_time'] ?? ''));

    if ($start && strlen($start) >= 5) {
        $start = substr($start, 0, 5);
    }

    if ($end && strlen($end) >= 5) {
        $end = substr($end, 0, 5);
    }

    if ($start && $end) {
        return $start . ' - ' . $end;
    }

    return $start ?: $end;
}

?>
