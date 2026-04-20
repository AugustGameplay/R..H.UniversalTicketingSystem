<?php
/**
 * TicketRepository.php
 * Abstracción de base de datos para la gestión de tickets.
 * Concentra las consultas (SELECT, COUNT, DELETE) de tickets.php, mis_tickets.php, y history.php
 */

class TicketRepository {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Mapeo de UI de estado al valor real de BD
     */
    public function mapStateUIToDB(string $stateUI): string {
        $map = [
            'Open'        => 'Pendiente',
            'In progress' => 'En Proceso',
            'On hold'     => 'Pendiente',
            'Resolved'    => 'Resuelto',
            'Cancelled'   => 'Cerrado',
        ];
        return $map[$stateUI] ?? $stateUI;
    }

    /**
     * Construye dinámicamente las partes de la query FROM, WHERE, PARAMS
     */
    private function buildQueryParts(array $filters): array {
        $where = [];
        $params = [];

        // FROM base
        $from = "FROM tickets t 
                 LEFT JOIN users u ON u.id_user = t.assigned_user_id
                 LEFT JOIN users uc ON uc.id_user = t.id_user";

        // Filter: owner (id_user creator) -> used by mis_tickets.php
        if (isset($filters['owner_id']) && $filters['owner_id'] > 0) {
            $where[] = "t.id_user = :owner_id";
            $params[':owner_id'] = $filters['owner_id'];
        }

        // Filter: search query (q)
        if (!empty($filters['q'])) {
            $searchParts = [
                "CAST(t.id_ticket AS CHAR) LIKE :q1",
                "t.area LIKE :q2",
                "t.type LIKE :q3",
                "t.comments LIKE :q4",
                "u.full_name LIKE :q5",
                "uc.full_name LIKE :q6"
            ];
            $where[] = "(" . implode(" OR ", $searchParts) . ")";
            $qVal = "%" . $filters['q'] . "%";
            $params[':q1'] = $qVal;
            $params[':q2'] = $qVal;
            $params[':q3'] = $qVal;
            $params[':q4'] = $qVal;
            $params[':q5'] = $qVal;
            $params[':q6'] = $qVal;
        }

        // Filter: status
        if (!empty($filters['status']) && $filters['status'] !== 'Todos' && $filters['status'] !== 'Filter by state') {
            $dbState = $this->mapStateUIToDB($filters['status']);
            $where[] = "t.status = :status";
            $params[':status'] = $dbState;
        }

        // Filter: priority
        if (!empty($filters['priority']) && $filters['priority'] !== 'Priority') {
            $where[] = "t.priority = :priority";
            $params[':priority'] = $filters['priority'];
        }

        // Filter: assignedTo
        if (!empty($filters['assigned']) && $filters['assigned'] !== 'Assigned To') {
            $where[] = "t.assigned_user_id = :assigned";
            $params[':assigned'] = $filters['assigned'];
        }

        $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

        return [$from, $whereSql, $params];
    }

    /**
     * Cuenta el total de tickets según los filtros
     */
    public function countTickets(array $filters = []): int {
        [$from, $whereSql, $params] = $this->buildQueryParts($filters);
        
        $stmtTotal = $this->pdo->prepare("SELECT COUNT(*) $from $whereSql");
        $stmtTotal->execute($params);
        return (int)$stmtTotal->fetchColumn();
    }

    /**
     * Recupera el listado de tickets paginado y ordenado
     */
    public function getTickets(array $filters = [], int $perPage = 10, int $offset = 0, string $orderBySql = "t.id_ticket DESC"): array {
        [$from, $whereSql, $params] = $this->buildQueryParts($filters);

        try {
            $sql = "
                SELECT
                    t.id_ticket,
                    t.category,
                    t.type,
                    t.area,
                    t.comments,
                    t.status,
                    t.priority,
                    t.created_at,
                    t.ticket_url,
                    t.attachment_path,
                    COALESCE(u.full_name, '—') AS assigned_name,
                    NULLIF(uc.full_name, '') AS created_by_name
                $from
                $whereSql
                ORDER BY $orderBySql
                LIMIT $perPage OFFSET $offset
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("TicketRepository error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene contadores agrupados por status (ej. para mis_tickets.php)
     */
    public function getStatusSummary(int $userId = 0): array {
        $statusCounts = [
            'Pendiente'  => 0,
            'En Proceso' => 0,
            'Resuelto'   => 0,
            'Cerrado'    => 0,
        ];
        
        $where = "WHERE 1=1";
        $params = [];
        if ($userId > 0) {
            $where = "WHERE t.id_user = :uid";
            $params[':uid'] = $userId;
        }

        try {
            $stmtSummary = $this->pdo->prepare("
                SELECT status, COUNT(*) AS total
                FROM tickets t
                $where
                GROUP BY status
                ORDER BY FIELD(status, 'Pendiente', 'En Proceso', 'Resuelto', 'Cerrado')
            ");
            $stmtSummary->execute($params);
            $summaryRaw = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($summaryRaw as $row) {
                $s = (string)($row['status'] ?? '');
                $n = (int)($row['total'] ?? 0);
                if (isset($statusCounts[$s])) $statusCounts[$s] += $n;
            }
        } catch (Throwable $e) {}

        return $statusCounts;
    }

    /**
     * Borra un ticket y opcionalmente su evidencia
     */
    public function deleteTicket(int $ticketId): bool {
        if ($ticketId <= 0) return false;

        try {
            // Guardar adjunto
            $stmtEv = $this->pdo->prepare("SELECT attachment_path FROM tickets WHERE id_ticket = :id LIMIT 1");
            $stmtEv->execute([':id' => $ticketId]);
            $att = (string)($stmtEv->fetchColumn() ?: '');

            // Borrar registro
            $stmtDel = $this->pdo->prepare("DELETE FROM tickets WHERE id_ticket = :id");
            $stmtDel->execute([':id' => $ticketId]);

            // Borrar físico si es local y no explota fuera de uploads
            if ($att !== '') {
                $rel = ltrim(str_replace('\\', '/', $att), '/');
                if (strpos($rel, 'public/') === 0) $rel = substr($rel, 7);
                if (strpos($rel, '..') === false && strpos($rel, 'uploads/') === 0) {
                    $baseUploads = realpath(__DIR__ . '/../uploads');
                    $full = realpath(__DIR__ . '/../' . $rel);
                    if ($baseUploads && $full && strpos($full, $baseUploads) === 0 && is_file($full)) {
                        @unlink($full);
                    }
                }
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
