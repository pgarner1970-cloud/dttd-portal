<?php
require_once __DIR__ . '/../includes/local-music.php';

dttd_no_cache_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dttd_local_sync_json($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dttd_local_sync_json(['ok' => false, 'error' => 'POST required.'], 405);
}

if (!dttd_local_music_table_exists('local_tracks')) {
    dttd_local_sync_json(['ok' => false, 'error' => 'Local music table has not been created yet.'], 503);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    $data = $_POST;
}

$configuredKey = dttd_local_music_sync_key();
$providedKey = trim((string)($data['sync_key'] ?? $data['key'] ?? ''));
if ($configuredKey === '' || $providedKey === '' || !hash_equals($configuredKey, $providedKey)) {
    dttd_local_sync_json(['ok' => false, 'error' => 'Invalid or missing local music sync key.'], 403);
}

$action = trim((string)($data['action'] ?? 'upsert_tracks'));
$sourceKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($data['source_key'] ?? 'lenovo'));
if ($sourceKey === '') $sourceKey = 'lenovo';

try {
    if ($action === 'start_scan') {
        $startedAt = date('Y-m-d H:i:s');
        dttd_local_music_set_setting('local_music_last_scan_started_at_' . $sourceKey, $startedAt);
        dttd_local_sync_json(['ok' => true, 'source_key' => $sourceKey, 'scan_started_at' => $startedAt]);
    }

    if ($action === 'finish_scan') {
        $startedAt = trim((string)($data['scan_started_at'] ?? dttd_local_music_setting('local_music_last_scan_started_at_' . $sourceKey, '')));
        $markedMissing = 0;
        if ($startedAt !== '') {
            $stmt = db()->prepare("UPDATE local_tracks SET missing_since_at = IF(missing_since_at IS NULL, NOW(), missing_since_at), is_enabled = 0 WHERE source_key = ? AND (last_seen_at IS NULL OR last_seen_at < ?)");
            $stmt->execute([$sourceKey, $startedAt]);
            $markedMissing = $stmt->rowCount();
        }
        $finishedAt = date('Y-m-d H:i:s');
        dttd_local_music_set_setting('local_music_last_scan_finished_at_' . $sourceKey, $finishedAt);
        dttd_local_sync_json(['ok' => true, 'source_key' => $sourceKey, 'scan_finished_at' => $finishedAt, 'marked_missing' => $markedMissing]);
    }

    if ($action !== 'upsert_tracks') {
        dttd_local_sync_json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }

    $tracks = $data['tracks'] ?? [];
    if (!is_array($tracks)) $tracks = [];
    $tracks = array_slice($tracks, 0, 250);

    $sql = "INSERT INTO local_tracks (
                source_key, relative_path, file_name, extension, file_size, file_mtime, file_hash, duration_seconds,
                detected_title, detected_artist, detected_album, detected_album_artist, detected_year, detected_genre,
                display_title, display_artist, display_album, display_year,
                needs_review, is_enabled, last_seen_at, missing_since_at
            ) VALUES (
                :source_key, :relative_path, :file_name, :extension, :file_size, :file_mtime, :file_hash, :duration_seconds,
                :detected_title, :detected_artist, :detected_album, :detected_album_artist, :detected_year, :detected_genre,
                :display_title, :display_artist, :display_album, :display_year,
                :needs_review, 1, NOW(), NULL
            ) ON DUPLICATE KEY UPDATE
                file_name = VALUES(file_name),
                extension = VALUES(extension),
                file_size = VALUES(file_size),
                file_mtime = VALUES(file_mtime),
                file_hash = COALESCE(NULLIF(VALUES(file_hash), ''), file_hash),
                duration_seconds = COALESCE(VALUES(duration_seconds), duration_seconds),
                detected_title = VALUES(detected_title),
                detected_artist = VALUES(detected_artist),
                detected_album = VALUES(detected_album),
                detected_album_artist = VALUES(detected_album_artist),
                detected_year = VALUES(detected_year),
                detected_genre = VALUES(detected_genre),
                display_title = IF(display_title IS NULL OR display_title = '', VALUES(display_title), display_title),
                display_artist = IF(display_artist IS NULL OR display_artist = '', VALUES(display_artist), display_artist),
                display_album = IF(display_album IS NULL OR display_album = '', VALUES(display_album), display_album),
                display_year = IF(display_year IS NULL OR display_year = '', VALUES(display_year), display_year),
                needs_review = VALUES(needs_review),
                is_enabled = 1,
                last_seen_at = NOW(),
                missing_since_at = NULL,
                updated_at = NOW()";
    $stmt = db()->prepare($sql);

    $count = 0;
    $skipped = 0;
    foreach ($tracks as $track) {
        if (!is_array($track)) { $skipped++; continue; }
        $relativePath = dttd_local_music_normalise_path($track['relative_path'] ?? $track['path'] ?? '');
        if ($relativePath === '') { $skipped++; continue; }
        $fileName = (string)($track['file_name'] ?? basename($relativePath));
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $detectedTitle = trim((string)($track['title'] ?? $track['detected_title'] ?? ''));
        $detectedArtist = trim((string)($track['artist'] ?? $track['detected_artist'] ?? ''));
        $detectedAlbum = trim((string)($track['album'] ?? $track['detected_album'] ?? ''));
        $displayTitle = $detectedTitle !== '' ? $detectedTitle : dttd_local_music_guess_title($relativePath, $fileName);
        $displayArtist = $detectedArtist;
        $needsReview = ($detectedTitle === '' || $detectedArtist === '') ? 1 : 0;

        $stmt->execute([
            ':source_key' => $sourceKey,
            ':relative_path' => $relativePath,
            ':file_name' => $fileName,
            ':extension' => $extension,
            ':file_size' => isset($track['file_size']) ? (int)$track['file_size'] : null,
            ':file_mtime' => isset($track['file_mtime']) ? date('Y-m-d H:i:s', (int)$track['file_mtime']) : null,
            ':file_hash' => (string)($track['file_hash'] ?? ''),
            ':duration_seconds' => isset($track['duration_seconds']) ? (int)$track['duration_seconds'] : null,
            ':detected_title' => $detectedTitle,
            ':detected_artist' => $detectedArtist,
            ':detected_album' => $detectedAlbum,
            ':detected_album_artist' => trim((string)($track['album_artist'] ?? $track['detected_album_artist'] ?? '')),
            ':detected_year' => trim((string)($track['year'] ?? $track['detected_year'] ?? '')),
            ':detected_genre' => trim((string)($track['genre'] ?? $track['detected_genre'] ?? '')),
            ':display_title' => $displayTitle,
            ':display_artist' => $displayArtist,
            ':display_album' => $detectedAlbum,
            ':display_year' => trim((string)($track['year'] ?? $track['detected_year'] ?? '')),
            ':needs_review' => $needsReview,
        ]);
        $count++;
    }

    dttd_local_sync_json(['ok' => true, 'source_key' => $sourceKey, 'received' => count($tracks), 'upserted' => $count, 'skipped' => $skipped]);
} catch (Throwable $e) {
    dttd_local_sync_json(['ok' => false, 'error' => 'Local music sync failed.'], 500);
}
