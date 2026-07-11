-- ============================================================
-- Migration : Mode semestre pour les attestations
-- Base      : uvcoding
-- Exécuter une seule fois après attestations_migration.sql
-- ============================================================

-- 1. Colonne mode (annee_complete par défaut → rétro-compatible)
ALTER TABLE attestations
    ADD COLUMN mode ENUM('annee_complete', 'semestre') NOT NULL DEFAULT 'annee_complete'
    AFTER annee_academique;

-- 2. Référence à la période d'évaluation (NULL = année complète)
ALTER TABLE attestations
    ADD COLUMN evaluation_period_id INT UNSIGNED NULL DEFAULT NULL
    AFTER mode;

-- 3. Colonne générée pour l'index UNIQUE (MySQL n'accepte pas COALESCE dans un index)
--    Pour mode='annee_complete' : period_uniq = 0
--    Pour mode='semestre'       : period_uniq = evaluation_period_id
ALTER TABLE attestations
    ADD COLUMN period_uniq INT UNSIGNED AS (COALESCE(evaluation_period_id, 0)) STORED;

-- 4. Remplacer l'ancien unique (student_id, annee_academique) par le composite
--    Ce nouvel index garantit :
--      - une seule attestation annuelle par étudiant/année (period_uniq=0)
--      - une seule attestation semestrielle par étudiant/période (period_uniq=period_id)
ALTER TABLE attestations
    DROP INDEX uq_student_year,
    ADD UNIQUE KEY uq_student_scope (student_id, annee_academique, period_uniq);
