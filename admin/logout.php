<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/_auth_cookie.php';
unset($_SESSION['dttd_admin']);
dttd_admin_clear_auth_cookie();
header('Location: login.php');
exit;
