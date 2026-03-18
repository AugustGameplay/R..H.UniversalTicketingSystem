<?php
/**
 * public/config/mailer.php
 * Unified mailer:
 *  - DEV: Mailpit (MAIL_DRIVER=mailpit)  -> SMTP 127.0.0.1:1025
 *  - PROD: Resend  (MAIL_DRIVER=resend)  -> SMTP smtp.resend.com
 */

$autoloadCandidates = [
  __DIR__ . '/../../../vendor/autoload.php',
  __DIR__ . '/../../vendor/autoload.php',
  __DIR__ . '/../vendor/autoload.php',
];

$autoloadFound = false;
foreach ($autoloadCandidates as $file) {
  if (file_exists($file)) {
    require_once $file;
    $autoloadFound = true;
    break;
  }
}
if (!$autoloadFound) {
  die('No se encontro vendor/autoload.php. Revisa donde esta tu carpeta vendor/.');
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function mail_driver(): string
{
  $d = strtolower(trim((string)(getenv('MAIL_DRIVER') ?: 'mailpit')));
  return $d ?: 'mailpit';
}

function mail_templates_dir(): string
{
  // public/config -> project root
  return dirname(__DIR__, 2) . '/mail_templates';
}

function load_mail_template(string $fileName): ?string
{
  $path = mail_templates_dir() . '/' . $fileName;
  if (!is_file($path)) {
    return null;
  }

  $content = file_get_contents($path);
  return ($content === false) ? null : $content;
}

function render_mail_template(string $templateHtml, array $vars): string
{
  $replace = [];
  foreach ($vars as $key => $value) {
    $replace['{{' . $key . '}}'] = (string)$value;
  }
  return strtr($templateHtml, $replace);
}

function e_mail(?string $value): string
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function merida_datetime_obj(?string $dateTime = null): DateTime
{
  $tz = new DateTimeZone('America/Merida');

  try {
    $raw = trim((string)$dateTime);
    if ($raw === '') {
      return new DateTime('now', $tz);
    } else {
      // Si viene sin zona horaria (caso comun de DATETIME en MySQL),
      // se interpreta DIRECTO en Merida para evitar desfase.
      $hasTimezoneInfo = (bool)preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/i', $raw);
      if ($hasTimezoneInfo) {
        $dt = new DateTime($raw);
      } else {
        $dt = null;
        $formats = [
          'Y-m-d H:i:s.u',
          'Y-m-d H:i:s',
          'Y-m-d\TH:i:s.u',
          'Y-m-d\TH:i:s',
          'Y-m-d H:i',
        ];

        foreach ($formats as $fmt) {
          $tmp = DateTime::createFromFormat($fmt, $raw, $tz);
          if ($tmp instanceof DateTime) {
            $dt = $tmp;
            break;
          }
        }

        if (!$dt) {
          $dt = new DateTime($raw, $tz);
        }
      }
    }
  } catch (\Throwable $e) {
    $dt = new DateTime('now', $tz);
  }

  $dt->setTimezone($tz);
  return $dt;
}

function merida_time_12h(?string $dateTime = null): string
{
  return merida_datetime_obj($dateTime)->format('h:i A');
}

function merida_datetime_12h(?string $dateTime = null): string
{
  return merida_datetime_obj($dateTime)->format('d/m/Y h:i A');
}

function first_initial(string $name): string
{
  $clean = trim($name);
  if ($clean === '') return 'A';
  if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
    return mb_strtoupper(mb_substr($clean, 0, 1, 'UTF-8'), 'UTF-8');
  }
  return strtoupper(substr($clean, 0, 1));
}

function merida_year(): string
{
  $dt = new DateTime('now', new DateTimeZone('America/Merida'));
  return $dt->format('Y');
}

function my_tickets_url(): string
{
  $appUrl = trim((string)(getenv('APP_URL') ?: ''));
  if ($appUrl !== '') {
    return rtrim($appUrl, '/') . '/mis_tickets.php';
  }

  if (!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['SCRIPT_NAME'])) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $basePath = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/mis_tickets.php';
  }

  return 'http://localhost/ticketsystem/R..H.UniversalTicketingSystem/public/mis_tickets.php';
}

