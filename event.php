<?php
require_once __DIR__ . '/includes/db.php';
dttd_redirect_public_feature_to_primary_domain();

if (!function_exists('public_h')) {
    function public_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function public_slugify($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = trim($value, '-');
    return $value ?: 'event';
}

function public_event_slug($event) {
    if (!empty($event['public_slug'])) {
        return public_slugify($event['public_slug']);
    }

    if (!empty($event['slug'])) {
        return public_slugify($event['slug']);
    }

    $parts = [
        $event['event_name'] ?? $event['name'] ?? 'event',
        $event['venue_name'] ?? $event['venue'] ?? '',
    ];

    if (!empty($event['event_date'])) {
        try {
            $parts[] = (new DateTime($event['event_date']))->format('Y-m-d');
        } catch (Throwable $e) {
            $parts[] = (string)$event['event_date'];
        }
    }

    return public_slugify(implode(' ', array_filter($parts)));
}

function public_event_status($event) {
    return dttd_event_status_value($event);
}

function public_event_is_private($event) {
    $visibility = strtolower((string)($event['queue_visibility'] ?? $event['visibility'] ?? 'public'));
    $eventType = strtolower((string)($event['event_type'] ?? ''));
    $status = public_event_status($event);

    return (
        $status === 'private'
        || $visibility === 'private'
        || str_contains($eventType, 'private')
        || str_contains($eventType, 'wedding')
        || str_contains($eventType, 'birthday')
    );
}

function public_event_image_url($image) {
    $image = trim((string)$image);

    if ($image === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $image)) {
        return $image;
    }

    $image = ltrim($image, '/');

    if (str_starts_with($image, 'uploads/')) {
        return '/' . $image;
    }

    if (str_contains($image, '/')) {
        return '/' . $image;
    }

    return '/uploads/events/' . $image;
}

function public_event_description($event) {
    foreach (['public_description', 'event_description', 'description', 'public_notes', 'notes'] as $field) {
        if (!empty($event[$field])) {
            return trim((string)$event[$field]);
        }
    }

    return '';
}

function public_cancelled_message($event) {
    foreach (['cancelled_message', 'cancellation_message', 'status_message'] as $field) {
        if (!empty($event[$field])) {
            return trim((string)$event[$field]);
        }
    }

    return 'This event has been cancelled. Please check our Facebook page or the venue for further updates.';
}

