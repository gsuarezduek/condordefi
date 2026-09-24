<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/snapshots.php';
app_session_start();
require_admin();
csrf_check();

header('Content-Type: application/json; charset=utf-8');

// Ethereum con muchos tokens puede tardar más que el límite por defecto.
@set_time_limit(180);
ignore_user_abort(true);

$addressId = (int) ($_POST['address_id'] ?? 0);
$chain = (string) ($_POST['chain'] ?? '');

$stmt = db()->prepare('SELECT * FROM account_addresses WHERE id = ?');
$stmt->execute([$addressId]);
$address = $stmt->fetch();

if (!$address || !isset(CHAINS[$chain])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Dirección o red inexistente.']);
    exit;
}

$error = refresh_snapshot($addressId, $chain, $address['address']);

echo json_encode(['ok' => $error === null, 'message' => $error]);
