-- ============================================================================
-- UV (Université Virtuelle) — MIGRATION CONSOLIDÉE — Juillet 2026
-- ============================================================================
-- Contenu :
--   1. Module Quiz avec correction automatique (6 tables)
--   2. Type d'évaluation « Quiz »
--   3. Verrou de modification des notes (parametres.verrou_notes_jours)
--   4. Rang Super Administrateur (users.is_super_admin)
--
-- Script IDEMPOTENT : ré-exécutable sans erreur (IF NOT EXISTS partout).
-- Déjà appliqué sur la base de développement locale (uvcoding).
-- À exécuter en production :  mysql -u USER -p BASE < migration_prod_2026-07.sql
-- Prérequis : MariaDB 10.3+ (Hostinger OK) — utf8mb4 partout.
-- ============================================================================


-- ============================================================================
-- 1. MODULE QUIZ
-- ============================================================================

-- ── 1.1 Banque de questions (réutilisable, indépendante des quiz) ───────────
CREATE TABLE IF NOT EXISTS `quiz_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `course_id` int(11) NOT NULL,
  `teacher_id` varchar(36) NOT NULL,
  `type` enum('single_choice','multiple_choice','true_false','short_answer') NOT NULL,
  `question_text` text NOT NULL,
  `points` decimal(5,2) NOT NULL DEFAULT 1.00,
  `explanation` text DEFAULT NULL COMMENT 'Explication affichée après correction (optionnel)',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `annee_academique` varchar(9) NOT NULL DEFAULT '',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_qq_course` (`course_id`),
  KEY `idx_qq_teacher` (`teacher_id`),
  KEY `idx_qq_course_year` (`course_id`,`annee_academique`),
  CONSTRAINT `fk_qq_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qq_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1.2 Options de réponse (QCM simple, QCM multiple, vrai/faux) ────────────
CREATE TABLE IF NOT EXISTS `quiz_question_options` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question_id` int(11) NOT NULL,
  `option_text` text NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `display_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_qqo_question` (`question_id`),
  CONSTRAINT `fk_qqo_question` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1.3 Réponses acceptées pour « réponse courte » ──────────────────────────
--        Stockées déjà normalisées (minuscules, sans accents, espaces réduits)
CREATE TABLE IF NOT EXISTS `quiz_short_answers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `question_id` int(11) NOT NULL,
  `accepted_value` varchar(255) NOT NULL COMMENT 'Stocker déjà normalisée pour comparaison directe',
  PRIMARY KEY (`id`),
  KEY `idx_qsa_question` (`question_id`),
  CONSTRAINT `fk_qsa_question` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1.4 Quiz ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `quizzes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_qz_course` (`course_id`),
  KEY `idx_qz_teacher` (`teacher_id`),
  KEY `idx_qz_course_year` (`course_id`,`annee_academique`),
  KEY `idx_qz_status_dates` (`status`,`start_date`,`end_date`),
  KEY `idx_qz_eval_type` (`evaluation_type_id`),
  KEY `idx_qz_eval_period` (`evaluation_period_id`),
  CONSTRAINT `fk_qz_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qz_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qz_eval_type` FOREIGN KEY (`evaluation_type_id`) REFERENCES `evaluation_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_qz_eval_period` FOREIGN KEY (`evaluation_period_id`) REFERENCES `evaluation_periods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1.5 Liaison quiz ↔ questions (barème surchargeable par quiz) ────────────
CREATE TABLE IF NOT EXISTS `quiz_question_links` (
  `quiz_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `points_override` decimal(5,2) DEFAULT NULL COMMENT 'Surcharge du barème pour ce quiz uniquement',
  PRIMARY KEY (`quiz_id`,`question_id`),
  KEY `idx_qql_question` (`question_id`),
  CONSTRAINT `fk_qql_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qql_question` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 1.6 Tentatives des étudiants ─────────────────────────────────────────────
--   answers : {"12": [45], "13": [47,48], "14": "photosynthese"}
--   detail  : {"12": {"earned": 1.0, "max": 1.0}, ...} (rempli à la correction)
--   Timer AUTORITAIRE côté serveur : min(started_at + durée, end_date) + 30 s
CREATE TABLE IF NOT EXISTS `quiz_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attempt` (`quiz_id`,`student_id`,`attempt_number`),
  KEY `idx_qa_student` (`student_id`),
  KEY `idx_qa_quiz_status` (`quiz_id`,`status`),
  CONSTRAINT `fk_qa_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qa_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 2. TYPE D'ÉVALUATION « Quiz »
--    (les notes de quiz sont injectées dans `grades` sous ce type)
-- ============================================================================
INSERT INTO `evaluation_types` (`name`, `coefficient`)
SELECT 'Quiz', 0.20
WHERE NOT EXISTS (SELECT 1 FROM `evaluation_types` WHERE `name` = 'Quiz');


-- ============================================================================
-- 3. VERROU DE MODIFICATION DES NOTES
--    Un enseignant ne peut plus modifier/supprimer une note au-delà de N jours
--    après sa saisie (grades.created_at). Les admins ne sont pas concernés.
--    Contrôle serveur : includes/grade_lock.php. 0 = verrou désactivé.
-- ============================================================================
INSERT INTO `parametres` (`cle`, `valeur`, `description`)
SELECT 'verrou_notes_jours', '7',
       'Nombre de jours après saisie au-delà duquel un enseignant ne peut plus modifier/supprimer une note (0 = désactivé, les admins ne sont pas concernés)'
WHERE NOT EXISTS (SELECT 1 FROM `parametres` WHERE `cle` = 'verrou_notes_jours');


-- ============================================================================
-- 4. RANG SUPER ADMINISTRATEUR
--    Remplace le contrôle codé en dur sur l'ID 'ADMIN01'. Le super admin
--    conserve role = 'admin' : aucun contrôle de rôle existant n'est impacté.
--    Contrôle serveur : includes/super_admin.php.
--    Gestion (promouvoir/rétrograder) : admin/manage_admins.php.
-- ============================================================================
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_super_admin` tinyint(1) NOT NULL DEFAULT 0
  COMMENT 'Super administrateur : gestion des admins, permissions d''examen, annulation de paiements'
  AFTER `role`;

ALTER TABLE `users`
  ADD KEY IF NOT EXISTS `idx_users_super_admin` (`is_super_admin`);

-- L'administrateur principal historique devient le premier super admin
UPDATE `users` SET `is_super_admin` = 1 WHERE `id` = 'ADMIN01' AND `role` = 'admin';

-- ⚠ Vérifier après exécution qu'il existe au moins un super admin :
--    SELECT id, name FROM users WHERE role = 'admin' AND is_super_admin = 1;
-- Si le compte principal de la production porte un autre ID, le promouvoir :
--    UPDATE users SET is_super_admin = 1 WHERE id = 'VOTRE_ID_ADMIN' AND role = 'admin';

-- ============================================================================
-- FIN — Rappels d'implémentation (contrôlés côté PHP) :
--   • Correction : QCM simple/VF tout-ou-rien ; QCM multiple partiel
--     score = MAX(0, (bonnes cochées − mauvaises cochées) / total bonnes) × points ;
--     réponse courte comparée après normalisation.
--   • final_grade = ROUND(raw_score / max_score × 20, 2) — compatible chk_grade_range.
--   • Fermeture d'un quiz : tentatives in_progress → 'expired' (corrigées sur
--     l'autosave), puis injection dans grades sous verrou grade_injected.
--   • Visibilité étudiant : JSON_CONTAINS(courses.class_id, JSON_QUOTE(classe)).
-- ============================================================================
