<?php
require_once __DIR__ . '/config.php';
function db() { static $pdo=null; if ($pdo===null) { $dsn='mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4'; $pdo=new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); } return $pdo; }
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function active_event() { $sql="SELECT * FROM events WHERE is_active=1 AND (portal_available_from IS NULL OR portal_available_from <= NOW()) AND (portal_available_until IS NULL OR portal_available_until >= NOW()) ORDER BY event_date ASC, id DESC LIMIT 1"; return db()->query($sql)->fetch(); }
function get_event($id) { $stmt=db()->prepare('SELECT * FROM events WHERE id=?'); $stmt->execute([(int)$id]); return $stmt->fetch(); }
function request_event() { if (!empty($_GET['event'])) { $event=get_event((int)$_GET['event']); if ($event) return $event; } return active_event(); }
function event_is_available($event) { if (!$event) return false; if ((int)$event['is_active']!==1) return false; $now=time(); if (!empty($event['portal_available_from']) && strtotime($event['portal_available_from'])>$now) return false; if (!empty($event['portal_available_until']) && strtotime($event['portal_available_until'])<$now) return false; return true; }
?>
