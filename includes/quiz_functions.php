<?php
/**
 * Fonctions partagées du module Quiz
 *
 * Utilisé par :
 *   professor/quiz_bank.php, professor/quiz_aiken_import.php,
 *   professor/quiz_manage.php, professor/quiz_dashboard.php,
 *   student/quiz_list.php, student/quiz_take.php, student/quiz_result.php
 *
 * Contient : normalisation des réponses courtes, contrôles de propriété,
 * moteur de correction, injection des notes dans `grades`.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Normalisation des réponses courtes
// minuscules, sans accents, trim, espaces multiples réduits
// ─────────────────────────────────────────────────────────────────────────────
function quiz_normalize_answer(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');

    // Suppression des accents (couvre le français + diacritiques courants)
    $map = [
        'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
        'ç'=>'c',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ñ'=>'n',
        'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ý'=>'y','ÿ'=>'y',
        'œ'=>'oe','æ'=>'ae',
        '’'=>"'", '‘'=>"'",
    ];
    $value = strtr($value, $map);

    // Espaces multiples → un seul espace
    $value = preg_replace('/\s+/u', ' ', $value);

    return trim($value);
}

// ─────────────────────────────────────────────────────────────────────────────
// Contrôles de propriété (un enseignant ne gère que SES cours / questions / quiz)
// ─────────────────────────────────────────────────────────────────────────────
function quiz_user_owns_course(mysqli $conn, int $course_id, string $user_id, string $role): bool
{
    if ($role === 'admin') {
        $stmt = $conn->prepare("SELECT id FROM courses WHERE id = ?");
        $stmt->bind_param("i", $course_id);
    } else {
        $stmt = $conn->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("is", $course_id, $user_id);
    }
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * Retourne la ligne quiz_questions si l'utilisateur en est propriétaire, sinon null.
 */
