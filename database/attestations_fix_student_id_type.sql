-- ============================================================
-- Migration corrective : student_id et generated_by
-- La table attestations a été créée avec student_id INT UNSIGNED
-- mais users.id est VARCHAR(36) (ex : UAS-GI2-07, LT01, ADMIN01).
-- Cette migration corrige le type pour que les INSERT fonctionnent.
-- Exécuter une seule fois après les deux migrations précédentes.
-- ============================================================

-- Supprimer les contraintes d'index qui bloquent l'ALTER
ALTER TABLE attestations
    DROP INDEX IF EXISTS uq_student_scope,
    DROP INDEX IF EXISTS uq_student_year;

-- Corriger student_id : INT UNSIGNED → VARCHAR(36)
ALTER TABLE attestations
    MODIFY COLUMN student_id VARCHAR(36) NOT NULL COMMENT 'Référence users.id (VARCHAR)';

-- Corriger generated_by : INT UNSIGNED → VARCHAR(36)
ALTER TABLE attestations
    MODIFY COLUMN generated_by VARCHAR(36) NOT NULL COMMENT 'ID admin (users.id VARCHAR)';

-- Recréer l'index unique composite (mode semestre ou année complète)
ALTER TABLE attestations
    ADD UNIQUE KEY uq_student_scope (student_id, annee_academique, period_uniq);
