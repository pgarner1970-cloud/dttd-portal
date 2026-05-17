<?php
require_once __DIR__ . '/config.php';

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function current_event() {
    $stmt = db()->query("SELECT * FROM events WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    $event = $stmt->fetch();
    if (!$event) {
        db()->exec("INSERT INTO events (event_name, venue_name, queue_visibility, is_active) VALUES ('Tonight’s Event', 'Dance Thru the Decades', '" . DEFAULT_QUEUE_VISIBILITY . "', 1)");
        $stmt = db()->query("SELECT * FROM events WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $event = $stmt->fetch();
    }
    return $event;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
