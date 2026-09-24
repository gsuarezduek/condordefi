<?php

require_once __DIR__ . '/tokens.php';

const ETHERSCAN_BASE_URL = 'https://api.etherscan.io/v2/api';
const ETHERSCAN_ETH_CHAIN_ID = 1;

/**
 * Llama a la API V2 de Etherscan y devuelve el campo `result` ya decodificado.
 * Lanza RuntimeException si falla la red o la API responde con error real
 * (un status "0" con mensaje "No transactions found" no cuenta como error,
 * Etherscan lo usa para decir "sin resultados").
 * Sin tipo de retorno `mixed`: el hosting corre PHP 7.x, donde no existe.
 */
function etherscan_get(array $params)
{
    $config = require __DIR__ . '/../config.php';
    $params['apikey'] = $config['etherscan']['api_key'];

    $url = ETHERSCAN_BASE_URL . '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);

    if ($response === false) {
        $err = curl_error($ch);
        throw new RuntimeException("Error de red llamando a Etherscan: $err");
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('Etherscan devolvió una respuesta inválida: ' . substr((string) $response, 0, 300));
    }

    $noResults = ($data['message'] ?? '') === 'No transactions found';
    if (($data['status'] ?? null) === '0' && !$noResults) {
        $detail = is_array($data['result'] ?? null) ? json_encode($data['result']) : ($data['result'] ?? $data['message'] ?? 'error desconocido');
        throw new RuntimeException("Etherscan API: $detail");
    }

    return $data['result'] ?? [];
}

function etherscan_eth_balance(string $address): float
{
    $wei = etherscan_get([
        'chainid' => ETHERSCAN_ETH_CHAIN_ID,
        'module' => 'account',
        'action' => 'balance',
        'address' => $address,
        'tag' => 'latest',
    ]);
    return ((float) $wei) / 1e18;
}

function etherscan_token_transfers(string $address, int $limit = 1000): array
{
    return etherscan_get([
        'chainid' => ETHERSCAN_ETH_CHAIN_ID,
        'module' => 'account',
        'action' => 'tokentx',
        'address' => $address,
        'page' => 1,
        'offset' => $limit,
        'sort' => 'desc',
    ]);
}

function etherscan_token_balance(string $address, string $contract): float
{
    $raw = etherscan_get([
        'chainid' => ETHERSCAN_ETH_CHAIN_ID,
        'module' => 'account',
        'action' => 'tokenbalance',
        'contractaddress' => $contract,
        'address' => $address,
        'tag' => 'latest',
    ]);
    return (float) $raw;
}

/**
 * Descubre qué tokens ERC20 tocó la wallet (a partir del historial de
 * transferencias) y consulta el saldo actual de cada uno. Solo devuelve
 * los que tienen saldo > 0 hoy. Los tokens de spam (ver is_spam_token) se
 * descartan antes de consultar el saldo, para ahorrar llamadas.
 * Devuelve ['tokens' => [...], 'hidden' => int].
 */
function etherscan_discover_token_balances(string $address): array
{
    $transfers = etherscan_token_transfers($address);

    $tokens = [];
    $hidden = 0;
    foreach ($transfers as $t) {
        $contract = strtolower($t['contractAddress']);
        if (array_key_exists($contract, $tokens)) {
            continue;
        }
        if (is_spam_token($t['tokenName'], $t['tokenSymbol'])) {
            $tokens[$contract] = null;
            $hidden++;
            continue;
        }
        $tokens[$contract] = [
            'contract' => $t['contractAddress'],
            'symbol' => $t['tokenSymbol'],
            'name' => $t['tokenName'],
            'decimals' => (int) $t['tokenDecimal'],
        ];
    }

    $balances = [];
    foreach (array_filter($tokens) as $token) {
        $raw = etherscan_token_balance($address, $token['contract']);
        $amount = $raw / (10 ** max($token['decimals'], 0));
        if ($amount > 0) {
            $balances[] = $token + ['amount' => $amount];
        }
        usleep(210000); // ~4.7 req/s, debajo del límite free (5 req/s)
    }

    usort($balances, fn ($a, $b) => $b['amount'] <=> $a['amount']);
    return ['tokens' => $balances, 'hidden' => $hidden];
}

/** Últimos movimientos (ETH + tokens ERC20) mezclados y ordenados por fecha. */
function etherscan_recent_activity(string $address, int $limit = 20): array
{
    $normal = etherscan_get([
        'chainid' => ETHERSCAN_ETH_CHAIN_ID, 'module' => 'account', 'action' => 'txlist',
        'address' => $address, 'page' => 1, 'offset' => $limit, 'sort' => 'desc',
    ]);
    $tokenTx = etherscan_get([
        'chainid' => ETHERSCAN_ETH_CHAIN_ID, 'module' => 'account', 'action' => 'tokentx',
        'address' => $address, 'page' => 1, 'offset' => $limit, 'sort' => 'desc',
    ]);

    $events = [];
    foreach ($normal as $t) {
        $amount = ((float) $t['value']) / 1e18;
        if ($amount < MIN_ACTIVITY_AMOUNT) {
            continue; // llamadas a contratos sin ETH, o polvo
        }
        $events[] = [
            'hash' => $t['hash'],
            'timestamp' => (int) $t['timeStamp'],
            'direction' => strtolower($t['to']) === strtolower($address) ? 'in' : 'out',
            'amount' => $amount,
            'symbol' => 'ETH',
            'contract' => null,
        ];
    }
    foreach ($tokenTx as $t) {
        $amount = ((float) $t['value']) / (10 ** max((int) $t['tokenDecimal'], 0));
        if ($amount < MIN_ACTIVITY_AMOUNT || is_spam_token($t['tokenName'], $t['tokenSymbol'])) {
            continue;
        }
        $events[] = [
            'hash' => $t['hash'],
            'timestamp' => (int) $t['timeStamp'],
            'direction' => strtolower($t['to']) === strtolower($address) ? 'in' : 'out',
            'amount' => $amount,
            'symbol' => $t['tokenSymbol'],
            'contract' => $t['contractAddress'],
        ];
    }

    usort($events, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);
    return array_slice($events, 0, $limit);
}
