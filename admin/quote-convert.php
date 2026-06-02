<?php
require_once __DIR__ . '/_auth.php';
$id = (int)($_GET['id'] ?? 0);
$q = null;
if ($id > 0) {
    $stmt = db()->prepare('SELECT * FROM quotations WHERE id = ?');
    $stmt->execute([$id]);
    $q = $stmt->fetch();
}
if (!$q) { header('Location: quotes.php'); exit; }
$stmt = db()->prepare('SELECT id FROM invoices WHERE quotation_id = ? LIMIT 1');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    $stmt = db()->prepare("INSERT INTO invoices (quotation_id, total_amount, paid_status, created_at) VALUES (?, ?, 'unpaid', NOW())");
    $stmt->execute([$id, $q['total_amount']]);
    $invoiceId = (int)db()->lastInsertId();
    $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad((string)$invoiceId, 4, '0', STR_PAD_LEFT);
    db()->prepare('UPDATE invoices SET invoice_number = ? WHERE id = ?')->execute([$invoiceNumber, $invoiceId]);
    db()->prepare("UPDATE quotations SET status = 'accepted' WHERE id = ?")->execute([$id]);
}
header('Location: invoices.php?created=1');
exit;
