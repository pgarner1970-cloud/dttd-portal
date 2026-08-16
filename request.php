<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/track-history.php';
dttd_no_cache_headers();
dttd_redirect_public_feature_to_primary_domain();

if (!function_exists('public_h')) {
    function public_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$facebookUrl = defined('FACEBOOK_URL') ? FACEBOOK_URL : 'https://www.facebook.com/profile.php?id=61579454050951';
$public_current = '';
$gate_error = '';
$success = false;
$error = '';

$is_access_attempt = (
    isset($_GET['code']) || isset($_GET['token']) || isset($_GET['access']) ||
    isset($_POST['event_access_code']) || isset($_POST['event_code']) || isset($_POST['code']) || isset($_POST['token']) || isset($_POST['access'])
);

if ($is_access_attempt && !isset($_POST['song_title'])) {
    [$access_event, $access_error] = dttd_handle_event_access_submission('/request.php');
    $gate_error = $access_error;
}

$event = dttd_event_from_access_cookie(true);
$available = $event ? event_is_available($event) : false;
$requests_open = $event ? event_requests_open($event) : false;

function dttd_request_column_exists($column) {
    return dttd_table_column_exists('song_requests', $column);
}

function dttd_request_event_label($event) {
    if (!$event) return 'Request a Song';

    $bits = [];
    if (!empty($event['event_name'])) $bits[] = $event['event_name'];
    if (!empty($event['venue_name'])) $bits[] = $event['venue_name'];

    return $bits ? implode(' at ', $bits) : 'Tonight\'s event';
}

function dttd_public_request_base_key($song_title, $artist) {
    return strtolower(trim((string)$song_title)) . '|' . strtolower(trim((string)$artist));
}

function dttd_public_new_request_group_id() {
    try {
        return 'grp_' . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return 'grp_' . uniqid('', true);
    }
}

function dttd_public_request_group_id_for_request($event_id, $song_title, $artist) {
    if (!dttd_request_column_exists('request_group_id')) {
        return null;
    }

    $base_key = dttd_public_request_base_key($song_title, $artist);

    try {
        $stmt = db()->prepare("
            SELECT request_group_id
            FROM song_requests
            WHERE event_id = ?
            AND request_group_id IS NOT NULL
            AND request_group_id <> ''
            AND status IN ('pending','maybe','duplicate')
            AND CONCAT(LOWER(TRIM(song_title)), '|', LOWER(TRIM(artist))) = ?
            ORDER BY created_at ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([(int)$event_id, $base_key]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (string)$existing;
        }
    } catch (Throwable $e) {
        return dttd_public_new_request_group_id();
    }

    return dttd_public_new_request_group_id();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['song_title']) && $event && $requests_open) {
    $guest_name = trim($_POST['guest_name'] ?? '');
    $song_title = trim($_POST['song_title'] ?? '');
    $artist = trim($_POST['artist'] ?? '');
    $dedication = trim($_POST['dedication'] ?? '');

    if ($guest_name === '' || $song_title === '' || $artist === '') {
        $error = 'Please enter your name, song title and artist.';
    } else {
        try {
            $columns = ['event_id', 'guest_name', 'song_title', 'artist'];
            $values = [(int)$event['id'], $guest_name, $song_title, $artist];

            if (dttd_request_column_exists('dedication')) {
                $columns[] = 'dedication';
                $values[] = $dedication;
            }

            $request_source = ($_POST['request_source'] ?? '') === 'spotify' ? 'spotify' : 'manual';
            $spotify_fields = [
                'spotify_track_id' => trim($_POST['spotify_track_id'] ?? ''),
                'spotify_track_url' => trim($_POST['spotify_track_url'] ?? ''),
                'spotify_artist_name' => trim($_POST['spotify_artist_name'] ?? ''),
                'spotify_album_image' => trim($_POST['spotify_album_image'] ?? ''),
                'request_source' => $request_source,
            ];

            foreach ($spotify_fields as $column => $value) {
                if (dttd_request_column_exists($column)) {
                    $columns[] = $column;
                    $values[] = $value;
                }
            }

            if (dttd_request_column_exists('source')) {
                $columns[] = 'source';
                $values[] = $request_source === 'spotify' ? 'api' : 'manual';
            }

            if (dttd_request_column_exists('request_group_id')) {
                $columns[] = 'request_group_id';
                $values[] = dttd_public_request_group_id_for_request((int)$event['id'], $song_title, $artist);
            }

            if (dttd_request_column_exists('created_at')) {
                $columns[] = 'created_at';
                $values[] = date('Y-m-d H:i:s');
            }

            $placeholders = array_fill(0, count($columns), '?');
            $stmt = db()->prepare("INSERT INTO song_requests (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")");
            $stmt->execute($values);
            if (function_exists('dttd_history_event_request_upsert_from_song_request_id')) {
                dttd_history_event_request_upsert_from_song_request_id((int)db()->lastInsertId());
            }
            $success = true;
        } catch (Throwable $e) {
            $error = 'Sorry, your request could not be sent just now. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['song_title']) && !$event) {
    $gate_error = 'Please enter the event code first, then send your request.';
}

$title = 'Request a Song';
$eventLabel = dttd_request_event_label($event);
$requestCloseClock = $event ? dttd_event_request_close_clock_label($event) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?= dttd_cache_meta_tags() ?>
  <title><?= public_h($title) ?> | Dance Thru the Decades</title>
  <meta name="description" content="Request a song at a Dance Thru the Decades event using the venue QR code or event code.">
  <link rel="stylesheet" href="<?= h(dttd_asset_url('assets/public-site.css')) ?>">
</head>
<body class="homepage-option-one public-event-feature-page public-request-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <section class="public-event-detail-hero public-feature-hero">
      <div class="option-one-logo-shell public-list-logo">
        <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
      </div>
      <p class="option-one-eyebrow">Song Requests</p>
      <h1 class="event-detail-title">Request a Song</h1>
      <p class="option-one-subtitle"><?= $event ? public_h($eventLabel) : 'Scan the QR code at the venue or enter the event code to continue.' ?></p>
    </section>

    <section class="public-event-detail-section public-feature-section">
      <?php if (!$event): ?>
        <article class="public-empty-card public-access-card">
          <h2>Join this event</h2>
          <p>Enter the event code displayed at the venue, or scan the QR code again. We will remember this device for the rest of the evening.</p>

          <?php if ($gate_error): ?>
            <div class="public-alert error"><?= public_h($gate_error) ?></div>
          <?php endif; ?>

          <form class="public-access-form" method="post" action="/request.php">
            <input type="hidden" name="next" value="request">
            <label for="event_access_code">Event code</label>
            <input id="event_access_code" name="event_access_code" inputmode="text" autocomplete="off" autocapitalize="characters" placeholder="Example: 5MKDP2" required>
            <button class="public-neon-btn" type="submit">Continue</button>
          </form>

          <p class="public-small-note">The code is shown on venue posters, table cards or screens.</p>
          <a class="public-neon-btn subtle" href="/">Back to Website</a>
        </article>

      <?php elseif (!$available): ?>
        <article class="public-empty-card public-access-card">
          <h2>Requests unavailable</h2>
          <p>Song requests are not currently available for this event.</p>
          <a class="public-neon-btn" href="/event.php">Back to Event</a>
        </article>

      <?php elseif (!$requests_open): ?>
        <article class="public-empty-card public-access-card">
          <h2>Requests closed</h2>
          <p>Song requests have closed for this event so the DJ can finish the night smoothly.</p>
          <?php if ($requestCloseClock): ?>
            <p class="public-small-note">The request window closed at <?= public_h($requestCloseClock) ?>.</p>
          <?php endif; ?>
          <div class="public-event-actions public-centred-actions">
            <a class="public-neon-btn" href="/event.php">Back to Event</a>
            <a class="public-neon-btn subtle" href="/upload.php">Upload Photos</a>
          </div>
        </article>

      <?php elseif ($success): ?>
        <article class="public-empty-card public-access-card">
          <h2>Request sent</h2>
          <p>Thanks — your request has been sent to the DJ queue.</p>
          <div class="public-event-actions public-centred-actions">
            <a class="public-neon-btn" href="/request.php">Send another request</a>
            <a class="public-neon-btn subtle" href="/event.php">Back to Event</a>
          </div>
        </article>

      <?php else: ?>
        <article class="public-feature-card public-request-card compact-request-card">
          <div class="public-feature-card-header">
            <div>
              <span class="public-feature-kicker">Connected to this event</span>
              <h2><?= public_h($eventLabel) ?></h2>
            </div>
          </div>

          <?php if ($error): ?>
            <div class="public-alert error"><?= public_h($error) ?></div>
          <?php endif; ?>

          <form class="public-request-form public-request-form-clean public-request-search-first" method="post" action="/request.php" data-spotify-request-form>
            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">

            <section class="request-song-stage" data-request-song-stage>
              <div class="request-stage-heading">
                <span class="request-stage-number">1</span>
                <div>
                  <h3>Find your song</h3>
                  <p>Search by song title, artist, or both.</p>
                </div>
              </div>

              <div class="request-field request-search-field">
                <label for="spotify_request_query">Search songs or artists</label>
                <div class="request-search-control">
                  <span class="request-search-icon" aria-hidden="true">⌕</span>
                  <input id="spotify_request_query" type="search" autocomplete="off" enterkeyhint="search" placeholder="Example: Michael Jackson Billie Jean" data-spotify-query>
                </div>
              </div>

              <div class="spotify-search-box public-spotify-search-box">
                <small class="spotify-search-status" data-spotify-status></small>
                <div class="spotify-results" data-spotify-results hidden></div>
              </div>

              <button class="request-manual-toggle" type="button" data-request-manual-toggle>Can't find it? Enter the song manually</button>
            </section>

            <section class="request-completion-stage" data-request-completion-stage>
              <div class="request-stage-heading">
                <span class="request-stage-number">2</span>
                <div>
                  <h3>Complete your request</h3>
                  <p>Add your name and an optional dedication.</p>
                </div>
              </div>

              <div class="spotify-selected" data-spotify-selected hidden></div>

              <div class="request-song-artist-row request-manual-fields" data-request-manual-fields>
                <div class="request-field">
                  <label for="song_title">Song title *</label>
                  <input id="song_title" name="song_title" required maxlength="190" placeholder="Example: September" value="<?= public_h($_POST['song_title'] ?? '') ?>">
                </div>

                <div class="request-field">
                  <label for="artist">Artist *</label>
                  <input id="artist" name="artist" required maxlength="190" placeholder="Example: Earth, Wind & Fire" value="<?= public_h($_POST['artist'] ?? '') ?>">
                </div>
              </div>

              <input type="hidden" name="spotify_track_id" value="<?= public_h($_POST['spotify_track_id'] ?? '') ?>">
              <input type="hidden" name="spotify_track_url" value="<?= public_h($_POST['spotify_track_url'] ?? '') ?>">
              <input type="hidden" name="spotify_artist_name" value="<?= public_h($_POST['spotify_artist_name'] ?? '') ?>">
              <input type="hidden" name="spotify_album_image" value="<?= public_h($_POST['spotify_album_image'] ?? '') ?>">
              <input type="hidden" name="request_source" value="<?= (($_POST['request_source'] ?? '') === 'spotify') ? 'spotify' : 'manual' ?>">

              <div class="request-field request-name-field">
                <label for="guest_name">Your name *</label>
                <input id="guest_name" name="guest_name" required maxlength="120" autocomplete="name" placeholder="Your name" value="<?= public_h($_POST['guest_name'] ?? '') ?>">
              </div>

              <div class="request-field request-dedication-field">
                <label for="dedication">Dedication / message <span>optional</span></label>
                <textarea id="dedication" name="dedication" rows="2" placeholder="Add a message or dedication if you like"><?= public_h($_POST['dedication'] ?? '') ?></textarea>
              </div>

              <div class="request-submit-row">
                <button class="request-change-song" type="button" data-request-change-song>Change song</button>
                <button class="public-neon-btn public-submit-btn" type="submit">Send Request</button>
              </div>
            </section>
          </form>
        </article>
      <?php endif; ?>
    </section>

    <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
  <script src="<?= h(dttd_asset_url('assets/spotify-request-search.js')) ?>"></script>
<?= dttd_bfcache_reload_script() ?>
</body>
</html>
