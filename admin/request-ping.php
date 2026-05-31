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

        // Alerts outside the Requests page should only be driven by requests that still
        // need DJ review on the main Requests page. Once a request has been sent to
        // the mixer/playlist/loaded deck, later status changes such as played should
        // not create a fresh NEW alert.
        $queue_status = strtolower(trim((string)($row['spotify_queue_status'] ?? '')));
        $already_handled_by_mixer = in_array($queue_status, ['mixer_request', 'dj_playlist', 'loaded_a', 'loaded_b', 'played', 'queued', 'spotify_queue'], true);
        if (in_array($status, ['pending', 'maybe'], true) && !$already_handled_by_mixer) {
            $actionable_count++;
            $id = (int)($row['id'] ?? 0);
            $actionable_newest_id = max($actionable_newest_id, $id);
            $actionable_parts[] = $part;
        }
    }

    $fingerprint = sha1(implode("\n", $fingerprint_parts));
    $actionable_fingerprint = sha1(implode("\n", $actionable_parts));

    $photo_pending_count = 0;
    $photo_newest_id = 0;
    $photo_fingerprint = '';
    try {
        $photo_stmt = db()->prepare("\n            SELECT id, status, event_id, guest_name, uploaded_at, created_at\n            FROM event_photo_uploads\n            WHERE event_id = ? AND LOWER(COALESCE(status, 'pending')) = 'pending'\n            ORDER BY id ASC\n        ");
        $photo_stmt->execute([$event_id]);
        $photo_parts = [];
        foreach ($photo_stmt->fetchAll(PDO::FETCH_ASSOC) as $photo_row) {
            $photo_pending_count++;
            $photo_newest_id = max($photo_newest_id, (int)($photo_row['id'] ?? 0));
            $photo_parts[] = implode('|', [
                (string)($photo_row['id'] ?? ''),
                (string)($photo_row['event_id'] ?? ''),
                strtolower((string)($photo_row['status'] ?? 'pending')),
                (string)($photo_row['guest_name'] ?? ''),
                (string)($photo_row['uploaded_at'] ?? ''),
                (string)($photo_row['created_at'] ?? ''),
            ]);
        }
        $photo_fingerprint = sha1(implode("\n", $photo_parts));
    } catch (Throwable $photo_error) {
        $photo_pending_count = 0;
        $photo_newest_id = 0;
        $photo_fingerprint = '';
    }

    echo json_encode([
        'ok' => true,
        'event_id' => $event_id,
        'total_requests' => $total,
        'status_counts' => $status_counts,
        'fingerprint' => $fingerprint,
        'actionable_fingerprint' => $actionable_fingerprint,
        'actionable_count' => $actionable_count,
        'actionable_newest_id' => $actionable_newest_id,
        'photo_pending_count' => $photo_pending_count,
        'photo_newest_id' => $photo_newest_id,
        'photo_fingerprint' => $photo_fingerprint,
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
