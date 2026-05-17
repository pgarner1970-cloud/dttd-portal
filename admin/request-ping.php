<?php
require_once __DIR__ . '/_auth.php';

header('Content-Type: application/json; charset=utf-8');

$event_id = !empty($_GET['event']) ? (int)$_GET['event'] : 0;

if (!$event_id) {
    $current_event = dttd_get_calculated_current_event();
    $event_id = $current_event ? (int)$current_event['id'] : 0;
}

if (!$event_id) {
    echo json_encode([
        'ok' => false,
        'error' => 'No calculated current event'
    ]);
    exit;
}

$stmt = db()->prepare("
    SELECT 
        COUNT(*) AS total_requests,
        COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) AS latest_updated,
        COALESCE(MAX(UNIX_TIMESTAMP(created_at)), 0) AS latest_created
    FROM song_requests
    WHERE event_id = ?
");
$stmt->execute([$event_id]);
$row = $stmt->fetch();

$total = (int)($row['total_requests'] ?? 0);
$latestUpdated = (int)($row['latest_updated'] ?? 0);
$latestCreated = (int)($row['latest_created'] ?? 0);

$fingerprint = sha1($event_id . '|' . $total . '|' . $latestUpdated . '|' . $latestCreated);

echo json_encode([
    'ok' => true,
    'event_id' => $event_id,
    'total_requests' => $total,
    'latest_updated' => $latestUpdated,
    'latest_created' => $latestCreated,
    'fingerprint' => $fingerprint,
    'checked_at' => date('H:i:s')
]);
