<?php

require_once __DIR__ . '/snapshots.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/tokens.php';
require_once __DIR__ . '/health.php';

// Tokens de wallet con menos valor que esto (o sin precio de mercado) no se
// muestran ni suman: casi siempre son polvo o spam.
const WALLET_MIN_USD = 1.0;

/**
 * Lo que se muestra de una red a partir de la foto guardada: filas de wallet
 * (nativo + tokens con precio y valor suficiente), posiciones en protocolos y
 * totales en USD. $skip: contratos que el admin ocultó.
 */
function chain_view(string $chain, array $cfg, array $data, array $skip): array
{
    $rows = [];
    if (($data['native_usd'] ?? null) !== null && $data['native_usd'] >= WALLET_MIN_USD) {
        $rows[] = ['contract' => null, 'symbol' => $cfg['symbol'], 'name' => $cfg['symbol'],
                   'price' => $data['native_price'], 'amount' => $data['native'], 'usd' => $data['native_usd']];
    }

    $ignored = 0;
    $lowValue = 0;
    foreach ($data['tokens'] as $t) {
        if (isset($skip[strtolower($t['contract'])])) {
            $ignored++;
        } elseif ($t['usd'] === null || $t['usd'] < WALLET_MIN_USD) {
            $lowValue++;
        } else {
            $rows[] = $t;
        }
    }
    usort($rows, fn ($a, $b) => $b['usd'] <=> $a['usd']);

    $walletUsd = array_sum(array_column($rows, 'usd'));
    $suppliedUsd = array_sum(array_column($data['protocols'], 'supplied_usd'));
    $borrowedUsd = array_sum(array_column($data['protocols'], 'borrowed_usd'));

    return [
        'rows' => $rows,
        'hidden' => (int) $data['hidden'] + $ignored + $lowValue,
        'wallet_usd' => $walletUsd,
        'supplied_usd' => $suppliedUsd,
        'borrowed_usd' => $borrowedUsd,
        'total_usd' => $walletUsd + $suppliedUsd - $borrowedUsd,
    ];
}

/** Contratos ocultos por el admin, por red. */
function skip_contracts_by_chain(): array
{
    $skip = [];
    foreach (array_keys(CHAINS) as $chain) {
        $skip[$chain] = ignored_contracts($chain);
    }
    return $skip;
}

/**
 * Balance de una cuenta: suma de todas sus direcciones y redes con datos
 * actuales. 'missing' cuenta las redes sin datos actuales (no incluidas);
 * 'has_data' es false si ninguna red tiene datos.
 * @param array $addresses filas de account_addresses de la cuenta
 * @param array $snapshots resultado de load_snapshots()
 */
function summarize_account(array $addresses, array $snapshots, array $skipByChain): array
{
    $summary = ['total' => 0.0, 'wallet' => 0.0, 'supplied' => 0.0, 'borrowed' => 0.0, 'missing' => 0];
    foreach ($addresses as $addr) {
        foreach (CHAINS as $chain => $cfg) {
            $data = $snapshots[(int) $addr['id']][$chain]['data'] ?? null;
            if ($data === null || ($data['v'] ?? 1) < SNAPSHOT_VERSION) {
                $summary['missing']++;
                continue;
            }
            $v = chain_view($chain, $cfg, $data, $skipByChain[$chain]);
            $summary['total'] += $v['total_usd'];
            $summary['wallet'] += $v['wallet_usd'];
            $summary['supplied'] += $v['supplied_usd'];
            $summary['borrowed'] += $v['borrowed_usd'];
        }
    }
    $summary['health'] = account_health($addresses, $snapshots);
    $summary['has_data'] = $summary['missing'] < count($addresses) * count(CHAINS);
    return $summary;
}

/**
 * Balance de varias cuentas con pocas consultas.
 * @param int[] $accountIds
 * @return array<int, array> account_id => resultado de summarize_account()
 */
function account_summaries(array $accountIds): array
{
    $addressesByAccount = addresses_by_account($accountIds);
    $addressIds = [];
    foreach ($addressesByAccount as $list) {
        array_push($addressIds, ...array_column($list, 'id'));
    }
    $snapshots = load_snapshots($addressIds);
    $skip = skip_contracts_by_chain();

    $summaries = [];
    foreach ($accountIds as $accountId) {
        $summaries[(int) $accountId] = summarize_account($addressesByAccount[(int) $accountId] ?? [], $snapshots, $skip);
    }
    return $summaries;
}
