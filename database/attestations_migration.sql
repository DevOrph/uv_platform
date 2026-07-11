-- ============================================================
-- Migration : Module Attestations de Réussite
-- Base      : uvcoding
-- Exécuter une seule fois
-- ============================================================

-- 1. Colonne lieu de naissance sur les utilisateurs (si absente)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS place_of_birth VARCHAR(100) NULL DEFAULT NULL
    AFTER birth_date;

-- 2. Table principale des attestations
CREATE TABLE IF NOT EXISTS attestations (
    id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    student_id            VARCHAR(36)   NOT NULL                    COMMENT 'Référence users.id (VARCHAR)',
    annee_academique      VARCHAR(9)    NOT NULL                    COMMENT 'Ex : 2024-2025',
    numero_enregistrement VARCHAR(20)   NOT NULL                    COMMENT 'Ex : 0001/2025 — unique par année',
    mention               VARCHAR(50)   NOT NULL,
    filiere               VARCHAR(255)  NOT NULL,
    type_diplome          VARCHAR(255)  NOT NULL DEFAULT 'LICENCE PROFESSIONNELLE',
    promotion             VARCHAR(100)  NOT NULL,
    lieu_emission         VARCHAR(100)  NOT NULL,
    date_emission         DATE          NOT NULL,
    generated_by          VARCHAR(36)   NOT NULL                    COMMENT 'Référence users.id de l''admin',
    created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_student_year (student_id, annee_academique),
    UNIQUE KEY uq_numero       (numero_enregistrement)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
