-- WHMCS EPay Secure 2.0 -> 2.2 manual upgrade
-- Run ONCE if upgrading directly from v2.0 and the WHMCS DB user cannot ALTER automatically.
-- Old amount/currency columns are intentionally preserved for rollback/audit.

ALTER TABLE `mod_epay_attempts`
  ADD COLUMN `invoice_amount` DECIMAL(18,2) NULL,
  ADD COLUMN `invoice_currency` VARCHAR(16) NULL,
  ADD COLUMN `processing_amount` DECIMAL(18,2) NULL,
  ADD COLUMN `processing_currency` VARCHAR(16) NULL,
  ADD COLUMN `conversion_rate` DECIMAL(28,12) NULL,
  ADD COLUMN `invoice_fee` DECIMAL(18,2) NULL,
  ADD COLUMN `source` VARCHAR(16) NULL,
  ADD COLUMN `provider_response_at` DATETIME NULL,
  ADD COLUMN `callback_payload` TEXT NULL;

UPDATE `mod_epay_attempts`
SET `processing_amount` = `amount`
WHERE `processing_amount` IS NULL;

UPDATE `mod_epay_attempts`
SET `processing_currency` = `currency`
WHERE `processing_currency` IS NULL;

UPDATE `mod_epay_attempts`
SET `source` = 'v20'
WHERE `source` IS NULL;

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
