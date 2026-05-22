<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

$track_id = trim($_POST['spotify_track_id'] ?? $_GET['spotify_track_id'] ?? '');
$request_group_id = trim($_POST['request_group_id'] ?? $_GET['request_group_id'] ?? '');
$device_id = trim($_POST['device_id'] ?? $_GET['device_id'] ?? '');
$return = $_POST['return'] ?? $_GET['return'] ?? '../requests.php';


function dttd_spotify_song_request_column_exists($column) {
    static $cache = [];
    $column = (string)$column;

    if (isset($cache[$column])) {
        return $cache[$column];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM song_requests LIKE ?");
        $stmt->execute([$column]);
        $cache[$column] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function dttd_mark_spotify_request_queued($track_id, $request_group_id = '') {
    if (!dttd_spotify_song_request_column_exists('spotify_queue_status')) {
        return;
    }

    $set = ["spotify_queue_status = 'queued'"];
    if (dttd_spotify_song_request_column_exists('spotify_queued_at')) {
        $set[] = 'spotify_queued_at = NOW()';
    }
    if (dttd_spotify_song_request_column_exists('spotify_queued_by')) {
        $set[] = "spotify_queued_by = 'dj'";
    }

    $where = [];
    $params = [];

    if ($request_group_id !== '' && dttd_spotify_song_request_column_exists('request_group_id')) {
        $where[] = 'request_group_id = ?';
        $params[] = $request_group_id;
    }

    if ($track_id !== '' && dttd_spotify_song_request_column_exists('spotify_track_id')) {
        $where[] = 'spotify_track_id = ?';
        $params[] = $track_id;
    }

    if (!$where) {
        return;
    }

    $sql = 'UPDATE song_requests SET ' . implode(', ', $set) . ' WHERE status IN (\'pending\',\'maybe\',\'duplicate\') AND (' . implode(' OR ', $where) . ')';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
}

if ($return === '' || strpos($return, 'http') === 0 || strpos($return, '//') !== false) {
    $return = '../requests.php';
}

try {
    if (!dttd_spotify_queue_connected()) {
        throw new RuntimeException('Spotify account is not connected yet. Use Spotify Tools first.');
    }
    dttd_spotify_add_to_queue($track_id, $device_id);
    dttd_mark_spotify_request_queued($track_id, $request_group_id);
    $_SESSION['spotify_flash'] = 'Track added to Spotify queue.';
} catch (Throwable $e) {
    $_SESSION['spotify_flash'] = 'Could not add to Spotify queue: ' . $e->getMessage();
}

header('Location: ' . $return);
exit;
