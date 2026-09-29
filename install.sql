-- WHMCS EPay Secure 2.2 fresh-install schema

CREATE TABLE IF NOT EXISTS `mod_epay_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `out_trade_no` VARCHAR(64) NOT NULL,
  `provider_trade_no` VARCHAR(128) NULL,
  `invoice_amount` DECIMAL(18,2) NULL,
  `invoice_currency` VARCHAR(16) NULL,
  `processing_amount` DECIMAL(18,2) NULL,
  `processing_currency` VARCHAR(16) NULL,
  `conversion_rate` DECIMAL(28,12) NULL,
  `invoice_fee` DECIMAL(18,2) NULL,
  `payment_type` VARCHAR(32) NOT NULL,
  `source` VARCHAR(16) NOT NULL DEFAULT 'v22',
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `launch_count` INT UNSIGNED NOT NULL DEFAULT 1,
  `client_ip` VARCHAR(45) NULL,
  `provider_response` TEXT NULL,
  `provider_response_at` DATETIME NULL,
  `callback_payload` TEXT NULL,
  `last_error` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NULL,
  `paid_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mod_epay_attempts_out_trade_no_unique` (`out_trade_no`),
  KEY `mod_epay_attempts_provider_trade_no_index` (`provider_trade_no`),
  KEY `epay_invoice_status_idx` (`invoice_id`,`status`),
  KEY `epay_expires_idx` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mod_epay_meta` (
  `meta_key` VARCHAR(64) NOT NULL,
  `meta_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mod_epay_legacy_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT UNSIGNED NOT NULL,
  `out_trade_no` VARCHAR(64) NOT NULL,
  `provider_trade_no` VARCHAR(128) NOT NULL,
  `payment_type` VARCHAR(32) NULL,
  `processing_amount` DECIMAL(18,2) NOT NULL,
  `processing_currency` VARCHAR(16) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'paid_review',
  `callback_payload` TEXT NULL,
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mod_epay_legacy_provider_trade_unique` (`provider_trade_no`),
  KEY `epay_legacy_invoice_idx` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `mod_epay_meta` (`meta_key`, `meta_value`, `updated_at`)
VALUES ('schema_version', '2.2', NOW())
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`), `updated_at` = VALUES(`updated_at`);