/**
 * Generic HTML send with configured driver.
 */
function send_mail(string $toEmail, string $toName, string $subject, string $html): bool
{
  $driver = mail_driver();
  $mail = new PHPMailer(true);

  try {
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();

    if ($driver === 'resend') {
      $apiKey = getenv('RESEND_API_KEY');
      if (!$apiKey) {
        throw new Exception('Falta RESEND_API_KEY.');
      }

      $mail->Host       = 'smtp.resend.com';
      $mail->Port       = 587;
      $mail->SMTPAuth   = true;
      $mail->Username   = 'resend';
      $mail->Password   = $apiKey;
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

      $fromEmail = getenv('MAIL_FROM') ?: 'onboarding@resend.dev';
      $fromName  = getenv('MAIL_FROM_NAME') ?: 'RH&R Ticketing';
    } else {
      $mailpitHost = getenv('MAILPIT_HOST') ?: '127.0.0.1';
      $mailpitPort = (int)(getenv('MAILPIT_PORT') ?: 1025);

      $mail->Host         = $mailpitHost;
      $mail->Port         = $mailpitPort;
      $mail->SMTPAuth     = false;
      $mail->SMTPSecure   = false;
      $mail->SMTPAutoTLS  = false;

      $fromEmail = getenv('MAIL_FROM') ?: 'no-reply@support.local';
      $fromName  = getenv('MAIL_FROM_NAME') ?: 'R.H. Universal Tickets (Local)';
    }

    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($toEmail, $toName);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->AltBody = strip_tags($html);

    $mail->send();
    return true;
  } catch (\Throwable $e) {
    error_log('[MAIL] Error (' . $driver . '): ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
    return false;
  }
}

/**
 * Ticket created notification.
 * Expected keys: id, titulo, descripcion, area, prioridad, creado_por, url, status, category, type.
 */
function notify_ticket_created(array $ticket, ?string $toEmail = null, string $toName = 'Admin'): bool
{
  $toEmail = $toEmail ?: (getenv('TICKETS_NOTIFY_EMAIL') ?: 'test@local.test');

  $id       = $ticket['id'] ?? 'N/A';
  $titulo   = $ticket['titulo'] ?? 'Ticket';
  $desc     = $ticket['descripcion'] ?? '';
  $area     = $ticket['area'] ?? 'N/A';
  $prio     = trim((string)($ticket['prioridad'] ?? 'N/A'));
  $prioMap  = ['baja' => 'Low', 'media' => 'Medium', 'alta' => 'High'];
  $prio     = $prioMap[strtolower($prio)] ?? ($prio === 'N/A' ? 'N/A' : ucfirst(strtolower($prio)));
  $creado   = $ticket['creado_por'] ?? 'N/A';
  $url      = $ticket['url'] ?? '';
  $status   = $ticket['status'] ?? '';
  $category = $ticket['category'] ?? '';
  $type     = $ticket['type'] ?? '';
  $createdAt = isset($ticket['created_at']) ? (string)$ticket['created_at'] : null;

  $subject = "New ticket #{$id} - {$titulo}";

  $templateVars = [
    'nombre_usuario' => e_mail((string)$creado),
    'numero_ticket' => e_mail((string)$id),
    'asunto_ticket' => e_mail((string)$titulo),
    'categoria' => e_mail((string)($category ?: 'General')),
    'prioridad' => e_mail((string)$prio),
    'fecha_apertura' => e_mail(merida_time_12h($createdAt)),
    'agente_asignado' => e_mail((string)(getenv('DEFAULT_ASSIGNEE_NAME') ?: 'IT Service Desk')),
    'descripcion_ticket' => nl2br(e_mail((string)$desc)),
    'url_ticket' => e_mail(my_tickets_url()),
    'email_soporte' => e_mail((string)(getenv('SUPPORT_EMAIL') ?: (getenv('MAIL_FROM') ?: 'soporte@local.test'))),
    'telefono_soporte' => e_mail((string)(getenv('SUPPORT_PHONE') ?: 'N/A')),
    'año' => merida_year(),
  ];

  $templateHtml = load_mail_template('ticket-created.html');

  if ($templateHtml !== null) {
    $html = render_mail_template($templateHtml, $templateVars);
  } else {
    $html = "
      <h2>A new ticket was opened</h2>
      <p><b>ID:</b> " . e_mail((string)$id) . "</p>
      <p><b>Title:</b> " . e_mail((string)$titulo) . "</p>
      " . ($category ? "<p><b>Category:</b> " . e_mail((string)$category) . "</p>" : "") . "
      " . ($type ? "<p><b>Type:</b> " . e_mail((string)$type) . "</p>" : "") . "
      <p><b>Department/Area:</b> " . e_mail((string)$area) . "</p>
      <p><b>Priority:</b> " . e_mail((string)$prio) . "</p>
      " . ($status ? "<p><b>Status:</b> " . e_mail((string)$status) . "</p>" : "") . "
      <p><b>Created by:</b> " . e_mail((string)$creado) . "</p>
      <p><b>Description:</b><br>" . nl2br(e_mail((string)$desc)) . "</p>
      " . ($url ? "<p><b>URL:</b> <a href='" . e_mail((string)$url) . "'>" . e_mail((string)$url) . "</a></p>" : "") . "
    ";
  }

  return send_mail($toEmail, $toName, $subject, $html);
}

/**
 * Ticket closed notification.
 * Expected keys include:
 * id, titulo, category, created_at, closed_at, created_by, resolved_by, resolution_description.
 */
function notify_ticket_closed(array $ticket, ?string $toEmail = null, string $toName = 'Usuario'): bool
{
  $toEmail = $toEmail ?: (getenv('TICKETS_NOTIFY_EMAIL') ?: 'test@local.test');

  $id = $ticket['id'] ?? 'N/A';
  $titulo = $ticket['titulo'] ?? 'Ticket';
  $category = $ticket['category'] ?? 'General';
  $createdBy = $ticket['created_by'] ?? $ticket['creado_por'] ?? 'User';
  $resolvedBy = $ticket['resolved_by'] ?? 'IT Service Desk';
  $resolutionDescription = $ticket['resolution_description'] ?? 'No resolution details.';
  $createdAt = isset($ticket['created_at']) ? (string)$ticket['created_at'] : null;
  $closedAt = isset($ticket['closed_at']) ? (string)$ticket['closed_at'] : null;
  $resolutionTime = $ticket['resolution_time'] ?? 'N/A';
  $interactions = $ticket['interactions_count'] ?? '1';
  $priority = trim((string)($ticket['prioridad'] ?? 'Media'));
  $prioMap  = ['baja' => 'Low', 'media' => 'Medium', 'alta' => 'High'];
  $priority = $prioMap[strtolower($priority)] ?? ucfirst(strtolower($priority));
  $surveyUrl = $ticket['survey_url'] ?? '#';
  $reopenUrl = $ticket['reopen_url'] ?? my_tickets_url();
  $reopenDays = $ticket['reopen_days'] ?? '7';

  $subject = "Ticket resolved #{$id} - {$titulo}";

  $templateVars = [
    'nombre_usuario' => e_mail((string)$createdBy),
    'numero_ticket' => e_mail((string)$id),
    'asunto_ticket' => e_mail((string)$titulo),
    'categoria' => e_mail((string)$category),
    'fecha_apertura' => e_mail(merida_time_12h($createdAt)),
    'fecha_cierre' => e_mail(merida_time_12h($closedAt)),
    'agente_asignado' => e_mail((string)$resolvedBy),
    'descripcion_resolucion' => nl2br(e_mail((string)$resolutionDescription)),
    'tiempo_resolucion' => e_mail((string)$resolutionTime),
    'numero_interacciones' => e_mail((string)$interactions),
    'prioridad' => e_mail((string)$priority),
    'url_encuesta' => e_mail((string)$surveyUrl),
    'url_reabrir_ticket' => e_mail((string)$reopenUrl),
    'dias_reabrir' => e_mail((string)$reopenDays),
    'email_soporte' => e_mail((string)(getenv('SUPPORT_EMAIL') ?: (getenv('MAIL_FROM') ?: 'soporte@local.test'))),
    'telefono_soporte' => e_mail((string)(getenv('SUPPORT_PHONE') ?: 'N/A')),
    'año' => merida_year(),
  ];

  $templateHtml = load_mail_template('ticket-closed.html');

  if ($templateHtml !== null) {
    $html = render_mail_template($templateHtml, $templateVars);
  } else {
    $html = "
      <h2>Your ticket was resolved</h2>
      <p><b>ID:</b> " . e_mail((string)$id) . "</p>
      <p><b>Subject:</b> " . e_mail((string)$titulo) . "</p>
      <p><b>Opening time (Merida):</b> " . e_mail(merida_time_12h($createdAt)) . "</p>
      <p><b>Closing time (Merida):</b> " . e_mail(merida_time_12h($closedAt)) . "</p>
      <p><b>Resolution:</b><br>" . nl2br(e_mail((string)$resolutionDescription)) . "</p>
    ";
  }

  return send_mail($toEmail, $toName, $subject, $html);
}

/**
 * Ticket assigned notification.
 * Expected keys:
 * id, type, area, category, created_at, assigned_at, created_by, assigned_to, assigned_role, assigned_phone, url_ticket.
 */
function notify_ticket_assigned(array $ticket, ?string $toEmail = null, string $toName = 'Usuario'): bool
{
  $toEmail = $toEmail ?: (getenv('TICKETS_NOTIFY_EMAIL') ?: 'test@local.test');

  $id = $ticket['id'] ?? 'N/A';
  $type = (string)($ticket['type'] ?? $ticket['tipo_ticket'] ?? 'Ticket');
  $area = (string)($ticket['area'] ?? 'N/A');
  $category = (string)($ticket['category'] ?? $ticket['categoria'] ?? 'General');
  $title = (string)($ticket['asunto_ticket'] ?? trim($type . ' | ' . $area));
  $createdAt = isset($ticket['created_at']) ? (string)$ticket['created_at'] : null;
  $assignedAt = isset($ticket['assigned_at']) ? (string)$ticket['assigned_at'] : null;
  $createdBy = (string)($ticket['created_by'] ?? $ticket['creado_por'] ?? 'User');
  $assignedTo = (string)($ticket['assigned_to'] ?? 'IT Agent');
  $assignedRole = (string)($ticket['assigned_role'] ?? 'IT Support');
  $assignedPhone = (string)($ticket['assigned_phone'] ?? (getenv('SUPPORT_PHONE') ?: 'N/A'));
  $ticketUrl = (string)($ticket['url_ticket'] ?? $ticket['url'] ?? my_tickets_url());

  $subject = "Ticket assigned #{$id} - {$title}";

  $templateVars = [
    'nombre_usuario' => e_mail($createdBy),
    'numero_ticket' => e_mail((string)$id),
    'asunto_ticket' => e_mail($title),
    'categoria' => e_mail($category),
    'fecha_apertura' => e_mail(merida_time_12h($createdAt)),
    'tipo_ticket' => e_mail($type),
    'area' => e_mail($area),
    'fecha_asignacion' => e_mail(merida_datetime_12h($assignedAt)),
    'inicial_agente' => e_mail(first_initial($assignedTo)),
    'nombre_agente' => e_mail($assignedTo),
    'puesto_agente' => e_mail($assignedRole),
    'telefono_agente' => e_mail($assignedPhone),
    'url_ticket' => e_mail($ticketUrl),
    'año' => merida_year(),
    // Compat por archivos guardados con encoding roto
    'aÃ±o' => merida_year(),
  ];

  $templateHtml = load_mail_template('ticket-assigned.html');

  if ($templateHtml !== null) {
    $html = render_mail_template($templateHtml, $templateVars);
  } else {
    $html = "
      <h2>Your ticket was assigned</h2>
      <p><b>Ticket:</b> #" . e_mail((string)$id) . "</p>
      <p><b>Subject:</b> " . e_mail($type) . " - " . e_mail($area) . "</p>
      <p><b>Assigned to:</b> " . e_mail($assignedTo) . " (" . e_mail($assignedRole) . ")</p>
      <p><b>Assignment date:</b> " . e_mail(merida_datetime_12h($assignedAt)) . "</p>
      <p><b>Phone:</b> " . e_mail($assignedPhone) . "</p>
    ";
  }

  return send_mail($toEmail, $toName, $subject, $html);
}
