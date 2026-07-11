-- ============================================================================
-- UV (Université Virtuelle) — Module Quiz avec correction automatique
-- Version standard — aligné sur le schéma existant (dump du 09/07/2026)
-- ============================================================================
-- Conventions respectées :
--   - users.id = varchar(36) UUID  →  student_id / teacher_id en varchar(36)
--   - Rattachement au cours (course_id), comme course_assignments
--     (la visibilité étudiant se déduit de courses.class_id JSON)
--   - annee_academique varchar(9), comme course_assignments
--   - Injection dans `grades` via evaluation_type_id + evaluation_period_id
--   - Notes sur 20, decimal(4,2), compatibles avec chk_grade_range
--   - ENGINE=InnoDB, CHARSET=utf8mb4, COLLATE=utf8mb4_unicode_ci
--   - FK nommées fk_qz*_xxx, ON DELETE CASCADE
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0. Nouveau type d'évaluation "Quiz" (optionnel mais recommandé)
--    Si l'école préfère que les quiz comptent comme "Devoir", ignorer cet
--    INSERT et sélectionner le type Devoir à la création du quiz.
-- ----------------------------------------------------------------------------
INSERT INTO `evaluation_types` (`name`, `coefficient`)
SELECT 'Quiz', 0.20
WHERE NOT EXISTS (SELECT 1 FROM `evaluation_types` WHERE `name` = 'Quiz');

-- ----------------------------------------------------------------------------
-- 1. Banque de questions (réutilisable, indépendante des quiz)
-- ----------------------------------------------------------------------------
CREATE TABLE `quiz_questions` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `teacher_id` varchar(36) NOT NULL,
  `type` enum('single_choice','multiple_choice','true_false','short_answer') NOT NULL,
  `question_text` text NOT NULL,
  `points` decimal(5,2) NOT NULL DEFAULT 1.00,
  `explanation` text DEFAULT NULL COMMENT 'Explication affichée après correction (optionnel)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `annee_academique` varchar(9) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Options de réponse (QCM simple, QCM multiple, vrai/faux)
--    Pour true_false : créer 2 options "Vrai" / "Faux"
-- ----------------------------------------------------------------------------
CREATE TABLE `quiz_question_options` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `option_text` text NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `display_order` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Réponses acceptées pour "short_answer"
--    Plusieurs variantes possibles par question. La comparaison se fait
--    côté PHP après normalisation (minuscules, trim, accents supprimés,
--    espaces multiples réduits).
-- ----------------------------------------------------------------------------
CREATE TABLE `quiz_short_answers` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `accepted_value` varchar(255) NOT NULL COMMENT 'Stocker déjà normalisée pour comparaison directe'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Quiz
-- ----------------------------------------------------------------------------
CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `course_id` int(11) NOT NULL,
  `teacher_id` varchar(36) NOT NULL,
  `annee_academique` varchar(9) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `duration_minutes` int(11) DEFAULT NULL COMMENT 'NULL = pas de limite de temps',
  `max_attempts` int(11) NOT NULL DEFAULT 1,
  `shuffle_questions` tinyint(1) NOT NULL DEFAULT 1,
  `shuffle_options` tinyint(1) NOT NULL DEFAULT 1,
  `show_correction` enum('never','after_submit','after_close') NOT NULL DEFAULT 'after_close',
  `grading_method` enum('best','last','average') NOT NULL DEFAULT 'best' COMMENT 'Si max_attempts > 1',
  `partial_credit` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Points partiels sur QCM multiple, plancher 0',
  `status` enum('draft','published','closed') NOT NULL DEFAULT 'draft',
  `counts_in_average` tinyint(1) NOT NULL DEFAULT 1,
  `evaluation_type_id` int(11) DEFAULT NULL COMMENT 'Type sous lequel la note est injectée dans grades',
  `evaluation_period_id` int(11) DEFAULT NULL COMMENT 'Période sous laquelle la note est injectée dans grades',
  `grade_injected` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Verrou : notes déjà injectées dans grades',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Liaison quiz ↔ questions de la banque
