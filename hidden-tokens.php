<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/tokens.php';
app_session_start();
require_admin();

$activePage = 'hidden-tokens';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    unignore_token((int) ($_POST['id'] ?? 0));
    header('Location: /hidden-tokens.php');
    exit;
}

$tokens = ignored_tokens_list();
$explorers = [
    'eth' => ['name' => 'Ethereum', 'token_url' => 'https://etherscan.io/token/'],
    'bsc' => ['name' => 'BSC', 'token_url' => 'https://bscscan.com/token/'],
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tokens ocultos — CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<style>
  .intro{color:var(--text-dim);font-size:13px;line-height:1.5;max-width:640px;margin:-10px 0 22px 0;}
  .contract{font-family:monospace;font-size:12px;color:var(--text-dim);word-break:break-all;}
  td.row-action{text-align:right;}
  td.row-action form{display:inline;margin:0;}
  .btn-small{padding:4px 10px;font-size:11.5px;background:transparent;color:var(--text-dim);border:1px solid var(--panel-border);}
  .btn-small:hover{color:#fff;border-color:var(--text-dim);}
</style>
</head>
<body>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <h1>Tokens ocultos</h1>
    <p class="intro">
      Tokens que ocultaste desde la vista de una cuenta (por lo general spam que el filtro automático no detecta).
      Aplica a todas las cuentas y también oculta sus movimientos. Para volver a mostrar uno, usá "Restaurar".
    </p>

    <?php if (empty($tokens)): ?>
      <p class="empty">Todavía no ocultaste ningún token. Aparece un botón "Ocultar" al lado de cada token en la vista de una cuenta.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr><th>Token</th><th>Red</th><th>Contrato</th><th>Oculto desde</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($tokens as $t):
            $explorer = $explorers[$t['chain']] ?? null;
          ?>
            <tr>
              <td><?= htmlspecialchars($t['symbol'] ?? '?', ENT_QUOTES) ?> <span class="contract">— <?= htmlspecialchars($t['name'] ?? '', ENT_QUOTES) ?></span></td>
              <td><?= htmlspecialchars($explorer['name'] ?? $t['chain'], ENT_QUOTES) ?></td>
              <td class="contract">
                <?php if ($explorer): ?>
                  <a href="<?= htmlspecialchars($explorer['token_url'] . $t['contract'], ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($t['contract'], ENT_QUOTES) ?></a>
                <?php else: ?>
                  <?= htmlspecialchars($t['contract'], ENT_QUOTES) ?>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($t['created_at'], ENT_QUOTES) ?></td>
              <td class="row-action">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button type="submit" class="btn-small">Restaurar</button>
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
