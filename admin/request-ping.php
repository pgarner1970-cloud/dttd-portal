<?php
require_once __DIR__ . '/_auth.php';

header('Content-Type: application/json; charset=utf-8');

$event_id = !empty($_GET['event']) ? (int)$_GET['event'] : 0;

if (!$event_id && function_exists('dttd_get_calculated_current_event')) {
    $event = dttd_get_calculated_current_event();
    $event_id = $event ? (int)$event['id'] : 0;
}

if (!$event_id) {
    echo json_encode([
        'ok' => false,
        'error' => 'Missing event id',
        'checked_at' => date('H:i:s')
    ]);
    exit;
}

try {
    $total_stmt = db()->prepare("SELECT COUNT(*) FROM song_requests WHERE event_id = ?");
    $total_stmt->execute([$event_id]);
    $total = (int)$total_stmt->fetchColumn();

    $status_counts = [
        'pending' => 0,
        'maybe' => 0,
        'played' => 0,
        'duplicate' => 0,
        'rejected' => 0,
    ];

    $status_stmt = db()->prepare("
        SELECT LOWER(COALESCE(status, 'pending')) AS request_status, COUNT(*) AS request_total
        FROM song_requests
        WHERE event_id = ?
        GROUP BY LOWER(COALESCE(status, 'pending'))
    ");
    $status_stmt->execute([$event_id]);

    foreach ($status_stmt->fetchAll() as $row) {
        $status = (string)($row['request_status'] ?? 'pending');
        $status_counts[$status] = (int)($row['request_total'] ?? 0);
    }

    $fingerprint_rows_stmt = db()->prepare("
        SELECT *
        FROM song_requests
        WHERE event_id = ?
        ORDER BY id ASC
    ");
    $fingerprint_rows_stmt->execute([$event_id]);
    $fingerprint_parts = [];

    foreach ($fingerprint_rows_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fingerprint_parts[] = implode('|', [
            (string)($row['id'] ?? ''),
            (string)($row['request_group_id'] ?? ''),
            strtolower((string)($row['status'] ?? 'pending')),
            (string)($row['song_title'] ?? ''),
            (string)($row['artist'] ?? ''),
            (string)($row['message'] ?? ''),
            (string)($row['spotify_track_id'] ?? ''),
            (string)($row['spotify_queue_status'] ?? ''),
            (string)($row['spotify_queued_at'] ?? ''),
            (string)($row['created_at'] ?? ''),
            (string)($row['updated_at'] ?? ''),
        ]);
    }

    $fingerprint = sha1(implode("\n", $fingerprint_parts));

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
        'error' => 'Ping failed: ' . $e->getMessage(),
        'event_id' => $event_id,
        'checked_at' => date('H:i:s')
    ]);
}
