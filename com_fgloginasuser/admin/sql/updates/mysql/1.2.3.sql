ALTER TABLE `#__fgloginasuser_logs` ADD COLUMN `session_hash` char(64) NOT NULL DEFAULT '' AFTER `reason`;
