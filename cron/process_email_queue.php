<?php
/**
 * cron/process_email_queue.php
 * Process queued emails — run via cron every 30-60 seconds:
 *   * * * * * php /path/to/cron/process_email_queue.php >> /path/to/logs/email_cron.log 2>&1
 *
 * Can also be run manually: php cron/process_email_queue.php
 */

// Limit: max emails to process per run
$BATCH_SIZE = 10;

// Lock file to prevent overlapping runs
$lockFile = sys_get_temp_dir() . '/rhr_email_queue.lock';
$lockFp = fopen($lockFile, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
  echo date('Y-m-d H:i:s') . " [SKIP] Another instance is already running.\n";
  exit(0);
}

require __DIR__ . '/../public/config/db.php';
require_once __DIR__ . '/../public/config/mailer.php';

// Check if table exists
try {
  $pdo->query("SELECT 1 FROM email_queue LIMIT 1");
} catch (Throwable $e) {
  echo date('Y-m-d H:i:s') . " [ERROR] email_queue table not found. Run migrate.php first.\n";
  flock($lockFp, LOCK_UN);
  fclose($lockFp);
  exit(1);
}

// Fetch pending emails
$stmt = $pdo->prepare("
  SELECT id, to_email, to_name, subject, body, bcc_json, attempts, max_attempts
  FROM email_queue
  WHERE status IN ('pending', 'failed')
    AND attempts < max_attempts
  ORDER BY created_at ASC
  LIMIT :limit
");
$stmt->bindValue(':limit', $BATCH_SIZE, PDO::PARAM_INT);
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($jobs)) {
  // Nothing to do — silent exit (no log spam)
  flock($lockFp, LOCK_UN);
  fclose($lockFp);
  exit(0);
}

echo date('Y-m-d H:i:s') . " [START] Processing " . count($jobs) . " email(s)...\n";

$stmtUpdate = $pdo->prepare("
  UPDATE email_queue
  SET status = :status, attempts = attempts + 1, last_error = :error, processed_at = NOW()
  WHERE id = :id
");

$sent = 0;
$failed = 0;

foreach ($jobs as $job) {
  $id = (int)$job['id'];

  // Mark as processing
  $pdo->prepare("UPDATE email_queue SET status = 'processing' WHERE id = :id")
      ->execute([':id' => $id]);

  // Parse BCC
  $bccEmails = [];
  if (!empty($job['bcc_json'])) {
    $decoded = json_decode($job['bcc_json'], true);
    if (is_array($decoded)) {
      $bccEmails = $decoded;
    }
  }

  // Send via PHPMailer (direct send, not queue again)
  try {
    $result = send_mail_now(
      $job['to_email'],
      $job['to_name'],
      $job['subject'],
      $job['body'],
      $bccEmails
    );

    if ($result) {
      $stmtUpdate->execute([
        ':status' => 'sent',
        ':error' => null,
        ':id' => $id,
      ]);
      $sent++;
      echo "  [OK] #{$id} -> {$job['to_email']}\n";
    } else {
      throw new Exception('send_mail_now returned false');
    }
  } catch (Throwable $e) {
    $errMsg = $e->getMessage();
    $newAttempts = (int)$job['attempts'] + 1;
    $newStatus = ($newAttempts >= (int)$job['max_attempts']) ? 'failed' : 'pending';

    $stmtUpdate->execute([
      ':status' => $newStatus,
      ':error' => $errMsg,
      ':id' => $id,
    ]);
    $failed++;
    echo "  [FAIL] #{$id} -> {$job['to_email']}: {$errMsg}\n";
  }
}

echo date('Y-m-d H:i:s') . " [DONE] Sent: {$sent}, Failed: {$failed}\n";

flock($lockFp, LOCK_UN);
fclose($lockFp);
