<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/snapshots.php';
require_once __DIR__ . '/includes/health.php';
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/includes/balances.php';
app_session_start();
require_login();

$activePage = 'accounts';
$isAdmin = !empty($_SESSION['is_admin']);

$id = (int) ($_GET['id'] ?? 0);
require_account_access($id);

$stmt = db()->prepare('SELECT * FROM accounts WHERE id = ?');
$stmt->execute([$id]);
$account = $stmt->fetch();

if (!$account) {
    http_response_code(404);
    echo 'Cuenta no encontrada.';
    exit;
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'hide_token') {
        $chain = (string) ($_POST['chain'] ?? '');
        if (isset(CHAINS[$chain])) {
            ignore_token($chain, (string) ($_POST['contract'] ?? ''), (string) ($_POST['symbol'] ?? ''), (string) ($_POST['name'] ?? ''));
        }
    } elseif (($_POST['action'] ?? '') === 'save_note') {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $body = trim($_POST['body'] ?? '');
        if ($body !== '') {
            if ($noteId > 0) {
                $stmt = db()->prepare('UPDATE account_notes SET body = ? WHERE id = ? AND account_id = ?');
                $stmt->execute([$body, $noteId, $id]);
            } else {
                $stmt = db()->prepare('INSERT INTO account_notes (account_id, body) VALUES (?, ?)');
                $stmt->execute([$id, $body]);
                $noteId = (int) db()->lastInsertId();
            }
        }
        header('Location: /account.php?id=' . $id . ($noteId > 0 ? '&note=' . $noteId : '') . '#notas');
        exit;
    } elseif (($_POST['action'] ?? '') === 'delete_note') {
        $stmt = db()->prepare('DELETE FROM account_notes WHERE id = ? AND account_id = ?');
        $stmt->execute([(int) ($_POST['note_id'] ?? 0), $id]);
        header('Location: /account.php?id=' . $id . '#notas');
        exit;
    }
    header('Location: /account.php?id=' . $id);
    exit;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

function fmt_amount($n): string
{
    $n = (float) $n;
    if ($n == 0) {
        return '0';
    }
    $decimals = $n < 1 ? 6 : 4;
    return rtrim(rtrim(number_format($n, $decimals, '.', ','), '0'), '.');
}

function fmt_price($p): string
{
    $p = (float) $p;
    if ($p >= 1) {
        return '$' . number_format($p, 2);
    }
    if ($p >= 0.01) {
        return '$' . number_format($p, 4);
    }
    return '$' . rtrim(rtrim(number_format($p, 8, '.', ''), '0'), '.');
}

/** Logo de una red (SVG propio). */
function chain_icon(string $chain, string $class = 'chain-ico'): string
{
    return '<img class="' . h($class) . '" src="/assets/chain-' . h($chain) . '.svg" alt="' . h(CHAINS[$chain]['title']) . '" title="' . h(CHAINS[$chain]['title']) . '">';
}

/**
 * Logo + símbolo. Si el logo no existe (404) se muestra una letra. Lleva un
 * icono chico de la red que solo se ve cuando hay más de una red activada.
 */
function token_cell(string $chain, ?string $contract, string $symbol): string
{
    $letter = mb_strtoupper(mb_substr($symbol !== '' ? $symbol : '?', 0, 1));
    return '<span class="tok"><span class="tok-icon">'
        . '<img class="tok-logo" src="' . h(token_logo_url($chain, $contract)) . '" alt="" loading="lazy"'
        . ' onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline-flex\'">'
        . '<span class="tok-logo tok-fallback" style="display:none">' . h($letter) . '</span>'
        . chain_icon($chain, 'chain-mini')
        . '</span><span class="tok-sym">' . h($symbol) . '</span></span>';
}

/** 0x1234…abcd */
function short_address(string $address): string
{
    return substr($address, 0, 6) . '…' . substr($address, -4);
}