-- ----------------------------------------------------------------------------
CREATE TABLE `quiz_question_links` (
  `quiz_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `points_override` decimal(5,2) DEFAULT NULL COMMENT 'Surcharge du barème pour ce quiz uniquement'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Tentatives des étudiants
--    answers : JSON de la forme
--      {"12": [45], "13": [47,48], "14": "photosynthese"}
--      (clé = question_id ; valeur = liste d'option_id, ou texte pour
--       short_answer)
--    Le timer est contrôlé CÔTÉ SERVEUR : à la soumission, vérifier
--      NOW() <= started_at + duration_minutes (+ 30s de grâce).
--    detail : JSON du détail de correction par question, rempli à la
--      soumission : {"12": {"earned": 1.0, "max": 1.0}, ...}
-- ----------------------------------------------------------------------------
CREATE TABLE `quiz_attempts` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `student_id` varchar(36) NOT NULL,
  `attempt_number` int(11) NOT NULL DEFAULT 1,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  `answers` longtext NOT NULL DEFAULT '{}' CHECK (json_valid(`answers`)),
  `detail` longtext DEFAULT NULL CHECK (`detail` IS NULL OR json_valid(`detail`)),
  `raw_score` decimal(6,2) DEFAULT NULL COMMENT 'Somme des points obtenus',
  `max_score` decimal(6,2) DEFAULT NULL COMMENT 'Total des points du quiz au moment de la tentative',
  `final_grade` decimal(4,2) DEFAULT NULL COMMENT 'Note ramenée sur 20, compatible grades.grade',
  `status` enum('in_progress','submitted','expired') NOT NULL DEFAULT 'in_progress',
  `last_saved_at` datetime DEFAULT NULL COMMENT 'Dernier autosave AJAX',
  `ip_address` varchar(45) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- CLÉS PRIMAIRES, INDEX ET AUTO_INCREMENT
-- (style phpMyAdmin, cohérent avec le dump existant)
-- ============================================================================

ALTER TABLE `quiz_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qq_course` (`course_id`),
  ADD KEY `idx_qq_teacher` (`teacher_id`),
  ADD KEY `idx_qq_course_year` (`course_id`,`annee_academique`);

ALTER TABLE `quiz_question_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qqo_question` (`question_id`);

ALTER TABLE `quiz_short_answers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qsa_question` (`question_id`);

ALTER TABLE `quizzes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_qz_course` (`course_id`),
  ADD KEY `idx_qz_teacher` (`teacher_id`),
  ADD KEY `idx_qz_course_year` (`course_id`,`annee_academique`),
  ADD KEY `idx_qz_status_dates` (`status`,`start_date`,`end_date`),
  ADD KEY `idx_qz_eval_type` (`evaluation_type_id`),
  ADD KEY `idx_qz_eval_period` (`evaluation_period_id`);

ALTER TABLE `quiz_question_links`
  ADD PRIMARY KEY (`quiz_id`,`question_id`),
  ADD KEY `idx_qql_question` (`question_id`);

ALTER TABLE `quiz_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_attempt` (`quiz_id`,`student_id`,`attempt_number`),
  ADD KEY `idx_qa_student` (`student_id`),
  ADD KEY `idx_qa_quiz_status` (`quiz_id`,`status`);

ALTER TABLE `quiz_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `quiz_question_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `quiz_short_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `quiz_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- ============================================================================
-- CLÉS ÉTRANGÈRES
-- ============================================================================

ALTER TABLE `quiz_questions`
  ADD CONSTRAINT `fk_qq_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_qq_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `quiz_question_options`
  ADD CONSTRAINT `fk_qqo_question` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE;

ALTER TABLE `quiz_short_answers`
  ADD CONSTRAINT `fk_qsa_question` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE;

ALTER TABLE `quizzes`
  ADD CONSTRAINT `fk_qz_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_qz_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_qz_eval_type` FOREIGN KEY (`evaluation_type_id`) REFERENCES `evaluation_types` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_qz_eval_period` FOREIGN KEY (`evaluation_period_id`) REFERENCES `evaluation_periods` (`id`) ON DELETE SET NULL;

ALTER TABLE `quiz_question_links`
  ADD CONSTRAINT `fk_qql_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_qql_question` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE;

ALTER TABLE `quiz_attempts`
  ADD CONSTRAINT `fk_qa_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_qa_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- ============================================================================
-- NOTES D'IMPLÉMENTATION (pour Claude Code)
-- ============================================================================
-- 1. INJECTION DANS grades :
--    À la fermeture du quiz (status → 'closed') ou à la soumission selon
--    show_correction, insérer pour chaque étudiant :
--      INSERT INTO grades (student_id, course_id, evaluation_type_id,
--                          evaluation_period_id, grade, comment, created_by)
--      VALUES (:student_id, quiz.course_id, quiz.evaluation_type_id,
--              quiz.evaluation_period_id, :final_grade,
--              CONCAT('Quiz : ', quiz.title), quiz.teacher_id);
--    Tentative retenue selon quizzes.grading_method (best/last/average).
--    Mettre grade_injected = 1 après injection (idempotence).
--
-- 2. VISIBILITÉ ÉTUDIANT :
--    Un étudiant voit les quiz publiés des cours dont courses.class_id
--    (JSON) contient sa users.class_id — même logique que course_assignments.
--    En SQL : JSON_CONTAINS(c.class_id, CAST(u.class_id AS JSON), '$')
--
-- 3. TIMER SERVEUR :
--    À la soumission : refuser si duration_minutes IS NOT NULL AND
--    NOW() > started_at + INTERVAL (duration_minutes) MINUTE + INTERVAL 30 SECOND.
--    Marquer 'expired' et corriger avec les réponses autosauvées.
--
-- 4. MÉLANGE DÉTERMINISTE :
--    Seeder le shuffle PHP avec quiz_attempts.id pour que l'ordre reste
--    stable en cas de rafraîchissement de page.
--
-- 5. AUTOSAVE :
--    Endpoint AJAX qui met à jour answers + last_saved_at toutes les 30 s
--    ou à chaque changement de réponse (crucial pour les coupures réseau).
--
-- 6. BARÈME QCM MULTIPLE (si partial_credit = 1) :
--    score = MAX(0, (bonnes_cochées - mauvaises_cochées) / total_bonnes) * points
--    Sinon : tout ou rien.
--
-- 7. CONVERSION FINALE :
--    final_grade = ROUND(raw_score / max_score * 20, 2)  → toujours sur 20,
--    compatible avec chk_grade_range de la table grades.
--
-- 8. IMPORT AIKEN (banque Moodle de l'école) :
--    Parser les blocs "question / A. ... / ANSWER: X" → quiz_questions
--    (type single_choice) + quiz_question_options. ~40 lignes de PHP.
-- ============================================================================
