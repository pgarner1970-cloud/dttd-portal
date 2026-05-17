<?php
require_once __DIR__ . '/_auth.php';

header('Content-Type: application/json; charset=utf-8');

$event_id = !empty($_GET['event']) ? (int)$_GET['event'] : 0;

if (!$event_id && function_exists('dttd_get_calculated_current_event')) {
    $current_event = dttd_get_calculated_current_event();
    $event_id = $current_event ? (int)$current_event['id'] : 0;
}

if (!$event_id) {
    echo json_encode([
        'ok' => false,
        'error' => 'Missing event id'
    ]);
    exit;
}

try {
    $stmt = db()->prepare("
        SELECT *
        FROM song_requests
        WHERE event_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$event_id]);
    $rows = $stmt->fetchAll();

    $total = count($rows);

    $status_counts = [
        'pending' => 0,
        'maybe' => 0,
        'played' => 0,
        'duplicate' => 0,
        'rejected' => 0,
    ];

    $fingerprint_parts = [];

    foreach ($rows as $row) {
        $status = strtolower((string)($row['status'] ?? 'pending'));

        if (!array_key_exists($status, $status_counts)) {
            $status_counts[$status] = 0;
        }

        $status_counts[$status]++;

        $fingerprint_parts[] = implode('|', [
            (int)($row['id'] ?? 0),
            $status,
            (string)($row['guest_name'] ?? $row['guest'] ?? ''),
            (string)($row['song_title'] ?? $row['song'] ?? $row['track'] ?? ''),
            (string)($row['artist'] ?? ''),
            (string)($row['message'] ?? ''),
        ]);
    }

    $fingerprint = sha1($event_id . '|' . $total . '|' . json_encode($status_counts) . '|' . implode('~', $fingerprint_parts));

    echo json_encode([
        'ok' => true,
        'event_id' => $event_id,
        'total_requests' => $total,
        'status_counts' => $status_counts,
        'fingerprint' => $fingerprint,
        'checked_at' => date('H:i:s')
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Ping failed'
    ]);
}
