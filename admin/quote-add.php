<?php
require_once __DIR__ . '/_auth.php';

function dttd_venues_table_exists() {
    try {
        $stmt = db()->query("SHOW TABLES LIKE 'venues'");
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dttd_get_venues_for_select() {
    if (!dttd_venues_table_exists()) { return []; }
    try {
        $stmt = db()->query("SELECT id, venue_name, venue_address, venue_postcode FROM venues ORDER BY venue_name ASC, id ASC");
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_quote_has_invoice($quoteId) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM invoices WHERE quotation_id = ?');
    $stmt->execute([(int)$quoteId]);
    return ((int)$stmt->fetchColumn()) > 0;
}

$venues_for_select = dttd_get_venues_for_select();
$quoteId = (int)($_GET['id'] ?? 0);
$isClone = !empty($_GET['clone']);
$isEdit = $quoteId > 0 && !$isClone;
$locked = false;

$defaults = [
    'customer_name' => '', 'customer_email' => '', 'customer_address' => '',
    'event_description' => '',
    'event_date' => '', 'event_start_time' => '', 'event_end_time' => '', 'venue_id' => '', 'venue' => '', 'notes' => '',
    'line_description' => ['Dance Thru The Decades DJ Entertainment Package', '', ''],
    'line_price' => ['350.00', '', ''],
    'deposit_percentage' => '20.00',
    'deposit_due_date' => date('Y-m-d', strtotime('+30 days')),
    'balance_due_date' => '',
];

if ($quoteId > 0) {
    $stmt = db()->prepare('SELECT * FROM quotations WHERE id = ?');
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch();
    if (!$quote) { http_response_code(404); echo 'Quotation not found'; exit; }
    $locked = dttd_quote_has_invoice($quoteId);
    if ($isEdit && $locked) {
        http_response_code(403);
        echo 'This quotation has already been converted to an invoice. Please clone it to make a revised quotation.';
        exit;
    }
    $items = json_decode($quote['items_json'] ?? '[]', true) ?: [];
    $lineDesc = [];
    $linePrice = [];
    foreach ($items as $item) {
        $lineDesc[] = (string)($item['description'] ?? '');
        $linePrice[] = (string)($item['price'] ?? '');
    }
    while (count($lineDesc) < 3) { $lineDesc[] = ''; $linePrice[] = ''; }
    $defaults = [
        'customer_name' => $quote['customer_name'] ?? '',
        'customer_email' => $quote['customer_email'] ?? '',
        'customer_address' => $quote['customer_address'] ?? '',
        'event_description' => $quote['event_description'] ?? '',
        'event_date' => $quote['event_date'] ?? '',
        'event_start_time' => isset($quote['event_start_time']) ? substr((string)$quote['event_start_time'], 0, 5) : '',
        'event_end_time' => isset($quote['event_end_time']) ? substr((string)$quote['event_end_time'], 0, 5) : '',
        'venue_id' => $quote['venue_id'] ?? '',
        'venue' => $quote['venue'] ?? '',
        'notes' => $quote['notes'] ?? '',
        'line_description' => $lineDesc,
        'line_price' => $linePrice,
        'deposit_percentage' => number_format((float)($quote['deposit_percentage'] ?? 20), 2, '.', ''),
        'deposit_due_date' => $quote['deposit_due_date'] ?? '',
        'balance_due_date' => $quote['balance_due_date'] ?? '',
    ];
}

$pageTitle = $isClone ? 'Clone Quotation' : ($isEdit ? 'Edit Quotation' : 'Add Quotation');
$submitLabel = $isClone ? 'Save Cloned Quotation' : ($isEdit ? 'Update Quotation' : 'Save Quotation');
$subtitle = $isClone ? 'Create a revised quotation without changing the original.' : ($isEdit ? 'Update this quotation before it is converted to an invoice.' : 'Preview test PDFs without saving, then save as a quotation when ready.');

admin_header($pageTitle . ' - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title"><?= h($pageTitle) ?></h1>
        <p class="touch-subtitle"><?= h($subtitle) ?></p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn ghost" href="quotes.php">Quotations</a>
        <a class="touch-btn ghost" href="invoices.php">Invoices</a>
      </div>
    </div>

    <div class="touch-panel-pad">
      <form class="settings-form event-form-shell" method="post" action="quote-save.php">
        <input type="hidden" name="quote_id" value="<?= $isEdit ? (int)$quoteId : 0 ?>">
        <input type="hidden" name="save_mode" value="<?= $isEdit ? 'update' : 'insert' ?>">

        <section class="form-section">
          <div class="form-section-header"><div class="form-section-icon">👤</div><div><h2 class="form-section-title">Customer Details</h2><p class="form-section-subtitle">Who the quotation is for.</p></div></div>
          <div class="form-section-body"><div class="form-grid">
            <div class="form-field span-6"><label for="customer_name">Customer name</label><input id="customer_name" type="text" name="customer_name" value="<?= h($defaults['customer_name']) ?>" required></div>
            <div class="form-field span-6"><label for="customer_email">Customer email</label><input id="customer_email" type="email" name="customer_email" value="<?= h($defaults['customer_email']) ?>"></div>
            <div class="form-field span-12"><label for="customer_address">Customer address</label><textarea id="customer_address" name="customer_address" rows="3"><?= h($defaults['customer_address']) ?></textarea></div>
          </div></div>
        </section>

        <section class="form-section">
          <div class="form-section-header"><div class="form-section-icon">📅</div><div><h2 class="form-section-title">Event Details</h2><p class="form-section-subtitle">The event, date and venue for the booking.</p></div></div>
          <div class="form-section-body"><div class="form-grid">
            <div class="form-field span-12"><label for="event_description">Event / quotation description</label><input id="event_description" type="text" name="event_description" value="<?= h($defaults['event_description']) ?>" placeholder="e.g. 70s / 80s / 90s party night, wedding reception, birthday party"></div>
            <div class="form-field span-4"><label for="event_date">Event date</label><input id="event_date" type="date" name="event_date" value="<?= h($defaults['event_date']) ?>"></div>
            <div class="form-field span-4"><label for="event_start_time">Start time</label><input id="event_start_time" type="time" name="event_start_time" value="<?= h($defaults['event_start_time']) ?>"></div>
            <div class="form-field span-4"><label for="event_end_time">End time</label><input id="event_end_time" type="time" name="event_end_time" value="<?= h($defaults['event_end_time']) ?>"></div>
            <div class="form-field span-12"><label for="venue_id">Select a venue</label><select id="venue_id" name="venue_id"><option value="">Manual venue entry</option><?php foreach ($venues_for_select as $venue): ?><?php $venueLabel=trim((string)($venue['venue_name']??'')); $venueParts=array_filter([trim((string)($venue['venue_address']??'')), trim((string)($venue['venue_postcode']??''))]); $venueDetails=implode(', ', $venueParts); ?><option value="<?= (int)$venue['id'] ?>" data-name="<?= h($venueLabel) ?>" data-details="<?= h($venueDetails) ?>" <?= ((string)$defaults['venue_id'] === (string)$venue['id']) ? 'selected' : '' ?>><?= h($venueLabel) ?><?= $venueDetails !== '' ? ' — ' . h($venueDetails) : '' ?></option><?php endforeach; ?></select></div>
            <div class="form-field span-12"><label for="venue">Manually enter venue address</label><input id="venue" type="text" name="venue" value="<?= h($defaults['venue']) ?>" placeholder="Type venue name and/or address"></div>
          </div></div>
        </section>

        <section class="form-section">
          <div class="form-section-header"><div class="form-section-icon">£</div><div><h2 class="form-section-title">Items</h2><p class="form-section-subtitle">Add the main package and any optional extras.</p></div></div>
          <div class="form-section-body"><div class="form-grid">
            <?php for ($i=0; $i<3; $i++): ?>
              <div class="form-field span-8"><label for="line_description_<?= $i+1 ?>">Description</label><input id="line_description_<?= $i+1 ?>" type="text" name="line_description[]" value="<?= h($defaults['line_description'][$i] ?? '') ?>" placeholder="<?= $i === 0 ? '' : 'Optional extra item' ?>"></div>
              <div class="form-field span-4"><label for="line_price_<?= $i+1 ?>">Price</label><input id="line_price_<?= $i+1 ?>" type="number" step="0.01" min="0" name="line_price[]" value="<?= h($defaults['line_price'][$i] ?? '') ?>" placeholder="0.00"></div>
            <?php endfor; ?>
          </div></div>
        </section>

        <section class="form-section">
          <div class="form-section-header"><div class="form-section-icon">💷</div><div><h2 class="form-section-title">Payment Schedule</h2><p class="form-section-subtitle">Defaults are 20% deposit, due 30 days from quote date, with the balance due 14 days before the event.</p></div></div>
          <div class="form-section-body"><div class="form-grid">
            <div class="form-field span-4"><label for="deposit_percentage">Deposit percentage</label><input id="deposit_percentage" type="number" step="0.01" min="0" max="100" name="deposit_percentage" value="<?= h($defaults['deposit_percentage']) ?>"><p class="field-help">Set to 0 if no deposit is required.</p></div>
            <div class="form-field span-4"><label for="deposit_due_date">Deposit due date</label><input id="deposit_due_date" type="date" name="deposit_due_date" value="<?= h($defaults['deposit_due_date']) ?>"><p class="field-help">Not applicable when the deposit is 0%.</p></div>
            <div class="form-field span-4"><label for="balance_due_date">Balance due date</label><input id="balance_due_date" type="date" name="balance_due_date" value="<?= h($defaults['balance_due_date']) ?>"><label class="compact-checkbox-row"><input id="balance_due_event_date" type="checkbox" name="balance_due_event_date" value="1"><span>Use event date</span></label></div>
          </div></div>
        </section>

        <section class="form-section">
          <div class="form-section-header"><div class="form-section-icon">📝</div><div><h2 class="form-section-title">Notes / Payment Terms</h2><p class="form-section-subtitle">Optional extra wording. If left blank, the PDF will use a simple thank-you message.</p></div></div>
          <div class="form-section-body"><div class="form-grid"><div class="form-field span-12"><label for="notes">Notes / payment terms</label><textarea id="notes" name="notes" rows="4" placeholder="Optional. Leave blank to use the default thank-you message."><?= h($defaults['notes']) ?></textarea></div></div></div>
        </section>

        <p class="touch-subtitle">Preview mode does not save details and does not allocate a quote or invoice number.</p>
        <div class="form-actions">
          <button class="touch-btn ghost" type="submit" formaction="quote-pdf.php?mode=preview" formtarget="_blank">Preview Test PDF</button>
          <button class="touch-btn blue" type="submit"><?= h($submitLabel) ?></button>
        </div>
      </form>
    </div>
  </section>
</main>

<script>
(function () {
  const eventDate = document.getElementById('event_date');
  const depositPercentage = document.getElementById('deposit_percentage');
  const depositDue = document.getElementById('deposit_due_date');
  const balanceDue = document.getElementById('balance_due_date');
  const useEventDate = document.getElementById('balance_due_event_date');
  const venueSelect = document.getElementById('venue_id');
  const venueInput = document.getElementById('venue');
  function isoDate(date) { const y = date.getFullYear(); const m = String(date.getMonth() + 1).padStart(2, '0'); const d = String(date.getDate()).padStart(2, '0'); return `${y}-${m}-${d}`; }
  function refreshDepositDateState() { if (!depositPercentage || !depositDue) return; const pct = parseFloat(depositPercentage.value || '0'); depositDue.disabled = pct <= 0; if (pct <= 0) { depositDue.value = ''; } else if (!depositDue.value) { const d = new Date(); d.setDate(d.getDate() + 30); depositDue.value = isoDate(d); } }
  function refreshBalanceDueDate(forceDefault) { if (!eventDate || !balanceDue || !eventDate.value) return; const event = new Date(eventDate.value + 'T12:00:00'); if (Number.isNaN(event.getTime())) return; if (useEventDate && useEventDate.checked) { balanceDue.value = eventDate.value; return; } if (forceDefault || !balanceDue.value) { event.setDate(event.getDate() - 14); balanceDue.value = isoDate(event); } }
  if (venueSelect && venueInput) { venueSelect.addEventListener('change', function () { const option = venueSelect.options[venueSelect.selectedIndex]; if (!option || !venueSelect.value) return; const name = option.getAttribute('data-name') || option.textContent || ''; const details = option.getAttribute('data-details') || ''; venueInput.value = details ? `${name}, ${details}` : name; }); }
  if (depositPercentage) depositPercentage.addEventListener('input', refreshDepositDateState);
  if (eventDate) eventDate.addEventListener('change', function () { refreshBalanceDueDate(true); });
  if (useEventDate) useEventDate.addEventListener('change', function () { refreshBalanceDueDate(true); });
  refreshDepositDateState();
})();
</script>

<style>
  .compact-checkbox-row { display: inline-flex; align-items: center; gap: 8px; margin-top: 10px; font-weight: 700; color: var(--admin-text, #fff); }
  .compact-checkbox-row input[type="checkbox"] { width: 18px !important; height: 18px !important; min-height: 0 !important; padding: 0 !important; margin: 0 !important; border-radius: 4px !important; accent-color: #2f7df6; }
</style>
<?php admin_footer(); ?>
