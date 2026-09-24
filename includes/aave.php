<?php

require_once __DIR__ . '/nodereal.php';

// Pool de Aave V3 (mercado principal) por red. El resto de las direcciones
// (data provider, oráculo, reservas) se descubren leyendo el contrato.
const AAVE_V3_POOLS = [
    'bsc' => '0x6807dc923806fE8Fd134338EABCA509979a7e0cB',
    'eth' => '0x87870Bca3F3fD6335C3F4ce8392D69350B4fA4E2',
];

// Selectores de funciones (primeros 4 bytes del keccak256 de la firma).
const AAVE_SEL_USER_ACCOUNT_DATA = '0xbf92857c';   // getUserAccountData(address)
const AAVE_SEL_ADDRESSES_PROVIDER = '0x0542975c';  // ADDRESSES_PROVIDER()
const AAVE_SEL_RESERVES_LIST = '0xd1946dbc';       // getReservesList()
const AAVE_SEL_DATA_PROVIDER = '0xe860accb';       // getPoolDataProvider()
const AAVE_SEL_PRICE_ORACLE = '0xfca513a8';        // getPriceOracle()
const AAVE_SEL_USER_RESERVE_DATA = '0x28dd2d01';   // getUserReserveData(address,address)
const AAVE_SEL_RESERVE_TOKENS = '0xd2493b6c';      // getReserveTokensAddresses(address)
const AAVE_SEL_ASSET_PRICE = '0xb3596f07';         // getAssetPrice(address)
const AAVE_SEL_DECIMALS = '0x313ce567';            // decimals()
const AAVE_SEL_SYMBOL = '0x95d89b41';              // symbol()

/** Dirección ABI-codificada (32 bytes) para usar como argumento. */
function abi_address(string $address): string
{
    return str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
}

/**
 * Palabras de 32 bytes de una respuesta eth_call como enteros (float: sirve
 * para mostrar, no para aritmética exacta).
 * @return float[]
 */
function abi_words(?string $hex): array
{
    if ($hex === null || strlen($hex) < 66) {
        return [];
    }
    $words = [];
    foreach (str_split(substr($hex, 2), 64) as $chunk) {
        $words[] = hexdec($chunk);
    }
    return $words;
}

/** Dirección contenida en una palabra ABI (últimos 20 bytes). */
function abi_word_address(?string $hex, int $index = 0): ?string
{
    if ($hex === null || strlen($hex) < 2 + 64 * ($index + 1)) {
        return null;
    }
    return '0x' . substr($hex, 2 + 64 * $index + 24, 40);
}

/** Texto de una respuesta symbol() (string dinámico o bytes32). */
function abi_string(?string $hex): string
{
    if ($hex === null || strlen($hex) <= 2) {
        return '?';
    }
    $bytes = hex2bin(substr($hex, 2));
    if ($bytes === false) {
        return '?';
    }
    if (strlen($bytes) === 32) {
        return rtrim($bytes, "\0");
    }
    $offset = (int) hexdec(bin2hex(substr($bytes, 0, 32)));
    $length = (int) hexdec(bin2hex(substr($bytes, $offset, 32)));
    return substr($bytes, $offset + 32, $length);
}

/**
 * Posición de una dirección en Aave V3 en una red: qué tiene depositado
 * (supplied), qué tiene prestado (borrowed) y su Health Rate, leído directo
 * de los contratos (valores en USD según el oráculo de Aave).
 * Devuelve null si la red no tiene Aave configurado o la dirección no tiene
 * posición. 'token_contracts' lista los contratos de aTokens y deuda: son
 * recibos de la posición y no deben contarse otra vez como saldo de wallet.
 */
