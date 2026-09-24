<?php

require_once __DIR__ . '/db.php';

const CODE_LENGTH = 6;
const CODE_TTL_MINUTES = 10;
const MAX_ATTEMPTS = 5;
const MAX_CODES_PER_EMAIL_WINDOW = 3;
const MAX_CODES_PER_IP_WINDOW = 10;
const RATE_LIMIT_WINDOW_MINUTES = 10;

/**
 * Arranca la sesión con cookies seguras. Reemplaza a session_start() en
 * todos los puntos de entrada (páginas y endpoints de /api).
 */
function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function generate_code(): string
{
    return str_pad((string) random_int(0, 999999), CODE_LENGTH, '0', STR_PAD_LEFT);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** @return array{0: bool, 1: string} */
function request_login_code(string $email): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Email inválido.'];
    }

    $pdo = db();
    $ip = client_ip();
    // La ventana se calcula con el reloj de la base (NOW()), el mismo que graba
    // created_at: comparar con la hora de PHP falla si las zonas horarias difieren.
    $window = 'created_at > (NOW() - INTERVAL ' . (int) RATE_LIMIT_WINDOW_MINUTES . ' MINUTE)';

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM login_codes WHERE email = ? AND $window");
    $stmt->execute([$email]);
    if ((int) $stmt->fetch()['c'] >= MAX_CODES_PER_EMAIL_WINDOW) {
        return [false, 'Pediste demasiados códigos. Esperá unos minutos e intentá de nuevo.'];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM login_codes WHERE ip = ? AND $window");
    $stmt->execute([$ip]);
    if ((int) $stmt->fetch()['c'] >= MAX_CODES_PER_IP_WINDOW) {
        return [false, 'Demasiados intentos desde esta red. Esperá unos minutos e intentá de nuevo.'];
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if (!$stmt->fetch()) {
        return [false, 'Ese email no tiene una cuenta creada.'];
    }

    $code = generate_code();
    $hash = hash('sha256', $code);
    $expiresAt = (new DateTime('+' . CODE_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('INSERT INTO login_codes (email, code_hash, expires_at, ip) VALUES (?, ?, ?, ?)');
    $stmt->execute([$email, $hash, $expiresAt, $ip]);

    if (!send_code_email($email, $code)) {
        return [false, 'El servidor no pudo enviar el email (falló mail()). Revisar configuración de correo.'];
    }

    return [true, 'Te enviamos un código a tu email.'];
}

function send_code_email(string $email, string $code): bool
{
    $config = require __DIR__ . '/../config.php';
    $from = $config['mail']['from'];
    $fromName = $config['mail']['from_name'];

    $subject = "Tu código de acceso: $code";
    $body = "Tu código de acceso es: $code\n\n"
        . 'Expira en ' . CODE_TTL_MINUTES . " minutos.\n"
        . 'Si no lo pediste vos, ignorá este mensaje.';
    $headers = "From: $fromName <$from>\r\nContent-Type: text/plain; charset=utf-8";

    return mail($email, $subject, $body, $headers);
}

/** @return array{0: bool, 1: string} */
function verify_login_code(string $email, string $code): array
{
    $email = strtolower(trim($email));
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM login_codes WHERE email = ? AND consumed_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        return [false, 'Pedí un código nuevo.'];
    }
    if (strtotime($row['expires_at']) < time()) {
        return [false, 'El código expiró, pedí uno nuevo.'];
    }
    if ($row['attempts'] >= MAX_ATTEMPTS) {
        return [false, 'Demasiados intentos, pedí un código nuevo.'];
    }

    $stmt = $pdo->prepare('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?');
    $stmt->execute([$row['id']]);

    if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
        return [false, 'Código incorrecto.'];
    }

    $stmt = $pdo->prepare('UPDATE login_codes SET consumed_at = NOW() WHERE id = ?');
    $stmt->execute([$row['id']]);

    $stmt = $pdo->prepare('SELECT id, email, name, is_admin FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return [false, 'Ese email no tiene una cuenta creada.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['is_admin'] = (bool) $user['is_admin'];

    return [true, 'OK'];
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /index.php');
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo 'No tenés permisos para acceder a esta página.';
        exit;
    }
}

/**
 * Cuentas visibles para el usuario logueado: todas si es admin,
 * o solo las asignadas en account_user si no lo es.
 */
function accessible_accounts(): array
{
    if (!empty($_SESSION['is_admin'])) {
        return db()->query('SELECT * FROM accounts ORDER BY created_at DESC')->fetchAll();
    }

    $stmt = db()->prepare(
        'SELECT a.* FROM accounts a
         JOIN account_user au ON au.account_id = a.id
         WHERE au.user_id = ?
         ORDER BY a.created_at DESC'
    );
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetchAll();
}

/** Corta con 403 si el usuario logueado no puede ver esta cuenta. */
function require_account_access(int $accountId): void
{
    require_login();
    if (!empty($_SESSION['is_admin'])) {
        return;
    }

    $stmt = db()->prepare('SELECT 1 FROM account_user WHERE account_id = ? AND user_id = ?');
    $stmt->execute([$accountId, $_SESSION['user_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo 'No tenés acceso a esta cuenta.';
        exit;
    }
}

/** Token CSRF para el usuario logueado (uno por sesión, no por formulario). */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Input hidden listo para poner dentro de un <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Corta con 403 si el POST no trae un csrf_token válido para esta sesión. */
function csrf_check(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo 'Token de seguridad inválido. Volvé a cargar la página e intentá de nuevo.';
        exit;
    }
}
