-- Monitor Central Database Schema
-- MySQL 5.7+ / MariaDB 10.2+

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `dashboard_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100),
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_login` DATETIME,
  `is_active` TINYINT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `dashboard_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `session_token` VARCHAR(128) UNIQUE NOT NULL,
  `ip_address` VARCHAR(45),
  `user_agent` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `dashboard_users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `sites` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `domain` VARCHAR(255) NOT NULL,
  `api_key` VARCHAR(64) UNIQUE NOT NULL,
  `name` VARCHAR(100),
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_seen` DATETIME,
  `status` ENUM('online','offline','unknown') DEFAULT 'unknown',
  `ssl_valid` TINYINT DEFAULT 0,
  `ssl_expiry` DATE,
  `http_status` INT,
  `visits_today` INT DEFAULT 0,
  `visits_total` INT DEFAULT 0,
  `is_active` TINYINT DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `traffic_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT,
  `ip` VARCHAR(45),
  `country` VARCHAR(100),
  `country_code` CHAR(2),
  `city` VARCHAR(100),
  `url` TEXT,
  `method` VARCHAR(10),
  `status_code` INT,
  `user_agent` TEXT,
  `referer` TEXT,
  `response_time` FLOAT,
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_site_time` (`site_id`, `timestamp`),
  INDEX `idx_ip` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `error_log` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT,
  `type` VARCHAR(50),
  `message` TEXT,
  `file` VARCHAR(500),
  `line` INT,
  `url` TEXT,
  `ip` VARCHAR(45),
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_site_time` (`site_id`, `timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ip_reputation` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip` VARCHAR(45) UNIQUE NOT NULL,
  `abuse_score` INT DEFAULT 0,
  `country` VARCHAR(100),
  `isp` VARCHAR(255),
  `domain` VARCHAR(255),
  `total_reports` INT DEFAULT 0,
  `last_reported` DATETIME,
  `is_tor` TINYINT DEFAULT 0,
  `is_proxy` TINYINT DEFAULT 0,
  `is_vpn` TINYINT DEFAULT 0,
  `last_checked` DATETIME,
  `source` VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `blocked_ips` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT,
  `ip` VARCHAR(45) NOT NULL,
  `reason` TEXT,
  `blocked_by` VARCHAR(100),
  `blocked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME,
  `is_active` TINYINT DEFAULT 1,
  INDEX `idx_ip_active` (`ip`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `file_change_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT,
  `file_path` VARCHAR(1000),
  `change_type` ENUM('added','modified','deleted'),
  `old_hash` VARCHAR(64),
  `new_hash` VARCHAR(64),
  `old_size` INT,
  `new_size` INT,
  `detected_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `monitored_files` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT,
  `file_path` VARCHAR(1000),
  `file_hash` VARCHAR(64),
  `file_size` INT,
  `last_checked` DATETIME,
  `is_suspicious` TINYINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `malware_scans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT,
  `file_path` VARCHAR(1000),
  `threat_name` VARCHAR(255),
  `pattern_matched` TEXT,
  `detected_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `is_resolved` TINYINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `alerts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT,
  `type` VARCHAR(50),
  `severity` ENUM('info','warning','critical') DEFAULT 'info',
  `message` TEXT,
  `data` JSON,
  `is_read` TINYINT DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_unread` (`is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ip_alerts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip` VARCHAR(45),
  `alert_type` VARCHAR(50),
  `site_id` INT,
  `details` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `is_resolved` TINYINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `threat_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `feed_name` VARCHAR(100),
  `feed_url` VARCHAR(500),
  `feed_type` ENUM('ip_blocklist','domain_blocklist','malware_hashes'),
  `is_enabled` TINYINT DEFAULT 1,
  `last_updated` DATETIME,
  `update_interval` INT DEFAULT 3600
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `system_config` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `config_key` VARCHAR(100) UNIQUE NOT NULL,
  `config_value` TEXT,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `traffic_stats_hourly` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `site_id` INT,
  `hour` DATETIME,
  `total_requests` INT DEFAULT 0,
  `unique_ips` INT DEFAULT 0,
  `error_count` INT DEFAULT 0,
  `blocked_count` INT DEFAULT 0,
  INDEX `idx_site_hour` (`site_id`, `hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Default Data
-- --------------------------------------------------------

-- Default admin user (password: admin123)
INSERT INTO `dashboard_users` (`username`, `password_hash`, `email`, `is_active`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@localhost', 1)
ON DUPLICATE KEY UPDATE `username` = `username`;

-- Default system configuration
INSERT INTO `system_config` (`config_key`, `config_value`) VALUES
('abuseipdb_api_key', ''),
('ipapi_key', ''),
('alert_email', 'admin@localhost'),
('session_lifetime', '3600'),
('max_login_attempts', '5'),
('cron_last_run_threat_feeds', ''),
('cron_last_run_ssl_check', ''),
('cron_last_run_cleanup', ''),
('login_attempts', '{}')
ON DUPLICATE KEY UPDATE `config_key` = `config_key`;

-- Sample threat intelligence feeds
INSERT INTO `threat_config` (`feed_name`, `feed_url`, `feed_type`, `is_enabled`, `update_interval`) VALUES
('Emerging Threats IP Blocklist', 'https://rules.emergingthreats.net/fwrules/emerging-Block-IPs.txt', 'ip_blocklist', 1, 3600),
('Feodo Tracker Botnet C2', 'https://feodotracker.abuse.ch/downloads/ipblocklist.csv', 'ip_blocklist', 1, 3600),
('URLhaus Malware URLs', 'https://urlhaus.abuse.ch/downloads/text/', 'domain_blocklist', 1, 3600)
ON DUPLICATE KEY UPDATE `feed_name` = `feed_name`;

SET FOREIGN_KEY_CHECKS = 1;
