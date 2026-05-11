<?php
/**
 * api/session_ping.php
 * ─────────────────────────────────────────────────────────────
 * Endpoint ligero para mantener viva la sesión PHP.
 * El JS del timeout hace POST aquí cada vez que detecta actividad real.
 * ─────────────────────────────────────────────────────────────
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Si no hay sesión activa, 401
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'reason' => 'not_authenticated']);
    exit;
}

// Actualizar marca de actividad
$_SESSION['last_activity'] = time();

echo json_encode(['ok' => true, 'ts' => $_SESSION['last_activity']]);
