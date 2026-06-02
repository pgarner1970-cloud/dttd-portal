<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/simple-pdf.php';

function dttd_post_items() {
    $items = [];
    $descs = $_POST['line_description'] ?? [];
    $prices = $_POST['line_price'] ?? [];
    foreach ($descs as $i => $desc) {
        $desc = trim((string)$desc);
        $price = (float)($prices[$i] ?? 0);
        if ($desc !== '' && $price >= 0) {
            $items[] = ['description' => $desc, 'price' => number_format($price, 2, '.', '')];
        }
    }
    return $items ?: [['description' => 'Dance Thru The Decades DJ Entertainment Package', 'price' => '0.00']];
}

$mode = $_GET['mode'] ?? '';
$type = $_GET['type'] ?? 'quote';
$isPreview = $mode === 'preview';
$invoiceNumber = '';

if ($isPreview) {
    $items = dttd_post_items();
    $total = 0; foreach ($items as $item) { $total += (float)$item['price']; }
    $doc = [
        'number' => 'PREVIEW',
        'customer_name' => trim($_POST['customer_name'] ?? 'Preview Customer'),
        'customer_email' => trim($_POST['customer_email'] ?? ''),
        'customer_address' => trim($_POST['customer_address'] ?? ''),
        'event_description' => trim($_POST['event_description'] ?? 'Dance Thru The Decades Event'),
        'event_date' => trim($_POST['event_date'] ?? ''),
        'venue' => trim($_POST['venue'] ?? ''),
        'items' => $items,
        'notes' => trim($_POST['notes'] ?? ''),
        'total_amount' => $total,
    ];
} else {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM quotations WHERE id = ?');
    $stmt->execute([$id]);
    $q = $stmt->fetch();
    if (!$q) { http_response_code(404); echo 'Quotation not found'; exit; }
    if ($type === 'invoice') {
        $stmt = db()->prepare('SELECT * FROM invoices WHERE quotation_id = ? LIMIT 1');
        $stmt->execute([$id]);
        $inv = $stmt->fetch();
        if (!$inv) { http_response_code(404); echo 'Invoice not found'; exit; }
        $invoiceNumber = $inv['invoice_number'];
    }
    $doc = [
        'number' => $type === 'invoice' ? $invoiceNumber : $q['quote_number'],
        'customer_name' => $q['customer_name'],
        'customer_email' => $q['customer_email'],
        'customer_address' => $q['customer_address'],
        'event_description' => $q['event_description'],
        'event_date' => $q['event_date'],
        'venue' => $q['venue'],
        'items' => json_decode($q['items_json'], true) ?: [],
        'notes' => $q['notes'],
        'total_amount' => $q['total_amount'],
    ];
}

$title = $type === 'invoice' ? 'INVOICE' : 'QUOTATION';
if ($isPreview) { $title = 'TEST PREVIEW'; }

$pdf = new DttdSimplePdf();
$pdf->beginPage();
$logo = dirname(__DIR__) . '/assets/dttd-invoice-logo.jpg';
$footer = dirname(__DIR__) . '/assets/dttd-invoice-footer.jpg';

// Footer artwork first, full page width.
if (is_file($footer)) { $pdf->image($footer, 0, 0, 595.28, 118); }

// Logo at top without stretching.
if (is_file($logo)) { $pdf->image($logo, 40, 705, 96, 96); }

$pdf->text(155, 780, 'Dance Thru The Decades Events', 19, true);
$pdf->text(155, 760, '1 Cooks Cross, Alveley, Shropshire WV15 6LS', 10, false);
$pdf->text(430, 782, $title, 22, true);
$pdf->text(430, 758, 'No: ' . $doc['number'], 10, true);
$pdf->text(430, 742, 'Date: ' . date('d/m/Y'), 10, false);

if ($isPreview) {
    $pdf->text(210, 690, 'PREVIEW / TEST DOCUMENT - NOT SAVED', 14, true);
    $pdf->line(40, 680, 555, 680, 0.65);
} else {
    $pdf->line(40, 690, 555, 690, 0.65);
}

