<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/accounts.php';
app_session_start();
require_admin();

$activePage = 'accounts';
$error = null;

$accountId = (int) ($_GET['account_id'] ?? $_POST['account_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM accounts WHERE id = ?');
$stmt->execute([$accountId]);
$account = $stmt->fetch();

if (!$account) {
    http_response_code(404);
    echo 'Cuenta no encontrada.';
    exit;
}

/** Fecha 'Y-m-d' válida, o null si viene vacía. Lanza InvalidArgumentException si es inválida. */
function parse_date_field(string $value, string $label): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException("$label no es una fecha válida.");
    }
    return $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $code = trim($_POST['code'] ?? '');
        if (mb_strlen($code) > 64 || preg_match('/[\x00-\x1F\x7F]/', $code)) {
            throw new InvalidArgumentException('El código puede tener hasta 64 caracteres, sin saltos de línea ni caracteres de control.');
        }

        $fee = str_replace(',', '.', trim($_POST['performance_fee'] ?? ''));
        if ($fee !== '' && (!is_numeric($fee) || (float) $fee < 0 || (float) $fee > 100)) {
            throw new InvalidArgumentException('El fee de performance tiene que ser un porcentaje entre 0 y 100.');
        }

        $commercialId = (int) ($_POST['commercial_id'] ?? 0);
        if ($commercialId > 0) {
            $stmt = db()->prepare('SELECT 1 FROM commercials WHERE id = ?');
            $stmt->execute([$commercialId]);
            if (!$stmt->fetch()) {
                throw new InvalidArgumentException('El comercial elegido no existe.');
            }
        }

        $startDate = parse_date_field($_POST['start_date'] ?? '', 'La fecha de inicio');
        $nextReport = parse_date_field($_POST['next_report_date'] ?? '', 'La fecha del próximo informe');
        $lastReport = parse_date_field($_POST['last_report_date'] ?? '', 'La fecha del último informe');

        $url = trim($_POST['last_report_url'] ?? '');
        if ($url !== '' && (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || strlen($url) > 500)) {
            throw new InvalidArgumentException('El link del último informe tiene que ser una URL http(s) válida.');
        }

        $hwm = str_replace(',', '.', trim($_POST['high_water_mark'] ?? ''));
        if ($hwm !== '' && (!is_numeric($hwm) || (float) $hwm < 0 || (float) $hwm >= 1e18)) {
            throw new InvalidArgumentException('La marca de agua tiene que ser un número positivo.');
        }

        $stmt = db()->prepare(
            'UPDATE accounts SET code = ?, start_date = ?, next_report_date = ?, last_report_date = ?,
                                 last_report_url = ?, high_water_mark = ?,
                                 performance_fee = ?, commercial_id = ? WHERE id = ?'
        );
        $stmt->execute([$code !== '' ? $code : null, $startDate, $nextReport, $lastReport, $url !== '' ? $url : null, $hwm !== '' ? $hwm : null,
            $fee !== '' ? $fee : null, $commercialId > 0 ? $commercialId : null, $accountId]);

        header('Location: /account.php?id=' . $accountId);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        // Que el formulario conserve lo que el admin escribió.
        $account = array_merge($account, [
            'code' => $_POST['code'] ?? '',
            'start_date' => $_POST['start_date'] ?? '',
            'next_report_date' => $_POST['next_report_date'] ?? '',
            'last_report_date' => $_POST['last_report_date'] ?? '',
            'last_report_url' => $_POST['last_report_url'] ?? '',
            'high_water_mark' => $_POST['high_water_mark'] ?? '',
            'performance_fee' => $_POST['performance_fee'] ?? '',
            'commercial_id' => (int) ($_POST['commercial_id'] ?? 0) ?: null,
        ]);
    }
}
$commercials = db()->query('SELECT id, name FROM commercials ORDER BY name')->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Datos — <?= htmlspecialchars($account['name'], ENT_QUOTES) ?> — CondorDeFi</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
<style>
  .back{color:var(--text-dim);font-size:12.5px;text-decoration:none;display:inline-block;margin-bottom:10px;}
  .back:hover{color:#fff;}
  input[type=date],input[type=url],input[type=number]{
    width:100%;
    padding:10px 12px;
    border:1px solid var(--border);
    border-radius:8px;
    font-size:14px;
    background:#fcfbf8;
    outline:none;
  }
  select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:#fcfbf8;outline:none;}
  .hint{font-size:11.5px;color:#8a8a8a;margin-top:4px;}
</style>
</head>
<body>
  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="main">
    <a class="back" href="/account.php?id=<?= $accountId ?>">&larr; <?= htmlspecialchars($account['name'], ENT_QUOTES) ?></a>
    <h1>Datos de la cuenta — <?= htmlspecialchars($account['name'], ENT_QUOTES) ?></h1>

    <div class="card">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="account_id" value="<?= $accountId ?>">
        <div class="field">
          <label for="code">Código</label>
          <input type="text" id="code" name="code" maxlength="64" autocomplete="off" value="<?= htmlspecialchars((string) $account['code'], ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label for="start_date">Fecha de inicio</label>
          <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars((string) $account['start_date'], ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label for="next_report_date">Próximo informe</label>
          <input type="date" id="next_report_date" name="next_report_date" value="<?= htmlspecialchars((string) $account['next_report_date'], ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label for="last_report_date">Último informe — fecha</label>
          <input type="date" id="last_report_date" name="last_report_date" value="<?= htmlspecialchars((string) $account['last_report_date'], ENT_QUOTES) ?>">
        </div>
        <div class="field" style="flex-basis:100%;">
          <label for="last_report_url">Último informe — link</label>
          <input type="url" id="last_report_url" name="last_report_url" placeholder="https://..." maxlength="500" value="<?= htmlspecialchars((string) $account['last_report_url'], ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label for="high_water_mark">Marca de agua (USD)</label>
          <input type="number" id="high_water_mark" name="high_water_mark" step="0.01" min="0" value="<?= htmlspecialchars((string) $account['high_water_mark'], ENT_QUOTES) ?>">
          <div class="hint">High-water mark: el valor máximo alcanzado sobre el que se calculan comisiones.</div>
        </div>
        <div class="field">
          <label for="performance_fee">Fee de performance (%)</label>
          <input type="number" id="performance_fee" name="performance_fee" step="0.01" min="0" max="100" value="<?= htmlspecialchars((string) $account['performance_fee'], ENT_QUOTES) ?>">
        </div>
        <div class="field">
          <label for="commercial_id">Comercial</label>
          <select id="commercial_id" name="commercial_id">
            <option value="0">— Sin comercial —</option>
            <?php foreach ($commercials as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) $account['commercial_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name'], ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($commercials)): ?>
            <div class="hint">Todavía no hay comerciales. Crealos en <a href="/commercials.php">Comerciales</a>.</div>
          <?php endif; ?>
        </div>
        <div style="flex-basis:100%;"><button type="submit">Guardar</button></div>
      </form>
      <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
