<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/accounts.php';
app_session_start();
require_admin();

$activePage = 'commercials';
$errors = ['data' => null, 'payment' => null];

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM commercials WHERE id = ?');
$stmt->execute([$id]);
$commercial = $stmt->fetch();

if (!$commercial) {
    http_response_code(404);
    echo 'Comercial no encontrado.';
    exit;
}

$stmt = db()->prepare('SELECT id, name, performance_fee FROM accounts WHERE commercial_id = ? ORDER BY name');
$stmt->execute([$id]);
$accounts = $stmt->fetchAll();
$accountNames = array_column($accounts, 'name', 'id');

function redirect_self(int $id): void
{
    header('Location: /commercial.php?id=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $wallet = trim($_POST['wallet_address'] ?? '');
        if ($name === '') {
            $errors['data'] = 'El nombre no puede quedar vacío.';
        } elseif ($wallet !== '' && !preg_match(EVM_ADDRESS_PATTERN, $wallet)) {
            $errors['data'] = 'La wallet no es válida (0x + 40 caracteres hex).';
        } else {
            $stmt = db()->prepare('UPDATE commercials SET name = ?, wallet_address = ? WHERE id = ?');
            $stmt->execute([$name, $wallet !== '' ? strtolower($wallet) : null, $id]);
            redirect_self($id);
        }
        $commercial['name'] = $name;
        $commercial['wallet_address'] = $wallet;

    } elseif ($action === 'add_payment') {
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $amount = str_replace(',', '.', trim($_POST['amount'] ?? ''));
        $paidAt = trim($_POST['paid_at'] ?? '');
        $txHash = trim($_POST['tx_hash'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $date = DateTime::createFromFormat('!Y-m-d', $paidAt);

        if (!isset($accountNames[$accountId])) {
            $errors['payment'] = 'Elegí una de las cuentas de este comercial.';
        } elseif (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount >= 1e18) {
            $errors['payment'] = 'El monto tiene que ser un número mayor que cero.';
        } elseif (!$date || $date->format('Y-m-d') !== $paidAt) {
            $errors['payment'] = 'La fecha del pago no es válida.';
        } elseif (strlen($txHash) > 80 || preg_match('/[^A-Za-z0-9]/', $txHash)) {
            $errors['payment'] = 'El hash de la transacción solo puede tener letras y números (hasta 80).';
        } elseif (mb_strlen($notes) > 500) {
            $errors['payment'] = 'Las notas pueden tener hasta 500 caracteres.';
        } else {
            $stmt = db()->prepare(
                'INSERT INTO commercial_payments (commercial_id, account_id, amount, paid_at, tx_hash, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id, $accountId, $amount, $paidAt, $txHash !== '' ? $txHash : null, $notes !== '' ? $notes : null]);
            redirect_self($id);
        }

    } elseif ($action === 'delete_payment') {
        $stmt = db()->prepare('DELETE FROM commercial_payments WHERE id = ? AND commercial_id = ?');
        $stmt->execute([(int) ($_POST['payment_id'] ?? 0), $id]);
        redirect_self($id);
    }
}

$stmt = db()->prepare(
    'SELECT p.*, a.name AS account_name
     FROM commercial_payments p JOIN accounts a ON a.id = p.account_id
     WHERE p.commercial_id = ? ORDER BY p.paid_at DESC, p.id DESC'
);
$stmt->execute([$id]);
$payments = $stmt->fetchAll();
$totalPaid = array_sum(array_map('floatval', array_column($payments, 'amount')));

function usd($n): string
{
    return '$' . number_format((float) $n, 2, ',', '.');
}
function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($commercial['name']) ?> — Comerciales — CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .back{color:var(--text-dim);font-size:12.5px;text-decoration:none;display:inline-block;margin-bottom:10px;}
  .back:hover{color:#fff;}
  .section-title{font-size:15px;margin:32px 0 12px 0;color:#fff;}
  .total{font-size:26px;font-weight:700;margin:4px 0 0 0;}
  .muted{color:var(--text-dim);font-size:12.5px;}
  input[type=date],input[type=number],select{
    width:100%;
    padding:10px 12px;
    border:1px solid var(--border);
    border-radius:8px;
    font-size:14px;
    background:#fcfbf8;
    outline:none;
  }
  td form{display:inline;}
  .link-danger{background:none;color:#ee7b6f;padding:0;font-weight:600;font-size:12.5px;}
  .hash{font-family:monospace;font-size:12px;color:var(--text-dim);word-break:break-all;}
</style>
</head>
<body>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <a class="back" href="/commercials.php">&larr; Comerciales</a>
    <h1><?= h($commercial['name']) ?></h1>

    <div class="card">
      <h2>Datos del comercial</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="update">
        <div class="field">
          <label for="name">Nombre</label>
          <input type="text" id="name" name="name" value="<?= h($commercial['name']) ?>" required>
        </div>
        <div class="field">
          <label for="wallet_address">Wallet para los pagos</label>
          <input type="text" id="wallet_address" name="wallet_address" placeholder="0x..." pattern="0x[a-fA-F0-9]{40}" value="<?= h($commercial['wallet_address']) ?>">
        </div>
        <button type="submit">Guardar</button>
      </form>
      <?php if ($errors['data']): ?><div class="error"><?= h($errors['data']) ?></div><?php endif; ?>
    </div>

    <h2 class="section-title">Cuentas</h2>
    <?php if (empty($accounts)): ?>
      <p class="empty">Este comercial todavía no tiene cuentas. Se asignan desde "Editar datos de la cuenta" en cada cuenta.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Cuenta</th><th>Fee de performance</th><th>Pagado</th></tr></thead>
        <tbody>
          <?php
            $paidByAccount = [];
            foreach ($payments as $p) {
                $paidByAccount[(int) $p['account_id']] = ($paidByAccount[(int) $p['account_id']] ?? 0) + (float) $p['amount'];
            }
          ?>
          <?php foreach ($accounts as $a): ?>
            <tr>
              <td><a href="/account.php?id=<?= (int) $a['id'] ?>"><?= h($a['name']) ?></a></td>
              <td><?= $a['performance_fee'] !== null ? h(rtrim(rtrim(number_format((float) $a['performance_fee'], 2, ',', ''), '0'), ',')) . '%' : '—' ?></td>
              <td><?= usd($paidByAccount[(int) $a['id']] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h2 class="section-title">Pagos</h2>
    <div class="total"><?= usd($totalPaid) ?></div>
    <div class="muted" style="margin-bottom:16px;">pagado en total (USD)</div>

    <?php if (!empty($accounts)): ?>
      <div class="card">
        <h2>Anotar pago</h2>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="action" value="add_payment">
          <div class="field">
            <label for="account_id">Sale del cobro de la cuenta</label>
            <select id="account_id" name="account_id" required>
              <?php foreach ($accounts as $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= (int) ($_POST['account_id'] ?? 0) === (int) $a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="amount">Monto (USD)</label>
            <input type="number" id="amount" name="amount" step="0.01" min="0.01" required value="<?= h($_POST['amount'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="paid_at">Fecha</label>
            <input type="date" id="paid_at" name="paid_at" required value="<?= h($_POST['paid_at'] ?? date('Y-m-d')) ?>">
          </div>
          <div class="field">
            <label for="tx_hash">Hash de la transacción (opcional)</label>
            <input type="text" id="tx_hash" name="tx_hash" maxlength="80" autocomplete="off" value="<?= h($_POST['tx_hash'] ?? '') ?>">
          </div>
          <div class="field" style="flex-basis:100%;">
            <label for="notes">Notas (opcional)</label>
            <input type="text" id="notes" name="notes" maxlength="500" value="<?= h($_POST['notes'] ?? '') ?>">
          </div>
          <button type="submit">Anotar pago</button>
        </form>
        <?php if ($errors['payment']): ?><div class="error"><?= h($errors['payment']) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (empty($payments)): ?>
      <p class="empty">Todavía no hay pagos anotados.</p>
    <?php else: ?>
      <table>
        <thead><tr><th>Fecha</th><th>Cuenta</th><th>Monto</th><th>Detalle</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td><?= h(date('d/m/Y', strtotime($p['paid_at']))) ?></td>
              <td><a href="/account.php?id=<?= (int) $p['account_id'] ?>"><?= h($p['account_name']) ?></a></td>
              <td><?= usd($p['amount']) ?></td>
              <td>
                <?= h($p['notes']) ?>
                <?php if ($p['tx_hash']): ?><div class="hash"><?= h($p['tx_hash']) ?></div><?php endif; ?>
              </td>
              <td>
                <form method="post" onsubmit="return confirm('¿Borrar este pago?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="action" value="delete_payment">
                  <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                  <button type="submit" class="link-danger">Borrar</button>
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
