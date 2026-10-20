CREATE TABLE IF NOT EXISTS `#__fgloginasuser_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` int unsigned NOT NULL DEFAULT 0,
  `actor_username` varchar(150) NOT NULL DEFAULT '',
  `target_id` int unsigned NOT NULL DEFAULT 0,
  `target_username` varchar(150) NOT NULL DEFAULT '',
  `client` varchar(16) NOT NULL DEFAULT 'site',
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `note` varchar(255) NOT NULL DEFAULT '',
  `reason` varchar(255) NOT NULL DEFAULT '',
  `session_hash` char(64) NOT NULL DEFAULT '',
  `token_hash` char(64) DEFAULT NULL,
  `token_expires` datetime DEFAULT NULL,
  `created` datetime NOT NULL,
  `started` datetime DEFAULT NULL,
  `ended` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token_hash` (`token_hash`),
  KEY `idx_created` (`created`),
  KEY `idx_actor` (`actor_id`),
  KEY `idx_target` (`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

INSERT INTO `#__action_logs_extensions` (`extension`) SELECT 'com_fgloginasuser' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `#__action_logs_extensions` WHERE `extension` = 'com_fgloginasuser');
