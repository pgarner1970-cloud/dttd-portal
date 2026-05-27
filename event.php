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

    try {
        $stmt = db()->prepare("
            SELECT song_title, artist, created_at
            FROM song_requests
            WHERE event_id = ? AND status = 'played'
            ORDER BY updated_at DESC, created_at DESC, id DESC
            LIMIT " . $limit . "
        ");
        $stmt->execute([(int)$event_id]);
        return $stmt->fetchAll();
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
    foreach (['dedication', 'message', 'spotify_queue_status', 'spotify_queued_at', 'updated_at'] as $column) {
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
              AND LOWER(COALESCE(status, 'pending')) NOT IN ('rejected', 'removed', 'hidden')
            ORDER BY
              CASE
                WHEN LOWER(COALESCE(status, 'pending')) IN ('pending','maybe','duplicate') THEN 0
                WHEN LOWER(COALESCE(status, 'pending')) = 'played' THEN 2
                ELSE 1
              END ASC,
              created_at DESC,
              id DESC
            LIMIT " . $limit . "
        ");
        $stmt->execute([(int)$event_id]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function public_request_status_label($request) {
    $status = strtolower((string)($request['status'] ?? 'pending'));
    $spotifyStatus = strtolower((string)($request['spotify_queue_status'] ?? ''));
    $queuedAt = trim((string)($request['spotify_queued_at'] ?? ''));

    if ($status === 'played') {
        return 'Played';
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

function public_request_guest_name($request) {
    $name = trim((string)($request['guest_name'] ?? ''));
    return $name !== '' ? $name : 'Guest';
}

function public_event_photo_table_ready() {
    return dttd_table_exists('event_photo_uploads')
        && dttd_table_column_exists('event_photo_uploads', 'event_id')
        && dttd_table_column_exists('event_photo_uploads', 'file_path');
}

function public_event_approved_photos($event_id, $limit = 12) {
    $photos = [];
    $limit = max(1, min(30, (int)$limit));

    if (public_event_photo_table_ready()) {
        try {
            $statusFilter = dttd_table_column_exists('event_photo_uploads', 'status') ? "AND status = 'approved'" : '';
            $orderParts = [];
            if (dttd_table_column_exists('event_photo_uploads', 'approved_at')) {
                $orderParts[] = 'approved_at DESC';
            }
            if (dttd_table_column_exists('event_photo_uploads', 'uploaded_at')) {
                $orderParts[] = 'uploaded_at DESC';
            }
            if (dttd_table_column_exists('event_photo_uploads', 'created_at')) {
                $orderParts[] = 'created_at DESC';
            }
            $orderParts[] = 'id DESC';
            $orderSql = implode(', ', array_unique($orderParts));

            $stmt = db()->prepare("SELECT file_path, guest_name FROM event_photo_uploads WHERE event_id = ? $statusFilter ORDER BY $orderSql LIMIT ?");
            $stmt->execute([(int)$event_id, $limit]);
            foreach ($stmt->fetchAll() as $row) {
                $path = trim((string)($row['file_path'] ?? ''));
                if ($path === '') {
                    continue;
                }
                $photos[] = [
                    'path' => ltrim($path, '/'),
                    'guest_name' => trim((string)($row['guest_name'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            $photos = [];
        }
    }

    if (!$photos) {
        $approvedDir = __DIR__ . '/uploads/event-photos/approved';
        if (is_dir($approvedDir)) {
            foreach (glob($approvedDir . '/event-' . (int)$event_id . '-*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE) ?: [] as $file) {
                $photos[] = [
                    'path' => 'uploads/event-photos/approved/' . basename($file),
                    'guest_name' => '',
                ];
                if (count($photos) >= $limit) {
                    break;
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
    $playedRequests = $hasEventAccess ? public_recent_played_requests((int)$event['id'], 25) : [];
    $pendingCount = $hasEventAccess ? public_pending_request_count((int)$event['id']) : 0;
    $publicRequests = $hasEventAccess ? public_event_request_board((int)$event['id'], 40) : [];
    $eventPhotos = $hasEventAccess ? public_event_approved_photos((int)$event['id'], 12) : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $event ? public_h($title) : ($notFound ? 'Event Not Found' : 'Join Event') ?> | Dance Thru the Decades</title>
  <meta name="description" content="<?= $event ? public_h(($description ?: $title . ' at ' . $venue)) : 'Dance Thru the Decades event portal.' ?>">
  <link rel="stylesheet" href="/assets/public-site.css?v=169">
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
              <a class="public-event-action-tile" href="/request.php">
                <span>🎵</span>
                <strong>Request a Song</strong>
                <em>Send a request to the DJ queue</em>
              </a>
              <a class="public-event-action-tile" href="/gallery.php">
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

          <div class="public-event-live-grid">
            <article class="public-feature-card public-live-card public-requests-card">
              <span class="public-feature-kicker">Queue</span>
              <h2>Requests tonight</h2>
              <?php if (!empty($publicRequests)): ?>
                <?php $visibleRequests = array_slice($publicRequests, 0, 5); $hiddenRequests = array_slice($publicRequests, 5); ?>
                <?php if ($pendingCount > 0): ?>
                  <p class="public-card-summary"><?= public_h($pendingCount . ' request' . ($pendingCount === 1 ? '' : 's') . ' waiting for the DJ.') ?></p>
                <?php else: ?>
                  <p class="public-card-summary">Requests from tonight are shown below.</p>
                <?php endif; ?>
                <ul class="public-request-board-list">
                  <?php foreach ($visibleRequests as $request): ?>
                    <?php $requestStatus = public_request_status_label($request); $dedicationText = public_request_dedication($request); ?>
                    <li>
                      <div class="public-request-row-head">
                        <strong><?= public_h($request['song_title'] ?? '') ?></strong>
                        <span class="public-request-status <?= public_h(public_request_status_class($requestStatus)) ?>"><?= public_h($requestStatus) ?></span>
                      </div>
                      <span class="public-request-artist"><?= public_h($request['artist'] ?? '') ?></span>
                      <small>Requested by <?= public_h(public_request_guest_name($request)) ?></small>
                      <?php if ($dedicationText !== ''): ?>
                        <p class="public-request-dedication">“<?= public_h($dedicationText) ?>”</p>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <?php if ($hiddenRequests): ?>
                  <details class="public-expand-list public-request-expand-list">
                    <summary>View all requests</summary>
                    <ul class="public-request-board-list public-request-board-list-extra">
                      <?php foreach ($hiddenRequests as $request): ?>
                        <?php $requestStatus = public_request_status_label($request); $dedicationText = public_request_dedication($request); ?>
                        <li>
                          <div class="public-request-row-head">
                            <strong><?= public_h($request['song_title'] ?? '') ?></strong>
                            <span class="public-request-status <?= public_h(public_request_status_class($requestStatus)) ?>"><?= public_h($requestStatus) ?></span>
                          </div>
                          <span class="public-request-artist"><?= public_h($request['artist'] ?? '') ?></span>
                          <small>Requested by <?= public_h(public_request_guest_name($request)) ?></small>
                          <?php if ($dedicationText !== ''): ?>
                            <p class="public-request-dedication">“<?= public_h($dedicationText) ?>”</p>
                          <?php endif; ?>
                        </li>
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
                    <li><strong><?= public_h($played['song_title'] ?? '') ?></strong><span><?= public_h($played['artist'] ?? '') ?></span></li>
                  <?php endforeach; ?>
                </ol>
                <?php if ($hiddenPlayed): ?>
                  <details class="public-expand-list">
                    <summary>View more played songs</summary>
                    <ol class="public-mini-list public-played-list public-played-list-extra" start="6">
                      <?php foreach ($hiddenPlayed as $played): ?>
                        <li><strong><?= public_h($played['song_title'] ?? '') ?></strong><span><?= public_h($played['artist'] ?? '') ?></span></li>
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
              <a class="public-neon-btn subtle" href="/gallery.php">Upload / View Gallery</a>
            </div>

            <?php if (!empty($eventPhotos)): ?>
              <div class="public-photo-carousel" data-public-carousel>
                <button class="public-carousel-btn public-carousel-prev" type="button" aria-label="Previous photo">‹</button>
                <div class="public-photo-carousel-track">
                  <?php foreach ($eventPhotos as $photo): ?>
                    <a class="public-photo-carousel-slide" href="/<?= public_h($photo['path']) ?>" target="_blank" rel="noopener">
                      <img src="/<?= public_h($photo['path']) ?>" alt="Approved photo from <?= public_h($title) ?>">
                      <?php if (!empty($photo['guest_name'])): ?>
                        <span>Shared by <?= public_h($photo['guest_name']) ?></span>
                      <?php endif; ?>
                    </a>
                  <?php endforeach; ?>
                </div>
                <button class="public-carousel-btn public-carousel-next" type="button" aria-label="Next photo">›</button>
              </div>
            <?php else: ?>
              <div class="public-photo-carousel public-placeholder-carousel" data-public-carousel>
                <button class="public-carousel-btn public-carousel-prev" type="button" aria-label="Previous photo placeholder">‹</button>
                <div class="public-photo-carousel-track">
                  <div class="public-photo-carousel-slide public-photo-placeholder-slide">
                    <span class="public-placeholder-icon">📸</span>
                    <strong>Photos from tonight will appear here</strong>
                    <em>Once approved, guest uploads become part of the event memories.</em>
                  </div>
                  <div class="public-photo-carousel-slide public-photo-placeholder-slide">
                    <span class="public-placeholder-icon">✨</span>
                    <strong>Share your best dancefloor moment</strong>
                    <em>Upload photos from your phone and we will check them before they go live.</em>
                  </div>
                  <div class="public-photo-carousel-slide public-photo-placeholder-slide">
                    <span class="public-placeholder-icon">🎶</span>
                    <strong>Keep the memories together</strong>
                    <em>Approved photos will build into tonight’s gallery.</em>
                  </div>
                </div>
                <button class="public-carousel-btn public-carousel-next" type="button" aria-label="Next photo placeholder">›</button>
              </div>
              <div class="public-carousel-empty public-carousel-empty-actions">
                <strong>No approved photos yet.</strong>
                <span>Be the first to upload a memory from tonight. Photos appear here once approved.</span>
                <a class="public-neon-btn" href="/gallery.php">Upload Photos</a>
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

            <?php if (!$hasEventAccess && !$isCancelled): ?>
              <div class="public-qr-only-note">
                <strong>At the event?</strong>
                <span>Song requests and guest photo uploads open after you scan the venue QR code or enter the event code.</span>
              </div>
            <?php endif; ?>
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
  </script>
</body>
</html>
