<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/auth.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = is_string($input['email'] ?? null) ? $input['email'] : '';
$code = is_string($input['code'] ?? null) ? $input['code'] : '';

[$ok, $message] = verify_login_code($email, $code);

echo json_encode(['ok' => $ok, 'message' => $message, 'redirect' => $ok ? '/dashboard.php' : null]);
