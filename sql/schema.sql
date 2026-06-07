-- InventorFlow — IT Asset Inventory Management
-- Schema v1.0

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `inventorflow` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `inventorflow`;

CREATE TABLE IF NOT EXISTS `clients` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `contact_email` VARCHAR(100),
  `contact_name` VARCHAR(100),
  `phone` VARCHAR(30),
  `address` TEXT,
  `active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT DEFAULT NULL,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100),
  `email` VARCHAR(100),
  `role` ENUM('superadmin','admin','viewer') DEFAULT 'admin',
  `active` TINYINT(1) DEFAULT 1,
  `last_login` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `departments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `employees` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT NOT NULL,
  `first_name` VARCHAR(50) NOT NULL,
  `last_name` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100),
  `phone` VARCHAR(30),
  `department_id` INT DEFAULT NULL,
  `position` VARCHAR(100),
  `active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `naming_conventions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT NOT NULL,
  `os_type` ENUM('MAC','WIN','LIN','ALL') DEFAULT 'ALL',
  `template` VARCHAR(100) NOT NULL DEFAULT '{CLIENT}-{OS}-{SEQ:3}',
  `current_seq_mac` INT DEFAULT 0,
  `current_seq_win` INT DEFAULT 0,
  `current_seq_lin` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `client_os` (`client_id`, `os_type`),
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `assets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT NOT NULL,
  `hostname` VARCHAR(100) NOT NULL,
  `asset_tag` VARCHAR(50),
  `serial_number` VARCHAR(100),
  `os_type` ENUM('MAC','WIN','LIN') NOT NULL,
  `os_version` VARCHAR(50),
  `brand` VARCHAR(100),
  `model` VARCHAR(100),
  `cpu` VARCHAR(100),
  `ram_gb` INT,
  `storage_gb` INT,
  `storage_type` ENUM('HDD','SSD','NVMe'),
  `ip_address` VARCHAR(45),
  `mac_address` VARCHAR(17),
  `location` VARCHAR(150),
  `purchase_date` DATE DEFAULT NULL,
  `warranty_until` DATE DEFAULT NULL,
  `status` ENUM('active','stock','repair','retired') DEFAULT 'stock',
  `assigned_to` INT DEFAULT NULL,
  `department_id` INT DEFAULT NULL,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `employees`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `asset_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `asset_id` INT NOT NULL,
  `action` ENUM('created','assigned','unassigned','status_change','updated','deleted') NOT NULL,
  `from_value` VARCHAR(255),
  `to_value` VARCHAR(255),
  `notes` TEXT,
  `performed_by` INT DEFAULT NULL,
  `performed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Default super admin (password: admin123)
INSERT INTO `users` (`client_id`, `username`, `password`, `full_name`, `email`, `role`) VALUES
(NULL, 'admin', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Super Admin', 'admin@inventorflow.local', 'superadmin');

-- Demo client
INSERT INTO `clients` (`name`, `code`, `contact_email`, `contact_name`) VALUES
('ACME Corp (démo)', 'ACME', 'it@acme.example', 'IT Admin');

INSERT INTO `departments` (`client_id`, `name`, `code`) VALUES
(1, 'Informatique', 'IT'),
(1, 'Ressources Humaines', 'RH'),
(1, 'Direction', 'DIR'),
(1, 'Marketing', 'MKT'),
(1, 'Comptabilité', 'CPT');

INSERT INTO `naming_conventions` (`client_id`, `os_type`, `template`) VALUES
(1, 'MAC', '{CLIENT}-MAC-{DEPT}-{SEQ:3}'),
(1, 'WIN', '{CLIENT}-WIN-{DEPT}-{SEQ:3}'),
(1, 'LIN', '{CLIENT}-LIN-{DEPT}-{SEQ:3}');

SET FOREIGN_KEY_CHECKS = 1;
