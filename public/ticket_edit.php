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
  'category' => (string)($ticket['category'] ?? ''),
];



// Tables are created by migrate.php (no runtime DDL needed)
$modsOkInit = true;
$commentsOk = true;

// AJAX: comentarios internos en "tiempo real" (sin refresh)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'comments') {
  $after = (int)($_GET['after'] ?? 0);

  $rows = [];
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
        WHERE c.ticket_id = :id AND c.id > :after
        ORDER BY c.id ASC
      ");
      $cs->execute([':id' => $ticketId, ':after' => $after]);
      $rows = $cs->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      $rows = [];
    }
  }

  $items = [];
  foreach ($rows as $r) {
    $items[] = [
      'id' => (int)($r['id'] ?? 0),
      'author' => (string)($r['author'] ?? ''),
      'created_at' => (string)fmtDT($r['created_at'] ?? ''),
      'comment_html' => (string)nl2br(esc((string)($r['comment'] ?? '')))
    ];
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
  exit;
}

// 2) Lista SOLO IT Support (activos)
$itStmt = $pdo->query("
  SELECT id_user, full_name, email
  FROM users
  WHERE (AREA = 'IT Support' OR AREA = 'Managers') AND is_active = 1
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
  if ($id === null) return true; // null = unassigned
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
  if (!$id) return 'Unassigned';
  static $cache = [];
  if (isset($cache[$id])) return $cache[$id];
  try {
    $st = $pdo->prepare("SELECT full_name FROM users WHERE id_user = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $name = $st->fetchColumn();
    $cache[$id] = $name ? (string)$name : ('User #' . $id);
    return $cache[$id];
  } catch (Throwable $e) {
    return 'User #' . $id;
  }
}

function assignedLabel(PDO $pdo, ?int $id): string {
  return $id ? userNameById($pdo, $id) : 'Unassigned';
}


// Defaults si no existen (por si tu tabla aún no tiene priority)
$ticket['priority'] = $ticket['priority'] ?? 'Media';

// 3) Guardar cambios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Agregar comentario interno (historial). No afecta el comentario original del ticket.
  $newComment = trim((string)($_POST['new_comment'] ?? ''));
  if ($newComment !== '') {
    if (mb_strlen($newComment) > 2000) {
      $errors[] = 'The comment is too long (max 2000 characters).';
    } elseif (empty($commentsOk)) {
      $errors[] = 'Could not enable comment history in DB.';
    }

    if (!$errors) {
      $authorId = getLoggedUserId();
      $authorName = $authorId ? userNameById($pdo, (int)$authorId) : 'System';
      try {
        $ins = $pdo->prepare("INSERT INTO ticket_comments (ticket_id, comment, created_by_user_id, created_by_name) VALUES (:t, :c, :uid, :uname)");
        $ins->execute([
          ':t' => $ticketId,
          ':c' => $newComment,
          ':uid' => $authorId,
          ':uname' => $authorName,
        ]);
      } catch (Throwable $e) {
        $errors[] = 'Could not save the comment: ' . $e->getMessage();
      }
    }
  }

  $assigned = $_POST['assigned_user_id'] ?? '';
  $priority = trim($_POST['priority'] ?? 'Media');
  $status   = trim($_POST['status'] ?? 'Pendiente');
  $area     = trim($_POST['area'] ?? ($ticketOriginal['area'] ?? ''));
  $type     = trim($_POST['type'] ?? ($ticketOriginal['type'] ?? ''));
  $category = trim($_POST['category'] ?? ($ticketOriginal['category'] ?? ''));

  // Normalizar estatus en caso de que llegue en inglés
  if (in_array(strtolower($status), ['close','closed'], true)) { $status = 'Cerrado'; }

  // Normalizar assigned
  $assigned_user_id = ($assigned === '' || $assigned === '0') ? null : (int)$assigned;

  // Validación: solo IT Support
  if (!isItSupportUser($itUsers, $assigned_user_id)) {
    $errors[] = "You can only assign tickets to IT Support or Managers users.";
  }

  // Validación enums
  $validPriority = ['Baja', 'Media', 'Alta', 'Urgente'];
  if (!in_array($priority, $validPriority, true)) {
    $errors[] = "Invalid priority.";
  }

  $validStatus = ['Pendiente', 'En Proceso', 'Resuelto', 'Cerrado'];
  if (!in_array($status, $validStatus, true)) {
    $errors[] = "Invalid status.";
  }

  // Validación suave: longitudes
  if (mb_strlen($area) > 60) { $errors[] = "Area is too long."; }
  if (mb_strlen($type) > 80) { $errors[] = "Type is too long."; }

  if (!$errors) {
    // Auditoría: registrar cambios (quién modificó, qué cambió, cuándo)
    $modifierId = getLoggedUserId();
    $closeStatuses = ['Resuelto', 'Cerrado'];

    $oldAssigned = $ticketOriginal['assigned_user_id'];
    $oldPriority = (string)$ticketOriginal['priority'];
    $oldStatus   = (string)$ticketOriginal['status'];
    $oldArea     = (string)$ticketOriginal['area'];
    $oldType     = (string)$ticketOriginal['type'];
    $oldCategory = (string)$ticketOriginal['category'];

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

    if ($oldCategory !== $category) {
      $changes[] = [
        'field_name' => 'category',
        'old_value'  => $oldCategory !== '' ? $oldCategory : '—',
        'new_value'  => $category !== '' ? $category : '—',
      ];
    }


    $closedAtOk = true; // Column created by migrate.php
    $modsOk     = true; // Table created by migrate.php

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
        SET category = :category,
            area = :area,
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
        ':category' => $category,
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
            $note = 'Ticket closed';
          }
          if ($c['field_name'] === 'status' && $wasClosed && !$willClosed) {
            $note = 'Ticket reopened';
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

      // Correo de asignacion: cuando cambia el asignado a un usuario valido.
      // Si falla el mail, NO debe romper el guardado.
      if ($assigned_user_id && $oldAssigned !== $assigned_user_id) {
        try {
          require_once __DIR__ . '/config/mailer.php';

          // Destinatario: creador del ticket.
          $creatorEmail = '';
          $creatorNameForMail = 'User';
          if (!empty($ticket['id_user'])) {
            $stCreator = $pdo->prepare("SELECT full_name, email FROM users WHERE id_user = :id LIMIT 1");
            $stCreator->execute([':id' => (int)$ticket['id_user']]);
            $creatorRow = $stCreator->fetch(PDO::FETCH_ASSOC) ?: [];
            $creatorEmail = trim((string)($creatorRow['email'] ?? ''));
            $creatorNameForMail = (string)($creatorRow['full_name'] ?? $creatorNameForMail);
          }

          if ($creatorEmail !== '' && function_exists('notify_ticket_assigned')) {
            // (El nombre real se carga de la base de datos justo abajo)
            $assignedRole = 'IT Support';
            $assignedPhone = 'N/A';

            // Traer rol + telefono del agente (si existe alguna columna de telefono).
            try {
              $stAgent = $pdo->prepare("
                SELECT
                  u.full_name,
                  COALESCE(u.AREA, 'IT Support') AS role_name
                FROM users u
                WHERE u.id_user = :id
                LIMIT 1
              ");
              $stAgent->execute([':id' => $assigned_user_id]);
              $agentRow = $stAgent->fetch(PDO::FETCH_ASSOC) ?: [];

              $assignedName = (string)($agentRow['full_name'] ?? $assignedName);
              $assignedRole = (string)($agentRow['role_name'] ?? $assignedRole);

            } catch (Throwable $agentDataErr) {
              // Si no se puede consultar extra data, seguimos con defaults.
            }

            // Fecha real de asignacion (updated_at post-commit).
            $assignedAt = date('Y-m-d H:i:s');
            try {
              $stTime = $pdo->prepare("SELECT updated_at FROM tickets WHERE id_ticket = :id LIMIT 1");
              $stTime->execute([':id' => $ticketId]);
              $dbUpdatedAt = trim((string)$stTime->fetchColumn());
              if ($dbUpdatedAt !== '') $assignedAt = $dbUpdatedAt;
            } catch (Throwable $timeErr) {
              // fallback a now
            }

            $ticketMail = [
              'id' => $ticketId,
              'type' => (string)$type,
              'area' => (string)$area,
              'category' => (string)($ticket['category'] ?? 'General'),
              'asunto_ticket' => trim(((string)$type !== '' ? (string)$type : 'Ticket') . ' | ' . ((string)$area !== '' ? (string)$area : 'Area N/A')),
              'created_at' => (string)($ticket['created_at'] ?? ''),
              'assigned_at' => $assignedAt,
              'created_by' => $creatorNameForMail,
              'assigned_to' => $assignedName,
              'assigned_role' => $assignedRole,
              'assigned_phone' => $assignedPhone,
              'url_ticket' => function_exists('my_tickets_url') ? my_tickets_url() : '',
            ];

            notify_ticket_assigned($ticketMail, $creatorEmail, $creatorNameForMail);
          } else {
            error_log('[MAIL] Ticket asignado sin destinatario: id_ticket=' . $ticketId);
          }
        } catch (Throwable $mailErr) {
          error_log('[MAIL] No se pudo enviar correo de ticket asignado: ' . $mailErr->getMessage());
        }
      }

      // Correo de cierre: solo cuando cambia especificamente a "Cerrado".
      // Si falla el mail, NO debe romper el guardado.
      if ($status === 'Cerrado' && $oldStatus !== 'Cerrado') {
        try {
          require_once __DIR__ . '/config/mailer.php';

          // Destinatario: creador del ticket
          $creatorEmail = '';
          $creatorNameForMail = 'User';
          if (!empty($ticket['id_user'])) {
            $stCreator = $pdo->prepare("SELECT full_name, email FROM users WHERE id_user = :id LIMIT 1");
            $stCreator->execute([':id' => (int)$ticket['id_user']]);
            $creatorRow = $stCreator->fetch(PDO::FETCH_ASSOC) ?: [];
            $creatorEmail = trim((string)($creatorRow['email'] ?? ''));
            $creatorNameForMail = (string)($creatorRow['full_name'] ?? $creatorNameForMail);
          }

          if ($creatorEmail !== '' && function_exists('notify_ticket_closed')) {
            // Tomar created_at/closed_at reales desde BD para el template
            $stTimes = $pdo->prepare("SELECT created_at, closed_at FROM tickets WHERE id_ticket = :id LIMIT 1");
            $stTimes->execute([':id' => $ticketId]);
            $times = $stTimes->fetch(PDO::FETCH_ASSOC) ?: [];
            $createdAt = (string)($times['created_at'] ?? ($ticket['created_at'] ?? ''));
            $closedAt  = (string)($times['closed_at'] ?? date('Y-m-d H:i:s'));

            // Texto de resolucion (ultma nota interna, si existe)
            $resolutionDescription = 'The ticket was marked as closed by the support team.';
            $interactionsCount = 1;
            if (!empty($commentsOk)) {
              try {
                $stCount = $pdo->prepare("SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = :id");
                $stCount->execute([':id' => $ticketId]);
                $interactionsCount += (int)$stCount->fetchColumn();

                $stLast = $pdo->prepare("SELECT comment FROM ticket_comments WHERE ticket_id = :id ORDER BY id DESC LIMIT 1");
                $stLast->execute([':id' => $ticketId]);
                $lastComment = trim((string)$stLast->fetchColumn());
                if ($lastComment !== '') {
                  $resolutionDescription = $lastComment;
                }
              } catch (Throwable $mailDataErr) {
                // Si falla obtener extras, seguimos con defaults.
              }
            }

            // Tiempo de resolucion aproximado
            $resolutionTime = 'N/A';
            try {
              if ($createdAt !== '' && $closedAt !== '') {
                $dtStart = new DateTime($createdAt);
                $dtEnd = new DateTime($closedAt);
                $diff = $dtStart->diff($dtEnd);
                $parts = [];
                if ($diff->d > 0) $parts[] = $diff->d . ' d';
                if ($diff->h > 0) $parts[] = $diff->h . ' h';
                if ($diff->i > 0) $parts[] = $diff->i . ' min';
                if (!$parts) $parts[] = '0 min';
                $resolutionTime = implode(' ', array_slice($parts, 0, 2));
              }
            } catch (Throwable $timeErr) {
              $resolutionTime = 'N/A';
            }

            $resolvedBy = $assigned_user_id ? userNameById($pdo, $assigned_user_id) : ($modifierId ? userNameById($pdo, $modifierId) : 'IT Help Desk');
            $titleForMail = trim(($type !== '' ? $type : 'Ticket') . ' | ' . ($area !== '' ? $area : 'Area N/A'));
            $reopenUrl = function_exists('my_tickets_url') ? my_tickets_url() : '';

            $ticketMail = [
              'id' => $ticketId,
              'titulo' => $titleForMail,
              'category' => (string)($ticket['category'] ?? 'General'),
              'created_at' => $createdAt,
              'closed_at' => $closedAt,
              'created_by' => $creatorNameForMail,
              'resolved_by' => $resolvedBy,
              'resolution_description' => $resolutionDescription,
              'resolution_time' => $resolutionTime,
              'interactions_count' => (string)$interactionsCount,
              'prioridad' => $priority,
              'survey_url' => '#',
              'reopen_url' => $reopenUrl,
              'reopen_days' => '7',
            ];

            notify_ticket_closed($ticketMail, $creatorEmail, $creatorNameForMail);
          } else {
            error_log('[MAIL] Ticket cerrado sin destinatario: id_ticket=' . $ticketId);
          }
        } catch (Throwable $mailErr) {
          error_log('[MAIL] No se pudo enviar correo de ticket cerrado: ' . $mailErr->getMessage());
        }
      }

      if (!empty($_POST['post_note_btn'])) {
        header('Location: ticket_edit.php?id=' . $ticketId . '#comments');
      } else {
        header("Location: tickets.php?updated=1");
      }
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = "Error saving to DB: " . $e->getMessage();
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
        COALESCE(u.full_name, c.created_by_name, CONCAT('User #', c.created_by_user_id)) AS author
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


$lastInternalId = 0;
if (!empty($internalComments)) {
  $tmpLast = end($internalComments);
  $lastInternalId = (int)($tmpLast['id'] ?? 0);
}

$creatorName = userNameById($pdo, (int)($ticket['id_user'] ?? 0));

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Assign Ticket | RH&amp;R Ticketing</title>
  <link rel="icon" type="image/png" href="./assets/img/isotopo.png" />

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

<style>
:root {
  --brand: #083B5C;
  --brand-hover: #D14B16;
  --brand-rgb: 8, 59, 92;
  --slate-50:  #f8fafc;
  --slate-100: #f1f5f9;
  --slate-200: #e2e8f0;
  --slate-300: #cbd5e1;
  --slate-400: #94a3b8;
  --slate-500: #64748b;
  --slate-700: #334155;
  --slate-800: #1e293b;
  --slate-900: #0f172a;
}

/* ── Modal Fallas Styles (from generarTickets.php) ─────────────────────── */
#modalFallas .modal-content {
  border-radius: 20px;
  border: 1px solid var(--slate-200);
  box-shadow: 0 25px 50px -12px rgba(0,0,0,0.15);
  overflow: hidden;
}
#modalFallas .modal-header {
  background: #fff;
  border-bottom: 1px solid var(--slate-100);
  padding: 20px 24px 16px;
}
#modalFallas .modal-title {
  font-size: 1.1rem; font-weight: 800; color: var(--slate-900);
}
.modal-back-btn {
  background: var(--brand); border: 1px solid var(--brand-hover);
  color: #fff; border-radius: 8px;
  padding: 6px 14px; font-size: 0.8rem; font-weight: 700;
  display: none; cursor: pointer; transition: all 0.15s ease;
  align-items: center; gap: 6px;
  box-shadow: 0 1px 3px rgba(var(--brand-rgb), 0.3);
}
.modal-back-btn:hover { background: var(--brand-hover); }
.modal-back-btn.visible { display: flex !important; }

