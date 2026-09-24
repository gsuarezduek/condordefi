<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/etherscan.php';
require_once __DIR__ . '/nodereal.php';
require_once __DIR__ . '/prices.php';
require_once __DIR__ . '/aave.php';
require_once __DIR__ . '/venus.php';
require_once __DIR__ . '/format.php';

const CHAINS = [
    'eth' => ['title' => 'Ethereum', 'symbol' => 'ETH', 'explorer' => 'https://etherscan.io/tx/'],
    'bsc' => ['title' => 'BSC', 'symbol' => 'BNB', 'explorer' => 'https://bscscan.com/tx/'],
];

// Movimientos que se guardan por dirección y red; la pantalla muestra menos
// porque después se descartan los de tokens ocultos por el admin.
const SNAPSHOT_ACTIVITY_LIMIT = 30;

// Versión del formato guardado en chain_snapshots.data. La pantalla pide
// volver a actualizar las fotos de versiones anteriores (sin precios ni posiciones).
const SNAPSHOT_VERSION = 2;

/**
 * Consulta las APIs y devuelve, para una dirección en una red: saldo nativo,
 * tokens de wallet con precio y valor en USD, posiciones en protocolos (Aave, Venus)
 * y movimientos. Es lento (varias llamadas): usarlo solo al actualizar.
 * Lanza excepción si alguna API falla: no se guarda una foto a medias, porque
 * los totales quedarían mal sin que se note.
 */
function fetch_chain_data(string $chain, string $address): array
{
    if ($chain === 'eth') {
        $holdings = etherscan_discover_token_balances($address);
        $native = etherscan_eth_balance($address);
        $activity = etherscan_recent_activity($address, SNAPSHOT_ACTIVITY_LIMIT);
    } else {
        $holdings = nodereal_token_holdings($address);
        $native = nodereal_bnb_balance($address);
        $activity = nodereal_recent_activity($address, SNAPSHOT_ACTIVITY_LIMIT);
    }

    $protocols = [];
    $receiptTokens = [];
    foreach (['aave_position', 'venus_position'] as $fetchPosition) {
        $position = $fetchPosition($chain, $address);
        if ($position !== null) {
            $receiptTokens += array_flip($position['token_contracts']);
            unset($position['token_contracts']);
            $protocols[] = $position;
        }
    }

    // Los tokens recibo (aTokens, deuda de Aave, vTokens de Venus) ya figuran dentro del protocolo.
    $tokens = array_values(array_filter(
        $holdings['tokens'],
        fn ($t) => !isset($receiptTokens[strtolower($t['contract'])])
    ));
    $prices = token_prices($chain, array_column($tokens, 'contract'));
    foreach ($tokens as &$token) {
        $price = $prices[strtolower($token['contract'])] ?? null;
        $token['price'] = $price;
        $token['usd'] = $price !== null ? $price * $token['amount'] : null;
    }
    unset($token);

    $nativePrice = native_price($chain);

    return [
        'v' => SNAPSHOT_VERSION,
        'native' => $native,
        'native_price' => $nativePrice,
        'native_usd' => $nativePrice !== null ? $native * $nativePrice : null,
        'tokens' => $tokens,
        'hidden' => $holdings['hidden'],
        'protocols' => $protocols,
        'activity' => $activity,
    ];
}

/**
 * Actualiza la foto guardada de una dirección en una red. Devuelve null si
 * salió bien o el mensaje de error. Si falla, se conservan los datos viejos.
 */
function refresh_snapshot(int $addressId, string $chain, string $address): ?string
{
    try {
        $data = fetch_chain_data($chain, $address);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar la respuesta de la API.');
        }
        $stmt = db()->prepare(
            'INSERT INTO chain_snapshots (address_id, chain, data, error, updated_at) VALUES (?, ?, ?, NULL, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), error = NULL, updated_at = VALUES(updated_at)'
        );
        $stmt->execute([$addressId, $chain, $json, date('Y-m-d H:i:s')]);
        return null;
    } catch (Throwable $e) {
        $message = mb_substr($e->getMessage(), 0, 500);
        $stmt = db()->prepare(
            'INSERT INTO chain_snapshots (address_id, chain, error) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE error = VALUES(error)'
        );
        $stmt->execute([$addressId, $chain, $message]);
        return $message;
    }
}

/**
 * Fotos guardadas de varias direcciones, con `data` ya decodificado.
 * @param int[] $addressIds
 * @return array<int, array<string, array>> address_id => chain => fila
 */
function load_snapshots(array $addressIds): array
{
    if (!$addressIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($addressIds), '?'));
    $stmt = db()->prepare("SELECT * FROM chain_snapshots WHERE address_id IN ($in)");
    $stmt->execute(array_values($addressIds));

    $snapshots = [];
    foreach ($stmt->fetchAll() as $row) {
        $row['data'] = $row['data'] !== null ? json_decode($row['data'], true) : null;
        $snapshots[(int) $row['address_id']][$row['chain']] = $row;
    }
    return $snapshots;
}
