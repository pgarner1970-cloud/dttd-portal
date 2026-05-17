<?php
require_once __DIR__ . '/_auth.php';
admin_header('DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">DJ Portal</h1>
        <p class="touch-subtitle">Choose what you want to manage.</p>
      </div>
    </div>

    <div class="admin-home-grid">
      <a class="admin-home-card" href="/admin/requests.php">
        <span class="admin-home-icon">♫</span>
        <strong>Requests</strong>
        <span>Live song request queue</span>
      </a>

      <a class="admin-home-card" href="/admin/events.php">
        <span class="admin-home-icon">▦</span>
        <strong>Events</strong>
        <span>Create, edit and choose the current event</span>
      </a>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
