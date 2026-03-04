<?php
/**
 * public/config/mailer_mailpit.php
 * Envío LOCAL con Mailpit (DEV) usando PHPMailer.
 *
 * Mailpit (default):
 *  - SMTP: 127.0.0.1:1025
 *  - UI:   http://localhost:8025
 */

// ====== Autoload (auto-detect para tu estructura) ======
$autoloadCandidates = [
  __DIR__ . '/../../vendor/autoload.php',   // si vendor está en /public/../../vendor (ticketsystem/vendor)
  __DIR__ . '/../vendor/autoload.php',      // si vendor estuviera dentro de /public/vendor (menos común)
  __DIR__ . '/../../../vendor/autoload.php' // si vendor está aún más arriba
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
  // Para debug rápido
  die("No se encontró vendor/autoload.php. Revisa dónde está tu carpeta vendor/.");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Enviar correo con Mailpit local (DEV).
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $subject
 * @param string $html
 * @return bool
 */
function sendMailLocalMailpit(string $toEmail, string $toName, string $subject, string $html): bool
{
  $mail = new PHPMailer(true);

  try {
    // ====== Mailpit SMTP local ======
    $mail->isSMTP();
    $mail->Host = '127.0.0.1';
    $mail->Port = 1025;

    // Mailpit no usa auth ni TLS por default
    $mail->SMTPAuth = false;
    $mail->SMTPSecure = false;

    // Evita que PHPMailer intente STARTTLS automáticamente
    $mail->SMTPAutoTLS = false;

    // Opcional: debug (descomenta si quieres ver logs SMTP)
    // $mail->SMTPDebug = 2;
    // $mail->Debugoutput = 'error_log';

    $mail->CharSet = 'UTF-8';
    $mail->setFrom('no-reply@support.local', 'R.H. Universal Tickets (Local)');
    $mail->addAddress($toEmail, $toName);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $html;
    $mail->AltBody = strip_tags($html);

    $mail->send();
    return true;

  } catch (Exception $e) {
    error_log("Mailpit error: " . $mail->ErrorInfo);
    return false;
  }
}

/**
 * Helper específico: notificación cuando se crea un ticket.
 *
 * @param array $ticket (id, titulo, descripcion, area, prioridad, creado_por, url opcional)
 * @param string $toEmail correo destino (si no se pasa, usa el del env o uno default)
 * @param string $toName nombre destino
 * @return bool
 */
function mailpit_send_ticket_created(array $ticket, string $toEmail = '', string $toName = 'Admin'): bool
{
  // destino: si no pasas, intenta env; si no, default
  if ($toEmail === '') {
    $toEmail = getenv('TICKETS_NOTIFY_EMAIL') ?: 'admin@local.test';
  }

  $id       = $ticket['id'] ?? 'N/A';
  $titulo   = $ticket['titulo'] ?? 'Sin título';
  $desc     = $ticket['descripcion'] ?? '';
  $area     = $ticket['area'] ?? 'N/A';
  $prio     = $ticket['prioridad'] ?? 'N/A';
  $creado   = $ticket['creado_por'] ?? 'N/A';
  $url      = $ticket['url'] ?? '';

  $subject = "Nuevo ticket (LOCAL) #{$id} - {$titulo}";

  $html = "
    <h2>Se abrió un nuevo ticket (LOCAL)</h2>
    <p><b>ID:</b> " . htmlspecialchars((string)$id) . "</p>
    <p><b>Título:</b> " . htmlspecialchars((string)$titulo) . "</p>
    <p><b>Área:</b> " . htmlspecialchars((string)$area) . "</p>
    <p><b>Prioridad:</b> " . htmlspecialchars((string)$prio) . "</p>
    <p><b>Creado por:</b> " . htmlspecialchars((string)$creado) . "</p>
    <p><b>Descripción:</b><br>" . nl2br(htmlspecialchars((string)$desc)) . "</p>
    " . ($url ? "<p><b>URL:</b> <a href='" . htmlspecialchars((string)$url) . "'>" . htmlspecialchars((string)$url) . "</a></p>" : "") . "
  ";

  return sendMailLocalMailpit($toEmail, $toName, $subject, $html);
}