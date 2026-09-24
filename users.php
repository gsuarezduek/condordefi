<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
app_session_start();
require_admin();

$activePage = 'users';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Completá un nombre y un email válido.';
    } else {
        try {
            $stmt = db()->prepare('INSERT INTO users (name, email) VALUES (?, ?)');
            $stmt->execute([$name, $email]);
            header('Location: /users.php');
            exit;
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000' ? 'Ya existe un usuario con ese email.' : 'Error: ' . $e->getMessage();
        }
    }
}

$users = db()->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Usuarios — CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
</head>
<body>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <h1>Usuarios</h1>

    <div class="card">
      <h2>Agregar usuario</h2>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field">
          <label for="name">Nombre</label>
          <input type="text" id="name" name="name" required>
        </div>
        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" required>
        </div>
        <button type="submit">Agregar</button>
      </form>
      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
      <?php endif; ?>
    </div>

    <table>
      <thead>
        <tr><th>Nombre</th><th>Email</th><th>Alta</th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['name'] ?? '—', ENT_QUOTES) ?></td>
            <td>
              <?= htmlspecialchars($u['email'], ENT_QUOTES) ?>
              <?php if ($u['is_admin']): ?><span class="badge">admin</span><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($u['created_at'], ENT_QUOTES) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </main>
</body>
</html>
