-- Manual migration for an existing v2.2/v2.3 database when the WHMCS user cannot ALTER tables.
-- Run once. Existing orders must be matched to the merchant by a verified query or a signed receipt.
ALTER TABLE `mod_epay_attempts` ADD COLUMN `gateway_fingerprint` VARCHAR(64) NULL;

CREATE TABLE IF NOT EXISTS `mod_epay_payment_reviews` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_key` VARCHAR(64) NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `out_trade_no` VARCHAR(64) NOT NULL,
  `provider_trade_no` VARCHAR(128) NOT NULL,
  `gateway_fingerprint` VARCHAR(64) NOT NULL,
  `processing_amount` DECIMAL(18,2) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'paid_review',
  `note` VARCHAR(255) NOT NULL,
  `callback_payload` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mod_epay_payment_reviews_event_key_unique` (`event_key`),
  KEY `epay_payment_reviews_invoice_status_idx` (`invoice_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `mod_epay_meta` (`meta_key`, `meta_value`, `updated_at`)
VALUES ('schema_version', '2.4', NOW())
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`), `updated_at` = VALUES(`updated_at`);
