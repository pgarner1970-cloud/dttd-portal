<?php
require_once __DIR__ . '/_auth.php';

header('Content-Type: application/json; charset=utf-8');

function header_timer_input_time($value) {
    if (function_exists('input_time')) {
        return input_time($value);
    }

    if (!$value) {
        return '';
    }

    return date('H:i', strtotime($value));
}

function header_timer_event_state($event) {
    if (!$event || empty($event['event_date']) || empty($event['start_time'])) {
        return 'upcoming';
    }

    $start = strtotime($event['event_date'] . ' ' . header_timer_input_time($event['start_time']));
    $end = !empty($event['end_time'])
        ? strtotime($event['event_date'] . ' ' . header_timer_input_time($event['end_time']))
        : null;

    if ($start && $end && $end <= $start) {
        $end = strtotime('+1 day', $end);
    }

    $now = time();

    if ($start && $end && $now >= $start - 3600 && $now <= $end) {
        return 'current';
    }

    if ($start && $now < $start) {
        return 'upcoming';
    }

    return 'past';
}

function header_timer_iso($ts) {
    return $ts ? date('c', $ts) : '';
}

try {
    $event = null;

    if (function_exists('dttd_get_calculated_current_event')) {
        $event = dttd_get_calculated_current_event();
    }

    if (!$event) {
        // Pick current first using PHP calculation, then next upcoming.
        $events = db()->query("
            SELECT *
            FROM events
            WHERE event_date IS NOT NULL
            ORDER BY event_date ASC, start_time ASC, id ASC
        ")->fetchAll();

        foreach ($events as $candidate) {
            if (header_timer_event_state($candidate) === 'current') {
                $event = $candidate;
                break;
            }
        }

        if (!$event) {
            foreach ($events as $candidate) {
                if (header_timer_event_state($candidate) === 'upcoming') {
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

    $event_end_ts = null;

    if (!empty($event['event_date']) && !empty($event['end_time'])) {
        $event_end_ts = strtotime($event['event_date'] . ' ' . header_timer_input_time($event['end_time']));

        if (!empty($event['start_time'])) {
            $start_ts = strtotime($event['event_date'] . ' ' . header_timer_input_time($event['start_time']));
            if ($start_ts && $event_end_ts && $event_end_ts <= $start_ts) {
                $event_end_ts = strtotime('+1 day', $event_end_ts);
            }
        }
    }

    $request_close_ts = !empty($event['requests_close_at']) ? strtotime($event['requests_close_at']) : null;

    echo json_encode([
        'ok' => true,
        'has_event' => true,
        'event_id' => (int)$event['id'],
        'event_name' => (string)($event['event_name'] ?? ''),
        'event_end' => header_timer_iso($event_end_ts),
        'requests_close' => header_timer_iso($request_close_ts),
        'checked_at' => date('H:i:s')
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Timer endpoint failed: ' . $e->getMessage(),
        'checked_at' => date('H:i:s')
    ]);
}