/** Tabla de un lado de una posición (depositado o prestado) de un protocolo. */
function render_position_table(string $chain, string $title, array $rows, string $usdClass): void
{
    ?>
    <div class="pos-title"><?= h($title) ?></div>
    <table>
      <thead><tr><th>Token</th><th class="num">Balance</th><th class="num">Valor USD</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= token_cell($chain, $r['contract'], $r['symbol']) ?></td>
            <td class="num"><?= fmt_amount($r['amount']) ?></td>
            <td class="num <?= $usdClass ?>"><?= fmt_usd($r['usd']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

/** Estado de una red de una dirección: cuándo se actualizó, botón de admin y avisos. */
function render_chain_status(string $chain, array $cfg, ?array $snap, bool $isAdmin, int $addressId): void
{
    $data = $snap['data'] ?? null;
    $current = $data !== null && ($data['v'] ?? 1) >= SNAPSHOT_VERSION;
    ?>
    <div class="chain-status by-chain" data-chain="<?= h($chain) ?>" hidden>
      <div class="chain-status-line">
        <?= chain_icon($chain) ?>
        <span class="chain-name"><?= h($cfg['title']) ?></span>
        <span class="updated"><?= !empty($snap['updated_at']) ? 'Actualizado ' . h(time_ago($snap['updated_at'])) : 'Sin datos todavía' ?></span>
        <?php if ($isAdmin): ?>
          <button type="button" class="btn-small js-refresh" data-address-id="<?= $addressId ?>" data-chain="<?= h($chain) ?>">Actualizar</button>
        <?php endif; ?>
      </div>
      <?php if (!empty($snap['error']) && ($isAdmin || $data !== null)): ?>
        <div class="error">
          <?= $isAdmin
              ? 'La última actualización falló: ' . h($snap['error'])
              : 'La última actualización falló; estos datos pueden estar desactualizados.' ?>
        </div>
      <?php endif; ?>
      <?php if ($data === null): ?>
        <p class="empty">
          <?= $isAdmin
              ? 'Todavía no hay datos. Apretá "Actualizar".'
              : 'Todavía no hay datos. Un administrador tiene que actualizar esta cuenta.' ?>
        </p>
      <?php elseif (!$current): ?>
        <p class="empty">
          <?= $isAdmin
              ? 'Estos datos son de un formato anterior (sin precios ni posiciones). Apretá "Actualizar" para verlos completos.'
              : 'Estos datos están desactualizados. Un administrador tiene que actualizar esta cuenta.' ?>
        </p>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * Dibuja una dirección con todas sus redes juntas: estado por red, una sola
 * tabla de wallet y las posiciones en protocolos. Cada pieza lleva data-chain
 * y el JS de la página muestra u oculta según las redes activadas.
 * @param array<string, array> $byChain fotos de la dirección: red => fila de chain_snapshots
 */
function render_address(array $addr, array $byChain, array $skipByChain, bool $isAdmin): void
{
    $addressId = (int) $addr['id'];
    $rows = [];
    $walletByChain = [];
    $hiddenByChain = [];
    $protocols = [];
    foreach (CHAINS as $chain => $cfg) {
        $data = $byChain[$chain]['data'] ?? null;
        if ($data === null || ($data['v'] ?? 1) < SNAPSHOT_VERSION) {
            continue;
        }
        $view = chain_view($chain, $cfg, $data, $skipByChain[$chain]);
        foreach ($view['rows'] as $r) {
            $rows[] = $r + ['chain' => $chain];
        }
        $walletByChain[$chain] = $view['wallet_usd'];
        $hiddenByChain[$chain] = $view['hidden'];
        foreach ($data['protocols'] as $protocol) {
            $protocols[] = ['chain' => $chain, 'protocol' => $protocol];
        }
    }
    usort($rows, fn ($a, $b) => $b['usd'] <=> $a['usd']);
    ?>
    <h2 class="address-title">
      <?= $addr['label'] ? h($addr['label']) . ' · ' : '' ?><span class="address"><?= h($addr['address']) ?></span>
      <?= health_badge(address_health($byChain)) ?>
    </h2>

    <div class="chain-statuses">
      <?php foreach (CHAINS as $chain => $cfg): render_chain_status($chain, $cfg, $byChain[$chain] ?? null, $isAdmin, $addressId); endforeach; ?>
    </div>

    <?php if ($walletByChain): ?>
      <div class="wallet-block" hidden>
        <div class="section-head">
          <span class="section-name">Wallet</span>
          <span class="section-total js-sum" data-by-chain="<?= h(json_encode($walletByChain)) ?>"></span>
        </div>
        <p class="empty js-no-tokens" hidden>Sin tokens con valor relevante.</p>
        <table>
          <thead>
            <tr><th>Token</th><th class="num">Precio</th><th class="num">Cantidad</th><th class="num">Valor USD</th><?php if ($isAdmin): ?><th></th><?php endif; ?></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $t): ?>
              <tr class="by-chain" data-chain="<?= h($t['chain']) ?>" hidden>
                <td><?= token_cell($t['chain'], $t['contract'], $t['symbol']) ?></td>
                <td class="num"><?= fmt_price($t['price']) ?></td>
                <td class="num"><?= fmt_amount($t['amount']) ?></td>
                <td class="num"><?= fmt_usd($t['usd']) ?></td>
                <?php if ($isAdmin): ?>
                  <td class="row-action">
                    <?php if ($t['contract'] !== null): ?>
                      <form method="post" onsubmit="return confirm('¿Ocultar este token en todas las cuentas?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="hide_token">
                        <input type="hidden" name="chain" value="<?= h($t['chain']) ?>">
                        <input type="hidden" name="contract" value="<?= h($t['contract']) ?>">
                        <input type="hidden" name="symbol" value="<?= h($t['symbol']) ?>">
                        <input type="hidden" name="name" value="<?= h($t['name']) ?>">
                        <button type="submit" class="btn-small">Ocultar</button>
                      </form>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php foreach ($hiddenByChain as $chain => $count): if ($count > 0): ?>
          <p class="empty by-chain" data-chain="<?= h($chain) ?>" hidden>
            <?= chain_icon($chain) ?> <?= $count ?> token(s) ocultos en <?= h(CHAINS[$chain]['title']) ?> (sin precio de mercado, menos de <?= fmt_usd(WALLET_MIN_USD) ?>, spam o marcados por el admin).
            <?php if ($isAdmin): ?><a href="/hidden-tokens.php">Gestionar</a><?php endif; ?>
          </p>
        <?php endif; endforeach; ?>
      </div>
    <?php endif; ?>

    <?php foreach ($protocols as $item): $protocol = $item['protocol']; ?>
      <div class="protocol-block by-chain" data-chain="<?= h($item['chain']) ?>" hidden>
        <div class="section-head protocol-head">
          <span class="section-name"><?= h($protocol['name']) ?></span>
          <span class="chain-tag"><?= chain_icon($item['chain']) ?> <?= h(CHAINS[$item['chain']]['title']) ?></span>
          <?php if ($protocol['health_rate'] !== null):
            $status = health_status((float) $protocol['health_rate']); ?>
            <span class="health <?= h($status['class']) ?>">Health Rate: <?= number_format((float) $protocol['health_rate'], 2, '.', '') ?> · <?= h($status['label']) ?></span>
          <?php endif; ?>
          <span class="section-total"><?= fmt_usd($protocol['net_usd']) ?></span>
        </div>
        <?php if (!empty($protocol['supplied'])): render_position_table($item['chain'], 'Supplied', $protocol['supplied'], ''); endif; ?>
        <?php if (!empty($protocol['borrowed'])): render_position_table($item['chain'], 'Borrowed', $protocol['borrowed'], 'usd-debt'); endif; ?>
      </div>
    <?php endforeach; ?>
    <?php
}

$addresses = account_addresses($id);
$snapshots = load_snapshots(array_column($addresses, 'id'));
$skipByChain = skip_contracts_by_chain();

// Balance total de la cuenta: suma de todas sus direcciones y redes con datos actuales.
$summary = summarize_account($addresses, $snapshots, $skipByChain);
$hasSummary = $summary['has_data'];

// Movimientos de todas las direcciones y redes, del más reciente al más viejo.
// Salen de las fotos guardadas (no consultan las APIs); se descartan los de tokens ocultos.
$movements = [];
foreach ($addresses as $addr) {
    foreach (CHAINS as $chain => $cfg) {
        $data = $snapshots[(int) $addr['id']][$chain]['data'] ?? null;
        foreach ($data['activity'] ?? [] as $ev) {
            if (!empty($ev['contract']) && isset($skipByChain[$chain][strtolower($ev['contract'])])) {
                continue;
            }
            $movements[] = $ev + ['chain' => $chain, 'address_id' => (int) $addr['id'], 'address_label' => $addr['label'] ?: short_address($addr['address'])];
        }
    }
}
usort($movements, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

// Notas: la más reciente primero. Si la tabla todavía no existe (falta correr
// la migración) la página sigue funcionando sin notas.
$notes = [];
$notesError = null;
try {
    $stmt = db()->prepare('SELECT id, body, created_at, updated_at FROM account_notes WHERE account_id = ? ORDER BY created_at DESC, id DESC');
    $stmt->execute([$id]);
    $notes = $stmt->fetchAll();
} catch (PDOException $e) {
    $notesError = 'No se pudieron leer las notas (¿falta correr la migración de account_notes?): ' . $e->getMessage();
}
$notesJs = array_map(fn ($n) => [
    'id' => (int) $n['id'],
    'body' => $n['body'],
    'date' => date('d/m/Y', strtotime($n['created_at'])),
    'edited' => date('d/m/Y', strtotime($n['updated_at'])) !== date('d/m/Y', strtotime($n['created_at']))
        ? date('d/m/Y', strtotime($n['updated_at'])) : null,
], $notes);
$noteStart = 0;
foreach ($notesJs as $i => $n) {
    if ($n['id'] === (int) ($_GET['note'] ?? 0)) {
        $noteStart = $i;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($account['name']) ?> — CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/favicon-192.png">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<style>
  .back{color:var(--text-dim);font-size:12.5px;text-decoration:none;display:inline-block;margin-bottom:10px;}
  .back:hover{color:#fff;}
  .address{font-family:monospace;color:var(--text-dim);font-size:13px;word-break:break-all;}
  .section-title{font-size:15px;margin:32px 0 12px 0;color:#fff;}
  .address-title{font-size:15px;margin:36px 0 4px 0;color:#fff;word-break:break-all;}
  .updated{color:var(--text-dim);font-size:12px;}
  .page-head h1{font-size:30px;line-height:1.15;}
  .page-side{display:flex;flex-direction:column;align-items:flex-end;gap:6px;}
  .page-actions{flex-wrap:wrap;justify-content:flex-end;}
  .tabs{display:flex;gap:2px;border-bottom:1px solid var(--panel-border);margin:28px 0 6px 0;overflow-x:auto;}
  .tab{align-self:auto;background:none;border:none;border-bottom:2px solid transparent;border-radius:0;margin-bottom:-1px;padding:11px 18px;font-size:13.5px;font-weight:600;color:var(--text-dim);white-space:nowrap;}
  .tab:hover{color:#fff;}
  .tab.active{color:#fff;border-bottom-color:var(--gold);}
  .tab-soon{font-size:9.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-dim);border:1px solid var(--panel-border);border-radius:999px;padding:1px 6px;margin-left:6px;}
  .tab-panel[hidden]{display:none;}
  .filters{display:flex;flex-wrap:wrap;align-items:center;gap:8px 22px;margin:18px 0 6px 0;}
  .filter-group{display:flex;align-items:center;gap:6px;}
  .filter-label{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-dim);margin-right:2px;}
  .pill{align-self:auto;padding:4px 12px;font-size:12px;font-weight:600;background:transparent;color:var(--text-dim);border:1px solid var(--panel-border);border-radius:999px;}
  .pill:hover{color:#fff;border-color:var(--text-dim);}
  .pill.active{background:var(--panel-border);color:#fff;border-color:var(--panel-border);}
  .filters select{background:var(--panel);color:#fff;border:1px solid var(--panel-border);border-radius:8px;padding:5px 8px;font-size:12px;}
  td.when{white-space:nowrap;color:var(--text-dim);font-size:12.5px;}
  td.tx{text-align:right;white-space:nowrap;}
  tr.mv[hidden]{display:none;}
  .more{margin:14px 0 0 0;text-align:center;}
  .placeholder{margin:28px 0;padding:44px 20px;text-align:center;border:1px dashed var(--panel-border);border-radius:14px;color:var(--text-dim);font-size:13.5px;}
  .placeholder strong{display:block;color:#fff;font-size:15px;margin-bottom:6px;}
  .summary{display:flex;flex-wrap:wrap;align-items:flex-end;gap:14px 40px;margin:22px 0 6px 0;padding:18px 22px;background:var(--panel);border:1px solid var(--panel-border);border-radius:14px;}
  .summary-total{font-size:34px;font-weight:700;line-height:1.1;margin-top:2px;}
  .usd-debt{color:#ee7b6f;}
  .section-head{display:flex;align-items:center;gap:12px;margin:22px 0 4px 0;padding-bottom:6px;border-bottom:1px solid var(--panel-border);}
  .section-name{font-size:14px;font-weight:700;}
  .section-total{margin-left:auto;font-size:14px;font-weight:700;color:var(--gold);}
  .pos-title{font-size:11.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-dim);margin:12px 0 2px 0;}
  th.num,td.num{text-align:right;font-variant-numeric:tabular-nums;}
  .tok{display:inline-flex;align-items:center;gap:9px;}
  .tok-logo{width:22px;height:22px;border-radius:50%;flex:none;object-fit:cover;background:var(--panel-border);}
  .tok-fallback{align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--gold);}
  .tok-sym{font-weight:600;}
  .sub-title{font-size:12.5px;margin:22px 0 8px 0;color:var(--text-dim);font-weight:600;}
  td.row-action{text-align:right;}
  td.row-action form{display:inline;margin:0;}
  .btn-small{padding:4px 10px;font-size:11.5px;background:transparent;color:var(--text-dim);border:1px solid var(--panel-border);}
  .btn-small:hover{color:#fff;border-color:var(--text-dim);}
  .account-info{display:flex;flex-wrap:wrap;gap:12px 32px;margin:22px 0 10px 0;}
  .info-label{font-size:11px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px;}
  .info-value{font-size:15px;font-weight:600;}
  .note-card{padding:16px 22px 22px 22px;}
  .note-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-bottom:10px;margin-bottom:14px;border-bottom:1px solid var(--border);}
  .note-nav{display:flex;align-items:center;gap:6px;}
  .note-pos{font-size:12px;color:#7a7a72;min-width:150px;text-align:center;}
  .note-arrow{padding:0;width:28px;height:28px;border-radius:50%;background:#f4f1e8;color:#161615;font-size:18px;line-height:1;}
  .note-arrow:disabled{opacity:.3;cursor:default;}
  .note-actions{display:flex;gap:6px;}
  .note-arrow,.note-btn,.note-form-actions button{align-self:center;}
  .note-btn{padding:5px 12px;font-size:12px;background:#f4f1e8;color:#161615;}
  .note-btn:hover{background:#ebe6d6;}
  .note-body{white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.6;min-height:96px;}
  .note-body.note-empty{color:#9a9a92;}
  .note-form{display:block;}
  .note-form[hidden],#note-delete-form[hidden]{display:none;}
  .note-form textarea{min-height:180px;}
  .note-form-actions{display:flex;align-items:center;gap:8px;margin-top:10px;}
  .note-form-actions .btn-small{color:#5a5a5a;border-color:var(--border);}
  .note-form-actions .btn-small:hover{color:#161615;border-color:#9a9a92;}
  .note-form-actions .note-delete{margin-left:auto;color:#a5322a;}
  textarea{
    width:100%;
    min-height:80px;
    padding:10px 12px;
    border:1px solid var(--border);
    border-radius:8px;
    font-size:14px;
    line-height:1.6;
    font-family:inherit;
    background:#fcfbf8;
    resize:vertical;
    outline:none;
  }
  textarea:focus{border-color:var(--gold-dark);}
  .chain-toggles{display:flex;align-items:center;flex-wrap:wrap;gap:8px 10px;margin:20px 0 4px 0;}
  .chain-toggle{align-self:auto;display:inline-flex;align-items:center;gap:8px;padding:5px 15px 5px 6px;background:transparent;color:var(--text-dim);border:1px solid var(--panel-border);border-radius:999px;font-size:13px;font-weight:600;}
  .chain-toggle:hover{color:#fff;border-color:var(--text-dim);}
  .chain-toggle.on{color:#fff;border-color:var(--gold);background:var(--panel);}
  .chain-toggle-ico{width:24px;height:24px;filter:grayscale(1);opacity:.45;transition:filter .15s,opacity .15s;}
  .chain-toggle.on .chain-toggle-ico{filter:none;opacity:1;}
  .chain-ico{width:15px;height:15px;vertical-align:-3px;border-radius:50%;}
  .chain-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--gold);}
  .tok-icon{position:relative;display:inline-flex;flex:none;}
  .chain-mini{display:none;position:absolute;right:-4px;bottom:-4px;width:12px;height:12px;border-radius:50%;box-shadow:0 0 0 1.5px var(--panel);}
  .multi-chain .chain-mini{display:block;}
  [hidden]{display:none !important;}
  .chain-statuses{margin:6px 0 4px 0;}
  .chain-status{margin:6px 0;}
  .chain-status-line{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
  .chain-name{font-size:12px;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:.5px;}
  .dir-in{color:#6fcf7f;}
  .dir-out{color:#ee7b6f;}
</style>
</head>
<body>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <a class="back" href="/accounts.php">&larr; Cuentas</a>
    <div class="page-head">
      <h1><?= h($account['name']) ?></h1>
      <?php if ($isAdmin): ?>
        <div class="page-side">
          <div class="page-actions">
            <?php if (!empty($addresses)): ?>
              <button type="button" class="btn-link" id="refresh-all">Actualizar todo</button>
            <?php endif; ?>
            <a class="btn-link" href="/addresses.php?account_id=<?= $id ?>">+ Dirección</a>
            <a class="btn-link" href="/access.php?account_id=<?= $id ?>">+ Usuario</a>
          </div>
          <span class="updated" id="refresh-status"></span>
        </div>
      <?php endif; ?>
    </div>
    <?php foreach ($addresses as $addr): ?>
      <div class="address">
        <?= h($addr['address']) ?>
        <?php if ($addr['label']): ?><span class="badge"><?= h($addr['label']) ?></span><?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="chain-toggles" id="chain-toggles" role="group" aria-label="Redes">
      <span class="filter-label">Redes</span>
      <?php foreach (CHAINS as $chain => $cfg): ?>
        <button type="button" class="chain-toggle" data-chain="<?= h($chain) ?>" aria-pressed="false" title="Activar / desactivar <?= h($cfg['title']) ?>">
          <?= chain_icon($chain, 'chain-toggle-ico') ?><span><?= h($cfg['title']) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <p class="empty" id="chains-hint">Activá una red para ver los saldos, tokens y movimientos.</p>

    <?php if ($hasSummary):
      $by = $summary['by_chain'];
      $sumAttr = fn (string $key) => h(json_encode(array_map(fn ($c) => $c[$key], $by)));
    ?>
      <div class="summary" id="summary" hidden>
        <div class="summary-main">
          <div class="info-label">Balance total</div>
          <div class="summary-total js-sum" data-by-chain="<?= $sumAttr('total') ?>"></div>
          <div class="updated js-missing" data-by-chain="<?= $sumAttr('missing') ?>" hidden></div>
        </div>
        <div><div class="info-label">Wallet</div><div class="info-value js-sum" data-by-chain="<?= $sumAttr('wallet') ?>"></div></div>
        <div><div class="info-label">Depositado</div><div class="info-value js-sum" data-by-chain="<?= $sumAttr('supplied') ?>"></div></div>
        <div><div class="info-label">Prestado</div><div class="info-value usd-debt js-sum" data-by-chain="<?= $sumAttr('borrowed') ?>"></div></div>
      </div>
    <?php endif; ?>

    <?php include __DIR__ . '/includes/account_info.php'; ?>

    <?php $showNotes = !empty($notes) || $isAdmin; ?>
    <div class="tabs" role="tablist" id="tabs">
      <button type="button" class="tab active" role="tab" data-tab="saldos">Saldos y tokens</button>
      <button type="button" class="tab" role="tab" data-tab="movimientos">Movimientos</button>
      <button type="button" class="tab" role="tab" data-tab="graficos">Gráficos<span class="tab-soon">Pronto</span></button>
      <?php if ($showNotes): ?><button type="button" class="tab" role="tab" data-tab="notas">Notas</button><?php endif; ?>
    </div>

    <section class="tab-panel" id="tab-saldos">
      <?php if (empty($addresses)): ?>
        <p class="empty" style="margin-top:24px;">Esta cuenta todavía no tiene direcciones cargadas.</p>
      <?php endif; ?>

    <?php foreach ($addresses as $addr): ?>
      <?php render_address($addr, $snapshots[(int) $addr['id']] ?? [], $skipByChain, $isAdmin); ?>
    <?php endforeach; ?>
    </section>

    <section class="tab-panel" id="tab-movimientos" hidden>
      <?php if (empty($movements)): ?>
        <p class="empty">Todavía no hay movimientos guardados<?= $isAdmin ? '. Actualizá la cuenta para traerlos.' : '.' ?></p>
      <?php else: ?>
        <div class="filters" id="mv-filters">
          <div class="filter-group" data-filter="direction">
            <span class="filter-label">Tipo</span>
            <button type="button" class="pill active" data-value="">Todos</button>
            <button type="button" class="pill" data-value="in">Recibidos</button>
            <button type="button" class="pill" data-value="out">Enviados</button>
          </div>
          <?php if (count($addresses) > 1): ?>
            <div class="filter-group">
              <span class="filter-label">Dirección</span>
              <select id="mv-address">
                <option value="">Todas</option>
                <?php foreach ($addresses as $addr): ?>
                  <option value="<?= (int) $addr['id'] ?>"><?= h($addr['label'] ?: short_address($addr['address'])) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
        </div>
        <table>
          <thead>
            <tr><th>Fecha</th><?php if (count($addresses) > 1): ?><th>Dirección</th><?php endif; ?><th>Red</th><th>Tipo</th><th>Token</th><th class="num">Cantidad</th><th></th></tr>
          </thead>
          <tbody id="mv-body">
            <?php foreach ($movements as $ev): ?>
              <tr class="mv" data-direction="<?= h($ev['direction']) ?>" data-chain="<?= h($ev['chain']) ?>" data-address="<?= $ev['address_id'] ?>">
                <td class="when"><?= date('d/m/Y H:i', $ev['timestamp']) ?></td>
                <?php if (count($addresses) > 1): ?><td class="address"><?= h($ev['address_label']) ?></td><?php endif; ?>
                <td><span class="chain-tag"><?= chain_icon($ev['chain']) ?> <?= h(CHAINS[$ev['chain']]['title']) ?></span></td>
                <td class="<?= $ev['direction'] === 'in' ? 'dir-in' : 'dir-out' ?>"><?= $ev['direction'] === 'in' ? '↓ Recibido' : '↑ Enviado' ?></td>
                <td><?= token_cell($ev['chain'], $ev['contract'] ?? null, (string) $ev['symbol']) ?></td>
                <td class="num"><?= fmt_amount($ev['amount']) ?></td>
                <td class="tx"><a href="<?= h(CHAINS[$ev['chain']]['explorer'] . $ev['hash']) ?>" target="_blank" rel="noopener">Ver &#8599;</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="empty" id="mv-empty" hidden>Ningún movimiento coincide con el filtro.</p>
        <div class="more"><button type="button" class="btn-small" id="mv-more" hidden>Ver más</button></div>
        <p class="updated" style="margin-top:14px;">Se guardan los últimos <?= SNAPSHOT_ACTIVITY_LIMIT ?> movimientos por dirección y red; se renuevan al actualizar la cuenta.</p>
      <?php endif; ?>
    </section>

    <section class="tab-panel" id="tab-graficos" hidden>
      <div class="placeholder"><strong>Gráficos</strong>Acá vamos a mostrar la evolución del saldo de la cuenta. Próximamente.</div>
    </section>

    <?php if ($showNotes): ?>
    <section class="tab-panel" id="tab-notas" hidden>
      <div class="card note-card" id="notes">
        <?php if ($notesError && $isAdmin): ?>
          <div class="error"><?= h($notesError) ?></div>
        <?php endif; ?>
        <div class="note-bar" id="note-bar">
          <div class="note-nav">
            <button type="button" class="note-arrow" id="note-prev" title="Nota anterior" aria-label="Nota anterior">&lsaquo;</button>
            <span class="note-pos" id="note-pos"></span>
            <button type="button" class="note-arrow" id="note-next" title="Nota siguiente" aria-label="Nota siguiente">&rsaquo;</button>
          </div>
          <?php if ($isAdmin): ?>
            <div class="note-actions">
              <button type="button" class="note-btn" id="note-edit">Editar</button>
              <button type="button" class="note-btn" id="note-new">+ Nueva</button>
            </div>
          <?php endif; ?>
        </div>
        <div class="note-body" id="note-view"><?= !empty($notes) ? h($notes[$noteStart]['body']) : '' ?></div>
        <?php if ($isAdmin): ?>
          <form method="post" class="note-form" id="note-form" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_note">
            <input type="hidden" name="note_id" id="note-id" value="0">
            <textarea name="body" id="note-text" placeholder="Ej: yRise y BBTC son tokens sin valor, ignorar." required></textarea>
            <div class="note-form-actions">
              <button type="submit">Guardar</button>
              <button type="button" class="btn-small" id="note-cancel">Cancelar</button>
              <button type="button" class="btn-small note-delete" id="note-delete">Eliminar</button>
            </div>
          </form>
          <form method="post" id="note-delete-form" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_note">
            <input type="hidden" name="note_id" id="note-delete-id" value="0">
          </form>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </main>

  <script>
    // Redes: interruptores (todas apagadas por defecto) que muestran u ocultan las piezas
    // marcadas con .by-chain[data-chain] y recalculan los totales (.js-sum). La elección
    // se recuerda en este navegador.
    const Chains = (function () {
      const KEY = 'condor.chains';
      const all = <?= json_encode(array_keys(CHAINS)) ?>;
      let saved = [];
      try { saved = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) {}
      const enabled = new Set((Array.isArray(saved) ? saved : []).filter(c => all.includes(c)));
      const listeners = [];
      const usd = n => '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const sum = el => {
        const by = JSON.parse(el.dataset.byChain);
        let t = 0;
        enabled.forEach(c => { t += by[c] || 0; });
        return t;
      };

      function render() {
        document.querySelectorAll('.chain-toggle').forEach(b => {
          const on = enabled.has(b.dataset.chain);
          b.classList.toggle('on', on);
          b.setAttribute('aria-pressed', on);
        });
        document.querySelectorAll('.by-chain').forEach(el => { el.hidden = !enabled.has(el.dataset.chain); });
        document.querySelectorAll('.js-sum').forEach(el => { el.textContent = usd(sum(el)); });
        document.querySelectorAll('.js-missing').forEach(el => {
          const n = sum(el);
          el.hidden = n === 0;
          el.textContent = n + ' red(es) sin datos actualizados no están incluidas.';
        });
        document.querySelectorAll('.wallet-block').forEach(block => {
          block.hidden = enabled.size === 0;
          const any = block.querySelector('tbody tr.by-chain:not([hidden])') !== null;
          block.querySelector('table').hidden = !any;
          block.querySelector('.js-no-tokens').hidden = any;
        });
        const summary = document.getElementById('summary');
        if (summary) summary.hidden = enabled.size === 0;
        document.getElementById('chains-hint').hidden = enabled.size > 0;
        document.body.classList.toggle('multi-chain', enabled.size > 1);
        listeners.forEach(f => f());
      }

      document.querySelectorAll('.chain-toggle').forEach(b => b.addEventListener('click', () => {
        const c = b.dataset.chain;
        if (enabled.has(c)) enabled.delete(c); else enabled.add(c);
        try { localStorage.setItem(KEY, JSON.stringify(Array.from(enabled))); } catch (e) {}
        render();
      }));

      return { enabled, onChange: f => listeners.push(f), render };
    })();
    Chains.render();
  </script>

  <script>
    // Pestañas: la elegida se guarda en el hash (#movimientos), así sobrevive a recargas y redirecciones.
    (function () {
      const tabs = Array.from(document.querySelectorAll('.tab'));
      const panels = {};
      tabs.forEach(t => { panels[t.dataset.tab] = document.getElementById('tab-' + t.dataset.tab); });

      function select(name, updateHash) {
        if (!panels[name]) name = 'saldos';
        tabs.forEach(t => {
          const on = t.dataset.tab === name;
          t.classList.toggle('active', on);
          t.setAttribute('aria-selected', on);
        });
        Object.keys(panels).forEach(k => { panels[k].hidden = k !== name; });
        if (updateHash) history.replaceState(null, '', '#' + name);
      }

      tabs.forEach(t => t.addEventListener('click', () => select(t.dataset.tab, true)));
      window.addEventListener('hashchange', () => select(location.hash.slice(1)));
      select(location.hash.slice(1));
    })();

    // Movimientos: filtros por tipo, red y dirección; se muestran de a 50.
    (function () {
      const body = document.getElementById('mv-body');
      if (!body) return;
      const rows = Array.from(body.querySelectorAll('tr.mv'));
      const more = document.getElementById('mv-more');
      const empty = document.getElementById('mv-empty');
      const PAGE = 50;
      let shown = PAGE;
      const filter = { direction: '', address: '' };

      function apply() {
        let matched = 0;
        rows.forEach(r => {
          const ok = (!filter.direction || r.dataset.direction === filter.direction)
            && Chains.enabled.has(r.dataset.chain)
            && (!filter.address || r.dataset.address === filter.address);
          if (ok) matched++;
          r.hidden = !ok || matched > shown;
        });
        more.hidden = matched <= shown;
        empty.textContent = Chains.enabled.size === 0
          ? 'Activá una red para ver los movimientos.'
          : 'Ningún movimiento coincide con el filtro.';
        empty.hidden = matched > 0;
      }

      document.querySelectorAll('#mv-filters .filter-group[data-filter]').forEach(group => {
        group.addEventListener('click', e => {
          const pill = e.target.closest('.pill');
          if (!pill) return;
          group.querySelectorAll('.pill').forEach(p => p.classList.toggle('active', p === pill));
          filter[group.dataset.filter] = pill.dataset.value;
          shown = PAGE;
          apply();
        });
      });
      const select = document.getElementById('mv-address');
      if (select) select.addEventListener('change', () => { filter.address = select.value; shown = PAGE; apply(); });
      more.addEventListener('click', () => { shown += PAGE; apply(); });
      Chains.onChange(() => { shown = PAGE; apply(); });
      apply();
    })();
  </script>

  <?php if (!empty($notes) || $isAdmin): ?>
  <script>
    (function () {
      const NOTES = <?= json_encode($notesJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
      let idx = <?= (int) $noteStart ?>; // 0 = la más reciente; +1 va a las anteriores
      let editing = false;

      const $ = id => document.getElementById(id);
      const view = $('note-view'), pos = $('note-pos');
      const prev = $('note-prev'), next = $('note-next');
      const editBtn = $('note-edit'), newBtn = $('note-new');
      const form = $('note-form'), text = $('note-text'), noteId = $('note-id');
      const deleteBtn = $('note-delete');

      function show() {
        const n = NOTES[idx];
        if (!n) {
          pos.textContent = 'Sin notas';
          view.textContent = 'Todavía no hay notas. Usá "+ Nueva" para agregar la primera.';
          view.classList.add('note-empty');
        } else {
          pos.textContent = 'Nota ' + (NOTES.length - idx) + ' de ' + NOTES.length + ' · ' + n.date + (n.edited ? ' (editada ' + n.edited + ')' : '');
          view.textContent = n.body;
          view.classList.remove('note-empty');
        }
        prev.disabled = editing || idx >= NOTES.length - 1;
        next.disabled = editing || idx <= 0;
        view.hidden = editing;
        if (IS_ADMIN) {
          editBtn.hidden = editing || !n;
          newBtn.hidden = editing;
          form.hidden = !editing;
        }
      }

      function startEdit(isNew) {
        editing = true;
        noteId.value = isNew ? 0 : NOTES[idx].id;
        text.value = isNew ? '' : NOTES[idx].body;
        deleteBtn.hidden = isNew;
        show();
        if (isNew) pos.textContent = 'Nueva nota';
        text.focus();
      }

      prev.addEventListener('click', () => { idx++; show(); });
      next.addEventListener('click', () => { idx--; show(); });

      if (IS_ADMIN) {
        editBtn.addEventListener('click', () => startEdit(false));
        newBtn.addEventListener('click', () => startEdit(true));
        $('note-cancel').addEventListener('click', () => { editing = false; show(); });
        deleteBtn.addEventListener('click', () => {
          if (!confirm('¿Eliminar esta nota?')) return;
          $('note-delete-id').value = NOTES[idx].id;
          $('note-delete-form').submit();
        });
        text.addEventListener('keydown', e => {
          if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') form.requestSubmit();
          if (e.key === 'Escape') { editing = false; show(); }
        });
      }

      show();
    })();
  </script>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
  <script>
    const CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>;
    const buttons = Array.from(document.querySelectorAll('.js-refresh'));
    const allButton = document.getElementById('refresh-all');
    const statusEl = document.getElementById('refresh-status');

    // Actualiza una dirección/red. El servidor guarda el resultado (o el error)
    // en la base; acá solo hace falta detectar si la respuesta no fue JSON
    // (timeout del servidor, error fatal de PHP, etc.).
    async function refreshOne(button) {
      const body = new URLSearchParams({
        csrf_token: CSRF,
        address_id: button.dataset.addressId,
        chain: button.dataset.chain,
      });
      const res = await fetch('/api/refresh-chain.php', { method: 'POST', body });
      const text = await res.text();
      try {
        JSON.parse(text);
      } catch (e) {
        throw new Error('Respuesta inesperada del servidor (HTTP ' + res.status + '): ' + text.slice(0, 200));
      }
    }

    async function run(list) {
      const labels = list.map(b => b.textContent);
      buttons.forEach(b => b.disabled = true);
      if (allButton) allButton.disabled = true;
      try {
        for (let i = 0; i < list.length; i++) {
          list[i].textContent = 'Actualizando…';
          if (list.length > 1) statusEl.textContent = 'Actualizando ' + (i + 1) + ' de ' + list.length + '…';
          await refreshOne(list[i]);
        }
        window.location.reload();
      } catch (err) {
        list.forEach((b, i) => b.textContent = labels[i]);
        statusEl.textContent = err.message;
        buttons.forEach(b => b.disabled = false);
        if (allButton) allButton.disabled = false;
      }
    }

    buttons.forEach(b => b.addEventListener('click', () => run([b])));
    if (allButton) allButton.addEventListener('click', () => run(buttons));
  </script>
  <?php endif; ?>
</body>
</html>
