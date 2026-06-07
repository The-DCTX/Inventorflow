-- 004_user_lang.sql : préférence de langue par utilisateur
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `lang` VARCHAR(5) NOT NULL DEFAULT 'fr';
