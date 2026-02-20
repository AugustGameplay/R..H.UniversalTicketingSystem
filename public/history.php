<?php
require __DIR__ . '/partials/auth.php';
$active = 'history';

require __DIR__ . '/config/db.php';

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =====================================================
// Fechas (GET) - inicio y fin
// =====================================================
$start = trim($_GET['start'] ?? '');
$end   = trim($_GET['end'] ?? '');

$today = (new DateTime('today'))->format('Y-m-d');
if ($start === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = $today;
if ($end === ''   || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end))   $end   = $start;

if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }

$dtStart   = new DateTime($start);
$dtEnd     = new DateTime($end);
$dtEndNext = (clone $dtEnd)->modify('+1 day');

$sqlRange = "t.created_at >= :start AND t.created_at < :endNext";
$paramsRange = [
  ':start'   => $dtStart->format('Y-m-d 00:00:00'),
  ':endNext' => $dtEndNext->format('Y-m-d 00:00:00'),
];

// =====================================================
// Detectar columnas útiles en tickets
// =====================================================
$ticketCols = [];
try {
  $colsStmt = $pdo->query("SHOW COLUMNS FROM tickets");
  foreach ($colsStmt->fetchAll(PDO::FETCH_ASSOC) as $c){
    $ticketCols[strtolower($c['Field'])] = strtolower($c['Type']);
  }
} catch (Throwable $e) {
  $ticketCols = [];
}

// =====================================================
// 1) "Creado por" (ID o texto)
// =====================================================
$creatorIdField = null;
$creatorNameField = null;
$creatorJoinSQL = "";
$creatorNameExpr = "''";

if ($ticketCols){
  $idCandidates = ['created_by_user_id','creator_user_id','created_by_id','user_id','id_user','created_by'];
  foreach ($idCandidates as $f){
    $lf = strtolower($f);
    if (isset($ticketCols[$lf]) && preg_match('/^(int|bigint|smallint|mediumint|tinyint)/', $ticketCols[$lf])){
      $creatorIdField = $f;
      break;
    }
  }

  $nameCandidates = ['created_by_name','creator_name','created_by'];
  foreach ($nameCandidates as $f){
    $lf = strtolower($f);
    if (isset($ticketCols[$lf]) && !preg_match('/^(int|bigint|smallint|mediumint|tinyint)/', $ticketCols[$lf])){
      $creatorNameField = $f;
      break;
    }
  }

  if ($creatorIdField){
    $creatorJoinSQL = "LEFT JOIN users cu ON cu.id_user = t.$creatorIdField";
  }

  if ($creatorIdField && $creatorNameField){
    $creatorNameExpr = "COALESCE(cu.full_name, t.$creatorNameField, '')";
  } elseif ($creatorIdField){
    $creatorNameExpr = "COALESCE(cu.full_name, '')";
  } elseif ($creatorNameField){
    $creatorNameExpr = "COALESCE(t.$creatorNameField, '')";
  }
}

// =====================================================
// 2) "Cerrado" (fecha/hora)
// =====================================================
$closedField = null;
$closedCandidates = ['closed_at','resolved_at','completed_at','closed_datetime','closed_date'];
foreach ($closedCandidates as $f){
  if (isset($ticketCols[strtolower($f)])) { $closedField = $f; break; }
}

