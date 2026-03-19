<?php
/**
 * mis_tickets.php
 * Vista personal: el usuario ve SOLO sus propios tickets y su estado.
 * Accesible para TODOS los roles autenticados.
 */
$active = 'mis_tickets';
require __DIR__ . '/partials/auth.php';

require __DIR__ . '/config/db.php';

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ── ID del usuario en sesión ─────────────────────────────────
$userId = $_AUTH_USER_ID; // viene de auth.php

// ── Filtros GET ──────────────────────────────────────────────
$filterStatus = trim($_GET['status'] ?? '');
$q            = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 8;

// Ignorar placeholder
if ($filterStatus === 'Todos') $filterStatus = '';

// ── WHERE ────────────────────────────────────────────────────
$where  = ['t.id_user = :uid'];
$params = [':uid' => $userId];

if ($filterStatus !== '') {
    $where[]          = 't.status = :status';
    $params[':status'] = $filterStatus;
}

if ($q !== '') {
    $where[]    = '(CAST(t.id_ticket AS CHAR) LIKE :q OR t.area LIKE :q OR t.type LIKE :q OR t.comments LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// ── TOTAL ────────────────────────────────────────────────────
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM tickets t $whereSql");
$stmtTotal->execute($params);
$total      = (int)$stmtTotal->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$offset     = ($page - 1) * $perPage;

// ── Resumen de estados (para las tarjetas superiores) ────────
$summaryBase = ['t.id_user = :suid'];
$summaryParams = [':suid' => $userId];

try {
    $stmtSummary = $pdo->prepare("
        SELECT status, COUNT(*) AS total
        FROM tickets t
        WHERE t.id_user = :suid
        GROUP BY status
        ORDER BY FIELD(status, 'Pendiente', 'En Proceso', 'Resuelto', 'Cerrado')
    ");
    $stmtSummary->execute([':suid' => $userId]);
    $summaryRaw = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $summaryRaw = [];
}

// Construir mapa estado → count
$statusCounts = [
    'Pendiente'  => 0,
    'En Proceso' => 0,
    'Resuelto'   => 0,
    'Cerrado'    => 0,
];
$totalAll = 0;
foreach ($summaryRaw as $row) {
    $s = (string)($row['status'] ?? '');
    $n = (int)($row['total'] ?? 0);
    if (isset($statusCounts[$s])) $statusCounts[$s] += $n;
    $totalAll += $n;
}

// ── DATA ─────────────────────────────────────────────────────
$tickets = [];
try {
    $stmt = $pdo->prepare("
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
            COALESCE(u.full_name, '—') AS assigned_name
        FROM tickets t
        LEFT JOIN users u ON u.id_user = t.assigned_user_id
        $whereSql
        ORDER BY
            FIELD(t.status, 'Pendiente', 'En Proceso', 'Resuelto', 'Cerrado'),
            t.id_ticket DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $tickets = [];
}

// ── Helpers UI ───────────────────────────────────────────────
function ui_status_label(string $db): string {
    return match ($db) {
        'Pendiente'  => 'Pending',
        'En Proceso' => 'In Progress',
        'Resuelto'   => 'Resolved',
        'Cerrado'    => 'Closed',
        default      => $db,
    };
}

function ui_status_class(string $db): string {
    return match ($db) {
        'Pendiente'  => 'st-open',
        'En Proceso' => 'st-progress',
        'Resuelto'   => 'st-done',
        'Cerrado'    => 'st-cancel',
        default      => 'st-open',
    };
}

function ui_prio_label(string $prio): string {
    return match ($prio) {
        'Baja'    => 'Low',
        'Media'   => 'Medium',
        'Alta'    => 'High',
        'Urgente' => 'Urgent',
        default   => $prio,
    };
}

function ui_prio_class(string $prio): string {
    return match ($prio) {
        'Baja'    => 'prio-low',
        'Media'   => 'prio-medium',
        'Alta'    => 'prio-high',
        'Urgente' => 'prio-urgent',
        default   => 'prio-medium',
    };
}

function build_url(array $overrides = []): string {
    $base = $_GET;
    foreach ($overrides as $k => $v) $base[$k] = $v;
    return 'mis_tickets.php?' . http_build_query($base);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Tickets | RH&amp;R Ticketing</title>
    <link rel="icon" type="image/png" href="./assets/img/isotopo.png" />

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <link rel="stylesheet" href="./assets/css/generarTickets.css">
    <link rel="stylesheet" href="./assets/css/menu.css">
    <link rel="stylesheet" href="./assets/css/movil.css">
    <link rel="stylesheet" href="./assets/css/tickets.css">

    <script defer src="./assets/js/sidebar.js"></script>

    <style>
        /* ── Premium HQ "Linear" Theme for mis_tickets ─────────────────────────────── */
        :root {
          --slate-50:  #f8fafc;
          --slate-100: #f1f5f9;
          --slate-200: #e2e8f0;
          --slate-300: #cbd5e1;
          --slate-400: #94a3b8;
          --slate-500: #64748b;
          --slate-600: #475569;
          --slate-700: #334155;
          --slate-800: #1e293b;
          --slate-900: #0f172a;
          --brand: #0f5a8a;
          --brand-hover: #0a4267;
          --radius-lg: 16px;
          --radius-md: 12px;
          --radius-sm: 8px;
        }

        body.tickets-page {
          background: var(--slate-50);
          font-family: 'Inter', system-ui, -apple-system, sans-serif;
          color: var(--slate-800);
        }

        /* Container adjustments */
        .tickets-hq-wrapper {
          max-width: 1040px;
          margin: 0 auto;
          padding: 2rem 1rem;
          width: 100%;
        }

        .hq-title {
          font-size: 1.6rem;
          font-weight: 800;
          color: var(--slate-900);
          letter-spacing: -0.02em;
        }

        .hq-subtitle {
          color: var(--slate-500);
          font-weight: 500;
          font-size: 0.9rem;
        }

        /* ── Summary cards ─────────────────────────────── */
        .mis-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .mis-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 20px 16px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--slate-200);
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .mis-stat:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            border-color: var(--slate-300);
        }
        .mis-stat.is-active {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(15, 90, 138, 0.1), 0 4px 6px -1px rgba(0,0,0,0.05);
        }

        .mis-stat__num {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            color: var(--slate-900);
        }
        .mis-stat__label {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--slate-500);
        }

        /* Accentos de color */
        .mis-stat--pendiente .mis-stat__num { color: #b45309; }
        .mis-stat--proceso   .mis-stat__num { color: #1d4ed8; }
        .mis-stat--resuelto  .mis-stat__num { color: #047857; }
        .mis-stat--cerrado   .mis-stat__num { color: var(--slate-400); }

        /* ── Filtros y search ───────────────────────────── */
        .mis-filters {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .mis-search-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-md);
            padding: 10px 16px;
            flex: 1;
            min-width: 200px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.01) inset;
            transition: all 0.2s ease;
        }
        .mis-search-wrap:focus-within {
            border-color: rgba(15, 90, 138, 0.5);
            box-shadow: 0 0 0 3px rgba(15, 90, 138, 0.1);
        }
        .mis-search-wrap i { color: var(--slate-400); font-size: 14px; }
        .mis-search-input {
            background: transparent;
            border: 0;
            outline: none;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--slate-800);
            width: 100%;
        }
        .mis-search-input::placeholder { color: var(--slate-400); font-weight: 500; }

        .mis-filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 99px;
            border: 1px solid var(--slate-200);
            background: #fff;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--slate-600);
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .mis-filter-chip:hover {
            background: var(--slate-50);
            border-color: var(--slate-300);
            color: var(--slate-900);
        }

        /* ── Ticket card list ────────────── */
        .mis-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }

        .mis-card {
            background: #fff;
            border: 1px solid var(--slate-200);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s ease;
            position: relative;
            display: block;
            text-decoration: none;
            color: inherit;
        }
        .mis-card:hover {
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
            border-color: var(--slate-300);
            transform: translateY(-1px);
        }

        .mis-card__top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .mis-card__id {
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            color: var(--slate-500);
            background: var(--slate-50);
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid var(--slate-200);
            letter-spacing: 0.05em;
        }

        .mis-card__badges {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .hq-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 99px;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border: 1px solid transparent;
        }
        .hq-badge-status-pending { background: #fffbeb; color: #b45309; border-color: #fce7f3; }
        .hq-badge-status-process { background: #eff6ff; color: #1d4ed8; border-color: #dbeafe; }
        .hq-badge-status-resolved { background: #ecfdf5; color: #047857; border-color: #d1fae5; }
        .hq-badge-status-closed { background: var(--slate-100); color: var(--slate-600); border-color: var(--slate-200); }
        .hq-badge-prio-low { background: #f8fafc; color: #64748b; border-color: #e2e8f0; }
        .hq-badge-prio-medium { background: #eff6ff; color: #2563eb; border-color: #bfdbfe; }
        .hq-badge-prio-high { background: #fff7ed; color: #c2410c; border-color: #ffedd5; }
        .hq-badge-prio-urgent { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }

        .mis-card__title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--slate-900);
            margin-bottom: 8px;
        }

        .mis-card__comments {
            font-size: 0.9rem;
            color: var(--slate-500);
            line-height: 1.5;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 16px;
        }

        .mis-card__meta {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            border-top: 1px dashed var(--slate-200);
            padding-top: 16px;
        }

        .mis-card__meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--slate-500);
        }
        .mis-card__meta-item i { font-size: 0.85rem; opacity: 0.7; }

        .mis-card__meta-item.text-brand { color: var(--brand); }
        .mis-card__meta-item.text-brand i { color: var(--brand); opacity: 1; }
        .mis-card__meta-item.text-brand:hover { text-decoration: underline; }

        /* ── New ticket CTA ─────────────────────────────── */
        .mis-cta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            background: linear-gradient(180deg, var(--brand), var(--brand-hover));
            color: #fff;
            font-size: 0.9rem;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid var(--brand-hover);
            box-shadow: 0 1px 2px rgba(15, 90, 138, 0.3), inset 0 1px 0 rgba(255,255,255,0.1);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .mis-cta:hover {
            background: linear-gradient(180deg, #116a9e, var(--brand-hover));
            box-shadow: 0 4px 10px rgba(15, 90, 138, 0.2), inset 0 1px 0 rgba(255,255,255,0.15);
            color: #fff;
        }
        .mis-cta:active { transform: scale(0.97); }

        /* Empty state */
        .mis-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            padding: 80px 20px;
            color: var(--slate-400);
            background: #fff;
            border: 1px dashed var(--slate-300);
            border-radius: var(--radius-lg);
        }
        .mis-empty i { font-size: 48px; opacity: 0.5; margin-bottom: 8px; color: var(--slate-300); }
        .mis-empty p { font-size: 1rem; font-weight: 600; margin: 0; color: var(--slate-500); }
        
        /* Pagination overrides */
        .pagination .page-link {
          color: var(--slate-600);
          border-color: var(--slate-200);
          font-weight: 500;
        }
        .pagination .page-item.active .page-link {
          background-color: var(--brand);
          border-color: var(--brand);
          color: white;
        }
        .pagination .page-link:hover {
          background-color: var(--slate-50);
          color: var(--slate-900);
        }
    </style>
</head>

<body class="tickets-page">
<div class="layout d-flex">

    <!-- SIDEBAR -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 p-0 p-md-4">
        <div class="tickets-hq-wrapper">

            <!-- ── Header ──────────────────────────────── -->
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-4">
                <div>
                    <h1 class="hq-title m-0">My Tickets</h1>
                    <p class="hq-subtitle m-0 mt-1">
                        Personal history of your requests
                    </p>
                </div>
                <a href="generarTickets.php" class="mis-cta">
                    <i class="fa-solid fa-plus"></i> New Ticket
                </a>
            </div>

            <!-- ── Summary cards ───────────────────────── -->
            <div class="mis-summary">

                <a href="<?= esc(build_url(['status' => '', 'page' => 1])) ?>"
                   class="mis-stat mis-stat--total<?= $filterStatus === '' ? ' is-active' : '' ?>">
                    <div class="mis-stat__num"><?= $totalAll ?></div>
                    <div class="mis-stat__label">Total</div>
                </a>

                <a href="<?= esc(build_url(['status' => 'Pendiente', 'page' => 1])) ?>"
                   class="mis-stat mis-stat--pendiente<?= $filterStatus === 'Pendiente' ? ' is-active' : '' ?>">
                    <div class="mis-stat__num"><?= $statusCounts['Pendiente'] ?></div>
                    <div class="mis-stat__label">Pending</div>
                </a>

                <a href="<?= esc(build_url(['status' => 'En Proceso', 'page' => 1])) ?>"
                   class="mis-stat mis-stat--proceso<?= $filterStatus === 'En Proceso' ? ' is-active' : '' ?>">
                    <div class="mis-stat__num"><?= $statusCounts['En Proceso'] ?></div>
                    <div class="mis-stat__label">In Progress</div>
                </a>

                <a href="<?= esc(build_url(['status' => 'Resuelto', 'page' => 1])) ?>"
                   class="mis-stat mis-stat--resuelto<?= $filterStatus === 'Resuelto' ? ' is-active' : '' ?>">
                    <div class="mis-stat__num"><?= $statusCounts['Resuelto'] ?></div>
                    <div class="mis-stat__label">Resolved</div>
                </a>

                <a href="<?= esc(build_url(['status' => 'Cerrado', 'page' => 1])) ?>"
                   class="mis-stat mis-stat--cerrado<?= $filterStatus === 'Cerrado' ? ' is-active' : '' ?>">
                    <div class="mis-stat__num"><?= $statusCounts['Cerrado'] ?></div>
                    <div class="mis-stat__label">Closed</div>
                </a>

            </div>

            <!-- ── Search + filtros chip ───────────────── -->
            <form class="mis-filters" method="GET" action="mis_tickets.php">
                <div class="mis-search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input class="mis-search-input"
                           type="search"
                           name="q"
                           value="<?= esc($q) ?>"
                           placeholder="Search by ID, type, area or description…">
                    <?php if ($filterStatus !== ''): ?>
                        <input type="hidden" name="status" value="<?= esc($filterStatus) ?>">
                    <?php endif; ?>
                </div>

                <?php if ($q !== '' || $filterStatus !== ''): ?>
                    <a href="mis_tickets.php" class="mis-filter-chip" title="Clear filters">
                        <i class="fa-solid fa-xmark"></i> Clear Filters
                    </a>
                <?php endif; ?>
            </form>

            <!-- ── Lista de tickets ────────────────────── -->
            <?php if (empty($tickets)): ?>
                <div class="mis-empty">
                    <i class="fa-regular fa-folder-open"></i>
                    <?php if ($totalAll === 0): ?>
                        <p>You don't have any tickets yet. Create your first one!</p>
                    <?php else: ?>
                        <p>No tickets found matching these filters.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="mis-list">
                    <?php foreach ($tickets as $t):
                        $idTxt    = '#' . str_pad((string)$t['id_ticket'], 3, '0', STR_PAD_LEFT);
                        $prio     = $t['priority'] ?: 'Media';
                        $status   = $t['status']   ?: 'Pendiente';
                        $dateStr  = $t['created_at'] ? date('d/m/Y H:i', strtotime($t['created_at'])) : '—';
                        $comments = (string)($t['comments'] ?? '');
                        $shortType = $t['type'] ?: ($t['category'] ?: 'General');
                        $hasUrl   = !empty(trim($t['ticket_url'] ?? ''));
                        $hasFile  = !empty(trim($t['attachment_path'] ?? ''));

                        // Classes de badge
                        $sClass = 'hq-badge-status-closed';
                        if($status==='Pendiente') $sClass='hq-badge-status-pending';
                        if($status==='En Proceso') $sClass='hq-badge-status-process';
                        if($status==='Resuelto') $sClass='hq-badge-status-resolved';

                        $pClass = 'hq-badge-prio-medium';
                        if($prio==='Baja') $pClass='hq-badge-prio-low';
                        if($prio==='Alta') $pClass='hq-badge-prio-high';
                        if($prio==='Urgente') $pClass='hq-badge-prio-urgent';
                    ?>
                        <a href="ticket_edit.php?id=<?= $t['id_ticket'] ?>" class="mis-card">

                            <div class="mis-card__top">
                                <span class="mis-card__id"><?= esc($idTxt) ?></span>
                                <div class="mis-card__badges">
                                    <span class="hq-badge <?= $pClass ?>"><i class="fa-solid fa-bolt"></i> <?= esc(ui_prio_label($prio)) ?></span>
                                    <span class="hq-badge <?= $sClass ?>"><?= esc(ui_status_label($status)) ?></span>
                                </div>
                            </div>

                            <!-- Tipo + Área -->
                            <div class="mis-card__title">
                                <?= esc($shortType) ?>
                                <?php if (!empty($t['area'])): ?>
                                    <span class="fw-normal" style="color:var(--slate-400)">
                                        — <?= esc($t['area']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Descripción -->
                            <?php if ($comments !== ''): ?>
                                <div class="mis-card__comments"><?= esc($comments) ?></div>
                            <?php endif; ?>

                            <!-- Meta -->
                            <div class="mis-card__meta">

                                <span class="mis-card__meta-item" title="Creation Date">
                                    <i class="fa-regular fa-calendar"></i>
                                    <?= esc($dateStr) ?>
                                </span>

                                <?php if (!empty($t['assigned_name']) && $t['assigned_name'] !== '—'): ?>
                                    <span class="mis-card__meta-item" title="Assigned to">
                                        <i class="fa-solid fa-user-check"></i>
                                        <?= esc($t['assigned_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="mis-card__meta-item" style="color:var(--slate-300)" title="Unassigned">
                                        <i class="fa-regular fa-user"></i>
                                        Unassigned
                                    </span>
                                <?php endif; ?>

                                <?php if ($hasUrl): ?>
                                    <span class="mis-card__meta-item text-brand">
                                        <i class="fa-solid fa-link"></i> URL
                                    </span>
                                <?php endif; ?>

                                <?php if ($hasFile): ?>
                                    <span class="mis-card__meta-item text-brand">
                                        <i class="fa-solid fa-paperclip"></i> File
                                    </span>
                                <?php endif; ?>

                            </div>

                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- ── Paginación ──────────────────────── -->
                <?php if ($totalPages > 1): ?>
                    <?php
                        $pFrom = $total ? ($offset + 1) : 0;
                        $pTo   = min($offset + $perPage, $total);
                    ?>
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 mt-4 pt-3 border-top border-slate-200">
                        <span class="text-slate-500" style="font-size: 0.9rem; font-weight: 500;">
                            Showing <?= $pFrom ?> to <?= $pTo ?> of <?= $total ?> tickets
                        </span>
                        <nav aria-label="Pagination">
                            <ul class="pagination pagination-sm mb-0 shadow-sm rounded-lg overflow-hidden border border-slate-200">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link px-3" href="<?= esc(build_url(['page' => max(1, $page - 1)])) ?>">Previous</a>
                                </li>
                                <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= esc(build_url(['page' => $p])) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link px-3" href="<?= esc(build_url(['page' => min($totalPages, $page + 1)])) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php else: ?>
                    <div class="text-slate-500 text-center mt-4 pt-4 border-top border-slate-200" style="font-size: 0.9rem; font-weight: 500;">
                        Showing all <?= $total ?> tickets
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </main>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
