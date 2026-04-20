<?php
/**
 * migrate.php — One-time migration script
 * Run manually: php public/config/migrate.php
 * 
 * Consolidates all DDL so runtime code never needs to check/create tables.
 */

require __DIR__ . '/db.php';

$migrations = [];

// 1) email_queue
$migrations[] = [
  'name' => 'email_queue table',
  'sql' => file_get_contents(__DIR__ . '/email_queue.sql'),
];

// 2) ticket_modifications
$migrations[] = [
  'name' => 'ticket_modifications table',
  'sql' => "
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
  ",
];

// 3) ticket_comments
$migrations[] = [
  'name' => 'ticket_comments table',
  'sql' => "
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
  ",
];

// 4) closed_at column on tickets
$migrations[] = [
  'name' => 'tickets.closed_at column',
  'sql' => null, // handled below
  'callback' => function (PDO $pdo) {
    $st = $pdo->prepare("SHOW COLUMNS FROM tickets LIKE 'closed_at'");
    $st->execute();
    if (!$st->fetch()) {
      $pdo->exec("ALTER TABLE tickets ADD COLUMN closed_at DATETIME NULL");
      echo "  -> Added closed_at column\n";
    } else {
      echo "  -> Already exists\n";
    }
  },
];

// 5) Indexes on tickets table
$indexDefs = [
  'idx_tickets_status'      => 'tickets(status)',
  'idx_tickets_priority'    => 'tickets(priority)',
  'idx_tickets_area'        => 'tickets(area)',
  'idx_tickets_assigned'    => 'tickets(assigned_user_id)',
  'idx_tickets_id_user'     => 'tickets(id_user)',
  'idx_tickets_created_at'  => 'tickets(created_at)',
];

foreach ($indexDefs as $idxName => $idxDef) {
  $migrations[] = [
    'name' => "Index $idxName on $idxDef",
    'sql' => null,
    'callback' => function (PDO $pdo) use ($idxName, $idxDef) {
      try {
        $pdo->exec("CREATE INDEX $idxName ON $idxDef");
        echo "  -> Created\n";
      } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate') !== false) {
          echo "  -> Already exists\n";
        } else {
          echo "  -> Skipped: " . $e->getMessage() . "\n";
        }
      }
    },
  ];
}

// 6) Trigger closed_at
$migrations[] = [
  'name' => 'tickets_set_closed_at trigger',
  'sql' => null,
  'callback' => function (PDO $pdo) {
    $trgName = 'tickets_set_closed_at';
    $exists = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :t");
    $exists->execute([':t'=>$trgName]);
    if ((int)$exists->fetchColumn() === 0) {
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
      echo "  -> Created trigger\n";
    } else {
      echo "  -> Already exists\n";
    }
  }
];

// Execute all migrations
echo "=== Running Migrations ===\n\n";

foreach ($migrations as $i => $m) {
  $n = $i + 1;
  echo "[{$n}] {$m['name']}...\n";

  try {
    if (!empty($m['callback'])) {
      $m['callback']($pdo);
    } elseif (!empty($m['sql'])) {
      $pdo->exec($m['sql']);
      echo "  -> OK\n";
    }
  } catch (Throwable $e) {
    echo "  -> ERROR: " . $e->getMessage() . "\n";
  }
}

echo "\n=== Done ===\n";
