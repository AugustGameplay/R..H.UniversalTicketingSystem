<?php
/**
 * partials/auth.php
 * ─────────────────────────────────────────────────────────────
 * Autenticación + Control de Acceso (ACL) por rol.
 *
 * ROLES (id_role en tabla users):
 *   1 → Superadmin  → acceso total
 *   2 → Admin       → acceso total
 *   3 → Usuario general → SOLO: generarTickets + mis_tickets
 *
 * IMPORTANTE:
 * 1) En cada vista, define $active ANTES de incluir este archivo:
 *      $active = 'mis_tickets';
 *      require __DIR__ . '/partials/auth.php';
 *
 * 2) Este archivo también intenta detectar la página por el nombre del archivo
 *    si $active no viene definido (fallback).
 * ─────────────────────────────────────────────────────────────
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ── 1) ¿Hay sesión activa? ────────────────────────────────── */
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

/* ── 2) Datos de sesión ─────────────────────────────────────── */
$_AUTH_USER_ID   = (int)($_SESSION['user_id'] ?? 0);
$_AUTH_ROLE_ID   = (int)($_SESSION['id_role'] ?? 0);
$_AUTH_FULL_NAME = (string)($_SESSION['full_name'] ?? '');

/* ── 3) Permisos por página (valor de $active) ─────────────────
   null  = cualquier rol autenticado
   array = solo esos id_role tienen acceso
*/
$_PAGE_PERMISSIONS = [
    'generarTickets' => null,      // todos los roles autenticados
    'mis_tickets'    => null,      // todos los roles autenticados

    // Vistas avanzadas (solo 1 y 2)
    'tickets'        => [1, 2],
    'history'        => [1, 2],
    'users'          => [1, 2],
    'ticket_edit'    => [1, 2],
];

/* ── 4) Resolver página actual ──────────────────────────────── */
$_CURRENT_PAGE = $active ?? _auth_detect_page_key();

/* ── 5) Enforce ACL ─────────────────────────────────────────── */
if ($_CURRENT_PAGE !== '' && isset($_PAGE_PERMISSIONS[$_CURRENT_PAGE])) {
    $allowed = $_PAGE_PERMISSIONS[$_CURRENT_PAGE];

    // Si la página tiene lista de roles y el rol no está permitido → bloquear
    if ($allowed !== null && !in_array($_AUTH_ROLE_ID, $allowed, true)) {
        header('Location: ' . _auth_default_page($_AUTH_ROLE_ID));
        exit;
    }
} else {
    // Fallback de seguridad: si es rol 3 (usuario general) y no pudimos
    // identificar la página, permitimos solo generarTickets/mis_tickets.
    if ($_AUTH_ROLE_ID === 3) {
        $file = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
        $allowFiles = ['generarTickets.php', 'mis_tickets.php', 'logout.php'];
        if ($file !== '' && !in_array($file, $allowFiles, true)) {
            header('Location: generarTickets.php?denied=1');
            exit;
        }
    }
}

/**
 * Página por defecto según rol.
 */
function _auth_default_page(int $roleId): string {
    return match (true) {
        in_array($roleId, [1, 2], true) => 'tickets.php',
        default                         => 'generarTickets.php',
    };
}

/**
 * Detecta el key de la página a partir del archivo (fallback si falta $active).
 */
function _auth_detect_page_key(): string {
    $file = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');

    return match ($file) {
        'generarTickets.php' => 'generarTickets',
        'mis_tickets.php'    => 'mis_tickets',
        'tickets.php'        => 'tickets',
        'history.php'        => 'history',
        'users.php'          => 'users',
        'ticket_edit.php'    => 'ticket_edit',
        default              => '',
    };
}

/**
 * ¿Puede el usuario actual acceder a una página (key $active)?
 * Útil para mostrar/ocultar ítems en el menú.
 */
function auth_can(string $page): bool {
    global $_PAGE_PERMISSIONS, $_AUTH_ROLE_ID;

    if (!isset($_PAGE_PERMISSIONS[$page])) return false;

    $allowed = $_PAGE_PERMISSIONS[$page];
    return $allowed === null || in_array($_AUTH_ROLE_ID, $allowed, true);
}

/**
 * ¿El usuario actual es "usuario general" (id_role = 3)?
 */
function auth_is_usuario_general(): bool {
    global $_AUTH_ROLE_ID;
    return $_AUTH_ROLE_ID === 3;
}
