<?php
/**
 * api/process_queue.php
 * Lightweight web endpoint to process the email queue.
 * Called via AJAX after page renders so user sees instant response.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Minimal auth: only process if called from same site
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden']);
  exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

$BATCH = 10;
$sent = 0;
$failed = 0;

try {
  $stmt = $pdo->prepare("
    SELECT id, to_email, to_name, subject, body, bcc_json, attempts, max_attempts
    FROM email_queue
    WHERE status IN ('pending','failed') AND attempts < max_attempts
    ORDER BY created_at ASC
    LIMIT :limit
  ");
  $stmt->bindValue(':limit', $BATCH, PDO::PARAM_INT);
  $stmt->execute();
  $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (empty($jobs)) {
    echo json_encode(['ok' => true, 'sent' => 0, 'pending' => 0]);
    exit;
  }

  $stmtUp = $pdo->prepare("
    UPDATE email_queue
    SET status = :status, attempts = attempts + 1, last_error = :error, processed_at = NOW()
    WHERE id = :id
  ");

  foreach ($jobs as $job) {
    $id = (int)$job['id'];
    $pdo->prepare("UPDATE email_queue SET status='processing' WHERE id=:id")->execute([':id' => $id]);

    $bcc = [];
    if (!empty($job['bcc_json'])) {
      $decoded = json_decode($job['bcc_json'], true);
      if (is_array($decoded)) $bcc = $decoded;
    }

    try {
      $ok = send_mail_now($job['to_email'], $job['to_name'], $job['subject'], $job['body'], $bcc);
      if ($ok) {
        $stmtUp->execute([':status' => 'sent', ':error' => null, ':id' => $id]);
        $sent++;
      } else {
        throw new Exception('send_mail_now returned false');
      }
    } catch (Throwable $e) {
      $newAttempts = (int)$job['attempts'] + 1;
      $newStatus = ($newAttempts >= (int)$job['max_attempts']) ? 'failed' : 'pending';
      $stmtUp->execute([':status' => $newStatus, ':error' => $e->getMessage(), ':id' => $id]);
      $failed++;
    }
  }
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}

echo json_encode(['ok' => true, 'sent' => $sent, 'failed' => $failed]);
