<?php
// Bloque de solo lectura con los datos de seguimiento de una cuenta.
// Requiere $account (fila de accounts) y $isAdmin; se incluye desde account.php.

$infoDate = fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '—';
$infoRows = [
    'Código' => $account['code'] ?? '—',
    'Fecha de inicio' => $infoDate($account['start_date']),
    'Próximo informe' => $infoDate($account['next_report_date']),
    'Último informe' => $infoDate($account['last_report_date']),
    'Fee de performance' => $account['performance_fee'] !== null ? rtrim(rtrim(number_format((float) $account['performance_fee'], 2, ',', ''), '0'), ',') . '%' : '—',
    'Marca de agua' => $account['high_water_mark'] !== null ? '$' . number_format((float) $account['high_water_mark'], 2, ',', '.') : '—',
];
$infoHasData = $account['code'] !== null || $account['start_date'] || $account['next_report_date'] || $account['last_report_date']
    || $account['last_report_url'] || $account['performance_fee'] !== null || $account['high_water_mark'] !== null;
?>
<?php if ($infoHasData || $isAdmin): ?>
  <div class="account-info">
    <?php foreach ($infoRows as $label => $value): ?>
      <div class="info-item">
        <div class="info-label"><?= htmlspecialchars($label, ENT_QUOTES) ?></div>
        <div class="info-value"><?= htmlspecialchars($value, ENT_QUOTES) ?></div>
      </div>
    <?php endforeach; ?>
    <?php if ($isAdmin && $account['commercial_id']): ?>
      <?php
        $stmt = db()->prepare('SELECT id, name FROM commercials WHERE id = ?');
        $stmt->execute([$account['commercial_id']]);
        $infoCommercial = $stmt->fetch();
      ?>
      <?php if ($infoCommercial): ?>
        <div class="info-item">
          <div class="info-label">Comercial</div>
          <div class="info-value"><a href="/commercial.php?id=<?= (int) $infoCommercial['id'] ?>"><?= htmlspecialchars($infoCommercial['name'], ENT_QUOTES) ?></a></div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($account['last_report_url']): ?>
      <div class="info-item">
        <div class="info-label">Link del informe</div>
        <div class="info-value"><a href="<?= htmlspecialchars($account['last_report_url'], ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer">Ver informe</a></div>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($isAdmin): ?>
    <a class="back" href="/account-info.php?account_id=<?= (int) $account['id'] ?>">Editar datos de la cuenta</a>
  <?php endif; ?>
<?php endif; ?>
