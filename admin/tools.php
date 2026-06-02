<?php
require_once __DIR__ . '/_auth.php';

admin_header('Admin Tools - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Admin Tools</h1>
        <p class="touch-subtitle">Behind-the-scenes setup and maintenance tools. The live DJ header stays focused on mixer, requests and photos.</p>
      </div>
    </div>

    <div class="admin-home-grid">
      <a class="admin-home-card" href="events.php">
        <span class="admin-home-icon">▦</span>
        <strong>Events</strong>
        <span>Create, edit and review event setup</span>
      </a>

      <a class="admin-home-card" href="venues.php">
        <span class="admin-home-icon">⌂</span>
        <strong>Venues</strong>
        <span>Maintain venue details and defaults</span>
      </a>

      <a class="admin-home-card" href="event-photos.php">
        <span class="admin-home-icon">◉</span>
        <strong>Photo moderation</strong>
        <span>Review guest uploads and gallery items</span>
      </a>

      <a class="admin-home-card" href="settings.php">
        <span class="admin-home-icon">⚙</span>
        <strong>Settings</strong>
        <span>Portal, request and Spotify settings</span>
      </a>

      <a class="admin-home-card" href="request-debug.php">
        <span class="admin-home-icon">◎</span>
        <strong>Queue Debug</strong>
        <span>Diagnose request update polling</span>
      </a>

      <a class="admin-home-card" href="events-diagnostic.php">
        <span class="admin-home-icon">!</span>
        <strong>Diagnostics</strong>
        <span>Check event and system diagnostics</span>
      </a>
    </div>
  </section>

  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h2 class="touch-panel-title">Planned maintenance areas</h2>
        <p class="touch-subtitle">Reserved for the next stage without adding more buttons to the live DJ header.</p>
      </div>
    </div>

    <div class="admin-home-grid">
      <div class="admin-home-card admin-home-card-muted">
        <span class="admin-home-icon">★</span>
        <strong>Partners</strong>
        <span>DJ suppliers, party suppliers, banner printer and reusable business contacts</span>
      </div>

      <div class="admin-home-card admin-home-card-muted">
        <span class="admin-home-icon">£</span>
        <strong>Sponsors</strong>
        <span>Reusable sponsors that can later be assigned to individual events</span>
      </div>

      <div class="admin-home-card admin-home-card-muted">
        <span class="admin-home-icon">⇄</span>
        <strong>Event sponsors</strong>
        <span>Per-event prizes, offers and sponsor display settings</span>
      </div>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
