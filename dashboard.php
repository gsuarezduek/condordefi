<?php
session_start();
require_once __DIR__ . '/includes/auth.php';
require_login();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<style>
  body{
    margin:0;
    min-height:100vh;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:16px;
    background:#0f0f0e;
    color:#fff;
    font-family:'Helvetica Neue', Arial, sans-serif;
  }
  a{color:#e9b73e;}
</style>
</head>
<body>
  <h1>Hola Mundo</h1>
  <p>Sesión iniciada como <?= htmlspecialchars($_SESSION['user_email'], ENT_QUOTES) ?></p>
  <a href="/logout.php">Cerrar sesión</a>
</body>
</html>
