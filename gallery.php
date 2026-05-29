<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/photo-uploads.php';

$public_current = 'gallery';
$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';

$filters = [
    'event_id' => (int)($_GET['event_id'] ?? 0),
    'venue' => trim((string)($_GET['venue'] ?? '')),
    'date' => trim((string)($_GET['date'] ?? '')),
    'search' => trim((string)($_GET['search'] ?? '')),
];

$photoColumns = [
    'original' => photo_column_exists('event_photo_uploads', 'original_path'),
    'framed' => photo_column_exists('event_photo_uploads', 'framed_path'),
    'thumb' => photo_column_exists('event_photo_uploads', 'thumb_path'),
];

$selectPieces = [
    'p.*',
    'e.event_name',
    'e.venue_name',
    'e.event_date',
];

if (!$photoColumns['original']) {
    $selectPieces[] = "'' AS original_path";
}
if (!$photoColumns['framed']) {
    $selectPieces[] = "'' AS framed_path";
}
if (!$photoColumns['thumb']) {
    $selectPieces[] = "'' AS thumb_path";
}

$sql = 'SELECT ' . implode(', ', $selectPieces) . ' FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE p.status = ?';
$params = ['approved'];

if ($filters['event_id'] > 0) {
    $sql .= ' AND p.event_id = ?';
    $params[] = $filters['event_id'];
}
if ($filters['venue'] !== '') {
    $sql .= ' AND e.venue_name = ?';
    $params[] = $filters['venue'];
}
if ($filters['date'] !== '') {
    $sql .= ' AND DATE_FORMAT(e.event_date, "%Y-%m") = ?';
    $params[] = $filters['date'];
}
if ($filters['search'] !== '') {
    $sql .= ' AND (e.event_name LIKE ? OR e.venue_name LIKE ? OR p.guest_name LIKE ?)';
    $like = '%' . $filters['search'] . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY e.event_date DESC, p.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

$eventOptions = db()->query("\n    SELECT DISTINCT e.id, e.event_name, e.venue_name, e.event_date\n    FROM event_photo_uploads p\n    INNER JOIN events e ON e.id = p.event_id\n    WHERE p.status = 'approved'\n    ORDER BY e.event_date DESC, e.id DESC\n")->fetchAll();

$venueOptions = db()->query("\n    SELECT DISTINCT e.venue_name\n    FROM event_photo_uploads p\n    INNER JOIN events e ON e.id = p.event_id\n    WHERE p.status = 'approved'\n      AND e.venue_name <> ''\n    ORDER BY e.venue_name ASC\n")->fetchAll();

$dateOptions = db()->query("\n    SELECT DISTINCT DATE_FORMAT(e.event_date, '%Y-%m') AS month_key, DATE_FORMAT(e.event_date, '%b %Y') AS month_label\n    FROM event_photo_uploads p\n    INNER JOIN events e ON e.id = p.event_id\n    WHERE p.status = 'approved'\n      AND e.event_date IS NOT NULL\n    ORDER BY month_key DESC\n")->fetchAll();

$sharePhotoId = max(0, (int)($_GET['photo'] ?? 0));
$sharePhoto = null;
if ($sharePhotoId > 0) {
    foreach ($photos as $candidatePhoto) {
        if ((int)($candidatePhoto['id'] ?? 0) === $sharePhotoId) {
            $sharePhoto = $candidatePhoto;
            break;
        }
    }

    if (!$sharePhoto) {
        $sharePieces = $selectPieces;
        $shareSql = 'SELECT ' . implode(', ', $sharePieces) . ' FROM event_photo_uploads p INNER JOIN events e ON e.id = p.event_id WHERE p.status = ? AND p.id = ? LIMIT 1';
        $shareStmt = db()->prepare($shareSql);
        $shareStmt->execute(['approved', $sharePhotoId]);
        $sharePhoto = $shareStmt->fetch() ?: null;
    }
}

function gallery_absolute_url($path) {
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'dancethruthedecades.co.uk';
    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function gallery_photo_share_url($photoId) {
    return gallery_absolute_url('/gallery.php?photo=' . (int)$photoId);
}

function gallery_photo_caption($photo) {
    $dateLabel = '';
    if (!empty($photo['event_date'])) {
        try {
            $dateLabel = (new DateTime($photo['event_date']))->format('D j M Y');
        } catch (Throwable $e) {
            $dateLabel = (string)$photo['event_date'];
        }
    }
    return trim(implode(' · ', array_filter([$photo['venue_name'] ?? '', $dateLabel])));
}

function gallery_photo_share_text($photo) {
    $title = trim((string)($photo['event_name'] ?? 'Dance Thru The Decades photo'));
    $caption = gallery_photo_caption($photo);
    return trim($title . ($caption !== '' ? "\n" . $caption : '') . "\nDance Thru The Decades");
}

$metaTitle = 'Gallery | Dance Thru The Decades';
$metaDescription = 'Browse approved photos from Dance Thru The Decades events.';
$metaImage = gallery_absolute_url('/assets/dttd-logo-inner.png?v=152');
$metaUrl = gallery_absolute_url('/gallery.php');

if ($sharePhoto) {
    $sharePaths = photo_row_display_paths($sharePhoto);
    $shareDisplayUrl = photo_public_url($sharePaths['display']);
    $shareTitle = trim((string)($sharePhoto['event_name'] ?? 'Dance Thru The Decades photo'));
    $shareCaption = gallery_photo_caption($sharePhoto);

    $metaTitle = ($shareTitle !== '' ? $shareTitle : 'Dance Thru The Decades photo') . ' | Dance Thru The Decades';
    $metaDescription = trim(($shareCaption !== '' ? $shareCaption . ' · ' : '') . 'Shared from Dance Thru The Decades.');
    if ($shareDisplayUrl !== '') {
        $metaImage = gallery_absolute_url($shareDisplayUrl);
    }
    $metaUrl = gallery_photo_share_url($sharePhotoId);
}

$currentEvent = null;
try {
    $currentEvent = active_event();
} catch (Throwable $e) {
    $currentEvent = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= photo_h($metaTitle) ?></title>
  <meta name="description" content="<?= photo_h($metaDescription) ?>">
  <meta property="og:title" content="<?= photo_h($metaTitle) ?>">
  <meta property="og:description" content="<?= photo_h($metaDescription) ?>">
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= photo_h($metaUrl) ?>">
  <meta property="og:image" content="<?= photo_h($metaImage) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= photo_h($metaTitle) ?>">
  <meta name="twitter:description" content="<?= photo_h($metaDescription) ?>">
  <meta name="twitter:image" content="<?= photo_h($metaImage) ?>">
  <link rel="canonical" href="<?= photo_h($metaUrl) ?>">
  <link rel="stylesheet" href="/assets/public-site.css?v=304">
</head>
<body class="homepage-option-one public-gallery-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <section class="public-gallery-shell">
      <article class="public-filter-card">
        <div class="public-gallery-filter-head">
          <div>
            <p class="option-one-eyebrow">Browse Gallery</p>
            <h1 class="public-gallery-title">Find photos</h1>
          </div>
          <a class="public-neon-btn public-gallery-upload-btn" href="/upload.php">Upload Photos</a>
        </div>
        <form class="public-filter-grid" method="get">
          <label>
            <span>Event</span>
            <select name="event_id">
              <option value="0">All events</option>
              <?php foreach ($eventOptions as $event): ?>
                <option value="<?= (int)$event['id'] ?>" <?= $filters['event_id'] === (int)$event['id'] ? 'selected' : '' ?>><?= photo_h(photo_event_label($event)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Venue</span>
            <select name="venue">
              <option value="">All venues</option>
              <?php foreach ($venueOptions as $venue): ?>
                <option value="<?= photo_h($venue['venue_name']) ?>" <?= $filters['venue'] === $venue['venue_name'] ? 'selected' : '' ?>><?= photo_h($venue['venue_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Date</span>
            <select name="date">
              <option value="">All dates</option>
              <?php foreach ($dateOptions as $date): ?>
                <option value="<?= photo_h($date['month_key']) ?>" <?= $filters['date'] === $date['month_key'] ? 'selected' : '' ?>><?= photo_h($date['month_label']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            <span>Search</span>
            <input type="search" name="search" value="<?= photo_h($filters['search']) ?>" placeholder="Event or venue">
          </label>

          <div class="public-filter-actions">
            <button class="public-neon-btn" type="submit">Filter Photos</button>
          </div>
        </form>
      </article>

      <article class="public-photo-grid-card">
        <div class="public-grid-heading-row">
          <div>
            <p class="option-one-eyebrow">Approved Photos</p>
            <h2>Latest photos</h2>
          </div>
          <span class="public-photo-count"><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></span>
        </div>

        <?php if (!$photos): ?>
          <div class="public-empty-card">
            <h3>No approved photos yet</h3>
            <p>Photos uploaded from events will appear here once they have been approved.</p>
            <a class="public-neon-btn" href="/upload.php">Upload a Photo</a>
          </div>
        <?php else: ?>
          <div class="public-photo-grid">
            <?php foreach ($photos as $index => $photo):
              $paths = photo_row_display_paths($photo);
              $displayUrl = photo_public_url($paths['display']);
              $thumbUrl = photo_public_url($paths['thumb']);
              $dateLabel = '';
              if (!empty($photo['event_date'])) {
                try {
                  $dateLabel = (new DateTime($photo['event_date']))->format('D j M Y');
                } catch (Throwable $e) {
                  $dateLabel = (string)$photo['event_date'];
                }
              }
              $caption = trim(implode(' · ', array_filter([$photo['venue_name'] ?? '', $dateLabel])));
              $credit = trim((string)($photo['guest_name'] ?? ''));
            ?>
              <?php
                $shareUrl = gallery_photo_share_url((int)($photo['id'] ?? 0));
                $shareText = gallery_photo_share_text($photo);
              ?>
              <article class="public-photo-tile" data-lightbox-item="<?= (int)$index ?>" data-lightbox-image="<?= photo_h($displayUrl) ?>" data-lightbox-title="<?= photo_h((string)($photo['event_name'] ?? 'Event photo')) ?>" data-lightbox-meta="<?= photo_h($caption) ?>" data-lightbox-share-url="<?= photo_h($shareUrl) ?>" data-lightbox-share-text="<?= photo_h($shareText) ?>" data-lightbox-id="<?= (int)($photo['id'] ?? 0) ?>">
                <button class="public-photo-thumb" type="button">
                  <img src="<?= photo_h($displayUrl) ?>" alt="<?= photo_h((string)($photo['event_name'] ?? 'Event photo')) ?>">
                </button>
                <div class="public-photo-meta">
                  <h3><?= photo_h((string)($photo['event_name'] ?? 'Event')) ?></h3>
                  <p><?= photo_h($caption) ?></p>
                  <?php if ($credit !== ''): ?>
                    <strong>Shared by <?= photo_h($credit) ?></strong>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    </section>

    <div class="public-lightbox" id="publicPhotoLightbox" hidden>
      <button class="public-lightbox-close" type="button" aria-label="Close photo viewer">×</button>
      <button class="public-lightbox-nav prev" type="button" aria-label="Previous photo">‹</button>
      <figure>
        <img id="publicLightboxImage" src="" alt="">
        <figcaption>
          <strong id="publicLightboxTitle"></strong>
          <span id="publicLightboxMeta"></span>
          <div class="public-lightbox-actions">
            <button class="public-lightbox-share" type="button">Share photo</button>
            <button class="public-lightbox-copy" type="button">Copy link</button>
            <a class="public-lightbox-download" href="#" download>Download image</a>
          </div>
          <small id="publicLightboxNotice" class="public-lightbox-notice" aria-live="polite"></small>
        </figcaption>
      </figure>
      <button class="public-lightbox-nav next" type="button" aria-label="Next photo">›</button>
    </div>

    <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>

  <script>
  (function(){
    const items = Array.from(document.querySelectorAll('[data-lightbox-item]'));
    const lightbox = document.getElementById('publicPhotoLightbox');
    if (!lightbox || !items.length) return;

    const image = document.getElementById('publicLightboxImage');
    const title = document.getElementById('publicLightboxTitle');
    const meta = document.getElementById('publicLightboxMeta');
    const notice = document.getElementById('publicLightboxNotice');
    const closeBtn = lightbox.querySelector('.public-lightbox-close');
    const prevBtn = lightbox.querySelector('.public-lightbox-nav.prev');
    const nextBtn = lightbox.querySelector('.public-lightbox-nav.next');
    const shareBtn = lightbox.querySelector('.public-lightbox-share');
    const copyBtn = lightbox.querySelector('.public-lightbox-copy');
    const downloadLink = lightbox.querySelector('.public-lightbox-download');
    let index = 0;
    let currentShareUrl = '';
    let currentShareText = '';

    function absoluteUrl(url){
      try {
        return new URL(url, window.location.origin).toString();
      } catch (e) {
        return window.location.href;
      }
    }

    function showNotice(message){
      if (!notice) return;
      notice.textContent = message || '';
      if (message) {
        window.clearTimeout(showNotice.timer);
        showNotice.timer = window.setTimeout(() => { notice.textContent = ''; }, 2400);
      }
    }

    async function copyShareLink(){
      if (!currentShareUrl) return;
      try {
        await navigator.clipboard.writeText(currentShareUrl);
        showNotice('Link copied');
      } catch (e) {
        window.prompt('Copy this link', currentShareUrl);
      }
    }

    function render(i, updateUrl){
      const item = items[i];
      if (!item) return;
      index = i;
      currentShareUrl = item.dataset.lightboxShareUrl || window.location.href;
      currentShareText = item.dataset.lightboxShareText || item.dataset.lightboxTitle || 'Dance Thru The Decades photo';

      image.src = item.dataset.lightboxImage || '';
      image.alt = item.dataset.lightboxTitle || 'Event photo';
      title.textContent = item.dataset.lightboxTitle || '';
      meta.textContent = item.dataset.lightboxMeta || '';

      if (downloadLink) {
        downloadLink.href = item.dataset.lightboxImage || '#';
      }

      if (updateUrl !== false && currentShareUrl) {
        try {
          const shareLocation = new URL(currentShareUrl);
          window.history.replaceState(null, '', shareLocation.pathname + shareLocation.search);
        } catch (e) {}
      }

      lightbox.hidden = false;
      document.body.classList.add('lightbox-open');
      showNotice('');
    }

    items.forEach((item, i) => {
      item.addEventListener('click', () => render(i, true));
    });

    closeBtn.addEventListener('click', () => {
      lightbox.hidden = true;
      document.body.classList.remove('lightbox-open');
    });

    prevBtn.addEventListener('click', () => render((index - 1 + items.length) % items.length, true));
    nextBtn.addEventListener('click', () => render((index + 1) % items.length, true));

    if (shareBtn) {
      shareBtn.addEventListener('click', async () => {
        const shareData = {
          title: title.textContent || 'Dance Thru The Decades photo',
          text: currentShareText,
          url: currentShareUrl
        };

        if (navigator.share) {
          try {
            await navigator.share(shareData);
            return;
          } catch (e) {
            if (e && e.name === 'AbortError') return;
          }
        }

        copyShareLink();
      });
    }

    if (copyBtn) {
      copyBtn.addEventListener('click', copyShareLink);
    }

    lightbox.addEventListener('click', (e) => { if (e.target === lightbox) closeBtn.click(); });

    const initialPhotoId = new URLSearchParams(window.location.search).get('photo');
    if (initialPhotoId) {
      const initialIndex = items.findIndex((item) => item.dataset.lightboxId === initialPhotoId);
      if (initialIndex >= 0) {
        render(initialIndex, false);
      }
    }
  })();
  </script>
</body>
</html>
