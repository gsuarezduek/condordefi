<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
app_session_start();
require_admin();

$activePage = 'settings';
$config = require __DIR__ . '/config.php';

const NOTES_KEY = 'config_notes';

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

/**
 * Estado de una clave de config.php sin exponerla: nunca se muestra completa,
 * solo los últimos 4 caracteres para reconocer cuál es.
 */
function secret_status(?string $value): string
{
    $value = (string) $value;
    if ($value === '' || $value === 'CAMBIAR') {
        return 'Sin configurar';
    }
    return strlen($value) >= 12 ? 'Configurada (…' . substr($value, -4) . ')' : 'Configurada';
}

$notesError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $notes = trim($_POST['notes'] ?? '');
    try {
        $stmt = db()->prepare(
            'INSERT INTO app_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute([NOTES_KEY, $notes !== '' ? $notes : null]);
        header('Location: /settings.php?saved=1');
        exit;
    } catch (PDOException $e) {
        $notesError = 'No se pudieron guardar las notas (¿falta correr la migración de app_settings?): ' . $e->getMessage();
    }
}

$notes = '';
try {
    $stmt = db()->prepare('SELECT value FROM app_settings WHERE name = ?');
    $stmt->execute([NOTES_KEY]);
    $notes = (string) $stmt->fetchColumn();
} catch (PDOException $e) {
    $notesError = $notesError ?? 'No se pudieron leer las notas (¿falta correr la migración de app_settings?): ' . $e->getMessage();
}
if ($notesError !== null && isset($_POST['notes'])) {
    $notes = (string) $_POST['notes']; // que no se pierda lo que se escribió
}

$services = [
    [
        'name' => 'Etherscan (API V2)',
        'use' => 'Saldo de ETH, tokens ERC-20 y movimientos en Ethereum.',
        'where' => 'includes/etherscan.php',
        'key' => 'etherscan.api_key',
        'status' => secret_status($config['etherscan']['api_key'] ?? null),
        'link' => 'https://etherscan.io/myapikey',
        'note' => 'Plan free: máx. 5 req/s (el código espera ~210 ms entre llamadas).',
    ],
    [
        'name' => 'NodeReal MegaNode (BSC)',
        'use' => 'Saldo de BNB, tokens BEP-20 y movimientos en BSC.',
        'where' => 'includes/nodereal.php',
        'key' => 'nodereal.api_key',
        'status' => secret_status($config['nodereal']['api_key'] ?? null),
        'link' => 'https://nodereal.io/meganode',
        'note' => 'JSON-RPC. Rango máximo de bloques por consulta: 2.000.000.',
    ],
    [
        'name' => 'DeBank Cloud',
        'use' => 'Saldos totales, tokens y protocolos DeFi por dirección.',
        'where' => 'includes/debank.php',
        'key' => 'debank.access_key',
        'status' => secret_status($config['debank']['access_key'] ?? null),
        'link' => 'https://cloud.debank.com',
        'note' => 'Cliente escrito, pero hoy ninguna página lo usa.',
    ],
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configuración — CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .section-title{font-size:15px;margin:32px 0 12px 0;color:#fff;}
  .muted{color:var(--text-dim);font-size:12.5px;}
  .status-ok{color:#6fcf7f;font-weight:600;}
  .status-missing{color:#ee7b6f;font-weight:600;}
  code{font-family:monospace;font-size:12.5px;color:var(--gold);}
  td{vertical-align:top;}
  textarea{
    width:100%;
    min-height:140px;
    padding:10px 12px;
    border:1px solid var(--border);
    border-radius:8px;
    font-size:13.5px;
    font-family:inherit;
    background:#fcfbf8;
    resize:vertical;
  }
  .saved{background:#e8f6ea;color:#2a6b34;border-radius:8px;padding:10px 12px;font-size:12.5px;margin-bottom:12px;}
  .table-wrap{overflow-x:auto;}
</style>
</head>
<body>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <h1>Configuración</h1>
    <p class="muted">Inventario de las APIs y servicios que usa la app. Las claves viven en <code>config.php</code> en el servidor (no se guardan en la base ni se muestran completas). Para cambiarlas, editá ese archivo por FTP.</p>

    <h2 class="section-title">APIs externas</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Servicio</th><th>Para qué se usa</th><th>Dónde</th><th>Clave</th><th>Estado</th></tr>
        </thead>
        <tbody>
          <?php foreach ($services as $s): ?>
            <tr>
              <td>
                <a href="<?= h($s['link']) ?>" target="_blank" rel="noopener noreferrer"><?= h($s['name']) ?></a>
                <div class="muted"><?= h($s['note']) ?></div>
              </td>
              <td><?= h($s['use']) ?></td>
              <td><code><?= h($s['where']) ?></code></td>
              <td><code><?= h($s['key']) ?></code></td>
              <td class="<?= $s['status'] === 'Sin configurar' ? 'status-missing' : 'status-ok' ?>"><?= h($s['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h2 class="section-title">Servidor</h2>
    <div class="card">
      <table>
        <tbody>
          <tr><td>Base de datos</td><td><code><?= h($config['db']['name'] ?? '') ?></code> en <code><?= h($config['db']['host'] ?? '') ?></code> (usuario <code><?= h($config['db']['user'] ?? '') ?></code>)</td></tr>
          <tr><td>Email de códigos</td><td>PHP <code>mail()</code> desde <code><?= h($config['mail']['from'] ?? '') ?></code> (<?= h($config['mail']['from_name'] ?? '') ?>)</td></tr>
          <tr><td>Código de acceso</td><td><?= CODE_LENGTH ?> dígitos, vence a los <?= CODE_TTL_MINUTES ?> min, máx. <?= MAX_ATTEMPTS ?> intentos por código; <?= MAX_CODES_PER_EMAIL_WINDOW ?> códigos por email y <?= MAX_CODES_PER_IP_WINDOW ?> por IP cada <?= RATE_LIMIT_WINDOW_MINUTES ?> min</td></tr>
          <tr><td>PHP</td><td><?= h(PHP_VERSION) ?></td></tr>
          <tr><td>Archivo de claves</td><td><code>config.php</code> — bloqueado desde la web por <code>.htaccess</code>; el modelo sin claves es <code>config.example.php</code></td></tr>
        </tbody>
      </table>
    </div>

    <h2 class="section-title">Notas de configuración</h2>
    <div class="card">
      <?php if (isset($_GET['saved']) && $notesError === null): ?>
        <div class="saved">Notas guardadas.</div>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <textarea name="notes" placeholder="Ej: la key de Etherscan está a nombre de… Renovar el plan de NodeReal en… Contraseña de la base: ver gestor de claves."><?= h($notes) ?></textarea>
        <button type="submit" style="margin-top:10px;">Guardar</button>
      </form>
      <p class="muted" style="margin:12px 0 0 0;">Estas notas se guardan en la base de datos: no anotes acá claves ni contraseñas.</p>
      <?php if ($notesError): ?>
        <div class="error"><?= h($notesError) ?></div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
