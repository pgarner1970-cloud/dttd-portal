<?php
require_once __DIR__ . '/_auth.php';

admin_header('Event Sponsors - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Event Sponsors</h1>
        <p class="touch-subtitle">Assign sponsors, prizes and promotional wording to individual events.</p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn ghost" href="tools.php">← Admin Tools</a>
      </div>
    </div>

    <div class="settings-card">
      <div class="empty-state">
        Event sponsor assignments are reserved for the next database stage. This will link sponsors to specific events without cluttering the live DJ operation screens.
      </div>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
