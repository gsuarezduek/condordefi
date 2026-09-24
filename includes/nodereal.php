<?php

require_once __DIR__ . '/tokens.php';

const NODEREAL_URLS = [
    'bsc' => 'https://bsc-mainnet.nodereal.io/v1/',
    'eth' => 'https://eth-mainnet.nodereal.io/v1/',
];
const NODEREAL_MAX_BLOCK_RANGE = 1900000; // el límite de la API es 2.000.000 por consulta
const NODEREAL_ACTIVITY_MAX_WINDOWS = 4;
const NODEREAL_BATCH_SIZE = 50; // llamadas por petición HTTP en un batch

/** POST JSON-RPC a NodeReal (una llamada o un batch). Devuelve el JSON decodificado. */
function nodereal_post(string $chain, array $payload): array
{
    $config = require __DIR__ . '/../config.php';

    $ch = curl_init(NODEREAL_URLS[$chain] . $config['nodereal']['api_key']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);

    if ($response === false) {
        throw new RuntimeException('Error de red llamando a NodeReal: ' . curl_error($ch));
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('NodeReal devolvió una respuesta inválida: ' . substr((string) $response, 0, 300));
    }
    return $data;
}

/**
 * Llamada JSON-RPC a NodeReal MegaNode. Devuelve el campo `result`.
 * Sin tipo de retorno `mixed`: mantiene compatibilidad con PHP 7.x.
 */
function nodereal_rpc(string $method, array $params, string $chain = 'bsc')
{
    $data = nodereal_post($chain, ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1]);

    if (isset($data['error'])) {
        throw new RuntimeException('NodeReal API (' . $method . '): ' . ($data['error']['message'] ?? json_encode($data['error'])));
    }

    return $data['result'] ?? null;
}

/**
 * Varias llamadas eth_call de solo lectura, en pocas peticiones HTTP.
 * @param array<int, array{0: string, 1: string}> $calls [contrato, datos hex]
 * @return array<int, ?string> resultado hex de cada llamada, en el mismo orden (null si revirtió)
 */
function nodereal_eth_call_batch(string $chain, array $calls): array
{
    $calls = array_values($calls);
    $results = array_fill(0, count($calls), null);

    foreach (array_chunk($calls, NODEREAL_BATCH_SIZE, true) as $chunk) {
        $payload = [];
        foreach ($chunk as $i => $call) {
            $payload[] = ['jsonrpc' => '2.0', 'id' => $i, 'method' => 'eth_call', 'params' => [['to' => $call[0], 'data' => $call[1]], 'latest']];
        }
        foreach (nodereal_post($chain, $payload) as $item) {
            if (isset($item['id'], $item['result']) && $item['result'] !== '0x') {
                $results[$item['id']] = $item['result'];
            }
        }
    }

    return $results;
}

function nodereal_bnb_balance(string $address): float
{
    $hex = nodereal_rpc('eth_getBalance', [$address, 'latest']);
    return hexdec($hex) / 1e18;
}

/**
 * Tokens BEP20 que tiene la wallet hoy, separados en legítimos y sospechosos
 * (spam). Devuelve ['tokens' => [...], 'hidden' => int].
 */
function nodereal_token_holdings(string $address): array
{
    $result = nodereal_rpc('nr_getTokenHoldings', [$address, '0x1', '0x64']);

    $tokens = [];
    $hidden = 0;
    foreach ($result['details'] ?? [] as $t) {
        $decimals = (int) hexdec($t['tokenDecimals'] ?? '0x0');
        $amount = hexdec($t['tokenBalance']) / (10 ** $decimals);
        if ($amount <= 0) {
            continue;
        }
        if (is_spam_token($t['tokenName'] ?? '', $t['tokenSymbol'] ?? '')) {
            $hidden++;
            continue;
        }
        $tokens[] = [
            'contract' => $t['tokenAddress'],
            'symbol' => $t['tokenSymbol'],
            'name' => $t['tokenName'],
            'amount' => $amount,
        ];
    }

    usort($tokens, fn ($a, $b) => $b['amount'] <=> $a['amount']);
    return ['tokens' => $tokens, 'hidden' => $hidden];
}

/**
 * Últimos movimientos (BNB + tokens BEP20). La API limita cada consulta a
 * ~2M bloques, así que se recorren ventanas hacia atrás hasta juntar
 * suficientes eventos o agotar NODEREAL_ACTIVITY_MAX_WINDOWS.
 */
function nodereal_recent_activity(string $address, int $limit = 20): array
{
    $latest = (int) hexdec(nodereal_rpc('eth_blockNumber', []));
    $addressLower = strtolower($address);
    $events = [];

    for ($w = 0; $w < NODEREAL_ACTIVITY_MAX_WINDOWS && count($events) < $limit; $w++) {
        $to = $latest - $w * NODEREAL_MAX_BLOCK_RANGE;
        $from = max(0, $to - NODEREAL_MAX_BLOCK_RANGE);
        if ($to <= 0) {
            break;
        }

        foreach (['toAddress', 'fromAddress'] as $side) {
            // El indexador de transferencias puede ir unos bloques atrás del nodo
            // ("blockNum not reached"): se reintenta un poco más atrás.
            for ($attempt = 0;; $attempt++) {
                try {
                    $result = nodereal_rpc('nr_getAssetTransfers', [[
                        'category' => ['external', '20'],
                        'fromBlock' => '0x' . dechex($from),
                        'toBlock' => '0x' . dechex($to),
                        $side => $address,
                        'order' => 'desc',
                        'excludeZeroValue' => true,
                        'maxCount' => '0x' . dechex($limit),
                    ]]);
                    break;
                } catch (RuntimeException $e) {
                    if ($attempt >= 3 || $w > 0 || !str_contains($e->getMessage(), 'not reached')) {
                        throw $e;
                    }
                    $to -= 200;
                }
            }

            foreach ($result['transfers'] ?? [] as $t) {
                $isNative = $t['category'] === 'external';
                $value = hexdec($t['value']);
                $decimals = $isNative ? 18 : (int) hexdec($t['decimal'] ?? '0x12');
                $amount = $value / (10 ** $decimals);
                $symbol = $isNative ? 'BNB' : (string) ($t['asset'] ?? '');

                // Ruido típico: llamadas a contratos sin valor y tokens de spam,
                // incluidos los que usan solo caracteres invisibles como símbolo.
                $visibleSymbol = preg_replace('/[\p{Z}\p{C}]+/u', '', $symbol);
                if ($amount < MIN_ACTIVITY_AMOUNT || $visibleSymbol === '' || $visibleSymbol === null) {
                    continue;
                }
                if (!$isNative && is_spam_token((string) ($t['name'] ?? ''), $symbol)) {
                    continue;
                }

                $events[$t['hash'] . ':' . ($t['logIndex'] ?? 0) . ':' . $side] = [
                    'hash' => $t['hash'],
                    'timestamp' => (int) $t['blockTimeStamp'],
                    'direction' => strtolower($t['to']) === $addressLower ? 'in' : 'out',
                    'amount' => $amount,
                    'symbol' => $symbol,
                    'contract' => $isNative ? null : $t['contractAddress'],
                ];
            }
        }
    }

    $events = array_values($events);
    usort($events, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);
    return array_slice($events, 0, $limit);
}
