<?php
// Migrador temporal: compara la base con sql/schema.sql y aplica SOLO lo que
// falta. Nunca borra ni pisa datos (solo crea tablas, agrega columnas,
// índices y claves foráneas, y copia direcciones viejas). Primero muestra el
// plan; recién aplica cuando lo confirmás. Borrar este archivo del servidor
// cuando termines.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
app_session_start();
require_admin();

$pdo = db();

function q(string $sql, array $params = []): array
{
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function table_exists(string $table): bool
{
    return (bool) q('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
}

function column_info(string $table, string $column): ?array
{
    $rows = q('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
    return $rows ? $rows[0] : null;
}

function index_exists(string $table, string $index): bool
{
    return (bool) q('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$table, $index]);
}

function has_foreign_key(string $table, string $column): bool
{
    return (bool) q(
        'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
        [$table, $column]
    );
}

// Lo que "va a existir" al aplicar el plan, para decidir pasos que dependen de otros.
$willExist = [];
$steps = []; // cada paso: ['label' => ..., 'sql' => ...]

// 1) Tablas que faltan: se toman tal cual del schema.sql.
$schema = file_get_contents(__DIR__ . '/sql/schema.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\(.*?ENGINE=InnoDB[^;]*;/s', $schema, $creates, PREG_SET_ORDER);
foreach ($creates as $c) {
    if (!table_exists($c[1])) {
        $steps[] = ['label' => "Crear tabla {$c[1]}", 'sql' => rtrim($c[0], ';')];
        $willExist[$c[1]] = true;
    }
}
$exists = function (string $table) use ($willExist): bool {
    return isset($willExist[$table]) || table_exists($table);
};

// 2) Columnas que pudieron faltar en tablas creadas antes de cada cambio.
$columns = [
    ['users', 'name', 'VARCHAR(255) NULL'],
    ['users', 'is_admin', 'TINYINT(1) NOT NULL DEFAULT 0'],
    ['login_codes', 'ip', 'VARCHAR(45) NULL'],
    ['accounts', 'notes', 'TEXT NULL'],
    ['accounts', 'code', 'VARCHAR(64) NULL'],
    ['accounts', 'start_date', 'DATE NULL'],
    ['accounts', 'next_report_date', 'DATE NULL'],
    ['accounts', 'last_report_date', 'DATE NULL'],
    ['accounts', 'last_report_url', 'VARCHAR(500) NULL'],
    ['accounts', 'high_water_mark', 'DECIMAL(20,2) NULL'],
    ['accounts', 'performance_fee', 'DECIMAL(5,2) NULL'],
    ['accounts', 'commercial_id', 'INT UNSIGNED NULL'],
];
foreach ($columns as [$table, $column, $ddl]) {
    if (isset($willExist[$table]) || !table_exists($table)) {
        continue; // recién creada con todas sus columnas (o no existe: ya la cubre el paso 1)
    }
    if (!column_info($table, $column)) {
        $steps[] = ['label' => "Agregar columna $table.$column", 'sql' => "ALTER TABLE $table ADD COLUMN $column $ddl"];
    }
}

// 3) Índice y clave foránea agregados por migración.
if (table_exists('login_codes') && !index_exists('login_codes', 'idx_ip')) {
    $steps[] = ['label' => 'Agregar índice login_codes.idx_ip', 'sql' => 'ALTER TABLE login_codes ADD INDEX idx_ip (ip)'];
}
if (table_exists('accounts') && !isset($willExist['accounts']) && !has_foreign_key('accounts', 'commercial_id')) {
    $steps[] = [
        'label' => 'Vincular accounts.commercial_id con commercials',
        'sql' => 'ALTER TABLE accounts ADD FOREIGN KEY (commercial_id) REFERENCES commercials(id) ON DELETE SET NULL',
    ];
}

// 4) La dirección vieja de accounts pasa a ser opcional, y se copia a account_addresses
//    para las cuentas que todavía no tienen ninguna dirección cargada allá.
$addressColumn = table_exists('accounts') ? column_info('accounts', 'address') : null;
if ($addressColumn && $addressColumn['IS_NULLABLE'] === 'NO') {
    $steps[] = ['label' => 'Hacer opcional accounts.address', 'sql' => 'ALTER TABLE accounts MODIFY address CHAR(42) NULL'];
}
if ($addressColumn && $exists('account_addresses')) {
    $pending = table_exists('account_addresses')
        ? q('SELECT COUNT(*) AS c FROM accounts a WHERE a.address IS NOT NULL AND NOT EXISTS (SELECT 1 FROM account_addresses x WHERE x.account_id = a.id)')[0]['c']
        : q('SELECT COUNT(*) AS c FROM accounts WHERE address IS NOT NULL')[0]['c'];
    if ((int) $pending > 0) {
        $steps[] = [
            'label' => "Copiar la dirección de $pending cuenta(s) a account_addresses",
            'sql' => 'INSERT IGNORE INTO account_addresses (account_id, address) SELECT a.id, a.address FROM accounts a WHERE a.address IS NOT NULL AND NOT EXISTS (SELECT 1 FROM account_addresses x WHERE x.account_id = a.id)',
        ];
    }
}

// 5) La nota vieja de cada cuenta (accounts.notes) pasa a account_notes, que admite varias.
if ($exists('account_notes') && table_exists('accounts') && column_info('accounts', 'notes')) {
    $where = "a.notes IS NOT NULL AND a.notes <> ''" . (table_exists('account_notes')
        ? ' AND NOT EXISTS (SELECT 1 FROM account_notes n WHERE n.account_id = a.id)'
        : '');
    $pending = (int) q("SELECT COUNT(*) AS c FROM accounts a WHERE $where")[0]['c'];
    if ($pending > 0) {
        $steps[] = [
            'label' => "Copiar la nota de $pending cuenta(s) a account_notes",
            'sql' => "INSERT INTO account_notes (account_id, body, created_at) SELECT a.id, a.notes, a.created_at FROM accounts a WHERE $where",
        ];
    }
}

// Diagnóstico para errores de clave foránea (1215): motor y tipo de las columnas involucradas.
$diagnostics = [];
if (table_exists('accounts') && table_exists('commercials')) {
    foreach (q("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('accounts', 'commercials', 'account_addresses')") as $t) {
        $diagnostics[] = "Tabla {$t['TABLE_NAME']}: motor {$t['ENGINE']}, collation {$t['TABLE_COLLATION']}";
    }
    foreach ([['accounts', 'id'], ['accounts', 'commercial_id'], ['commercials', 'id'], ['account_addresses', 'account_id']] as [$t, $c]) {
        $r = q('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
        $diagnostics[] = "Columna $t.$c: " . ($r ? $r[0]['COLUMN_TYPE'] : 'no existe');
    }
}

$results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $results = [];
    foreach ($steps as $step) {
        try {
            $pdo->exec($step['sql']);
            $results[] = [$step['label'], null];
        } catch (Throwable $e) {
            $results[] = [$step['label'], $e->getMessage()];
            if (strpos($e->getMessage(), '1215') !== false) {
                // InnoDB guarda el motivo detallado del último error de clave foránea (si el usuario de MySQL tiene permiso PROCESS).
                try {
                    $status = $pdo->query('SHOW ENGINE INNODB STATUS')->fetch();
                    if ($status && preg_match('/LATEST FOREIGN KEY ERROR\s*-+\s*(.*?)\s*-{10,}/s', $status['Status'], $m)) {
                        $diagnostics[] = "InnoDB dice:\n" . trim($m[1]);
                    }
                } catch (Throwable $ignored) {
                    $diagnostics[] = 'No se pudo leer el estado de InnoDB (el usuario de MySQL no tiene permiso).';
                }
            }
            break; // no seguir: los pasos siguientes pueden depender de este
        }
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES);
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Migración — CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .card pre{background:#f4f1e8;border-radius:8px;padding:10px 12px;font-size:12px;overflow-x:auto;margin:6px 0 16px 0;white-space:pre-wrap;}
  .ok{color:#2a6b34;font-weight:600;}
  .fail{color:#a5322a;font-weight:600;}
  .step-label{font-weight:600;font-size:13.5px;}
</style>
</head>
<body>
  <?php $activePage = 'settings'; include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <h1>Migración de la base</h1>

    <?php if ($results !== null): ?>
      <div class="card">
        <h2>Resultado</h2>
        <?php foreach ($results as [$label, $err]): ?>
          <div><?= $err === null ? '<span class="ok">✔</span>' : '<span class="fail">✘</span>' ?> <?= h($label) ?></div>
          <?php if ($err !== null): ?><div class="error"><?= h($err) ?></div><?php endif; ?>
        <?php endforeach; ?>
        <?php if (!empty($diagnostics)): ?>
          <h2 style="margin-top:16px;">Diagnóstico</h2>
          <pre><?= h(implode("\n", $diagnostics)) ?></pre>
        <?php endif; ?>
        <p style="margin-top:14px;">Recargá esta página: si todo salió bien, dice que la base está al día.</p>
      </div>
    <?php elseif (empty($steps)): ?>
      <div class="card"><h2>La base ya está al día.</h2><p>No hay nada para aplicar. Podés borrar <code>migrate.php</code> del servidor.</p></div>
    <?php else: ?>
      <div class="card">
        <h2>Esto es lo que falta (<?= count($steps) ?> cambio<?= count($steps) === 1 ? '' : 's' ?>)</h2>
        <p style="font-size:13px;">Solo crea tablas, agrega columnas o índices y copia direcciones. No borra ni modifica tus cuentas, wallets ni usuarios.</p>
        <?php foreach ($steps as $s): ?>
          <div class="step-label"><?= h($s['label']) ?></div>
          <pre><?= h($s['sql']) ?></pre>
        <?php endforeach; ?>
        <?php if (!empty($diagnostics)): ?>
          <div class="step-label">Diagnóstico (motor y tipos de las tablas involucradas)</div>
          <pre><?= h(implode("\n", $diagnostics)) ?></pre>
        <?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <button type="submit">Aplicar estos cambios</button>
        </form>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
