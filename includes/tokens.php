<?php

require_once __DIR__ . '/db.php';

// Movimientos por debajo de este monto se ignoran (llamadas a contratos sin
// valor y transferencias de 0). Es deliberadamente bajo: sin precios no hay
// forma de distinguir polvo de un movimiento legítimo chico de BTC/ETH.
const MIN_ACTIVITY_AMOUNT = 0.000001;

/**
 * Detecta tokens de spam/phishing típicos: nombres o símbolos que contienen
 * URLs o dominios ("10BNB Airdrop at bep20.biz"). Es una heurística: no
 * atrapa todo, para el resto está la lista de tokens ocultos por el admin.
 */
function is_spam_token(string $name, string $symbol): bool
{
    return (bool) preg_match('#https?://|\.(com|io|org|app|net|biz|xyz|co|in|me|site|top|vip)\b|airdrop|claim#i', $name . ' ' . $symbol);
}

/**
 * Contratos ocultos por el admin en una red, como mapa contrato => true
 * (en minúsculas) para chequear con isset().
 * @return array<string, true>
 */
function ignored_contracts(string $chain): array
{
    $stmt = db()->prepare('SELECT contract FROM ignored_tokens WHERE chain = ?');
    $stmt->execute([$chain]);
    return array_fill_keys(array_map('strtolower', array_column($stmt->fetchAll(), 'contract')), true);
}

/** Todos los tokens ocultos, para la pantalla de gestión. */
function ignored_tokens_list(): array
{
    return db()->query('SELECT * FROM ignored_tokens ORDER BY created_at DESC')->fetchAll();
}

/** Oculta un token en todas las cuentas. Si ya estaba oculto no hace nada. */
function ignore_token(string $chain, string $contract, string $symbol, string $name): void
{
    if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $contract)) {
        return;
    }
    $stmt = db()->prepare('INSERT IGNORE INTO ignored_tokens (chain, contract, symbol, name) VALUES (?, ?, ?, ?)');
    $stmt->execute([$chain, strtolower($contract), mb_substr($symbol, 0, 255), mb_substr($name, 0, 255)]);
}

function unignore_token(int $id): void
{
    db()->prepare('DELETE FROM ignored_tokens WHERE id = ?')->execute([$id]);
}
