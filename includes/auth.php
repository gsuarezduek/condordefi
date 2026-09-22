<?php

require_once __DIR__ . '/db.php';

const CODE_LENGTH = 6;
const CODE_TTL_MINUTES = 10;
const MAX_ATTEMPTS = 5;

function generate_code(): string
{
    return str_pad((string) random_int(0, 999999), CODE_LENGTH, '0', STR_PAD_LEFT);
}

/** @return array{0: bool, 1: string} */
function request_login_code(string $email): array
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Email inválido.'];
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if (!$stmt->fetch()) {
        return [false, 'Ese email no tiene una cuenta creada.'];
    }

    $code = generate_code();
    $hash = hash('sha256', $code);
    $expiresAt = (new DateTime('+' . CODE_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare('INSERT INTO login_codes (email, code_hash, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([$email, $hash, $expiresAt]);

    send_code_email($email, $code);

    return [true, 'Te enviamos un código a tu email.'];
}

function send_code_email(string $email, string $code): void
{
    $config = require __DIR__ . '/../config.php';
    $from = $config['mail']['from'];
    $fromName = $config['mail']['from_name'];

    $subject = "Tu código de acceso: $code";
    $body = "Tu código de acceso es: $code\n\n"
        . 'Expira en ' . CODE_TTL_MINUTES . " minutos.\n"
        . 'Si no lo pediste vos, ignorá este mensaje.';
    $headers = "From: $fromName <$from>\r\nContent-Type: text/plain; charset=utf-8";

    mail($email, $subject, $body, $headers);
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

    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return [false, 'Ese email no tiene una cuenta creada.'];
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_email'] = $user['email'];

    return [true, 'OK'];
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /index.php');
        exit;
    }
}
