-- 003_custom_fields.sql : champs personnalisés par entité
CREATE TABLE IF NOT EXISTS `custom_field_defs` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `entity_type` VARCHAR(20)  NOT NULL,                 -- asset|employee|client|license
  `field_key`   VARCHAR(50)  NOT NULL,                 -- slug unique par entité
  `label`       VARCHAR(100) NOT NULL,
  `field_type`  VARCHAR(20)  NOT NULL DEFAULT 'text',  -- text|number|date|select|checkbox|textarea
  `options`     TEXT         DEFAULT NULL,             -- JSON (liste déroulante)
  `required`    TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  `active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_entity_key` (`entity_type`, `field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `custom_field_values` (
  `id`        BIGINT AUTO_INCREMENT PRIMARY KEY,
  `field_id`  INT    NOT NULL,
  `entity_id` INT    NOT NULL,
  `value`     TEXT   DEFAULT NULL,
  UNIQUE KEY `uq_field_entity` (`field_id`, `entity_id`),
  INDEX `idx_entity` (`entity_id`),
  CONSTRAINT `fk_cfv_def` FOREIGN KEY (`field_id`) REFERENCES `custom_field_defs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