// Si no existe, intentamos crear closed_at (local) + trigger para autollenar
if (!$closedField){
  try {
    $pdo->exec("ALTER TABLE tickets ADD COLUMN closed_at DATETIME NULL");
    $closedField = 'closed_at';

    // Backfill suave: si existe updated_at/modified_at, úsalo para tickets ya resueltos.
    $updatedCandidates = ['updated_at','modified_at','last_updated_at','last_update'];
    foreach ($updatedCandidates as $u){
      if (isset($ticketCols[strtolower($u)])){
        try {
          $pdo->exec("UPDATE tickets SET closed_at = {$u} WHERE (status='Cerrado' OR status='Closed') AND closed_at IS NULL AND {$u} IS NOT NULL");
        } catch (Throwable $e) {}
        break;
      }
    }

    // Trigger para setear/limpiar closed_at según status
    $trgName = 'tickets_set_closed_at';
    $exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :t");
    $exists->execute([':t'=>$trgName]);
    if ((int)$exists->fetchColumn() === 0){
      $pdo->exec("DROP TRIGGER IF EXISTS $trgName");
      $pdo->exec("
        CREATE TRIGGER $trgName
        BEFORE UPDATE ON tickets
        FOR EACH ROW
        BEGIN
          IF (NEW.status = 'Cerrado' OR NEW.status = 'Closed') AND (OLD.status <> 'Cerrado' AND OLD.status <> 'Closed') THEN
            SET NEW.closed_at = IFNULL(NEW.closed_at, NOW());
          END IF;
          IF (NEW.status <> 'Cerrado' AND NEW.status <> 'Closed') AND (OLD.status = 'Cerrado' OR OLD.status = 'Closed') THEN
            SET NEW.closed_at = NULL;
          END IF;
        END
      ");
    }
  } catch (Throwable $e) {
    // si falla, lo dejamos como NULL
    $closedField = null;
  }
}


// Asegurar trigger correcto para "Cerrado/Closed" (por si quedó uno viejo)
if ($closedField){
  try {
    // Backfill suave para tickets ya cerrados
    $updatedCandidates = ['updated_at','modified_at','last_updated_at','last_update'];
    foreach ($updatedCandidates as $u){
      if (isset($ticketCols[strtolower($u)])){
        try {
          $pdo->exec("UPDATE tickets SET {$closedField} = IFNULL({$closedField}, {$u}) WHERE (status='Cerrado' OR status='Closed') AND {$closedField} IS NULL AND {$u} IS NOT NULL");
        } catch (Throwable $e) {}
        break;
      }
    }

    // Re-crear trigger con la lógica correcta
    $trgName = 'tickets_set_closed_at';
    try { $pdo->exec("DROP TRIGGER IF EXISTS {$trgName}"); } catch (Throwable $e) {}

    $pdo->exec("
      CREATE TRIGGER {$trgName}
      BEFORE UPDATE ON tickets
      FOR EACH ROW
      BEGIN
        IF (NEW.status = 'Cerrado' OR NEW.status = 'Closed')
           AND (OLD.status <> 'Cerrado' AND OLD.status <> 'Closed') THEN
          SET NEW.{$closedField} = IFNULL(NEW.{$closedField}, NOW());
        END IF;

        IF (NEW.status <> 'Cerrado' AND NEW.status <> 'Closed')
           AND (OLD.status = 'Cerrado' OR OLD.status = 'Closed') THEN
          SET NEW.{$closedField} = NULL;
        END IF;
      END
    ");
  } catch (Throwable $e) {
    // ignora si no hay permisos o no aplica
  }
}

// =====================================================
// 3) Tabla de modificaciones (auditoría)
// =====================================================
$modsTable = null;
$modsCandidates = ['ticket_modifications','ticket_changes','ticket_audit','ticket_history','tickets_history','ticket_log'];
foreach ($modsCandidates as $t){
  try {
    $q = $pdo->prepare("SHOW TABLES LIKE :t");
    $q->execute([':t'=>$t]);
    if ($q->fetchColumn()){ $modsTable = $t; break; }
  } catch (Throwable $e) {}
}

if (!$modsTable){
  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS ticket_modifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        modified_by INT NULL,
        modified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        action VARCHAR(32) NOT NULL DEFAULT 'update',
        notes TEXT NULL,
        INDEX idx_ticket_id (ticket_id),
        INDEX idx_modified_at (modified_at),
        INDEX idx_modified_by (modified_by)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    $modsTable = 'ticket_modifications';
  } catch (Throwable $e) {
    $modsTable = null;
  }
}

// =====================================================
// AJAX: historial de modificaciones (modal)
// =====================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mods'){
  header('Content-Type: application/json; charset=utf-8');
  $ticketId = isset($_GET['ticket_id']) && ctype_digit((string)$_GET['ticket_id']) ? (int)$_GET['ticket_id'] : 0;
  if (!$modsTable || $ticketId <= 0){
    echo json_encode(['ok'=>true, 'items'=>[]]);
    exit;
  }

  try {
    $stmt = $pdo->prepare("
      SELECT
        m.id,
        m.ticket_id,
        m.modified_at,
        m.field_name,
        m.old_value,
        m.new_value,
        m.action,
        COALESCE(u.full_name, '—') AS modified_by_name
      FROM {$modsTable} m
      LEFT JOIN users u ON u.id_user = m.modified_by
      WHERE m.ticket_id = :id
      ORDER BY m.modified_at DESC, m.id DESC
      LIMIT 200
    ");
    $stmt->execute([':id'=>$ticketId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true, 'items'=>$items]);
  } catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'items'=>[]]);
  }
  exit;
}