function quiz_user_owns_question(mysqli $conn, int $question_id, string $user_id, string $role): ?array
{
    if ($role === 'admin') {
        $stmt = $conn->prepare("SELECT * FROM quiz_questions WHERE id = ?");
        $stmt->bind_param("i", $question_id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM quiz_questions WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("is", $question_id, $user_id);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * Retourne la ligne quizzes si l'utilisateur en est propriétaire, sinon null.
 */
function quiz_user_owns_quiz(mysqli $conn, int $quiz_id, string $user_id, string $role): ?array
{
    if ($role === 'admin') {
        $stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ?");
        $stmt->bind_param("i", $quiz_id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("is", $quiz_id, $user_id);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * Liste des cours accessibles pour les listes déroulantes
 * (tous pour admin, uniquement les siens pour un enseignant).
 */
function quiz_get_teacher_courses(mysqli $conn, string $user_id, string $role): array
{
    if ($role === 'admin') {
        $res = $conn->query("SELECT id, name, semester FROM courses ORDER BY name");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
    $stmt = $conn->prepare("SELECT id, name, semester FROM courses WHERE teacher_id = ? ORDER BY name");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Une question est "verrouillée" (structure non modifiable) dès qu'elle est
 * liée à un quiz ayant au moins une tentative : les réponses des étudiants
 * référencent les option_id existants.
 */
function quiz_question_is_locked(mysqli $conn, int $question_id): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM quiz_question_links l
        JOIN quiz_attempts qa ON qa.quiz_id = l.quiz_id
        WHERE l.question_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $question_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// Chargement des questions d'un quiz (avec barème surchargé)
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Retourne les questions d'un quiz, indexées par question_id :
 * [
 *   12 => [
 *     'id', 'type', 'question_text', 'explanation', 'display_order',
 *     'points'   => float (points_override si défini, sinon points de la banque),
 *     'options'  => [option_id => ['text' =>, 'is_correct' =>, 'display_order' =>]],
 *     'accepted' => ['variante1', ...]   // short_answer uniquement
 *   ],
 * ]
 */
function quiz_load_quiz_questions(mysqli $conn, int $quiz_id): array
{
    $questions = [];

    $stmt = $conn->prepare("
        SELECT q.id, q.type, q.question_text, q.explanation,
               COALESCE(l.points_override, q.points) AS points,
               l.display_order
        FROM quiz_question_links l
        JOIN quiz_questions q ON q.id = l.question_id
        WHERE l.quiz_id = ?
        ORDER BY l.display_order ASC, q.id ASC
    ");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['points']   = (float) $row['points'];
        $row['options']  = [];
        $row['accepted'] = [];
        $questions[(int) $row['id']] = $row;
    }

    if (empty($questions)) {
        return [];
    }

    $ids          = array_keys($questions);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types        = str_repeat('i', count($ids));

    // Options
    $stmt = $conn->prepare("
        SELECT id, question_id, option_text, is_correct, display_order
        FROM quiz_question_options
        WHERE question_id IN ($placeholders)
        ORDER BY display_order ASC, id ASC
    ");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($opt = $res->fetch_assoc()) {
        $questions[(int) $opt['question_id']]['options'][(int) $opt['id']] = [
            'text'          => $opt['option_text'],
            'is_correct'    => (int) $opt['is_correct'],
            'display_order' => (int) $opt['display_order'],
        ];
    }

    // Variantes acceptées (réponses courtes)
    $stmt = $conn->prepare("
        SELECT question_id, accepted_value
        FROM quiz_short_answers
        WHERE question_id IN ($placeholders)
    ");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($sa = $res->fetch_assoc()) {
        $questions[(int) $sa['question_id']]['accepted'][] = $sa['accepted_value'];
    }

    return $questions;
}

// ─────────────────────────────────────────────────────────────────────────────
// Moteur de correction
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Corrige une question isolée.
 *
 * @param array $question  Élément de quiz_load_quiz_questions()
 * @param mixed $answer    Valeur du JSON answers : liste d'option_id (QCM/VF)
 *                         ou chaîne (réponse courte). null si non répondu.
 * @return array ['earned' => float, 'max' => float]
 */
function quiz_grade_question(array $question, $answer, bool $partial_credit): array
{
    $max    = (float) $question['points'];
    $earned = 0.0;

    switch ($question['type']) {
        case 'single_choice':
        case 'true_false':
            // Tout ou rien : exactement une option cochée, et c'est la bonne
            if (is_array($answer) && count($answer) === 1) {
                $chosen = (int) $answer[0];
                if (isset($question['options'][$chosen]) && $question['options'][$chosen]['is_correct']) {
                    $earned = $max;
                }
            }
            break;

        case 'multiple_choice':
            $correct_ids = [];
            foreach ($question['options'] as $oid => $opt) {
                if ($opt['is_correct']) {
                    $correct_ids[] = $oid;
                }
            }
            $total_good = count($correct_ids);
            if ($total_good === 0) {
                break; // question mal configurée → 0
            }

            $checked = [];
            if (is_array($answer)) {
                foreach ($answer as $oid) {
                    $oid = (int) $oid;
                    // Ignorer les option_id ne faisant pas partie de la question
                    if (isset($question['options'][$oid])) {
                        $checked[$oid] = true;
                    }
                }
            }

            $good_checked = 0;
            $bad_checked  = 0;
            foreach (array_keys($checked) as $oid) {
                if ($question['options'][$oid]['is_correct']) {
                    $good_checked++;
                } else {
                    $bad_checked++;
                }
            }

            if ($partial_credit) {
                // score = MAX(0, (bonnes cochées - mauvaises cochées) / total bonnes) * points
                $earned = max(0.0, ($good_checked - $bad_checked) / $total_good) * $max;
            } else {
                // Tout ou rien : ensemble coché == ensemble des bonnes réponses
                if ($good_checked === $total_good && $bad_checked === 0 && count($checked) === $total_good) {
                    $earned = $max;
                }
            }
            break;

        case 'short_answer':
            if (is_string($answer) && trim($answer) !== '') {
                $normalized = quiz_normalize_answer($answer);
                // Les variantes sont stockées déjà normalisées
                if (in_array($normalized, $question['accepted'], true)) {
                    $earned = $max;
                }
            }
            break;
    }

    return ['earned' => round($earned, 2), 'max' => $max];
}

/**
 * Corrige une tentative complète (sans écrire en base).
 *
 * @param array $quiz     Ligne de `quizzes`
 * @param array $attempt  Ligne de `quiz_attempts` (answers = JSON brut)
 * @return array ['detail' => array, 'raw_score' => float, 'max_score' => float, 'final_grade' => float]
 */
function quiz_grade_attempt(mysqli $conn, array $quiz, array $attempt): array
{
    $questions = quiz_load_quiz_questions($conn, (int) $quiz['id']);
    $answers   = json_decode($attempt['answers'] ?? '{}', true);
    if (!is_array($answers)) {
        $answers = [];
    }

    $partial_credit = !empty($quiz['partial_credit']);

    $detail    = [];
    $raw_score = 0.0;
    $max_score = 0.0;

    foreach ($questions as $qid => $question) {
        $answer = $answers[(string) $qid] ?? ($answers[$qid] ?? null);
        $result = quiz_grade_question($question, $answer, $partial_credit);

        $detail[(string) $qid] = $result;
        $raw_score += $result['earned'];
        $max_score += $result['max'];
    }

    $raw_score   = round($raw_score, 2);
    $max_score   = round($max_score, 2);
    $final_grade = $max_score > 0 ? round($raw_score / $max_score * 20, 2) : 0.0;

    return [
        'detail'      => $detail,
        'raw_score'   => $raw_score,
        'max_score'   => $max_score,
        'final_grade' => $final_grade,
    ];
}

/**
 * Corrige une tentative et enregistre le résultat dans quiz_attempts.
 *
 * @param string $status  'submitted' ou 'expired'
 */
function quiz_finalize_attempt(mysqli $conn, array $quiz, array $attempt, string $status): array
{
    $graded      = quiz_grade_attempt($conn, $quiz, $attempt);
    $detail_json = json_encode($graded['detail']);

    $stmt = $conn->prepare("
        UPDATE quiz_attempts
        SET detail = ?, raw_score = ?, max_score = ?, final_grade = ?,
            status = ?, submitted_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param(
        "sdddsi",
        $detail_json,
        $graded['raw_score'],
        $graded['max_score'],
        $graded['final_grade'],
        $status,
        $attempt['id']
    );
    $stmt->execute();

    return $graded;
}

// ─────────────────────────────────────────────────────────────────────────────
// Injection des notes dans `grades` (note 1 du cahier des charges SQL)
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Note retenue par étudiant selon quizzes.grading_method (best/last/average),
 * parmi les tentatives corrigées (submitted/expired, final_grade non NULL).
 *
 * @return array [student_id => float]
 */
function quiz_get_retained_grades(mysqli $conn, array $quiz): array
{
    $quiz_id = (int) $quiz['id'];
    $method  = $quiz['grading_method'] ?? 'best';

    switch ($method) {
        case 'average':
            $sql = "SELECT student_id, ROUND(AVG(final_grade), 2) AS retained
                    FROM quiz_attempts
                    WHERE quiz_id = ? AND status IN ('submitted','expired') AND final_grade IS NOT NULL
                    GROUP BY student_id";
            break;
        case 'last':
            $sql = "SELECT qa.student_id, qa.final_grade AS retained
                    FROM quiz_attempts qa
                    JOIN (
                        SELECT student_id, MAX(attempt_number) AS max_att
                        FROM quiz_attempts
                        WHERE quiz_id = ? AND status IN ('submitted','expired') AND final_grade IS NOT NULL
                        GROUP BY student_id
                    ) last ON last.student_id = qa.student_id AND last.max_att = qa.attempt_number
                    WHERE qa.quiz_id = ? AND qa.status IN ('submitted','expired') AND qa.final_grade IS NOT NULL";
            break;
        case 'best':
        default:
            $sql = "SELECT student_id, MAX(final_grade) AS retained
                    FROM quiz_attempts
                    WHERE quiz_id = ? AND status IN ('submitted','expired') AND final_grade IS NOT NULL
                    GROUP BY student_id";
            break;
    }

    $quiz_id_i = $quiz_id;
    $stmt = $conn->prepare($sql);
    if ($method === 'last') {
        $stmt->bind_param("ii", $quiz_id_i, $quiz_id_i);
    } else {
        $stmt->bind_param("i", $quiz_id_i);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $grades = [];
    while ($row = $res->fetch_assoc()) {
        $grades[$row['student_id']] = (float) $row['retained'];
    }
    return $grades;
}

/**
 * Injecte les notes du quiz dans `grades` (même logique d'INSERT préparé que
 * professor/grades_management.php, le tout en transaction).
 * NB : pas d'appel à calculate_student_average — cette procédure n'existe pas
 * en base (l'appel dans grades/add_grade.php est du code mort) ; les moyennes
 * sont recalculées à l'affichage par includes/grade_calculator.php.
 *
 * Idempotence garantie par le verrou quizzes.grade_injected.
 *
 * @return array ['success' => bool, 'injected' => int, 'message' => string]
 */
function quiz_inject_grades(mysqli $conn, array $quiz): array
{
    $quiz_id = (int) $quiz['id'];

    if (!empty($quiz['grade_injected'])) {
        return ['success' => false, 'injected' => 0, 'message' => "Les notes de ce quiz ont déjà été injectées."];
    }
    if (empty($quiz['counts_in_average'])) {
        // Quiz hors moyenne : on pose simplement le verrou
        $stmt = $conn->prepare("UPDATE quizzes SET grade_injected = 1 WHERE id = ?");
        $stmt->bind_param("i", $quiz_id);
        $stmt->execute();
        return ['success' => true, 'injected' => 0, 'message' => "Quiz hors moyenne : aucune note injectée."];
    }
    if (empty($quiz['evaluation_type_id']) || empty($quiz['evaluation_period_id'])) {
        return ['success' => false, 'injected' => 0, 'message' => "Type ou période d'évaluation manquant : impossible d'injecter les notes."];
    }

    $retained = quiz_get_retained_grades($conn, $quiz);
    if (empty($retained)) {
        // Aucune tentative corrigée : verrou quand même (le quiz est fermé)
        $stmt = $conn->prepare("UPDATE quizzes SET grade_injected = 1 WHERE id = ?");
        $stmt->bind_param("i", $quiz_id);
        $stmt->execute();
        return ['success' => true, 'injected' => 0, 'message' => "Aucune tentative corrigée : aucune note à injecter."];
    }

    $course_id = (int) $quiz['course_id'];
    $type_id   = (int) $quiz['evaluation_type_id'];
    $period_id = (int) $quiz['evaluation_period_id'];
    $comment   = 'Quiz : ' . $quiz['title'];
    $creator   = $quiz['teacher_id'];

    try {
        $conn->begin_transaction();

        // Verrou (dans la transaction, avant les INSERT, pour bloquer un double clic)
        $lock = $conn->prepare("UPDATE quizzes SET grade_injected = 1 WHERE id = ? AND grade_injected = 0");
        $lock->bind_param("i", $quiz_id);
        $lock->execute();
        if ($lock->affected_rows === 0) {
            $conn->rollback();
            return ['success' => false, 'injected' => 0, 'message' => "Les notes de ce quiz ont déjà été injectées."];
        }

        $insert = $conn->prepare("
            INSERT INTO grades (student_id, course_id, evaluation_type_id,
                                evaluation_period_id, grade, comment, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $count = 0;
        foreach ($retained as $student_id => $grade) {
            $grade = max(0.0, min(20.0, $grade)); // sécurité chk_grade_range
            $insert->bind_param("siiidss", $student_id, $course_id, $type_id, $period_id, $grade, $comment, $creator);
            if (!$insert->execute()) {
                throw new Exception("Erreur lors de l'insertion de la note de $student_id");
            }
            $count++;
        }

        $conn->commit();
        return ['success' => true, 'injected' => $count, 'message' => "$count note(s) injectée(s) dans le relevé."];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'injected' => 0, 'message' => $e->getMessage()];
    }
}

/**
 * Ferme un quiz : marque 'expired' + corrige les tentatives encore en cours
 * (avec leurs réponses autosauvées), passe le statut à 'closed', puis
 * injecte les notes.
 *
 * @return array ['success' => bool, 'message' => string]
 */
function quiz_close_and_inject(mysqli $conn, array $quiz): array
{
    $quiz_id = (int) $quiz['id'];

    // 1. Corriger les tentatives in_progress avec les réponses autosauvées
    $stmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND status = 'in_progress'");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $in_progress = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($in_progress as $attempt) {
        quiz_finalize_attempt($conn, $quiz, $attempt, 'expired');
    }

    // 2. Fermer le quiz
    $stmt = $conn->prepare("UPDATE quizzes SET status = 'closed' WHERE id = ?");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $quiz['status'] = 'closed';

    // 3. Injecter les notes
    $result = quiz_inject_grades($conn, $quiz);

    $msg = "Quiz fermé.";
    if (count($in_progress) > 0) {
        $msg .= " " . count($in_progress) . " tentative(s) en cours corrigée(s) avec les réponses sauvegardées.";
    }
    $msg .= " " . $result['message'];

    return ['success' => $result['success'], 'message' => $msg];
}

// ─────────────────────────────────────────────────────────────────────────────
// Timer serveur (note 3 du cahier des charges SQL)
//
// IMPORTANT : PHP et MySQL peuvent être sur des fuseaux différents (constaté
// en dev : PHP Europe/Berlin vs MySQL WAT). Toutes les comparaisons se font
// donc contre l'horloge de MySQL (quiz_db_time), jamais contre time() seul,
// puisque started_at / end_date sont écrits avec NOW() côté MySQL.
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Heure courante de MySQL, exprimée dans le référentiel strtotime() de PHP —
 * comparable directement aux colonnes datetime lues en base.
 */
function quiz_db_time(mysqli $conn): int
{
    static $offset = null;
    if ($offset === null) {
        $row    = $conn->query("SELECT NOW() AS now_dt")->fetch_assoc();
        $offset = strtotime($row['now_dt']) - time();
    }
    return time() + $offset;
}

/**
 * Échéance effective d'une tentative (timestamp, référentiel MySQL) :
 * min(started_at + durée, end_date du quiz). La fermeture du quiz borne
 * toujours la tentative, même sans limite de durée.
 */
function quiz_attempt_deadline(array $quiz, array $attempt): int
{
    $limits = [strtotime($quiz['end_date'])];
    if (!empty($quiz['duration_minutes'])) {
        $limits[] = strtotime($attempt['started_at']) + ((int) $quiz['duration_minutes']) * 60;
    }
    return min($limits);
}

/**
 * Une tentative est-elle expirée côté serveur ? (échéance + 30 s de grâce)
 */
function quiz_attempt_is_expired(mysqli $conn, array $quiz, array $attempt): bool
{
    return quiz_db_time($conn) > quiz_attempt_deadline($quiz, $attempt) + 30;
}

/**
 * Secondes restantes pour une tentative (sans la période de grâce) — pour
 * initialiser le timer JS cosmétique.
 */
function quiz_attempt_seconds_left(mysqli $conn, array $quiz, array $attempt): int
{
    return max(0, quiz_attempt_deadline($quiz, $attempt) - quiz_db_time($conn));
}

// ─────────────────────────────────────────────────────────────────────────────
// Accès étudiant (note 2 du cahier des charges SQL)
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Retourne le quiz (+ course_name) si l'étudiant y a accès : quiz publié ou
 * fermé dont le cours vise la classe de l'étudiant (courses.class_id JSON,
 * même convention JSON_QUOTE que le reste du code). Sinon null.
 */
function quiz_student_get_quiz(mysqli $conn, int $quiz_id, string $student_id): ?array
{
    $stmt = $conn->prepare("
        SELECT z.*, c.name AS course_name
        FROM quizzes z
        JOIN courses c ON c.id = z.course_id
        JOIN users u  ON u.id = ? AND u.role = 'student'
        WHERE z.id = ?
          AND z.status IN ('published','closed')
          AND JSON_CONTAINS(c.class_id, JSON_QUOTE(CAST(u.class_id AS CHAR)))
    ");
    $stmt->bind_param("si", $student_id, $quiz_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

/**
 * Nettoie le JSON de réponses reçu du client : ne garde que les questions du
 * quiz, des option_id valides (QCM) ou une chaîne bornée (réponse courte).
 */
function quiz_sanitize_answers($raw, array $questions): array
{
    $clean = [];
    if (!is_array($raw)) {
        return $clean;
    }
    foreach ($questions as $qid => $q) {
        $key = (string) $qid;
        if (!array_key_exists($key, $raw) && !array_key_exists($qid, $raw)) {
            continue;
        }
        $value = $raw[$key] ?? $raw[$qid];

        if ($q['type'] === 'short_answer') {
            if (is_string($value) && trim($value) !== '') {
                $clean[$key] = mb_substr($value, 0, 255);
            }
        } else {
            if (is_array($value)) {
                $ids = [];
                foreach ($value as $oid) {
                    $oid = (int) $oid;
                    if (isset($q['options'][$oid]) && !in_array($oid, $ids, true)) {
                        $ids[] = $oid;
                    }
                }
                if ($ids) {
                    $clean[$key] = $ids;
                }
            }
        }
    }
    return $clean;
}

// ─────────────────────────────────────────────────────────────────────────────
// Mélange déterministe (note 4 du cahier des charges SQL)
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Mélange un tableau de manière déterministe, seedé par l'ID de tentative :
 * l'ordre reste stable si l'étudiant rafraîchit la page.
 * Ne modifie pas le générateur aléatoire global.
 */
function quiz_seeded_shuffle(array $items, int $seed): array
{
    $keys = array_keys($items);
    $n    = count($keys);
    // Fisher-Yates avec générateur congruentiel local
    $state = $seed * 2654435761 % 2147483647;
    if ($state <= 0) {
        $state += 2147483646;
    }
    for ($i = $n - 1; $i > 0; $i--) {
        $state = (int) (($state * 48271) % 2147483647);
        $j     = $state % ($i + 1);
        [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
    }
    $shuffled = [];
    foreach ($keys as $k) {
        $shuffled[$k] = $items[$k];
    }
    return $shuffled;
}

// ─────────────────────────────────────────────────────────────────────────────
// Import Aiken (note 8 du cahier des charges SQL)
// ─────────────────────────────────────────────────────────────────────────────
/**
 * Parse un fichier au format Aiken (export Moodle).
 *
 * Format :
 *   Texte de la question (une ou plusieurs lignes)
 *   A. Première option
 *   B. Deuxième option
 *   ANSWER: B
 *
 * @return array ['questions' => [['text','options'=>[lettre=>texte],'answer']], 'errors' => [string]]
 */
function quiz_parse_aiken(string $content): array
{
    $content = str_replace(["\r\n", "\r"], "\n", $content);
    // Retirer un éventuel BOM UTF-8
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

    $questions = [];
    $errors    = [];

    $blocks = preg_split('/\n\s*\n/', trim($content));
    foreach ($blocks as $index => $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }
        $num   = $index + 1;
        $lines = array_map('trim', explode("\n", $block));

        $text    = [];
        $options = [];
        $answer  = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^ANSWER\s*:\s*([A-Z])\s*$/i', $line, $m)) {
                $answer = strtoupper($m[1]);
            } elseif (preg_match('/^([A-Z])[\.\)]\s+(.+)$/', $line, $m)) {
                $options[strtoupper($m[1])] = trim($m[2]);
            } else {
                if (!empty($options)) {
                    // Ligne de texte après le début des options → format invalide
                    $errors[] = "Bloc $num : ligne inattendue après les options (« " . mb_substr($line, 0, 60) . " »).";
                    continue 2;
                }
                $text[] = $line;
            }
        }

        $question_text = trim(implode("\n", $text));

        if ($question_text === '') {
            $errors[] = "Bloc $num : texte de question manquant.";
            continue;
        }
        if (count($options) < 2) {
            $errors[] = "Bloc $num : au moins 2 options requises (« " . mb_substr($question_text, 0, 60) . " »).";
            continue;
        }
        if ($answer === null) {
            $errors[] = "Bloc $num : ligne ANSWER manquante (« " . mb_substr($question_text, 0, 60) . " »).";
            continue;
        }
        if (!isset($options[$answer])) {
            $errors[] = "Bloc $num : la réponse « $answer » ne correspond à aucune option.";
            continue;
        }

        $questions[] = [
            'text'    => $question_text,
            'options' => $options,
            'answer'  => $answer,
        ];
    }

    return ['questions' => $questions, 'errors' => $errors];
}
