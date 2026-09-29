-- WHMCS EPay Secure 2.1 -> 2.2 manual upgrade
-- Run ONCE if the WHMCS database user cannot ALTER TABLE automatically.

ALTER TABLE `mod_epay_attempts`
  ADD COLUMN `provider_response_at` DATETIME NULL AFTER `provider_response`;

INSERT INTO `mod_epay_meta` (`meta_key`, `meta_value`, `updated_at`)
VALUES ('schema_version', '2.2', NOW())
ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`), `updated_at` = VALUES(`updated_at`);

-- Intentionally DO NOT populate provider_response_at for cached v2.1 responses.
-- Their actual artifact creation time is unknown, so v2.2 treats them as stale and
-- attempts a same-out_trade_no refresh instead of assuming an old QR/payurl is valid.
