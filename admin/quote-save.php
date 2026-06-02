<?php
require_once __DIR__ . '/_auth.php';

function dttd_quote_items_from_post() {
    $descs = $_POST['line_description'] ?? [];
    $prices = $_POST['line_price'] ?? [];
    $items = [];
    foreach ($descs as $i => $desc) {
        $desc = trim((string)$desc);
        $price = (float)($prices[$i] ?? 0);
        if ($desc !== '' && $price >= 0) {
            $items[] = ['description' => $desc, 'price' => number_format($price, 2, '.', '')];
        }
    }
    return $items ?: [['description' => 'Dance Thru The Decades DJ Entertainment Package', 'price' => '0.00']];
}

$items = dttd_quote_items_from_post();
$total = 0;
foreach ($items as $item) { $total += (float)$item['price']; }

$stmt = db()->prepare("INSERT INTO quotations (customer_name, customer_email, customer_address, event_description, event_date, venue, items_json, notes, total_amount, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW())");
$stmt->execute([
    trim($_POST['customer_name'] ?? ''), trim($_POST['customer_email'] ?? ''), trim($_POST['customer_address'] ?? ''),
    trim($_POST['event_description'] ?? ''), ($_POST['event_date'] ?? '') ?: null, trim($_POST['venue'] ?? ''),
    json_encode($items), trim($_POST['notes'] ?? ''), number_format($total, 2, '.', '')
]);
$id = (int)db()->lastInsertId();
$quoteNumber = 'QT-' . date('Y') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
db()->prepare("UPDATE quotations SET quote_number = ? WHERE id = ?")->execute([$quoteNumber, $id]);
header('Location: quotes.php?saved=1');
exit;
