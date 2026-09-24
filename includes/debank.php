<?php

const DEBANK_BASE_URL = 'https://pro-openapi.debank.com';
const DEBANK_CHAINS = 'eth,bsc';

/**
 * Llama a un endpoint de la DeBank Cloud API y devuelve el JSON decodificado.
 * Lanza RuntimeException si falla la red o la API responde con error.
 */
function debank_get(string $path, array $params = []): array
{
    $config = require __DIR__ . '/../config.php';
    $accessKey = $config['debank']['access_key'];

    $url = DEBANK_BASE_URL . $path;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['AccessKey: ' . $accessKey, 'Accept: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);

    if ($response === false) {
        $err = curl_error($ch);
        throw new RuntimeException("Error de red llamando a DeBank ($path): $err");
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $data = json_decode($response, true);

    if ($status >= 400 || !is_array($data)) {
        $detail = is_array($data) ? ($data['error_msg'] ?? json_encode($data)) : substr((string) $response, 0, 300);
        throw new RuntimeException("DeBank API ($path) devolvió HTTP $status: $detail");
    }

    return $data;
}

/** @return array{0: array|null, 1: string|null} */
function debank_total_balance(string $address): array
{
    try {
        return [debank_get('/v1/user/total_balance', ['id' => $address]), null];
    } catch (Throwable $e) {
        return [null, $e->getMessage()];
    }
}

/** @return array{0: array|null, 1: string|null} */
function debank_token_list(string $address): array
{
    try {
        $tokens = debank_get('/v1/user/all_token_list', [
            'id' => $address,
            'chain_ids' => DEBANK_CHAINS,
            'is_all' => 'false',
        ]);
        usort($tokens, fn ($a, $b) => ($b['amount'] * $b['price']) <=> ($a['amount'] * $a['price']));
        return [$tokens, null];
    } catch (Throwable $e) {
        return [null, $e->getMessage()];
    }
}

/** @return array{0: array|null, 1: string|null} */
function debank_protocol_list(string $address): array
{
    try {
        $protocols = debank_get('/v1/user/all_complex_protocol_list', [
            'id' => $address,
            'chain_ids' => DEBANK_CHAINS,
        ]);
        usort($protocols, fn ($a, $b) => ($b['tvl'] ?? 0) <=> ($a['tvl'] ?? 0));
        return [$protocols, null];
    } catch (Throwable $e) {
        return [null, $e->getMessage()];
    }
}
