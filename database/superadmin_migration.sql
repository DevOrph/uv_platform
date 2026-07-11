-- ============================================================================
-- Migration : rang Super Administrateur
-- Remplace le contrôle codé en dur sur l'ID 'ADMIN01' par un drapeau en base.
-- Le super admin conserve role = 'admin' : aucun contrôle de rôle existant
-- n'est impacté. Gestion depuis admin/manage_admins.php.
-- ============================================================================

ALTER TABLE `users`
  ADD COLUMN `is_super_admin` tinyint(1) NOT NULL DEFAULT 0
  COMMENT 'Super administrateur : gestion des admins, permissions d''examen, annulation de paiements'
  AFTER `role`;

-- L'administrateur principal historique devient le premier super admin
UPDATE `users` SET `is_super_admin` = 1 WHERE `id` = 'ADMIN01' AND `role` = 'admin';

-- Index pour les exclusions de listes (WHERE is_super_admin = 0)
ALTER TABLE `users` ADD KEY `idx_users_super_admin` (`is_super_admin`);
