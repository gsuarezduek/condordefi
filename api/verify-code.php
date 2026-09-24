<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
app_session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = is_string($input['email'] ?? null) ? $input['email'] : '';
    $code = is_string($input['code'] ?? null) ? $input['code'] : '';

    [$ok, $message] = verify_login_code($email, $code);

    echo json_encode(['ok' => $ok, 'message' => $message, 'redirect' => $ok ? '/dashboard.php' : null]);
} catch (Throwable $e) {
    error_log('verify-code.php: ' . $e->getMessage());
    http_response_code(500);
    $detail = !empty($_SESSION['is_admin']) ? $e->getMessage() : 'Intentá de nuevo en unos minutos.';
    echo json_encode(['ok' => false, 'message' => 'Error interno: ' . $detail]);
}
