<?php
require __DIR__ . '/partials/auth.php';
require __DIR__ . '/config/db.php';

$active = 'tickets';

$ticketId = (int)($_GET['id'] ?? 0);
if ($ticketId <= 0) {
  header("Location: tickets.php");
  exit;
}

$errors = [];

// 1) Traer ticket
$stmt = $pdo->prepare("
  SELECT 
    id_ticket,
    id_user,
    category,
    type,
    area,
    comments,
    status,
    created_at,
    updated_at,
    priority,
    assigned_user_id,
    ticket_url,
    attachment_path
  FROM tickets
  WHERE id_ticket = :id
  LIMIT 1
");
$stmt->execute([':id' => $ticketId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
  header("Location: tickets.php");
  exit;
}

// Guardar snapshot para auditoría (comparar cambios)
$ticketOriginal = [
  'assigned_user_id' => ($ticket['assigned_user_id'] === null || $ticket['assigned_user_id'] === '') ? null : (int)$ticket['assigned_user_id'],
  'priority' => (string)($ticket['priority'] ?? ''),
  'status'   => (string)($ticket['status'] ?? ''),
  'area'     => (string)($ticket['area'] ?? ''),
  'type'     => (string)($ticket['type'] ?? ''),
];



// Asegurar tablas auxiliares (auditoría + comentarios internos)
$modsOkInit = ensureTicketModsTable($pdo);
$commentsOk = ensureTicketCommentsTable($pdo);
// 2) Lista SOLO IT Support (activos)
$itStmt = $pdo->query("
  SELECT id_user, full_name, email
  FROM users
  WHERE AREA = 'IT Support' AND is_active = 1
  ORDER BY full_name ASC
");
$itUsers = $itStmt->fetchAll(PDO::FETCH_ASSOC);

// 2.1) Opciones para editar "Area" y "Tipo" (misma lista que generarTickets.php)
//     ✅ Sin inputs editables (solo select)
//     ✅ Si agregas opciones en generarTickets.php, aquí aparecen automáticamente
$areasOptions = [];
$typeOptions  = [];

$typeToCategory = [];
$typesByCategory = [];
$typeOptionsForCategory = [];


function extractDropdownValuesFromSource(string $src, string $targetInput): array {
  $out = [];
  $re = '~<ul[^>]*data-target-input\s*=\s*"' . preg_quote($targetInput, '~') . '"[^>]*>(.*?)</ul>~si';
  if (preg_match($re, $src, $m)) {
    if (preg_match_all('~data-value\s*=\s*"([^"]+)"~i', $m[1], $mm)) {
      foreach ($mm[1] as $v) {
        $v = html_entity_decode(trim($v), ENT_QUOTES, 'UTF-8');
        if ($v !== '' && !in_array($v, $out, true)) {
          $out[] = $v;
        }

      }
    }
  }
  return $out;
}

function extractTypeToCategoryFromSource(string $src): array {
  $map = [];

  // 1) Preferimos el array PHP $TYPE_TO_CATEGORY = [ 'Tipo' => 'Categoria', ... ];
  if (preg_match('~\\$TYPE_TO_CATEGORY\\s*=\\s*\\[(.*?)\\];~si', $src, $m)) {
    if (preg_match_all('~[\'"]([^\'"]+)[\'"]\\s*=>\\s*[\'"]([^\'"]+)[\'"]~', $m[1], $mm, PREG_SET_ORDER)) {
      foreach ($mm as $row) {
        $k = html_entity_decode(trim($row[1]), ENT_QUOTES, 'UTF-8');
        $v = html_entity_decode(trim($row[2]), ENT_QUOTES, 'UTF-8');
        if ($k !== '' && $v !== '') $map[$k] = $v;
      }
    }
  }

  // 2) Fallback: const TYPE_TO_CATEGORY = { "Tipo": "Categoria", ... };
  if (!$map && preg_match('~const\\s+TYPE_TO_CATEGORY\\s*=\\s*\\{(.*?)\\};~si', $src, $m2)) {
    if (preg_match_all('~[\'"]([^\'"]+)[\'"]\\s*:\\s*[\'"]([^\'"]+)[\'"]~', $m2[1], $mm2, PREG_SET_ORDER)) {
      foreach ($mm2 as $row) {
        $k = html_entity_decode(trim($row[1]), ENT_QUOTES, 'UTF-8');
        $v = html_entity_decode(trim($row[2]), ENT_QUOTES, 'UTF-8');
        if ($k !== '' && $v !== '') $map[$k] = $v;
      }
    }
  }

  return $map;
}



$srcPath = __DIR__ . '/generarTickets.php';
if (is_file($srcPath) && is_readable($srcPath)) {
  $src = (string)@file_get_contents($srcPath);
  if ($src !== '') {
    $areasOptions = extractDropdownValuesFromSource($src, '#area');
    $typeOptions  = extractDropdownValuesFromSource($src, '#type');

    // Mapeo Tipo -> Categoría (para filtrar tipos según categoría)
    $typeToCategory = extractTypeToCategoryFromSource($src);

    // Agrupar tipos por categoría (dinámico)
    foreach ($typeOptions as $t) {
      $t = (string)$t;
      $cat = $typeToCategory[$t] ?? 'General';
      if (!isset($typesByCategory[$cat])) $typesByCategory[$cat] = [];
      if (!in_array($t, $typesByCategory[$cat], true)) $typesByCategory[$cat][] = $t;
    }
  }
}

// Fallback a BD si no se pudo leer el archivo (no rompemos la vista en local)
try {
  if (!$areasOptions) {
    $areasOptions = $pdo->query("
      SELECT DISTINCT area
      FROM tickets
      WHERE area IS NOT NULL AND area <> ''
      ORDER BY area ASC
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
  }

  if (!$typeOptions) {
    $ts = $pdo->prepare("
      SELECT DISTINCT type
      FROM tickets
      WHERE category = :cat AND type IS NOT NULL AND type <> ''
      ORDER BY type ASC
    ");
    $ts->execute([':cat' => (string)($ticket['category'] ?? '')]);
    $typeOptions = $ts->fetchAll(PDO::FETCH_COLUMN) ?: [];
  }
} catch (Throwable $e) {
  // sin romper la vista
}

// Si no pudimos armar el mapa desde generarTickets.php, lo armamos con lo que haya (default: General)
if ($typeOptions && !$typesByCategory) {
  foreach ($typeOptions as $t) {
    $t = (string)$t;
    $cat = $typeToCategory[$t] ?? 'General';
    if (!isset($typesByCategory[$cat])) $typesByCategory[$cat] = [];
    if (!in_array($t, $typesByCategory[$cat], true)) $typesByCategory[$cat][] = $t;
  }
}

// Tipos mostrados: filtramos por la categoría actual del ticket (sin dejarlo sin su valor actual)
$currentCategory = (string)($ticket['category'] ?? '');
$typeOptionsForCategory = $typeOptions;

// Asegurar que los valores actuales aparezcan aunque no estén en las listas

$currentArea = (string)($ticket['area'] ?? '');
$currentType = (string)($ticket['type'] ?? '');
if ($currentArea !== '' && !in_array($currentArea, $areasOptions, true)) {
  array_unshift($areasOptions, $currentArea);
}
if ($currentType !== '' && !in_array($currentType, $typeOptions, true)) {
  array_unshift($typeOptions, $currentType);
}


// Helper: escapar HTML
function esc($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}



function fmtDT(?string $dt): string {
  if (!$dt) return '—';
  $ts = strtotime($dt);
  if (!$ts) return (string)$dt;
  return date('d/m/Y H:i', $ts);
}
// Helper: validar si un usuario pertenece a IT Support (con la lista ya cargada)
function isItSupportUser(array $itUsers, ?int $id): bool {
  if ($id === null) return true; // null = sin asignar
  foreach ($itUsers as $u) {
    if ((int)$u['id_user'] === (int)$id) return true;
  }
  return false;
}

// ===== Auditoría / Historial de modificaciones =====
function getLoggedUserId(): ?int {
  // auth.php normalmente inicia sesión; aquí solo leemos lo que exista.
  if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    foreach (['id_user','user_id','id'] as $k) {
      if (isset($_SESSION['user'][$k]) && is_numeric($_SESSION['user'][$k])) return (int)$_SESSION['user'][$k];
    }
  }
  foreach (['id_user','user_id','uid'] as $k) {
    if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k])) return (int)$_SESSION[$k];
  }
  return null;
}

function ensureClosedAtColumn(PDO $pdo): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM tickets LIKE 'closed_at'");
    $st->execute();
    if ($st->fetch(PDO::FETCH_ASSOC)) return true;
    $pdo->exec("ALTER TABLE tickets ADD COLUMN closed_at DATETIME NULL");
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function ensureTicketModsTable(PDO $pdo): bool {
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
    return true;
  } catch (Throwable $e) {
    return false;
  }
}


