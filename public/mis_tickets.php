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
        'Pendiente'  => 'Pendiente',
        'En Proceso' => 'En proceso',
        'Resuelto'   => 'Resuelto',
        'Cerrado'    => 'Cerrado',
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
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Mis Tickets | RH&amp;R Ticketing</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <link rel="stylesheet" href="./assets/css/generarTickets.css">
    <link rel="stylesheet" href="./assets/css/menu.css">
    <link rel="stylesheet" href="./assets/css/movil.css">
    <link rel="stylesheet" href="./assets/css/tickets.css">

    <script defer src="./assets/js/sidebar.js"></script>

    <style>
        /* ── Summary cards ─────────────────────────────── */
        .mis-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .mis-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 14px 10px 12px;
            border-radius: 16px;
            border: 1.5px solid rgba(15,23,42,.07);
            background: #fff;
            box-shadow: 0 4px 14px rgba(15,23,42,.06);
            cursor: pointer;
            text-decoration: none;
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }
        .mis-stat:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(15,23,42,.10);
        }
        .mis-stat.is-active {
            border-color: var(--rhr-cyan, #18A9C8);
            box-shadow: 0 0 0 3px rgba(24,169,200,.18), 0 8px 22px rgba(15,23,42,.10);
        }

        .mis-stat__num {
            font-size: 28px;
            font-weight: 900;
            line-height: 1;
            letter-spacing: -.5px;
            color: #0f172a;
        }
        .mis-stat__label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: rgba(15,23,42,.45);
        }

        /* Status color accents */
        .mis-stat--total    .mis-stat__num { color: var(--rhr-navy,   #0a3d63); }
        .mis-stat--pendiente .mis-stat__num { color: #d97706; }
        .mis-stat--proceso   .mis-stat__num { color: var(--rhr-cyan,  #18A9C8); }
        .mis-stat--resuelto  .mis-stat__num { color: #16a34a; }
        .mis-stat--cerrado   .mis-stat__num { color: rgba(15,23,42,.38); }

        /* ── Empty state ────────────────────────────────── */
        .mis-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 48px 20px;
            color: rgba(15,23,42,.38);
        }
        .mis-empty i { font-size: 42px; opacity: .35; }
        .mis-empty p { font-size: 14px; font-weight: 600; margin: 0; }

        /* ── Ticket card list (mobile-first) ────────────── */
        .mis-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .mis-card {
            background: #fff;
            border: 1.5px solid rgba(15,23,42,.07);
            border-radius: 16px;
            padding: 14px 16px 12px;
            box-shadow: 0 2px 10px rgba(15,23,42,.05);
            transition: box-shadow .15s ease, transform .15s ease;
            position: relative;
        }
        .mis-card:hover {
            box-shadow: 0 6px 20px rgba(15,23,42,.09);
            transform: translateY(-1px);
        }

        /* Accent bar izquierda según estado */
        .mis-card::before {
            content: '';
            position: absolute;
            left: 0; top: 12px; bottom: 12px;
            width: 4px;
            border-radius: 0 4px 4px 0;
            background: var(--rhr-cyan, #18A9C8);
        }
        .mis-card.status-Pendiente::before  { background: #d97706; }
        .mis-card.status-En\ Proceso::before { background: var(--rhr-cyan, #18A9C8); }
        .mis-card.status-Resuelto::before   { background: #16a34a; }
        .mis-card.status-Cerrado::before    { background: rgba(15,23,42,.20); }

        .mis-card__top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 6px;
        }

        .mis-card__id {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: rgba(15,23,42,.38);
        }

        .mis-card__badges {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .mis-card__title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
            line-height: 1.35;
        }
        .mis-card__title span {
            color: var(--rhr-orange, #F47A21);
        }

        .mis-card__comments {
            font-size: 13px;
            color: rgba(15,23,42,.55);
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .mis-card__meta {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .mis-card__meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            color: rgba(15,23,42,.50);
            font-weight: 600;
        }
        .mis-card__meta-item i {
            font-size: 11px;
            opacity: .65;
        }

        /* ── Filtros y search ───────────────────────────── */
        .mis-filters {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .mis-search-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(15,23,42,.04);
            border: 1.5px solid rgba(15,23,42,.08);
            border-radius: 12px;
            padding: 6px 14px;
            flex: 1;
            min-width: 180px;
        }
        .mis-search-wrap i { color: rgba(15,23,42,.35); font-size: 13px; }
        .mis-search-input {
            background: transparent;
            border: 0;
            outline: none;
            font-size: 13.5px;
            color: #0f172a;
            width: 100%;
        }
        .mis-search-input::placeholder { color: rgba(15,23,42,.35); }

        .mis-filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border-radius: 20px;
            border: 1.5px solid rgba(15,23,42,.10);
            background: #fff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .03em;
            color: rgba(15,23,42,.55);
            text-decoration: none;
            cursor: pointer;
            transition: border-color .13s ease, color .13s ease, background .13s ease;
            white-space: nowrap;
        }
        .mis-filter-chip:hover {
            border-color: var(--rhr-orange, #F47A21);
            color: var(--rhr-orange, #F47A21);
        }
        .mis-filter-chip.is-active {
            background: var(--rhr-orange, #F47A21);
            border-color: var(--rhr-orange, #F47A21);
            color: #fff;
        }

        /* ── New ticket CTA ─────────────────────────────── */
        .mis-cta {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 18px;
            border-radius: 12px;
            background: var(--rhr-orange, #F47A21);
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            border: 0;
            cursor: pointer;
            transition: background .15s ease, transform .1s ease, box-shadow .15s ease;
            box-shadow: 0 4px 14px rgba(244,122,33,.28);
            white-space: nowrap;
        }
        .mis-cta:hover {
            background: #d95f0a;
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(244,122,33,.36);
        }
        .mis-cta:active { transform: translateY(1px); }
    </style>
</head>

<body class="tickets-page">
<div class="layout d-flex">

    <!-- SIDEBAR -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
        <div class="tickets-page tickets-wrap" style="width:100%;">

            <section class="panel card tickets-panel" style="max-width:900px;">

                <!-- ── Header ──────────────────────────────── -->
                <div class="tickets-head d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div>
                        <h1 class="panel__title m-0">My Tickets</h1>
                        <p class="text-muted small m-0 mt-1">
                            Personal history of your requests
                        </p>
                    </div>
                    <a href="generarTickets.php" class="mis-cta">
                        <i class="fa-solid fa-plus"></i> New Ticket
                    </a>
                </div>

                <!-- ── Summary cards ───────────────────────── -->
                <div class="mis-summary mt-3">

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
                               placeholder="Buscar por ID, tipo, área o descripción…">
                        <?php if ($filterStatus !== ''): ?>
                            <input type="hidden" name="status" value="<?= esc($filterStatus) ?>">
                        <?php endif; ?>
                    </div>

                    <?php if ($q !== '' || $filterStatus !== ''): ?>
                        <a href="mis_tickets.php" class="mis-filter-chip" title="Limpiar filtros">
                            <i class="fa-solid fa-xmark"></i> Limpiar
                        </a>
                    <?php endif; ?>
                </form>

                <!-- ── Lista de tickets ────────────────────── -->
                <?php if (empty($tickets)): ?>
                    <div class="mis-empty">
                        <i class="fa-regular fa-folder-open"></i>
                        <?php if ($totalAll === 0): ?>
                            <p>Aún no tienes tickets. ¡Crea el primero!</p>
                        <?php else: ?>
                            <p>No hay tickets con este filtro.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="mis-list">
                        <?php foreach ($tickets as $t):
                            $idTxt    = '#' . str_pad((string)$t['id_ticket'], 3, '0', STR_PAD_LEFT);
                            $prio     = $t['priority'] ?: 'Media';
                            $status   = $t['status']   ?: 'Pendiente';
                            $prioClass = ui_prio_class($prio);
                            $stClass   = ui_status_class($status);
                            $dateStr   = $t['created_at'] ? date('d/m/Y H:i', strtotime($t['created_at'])) : '—';
                            $comments  = (string)($t['comments'] ?? '');
                            $shortType = $t['type'] ?: ($t['category'] ?: 'General');
                            $hasUrl    = !empty(trim($t['ticket_url'] ?? ''));
                            $hasFile   = !empty(trim($t['attachment_path'] ?? ''));
                        ?>
                            <div class="mis-card status-<?= esc($status) ?>">

                                <div class="mis-card__top">
                                    <span class="mis-card__id"><?= esc($idTxt) ?></span>
                                    <div class="mis-card__badges">
                                        <!-- Prioridad -->
                                        <span class="badge badge-prio <?= esc($prioClass) ?>"><?= esc($prio) ?></span>
                                        <!-- Estado -->
                                        <span class="badge badge-status <?= esc($stClass) ?>"><?= esc(ui_status_label($status)) ?></span>
                                    </div>
                                </div>

                                <!-- Tipo + Área -->
                                <div class="mis-card__title">
                                    <span><?= esc($shortType) ?></span>
                                    <?php if (!empty($t['area'])): ?>
                                        <span class="fw-normal" style="color:rgba(15,23,42,.45)">
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

                                    <span class="mis-card__meta-item">
                                        <i class="fa-regular fa-calendar"></i>
                                        <?= esc($dateStr) ?>
                                    </span>

                                    <?php if (!empty($t['assigned_name']) && $t['assigned_name'] !== '—'): ?>
                                        <span class="mis-card__meta-item">
                                            <i class="fa-solid fa-user-check"></i>
                                            <?= esc($t['assigned_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="mis-card__meta-item" style="color:rgba(15,23,42,.28)">
                                            <i class="fa-regular fa-user"></i>
                                            Sin asignar
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($hasUrl): ?>
                                        <a class="mis-card__meta-item text-decoration-none"
                                           href="<?= esc(trim($t['ticket_url'])) ?>"
                                           target="_blank" rel="noopener"
                                           title="Ver URL adjunta">
                                            <i class="fa-solid fa-link"></i> URL
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($hasFile): ?>
                                        <span class="mis-card__meta-item">
                                            <i class="fa-solid fa-paperclip"></i> Evidencia
                                        </span>
                                    <?php endif; ?>

                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- ── Paginación ──────────────────────── -->
                    <?php if ($totalPages > 1): ?>
                        <?php
                            $pFrom = $total ? ($offset + 1) : 0;
                            $pTo   = min($offset + $perPage, $total);
                        ?>
                        <div class="table-foot d-flex flex-column flex-md-row justify-content-between align-items-center gap-2 mt-3">
                            <span class="foot-text">
                                Mostrando <?= $pFrom ?>–<?= $pTo ?> de <?= $total ?> tickets
                            </span>
                            <nav aria-label="Paginación">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= esc(build_url(['page' => max(1, $page - 1)])) ?>">Anterior</a>
                                    </li>
                                    <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= esc(build_url(['page' => $p])) ?>"><?= $p ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= esc(build_url(['page' => min($totalPages, $page + 1)])) ?>">Siguiente</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php else: ?>
                        <div class="foot-text text-muted small mt-2">
                            <?= $total ?> ticket<?= $total !== 1 ? 's' : '' ?> en total
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            </section>
        </div>
    </main>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
