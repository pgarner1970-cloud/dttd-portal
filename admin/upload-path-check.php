<?php
require_once __DIR__ . '/_auth.php';

admin_header('Upload Path Check');

$uploadDir = dirname(__DIR__) . '/uploads/events';
$badDir = dirname(dirname(__DIR__)) . '/dttd-portalhttps:';
?>

<main class="touch-wrap">
  <section class="touch-panel">
    <h1>Upload Path Check</h1>

    <p><strong>Correct upload directory:</strong></p>
    <pre><?= h($uploadDir) ?></pre>

    <p><strong>Correct directory exists?</strong> <?= is_dir($uploadDir) ? 'Yes' : 'No' ?></p>
    <p><strong>Correct directory writable?</strong> <?= is_writable($uploadDir) ? 'Yes' : 'No' ?></p>

    <hr>

    <p><strong>Known bad directory:</strong></p>
    <pre><?= h($badDir) ?></pre>

    <p><strong>Bad directory exists?</strong> <?= is_dir($badDir) ? 'Yes' : 'No' ?></p>

    <p>
      <a class="btn" href="/events">Back to Events</a>
    </p>
  </section>
</main>

<?php admin_footer(); ?>