// =====================================================
// Filtros: vista (cards) + creador
// =====================================================
$view = strtolower(trim($_GET['view'] ?? 'total'));
$allowedViews = ['total','assigned','unassigned','inprogress','done','closed'];
if (!in_array($view, $allowedViews, true)) $view = 'total';

$creatorId = trim($_GET['creator'] ?? '');
$creatorId = ($creatorIdField && $creatorId !== '' && ctype_digit($creatorId)) ? (int)$creatorId : 0;

$creatorFilterSQL = "";
$params = $paramsRange;
if ($creatorIdField && $creatorId > 0){
  $creatorFilterSQL = " AND t.$creatorIdField = :creatorId";
  $params[':creatorId'] = $creatorId;
}

// =====================================================
// Download CSV (respeta filtros)
// =====================================================
if (isset($_GET['download']) && $_GET['download'] == '1') {
  $extraWhere = "";
  if ($view === 'assigned')   $extraWhere = " AND t.assigned_user_id IS NOT NULL";
  if ($view === 'unassigned') $extraWhere = " AND t.assigned_user_id IS NULL";
  if ($view === 'inprogress') $extraWhere = " AND t.status = 'En Proceso'";
  if ($view === 'done')       $extraWhere = " AND t.status = 'Resuelto'";
  if ($view === 'closed')     $extraWhere = " AND (t.status = 'Cerrado' OR t.status = 'Closed')";

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="tickets_history_'.$start.'_to_'.$end.'_'.$view.'.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID', 'Creado', 'Cerrado', 'Área', 'Prioridad', 'Estatus', 'Asignado a', 'Creado por']);

  $closedSelect = $closedField ? "t.$closedField AS closed_at" : "NULL AS closed_at";
  $stmt = $pdo->prepare("
    SELECT
      t.id_ticket,
      t.created_at,
      $closedSelect,
      t.area,
      t.priority,
      t.status,
      COALESCE(u.full_name, '') AS assigned_name,
      $creatorNameExpr AS created_by_name
    FROM tickets t
    LEFT JOIN users u ON u.id_user = t.assigned_user_id
    $creatorJoinSQL
    WHERE $sqlRange $creatorFilterSQL $extraWhere
    ORDER BY t.id_ticket DESC
  ");
  $stmt->execute($params);

  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $row['id_ticket'],
      $row['created_at'],
      $row['closed_at'] ?: '',
      $row['area'],
      $row['priority'],
      $row['status'],
      $row['assigned_name'],
      $row['created_by_name'],
    ]);
  }
  fclose($out);
  exit;
}

// =====================================================
// Métricas
// =====================================================
function count_where(PDO $pdo, string $where, array $params): int {
  $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM tickets t WHERE $where");
  $stmt->execute($params);
  return (int)($stmt->fetchColumn() ?: 0);
}

