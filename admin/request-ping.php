<?php
require_once __DIR__ . '/_auth.php';

header('Content-Type: application/json; charset=utf-8');

$event_id = !empty($_GET['event']) ? (int)$_GET['event'] : 0;

if (!$event_id) {
    echo json_encode([
        'ok' => false,
        'error' => 'Missing event id'
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
        SELECT LOWER(COALESCE(status, 'pending')) AS status, COUNT(*) AS total
        FROM song_requests
        WHERE event_id = ?
        GROUP BY LOWER(COALESCE(status, 'pending'))
    ");
    $status_stmt->execute([$event_id]);

    foreach ($status_stmt->fetchAll() as $row) {
        $status = (string)$row['status'];
        $status_counts[$status] = (int)$row['total'];
    }

    echo json_encode([
        'ok' => true,
        'event_id' => $event_id,
        'total_requests' => $total,
        'status_counts' => $status_counts,
        'checked_at' => date('H:i:s')
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Ping failed'
    ]);
}