function public_find_event_by_slug($slug) {
    $slug = public_slugify($slug);

    if ($slug === '') {
        return null;
    }

    try {
        if (dttd_event_column_exists('public_slug')) {
            $stmt = db()->prepare("SELECT * FROM events WHERE public_slug = ? LIMIT 1");
            $stmt->execute([$slug]);
            $event = $stmt->fetch();
            if ($event) {
                return $event;
            }
        }

        $candidateEvents = db()->query("SELECT * FROM events")->fetchAll();
        foreach ($candidateEvents as $candidate) {
            $status = public_event_status($candidate);

            if (in_array($status, ['draft', 'private'], true)) {
                continue;
            }

            if (public_event_is_private($candidate)) {
                continue;
            }

            if (public_event_slug($candidate) === $slug) {
                return $candidate;
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function public_recent_played_requests($event_id, $limit = 25) {
    if (!dttd_table_exists('song_requests')) {
        return [];
    }

    $limit = max(1, min(50, (int)$limit));
    $select = ['id', 'song_title', 'artist', 'created_at'];
    foreach (['spotify_track_url', 'spotify_track_id', 'spotify_album_image', 'updated_at', 'request_group_id', 'guest_name', 'dedication', 'message'] as $column) {
        if (dttd_table_column_exists('song_requests', $column)) {
            $select[] = $column;
        }
    }

    $selectSql = implode(', ', array_map(function($column) {
        return '`' . str_replace('`', '', $column) . '`';
    }, array_unique($select)));

    try {
        $orderSql = dttd_table_column_exists('song_requests', 'updated_at') ? 'updated_at DESC, created_at DESC, id DESC' : 'created_at DESC, id DESC';
        $stmt = db()->prepare("
            SELECT $selectSql
            FROM song_requests
            WHERE event_id = ? AND status = 'played'
            ORDER BY $orderSql
            LIMIT " . $limit . "
        ");
        $stmt->execute([(int)$event_id]);
        return public_group_played_tracks($stmt->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}

function public_pending_request_count($event_id) {
    if (!dttd_table_exists('song_requests')) {
        return 0;
    }

    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM song_requests WHERE event_id = ? AND status IN ('pending','maybe')");
        $stmt->execute([(int)$event_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}



function public_event_request_board($event_id, $limit = 40) {
    if (!dttd_table_exists('song_requests')) {
        return [];
    }

    $limit = max(1, min(80, (int)$limit));
    $select = ['id', 'song_title', 'artist', 'guest_name', 'status', 'created_at'];
    foreach (['dedication', 'message', 'spotify_queue_status', 'spotify_queued_at', 'updated_at', 'reject_reason', 'request_group_id', 'spotify_track_id', 'spotify_track_url', 'spotify_album_image'] as $column) {
        if (dttd_table_column_exists('song_requests', $column)) {
            $select[] = $column;
        }
    }

    $selectSql = implode(', ', array_map(function($column) {
        return '`' . str_replace('`', '', $column) . '`';
    }, array_unique($select)));

    try {
        $stmt = db()->prepare("
            SELECT $selectSql
            FROM song_requests
            WHERE event_id = ?
              AND LOWER(COALESCE(status, 'pending')) NOT IN ('removed', 'hidden')
            ORDER BY
              COALESCE(updated_at, created_at) DESC,
              created_at DESC,
              id DESC
            LIMIT " . $limit . "
        ");
        $stmt->execute([(int)$event_id]);
        return public_group_request_board_rows($stmt->fetchAll());
    } catch (Throwable $e) {
        return [];
    }
}


function public_request_group_key($request) {
    $groupId = trim((string)($request['request_group_id'] ?? ''));
    if ($groupId !== '') {
        return 'group:' . $groupId;
    }

    $id = (int)($request['id'] ?? 0);
    return 'single:' . $id;
}

function public_group_request_board_rows($requests) {
    $groups = [];
    $order = [];

    foreach ((array)$requests as $request) {
        $key = public_request_group_key($request);
        if (!isset($groups[$key])) {
            $groups[$key] = $request;
            $groups[$key]['rows'] = [];
            $groups[$key]['request_count'] = 0;
            $groups[$key]['request_group_key'] = $key;
            $order[] = $key;
        }
        $groups[$key]['rows'][] = $request;
        $groups[$key]['request_count']++;

        foreach (['spotify_album_image', 'spotify_track_url', 'spotify_track_id'] as $publicTrackField) {
            if (empty($groups[$key][$publicTrackField]) && !empty($request[$publicTrackField])) {
                $groups[$key][$publicTrackField] = $request[$publicTrackField];
            }
        }
    }

    $result = [];
    foreach ($order as $key) {
        $result[] = $groups[$key];
    }

    usort($result, 'public_request_board_compare');

    return $result;
}

function public_group_played_tracks($requests) {
    $groups = [];
    $order = [];

    foreach ((array)$requests as $request) {
        $key = public_request_group_key($request);
        if (!isset($groups[$key])) {
            $groups[$key] = $request;
            $groups[$key]['rows'] = [];
            $groups[$key]['request_count'] = 0;
            $groups[$key]['request_group_key'] = $key;
            $order[] = $key;
        }
        $groups[$key]['rows'][] = $request;
        $groups[$key]['request_count']++;

        if (empty($groups[$key]['spotify_track_url']) && !empty($request['spotify_track_url'])) {
            $groups[$key]['spotify_track_url'] = $request['spotify_track_url'];
        }
        if (empty($groups[$key]['spotify_track_id']) && !empty($request['spotify_track_id'])) {
            $groups[$key]['spotify_track_id'] = $request['spotify_track_id'];
        }
        if (empty($groups[$key]['spotify_album_image']) && !empty($request['spotify_album_image'])) {
            $groups[$key]['spotify_album_image'] = $request['spotify_album_image'];
        }
    }

    $result = [];
    foreach ($order as $key) {
        $result[] = $groups[$key];
    }

    return $result;
}


function public_request_board_sort_rank($request) {
    $label = public_request_status_label($request);
    $order = [
        'In DJ queue' => 0,
        'Waiting' => 1,
        'Under review' => 2,
        'Already requested' => 3,
        'Played' => 4,
        'Unable to play' => 5,
    ];

    return $order[$label] ?? 9;
}

function public_request_board_sort_time($request) {
    $best = 0;
    foreach (public_group_rows($request) as $row) {
        foreach (['spotify_queued_at', 'updated_at', 'created_at'] as $field) {
            if (!empty($row[$field])) {
                $ts = strtotime((string)$row[$field]);
                if ($ts && $ts > $best) {
                    $best = $ts;
                }
            }
        }
    }

    return $best;
}

function public_request_board_compare($a, $b) {
    $rankA = public_request_board_sort_rank($a);
    $rankB = public_request_board_sort_rank($b);

    if ($rankA !== $rankB) {
        return $rankA <=> $rankB;
    }

    $timeA = public_request_board_sort_time($a);
    $timeB = public_request_board_sort_time($b);

    if ($timeA !== $timeB) {
        return $timeB <=> $timeA;
    }

    return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
}

function public_track_artwork_url($track) {
    foreach (['spotify_album_image', 'album_image', 'artwork_url', 'image'] as $field) {
        $value = trim((string)($track[$field] ?? ''));
        if ($value !== '') {
            if (preg_match('~^https?://~i', $value)) {
                return $value;
            }
            return '/' . ltrim($value, '/');
        }
    }

    $trackId = trim((string)($track['spotify_track_id'] ?? ''));
    if ($trackId !== '' && dttd_table_exists('spotify_track_cache') && dttd_table_column_exists('spotify_track_cache', 'artwork_url')) {
        try {
            $spotifyUriColumn = dttd_table_column_exists('spotify_track_cache', 'spotify_uri') ? 'spotify_uri' : '';
            if ($spotifyUriColumn !== '') {
                static $artworkCache = [];
                if (array_key_exists($trackId, $artworkCache)) {
                    return $artworkCache[$trackId];
                }
                $stmt = db()->prepare("SELECT artwork_url FROM spotify_track_cache WHERE spotify_uri IN (?, ?) OR spotify_uri LIKE ? ORDER BY last_requested_at DESC LIMIT 1");
                $stmt->execute([$trackId, 'spotify:track:' . $trackId, '%' . $trackId]);
                $cached = trim((string)$stmt->fetchColumn());
                $artworkCache[$trackId] = $cached;
                return $cached;
            }
        } catch (Throwable $e) {
            return '';
        }
    }

    return '';
}

function public_track_artwork_alt($track) {
    $title = trim((string)($track['song_title'] ?? 'track'));
    $artist = trim((string)($track['artist'] ?? ''));
    if ($artist !== '') {
        return $title . ' by ' . $artist . ' artwork';
    }
    return $title . ' artwork';
}

function public_render_track_artwork($track, $className = '') {
    $artwork = public_track_artwork_url($track);
    $class = trim('public-track-artwork ' . $className);
    if ($artwork !== '') {
        ?>
        <span class="<?= public_h($class) ?> has-artwork">
          <img src="<?= public_h($artwork) ?>" alt="<?= public_h(public_track_artwork_alt($track)) ?>" loading="lazy" onerror="this.closest('.public-track-artwork').classList.remove('has-artwork'); this.remove();">
        </span>
        <?php
        return;
    }
    ?>
    <span class="<?= public_h($class) ?> public-track-artwork-placeholder" aria-hidden="true">♫</span>
    <?php
}

function public_group_rows($request) {
    return isset($request['rows']) && is_array($request['rows']) && $request['rows'] ? $request['rows'] : [$request];
}

function public_group_request_count($request) {
    return isset($request['request_count']) ? max(1, (int)$request['request_count']) : count(public_group_rows($request));
}

function public_group_status_label($request) {
    $rows = public_group_rows($request);
    $hasQueued = false;
    $hasPending = false;
    $hasMaybe = false;
    $hasDuplicate = false;
    $hasPlayed = false;
    $hasRejected = false;
    $allPlayed = true;
    $allRejected = true;

    foreach ($rows as $row) {
        $status = strtolower((string)($row['status'] ?? 'pending'));
        $spotifyStatus = strtolower((string)($row['spotify_queue_status'] ?? ''));
        $queuedAt = trim((string)($row['spotify_queued_at'] ?? ''));

        if ($status !== 'played') {
            $allPlayed = false;
        }
        if ($status !== 'rejected') {
            $allRejected = false;
        }
        if ($status === 'played') {
            $hasPlayed = true;
        } elseif ($status === 'rejected') {
            $hasRejected = true;
        } elseif ($status === 'maybe') {
            $hasMaybe = true;
        } elseif ($status === 'duplicate') {
            $hasDuplicate = true;
        } else {
            $hasPending = true;
        }

        if ($spotifyStatus !== '' || $queuedAt !== '') {
            $hasQueued = true;
        }
    }

    if ($allPlayed || ($hasPlayed && !$hasPending && !$hasMaybe && !$hasDuplicate && !$hasQueued && !$hasRejected)) {
        return 'Played';
    }

    if ($allRejected) {
        return 'Unable to play';
    }

    if ($hasQueued) {
        return 'In DJ queue';
    }

    if ($hasMaybe) {
        return 'Under review';
    }

    if ($hasDuplicate && !$hasPending) {
        return 'Already requested';
    }

    return 'Waiting';
}

function public_request_status_label($request) {
    if (isset($request['rows']) && is_array($request['rows'])) {
        return public_group_status_label($request);
    }

    $status = strtolower((string)($request['status'] ?? 'pending'));
    $spotifyStatus = strtolower((string)($request['spotify_queue_status'] ?? ''));
    $queuedAt = trim((string)($request['spotify_queued_at'] ?? ''));

    if ($status === 'played') {
        return 'Played';
    }

    if ($status === 'rejected') {
        return 'Unable to play';
    }

    if ($spotifyStatus !== '' || $queuedAt !== '') {
        return 'In DJ queue';
    }

    if ($status === 'maybe') {
        return 'Under review';
    }

    if ($status === 'duplicate') {
        return 'Already requested';
    }

    return 'Waiting';
}

function public_request_status_class($label) {
    $key = strtolower((string)$label);
    if (str_contains($key, 'played')) return 'played';
    if (str_contains($key, 'unable')) return 'unable';
    if (str_contains($key, 'queue')) return 'queued';
    if (str_contains($key, 'review')) return 'review';
    if (str_contains($key, 'already')) return 'duplicate';
    return 'waiting';
}

function public_request_dedication($request) {
    foreach (['dedication', 'message'] as $field) {
        if (!empty($request[$field])) {
            return trim((string)$request[$field]);
        }
    }

    return '';
}

function public_request_reject_reason_label($request) {
    if (isset($request['rows']) && is_array($request['rows'])) {
        if (public_request_status_label($request) !== 'Unable to play') {
            return '';
        }
        $reason = '';
        foreach ($request['rows'] as $row) {
            $candidate = strtolower(trim((string)($row['reject_reason'] ?? '')));
            if ($candidate !== '') {
                $reason = $candidate;
                break;
            }
        }
    } else {
        if (strtolower((string)($request['status'] ?? '')) !== 'rejected') {
            return '';
        }
        $reason = strtolower(trim((string)($request['reject_reason'] ?? '')));
    }
    $labels = [
        'not_suitable' => "Sorry, this one is not quite right for tonight's event.",
        'explicit' => 'Sorry, this one is not suitable for the audience tonight.',
        'already_played' => 'Sorry, this track has already been played tonight.',
        'time_constraints' => 'Sorry, there may not be enough time to fit this one in tonight.',
        'not_available' => 'Sorry, the DJ cannot access this track tonight.',
    ];

    return $labels[$reason] ?? 'Sorry, the DJ is unable to play this one tonight.';
}

function public_request_board_summary($requests) {
    $groups = is_array($requests) ? $requests : [];
    $groupTotal = count($groups);
    if ($groupTotal <= 0) {
        return 'Requests from tonight are shown below.';
    }

    $requestTotal = 0;
    $active = 0;
    foreach ($groups as $request) {
        $requestTotal += public_group_request_count($request);
        $label = public_request_status_label($request);
        if (!in_array($label, ['Played', 'Unable to play'], true)) {
            $active++;
        }
    }

    $songWord = $groupTotal === 1 ? 'song' : 'songs';
    $requestWord = $requestTotal === 1 ? 'request' : 'requests';
    $summary = $groupTotal . ' ' . $songWord . ' shown tonight';
    if ($requestTotal !== $groupTotal) {
        $summary .= ', covering ' . $requestTotal . ' ' . $requestWord;
    }

    if ($active > 0) {
        $activeWord = $active === 1 ? 'active song' : 'active songs';
        $summary .= ', including ' . $active . ' ' . $activeWord;
    }

    return $summary . '.';
}

function public_request_guest_name($request) {
    $name = trim((string)($request['guest_name'] ?? ''));
    return $name !== '' ? $name : 'Guest';
}


function public_render_request_board_item($request) {
    $requestStatus = public_request_status_label($request);
    $dedicationRows = public_group_rows($request);
    $requestCount = public_group_request_count($request);
    $isUnable = ($requestStatus === 'Unable to play');
    $groupClass = $requestCount > 1 ? ' is-grouped' : '';
    if ($isUnable) {
        $groupClass .= ' is-unable';
    }
    ?>
    <li class="public-request-board-item<?= public_h($groupClass) ?>">
      <div class="public-request-card-shell">
        <?php public_render_track_artwork($request, 'public-request-artwork'); ?>
        <div class="public-request-card-main">
          <div class="public-request-row-head">
            <strong><?= public_h($request['song_title'] ?? '') ?></strong>
            <span class="public-request-status <?= public_h(public_request_status_class($requestStatus)) ?>"><?= public_h($requestStatus) ?></span>
          </div>
          <span class="public-request-artist"><?= public_h($request['artist'] ?? '') ?></span>

          <?php if (!$isUnable): ?>
            <div class="public-request-member-list<?= $requestCount > 1 ? ' has-multiple' : '' ?>">
              <?php foreach ($dedicationRows as $memberRequest): ?>
                <?php $memberDedication = public_request_dedication($memberRequest); ?>
                <div class="public-request-member">
                  <small>Requested by <?= public_h(public_request_guest_name($memberRequest)) ?></small>
                  <?php if ($memberDedication !== ''): ?>
                    <p class="public-request-dedication">“<?= public_h($memberDedication) ?>”</p>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php $rejectReasonText = public_request_reject_reason_label($request); ?>
          <?php if ($rejectReasonText !== ''): ?>
            <p class="public-request-reason"><?= public_h($rejectReasonText) ?></p>
          <?php endif; ?>
        </div>
      </div>
    </li>
    <?php
}

function public_played_spotify_url($played) {
    $direct = trim((string)($played['spotify_track_url'] ?? ''));
    if ($direct !== '' && preg_match('~^https?://~i', $direct)) {
        return $direct;
    }

    $trackId = trim((string)($played['spotify_track_id'] ?? ''));
    if ($trackId !== '') {
        return 'https://open.spotify.com/track/' . rawurlencode($trackId);
    }

    $query = trim((string)($played['song_title'] ?? '') . ' ' . (string)($played['artist'] ?? ''));
    return $query !== '' ? 'https://open.spotify.com/search/' . rawurlencode($query) : '';
}

function public_event_share_url($event) {
    $base = function_exists('dttd_public_request_base_url') ? dttd_public_request_base_url('https://dancethruthedecades.co.uk') : 'https://dancethruthedecades.co.uk';
    return rtrim($base, '/') . '/event/' . rawurlencode(public_event_slug($event));
}

function public_event_share_text($event) {
    $title = trim((string)($event['event_name'] ?? $event['name'] ?? 'this Dance Thru The Decades event'));
    $venue = trim((string)($event['venue_name'] ?? $event['venue'] ?? ''));
    $date = trim((string)dttd_public_event_date($event));

    $lines = [];
    $lines[] = 'I’m thinking of going to ' . $title . ' with Dance Thru The Decades 🎶';
    if ($date !== '') {
        $lines[] = $date;
    }
    if ($venue !== '') {
        $lines[] = $venue;
    }
    $lines[] = 'Fancy joining me?';

    return implode("\n", $lines);
}

function public_played_share_text($played, $event) {
    $title = trim((string)($played['song_title'] ?? ''));
    $artist = trim((string)($played['artist'] ?? ''));
    $eventTitle = trim((string)($event['event_name'] ?? $event['name'] ?? 'this Dance Thru The Decades event'));

    $track = $title;
    if ($artist !== '') {
        $track .= ' by ' . $artist;
    }

    if ($track === '') {
        return 'I am at ' . $eventTitle . ' with Dance Thru The Decades.';
    }

    return 'I am at ' . $eventTitle . ' with Dance Thru The Decades, listening to ' . $track . ' 🎶';
}


function public_render_played_track_item($played, $event, $eventShareUrl) {
    $spotifyUrl = public_played_spotify_url($played);
    $shareText = public_played_share_text($played, $event);
    $requestCount = public_group_request_count($played);
    $trackTitle = trim((string)($played['song_title'] ?? 'track'));
    ?>
    <li class="public-played-track-row<?= $requestCount > 1 ? ' is-grouped' : '' ?>">
      <?php public_render_track_artwork($played, 'public-played-artwork'); ?>
      <div class="public-played-track-main">
        <strong><?= public_h($played['song_title'] ?? '') ?></strong>
        <span><?= public_h($played['artist'] ?? '') ?></span>
      </div>
      <div class="public-played-track-actions">
        <?php if ($spotifyUrl): ?>
          <a class="public-track-action public-track-icon-action spotify" href="<?= public_h($spotifyUrl) ?>" target="_blank" rel="noopener" aria-label="Open <?= public_h($trackTitle) ?> in Spotify" title="Open in Spotify">
            <span class="public-sr-only">Open in Spotify</span>
            <svg class="public-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <circle cx="12" cy="12" r="10"></circle>
              <path d="M7.3 9.4c3.1-1 6.8-.8 9.8.9"></path>
              <path d="M7.8 12.4c2.5-.8 5.5-.6 7.9.7"></path>
              <path d="M8.3 15.2c1.9-.6 4-.5 5.8.4"></path>
            </svg>
          </a>
        <?php endif; ?>
        <button class="public-track-action public-track-icon-action share" type="button" data-track-share data-share-title="<?= public_h(($played['song_title'] ?? 'Recently played') . ' | Dance Thru The Decades') ?>" data-share-text="<?= public_h($shareText) ?>" data-share-url="<?= public_h($eventShareUrl) ?>" aria-label="Share <?= public_h($trackTitle) ?>" title="Share this track">
          <span class="public-sr-only">Share this track</span>
          <svg class="public-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="18" cy="5" r="3"></circle>
            <circle cx="6" cy="12" r="3"></circle>
            <circle cx="18" cy="19" r="3"></circle>
            <path d="M8.7 10.7l6.6-3.5"></path>
            <path d="M8.7 13.3l6.6 3.5"></path>
          </svg>
        </button>
      </div>
    </li>
    <?php
}

function public_event_photo_table_ready() {
    return dttd_table_exists('event_photo_uploads')
        && dttd_table_column_exists('event_photo_uploads', 'event_id')
        && (
            dttd_table_column_exists('event_photo_uploads', 'file_path')
            || dttd_table_column_exists('event_photo_uploads', 'framed_path')
            || dttd_table_column_exists('event_photo_uploads', 'thumb_path')
            || dttd_table_column_exists('event_photo_uploads', 'original_path')
        );
}

function public_event_approved_photos($event_id, $limit = 12) {
    $photos = [];
    $limit = max(1, min(30, (int)$limit));
    $event_id = (int)$event_id;

    if (public_event_photo_table_ready()) {
        try {
            $selectColumns = ['event_id'];
            foreach (['id', 'file_path', 'framed_path', 'thumb_path', 'original_path', 'guest_name', 'status'] as $column) {
                if (dttd_table_column_exists('event_photo_uploads', $column)) {
                    $selectColumns[] = $column;
                }
            }
            $selectSql = implode(', ', array_unique($selectColumns));

            $statusFilter = dttd_table_column_exists('event_photo_uploads', 'status') ? "AND LOWER(status) = 'approved'" : '';
            $orderParts = [];
            foreach (['approved_at', 'uploaded_at', 'created_at'] as $dateColumn) {
                if (dttd_table_column_exists('event_photo_uploads', $dateColumn)) {
                    $orderParts[] = $dateColumn . ' DESC';
                }
            }
            if (dttd_table_column_exists('event_photo_uploads', 'id')) {
                $orderParts[] = 'id DESC';
            }
            $orderSql = $orderParts ? ' ORDER BY ' . implode(', ', array_unique($orderParts)) : '';

            // Keep LIMIT as an integer in SQL for better compatibility on shared hosts.
            $stmt = db()->prepare("SELECT $selectSql FROM event_photo_uploads WHERE event_id = ? $statusFilter $orderSql LIMIT $limit");
            $stmt->execute([$event_id]);

            foreach ($stmt->fetchAll() as $row) {
                $display = '';
                $thumb = '';

                if (function_exists('photo_row_display_paths')) {
                    $paths = photo_row_display_paths($row);
                    $display = trim((string)($paths['display'] ?? ''));
                    $thumb = trim((string)($paths['thumb'] ?? $display));
                }

                if ($display === '') {
                    foreach (['framed_path', 'file_path', 'original_path', 'thumb_path'] as $pathColumn) {
                        $candidate = trim((string)($row[$pathColumn] ?? ''));
                        if ($candidate !== '') {
                            $display = $candidate;
                            break;
                        }
                    }
                }

                if ($thumb === '') {
                    foreach (['thumb_path', 'framed_path', 'file_path', 'original_path'] as $pathColumn) {
                        $candidate = trim((string)($row[$pathColumn] ?? ''));
                        if ($candidate !== '') {
                            $thumb = $candidate;
                            break;
                        }
                    }
                }

                if ($display === '') {
                    continue;
                }

                $photos[] = [
                    'path' => ltrim($display, '/'),
                    'thumb' => ltrim(($thumb ?: $display), '/'),
                    'guest_name' => trim((string)($row['guest_name'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            error_log('[DTTD event photos] Approved photo lookup failed: ' . $e->getMessage());
            $photos = [];
        }
    }

    if (!$photos) {
        $fallbackDirs = [
            __DIR__ . '/uploads/event-photos/framed' => 'uploads/event-photos/framed/',
            __DIR__ . '/uploads/event-photos/approved' => 'uploads/event-photos/approved/',
            __DIR__ . '/uploads/event-photos' => 'uploads/event-photos/',
        ];

        foreach ($fallbackDirs as $dir => $urlPrefix) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [] as $file) {
                $base = basename($file);
                if (!str_contains($base, 'event-' . $event_id . '-') && !str_contains($base, '-' . $event_id . '-')) {
                    continue;
                }
                $photos[] = [
                    'path' => $urlPrefix . $base,
                    'thumb' => $urlPrefix . $base,
                    'guest_name' => '',
                ];
                if (count($photos) >= $limit) {
                    break 2;
                }
            }
        }
    }

    return array_slice($photos, 0, $limit);
}

$facebookUrl = defined('FACEBOOK_URL') ? FACEBOOK_URL : 'https://www.facebook.com/profile.php?id=61579454050951';
$public_current = 'events';
$gate_error = '';
$error = '';
$slug = trim((string)($_GET['slug'] ?? ''));
$event = null;
$hasEventAccess = false;
$publicDetailsMode = false;

$is_access_attempt = isset($_GET['code']) || isset($_GET['token']) || isset($_GET['access']) || isset($_POST['event_access_code']) || isset($_POST['event_code']) || isset($_POST['code']) || isset($_POST['token']) || isset($_POST['access']);

if ($is_access_attempt) {
    [$access_event, $access_error] = dttd_handle_event_access_submission('/event.php');
    $gate_error = $access_error;
}

if ($slug !== '') {
    $event = public_find_event_by_slug($slug);
    $publicDetailsMode = true;
}

if (!$event) {
    $event = dttd_event_from_access_cookie(false);
    $hasEventAccess = (bool)$event && dttd_event_access_allowed($event);
} else {
    $cookieEvent = dttd_event_from_access_cookie(false);
    $hasEventAccess = $cookieEvent && (int)$cookieEvent['id'] === (int)$event['id'] && dttd_event_access_allowed($cookieEvent);
}

$showGate = (!$event && !$publicDetailsMode);
$notFound = (!$event && $publicDetailsMode);

if ($event) {
    $title = $event['event_name'] ?? $event['name'] ?? 'Dance Thru The Decades Event';
    $venue = $event['venue_name'] ?? $event['venue'] ?? '';
    $venueAddress = $event['venue_address'] ?? '';
    $postcode = $event['postcode'] ?? $event['venue_postcode'] ?? '';
    $venueFacebook = $event['venue_facebook_url'] ?? $event['facebook_url'] ?? '';
    $venueWebsite = $event['venue_website_url'] ?? $event['website_url'] ?? '';
    $ticketUrl = $event['ticketing_url'] ?? $event['tickets_url'] ?? $event['venue_ticket_url'] ?? '';
    $imageUrl = public_event_image_url($event['event_image'] ?? '');
    $description = public_event_description($event);
    $status = public_event_status($event);
    $isCancelled = $status === 'cancelled';
    $cancelledMessage = $isCancelled ? public_cancelled_message($event) : '';
    $mapQuery = trim($venue . ' ' . $venueAddress . ' ' . $postcode);
    $mapEmbedUrl = $mapQuery ? 'https://www.google.com/maps?q=' . urlencode($mapQuery) . '&output=embed' : '';
    $mapExternalUrl = $mapQuery ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($mapQuery) : '';
    $eventShareUrl = public_event_share_url($event);
    $playedRequests = $hasEventAccess ? public_recent_played_requests((int)$event['id'], 25) : [];
    $pendingCount = $hasEventAccess ? public_pending_request_count((int)$event['id']) : 0;
    $publicRequests = $hasEventAccess ? public_event_request_board((int)$event['id'], 40) : [];
    $eventPhotos = $hasEventAccess ? public_event_approved_photos((int)$event['id'], 12) : [];
    $requestsOpen = $hasEventAccess && !$isCancelled ? event_requests_open($event) : false;
    $requestCloseIso = dttd_event_request_close_iso($event);
    $requestTimerLabel = dttd_event_request_timer_label($event);
    $requestCloseClock = dttd_event_request_close_clock_label($event);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $event ? public_h($title) : ($notFound ? 'Event Not Found' : 'Join Event') ?> | Dance Thru the Decades</title>
  <meta name="description" content="<?= $event ? public_h(($description ?: $title . ' at ' . $venue)) : 'Dance Thru the Decades event portal.' ?>">
  <link rel="stylesheet" href="/assets/public-site.css?v=291">
</head>
<body class="homepage-option-one public-event-detail-page public-event-portal-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <?php if ($showGate): ?>
      <section class="public-event-detail-hero public-feature-hero">
        <div class="option-one-logo-shell public-list-logo">
          <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
        </div>
        <p class="option-one-eyebrow">Event Portal</p>
        <h1 class="event-detail-title">Join This Event</h1>
        <p class="option-one-subtitle">Scan the QR code at the venue or enter the event code to continue.</p>
      </section>

      <section class="public-event-detail-section public-feature-section">
        <article class="public-empty-card public-access-card">
          <h2>Event access required</h2>
          <p>Enter the code displayed around the venue. We will remember this device until the event closes.</p>

          <?php if ($gate_error): ?>
            <div class="public-alert error"><?= public_h($gate_error) ?></div>
          <?php endif; ?>

          <form class="public-access-form" method="post" action="/event.php">
            <label for="event_access_code">Event code</label>
            <input id="event_access_code" name="event_access_code" inputmode="text" autocomplete="off" autocapitalize="characters" placeholder="Example: 5MKDP2" required>
            <button class="public-neon-btn" type="submit">Continue</button>
          </form>

          <div class="public-event-actions public-centred-actions">
            <a class="public-neon-btn subtle" href="/">Back to Website</a>
            <a class="public-neon-btn subtle" href="/events">Public Events</a>
          </div>
        </article>
      </section>

    <?php elseif ($notFound): ?>
      <section class="public-event-detail-hero">
        <div class="option-one-logo-shell public-list-logo">
          <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
        </div>
        <p class="option-one-eyebrow">Event Portal</p>
        <h1 class="event-detail-title">Event Not Found</h1>
        <p class="option-one-subtitle">This event link is not recognised.</p>

        <article class="public-empty-card">
          <h2>Check the link or QR code</h2>
          <p>Please check that the event link is correct, or scan the QR code again at the venue.</p>
          <a class="public-neon-btn" href="/">Back to Website</a>
        </article>
      </section>

    <?php else: ?>
      <section class="public-event-detail-hero">
        <div class="option-one-logo-shell public-list-logo">
          <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
        </div>
        <p class="option-one-eyebrow"><?= $hasEventAccess ? 'Event Portal' : ($isCancelled ? 'Cancelled Event' : 'Event Details') ?></p>
        <h1 class="event-detail-title"><?= public_h($title) ?></h1>

        <?php if ($venue): ?>
          <p class="option-one-subtitle"><?= public_h($venue) ?></p>
        <?php endif; ?>
      </section>

      <section class="public-event-detail-section">
        <?php if ($isCancelled): ?>
          <div class="public-cancelled-banner">
            <strong>Event Cancelled</strong>
            <span><?= public_h($cancelledMessage) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($hasEventAccess && !$isCancelled): ?>
          <article class="public-feature-card public-event-hub-card">
            <div class="public-feature-card-header">
              <div>
                <span class="public-feature-kicker">You are connected to this event</span>
                <h2>What would you like to do?</h2>
              </div>
              <span class="public-connected-pill">Access remembered</span>
            </div>

            <div class="public-event-action-grid">
              <?php if ($requestsOpen): ?>
                <a class="public-event-action-tile request-window-card is-open" href="/request.php">
                  <span>🎵</span>
                  <strong>Request a Song</strong>
                  <em>Send a request to the DJ queue</em>
                  <small class="public-request-timer" <?= $requestCloseIso ? 'data-countdown-to="' . public_h($requestCloseIso) . '"' : '' ?>><?= public_h($requestTimerLabel) ?></small>
                </a>
              <?php else: ?>
                <span class="public-event-action-tile request-window-card is-closed" aria-disabled="true">
                  <span>🎵</span>
                  <strong>Requests Closed</strong>
                  <em>Song requests are now closed<?= $requestCloseClock ? ' — closed at ' . public_h($requestCloseClock) : '' ?></em>
                  <small class="public-request-timer is-closed">Requests closed</small>
                </span>
              <?php endif; ?>
              <a class="public-event-action-tile" href="/upload.php">
                <span>📸</span>
                <strong>Upload Photos</strong>
                <em>Uploads wait for moderation</em>
              </a>
              <a class="public-event-action-tile" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener">
                <span>f</span>
                <strong>Follow Us</strong>
                <em>Facebook updates and photos</em>
              </a>
            </div>
          </article>

          <?php if (!$requestsOpen): ?>
            <div class="public-alert info public-request-window-closed-note">
              Song requests are now closed for this event. You can still view requests, see recently played tracks and upload photos.
            </div>
          <?php endif; ?>

          <div class="public-event-live-grid">
            <article class="public-feature-card public-live-card public-requests-card">
              <span class="public-feature-kicker">Queue</span>
              <h2>Requests tonight</h2>
              <?php if (!empty($publicRequests)): ?>
                <?php $visibleRequests = array_slice($publicRequests, 0, 5); $hiddenRequests = array_slice($publicRequests, 5); ?>
                <p class="public-card-summary"><?= public_h(public_request_board_summary($publicRequests)) ?></p>
                <ul class="public-request-board-list">
                  <?php foreach ($visibleRequests as $request): ?>
                    <?php public_render_request_board_item($request); ?>
                  <?php endforeach; ?>
                </ul>
                <?php if ($hiddenRequests): ?>
                  <details class="public-expand-list public-request-expand-list">
                    <summary>View all requests</summary>
                    <ul class="public-request-board-list public-request-board-list-extra">
                      <?php foreach ($hiddenRequests as $request): ?>
                        <?php public_render_request_board_item($request); ?>
                      <?php endforeach; ?>
                    </ul>
                  </details>
                <?php endif; ?>
              <?php else: ?>
                <p>No public requests yet. Be the first to request a track for tonight.</p>
                <a class="public-neon-btn subtle public-inline-action" href="/request.php">Request a Song</a>
              <?php endif; ?>
            </article>

            <article class="public-feature-card public-live-card public-played-card">
              <span class="public-feature-kicker">Played</span>
              <h2>Recently played</h2>
              <?php if ($playedRequests): ?>
                <?php $visiblePlayed = array_slice($playedRequests, 0, 5); $hiddenPlayed = array_slice($playedRequests, 5); ?>
                <ol class="public-mini-list public-played-list">
                  <?php foreach ($visiblePlayed as $played): ?>
                    <?php public_render_played_track_item($played, $event, $eventShareUrl); ?>
                  <?php endforeach; ?>
                </ol>
                <?php if ($hiddenPlayed): ?>
                  <details class="public-expand-list">
                    <summary>View more played songs</summary>
                    <ol class="public-mini-list public-played-list public-played-list-extra" start="6">
                      <?php foreach ($hiddenPlayed as $played): ?>
                        <?php public_render_played_track_item($played, $event, $eventShareUrl); ?>
                      <?php endforeach; ?>
                    </ol>
                  </details>
                <?php endif; ?>
              <?php else: ?>
                <p>Played-track history will appear here once songs have been marked as played.</p>
              <?php endif; ?>
            </article>
          </div>

          <article class="public-feature-card public-event-photo-carousel-card">
            <div class="public-feature-card-header">
              <div>
                <span class="public-feature-kicker">Photos & Memories</span>
                <h2>Event photos</h2>
              </div>
              <span class="public-gallery-card-actions"><a class="public-neon-btn subtle" href="/upload.php">Upload Photos</a><a class="public-neon-btn subtle" href="/gallery.php">View Gallery</a></span>
            </div>

            <?php if (!empty($eventPhotos)): ?>
              <div class="public-event-photo-grid">
                <?php foreach ($eventPhotos as $photo): ?>
                  <a class="public-event-photo-grid-item" href="/<?= public_h($photo['path']) ?>" target="_blank" rel="noopener">
                    <img src="/<?= public_h($photo['thumb'] ?? $photo['path']) ?>" alt="Approved photo from <?= public_h($title) ?>">
                    <?php if (!empty($photo['guest_name'])): ?>
                      <span>Shared by <?= public_h($photo['guest_name']) ?></span>
                    <?php endif; ?>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="public-event-photo-placeholder-grid">
                <div class="public-event-photo-placeholder-card">
                  <span class="public-placeholder-icon">📸</span>
                  <strong>Photos from tonight will appear here</strong>
                  <em>Once approved, guest uploads become part of the event memories.</em>
                </div>
                <div class="public-event-photo-placeholder-card">
                  <span class="public-placeholder-icon">✨</span>
                  <strong>Share your best dancefloor moment</strong>
                  <em>Upload photos from your phone and we will check them before they go live.</em>
                </div>
                <div class="public-event-photo-placeholder-card">
                  <span class="public-placeholder-icon">🎶</span>
                  <strong>Keep the memories together</strong>
                  <em>Approved photos will build into tonight’s gallery.</em>
                </div>
              </div>
              <div class="public-carousel-empty public-carousel-empty-actions">
                <strong>No approved photos yet.</strong>
                <span>Be the first to upload a memory from tonight. Photos appear here once approved.</span>
                <a class="public-neon-btn" href="/upload.php">Upload Photos</a>
              </div>
            <?php endif; ?>
          </article>
        <?php endif; ?>

        <article class="public-event-detail-card <?= $isCancelled ? 'is-cancelled' : '' ?>">
          <div class="public-event-detail-image <?= $imageUrl ? '' : 'public-event-placeholder' ?>">
            <?php if ($imageUrl): ?>
              <img src="<?= public_h($imageUrl) ?>" alt="<?= public_h($title) ?> event image" onerror="this.closest('.public-event-detail-image').classList.add('public-event-placeholder'); this.remove();">
            <?php else: ?>
              <span>♫</span>
            <?php endif; ?>
          </div>

          <div class="public-event-detail-body">
            <div class="public-event-title-row">
              <div class="public-event-title-main">
                <div class="public-event-date">
                  <strong><?= public_h(dttd_public_event_date($event)) ?></strong>
                  <?php if (dttd_public_event_time_range($event)): ?>
                    <span><?= public_h(dttd_public_event_time_range($event)) ?></span>
                  <?php endif; ?>
                </div>

                <?php if ($isCancelled): ?>
                  <span class="public-status-pill cancelled">Cancelled</span>
                <?php endif; ?>

                <h2><?= public_h($title) ?></h2>
              </div>

              <button class="public-event-share-button" type="button" data-track-share data-share-title="<?= public_h($title . ' | Dance Thru The Decades') ?>" data-share-text="<?= public_h(public_event_share_text($event)) ?>" data-share-url="<?= public_h($eventShareUrl) ?>" aria-label="Share this event" title="Share this event">
                <span class="public-sr-only">Share this event</span>
                <svg class="public-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                  <circle cx="18" cy="5" r="3"></circle>
                  <circle cx="6" cy="12" r="3"></circle>
                  <circle cx="18" cy="19" r="3"></circle>
                  <path d="M8.7 10.7l6.6-3.5"></path>
                  <path d="M8.7 13.3l6.6 3.5"></path>
                </svg>
              </button>
            </div>

            <?php if ($description): ?>
              <div class="public-event-description">
                <?= nl2br(public_h($description)) ?>
              </div>
            <?php endif; ?>

            <?php if ($venue): ?>
              <p><strong>Venue:</strong> <?= public_h($venue) ?></p>
            <?php endif; ?>

            <?php if ($venueAddress || $postcode): ?>
              <p><strong>Address:</strong> <?= public_h(trim($venueAddress . ' ' . $postcode)) ?></p>
            <?php endif; ?>

            <div class="public-event-actions">
              <?php if (!$isCancelled && $ticketUrl): ?>
                <a class="public-neon-btn" href="<?= public_h($ticketUrl) ?>" target="_blank" rel="noopener">Tickets</a>
              <?php endif; ?>

              <a class="public-neon-btn subtle" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener">Our Facebook</a>

              <?php if ($venueFacebook): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($venueFacebook) ?>" target="_blank" rel="noopener"><span class="venue-label">Venue</span><span class="venue-facebook-icon" aria-hidden="true">f</span></a>
              <?php endif; ?>

              <?php if ($venueWebsite): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($venueWebsite) ?>" target="_blank" rel="noopener">Venue Website</a>
              <?php endif; ?>

              <?php if ($mapExternalUrl): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($mapExternalUrl) ?>" target="_blank" rel="noopener">Open Map</a>
              <?php endif; ?>
            </div>

          </div>
        </article>

        <?php if ($mapEmbedUrl): ?>
          <section class="public-map-section">
            <h2>Venue Map</h2>
            <div class="public-map-frame">
              <iframe
                src="<?= public_h($mapEmbedUrl) ?>"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                title="<?= public_h($venue ?: 'Venue') ?> map"></iframe>
            </div>
          </section>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
  <script>
    (function(){
      function labelFor(ms){
        if(ms <= 0) return 'Requests closed';
        var total = Math.ceil(ms / 60000);
        var h = Math.floor(total / 60);
        var m = total % 60;
        if(h > 0) return 'Requests close in ' + h + 'h' + (m ? ' ' + m + 'm' : '');
        return 'Requests close in ' + Math.max(1, m) + 'm';
      }
      function tick(){
        document.querySelectorAll('[data-countdown-to]').forEach(function(el){
          var target = Date.parse(el.getAttribute('data-countdown-to'));
          if(!target) return;
          var remaining = target - Date.now();
          el.textContent = labelFor(remaining);
          if(remaining <= 0){
            el.classList.add('is-closed');
            var card = el.closest('.request-window-card');
            if(card){
              card.classList.remove('is-open');
              card.classList.add('is-closed');
            }
          }
        });
      }
      tick();
      setInterval(tick, 30000);
    })();

    document.querySelectorAll('[data-public-carousel]').forEach(function(carousel) {
      var track = carousel.querySelector('.public-photo-carousel-track');
      if (!track) return;
      carousel.querySelectorAll('.public-carousel-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var direction = btn.classList.contains('public-carousel-prev') ? -1 : 1;
          track.scrollBy({ left: direction * Math.max(260, track.clientWidth * 0.85), behavior: 'smooth' });
        });
      });
    });

    document.querySelectorAll('[data-track-share]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var title = btn.getAttribute('data-share-title') || 'Dance Thru The Decades';
        var text = btn.getAttribute('data-share-text') || 'I am at a Dance Thru The Decades event 🎶';
        var url = btn.getAttribute('data-share-url') || window.location.href;
        var payload = { title: title, text: text, url: url };

        if (navigator.share) {
          navigator.share(payload).catch(function(){});
          return;
        }

        var copyText = text + '\n' + url;
        var originalHtml = btn.innerHTML;
        var originalLabel = btn.getAttribute('aria-label') || 'Share this track';
        function showCopied(){
          btn.innerHTML = '<span class="public-share-copied">Copied</span>';
          btn.setAttribute('aria-label', 'Share text copied');
          btn.classList.add('copied');
          setTimeout(function(){
            btn.innerHTML = originalHtml;
            btn.setAttribute('aria-label', originalLabel);
            btn.classList.remove('copied');
          }, 1800);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(copyText).then(showCopied).catch(function(){
            window.prompt('Copy this to share:', copyText);
          });
        } else {
          window.prompt('Copy this to share:', copyText);
        }
      });
    });
  </script>
</body>
</html>
