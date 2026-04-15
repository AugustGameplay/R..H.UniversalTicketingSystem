<?php
/**
 * api/notifications.php
 * Endpoint for real-time ticket notifications.
 * Only accessible by Managers and IT Support (Roles 1 & 2).
 */

require __DIR__ . '/../partials/auth.php';
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Only roles 1 and 2 can receive these notifications
if (!in_array($_AUTH_ROLE_ID, [1, 2], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$isInit = !empty($_GET['init']);
$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

try {
    if ($isInit) {
        $stmt = $pdo->query("SELECT MAX(id_ticket) AS max_id FROM tickets");
        $max = (int)$stmt->fetchColumn();
        echo json_encode([
            'status' => 'ok',
            'latest_id' => $max,
            'tickets' => []
        ]);
        exit;
    }

    // Fetch new tickets
    $stmt = $pdo->prepare("
        SELECT t.id_ticket, t.category, t.type, t.area, u.full_name AS creator_name
        FROM tickets t
        LEFT JOIN users u ON u.id_user = t.id_user
        WHERE t.id_ticket > :last_id
        ORDER BY t.id_ticket ASC
    ");
    $stmt->execute([':last_id' => $lastId]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $newLastId = $lastId;
    if (!empty($tickets)) {
        $newLastId = (int)end($tickets)['id_ticket'];
    }

    echo json_encode([
        'status' => 'ok',
        'latest_id' => $newLastId,
        'tickets' => $tickets
    ]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
