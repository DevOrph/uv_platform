-- ============================================================
-- Migration : Module Rattrapage
-- UV Platform — Coding Enterprise
-- ============================================================

CREATE TABLE IF NOT EXISTS `rattrapages` (
  `id`                   INT(11)      NOT NULL AUTO_INCREMENT,
  `student_id`           VARCHAR(36)  NOT NULL,
  `course_id`            INT(11)      NOT NULL,
  `evaluation_period_id` INT(11)      NOT NULL,
  `eligibility_reason`   ENUM('average_low','ue_not_validated','both') NOT NULL DEFAULT 'average_low',
  `original_average`     DECIMAL(4,2) DEFAULT NULL,
  `grade`                DECIMAL(4,2) DEFAULT NULL,
  `comment`              TEXT         DEFAULT NULL,
  `status`               ENUM('pending','graded') NOT NULL DEFAULT 'pending',
  `created_at`           DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `created_by`           VARCHAR(36)  NOT NULL,
  `graded_at`            DATETIME     DEFAULT NULL,
  `graded_by`            VARCHAR(36)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_rattrapage` (`student_id`, `course_id`, `evaluation_period_id`),
  KEY `idx_student`  (`student_id`),
  KEY `idx_course`   (`course_id`),
  KEY `idx_period`   (`evaluation_period_id`),
  KEY `idx_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
