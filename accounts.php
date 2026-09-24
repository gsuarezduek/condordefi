<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/health.php';
require_once __DIR__ . '/includes/balances.php';
require_once __DIR__ . '/includes/format.php';
app_session_start();
require_login();

$activePage = 'accounts';
$isAdmin = !empty($_SESSION['is_admin']);
$error = null;

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '') {
        $error = 'Completá un nombre para la cuenta.';
    } elseif ($address !== '' && !preg_match(EVM_ADDRESS_PATTERN, $address)) {
        $error = 'La dirección no es válida (0x + 40 caracteres hex).';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO accounts (name) VALUES (?)')->execute([$name]);
        $accountId = (int) $pdo->lastInsertId();

        $error = $address !== '' ? add_account_address($accountId, $address, '') : null;
        if ($error === null) {
            $pdo->commit();
            header('Location: /addresses.php?account_id=' . $accountId);
            exit;
        }
        $pdo->rollBack();
    }
}

$accounts = accessible_accounts();
$accountIds = array_column($accounts, 'id');
$addressesByAccount = addresses_by_account($accountIds);
$summaries = account_summaries($accountIds);

// Orden: ?sort=name|code|balance|next_report&dir=asc|desc. Lo que no tiene
// valor (sin código, sin datos de saldo, sin próximo informe) va siempre al final.
const SORT_COLUMNS = [
    'name' => ['label' => 'Nombre', 'default_dir' => 'asc'],
    'code' => ['label' => 'Código', 'default_dir' => 'asc'],
    'balance' => ['label' => 'Saldo', 'default_dir' => 'desc'],
    'next_report' => ['label' => 'Próximo informe', 'default_dir' => 'asc'],
];
$sort = isset(SORT_COLUMNS[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'name';
$dir = in_array($_GET['dir'] ?? '', ['asc', 'desc'], true) ? $_GET['dir'] : SORT_COLUMNS[$sort]['default_dir'];

$sortValue = function (array $a) use ($sort, $summaries) {
    switch ($sort) {
        case 'code':
            return $a['code'] !== null && $a['code'] !== '' ? $a['code'] : null;
        case 'balance':
            $s = $summaries[(int) $a['id']];
            return $s['has_data'] ? $s['total'] : null;
        case 'next_report':
            return $a['next_report_date'];
        default:
            return $a['name'];
    }
};
usort($accounts, function (array $a, array $b) use ($sort, $dir, $sortValue): int {
    $va = $sortValue($a);
    $vb = $sortValue($b);
    if ($va === null || $vb === null) {
        return ($va === null) <=> ($vb === null);
    }
    $cmp = $sort === 'balance' ? $va <=> $vb : strnatcasecmp((string) $va, (string) $vb);
    if ($cmp === 0) {
        $cmp = strnatcasecmp($a['name'], $b['name']);
    }
    return $dir === 'desc' && $va !== $vb ? -$cmp : $cmp;
});

/** Encabezado de columna ordenable: link que ordena por esa columna (y alterna el sentido si ya es la activa). */
function sort_header(string $column, string $sort, string $dir, string $class = ''): string
{
    $active = $column === $sort;
    $nextDir = $active ? ($dir === 'asc' ? 'desc' : 'asc') : SORT_COLUMNS[$column]['default_dir'];
    $arrow = $active ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<th' . ($class ? ' class="' . $class . '"' : '') . '><a class="sort' . ($active ? ' active' : '') . '" href="?sort=' . $column . '&amp;dir=' . $nextDir . '">'
        . htmlspecialchars(SORT_COLUMNS[$column]['label'], ENT_QUOTES) . $arrow . '</a></th>';
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cuentas — CondorDeFi</title>
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
    <h1>Cuentas</h1>

    <?php if ($isAdmin): ?>
      <div class="card">
        <h2>Agregar cuenta</h2>
        <form method="post">
          <?= csrf_field() ?>
          <div class="field">
            <label for="name">Nombre</label>
            <input type="text" id="name" name="name" required>
          </div>
          <div class="field">
            <label for="address">Primera dirección (opcional, después podés agregar más)</label>
            <input type="text" id="address" name="address" placeholder="0x..." pattern="0x[a-fA-F0-9]{40}">
          </div>
          <button type="submit">Agregar</button>
        </form>
        <?php if ($error): ?>
          <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (empty($accounts)): ?>
      <p class="empty">Todavía no hay cuentas cargadas<?= $isAdmin ? '' : ' asignadas a tu usuario' ?>.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <?= sort_header('name', $sort, $dir) ?>
            <?= sort_header('code', $sort, $dir) ?>
            <?= sort_header('balance', $sort, $dir, 'num') ?>
            <th>Salud</th>
            <th>Direcciones</th>
            <?= sort_header('next_report', $sort, $dir) ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accounts as $a): $summary = $summaries[(int) $a['id']]; ?>
            <tr>
              <td><a href="/account.php?id=<?= (int) $a['id'] ?>"><?= htmlspecialchars($a['name'], ENT_QUOTES) ?></a></td>
              <td><?= htmlspecialchars($a['code'] ?? '—', ENT_QUOTES) ?></td>
              <td class="num">
                <?php if ($summary['has_data']): ?>
                  <?= fmt_usd($summary['total']) ?><?php if ($summary['missing'] > 0): ?><span class="muted" title="<?= $summary['missing'] ?> red(es) sin datos actualizados no están incluidas"> *</span><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td><?= health_badge($summary['health']) ?: '—' ?></td>
              <td class="address">
                <?php foreach ($addressesByAccount[(int) $a['id']] ?? [] as $addr): ?>
                  <div><?= htmlspecialchars($addr['address'], ENT_QUOTES) ?><?php if ($addr['label']): ?> <span class="badge"><?= htmlspecialchars($addr['label'], ENT_QUOTES) ?></span><?php endif; ?></div>
                <?php endforeach; ?>
                <?php if (empty($addressesByAccount[(int) $a['id']])): ?>—<?php endif; ?>
              </td>
              <td><?= $a['next_report_date'] ? date('d/m/Y', strtotime($a['next_report_date'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </main>
</body>
</html>
