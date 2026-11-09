-- Disposable local DB ONLY. Not an XServer migration or deployment script.
CREATE TABLE staff_login_windows (
 key_hash CHAR(64) PRIMARY KEY,
 failures INT UNSIGNED NOT NULL DEFAULT 0,
 window_started BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE notification_outbox MODIFY status ENUM('pending','processing','sent','failed','simulated') NOT NULL DEFAULT 'pending';
CREATE TABLE dev_notification_deliveries (
 outbox_id BIGINT UNSIGNED PRIMARY KEY,
 payload TEXT NOT NULL,
 simulated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (outbox_id) REFERENCES notification_outbox(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE inquiry_audit_events MODIFY event_type ENUM('status_change','assignment_change') NOT NULL;
CREATE TABLE dev_staff_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_id BIGINT UNSIGNED NOT NULL,
 target_id BIGINT UNSIGNED NOT NULL,
 old_role VARCHAR(16) NOT NULL,
 new_role VARCHAR(16) NOT NULL,
 new_active TINYINT(1) NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
