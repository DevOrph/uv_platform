<?php
ob_start();
require_once '../includes/db_connect.php';
/** @var mysqli $conn Connexion créée par db_connect.php */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Augmenter le temps d'exécution pour l'envoi d'emails
set_time_limit(300);
ini_set('max_execution_time', 300);

// Importer SendGrid
require_once '../vendor/autoload.php';
use SendGrid\Mail\Mail;

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.php?error=access_denied");
    exit();
}

require_once '../includes/semester_helper.php';
$current_period   = get_current_period($conn);
$current_year     = ANNEE_ACADEMIQUE_COURANTE;
$current_semester = $current_period['semester'] ?? 1;

// Configuration SendGrid
define('SENDGRID_FROM_EMAIL', 'contact@uvcoding.com');
define('SENDGRID_FROM_NAME', 'Université Virtuelle');
define('SENDGRID_DISCUSSION_TEMPLATE', 'd-6125ebdeb75043a9a4ade8426530a0f1');

$user_id = $_SESSION['user_id'];

// ============================================================
// FONCTION POUR VÉRIFIER ET RÉTABLIR LA CONNEXION MYSQL
// ============================================================
function createNewConnection() {
    try {
        return get_db_connection();
    } catch (\Throwable $e) {
        error_log("❌ Échec de connexion: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// VÉRIFIER QUE LE PROF CONNECTÉ EST L'ENSEIGNANT DU COURS
// ============================================================
function verify_teacher_course_access($conn, $teacher_id, $course_id) {
    $tid = $conn->real_escape_string($teacher_id);
    $cid = intval($course_id);

    $res = $conn->query("
        SELECT id FROM courses
        WHERE id = $cid
        AND teacher_id = '$tid'
    ");

    return $res && $res->num_rows > 0;
}

// ============================================================
// FONCTION POUR ENVOYER DES EMAILS AUX ÉTUDIANTS
// — Insère dans email_queue au lieu d'appeler SendGrid directement
// ============================================================
function sendEmailToStudents($conn, $course_id, $course_name, $sender_name, $action_type, $message_preview = '') {
    $email_conn = createNewConnection();
    if (!$email_conn) {
        error_log("❌ Impossible de créer une connexion pour les emails");
        return false;
    }

    $sql_classes = "SELECT class_id FROM courses WHERE id = ?";
    $stmt_classes = $email_conn->prepare($sql_classes);
    if (!$stmt_classes) {
        error_log("❌ Erreur préparation requête: " . $email_conn->error);
        $email_conn->close();
        return false;
    }

    $stmt_classes->bind_param("i", $course_id);
    $stmt_classes->execute();
    $result_classes = $stmt_classes->get_result();

    if (!$result_classes || !($row_class = $result_classes->fetch_assoc())) {
        $stmt_classes->close();
        $email_conn->close();
        return false;
    }

    $class_ids_json = $row_class['class_id'];
    $clean_json = preg_replace('/"\s*([^"]*?)\s*"/', '"$1"', $class_ids_json);
    $clean_json = preg_replace('/\s*,\s*/', ',', $clean_json);
    $clean_json = preg_replace('/\[\s*/', '[', $clean_json);
    $clean_json = preg_replace('/\s*\]/', ']', $clean_json);
    $class_ids = json_decode($clean_json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        preg_match_all('/["\']?\s*(\d+)\s*["\']?/', $class_ids_json, $matches);
        $class_ids = $matches[1] ?? [];
    }

    $clean_class_ids = [];
    foreach ((array)$class_ids as $id) {
        $clean_id = intval(preg_replace('/[^0-9]/', '', $id));
        if ($clean_id > 0) $clean_class_ids[] = $clean_id;
    }

    $stmt_classes->close();

    if (empty($clean_class_ids)) {
        $email_conn->close();
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($clean_class_ids), '?'));
    $sql_emails = "
        SELECT email, name
        FROM users
        WHERE role = 'student'
        AND class_id IN ($placeholders)
        AND email IS NOT NULL AND email != ''
        ORDER BY name
    ";
    $stmt_emails = $email_conn->prepare($sql_emails);
    if (!$stmt_emails) {
        error_log("❌ Erreur requête étudiants: " . $email_conn->error);
        $email_conn->close();
        return false;
    }
    $stmt_emails->bind_param(str_repeat('i', count($clean_class_ids)), ...$clean_class_ids);
    $stmt_emails->execute();
    $result_emails = $stmt_emails->get_result();

    if (!$result_emails || $result_emails->num_rows === 0) {
        $stmt_emails->close();
        $email_conn->close();
        return false;
    }

    $all_students = [];
    while ($row_email = $result_emails->fetch_assoc()) {
        $all_students[] = $row_email;
    }
    $stmt_emails->close();

    $protocol    = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    // Page discussions côté étudiant, dérivée du chemin courant (fonctionne en local /img/... et en prod)
    $student_page = str_replace('/professor/', '/student/', dirname($_SERVER['PHP_SELF']) . '/manage_discussions.php');
    $course_url  = $protocol . "://" . $_SERVER['HTTP_HOST'] . $student_page . "?course_id=" . $course_id;
    $message_preview = substr($message_preview, 0, 100);
    if (strlen($message_preview) == 100) $message_preview .= "...";

    $q_stmt = $email_conn->prepare(
        "INSERT INTO email_queue (to_email, to_name, template_id, dynamic_data) VALUES (?, ?, ?, ?)"
    );
    $template_id = SENDGRID_DISCUSSION_TEMPLATE;
    $queued = 0;

    foreach ($all_students as $student) {
        $dynamic_data = json_encode([
            'student_name'    => $student['name'],
            'sender_name'     => $sender_name,
            'course_name'     => $course_name,
            'action_type'     => $action_type,
            'message_preview' => $message_preview,
            'course_url'      => $course_url,
            'support_email'   => SENDGRID_FROM_EMAIL,
            'current_year'    => date('Y'),
        ]);
        $q_stmt->bind_param('ssss', $student['email'], $student['name'], $template_id, $dynamic_data);
        if ($q_stmt->execute()) $queued++;
    }

    $q_stmt->close();
    error_log("📬 $queued/" . count($all_students) . " emails mis en file d'attente (cours $course_id)");
    $email_conn->close();
    return $queued > 0;
}

// ============================================================
// INSERTION 1 — HANDLERS AJAX PRÉSENCES (NOUVEAU)
// ============================================================

// AJAX — Charger étudiants + séances de progression du jour
if (isset($_GET['ajax']) && $_GET['ajax'] === 'attendance') {
    header('Content-Type: application/json');
    $cid = intval($_GET['course_id'] ?? 0);

    if (($_SESSION['role'] ?? '') !== 'admin' && !verify_teacher_course_access($conn, $user_id, $cid)) {
        echo json_encode(['error' => 'Accès non autorisé', 'code' => 403]);
        exit;
    }

    $filter_year = (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year']))
        ? $_GET['year'] : $current_year;

    $stmt = $conn->prepare("SELECT class_id FROM courses WHERE id = ?");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $class_ids = json_decode($row['class_id'] ?? '[]', true);
    if (!is_array($class_ids)) $class_ids = [];
    $clean_ids = array_values(array_filter(array_map('intval', $class_ids)));

    if (empty($clean_ids)) {
        echo json_encode(['students' => [], 'available_sessions' => [], 'records_by_att' => []]);
        exit();
    }

    $ph = implode(',', array_fill(0, count($clean_ids), '?'));
    // Exclure les étudiants dont la date d'inscription dans la classe est
    // postérieure à aujourd'hui (arrivés après le début de la séance courante).
    // Si aucune entrée dans student_class_history → inclus par défaut (fallback).
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.avatar
        FROM users u
        LEFT JOIN (
            SELECT student_id, MIN(start_date) AS start_date
            FROM student_class_history
            WHERE academic_year = ?
            GROUP BY student_id
        ) sch ON sch.student_id = u.id
        WHERE u.role = 'student'
          AND u.class_id IN ($ph)
          AND u.status = 'active'
          AND (sch.start_date IS NULL OR sch.start_date <= CURDATE())
        ORDER BY u.name ASC
    ");
    $stmt->bind_param('s' . str_repeat('i', count($clean_ids)), $filter_year, ...$clean_ids);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $today = date('Y-m-d');

    // Séances de progression du jour (avec lien éventuel att_session)
    $stmt = $conn->prepare("
        SELECT cs.id AS cs_id, cs.start_time, cs.end_time, cs.hours,
               cc.title AS chapter_title,
               cs.attendance_session_id
        FROM course_sessions cs
        JOIN course_chapters cc ON cc.id = cs.chapter_id
        WHERE cs.course_id = ?
          AND DATE(cs.session_date) = CURDATE()
          AND (cs.academic_year = ? OR cs.academic_year IS NULL)
        ORDER BY cs.start_time ASC, cs.id ASC
    ");
    $stmt->bind_param("is", $cid, $filter_year);
    $stmt->execute();
    $cs_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $available_sessions = [];
    $records_by_att     = [];

    foreach ($cs_rows as $cs) {
        $start = $cs['start_time'] ? substr($cs['start_time'], 0, 5) : null;
        $end   = $cs['end_time']   ? substr($cs['end_time'],   0, 5) : null;

        $att_session_id = $cs['attendance_session_id'];
        $already_done   = false;

        if (!empty($att_session_id)) {
            // Vérifier que l'att_session liée est bien d'aujourd'hui
            $chk_att = $conn->prepare("SELECT id FROM attendance_sessions WHERE id = ? AND DATE(session_date) = CURDATE()");
            $chk_att->bind_param("s", $att_session_id);
            $chk_att->execute();
            $att_today = $chk_att->get_result()->fetch_assoc();
            $chk_att->close();

            if ($att_today) {
                $already_done = true;
            } else {
                // L'att_session pointe vers un autre jour : déliez le course_session
                $reset = $conn->prepare("UPDATE course_sessions SET attendance_session_id = NULL WHERE id = ?");
                $reset->bind_param("i", $cs['cs_id']);
                $reset->execute();
                $reset->close();
                $att_session_id = null;
            }
        }

        $available_sessions[] = [
            'cs_id'          => (int)$cs['cs_id'],
            'chapter'        => $cs['chapter_title'],
            'start_time'     => $start,
            'end_time'       => $end,
            'hours'          => round(floatval($cs['hours']), 2),
            'att_session_id' => $att_session_id,
            'already_done'   => $already_done,
        ];

        if ($already_done && $att_session_id) {
            $stmt2 = $conn->prepare("SELECT student_id, status, justification FROM attendance_records WHERE session_id = ?");
            $stmt2->bind_param("s", $att_session_id);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            $recs = [];
            while ($r = $res2->fetch_assoc()) {
                $recs[$r['student_id']] = ['status' => $r['status'], 'justification' => $r['justification']];
            }
            $stmt2->close();
            $records_by_att[$att_session_id] = $recs;
        }
    }

    $scheduled_slots = [];
    if (empty($available_sessions)) {
        $resolved_class_sched = $clean_ids[0] ?? 0;
        if (count($clean_ids) > 1) {
            $ph_sc  = implode(',', array_fill(0, count($clean_ids), '?'));
            $sth_sc = $conn->prepare("SELECT DISTINCT class_id FROM student_class_history WHERE academic_year = ? AND status = 'en_cours' AND class_id IN ($ph_sc) LIMIT 1");
            $sth_sc->bind_param('s' . str_repeat('i', count($clean_ids)), $filter_year, ...$clean_ids);
            $sth_sc->execute();
            $scRow_sc = $sth_sc->get_result()->fetch_assoc();
            $sth_sc->close();
            if ($scRow_sc) $resolved_class_sched = (int)$scRow_sc['class_id'];
        }
        $weekday_sched = (int)date('N');
        $sth_sched = $conn->prepare("
            SELECT ts.start_time, ts.end_time,
                   ROUND(TIMESTAMPDIFF(MINUTE, ts.start_time, ts.end_time) / 60, 2) AS hours
            FROM schedule s
            JOIN time_slots ts ON ts.id = s.time_slot_id
            WHERE s.course_id = ?
              AND s.class_id = ?
              AND s.weekday_id = ?
              AND (s.start_date IS NULL OR s.start_date <= ?)
              AND (s.end_date IS NULL OR s.end_date >= ?)
            ORDER BY ts.start_time ASC
        ");
        $sth_sched->bind_param("iiiss", $cid, $resolved_class_sched, $weekday_sched, $today, $today);
        $sth_sched->execute();
        $sched_rows = $sth_sched->get_result()->fetch_all(MYSQLI_ASSOC);
        $sth_sched->close();
        foreach ($sched_rows as $slot) {
            $scheduled_slots[] = [
                'start_time' => $slot['start_time'] ? substr($slot['start_time'], 0, 5) : null,
                'end_time'   => $slot['end_time']   ? substr($slot['end_time'],   0, 5) : null,
                'hours'      => round(floatval($slot['hours']), 2),
            ];
        }
    }

    echo json_encode([
        'students'           => $students,
        'available_sessions' => $available_sessions,
        'records_by_att'     => $records_by_att,
        'scheduled_slots'    => $scheduled_slots,
    ]);
    exit();
}

// AJAX — Créer séance manuellement
if (isset($_POST['ajax']) && $_POST['ajax'] === 'create_session') {
    header('Content-Type: application/json');
    $cid   = intval($_POST['course_id'] ?? 0);

    if (($_SESSION['role'] ?? '') !== 'admin' && !verify_teacher_course_access($conn, $user_id, $cid)) {
        echo json_encode(['error' => 'Accès non autorisé', 'code' => 403]);
        exit;
    }

    $today = date('Y-m-d');
    $uid   = $_SESSION['user_id'];

    // Séance déjà existante pour ce cours aujourd'hui ?
    $stmt = $conn->prepare("SELECT id FROM attendance_sessions WHERE course_id = ? AND session_date = ? AND academic_year = ? LIMIT 1");
    $stmt->bind_param("iss", $cid, $today, $current_year);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        echo json_encode(['success' => true, 'session_id' => $existing['id'], 'already_exists' => true]);
        exit();
    }

    // Récupérer les class_ids du cours (JSON)
    $stmt = $conn->prepare("SELECT class_id FROM courses WHERE id = ?");
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        echo json_encode(['success' => false, 'error' => 'Cours introuvable']);
        exit();
    }
    $class_ids = array_values(array_filter(array_map('intval', json_decode($row['class_id'] ?? '[]', true))));
    if (empty($class_ids)) {
        echo json_encode(['success' => false, 'error' => 'Aucune classe attachée à ce cours']);
        exit();
    }

    // Résoudre le class_id actif via student_class_history (cours multi-classes)
    $resolved_class = $class_ids[0] ?? 0;
    if (count($class_ids) > 1) {
        $ph  = implode(',', array_fill(0, count($class_ids), '?'));
        $sth = $conn->prepare("SELECT DISTINCT class_id FROM student_class_history WHERE academic_year = ? AND status = 'en_cours' AND class_id IN ($ph) LIMIT 1");
        $sth->bind_param('s' . str_repeat('i', count($class_ids)), $current_year, ...$class_ids);
        $sth->execute();
        $scRow = $sth->get_result()->fetch_assoc();
        $sth->close();
        if ($scRow) $resolved_class = (int)$scRow['class_id'];
    }

    // Récupérer tous les créneaux du cours ce jour dans schedule (pour durée réelle)
    $weekday = (int)date('N');
    $sth = $conn->prepare("
        SELECT ts.id AS ts_id, ts.start_time, ts.end_time
        FROM schedule s
        JOIN time_slots ts ON ts.id = s.time_slot_id
        WHERE s.course_id = ? AND s.class_id = ? AND s.weekday_id = ?
          AND (s.start_date IS NULL OR s.start_date <= ?)
          AND (s.end_date IS NULL OR s.end_date >= ?)
        ORDER BY ts.start_time ASC
    ");
    $sth->bind_param("iiiss", $cid, $resolved_class, $weekday, $today, $today);
    $sth->execute();
    $slots = $sth->get_result()->fetch_all(MYSQLI_ASSOC);
    $sth->close();

    // Fusionner les créneaux consécutifs → durée totale en heures
    $first_slot_id = null;
    $duration      = null;
    if (!empty($slots)) {
        $first_slot_id = (int)$slots[0]['ts_id'];
        $seg_start = $slots[0]['start_time'];
        $seg_end   = $slots[0]['end_time'];
        for ($i = 1; $i < count($slots); $i++) {
            if ($slots[$i]['start_time'] === $seg_end) {
                $seg_end = $slots[$i]['end_time'];
            }
        }
        $toMin    = fn($t) => (int)explode(':', $t)[0] * 60 + (int)explode(':', $t)[1];
        $duration = round(($toMin($seg_end) - $toMin($seg_start)) / 60, 2);
    }
    if ($duration === null && isset($_POST['manual_duration'])) {
        $md = floatval($_POST['manual_duration']);
        if ($md > 0) $duration = round($md, 2);
    }

    // Insérer la séance + lier au course_session du jour (atomique)
    $new_session_id = null;
    $inTx = false;
    try {
        $conn->begin_transaction();
        $inTx = true;

        $stmt = $conn->prepare("INSERT INTO attendance_sessions (id, course_id, teacher_id, class_id, session_date, time_slot_id, created_by, academic_year, duration) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isisissd", $cid, $uid, $resolved_class, $today, $first_slot_id, $uid, $current_year, $duration);
        $stmt->execute();
        $stmt->close();

        // Récupérer l'id de la séance créée
        $stmt = $conn->prepare("SELECT id FROM attendance_sessions WHERE course_id = ? AND session_date = ? AND academic_year = ? LIMIT 1");
        $stmt->bind_param("iss", $cid, $today, $current_year);
        $stmt->execute();
        $new_session = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $new_session_id = $new_session['id'] ?? null;

        if ($new_session_id) {
            $cs_link = $conn->prepare("SELECT id FROM course_sessions WHERE course_id = ? AND DATE(session_date) = CURDATE() AND attendance_session_id IS NULL ORDER BY id ASC LIMIT 1");
            $cs_link->bind_param("i", $cid);
            $cs_link->execute();
            $cs_link_row = $cs_link->get_result()->fetch_assoc();
            $cs_link->close();
            if ($cs_link_row) {
                $upd_cs = $conn->prepare("UPDATE course_sessions SET attendance_session_id = ? WHERE id = ?");
                $upd_cs->bind_param("si", $new_session_id, $cs_link_row['id']);
                $upd_cs->execute();
                $upd_cs->close();
            }
        }

        $conn->commit();
        $inTx = false;

    } catch (\Throwable $e) {
        if ($inTx) {
            try { $conn->rollback(); } catch (\Throwable $re) {}
        }
        echo json_encode(['error' => 'Erreur lors de la création de la séance : ' . $e->getMessage()]);
        exit;
    }

    echo json_encode(['success' => true, 'session_id' => $new_session_id]);
    exit();
}

// AJAX — Sauvegarder présence d'un étudiant
if (isset($_POST['ajax']) && $_POST['ajax'] === 'save_attendance') {
    header('Content-Type: application/json');
    $session_id = trim($_POST['session_id'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $status     = $_POST['status'] ?? 'absent';
    $justif     = trim($_POST['justification'] ?? '');
    $cid_save   = intval($_POST['course_id'] ?? 0);
    $cs_id      = intval($_POST['cs_id'] ?? 0);
    $today      = date('Y-m-d');
    $uid        = $_SESSION['user_id'];

    // Résoudre le course_id depuis la séance si absent du POST (cas session_id déjà connu)
    $check_cid = $cid_save;
    if ($check_cid === 0 && !empty($session_id)) {
        $stmt_cid = $conn->prepare("SELECT course_id FROM attendance_sessions WHERE id = ?");
        $stmt_cid->bind_param("s", $session_id);
        $stmt_cid->execute();
        $row_cid = $stmt_cid->get_result()->fetch_assoc();
        $stmt_cid->close();
        $check_cid = $row_cid ? intval($row_cid['course_id']) : 0;
    }
    if (($_SESSION['role'] ?? '') !== 'admin' && !verify_teacher_course_access($conn, $user_id, $check_cid)) {
        echo json_encode(['error' => 'Accès non autorisé', 'code' => 403]);
        exit;
    }

    if (empty($student_id)) {
        echo json_encode(['success' => false, 'error' => 'Paramètre student_id manquant']);
        exit();
    }

    $allowed = ['present', 'absent', 'late'];
    if (!in_array($status, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Statut invalide']);
        exit();
    }

    // Si aucun session_id fourni, chercher ou créer la séance
    if (empty($session_id)) {
        if (!$cid_save) {
            echo json_encode(['success' => false, 'error' => 'course_id requis pour créer une séance']);
            exit();
        }

        if ($cs_id) {
            // Nouveau flux : att_session liée au course_session sélectionné
            $cs_stmt = $conn->prepare("SELECT id, hours, attendance_session_id FROM course_sessions WHERE id = ? AND course_id = ? AND DATE(session_date) = CURDATE()");
            $cs_stmt->bind_param("ii", $cs_id, $cid_save);
            $cs_stmt->execute();
            $cs_row = $cs_stmt->get_result()->fetch_assoc();
            $cs_stmt->close();

            if (!$cs_row) {
                echo json_encode(['success' => false, 'error' => 'Séance de progression introuvable ou non valide']);
                exit();
            }

            if (!empty($cs_row['attendance_session_id'])) {
                // Réutiliser l'att_session déjà liée
                $session_id = $cs_row['attendance_session_id'];
            } else {
                $cs_hours = round(floatval($cs_row['hours']), 2);

                // Résoudre le class_id (nécessaire pour la vérification de doublon)
                $stmt_c = $conn->prepare("SELECT class_id FROM courses WHERE id = ?");
                $stmt_c->bind_param("i", $cid_save);
                $stmt_c->execute();
                $course_row2 = $stmt_c->get_result()->fetch_assoc();
                $stmt_c->close();

                $class_ids2 = array_values(array_filter(array_map('intval', json_decode($course_row2['class_id'] ?? '[]', true))));
                $resolved_class2 = $class_ids2[0] ?? 0;
                if (count($class_ids2) > 1) {
                    $ph2 = implode(',', array_fill(0, count($class_ids2), '?'));
                    $sth2 = $conn->prepare("SELECT DISTINCT class_id FROM student_class_history WHERE academic_year = ? AND status = 'en_cours' AND class_id IN ($ph2) LIMIT 1");
                    $sth2->bind_param('s' . str_repeat('i', count($class_ids2)), $current_year, ...$class_ids2);
                    $sth2->execute();
                    $scRow2 = $sth2->get_result()->fetch_assoc();
                    $sth2->close();
                    if ($scRow2) $resolved_class2 = (int)$scRow2['class_id'];
                }

                // Vérifier doublon par course_session_id avant toute insertion
                $chk_dup = $conn->prepare("SELECT id FROM attendance_sessions WHERE course_id = ? AND class_id = ? AND DATE(session_date) = CURDATE() AND course_session_id = ? LIMIT 1");
                $chk_dup->bind_param("iii", $cid_save, $resolved_class2, $cs_id);
                $chk_dup->execute();
                $dup_row = $chk_dup->get_result()->fetch_assoc();
                $chk_dup->close();

                if ($dup_row) {
                    // Réutiliser la séance existante et synchroniser course_sessions si besoin
                    $session_id = $dup_row['id'];
                    $sync = $conn->prepare("UPDATE course_sessions SET attendance_session_id = ? WHERE id = ? AND attendance_session_id IS NULL");
                    $sync->bind_param("si", $session_id, $cs_id);
                    $sync->execute();
                    $sync->close();
                } else {
                    // Créer une nouvelle att_session avec duration = cs.hours
                    $new_att_id = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));

                    $inTx = false;
                    try {
                        $conn->begin_transaction();
                        $inTx = true;

                        $stmt_ins = $conn->prepare("INSERT INTO attendance_sessions (id, course_id, teacher_id, class_id, session_date, created_by, academic_year, duration, course_session_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt_ins->bind_param("sisisssdi", $new_att_id, $cid_save, $uid, $resolved_class2, $today, $uid, $current_year, $cs_hours, $cs_id);
                        $stmt_ins->execute();
                        $stmt_ins->close();

                        $upd = $conn->prepare("UPDATE course_sessions SET attendance_session_id = ? WHERE id = ? AND course_id = ?");
                        $upd->bind_param("sii", $new_att_id, $cs_id, $cid_save);
                        $upd->execute();
                        if ($upd->affected_rows === 0) {
                            throw new \Exception("Liaison course_session échouée");
                        }
                        $upd->close();

                        $conn->commit();
                        $inTx = false;
                        $session_id = $new_att_id;

                    } catch (\Throwable $e) {
                        if ($inTx) {
                            try { $conn->rollback(); } catch (\Throwable $re) {}
                        }
                        echo json_encode(['error' => 'Erreur lors de la création de la séance : ' . $e->getMessage()]);
                        exit;
                    }
                }
            }
        } else {
            // Fallback : ancien comportement sans cs_id (uniquement séances non liées à un course_session)
            $stmt = $conn->prepare("SELECT id FROM attendance_sessions WHERE course_id = ? AND session_date = ? AND academic_year = ? AND course_session_id IS NULL LIMIT 1");
            $stmt->bind_param("iss", $cid_save, $today, $current_year);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $session_id = $existing['id'];
            } else {
                // Résoudre la classe principale du cours
                $stmt = $conn->prepare("SELECT class_id FROM courses WHERE id = ?");
                $stmt->bind_param("i", $cid_save);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $class_ids = array_values(array_filter(array_map('intval', json_decode($row['class_id'] ?? '[]', true))));
                $resolved_class = $class_ids[0] ?? 0;

                if (count($class_ids) > 1) {
                    $ph  = implode(',', array_fill(0, count($class_ids), '?'));
                    $sth = $conn->prepare("SELECT DISTINCT class_id FROM student_class_history WHERE academic_year = ? AND status = 'en_cours' AND class_id IN ($ph) LIMIT 1");
                    $sth->bind_param('s' . str_repeat('i', count($class_ids)), $current_year, ...$class_ids);
                    $sth->execute();
                    $scRow = $sth->get_result()->fetch_assoc();
                    $sth->close();
                    if ($scRow) $resolved_class = (int)$scRow['class_id'];
                }

                // Récupérer le créneau horaire du jour
                $weekday = (int)date('N');
                $sth = $conn->prepare("
                    SELECT ts.id AS ts_id, ts.start_time, ts.end_time
                    FROM schedule s
                    JOIN time_slots ts ON ts.id = s.time_slot_id
                    WHERE s.course_id = ? AND s.class_id = ? AND s.weekday_id = ?
                      AND (s.start_date IS NULL OR s.start_date <= ?)
                      AND (s.end_date IS NULL OR s.end_date >= ?)
                    ORDER BY ts.start_time ASC
                ");
                $sth->bind_param("iiiss", $cid_save, $resolved_class, $weekday, $today, $today);
                $sth->execute();
                $slots = $sth->get_result()->fetch_all(MYSQLI_ASSOC);
                $sth->close();

                $first_slot_id = null;
                $duration      = null;
                if (!empty($slots)) {
                    $first_slot_id = (int)$slots[0]['ts_id'];
                    $seg_start = $slots[0]['start_time'];
                    $seg_end   = $slots[0]['end_time'];
                    for ($i = 1; $i < count($slots); $i++) {
                        if ($slots[$i]['start_time'] === $seg_end) $seg_end = $slots[$i]['end_time'];
                    }
                    $toMin    = fn($t) => (int)explode(':', $t)[0] * 60 + (int)explode(':', $t)[1];
                    $duration = round(($toMin($seg_end) - $toMin($seg_start)) / 60, 2);
                }
                if ($duration === null && isset($_POST['manual_duration'])) {
                    $md = floatval($_POST['manual_duration']);
                    if ($md > 0) $duration = round($md, 2);
                }

                $stmt = $conn->prepare("INSERT INTO attendance_sessions (id, course_id, teacher_id, class_id, session_date, time_slot_id, created_by, academic_year, duration) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isisissd", $cid_save, $uid, $resolved_class, $today, $first_slot_id, $uid, $current_year, $duration);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("SELECT id FROM attendance_sessions WHERE course_id = ? AND session_date = ? AND academic_year = ? LIMIT 1");
                $stmt->bind_param("iss", $cid_save, $today, $current_year);
                $stmt->execute();
                $new_sess = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $session_id = $new_sess['id'] ?? '';

                // Lier un course_session non encore lié pour ce cours aujourd'hui
                if ($session_id) {
                    $cs_stmt = $conn->prepare("SELECT id FROM course_sessions WHERE course_id = ? AND DATE(session_date) = CURDATE() AND attendance_session_id IS NULL ORDER BY id ASC LIMIT 1");
                    $cs_stmt->bind_param("i", $cid_save);
                    $cs_stmt->execute();
                    $cs_row = $cs_stmt->get_result()->fetch_assoc();
                    $cs_stmt->close();
                    if ($cs_row) {
                        $upd = $conn->prepare("UPDATE course_sessions SET attendance_session_id = ? WHERE id = ?");
                        $upd->bind_param("si", $session_id, $cs_row['id']);
                        $upd->execute();
                        $upd->close();
                    }
                }
            }
        }
    }

    // Vérifier que la séance est bien aujourd'hui
    $stmt = $conn->prepare("SELECT session_date FROM attendance_sessions WHERE id = ?");
    $stmt->bind_param("s", $session_id);
    $stmt->execute();
    $sess = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sess || $sess['session_date'] !== $today) {
        echo json_encode(['success' => false, 'error' => 'Modification impossible : séance passée']);
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO attendance_records (id, session_id, student_id, status, justification, marked_by)
        VALUES (UUID(), ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), justification = VALUES(justification), updated_by = VALUES(marked_by), updated_at = NOW()
    ");
    $stmt->bind_param("sssss", $session_id, $student_id, $status, $justif, $uid);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'session_id' => $session_id]);
    exit();
}

// ============================================================
// HANDLERS AJAX PROGRESSION (PROFESSEUR)
// ============================================================

if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_progress' && isset($_GET['course_id'])) {
    header('Content-Type: application/json');
    $cid = intval($_GET['course_id']);
    $filter_year = (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year']))
        ? $_GET['year'] : $current_year;
    // Vérifier que ce cours appartient bien à ce professeur
    $check = $conn->prepare("SELECT id, total_hours FROM courses WHERE id = ? AND teacher_id = ?");
    $check->bind_param("is", $cid, $user_id);
    $check->execute();
    $course_row = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$course_row) { echo json_encode(['success' => false, 'message' => 'Accès refusé']); exit(); }

    $att_year = $conn->real_escape_string($filter_year);

    // Précharger toutes les séances en une requête
    $all_sessions_stmt = $conn->prepare("
        SELECT cs.*, COALESCE(att.duration, cs.hours) AS effective_hours
        FROM course_sessions cs
        LEFT JOIN attendance_sessions att ON att.id = cs.attendance_session_id
        WHERE cs.course_id = ?
          AND (cs.academic_year = ? OR cs.academic_year IS NULL)
        ORDER BY cs.chapter_id ASC, cs.session_number ASC
    ");
    $all_sessions_stmt->bind_param("is", $cid, $att_year);
    $all_sessions_stmt->execute();
    $all_sessions = $all_sessions_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $all_sessions_stmt->close();

    $sessions_by_chapter = [];
    foreach ($all_sessions as $s) {
        $sessions_by_chapter[$s['chapter_id']][] = $s;
    }

    // Chapitres et séances du syllabus — filtrés par année (NULL = planifiés, visibles partout)
    $chapters_res = $conn->query("SELECT * FROM course_chapters WHERE course_id = $cid AND (academic_year = '$att_year' OR academic_year IS NULL) ORDER BY order_num ASC");
    $chapters = [];
    $total_hours_done = 0.0;
    if ($chapters_res) while ($ch = $chapters_res->fetch_assoc()) {
        $sessions = [];
        $ch_hours_done = 0.0;
        foreach ($sessions_by_chapter[$ch['id']] ?? [] as $s) {
            $s['done'] = $s['attendance_session_id'] !== null;
            if ($s['session_date'] !== null) $ch_hours_done += (float)$s['hours'];
            $sessions[] = $s;
        }
        $ch['sessions']   = $sessions;
        $ch['hours_done'] = $ch_hours_done;
        $total_hours_done += $ch_hours_done;
        $chapters[] = $ch;
    }
    // Nombre de séances documentées (session_date renseignée)
    $nb_res = $conn->query("SELECT COUNT(*) AS nb FROM course_sessions WHERE course_id = $cid AND session_date IS NOT NULL AND (academic_year = '$att_year' OR academic_year IS NULL)");
    $total_sessions = $nb_res ? (int)$nb_res->fetch_assoc()['nb'] : 0;
    $planned = floatval($course_row['total_hours'] ?? 0);

    echo json_encode(['success' => true, 'chapters' => $chapters, 'total_hours' => $total_hours_done, 'total_sessions' => $total_sessions, 'planned_hours' => $planned]);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'prog_edit_chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data  = json_decode(file_get_contents('php://input'), true);
    $id    = intval($data['id']    ?? 0);
    $title = trim($data['title']   ?? '');
    // Vérifier ownership via le cours + récupérer l'année du chapitre
    $chk = $conn->prepare("SELECT c.id, ch.academic_year FROM courses c JOIN course_chapters ch ON ch.course_id = c.id WHERE ch.id = ? AND c.teacher_id = ?");
    $chk->bind_param("is", $id, $user_id); $chk->execute();
    $chk_row = $chk->get_result()->fetch_assoc();
    if (!$chk_row) { echo json_encode(['success' => false]); exit(); }
    $chk->close();
    // Un chapitre rattaché à une année académique passée (différente de l'année courante) n'est plus modifiable
    if ($chk_row['academic_year'] !== null && $chk_row['academic_year'] !== $current_year) {
        echo json_encode(['success' => false, 'error' => "Ce chapitre appartient à une année académique archivée et n'est plus modifiable."]);
        exit();
    }
    if (!$id || !$title) { echo json_encode(['success' => false, 'error' => 'Titre requis']); exit(); }
    if (mb_strlen($title) > 255) { echo json_encode(['success' => false, 'error' => 'Titre trop long (255 caractères max)']); exit(); }
    $s = $conn->prepare("UPDATE course_chapters SET title=? WHERE id=?");
    $s->bind_param("si", $title, $id);
    $ok = $s->execute();
    $s->close();
    echo json_encode(['success' => $ok]);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_chapter_delete' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $chk = $conn->prepare("SELECT c.id, ch.academic_year FROM courses c JOIN course_chapters ch ON ch.course_id = c.id WHERE ch.id = ? AND c.teacher_id = ?");
    $chk->bind_param("is", $id, $user_id); $chk->execute();
    $chk_row = $chk->get_result()->fetch_assoc();
    if (!$chk_row) { echo json_encode(['success' => false]); exit(); }
    $chk->close();
    if ($chk_row['academic_year'] !== null && $chk_row['academic_year'] !== $current_year) {
        echo json_encode(['needs_confirm' => false, 'archived' => true, 'error' => "Ce chapitre appartient à une année académique archivée et ne peut pas être supprimé."]);
        exit();
    }

    $stmt_total = $conn->prepare("SELECT COUNT(*) AS cnt FROM course_sessions WHERE chapter_id = ?");
    $stmt_total->bind_param("i", $id);
    $stmt_total->execute();
    $total = (int)$stmt_total->get_result()->fetch_assoc()['cnt'];
    $stmt_total->close();

    $stmt_with_call = $conn->prepare("SELECT COUNT(*) AS cnt FROM course_sessions WHERE chapter_id = ? AND attendance_session_id IS NOT NULL");
    $stmt_with_call->bind_param("i", $id);
    $stmt_with_call->execute();
    $with_call = (int)$stmt_with_call->get_result()->fetch_assoc()['cnt'];
    $stmt_with_call->close();

    if ($with_call === 0) {
        echo json_encode(['needs_confirm' => false]);
    } else {
        $sess_word  = $total > 1    ? 'séances'            : 'séance';
        $call_word  = $with_call > 1 ? 'appels enregistrés' : 'appel enregistré';
        echo json_encode([
            'needs_confirm'      => true,
            'total_sessions'     => $total,
            'sessions_with_call' => $with_call,
            'message'            => "Ce chapitre contient $total $sess_word dont $with_call avec des $call_word. Les données de présence associées seront conservées mais ne seront plus visibles depuis la progression.",
        ]);
    }
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'prog_delete_chapter' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id    = intval($_GET['id']);
    $force = isset($_GET['force']) && $_GET['force'] === '1';
    error_log("DELETE_CHAPTER: chapter_id=$id force=" . ($_GET['force'] ?? 'absent'));
    // Vérifier ownership + année
    $chk = $conn->prepare("SELECT c.id, ch.academic_year FROM courses c JOIN course_chapters ch ON ch.course_id = c.id WHERE ch.id = ? AND c.teacher_id = ?");
    $chk->bind_param("is", $id, $user_id); $chk->execute();
    $chk_row = $chk->get_result()->fetch_assoc();
    if (!$chk_row) {
        error_log("DELETE_CHAPTER: ownership check failed");
        echo json_encode(['success' => false]);
        exit();
    }
    $chk->close();
    if ($chk_row['academic_year'] !== null && $chk_row['academic_year'] !== $current_year) {
        echo json_encode(['success' => false, 'error' => "Ce chapitre appartient à une année académique archivée et ne peut pas être supprimé."]);
        exit();
    }

    $inTx = false;
    try {
        $conn->begin_transaction();
        $inTx = true;

        if ($force) {
            // Délier les attendance_sessions dont course_session_id pointe vers une séance de ce chapitre
            $upd = $conn->prepare("UPDATE attendance_sessions SET course_session_id = NULL WHERE course_session_id IN (SELECT id FROM course_sessions WHERE chapter_id = ?)");
            if (!$upd) {
                throw new \Exception("Préparation UPDATE échouée : " . $conn->error);
            }
            $upd->bind_param("i", $id);
            if (!$upd->execute()) {
                throw new \Exception("UPDATE attendance_sessions : " . $upd->error);
            }
            error_log("DELETE_CHAPTER: UPDATE ok, rows=" . $upd->affected_rows);
            $upd->close();
        }

        $del_sess = $conn->prepare("DELETE FROM course_sessions WHERE chapter_id = ?");
        $del_sess->bind_param("i", $id);
        if (!$del_sess->execute()) {
            throw new \Exception("DELETE course_sessions : " . $del_sess->error);
        }
        $del_sess->close();

        $s = $conn->prepare("DELETE FROM course_chapters WHERE id=?");
        $s->bind_param("i", $id);
        if (!$s->execute()) {
            throw new \Exception("DELETE course_chapters : " . $s->error);
        }
        $s->close();

        $conn->commit();
        $inTx = false;

        error_log("DELETE_CHAPTER: done, success=true");
        echo json_encode(['success' => true]);

    } catch (\Throwable $e) {
        if ($inTx) {
            try { $conn->rollback(); } catch (\Throwable $re) {}
        }
        error_log("DELETE_CHAPTER: error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'prog_add_chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data      = json_decode(file_get_contents('php://input'), true);
    $cid       = intval($data['course_id'] ?? 0);
    $title     = trim($data['title'] ?? '');
    $req_year  = (isset($data['year']) && preg_match('/^\d{4}-\d{4}$/', $data['year'])) ? $data['year'] : $current_year;
    if ($req_year !== $current_year) {
        echo json_encode(['success' => false, 'error' => "Impossible d'ajouter un chapitre dans une année académique archivée."]);
        exit();
    }
    // Vérifier ownership
    $chk = $conn->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
    $chk->bind_param("is", $cid, $user_id); $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) { echo json_encode(['success' => false]); exit(); }
    $chk->close();
    if (!$cid || !$title) { echo json_encode(['success' => false, 'error' => 'Titre requis']); exit(); }
    if (mb_strlen($title) > 255) { echo json_encode(['success' => false, 'error' => 'Titre trop long (255 caractères max)']); exit(); }
    $ord_res = $conn->query("SELECT COALESCE(MAX(order_num),0)+1 AS n FROM course_chapters WHERE course_id=$cid AND (academic_year = '$current_year' OR academic_year IS NULL)");
    $ord = $ord_res ? (int)$ord_res->fetch_assoc()['n'] : 1;
    $s = $conn->prepare("INSERT INTO course_chapters (course_id, title, order_num, created_by, academic_year) VALUES (?,?,?,?,?)");
    $s->bind_param("isiss", $cid, $title, $ord, $user_id, $current_year);
    if ($s->execute()) echo json_encode(['success' => true, 'id' => $conn->insert_id, 'order_num' => $ord]);
    else echo json_encode(['success' => false]);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'prog_add_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data        = json_decode(file_get_contents('php://input'), true);
    $chapter_id  = intval($data['chapter_id']  ?? 0);
    $cid         = intval($data['course_id']   ?? 0);
    $title       = trim($data['title']         ?? '');
    $description = trim($data['description']   ?? '');
    $req_year    = (isset($data['year']) && preg_match('/^\d{4}-\d{4}$/', $data['year'])) ? $data['year'] : $current_year;
    if ($req_year !== $current_year) {
        echo json_encode(['success' => false, 'error' => "Impossible d'ajouter une séance dans une année académique archivée."]);
        exit();
    }

    // Date
    $sess_date_raw = trim($data['session_date'] ?? '');
    if (empty($sess_date_raw)) { echo json_encode(['success' => false, 'error' => 'La date de la séance est obligatoire']); exit(); }
    $d_check = \DateTime::createFromFormat('Y-m-d', $sess_date_raw);
    if (!$d_check || $d_check->format('Y-m-d') !== $sess_date_raw) { echo json_encode(['success' => false, 'error' => 'Format de date invalide (AAAA-MM-JJ attendu)']); exit(); }
    $sess_date = $sess_date_raw;

    // Heures calculées depuis start_time/end_time
    $start_time_raw = trim($data['start_time'] ?? '');
    $end_time_raw   = trim($data['end_time']   ?? '');
    if (empty($start_time_raw) || empty($end_time_raw)) {
        echo json_encode(['success' => false, 'error' => 'Les heures de début et de fin sont obligatoires']);
        exit();
    }
    // Normaliser HH:MM → HH:MM:SS (input type="time" envoie HH:MM)
    if (preg_match('/^\d{2}:\d{2}$/', $start_time_raw)) $start_time_raw .= ':00';
    if (preg_match('/^\d{2}:\d{2}$/', $end_time_raw))   $end_time_raw   .= ':00';
    [$sh, $sm] = array_map('intval', explode(':', $start_time_raw));
    [$eh, $em] = array_map('intval', explode(':', $end_time_raw));
    $duration_mins = ($eh * 60 + $em) - ($sh * 60 + $sm);
    if ($duration_mins <= 0)  { echo json_encode(['success' => false, 'error' => 'L\'heure de fin doit être après l\'heure de début']); exit(); }
    if ($duration_mins < 30)  { echo json_encode(['success' => false, 'error' => 'La durée minimale est de 30 minutes']); exit(); }
    if ($duration_mins > 480) { echo json_encode(['success' => false, 'error' => 'La durée maximale est de 8 heures']); exit(); }
    $hours      = round($duration_mins / 60, 2);
    $start_time = $start_time_raw;
    $end_time   = $end_time_raw;

    // Auto-generate title if not provided (appel depuis le drawer appel)
    if (empty($title)) {
        $title = 'Séance du ' . date('d/m/Y', strtotime($sess_date));
    }

    // Ownership
    $chk = $conn->prepare("SELECT id FROM courses WHERE id = ? AND teacher_id = ?");
    $chk->bind_param("is", $cid, $user_id); $chk->execute();
    if (!$chk->get_result()->fetch_assoc()) { echo json_encode(['success' => false, 'error' => 'Accès refusé']); exit(); }
    $chk->close();
    if (!$cid) { echo json_encode(['success' => false, 'error' => 'Paramètres requis manquants']); exit(); }
    if (mb_strlen($title) > 255) { echo json_encode(['success' => false, 'error' => 'Titre trop long (255 caractères max)']); exit(); }

    $auto_chapter = false;
    if (!$chapter_id) {
        // Créer un chapitre temporaire automatiquement
        $ch_title_auto = 'Séance du ' . date('d/m/Y', strtotime($sess_date));
        $ord_res_auto  = $conn->query("SELECT COALESCE(MAX(order_num),0)+1 AS n FROM course_chapters WHERE course_id=$cid AND (academic_year = '$current_year' OR academic_year IS NULL)");
        $ord_auto      = $ord_res_auto ? (int)$ord_res_auto->fetch_assoc()['n'] : 1;
        $s_ch = $conn->prepare("INSERT INTO course_chapters (course_id, title, order_num, created_by, academic_year) VALUES (?,?,?,?,?)");
        $s_ch->bind_param("isiss", $cid, $ch_title_auto, $ord_auto, $user_id, $current_year);
        $s_ch->execute();
        $chapter_id = $conn->insert_id;
        $s_ch->close();
        $auto_chapter = true;
    } else {
        $chk2 = $conn->prepare("SELECT id, academic_year FROM course_chapters WHERE id = ? AND course_id = ?");
        $chk2->bind_param("ii", $chapter_id, $cid); $chk2->execute();
        $chk2_row = $chk2->get_result()->fetch_assoc();
        if (!$chk2_row) { echo json_encode(['success' => false, 'error' => 'Chapitre introuvable']); exit(); }
        $chk2->close();
        if ($chk2_row['academic_year'] !== null && $chk2_row['academic_year'] !== $current_year) {
            echo json_encode(['success' => false, 'error' => "Ce chapitre appartient à une année académique archivée."]);
            exit();
        }
    }

    $num_res = $conn->query("SELECT COALESCE(MAX(session_number),0)+1 AS n FROM course_sessions WHERE course_id=$cid AND (academic_year = '$current_year' OR academic_year IS NULL)");
    $num = $num_res ? (int)$num_res->fetch_assoc()['n'] : 1;
    $s = $conn->prepare("INSERT INTO course_sessions (chapter_id, course_id, session_number, title, description, hours, session_date, start_time, end_time, created_by, academic_year) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $s->bind_param("iiissdsssss", $chapter_id, $cid, $num, $title, $description, $hours, $sess_date, $start_time, $end_time, $user_id, $current_year);
    if ($s->execute()) {
        $tot_res = $conn->query("SELECT COALESCE(SUM(hours),0) AS t FROM course_sessions WHERE course_id=$cid AND (academic_year = '$current_year' OR academic_year IS NULL)");
        $tot = $tot_res ? $tot_res->fetch_assoc()['t'] : 0;
        $response_data = ['success' => true, 'id' => $conn->insert_id, 'session_number' => $num, 'total_hours' => $tot];
        if ($auto_chapter) {
            $response_data['auto_chapter'] = true;
            $response_data['message'] = 'Séance ajoutée. Pensez à rattacher cette séance à un chapitre dans la Progression.';
        }
        echo json_encode($response_data);
    } else echo json_encode(['success' => false, 'error' => 'Erreur lors de l\'insertion']);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'prog_edit_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data        = json_decode(file_get_contents('php://input'), true);
    $id          = intval($data['id']          ?? 0);
    $cid         = intval($data['course_id']   ?? 0);
    $title       = trim($data['title']         ?? '');
    $description = trim($data['description']   ?? '');

    $sess_date = null;
    if (!empty($data['session_date'])) {
        $d_check = \DateTime::createFromFormat('Y-m-d', $data['session_date']);
        if ($d_check && $d_check->format('Y-m-d') === $data['session_date']) $sess_date = $data['session_date'];
    }

    // Ownership + année
    $chk = $conn->prepare("SELECT c.id, cs.academic_year FROM courses c JOIN course_sessions cs ON cs.course_id = c.id WHERE cs.id = ? AND c.teacher_id = ?");
    $chk->bind_param("is", $id, $user_id); $chk->execute();
    $chk_row = $chk->get_result()->fetch_assoc();
    if (!$chk_row) { echo json_encode(['success' => false, 'error' => 'Accès refusé']); exit(); }
    $chk->close();
    if ($chk_row['academic_year'] !== null && $chk_row['academic_year'] !== $current_year) {
        echo json_encode(['success' => false, 'error' => "Cette séance appartient à une année académique archivée et n'est plus modifiable."]);
        exit();
    }
    if (!$id || !$title) { echo json_encode(['success' => false, 'error' => 'Titre requis']); exit(); }
    if (mb_strlen($title) > 255) { echo json_encode(['success' => false, 'error' => 'Titre trop long (255 caractères max)']); exit(); }

    // Heures : depuis start_time/end_time si fournis, sinon récupérer l'existant
    $start_time_raw = trim($data['start_time'] ?? '');
    $end_time_raw   = trim($data['end_time']   ?? '');
    $start_time = null;
    $end_time   = null;
    $hours      = null;

    if (!empty($start_time_raw) && !empty($end_time_raw)) {
        // Normaliser HH:MM → HH:MM:SS (input type="time" envoie HH:MM)
        if (preg_match('/^\d{2}:\d{2}$/', $start_time_raw)) $start_time_raw .= ':00';
        if (preg_match('/^\d{2}:\d{2}$/', $end_time_raw))   $end_time_raw   .= ':00';
        [$sh, $sm] = array_map('intval', explode(':', $start_time_raw));
        [$eh, $em] = array_map('intval', explode(':', $end_time_raw));
        $duration_mins = ($eh * 60 + $em) - ($sh * 60 + $sm);
        if ($duration_mins <= 0)  { echo json_encode(['success' => false, 'error' => 'L\'heure de fin doit être après l\'heure de début']); exit(); }
        if ($duration_mins < 30)  { echo json_encode(['success' => false, 'error' => 'La durée minimale est de 30 minutes']); exit(); }
        if ($duration_mins > 480) { echo json_encode(['success' => false, 'error' => 'La durée maximale est de 8 heures']); exit(); }
        $hours      = round($duration_mins / 60, 2);
        $start_time = $start_time_raw;
        $end_time   = $end_time_raw;
    } elseif (!empty($start_time_raw) || !empty($end_time_raw)) {
        echo json_encode(['success' => false, 'error' => 'Remplissez les deux heures (début ET fin) ou laissez-les vides']); exit();
    } else {
        // Conserver les heures existantes
        $stmt_get = $conn->prepare("SELECT hours FROM course_sessions WHERE id = ?");
        $stmt_get->bind_param("i", $id);
        $stmt_get->execute();
        $existing_h = $stmt_get->get_result()->fetch_assoc();
        $stmt_get->close();
        $hours = floatval($existing_h['hours'] ?? 1.5);
    }

    if ($start_time !== null) {
        $s = $conn->prepare("UPDATE course_sessions SET title=?, description=?, hours=?, session_date=?, start_time=?, end_time=? WHERE id=?");
        $s->bind_param("ssdsssi", $title, $description, $hours, $sess_date, $start_time, $end_time, $id);
    } else {
        $s = $conn->prepare("UPDATE course_sessions SET title=?, description=?, hours=?, session_date=? WHERE id=?");
        $s->bind_param("ssdsi", $title, $description, $hours, $sess_date, $id);
    }
    if ($s->execute()) {
        $tot_res = $conn->query("SELECT COALESCE(SUM(hours),0) AS t FROM course_sessions WHERE course_id=$cid AND (academic_year = '$current_year' OR academic_year IS NULL)");
        $tot = $tot_res ? $tot_res->fetch_assoc()['t'] : 0;
        echo json_encode(['success' => true, 'total_hours' => $tot]);
    } else echo json_encode(['success' => false, 'error' => 'Erreur lors de la modification']);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_session_delete' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $chk = $conn->prepare("SELECT c.id, cs.academic_year FROM courses c JOIN course_sessions cs ON cs.course_id = c.id WHERE cs.id = ? AND c.teacher_id = ?");
    $chk->bind_param("is", $id, $user_id); $chk->execute();
    $chk_row = $chk->get_result()->fetch_assoc();
    if (!$chk_row) { echo json_encode(['success' => false]); exit(); }
    $chk->close();
    if ($chk_row['academic_year'] !== null && $chk_row['academic_year'] !== $current_year) {
        echo json_encode(['needs_confirm' => false, 'archived' => true, 'error' => "Cette séance appartient à une année académique archivée et ne peut pas être supprimée."]);
        exit();
    }

    $stmt = $conn->prepare("SELECT attendance_session_id FROM course_sessions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['attendance_session_id'] === null) {
        echo json_encode(['needs_confirm' => false]);
    } else {
        echo json_encode([
            'needs_confirm'      => true,
            'sessions_with_call' => 1,
            'message'            => "Cette séance a un appel enregistré. Les données de présence associées seront conservées mais ne seront plus visibles depuis la progression.",
        ]);
    }
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'prog_delete_session' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id    = intval($_GET['id']);
    $cid   = intval($_GET['course_id'] ?? 0);
    $force = isset($_GET['force']) && $_GET['force'] === '1';
    // Vérifier ownership + année
    $chk = $conn->prepare("SELECT c.id, cs.academic_year FROM courses c JOIN course_sessions cs ON cs.course_id = c.id WHERE cs.id = ? AND c.teacher_id = ?");
    $chk->bind_param("is", $id, $user_id); $chk->execute();
    $chk_row = $chk->get_result()->fetch_assoc();
    if (!$chk_row) { echo json_encode(['success' => false]); exit(); }
    $chk->close();
    if ($chk_row['academic_year'] !== null && $chk_row['academic_year'] !== $current_year) {
        echo json_encode(['success' => false, 'error' => "Cette séance appartient à une année académique archivée et ne peut pas être supprimée."]);
        exit();
    }

    error_log("DELETE_SESSION: session_id=$id force=" . ($_GET['force'] ?? 'absent'));

    $inTx = false;
    try {
        $conn->begin_transaction();
        $inTx = true;

        if ($force) {
            // Délier l'attendance_session dont course_session_id pointe vers cette séance
            $upd = $conn->prepare("UPDATE attendance_sessions SET course_session_id = NULL WHERE course_session_id = ?");
            if (!$upd) {
                throw new \Exception("Préparation UPDATE échouée : " . $conn->error);
            }
            $upd->bind_param("i", $id);
            if (!$upd->execute()) {
                throw new \Exception("UPDATE attendance_sessions : " . $upd->error);
            }
            error_log("DELETE_SESSION: UPDATE ok, rows=" . $upd->affected_rows);
            $upd->close();
        }

        $s = $conn->prepare("DELETE FROM course_sessions WHERE id=?");
        $s->bind_param("i", $id);
        if (!$s->execute()) {
            throw new \Exception("DELETE course_sessions : " . $s->error);
        }
        $s->close();

        $conn->commit();
        $inTx = false;

        $tot_res = $conn->query("SELECT COALESCE(SUM(hours),0) AS t FROM course_sessions WHERE course_id=$cid AND (academic_year = '$current_year' OR academic_year IS NULL)");
        $tot = $tot_res ? $tot_res->fetch_assoc()['t'] : 0;
        echo json_encode(['success' => true, 'total_hours' => $tot]);

    } catch (\Throwable $e) {
        if ($inTx) {
            try { $conn->rollback(); } catch (\Throwable $re) {}
        }
        error_log("DELETE_SESSION: error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la suppression']);
    }
    exit();
}

// ============================================================
// HANDLERS AJAX DEVOIRS (PROFESSEUR)
// ============================================================
if (isset($_GET['action'])) {
    $assign_action   = $_GET['action'];
    $assign_cid      = intval($_GET['course_id'] ?? 0);
    $assign_year     = (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year']))
        ? $_GET['year'] : $current_year;
    $assign_year_esc = $conn->real_escape_string($assign_year);

    // ── get_assignments ───────────────────────────────────────
    if ($assign_action === 'get_assignments' && $assign_cid > 0) {
        ob_clean();
        header('Content-Type: application/json');
        if (!verify_teacher_course_access($conn, $user_id, $assign_cid)) {
            echo json_encode(['error' => 'Accès refusé', 'code' => 403]);
            exit;
        }
        $uid_esc = $conn->real_escape_string($user_id);
        $sql_ga  = "SELECT ca.*,
                        COUNT(DISTINCT asub.id) AS nb_rendus,
                        COUNT(DISTINCT u.id)    AS nb_etudiants
                    FROM course_assignments ca
                    JOIN courses c ON c.id = ca.course_id
                    LEFT JOIN assignment_submissions asub ON asub.assignment_id = ca.id
                    LEFT JOIN users u
                        ON u.role = 'student'
                        AND u.status = 'active'
                        AND JSON_CONTAINS(c.class_id, JSON_QUOTE(CAST(u.class_id AS CHAR)))
                    WHERE ca.course_id = $assign_cid
                      AND ca.teacher_id = '$uid_esc'
                      AND ca.annee_academique = '$assign_year_esc'
                    GROUP BY ca.id
                    ORDER BY ca.due_date DESC";
        $res_ga = $conn->query($sql_ga);
        $rows_ga = [];
        if ($res_ga) while ($r = $res_ga->fetch_assoc()) $rows_ga[] = $r;
        echo json_encode($rows_ga);
        exit;
    }

    // ── create_assignment ─────────────────────────────────────
    if ($assign_action === 'create_assignment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        ob_clean();
        header('Content-Type: application/json');
        if (!verify_teacher_course_access($conn, $user_id, $assign_cid)) {
            echo json_encode(['success' => false, 'error' => 'Accès refusé']);
            exit;
        }
        // Un devoir ne peut être créé que dans l'année académique courante.
        // (auparavant $assign_year — dérivé du paramètre GET 'year' — était utilisé
        // directement pour l'INSERT, ce qui permettait de créer un devoir dans une
        // année archivée simplement en naviguant le sélecteur d'année.)
        if ($assign_year !== $current_year) {
            echo json_encode(['success' => false, 'error' => "Impossible de créer un devoir dans une année académique archivée."]);
            exit;
        }
        $ca_title    = trim($_POST['title']    ?? '');
        $ca_due_date = trim($_POST['due_date'] ?? '');
        if (!$ca_title || !$ca_due_date) {
            echo json_encode(['success' => false, 'error' => 'Titre et date limite obligatoires']);
            exit;
        }
        $ca_desc      = trim($_POST['description'] ?? '');
        $ca_file_path = null;
        $ca_orig_name = null;

        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['file']['size'] > 10485760) {
                echo json_encode(['success' => false, 'error' => 'Fichier trop volumineux (max 10 MB)']);
                exit;
            }
            $ca_allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'rar'];
            $ca_ext     = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ca_ext, $ca_allowed)) {
                echo json_encode(['success' => false, 'error' => 'Extension non autorisée']);
                exit;
            }
            if (!is_dir('../uploads/assignments/')) mkdir('../uploads/assignments/', 0755, true);
            $ca_safe = uniqid('assign_', true) . '.' . $ca_ext;
            if (move_uploaded_file($_FILES['file']['tmp_name'], '../uploads/assignments/' . $ca_safe)) {
                $ca_file_path = $ca_safe;
                $ca_orig_name = basename($_FILES['file']['name']);
            }
        }

        $stmt_ca = $conn->prepare("INSERT INTO course_assignments (course_id, annee_academique, teacher_id, title, description, file_path, original_name, due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_ca->bind_param('isssssss', $assign_cid, $assign_year, $user_id, $ca_title, $ca_desc, $ca_file_path, $ca_orig_name, $ca_due_date);
        if ($stmt_ca->execute()) {
            echo json_encode(['success' => true, 'id' => $conn->insert_id]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        $stmt_ca->close();
        exit;
    }

    // ── get_assignment_submissions ────────────────────────────
    if ($assign_action === 'get_assignment_submissions') {
        ob_clean();
        header('Content-Type: application/json');
        $gas_id  = intval($_GET['assignment_id'] ?? 0);
        if (!$gas_id) { echo json_encode([]); exit; }
        $uid_esc = $conn->real_escape_string($user_id);
        $chk_gas = $conn->query("SELECT id FROM course_assignments WHERE id = $gas_id AND teacher_id = '$uid_esc'");
        if (!$chk_gas || $chk_gas->num_rows === 0) {
            echo json_encode(['error' => 'Accès refusé', 'code' => 403]);
            exit;
        }
        $sql_subs = "SELECT asub.*, u.name AS student_name, u.id AS student_id
                     FROM assignment_submissions asub
                     JOIN users u ON u.id = asub.student_id
                     WHERE asub.assignment_id = $gas_id
                     ORDER BY asub.submitted_at ASC";
        $res_subs = $conn->query($sql_subs);
        $rows_subs = [];
        if ($res_subs) while ($r = $res_subs->fetch_assoc()) $rows_subs[] = $r;
        echo json_encode($rows_subs);
        exit;
    }

    // ── delete_assignment ─────────────────────────────────────
    if ($assign_action === 'delete_assignment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        ob_clean();
        header('Content-Type: application/json');
        $da_id   = intval($_POST['assignment_id'] ?? 0);
        if (!$da_id) { echo json_encode(['success' => false]); exit; }
        $uid_esc = $conn->real_escape_string($user_id);
        $chk_da  = $conn->query("SELECT id, file_path, annee_academique FROM course_assignments WHERE id = $da_id AND teacher_id = '$uid_esc'");
        if (!$chk_da || $chk_da->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'Accès refusé']);
            exit;
        }
        $row_da = $chk_da->fetch_assoc();
        if (($row_da['annee_academique'] ?? $current_year) !== $current_year) {
            echo json_encode(['success' => false, 'error' => "Ce devoir appartient à une année académique archivée et ne peut pas être supprimé."]);
            exit;
        }
        if ($row_da['file_path'] && file_exists('../uploads/assignments/' . $row_da['file_path'])) {
            unlink('../uploads/assignments/' . $row_da['file_path']);
        }
        $subs_da = $conn->query("SELECT file_path FROM assignment_submissions WHERE assignment_id = $da_id");
        if ($subs_da) while ($s_da = $subs_da->fetch_assoc()) {
            $sub_src = '../uploads/assignments/submissions/' . $s_da['file_path'];
            if ($s_da['file_path'] && file_exists($sub_src)) unlink($sub_src);
        }
        $stmt_da = $conn->prepare("DELETE FROM course_assignments WHERE id = ? AND teacher_id = ?");
        $stmt_da->bind_param('is', $da_id, $user_id);
        $ok_da = $stmt_da->execute();
        $stmt_da->close();
        echo json_encode(['success' => $ok_da]);
        exit;
    }

    // ── download_all_submissions ──────────────────────────────
    if ($assign_action === 'download_all_submissions') {
        $dz_id   = intval($_GET['assignment_id'] ?? 0);
        if (!$dz_id) { http_response_code(400); exit; }
        $uid_esc = $conn->real_escape_string($user_id);
        $chk_dz  = $conn->query("SELECT title FROM course_assignments WHERE id = $dz_id AND teacher_id = '$uid_esc'");
        if (!$chk_dz || $chk_dz->num_rows === 0) { http_response_code(403); exit; }
        if (!class_exists('ZipArchive')) { http_response_code(500); echo 'ZipArchive non disponible'; exit; }
        $zip_dz  = new ZipArchive();
        $tmp_dz  = tempnam(sys_get_temp_dir(), 'assign_zip_');
        $zip_dz->open($tmp_dz, ZipArchive::OVERWRITE);
        $subs_dz = $conn->query("SELECT asub.file_path, asub.file_name, u.name AS student_name
                                 FROM assignment_submissions asub
                                 JOIN users u ON u.id = asub.student_id
                                 WHERE asub.assignment_id = $dz_id");
        if ($subs_dz) while ($s_dz = $subs_dz->fetch_assoc()) {
            $src_dz = '../uploads/assignments/submissions/' . $s_dz['file_path'];
            if (file_exists($src_dz)) {
                $name_clean = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $s_dz['student_name']);
                $zip_dz->addFile($src_dz, $name_clean . '_' . $s_dz['file_name']);
            }
        }
        $zip_dz->close();
        ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="assignment_' . $dz_id . '_rendus.zip"');
        header('Content-Length: ' . filesize($tmp_dz));
        readfile($tmp_dz);
        unlink($tmp_dz);
        exit;
    }
}

// ============================================================
// VÉRIFIER SI UN COURS EST SÉLECTIONNÉ
// ============================================================
if (isset($_GET['course_id'])) {
    $course_id = intval($_GET['course_id']);

    // Vérifier que le prof connecté est bien l'enseignant de ce cours
    if (($_SESSION['role'] ?? '') !== 'admin' && !verify_teacher_course_access($conn, $user_id, $course_id)) {
        header("Location: ../pages/dashboard.php?error=" . urlencode("Vous n'êtes pas l'enseignant de ce cours."));
        exit;
    }

    // Récupérer les informations du cours
    $sql = "SELECT name FROM courses WHERE id = ?";
    $stmt_course = $conn->prepare($sql);
    $stmt_course->bind_param("i", $course_id);
    $stmt_course->execute();
    $course_result = $stmt_course->get_result();
    
    if ($course_result->num_rows > 0) {
        $course = $course_result->fetch_assoc();
        $course_name = $course['name'];
    } else {
        echo "Cours non trouvé.";
        exit();
    }
    $stmt_course->close();

    // Récupérer les informations de l'utilisateur connecté
    $sql_user = "SELECT role, name FROM users WHERE id = ?";
    $stmt_user = $conn->prepare($sql_user);
    $stmt_user->bind_param("s", $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    $user_info = $result_user->fetch_assoc();
    $user_role = $user_info['role'];
    $user_name = $user_info['name'];
    $stmt_user->close();

    // Année de filtrage : paramètre GET ou année courante par défaut.
    // Calculée ICI (avant le traitement du POST) car l'envoi de message,
    // l'upload de documents et la suppression en dépendent désormais :
    // aucune écriture n'est autorisée en dehors de l'année académique courante.
    $filter_year = (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year']))
        ? $_GET['year'] : $current_year;
    $is_archived_year = ($filter_year !== $current_year);

    // ============================================================
    // GESTION DES REQUÊTES POST
    // ============================================================
    $archived_write_blocked = false;
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $discussion_id = null;
        $email_sent = false;

        // Blocage explicite : aucune écriture (message, document) n'est permise
        // quand on consulte une année académique archivée.
        if ($is_archived_year && (isset($_POST['message']) || isset($_FILES['documents']))) {
            $archived_write_blocked = true;
        }

        // SUPPRESSION DE MESSAGE OU DE DOCUMENT
        if (isset($_POST['delete_id'])) {
            $delete_id = intval($_POST['delete_id']);
            $delete_type = $_POST['delete_type'];

            if ($delete_type === 'message') {
                // Vérifier que ce message n'appartient pas à une année académique archivée
                // (protection supplémentaire ; en pratique le bouton "Supprimer" n'apparaît déjà
                // que dans les 20 minutes suivant l'envoi, ce qui exclut les années passées).
                $chk_msg_year = $conn->prepare("SELECT academic_year FROM discussions WHERE id = ? AND sender_id = ?");
                $chk_msg_year->bind_param("is", $delete_id, $user_id);
                $chk_msg_year->execute();
                $msg_year_row = $chk_msg_year->get_result()->fetch_assoc();
                $chk_msg_year->close();

                if ($msg_year_row && $msg_year_row['academic_year'] === $current_year) {
                    // Supprimer d'abord les documents associés
                    $sql_del_docs = "DELETE FROM documents WHERE discussion_id = ? AND uploaded_by = ?";
                    $stmt_del_docs = $conn->prepare($sql_del_docs);
                    $stmt_del_docs->bind_param("is", $delete_id, $user_id);
                    $stmt_del_docs->execute();
                    $stmt_del_docs->close();

                    // Puis supprimer le message
                    $sql_del_msg = "DELETE FROM discussions WHERE id = ? AND sender_id = ?";
                    $stmt_del_msg = $conn->prepare($sql_del_msg);
                    $stmt_del_msg->bind_param("is", $delete_id, $user_id);
                    $stmt_del_msg->execute();
                    $stmt_del_msg->close();
                }
            } else if ($delete_type === 'document') {
                // Même protection pour la suppression de document (via le message parent)
                $chk_doc_year = $conn->prepare("
                    SELECT d.academic_year
                    FROM documents doc
                    JOIN discussions d ON d.id = doc.discussion_id
                    WHERE doc.id = ? AND doc.uploaded_by = ?
                ");
                $chk_doc_year->bind_param("is", $delete_id, $user_id);
                $chk_doc_year->execute();
                $doc_year_row = $chk_doc_year->get_result()->fetch_assoc();
                $chk_doc_year->close();

                if ($doc_year_row && $doc_year_row['academic_year'] === $current_year) {
                    // Supprimer le document
                    $sql_del_doc = "DELETE FROM documents WHERE id = ? AND uploaded_by = ?";
                    $stmt_del_doc = $conn->prepare($sql_del_doc);
                    $stmt_del_doc->bind_param("is", $delete_id, $user_id);
                    $stmt_del_doc->execute();
                    $stmt_del_doc->close();
                }
            }
        }

        // AJOUT DE MESSAGE TEXTE
        if (!$is_archived_year && isset($_POST['message']) && !empty(trim($_POST['message']))) {
            $message = trim($_POST['message']);
            $sql_insert = "INSERT INTO discussions (course_id, sender_id, message, academic_year, semester, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->bind_param("isssi", $course_id, $user_id, $message, $current_year, $current_semester);
            $stmt_insert->execute();
            $discussion_id = $conn->insert_id;
            $stmt_insert->close();
            
            // Envoyer email si c'est un enseignant
            if ($user_role === 'teacher' && !$email_sent) {
                error_log("🔥 DÉBUT ENVOI EMAIL - Cours: $course_name (ID: $course_id) - Enseignant: $user_name");
                
                $email_result = sendEmailToStudents($conn, $course_id, $course_name, $user_name, 'message', $message);
                $email_sent = true;
                
                error_log("🔥 RÉSULTAT ENVOI EMAIL: " . ($email_result ? 'SUCCÈS' : 'ÉCHEC'));
            }
        }

        // AJOUT DE DOCUMENTS
        if (!$is_archived_year && isset($_FILES['documents'])) {
            $fileCount      = count($_FILES['documents']['name']);
            $files_uploaded = false;

            $allowed_extensions = [
                'pdf', 'doc', 'docx', 'xls', 'xlsx',
                'ppt', 'pptx', 'txt', 'csv',
                'jpg', 'jpeg', 'png', 'gif', 'webp',
                'mp4', 'mp3', 'zip', 'rar',
            ];

            $allowed_mimes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain', 'text/csv',
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'video/mp4', 'audio/mpeg',
                'application/zip',
                'application/x-rar-compressed', 'application/x-rar',
            ];

            $rejected_files = [];

            for ($i = 0; $i < $fileCount; $i++) {
                if ($_FILES['documents']['error'][$i] !== UPLOAD_ERR_OK) continue;

                $original_name = basename($_FILES['documents']['name'][$i]);

                // Taille max 40 Mo
                if ($_FILES['documents']['size'][$i] > 40000000) {
                    echo "Désolé, le fichier " . htmlspecialchars($original_name) . " est trop volumineux.";
                    continue;
                }

                // Whitelist d'extensions
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_extensions)) {
                    $rejected_files[] = $original_name;
                    error_log("Upload rejeté (extension interdite): $original_name");
                    continue;
                }

                // Vérification du MIME type réel
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['documents']['tmp_name'][$i]);
                finfo_close($finfo);
                if (!in_array($mime, $allowed_mimes)) {
                    $rejected_files[] = $original_name;
                    error_log("Upload rejeté (MIME interdit: $mime): $original_name");
                    continue;
                }

                // Nom de fichier unique pour le stockage
                $safe_name   = uniqid('doc_', true) . '.' . $ext;
                $target_dir  = "../uploads/";
                $target_file = $target_dir . $safe_name;

                if (move_uploaded_file($_FILES['documents']['tmp_name'][$i], $target_file)) {
                    echo "Le fichier " . htmlspecialchars($original_name) . " a été téléchargé avec succès.";

                    if ($discussion_id === null) {
                        $sql_disc = "INSERT INTO discussions (course_id, sender_id, message, academic_year, semester, created_at) VALUES (?, ?, '', ?, ?, NOW())";
                        $stmt_disc = $conn->prepare($sql_disc);
                        $stmt_disc->bind_param("issi", $course_id, $user_id, $current_year, $current_semester);
                        $stmt_disc->execute();
                        $discussion_id = $conn->insert_id;
                        $stmt_disc->close();
                    }

                    $is_teacher = ($user_role === 'teacher') ? 1 : 0;
                    $sql_doc    = "INSERT INTO documents (discussion_id, file_path, original_name, uploaded_by, is_teacher) VALUES (?, ?, ?, ?, ?)";
                    $stmt_doc   = $conn->prepare($sql_doc);
                    $stmt_doc->bind_param("isssi", $discussion_id, $safe_name, $original_name, $user_id, $is_teacher);
                    $stmt_doc->execute();
                    $stmt_doc->close();

                    $files_uploaded = true;
                } else {
                    echo "Désolé, une erreur est survenue lors du téléchargement de " . htmlspecialchars($original_name) . ".";
                }
            }

            if (!empty($rejected_files)) {
                $rej_list = implode(', ', array_map('htmlspecialchars', $rejected_files));
                echo "Fichier(s) rejeté(s) — type non autorisé : $rej_list. "
                   . "Types acceptés : PDF, Word, Excel, PowerPoint, images, vidéos, ZIP.";
            }

            // Envoyer email si c'est un enseignant et des fichiers ont été uploadés
            if ($user_role === 'teacher' && $files_uploaded && !$email_sent) {
                error_log("🔥 DÉBUT ENVOI EMAIL DOCUMENTS - Cours: $course_name (ID: $course_id) - Enseignant: $user_name");
                $email_result = sendEmailToStudents($conn, $course_id, $course_name, $user_name, 'document(s)', 'Un ou plusieurs documents ont été ajoutés au cours.');
                $email_sent = true;
                error_log("🔥 RÉSULTAT ENVOI EMAIL DOCUMENTS: " . ($email_result ? 'SUCCÈS' : 'ÉCHEC'));
            }
        }
    }

    // Années disponibles pour le sélecteur d'archives
    $stmt_yrs = $conn->prepare(
        "SELECT DISTINCT academic_year AS yr FROM discussions
         WHERE course_id = ? AND academic_year IS NOT NULL
         ORDER BY yr DESC"
    );
    $stmt_yrs->bind_param("i", $course_id);
    $stmt_yrs->execute();
    $res_yrs       = $stmt_yrs->get_result();
    $available_years = [];
    while ($yr = $res_yrs->fetch_assoc()) {
        $available_years[] = $yr['yr'];
    }
    if (!in_array($current_year, $available_years)) {
        array_unshift($available_years, $current_year);
    }
    $stmt_yrs->close();

    // RÉCUPÉRATION DES MESSAGES DE LA DISCUSSION filtrés par année
    $sql_messages = "
        SELECT d.id AS discussion_id, d.sender_id, d.message, d.created_at, u.name, u.avatar,
               doc.id AS document_id, doc.file_path, COALESCE(doc.original_name, doc.file_path) AS original_name
        FROM discussions d
        JOIN users u ON d.sender_id = u.id
        LEFT JOIN documents doc ON d.id = doc.discussion_id
        WHERE d.course_id = ? AND d.academic_year = ?
        ORDER BY d.created_at ASC
    ";
    $stmt_messages = $conn->prepare($sql_messages);
    $stmt_messages->bind_param("is", $course_id, $filter_year);
    $stmt_messages->execute();
    $messages = $stmt_messages->get_result();
} else {
    echo "Aucun cours sélectionné.";
    exit();
}

// RÉCUPÉRATION DES DOCUMENTS filtrés par année académique
$sql_documents = "
    SELECT doc.id AS document_id, doc.file_path, COALESCE(doc.original_name, doc.file_path) AS original_name,
           doc.uploaded_by, u.name AS uploader_name, u.role
    FROM documents doc
    JOIN users u ON doc.uploaded_by = u.id
    WHERE doc.discussion_id IN (
        SELECT id FROM discussions WHERE course_id = ? AND academic_year = ?
    )
    ORDER BY u.role ASC
";
$stmt_documents = $conn->prepare($sql_documents);
$stmt_documents->bind_param("is", $course_id, $filter_year);
$stmt_documents->execute();
$documents = $stmt_documents->get_result();

$prof_documents = [];
$student_documents = [];

while ($doc = $documents->fetch_assoc()) {
    if ($doc['role'] === 'teacher') {
        $prof_documents[] = $doc;
    } else {
        $student_documents[] = $doc;
    }
}

$stmt_documents->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
    <script>
    (function() {
        var token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!token) return;
        window.CSRF_TOKEN = token;
        var originalFetch = window.fetch;
        window.fetch = function(url, options) {
            options = options || {};
            var method = (options.method || 'GET').toUpperCase();
            if (method === 'GET') return originalFetch(url, options);
            options.headers = options.headers || {};
            if (options.headers instanceof Headers) {
                options.headers.set('X-CSRF-Token', token);
            } else {
                options.headers['X-CSRF-Token'] = token;
            }
            return originalFetch(url, options);
        };
    })();
    </script>
    <title>Discussion - <?php echo htmlspecialchars($course_name); ?></title>
    <link rel="stylesheet" href="manage_discussions.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-bg:    #051e34;
            --secondary-bg:  #0c2d48;
            --accent-color:  #039be5;
            --text-light:    #ffffff;
            --error-color:   #e74c3c;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --border-color:  rgba(255, 255, 255, 0.1);
            --card-bg:       rgba(255, 255, 255, 0.05);
            --hover-color:   rgba(3, 155, 229, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Google Sans', Arial, sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Conteneur principal */
        .page-container {
            flex: 1 0 auto;
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 80px); /* Hauteur totale moins hauteur du footer */
        }

        /* Zone de discussion */
        .discussion {
            flex: 1;
            max-width: 1000px;
            width: 90%;
            margin: 20px auto;
            background: var(--card-bg);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            padding: 20px;
        }

        /* Message grid */
        .message-grid {
            max-height: 500px;
            overflow-y: auto;
            margin-bottom: 20px;
            padding-right: 10px;
        }

        /* Notification d'envoi d'email */
        .email-notification {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid #4CAF50;
            color: #4CAF50;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .email-notification i {
            font-size: 16px;
        }

        /* Modification de la largeur maximale de la discussion */
        .discussion {
            max-width: 1000px;
            width: 90%;
            margin: 20px auto;
            background: var(--card-bg);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            padding: 20px;
            position: relative;
            flex: 1 0 auto;
        }

        /* Ajustement du conteneur principal pour le footer */
        main {
            flex: 1 0 auto;
            padding-bottom: 60px;
            min-height: calc(100vh - 60px);
        }

        /* Style du footer modifié */
        footer {
            flex-shrink: 0;
            margin-top: auto;
            background: var(--secondary-bg);
            padding: 20px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            width: 100%;
            position: relative;
            bottom: 0;
        }

        /* Header Styles */
        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            margin-bottom: 20px;
        }

        header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(to right, transparent, #FFD700, #FFC200, #FFD700, transparent);
            animation: shimmer 2s infinite linear;
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            position: relative;
            overflow: hidden;
        }

        .header-content h1 {
            font-size: 24px;
            color: #FFFFFF;
            margin: 0 0 20px 0;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* Navigation Styles */
        nav {
            display: flex;
            justify-content: center;
        }

        nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            gap: 20px;
            justify-content: center;
        }

        nav a {
            color: var(--text-light);
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 5px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
        }

        nav a::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--card-bg);
            transform: scaleX(0);
            transition: transform 0.3s ease;
            transform-origin: right;
        }

        nav a:hover::before {
            transform: scaleX(1);
            transform-origin: left;
        }

        nav a:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        /* Floating Icons */
        .floating-icons {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            z-index: 1;
        }

        .floating-icon {
            position: absolute;
            font-size: 20px;
            color: rgba(255, 255, 255, 0.4);
            opacity: 0;
            animation: floatIcon 3s ease-in-out infinite;
        }

        .floating-icon:nth-child(1) { left: 10%; top: 20%; animation-delay: 0s; }
        .floating-icon:nth-child(2) { left: 30%; top: 60%; animation-delay: 0.5s; }
        .floating-icon:nth-child(3) { left: 50%; top: 30%; animation-delay: 1s; }
        .floating-icon:nth-child(4) { left: 70%; top: 50%; animation-delay: 1.5s; }
        .floating-icon:nth-child(5) { left: 90%; top: 40%; animation-delay: 2s; }

        /* Discussion Styles */
        .discussion {
            max-width: 800px;
            margin: 20px auto;
            background: var(--card-bg);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            padding: 20px;
            position: relative;
        }

        .message-grid {
            max-height: 500px;
            overflow-y: auto;
            margin-bottom: 20px;
            padding-right: 10px;
        }

        /* Modifier le style des cartes de messages */
        .message-card {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            width: 80%;
        }

        /* Style pour les messages des autres - aligné à gauche */
        .message-card {
            margin-right: auto;
        }

        /* Style pour les messages de l'utilisateur connecté - aligné à droite */
        .message-card.own {
            background: var(--accent-color);
            color: #FFFFFF;
            margin-left: auto;
            margin-right: 0;
            flex-direction: row-reverse;
        }

        .message-card.own .message-info h4 { color: #FFFFFF; }
        .message-card.own .message-info p { color: #FFFFFF; }
        .message-card.own .message-info em { color: rgba(255,255,255,0.7); }

        /* Ajuster les marges du contenu du message en fonction du propriétaire */
        .message-card .message-info {
            margin-left: 10px;
            margin-right: 0;
        }

        .message-card.own .message-info {
            margin-left: 0;
            margin-right: 10px;
            text-align: right;
        }

        /* Ajuster l'alignement des détails du fichier pour les messages de l'utilisateur */
        .message-card.own .file-preview {
            text-align: right;
        }

        .message-card.own .doc-info {
            align-items: flex-end;
        }

        /* Ajuster l'alignement des boutons d'action pour les messages de l'utilisateur */
        .message-card.own .action-buttons {
            justify-content: flex-end;
        }

        .message-info {
            margin-left: 10px;
            max-width: 70%;
        }

        .message-info h4 {
            margin: 0;
            font-size: 14px;
            font-weight: bold;
            color: var(--accent-color);
        }

        .message-info em {
            font-size: 12px;
            color: #A6A6A6;
        }

        .message-info p {
            margin: 5px 0;
            color: var(--text-light);
        }

        /* Files and Documents Styles */
        .file-preview {
            margin-top: 5px;
        }

        .file-input {
            margin-bottom: 10px;
            background: var(--card-bg);
            color: var(--accent-color);
            border: 1px solid var(--accent-color);
            padding: 10px;
            border-radius: 5px;
            text-align: center;
            transition: background-color 0.3s;
        }

        .file-input:hover {
            background-color: rgba(3, 155, 229, 0.1);
        }

        .file-input input {
            display: none;
        }

        .upload-docs {
            display: inline-block;
            background: var(--card-bg);
            color: var(--accent-color);
            border: 1px solid var(--accent-color);
            padding: 10px 20px;
            border-radius: 50px;
            cursor: pointer;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .upload-docs:hover {
            background: rgba(3, 155, 229, 0.1);
            border-color: var(--accent-color);
            color: var(--accent-color);
            transform: translateY(-2px);
        }

        /* Send Message Section */
        .send-message {
            margin-top: 20px;
        }

        .send-message textarea {
            width: 100%;
            height: 100px;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-light);
            margin-bottom: 10px;
            resize: vertical;
        }

        button {
            padding: 10px 20px;
            background: var(--accent-color);
            color: var(--text-light);
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        button:hover {
            background: #0288d1;
            transform: translateY(-2px);
        }

        /* Documents Drawer — ORIGINAL INTACT */
        .documents-btn {
            position: fixed;
            right: 20px;
            bottom: 20px;
            padding: 12px 25px;
            background: linear-gradient(135deg, var(--accent-color), #0288d1);
            color: var(--text-light);
            border: none;
            border-radius: 50px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(0, 176, 240, 0.3);
            transition: all 0.3s ease;
            z-index: 100;
        }

        .documents-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 176, 240, 0.4);
        }

        /* INSERTION 2 — Repositionner le bouton documents pour faire de la place au bouton appel */
        .documents-btn {
            bottom: 20px;
        }

        /* NOUVEAU — Bouton Faire l'appel (visible uniquement pour les profs via PHP) */
        .attendance-btn {
            position: fixed;
            right: 20px;
            bottom: 80px;
            padding: 12px 25px;
            background: linear-gradient(135deg, #00c853, #00897b);
            color: var(--text-light);
            border: none;
            border-radius: 50px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(0, 200, 83, 0.3);
            transition: all 0.3s ease;
            z-index: 100;
        }

        .attendance-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 200, 83, 0.4);
        }

        /* Bouton Progression (visible uniquement pour les profs) */
        .progress-btn {
            position: fixed;
            right: 20px;
            bottom: 140px;
            padding: 12px 25px;
            background: linear-gradient(135deg, #1a6b3c, #22a05a);
            color: var(--text-light);
            border: none;
            border-radius: 50px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(34, 160, 90, 0.3);
            transition: all 0.3s ease;
            z-index: 100;
        }

        .progress-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 160, 90, 0.4);
        }

        .documents-drawer {
            position: fixed;
            top: 0;
            right: -400px;
            width: 380px;
            height: 100vh;
            background: var(--secondary-bg);
            box-shadow: -4px 0 15px rgba(0, 0, 0, 0.3);
            transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .documents-drawer.open {
            right: 0;
        }

        .drawer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: rgba(3, 155, 229, 0.1);
            border-bottom: 2px solid var(--accent-color);
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }

        .drawer-header h3 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--accent-color);
            margin: 0;
        }

        .close-drawer {
            background: transparent;
            border: none;
            color: var(--text-light);
            font-size: 24px;
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .close-drawer:hover {
            background: rgba(0, 0, 0, 0.06);
            transform: rotate(90deg);
        }

        .drawer-content {
            padding: 20px;
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: 0;
            -webkit-overflow-scrolling: touch;
        }

        .drawer-content h4 {
            color: var(--accent-color);
            font-size: 1.2rem;
            margin: 20px 0 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .document-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            margin-bottom: 10px;
            background: var(--card-bg);
            border-radius: 10px;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }

        .document-item:hover {
            background: rgba(3, 155, 229, 0.1);
            transform: translateX(-5px);
            border-color: var(--accent-color);
        }

        .doc-icon {
            min-width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            color: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .doc-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .doc-info a {
            color: var(--text-light);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .doc-info a:hover {
            color: var(--accent-color);
        }

        .doc-details {
            font-size: 12px;
            color: #A6A6A6;
        }

        /* Document Type Colors */
        .doc-icon.pdf { background: linear-gradient(135deg, #ff5722, #f44336); }
        .doc-icon.doc, .doc-icon.docx { background: linear-gradient(135deg, #2196f3, #1976d2); }
        .doc-icon.xls, .doc-icon.xlsx { background: linear-gradient(135deg, #4caf50, #388e3c); }
        .doc-icon.jpg, .doc-icon.png, .doc-icon.gif { background: linear-gradient(135deg, #9c27b0, #7b1fa2); }
        .doc-icon.ppt, .doc-icon.pptx { background: linear-gradient(135deg, #ff9800, #f57c00); }

        .doc-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .doc-info a {
            color: var(--text-light);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: color 0.3s;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doc-info a:hover {
            color: var(--accent-color);
        }

        .doc-details {
            font-size: 0.8rem;
            color: #A6A6A6;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .no-docs {
            text-align: center;
            padding: 30px;
            color: #A6A6A6;
            font-style: italic;
            background: var(--card-bg);
            border-radius: 10px;
            margin: 10px 0;
        }

        /* Barre de défilement personnalisée */
        .drawer-content::-webkit-scrollbar {
            width: 6px;
        }

        .drawer-content::-webkit-scrollbar-track {
            background: var(--card-bg);
        }

        .drawer-content::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 3px;
        }

        /* Responsive design */
        @media (max-width: 1024px) {
            .discussion {
                width: 95%;
                margin: 10px auto;
            }
        }

        @media (max-width: 768px) {
            .discussion {
                width: 98%;
                margin: 5px auto;
                padding: 15px;
            }
            
            .documents-drawer {
                width: 100%;
                right: -100%;
            }
            
            .document-item {
                padding: 12px;
            }
            
            .doc-icon {
                min-width: 40px;
                height: 40px;
                font-size: 12px;
            }

            /* NOUVEAU responsive */
            .attendance-drawer,
            .progress-drawer {
                width: 100%;
                right: -100%;
                height: 100dvh;
            }
        }

        /* Footer Styles */
        .footer {
            width: 100%;
            background: var(--secondary-bg);
            padding: 25px 0;
            text-align: center;
            margin-top: auto;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 -4px 6px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(to right, transparent, #FFD700, #FFC200, #FFD700, transparent);
            animation: shimmer 2s infinite linear;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
            padding: 0 20px;
        }

        .footer-logo {
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s ease;
        }

        .footer-text {
            color: var(--text-light);
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .footer-social {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .social-icon {
            color: var(--text-light);
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .social-icon:hover {
            background: #0288d1;
            transform: translateY(-3px);
        }

        .footer-copyright {
            margin-top: 15px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }

        .footer-brand {
            color: #FFFFFF;
            font-style: italic;
            font-weight: 500;
        }

        .footer-brand:hover {
            color: #4CAF50;
        }

        @media (max-width: 768px) {
            .footer-content {
                flex-direction: column;
                gap: 20px;
            }
        }

        .floating-icons {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            z-index: 1;
        }

        .floating-icon {
            position: absolute;
            font-size: 20px;
            color: rgba(255, 255, 255, 0.4);
            opacity: 0;
            animation: floatIcon 3s ease-in-out infinite;
        }

        .floating-icon:nth-child(1) {
            left: 10%;
            top: 20%;
            animation-delay: 0s;
        }
        .floating-icon:nth-child(2) { 
            left: 30%; 
            top: 60%; 
            animation-delay: 0.5s; 
        }
        .floating-icon:nth-child(3) { 
            left: 50%; 
            top: 30%; 
            animation-delay: 1s; 
        }
        .floating-icon:nth-child(4) { 
            left: 70%; 
            top: 50%; 
            animation-delay: 1.5s; 
        }
        .floating-icon:nth-child(5) { 
            left: 90%; 
            top: 40%; 
            animation-delay: 2s; 
        }

        @keyframes floatIcon {
            0% { transform: translateY(100%); opacity: 0; }
            50% { opacity: 0.3; }
            100% { transform: translateY(-100%); opacity: 0; }
        }

        /* Styles pour la prévisualisation des images */
        .image-preview {
            margin: 10px 0;
            position: relative;
        }

        .thumbnail-image {
            max-width: 200px;
            max-height: 150px;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .thumbnail-image:hover {
            transform: scale(1.05);
        }

        .image-actions {
            margin-top: 5px;
        }

        .image-actions a {
            color: var(--accent-color);
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .image-actions a:hover {
            text-decoration: underline;
        }

        /* Modal pour l'affichage en plein écran */
        .image-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            z-index: 1001;
            padding: 20px;
            box-sizing: border-box;
            overflow: auto;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            max-width: 90%;
            max-height: 90%;
            display: block;
            margin: auto;
        }

        .close-modal {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 30px;
            cursor: pointer;
            z-index: 1002;
            background: rgba(0, 0, 0, 0.5);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:hover {
            color: var(--accent-color);
            transform: scale(1.1);
        }

        /* Styles pour l'aperçu des fichiers avant envoi */
        .file-preview-container {
            margin: 10px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .file-preview-item {
            position: relative;
            border-radius: 8px;
            background: var(--card-bg);
            padding: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            max-width: 300px;
        }

        .file-preview-item .preview-icon {
            min-width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
        }

        .file-preview-item .preview-info {
            flex: 1;
            overflow: hidden;
        }

        .file-preview-item .preview-info .filename {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 14px;
            color: var(--text-light);
        }

        .file-preview-item .preview-info .filesize {
            font-size: 12px;
            color: #A6A6A6;
        }

        .file-preview-item .remove-file {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 20px;
            height: 20px;
            background: rgba(220, 53, 69, 0.8);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 10px;
            transition: all 0.3s ease;
        }

        .file-preview-item .remove-file:hover {
            background: rgba(220, 53, 69, 1);
            transform: scale(1.1);
        }

        .file-preview-image {
            max-width: 80px;
            max-height: 60px;
            border-radius: 4px;
        }

        /* ============================================================
           CSS DRAWER PRÉSENCES (ORIGINAL)
        ============================================================ */
        .attendance-drawer {
            position: fixed;
            top: 0;
            right: -520px;
            width: 500px;
            height: 100vh;
            background: linear-gradient(160deg, #03111f 0%, #061a2e 50%, #042a1e 100%);
            box-shadow: -4px 0 30px rgba(0, 0, 0, 0.5);
            transition: right 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1001;
            border-left: 1px solid rgba(0, 200, 83, 0.2);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .attendance-drawer.open {
            right: 0;
        }

        .att-drawer-header {
            padding: 20px 24px;
            background: rgba(0, 200, 83, 0.08);
            border-bottom: 2px solid rgba(0, 200, 83, 0.4);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .att-title { display: flex; flex-direction: column; gap: 4px; }
        .att-title h3 { font-size: 1.3rem; font-weight: 700; color: #00e676; margin: 0; display: flex; align-items: center; gap: 10px; }
        .att-subtitle { font-size: 0.78rem; color: rgba(255,255,255,0.5); }
        .att-date-badge {
            background: rgba(0,200,83,0.15); border: 1px solid rgba(0,200,83,0.3);
            color: #00e676; padding: 4px 12px; border-radius: 20px; font-size: 0.78rem; font-weight: 600;
        }
        .close-att-drawer {
            background: transparent; border: 1px solid rgba(255,255,255,0.15);
            color: var(--text-light); font-size: 18px; cursor: pointer; width: 36px; height: 36px;
            border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all 0.3s;
        }
        .close-att-drawer:hover { background: rgba(255,255,255,0.1); transform: rotate(90deg); }

        .att-stats-bar {
            display: flex; gap: 8px; padding: 14px 24px;
            background: rgba(0,0,0,0.2); border-bottom: 1px solid rgba(255,255,255,0.05);
            flex-shrink: 0; flex-wrap: wrap;
        }
        .att-stat { flex: 1; min-width: 60px; background: rgba(255,255,255,0.04); border-radius: 10px; padding: 10px 8px; text-align: center; border: 1px solid rgba(255,255,255,0.06); }
        .att-stat .stat-num { font-size: 1.5rem; font-weight: 700; line-height: 1; }
        .att-stat .stat-label { font-size: 0.65rem; color: rgba(255,255,255,0.5); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px; }
        .att-stat.s-present .stat-num { color: #00e676; }
        .att-stat.s-absent  .stat-num { color: #ff5252; }
        .att-stat.s-late    .stat-num { color: #ffab40; }
        .att-stat.s-justified .stat-num { color: #64b5f6; }
        .att-stat.s-total   .stat-num { color: rgba(255,255,255,0.7); }

        .att-toolbar {
            padding: 14px 24px; display: flex; gap: 10px; align-items: center;
            flex-shrink: 0; border-bottom: 1px solid rgba(255,255,255,0.05); flex-wrap: wrap;
        }
        .att-search {
            flex: 1; min-width: 140px; background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 8px 14px;
            color: var(--text-light); font-size: 0.85rem; outline: none; transition: border-color 0.2s;
        }
        .att-search:focus { border-color: rgba(0,200,83,0.5); }
        .att-search::placeholder { color: rgba(255,255,255,0.35); }
        .att-quick-btn { padding: 7px 14px; border-radius: 8px; font-size: 0.78rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; }
        .att-quick-btn.all-present { background: rgba(0,200,83,0.15); border-color: rgba(0,200,83,0.3); color: #00e676; }
        .att-quick-btn.all-present:hover { background: rgba(0,200,83,0.25); transform: none; }
        .att-quick-btn.all-absent  { background: rgba(255,82,82,0.12); border-color: rgba(255,82,82,0.3); color: #ff5252; }
        .att-quick-btn.all-absent:hover  { background: rgba(255,82,82,0.22); transform: none; }

        .att-session-banner {
            margin: 14px 24px 0; padding: 12px 16px; border-radius: 10px;
            font-size: 0.82rem; display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        }
        .att-session-banner.no-session  { background: rgba(255,171,64,0.1); border: 1px solid rgba(255,171,64,0.3); color: #ffab40; }
        .att-session-banner.has-session { background: rgba(0,200,83,0.08);  border: 1px solid rgba(0,200,83,0.2);  color: #69f0ae; }
        .att-session-banner .create-session-btn {
            padding: 6px 14px; font-size: 0.78rem; border-radius: 6px;
            background: rgba(255,171,64,0.2); border: 1px solid rgba(255,171,64,0.4);
            color: #ffab40; cursor: pointer; margin-left: auto; transition: all 0.2s;
        }
        .att-session-banner .create-session-btn:hover { background: rgba(255,171,64,0.35); transform: none; }

        /* Session picker dans le drawer Appel */
        .att-sessions-picker { margin: 14px 24px 0; flex-shrink: 0; }
        .att-sessions-title { font-size: 0.7rem; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 8px; }
        .att-sess-option {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; border-radius: 9px; cursor: pointer;
            border: 1px solid rgba(255,255,255,0.07);
            background: rgba(255,255,255,0.04); margin-bottom: 6px; transition: all 0.2s;
        }
        .att-sess-option:last-of-type { margin-bottom: 0; }
        .att-sess-option.selected { border-color: rgba(0,200,83,0.45); background: rgba(0,200,83,0.1); }
        .att-sess-option.done { border-color: rgba(100,181,246,0.3); background: rgba(100,181,246,0.06); }
        .att-sess-option:hover:not(.selected) { background: rgba(255,255,255,0.07); }
        .att-sess-radio { accent-color: #00e676; width: 15px; height: 15px; flex-shrink: 0; cursor: pointer; }
        .att-sess-time { font-size: 0.88rem; font-weight: 600; color: #fff; }
        .att-sess-chapter { font-size: 0.72rem; color: rgba(255,255,255,0.45); }
        .att-sess-hours { font-size: 0.75rem; color: #ffab40; font-weight: 600; margin-left: auto; white-space: nowrap; }
        .att-sess-done-badge { font-size: 0.65rem; color: #64b5f6; margin-left: 4px; }
        .att-no-sessions-msg { font-size: 0.82rem; color: rgba(255,82,82,0.85); line-height: 1.5; }
        .att-no-sessions-link { color: #64b5f6; text-decoration: none; }
        .att-no-sessions-link:hover { text-decoration: underline; }
        .att-sess-hint { font-size: 0.73rem; color: rgba(255,171,64,0.65); margin-top: 8px; }

        /* Affichage plage horaire dans la progression */
        .prog-sess-time-range {
            font-size: 0.72rem; color: rgba(255,171,64,0.75); font-weight: 600;
            padding: 2px 7px; background: rgba(255,171,64,0.09);
            border-radius: 10px; white-space: nowrap;
        }

        .att-list {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: 0;
            padding: 14px 24px 24px;
            -webkit-overflow-scrolling: touch;
        }
        .att-list .student-row + .student-row { margin-top: 8px; }
        .att-list::-webkit-scrollbar { width: 4px; }
        .att-list::-webkit-scrollbar-track { background: transparent; }
        .att-list::-webkit-scrollbar-thumb { background: rgba(0,200,83,0.3); border-radius: 2px; }

        .att-loading { text-align: center; padding: 40px; color: rgba(255,255,255,0.4); }
        .att-loading i { font-size: 2rem; color: rgba(0,200,83,0.4); animation: spin 1s linear infinite; }

        .student-row {
            background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.07);
            border-radius: 12px; padding: 12px 14px; transition: all 0.2s;
            display: flex; flex-direction: column; gap: 8px;
        }
        .student-row:hover { background: rgba(255,255,255,0.07); border-color: rgba(255,255,255,0.12); }
        .student-row.is-present   { border-left: 3px solid #00e676; }
        .student-row.is-absent    { border-left: 3px solid #ff5252; }
        .student-row.is-late      { border-left: 3px solid #ffab40; }
        .student-row.is-justified { border-left: 3px solid #64b5f6; }

        .student-row-top { display: flex; align-items: center; gap: 12px; }
        .student-avatar  { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.1); flex-shrink: 0; }
        .student-name    { flex: 1; font-size: 0.9rem; font-weight: 500; color: var(--text-light); }
        .student-id-badge { font-size: 0.7rem; color: rgba(255,255,255,0.4); }

        .status-pills { display: flex; gap: 6px; flex-wrap: wrap; }
        .status-pill {
            padding: 5px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 600;
            cursor: pointer; border: 1px solid transparent; transition: all 0.15s; user-select: none;
        }
        .status-pill[data-status="present"]   { background: rgba(0,200,83,0.1);   border-color: rgba(0,200,83,0.25);   color: rgba(0,230,118,0.7); }
        .status-pill[data-status="absent"]    { background: rgba(255,82,82,0.1);   border-color: rgba(255,82,82,0.25);  color: rgba(255,82,82,0.7); }
        .status-pill[data-status="late"]      { background: rgba(255,171,64,0.1);  border-color: rgba(255,171,64,0.25); color: rgba(255,171,64,0.7); }
        .status-pill[data-status="present"].active   { background: rgba(0,200,83,0.25);   border-color: #00e676; color: #00e676; box-shadow: 0 0 10px rgba(0,230,118,0.2); }
        .status-pill[data-status="absent"].active    { background: rgba(255,82,82,0.25);   border-color: #ff5252; color: #ff5252; box-shadow: 0 0 10px rgba(255,82,82,0.2); }
        .status-pill[data-status="late"].active      { background: rgba(255,171,64,0.25);  border-color: #ffab40; color: #ffab40; box-shadow: 0 0 10px rgba(255,171,64,0.2); }

        .save-indicator { font-size: 0.68rem; color: rgba(0,230,118,0.6); display: none; margin-top: 2px; }
        .save-indicator.show  { display: block; animation: fadeOutAtt 2s forwards; }
        .save-indicator.error { color: rgba(255,82,82,0.7); }
        @keyframes fadeOutAtt { 0%{opacity:1} 70%{opacity:1} 100%{opacity:0} }

        .att-drawer-footer {
            padding: 14px 24px; border-top: 1px solid rgba(255,255,255,0.06);
            background: rgba(0,0,0,0.2); flex-shrink: 0;
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
        }
        .att-footer-info { font-size: 0.75rem; color: rgba(255,255,255,0.4); }
        .att-save-all-btn {
            padding: 9px 20px; border-radius: 8px; font-size: 0.82rem; font-weight: 600;
            background: linear-gradient(135deg, #00c853, #00897b); border: none; color: white;
            cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s;
            box-shadow: 0 3px 12px rgba(0,200,83,0.2);
        }
        .att-save-all-btn:hover { transform: translateY(-1px); box-shadow: 0 5px 16px rgba(0,200,83,0.3); }

        /* ============================================================
           CSS DRAWER PROGRESSION (NOUVEAU)
        ============================================================ */
        .progress-drawer {
            position: fixed;
            top: 0;
            right: -520px;
            width: 500px;
            height: 100vh;
            background: linear-gradient(160deg, #021a0e 0%, #031e12 50%, #051e34 100%);
            box-shadow: -4px 0 30px rgba(0,0,0,0.5);
            transition: right 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1002;
            border-left: 1px solid rgba(34,160,90,0.2);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .progress-drawer.open { right: 0; }

        .prog-drawer-header {
            padding: 20px 24px;
            background: rgba(34,160,90,0.08);
            border-bottom: 2px solid rgba(34,160,90,0.4);
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .prog-header-left { display: flex; flex-direction: column; gap: 4px; }
        .prog-header-left h3 { font-size: 1.2rem; font-weight: 700; color: #69f0ae; margin: 0; display: flex; align-items: center; gap: 10px; }
        .prog-header-left .prog-subtitle { font-size: 0.75rem; color: rgba(255,255,255,0.45); }
        .close-prog-drawer {
            background: transparent; border: 1px solid rgba(255,255,255,0.15);
            color: var(--text-light); font-size: 18px; cursor: pointer;
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; transition: all 0.3s;
        }
        .close-prog-drawer:hover { background: rgba(255,255,255,0.1); transform: rotate(90deg); }

        .prog-summary-bar {
            display: flex; gap: 8px; padding: 14px 24px;
            background: rgba(0,0,0,0.2); border-bottom: 1px solid rgba(255,255,255,0.05);
            flex-shrink: 0; flex-wrap: wrap;
        }
        .prog-kpi {
            flex: 1; min-width: 70px; background: rgba(255,255,255,0.04);
            border-radius: 10px; padding: 10px 8px; text-align: center;
            border: 1px solid rgba(255,255,255,0.06);
        }
        .prog-kpi .kpi-num { font-size: 1.4rem; font-weight: 700; color: #69f0ae; line-height: 1; }
        .prog-kpi .kpi-label { font-size: 0.65rem; color: rgba(255,255,255,0.45); margin-top: 3px; text-transform: uppercase; letter-spacing: 0.5px; }
        .prog-kpi.planned .kpi-num { color: rgba(255,255,255,0.5); }

        .prog-body {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: 0;
            padding: 16px 24px 24px;
            -webkit-overflow-scrolling: touch;
        }
        .prog-body::-webkit-scrollbar { width: 4px; }
        .prog-body::-webkit-scrollbar-track { background: transparent; }
        .prog-body::-webkit-scrollbar-thumb { background: rgba(34,160,90,0.3); border-radius: 2px; }

        .prog-loading { text-align: center; padding: 40px; color: rgba(255,255,255,0.35); }
        .prog-loading i { font-size: 2rem; animation: spin 1s linear infinite; color: rgba(34,160,90,0.4); }
        @keyframes spin { to { transform: rotate(360deg); } }

        .prog-chapter {
            background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.07);
            border-radius: 12px; overflow: hidden;
        }
        .prog-ch-header {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px; cursor: pointer;
            background: rgba(255,255,255,0.04);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            user-select: none; transition: background 0.2s;
        }
        .prog-ch-header:hover { background: rgba(255,255,255,0.08); }
        .prog-ch-num {
            background: rgba(34,160,90,0.2); border: 1px solid rgba(34,160,90,0.3);
            color: #69f0ae; font-size: 0.7rem; font-weight: 700;
            padding: 3px 9px; border-radius: 20px; white-space: nowrap;
        }
        .prog-ch-title { flex: 1; font-weight: 600; font-size: 0.9rem; }
        .prog-ch-hours {
            background: rgba(34,160,90,0.1); border: 1px solid rgba(34,160,90,0.2);
            color: #69f0ae; font-size: 0.72rem; padding: 2px 8px; border-radius: 20px;
        }
        .prog-ch-toggle { color: rgba(255,255,255,0.35); transition: transform 0.25s; font-size: 0.8rem; }
        .prog-ch-toggle.open { transform: rotate(180deg); }
        .prog-ch-actions { display: flex; gap: 4px; }

        .prog-ch-body { padding: 12px 16px; display: none; }
        .prog-ch-body.open { display: block; }

        /* Session items */
        .prog-sess-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 10px 12px; border-radius: 10px; margin-bottom: 8px;
            background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06);
            transition: background 0.2s;
        }
        .prog-sess-item:hover { background: rgba(255,255,255,0.06); }
        .prog-sess-num {
            background: rgba(34,160,90,0.1); border: 1px solid rgba(34,160,90,0.2);
            color: #69f0ae; font-size: 0.68rem; font-weight: 700;
            padding: 3px 7px; border-radius: 6px; white-space: nowrap; min-width: 32px; text-align: center; margin-top: 2px;
        }
        .prog-sess-main { flex: 1; min-width: 0; }
        .prog-sess-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 3px; }
        .prog-sess-title { font-weight: 600; font-size: 0.88rem; }
        .prog-sess-hours {
            background: rgba(34,160,90,0.1); border: 1px solid rgba(34,160,90,0.2);
            color: #69f0ae; font-size: 0.7rem; font-weight: 600; padding: 2px 7px; border-radius: 20px;
        }
        .prog-sess-date { color: rgba(255,255,255,0.35); font-size: 0.68rem; }
        .prog-sess-done-badge {
            font-size: 0.68rem; padding: 2px 8px; border-radius: 10px;
            white-space: nowrap; display: inline-flex; align-items: center; gap: 4px;
        }
        .prog-badge-done        { background: rgba(0,200,83,0.12);    color: #69f0ae;               border: 1px solid rgba(0,200,83,0.25); }
        .prog-badge-att-done    { background: rgba(0,200,83,0.12);    color: #69f0ae;               border: 1px solid rgba(0,200,83,0.25); }
        .prog-badge-att-missing { background: rgba(255,171,64,0.12);  color: #ffab40;               border: 1px solid rgba(255,171,64,0.25); }
        .prog-badge-upcoming    { background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.4); border: 1px solid rgba(255,255,255,0.1); }
        .prog-sess-desc-toggle {
            background: none; border: none; color: rgba(34,160,90,0.7);
            font-size: 0.75rem; cursor: pointer; padding: 0; font-family: inherit;
            display: flex; align-items: center; gap: 4px; margin-top: 3px; transition: color 0.2s;
        }
        .prog-sess-desc-toggle:hover { color: #69f0ae; }
        .prog-sess-desc-toggle i { transition: transform 0.2s; font-size: 0.65rem; }
        .prog-sess-desc-toggle.open i { transform: rotate(180deg); }
        .prog-sess-desc-body {
            margin-top: 6px; padding: 8px 10px;
            background: rgba(255,255,255,0.03);
            border-left: 2px solid rgba(34,160,90,0.3);
            border-radius: 0 6px 6px 0; font-size: 0.82rem;
            color: rgba(255,255,255,0.6); line-height: 1.5; display: none;
        }
        .prog-sess-desc-body.open { display: block; }
        .prog-sess-actions { display: flex; gap: 4px; margin-top: 2px; }

        /* Icon buttons */
        .prog-icon-btn {
            background: transparent; border: none;
            color: rgba(255,255,255,0.35); cursor: pointer;
            padding: 4px 6px; border-radius: 6px; font-size: 0.82rem; transition: all 0.2s;
        }
        .prog-icon-btn:hover { background: rgba(255,255,255,0.08); color: #fff; transform: none; }
        .prog-icon-btn.del:hover { background: rgba(231,76,60,0.12); color: #e74c3c; }

        /* Add session button */
        .prog-add-sess-btn {
            width: 100%; padding: 8px; border: 1px dashed rgba(255,255,255,0.12);
            background: transparent; border-radius: 8px; color: rgba(255,255,255,0.35);
            cursor: pointer; font-size: 0.82rem; font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 6px;
            transition: all 0.2s; margin-top: 4px;
        }
        .prog-add-sess-btn:hover { background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.25); color: #fff; }

        /* Add chapter button */
        .prog-add-ch-btn {
            width: 100%; padding: 11px; border: 2px dashed rgba(34,160,90,0.25);
            background: transparent; border-radius: 10px; color: rgba(34,160,90,0.7);
            cursor: pointer; font-size: 0.88rem; font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all 0.2s; margin-bottom: 14px;
        }
        .prog-add-ch-btn:hover { background: rgba(34,160,90,0.06); border-color: rgba(34,160,90,0.5); color: #69f0ae; }

        /* Inline forms */
        .prog-inline-form {
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px; padding: 14px; margin-bottom: 10px; display: none;
        }
        .prog-inline-form.open { display: block; }
        .prog-inline-form label { display: block; font-size: 0.75rem; opacity: 0.55; margin-bottom: 4px; }
        .prog-inline-form input, .prog-inline-form textarea {
            width: 100%; padding: 8px 11px; background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: #fff;
            font-family: inherit; font-size: 0.85rem; outline: none; transition: border-color 0.2s;
        }
        .prog-inline-form input:focus, .prog-inline-form textarea:focus { border-color: rgba(34,160,90,0.5); }
        .prog-inline-form textarea { resize: vertical; min-height: 56px; }
        .prog-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 8px; }
        .prog-form-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
        .prog-btn-sm {
            padding: 6px 14px; font-size: 0.8rem; border-radius: 7px; cursor: pointer;
            border: none; font-family: inherit; font-weight: 600; transition: all 0.2s;
        }
        .prog-btn-save { background: linear-gradient(135deg, #1a6b3c, #22a05a); color: #fff; }
        .prog-btn-save:hover { opacity: 0.9; transform: none; }
        .prog-btn-cancel { background: rgba(255,255,255,0.08); color: rgba(255,255,255,0.7); border: 1px solid rgba(255,255,255,0.1) !important; }
        .prog-btn-cancel:hover { background: rgba(255,255,255,0.12); transform: none; }

        .prog-empty { text-align: center; padding: 40px 20px; color: rgba(255,255,255,0.3); }
        .prog-empty i { font-size: 2.2rem; margin-bottom: 12px; display: block; }

        /* Bouton ajout rapide depuis le drawer appel */
        .att-add-slot-btn {
            padding: 6px 14px; font-size: 0.78rem; border-radius: 6px;
            background: rgba(255,171,64,0.15); border: 1px solid rgba(255,171,64,0.35);
            color: #ffab40; cursor: pointer; display: inline-flex; align-items: center;
            gap: 6px; transition: all 0.2s; font-family: inherit; font-weight: 600;
        }
        .att-add-slot-btn:hover { background: rgba(255,171,64,0.28); transform: none; }
        .att-add-slot-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

        /* Overlay commun aux deux drawers */
        .drawer-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            z-index: 999; opacity: 0; pointer-events: none; transition: opacity 0.3s;
        }
        .drawer-overlay.show { opacity: 1; pointer-events: all; }

        /* ── Bouton flottant devoirs (gauche) ── */
        .assign-btn-prof {
            position: fixed; left: 20px; bottom: 140px; z-index: 900;
            width: 50px; height: 50px; border-radius: 50%;
            background: #8e44ad; color: #fff; border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; box-shadow: 0 4px 12px rgba(0,0,0,.25);
            transition: background .2s, transform .2s;
        }
        .assign-btn-prof:hover { background: #6c3483; transform: scale(1.08); }

        /* ── Overlay + modal devoirs ── */
        .assign-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 1100;
            align-items: center; justify-content: center;
        }
        .assign-modal-overlay.open { display: flex; }
        .assign-modal {
            background: #fff; border-radius: 12px; width: 800px; max-width: 95vw;
            max-height: 90vh; overflow-y: auto; padding: 28px 32px;
            box-shadow: 0 8px 32px rgba(0,0,0,.3); position: relative;
        }
        .assign-modal h2 { margin: 0 0 18px; color: #8e44ad; font-size: 1.3rem; }
        .assign-modal .close-btn {
            position: absolute; top: 14px; right: 18px;
            background: none; border: none; font-size: 22px; cursor: pointer; color: #888;
        }
        .assign-form-section { margin-bottom: 24px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
        .assign-form-section h3 { margin: 0 0 12px; font-size: 1rem; color: #555; }
        .assign-form-section input,
        .assign-form-section textarea,
        .assign-form-section input[type=datetime-local] {
            width: 100%; padding: 8px 10px; border: 1px solid #ddd;
            border-radius: 6px; margin-bottom: 10px; font-size: .93rem; box-sizing: border-box;
        }
        .assign-form-section textarea { min-height: 70px; resize: vertical; }
        .assign-submit-btn {
            background: #8e44ad; color: #fff; border: none; padding: 9px 22px;
            border-radius: 6px; cursor: pointer; font-size: .95rem; transition: background .2s;
        }
        .assign-submit-btn:hover { background: #6c3483; }
        .assign-list { margin-top: 10px; }
        .assign-card {
            border: 1px solid #e0d0ea; border-radius: 8px; padding: 14px 16px;
            margin-bottom: 12px; background: #faf5ff;
        }
        .assign-card-title { font-weight: 600; color: #6c3483; margin-bottom: 4px; }
        .assign-card-meta { font-size: .83rem; color: #777; margin-bottom: 8px; }
        .assign-card-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .assign-card-actions button {
            font-size: .82rem; padding: 5px 12px; border-radius: 5px; border: none; cursor: pointer;
        }
        .btn-voir-rendus { background: #2980b9; color: #fff; }
        .btn-voir-rendus:hover { background: #1a6295; }
        .btn-dl-zip { background: #27ae60; color: #fff; }
        .btn-dl-zip:hover { background: #1e8449; }
        .btn-del-assign { background: #e74c3c; color: #fff; }
        .btn-del-assign:hover { background: #b03a2e; }
        .assign-rendus-list { margin-top: 12px; border-top: 1px dashed #ccc; padding-top: 10px; }
        .rendu-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 6px 0; border-bottom: 1px solid #f0e6ff; font-size: .87rem;
        }
        .rendu-row:last-child { border-bottom: none; }
        .assign-empty { color: #aaa; font-style: italic; text-align: center; padding: 20px 0; }
    </style>
</head>
<body>

<!-- Barre de navigation -->
<?php include '../includes/header_discussion.php'; ?>

<!-- Zone de discussion -->
<div class="discussion">

    <!-- Sélecteur d'année académique -->
    <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="font-size:13px;color:#ffffff;">
            <i class="fas fa-calendar-alt"></i> Année académique :
        </span>
        <form method="GET" id="yearForm" style="display:inline-flex;align-items:center;gap:8px;">
            <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($_GET['course_id'] ?? ''); ?>">
            <select name="year" onchange="onYearChange()"
                    style="background:rgba(255,255,255,0.1);border:1px solid var(--border-color) !important;color:#ffffff !important;padding:5px 10px;border-radius:6px;font-size:13px;cursor:pointer;outline:none;">
                <?php foreach ($available_years as $yr): ?>
                    <option value="<?php echo htmlspecialchars($yr); ?>"
                        <?php echo $yr === $filter_year ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($yr); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($filter_year !== $current_year): ?>
            <a href="?course_id=<?php echo htmlspecialchars($_GET['course_id'] ?? ''); ?>"
               style="font-size:12px;color:var(--accent-color);text-decoration:none;">
                <i class="fas fa-arrow-left"></i> Retour à l'année courante
            </a>
        <?php endif; ?>
    </div>

    <?php if ($archived_write_blocked): ?>
        <div class="email-notification" style="background:rgba(255,82,82,0.12);border-color:rgba(255,82,82,0.35);color:#ff5252;">
            <i class="fas fa-lock"></i>
            <span>Vous consultez l'année archivée <?php echo htmlspecialchars($filter_year); ?> : l'envoi de messages et de documents est désactivé. Revenez à l'année courante pour écrire.</span>
        </div>
    <?php elseif ($_SERVER["REQUEST_METHOD"] == "POST" && $user_role === 'teacher' && (isset($_POST['message']) || isset($_FILES['documents']))): ?>
        <div class="email-notification">
            <i class="fas fa-envelope"></i>
            <span>Notification envoyée par email à tous les étudiants du cours</span>
        </div>
    <?php endif; ?>

    <div class="message-grid">
        <?php if ($messages->num_rows > 0): ?>
            <?php while ($row = $messages->fetch_assoc()): ?>
                <div class="message-card <?php echo ($row['sender_id'] == $user_id) ? 'own' : ''; ?>">
                    <img src="../uploads/avatars/<?php echo htmlspecialchars($row['avatar']); ?>" alt="Avatar" width="40" height="40">
                    <div class="message-info">
                        <h4><?php echo htmlspecialchars($row['name']); ?> <em><?php echo htmlspecialchars($row['created_at']); ?></em></h4>
                        <p><?php echo htmlspecialchars($row['message']); ?></p>
                        
                        <!-- Affichage des fichiers joints -->
                        <?php if ($row['document_id']): ?>
                            <div class="file-preview">
                                <?php
                                $file_extension = strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION));
                                $is_image       = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                                $display_name   = $row['original_name'] ?? $row['file_path'];
                                if ($is_image): ?>
                                    <!-- Affichage direct de l'image avec une miniature cliquable -->
                                    <div class="image-preview">
                                        <img src="../uploads/<?php echo htmlspecialchars($row['file_path']); ?>"
                                             alt="<?php echo htmlspecialchars($display_name); ?>"
                                             class="thumbnail-image"
                                             onclick="openImagePreview('../uploads/<?php echo htmlspecialchars($row['file_path']); ?>')">
                                        <div class="image-actions">
                                            <a href="../uploads/<?php echo htmlspecialchars($row['file_path']); ?>"
                                               download="<?php echo htmlspecialchars($display_name); ?>">
                                                <i class="fas fa-download"></i> Télécharger
                                            </a>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <!-- Affichage normal des autres types de fichiers -->
                                    <a href="../uploads/<?php echo htmlspecialchars($row['file_path']); ?>"
                                       download="<?php echo htmlspecialchars($display_name); ?>">
                                        <div class="doc-icon <?php echo $file_extension; ?>">
                                            <?php echo strtoupper($file_extension); ?>
                                        </div>
                                        <div class="doc-info">
                                            <a href="../uploads/<?php echo htmlspecialchars($row['file_path']); ?>"
                                               download="<?php echo htmlspecialchars($display_name); ?>">
                                                <?php echo htmlspecialchars($display_name); ?>
                                            </a>
                                            <span class="doc-details">Taille du fichier : <?php echo is_file("../uploads/" . $row['file_path']) ? round(filesize("../uploads/" . $row['file_path']) / 1024, 2) . ' Ko' : 'fichier introuvable'; ?></span>
                                        </div>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Boutons d'action pour l'auteur du message -->
                        <?php 
                        $created_at = strtotime($row['created_at']); // Convertir la date en timestamp
                        $now = time(); // Timestamp actuel
                        $time_difference = $now - $created_at; // Différence en secondes

                        if ($row['sender_id'] == $user_id && $time_difference <= 1200): // 1200 secondes = 20 minutes
                        ?>
                            <div class="action-buttons">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                    <input type="hidden" name="delete_id" value="<?php echo $row['discussion_id']; ?>">
                                    <input type="hidden" name="delete_type" value="message">
                                    <button type="submit">Supprimer</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>Aucun message pour ce cours.</p>
        <?php endif; ?>
    </div>

    <!-- Formulaire d'envoi de message et de documents -->
    <?php if ($is_archived_year): ?>
        <div class="send-message" style="text-align:center;opacity:.7;padding:16px;">
            <i class="fas fa-lock"></i>
            Année <?php echo htmlspecialchars($filter_year); ?> archivée — lecture seule.
            <a href="?course_id=<?php echo htmlspecialchars($_GET['course_id'] ?? ''); ?>" style="color:var(--accent-color);">Revenir à l'année courante</a> pour écrire.
        </div>
    <?php else: ?>
    <div class="send-message">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <textarea name="message" placeholder="Entrez votre message"></textarea>
            <div class="file-input">
                <label class="upload-docs">
                    Sélectionner des documents
                    <input type="file" name="documents[]" multiple onchange="previewFiles(this)">
                </label>
            </div>
            <div id="file-preview-container" class="file-preview-container"></div>
            <button type="submit">Envoyer</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Bouton pour ouvrir le drawer documents (ORIGINAL) -->
    <button type="button" class="documents-btn" id="openDocsBtn">
        <i class="fas fa-folder"></i> Voir les Documents
    </button>

    <!-- Bouton Faire l'appel (uniquement pour le prof) -->
    <?php if ($user_role === 'teacher'): ?>
    <button type="button" class="attendance-btn" id="openAttendanceBtn">
        <i class="fas fa-clipboard-check"></i> Faire l'appel
    </button>

    <!-- Bouton Progression (uniquement pour le prof) -->
    <button type="button" class="progress-btn" id="openProgressBtn">
        <i class="fas fa-chart-line"></i> Progression
    </button>

    <!-- Bouton flottant Devoirs (gauche) -->
    <button type="button" class="assign-btn-prof" id="openDevoirsBtn" title="Devoirs" onclick="ouvrirDevoirs()">
        <i class="fas fa-tasks"></i>
    </button>
    <?php endif; ?>

    <!-- Drawer des documents (ORIGINAL INTACT) -->
    <div class="documents-drawer" id="documentsDrawer">
        <div class="drawer-header">
            <h3>Documents</h3>
            <button type="button" class="close-drawer" id="closeDocsBtn">×</button>
        </div>
        <div class="drawer-content">
            <!-- Documents envoyés par le professeur -->
            <h4>Documents du Professeur</h4>
            <?php if (count($prof_documents) > 0): ?>
                <ul>
                    <?php foreach ($prof_documents as $doc): ?>
                        <?php $doc_display = $doc['original_name'] ?? $doc['file_path']; ?>
                        <li class="document-item">
                            <div class="doc-icon <?php echo strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION)); ?>">
                                <?php echo strtoupper(pathinfo($doc['file_path'], PATHINFO_EXTENSION)); ?>
                            </div>
                            <div class="doc-info">
                                <a href="../uploads/<?php echo htmlspecialchars($doc['file_path']); ?>"
                                   download="<?php echo htmlspecialchars($doc_display); ?>"><?php echo htmlspecialchars($doc_display); ?></a>
                                <span class="doc-details">Taille : <?php echo is_file("../uploads/" . $doc['file_path']) ? round(filesize("../uploads/" . $doc['file_path']) / 1024, 2) . ' Ko' : 'fichier introuvable'; ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="no-docs">Aucun document disponible.</div>
            <?php endif; ?>

            <!-- Documents envoyés par les étudiants -->
            <h4>Documents des Étudiants</h4>
            <?php if (count($student_documents) > 0): ?>
                <ul>
                    <?php foreach ($student_documents as $doc): ?>
                        <?php $doc_display = $doc['original_name'] ?? $doc['file_path']; ?>
                        <li class="document-item">
                            <div class="doc-icon <?php echo strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION)); ?>">
                                <?php echo strtoupper(pathinfo($doc['file_path'], PATHINFO_EXTENSION)); ?>
                            </div>
                            <div class="doc-info">
                                <a href="../uploads/<?php echo htmlspecialchars($doc['file_path']); ?>"
                                   download="<?php echo htmlspecialchars($doc_display); ?>"><?php echo htmlspecialchars($doc_display); ?></a>
                                <span class="doc-details">Taille : <?php echo is_file("../uploads/" . $doc['file_path']) ? round(filesize("../uploads/" . $doc['file_path']) / 1024, 2) . ' Ko' : 'fichier introuvable'; ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="no-docs">Aucun document disponible.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Overlay commun -->
    <div class="drawer-overlay" id="drawerOverlay"></div>

    <!-- Drawer Présences (ORIGINAL INTACT) -->
    <div class="attendance-drawer" id="attendanceDrawer">
        <div class="att-drawer-header">
            <div class="att-title">
                <h3><i class="fas fa-clipboard-check"></i> Fiche d'appel</h3>
                <span class="att-subtitle"><?php echo htmlspecialchars($course_name); ?></span>
                <span class="att-subtitle" style="color:rgba(0,230,118,.6);">
                    <?php echo htmlspecialchars($filter_year); ?><?php if ($filter_year === $current_year): ?> &mdash; Semestre <?php echo $current_semester; ?><?php endif; ?>
                </span>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <span class="att-date-badge" id="attDateBadge"></span>
                <button type="button" class="close-att-drawer" id="closeAttBtn">×</button>
            </div>
        </div>

        <div class="att-stats-bar">
            <div class="att-stat s-total">    <div class="stat-num" id="statTotal">0</div>    <div class="stat-label">Total</div></div>
            <div class="att-stat s-present">  <div class="stat-num" id="statPresent">0</div>  <div class="stat-label">Présents</div></div>
            <div class="att-stat s-absent">   <div class="stat-num" id="statAbsent">0</div>   <div class="stat-label">Absents</div></div>
            <div class="att-stat s-late">     <div class="stat-num" id="statLate">0</div>     <div class="stat-label">Retards</div></div>
            <div class="att-stat s-justified"><div class="stat-num" id="statJustified">0</div><div class="stat-label">Justifiés</div></div>
        </div>

        <div id="attSessionBanner"></div>

        <div class="att-toolbar">
            <input type="text" class="att-search" id="attSearch" placeholder="Rechercher un étudiant…">
            <button class="att-quick-btn all-present" id="btnAllPresent">✓ Tous présents</button>
            <button class="att-quick-btn all-absent"  id="btnAllAbsent">✗ Tous absents</button>
        </div>

        <div class="att-list" id="attList">
            <div class="att-loading"><i class="fas fa-circle-notch"></i><br><br>Chargement…</div>
        </div>

        <div class="att-drawer-footer">
            <span class="att-footer-info" id="attFooterInfo">—</span>
            <button class="att-save-all-btn" id="attSaveAll">
                <i class="fas fa-save"></i> Tout enregistrer
            </button>
        </div>
    </div>

    <!-- Drawer Progression (NOUVEAU) -->
    <div class="progress-drawer" id="progressDrawer">
        <div class="prog-drawer-header">
            <div class="prog-header-left">
                <h3><i class="fas fa-chart-line"></i> Progression du cours</h3>
                <span class="prog-subtitle"><?php echo htmlspecialchars($course_name); ?></span>
            </div>
            <button type="button" class="close-prog-drawer" id="closeProgBtn">×</button>
        </div>

        <div class="prog-summary-bar">
            <div class="prog-kpi">
                <div class="kpi-num" id="progTotalHours">0h</div>
                <div class="kpi-label">Heures effectuées</div>
            </div>
            <div class="prog-kpi">
                <div class="kpi-num" id="progTotalSessions">0</div>
                <div class="kpi-label">Séances</div>
            </div>
            <div class="prog-kpi planned">
                <div class="kpi-num" id="progPlannedHours">—</div>
                <div class="kpi-label">Heures prévues</div>
            </div>
        </div>

        <div class="prog-body" id="progBody">
            <div class="prog-loading"><i class="fas fa-circle-notch"></i><br><br>Chargement…</div>
        </div>
    </div>
</div>

<footer class="footer">
    <div class="footer-content">
        <div class="footer-text">
            <div class="footer-logo">UV</div>
            <span class="footer-brand">Université Virtuelle</span>
        </div>
    </div>
    <div class="footer-social">
        <a href="#" class="social-icon" title="Facebook"><i class="fab fa-facebook-f"></i></a>
        <a href="#" class="social-icon" title="Twitter"><i class="fab fa-twitter"></i></a>
        <a href="#" class="social-icon" title="LinkedIn"><i class="fab fa-linkedin-in"></i></a>
        <a href="#" class="social-icon" title="Instagram"><i class="fab fa-instagram"></i></a>
    </div>
    <div class="footer-copyright">
        <p>&copy; <?php echo date('Y'); ?> Université Virtuelle | <span class="footer-brand">from Coding Enterprise</span></p>
    </div>
</footer>

<!-- Modal sélection chapitre avant ajout depuis EDT -->
<div id="slotChapterModal" style="display:none;position:fixed;inset:0;z-index:2000;justify-content:center;align-items:flex-start;padding-top:12vh;">
    <div style="position:absolute;inset:0;background:rgba(0,0,0,0.55);" onclick="closeSlotChapterModal()"></div>
    <div style="position:relative;background:linear-gradient(135deg,#051e34,#0c2d48);border:1px solid rgba(255,255,255,0.12);border-radius:14px;padding:24px;width:100%;max-width:400px;margin:0 20px;box-shadow:0 8px 32px rgba(0,0,0,0.45);">
        <h3 id="slotModalTitle" style="font-size:0.98rem;font-weight:700;color:#69f0ae;margin:0 0 18px;line-height:1.4;"></h3>

        <div id="slotChapterSelectWrap" style="margin-bottom:12px;">
            <label style="display:block;font-size:0.75rem;color:rgba(255,255,255,0.55);margin-bottom:5px;">Chapitre</label>
            <select id="slotChapterSelect" style="width:100%;padding:8px 11px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.12);border-radius:8px;color:#fff;font-family:inherit;font-size:0.85rem;outline:none;cursor:pointer;"></select>
        </div>

        <div id="slotNewChWrap" style="display:none;margin-bottom:12px;">
            <label style="display:block;font-size:0.75rem;color:rgba(255,255,255,0.55);margin-bottom:5px;">Nom du chapitre *</label>
            <input type="text" id="slotNewChName" placeholder="ex : Chapitre 1 — Introduction" maxlength="255"
                style="width:100%;padding:8px 11px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.12);border-radius:8px;color:#fff;font-family:inherit;font-size:0.85rem;outline:none;"
                onkeydown="if(event.key==='Enter')confirmSlotChapter()">
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;">
            <button class="prog-btn-sm prog-btn-cancel" onclick="closeSlotChapterModal()">Annuler</button>
            <button class="prog-btn-sm prog-btn-save" id="slotConfirmBtn" onclick="confirmSlotChapter()"><i class="fas fa-check"></i> Confirmer</button>
        </div>
    </div>
</div>

<!-- Modal confirmation suppression chapitre/séance -->
<div id="deleteConfirmModal" style="display:none;position:fixed;inset:0;z-index:2000;justify-content:center;align-items:flex-start;padding-top:12vh;">
    <div style="position:absolute;inset:0;background:rgba(0,0,0,0.55);" onclick="closeDeleteConfirmModal()"></div>
    <div style="position:relative;background:linear-gradient(135deg,#051e34,#0c2d48);border:1px solid rgba(255,255,255,0.12);border-radius:14px;padding:24px;width:100%;max-width:440px;margin:0 20px;box-shadow:0 8px 32px rgba(0,0,0,0.45);">
        <h3 id="deleteConfirmTitle" style="font-size:1rem;font-weight:700;color:#ff5252;margin:0 0 14px;"></h3>
        <p id="deleteConfirmMessage" style="font-size:0.85rem;color:rgba(255,255,255,0.82);line-height:1.6;margin:0 0 22px;"></p>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="prog-btn-sm prog-btn-cancel" onclick="closeDeleteConfirmModal()">Annuler</button>
            <button class="prog-btn-sm" id="deleteConfirmBtn" style="background:#c62828;color:#fff;border:none;border-radius:7px;padding:7px 14px;cursor:pointer;font-size:0.83rem;" onclick="executeDeleteConfirm()">
                <i class="fas fa-trash"></i> Supprimer quand même
            </button>
        </div>
    </div>
</div>

<!-- ═══════════ MODAL DEVOIRS (PROFESSEUR) ═══════════ -->
<div class="assign-modal-overlay" id="mDevoirs">
    <div class="assign-modal">
        <button class="close-btn" onclick="fermerDevoirs()" title="Fermer">&times;</button>
        <h2><i class="fas fa-tasks" style="margin-right:8px"></i>Devoirs</h2>

        <!-- Formulaire création -->
        <div class="assign-form-section" id="assignFormSection">
            <h3>Créer un devoir</h3>
            <input type="text" id="assignTitle" placeholder="Titre du devoir *">
            <textarea id="assignDesc" placeholder="Description (optionnel)"></textarea>
            <label style="font-size:.85rem;color:#666;display:block;margin-bottom:4px">Date limite *</label>
            <input type="datetime-local" id="assignDueDate">
            <label style="font-size:.85rem;color:#666;display:block;margin:8px 0 4px">Fichier joint (optionnel, max 10 MB)</label>
            <input type="file" id="assignFile" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.rar">
            <div style="margin-top:12px">
                <button class="assign-submit-btn" onclick="creerDevoir()"><i class="fas fa-plus"></i> Créer le devoir</button>
            </div>
            <div id="assignFormMsg" style="margin-top:8px;font-size:.88rem"></div>
        </div>

        <!-- Liste des devoirs -->
        <div>
            <h3 style="color:#555;font-size:1rem;margin-bottom:12px">Devoirs publiés</h3>
            <div class="assign-list" id="assignList">
                <div class="assign-empty"><i class="fas fa-circle-notch fa-spin"></i> Chargement…</div>
            </div>
        </div>
    </div>
</div>

<!-- Modal pour l'affichage d'images en plein écran -->
<div id="imageModal" class="image-modal">
    <span class="close-modal" onclick="closeImagePreview()">&times;</span>
    <img id="modalImage" class="modal-content">
</div>

<script src="../api/assets/js/main.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Récupération et application des couleurs stockées
    const mainColor = localStorage.getItem('mainColor');
    const accentColor = localStorage.getItem('accentColor');
    const bgColor = localStorage.getItem('bgColor');
    const themeColor = localStorage.getItem('themeColor');

    if (mainColor) { document.documentElement.style.setProperty('--main-color', mainColor); document.documentElement.style.setProperty('--primary-bg', mainColor); }
    if (accentColor) { document.documentElement.style.setProperty('--accent', accentColor); document.documentElement.style.setProperty('--accent-color', accentColor); }
    if (bgColor) { document.documentElement.style.setProperty('--bg-color', bgColor); document.documentElement.style.setProperty('--secondary-bg', bgColor); }
    if (themeColor) { document.documentElement.style.setProperty('--primary-color', themeColor); }

    window.addEventListener('storage', function(e) {
        switch(e.key) {
            case 'mainColor': document.documentElement.style.setProperty('--main-color', e.newValue); document.documentElement.style.setProperty('--primary-bg', e.newValue); break;
            case 'accentColor': document.documentElement.style.setProperty('--accent', e.newValue); document.documentElement.style.setProperty('--accent-color', e.newValue); break;
            case 'bgColor': document.documentElement.style.setProperty('--bg-color', e.newValue); document.documentElement.style.setProperty('--secondary-bg', e.newValue); break;
            case 'themeColor': document.documentElement.style.setProperty('--primary-color', e.newValue); break;
        }
    });

    window.toggleDrawer = function() {
        const drawer = document.getElementById('documentsDrawer');
        drawer.classList.toggle('open');
    };

    const navLinks = document.querySelectorAll('nav a');
    const header = document.querySelector('header');
    const floatingIcons = document.querySelectorAll('.floating-icon');
    const drawer = document.getElementById('documentsDrawer');
    const documentsBtn = document.getElementById('openDocsBtn');
    const closeDrawerBtn = document.getElementById('closeDocsBtn');
    const messageGrid = document.querySelector('.message-grid');
    const overlay = document.getElementById('drawerOverlay');
    const attDrawer = document.getElementById('attendanceDrawer');
    const progDrawer = document.getElementById('progressDrawer');

    navLinks.forEach(link => {
        link.addEventListener('mouseenter', function() { this.style.transform = 'translateY(-2px)'; });
        link.addEventListener('mouseleave', function() { this.style.transform = 'translateY(0)'; });
    });

    if (header && floatingIcons) {
        header.addEventListener('mouseenter', () => { floatingIcons.forEach(icon => { icon.style.opacity = '1'; resetAnimation(icon); }); });
        header.addEventListener('mouseleave', () => { floatingIcons.forEach(icon => { icon.style.opacity = '0'; }); });
    }

    // ── Drawer Documents ──────────────────────────────────
    function closeAllDrawers() {
        drawer?.classList.remove('open');
        attDrawer?.classList.remove('open');
        progDrawer?.classList.remove('open');
        overlay?.classList.remove('show');
    }

    if (documentsBtn) {
        documentsBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const wasOpen = drawer.classList.contains('open');
            closeAllDrawers();
            if (!wasOpen) { drawer.classList.add('open'); overlay.classList.add('show'); }
        });
    }

    if (closeDrawerBtn) {
        closeDrawerBtn.addEventListener('click', function(e) { e.preventDefault(); closeAllDrawers(); });
    }

    if (drawer) {
        document.addEventListener('click', function(e) {
            if (drawer.classList.contains('open') && !drawer.contains(e.target) && documentsBtn && !documentsBtn.contains(e.target)) {
                drawer.classList.remove('open');
                overlay?.classList.remove('show');
            }
        });
        drawer.addEventListener('click', function(e) { e.stopPropagation(); });
    }

    if (messageGrid) { messageGrid.scrollTop = messageGrid.scrollHeight; }

    const buttons = document.querySelectorAll('button');
    buttons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            if (!this.classList.contains('close-drawer') && !this.classList.contains('close-att-drawer') && !this.classList.contains('close-prog-drawer')) {
                this.style.transform = 'translateY(-2px)';
            }
        });
        button.addEventListener('mouseleave', function() {
            if (!this.classList.contains('close-drawer') && !this.classList.contains('close-att-drawer') && !this.classList.contains('close-prog-drawer')) {
                this.style.transform = 'translateY(0)';
            }
        });
    });

    const fileInput = document.querySelector('input[type="file"]');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const fileLabel = this.previousElementSibling;
            fileLabel.textContent = this.files.length > 0 ? `${this.files.length} fichier(s) sélectionné(s)` : 'Sélectionner des documents';
        });
    }

    const messageForm = document.querySelector('form');
    if (messageForm) { messageForm.addEventListener('submit', () => { setTimeout(scrollToBottom, 100); }); }

    const messageTextarea = document.querySelector('textarea[name="message"]');
    if (messageTextarea) {
        messageTextarea.addEventListener('input', function() { this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'; });
    }

    if (localStorage.getItem('darkTheme') === 'true') { document.body.classList.add('dark-theme'); }

    // ── Date badge présences ──────────────────────────────
    const now = new Date();
    const dateBadge = document.getElementById('attDateBadge');
    if (dateBadge) { dateBadge.textContent = now.toLocaleDateString('fr-FR', {weekday:'short', day:'numeric', month:'short'}); }

    // ── Drawer Présences ──────────────────────────────────
    const openAttBtn  = document.getElementById('openAttendanceBtn');
    const closeAttBtn = document.getElementById('closeAttBtn');

    if (openAttBtn) {
        openAttBtn.addEventListener('click', function() {
            closeAllDrawers();
            attDrawer.classList.add('open');
            overlay.classList.add('show');
            loadAttendance();
        });
    }
    if (closeAttBtn) { closeAttBtn.addEventListener('click', function() { closeAllDrawers(); }); }

    document.getElementById('attSearch')?.addEventListener('input', function() { renderStudentList(this.value); });

    document.getElementById('btnAllPresent')?.addEventListener('click', async function() {
        attStudents.forEach(s => { attRecords[s.id] = { status: 'present', justification: '' }; });
        renderStudentList(document.getElementById('attSearch').value);
        if (attStudents.length > 0) {
            await saveRecord(attStudents[0].id);
            attStudents.slice(1).forEach(s => saveRecord(s.id));
        }
        updateAttStats();
        updateAttFooterInfo();
    });

    document.getElementById('btnAllAbsent')?.addEventListener('click', async function() {
        attStudents.forEach(s => { attRecords[s.id] = { status: 'absent', justification: '' }; });
        renderStudentList(document.getElementById('attSearch').value);
        if (attStudents.length > 0) {
            await saveRecord(attStudents[0].id);
            attStudents.slice(1).forEach(s => saveRecord(s.id));
        }
        updateAttStats();
        updateAttFooterInfo();
    });

    document.getElementById('attSaveAll')?.addEventListener('click', async function() {
        const btn = this;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement…';
        btn.disabled = true;
        const toSave = attStudents.filter(s => attRecords[s.id]?.status);
        if (toSave.length === 0) {
            btn.innerHTML = '<i class="fas fa-save"></i> Tout enregistrer';
            btn.disabled = false;
            return;
        }
        // Premier enregistrement séquentiel pour créer la séance si besoin
        await saveRecord(toSave[0].id);
        await Promise.all(toSave.slice(1).map(s => saveRecord(s.id)));
        btn.innerHTML = '<i class="fas fa-check"></i> Enregistré !';
        setTimeout(() => { btn.innerHTML = '<i class="fas fa-save"></i> Tout enregistrer'; btn.disabled = false; }, 2000);
    });

    // ── Drawer Progression ────────────────────────────────
    const openProgBtn  = document.getElementById('openProgressBtn');
    const closeProgBtn = document.getElementById('closeProgBtn');

    if (openProgBtn) {
        openProgBtn.addEventListener('click', function() {
            closeAllDrawers();
            progDrawer.classList.add('open');
            overlay.classList.add('show');
            loadProgress();
        });
    }
    if (closeProgBtn) { closeProgBtn.addEventListener('click', function() { closeAllDrawers(); }); }

    if (overlay) { overlay.addEventListener('click', function() { closeAllDrawers(); }); }
});

// ════════════════════════════════════════════════════════════
// CHANGEMENT D'ANNÉE — recharge tous les panneaux dynamiques
// ════════════════════════════════════════════════════════════
function onYearChange() {
    // Navigation complète (et non un simple pushState + rafraîchissement partiel) :
    // plusieurs éléments de la page — formulaire d'envoi de message, bannière
    // "année archivée", drawer devoirs — sont calculés côté PHP au chargement
    // et ne se recalculaient pas correctement avec un simple rechargement AJAX,
    // laissant certains blocages inactifs jusqu'à un vrai rafraîchissement.
    const yr = document.querySelector('select[name="year"]')?.value || '';
    const url = new URL(window.location.href);
    url.searchParams.set('year', yr);
    window.location.href = url.toString();
}

// ════════════════════════════════════════════════════════════
// PRÉSENCES — fonctions (scope global)
// ════════════════════════════════════════════════════════════
let attStudents          = [];
let attRecords           = {};
let attAvailableSessions = [];
let attScheduledSlots    = [];
let attRecordsByAtt      = {};
let attSelectedCsId      = null;
let attSelectedAttId     = null;

function loadAttendance() {
    document.getElementById('attList').innerHTML = '<div class="att-loading"><i class="fas fa-circle-notch"></i><br><br>Chargement…</div>';
    document.getElementById('attSessionBanner').innerHTML = '';
    updateAttStats();
    const courseId = <?php echo json_encode($course_id); ?>;
    const _attYr = document.querySelector('select[name="year"]')?.value || '';
    const _attYrQ = _attYr ? '&year=' + encodeURIComponent(_attYr) : '';
    fetch(`?ajax=attendance&course_id=${courseId}` + _attYrQ)
        .then(r => r.json())
        .then(data => {
            attStudents          = data.students           || [];
            attAvailableSessions = data.available_sessions || [];
            attScheduledSlots    = data.scheduled_slots    || [];
            attRecordsByAtt      = data.records_by_att     || {};

            // Auto-sélection si une seule séance disponible
            if (attAvailableSessions.length === 1) {
                const cs = attAvailableSessions[0];
                attSelectedCsId  = cs.cs_id;
                attSelectedAttId = cs.att_session_id || null;
                attRecords = attSelectedAttId ? (attRecordsByAtt[attSelectedAttId] || {}) : {};
            } else {
                attSelectedCsId  = null;
                attSelectedAttId = null;
                attRecords = {};
            }

            renderAttSessionBanner();
            renderStudentList();
            updateAttStats();
            updateAttFooterInfo();
        })
        .catch(() => {
            document.getElementById('attList').innerHTML = '<div class="att-loading" style="color:#ff5252"><i class="fas fa-exclamation-circle" style="animation:none"></i><br><br>Erreur de chargement</div>';
        });
}

function fmtTimeStr(t) {
    if (!t) return '';
    return t.substring(0, 5).replace(':', 'h');
}

function renderAttSessionBanner() {
    const banner = document.getElementById('attSessionBanner');
    if (!banner) return;

    const btnAllPresent = document.getElementById('btnAllPresent');
    const btnAllAbsent  = document.getElementById('btnAllAbsent');

    if (attAvailableSessions.length === 0) {
        if (btnAllPresent) btnAllPresent.disabled = true;
        if (btnAllAbsent)  btnAllAbsent.disabled  = true;

        if (attScheduledSlots.length === 0) {
            // Cas 1 : aucune séance ET aucun créneau dans schedule
            banner.innerHTML = `<div class="att-sessions-picker">
                <div class="att-no-sessions-msg">
                    <i class="fas fa-exclamation-circle" style="margin-right:6px;"></i>
                    Aucune séance de progression enregistrée pour aujourd'hui.<br>
                    <a href="#" class="att-no-sessions-link" onclick="document.getElementById('closeAttBtn').click();setTimeout(()=>{document.getElementById('openProgressBtn')?.click()},320);return false;">
                        <i class="fas fa-arrow-right" style="font-size:.65rem;"></i> Ajouter une séance dans la Progression du cours
                    </a>
                </div>
            </div>`;
        } else {
            // Cas 2 : créneau(x) dans schedule → proposer l'ajout rapide
            const slotsHtml = attScheduledSlots.map(slot => {
                const startFmt = fmtTimeStr(slot.start_time);
                const endFmt   = fmtTimeStr(slot.end_time);
                const hoursLbl = slot.hours ? slot.hours + 'h' : '';
                return `<div style="margin-bottom:14px;">
                    <div style="font-size:0.82rem;color:rgba(255,255,255,0.8);margin-bottom:4px;">
                        <i class="fas fa-clock" style="color:#ffab40;margin-right:5px;"></i>
                        Cours prévu aujourd'hui : <strong>${escHtml(startFmt)} → ${escHtml(endFmt)}</strong>${hoursLbl ? ' (' + hoursLbl + ')' : ''}
                    </div>
                    <div style="font-size:0.76rem;color:rgba(255,171,64,0.72);margin-bottom:8px;">
                        Ajoutez la séance de progression pour faire l'appel.
                    </div>
                    <button class="att-add-slot-btn"
                        data-start="${escHtml(slot.start_time)}"
                        data-end="${escHtml(slot.end_time)}"
                        data-hours="${slot.hours}"
                        onclick="addSessionFromSlot(this)">
                        ➕ Ajouter la séance
                    </button>
                </div>`;
            }).join('');
            banner.innerHTML = `<div class="att-sessions-picker">${slotsHtml}</div>`;
        }
        return;
    }

    const shouldEnable = attAvailableSessions.length === 1 || attSelectedCsId !== null;
    if (btnAllPresent) btnAllPresent.disabled = !shouldEnable;
    if (btnAllAbsent)  btnAllAbsent.disabled  = !shouldEnable;

    if (attAvailableSessions.length === 1) {
        const cs = attAvailableSessions[0];
        const timeStr = cs.start_time && cs.end_time
            ? `${fmtTimeStr(cs.start_time)} → ${fmtTimeStr(cs.end_time)}`
            : '';
        const hoursStr = cs.hours ? cs.hours + 'h' : '';
        const doneHtml = cs.already_done
            ? `<span class="att-sess-done-badge"><i class="fas fa-check-circle"></i> Déjà fait</span>` : '';
        banner.innerHTML = `<div class="att-sessions-picker">
            <div class="att-sess-option selected" style="cursor:default;">
                <div style="flex:1">
                    <div class="att-sess-time">${timeStr || '<span style="opacity:.5">Heure non définie</span>'}</div>
                    <div class="att-sess-chapter">${escHtml(cs.chapter)} ${doneHtml}</div>
                </div>
                <span class="att-sess-hours">${hoursStr}</span>
            </div>
        </div>`;
        return;
    }

    // Plusieurs séances : sélecteur radio
    const opts = attAvailableSessions.map(cs => {
        const timeStr = cs.start_time && cs.end_time
            ? `${fmtTimeStr(cs.start_time)} → ${fmtTimeStr(cs.end_time)}`
            : 'Heure non définie';
        const hoursStr = cs.hours ? cs.hours + 'h' : '';
        const isSelected = cs.cs_id === attSelectedCsId;
        const doneHtml = cs.already_done ? `<span class="att-sess-done-badge"><i class="fas fa-check-circle"></i> fait</span>` : '';
        return `<label class="att-sess-option ${isSelected ? 'selected' : ''} ${cs.already_done ? 'done' : ''}">
            <input type="radio" name="att_cs_sel" class="att-sess-radio" value="${cs.cs_id}" ${isSelected ? 'checked' : ''} onchange="selectAttSession(${cs.cs_id})">
            <div style="flex:1">
                <div class="att-sess-time">${escHtml(timeStr)}</div>
                <div class="att-sess-chapter">${escHtml(cs.chapter)} ${doneHtml}</div>
            </div>
            <span class="att-sess-hours">${hoursStr}</span>
        </label>`;
    }).join('');

    banner.innerHTML = `<div class="att-sessions-picker">
        <div class="att-sessions-title"><i class="fas fa-list-ul" style="margin-right:4px;"></i>Séances du jour</div>
        ${opts}
        ${!attSelectedCsId ? '<div class="att-sess-hint"><i class="fas fa-arrow-up" style="font-size:.6rem;"></i> Choisissez une séance pour activer les présences</div>' : ''}
    </div>`;
}

function selectAttSession(csId) {
    const cs = attAvailableSessions.find(s => s.cs_id === csId);
    if (!cs) return;
    attSelectedCsId  = csId;
    attSelectedAttId = cs.att_session_id || null;
    attRecords = attSelectedAttId ? (attRecordsByAtt[attSelectedAttId] || {}) : {};
    renderAttSessionBanner();
    renderStudentList();
    updateAttStats();
    updateAttFooterInfo();
}

function renderStudentList(filter) {
    filter = filter || '';
    const list = document.getElementById('attList');
    if (!list) return;
    if (attAvailableSessions.length > 0 && !attSelectedCsId) {
        list.innerHTML = '<div class="att-loading" style="color:rgba(255,171,64,0.5)"><i class="fas fa-hand-pointer" style="font-size:2rem;animation:none"></i><br><br>Sélectionnez une séance ci-dessus</div>';
        return;
    }
    if (attStudents.length === 0) { list.innerHTML = '<div class="att-loading" style="color:rgba(255,255,255,0.4)"><i class="fas fa-users" style="font-size:2rem;animation:none"></i><br><br>Aucun étudiant inscrit</div>'; return; }
    const filtered = filter ? attStudents.filter(s => s.name.toLowerCase().includes(filter.toLowerCase()) || s.id.toLowerCase().includes(filter.toLowerCase())) : attStudents;
    if (filtered.length === 0) { list.innerHTML = '<div class="att-loading" style="color:rgba(255,255,255,0.4);animation:none">Aucun résultat</div>'; return; }
    list.innerHTML = filtered.map(student => {
        const rec = attRecords[student.id] || {};
        const status = rec.status || '';
        const rowCls = status ? `is-${status}` : '';
        const labels = { present:'✓ Présent', absent:'✗ Absent', late:'⏱ Retard', justified:'📋 Justifié' };
        return `<div class="student-row ${rowCls}" data-student-id="${student.id}">
            <div class="student-row-top">
                <img src="../uploads/avatars/${student.avatar || 'default.png'}" alt="" class="student-avatar" onerror="this.src='../uploads/avatars/default.png'">
                <div style="flex:1"><div class="student-name">${student.name}</div><div class="student-id-badge">${student.id}</div></div>
                <span class="save-indicator" id="save-${student.id}"></span>
            </div>
            <div class="status-pills">
                ${['present','absent','late'].map(s => `<span class="status-pill ${status === s ? 'active' : ''}" data-status="${s}" onclick="setAttStatus('${student.id}','${s}',this)">${labels[s]}</span>`).join('')}
            </div>
        </div>`;
    }).join('');
}

function setAttStatus(studentId, status, pill) {
    if (!attRecords[studentId]) attRecords[studentId] = {};
    attRecords[studentId].status = status;
    const row = document.querySelector(`.student-row[data-student-id="${studentId}"]`);
    if (!row) return;
    row.querySelectorAll('.status-pill').forEach(p => p.classList.toggle('active', p.dataset.status === status));
    row.className = row.className.replace(/is-\w+/g, '').trim() + ` is-${status}`;
    saveRecord(studentId);
    updateAttStats();
    updateAttFooterInfo();
}

function saveRecord(studentId) {
    const rec = attRecords[studentId] || {};
    const status = rec.status || 'absent';
    const indicator = document.getElementById(`save-${studentId}`);
    const courseId = <?php echo json_encode($course_id); ?>;

    let body;
    if (attSelectedAttId) {
        body = `ajax=save_attendance&session_id=${encodeURIComponent(attSelectedAttId)}&student_id=${encodeURIComponent(studentId)}&status=${encodeURIComponent(status)}&justification=${encodeURIComponent(rec.justification || '')}`;
    } else if (attSelectedCsId) {
        body = `ajax=save_attendance&course_id=${encodeURIComponent(courseId)}&cs_id=${encodeURIComponent(attSelectedCsId)}&student_id=${encodeURIComponent(studentId)}&status=${encodeURIComponent(status)}&justification=${encodeURIComponent(rec.justification || '')}`;
    } else {
        body = `ajax=save_attendance&course_id=${encodeURIComponent(courseId)}&student_id=${encodeURIComponent(studentId)}&status=${encodeURIComponent(status)}&justification=${encodeURIComponent(rec.justification || '')}`;
    }

    return fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.session_id && !attSelectedAttId) {
                attSelectedAttId = data.session_id;
                // Mettre à jour le cs en mémoire
                const csIdx = attAvailableSessions.findIndex(s => s.cs_id === attSelectedCsId);
                if (csIdx >= 0) {
                    attAvailableSessions[csIdx].att_session_id = data.session_id;
                    attAvailableSessions[csIdx].already_done   = true;
                }
                renderAttSessionBanner();
            }
            if (indicator) {
                indicator.textContent = data.success ? '✓ Enregistré' : '✗ Erreur';
                indicator.className = `save-indicator show${data.success ? '' : ' error'}`;
                setTimeout(() => { if (indicator) indicator.classList.remove('show'); }, 2000);
            }
            return data;
        });
}

function updateAttStats() {
    const counts = { present:0, absent:0, late:0, justified:0 };
    attStudents.forEach(s => { const st = (attRecords[s.id] || {}).status; if (st && counts[st] !== undefined) counts[st]++; });
    const el = id => document.getElementById(id);
    if (el('statTotal'))     el('statTotal').textContent     = attStudents.length;
    if (el('statPresent'))   el('statPresent').textContent   = counts.present;
    if (el('statAbsent'))    el('statAbsent').textContent    = counts.absent;
    if (el('statLate'))      el('statLate').textContent      = counts.late;
    if (el('statJustified')) el('statJustified').textContent = counts.justified;
}

function updateAttFooterInfo() {
    const marked = attStudents.filter(s => attRecords[s.id]?.status).length;
    const el = document.getElementById('attFooterInfo');
    if (el) el.textContent = `${marked} / ${attStudents.length} étudiants marqués`;
}

function addSessionFromSlot(btn) {
    _slotPending = {
        start_time: btn.dataset.start,
        end_time:   btn.dataset.end,
        hours:      parseFloat(btn.dataset.hours)
    };
    openSlotChapterModal(_slotPending);
}

function showAttAutoChapterMsg(msg) {
    const attList = document.getElementById('attList');
    if (!attList || !attList.parentNode) return;
    const div = document.createElement('div');
    div.style.cssText = 'margin:8px 24px 0;padding:10px 14px;background:rgba(255,171,64,0.12);border:1px solid rgba(255,171,64,0.35);border-radius:8px;color:#ffab40;font-size:0.8rem;display:flex;align-items:flex-start;gap:8px;flex-shrink:0;';
    div.innerHTML = `<i class="fas fa-info-circle" style="margin-top:1px;flex-shrink:0;"></i><span>${escHtml(msg)}</span>`;
    attList.parentNode.insertBefore(div, attList);
    setTimeout(() => { if (div.parentNode) div.remove(); }, 8000);
}

// ── Modal sélection chapitre (ajout depuis EDT) ───────────────
let _slotPending = null;

function openSlotChapterModal(slot) {
    const modal = document.getElementById('slotChapterModal');
    if (!modal) return;
    const startFmt = fmtTimeStr(slot.start_time);
    const endFmt   = fmtTimeStr(slot.end_time);
    modal.querySelector('#slotModalTitle').textContent = 'Ajouter la séance ' + startFmt + ' → ' + endFmt;
    const select        = document.getElementById('slotChapterSelect');
    const selectWrap    = document.getElementById('slotChapterSelectWrap');
    const newChWrap     = document.getElementById('slotNewChWrap');
    const newChName     = document.getElementById('slotNewChName');
    if (newChName) newChName.value = '';
    if (!progChapters || progChapters.length === 0) {
        if (selectWrap) selectWrap.style.display = 'none';
        if (newChWrap) newChWrap.style.display = 'block';
        if (newChName) newChName.required = true;
    } else {
        if (selectWrap) selectWrap.style.display = '';
        select.innerHTML = progChapters.map(ch =>
            `<option value="${ch.id}">${escHtml(ch.title)}</option>`
        ).join('') + '<option value="__new__">➕ Créer un nouveau chapitre</option>';
        if (newChWrap) newChWrap.style.display = 'none';
        if (newChName) newChName.required = false;
        select.onchange = function() {
            const isNew = this.value === '__new__';
            if (newChWrap) newChWrap.style.display = isNew ? 'block' : 'none';
            if (newChName) newChName.required = isNew;
        };
    }
    modal.style.display = 'flex';
}

function confirmSlotChapter() {
    const slot = _slotPending;
    if (!slot) return;
    const select     = document.getElementById('slotChapterSelect');
    const newChNameEl = document.getElementById('slotNewChName');
    const newChName  = newChNameEl ? newChNameEl.value.trim() : '';
    const today      = new Date().toISOString().split('T')[0];
    const confirmBtn = document.getElementById('slotConfirmBtn');
    if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Création…'; }
    const resetBtn = () => {
        if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirmer'; }
    };
    const doAddSession = (chapterId) => {
        fetch(`?ajax=prog_add_session`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ chapter_id: chapterId, course_id: COURSE_ID, start_time: slot.start_time, end_time: slot.end_time, session_date: today })
        })
        .then(r => r.json())
        .then(data => {
            resetBtn();
            if (data.success) {
                closeSlotChapterModal();
                if (data.auto_chapter) showAttAutoChapterMsg(data.message);
                loadAttendance();
            } else {
                alert(data.error || "Erreur lors de l'ajout de la séance");
            }
        })
        .catch(() => { resetBtn(); alert('Erreur réseau'); });
    };
    const hasChapters = progChapters && progChapters.length > 0;
    const isNew = !hasChapters || (select && select.value === '__new__');
    if (isNew) {
        if (!newChName) { alert('Le nom du chapitre est requis'); resetBtn(); return; }
        fetch(`?ajax=prog_add_chapter`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ course_id: COURSE_ID, title: newChName })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) { doAddSession(data.id); }
            else { resetBtn(); alert(data.error || 'Erreur lors de la création du chapitre'); }
        })
        .catch(() => { resetBtn(); alert('Erreur réseau'); });
    } else {
        doAddSession(parseInt(select.value));
    }
}

function closeSlotChapterModal() {
    const modal = document.getElementById('slotChapterModal');
    if (modal) modal.style.display = 'none';
    _slotPending = null;
    const el = document.getElementById('slotNewChName');
    if (el) el.value = '';
}

// ════════════════════════════════════════════════════════════
// PROGRESSION — fonctions (scope global, NOUVEAU)
// ════════════════════════════════════════════════════════════
let progChapters = [];
const COURSE_ID  = <?php echo json_encode($course_id); ?>;
const CURRENT_ACADEMIC_YEAR = <?php echo json_encode($current_year); ?>;

// Année actuellement sélectionnée dans le sélecteur d'archives
function getSelectedYear() {
    return document.querySelector('select[name="year"]')?.value || CURRENT_ACADEMIC_YEAR;
}
// true si on consulte une année différente de l'année courante (lecture seule)
function isArchivedYearSelected() {
    return getSelectedYear() !== CURRENT_ACADEMIC_YEAR;
}
// Query string à ajouter aux appels de lecture (?year=...)
function yearQueryString() {
    const yr = getSelectedYear();
    return yr ? '&year=' + encodeURIComponent(yr) : '';
}
// Bloque une action d'écriture (ajout/modif/suppression) si année archivée
function blockIfArchived() {
    if (isArchivedYearSelected()) {
        alert("Vous consultez l'année " + getSelectedYear() + " (archivée). Revenez à l'année courante pour modifier la progression.");
        return true;
    }
    return false;
}

function loadProgress() {
    document.getElementById('progBody').innerHTML = '<div class="prog-loading"><i class="fas fa-circle-notch"></i><br><br>Chargement…</div>';
    const _yr = document.querySelector('select[name="year"]')?.value || '';
    const _yrQ = _yr ? '&year=' + encodeURIComponent(_yr) : '';
    fetch(`?ajax=get_progress&course_id=${COURSE_ID}` + _yrQ)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { document.getElementById('progBody').innerHTML = '<div class="prog-loading" style="color:#ff5252">Erreur de chargement</div>'; return; }
            progChapters = data.chapters || [];
            updateProgSummary(data.total_hours, data.total_sessions, data.planned_hours);
            renderProgChapters();
        })
        .catch(() => { document.getElementById('progBody').innerHTML = '<div class="prog-loading" style="color:#ff5252">Erreur de chargement</div>'; });
}

function updateProgSummary(totalHours, totalSessions, plannedHours) {
    const h = parseFloat(totalHours) || 0;
    const el = id => document.getElementById(id);
    if (el('progTotalHours'))    el('progTotalHours').textContent    = (h % 1 === 0 ? h : h.toFixed(1)) + 'h';
    if (el('progTotalSessions')) el('progTotalSessions').textContent = totalSessions || 0;
    if (el('progPlannedHours'))  el('progPlannedHours').textContent  = plannedHours > 0 ? plannedHours + 'h' : '—';
}

function renderProgChapters() {
    const body = document.getElementById('progBody');
    let html = `<button class="prog-add-ch-btn" onclick="showProgChapterForm()"><i class="fas fa-plus"></i> Nouveau chapitre</button>
                <div class="prog-inline-form" id="progNewChForm">
                    <label>Titre du chapitre *</label>
                    <input type="text" id="progNewChTitle" placeholder="ex: Chapitre 1 — Introduction" maxlength="255">
                    <div class="prog-form-actions">
                        <button class="prog-btn-sm prog-btn-cancel" onclick="hideProgChapterForm()">Annuler</button>
                        <button class="prog-btn-sm prog-btn-save" onclick="saveNewProgChapter()"><i class="fas fa-check"></i> Ajouter</button>
                    </div>
                </div>`;

    if (!progChapters.length) {
        html += '<div class="prog-empty"><i class="fas fa-layer-group"></i><p>Aucun chapitre. Créez le premier chapitre pour commencer.</p></div>';
    } else {
        html += progChapters.map(ch => renderProgChapter(ch)).join('');
    }

    body.innerHTML = html;
}

function renderProgChapter(ch) {
    const chHours  = ch.sessions.reduce((s, x) => s + parseFloat(x.hours || 0), 0);
    const doneH    = parseFloat(ch.hours_done || 0);
    const totalStr = chHours > 0 ? (chHours % 1 === 0 ? chHours : chHours.toFixed(1)) + 'h' : '0h';
    const doneStr  = doneH > 0 ? (doneH % 1 === 0 ? doneH : doneH.toFixed(1)) + 'h' : '0h';
    const hLabel   = doneH > 0 ? `${doneStr} / ${totalStr}` : totalStr;
    const sessHtml = ch.sessions.length
        ? ch.sessions.map(s => renderProgSession(s, ch.id)).join('')
        : '<div style="font-size:.8rem;color:rgba(255,255,255,.25);padding:6px 0;">Aucune séance</div>';

    return `
    <div class="prog-chapter" id="prog-ch-${ch.id}">
        <div class="prog-ch-header" onclick="toggleProgChapter(${ch.id}, event)">
            <span class="prog-ch-num">Ch.${ch.order_num}</span>
            <span class="prog-ch-title" id="prog-ch-title-${ch.id}">${escHtml(ch.title)}</span>
            <span class="prog-ch-hours"><i class="fas fa-clock" style="font-size:.65rem;margin-right:3px;"></i>${hLabel}</span>
            <div class="prog-ch-actions" onclick="event.stopPropagation()">
                <button class="prog-icon-btn" onclick="editProgChapter(${ch.id})" title="Modifier"><i class="fas fa-pen"></i></button>
                <button class="prog-icon-btn del" onclick="deleteProgChapter(${ch.id})" title="Supprimer"><i class="fas fa-trash"></i></button>
            </div>
            <i class="fas fa-chevron-down prog-ch-toggle" id="prog-ch-toggle-${ch.id}"></i>
        </div>
        <div class="prog-ch-body" id="prog-ch-body-${ch.id}">
            <div class="prog-inline-form" id="edit-prog-ch-${ch.id}">
                <label>Titre du chapitre</label>
                <input type="text" id="edit-prog-ch-input-${ch.id}" value="${escHtml(ch.title)}" maxlength="255">
                <div class="prog-form-actions">
                    <button class="prog-btn-sm prog-btn-cancel" onclick="cancelEditProgChapter(${ch.id})">Annuler</button>
                    <button class="prog-btn-sm prog-btn-save" onclick="saveEditProgChapter(${ch.id})"><i class="fas fa-check"></i> Sauvegarder</button>
                </div>
            </div>
            <div id="prog-sess-list-${ch.id}">${sessHtml}</div>
            <div class="prog-inline-form" id="prog-new-sess-${ch.id}">
                <div class="prog-form-grid">
                    <div><label>Titre *</label><input type="text" id="ps-title-${ch.id}" placeholder="Titre de la séance" maxlength="255"></div>
                    <div><label>Date *</label><input type="date" id="ps-date-${ch.id}" required></div>
                    <div><label>Heure début *</label><input type="time" id="ps-start-${ch.id}" required></div>
                    <div><label>Heure fin *</label><input type="time" id="ps-end-${ch.id}" required></div>
                </div>
                <div style="font-size:0.78rem;color:rgba(255,255,255,0.4);margin-bottom:6px;" id="ps-dur-${ch.id}">Durée : —</div>
                <div><label>Description (optionnel)</label><textarea id="ps-desc-${ch.id}" placeholder="Notions abordées…" rows="2"></textarea></div>
                <div class="prog-form-actions">
                    <button class="prog-btn-sm prog-btn-cancel" onclick="cancelNewProgSess(${ch.id})">Annuler</button>
                    <button class="prog-btn-sm prog-btn-save" onclick="saveNewProgSess(${ch.id})"><i class="fas fa-check"></i> Ajouter</button>
                </div>
            </div>
            <button class="prog-add-sess-btn" onclick="showNewProgSess(${ch.id})"><i class="fas fa-plus"></i> Ajouter une séance</button>
        </div>
    </div>`;
}

function renderProgSession(s, chId) {
    const h = parseFloat(s.hours || 0);
    const hLabel = (h % 1 === 0 ? h : h.toFixed(1)) + 'h';
    const dateHtml = s.session_date
        ? `<span class="prog-sess-date"><i class="fas fa-calendar" style="font-size:.6rem;margin-right:2px;"></i>${fmtDate(s.session_date)}</span>` : '';
    const timeHtml = s.start_time && s.end_time
        ? `<span class="prog-sess-time-range">${fmtTimeStr(s.start_time)} → ${fmtTimeStr(s.end_time)}</span>` : '';
    const descToggle = s.description
        ? `<button class="prog-sess-desc-toggle" id="prog-desc-toggle-${s.id}" onclick="toggleProgDesc(${s.id})"><i class="fas fa-chevron-down"></i> Voir le contenu</button>
           <div class="prog-sess-desc-body" id="prog-desc-body-${s.id}">${escHtml(s.description)}</div>` : '';
    const startVal = s.start_time ? s.start_time.substring(0, 5) : '';
    const endVal   = s.end_time   ? s.end_time.substring(0, 5)   : '';
    const durInitial = h > 0 ? `Durée : ${hLabel}` : 'Durée : —';

    // Badges — cours effectué et statut appel
    let badgesHtml = '';
    if (s.session_date) {
        const todayStr = new Date().toISOString().split('T')[0];
        badgesHtml += '<span class="prog-sess-done-badge prog-badge-done"><i class="fas fa-check-circle"></i> Cours effectué</span>';
        if (s.attendance_session_id) {
            badgesHtml += ' <span class="prog-sess-done-badge prog-badge-att-done"><i class="fas fa-check-circle"></i> Appel fait</span>';
        } else if (s.session_date < todayStr) {
            badgesHtml += ' <span class="prog-sess-done-badge prog-badge-att-missing"><i class="fas fa-exclamation-triangle"></i> Appel manquant</span>';
        } else {
            badgesHtml += ' <span class="prog-sess-done-badge prog-badge-upcoming"><i class="fas fa-calendar-alt"></i> À venir</span>';
        }
    }

    return `
    <div class="prog-sess-item" id="prog-sess-${s.id}">
        <span class="prog-sess-num">S${s.session_number}</span>
        <div class="prog-sess-main">
            <div class="prog-inline-form" id="edit-prog-sess-${s.id}">
                <div class="prog-form-grid">
                    <div><label>Titre *</label><input type="text" id="pes-title-${s.id}" value="${escHtml(s.title)}" maxlength="255"></div>
                    <div><label>Date</label><input type="date" id="pes-date-${s.id}" value="${s.session_date || ''}"></div>
                    <div><label>Heure début</label><input type="time" id="pes-start-${s.id}" value="${startVal}"></div>
                    <div><label>Heure fin</label><input type="time" id="pes-end-${s.id}" value="${endVal}"></div>
                </div>
                <div style="font-size:0.78rem;color:rgba(255,255,255,0.4);margin-bottom:6px;" id="pes-dur-${s.id}">${durInitial}</div>
                <div><label>Description</label><textarea id="pes-desc-${s.id}" rows="2">${escHtml(s.description || '')}</textarea></div>
                <div class="prog-form-actions">
                    <button class="prog-btn-sm prog-btn-cancel" onclick="cancelEditProgSess(${s.id})">Annuler</button>
                    <button class="prog-btn-sm prog-btn-save" onclick="saveEditProgSess(${s.id})"><i class="fas fa-check"></i> Sauvegarder</button>
                </div>
            </div>
            <div id="prog-sess-display-${s.id}">
                <div class="prog-sess-top">
                    <span class="prog-sess-title">${escHtml(s.title)}</span>
                    ${timeHtml || `<span class="prog-sess-hours"><i class="fas fa-clock" style="font-size:.6rem;margin-right:2px;"></i>${hLabel}</span>`}
                    ${dateHtml}
                    ${badgesHtml}
                </div>
                ${descToggle}
            </div>
        </div>
        <div class="prog-sess-actions">
            <button class="prog-icon-btn" onclick="editProgSess(${s.id})" title="Modifier"><i class="fas fa-pen"></i></button>
            <button class="prog-icon-btn del" onclick="deleteProgSess(${s.id}, ${chId})" title="Supprimer"><i class="fas fa-trash"></i></button>
        </div>
    </div>`;
}

// Toggles
function toggleProgChapter(chId, e) {
    if (e && e.target.closest('.prog-ch-actions')) return;
    document.getElementById('prog-ch-body-' + chId)?.classList.toggle('open');
    document.getElementById('prog-ch-toggle-' + chId)?.classList.toggle('open');
}
function toggleProgDesc(sessId) {
    const body = document.getElementById('prog-desc-body-' + sessId);
    const toggle = document.getElementById('prog-desc-toggle-' + sessId);
    if (!body || !toggle) return;
    body.classList.toggle('open');
    toggle.classList.toggle('open');
    toggle.innerHTML = body.classList.contains('open')
        ? '<i class="fas fa-chevron-down"></i> Masquer'
        : '<i class="fas fa-chevron-down"></i> Voir le contenu';
}

// Nouveau chapitre
function showProgChapterForm() {
    if (blockIfArchived()) return;
    document.getElementById('progNewChForm').classList.add('open');
    document.getElementById('progNewChTitle').focus();
}
function hideProgChapterForm() {
    document.getElementById('progNewChForm').classList.remove('open');
    document.getElementById('progNewChTitle').value = '';
}
function saveNewProgChapter() {
    if (blockIfArchived()) return;
    const title = document.getElementById('progNewChTitle').value.trim();
    if (!title) { alert('Le titre est requis'); return; }
    fetch(`?ajax=prog_add_chapter`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ course_id: COURSE_ID, title, year: getSelectedYear() }) })
        .then(r => r.json()).then(data => { if (data.success) { hideProgChapterForm(); loadProgress(); } else alert(data.error || 'Erreur lors de l\'ajout'); });
}

// Modifier chapitre
function editProgChapter(chId) {
    if (blockIfArchived()) return;
    document.getElementById('prog-ch-body-' + chId)?.classList.add('open');
    document.getElementById('prog-ch-toggle-' + chId)?.classList.add('open');
    document.getElementById('edit-prog-ch-' + chId)?.classList.add('open');
    document.getElementById('edit-prog-ch-input-' + chId)?.focus();
}
function cancelEditProgChapter(chId) { document.getElementById('edit-prog-ch-' + chId)?.classList.remove('open'); }
function saveEditProgChapter(chId) {
    if (blockIfArchived()) return;
    const title = document.getElementById('edit-prog-ch-input-' + chId)?.value.trim();
    if (!title) { alert('Le titre est requis'); return; }
    fetch(`?ajax=prog_edit_chapter`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: chId, title }) })
        .then(r => r.json()).then(data => {
            if (data.success) {
                const el = document.getElementById('prog-ch-title-' + chId);
                if (el) el.textContent = title;
                cancelEditProgChapter(chId);
            } else alert(data.error || 'Erreur lors de la modification');
        });
}

// ── Modal confirmation suppression ───────────────────────────
let _deleteConfirmCallback = null;

function showDeleteConfirmModal(title, message, callback, btnLabel) {
    document.getElementById('deleteConfirmTitle').textContent   = title;
    document.getElementById('deleteConfirmMessage').textContent = message;
    _deleteConfirmCallback = callback;
    const btn = document.getElementById('deleteConfirmBtn');
    if (btn) btn.innerHTML = `<i class="fas fa-trash"></i> ${btnLabel || 'Supprimer'}`;
    document.getElementById('deleteConfirmModal').style.display = 'flex';
}

function closeDeleteConfirmModal() {
    document.getElementById('deleteConfirmModal').style.display = 'none';
    _deleteConfirmCallback = null;
}

function executeDeleteConfirm() {
    const cb = _deleteConfirmCallback;
    closeDeleteConfirmModal();
    if (cb) cb();
}

// Supprimer chapitre (ownership vérifié côté PHP)
function deleteProgChapter(chId) {
    if (blockIfArchived()) return;
    fetch(`?ajax=check_chapter_delete&id=${chId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success === false) { alert('Accès refusé'); return; }
            if (data.archived) { alert(data.error || 'Ce chapitre appartient à une année archivée.'); return; }
            const doDelete = () => {
                fetch(`?ajax=prog_delete_chapter&id=${chId}&force=1`)
                    .then(r => r.json())
                    .then(d => { if (d.success) loadProgress(); else alert(d.error || 'Erreur lors de la suppression'); });
            };
            if (!data.needs_confirm) {
                showDeleteConfirmModal(
                    'Supprimer ce chapitre ?',
                    'Cette action est irréversible.',
                    doDelete
                );
            } else {
                showDeleteConfirmModal(
                    'Supprimer ce chapitre ?',
                    data.message,
                    doDelete,
                    'Supprimer quand même'
                );
            }
        })
        .catch(() => alert('Erreur réseau'));
}

// Nouvelle séance
// Calcul automatique durée à partir des champs time
function setupTimeDurationCalc(startId, endId, durId) {
    const update = () => {
        const s   = document.getElementById(startId)?.value;
        const e   = document.getElementById(endId)?.value;
        const dur = document.getElementById(durId);
        if (!dur) return;
        if (!s || !e) { dur.textContent = 'Durée : —'; dur.style.color = 'rgba(255,255,255,0.4)'; return; }
        const [sh, sm] = s.split(':').map(Number);
        const [eh, em] = e.split(':').map(Number);
        const mins = (eh * 60 + em) - (sh * 60 + sm);
        if (mins > 0) {
            const h = (mins / 60);
            dur.textContent = `Durée : ${h % 1 === 0 ? h : h.toFixed(1)}h`;
            dur.style.color = 'rgba(0,230,118,0.7)';
        } else {
            dur.textContent = mins === 0 ? 'Durée : —' : 'Durée : ⚠ heure fin avant heure début';
            dur.style.color = 'rgba(255,82,82,0.7)';
        }
    };
    document.getElementById(startId)?.addEventListener('change', update);
    document.getElementById(endId)?.addEventListener('change', update);
}

function showNewProgSess(chId) {
    if (blockIfArchived()) return;
    document.getElementById('prog-ch-body-' + chId)?.classList.add('open');
    document.getElementById('prog-ch-toggle-' + chId)?.classList.add('open');
    document.getElementById('prog-new-sess-' + chId)?.classList.add('open');
    const dateField = document.getElementById('ps-date-' + chId);
    if (dateField && !dateField.value) dateField.value = new Date().toISOString().split('T')[0];
    document.getElementById('ps-title-' + chId)?.focus();
    setupTimeDurationCalc('ps-start-' + chId, 'ps-end-' + chId, 'ps-dur-' + chId);
}
function cancelNewProgSess(chId) {
    document.getElementById('prog-new-sess-' + chId)?.classList.remove('open');
    ['ps-title-', 'ps-desc-'].forEach(p => { const el = document.getElementById(p + chId); if (el) el.value = ''; });
    ['ps-start-', 'ps-end-', 'ps-date-'].forEach(p => { const el = document.getElementById(p + chId); if (el) el.value = ''; });
    const dur = document.getElementById('ps-dur-' + chId);
    if (dur) { dur.textContent = 'Durée : —'; dur.style.color = 'rgba(255,255,255,0.4)'; }
}
function saveNewProgSess(chId) {
    const title      = document.getElementById('ps-title-' + chId)?.value.trim();
    const start_time = document.getElementById('ps-start-' + chId)?.value;
    const end_time   = document.getElementById('ps-end-' + chId)?.value;
    const desc       = document.getElementById('ps-desc-' + chId)?.value.trim();
    const date       = document.getElementById('ps-date-' + chId)?.value;
    if (!title)      { alert('Le titre est requis'); return; }
    if (!date)       { alert('La date de la séance est obligatoire'); return; }
    if (!start_time) { alert("L'heure de début est obligatoire"); return; }
    if (!end_time)   { alert("L'heure de fin est obligatoire"); return; }
    const [sh, sm] = start_time.split(':').map(Number);
    const [eh, em] = end_time.split(':').map(Number);
    const mins = (eh * 60 + em) - (sh * 60 + sm);
    if (mins <= 0)  { alert("L'heure de fin doit être après l'heure de début"); return; }
    if (mins < 30)  { alert('La durée minimale est de 30 minutes'); return; }
    if (mins > 480) { alert('La durée maximale est de 8 heures'); return; }
    fetch(`?ajax=prog_add_session`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ chapter_id: chId, course_id: COURSE_ID, title, description: desc, start_time, end_time, session_date: date, year: getSelectedYear() }) })
        .then(r => r.json()).then(data => {
            if (data.success) { cancelNewProgSess(chId); updateProgSummary(data.total_hours, null, null); loadProgress(); }
            else alert(data.error || "Erreur lors de l'ajout");
        });
}

// Modifier séance
function editProgSess(sessId) {
    if (blockIfArchived()) return;
    document.getElementById('edit-prog-sess-' + sessId)?.classList.add('open');
    document.getElementById('prog-sess-display-' + sessId).style.display = 'none';
    setupTimeDurationCalc('pes-start-' + sessId, 'pes-end-' + sessId, 'pes-dur-' + sessId);
}
function cancelEditProgSess(sessId) {
    document.getElementById('edit-prog-sess-' + sessId)?.classList.remove('open');
    document.getElementById('prog-sess-display-' + sessId).style.display = 'block';
}
function saveEditProgSess(sessId) {
    if (blockIfArchived()) return;
    const title      = document.getElementById('pes-title-' + sessId)?.value.trim();
    const start_time = document.getElementById('pes-start-' + sessId)?.value;
    const end_time   = document.getElementById('pes-end-' + sessId)?.value;
    const desc       = document.getElementById('pes-desc-' + sessId)?.value.trim();
    const date       = document.getElementById('pes-date-' + sessId)?.value;
    if (!title) { alert('Le titre est requis'); return; }

    let timesPayload = {};
    if (start_time && end_time) {
        const [sh, sm] = start_time.split(':').map(Number);
        const [eh, em] = end_time.split(':').map(Number);
        const mins = (eh * 60 + em) - (sh * 60 + sm);
        if (mins <= 0)  { alert("L'heure de fin doit être après l'heure de début"); return; }
        if (mins < 30)  { alert('La durée minimale est de 30 minutes'); return; }
        if (mins > 480) { alert('La durée maximale est de 8 heures'); return; }
        timesPayload = { start_time, end_time };
    } else if (start_time || end_time) {
        alert('Remplissez les deux heures (début ET fin) ou laissez-les vides'); return;
    }

    fetch(`?ajax=prog_edit_session`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: sessId, course_id: COURSE_ID, title, description: desc, session_date: date, ...timesPayload }) })
        .then(r => r.json()).then(data => {
            if (data.success) { updateProgSummary(data.total_hours, null, null); loadProgress(); }
            else alert(data.error || 'Erreur lors de la modification');
        });
}

// Supprimer séance
function deleteProgSess(sessId, chId) {
    if (blockIfArchived()) return;
    fetch(`?ajax=check_session_delete&id=${sessId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success === false) { alert('Accès refusé'); return; }
            if (data.archived) { alert(data.error || 'Cette séance appartient à une année archivée.'); return; }
            const doDelete = () => {
                fetch(`?ajax=prog_delete_session&id=${sessId}&course_id=${COURSE_ID}&force=1`)
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) { updateProgSummary(d.total_hours, null, null); loadProgress(); }
                        else alert('Erreur lors de la suppression');
                    });
            };
            if (!data.needs_confirm) {
                showDeleteConfirmModal(
                    'Supprimer cette séance ?',
                    'Cette action est irréversible.',
                    doDelete
                );
            } else {
                showDeleteConfirmModal(
                    'Supprimer cette séance ?',
                    data.message,
                    doDelete,
                    'Supprimer quand même'
                );
            }
        })
        .catch(() => alert('Erreur réseau'));
}

// ── Utilitaires ──────────────────────────────────────────────
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

// ── Fonctions utilitaires originales (inchangées) ─────────────
function resetAnimation(element) { element.style.animation = 'none'; element.offsetHeight; element.style.animation = null; }
function scrollToBottom() { const mg = document.querySelector('.message-grid'); if (mg) mg.scrollTop = mg.scrollHeight; }
function confirmDelete(messageId) { if (confirm('Voulez-vous vraiment supprimer ce message ?')) { document.querySelector(`form[data-message-id="${messageId}"]`).submit(); } }
function handleUploadError(error) { console.error('Erreur de téléchargement:', error); alert('Une erreur est survenue lors du téléchargement du fichier. Veuillez réessayer.'); }
const DEBUG = false;
function debug(message) { if (DEBUG) { console.log(`[Debug] ${message}`); } }

function openImagePreview(imageSrc) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    modal.style.display = 'flex';
    modalImg.src = imageSrc;
    document.body.style.overflow = 'hidden';
}
function closeImagePreview() {
    document.getElementById('imageModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}
document.getElementById('imageModal').addEventListener('click', function(e) { if (e.target === this) closeImagePreview(); });
document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && document.getElementById('imageModal').style.display === 'flex') closeImagePreview(); });

function previewFiles(input) {
    const previewContainer = document.getElementById('file-preview-container');
    if (input.files && input.files.length > 0) {
        Array.from(input.files).forEach((file, index) => {
            const fileItem = document.createElement('div');
            fileItem.className = 'file-preview-item';
            const extension = file.name.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension);
            let previewContent = '';
            if (isImage) {
                const imageUrl = URL.createObjectURL(file);
                previewContent = `<img src="${imageUrl}" alt="${file.name}" class="file-preview-image">`;
            } else {
                const iconColor = getFileIconColor(extension);
                previewContent = `<div class="preview-icon" style="background: ${iconColor}">${extension}</div>`;
            }
            fileItem.innerHTML = `${previewContent}<div class="preview-info"><div class="filename">${file.name}</div><div class="filesize">${formatFileSize(file.size)}</div></div><div class="remove-file" onclick="removeFile(${index}, this)">×</div>`;
            previewContainer.appendChild(fileItem);
        });
    }
}
function getFileIconColor(extension) {
    const colors = { pdf:'linear-gradient(135deg,#ff5722,#f44336)', doc:'linear-gradient(135deg,#2196f3,#1976d2)', docx:'linear-gradient(135deg,#2196f3,#1976d2)', xls:'linear-gradient(135deg,#4caf50,#388e3c)', xlsx:'linear-gradient(135deg,#4caf50,#388e3c)', ppt:'linear-gradient(135deg,#ff9800,#f57c00)', pptx:'linear-gradient(135deg,#ff9800,#f57c00)', txt:'linear-gradient(135deg,#607d8b,#455a64)', zip:'linear-gradient(135deg,#795548,#5d4037)', rar:'linear-gradient(135deg,#795548,#5d4037)', mp3:'linear-gradient(135deg,#9c27b0,#7b1fa2)', mp4:'linear-gradient(135deg,#e91e63,#c2185b)' };
    return colors[extension] || 'linear-gradient(135deg,#9e9e9e,#616161)';
}
function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' octets';
    else if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' Ko';
    else return (bytes / 1048576).toFixed(1) + ' Mo';
}
function removeFile(index, element) {
    const input = document.querySelector('input[type="file"]');
    const dt = new DataTransfer();
    Array.from(input.files).filter((file, i) => i !== index).forEach(file => dt.items.add(file));
    input.files = dt.files;
    element.parentElement.remove();
    const fileLabel = input.previousElementSibling;
    fileLabel.textContent = input.files.length > 0 ? `${input.files.length} fichier(s) sélectionné(s)` : 'Sélectionner des documents';
}
</script>
<script>
let lastMessageId = 0;

function loadMessages(force = false) {
    const _yr = document.querySelector('select[name="year"]')?.value || '';
    const _yrQ = _yr ? '&year=' + encodeURIComponent(_yr) : '';
    fetch('../includes/fetch_messages.php?course_id=' + <?php echo json_encode($course_id); ?> + _yrQ)
        .then(r => r.json())
        .then(response => {
            if (!Array.isArray(response) || response.length === 0) {
                if (force) document.querySelector('.message-grid').innerHTML = '';
                return;
            }
            const serverLastId = Math.max(...response.map(msg => msg.discussion_id));
            if (!force && serverLastId <= lastMessageId) return;
            const messageGrid = document.querySelector('.message-grid');
            messageGrid.innerHTML = '';
            response.forEach(msg => {
                const messageClass = (msg.sender_id == <?php echo json_encode($user_id); ?>) ? 'own' : '';
                const avatarPath = '../uploads/avatars/' + msg.avatar;
                const deleteButton = (msg.sender_id == <?php echo json_encode($user_id); ?> && (Date.now()/1000 - new Date(msg.created_at).getTime()/1000) <= 1200)
                    ? `<button class="delete-message" data-id="${msg.discussion_id}">Supprimer</button>` : '';
                const div = document.createElement('div');
                div.innerHTML = `
                    <div class="message-card ${messageClass}">
                        <img src="${escHtml(avatarPath)}" alt="Avatar" width="40" height="40">
                        <div class="message-info">
                            <h4>${escHtml(msg.name)} <em>${escHtml(msg.created_at)}</em></h4>
                            <p>${escHtml(msg.message)}</p>
                            ${msg.document_id ? `<div class="file-preview"><a href="../uploads/${encodeURIComponent(msg.file_path)}" download>${escHtml(msg.original_name || msg.file_path)}</a></div>` : ''}
                            <div class="action-buttons">${deleteButton}</div>
                        </div>
                    </div>`;
                messageGrid.appendChild(div.firstElementChild);
            });
            lastMessageId = serverLastId;
            messageGrid.scrollTop = messageGrid.scrollHeight;
        })
        .catch(() => {});
}

setInterval(loadMessages, 5000);
loadMessages();

document.addEventListener('click', function(e) {
    const btn = e.target.closest('.delete-message');
    if (!btn) return;
    const messageId = btn.dataset.id;
    const messageDiv = btn.closest('.message-card');
    if (confirm("Êtes-vous sûr de vouloir supprimer ce message ?")) {
        fetch('', { method: 'POST', body: new URLSearchParams({ delete_id: messageId, delete_type: 'message' }) })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                messageDiv.style.transition = 'opacity 0.3s';
                messageDiv.style.opacity = '0';
                setTimeout(() => messageDiv.remove(), 300);
            })
            .catch(() => alert('Erreur lors de la suppression.'));
    }
});
</script>

<!-- ═══════════ JS DEVOIRS (PROFESSEUR) ═══════════ -->
<script>
function ouvrirDevoirs() {
    document.getElementById('mDevoirs').classList.add('open');
    chargerDevoirs();
}
function fermerDevoirs() {
    document.getElementById('mDevoirs').classList.remove('open');
}
document.getElementById('mDevoirs').addEventListener('click', function(e) {
    if (e.target === this) fermerDevoirs();
});

function _assignYearQ() {
    const yr = document.querySelector('select[name="year"]')?.value || '';
    return yr ? '&year=' + encodeURIComponent(yr) : '';
}

function chargerDevoirs() {
    const list = document.getElementById('assignList');
    const formSection = document.getElementById('assignFormSection');
    if (formSection) {
        if (isArchivedYearSelected()) {
            formSection.innerHTML = `<div style="text-align:center;color:#888;padding:10px 0;"><i class="fas fa-lock"></i> Année ${getSelectedYear()} archivée — création de devoir désactivée.</div>`;
        } else if (!document.getElementById('assignTitle')) {
            // Restaurer le formulaire si on revient sur l'année courante après l'avoir masqué
            formSection.innerHTML = `<h3>Créer un devoir</h3>
                <input type="text" id="assignTitle" placeholder="Titre du devoir *">
                <textarea id="assignDesc" placeholder="Description (optionnel)"></textarea>
                <label style="font-size:.85rem;color:#666;display:block;margin-bottom:4px">Date limite *</label>
                <input type="datetime-local" id="assignDueDate">
                <label style="font-size:.85rem;color:#666;display:block;margin:8px 0 4px">Fichier joint (optionnel, max 10 MB)</label>
                <input type="file" id="assignFile" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.rar">
                <div style="margin-top:12px">
                    <button class="assign-submit-btn" onclick="creerDevoir()"><i class="fas fa-plus"></i> Créer le devoir</button>
                </div>
                <div id="assignFormMsg" style="margin-top:8px;font-size:.88rem"></div>`;
        }
    }
    list.innerHTML = '<div class="assign-empty"><i class="fas fa-circle-notch fa-spin"></i> Chargement…</div>';
    fetch(`?course_id=${COURSE_ID}&action=get_assignments${_assignYearQ()}`)
        .then(r => r.json())
        .then(data => renderDevoirs(data))
        .catch(() => { list.innerHTML = '<div class="assign-empty">Erreur de chargement.</div>'; });
}

function renderDevoirs(devoirs) {
    const list = document.getElementById('assignList');
    if (!devoirs || devoirs.error) {
        list.innerHTML = '<div class="assign-empty">Erreur : ' + (devoirs?.error || 'inconnue') + '</div>';
        return;
    }
    if (devoirs.length === 0) {
        list.innerHTML = '<div class="assign-empty">Aucun devoir créé.</div>';
        return;
    }
    list.innerHTML = devoirs.map(d => {
        const due = new Date(d.due_date);
        const dueStr = due.toLocaleDateString('fr-FR', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'});
        const expired = due < new Date();
        return `<div class="assign-card" id="acard-${d.id}">
            <div class="assign-card-title">${escHtml(d.title)}</div>
            <div class="assign-card-meta">
                Date limite : <strong style="color:${expired?'#e74c3c':'#27ae60'}">${dueStr}</strong>
                &nbsp;|&nbsp; Rendus : <strong>${d.nb_rendus}</strong>/${d.nb_etudiants}
                ${d.original_name ? `&nbsp;|&nbsp; <i class="fas fa-paperclip"></i> ${escHtml(d.original_name)}` : ''}
            </div>
            ${d.description ? `<div style="font-size:.87rem;color:#555;margin-bottom:8px">${escHtml(d.description)}</div>` : ''}
            <div class="assign-card-actions">
                <button class="btn-voir-rendus" onclick="voirRendus(${d.id}, this)"><i class="fas fa-eye"></i> Voir les rendus</button>
                <button class="btn-dl-zip" onclick="telechargerZip(${d.id})"><i class="fas fa-file-archive"></i> Télécharger tout</button>
                ${isArchivedYearSelected() ? '' : `<button class="btn-del-assign" onclick="supprimerDevoir(${d.id})"><i class="fas fa-trash"></i> Supprimer</button>`}
            </div>
            <div class="assign-rendus-list" id="rendus-${d.id}" style="display:none"></div>
        </div>`;
    }).join('');
}

function creerDevoir() {
    if (blockIfArchived()) return;
    const title    = document.getElementById('assignTitle').value.trim();
    const dueDate  = document.getElementById('assignDueDate').value;
    const desc     = document.getElementById('assignDesc').value.trim();
    const fileInput = document.getElementById('assignFile');
    const msgEl    = document.getElementById('assignFormMsg');

    if (!title || !dueDate) { msgEl.style.color = '#e74c3c'; msgEl.textContent = 'Titre et date limite obligatoires.'; return; }

    const fd = new FormData();
    fd.append('title', title);
    fd.append('due_date', dueDate);
    fd.append('description', desc);
    if (fileInput.files[0]) fd.append('file', fileInput.files[0]);

    msgEl.style.color = '#888'; msgEl.textContent = 'Envoi en cours…';

    fetch(`?course_id=${COURSE_ID}&action=create_assignment${_assignYearQ()}`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                msgEl.style.color = '#27ae60'; msgEl.textContent = 'Devoir créé !';
                document.getElementById('assignTitle').value = '';
                document.getElementById('assignDesc').value = '';
                document.getElementById('assignDueDate').value = '';
                fileInput.value = '';
                chargerDevoirs();
            } else {
                msgEl.style.color = '#e74c3c'; msgEl.textContent = res.error || 'Erreur inconnue.';
            }
        })
        .catch(() => { msgEl.style.color = '#e74c3c'; msgEl.textContent = 'Erreur réseau.'; });
}

function voirRendus(assignId, btn) {
    const box = document.getElementById('rendus-' + assignId);
    if (box.style.display !== 'none') { box.style.display = 'none'; return; }
    box.style.display = 'block';
    box.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Chargement…';
    fetch(`?course_id=${COURSE_ID}&action=get_assignment_submissions&assignment_id=${assignId}`)
        .then(r => r.json())
        .then(data => renderRendus(data, box, assignId))
        .catch(() => { box.innerHTML = '<div style="color:#e74c3c">Erreur de chargement.</div>'; });
}

function renderRendus(rendus, box, assignId) {
    if (!rendus || rendus.error) { box.innerHTML = '<div style="color:#e74c3c">Erreur.</div>'; return; }
    if (rendus.length === 0) { box.innerHTML = '<div style="color:#aaa;font-style:italic;padding:6px 0">Aucun rendu pour l\'instant.</div>'; return; }
    box.innerHTML = rendus.map(r => {
        const sub = new Date(r.submitted_at).toLocaleDateString('fr-FR', {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'});
        return `<div class="rendu-row">
            <span><strong>${escHtml(r.student_name)}</strong> — <a href="../uploads/assignments/submissions/${encodeURIComponent(r.file_path)}" target="_blank" style="color:#2980b9">${escHtml(r.file_name)}</a> (${Math.round(r.file_size/1024)} Ko)</span>
            <span style="color:#888;font-size:.8rem">${sub}</span>
        </div>`;
    }).join('');
}

function telechargerZip(assignId) {
    window.location.href = `?course_id=${COURSE_ID}&action=download_all_submissions&assignment_id=${assignId}`;
}

function supprimerDevoir(assignId) {
    if (!confirm('Supprimer ce devoir et tous ses rendus ?')) return;
    const fd = new FormData();
    fd.append('assignment_id', assignId);
    fetch(`?course_id=${COURSE_ID}&action=delete_assignment`, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                const card = document.getElementById('acard-' + assignId);
                if (card) card.remove();
                if (!document.querySelector('.assign-card')) {
                    document.getElementById('assignList').innerHTML = '<div class="assign-empty">Aucun devoir créé.</div>';
                }
            } else {
                alert(res.error || 'Erreur lors de la suppression.');
            }
        })
        .catch(() => alert('Erreur réseau.'));
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>