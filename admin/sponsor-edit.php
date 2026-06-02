<?php
require_once __DIR__ . '/_auth.php';

function sponsors_table_exists() {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = db()->query("SHOW TABLES LIKE 'sponsors'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function sponsor_column_exists($column) {
    static $cache = [];

    if (isset($cache[$column])) {
        return $cache[$column];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM sponsors LIKE ?");
        $stmt->execute([$column]);
        $cache[$column] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

$error = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $id > 0;

$sponsor = [
    'id' => 0,
    'sponsor_name' => '',
    'category' => '',
    'contact_name' => '',
    'phone' => '',
    'email' => '',
    'website_url' => '',
    'logo_url' => '',
    'logo_background' => 'dark',
    'default_offer' => '',
    'notes' => '',
    'sort_order' => 100,
    'is_active' => 1,
];

if (!sponsors_table_exists()) {
    $error = 'The sponsors table does not exist yet. Run the SQL supplied with this patch before adding sponsors.';
} elseif ($is_edit) {
    $stmt = db()->prepare("SELECT * FROM sponsors WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $loaded = $stmt->fetch();

    if ($loaded) {
        $sponsor = array_merge($sponsor, $loaded);
    } else {
        $error = 'Sponsor not found.';
        $is_edit = false;
        $id = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && sponsors_table_exists()) {
    $sponsor_id = (int)($_POST['sponsor_id'] ?? 0);

    $data = [
        'sponsor_name' => trim((string)($_POST['sponsor_name'] ?? '')),
        'category' => trim((string)($_POST['category'] ?? '')),
        'contact_name' => trim((string)($_POST['contact_name'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'website_url' => trim((string)($_POST['website_url'] ?? '')),
        'logo_url' => trim((string)($_POST['logo_url'] ?? '')),
        'logo_background' => in_array(($_POST['logo_background'] ?? 'dark'), ['dark', 'light'], true) ? $_POST['logo_background'] : 'dark',
        'default_offer' => trim((string)($_POST['default_offer'] ?? '')),
        'notes' => trim((string)($_POST['notes'] ?? '')),
        'sort_order' => (int)($_POST['sort_order'] ?? 100),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($data['sponsor_name'] === '') {
        $error = 'Sponsor name is required.';
        $sponsor = array_merge($sponsor, $data);
    } else {
        $data = array_filter(
            $data,
            fn($value, $column) => sponsor_column_exists($column),
            ARRAY_FILTER_USE_BOTH
        );

        if ($sponsor_id > 0) {
            $sets = [];
            $params = [];

            foreach ($data as $column => $value) {
                $sets[] = "{$column} = ?";
                $params[] = $value;
            }

            if ($sets) {
                $params[] = $sponsor_id;
                $stmt = db()->prepare("UPDATE sponsors SET " . implode(', ', $sets) . " WHERE id = ?");
                $stmt->execute($params);
            }
        } else {
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');

            $stmt = db()->prepare(
                "INSERT INTO sponsors (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
            );
            $stmt->execute(array_values($data));
        }

        header('Location: sponsors.php');
        exit;
    }
}

admin_header(($is_edit ? 'Edit Sponsor' : 'Add Sponsor') . ' - DJ Portal');
?>

<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title"><?= $is_edit ? 'Edit Sponsor' : 'Add Sponsor' ?></h1>
        <p class="touch-subtitle">Maintain reusable sponsors. Event-specific prizes are handled separately next.</p>
      </div>
      <div>
        <a class="touch-btn" href="sponsors.php">Back to Sponsors</a>
      </div>
    </div>

    <form method="post" class="venue-edit-form">
      <input type="hidden" name="sponsor_id" value="<?= (int)$sponsor['id'] ?>">

      <?php if ($error): ?>
        <div class="settings-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      <section class="settings-card venue-social-card venue-edit-simple-card">
        <div class="settings-grid">
          <label>
            <span>Sponsor name *</span>
            <input name="sponsor_name" value="<?= h($sponsor['sponsor_name']) ?>" required>
          </label>

          <label>
            <span>Category</span>
            <input name="category" value="<?= h($sponsor['category']) ?>" placeholder="e.g. Holiday prize, local business, raffle prize">
          </label>

          <label>
            <span>Contact name</span>
            <input name="contact_name" value="<?= h($sponsor['contact_name']) ?>">
          </label>

          <label>
            <span>Phone</span>
            <input name="phone" value="<?= h($sponsor['phone']) ?>">
          </label>

          <label>
            <span>Email</span>
            <input type="email" name="email" value="<?= h($sponsor['email']) ?>">
          </label>

          <label>
            <span>Website URL</span>
            <input type="url" name="website_url" value="<?= h($sponsor['website_url']) ?>" placeholder="https://...">
          </label>

          <label>
            <span>Logo/image URL</span>
            <input type="url" name="logo_url" value="<?= h($sponsor['logo_url']) ?>" placeholder="https://... or /assets/...">
          </label>

          <label>
            <span>Logo background</span>
            <select name="logo_background">
              <option value="dark" <?= (($sponsor['logo_background'] ?? 'dark') === 'dark') ? 'selected' : '' ?>>Dark / transparent</option>
              <option value="light" <?= (($sponsor['logo_background'] ?? 'dark') === 'light') ? 'selected' : '' ?>>Light panel</option>
            </select>
            <small>Use Light panel for transparent logos with dark text.</small>
          </label>

          <label>
            <span>Sort order</span>
            <input type="number" name="sort_order" value="<?= h((string)$sponsor['sort_order']) ?>">
          </label>

          <label class="venue-address-wide">
            <span>Default offer / sponsorship</span>
            <textarea name="default_offer" rows="3" placeholder="e.g. Win a weekend away for two..."><?= h($sponsor['default_offer']) ?></textarea>
          </label>

          <label class="venue-address-wide">
            <span>Notes</span>
            <textarea name="notes" rows="4" placeholder="Internal notes, terms, contact arrangements..."><?= h($sponsor['notes']) ?></textarea>
          </label>

          <label>
            <span>Display status</span>
            <label style="display:flex;align-items:center;gap:10px;margin-top:10px;">
              <input type="checkbox" name="is_active" value="1" <?= ((int)$sponsor['is_active'] === 1) ? 'checked' : '' ?> style="width:auto;">
              <span>Active</span>
            </label>
          </label>
        </div>
      </section>

      <div class="form-actions">
        <a class="touch-btn" href="sponsors.php">Cancel</a>
        <button class="touch-btn blue" type="submit" <?= sponsors_table_exists() ? '' : 'disabled' ?>><?= $is_edit ? 'Save Sponsor' : 'Add Sponsor' ?></button>
      </div>
    </form>
  </section>
</main>

<?php admin_footer(); ?>
