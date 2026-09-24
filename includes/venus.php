<?php

require_once __DIR__ . '/aave.php';

// Comptroller del pool principal (Core Pool) de Venus por red. Los pools
// aislados de BSC no están cubiertos.
const VENUS_COMPTROLLERS = [
    'bsc' => '0xfD36E2c2a6789Db23113685031d7F16329158384',
    'eth' => '0x687a01ecF6d3907658f7A7c714749fAC32336D1B',
];

const VENUS_NATIVE_SYMBOL = ['bsc' => 'BNB', 'eth' => 'ETH'];

const VENUS_SEL_ALL_MARKETS = '0xb0772d0b';      // getAllMarkets()
const VENUS_SEL_ORACLE = '0x7dc0d1d0';           // oracle()
const VENUS_SEL_ASSETS_IN = '0xabfceffc';        // getAssetsIn(address)
const VENUS_SEL_MARKETS = '0x8e8f294b';          // markets(address)
const VENUS_SEL_ACCOUNT_SNAPSHOT = '0xc37f68e2'; // getAccountSnapshot(address)
const VENUS_SEL_UNDERLYING = '0x6f307dc3';       // underlying()
const VENUS_SEL_UNDERLYING_PRICE = '0xfc57d4df'; // getUnderlyingPrice(address)

/**
 * Posición de una dirección en Venus (pool principal) en una red: qué tiene
 * depositado, qué debe y su Health Rate, leído de los contratos con los
 * precios del oráculo de Venus. Misma forma que aave_position(). Devuelve
 * null si la red no tiene Venus configurado o la dirección no tiene posición.
 * 'token_contracts' lista los vTokens: son recibos del depósito y no deben
 * contarse otra vez como saldo de wallet.
 * El Health Rate usa el umbral de liquidación de cada mercado, contando como
 * colateral solo los mercados que la dirección activó (getAssetsIn).
 */
function venus_position(string $chain, string $address): ?array
{
    if (!isset(VENUS_COMPTROLLERS[$chain])) {
        return null;
    }
    $comptroller = VENUS_COMPTROLLERS[$chain];
    $user = abi_address($address);

    // 1) Todos los mercados (vTokens), el oráculo y los mercados activados como colateral.
    [$marketsHex, $oracleHex, $enteredHex] = nodereal_eth_call_batch($chain, [
        [$comptroller, VENUS_SEL_ALL_MARKETS],
        [$comptroller, VENUS_SEL_ORACLE],
        [$comptroller, VENUS_SEL_ASSETS_IN . $user],
    ]);
    $oracle = abi_word_address($oracleHex);
    $marketCount = (int) (abi_words($marketsHex)[1] ?? 0);
    if ($oracle === null || $marketCount === 0) {
        throw new RuntimeException('Venus: no se pudo leer la lista de mercados.');
    }
    $markets = [];
    for ($i = 0; $i < $marketCount; $i++) {
        $markets[] = strtolower(abi_word_address($marketsHex, 2 + $i));
    }
    $entered = [];
    $enteredCount = (int) (abi_words($enteredHex)[1] ?? 0);
    for ($i = 0; $i < $enteredCount; $i++) {
        $entered[strtolower(abi_word_address($enteredHex, 2 + $i))] = true;
    }

    // 2) Saldo de la dirección en cada mercado.
    $calls = [];
    foreach ($markets as $vToken) {
        $calls[] = [$vToken, VENUS_SEL_ACCOUNT_SNAPSHOT . $user];
    }
    $active = [];
    foreach (nodereal_eth_call_batch($chain, $calls) as $i => $snapshot) {
        $w = abi_words($snapshot); // [error, vTokenBalance, borrowBalance, exchangeRate]
        if (count($w) < 4 || $w[0] != 0 || ($w[1] == 0 && $w[2] == 0)) {
            continue;
        }
        $active[$markets[$i]] = ['vtokens' => $w[1], 'borrow' => $w[2], 'rate' => $w[3]];
    }
    $receipts = array_fill_keys($markets, true);
    if (!$active) {
        return null;
    }

    // 3) De los mercados con saldo: token subyacente, precio y parámetros de riesgo.
    $calls = [];
    foreach (array_keys($active) as $vToken) {
        $calls[] = [$vToken, VENUS_SEL_UNDERLYING];
        $calls[] = [$oracle, VENUS_SEL_UNDERLYING_PRICE . abi_address($vToken)];
        $calls[] = [$comptroller, VENUS_SEL_MARKETS . abi_address($vToken)];
    }
    $results = nodereal_eth_call_batch($chain, $calls);
    $underlyings = [];
    foreach (array_keys($active) as $i => $vToken) {
        [$underlyingHex, $priceHex, $marketHex] = array_slice($results, $i * 3, 3);
        $marketWords = abi_words($marketHex);
        $active[$vToken]['underlying'] = $underlyingHex !== null ? abi_word_address($underlyingHex) : null; // null: mercado del token nativo (BNB / ETH)
        $active[$vToken]['price_raw'] = abi_words($priceHex)[0] ?? 0;
        // Umbral de liquidación (4.ª palabra); si el contrato no lo informa, factor de colateral.
        $threshold = $marketWords[3] ?? 0;
        $active[$vToken]['threshold'] = ($threshold > 0 ? $threshold : ($marketWords[1] ?? 0)) / 1e18;
        if ($active[$vToken]['underlying'] !== null) {
            $underlyings[$vToken] = $active[$vToken]['underlying'];
        }
    }

    // 4) Decimales y símbolo de cada subyacente.
    $calls = [];
    foreach ($underlyings as $underlying) {
        $calls[] = [$underlying, AAVE_SEL_DECIMALS];
        $calls[] = [$underlying, AAVE_SEL_SYMBOL];
    }
    $results = nodereal_eth_call_batch($chain, $calls);
    $meta = [];
    foreach (array_keys($underlyings) as $i => $vToken) {
        $meta[$vToken] = [
            'decimals' => (int) (abi_words($results[$i * 2])[0] ?? 18),
            'symbol' => abi_string($results[$i * 2 + 1]),
        ];
    }

    $supplied = [];
    $borrowed = [];
    $weightedCollateral = 0.0;
    foreach ($active as $vToken => $m) {
        $decimals = $meta[$vToken]['decimals'] ?? 18;
        $symbol = $meta[$vToken]['symbol'] ?? VENUS_NATIVE_SYMBOL[$chain];
        // El oráculo devuelve el precio escalado a 1e(36 - decimales).
        $price = $m['price_raw'] / 10 ** (36 - $decimals);
        $row = ['symbol' => $symbol, 'contract' => $m['underlying'], 'price' => $price];

        $suppliedAmount = $m['vtokens'] * $m['rate'] / 1e18 / 10 ** $decimals;
        $borrowedAmount = $m['borrow'] / 10 ** $decimals;
        if ($suppliedAmount > 0) {
            $supplied[] = $row + ['amount' => $suppliedAmount, 'usd' => $suppliedAmount * $price];
            if (isset($entered[$vToken])) {
                $weightedCollateral += $suppliedAmount * $price * $m['threshold'];
            }
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
        'id' => 'venus',
        'name' => 'Venus',
        'health_rate' => $borrowedUsd > 0 ? $weightedCollateral / $borrowedUsd : null,
        'supplied' => $supplied,
        'borrowed' => $borrowed,
        'supplied_usd' => $suppliedUsd,
        'borrowed_usd' => $borrowedUsd,
        'net_usd' => $suppliedUsd - $borrowedUsd,
        'token_contracts' => array_keys($receipts),
    ];
}
