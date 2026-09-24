<?php
// Diagnóstico temporal. Borrar este archivo del servidor una vez
// que no lo necesites más: expone detalles internos aunque esté
// protegido por login de admin.
require_once __DIR__ . '/includes/auth.php';
app_session_start();
require_admin();

header('Content-Type: text/plain; charset=utf-8');

echo "=== Diagnóstico CondorDeFi ===\n\n";
echo "PHP version: " . PHP_VERSION . "\n\n";

echo "-- Config --\n";
try {
    $config = require __DIR__ . '/config.php';
    echo "config.php cargado OK\n";
    echo "DB host: {$config['db']['host']}\n";
    echo "DB name: {$config['db']['name']}\n";
    echo "DB user: {$config['db']['user']}\n";
    echo "Mail from: {$config['mail']['from']}\n\n";
} catch (Throwable $e) {
    echo 'ERROR cargando config.php: ' . $e->getMessage() . "\n";
    exit;
}

echo "-- Conexión a MySQL --\n";
try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = db();
    $row = $pdo->query('SELECT COUNT(*) AS c FROM users')->fetch();
    echo "Conexión OK. Usuarios en la tabla `users`: {$row['c']}\n\n";
} catch (Throwable $e) {
    echo 'ERROR de conexión/consulta: ' . $e->getMessage() . "\n\n";
}

echo "-- Esquema (tablas/columnas que espera el código) --\n";
$expected = [
    'users' => ['id', 'email', 'name', 'is_admin'],
    'accounts' => ['id', 'name', 'address', 'notes', 'code', 'start_date', 'next_report_date', 'last_report_date', 'last_report_url', 'high_water_mark', 'performance_fee', 'commercial_id'],
    'commercials' => ['id', 'name', 'wallet_address'],
    'commercial_payments' => ['id', 'commercial_id', 'account_id', 'amount', 'paid_at', 'tx_hash', 'notes'],
    'account_addresses' => ['id', 'account_id', 'address', 'label'],
    'account_notes' => ['id', 'account_id', 'body', 'created_at', 'updated_at'],
    'account_user' => ['account_id', 'user_id'],
    'ignored_tokens' => ['id', 'chain', 'contract', 'symbol', 'name'],
    'app_settings' => ['name', 'value', 'updated_at'],
    'chain_snapshots' => ['id', 'address_id', 'chain', 'data', 'error', 'updated_at'],
];
try {
    foreach ($expected as $table => $columns) {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $found = array_column($stmt->fetchAll(), 'COLUMN_NAME');
        if (!$found) {
            echo "FALTA la tabla `$table`\n";
            continue;
        }
        $missing = array_diff($columns, $found);
        echo $missing ? "`$table`: faltan columnas: " . implode(', ', $missing) . "\n" : "`$table`: OK\n";
    }
} catch (Throwable $e) {
    echo 'ERROR revisando el esquema: ' . $e->getMessage() . "\n";
}
echo "\n-- Archivos includes --\n";
foreach (['accounts.php', 'auth.php', 'db.php', 'etherscan.php', 'nodereal.php', 'tokens.php', 'snapshots.php', 'health.php', 'format.php', 'sidebar.php', 'account_info.php'] as $f) {
    echo $f . ': ' . (is_file(__DIR__ . '/includes/' . $f) ? 'OK' : 'FALTA') . "\n";
}
echo "\n";

echo "-- Envío de mail() de prueba (se manda al remitente configurado) --\n";
$to = $config['mail']['from'];
$headers = "From: {$config['mail']['from_name']} <{$config['mail']['from']}>\r\nContent-Type: text/plain; charset=utf-8";
$result = mail($to, 'Prueba CondorDeFi', 'Email de prueba enviado desde debug.php', $headers);
echo 'mail() devolvió: ' . var_export($result, true) . "\n";
echo "(esto solo confirma que el servidor ACEPTÓ el envío, no que haya llegado a destino — revisá spam)\n";
