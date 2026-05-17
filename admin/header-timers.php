<?php
require_once __DIR__ . '/_auth.php';

header('Content-Type: application/json; charset=utf-8');

function timer_iso_from_event_time($event, $time_key) {
    if (!$event || empty($event['event_date']) || empty($event[$time_key])) {
        return '';
    }

    $base = strtotime($event['event_date'] . ' ' . input_time($event[$time_key]));

    if (!$base) {
        return '';
    }

    if ($time_key === 'end_time' && !empty($event['start_time'])) {
        $start = strtotime($event['event_date'] . ' ' . input_time($event['start_time']));
        if ($start && $base <= $start) {
            $base = strtotime('+1 day', $base);
        }
    }

    return date('c', $base);
}

try {
    $event = null;

    if (function_exists('dttd_get_calculated_current_event')) {
        $event = dttd_get_calculated_current_event();
    }

    if (!$event) {
        $stmt = db()->query("
            SELECT *
            FROM events
            WHERE event_date IS NOT NULL
            ORDER BY event_date ASC, start_time ASC, id ASC
            LIMIT 1
        ");
        $event = $stmt->fetch();
    }

    if (!$event) {
        echo json_encode([
            'ok' => true,
            'has_event' => false,
            'event_end' => '',
            'requests_close' => '',
            'checked_at' => date('H:i:s')
        ]);
        exit;
    }

    $event_end = timer_iso_from_event_time($event, 'end_time');
    $requests_close = !empty($event['requests_close_at']) ? date('c', strtotime($event['requests_close_at'])) : '';

    echo json_encode([
        'ok' => true,
        'has_event' => true,
        'event_id' => (int)$event['id'],
        'event_name' => (string)($event['event_name'] ?? ''),
        'event_end' => $event_end,
        'requests_close' => $requests_close,
        'checked_at' => date('H:i:s')
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Timer endpoint failed',
        'checked_at' => date('H:i:s')
    ]);
}
