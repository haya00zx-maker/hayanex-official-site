-- HAYANEX inquiry system development schema (not applied automatically)
-- MySQL 8 / MariaDB InnoDB. Separate development DB. No personal data in repo.
CREATE TABLE inquiry_sequences (
  received_day DATE PRIMARY KEY,
  last_number INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE inquiries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_no VARCHAR(40) NOT NULL UNIQUE,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  inquiry_type VARCHAR(40) NOT NULL,
  client_type VARCHAR(40) NOT NULL,
  person_name VARCHAR(50) NOT NULL,
  organization_name VARCHAR(120),
  email VARCHAR(254) NOT NULL,
  phone VARCHAR(20),
  service VARCHAR(50) NOT NULL,
  message TEXT NOT NULL,
  budget VARCHAR(60),
  desired_deadline VARCHAR(60),
  status ENUM('new','reviewing','in_progress','completed','on_hold') NOT NULL DEFAULT 'new',
  consent_at DATETIME(6) NOT NULL,
  INDEX idx_status_received (status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE inquiry_requests (
  request_key CHAR(64) PRIMARY KEY,
  payload_hash CHAR(64) NOT NULL,
  inquiry_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (inquiry_id) REFERENCES inquiries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE notification_outbox (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inquiry_id BIGINT UNSIGNED NOT NULL,
  notification_type ENUM('customer_confirmation','staff_alert') NOT NULL,
  status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY unique_notification (inquiry_id, notification_type),
  FOREIGN KEY (inquiry_id) REFERENCES inquiries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fixed-window abuse protection; periodically remove expired rows from an authorized maintenance job.
CREATE TABLE inquiry_rate_limits (
  key_hash CHAR(64) NOT NULL,
  window_start BIGINT UNSIGNED NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (key_hash,window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
