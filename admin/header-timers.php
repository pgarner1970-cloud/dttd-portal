<?php
require_once __DIR__ . '/_auth.php';

header('Content-Type: application/json; charset=utf-8');

function header_timer_normalise_time($value) {
    if (function_exists('input_time')) {
        return input_time($value);
    }

    if (!$value) {
        return '';
    }

    $ts = strtotime((string)$value);
    return $ts ? date('H:i', $ts) : '';
}

function header_timer_event_window($event) {
    if (!$event || empty($event['event_date']) || empty($event['start_time'])) {
        return ['state' => 'unknown', 'start' => null, 'end' => null];
    }

    $start = strtotime($event['event_date'] . ' ' . header_timer_normalise_time($event['start_time']));
    $end = !empty($event['end_time'])
        ? strtotime($event['event_date'] . ' ' . header_timer_normalise_time($event['end_time']))
        : null;

    if ($start && $end && $end <= $start) {
        $end = strtotime('+1 day', $end);
    }

    $now = time();

    if ($start && $end && $now >= ($start - 3600) && $now <= $end) {
        return ['state' => 'current', 'start' => $start, 'end' => $end];
    }

    if ($start && $now < $start) {
        return ['state' => 'upcoming', 'start' => $start, 'end' => $end];
    }

    return ['state' => 'past', 'start' => $start, 'end' => $end];
}

try {
    $event = null;

    if (function_exists('dttd_get_calculated_current_event')) {
        $event = dttd_get_calculated_current_event();
    }

    if (!$event) {
        $events = db()->query("
            SELECT *
            FROM events
            WHERE event_date IS NOT NULL
            ORDER BY event_date ASC, start_time ASC, id ASC
        ")->fetchAll();

        foreach ($events as $candidate) {
            $window = header_timer_event_window($candidate);
            if ($window['state'] === 'current') {
                $event = $candidate;
                break;
            }
        }

        if (!$event) {
            foreach ($events as $candidate) {
                $window = header_timer_event_window($candidate);
                if ($window['state'] === 'upcoming') {
                    $event = $candidate;
                    break;
                }
            }
        }
    }

    if (!$event) {
        echo json_encode([
            'ok' => true,
            'has_event' => false,
            'checked_at' => date('H:i:s')
        ]);
        exit;
    }

    $window = header_timer_event_window($event);
    $event_end_iso = $window['end'] ? date('c', $window['end']) : '';

    $requests_close_iso = '';
    if (!empty($event['requests_close_at'])) {
        $close_ts = strtotime($event['requests_close_at']);
        $requests_close_iso = $close_ts ? date('c', $close_ts) : '';
    }

    echo json_encode([
        'ok' => true,
        'has_event' => true,
        'event_id' => (int)$event['id'],
        'event_name' => (string)($event['event_name'] ?? ''),
        'state' => $window['state'],
        'event_end' => $event_end_iso,
        'requests_close' => $requests_close_iso,
        'checked_at' => date('H:i:s')
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'has_event' => false,
        'error' => 'Header timer endpoint failed',
        'checked_at' => date('H:i:s')
    ]);
}
