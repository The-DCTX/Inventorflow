-- ============================================================
-- InventorFlow — Script d'installation — Aucune donnée personnelle
-- ============================================================
-- 1. CREATE DATABASE inventorflow CHARACTER SET utf8mb4;
-- 2. CREATE USER 'inventorflow'@'localhost' IDENTIFIED BY 'MDP';
-- 3. GRANT ALL PRIVILEGES ON inventorflow.* TO 'inventorflow'@'localhost';
-- 4. mysql -u inventorflow -p inventorflow < install.sql
-- 5. Connexion : admin / InventorFlow2024!
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `agent_commands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agent_commands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `command` enum('force_report','update_agent','ping') DEFAULT 'force_report',
  `status` enum('pending','done','failed') DEFAULT 'pending',
  `result` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `executed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pending` (`asset_id`,`status`,`created_at`),
  CONSTRAINT `agent_commands_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `key_hash` varchar(64) NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `last_used` timestamp NULL DEFAULT NULL,
  `used_count` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `plain_key` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_hash` (`key_hash`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `api_keys_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `asset_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `action` enum('created','assigned','unassigned','status_change','updated','deleted') NOT NULL,
  `from_value` varchar(255) DEFAULT NULL,
  `to_value` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `performed_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_asset_performed` (`asset_id`,`performed_at`),
  CONSTRAINT `asset_history_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `hostname` varchar(100) NOT NULL,
  `asset_tag` varchar(50) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `os_type` enum('MAC','WIN','LIN') NOT NULL,
  `os_version` varchar(50) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `cpu` varchar(100) DEFAULT NULL,
  `ram_gb` int(11) DEFAULT NULL,
  `storage_gb` int(11) DEFAULT NULL,
  `storage_type` enum('HDD','SSD','NVMe') DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `mac_address` varchar(17) DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `warranty_until` date DEFAULT NULL,
  `status` enum('active','stock','repair','retired') DEFAULT 'stock',
  `assigned_to` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `billable` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `department_id` (`department_id`),
  KEY `idx_billable` (`client_id`,`billable`),
  KEY `idx_client_status` (`client_id`,`status`),
  KEY `idx_client_hostname` (`client_id`,`hostname`),
  CONSTRAINT `assets_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `assets_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `employees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `assets_ibfk_3` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backup_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `backup_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('success','warning','error') NOT NULL DEFAULT 'success',
  `files_size` varchar(20) DEFAULT NULL,
  `db_size` varchar(20) DEFAULT NULL,
  `duration_sec` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `files_path` varchar(255) DEFAULT NULL,
  `db_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_pack_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_pack_services` (
  `pack_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  PRIMARY KEY (`pack_id`,`service_id`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `billing_pack_services_ibfk_1` FOREIGN KEY (`pack_id`) REFERENCES `billing_packs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `billing_pack_services_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `billing_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_packs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_packs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(20) DEFAULT '#4f7ef8',
  `icon` varchar(30) DEFAULT 'layers',
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('monitoring','support','security','backup','infrastructure','other') DEFAULT 'other',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` enum('per_device','per_user','flat','per_hour') DEFAULT 'per_device',
  `billing_period` enum('monthly','annual','one_time') DEFAULT 'monthly',
  `color` varchar(20) DEFAULT '#4f7ef8',
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `qty_override` int(11) DEFAULT NULL,
  `discount_pct` decimal(5,2) DEFAULT 0.00,
  `custom_price` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `start_date` date DEFAULT curdate(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `service_id` (`service_id`),
  KEY `idx_client_active` (`client_id`,`active`),
  CONSTRAINT `billing_subscriptions_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `billing_subscriptions_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `billing_services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `contact_name` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `department_id` (`department_id`),
  KEY `idx_client_active` (`client_id`,`active`),
  CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `employees_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=359 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `license_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `license_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `license_id` int(11) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `revoked_at` date DEFAULT NULL,
  `assigned_at` date DEFAULT curdate(),
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_license_active` (`license_id`,`active`),
  KEY `idx_asset_active` (`asset_id`,`active`),
  KEY `idx_employee_active` (`employee_id`,`active`),
  CONSTRAINT `license_assignments_ibfk_1` FOREIGN KEY (`license_id`) REFERENCES `licenses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `license_assignments_ibfk_2` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `license_assignments_ibfk_3` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `license_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `license_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `vendor` varchar(100) NOT NULL,
  `category` enum('office','security','os','productivity','development','design','erp','other') DEFAULT 'other',
  `license_type` enum('subscription','perpetual','concurrent','per_device','per_user','oem') DEFAULT 'subscription',
  `price_ht` decimal(10,2) NOT NULL,
  `vat_rate` decimal(5,2) DEFAULT 20.00,
  `billing_period` enum('monthly','annual','one_time') DEFAULT 'monthly',
  `unit` enum('per_device','per_user','flat','per_hour') DEFAULT 'per_user',
  `description` varchar(255) DEFAULT NULL,
  `color` varchar(20) DEFAULT '#4f7ef8',
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `licenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `licenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `vendor` varchar(100) DEFAULT NULL,
  `category` enum('office','security','os','productivity','development','design','erp','other') DEFAULT 'other',
  `target` enum('asset','employee') NOT NULL DEFAULT 'employee',
  `license_type` enum('subscription','perpetual','concurrent','per_device','per_user','oem') DEFAULT 'subscription',
  `total_seats` int(11) DEFAULT 1,
  `used_seats` int(11) DEFAULT 0,
  `cost_per_seat` decimal(10,2) DEFAULT 0.00,
  `sell_price_per_seat` decimal(10,2) DEFAULT 0.00,
  `vat_rate` decimal(5,2) DEFAULT 20.00,
  `billing_period` enum('monthly','annual','one_time') DEFAULT 'annual',
  `purchase_date` date DEFAULT NULL,
  `renewal_date` date DEFAULT NULL,
  `product_key` varchar(500) DEFAULT NULL,
  `vendor_contact` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client_active` (`client_id`,`active`),
  CONSTRAINT `licenses_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monitoring_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitoring_snapshots` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `collected_at` timestamp NULL DEFAULT current_timestamp(),
  `cpu_pct` decimal(5,2) DEFAULT NULL,
  `ram_used_mb` int(11) DEFAULT NULL,
  `ram_total_mb` int(11) DEFAULT NULL,
  `disk_used_gb` int(11) DEFAULT NULL,
  `disk_total_gb` int(11) DEFAULT NULL,
  `load_1m` decimal(8,2) DEFAULT NULL,
  `load_5m` decimal(8,2) DEFAULT NULL,
  `load_15m` decimal(8,2) DEFAULT NULL,
  `uptime_seconds` bigint(20) DEFAULT NULL,
  `process_count` int(11) DEFAULT NULL,
  `temp_celsius` decimal(5,1) DEFAULT NULL,
  `thermal_state` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asset_collected` (`asset_id`,`collected_at`),
  KEY `idx_collected_at` (`collected_at`),
  CONSTRAINT `monitoring_snapshots_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=153 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `naming_conventions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `naming_conventions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `os_type` enum('MAC','WIN','LIN','ALL') DEFAULT 'ALL',
  `template` varchar(100) NOT NULL DEFAULT '{CLIENT}-{OS}-{SEQ:3}',
  `current_seq_mac` int(11) DEFAULT 0,
  `current_seq_win` int(11) DEFAULT 0,
  `current_seq_lin` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_os` (`client_id`,`os_type`),
  CONSTRAINT `naming_conventions_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `security_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `event_type` enum('brute_force','failed_auth','port_scan','ssh_success','other') NOT NULL DEFAULT 'other',
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `source_ip` varchar(45) DEFAULT NULL,
  `attempt_count` int(11) DEFAULT 1,
  `username` varchar(100) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `detected_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_asset_detected` (`asset_id`,`detected_at`),
  KEY `idx_detected_at` (`detected_at`),
  KEY `idx_source_ip` (`source_ip`),
  CONSTRAINT `security_events_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('superadmin','admin','viewer') DEFAULT 'admin',
  `active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;



-- ── Données de référence (catalogue générique) ──────────────
/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `license_templates` WRITE;
/*!40000 ALTER TABLE `license_templates` DISABLE KEYS */;
INSERT INTO `license_templates` VALUES
(1,'Microsoft 365 Business Basic','Microsoft','office','subscription',5.60,20.00,'monthly','per_user','Teams + SharePoint + 1 To OneDrive sans Office desktop','#0078d4',1),
(2,'Microsoft 365 Business Standard','Microsoft','office','subscription',11.70,20.00,'monthly','per_user','Office desktop + Teams + 1 To OneDrive','#0078d4',2),
(3,'Microsoft 365 Business Premium','Microsoft','office','subscription',19.70,20.00,'monthly','per_user','Premium + Intune + Azure AD P1 + Defender','#0078d4',3),
(4,'Google Workspace Business Starter','Google','office','subscription',5.20,20.00,'monthly','per_user','Gmail, Meet, Drive 30 Go','#4285f4',4),
(5,'Google Workspace Business Standard','Google','office','subscription',10.40,20.00,'monthly','per_user','Drive 2 To + enregistrement Meet','#4285f4',5),
(6,'Bitdefender GravityZone Business','Bitdefender','security','subscription',3.50,20.00,'annual','per_device','Antivirus/EDR managé cloud','#e3293e',10),
(7,'ESET Endpoint Security','ESET','security','subscription',3.80,20.00,'annual','per_device','Protection multi-couches endpoints','#006fba',11),
(8,'Kaspersky Endpoint Security Cloud','Kaspersky','security','subscription',3.20,20.00,'annual','per_device','AV + contrôle web + pare-feu','#006d5c',12),
(9,'Sophos Intercept X','Sophos','security','subscription',4.50,20.00,'monthly','per_device','EDR + deep learning + XDR','#003b5c',13),
(10,'Malwarebytes Teams','Malwarebytes','security','subscription',3.33,20.00,'monthly','per_device','Anti-malware + ransomware','#00baff',14),
(11,'CrowdStrike Falcon Go','CrowdStrike','security','subscription',4.99,20.00,'monthly','per_device','EDR nouvelle generation','#e3003b',15),
(12,'Windows 11 Pro','Microsoft','os','perpetual',199.00,20.00,'one_time','per_device','Licence perpetuelle OEM/Retail','#0078d4',20),
(13,'Windows 11 Home','Microsoft','os','perpetual',139.00,20.00,'one_time','per_device','Usage residentiel','#0078d4',21),
(14,'Windows Server 2022 Standard','Microsoft','os','perpetual',634.00,20.00,'one_time','flat','16 coeurs - 2 VM incluses','#0078d4',22),
(15,'Ubuntu Pro','Canonical','os','subscription',25.00,20.00,'annual','per_device','Support + securite etendue','#e95420',23),
(16,'Red Hat Enterprise Linux','Red Hat','os','subscription',80.00,20.00,'annual','per_device','Serveurs - support standard','#ee0000',24),
(17,'Slack Pro','Slack','productivity','subscription',7.25,20.00,'monthly','per_user','Messagerie + 10 000 messages archives','#4a154b',30),
(18,'Slack Business+','Slack','productivity','subscription',12.50,20.00,'monthly','per_user','Conformite + export + SSO','#4a154b',31),
(19,'Zoom Pro','Zoom','productivity','subscription',13.99,20.00,'monthly','per_user','Reunions jusqu\'a 100 pers.','#2d8cff',32),
(20,'Zoom Business','Zoom','productivity','subscription',18.99,20.00,'monthly','per_user','Jusqu\'a 300 participants + SSO','#2d8cff',33),
(21,'Dropbox Business','Dropbox','productivity','subscription',12.00,20.00,'monthly','per_user','Stockage cloud 5 To','#0061ff',34),
(22,'1Password Teams','1Password','productivity','subscription',3.99,20.00,'monthly','per_user','Gestionnaire de mots de passe','#1f5eff',35),
(23,'Adobe Creative Cloud All Apps','Adobe','design','subscription',54.99,20.00,'monthly','per_user','Photoshop, Illustrator, Premiere...','#ff0000',40),
(24,'Adobe Acrobat Pro','Adobe','design','subscription',18.29,20.00,'monthly','per_user','PDF professionnel','#ff0000',41),
(25,'Figma Professional','Figma','design','subscription',12.00,20.00,'monthly','per_user','Conception UI/UX collaborative','#a259ff',42),
(26,'Canva Pro','Canva','design','subscription',9.99,20.00,'monthly','per_user','Creation graphique simplifiee','#00c4cc',43),
(27,'JetBrains All Products','JetBrains','development','subscription',22.90,20.00,'monthly','per_user','IntelliJ, PhpStorm, WebStorm...','#fe315d',50),
(28,'GitHub Enterprise Cloud','GitHub','development','subscription',19.00,20.00,'monthly','per_user','Code source prive + CI/CD','#24292f',51),
(29,'GitLab Premium','GitLab','development','subscription',19.00,20.00,'monthly','per_user','DevSecOps complet','#fc6d26',52),
(30,'Visual Studio Professional','Microsoft','development','subscription',34.58,20.00,'monthly','per_user','IDE + Azure DevOps','#5c2d91',53),
(31,'Sage 50cloud Ciel','Sage','erp','subscription',35.00,20.00,'monthly','flat','Comptabilite PME','#00a651',60),
(32,'Pennylane','Pennylane','erp','subscription',49.00,20.00,'monthly','flat','Comptabilite + facturation','#6c5ce7',61),
(33,'Axonaut','Axonaut','erp','subscription',34.99,20.00,'monthly','flat','CRM + ERP PME','#ff6b35',62),
(34,'Sellsy','Sellsy','erp','subscription',60.00,20.00,'monthly','flat','CRM + facturation + gestion','#1e88e5',63),
(35,'Veeam Backup Essentials','Veeam','other','subscription',8.00,20.00,'annual','per_device','Sauvegarde VM + physique','#00b336',70),
(36,'Acronis Cyber Backup','Acronis','other','subscription',5.99,20.00,'annual','per_device','Backup cloud securise','#ef2929',71),
(37,'Backblaze Business Backup','Backblaze','other','subscription',6.00,20.00,'annual','per_device','Sauvegarde illimitee/poste','#d84b20',72);
/*!40000 ALTER TABLE `license_templates` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `billing_services` WRITE;
/*!40000 ALTER TABLE `billing_services` DISABLE KEYS */;
INSERT INTO `billing_services` VALUES
(1,'Supervision basique','Monitoring uptime, alertes email','monitoring',3.00,'per_device','monthly','#38d9f5',1,'2026-05-30 13:30:15'),
(2,'Supervision avancée','Monitoring temps réel, performances, logs, alertes SMS','monitoring',8.00,'per_device','monthly','#4f7ef8',1,'2026-05-30 13:30:15'),
(3,'Infogérance complète','Supervision + mises à jour + support inclus','support',25.00,'per_device','monthly','#9d7bff',1,'2026-05-30 13:30:15'),
(4,'Antivirus managé','Déploiement et gestion centralisée antivirus','security',4.00,'per_device','monthly','#22d3a0',1,'2026-05-30 13:30:15'),
(5,'Sauvegarde cloud','Sauvegarde quotidienne chiffrée hors site','backup',5.00,'per_device','monthly','#f5a623',1,'2026-05-30 13:30:15'),
(6,'Support helpdesk','Support utilisateurs illimité par ticket','support',15.00,'per_user','monthly','#fb923c',1,'2026-05-30 13:30:15'),
(7,'Intervention sur site','Déplacement + main d\'oeuvre (par heure)','support',120.00,'per_hour','one_time','#ff4757',1,'2026-05-30 13:30:15'),
(8,'Audit sécurité annuel','Audit complet infrastructure + rapport + préconisations','security',800.00,'flat','annual','#a78bfa',1,'2026-05-30 13:30:15');
/*!40000 ALTER TABLE `billing_services` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `billing_packs` WRITE;
/*!40000 ALTER TABLE `billing_packs` DISABLE KEYS */;
INSERT INTO `billing_packs` VALUES
(1,'Pack Essentiel','Supervision de base + protection antivirus. Idéal pour débuter.','#38d9f5','wifi',1),
(2,'Pack Pro','Supervision avancée + sécurité + sauvegarde cloud. Le plus populaire.','#4f7ef8','layers',2),
(3,'Pack Infogérance','Couverture complète : supervision, sécurité, sauvegarde et support utilisateurs.','#9d7bff','key',3);
/*!40000 ALTER TABLE `billing_packs` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;

SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
LOCK TABLES `billing_pack_services` WRITE;
/*!40000 ALTER TABLE `billing_pack_services` DISABLE KEYS */;
INSERT INTO `billing_pack_services` VALUES
(1,1),
(2,2),
(3,2),
(1,4),
(2,4),
(3,4),
(2,5),
(3,5),
(3,6);
/*!40000 ALTER TABLE `billing_pack_services` ENABLE KEYS */;
UNLOCK TABLES;
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;



-- ── Compte admin (bcrypt) ────────────────────────────────────
INSERT INTO `users` (username, `password`, full_name, role, active)
VALUES ('admin', '$2y$12$oPfPiCzeT5LJpvfDnbcSw.aKmVEe7cw1K0iYgj4.8QNUKna4iIJx.', 'Administrateur', 'superadmin', 1);

-- ── Paramètres par défaut ─────────────────────────────────────
INSERT INTO `app_settings` (setting_key, setting_value) VALUES
('app_name', 'InventorFlow'),
('app_url',  'http://localhost:8080'),
('theme',    'dark');

SET FOREIGN_KEY_CHECKS = 1;
