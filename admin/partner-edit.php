<?php
require_once __DIR__ . '/_auth.php';

function partners_table_exists() {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = db()->query("SHOW TABLES LIKE 'partners'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function partner_column_exists($column) {
    static $cache = [];

    if (isset($cache[$column])) {
        return $cache[$column];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM partners LIKE ?");
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

$partner = [
    'id' => 0,
    'partner_name' => '',
    'category' => '',
    'contact_name' => '',
    'phone' => '',
    'email' => '',
    'website_url' => '',
    'image_url' => '',
    'logo_background' => 'dark',
    'notes' => '',
    'sort_order' => 100,
    'is_active' => 1,
];

if (!partners_table_exists()) {
    $error = 'The partners table does not exist yet. Run the SQL supplied with this patch before adding partners.';
} elseif ($is_edit) {
    $stmt = db()->prepare("SELECT * FROM partners WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $loaded = $stmt->fetch();

    if ($loaded) {
        $partner = array_merge($partner, $loaded);
    } else {
        $error = 'Partner not found.';
        $is_edit = false;
        $id = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && partners_table_exists()) {
    $partner_id = (int)($_POST['partner_id'] ?? 0);

    $data = [
        'partner_name' => trim((string)($_POST['partner_name'] ?? '')),
        'category' => trim((string)($_POST['category'] ?? '')),
        'contact_name' => trim((string)($_POST['contact_name'] ?? '')),
        'phone' => trim((string)($_POST['phone'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'website_url' => trim((string)($_POST['website_url'] ?? '')),
        'image_url' => trim((string)($_POST['image_url'] ?? '')),
        'logo_background' => in_array(($_POST['logo_background'] ?? 'dark'), ['dark', 'light', 'neon_pink', 'starburst', 'radial_neon'], true) ? $_POST['logo_background'] : 'dark',
        'notes' => trim((string)($_POST['notes'] ?? '')),
        'sort_order' => (int)($_POST['sort_order'] ?? 100),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($data['partner_name'] === '') {
        $error = 'Partner name is required.';
        $partner = array_merge($partner, $data);
    } else {
        $data = array_filter(
            $data,
            fn($value, $column) => partner_column_exists($column),
            ARRAY_FILTER_USE_BOTH
        );

        if ($partner_id > 0) {
            $sets = [];
            $params = [];

            foreach ($data as $column => $value) {
                $sets[] = "{$column} = ?";
                $params[] = $value;
            }

            if ($sets) {
                $params[] = $partner_id;
                $stmt = db()->prepare("UPDATE partners SET " . implode(', ', $sets) . " WHERE id = ?");
                $stmt->execute($params);
            }
        } else {
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');

            $stmt = db()->prepare(
                "INSERT INTO partners (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")"
            );
            $stmt->execute(array_values($data));
        }

        header('Location: partners.php');
        exit;
    }
}

$logo_background = (string)($partner['logo_background'] ?? 'dark');
if (!in_array($logo_background, ['dark', 'light', 'neon_pink', 'starburst', 'radial_neon'], true)) {
    $logo_background = 'dark';
}

admin_header(($is_edit ? 'Edit Partner' : 'Add Partner') . ' - DJ Portal');
?>

<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title"><?= $is_edit ? 'Edit Partner' : 'Add Partner' ?></h1>
        <p class="touch-subtitle">Maintain suppliers and trusted contacts used behind the scenes.</p>
      </div>
      <div>
        <a class="touch-btn" href="partners.php">Back to Partners</a>
      </div>
    </div>

    <form method="post" class="venue-edit-form">
      <input type="hidden" name="partner_id" value="<?= (int)$partner['id'] ?>">

      <?php if ($error): ?>
        <div class="settings-alert error"><?= h($error) ?></div>
      <?php endif; ?>

      <section class="settings-card venue-social-card venue-edit-simple-card">
        <div class="settings-grid">
          <label>
            <span>Partner name *</span>
            <input name="partner_name" value="<?= h($partner['partner_name']) ?>" required>
          </label>

          <label>
            <span>Category</span>
            <input name="category" value="<?= h($partner['category']) ?>" placeholder="e.g. DJ supplies, banner printer">
          </label>

          <label>
            <span>Contact name</span>
            <input name="contact_name" value="<?= h($partner['contact_name']) ?>">
          </label>

          <label>
            <span>Phone</span>
            <input name="phone" value="<?= h($partner['phone']) ?>">
          </label>

          <label>
            <span>Email</span>
            <input type="email" name="email" value="<?= h($partner['email']) ?>">
          </label>

          <label>
            <span>Website URL</span>
            <input type="url" name="website_url" value="<?= h($partner['website_url']) ?>" placeholder="https://...">
          </label>

          <label>
            <span>Image/logo URL</span>
            <input id="partner-image-url" type="url" name="image_url" value="<?= h($partner['image_url']) ?>" placeholder="https://... or /assets/...">
          </label>

          <label>
            <span>Logo background</span>
            <select name="logo_background" id="partner-logo-background">
              <option value="dark" <?= $logo_background === 'dark' ? 'selected' : '' ?>>Dark / transparent</option>
              <option value="light" <?= $logo_background === 'light' ? 'selected' : '' ?>>Light panel</option>
              <option value="neon_pink" <?= $logo_background === 'neon_pink' ? 'selected' : '' ?>>Neon pink glow</option>
              <option value="starburst" <?= $logo_background === 'starburst' ? 'selected' : '' ?>>Soft starburst</option>
              <option value="radial_neon" <?= $logo_background === 'radial_neon' ? 'selected' : '' ?>>Radial neon glow</option>
            </select>
            <small>Use Light panel for transparent logos with dark text.</small>
          </label>

          <label>
            <span>Sort order</span>
            <input type="number" name="sort_order" value="<?= h((string)$partner['sort_order']) ?>">
          </label>

          <label class="venue-address-wide">
            <span>Notes</span>
            <textarea name="notes" rows="4" placeholder="What they provide, discount arrangements, useful details..."><?= h($partner['notes']) ?></textarea>
          </label>

          <label>
            <span>Display status</span>
            <label style="display:flex;align-items:center;gap:10px;margin-top:10px;">
              <input type="checkbox" name="is_active" value="1" <?= ((int)$partner['is_active'] === 1) ? 'checked' : '' ?> style="width:auto;">
              <span>Active</span>
            </label>
          </label>
        </div>
      </section>

      <div class="partner-logo-preview-wrap" aria-live="polite">
        <?php $previewLogoBg = preg_replace('/[^a-z0-9_]/', '', $logo_background); ?>
        <div class="partner-logo-preview is-<?= h($previewLogoBg) ?>" id="partner-logo-preview">
          <?php if (!empty($partner['image_url'])): ?>
            <img src="<?= h($partner['image_url']) ?>" alt="Partner logo preview">
          <?php else: ?>
            <span>Logo preview</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="form-actions">
        <a class="touch-btn" href="partners.php">Cancel</a>
        <button class="touch-btn blue" type="submit" <?= partners_table_exists() ? '' : 'disabled' ?>><?= $is_edit ? 'Save Partner' : 'Add Partner' ?></button>
      </div>
    </form>
  </section>
</main>

<style>
.partner-logo-preview-wrap {
  margin: 0 18px 18px;
}
.partner-logo-preview {
  min-height: 120px;
  border: 1px solid rgba(255,255,255,.16);
  border-radius: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 18px;
  background: rgba(255,255,255,.04);
  color: rgba(255,255,255,.65);
}
.partner-logo-preview.is-light {
  background:
    linear-gradient(
      135deg,
      rgba(236,230,255,0.96),
      rgba(220,235,255,0.92)
    );
  color: #111827;
  border-color: rgba(255,255,255,.22);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.22),
    0 10px 30px rgba(0,0,0,.28);
  backdrop-filter: blur(6px);
}

.partner-logo-preview.is-neon_pink {
  background:
    radial-gradient(circle at 50% 45%, rgba(255, 40, 190, .78), rgba(95, 20, 115, .78) 42%, rgba(23, 8, 45, .96) 100%);
  border-color: rgba(255, 70, 220, .42);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.12),
    0 0 28px rgba(255, 40, 190, .28);
}
.partner-logo-preview.is-starburst {
  background:
    radial-gradient(circle at 50% 50%, rgba(255, 75, 230, .72), rgba(105, 40, 150, .70) 28%, rgba(28, 10, 55, .96) 72%),
    conic-gradient(from 0deg at 50% 50%, rgba(255,255,255,.12), transparent 12%, rgba(255,255,255,.10) 18%, transparent 30%, rgba(255,255,255,.09) 36%, transparent 46%);
  border-color: rgba(205, 90, 255, .36);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.12),
    0 0 24px rgba(190, 65, 255, .22);
}
.partner-logo-preview.is-radial_neon {
  background:
    radial-gradient(circle at 50% 50%, rgba(255, 35, 175, .72), rgba(100, 20, 125, .72) 38%, rgba(20, 8, 44, .98) 100%);
  border-color: rgba(230, 65, 210, .32);
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.10),
    0 0 22px rgba(255, 40, 190, .20);
}

.partner-logo-preview img {
  max-width: min(420px, 90%);
  max-height: 110px;
  object-fit: contain;
}
</style>
<script>
(function () {
  const urlInput = document.getElementById('partner-image-url');
  const bgSelect = document.getElementById('partner-logo-background');
  const preview = document.getElementById('partner-logo-preview');
  if (!urlInput || !bgSelect || !preview) return;

  function updatePreview() {
    preview.classList.remove('is-dark', 'is-light', 'is-neon_pink', 'is-starburst', 'is-radial_neon');
    preview.classList.add('is-' + (bgSelect.value || 'dark'));
    const value = urlInput.value.trim();
    preview.innerHTML = value ? '<img src="' + value.replace(/"/g, '&quot;') + '" alt="Partner logo preview">' : '<span>Logo preview</span>';
  }

  urlInput.addEventListener('input', updatePreview);
  bgSelect.addEventListener('change', updatePreview);
})();
</script>
<?php admin_footer(); ?>