$whereBase = $sqlRange . $creatorFilterSQL;
$total      = count_where($pdo, $whereBase, $params);
$assigned   = count_where($pdo, "$whereBase AND t.assigned_user_id IS NOT NULL", $params);
$unassigned = count_where($pdo, "$whereBase AND t.assigned_user_id IS NULL", $params);
$inprogress = count_where($pdo, "$whereBase AND t.status = 'En Proceso'", $params);
$done       = count_where($pdo, "$whereBase AND t.status = 'Resuelto'", $params);
$closed     = count_where($pdo, "$whereBase AND (t.status = 'Cerrado' OR t.status = 'Closed')", $params);

// =====================================================
// Lista
// =====================================================
$extraWhere = "";
$viewLabel  = "Total";
if ($view === 'assigned')   { $extraWhere = " AND t.assigned_user_id IS NOT NULL"; $viewLabel="Assigned"; }
if ($view === 'unassigned') { $extraWhere = " AND t.assigned_user_id IS NULL";     $viewLabel="Unassigned"; }
if ($view === 'inprogress') { $extraWhere = " AND t.status = 'En Proceso'";        $viewLabel="In progress"; }
if ($view === 'done')       { $extraWhere = " AND t.status = 'Resuelto'";          $viewLabel="Done"; }
if ($view === 'closed')     { $extraWhere = " AND (t.status = 'Cerrado' OR t.status = 'Closed')"; $viewLabel="Closed"; }