$pdf->text(40, 660, 'Customer', 13, true);
$pdf->rect(40, 570, 240, 78, [0.97,0.97,0.97], [0.78,0.78,0.78]);
$pdf->text(52, 628, $doc['customer_name'], 11, true);
$y = 612;
foreach (preg_split('/\r\n|\r|\n/', (string)$doc['customer_address']) as $line) {
    if (trim($line) !== '') { $pdf->text(52, $y, trim($line), 9); $y -= 13; }
}
if (!empty($doc['customer_email'])) { $pdf->text(52, $y, $doc['customer_email'], 9); }

$pdf->text(315, 660, 'Event Details', 13, true);
$pdf->rect(315, 570, 240, 78, [0.97,0.97,0.97], [0.78,0.78,0.78]);
$pdf->text(327, 628, $doc['event_description'], 10, true);
$pdf->text(327, 608, 'Date: ' . (!empty($doc['event_date']) ? date('d/m/Y', strtotime($doc['event_date'])) : 'TBC'), 9);
$pdf->text(327, 590, 'Venue: ' . ($doc['venue'] ?: 'TBC'), 9);

$pdf->text(40, 535, 'Details', 13, true);
$pdf->rect(40, 505, 360, 22, [0.29,0.00,0.33], [0.29,0.00,0.33]);
$pdf->rect(400, 505, 70, 22, [0.29,0.00,0.33], [0.29,0.00,0.33]);
$pdf->rect(470, 505, 85, 22, [0.29,0.00,0.33], [0.29,0.00,0.33]);
$pdf->text(50, 512, 'Description', 10, true);
$pdf->text(416, 512, 'Qty', 10, true);
$pdf->text(492, 512, 'Price', 10, true);

$y = 477;
$total = 0;
foreach ($doc['items'] as $item) {
    $price = (float)($item['price'] ?? 0);
    $total += $price;
    $pdf->rect(40, $y-5, 360, 28, [1,1,1], [0.82,0.82,0.82]);
    $pdf->rect(400, $y-5, 70, 28, [1,1,1], [0.82,0.82,0.82]);
    $pdf->rect(470, $y-5, 85, 28, [1,1,1], [0.82,0.82,0.82]);
    $pdf->text(50, $y+5, substr((string)$item['description'], 0, 58), 9);
    $pdf->text(430, $y+5, '1', 9);
    $pdf->text(493, $y+5, chr(163) . number_format($price, 2), 9);
    $y -= 28;
}

$pdf->rect(385, $y-28, 85, 24, [0.97,0.97,0.97], [0.7,0.7,0.7]);
$pdf->rect(470, $y-28, 85, 24, [1.0,0.91,0.70], [0.7,0.7,0.7]);
$pdf->text(405, $y-14, 'Total Due', 11, true);
$pdf->text(493, $y-14, chr(163) . number_format($total, 2), 11, true);

$pdf->text(40, 230, 'Notes / Terms', 13, true);
$pdf->rect(40, 145, 515, 72, [0.98,0.98,0.98], [0.78,0.78,0.78]);
$notes = trim((string)$doc['notes']);
if ($notes === '') {
    $notes = 'Thank you for booking Dance Thru The Decades Events. Payment terms and bank details can be added here.';
}
$y = 195;
foreach (str_split(str_replace(["\r", "\n"], ' ', $notes), 86) as $line) {
    $pdf->text(52, $y, trim($line), 9);
    $y -= 13;
    if ($y < 155) break;
}

$pdf->text(40, 126, $isPreview ? 'This preview was not saved and no quote or invoice number has been allocated.' : 'Generated by Dance Thru The Decades Events.', 8);
$pdf->endPage();
$pdf->output(strtolower(str_replace(' ', '-', $title)) . '-' . preg_replace('/[^A-Za-z0-9-]/', '', $doc['number']) . '.pdf');
