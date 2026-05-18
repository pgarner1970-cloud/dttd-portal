<?php
session_start();
unset($_SESSION['dttd_admin']);
header('Location: login.php');
exit;