function ensureTicketCommentsTable(PDO $pdo): bool {
  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS ticket_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ticket_id INT NOT NULL,
        comment TEXT NOT NULL,
        created_by_user_id INT NULL,
        created_by_name VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ticket (ticket_id),
        INDEX idx_created_at (created_at),
        INDEX idx_created_by (created_by_user_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function userNameById(PDO $pdo, ?int $id): string {
  if (!$id) return 'Sin asignar';
  static $cache = [];
  if (isset($cache[$id])) return $cache[$id];
  try {
    $st = $pdo->prepare("SELECT full_name FROM users WHERE id_user = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $name = $st->fetchColumn();
    $cache[$id] = $name ? (string)$name : ('Usuario #' . $id);
    return $cache[$id];
  } catch (Throwable $e) {
    return 'Usuario #' . $id;
  }
}

function assignedLabel(PDO $pdo, ?int $id): string {
  return $id ? userNameById($pdo, $id) : 'Sin asignar';
}


// Defaults si no existen (por si tu tabla aún no tiene priority)
$ticket['priority'] = $ticket['priority'] ?? 'Media';

// 3) Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Agregar comentario interno (historial). No afecta el comentario original del ticket.
  if (isset($_POST['add_comment'])) {
    $newComment = trim((string)($_POST['new_comment'] ?? ''));
    if ($newComment === '') {
      $errors[] = 'Escribe un comentario antes de agregar.';
    } elseif (mb_strlen($newComment) > 2000) {
      $errors[] = 'El comentario es demasiado largo (máx. 2000 caracteres).';
    } elseif (empty($commentsOk)) {
      $errors[] = 'No se pudo habilitar el historial de comentarios en BD.';
    }

    if (!$errors) {
      $authorId = getLoggedUserId();
      $authorName = $authorId ? userNameById($pdo, (int)$authorId) : 'Sistema';
      try {
        $ins = $pdo->prepare("INSERT INTO ticket_comments (ticket_id, comment, created_by_user_id, created_by_name) VALUES (:t, :c, :uid, :uname)");
        $ins->execute([
          ':t' => $ticketId,
          ':c' => $newComment,
          ':uid' => $authorId,
          ':uname' => $authorName,
        ]);
        header('Location: ticket_edit.php?id=' . $ticketId . '#comments');
        exit;
      } catch (Throwable $e) {
        $errors[] = 'No se pudo guardar el comentario: ' . $e->getMessage();
      }
    }
  }

  $assigned = $_POST['assigned_user_id'] ?? '';
  $priority = trim($_POST['priority'] ?? 'Media');
  $status   = trim($_POST['status'] ?? 'Pendiente');
  $area     = trim($_POST['area'] ?? ($ticketOriginal['area'] ?? ''));
  $type     = trim($_POST['type'] ?? ($ticketOriginal['type'] ?? ''));

  // Normalizar estatus en caso de que llegue en inglés
  if (in_array(strtolower($status), ['close','closed'], true)) { $status = 'Cerrado'; }

  // Normalizar assigned
  $assigned_user_id = ($assigned === '' || $assigned === '0') ? null : (int)$assigned;

  // Validación: solo IT Support
  if (!isItSupportUser($itUsers, $assigned_user_id)) {
    $errors[] = "Solo puedes asignar tickets a usuarios de IT Support.";
  }

  // Validación enums
  $validPriority = ['Baja', 'Media', 'Alta', 'Urgente'];
  if (!in_array($priority, $validPriority, true)) {
    $errors[] = "Prioridad inválida.";
  }

  $validStatus = ['Pendiente', 'En Proceso', 'Resuelto', 'Cerrado'];
  if (!in_array($status, $validStatus, true)) {
    $errors[] = "Estatus inválido.";
  }

  // Validación suave: longitudes
  if (mb_strlen($area) > 60) { $errors[] = "Area demasiado larga."; }
  if (mb_strlen($type) > 80) { $errors[] = "Tipo demasiado largo."; }

  if (!$errors) {
    // Auditoría: registrar cambios (quién modificó, qué cambió, cuándo)
    $modifierId = getLoggedUserId();
    $closeStatuses = ['Resuelto', 'Cerrado'];

    $oldAssigned = $ticketOriginal['assigned_user_id'];
    $oldPriority = (string)$ticketOriginal['priority'];
    $oldStatus   = (string)$ticketOriginal['status'];
    $oldArea     = (string)$ticketOriginal['area'];
    $oldType     = (string)$ticketOriginal['type'];

    $changes = [];

    if ($oldAssigned !== $assigned_user_id) {
      $changes[] = [
        'field_name' => 'assigned_user_id',
        'old_value'  => assignedLabel($pdo, $oldAssigned),
        'new_value'  => assignedLabel($pdo, $assigned_user_id),
      ];
    }

    if ($oldPriority !== $priority) {
      $changes[] = [
        'field_name' => 'priority',
        'old_value'  => $oldPriority !== '' ? $oldPriority : '—',
        'new_value'  => $priority,
      ];
    }

    if ($oldStatus !== $status) {
      $changes[] = [
        'field_name' => 'status',
        'old_value'  => $oldStatus !== '' ? $oldStatus : '—',
        'new_value'  => $status,
      ];
    }

    if ($oldArea !== $area) {
      $changes[] = [
        'field_name' => 'area',
        'old_value'  => $oldArea !== '' ? $oldArea : '—',
        'new_value'  => $area !== '' ? $area : '—',
      ];
    }

    if ($oldType !== $type) {
      $changes[] = [
        'field_name' => 'type',
        'old_value'  => $oldType !== '' ? $oldType : '—',
        'new_value'  => $type !== '' ? $type : '—',
      ];
    }


    $closedAtOk = ensureClosedAtColumn($pdo);
    $modsOk     = ensureTicketModsTable($pdo);

    $wasClosed  = in_array($oldStatus, $closeStatuses, true);
    $willClosed = in_array($status, $closeStatuses, true);

    // Ajuste de closed_at (solo si existe o se pudo crear)
    $setClosedSql = '';
    if ($closedAtOk) {
      if (!$wasClosed && $willClosed) {
        $setClosedSql = ", closed_at = NOW()";
      } elseif ($wasClosed && !$willClosed) {
        $setClosedSql = ", closed_at = NULL";
      }
    }

    try {
      $pdo->beginTransaction();

      // Actualizar ticket
      $up = $pdo->prepare("
        UPDATE tickets
        SET area = :area,
            type = :type,
            assigned_user_id = :assigned_user_id,
            priority = :priority,
            status = :status,
            updated_at = NOW()
            {$setClosedSql}
        WHERE id_ticket = :id
        LIMIT 1
      ");
      $up->execute([
        ':area' => $area,
        ':type' => $type,
        ':assigned_user_id' => $assigned_user_id,
        ':priority' => $priority,
        ':status' => $status,
        ':id' => $ticketId
      ]);

      // Insertar auditoría (1 registro por cambio)
      if ($modsOk && $changes) {
        $ins = $pdo->prepare("
          INSERT INTO ticket_modifications
            (ticket_id, modified_by, field_name, old_value, new_value, action, notes)
          VALUES
            (:ticket_id, :modified_by, :field_name, :old_value, :new_value, 'update', :notes)
        ");

        foreach ($changes as $c) {
          $note = null;

          // Nota opcional para cambios de estatus a cerrado/resuelto
          if ($c['field_name'] === 'status' && !$wasClosed && $willClosed) {
            $note = 'Ticket cerrado';
          }
          if ($c['field_name'] === 'status' && $wasClosed && !$willClosed) {
            $note = 'Ticket reabierto';
          }

          $ins->execute([
            ':ticket_id'    => $ticketId,
            ':modified_by'  => $modifierId,
            ':field_name'   => $c['field_name'],
            ':old_value'    => $c['old_value'],
            ':new_value'    => $c['new_value'],
            ':notes'        => $note,
          ]);
        }
      }

      $pdo->commit();

      header("Location: tickets.php?updated=1");
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = "Error al guardar en BD: " . $e->getMessage();
    }
  }
// Si hubo errores, re-pintar valores enviados
  $ticket['assigned_user_id'] = $assigned_user_id;
  $ticket['priority'] = $priority;
  $ticket['status'] = $status;
}

// helpers UI
$ticketCode = str_pad((string)$ticket['id_ticket'], 3, '0', STR_PAD_LEFT);
$assignedName = '—';
$ticketUrl = trim((string)($ticket['ticket_url'] ?? ''));
$ticketEvidence = trim((string)($ticket['attachment_path'] ?? ''));
// Base path para construir URLs locales (evita /uploads/... que apunta a la raíz)
$basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])); // ej: /ticketsystem/public
$basePath = rtrim($basePath, '/');
if ($basePath === '/') { $basePath = ''; }

// Helper: construir href local
function localHref(string $path, string $basePath): string {
  $path = trim($path);
  if ($path === '') return '';
  if (preg_match('~^https?://~i', $path)) return $path;     // externo
  if (strpos($path, '/') === 0) return $path;               // absoluto desde root (ya viene bien)
  return $basePath . '/' . ltrim($path, '/');               // relativo al proyecto
}

if (!empty($ticket['assigned_user_id'])) {
  foreach ($itUsers as $u) {
    if ((int)$u['id_user'] === (int)$ticket['assigned_user_id']) {
      $assignedName = $u['full_name'];
      break;
    }
  }
}

// 3) Traer historial de comentarios internos (solo visible en esta vista)
$internalComments = [];
if (!empty($commentsOk)) {
  try {
    $cs = $pdo->prepare("
      SELECT 
        c.id,
        c.comment,
        c.created_at,
        c.created_by_user_id,
        COALESCE(u.full_name, c.created_by_name, CONCAT('Usuario #', c.created_by_user_id)) AS author
      FROM ticket_comments c
      LEFT JOIN users u ON u.id_user = c.created_by_user_id
      WHERE c.ticket_id = :id
      ORDER BY c.created_at ASC, c.id ASC
    ");
    $cs->execute([':id' => $ticketId]);
    $internalComments = $cs->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $internalComments = [];
  }
}

$creatorName = userNameById($pdo, (int)($ticket['id_user'] ?? 0));

?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Asignar Ticket | RH&R Ticketing</title>

  <!-- Bootstrap + FontAwesome -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <!-- CSS compartido (NO lo tocamos) -->
  <link rel="stylesheet" href="./assets/css/generarTickets.css">
  <link rel="stylesheet" href="./assets/css/menu.css">
  <link rel="stylesheet" href="./assets/css/movil.css">

  <!-- CSS SOLO para esta vista -->
  <link rel="stylesheet" href="assets/css/ticket_edit.css?v=<?= filemtime(__DIR__ . '/assets/css/ticket_edit.css') ?>">

  <script defer src="./assets/js/sidebar.js"></script>
</head>

<body class="ticket-edit-page">
  <div class="layout d-flex">

    <!-- SIDEBAR -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 d-flex justify-content-center align-items-start">
      <div class="ticket-edit-page" style="width: 100%; max-width: 980px;">

        <section class="panel card" style="width:100%;">

          <!-- Header -->
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="panel__title m-0">Asignar Ticket</h1>
            <a href="tickets.php" class="btn btn-outline-secondary btn-sm">
              <i class="fa-solid fa-arrow-left me-2"></i>Volver
            </a>
          </div>

          <?php if ($errors): ?>
            <div class="alert alert-danger">
              <b>Revisa esto:</b>
              <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                  <li><?= esc($e) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <!-- Resumen -->
          <div class="card p-3 mb-3 ticket-summary">
            <div class="row g-3">
              <div class="col-md-3"><b>ID:</b> <?= esc($ticketCode) ?></div>
              <div class="col-md-3"><b>Area:</b>
  <div class="dropdown w-100 d-inline-block">
    <button class="select-pro dropdown-toggle w-100" type="button" id="areaBtnEdit" data-bs-toggle="dropdown" aria-expanded="false">
      <span id="areaTextEdit"><?= esc((string)($ticket['area'] ?? '')) ?: 'Area' ?></span>
      <span class="chev" aria-hidden="true"></span>
    </button>

    <ul class="dropdown-menu dropdown-pro w-100" aria-labelledby="areaBtnEdit" id="areaMenuEdit">
      <?php if (empty($areasOptions)): ?>
        <li><button class="dropdown-item" type="button" data-value="<?= esc((string)($ticket['area'] ?? '')) ?>"><?= esc((string)($ticket['area'] ?? '—')) ?></button></li>
      <?php else: ?>
        <?php foreach ($areasOptions as $a): $a=(string)$a; ?>
          <li><button class="dropdown-item" type="button" data-value="<?= esc($a) ?>"><?= esc($a) ?></button></li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>

    <input type="hidden" name="area" id="areaEdit" form="ticketForm" value="<?= esc((string)($ticket['area'] ?? '')) ?>">
  </div>
</div>
              <div class="col-md-3"><b>Categoría:</b> <?= esc($ticket['category']) ?></div>
              <div class="col-md-3"><b>Tipo:</b>
  <div class="dropdown w-100 d-inline-block">
    <button class="select-pro dropdown-toggle w-100" type="button" id="typeBtnEdit" data-bs-toggle="dropdown" aria-expanded="false">
      <span id="typeTextEdit"><?= esc((string)($ticket['type'] ?? '')) ?: 'Type' ?></span>
      <span class="chev" aria-hidden="true"></span>
    </button>

    <ul class="dropdown-menu dropdown-pro w-100" aria-labelledby="typeBtnEdit" id="typeMenuEdit">
      <?php if (empty($typeOptionsForCategory)): ?>
        <li><button class="dropdown-item" type="button" data-value="<?= esc((string)($ticket['type'] ?? '')) ?>"><?= esc((string)($ticket['type'] ?? '—')) ?></button></li>
      <?php else: ?>
        <?php foreach ($typeOptionsForCategory as $topt): $topt=(string)$topt; ?>
          <li><button class="dropdown-item" type="button" data-value="<?= esc($topt) ?>"><?= esc($topt) ?></button></li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>

    <input type="hidden" name="type" id="typeEdit" form="ticketForm" value="<?= esc((string)($ticket['type'] ?? '')) ?>">
  </div>
</div>

              

              <div class="col-md-6">
                <b>URL:</b>
                <?php if (!empty($ticketUrl)): ?>
                  <?php
                    $urlHref = $ticketUrl;
                    if (!preg_match('~^https?://~i', $urlHref)) {
                      $urlHref = 'https://' . $urlHref;
                    }
                  ?>
                  <a href="<?= esc($urlHref) ?>" target="_blank" rel="noopener noreferrer">
                    <?= esc($ticketUrl) ?>
                  </a>
                <?php else: ?>
                  <span class="text-muted">Sin URL</span>
                <?php endif; ?>
              </div>

              <div class="col-md-6">
                <b>Evidencia:</b>
                <?php if (!empty($ticketEvidence)): ?>
                  <?php
                    $evHref = localHref($ticketEvidence, $basePath);
                    $ext = strtolower(pathinfo($ticketEvidence, PATHINFO_EXTENSION));
                    $evType = ($ext === 'pdf') ? 'pdf' : 'img';
                    $evName = basename($ticketEvidence);
                  ?>
                  <a href="#" 
                     class="evidence-link"
                     data-bs-toggle="modal"
                     data-bs-target="#evidenceModal"
                     data-ev-src="<?= esc($evHref) ?>"
                     data-ev-type="<?= esc($evType) ?>"
                     data-ev-name="<?= esc($evName) ?>">
                    <i class="fa-solid fa-paperclip"></i> Ver evidencia
                  </a>
                <?php else: ?>
                  <span class="text-muted">Sin evidencia</span>
                <?php endif; ?>
              </div>
<div class="col-md-4">
                <span class="chip brand">
                  <i class="fa-solid fa-user-check"></i>
                  <span><b>Asignado:</b> <?= esc($assignedName) ?></span>
                </span>
              </div>

              <div class="col-md-4">
                <span class="chip warn">
                  <i class="fa-solid fa-triangle-exclamation"></i>
                  <span><b>Prioridad:</b> <?= esc($ticket['priority']) ?></span>
                </span>
              </div>

              <div class="col-md-4">
                <span class="chip ok">
                  <i class="fa-solid fa-circle-info"></i>
                  <span><b>Status:</b> <?= esc($ticket['status']) ?></span>
                </span>
              </div>

              <div class="col-12" id="comments">
                <div class="d-flex align-items-center justify-content-between">
                  <b>Comentarios:</b>
                  <span class="text-muted small">Historial interno (solo en Edit)</span>
                </div>

                <div class="mt-2" style="display:flex;flex-direction:column;gap:10px;">
                  <!-- Comentario original -->
                  <div style="background:rgba(13,110,253,.06);border:1px solid rgba(13,110,253,.18);border-radius:16px;padding:12px 14px;box-shadow:0 10px 22px rgba(0,0,0,.06);">
                    <div class="text-muted small d-flex justify-content-between" style="gap:10px;">
                      <span><b>Original</b> — <?= esc($creatorName) ?></span>
                      <span><?= esc(fmtDT($ticket['created_at'] ?? '')) ?></span>
                    </div>
                    <div style="margin-top:6px;">
                      <?= nl2br(esc((string)($ticket['comments'] ?? ''))) ?>
                    </div>
                  </div>

                  <!-- Comentarios internos agregados -->
                  <?php if (!empty($internalComments)): ?>
                    <?php foreach ($internalComments as $c): ?>
                      <div style="background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:12px 14px;box-shadow:0 10px 22px rgba(0,0,0,.06);">
                        <div class="text-muted small d-flex justify-content-between" style="gap:10px;">
                          <span><b><?= esc($c['author']) ?></b></span>
                          <span><?= esc(fmtDT($c['created_at'] ?? '')) ?></span>
                        </div>
                        <div style="margin-top:6px;">
                          <?= nl2br(esc((string)$c['comment'])) ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <div class="text-muted small">Aún no hay notas internas agregadas.</div>
                  <?php endif; ?>
                </div>

                <!-- Agregar nota -->
                <form method="POST" action="ticket_edit.php?id=<?= (int)$ticketId ?>#comments" class="mt-3">
                  <input type="hidden" name="add_comment" value="1">
                  <label class="form-label mb-1">Agregar nota interna</label>
                  <textarea name="new_comment" class="form-control" rows="3" placeholder="Ej: Es fallo general, hay que esperar..." maxlength="2000"></textarea>
                  <div class="d-flex justify-content-end mt-2">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                      <i class="fa-solid fa-plus"></i> Agregar
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <!-- Form Asignación -->
          <form id="ticketForm" method="POST" class="card p-3">
            <div class="row g-3">

              <div class="col-md-6">
                <label class="form-label">Assigned to (solo IT Support)</label>
                <select name="assigned_user_id" class="form-select">
                  <option value="0">— Sin asignar —</option>
                  <?php foreach ($itUsers as $u): ?>
                    <option value="<?= (int)$u['id_user'] ?>"
                      <?= ((int)$ticket['assigned_user_id'] === (int)$u['id_user']) ? 'selected' : '' ?>>
                      <?= esc($u['full_name']) ?> (<?= esc($u['email']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted">Solo aparecen usuarios activos con AREA = IT Support.</small>
              </div>

              <div class="col-md-3">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                  <?php foreach (['Baja','Media','Alta','Urgente'] as $p): ?>
                    <option value="<?= esc($p) ?>" <?= ($ticket['priority'] === $p) ? 'selected' : '' ?>>
                      <?= esc($p) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <?php foreach (['Pendiente','En Proceso','Resuelto','Cerrado'] as $s): ?>
                    <option value="<?= esc($s) ?>" <?= ($ticket['status'] === $s) ? 'selected' : '' ?>>
                      <?= esc($s) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-12 d-flex justify-content-end gap-2">
                <a href="tickets.php" class="btn btn-outline-secondary">
                  Cancelar
                </a>
                <button class="btn btn-primary" type="submit">
                  <i class="fa-solid fa-floppy-disk me-2"></i>Guardar
                </button>
              </div>

            </div>
          </form>

        </section>
      </div>
    </main>

  </div>

  
  <!-- ====== MODAL: Evidencia ====== -->
  <div class="modal fade evidence-modal" id="evidenceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="evidenceTitle">Evidencia</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>

        <div class="modal-body">
          <div class="evidence-toolbar">
            <div class="btn-group" role="group" aria-label="Zoom">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="evZoomOut" title="Alejar">
                <i class="fa-solid fa-magnifying-glass-minus"></i>
              </button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="evZoomReset" title="Restablecer">
                <i class="fa-solid fa-rotate-left"></i>
              </button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="evZoomIn" title="Acercar">
                <i class="fa-solid fa-magnifying-glass-plus"></i>
              </button>
            </div>

            <a class="btn btn-outline-primary btn-sm ms-auto" id="evOpenNewTab" href="#" target="_blank" rel="noopener noreferrer">
              <i class="fa-solid fa-up-right-from-square me-1"></i>Abrir
            </a>
          </div>

          <div class="evidence-canvas mt-3" id="evCanvas">
            <img id="evImg" alt="Evidencia" />
            <iframe id="evPdf" title="Evidencia PDF"></iframe>
          </div>

          <div class="evidence-hint mt-2 text-muted small">
            Tip: usa los botones para zoom (imágenes). En PDF puedes usar el zoom del navegador.
          </div>
        </div>
      </div>
    </div>
  </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    (function(){
      const modalEl = document.getElementById('evidenceModal');
      if(!modalEl) return;

      const titleEl = document.getElementById('evidenceTitle');
      const imgEl   = document.getElementById('evImg');
      const pdfEl   = document.getElementById('evPdf');
      const openEl  = document.getElementById('evOpenNewTab');

      const zoomInBtn    = document.getElementById('evZoomIn');
      const zoomOutBtn   = document.getElementById('evZoomOut');
      const zoomResetBtn = document.getElementById('evZoomReset');

      let scale = 1;

      function applyScale(){
        imgEl.style.transform = 'scale(' + scale.toFixed(2) + ')';
      }

      function reset(){
        scale = 1;
        applyScale();
      }

      modalEl.addEventListener('show.bs.modal', function (event) {
        const trigger = event.relatedTarget;
        if(!trigger) return;

        const src  = trigger.getAttribute('data-ev-src') || '';
        const type = trigger.getAttribute('data-ev-type') || 'img';
        const name = trigger.getAttribute('data-ev-name') || 'Evidencia';

        titleEl.textContent = 'Evidencia: ' + name;
        openEl.href = src;

        // reset zoom
        reset();

        if(type === 'pdf'){
          imgEl.style.display = 'none';
          pdfEl.style.display = 'block';
          pdfEl.src = src;
          // deshabilitar zoom botones (no aplica al iframe)
          zoomInBtn.disabled = true;
          zoomOutBtn.disabled = true;
          zoomResetBtn.disabled = true;
        } else {
          pdfEl.style.display = 'none';
          pdfEl.src = '';
          imgEl.style.display = 'block';
          imgEl.src = src;
          zoomInBtn.disabled = false;
          zoomOutBtn.disabled = false;
          zoomResetBtn.disabled = false;
        }
      });

      modalEl.addEventListener('hidden.bs.modal', function(){
        // limpiar fuentes
        imgEl.src = '';
        pdfEl.src = '';
        reset();
      });

      zoomInBtn.addEventListener('click', function(){
        scale = Math.min(4, scale + 0.15);
        applyScale();
      });

      zoomOutBtn.addEventListener('click', function(){
        scale = Math.max(0.4, scale - 0.15);
        applyScale();
      });

      zoomResetBtn.addEventListener('click', reset);

      // zoom con rueda (solo imagen)
      const canvas = document.getElementById('evCanvas');
      canvas.addEventListener('wheel', function(e){
        if(imgEl.style.display === 'none') return;
        e.preventDefault();
        const delta = Math.sign(e.deltaY);
        scale = delta > 0 ? Math.max(0.4, scale - 0.10) : Math.min(4, scale + 0.10);
        applyScale();
      }, { passive:false });
    })();
  </script>


<script>
  // ===== Dropdown binding (mismo patrón que generarTickets.php) =====
  (function(){
    function bindMenu(menuId, textId, inputId){
      const menu  = document.getElementById(menuId);
      const text  = document.getElementById(textId);
      const input = document.getElementById(inputId);
      if (!menu) return;

      menu.addEventListener("click", (e) => {
        const item = e.target.closest(".dropdown-item[data-value]");
        if (!item) return;
        const val = item.getAttribute("data-value") || (item.textContent || "").trim();
        if (text)  text.textContent = val;
        if (input) input.value = val;
      });
    }

    bindMenu("areaMenuEdit", "areaTextEdit", "areaEdit");
    bindMenu("typeMenuEdit", "typeTextEdit", "typeEdit");
  })();

  // ===== Tipos dinámicos según Categoría (misma fuente que generarTickets.php) =====
  window.__ALL_TYPES = <?= json_encode(array_values($typeOptions ?: []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  window.__TYPES_BY_CATEGORY = <?= json_encode($typesByCategory ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  function __escapeHtml(s){
    return String(s).replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  }
  function __escapeAttr(s){
    return __escapeHtml(s).replace(/`/g, "&#096;");
  }

  function rebuildTypeMenuByCategory(category){
    const menu  = document.getElementById("typeMenuEdit");
    const text  = document.getElementById("typeTextEdit");
    const input = document.getElementById("typeEdit");
    if (!menu || !input) return;

    const map = window.__TYPES_BY_CATEGORY || {};
    const all = window.__ALL_TYPES || [];
    const current = (input.value || (text ? text.textContent : "") || "").trim();

    // Mostrar SIEMPRE todos los tipos (igual que generarTickets.php).
    let list = all.slice();

    // Nunca pierdas el valor actual aunque no esté mapeado
    if (current && !list.includes(current)) list.unshift(current);

    // Construir items
    menu.innerHTML = list.map(v =>
      `<li><button class="dropdown-item" type="button" data-value="${__escapeAttr(v)}">${__escapeHtml(v)}</button></li>`
    ).join("");

    // Mantener texto visible
    if (text && current) text.textContent = current;
    if (!current && list[0]) {
      if (text) text.textContent = list[0];
      input.value = list[0];
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    const cat = <?= json_encode((string)($ticket['category'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    rebuildTypeMenuByCategory((cat || "").trim());

    // Si un día vuelves la categoría editable, esto ya queda listo:
    const catSelect = document.querySelector('select[name="category"], #category');
    if (catSelect) {
      catSelect.addEventListener("change", () => {
        rebuildTypeMenuByCategory((catSelect.value || "").trim());
      });
    }
  });
</script>

</body>
</html>