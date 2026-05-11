-- Email Queue table for async email processing
CREATE TABLE IF NOT EXISTS email_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  to_email VARCHAR(255) NOT NULL,
  to_name VARCHAR(255) NOT NULL DEFAULT '',
  subject VARCHAR(500) NOT NULL,
  body LONGTEXT NOT NULL,
  bcc_json TEXT NULL COMMENT 'JSON array of {email,name} objects',
  status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  INDEX idx_status (status),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
