<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
app_session_start();
require_admin();

$activePage = 'accounts';

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
    $selected = array_map('intval', $_POST['user_ids'] ?? []);

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM account_user WHERE account_id = ?')->execute([$accountId]);
    if ($selected) {
        $insert = $pdo->prepare('INSERT INTO account_user (account_id, user_id) VALUES (?, ?)');
        foreach ($selected as $userId) {
            $insert->execute([$accountId, $userId]);
        }
    }
    $pdo->commit();

    header('Location: /access.php?account_id=' . $accountId);
    exit;
}

$users = db()->query('SELECT * FROM users WHERE is_admin = 0 ORDER BY name, email')->fetchAll();

$stmt = db()->prepare('SELECT user_id FROM account_user WHERE account_id = ?');
$stmt->execute([$accountId]);
$assignedIds = array_column($stmt->fetchAll(), 'user_id');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accesos — <?= htmlspecialchars($account['name'], ENT_QUOTES) ?> — CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<style>
  .back{color:var(--text-dim);font-size:12.5px;text-decoration:none;display:inline-block;margin-bottom:10px;}
  .back:hover{color:#fff;}
  .user-list{display:flex;flex-direction:column;gap:2px;}
  .user-check{
    display:flex;
    align-items:center;
    gap:10px;
    padding:8px 4px;
    border-bottom:1px solid #eee6d6;
    font-size:13.5px;
  }
  .user-check:last-child{border-bottom:none;}
  .user-check input{width:16px;height:16px;}
  .user-check .email{color:#8a8a8a;font-size:12px;}
</style>
</head>
<body>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <a class="back" href="/accounts.php">&larr; Cuentas</a>
    <h1>Accesos — <?= htmlspecialchars($account['name'], ENT_QUOTES) ?></h1>

    <div class="card">
      <h2>Usuarios con acceso</h2>
      <?php if (empty($users)): ?>
        <p class="empty">Todavía no hay usuarios no-admin cargados. Agregalos desde <a href="/users.php">Usuarios</a>.</p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="account_id" value="<?= (int) $accountId ?>">
          <div class="user-list">
            <?php foreach ($users as $u): ?>
              <label class="user-check">
                <input type="checkbox" name="user_ids[]" value="<?= (int) $u['id'] ?>" <?= in_array($u['id'], $assignedIds) ? 'checked' : '' ?>>
                <span><?= htmlspecialchars($u['name'] ?? $u['email'], ENT_QUOTES) ?></span>
                <span class="email"><?= htmlspecialchars($u['email'], ENT_QUOTES) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" style="margin-top:16px;">Guardar accesos</button>
        </form>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
