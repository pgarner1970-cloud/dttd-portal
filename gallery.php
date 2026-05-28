<?php
require_once __DIR__ . '/includes/db.php';
dttd_redirect_public_feature_to_primary_domain();

if (!function_exists('public_h')) {
    function public_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$facebookUrl = defined('FACEBOOK_URL') ? FACEBOOK_URL : 'https://www.facebook.com/profile.php?id=61579454050951';
$public_current = 'gallery';
$connectedEvent = dttd_event_from_access_cookie(false);

function dttd_public_gallery_table_ready() {
    return dttd_table_exists('event_photo_uploads')
        && dttd_table_column_exists('event_photo_uploads', 'event_id')
        && dttd_table_column_exists('event_photo_uploads', 'file_path')
        && dttd_table_column_exists('event_photo_uploads', 'status');
}

function dttd_gallery_event_select_columns() {
    $columns = [
        'e.id AS event_id',
    ];

    foreach (['event_name', 'venue_name', 'event_date', 'start_time', 'end_time', 'public_slug'] as $column) {
        if (dttd_event_column_exists($column)) {
            $columns[] = "e.`$column` AS `$column`";
        }
    }

    return implode(', ', $columns);
}

function dttd_gallery_photo_select_columns() {
    $columns = [
        'p.id AS photo_id',
        'p.event_id',
        'p.file_path',
    ];

    foreach (['guest_name', 'uploaded_at', 'created_at', 'approved_at', 'framed_path', 'thumb_path', 'original_path'] as $column) {
        if (dttd_table_column_exists('event_photo_uploads', $column)) {
            $columns[] = "p.`$column` AS `$column`";
        }
    }

    return implode(', ', $columns);
}

function dttd_gallery_display_path($row) {
    foreach (['thumb_path', 'framed_path', 'file_path'] as $column) {
        if (!empty($row[$column])) {
            return ltrim((string)$row[$column], '/');
        }
    }

    return '';
}

function dttd_gallery_full_path($row) {
    foreach (['framed_path', 'file_path', 'original_path'] as $column) {
        if (!empty($row[$column])) {
            return ltrim((string)$row[$column], '/');
        }
    }

    return dttd_gallery_display_path($row);
}

function dttd_gallery_event_title($row) {
    $title = trim((string)($row['event_name'] ?? ''));
    return $title !== '' ? $title : 'Dance Thru The Decades Event';
}

function dttd_gallery_event_venue($row) {
    return trim((string)($row['venue_name'] ?? ''));
}

function dttd_gallery_event_date_label($row) {
    if (empty($row['event_date'])) {
        return '';
    }

    try {
        return (new DateTime((string)$row['event_date']))->format('D j M Y');
    } catch (Throwable $e) {
        return (string)$row['event_date'];
    }
}

function dttd_gallery_event_subtitle($row) {
    $bits = [];
    $venue = dttd_gallery_event_venue($row);
    $date = dttd_gallery_event_date_label($row);

    if ($venue !== '') $bits[] = $venue;
    if ($date !== '') $bits[] = $date;

    return implode(' · ', $bits);
}

function dttd_gallery_event_key($row) {
    if (!empty($row['public_slug'])) {
        return (string)$row['public_slug'];
    }

    return (string)($row['event_id'] ?? '');
}

function dttd_public_gallery_filters() {
    return [
        'event' => trim((string)($_GET['event'] ?? '')),
        'venue' => trim((string)($_GET['venue'] ?? '')),
        'date' => trim((string)($_GET['date'] ?? '')),
        'q' => trim((string)($_GET['q'] ?? '')),
    ];
}

function dttd_public_gallery_filter_query($filters, &$params) {
    $where = ["p.status = 'approved'", "p.file_path IS NOT NULL", "p.file_path <> ''"];
    $params = [];

    if ($filters['event'] !== '') {
        if (ctype_digit($filters['event'])) {
            $where[] = 'e.id = ?';
            $params[] = (int)$filters['event'];
        } elseif (dttd_event_column_exists('public_slug')) {
            $where[] = 'e.public_slug = ?';
            $params[] = $filters['event'];
        }
    }

    if ($filters['venue'] !== '' && dttd_event_column_exists('venue_name')) {
        $where[] = 'e.venue_name = ?';
        $params[] = $filters['venue'];
    }

    if ($filters['date'] !== '' && dttd_event_column_exists('event_date')) {
        if (preg_match('/^\d{4}-\d{2}$/', $filters['date'])) {
            $where[] = "DATE_FORMAT(e.event_date, '%Y-%m') = ?";
            $params[] = $filters['date'];
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) {
            $where[] = 'e.event_date = ?';
            $params[] = $filters['date'];
        }
    }

    if ($filters['q'] !== '') {
        $searchParts = [];
        if (dttd_event_column_exists('event_name')) {
            $searchParts[] = 'e.event_name LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }
        if (dttd_event_column_exists('venue_name')) {
            $searchParts[] = 'e.venue_name LIKE ?';
            $params[] = '%' . $filters['q'] . '%';
        }
        if ($searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    return implode(' AND ', $where);
}

function dttd_public_gallery_photos($filters) {
    if (!dttd_public_gallery_table_ready()) {
        return [];
    }

    $params = [];
    $where = dttd_public_gallery_filter_query($filters, $params);
    $photoCols = dttd_gallery_photo_select_columns();
    $eventCols = dttd_gallery_event_select_columns();
    $order = dttd_event_column_exists('event_date') ? 'e.event_date DESC, ' : '';
    $order .= dttd_table_column_exists('event_photo_uploads', 'approved_at') ? 'p.approved_at DESC, p.id DESC' : 'p.id DESC';

    try {
        $stmt = db()->prepare("SELECT $photoCols, $eventCols FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE $where ORDER BY $order LIMIT 120");
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_public_gallery_event_options() {
    if (!dttd_public_gallery_table_ready()) {
        return [];
    }

    $eventCols = dttd_gallery_event_select_columns();
    $order = dttd_event_column_exists('event_date') ? 'e.event_date DESC, e.id DESC' : 'e.id DESC';

    try {
        $stmt = db()->query("SELECT $eventCols, COUNT(p.id) AS photo_count FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE p.status = 'approved' GROUP BY e.id ORDER BY $order LIMIT 100");
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_public_gallery_venue_options() {
    if (!dttd_public_gallery_table_ready() || !dttd_event_column_exists('venue_name')) {
        return [];
    }

    try {
        $stmt = db()->query("SELECT e.venue_name, COUNT(p.id) AS photo_count FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE p.status = 'approved' AND e.venue_name IS NOT NULL AND e.venue_name <> '' GROUP BY e.venue_name ORDER BY e.venue_name ASC LIMIT 100");
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_public_gallery_month_options() {
    if (!dttd_public_gallery_table_ready() || !dttd_event_column_exists('event_date')) {
        return [];
    }

    try {
        $stmt = db()->query("SELECT DATE_FORMAT(e.event_date, '%Y-%m') AS event_month, DATE_FORMAT(e.event_date, '%M %Y') AS event_month_label, COUNT(p.id) AS photo_count FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE p.status = 'approved' AND e.event_date IS NOT NULL GROUP BY event_month, event_month_label ORDER BY event_month DESC LIMIT 48");
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

$filters = dttd_public_gallery_filters();
$photos = dttd_public_gallery_photos($filters);
$eventOptions = dttd_public_gallery_event_options();
$venueOptions = dttd_public_gallery_venue_options();
$monthOptions = dttd_public_gallery_month_options();
$hasFilters = $filters['event'] !== '' || $filters['venue'] !== '' || $filters['date'] !== '' || $filters['q'] !== '';
$galleryCount = count($photos);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Event Photos | Dance Thru the Decades</title>
  <meta name="description" content="Browse approved Dance Thru the Decades event photos by event, venue or date.">
  <link rel="stylesheet" href="/assets/public-site.css?v=169">
</head>
<body class="homepage-option-one public-event-feature-page public-gallery-page public-gallery-archive-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <section class="public-event-detail-hero public-feature-hero public-gallery-hero">
      <div class="option-one-logo-shell public-list-logo">
        <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
      </div>
      <p class="option-one-eyebrow">Photos & Memories</p>
      <h1 class="event-detail-title">Event Photos</h1>
      <p class="option-one-subtitle">Browse approved photos from Dance Thru The Decades events.</p>

      <?php if ($connectedEvent): ?>
        <div class="public-gallery-connected-panel">
          <strong>You’re connected to <?= public_h($connectedEvent['event_name'] ?? 'this event') ?></strong>
          <span>Upload photos from this device until the event closes.</span>
          <a class="public-neon-btn" href="/upload.php">Upload Photos</a>
          <a class="public-neon-btn subtle" href="/event.php">This Event</a>
        </div>
      <?php endif; ?>
    </section>

    <section class="public-event-detail-section public-feature-section public-gallery-archive-section">
      <article class="public-feature-card public-gallery-filter-card">
        <div class="public-feature-card-header">
          <div>
            <span class="public-feature-kicker">Browse Gallery</span>
            <h2>Find photos</h2>
          </div>
          <?php if ($hasFilters): ?>
            <a class="public-neon-btn subtle" href="/gallery.php">Clear filters</a>
          <?php endif; ?>
        </div>

        <form class="public-gallery-filter-form" method="get" action="/gallery.php">
          <label>
            Event
            <select name="event">
              <option value="">All events</option>
              <?php foreach ($eventOptions as $eventOption): ?>
                <?php $value = dttd_gallery_event_key($eventOption); ?>
                <option value="<?= public_h($value) ?>" <?= $filters['event'] === (string)$value ? 'selected' : '' ?>>
                  <?= public_h(dttd_gallery_event_title($eventOption)) ?><?= dttd_gallery_event_subtitle($eventOption) ? ' — ' . public_h(dttd_gallery_event_subtitle($eventOption)) : '' ?> (<?= (int)($eventOption['photo_count'] ?? 0) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            Venue
            <select name="venue">
              <option value="">All venues</option>
              <?php foreach ($venueOptions as $venueOption): ?>
                <?php $venue = (string)($venueOption['venue_name'] ?? ''); ?>
                <option value="<?= public_h($venue) ?>" <?= $filters['venue'] === $venue ? 'selected' : '' ?>><?= public_h($venue) ?> (<?= (int)($venueOption['photo_count'] ?? 0) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            Date
            <select name="date">
              <option value="">All dates</option>
              <?php foreach ($monthOptions as $monthOption): ?>
                <?php $month = (string)($monthOption['event_month'] ?? ''); ?>
                <option value="<?= public_h($month) ?>" <?= $filters['date'] === $month ? 'selected' : '' ?>><?= public_h($monthOption['event_month_label'] ?? $month) ?> (<?= (int)($monthOption['photo_count'] ?? 0) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            Search
            <input name="q" value="<?= public_h($filters['q']) ?>" placeholder="Event or venue">
          </label>

          <button class="public-neon-btn" type="submit">Filter Photos</button>
        </form>
      </article>

      <article class="public-feature-card public-gallery-results-card">
        <div class="public-feature-card-header">
          <div>
            <span class="public-feature-kicker">Approved Photos</span>
            <h2><?= $hasFilters ? 'Filtered photos' : 'Latest photos' ?></h2>
          </div>
          <span class="public-gallery-count-pill"><?= (int)$galleryCount ?> photo<?= $galleryCount === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($photos): ?>
          <div class="public-gallery-archive-grid">
            <?php foreach ($photos as $index => $photo): ?>
              <?php
                $thumbPath = dttd_gallery_display_path($photo);
                $fullPath = dttd_gallery_full_path($photo);
                $title = dttd_gallery_event_title($photo);
                $subtitle = dttd_gallery_event_subtitle($photo);
                $guest = trim((string)($photo['guest_name'] ?? ''));
              ?>
              <button class="public-gallery-photo-card" type="button"
                data-gallery-index="<?= (int)$index ?>"
                data-full-src="/<?= public_h($fullPath) ?>"
                data-title="<?= public_h($title) ?>"
                data-subtitle="<?= public_h($subtitle) ?>"
                data-guest="<?= public_h($guest) ?>">
                <span class="public-gallery-photo-image">
                  <img src="/<?= public_h($thumbPath) ?>" alt="Approved photo from <?= public_h($title) ?>">
                </span>
                <span class="public-gallery-photo-meta">
                  <strong><?= public_h($title) ?></strong>
                  <?php if ($subtitle): ?><em><?= public_h($subtitle) ?></em><?php endif; ?>
                  <?php if ($guest): ?><small>Shared by <?= public_h($guest) ?></small><?php endif; ?>
                </span>
              </button>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="public-gallery-empty-state">
            <span class="public-placeholder-icon">📸</span>
            <h2><?= $hasFilters ? 'No photos match those filters yet' : 'No public photos yet' ?></h2>
            <p>Photos uploaded at events will appear here once they have been moderated and approved.</p>
            <?php if ($hasFilters): ?>
              <a class="public-neon-btn" href="/gallery.php">View all photos</a>
            <?php elseif ($connectedEvent): ?>
              <a class="public-neon-btn" href="/upload.php">Upload Photos</a>
            <?php else: ?>
              <a class="public-neon-btn subtle" href="/events.php">View Events</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </article>
    </section>

    <div class="public-gallery-lightbox" id="publicGalleryLightbox" aria-hidden="true">
      <div class="public-gallery-lightbox-backdrop" data-gallery-close></div>
      <div class="public-gallery-lightbox-dialog" role="dialog" aria-modal="true" aria-label="Photo viewer">
        <button class="public-gallery-lightbox-close" type="button" data-gallery-close aria-label="Close photo viewer">×</button>
        <button class="public-gallery-lightbox-nav is-prev" type="button" data-gallery-prev aria-label="Previous photo">‹</button>
        <figure>
          <img id="publicGalleryLightboxImage" src="" alt="Selected event photo">
          <figcaption>
            <strong id="publicGalleryLightboxTitle"></strong>
            <span id="publicGalleryLightboxSubtitle"></span>
            <em id="publicGalleryLightboxGuest"></em>
          </figcaption>
        </figure>
        <button class="public-gallery-lightbox-nav is-next" type="button" data-gallery-next aria-label="Next photo">›</button>
      </div>
    </div>

    <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>

  <script>
    (function(){
      const cards = Array.from(document.querySelectorAll('[data-gallery-index]'));
      const modal = document.getElementById('publicGalleryLightbox');
      if (!cards.length || !modal) return;

      const img = document.getElementById('publicGalleryLightboxImage');
      const title = document.getElementById('publicGalleryLightboxTitle');
      const subtitle = document.getElementById('publicGalleryLightboxSubtitle');
      const guest = document.getElementById('publicGalleryLightboxGuest');
      let current = 0;

      function show(index){
        current = (index + cards.length) % cards.length;
        const card = cards[current];
        img.src = card.dataset.fullSrc || '';
        title.textContent = card.dataset.title || '';
        subtitle.textContent = card.dataset.subtitle || '';
        guest.textContent = card.dataset.guest ? 'Shared by ' + card.dataset.guest : '';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('public-lightbox-open');
      }

      function close(){
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('public-lightbox-open');
        img.src = '';
      }

      cards.forEach((card, index) => card.addEventListener('click', () => show(index)));
      modal.querySelectorAll('[data-gallery-close]').forEach(button => button.addEventListener('click', close));
      modal.querySelector('[data-gallery-prev]')?.addEventListener('click', () => show(current - 1));
      modal.querySelector('[data-gallery-next]')?.addEventListener('click', () => show(current + 1));

      document.addEventListener('keydown', function(event){
        if (!modal.classList.contains('is-open')) return;
        if (event.key === 'Escape') close();
        if (event.key === 'ArrowLeft') show(current - 1);
        if (event.key === 'ArrowRight') show(current + 1);
      });
    })();
  </script>
</body>
</html>
