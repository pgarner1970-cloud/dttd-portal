<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/upload-paths.php';

admin_header('Repair Upload Paths');

$siteRoot = dttd_site_root();
$badDirs = [
    dirname($siteRoot) . DIRECTORY_SEPARATOR . 'dttd-portalhttps:',
    $siteRoot . 'https:',
    $siteRoot . DIRECTORY_SEPARATOR . 'https:',
];

$moved = [];
$failed = [];

if (($_POST['repair'] ?? '') === 'yes') {
    foreach ($badDirs as $badDir) {
        if (!is_dir($badDir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($badDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $name = $file->getFilename();
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                continue;
            }

            $target = dttd_event_upload_dir() . DIRECTORY_SEPARATOR . $name;

            if (!file_exists($target)) {
                if (@rename($file->getPathname(), $target)) {
                    chmod($target, 0644);
                    $moved[] = $name;
                } else {
                    $failed[] = $file->getPathname();
                }
            }
        }
    }
}
?>

<main class="touch-wrap">
  <section class="touch-panel">
    <h1>Repair Upload Paths</h1>
    <p>This moves images accidentally saved into a bad <code>https:</code> folder back into <code>uploads/events</code>.</p>

    <?php if ($moved): ?>
      <div class="flash success">
        <strong>Moved <?= count($moved) ?> file(s).</strong>
        <ul>
          <?php foreach ($moved as $file): ?>
            <li><?= h($file) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php elseif (($_POST['repair'] ?? '') === 'yes'): ?>
      <div class="flash">No files needed moving, or no bad folder was found.</div>
    <?php endif; ?>

    <?php if ($failed): ?>
      <div class="flash error">
        <strong>Could not move:</strong>
        <ul>
          <?php foreach ($failed as $file): ?>
            <li><?= h($file) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="repair" value="yes">
      <button class="btn primary" type="submit">Run Repair</button>
      <a class="btn" href="events.php">Back to Events</a>
    </form>
  </section>
</main>

<?php admin_footer(); ?>