/* ── Step 1: Categoría cards grid ─────────────────────── */
.cat-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  padding: 20px 24px;
}
@media (max-width: 480px) { .cat-grid { grid-template-columns: repeat(2, 1fr); } }

.cat-card {
  display: flex; flex-direction: column; align-items: center;
  gap: 10px; padding: 18px 10px;
  border-radius: 14px; border: 1.5px solid var(--slate-200);
  background: #fff; cursor: pointer;
  transition: all 0.18s ease; text-align: center;
}
.cat-card:hover {
  border-color: rgba(var(--brand-rgb), 0.4);
  background: rgba(var(--brand-rgb), 0.03);
  transform: translateY(-2px);
  box-shadow: 0 4px 10px rgba(0,0,0,0.05);
}
.cat-card.is-selected {
  border-color: var(--brand);
  background: rgba(var(--brand-rgb), 0.04);
  box-shadow: 0 0 0 3px rgba(var(--brand-rgb), 0.1);
}
.cat-card__ico {
  width: 48px; height: 48px; border-radius: 14px;
  background: var(--slate-50); border: 1px solid var(--slate-200);
  display: grid; place-items: center;
  font-size: 1.4rem; color: var(--brand);
  transition: all 0.18s ease;
}
.cat-card:hover .cat-card__ico { background: rgba(var(--brand-rgb), 0.08); }
.cat-card__label { font-size: 0.82rem; font-weight: 700; color: var(--slate-700); }