$rows = [];
try {
  $closedSelect = $closedField ? "t.$closedField AS closed_at" : "NULL AS closed_at";
  $stmt = $pdo->prepare("
    SELECT
      t.id_ticket,
      t.created_at,
      $closedSelect,
      t.area,
      t.priority,
      t.status,
      COALESCE(u.full_name, '—') AS assigned_name,
      $creatorNameExpr AS created_by_name
    FROM tickets t
    LEFT JOIN users u ON u.id_user = t.assigned_user_id
    $creatorJoinSQL
    WHERE $sqlRange $creatorFilterSQL $extraWhere
    ORDER BY t.id_ticket DESC
    LIMIT 250
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $rows = [];
}

// Usuarios para filtro
$usersList = [];
$selectedCreatorName = '';
if ($creatorIdField){
  try {
    $uStmt = $pdo->query("SELECT id_user, full_name FROM users ORDER BY full_name ASC");
    $usersList = $uStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($creatorId > 0){
      foreach ($usersList as $uu){
        if ((int)$uu['id_user'] === (int)$creatorId) { $selectedCreatorName = (string)$uu['full_name']; break; }
      }
    }
  } catch (Throwable $e) {
    $usersList = [];
  }
}

$startText = (new DateTime($start))->format('d/m/Y');
$endText   = (new DateTime($end))->format('d/m/Y');

function build_qs(array $pairs): string {
  $pairs = array_filter($pairs, fn($v) => $v !== null && $v !== '' && $v !== 0);
  return http_build_query($pairs);
}

$qsBase = build_qs([
  'start'   => $start,
  'end'     => $end,
  'creator' => $creatorId,
]);

// Mapeo de campos para el modal
$fieldLabels = [
  'status' => 'Estatus',
  'priority' => 'Prioridad',
  'area' => 'Área',
  'assigned_user_id' => 'Asignado a',
  'assigned_to' => 'Asignado a',
  'ticket_url' => 'URL',
  'url' => 'URL',
  'evidence' => 'Evidencia',
  'evidence_path' => 'Evidencia',
  'attachment' => 'Adjunto',
  'notes' => 'Notas',
];

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>History | RH&R Ticketing</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- Base -->
  <link rel="stylesheet" href="./assets/css/generarTickets.css">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/movil.css">
  <script defer src="./assets/js/sidebar.js"></script>

  <!-- History -->
  <link rel="stylesheet" href="./assets/css/history.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body>
  <div class="layout d-flex">
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <section class="panel card history-panel">

        <div class="history-shell">
          <!-- IZQUIERDA -->
          <div class="history-left">

            <div class="history-left__header">
              <div class="history-h1">Historial</div>
              <div class="history-sub">Consulta y descarga tickets por fecha / estatus.</div>
            </div>

            <!-- FECHAS (2 pastillas) -->
            <div class="history-dates">
              <div class="history-date history-date--start" id="pillStart" role="button" aria-label="Seleccionar fecha de inicio">
                <i class="fa-solid fa-calendar-days"></i>
                <span class="pill-label">Inicio</span>
                <input id="startDate" class="date-pill-input" type="text" value="<?= esc($startText) ?>" readonly />
              </div>

              <div class="history-date history-date--end" id="pillEnd" role="button" aria-label="Seleccionar fecha fin">
                <i class="fa-solid fa-calendar-days"></i>
                <span class="pill-label">Fin</span>
                <input id="endDate" class="date-pill-input" type="text" value="<?= esc($endText) ?>" readonly />
              </div>
            </div>

            <!-- MÉTRICAS (clicables) -->
            <div class="history-grid">

              <a class="stat-card stat-total <?= $view==='total'?'is-active':'' ?>" href="history.php?<?= esc($qsBase) ?>&view=total">
                <div class="stat-num"><?= (int)$total ?></div>
                <div class="stat-label">Total</div>
              </a>

              <a class="stat-card stat-assigned <?= $view==='assigned'?'is-active':'' ?>" href="history.php?<?= esc($qsBase) ?>&view=assigned">
                <div class="stat-num"><?= (int)$assigned ?></div>
                <div class="stat-label">Assigned</div>
              </a>

              <a class="stat-card stat-unassigned <?= $view==='unassigned'?'is-active':'' ?>" href="history.php?<?= esc($qsBase) ?>&view=unassigned">
                <div class="stat-num"><?= (int)$unassigned ?></div>
                <div class="stat-label">Unassigned</div>
              </a>

              <a class="stat-card stat-inprogress <?= $view==='inprogress'?'is-active':'' ?>" href="history.php?<?= esc($qsBase) ?>&view=inprogress">
                <div class="stat-num"><?= (int)$inprogress ?></div>
                <div class="stat-label">In progress</div>
              </a>

              <a class="stat-card stat-done <?= $view==='done'?'is-active':'' ?>" href="history.php?<?= esc($qsBase) ?>&view=done">
                <div class="stat-num"><?= (int)$done ?></div>
                <div class="stat-label">Done</div>
              </a>

              <a class="stat-card stat-closed <?= $view==='closed'?'is-active':'' ?>" href="history.php?<?= esc($qsBase) ?>&view=closed">
                <div class="stat-num"><?= (int)$closed ?></div>
                <div class="stat-label">Closed</div>
              </a>


            </div>

          </div>

          <!-- DERECHA -->
          <div class="history-right">
            <div class="history-right__top">
              <div class="history-right__titles">
                <div class="history-right__title">
                  Tickets <span class="chip-view"><?= esc($viewLabel) ?></span>
                </div>
                <div class="history-right__meta">
                  <?= esc("Del $startText al $endText") ?>
                  <?php if ($creatorIdField && $creatorId > 0 && $selectedCreatorName): ?>
                    <span class="chip-creator">Creados por: <?= esc($selectedCreatorName) ?></span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="history-right__controls">
                <?php if ($creatorIdField): ?>
                  <form class="history-user-filter" method="get" action="history.php">
                    <input type="hidden" name="start" value="<?= esc($start) ?>">
                    <input type="hidden" name="end" value="<?= esc($end) ?>">
                    <input type="hidden" name="view" value="<?= esc($view) ?>">
                    <select class="form-select" name="creator" onchange="this.form.submit()">
                      <option value="">Todos los creadores</option>
                      <?php foreach ($usersList as $uu): ?>
                        <option value="<?= (int)$uu['id_user'] ?>" <?= ((int)$uu['id_user']===(int)$creatorId)?'selected':'' ?>>
                          <?= esc($uu['full_name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <div class="history-table-wrap">
              <table class="history-table">
                <thead>
                  <tr>
                    <th style="width:90px;">ID</th>
                    <th style="width:170px;">Creado</th>
                    <th style="width:170px;">Cerrado</th>
                    <th>Área</th>
                    <th style="width:120px;">Prioridad</th>
                    <th style="width:140px;">Status</th>
                    <th style="width:190px;">Assigned to</th>
                    <th style="width:220px;">Creado por</th>
                    <th style="width:120px; text-align:right;">Mods</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$rows): ?>
                    <tr>
                      <td colspan="9" class="empty-row">No hay tickets para este filtro.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                      <?php
                        $createdTxt = '—';
                        $closedTxt = '—';
                        try {
                          $createdTxt = (new DateTime($r['created_at']))->format('d/m/Y H:i');
                        } catch (Throwable $e) {}
                        if (!empty($r['closed_at'])){
                          try {
                            $closedTxt = (new DateTime($r['closed_at']))->format('d/m/Y H:i');
                          } catch (Throwable $e) { $closedTxt = esc((string)$r['closed_at']); }
                        }
                      ?>
                      <tr>
                        <td class="td-id"><?= (int)$r['id_ticket'] ?></td>
                        <td><?= esc($createdTxt) ?></td>
                        <td><?= esc($closedTxt) ?></td>
                        <td class="td-ellipsis" title="<?= esc($r['area']) ?>"><?= esc($r['area']) ?></td>
                        <td><?= esc($r['priority']) ?></td>
                        <td><?= esc($r['status']) ?></td>
                        <td class="td-ellipsis" title="<?= esc($r['assigned_name']) ?>"><?= esc($r['assigned_name']) ?></td>
                        <td class="td-ellipsis" title="<?= esc($r['created_by_name'] ?: '—') ?>"><?= esc($r['created_by_name'] ?: '—') ?></td>
                        <td class="td-actions">
                          <button
                            type="button"
                            class="btn-mods"
                            data-ticket-id="<?= (int)$r['id_ticket'] ?>"
                            <?= $modsTable ? '' : 'disabled' ?>
                            title="Ver historial de modificaciones">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="history-footer">
              <a class="btn-download d-inline-flex align-items-center justify-content-center text-decoration-none"
                 href="history.php?<?= esc($qsBase) ?>&view=<?= esc($view) ?>&download=1">
                <i class="fa-solid fa-download me-2"></i> Download
              </a>
            </div>
          </div>
        </div>

      </section>
    </main>
  </div>

  <!-- Modal Mods -->
  <div class="modal fade" id="modsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content mods-modal">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="modsTitle">Historial de modificaciones</h5>
            <div class="mods-sub" id="modsSub">—</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div id="modsBody" class="mods-body">
            <div class="mods-loading">Cargando…</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

  <script>
    (function(){
      // ====== FECHAS (2 pastillas) ======
      const startEl = document.getElementById('startDate');
      const endEl   = document.getElementById('endDate');
      const pillStart = document.getElementById('pillStart');
      const pillEnd   = document.getElementById('pillEnd');

      const view = "<?= esc($view) ?>";
      const creator = "<?= esc((string)($creatorId ?: '')) ?>";
      const startISOInit = "<?= esc($start) ?>";
      const endISOInit   = "<?= esc($end) ?>";

      let startISO = startISOInit;
      let endISO   = endISOInit;

      const toISO = (d) => {
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth()+1).padStart(2,'0');
        const dd = String(d.getDate()).padStart(2,'0');
        return `${yyyy}-${mm}-${dd}`;
      };

      const go = () => {
        if (!startISO || !endISO) return;
        const params = new URLSearchParams();
        params.set('start', startISO);
        params.set('end', endISO);
        if (view) params.set('view', view);
        if (creator) params.set('creator', creator);
        window.location.href = `history.php?${params.toString()}`;
      };

      const fpStart = flatpickr(startEl, {
        dateFormat: "d/m/Y",
        defaultDate: startEl.value,
        allowInput: false,
        clickOpens: true,
        locale: { firstDayOfWeek: 1 },
        onChange: function(sel){
          if (!sel || !sel[0]) return;
          startISO = toISO(sel[0]);
          if (startISO > endISO){ const tmp = startISO; startISO = endISO; endISO = tmp; }
          setTimeout(go, 150);
        }
      });

      const fpEnd = flatpickr(endEl, {
        dateFormat: "d/m/Y",
        defaultDate: endEl.value,
        allowInput: false,
        clickOpens: true,
        locale: { firstDayOfWeek: 1 },
        onChange: function(sel){
          if (!sel || !sel[0]) return;
          endISO = toISO(sel[0]);
          if (startISO > endISO){ const tmp = startISO; startISO = endISO; endISO = tmp; }
          setTimeout(go, 150);
        }
      });

      pillStart.addEventListener('click', () => fpStart.open());
      pillEnd.addEventListener('click', () => fpEnd.open());

      // ====== MODAL MODS ======
      const modalEl = document.getElementById('modsModal');
      const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
      const titleEl = document.getElementById('modsTitle');
      const subEl = document.getElementById('modsSub');
      const bodyEl = document.getElementById('modsBody');

      const fieldLabels = <?= json_encode($fieldLabels, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

      const fmtDT = (s) => {
        if (!s) return '—';
        // asume "YYYY-MM-DD HH:MM:SS"
        const parts = String(s).replace('T',' ').split(' ');
        const d = (parts[0]||'').split('-');
        const t = (parts[1]||'').slice(0,5);
        if (d.length !== 3) return s;
        return `${d[2]}/${d[1]}/${d[0]} ${t}`;
      };

      const escapeHtml = (str) => {
        return String(str ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;','\'':'&#39;'}[c] || c));
      };

      const renderMods = (items) => {
        if (!items || !items.length){
          bodyEl.innerHTML = `<div class="mods-empty">Aún no hay modificaciones registradas para este ticket.</div>`;
          return;
        }

        const html = items.map((it) => {
          const when = fmtDT(it.modified_at);
          const who = escapeHtml(it.modified_by_name || '—');
          const fieldRaw = (it.field_name || '').toLowerCase();
          const field = escapeHtml(fieldLabels[fieldRaw] || it.field_name || 'Campo');
          const oldv = escapeHtml((it.old_value ?? '—'));
          const newv = escapeHtml((it.new_value ?? '—'));

          return `
            <div class="mod-item">
              <div class="mod-head">
                <div class="mod-when"><i class="fa-regular fa-clock"></i> ${when}</div>
                <div class="mod-who"><i class="fa-regular fa-user"></i> ${who}</div>
              </div>
              <div class="mod-body">
                <div class="mod-field">${field}</div>
                <div class="mod-diff">
                  <span class="mod-old">${oldv}</span>
                  <span class="mod-arrow">→</span>
                  <span class="mod-new">${newv}</span>
                </div>
              </div>
            </div>
          `;
        }).join('');

        bodyEl.innerHTML = `<div class="mods-list">${html}</div>`;
      };

      document.querySelectorAll('.btn-mods').forEach((btn) => {
        btn.addEventListener('click', async () => {
          if (!modal) return;
          const ticketId = btn.getAttribute('data-ticket-id');
          if (!ticketId) return;

          titleEl.textContent = 'Historial de modificaciones';
          subEl.textContent = `Ticket #${ticketId}`;
          bodyEl.innerHTML = `<div class="mods-loading">Cargando…</div>`;
          modal.show();

          try {
            const res = await fetch(`history.php?ajax=mods&ticket_id=${encodeURIComponent(ticketId)}`);
            const data = await res.json();
            if (data && data.ok) renderMods(data.items);
            else bodyEl.innerHTML = `<div class="mods-empty">No se pudo cargar el historial.</div>`;
          } catch (e){
            bodyEl.innerHTML = `<div class="mods-empty">No se pudo cargar el historial.</div>`;
          }
        });
      });
    })();
  </script>
</body>
</html>
