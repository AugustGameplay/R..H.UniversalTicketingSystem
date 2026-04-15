<?php
/**
 * api/notifications.php
 * ─────────────────────────────────────────────
 * Polling endpoint for real-time ticket notifications.
 * Only returns data for Admin (id_role=2) and Superadmin (id_role=1).
 *
 * Query params:
 *   ?init=1       → Returns the latest ticket ID (baseline, no notifications)
 *   ?last_id=N    → Returns tickets created after ID N
 *
 * Response (JSON):
 *   { status: "ok", latest_id: N, tickets: [...] }
 *   { status: "denied" }   — if user is not admin
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Start session to check role
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Must be logged in
if (empty($_SESSION['user_id'])) {
    echo json_encode(['status' => 'denied']);
    exit;
}

$roleId = (int)($_SESSION['id_role'] ?? 0);

// Only Admin (2) and Superadmin (1) get notifications
if (!in_array($roleId, [1, 2], true)) {
    echo json_encode(['status' => 'denied']);
    exit;
}

require __DIR__ . '/../config/db.php';

$isInit = isset($_GET['init']);
$lastId = (int)($_GET['last_id'] ?? 0);

try {
    if ($isInit) {
        // Just return the latest ticket ID as a baseline
        $stmt = $pdo->query("SELECT COALESCE(MAX(id_ticket), 0) AS max_id FROM tickets");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'status'    => 'ok',
            'latest_id' => (int)$row['max_id'],
            'tickets'   => []
        ]);
    } else {
        // Return new tickets since last_id
        $stmt = $pdo->prepare("
            SELECT 
                t.id_ticket,
                t.type,
                t.category,
                t.area,
                t.comments,
                t.created_at,
                u.full_name AS creator_name
            FROM tickets t
            LEFT JOIN users u ON u.id_user = t.id_user
            WHERE t.id_ticket > :last_id
            ORDER BY t.id_ticket ASC
            LIMIT 10
        ");
        $stmt->execute([':last_id' => $lastId]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get latest ID
        $latestId = $lastId;
        if (!empty($tickets)) {
            $latestId = (int)$tickets[count($tickets) - 1]['id_ticket'];
        }

        echo json_encode([
            'status'    => 'ok',
            'latest_id' => $latestId,
            'tickets'   => $tickets
        ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
