<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/accounts.php';
app_session_start();
require_admin();

$activePage = 'accounts';
$error = null;

$accountId = (int) ($_GET['account_id'] ?? $_POST['account_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM accounts WHERE id = ?');
$stmt->execute([$accountId]);
$account = $stmt->fetch();

if (!$account) {
    http_response_code(404);
    echo 'Cuenta no encontrada.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'delete') {
        $stmt = db()->prepare('DELETE FROM account_addresses WHERE id = ? AND account_id = ?');
        $stmt->execute([(int) ($_POST['address_id'] ?? 0), $accountId]);
        header('Location: /addresses.php?account_id=' . $accountId);
        exit;
    }

    $error = add_account_address($accountId, $_POST['address'] ?? '', $_POST['label'] ?? '');
    if ($error === null) {
        header('Location: /addresses.php?account_id=' . $accountId);
        exit;
    }
}

$addresses = account_addresses($accountId);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Direcciones — <?= htmlspecialchars($account['name'], ENT_QUOTES) ?> — CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<style>
  .back{color:var(--text-dim);font-size:12.5px;text-decoration:none;display:inline-block;margin-bottom:10px;}
  .back:hover{color:#fff;}
  td form{display:inline;}
  .link-danger{background:none;color:#ee7b6f;padding:0;font-weight:600;font-size:12.5px;}
</style>
</head>
<body>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <a class="back" href="/accounts.php">&larr; Cuentas</a>
    <h1>Direcciones — <?= htmlspecialchars($account['name'], ENT_QUOTES) ?></h1>

    <div class="card">
      <h2>Agregar dirección</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="account_id" value="<?= $accountId ?>">
        <div class="field">
          <label for="address">Dirección (Ethereum / BSC)</label>
          <input type="text" id="address" name="address" placeholder="0x..." pattern="0x[a-fA-F0-9]{40}" required>
        </div>
        <div class="field">
          <label for="label">Etiqueta (opcional)</label>
          <input type="text" id="label" name="label" placeholder="Ej: Wallet principal">
        </div>
        <button type="submit">Agregar</button>
      </form>
      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
      <?php endif; ?>
    </div>

    <?php if (empty($addresses)): ?>
      <p class="empty">Esta cuenta todavía no tiene direcciones.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Etiqueta</th><th>Dirección</th><th>Agregada</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($addresses as $a): ?>
            <tr>
              <td><?= htmlspecialchars($a['label'] ?? '—', ENT_QUOTES) ?></td>
              <td class="address"><?= htmlspecialchars($a['address'], ENT_QUOTES) ?></td>
              <td><?= htmlspecialchars($a['created_at'], ENT_QUOTES) ?></td>
              <td>
                <form method="post" onsubmit="return confirm('¿Quitar esta dirección de la cuenta?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="account_id" value="<?= $accountId ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="address_id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="link-danger">Quitar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>
</body>
</html>
