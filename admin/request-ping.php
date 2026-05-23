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

    foreach ($status_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string)($row['request_status'] ?? 'pending');
        $status_counts[$status] = (int)($row['request_total'] ?? 0);
    }

    $columns_stmt = db()->query("SHOW COLUMNS FROM song_requests");
    $columns = array_map('strtolower', array_column($columns_stmt->fetchAll(PDO::FETCH_ASSOC), 'Field'));
    $has_message = in_array('message', $columns, true);
    $has_dedication = in_array('dedication', $columns, true);

    $fingerprint_rows_stmt = db()->prepare("
        SELECT *
        FROM song_requests
        WHERE event_id = ?
        ORDER BY id ASC
    ");
    $fingerprint_rows_stmt->execute([$event_id]);
    $fingerprint_parts = [];
    $actionable_parts = [];
    $actionable_newest_id = 0;
    $actionable_count = 0;

    foreach ($fingerprint_rows_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = strtolower((string)($row['status'] ?? 'pending'));
        $message = '';
        if ($has_message && array_key_exists('message', $row)) {
            $message = (string)$row['message'];
        } elseif ($has_dedication && array_key_exists('dedication', $row)) {
            $message = (string)$row['dedication'];
        }

        $part = implode('|', [
            (string)($row['id'] ?? ''),
            (string)($row['request_group_id'] ?? ''),
            $status,
            (string)($row['song_title'] ?? ''),
            (string)($row['artist'] ?? ''),
            $message,
            (string)($row['spotify_track_id'] ?? ''),
            (string)($row['spotify_queue_status'] ?? ''),
            (string)($row['spotify_queued_at'] ?? ''),
            (string)($row['created_at'] ?? ''),
            (string)($row['updated_at'] ?? ''),
        ]);
        $fingerprint_parts[] = $part;

        // Alerts outside the Requests page should only be driven by requests that still need DJ review.
        // Status changes such as pending -> played should not create a new alert.
        if (in_array($status, ['pending', 'maybe'], true)) {
            $actionable_count++;
            $id = (int)($row['id'] ?? 0);
            $actionable_newest_id = max($actionable_newest_id, $id);
            $actionable_parts[] = $part;
        }
    }

    $fingerprint = sha1(implode("\n", $fingerprint_parts));
    $actionable_fingerprint = sha1(implode("\n", $actionable_parts));

    echo json_encode([
        'ok' => true,
        'event_id' => $event_id,
        'total_requests' => $total,
        'status_counts' => $status_counts,
        'fingerprint' => $fingerprint,
        'actionable_fingerprint' => $actionable_fingerprint,
        'actionable_count' => $actionable_count,
        'actionable_newest_id' => $actionable_newest_id,
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
