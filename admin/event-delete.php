<?php
require_once __DIR__ . '/_auth.php';

function dttd_event_delete_table_exists($table) {
    static $cache = [];
    $table = (string)$table;
    if (isset($cache[$table])) return $cache[$table];

    try {
        $stmt = db()->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function dttd_event_delete_column_exists($table, $column) {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function dttd_event_delete_count($table, $eventId) {
    if (!dttd_event_delete_table_exists($table) || !dttd_event_delete_column_exists($table, 'event_id')) return 0;

    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM `" . str_replace('`', '``', $table) . "` WHERE event_id = ?");
        $stmt->execute([(int)$eventId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function dttd_event_delete_event($eventId) {
    $stmt = db()->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$eventId]);
    return $stmt->fetch();
}

function dttd_event_delete_ids($table, $eventId) {
    if (!dttd_event_delete_table_exists($table) || !dttd_event_delete_column_exists($table, 'event_id')) return [];

    try {
        $stmt = db()->prepare("SELECT id FROM `" . str_replace('`', '``', $table) . "` WHERE event_id = ?");
        $stmt->execute([(int)$eventId]);
        return array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_event_delete_song_request_groups($eventId) {
    if (!dttd_event_delete_table_exists('song_requests') || !dttd_event_delete_column_exists('song_requests', 'request_group_id')) return [];

    try {
        $stmt = db()->prepare("SELECT DISTINCT request_group_id FROM song_requests WHERE event_id = ? AND request_group_id IS NOT NULL AND request_group_id <> ''");
        $stmt->execute([(int)$eventId]);
        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_event_delete_counts($eventId) {
    return [
        'song_requests' => dttd_event_delete_count('song_requests', $eventId),
        'event_requests' => dttd_event_delete_count('event_requests', $eventId),
        'event_track_history' => dttd_event_delete_count('event_track_history', $eventId),
        'event_photo_uploads' => dttd_event_delete_count('event_photo_uploads', $eventId),
        'event_sponsors' => dttd_event_delete_count('event_sponsors', $eventId),
    ];
}

function dttd_event_delete_photo_rows($eventId) {
    if (!dttd_event_delete_table_exists('event_photo_uploads')) return [];

    $columns = ['id'];
    foreach (['file_path', 'original_path', 'framed_path', 'thumb_path'] as $col) {
        if (dttd_event_delete_column_exists('event_photo_uploads', $col)) {
            $columns[] = $col;
        }
    }

    try {
        $stmt = db()->prepare('SELECT ' . implode(', ', array_map(function($col) { return '`' . $col . '`'; }, $columns)) . ' FROM event_photo_uploads WHERE event_id = ?');
        $stmt->execute([(int)$eventId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_event_delete_photo_file_path($path) {
    $path = trim((string)$path);
    if ($path === '') return '';

    $urlPath = parse_url($path, PHP_URL_PATH);
    if (is_string($urlPath) && $urlPath !== '') {
        $path = $urlPath;
    }

    $path = ltrim($path, '/');
    if ($path === '' || !str_starts_with($path, 'uploads/')) {
        return '';
    }

    $full = dirname(__DIR__) . '/' . $path;
    $root = realpath(dirname(__DIR__) . '/uploads');
    $dir = realpath(dirname($full));

    if (!$root || !$dir || !str_starts_with($dir, $root)) {
        return '';
    }

    return $full;
}

function dttd_event_delete_photo_files($photoRows) {
    $deleted = 0;
    $paths = [];

    foreach ($photoRows as $row) {
        foreach (['file_path', 'original_path', 'framed_path', 'thumb_path'] as $col) {
            if (!empty($row[$col])) {
                $paths[] = dttd_event_delete_photo_file_path($row[$col]);
            }
        }
    }

    foreach (array_unique(array_filter($paths)) as $full) {
        if (is_file($full) && @unlink($full)) {
            $deleted++;
        }
    }

    return $deleted;
}

function dttd_event_delete_json_remove_event_items($value, $eventId, $requestIds = [], $groupIds = []) {
    $decoded = json_decode((string)$value, true);
    if (!is_array($decoded)) return $value;

    $requestLookup = array_flip(array_map('intval', $requestIds));
    $groupLookup = array_flip(array_map('strval', $groupIds));
    $changed = false;

    $filterTrack = function($track) use ($eventId, $requestLookup, $groupLookup, &$changed) {
        if (!is_array($track)) return $track;

        $trackEventId = isset($track['event_id']) ? (int)$track['event_id'] : 0;
        if ($trackEventId === (int)$eventId) {
            $changed = true;
            return null;
        }

        $requestId = isset($track['request_id']) ? (int)$track['request_id'] : 0;
        if ($requestId > 0 && isset($requestLookup[$requestId])) {
            $changed = true;
            return null;
        }

        $groupId = isset($track['request_group_id']) ? (string)$track['request_group_id'] : '';
        if ($groupId !== '' && isset($groupLookup[$groupId])) {
            $changed = true;
            return null;
        }

        return $track;
    };

    // Simple list of tracks, for playlist/history settings.
    $isList = array_keys($decoded) === range(0, count($decoded) - 1);
    if ($isList) {
        $out = [];
        foreach ($decoded as $item) {
            $filtered = $filterTrack($item);
            if ($filtered !== null) $out[] = $filtered;
        }
        return $changed ? json_encode(array_values($out)) : $value;
    }

    // Crate-like structure. Kept defensive in case a future setting is passed in.
    if (isset($decoded['tracks']) && is_array($decoded['tracks'])) {
        $tracks = [];
        foreach ($decoded['tracks'] as $track) {
            $filtered = $filterTrack($track);
            if ($filtered !== null) $tracks[] = $filtered;
        }
        if ($changed) {
            $decoded['tracks'] = array_values($tracks);
            return json_encode($decoded);
        }
    }

    $filtered = $filterTrack($decoded);
    if ($filtered === null) {
        return '';
    }

    return $changed ? json_encode($filtered) : $value;
}

function dttd_event_delete_cleanup_settings($eventId, $requestIds, $groupIds) {
    if (!dttd_event_delete_table_exists('app_settings')) return 0;

    $updated = 0;
    $deleteKeys = [
        'public_now_playing_cache_' . (int)$eventId,
        'display_goodnight_started_at_' . (int)$eventId,
        'display_last_event_' . (int)$eventId,
    ];

    try {
        $stmt = db()->prepare('DELETE FROM app_settings WHERE setting_key = ?');
        foreach ($deleteKeys as $key) {
            $stmt->execute([$key]);
            $updated += $stmt->rowCount();
        }
    } catch (Throwable $e) {
        // Continue with JSON cleanup.
    }

    $jsonKeys = [
        'spotify_mixer_history',
        'spotify_mixer_playlist',
        'spotify_mixer_loaded_a',
        'spotify_mixer_loaded_b',
    ];

    try {
        $select = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $update = db()->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?');
        foreach ($jsonKeys as $key) {
            $select->execute([$key]);
            $row = $select->fetch();
            if (!$row) continue;

            $old = (string)($row['setting_value'] ?? '');
            if ($old === '') continue;

            $new = dttd_event_delete_json_remove_event_items($old, $eventId, $requestIds, $groupIds);
            if ($new !== $old) {
                $update->execute([$new, $key]);
                $updated++;
            }
        }
    } catch (Throwable $e) {
        // Setting cleanup is best-effort; table deletion below remains authoritative.
    }

    return $updated;
}

function dttd_event_delete_delete_from_table($table, $eventId) {
    if (!dttd_event_delete_table_exists($table) || !dttd_event_delete_column_exists($table, 'event_id')) return 0;

    $stmt = db()->prepare('DELETE FROM `' . str_replace('`', '``', $table) . '` WHERE event_id = ?');
    $stmt->execute([(int)$eventId]);
    return $stmt->rowCount();
}

function dttd_event_delete_run($eventId) {
    $event = dttd_event_delete_event($eventId);
    if (!$event) {
        throw new RuntimeException('Event not found.');
    }

    $songRequestIds = dttd_event_delete_ids('song_requests', $eventId);
    $eventRequestIds = dttd_event_delete_ids('event_requests', $eventId);
    $allRequestIds = array_values(array_unique(array_merge($songRequestIds, $eventRequestIds)));
    $groupIds = dttd_event_delete_song_request_groups($eventId);
    $photoRows = dttd_event_delete_photo_rows($eventId);

    $summary = [];
    $deletedPhotoFiles = 0;

    db()->beginTransaction();
    try {
        $summary['settings_cleaned'] = dttd_event_delete_cleanup_settings($eventId, $allRequestIds, $groupIds);

        if (dttd_event_delete_table_exists('event_track_history')) {
            if ($allRequestIds) {
                $placeholders = implode(',', array_fill(0, count($allRequestIds), '?'));
                $stmt = db()->prepare("DELETE FROM event_track_history WHERE event_id = ? OR request_id IN ($placeholders)");
                $stmt->execute(array_merge([(int)$eventId], $allRequestIds));
            } else {
                $stmt = db()->prepare('DELETE FROM event_track_history WHERE event_id = ?');
                $stmt->execute([(int)$eventId]);
            }
            $summary['event_track_history'] = $stmt->rowCount();
        }

        $summary['event_sponsors'] = dttd_event_delete_delete_from_table('event_sponsors', $eventId);
        $summary['event_requests'] = dttd_event_delete_delete_from_table('event_requests', $eventId);
        $summary['song_requests'] = dttd_event_delete_delete_from_table('song_requests', $eventId);
        $summary['event_photo_uploads'] = dttd_event_delete_delete_from_table('event_photo_uploads', $eventId);

        $stmt = db()->prepare('DELETE FROM events WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$eventId]);
        $summary['events'] = $stmt->rowCount();

        db()->commit();
        $deletedPhotoFiles = dttd_event_delete_photo_files($photoRows);
        $summary['photo_files'] = $deletedPhotoFiles;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    return [$event, $summary];
}

$eventId = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);
$event = $eventId > 0 ? dttd_event_delete_event($eventId) : null;
$error = '';

if (!$event) {
    admin_header('Delete Event - DJ Portal');
    ?>
    <main class="touch-wrap">
      <section class="touch-panel">
        <div class="touch-alert danger">Event not found.</div>
        <a class="touch-btn ghost" href="events.php">← Back to events</a>
      </section>
    </main>
    <?php
    admin_footer();
    exit;
}

$counts = dttd_event_delete_counts($eventId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = trim((string)($_POST['confirm_delete'] ?? ''));

    if ($confirm !== 'DELETE') {
        $error = 'Please type DELETE to confirm permanent deletion.';
    } else {
        try {
            [$deletedEvent, $summary] = dttd_event_delete_run($eventId);
            $_SESSION['event_delete_flash'] = [
                'success' => true,
                'message' => 'Deleted event “' . ($deletedEvent['event_name'] ?? ('#' . $eventId)) . '” and associated test data.',
                'summary' => $summary,
            ];
            header('Location: events.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            $error = 'Delete failed: ' . $e->getMessage();
        }
    }
}

admin_header('Delete Event - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Delete event</h1>
        <p class="touch-subtitle">Permanently remove this event and its associated test data.</p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn ghost" href="events.php">← Back to Events</a>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="touch-alert danger"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="touch-alert danger">
      <strong>This cannot be undone.</strong><br>
      Deleting this event will remove the event record, guest requests, mirrored Spotify/event request rows, played-track history, photo upload records, event sponsor assignments and related display/player cache entries for this event.
    </div>

    <article class="event-row-card row-past">
      <div class="event-row-date">
        <?= h(!empty($event['event_date']) ? date('d M', strtotime($event['event_date'])) : 'No date') ?>
        <small><?= h(input_time($event['start_time'] ?? '')) ?><?= !empty($event['end_time']) ? ' - ' . h(input_time($event['end_time'])) : '' ?></small>
      </div>
      <div class="event-row-title">
        <strong><?= h($event['event_name'] ?? '') ?></strong>
        <span><?= h($event['venue_name'] ?? '') ?></span>
        <?php if (!empty($event['event_code'])): ?><span>Code: <?= h($event['event_code']) ?></span><?php endif; ?>
      </div>
      <div class="event-row-close">
        <strong>Associated rows</strong>
        <span><?= h((string)array_sum($counts)) ?> database rows before cache cleanup</span>
      </div>
    </article>

    <div class="display-slide-settings-grid" style="margin-top:1rem;">
      <?php foreach ($counts as $label => $count): ?>
        <div class="display-slide-setting-card">
          <div class="display-slide-setting-main">
            <span class="display-slide-toggle" aria-hidden="true"><span><?= (int)$count ?></span></span>
            <div>
              <h2><?= h(str_replace('_', ' ', $label)) ?></h2>
              <p class="touch-muted">Rows linked to this event.</p>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="post" style="margin-top:1.25rem;max-width:720px;" onsubmit="return confirm('Permanently delete this event and all associated data?');">
      <input type="hidden" name="event_id" value="<?= (int)$eventId ?>">
      <label class="form-field">
        <span>Type DELETE to confirm</span>
        <input type="text" name="confirm_delete" autocomplete="off" required placeholder="DELETE">
      </label>
      <div class="settings-actions" style="justify-content:flex-start;margin-top:1rem;">
        <button class="touch-btn danger" type="submit">Delete event permanently</button>
        <a class="touch-btn ghost" href="events.php">Cancel</a>
      </div>
    </form>
  </section>
</main>
<?php admin_footer(); ?>