/* ── Step 2: Sub-fallas list ──────────────────────────── */
.subfalla-list {
  display: flex; flex-direction: column;
  gap: 6px; padding: 16px 24px 24px;
}
.subfalla-item {
  display: flex; align-items: center; gap: 14px;
  padding: 12px 16px; border-radius: 12px;
  border: 1px solid var(--slate-200); background: #fff;
  cursor: pointer; font-size: 0.9rem; font-weight: 600;
  color: var(--slate-700); transition: all 0.15s ease;
}
.subfalla-item:hover {
  border-color: rgba(var(--brand-rgb), 0.4);
  background: rgba(var(--brand-rgb), 0.03);
  color: var(--brand);
}
.subfalla-item__ico { width: 18px; text-align: center; color: var(--slate-400); font-size: 0.85rem; }
.subfalla-item:hover .subfalla-item__ico { color: var(--brand); }
.subfalla-item--other { border-style: dashed; color: var(--slate-500); }

/* ── Category selected badge ──────────────────────────── */
.cat-breadcrumb {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 24px 4px;
  font-size: 0.8rem; font-weight: 700; color: var(--slate-500);
}
.cat-breadcrumb i { color: var(--brand); }
</style>
</head>

<body class="ticket-edit-page">
  <div class="layout d-flex">

    <!-- SIDEBAR -->
    <?php include __DIR__ . '/partials/menu.php'; ?>

    <!-- MAIN -->
    <main class="main flex-grow-1 ticket-edit-hq p-0 p-md-4" style="background: var(--slate-50); min-height: 100vh;">
      <div class="container-fluid mx-auto" style="max-width: 1200px;">
        
        <!-- Header Hero -->
        <header class="mb-4 mt-3 mt-md-0 px-3 px-md-0">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="hq-badge hq-badge-id"><i class="fa-solid fa-ticket"></i> <?= esc($ticketCode) ?></span>
                
                <?php
                  $sClass = 'hq-badge-status-default';
                  if($ticket['status']==='Pendiente') $sClass='hq-badge-status-pending';
                  if($ticket['status']==='En Proceso') $sClass='hq-badge-status-process';
                  if($ticket['status']==='Resuelto') $sClass='hq-badge-status-resolved';
                  if($ticket['status']==='Cerrado') $sClass='hq-badge-status-closed';

                  $uiStatus = match($ticket['status']) {
                      'Pendiente'  => 'Open',
                      'En Proceso' => 'In progress',
                      'Resuelto'   => 'Resolved',
                      'Cerrado'    => 'Closed',
                      default      => $ticket['status']
                  };

                  $pClass = 'hq-badge-prio-default';
                  if($ticket['priority']==='Baja') $pClass='hq-badge-prio-low';
                  if($ticket['priority']==='Media') $pClass='hq-badge-prio-medium';
                  if($ticket['priority']==='Alta') $pClass='hq-badge-prio-high';
                  if($ticket['priority']==='Urgente') $pClass='hq-badge-prio-urgent';

                  $uiPrio = match($ticket['priority']) {
                      'Baja'    => 'Low',
                      'Media'   => 'Medium',
                      'Alta'    => 'High',
                      'Urgente' => 'Urgent',
                      default   => $ticket['priority']
                  };
                ?>
                <span class="hq-badge <?= $sClass ?>"><?= esc($uiStatus) ?></span>
                <span class="hq-badge <?= $pClass ?>"><i class="fa-solid fa-bolt"></i> <?= esc($uiPrio) ?></span>
              </div>
              <h1 class="hq-title mb-0">
                <span id="ticketCatDisplay"><?= esc($ticket['category']) ?></span> 
                <span class="hq-title-sub">/ <span id="ticketTypeDisplay"><?= esc((string)($ticket['type'] ?? 'General')) ?></span></span>
              </h1>
            </div>
            <a href="tickets.php" class="hq-btn hq-btn-ghost mt-1">
              <i class="fa-solid fa-arrow-left me-2"></i>Back
            </a>
          </div>
        </header>

        <?php if ($errors): ?>
          <div class="hq-alert-danger mb-4 mx-3 mx-md-0">
            <div class="d-flex align-items-center gap-2 fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation"></i> Check the following errors:</div>
            <ul class="mb-0 ps-4">
              <?php foreach ($errors as $e): ?>
                <li><?= esc($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="row g-4 px-3 px-md-0">
          
          <!-- LEFT COL: Info & Comments -->
          <div class="col-lg-8 d-flex flex-column gap-4">
            
            <!-- Info Card -->
            <div class="hq-card p-4">
              <h3 class="hq-section-head"><i class="fa-solid fa-circle-info me-2 text-muted"></i> Ticket Information</h3>
              <div class="row g-4 mt-1">
                <div class="col-sm-6">
                  <div class="hq-field">
                    <label>Area</label>
                    <div class="dropdown w-100">
                      <button class="hq-select-btn dropdown-toggle w-100 text-start d-flex justify-content-between align-items-center" type="button" id="areaBtnEdit" data-bs-toggle="dropdown" aria-expanded="false">
                        <span id="areaTextEdit"><?= esc((string)($ticket['area'] ?? '')) ?: 'Select Area' ?></span>
                      </button>
                      <ul class="dropdown-menu shadow-sm border-0 w-100" aria-labelledby="areaBtnEdit" id="areaMenuEdit">
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
                </div>

                <div class="col-sm-6">
                  <div class="hq-field">
                    <label>Type</label>
                    <button type="button" class="select-pro w-100 px-3" style="height:44px; font-size:0.9rem; border-radius:10px;" id="tipoTrigger" data-bs-toggle="modal" data-bs-target="#modalFallas">
                      <span id="tipoTriggerText" class="text-truncate"><?= esc((string)($ticket['type'] ?? '')) ?: 'Select Type' ?></span>
                      <span class="chev" aria-hidden="true" style="transform: scale(0.7) rotate(45deg);"></span>
                    </button>
                    <input type="hidden" name="type" id="typeEdit" form="ticketForm" value="<?= esc((string)($ticket['type'] ?? '')) ?>">
                    <input type="hidden" name="category" id="categoryEdit" form="ticketForm" value="<?= esc((string)($ticket['category'] ?? '')) ?>">
                  </div>
                </div>

                <div class="col-12">
                  <div class="hq-field">
                    <label>Reference URL</label>
                    <div class="hq-value-box">
                      <?php if (!empty($ticketUrl)): ?>
                        <?php
                          $urlHref = $ticketUrl;
                          if (!preg_match('~^https?://~i', $urlHref)) {
                            $urlHref = 'https://' . $urlHref;
                          }
                        ?>
                        <a href="<?= esc($urlHref) ?>" target="_blank" rel="noopener noreferrer" class="hq-link">
                          <i class="fa-solid fa-link me-2 text-muted"></i><?= esc($ticketUrl) ?>
                        </a>
                      <?php else: ?>
                        <span class="text-muted"><i class="fa-solid fa-ban me-2"></i>No attached URL</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="col-12">
                  <div class="hq-field mb-0">
                    <label>Attached Evidence</label>
                    <div class="hq-evidence-box">
                      <?php if (!empty($ticketEvidence)): ?>
                        <?php
                          $evHref = localHref($ticketEvidence, $basePath);
                          $ext = strtolower(pathinfo($ticketEvidence, PATHINFO_EXTENSION));
                          $evType = ($ext === 'pdf') ? 'pdf' : 'img';
                          $evName = basename($ticketEvidence);
                        ?>
                        <div class="d-flex align-items-center gap-3">
                          <div class="hq-evidence-icon">
                            <i class="fa-solid <?= $evType === 'pdf' ? 'fa-file-pdf text-danger' : 'fa-image text-primary' ?>"></i>
                          </div>
                          <div class="flex-grow-1 overflow-hidden">
                            <div class="text-truncate fw-semibold text-dark" style="font-size: 14px;"><?= esc($evName) ?></div>
                            <div class="text-muted" style="font-size: 13px;">Preview this file</div>
                          </div>
                          <button type="button" class="hq-btn hq-btn-light hq-btn-sm" data-bs-toggle="modal" data-bs-target="#evidenceModal" data-ev-src="<?= esc($evHref) ?>" data-ev-type="<?= esc($evType) ?>" data-ev-name="<?= esc($evName) ?>">
                            <i class="fa-solid fa-eye me-2"></i>View file
                          </button>
                        </div>
                      <?php else: ?>
                        <div class="text-muted d-flex align-items-center">
                          <i class="fa-regular fa-folder-open me-2"></i> No evidence attached
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Thread Activity -->
            <div class="hq-card p-4 d-flex flex-column h-100" id="comments">
              <div class="d-flex justify-content-between align-items-center mb-4 pb-2" style="border-bottom: 1px solid var(--slate-100)">
                <h3 class="hq-section-head border-0 p-0 m-0"><i class="fa-regular fa-comments me-2 text-muted"></i> History and Notes</h3>
                <span class="badge" style="background: var(--slate-100); color: var(--slate-500); border: 1px solid var(--slate-200); font-weight: 600;">Internal only</span>
              </div>

              <div class="hq-thread flex-grow-1" id="commentThread" data-ticket-id="<?= (int)$ticketId ?>" data-last-id="<?= (int)$lastInternalId ?>">
                
                <!-- Original -->
                <div class="hq-thread-item original">
                  <div class="hq-avatar border-brand text-brand bg-brand-light">
                    <?= esc(strtoupper(substr($creatorName, 0, 1))) ?>
                  </div>
                  <div class="hq-thread-content">
                    <div class="hq-thread-meta">
                      <span class="fw-bold text-dark"><?= esc($creatorName) ?></span> <span class="text-muted">created the case</span>
                      <span class="hq-time ms-auto"><?= esc(fmtDT($ticket['created_at'] ?? '')) ?></span>
                    </div>
                    <div class="hq-thread-body mt-2">
                      <?= nl2br(esc((string)($ticket['comments'] ?? ''))) ?>
                    </div>
                  </div>
                </div>

                <!-- Internal -->
                <?php if (!empty($internalComments)): ?>
                  <?php foreach ($internalComments as $c): ?>
                    <div class="hq-thread-item">
                      <div class="hq-avatar border">
                        <?= esc(strtoupper(substr($c['author'], 0, 1))) ?>
                      </div>
                      <div class="hq-thread-content">
                        <div class="hq-thread-meta">
                          <span class="fw-bold text-dark"><?= esc($c['author']) ?></span> <span class="text-muted">added a note</span>
                          <span class="hq-time ms-auto"><?= esc(fmtDT($c['created_at'] ?? '')) ?></span>
                        </div>
                        <div class="hq-thread-body mt-2">
                          <?= nl2br(esc((string)$c['comment'])) ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <!-- Add Comment Form -->
              <div class="hq-comment-box">
                  <div class="d-flex gap-3">
                    <div class="hq-avatar text-white border-0 shadow-sm mt-1" style="background: var(--slate-800);">
                      <?= esc(strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1))) ?>
                    </div>
                    <div class="flex-grow-1">
                      <textarea name="new_comment" form="ticketForm" class="hq-textarea w-100" rows="2" placeholder="Set case updates or internal notes..." style="resize:none;"></textarea>
                      <div class="d-flex justify-content-end mt-2">
                        <button type="submit" form="ticketForm" name="post_note_btn" value="1" class="hq-btn hq-btn-primary hq-btn-sm px-4">
                          <i class="fa-solid fa-paper-plane me-2"></i> Post note
                        </button>
                      </div>
                    </div>
                  </div>
              </div>
            </div>

          </div>

          <!-- RIGHT COL: Actions -->
          <div class="col-lg-4">
            <form id="ticketForm" method="POST" class="hq-card p-4 hq-sticky-sidebar">
              <h3 class="hq-section-head mb-4"><i class="fa-solid fa-sliders me-2 text-muted"></i> Operational Management</h3>
              
              <div class="hq-field mb-4">
                <label>Assigned To</label>
                <div class="hq-select-wrapper">
                  <select name="assigned_user_id" class="hq-select">
                    <option value="0">— Unassigned —</option>
                    <?php foreach ($itUsers as $u): ?>
                      <option value="<?= (int)$u['id_user'] ?>" <?= ((int)$ticket['assigned_user_id'] === (int)$u['id_user']) ? 'selected' : '' ?>>
                        <?= esc($u['full_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <i class="fa-solid fa-chevron-down hq-select-icon"></i>
                </div>
                <div class="mt-2 text-muted" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-info me-1"></i> Active IT Support list.</div>
              </div>

              <div class="hq-field mb-4">
                <label>Priority</label>
                <div class="hq-select-wrapper">
                  <select name="priority" class="hq-select">
                    <?php 
                      $prioMap = ['Baja' => 'Low', 'Media' => 'Medium', 'Alta' => 'High', 'Urgente' => 'Urgent'];
                      foreach (['Baja','Media','Alta','Urgente'] as $p): 
                    ?>
                      <option value="<?= esc($p) ?>" <?= ($ticket['priority'] === $p) ? 'selected' : '' ?>><?= esc($prioMap[$p]) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <i class="fa-solid fa-chevron-down hq-select-icon"></i>
                </div>
              </div>

              <div class="hq-field mb-4">
                <label>Ticket Status</label>
                <div class="hq-select-wrapper">
                  <select name="status" class="hq-select hq-select-status">
                    <?php 
                      $statusMap = ['Pendiente' => 'Open', 'En Proceso' => 'In progress', 'Resuelto' => 'Resolved', 'Cerrado' => 'Closed'];
                      foreach (['Pendiente','En Proceso','Resuelto','Cerrado'] as $s): 
                    ?>
                      <option value="<?= esc($s) ?>" <?= ($ticket['status'] === $s) ? 'selected' : '' ?>><?= esc($statusMap[$s]) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <i class="fa-solid fa-chevron-down hq-select-icon"></i>
                </div>
              </div>

              <hr class="hq-divider mb-4 mt-2">

              <div class="d-flex flex-column gap-3">
                <button type="submit" class="hq-btn hq-btn-brand w-100 py-2 fs-6">
                  Save Changes
                </button>
                <a href="tickets.php" class="hq-btn hq-btn-ghost w-100 text-center py-2" style="font-size:0.9rem;">
                  Discard
                </a>
              </div>
            </form>
          </div>

        </div>

      </div>
    </main>

  </div>

  
  <!-- ====== MODAL: Evidencia ====== -->
  <div class="modal fade evidence-modal" id="evidenceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="evidenceTitle">Evidence</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <div class="modal-body">
          <div class="evidence-toolbar">
            <div class="btn-group" role="group" aria-label="Zoom">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="evZoomOut" title="Zoom Out">
                <i class="fa-solid fa-magnifying-glass-minus"></i>
              </button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="evZoomReset" title="Reset">
                <i class="fa-solid fa-rotate-left"></i>
              </button>
              <button type="button" class="btn btn-outline-secondary btn-sm" id="evZoomIn" title="Zoom In">
                <i class="fa-solid fa-magnifying-glass-plus"></i>
              </button>
            </div>

            <a class="btn btn-outline-primary btn-sm ms-auto" id="evOpenNewTab" href="#" target="_blank" rel="noopener noreferrer">
              <i class="fa-solid fa-up-right-from-square me-1"></i>Open
            </a>
          </div>

          <div class="evidence-canvas mt-3" id="evCanvas">
            <img id="evImg" alt="Evidence" />
            <iframe id="evPdf" title="Evidence PDF"></iframe>
          </div>

          <div class="evidence-hint mt-2 text-muted small">
            Tip: use the buttons to zoom (images). For PDFs you can use the browser's zoom.
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
        const name = trigger.getAttribute('data-ev-name') || 'Evidence';

        titleEl.textContent = 'Evidence: ' + name;
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


<script>
(function(){
  const thread = document.getElementById('commentThread');
  if(!thread) return;

  let lastId = parseInt(thread.dataset.lastId || "0", 10) || 0;
  const ticketId = parseInt(thread.dataset.ticketId || "0", 10) || 0;
  const urlBase = new URL(window.location.href);
  // Mantén la URL actual (id=...), solo agrega ajax params
  function buildUrl(after){
    const u = new URL(urlBase.origin + urlBase.pathname);
    // conserva ?id=...
    u.searchParams.set('id', String(ticketId));
    u.searchParams.set('ajax', 'comments');
    u.searchParams.set('after', String(after || 0));
    return u.toString();
  }

  function nearBottom(el){
    return (el.scrollHeight - el.scrollTop - el.clientHeight) < 90;
  }

  async function poll(){
    try{
      const wasBottom = nearBottom(thread);
      const res = await fetch(buildUrl(lastId), {headers: {'Accept':'application/json'}});
      if(!res.ok) return;
      const data = await res.json();
      if(!data || !data.items || !data.items.length) return;

      for(const it of data.items){
        const id = parseInt(it.id, 10) || 0;
        if(id <= lastId) continue;

        const card = document.createElement('div');
        card.style.cssText = "background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;padding:12px 14px;box-shadow:0 10px 22px rgba(0,0,0,.06);";
        card.innerHTML = `
          <div class="text-muted small d-flex justify-content-between" style="gap:10px;">
            <span><b>${escapeHtml(it.author || '')}</b></span>
            <span>${escapeHtml(it.created_at || '')}</span>
          </div>
          <div style="margin-top:6px;">${it.comment_html || ''}</div>
        `;
        thread.appendChild(card);
        lastId = id;
      }

      thread.dataset.lastId = String(lastId);
      if(wasBottom){
        thread.scrollTop = thread.scrollHeight;
      }
    }catch(e){
      // silencioso
    }
  }

  function escapeHtml(str){
    return String(str)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  // Poll cada 1s (se siente tipo chat sin sobrecargar)
  setInterval(poll, 1000);
})();
</script>

  <!-- ═══════════════════════════════════════════════════
       MODAL: Selector de Tipo de Falla (2 pasos)
  ════════════════════════════════════════════════════ -->
  <div class="modal fade" id="modalFallas" tabindex="-1" aria-labelledby="modalFallasLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">

        <!-- Header -->
        <div class="modal-header">
          <div style="display:flex;align-items:center;gap:12px;flex:1;">
            <button type="button" class="modal-back-btn" id="modalBackBtn">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
              </svg>
              Back
            </button>
            <h5 class="modal-title" id="modalFallasLabel">What is the issue?</h5>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <!-- Body -->
        <div class="modal-body p-0" id="modalBody">
          <div id="step1">
            <div class="cat-grid">
              <button type="button" class="cat-card" data-cat="Hardware" data-cat-label="Computer / PC" data-cat-ico="fa-solid fa-desktop">
                <div class="cat-card__ico"><i class="fa-solid fa-desktop"></i></div>
                <div class="cat-card__label">Computer / PC</div>
              </button>
              <button type="button" class="cat-card" data-cat="Hardware" data-cat-label="Monitor" data-cat-ico="fa-solid fa-tv">
                <div class="cat-card__ico"><i class="fa-solid fa-tv"></i></div>
                <div class="cat-card__label">Monitor</div>
              </button>
              <button type="button" class="cat-card" data-cat="Hardware" data-cat-label="Printer" data-cat-ico="fa-solid fa-print">
                <div class="cat-card__ico"><i class="fa-solid fa-print"></i></div>
                <div class="cat-card__label">Printer</div>
              </button>
              <button type="button" class="cat-card" data-cat="Network" data-cat-label="Network / Internet" data-cat-ico="fa-solid fa-wifi">
                <div class="cat-card__ico"><i class="fa-solid fa-wifi"></i></div>
                <div class="cat-card__label">Network / Internet</div>
              </button>
              <button type="button" class="cat-card" data-cat="Software" data-cat-label="App / Software" data-cat-ico="fa-solid fa-cubes">
                <div class="cat-card__ico"><i class="fa-solid fa-cubes"></i></div>
                <div class="cat-card__label">App / Software</div>
              </button>
              <button type="button" class="cat-card" data-cat="Email" data-cat-label="Email / Access" data-cat-ico="fa-solid fa-envelope-circle-check">
                <div class="cat-card__ico"><i class="fa-solid fa-envelope-circle-check"></i></div>
                <div class="cat-card__label">Email / Access</div>
              </button>
              <button type="button" class="cat-card" data-cat="Hardware" data-cat-label="Keyboard / Mouse" data-cat-ico="fa-solid fa-keyboard">
                <div class="cat-card__ico"><i class="fa-solid fa-keyboard"></i></div>
                <div class="cat-card__label">Keyboard / Mouse</div>
              </button>
              <button type="button" class="cat-card" data-cat="Hardware" data-cat-label="Phone / VoIP" data-cat-ico="fa-solid fa-phone-office">
                <div class="cat-card__ico"><i class="fa-solid fa-phone"></i></div>
                <div class="cat-card__label">Phone / RingCentral</div>
              </button>
              <button type="button" class="cat-card" data-cat="General" data-cat-label="Other" data-cat-ico="fa-solid fa-circle-question">
                <div class="cat-card__ico"><i class="fa-solid fa-circle-question"></i></div>
                <div class="cat-card__label">Other</div>
              </button>
            </div>
          </div>
          <div id="step2" hidden>
            <div class="cat-breadcrumb" id="catBreadcrumb">
              <i class="fa-solid fa-folder-open"></i>
              <span id="catBreadcrumbLabel">Category</span>
              <i class="fa-solid fa-chevron-right" style="font-size:0.6rem;opacity:0.5;"></i>
              <span>Select specific issue</span>
            </div>
            <div class="subfalla-list" id="subfallaList"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
  'use strict';
  (function(){
    const FALLAS = {
      'Computer / PC': [
        { ico: 'fa-solid fa-power-off',          label: 'Does not turn on' },
        { ico: 'fa-solid fa-gauge',              label: 'Very slow / freezing' },
        { ico: 'fa-solid fa-fire',               label: 'Overheating / turns off randomly' },
        { ico: 'fa-solid fa-volume-high',        label: 'Making strange noises' },
        { ico: 'fa-solid fa-skull-crossbones',   label: 'Blue screen / crashing' },
        { ico: 'fa-solid fa-rotate-right',       label: 'Keeps restarting' },
        { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
      ],
      'Monitor': [
        { ico: 'fa-solid fa-power-off',          label: 'Does not turn on' },
        { ico: 'fa-solid fa-bolt',               label: 'Flickering / flashing' },
        { ico: 'fa-solid fa-plug',               label: 'HDMI / VGA / cable issue' },
        { ico: 'fa-solid fa-expand',             label: 'Incorrect resolution' },
        { ico: 'fa-solid fa-eye-slash',          label: 'No display / black screen' },
        { ico: 'fa-solid fa-bars',               label: 'Lines / spots on screen' },
        { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
      ],
      'Printer': [
        { ico: 'fa-solid fa-fill-drip',          label: 'Out of ink / toner' },
        { ico: 'fa-solid fa-file-circle-xmark',  label: 'Out of paper / paper jam' },
        { ico: 'fa-solid fa-link-slash',         label: 'Not connecting (USB/Network/WiFi)' },
        { ico: 'fa-solid fa-ban',                label: 'Not printing / stuck in queue' },
        { ico: 'fa-solid fa-file-circle-exclamation', label: 'Printing cut off / bad format' },
        { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
      ],
      'Network / Internet': [
        { ico: 'fa-solid fa-wifi',               label: 'No internet connection' },
        { ico: 'fa-solid fa-gauge',              label: 'Very slow connection' },
        { ico: 'fa-solid fa-ethernet',           label: 'Network cable unplugged/damaged' },
        { ico: 'fa-solid fa-server',             label: 'Cannot access server / VPN' },
        { ico: 'fa-solid fa-globe',              label: 'Certain websites not loading' },
        { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
      ],
      'App / Software': [
        { ico: 'fa-solid fa-triangle-exclamation', label: 'Error opening application' },
        { ico: 'fa-solid fa-bug',                label: 'App crashing / closing unexpectedly' },
        { ico: 'fa-solid fa-lock',               label: 'No access / permission denied' },
        { ico: 'fa-solid fa-download',           label: 'Need software installed' },
        { ico: 'fa-solid fa-rotate',             label: 'Pending / forced update' },
        { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
      ],
      'Email / Access': [
        { ico: 'fa-solid fa-key',                label: 'Forgot password' },
        { ico: 'fa-solid fa-user-lock',          label: 'Account locked' },
        { ico: 'fa-solid fa-paper-plane',        label: 'Cannot send or receive emails' },
        { ico: 'fa-solid fa-id-badge',           label: 'Need access to new system' },
        { ico: 'fa-solid fa-shield-halved',      label: 'Suspected compromised account' },
        { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
      ],
      'Keyboard / Mouse': [
        { ico: 'fa-solid fa-keyboard',           label: 'Keys not responding' },
        { ico: 'fa-solid fa-computer-mouse',     label: 'Mouse not moving / clicking' },
        { ico: 'fa-solid fa-battery-quarter',    label: 'Battery dead (wireless)' },
        { ico: 'fa-solid fa-circle-exclamation', label: 'Device not recognized' },
        { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
      ],
      'Phone / VoIP': [
        { ico: 'fa-solid fa-phone-slash',        label: 'No dial tone / cannot call' },
        { ico: 'fa-solid fa-microphone-slash',   label: 'No audio during calls' },
        { ico: 'fa-solid fa-signal',             label: 'Disconnected from VoIP network' },
        { ico: 'fa-solid fa-power-off',          label: 'Will not turn on / frozen' },
        { ico: 'fa-solid fa-circle-question',    label: 'Other issue', other: true },
      ],
      'Other': [
        { ico: 'fa-solid fa-wrench',             label: 'Unlisted hardware failure' },
        { ico: 'fa-solid fa-comment-dots',       label: 'General request / inquiry', other: true },
      ],
    };

    const modalEl      = document.getElementById('modalFallas');
    const step1        = document.getElementById('step1');
    const step2        = document.getElementById('step2');
    const backBtn      = document.getElementById('modalBackBtn');
    const modalTitle   = document.getElementById('modalFallasLabel');
    const subfallaList = document.getElementById('subfallaList');
    const catBLabel    = document.getElementById('catBreadcrumbLabel');
    const triggerText  = document.getElementById('tipoTriggerText');
    const inputType    = document.getElementById('typeEdit');
    const inputCat     = document.getElementById('categoryEdit');

    const ticketCatDisp = document.getElementById('ticketCatDisplay');
    const ticketTypeDisp = document.getElementById('ticketTypeDisplay');

    let bsModal = null;
    if (modalEl && typeof bootstrap !== 'undefined') {
      bsModal = new bootstrap.Modal(modalEl);
      modalEl.addEventListener('show.bs.modal', showStep1);
      modalEl.addEventListener('hide.bs.modal', () => document.activeElement?.blur());
    }

    function showStep1() {
      step1.hidden = false;
      step2.hidden = true;
      backBtn.classList.remove('visible');
      modalTitle.textContent = 'What is the issue?';
    }

    function showStep2(catLabel, catIco) {
      step1.hidden = true;
      step2.hidden = false;
      backBtn.classList.add('visible');
      modalTitle.textContent = 'Select specific issue';
      catBLabel.textContent = catLabel;

      const fallas = FALLAS[catLabel] || [{ ico: 'fa-solid fa-circle-question', label: 'Other', other: true }];
      subfallaList.innerHTML = '';
      fallas.forEach(f => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'subfalla-item' + (f.other ? ' subfalla-item--other' : '');
        btn.innerHTML = `<i class="subfalla-item__ico ${f.ico}"></i> ${f.label}`;
        btn.addEventListener('click', () => selectFalla(catLabel, f.label, catIco));
        subfallaList.appendChild(btn);
      });
    }

    function selectFalla(catLabel, fallaLabel, catIco) {
      const catData = document.querySelector(`.cat-card[data-cat-label="${catLabel}"]`)?.dataset?.cat || 'General';
      if(inputType) inputType.value = fallaLabel;
      if(inputCat) inputCat.value  = catData;
      if(triggerText) triggerText.textContent = fallaLabel;
      
      // Update the main header dynamically as well to show instant feedback without reload
      if(ticketCatDisp) ticketCatDisp.textContent = catData;
      if(ticketTypeDisp) ticketTypeDisp.textContent = fallaLabel;

      if(bsModal) bsModal.hide();
    }

    document.querySelectorAll('.cat-card').forEach(card => {
      card.addEventListener('click', () => {
        showStep2(card.dataset.catLabel, card.dataset.catIco);
      });
    });
    
    if(backBtn) backBtn.addEventListener('click', showStep1);
  })();
  </script>

</body>
</html>
