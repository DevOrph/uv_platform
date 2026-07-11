-- ─────────────────────────────────────────────────────────────────────────────
-- Migration : table device_tokens
-- Stocker les tokens Expo Push par utilisateur (étudiant ou enseignant)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS device_tokens (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED     NOT NULL,
    user_role       ENUM('student','teacher') NOT NULL,
    expo_push_token VARCHAR(255)     NOT NULL,
    created_at      DATETIME         NOT NULL DEFAULT NOW(),
    updated_at      DATETIME         NOT NULL DEFAULT NOW(),

    PRIMARY KEY (id),

    -- Un utilisateur ne peut avoir qu'un token par combinaison user/role
    UNIQUE KEY uq_user_role (user_id, user_role),

    INDEX idx_user_role (user_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- Exemples d'utilisation dans les contrôleurs existants
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Lors de la création d'une annonce (dans StudentController ou admin) :
--    $tokens = NotificationController::getAllStudentTokens($this->db);
--    NotificationController::sendPush($tokens,
--        'Nouvelle annonce',
--        $announceTitle,
--        ['screen' => 'home']
--    );

-- 2. Lors d'un nouveau message dans une discussion (dans StudentController/ProfessorController) :
--    $tokens = NotificationController::getTokensForUser($this->db, $recipientId, $recipientRole);
--    NotificationController::sendPush($tokens,
--        'Nouveau message',
--        "Message dans $courseName",
--        ['screen' => 'messages', 'course_id' => $courseId]
--    );

-- 3. Lors de la saisie d'une note de rattrapage (dans ProfessorController) :
--    $tokens = NotificationController::getTokensForUser($this->db, $studentId, 'student');
--    NotificationController::sendPush($tokens,
--        'Note de rattrapage',
--        'Votre note de rattrapage a été saisie.',
--        ['screen' => 'rattrapage']
--    );
