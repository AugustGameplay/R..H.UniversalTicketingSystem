<?php
/**
 * partials/auth.php
 * ─────────────────────────────────────────────────────────────
 * Autenticación + control de acceso por rol.
 *
 * ROLES (tabla roles / campo id_role en users):
 *   1 → Superadmin   → acceso total
 *   2 → Admin        → acceso total
 *   3 → IT Support   → tickets, ticket_edit, history
 *   4 → Operaciones  → SOLO generarTickets + mis_tickets
 *
 * CÓMO RESTRINGIR UNA PÁGINA:
 *   require __DIR__ . '/partials/auth.php';
 *   // La variable $active se usa ANTES de require para indicar la página activa.
 *   // El control de acceso se hace aquí automáticamente según $active y el rol.
 *
 * PÁGINAS (valor de $active):
 *   'generarTickets'  → todos los roles autenticados
 *   'mis_tickets'     → todos los roles autenticados
 *   'tickets'         → Superadmin, Admin, IT Support
 *   'history'         → Superadmin, Admin, IT Support
 *   'users'           → Superadmin, Admin
 * ─────────────────────────────────────────────────────────────
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ── 1. ¿Hay sesión activa? ───────────────────────────────────
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// ── 2. Leer datos de sesión ──────────────────────────────────
$_AUTH_USER_ID   = (int)$_SESSION['user_id'];
$_AUTH_ROLE_ID   = (int)($_SESSION['id_role'] ?? 0);
$_AUTH_FULL_NAME = (string)($_SESSION['full_name'] ?? '');

// ── 3. Mapa de permisos por página ───────────────────────────
//   null  = cualquier rol autenticado
//   array = solo esos id_role tienen acceso
$_PAGE_PERMISSIONS = [
    'generarTickets' => null,          // todos
    'mis_tickets'    => null,          // todos
    'tickets'        => [1, 2, 3],     // Super, Admin, IT
    'history'        => [1, 2, 3],     // Super, Admin, IT
    'users'          => [1, 2],        // Super, Admin
];

// ── 4. Verificar acceso a la página actual ───────────────────
$_CURRENT_PAGE = $active ?? '';

if (isset($_PAGE_PERMISSIONS[$_CURRENT_PAGE])) {
    $allowed = $_PAGE_PERMISSIONS[$_CURRENT_PAGE];
    if ($allowed !== null && !in_array($_AUTH_ROLE_ID, $allowed, true)) {
        // Sin permiso → redirigir a la página por defecto del rol
        header('Location: ' . _auth_default_page($_AUTH_ROLE_ID));
        exit;
    }
}

/**
 * Devuelve la página de inicio según el rol.
 */
function _auth_default_page(int $roleId): string {
    return match (true) {
        in_array($roleId, [1, 2, 3]) => 'tickets.php',
        default                       => 'generarTickets.php',
    };
}

/**
 * ¿Puede el usuario actual acceder a una página?
 * Útil para mostrar/ocultar ítems en el menú.
 */
function auth_can(string $page): bool {
    global $_PAGE_PERMISSIONS, $_AUTH_ROLE_ID;
    if (!isset($_PAGE_PERMISSIONS[$page])) return false;
    $allowed = $_PAGE_PERMISSIONS[$page];
    return $allowed === null || in_array($_AUTH_ROLE_ID, $allowed, true);
}

/**
 * ¿El usuario actual tiene el rol Operaciones (id_role = 4)?
 */
function auth_is_operaciones(): bool {
    global $_AUTH_ROLE_ID;
    return $_AUTH_ROLE_ID === 4;
}
