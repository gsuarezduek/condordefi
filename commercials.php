<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/accounts.php';
app_session_start();
require_admin();

$activePage = 'commercials';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $wallet = trim($_POST['wallet_address'] ?? '');

    if ($name === '') {
        $error = 'Completá el nombre del comercial.';
    } elseif ($wallet !== '' && !preg_match(EVM_ADDRESS_PATTERN, $wallet)) {
        $error = 'La wallet no es válida (0x + 40 caracteres hex).';
    } else {
        $stmt = db()->prepare('INSERT INTO commercials (name, wallet_address) VALUES (?, ?)');
        $stmt->execute([$name, $wallet !== '' ? strtolower($wallet) : null]);
        header('Location: /commercial.php?id=' . (int) db()->lastInsertId());
        exit;
    }
}

$commercials = db()->query(
    'SELECT c.*,
            (SELECT COUNT(*) FROM accounts a WHERE a.commercial_id = c.id) AS accounts_count,
            (SELECT COALESCE(SUM(p.amount), 0) FROM commercial_payments p WHERE p.commercial_id = c.id) AS total_paid
     FROM commercials c ORDER BY c.name'
)->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comerciales — CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <h1>Comerciales</h1>

    <div class="card">
      <h2>Agregar comercial</h2>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field">
          <label for="name">Nombre</label>
          <input type="text" id="name" name="name" required>
        </div>
        <div class="field">
          <label for="wallet_address">Wallet para los pagos (opcional)</label>
          <input type="text" id="wallet_address" name="wallet_address" placeholder="0x..." pattern="0x[a-fA-F0-9]{40}">
        </div>
        <button type="submit">Agregar</button>
      </form>
      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
      <?php endif; ?>
    </div>

    <?php if (empty($commercials)): ?>
      <p class="empty">Todavía no hay comerciales cargados.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Nombre</th><th>Wallet</th><th>Cuentas</th><th>Total pagado</th></tr>
        </thead>
        <tbody>
          <?php foreach ($commercials as $c): ?>
            <tr>
              <td><a href="/commercial.php?id=<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></a></td>
              <td class="address"><?= htmlspecialchars($c['wallet_address'] ?? '—', ENT_QUOTES) ?></td>
              <td><?= (int) $c['accounts_count'] ?></td>
              <td>$<?= number_format((float) $c['total_paid'], 2, ',', '.') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>
</body>
</html>
