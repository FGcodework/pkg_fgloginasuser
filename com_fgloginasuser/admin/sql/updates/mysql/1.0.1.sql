INSERT INTO `#__action_logs_extensions` (`extension`) SELECT 'com_fgloginasuser' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `#__action_logs_extensions` WHERE `extension` = 'com_fgloginasuser');
