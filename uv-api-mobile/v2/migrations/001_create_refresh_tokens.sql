-- ============================================================
-- Migration 001 — Tokens JWT pour l'API Mobile v2
-- Base : uvcoding
--
-- ⚠ Exécuter dans phpMyAdmin en deux temps si la FK échoue :
--   Temps 1 : tout sauf la ligne CONSTRAINT (commenter la ligne FK)
--   Temps 2 : ALTER TABLE pour ajouter la FK séparément
-- ============================================================

-- Supprimer si une version incomplète existe déjà
DROP TABLE IF EXISTS `refresh_tokens`;
DROP TABLE IF EXISTS `password_resets`;

-- ── refresh_tokens ────────────────────────────────────────────────────────────
-- user_id VARCHAR(36) pour correspondre exactement à users.id
CREATE TABLE `refresh_tokens` (
    `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`     VARCHAR(36)      NOT NULL,
    `token`       VARCHAR(100)     NOT NULL,
    `expires_at`  DATETIME         NOT NULL,
    `revoked`     TINYINT(1)       NOT NULL DEFAULT 0,
    `ip_address`  VARCHAR(45)      DEFAULT NULL,
    `user_agent`  VARCHAR(255)     DEFAULT NULL,
    `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_token`      (`token`),
    KEY           `idx_user_id` (`user_id`),
    KEY           `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK séparée (commenter si Hostinger refuse les FK cross-table)
ALTER TABLE `refresh_tokens`
    ADD CONSTRAINT `fk_rt_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- ── password_resets ───────────────────────────────────────────────────────────
CREATE TABLE `password_resets` (
    `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `user_id`     VARCHAR(36)      NOT NULL,
    `token`       VARCHAR(100)     NOT NULL,
    `expires_at`  DATETIME         NOT NULL,
    `used_at`     DATETIME         DEFAULT NULL,
    `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_user_id` (`user_id`),
    UNIQUE KEY `idx_token`   (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `password_resets`
    ADD CONSTRAINT `fk_pr_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