function aave_position(string $chain, string $address): ?array
{
    if (!isset(AAVE_V3_POOLS[$chain])) {
        return null;
    }
    $pool = AAVE_V3_POOLS[$chain];
    $user = abi_address($address);

    // 1) Resumen de la cuenta, proveedor de direcciones y lista de reservas.
    [$account, $provider, $reservesList] = nodereal_eth_call_batch($chain, [
        [$pool, AAVE_SEL_USER_ACCOUNT_DATA . $user],
        [$pool, AAVE_SEL_ADDRESSES_PROVIDER],
        [$pool, AAVE_SEL_RESERVES_LIST],
    ]);
    $accountWords = abi_words($account);
    if (count($accountWords) < 6 || ($accountWords[0] == 0 && $accountWords[1] == 0)) {
        return null; // sin colateral ni deuda: no usa Aave
    }
    $debtUsd = $accountWords[1] / 1e8;
    $healthRate = $debtUsd > 0 ? $accountWords[5] / 1e18 : null;

    $providerAddress = abi_word_address($provider);
    $listWords = abi_words($reservesList);
    $count = isset($listWords[1]) ? (int) $listWords[1] : 0;
    if ($providerAddress === null || $count === 0) {
        throw new RuntimeException('Aave: no se pudo leer la lista de reservas.');
    }
    $reserves = [];
    for ($i = 0; $i < $count; $i++) {
        $reserves[] = abi_word_address($reservesList, 2 + $i);
    }

    // 2) Contratos auxiliares que publica el proveedor de direcciones.
    [$dataProviderHex, $oracleHex] = nodereal_eth_call_batch($chain, [
        [$providerAddress, AAVE_SEL_DATA_PROVIDER],
        [$providerAddress, AAVE_SEL_PRICE_ORACLE],
    ]);
    $dataProvider = abi_word_address($dataProviderHex);
    $oracle = abi_word_address($oracleHex);
    if ($dataProvider === null || $oracle === null) {
        throw new RuntimeException('Aave: no se pudieron leer el data provider y el oráculo.');
    }

    // 3) Por cada reserva: saldo del usuario, precio, decimales, símbolo y tokens recibo.
    $calls = [];
    foreach ($reserves as $reserve) {
        $calls[] = [$dataProvider, AAVE_SEL_USER_RESERVE_DATA . abi_address($reserve) . $user];
        $calls[] = [$oracle, AAVE_SEL_ASSET_PRICE . abi_address($reserve)];
        $calls[] = [$reserve, AAVE_SEL_DECIMALS];
        $calls[] = [$reserve, AAVE_SEL_SYMBOL];
        $calls[] = [$dataProvider, AAVE_SEL_RESERVE_TOKENS . abi_address($reserve)];
    }
    $results = nodereal_eth_call_batch($chain, $calls);

    $supplied = [];
    $borrowed = [];
    $receiptTokens = [];
    foreach ($reserves as $i => $reserve) {
        [$userData, $priceHex, $decimalsHex, $symbolHex, $tokensHex] = array_slice($results, $i * 5, 5);
        $words = abi_words($userData);
        $decimals = (int) (abi_words($decimalsHex)[0] ?? 18);
        $price = (abi_words($priceHex)[0] ?? 0) / 1e8;
        $symbol = abi_string($symbolHex);

        foreach ([0, 1, 2] as $k) { // aToken, deuda estable, deuda variable
            $receipt = abi_word_address($tokensHex, $k);
            if ($receipt !== null) {
                $receiptTokens[strtolower($receipt)] = true;
            }
        }
        if (count($words) < 9) {
            continue;
        }

        $suppliedAmount = $words[0] / 10 ** $decimals;
        $borrowedAmount = ($words[1] + $words[2]) / 10 ** $decimals;
        $row = ['symbol' => $symbol, 'contract' => $reserve, 'price' => $price];
        if ($suppliedAmount > 0) {
            $supplied[] = $row + ['amount' => $suppliedAmount, 'usd' => $suppliedAmount * $price];
        }
        if ($borrowedAmount > 0) {
            $borrowed[] = $row + ['amount' => $borrowedAmount, 'usd' => $borrowedAmount * $price];
        }
    }

    usort($supplied, fn ($a, $b) => $b['usd'] <=> $a['usd']);
    usort($borrowed, fn ($a, $b) => $b['usd'] <=> $a['usd']);
    $suppliedUsd = array_sum(array_column($supplied, 'usd'));
    $borrowedUsd = array_sum(array_column($borrowed, 'usd'));

    return [
        'id' => 'aave-v3',
        'name' => 'Aave V3',
        'health_rate' => $healthRate,
        'supplied' => $supplied,
        'borrowed' => $borrowed,
        'supplied_usd' => $suppliedUsd,
        'borrowed_usd' => $borrowedUsd,
        'net_usd' => $suppliedUsd - $borrowedUsd,
        'token_contracts' => array_keys($receiptTokens),
    ];
}
