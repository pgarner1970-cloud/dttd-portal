<?php
require_once __DIR__ . '/config.php';

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
?>
