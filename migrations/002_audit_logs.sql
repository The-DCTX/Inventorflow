-- 002_audit_logs.sql : journal d'audit (qui a fait quoi)
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT          DEFAULT NULL,
  `username`    VARCHAR(50)  DEFAULT NULL,
  `action`      VARCHAR(60)  NOT NULL,
  `entity_type` VARCHAR(40)  DEFAULT NULL,
  `entity_id`   INT          DEFAULT NULL,
  `details`     TEXT         DEFAULT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX (`created_at`),
  INDEX (`user_id`),
  INDEX (`action`),
  INDEX (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
