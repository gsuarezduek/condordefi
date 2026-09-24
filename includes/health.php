<?php

require_once __DIR__ . '/snapshots.php';
require_once __DIR__ . '/format.php';

// Health Rate que se usa para "sin deuda" (un protocolo sin préstamos no tiene).
const HEALTH_NO_DEBT = 100;
// Umbrales del semáforo. Health Rate < 1 significa liquidación.
const HEALTH_DANGER_BELOW = 1.2;
const HEALTH_WARNING_BELOW = 1.5;
// Un dato más viejo que esto se marca como desactualizado: el Health Rate sale
// de la última actualización manual de la cuenta.
const HEALTH_STALE_HOURS = 24;

/**
 * Peor Health Rate de una dirección según sus fotos guardadas (Aave, Venus…,
 * en todas las redes): gana el más bajo. Si tiene datos pero ninguna deuda
 * devuelve HEALTH_NO_DEBT. Null si no hay ninguna foto actual.
 * @param array<string, array> $byChain fotos de la dirección: red => fila de chain_snapshots
 * @return array{health_rate: float, source: ?string, reported_at: string}|null
 *   source = protocolo y red del peor caso; reported_at = la foto más vieja considerada
 */
function address_health(array $byChain): ?array
{
    $worst = null;
    $source = null;
    $reportedAt = null;
    foreach ($byChain as $chain => $snap) {
        $data = $snap['data'] ?? null;
        if ($data === null || ($data['v'] ?? 1) < SNAPSHOT_VERSION) {
            continue;
        }
        $updated = $snap['updated_at'];
        if ($reportedAt === null || $updated < $reportedAt) {
            $reportedAt = $updated;
        }
        foreach ($data['protocols'] as $protocol) {
            $rate = $protocol['health_rate'] ?? null;
            if ($rate !== null && ($worst === null || $rate < $worst)) {
                $worst = (float) $rate;
                $source = $protocol['name'] . ' · ' . (CHAINS[$chain]['title'] ?? $chain);
            }
        }
    }
    if ($reportedAt === null) {
        return null;
    }
    return ['health_rate' => $worst ?? (float) HEALTH_NO_DEBT, 'source' => $worst !== null ? $source : null, 'reported_at' => $reportedAt];
}

/**
 * Peor Health Rate de una cuenta: el más bajo entre sus direcciones.
 * @param array $addresses filas de account_addresses de la cuenta
 * @param array $snapshots resultado de load_snapshots()
 */
function account_health(array $addresses, array $snapshots): ?array
{
    $worst = null;
    foreach ($addresses as $addr) {
        $health = address_health($snapshots[(int) $addr['id']] ?? []);
        if ($health === null) {
            continue;
        }
        if ($worst === null || $health['health_rate'] < $worst['health_rate']) {
            $worst = $health;
        } elseif ($health['reported_at'] < $worst['reported_at']) {
            $worst['reported_at'] = $health['reported_at'];
        }
    }
    return $worst;
}

/** Etiqueta y clase CSS según el Health Rate. */
function health_status(float $rate): array
{
    if ($rate >= HEALTH_NO_DEBT) {
        return ['label' => 'Sin deuda', 'class' => 'health-none'];
    }
    if ($rate < HEALTH_DANGER_BELOW) {
        return ['label' => 'Riesgo alto', 'class' => 'health-danger'];
    }
    if ($rate < HEALTH_WARNING_BELOW) {
        return ['label' => 'Atención', 'class' => 'health-warn'];
    }
    return ['label' => 'Saludable', 'class' => 'health-ok'];
}

/** HTML del indicador (ya escapado). Vacío si no hay dato. */
function health_badge(?array $health): string
{
    if ($health === null) {
        return '';
    }
    $rate = (float) $health['health_rate'];
    $status = health_status($rate);
    $stale = time() - strtotime($health['reported_at']) > HEALTH_STALE_HOURS * 3600;

    $text = $rate >= HEALTH_NO_DEBT
        ? $status['label']
        : number_format($rate, 2, '.', '') . ' · ' . $status['label'];
    $title = ($health['source'] ? $health['source'] . ' · ' : '') . 'dato ' . time_ago($health['reported_at']) . ($stale ? ' (desactualizado)' : '');

    return '<span class="health ' . $status['class'] . ($stale ? ' health-stale' : '') . '" title="'
        . htmlspecialchars($title, ENT_QUOTES) . '">Health Rate: '
        . htmlspecialchars($text, ENT_QUOTES) . ($stale ? ' *' : '') . '</span>';
}
