<?php

require_once __DIR__ . '/db.php';

const EVM_ADDRESS_PATTERN = '/^0x[a-fA-F0-9]{40}$/';

/** Direcciones de una cuenta, en el orden en que se cargaron. */
function account_addresses(int $accountId): array
{
    $stmt = db()->prepare('SELECT * FROM account_addresses WHERE account_id = ? ORDER BY id');
    $stmt->execute([$accountId]);
    return $stmt->fetchAll();
}

/**
 * Direcciones de varias cuentas en una sola consulta.
 * @param int[] $accountIds
 * @return array<int, array> account_id => lista de direcciones
 */
function addresses_by_account(array $accountIds): array
{
    if (!$accountIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($accountIds), '?'));
    $stmt = db()->prepare("SELECT * FROM account_addresses WHERE account_id IN ($in) ORDER BY id");
    $stmt->execute(array_values($accountIds));

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $grouped[(int) $row['account_id']][] = $row;
    }
    return $grouped;
}

/**
 * Agrega una dirección a una cuenta. Devuelve null si salió bien, o el
 * mensaje de error para mostrar en pantalla.
 */
function add_account_address(int $accountId, string $address, string $label): ?string
{
    $address = trim($address);
    if (!preg_match(EVM_ADDRESS_PATTERN, $address)) {
        return 'La dirección no es válida (0x + 40 caracteres hex).';
    }
    $label = trim($label);

    try {
        $stmt = db()->prepare('INSERT INTO account_addresses (account_id, address, label) VALUES (?, ?, ?)');
        $stmt->execute([$accountId, strtolower($address), $label !== '' ? $label : null]);
    } catch (PDOException $e) {
        return $e->getCode() === '23000' ? 'Esa dirección ya está cargada en una cuenta.' : 'Error: ' . $e->getMessage();
    }
    return null;
}
