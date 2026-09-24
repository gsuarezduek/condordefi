<?php

const LLAMA_PRICES_URL = 'https://coins.llama.fi/prices/current/';
const LLAMA_LOGO_URL = 'https://token-icons.llamao.fi/icons/tokens/';
const LLAMA_BATCH_SIZE = 40;

// Por red: nombre en DefiLlama, id de cadena (para los logos) y clave del
// token nativo.
const PRICE_CHAINS = [
    'eth' => ['llama' => 'ethereum', 'chain_id' => 1, 'native_key' => 'coingecko:ethereum'],
    'bsc' => ['llama' => 'bsc', 'chain_id' => 56, 'native_key' => 'coingecko:binancecoin'],
];

/**
 * Precios en USD desde DefiLlama (gratis, sin key). Devuelve clave => precio,
 * solo para las claves que DefiLlama conoce (los tokens de spam no tienen).
 * Lanza excepción si la API falla.
 * @param string[] $keys p. ej. 'bsc:0x…' o 'coingecko:binancecoin'
 * @return array<string, float>
 */
function llama_prices(array $keys): array
{
    $prices = [];
    foreach (array_chunk(array_values(array_unique($keys)), LLAMA_BATCH_SIZE) as $chunk) {
        $ch = curl_init(LLAMA_PRICES_URL . implode(',', $chunk));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $response = curl_exec($ch);

        if ($response === false) {
            throw new RuntimeException('Error de red llamando a DefiLlama: ' . curl_error($ch));
        }
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['coins'])) {
            throw new RuntimeException('DefiLlama devolvió una respuesta inválida: ' . substr((string) $response, 0, 200));
        }
        foreach ($data['coins'] as $key => $coin) {
            if (isset($coin['price']) && is_numeric($coin['price'])) {
                $prices[$key] = (float) $coin['price'];
            }
        }
    }
    return $prices;
}

/**
 * Precios de una lista de tokens de una red.
 * @param string[] $contracts
 * @return array<string, float> contrato en minúsculas => precio USD
 */
function token_prices(string $chain, array $contracts): array
{
    $prefix = PRICE_CHAINS[$chain]['llama'] . ':';
    $keys = [];
    foreach ($contracts as $contract) {
        $keys[] = $prefix . strtolower($contract);
    }

    $byContract = [];
    foreach (llama_prices($keys) as $key => $price) {
        $byContract[substr($key, strlen($prefix))] = $price;
    }
    return $byContract;
}

/** Precio del token nativo (ETH / BNB) o null si no se pudo obtener. */
function native_price(string $chain): ?float
{
    $key = PRICE_CHAINS[$chain]['native_key'];
    return llama_prices([$key])[$key] ?? null;
}

/** URL del logo de un token (contrato en minúsculas, o null para el nativo). Puede dar 404: la pantalla muestra una letra. */
function token_logo_url(string $chain, ?string $contract): string
{
    $contract = $contract !== null ? strtolower($contract) : '0x0000000000000000000000000000000000000000';
    return LLAMA_LOGO_URL . PRICE_CHAINS[$chain]['chain_id'] . '/' . $contract . '?h=48&w=48';
}
