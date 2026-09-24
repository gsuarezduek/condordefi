<?php
require_once __DIR__ . '/includes/auth.php';
app_session_start();
$_SESSION = [];
session_destroy();
header('Location: /index.php');
exit;
