<?php
ob_start();
require_once '../includes/db_connect.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

set_time_limit(300);
ini_set('max_execution_time', 300);

require_once '../vendor/autoload.php';
use SendGrid\Mail\Mail;

if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.html");
    exit();
}

require_once '../includes/semester_helper.php';
$current_period   = get_current_period($conn);
$current_year     = ANNEE_ACADEMIQUE_COURANTE;
$current_semester = $current_period['semester'] ?? 1;

define('SENDGRID_FROM_EMAIL',          'contact@uvcoding.com');
define('SENDGRID_FROM_NAME',           'Université Virtuelle');
define('SENDGRID_DISCUSSION_TEMPLATE', 'd-6125ebdeb75043a9a4ade8426530a0f1');

$user_id = $_SESSION['user_id'];

// ============================================================
// FONCTION POUR CRÉER UNE NOUVELLE CONNEXION MYSQL
// ============================================================
function createNewConnection() {
    $new_conn = new mysqli('localhost', 'u641337841_test_uvcoding', 'Test_uvcoding/8', 'u641337841_test_uvcoding');
    $new_conn->set_charset("utf8mb4");
    $new_conn->query("SET collation_connection = 'utf8mb4_general_ci'");
    if ($new_conn->connect_error) {
        error_log("❌ Échec de connexion: " . $new_conn->connect_error);
        return false;
    }
    return $new_conn;
}

// ============================================================
// FONCTION PRINCIPALE D'ENVOI EMAIL AUX PARTICIPANTS
// — Insère dans email_queue au lieu d'appeler SendGrid directement
// ============================================================
function sendEmailToParticipants($conn, $course_id, $course_name, $sender_id, $sender_name, $sender_role, $action_type, $message_preview = '', $file_count = 0) {
    $log = [];

    $email_conn = createNewConnection();
    if (!$email_conn) {
        $_SESSION['debug_email']['log'][] = "❌ Impossible de créer une connexion";
        return false;
    }

    $sender_id        = (string)$sender_id;
    $course_id        = intval($course_id);
    $sender_is_teacher = ($sender_role === 'teacher');

    // ── 1. Récupérer class_id ET teacher_id du cours ──
    $stmt = $email_conn->prepare("SELECT class_id, teacher_id FROM courses WHERE id = ?");
    if (!$stmt) {
        $_SESSION['debug_email']['log'][] = "❌ Erreur prepare: " . $email_conn->error;
        $email_conn->close();
        return false;
    }
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $_SESSION['debug_email']['log'][] = "❌ Cours introuvable: $course_id";
        $email_conn->close();
        return false;
    }

    $log[] = "✅ Cours trouvé: $course_name";
    $log[] = "class_id JSON brut: " . $row['class_id'];
    $log[] = "teacher_id JSON brut: " . $row['teacher_id'];
    $log[] = "Expéditeur: $sender_name (rôle: $sender_role)";

    // ── 2. Parser les class_ids ──
    $class_ids_json = $row['class_id'];
    $clean_json     = preg_replace('/"\s*([^"]*?)\s*"/', '"$1"', $class_ids_json);
    $clean_json     = preg_replace('/\s*,\s*/', ',', $clean_json);
    $clean_json     = preg_replace('/\[\s*/', '[', $clean_json);
    $clean_json     = preg_replace('/\s*\]/', ']', $clean_json);
    $class_ids      = json_decode($clean_json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        preg_match_all('/\d+/', $class_ids_json, $matches);
        $class_ids = $matches[1] ?? [];
        $log[] = "⚠️ JSON invalide, extraction regex: " . implode(',', $class_ids);
    } else {
        $log[] = "✅ JSON parsé: " . implode(',', $class_ids);
    }

    $clean_class_ids = [];
    foreach ((array)$class_ids as $id) {
        $clean_id = intval(preg_replace('/[^0-9]/', '', $id));
        if ($clean_id > 0) $clean_class_ids[] = $clean_id;
    }

    $log[] = "class_ids nettoyés: " . implode(',', $clean_class_ids);

    $all_recipients = [];
    $class_name     = '';

    // ── 3. Récupérer les étudiants via class_id ──
    if (!empty($clean_class_ids)) {
        // Nom de la classe
        $res_class = $email_conn->query("SELECT name FROM classes WHERE id = " . intval($clean_class_ids[0]) . " LIMIT 1");
        if ($res_class && $row_c = $res_class->fetch_assoc()) {
            $class_name = $row_c['name'];
        }

        $placeholders = implode(',', array_fill(0, count($clean_class_ids), '?'));
        $sql_students = "
            SELECT email, name, role
            FROM users
            WHERE class_id IN ($placeholders)
            AND id != ?
            AND email IS NOT NULL AND email != ''
            AND status = 'active'
            ORDER BY name
        ";
        $stmt_s = $email_conn->prepare($sql_students);
        if ($stmt_s) {
            $types_s  = str_repeat('i', count($clean_class_ids)) . 's';
            $params_s = array_merge($clean_class_ids, [$sender_id]);
            $stmt_s->bind_param($types_s, ...$params_s);
            $stmt_s->execute();
            $res_s = $stmt_s->get_result();
            while ($r = $res_s->fetch_assoc()) {
                $all_recipients[] = $r;
                $log[] = "👤 Étudiant: {$r['name']} ({$r['email']})";
            }
            $stmt_s->close();
        } else {
            $log[] = "❌ Erreur requête étudiants: " . $email_conn->error;
        }
    }

    // ── 4. Récupérer les professeurs via teacher_id du cours ──
    $teacher_ids_raw    = $row['teacher_id'] ?? '[]';
    $teacher_ids_parsed = json_decode($teacher_ids_raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($teacher_ids_parsed)) {
        preg_match_all('/\S+/', $teacher_ids_raw, $mt);
        $teacher_ids_parsed = $mt[0] ?? [];
    }

    $clean_teacher_ids = [];
    foreach ((array)$teacher_ids_parsed as $tid) {
        $tid = (string)trim($tid, " \t\n\r\0\x0B\"'[]");
        if (!empty($tid) && $tid !== $sender_id) {
            $clean_teacher_ids[] = $tid;
        }
    }

    $log[] = "teacher_ids à notifier: " . implode(',', $clean_teacher_ids);

    if (!empty($clean_teacher_ids)) {
        $ph_t  = implode(',', array_fill(0, count($clean_teacher_ids), '?'));
        $sql_t = "
            SELECT email, name, role
            FROM users
            WHERE id IN ($ph_t)
            AND email IS NOT NULL AND email != ''
        ";
        $stmt_t = $email_conn->prepare($sql_t);
        if ($stmt_t) {
            $types_t = str_repeat('s', count($clean_teacher_ids));
            $stmt_t->bind_param($types_t, ...$clean_teacher_ids);
            $stmt_t->execute();
            $res_t = $stmt_t->get_result();
            while ($r = $res_t->fetch_assoc()) {
                $already = false;
                foreach ($all_recipients as $existing) {
                    if ($existing['email'] === $r['email']) { $already = true; break; }
                }
                if (!$already) {
                    $all_recipients[] = $r;
                    $log[] = "👨‍🏫 Prof ajouté: {$r['name']} ({$r['email']})";
                }
            }
            $stmt_t->close();
        } else {
            $log[] = "❌ Erreur requête profs: " . $email_conn->error;
        }
    }

    $log[] = "Total destinataires: " . count($all_recipients);

    // ── 5. Diagnostic si aucun destinataire ──
    if (empty($all_recipients)) {
        if (!empty($clean_class_ids)) {
            $placeholders = implode(',', array_fill(0, count($clean_class_ids), '?'));
            $sql_diag     = "SELECT id, name, email, role, class_id, status FROM users WHERE class_id IN ($placeholders) LIMIT 20";
            $stmt_diag    = $email_conn->prepare($sql_diag);
            $stmt_diag->bind_param(str_repeat('i', count($clean_class_ids)), ...$clean_class_ids);
            $stmt_diag->execute();
            $res_diag = $stmt_diag->get_result();
            while ($rd = $res_diag->fetch_assoc()) {
                $log[] = "🔍 DIAG: {$rd['name']} email={$rd['email']} role={$rd['role']} class={$rd['class_id']} status={$rd['status']}";
            }
            $stmt_diag->close();
        }
        $_SESSION['debug_email']['log'] = $log;
        $email_conn->close();
        return false;
    }

    // ── 6. Préparer les données communes ──
    $message_preview = substr($message_preview, 0, 100);
    if (strlen($message_preview) == 100) $message_preview .= "...";

    $protocol   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $course_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/pages/manage_discussions.php?course_id=" . $course_id;

    // ── 7. Insérer dans email_queue (aucun appel réseau ici) ──
    $queued = 0;
    $q_stmt = $email_conn->prepare(
        "INSERT INTO email_queue (to_email, to_name, template_id, dynamic_data) VALUES (?, ?, ?, ?)"
    );

    $template_id = SENDGRID_DISCUSSION_TEMPLATE;

    foreach ($all_recipients as $recipient) {
        $dynamic_data = json_encode([
            'recipient_name'  => $recipient['name'],
            'sender_name'     => $sender_name,
            'is_teacher'      => $sender_is_teacher,
            'action_type'     => $action_type,
            'message_preview' => $message_preview,
            'course_name'     => $course_name,
            'class_name'      => $class_name,
            'file_count'      => $file_count > 0 ? $file_count : '',
            'course_url'      => $course_url,
            'support_email'   => SENDGRID_FROM_EMAIL,
            'current_year'    => date('Y'),
        ]);

        $q_stmt->bind_param('ssss', $recipient['email'], $recipient['name'], $template_id, $dynamic_data);
        if ($q_stmt->execute()) {
            $queued++;
            $log[] = "📬 En file → {$recipient['name']} ({$recipient['email']})";
        } else {
            $log[] = "❌ Erreur file → {$recipient['name']}: " . $email_conn->error;
        }
    }

    $q_stmt->close();
    $log[] = "🏁 $queued/" . count($all_recipients) . " emails mis en file d'attente";
    $_SESSION['debug_email']['log'] = $log;
    $email_conn->close();
    return $queued > 0;
}

// ============================================================
// VÉRIFICATION D'APPARTENANCE ÉTUDIANT AU COURS
// — Se base sur la classe HISTORIQUE de l'étudiant pour l'année académique
//   consultée (student_class_history), et non sur sa classe actuelle.
//   Ainsi, changer l'année dans l'URL ne permet plus de voir les discussions
//   d'un cours auquel l'étudiant n'était pas réellement inscrit à ce moment-là.
// ============================================================
function verify_student_course_access($conn, $user_id, $course_id, $academic_year, $current_year, $fallback_class_id = null) {
    $escaped_course = intval($course_id);
    if ($escaped_course <= 0) return false;

    // 1. Classe de l'étudiant pour l'année académique précise consultée
    $historical_class_id = null;
    $stmt_hist = $conn->prepare("
        SELECT class_id FROM student_class_history
        WHERE student_id = ? AND academic_year = ?
        ORDER BY id DESC LIMIT 1
    ");
    if ($stmt_hist) {
        $stmt_hist->bind_param("ss", $user_id, $academic_year);
        $stmt_hist->execute();
        $hist_row = $stmt_hist->get_result()->fetch_assoc();
        $stmt_hist->close();
        if ($hist_row) {
            $historical_class_id = $hist_row['class_id'];
        }
    }

    // 2. Repli sur la classe active UNIQUEMENT si on consulte l'année courante
    //    et qu'aucune ligne d'historique n'existe encore (ex: pas encore passé
    //    par un passage de classe depuis son inscription).
    if ($historical_class_id === null && $academic_year === $current_year) {
        $historical_class_id = $fallback_class_id;
    }

    if ($historical_class_id === null) return false;

    $escaped_class = $conn->real_escape_string((string)$historical_class_id);
    $res = $conn->query("
        SELECT id FROM courses
        WHERE id = $escaped_course
        AND JSON_CONTAINS(class_id, CONCAT('\"', '$escaped_class', '\"'))
    ");
    return $res && $res->num_rows > 0;
}

// ============================================================
// LOGIQUE PRINCIPALE DE LA PAGE
// ============================================================
if (isset($_GET['course_id'])) {
    $course_id = $conn->real_escape_string($_GET['course_id']);

    // Année de filtrage : paramètre GET ou année courante par défaut
    // (calculée AVANT le contrôle d'accès, car l'accès en dépend désormais)
    $filter_year = (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year']))
        ? $_GET['year'] : $current_year;
    $filter_year = $conn->real_escape_string($filter_year);

    // ── Vérification d'appartenance étudiant au cours POUR L'ANNÉE CONSULTÉE ──
    $student_class_id = $_SESSION['class_id'] ?? null;
    if ($student_class_id === null) {
        $stmt_access_chk = $conn->prepare("SELECT class_id FROM users WHERE id = ? AND role = 'student' LIMIT 1");
        $stmt_access_chk->bind_param("s", $user_id);
        $stmt_access_chk->execute();
        $access_row = $stmt_access_chk->get_result()->fetch_assoc();
        $stmt_access_chk->close();
        $student_class_id = $access_row['class_id'] ?? null;
    }
    if ($student_class_id !== null && !verify_student_course_access($conn, $user_id, $course_id, $filter_year, $current_year, $student_class_id)) {
        $is_ajax_req = isset($_GET['ajax'])
            || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        if ($is_ajax_req) {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Accès non autorisé', 'code' => 403]);
            exit();
        }
        $_SESSION['notification'] = ['message' => 'Vous n\'êtes pas inscrit à ce cours.', 'type' => 'error'];
        header('Location: ../student/dashboard.php');
        exit();
    }

    $sql = "SELECT name FROM courses WHERE id = '$course_id'";
    $course_result = $conn->query($sql);
    if ($course_result->num_rows > 0) {
        $course = $course_result->fetch_assoc();
        $course_name = $course['name'];
    } else {
        echo "Cours non trouvé.";
        exit();
    }

    $sql_user  = "SELECT role, name FROM users WHERE id = '$user_id'";
    $result_user = $conn->query($sql_user);
    $user_info = $result_user->fetch_assoc();
    $user_role = $user_info['role'];
    $user_name = $user_info['name'];

    $notification_message = '';
    $notification_type    = '';

    // ============================================================
    // ██ INSERTION 1 — HANDLER AJAX PRÉSENCES ÉTUDIANT ██
    // ============================================================
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'student_attendance') {
        ob_clean();
        header('Content-Type: application/json');

        $cid = intval($course_id);

        // Vérification accès (défense en profondeur) — pour l'année consultée
        $att_class_id = $_SESSION['class_id'] ?? null;
        if ($att_class_id !== null && !verify_student_course_access($conn, $user_id, $cid, $filter_year, $current_year, $att_class_id)) {
            echo json_encode(['error' => 'Accès non autorisé', 'code' => 403]);
            exit();
        }

        $uid = $conn->real_escape_string($user_id);

        // Récupérer la date d'inscription de l'étudiant dans cette classe pour ne
        // pas comptabiliser les séances antérieures à son arrivée.
        $student_start_date = null;
        $stmt_cls = $conn->prepare("SELECT class_id FROM courses WHERE id = ?");
        $stmt_cls->bind_param("i", $cid);
        $stmt_cls->execute();
        $course_cls_row = $stmt_cls->get_result()->fetch_assoc();
        $stmt_cls->close();
        $course_class_ids = array_values(array_filter(array_map('intval', json_decode($course_cls_row['class_id'] ?? '[]', true))));
        if (!empty($course_class_ids)) {
            $ph_sd = implode(',', array_fill(0, count($course_class_ids), '?'));
            $stmt_sd = $conn->prepare("
                SELECT start_date FROM student_class_history
                WHERE student_id = ? AND class_id IN ($ph_sd) AND academic_year = ?
                ORDER BY id DESC LIMIT 1
            ");
            $types_sd = 's' . str_repeat('i', count($course_class_ids)) . 's';
            $params_sd = array_merge([$user_id], $course_class_ids, [$filter_year]);
            $stmt_sd->bind_param($types_sd, ...$params_sd);
            $stmt_sd->execute();
            $sd_row = $stmt_sd->get_result()->fetch_assoc();
            $stmt_sd->close();
            if ($sd_row) $student_start_date = $sd_row['start_date'];
        }
        $date_filter_att = $student_start_date
            ? "AND att_s.session_date >= '" . $conn->real_escape_string($student_start_date) . "'"
            : '';

        $sql_sessions = "
            SELECT
                att_s.id           AS session_id,
                att_s.session_date,
                att_s.course_id,
                ts.start_time,
                ts.end_time,
                ar.status,
                ar.justification
            FROM attendance_sessions att_s
            LEFT JOIN time_slots ts ON att_s.time_slot_id = ts.id
            LEFT JOIN attendance_records ar
                   ON ar.session_id = att_s.id AND ar.student_id = '$uid'
            WHERE att_s.course_id = $cid
              AND att_s.academic_year = '$filter_year'
              $date_filter_att
            ORDER BY att_s.session_date DESC
        ";

        $res = $conn->query($sql_sessions);
        if (!$res) {
            echo json_encode(['sessions' => [], 'stats' => ['total'=>0,'present'=>0,'absent'=>0,'late'=>0,'justified'=>0,'unmarked'=>0,'rate'=>0]]);
            exit();
        }
        $sessions = [];
        $stats    = [
            'total'     => 0,
            'present'   => 0,
            'absent'    => 0,
            'late'      => 0,
            'justified' => 0,
            'unmarked'  => 0,
        ];

        while ($row = $res->fetch_assoc()) {
            $sessions[] = $row;
            $stats['total']++;
            $s = $row['status'] ?? 'unmarked';
            if (isset($stats[$s])) $stats[$s]++;
            else $stats['unmarked']++;
        }

        $attended      = $stats['present'] + $stats['late'] + $stats['justified'];
        $stats['rate'] = $stats['total'] > 0 ? round(($attended / $stats['total']) * 100) : 0;

        echo json_encode(['sessions' => $sessions, 'stats' => $stats]);
        exit();
    }
    // ============================================================

    // ============================================================
    // ██ INSERTION 1 — HANDLER AJAX PROGRESSION ÉTUDIANT (lecture seule) ██
    // ============================================================
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'student_progress') {
        ob_clean();
        header('Content-Type: application/json');

        $cid = intval($_GET['course_id'] ?? 0);

        // Vérification accès (défense en profondeur) — pour l'année consultée
        $prog_class_id = $_SESSION['class_id'] ?? null;
        if ($prog_class_id !== null && !verify_student_course_access($conn, $user_id, $cid, $filter_year, $current_year, $prog_class_id)) {
            echo json_encode(['error' => 'Accès non autorisé', 'code' => 403]);
            exit();
        }

        // Total heures prévues du cours
        $sql_course_h = "SELECT total_hours FROM courses WHERE id = $cid";
        $res_ch = $conn->query($sql_course_h);
        $total_hours_planned = 0;
        if ($res_ch && $row_ch = $res_ch->fetch_assoc()) {
            $total_hours_planned = (float)($row_ch['total_hours'] ?? 0);
        }

        $att_year = $conn->real_escape_string($filter_year);

        // Récupérer la date d'inscription de l'étudiant pour filtrer les séances
        // antérieures à son arrivée dans le cours.
        $student_start_date_prog = null;
        $stmt_cls_p = $conn->prepare("SELECT class_id FROM courses WHERE id = ?");
        $stmt_cls_p->bind_param("i", $cid);
        $stmt_cls_p->execute();
        $course_cls_p = $stmt_cls_p->get_result()->fetch_assoc();
        $stmt_cls_p->close();
        $course_class_ids_p = array_values(array_filter(array_map('intval', json_decode($course_cls_p['class_id'] ?? '[]', true))));
        if (!empty($course_class_ids_p)) {
            $ph_sdp = implode(',', array_fill(0, count($course_class_ids_p), '?'));
            $stmt_sdp = $conn->prepare("
                SELECT start_date FROM student_class_history
                WHERE student_id = ? AND class_id IN ($ph_sdp) AND academic_year = ?
                ORDER BY id DESC LIMIT 1
            ");
            $types_sdp = 's' . str_repeat('i', count($course_class_ids_p)) . 's';
            $params_sdp = array_merge([$user_id], $course_class_ids_p, [$filter_year]);
            $stmt_sdp->bind_param($types_sdp, ...$params_sdp);
            $stmt_sdp->execute();
            $sdp_row = $stmt_sdp->get_result()->fetch_assoc();
            $stmt_sdp->close();
            if ($sdp_row) $student_start_date_prog = $sdp_row['start_date'];
        }
        $date_filter_prog = $student_start_date_prog
            ? "AND (session_date IS NULL OR session_date >= '" . $conn->real_escape_string($student_start_date_prog) . "')"
            : '';

        // Précharger toutes les séances en une requête
        $sql_all_sess = "SELECT id, chapter_id, session_number, title, description, hours, session_date, attendance_session_id FROM course_sessions WHERE course_id = $cid AND (academic_year = '$att_year' OR academic_year IS NULL) $date_filter_prog ORDER BY chapter_id ASC, session_number ASC";
        $res_all_sess = $conn->query($sql_all_sess);
        $sessions_by_chapter = [];
        if ($res_all_sess) {
            while ($sess = $res_all_sess->fetch_assoc()) {
                $sessions_by_chapter[$sess['chapter_id']][] = $sess;
            }
        }

        // Chapitres et séances du syllabus — filtrés par année (NULL = planifiés, visibles partout)
        $sql_chaps = "SELECT id, title, order_num FROM course_chapters WHERE course_id = $cid AND (academic_year = '$att_year' OR academic_year IS NULL) ORDER BY order_num ASC";
        $res_chaps = $conn->query($sql_chaps);
        $chapters = [];
        $global_session_n = 0;
        $total_hours_done = 0.0;

        if ($res_chaps) {
            while ($chap = $res_chaps->fetch_assoc()) {
                $sessions_arr = [];
                $chap_hours_done = 0.0;
                foreach ($sessions_by_chapter[$chap['id']] ?? [] as $sess) {
                    $global_session_n++;
                    $sess['global_num'] = $global_session_n;
                    $sess['done'] = $sess['attendance_session_id'] !== null;
                    if ($sess['session_date'] !== null) $chap_hours_done += (float)$sess['hours'];
                    $sessions_arr[] = $sess;
                }
                $chap['sessions']   = $sessions_arr;
                $chap['hours_done'] = $chap_hours_done;
                $total_hours_done  += $chap_hours_done;
                $chapters[]         = $chap;
            }
        }

        // Nombre de séances documentées (session_date renseignée)
        $nb_sd_filter = $student_start_date_prog
            ? "AND session_date >= '" . $conn->real_escape_string($student_start_date_prog) . "'"
            : '';
        $nb_res = $conn->query("SELECT COUNT(*) AS nb FROM course_sessions WHERE course_id = $cid AND session_date IS NOT NULL AND (academic_year = '$att_year' OR academic_year IS NULL) $nb_sd_filter");
        $total_sessions = 0;
        if ($nb_res && $row_nb = $nb_res->fetch_assoc()) {
            $total_sessions = (int)($row_nb['nb'] ?? 0);
        }

        $total_hours_done = (float)$total_hours_done;

        // Si courses.total_hours non renseigné, calcul depuis le syllabus
        if ($total_hours_planned == 0) {
            $r_th = $conn->query("SELECT COALESCE(SUM(cs.hours), 0) AS th FROM course_sessions cs JOIN course_chapters cc ON cs.chapter_id = cc.id WHERE cc.course_id = $cid AND (cs.academic_year = '$att_year' OR cs.academic_year IS NULL)");
            if ($r_th && $rw_th = $r_th->fetch_assoc()) {
                $total_hours_planned = (float)$rw_th['th'];
            }
        }

        $progress_pct = $total_hours_planned > 0
            ? min(100, round(($total_hours_done / $total_hours_planned) * 100))
            : 0;

        echo json_encode([
            'chapters'       => $chapters,
            'hours_done'     => $total_hours_done,
            'sessions_count' => $total_sessions,
            'hours_planned'  => $total_hours_planned,
            'progress_pct'   => $progress_pct,
        ]);
        exit();
    }
    // ============================================================ FIN INSERTION 1

    // ── HANDLERS AJAX DEVOIRS (ÉTUDIANT) ────────────────────────────────────────
    if (isset($_GET['action'])) {
        $stu_action = $_GET['action'];

        // ── get_student_assignments ──────────────────────────────────────────────
        if ($stu_action === 'get_student_assignments') {
            ob_clean();
            header('Content-Type: application/json');
            $uid_esc_s = $conn->real_escape_string($user_id);
            $cid_s     = intval($course_id);
            $sql_sga   = "SELECT ca.*,
                              asub.id          AS sub_id,
                              asub.file_name   AS sub_file_name,
                              asub.file_path   AS sub_file_path,
                              asub.submitted_at AS sub_submitted_at
                          FROM course_assignments ca
                          LEFT JOIN assignment_submissions asub
                              ON asub.assignment_id = ca.id AND asub.student_id = '$uid_esc_s'
                          WHERE ca.course_id = $cid_s
                            AND ca.annee_academique = '$filter_year'
                          ORDER BY ca.due_date ASC";
            $res_sga   = $conn->query($sql_sga);
            $rows_sga  = [];
            if ($res_sga) while ($r = $res_sga->fetch_assoc()) $rows_sga[] = $r;
            echo json_encode($rows_sga);
            exit;
        }

        // ── submit_assignment ────────────────────────────────────────────────────
        if ($stu_action === 'submit_assignment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            ob_clean();
            header('Content-Type: application/json');
            $sa_assign_id = intval($_POST['assignment_id'] ?? 0);
            if (!$sa_assign_id) { echo json_encode(['success' => false, 'error' => 'ID invalide']); exit; }

            // Vérifier que le devoir appartient au cours de l'étudiant
            $uid_esc_s = $conn->real_escape_string($user_id);
            $cid_s     = intval($course_id);
            $chk_sa    = $conn->query("SELECT id, due_date FROM course_assignments WHERE id = $sa_assign_id AND course_id = $cid_s");
            if (!$chk_sa || $chk_sa->num_rows === 0) {
                echo json_encode(['success' => false, 'error' => 'Devoir introuvable']); exit;
            }
            $da_row = $chk_sa->fetch_assoc();
            if (strtotime($da_row['due_date']) < time()) {
                echo json_encode(['success' => false, 'error' => 'La date limite est dépassée. Modification impossible.']); exit;
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Fichier manquant ou erreur upload']); exit;
            }
            if ($_FILES['file']['size'] > 10485760) {
                echo json_encode(['success' => false, 'error' => 'Fichier trop volumineux (max 10 MB)']); exit;
            }
            $sa_allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'rar', 'txt', 'odt'];
            $sa_ext     = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($sa_ext, $sa_allowed)) {
                echo json_encode(['success' => false, 'error' => 'Extension non autorisée']); exit;
            }
            if (!is_dir('../uploads/assignments/submissions/')) mkdir('../uploads/assignments/submissions/', 0755, true);
            $sa_safe = uniqid('sub_', true) . '.' . $sa_ext;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], '../uploads/assignments/submissions/' . $sa_safe)) {
                echo json_encode(['success' => false, 'error' => 'Erreur lors de la sauvegarde du fichier']); exit;
            }
            $sa_orig  = basename($_FILES['file']['name']);
            $sa_size  = intval($_FILES['file']['size']);
            $sa_comment = $conn->real_escape_string(trim($_POST['comment'] ?? ''));

            // INSERT ou UPDATE (UNIQUE KEY uq_student_assignment)
            $chk_exist = $conn->query("SELECT id, file_path FROM assignment_submissions WHERE assignment_id = $sa_assign_id AND student_id = '$uid_esc_s'");
            if ($chk_exist && $chk_exist->num_rows > 0) {
                $old_row = $chk_exist->fetch_assoc();
                $old_path = '../uploads/assignments/submissions/' . $old_row['file_path'];
                if (file_exists($old_path)) unlink($old_path);
                $stmt_up = $conn->prepare("UPDATE assignment_submissions SET file_path=?, file_name=?, file_size=?, comment=?, updated_at=NOW() WHERE id=?");
                $stmt_up->bind_param('ssisi', $sa_safe, $sa_orig, $sa_size, $sa_comment, $old_row['id']);
                $ok_sa = $stmt_up->execute();
                $stmt_up->close();
            } else {
                $stmt_in = $conn->prepare("INSERT INTO assignment_submissions (assignment_id, student_id, file_path, file_name, file_size, comment) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_in->bind_param('issssi', $sa_assign_id, $user_id, $sa_safe, $sa_orig, $sa_size, $sa_comment);
                $ok_sa = $stmt_in->execute();
                $stmt_in->close();
            }
            echo json_encode(['success' => $ok_sa]);
            exit;
        }

        // ── download_my_submission ───────────────────────────────────────────────
        if ($stu_action === 'download_my_submission') {
            $dms_assign = intval($_GET['assignment_id'] ?? 0);
            if (!$dms_assign) { http_response_code(400); exit; }
            $uid_esc_s = $conn->real_escape_string($user_id);
            $res_dms = $conn->query("SELECT file_path, file_name FROM assignment_submissions WHERE assignment_id = $dms_assign AND student_id = '$uid_esc_s' LIMIT 1");
            if (!$res_dms || $res_dms->num_rows === 0) { http_response_code(404); exit; }
            $row_dms = $res_dms->fetch_assoc();
            $src_dms = '../uploads/assignments/submissions/' . $row_dms['file_path'];
            if (!file_exists($src_dms)) { http_response_code(404); exit; }
            ob_end_clean();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . rawurlencode($row_dms['file_name']) . '"');
            header('Content-Length: ' . filesize($src_dms));
            readfile($src_dms);
            exit;
        }

        // ── get_pending_count ────────────────────────────────────────────────────
        if ($stu_action === 'get_pending_count') {
            ob_clean();
            header('Content-Type: application/json');
            $gpc_yr  = (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year']))
                ? $_GET['year'] : $current_year;
            $gpc_cid = intval($course_id);
            $stmt_gpc = $conn->prepare("
                SELECT COUNT(*) AS pending
                FROM course_assignments ca
                LEFT JOIN assignment_submissions asub
                    ON asub.assignment_id = ca.id AND asub.student_id = ?
                WHERE ca.course_id = ?
                  AND ca.annee_academique = ?
                  AND ca.due_date > NOW()
                  AND asub.id IS NULL
            ");
            $stmt_gpc->bind_param('sis', $user_id, $gpc_cid, $gpc_yr);
            $stmt_gpc->execute();
            $gpc_count = $stmt_gpc->get_result()->fetch_assoc()['pending'];
            $stmt_gpc->close();
            echo json_encode(['count' => intval($gpc_count)]);
            exit;
        }
    }
    // ── FIN HANDLERS DEVOIRS ÉTUDIANT ────────────────────────────────────────────

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        // Vérification accès (défense en profondeur) — pour l'année consultée
        $post_class_id = $_SESSION['class_id'] ?? null;
        if ($post_class_id !== null && !verify_student_course_access($conn, $user_id, $course_id, $filter_year, $current_year, $post_class_id)) {
            $_SESSION['notification'] = ['message' => 'Vous n\'êtes pas inscrit à ce cours.', 'type' => 'error'];
            header('Location: ../student/dashboard.php');
            exit();
        }

        // Blocage des modifications sur une année académique archivée (défense en profondeur,
        // en plus du masquage des formulaires côté HTML plus bas)
        if ($filter_year !== $current_year) {
            $_SESSION['notification'] = ['message' => 'Modification impossible : vous consultez une année académique archivée.', 'type' => 'error'];
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit();
        }

        $discussion_id    = null;
        $success_count    = 0;
        $error_count      = 0;
        $email_sent       = false;
        $email_file_count = 0;

        // ── Suppression ──
        if (isset($_POST['delete_id'])) {
            $delete_id   = $conn->real_escape_string($_POST['delete_id']);
            $delete_type = $_POST['delete_type'];

            if ($delete_type === 'message') {
                $conn->query("DELETE FROM documents WHERE discussion_id = '$delete_id'");
                $sql = "DELETE FROM discussions WHERE id = '$delete_id' AND sender_id = '$user_id'";
                if ($conn->query($sql)) {
                    $notification_message = "Message supprimé avec succès";
                    $notification_type    = "success";
                } else {
                    $notification_message = "Erreur lors de la suppression du message";
                    $notification_type    = "error";
                }
            } elseif ($delete_type === 'document') {
                $sql = "DELETE FROM documents WHERE id = '$delete_id' AND uploaded_by = '$user_id'";
                if ($conn->query($sql)) {
                    $notification_message = "Document supprimé avec succès";
                    $notification_type    = "success";
                } else {
                    $notification_message = "Erreur lors de la suppression du document";
                    $notification_type    = "error";
                }
            }
        }

        // ── Ajout de message texte ── 
        if (isset($_POST['message']) && !empty(trim($_POST['message']))) {
            $message = $conn->real_escape_string(trim($_POST['message']));
            $sql = "INSERT INTO discussions (course_id, sender_id, message, academic_year, semester, created_at) VALUES ('$course_id', '$user_id', '$message', '$current_year', $current_semester, NOW())";
            if ($conn->query($sql)) {
                $discussion_id        = $conn->insert_id; 
                $notification_message = "Message envoyé avec succès";
                $notification_type    = "success";

                if (!$email_sent) {
                    error_log("\ud83d\udd25 ENVOI EMAIL - Cours: $course_name | Auteur: $user_name");
                    sendEmailToParticipants(
    $conn, intval($course_id), $course_name,
    $user_id, $user_name, $user_role,
    'Nouveau message', $message, 0  // ← 'Nouveau message' au lieu de 'message'
);
                    $email_sent = true;
                }
            } else {
                $notification_message = "Erreur lors de l'envoi du message";
                $notification_type    = "error";
            }
        }

        // ── Ajout de documents ──
        if (isset($_FILES['documents'])) {
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

                if ($_FILES['documents']['size'][$i] > 40000000) {
                    $error_count++;
                    continue;
                }

                // Whitelist d'extensions
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_extensions)) {
                    $rejected_files[] = $original_name;
                    $error_count++;
                    continue;
                }

                // Vérification du MIME type réel
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['documents']['tmp_name'][$i]);
                finfo_close($finfo);
                if (!in_array($mime, $allowed_mimes)) {
                    $rejected_files[] = $original_name;
                    $error_count++;
                    continue;
                }

                // Nom de fichier unique pour le stockage
                $safe_name   = uniqid('doc_', true) . '.' . $ext;
                $target_dir  = "../uploads/";
                $target_file = $target_dir . $safe_name;

                if (move_uploaded_file($_FILES['documents']['tmp_name'][$i], $target_file)) {
                    if ($discussion_id === null) {
                        $conn->query("INSERT INTO discussions (course_id, sender_id, academic_year, semester, created_at) VALUES ('$course_id', '$user_id', '$current_year', $current_semester, NOW())");
                        $discussion_id = $conn->insert_id;
                    }
                    $safe_path  = $conn->real_escape_string($safe_name);
                    $safe_orig  = $conn->real_escape_string($original_name);
                    $is_teacher = ($user_role === 'teacher') ? 1 : 0;
                    $sql = "INSERT INTO documents (discussion_id, file_path, original_name, uploaded_by, is_teacher) VALUES ('$discussion_id', '$safe_path', '$safe_orig', '$user_id', '$is_teacher')";
                    if ($conn->query($sql)) {
                        $success_count++;
                        $email_file_count++;
                        $files_uploaded = true;
                    } else {
                        $error_count++;
                    }
                } else {
                    $error_count++;
                }
            }

            if (!empty($rejected_files)) {
                $rej_list             = implode(', ', array_map('htmlspecialchars', $rejected_files));
                $notification_message = "Fichier(s) rejeté(s) — type non autorisé : $rej_list. Types acceptés : PDF, Word, Excel, PowerPoint, images, vidéos, ZIP.";
                $notification_type    = "error";
            }

            if ($success_count > 0 && empty($rejected_files) && $error_count == 0) {
                $notification_message = $success_count == 1 ? "Fichier téléchargé avec succès" : "$success_count fichiers téléchargés avec succès";
                $notification_type    = "success";
            } elseif ($success_count > 0 && ($error_count > 0 || !empty($rejected_files))) {
                $notification_message = "$success_count fichier(s) téléchargé(s), $error_count rejeté(s)";
                $notification_type    = "warning";
            } elseif ($error_count > 0 && empty($rejected_files)) {
                $notification_message = "Erreur lors du téléchargement des fichiers";
                $notification_type    = "error";
            }

            if ($files_uploaded && !$email_sent) {
                error_log("\ud83d\udd25 ENVOI EMAIL FICHIER - Cours: $course_name | Auteur: $user_name | Fichiers: $email_file_count");
                sendEmailToParticipants(
    $conn, intval($course_id), $course_name,
    $user_id, $user_name, $user_role,
    'Nouveau document', 'Des fichiers ont été partagés dans le cours.', $email_file_count
);
                $email_sent = true;
            }
        }

        if (!empty($notification_message)) {
            $_SESSION['notification'] = [
                'message' => $notification_message,
                'type'    => $notification_type,
            ];
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit();
        }
    }

    // Récupération des messages filtrés par année académique
    $sql = "
        SELECT d.id AS discussion_id, d.sender_id, d.message, d.created_at,
               u.name, u.avatar, doc.id AS document_id, doc.file_path,
               COALESCE(doc.original_name, doc.file_path) AS original_name
        FROM discussions d
        JOIN users u ON d.sender_id = u.id
        LEFT JOIN documents doc ON d.id = doc.discussion_id
        WHERE d.course_id = '$course_id'
          AND d.academic_year = '$filter_year'
        ORDER BY d.created_at ASC
    ";
    $messages = $conn->query($sql);
} else {
    echo "Aucun cours sélectionné.";
    exit();
}

// Devoirs en attente (deadline non passée, pas encore rendus)
$pending_count = 0;
$stmt_pc = $conn->prepare("
    SELECT COUNT(*) AS pending
    FROM course_assignments ca
    LEFT JOIN assignment_submissions asub
        ON asub.assignment_id = ca.id AND asub.student_id = ?
    WHERE ca.course_id = ?
      AND ca.annee_academique = ?
      AND ca.due_date > NOW()
      AND asub.id IS NULL
");
$pc_cid = intval($course_id);
$stmt_pc->bind_param('sis', $user_id, $pc_cid, $filter_year);
$stmt_pc->execute();
$pending_count = intval($stmt_pc->get_result()->fetch_assoc()['pending']);
$stmt_pc->close();

// Récupération des documents filtrés par année académique
$sql_documents = "
    SELECT doc.id AS document_id, doc.file_path, COALESCE(doc.original_name, doc.file_path) AS original_name,
           doc.uploaded_by, u.name AS uploader_name, u.role
    FROM documents doc
    JOIN users u ON doc.uploaded_by = u.id
    WHERE doc.discussion_id IN (
        SELECT id FROM discussions
        WHERE course_id = '$course_id' AND academic_year = '$filter_year'
    )
    ORDER BY u.role ASC
";
$documents         = $conn->query($sql_documents);
$prof_documents    = [];
$student_documents = [];

while ($doc = $documents->fetch_assoc()) {
    if ($doc['role'] === 'teacher') {
        $prof_documents[] = $doc;
    } else {
        $student_documents[] = $doc;
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discussion - <?php echo htmlspecialchars($course_name); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <style>
        /* Toast Notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 400px;
        }

        .toast {
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateX(100%);
            animation: slideIn 0.3s ease forwards;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .toast.success { background: linear-gradient(135deg, #27ae60, #2ecc71); }
        .toast.error   { background: linear-gradient(135deg, #e74c3c, #c0392b); }
        .toast.warning { background: linear-gradient(135deg, #f39c12, #e67e22); }
        .toast.info    { background: linear-gradient(135deg, #3498db, #2980b9); }

        .toast::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: rgba(255, 255, 255, 0.3);
            animation: progress 5s linear forwards;
        }

        .toast .toast-icon  { font-size: 18px; }
        .toast .toast-close { margin-left: auto; cursor: pointer; opacity: 0.7; transition: opacity 0.2s; }
        .toast .toast-close:hover { opacity: 1; }

        @keyframes slideIn  { from { transform: translateX(100%); } to { transform: translateX(0); } }
        @keyframes slideOut { from { transform: translateX(0); } to { transform: translateX(100%); } }
        @keyframes progress { from { width: 100%; } to { width: 0%; } }

        :root {
            --primary-bg:   #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light:   #ffffff;
            --error-color:  #e74c3c;
            --success-color:#2ecc71;
            --warning-color:#f39c12;
            --border-color: rgba(255, 255, 255, 0.1);
            --card-bg:      rgba(255, 255, 255, 0.05);
            --hover-color:  rgba(3, 155, 229, 0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Google Sans', Arial, sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body, .page-container {
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
        }

        .page-container {
            flex: 1 0 auto;
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 80px);
        }

        .discussion {
            flex: 1;
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

        .message-grid {
            max-height: 500px;
            overflow-y: auto;
            margin-bottom: 20px;
            padding-right: 10px;
        }

        main {
            flex: 1 0 auto;
            padding-bottom: 60px;
            min-height: calc(100vh - 60px);
        }

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

        nav { display: flex; justify-content: center; }

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

        nav a:hover::before  { transform: scaleX(1); transform-origin: left; }
        nav a:hover          { background: rgba(255, 255, 255, 0.15); transform: translateY(-2px); }

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

        .message-card {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            width: 80%;
            margin-right: auto;
        }

        .message-card.own {
            background: rgba(3, 155, 229, 0.1);
            margin-left: auto;
            margin-right: 0;
            flex-direction: row-reverse;
        }

        .message-card .message-info { margin-left: 10px; margin-right: 0; }

        .message-card.own .message-info {
            margin-left: 0;
            margin-right: 10px;
            text-align: right;
        }

        .message-card.own .file-preview  { text-align: right; }
        .message-card.own .doc-info      { align-items: flex-end; }
        .message-card.own .action-buttons { justify-content: flex-end; }

        .message-info { margin-left: 10px; max-width: 70%; }
        .message-info h4  { margin: 0; font-size: 14px; font-weight: bold; color: var(--accent-color); }
        .message-info em  { font-size: 12px; color: #A6A6A6; }
        .message-info p   { margin: 5px 0; color: var(--text-light); }

        .file-preview { margin-top: 5px; }

        .file-input {
            margin-bottom: 10px;
            background: var(--accent-color);
            color: var(--text-light);
            padding: 10px;
            border-radius: 5px;
            text-align: center;
            transition: background-color 0.3s;
        }

        .file-input:hover    { background-color: #0288d1; }
        .file-input input    { display: none; }

        .upload-docs {
            display: inline-block;
            background: var(--accent-color);
            color: var(--text-light);
            padding: 10px 20px;
            border-radius: 50px;
            cursor: pointer;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .upload-docs:hover { background: #0288d1; transform: translateY(-2px); }

        .send-message            { margin-top: 20px; }
        .send-message textarea   {
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

        button:hover { background: #0288d1; transform: translateY(-2px); }

        /* Documents Drawer */
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
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.3);
            transition: all 0.3s ease;
            z-index: 100;
        }

        .documents-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(3, 155, 229, 0.4);
        }

        .documents-drawer {
            position: fixed;
            top: 0;
            right: -400px;
            width: 380px;
            height: 100vh;
            background: var(--secondary-bg);
            box-shadow: -4px 0 15px rgba(0, 0, 0, 0.3);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .documents-drawer.open { right: 0; backdrop-filter: blur(10px); }

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

        .drawer-header h3 { font-size: 1.5rem; font-weight: 600; color: var(--accent-color); margin: 0; }

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

        .close-drawer:hover { background: rgba(0, 0, 0, 0.06); transform: rotate(90deg); }

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

        .doc-info a:hover { color: var(--accent-color); }

        .doc-details {
            font-size: 0.8rem;
            color: #A6A6A6;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .doc-icon.pdf           { background: linear-gradient(135deg, #ff5722, #f44336); }
        .doc-icon.doc,
        .doc-icon.docx          { background: linear-gradient(135deg, #2196f3, #1976d2); }
        .doc-icon.xls,
        .doc-icon.xlsx          { background: linear-gradient(135deg, #4caf50, #388e3c); }
        .doc-icon.jpg,
        .doc-icon.png,
        .doc-icon.gif           { background: linear-gradient(135deg, #9c27b0, #7b1fa2); }
        .doc-icon.ppt,
        .doc-icon.pptx          { background: linear-gradient(135deg, #ff9800, #f57c00); }

        .no-docs {
            text-align: center;
            padding: 30px;
            color: #A6A6A6;
            font-style: italic;
            background: var(--card-bg);
            border-radius: 10px;
            margin: 10px 0;
        }

        .drawer-content::-webkit-scrollbar       { width: 6px; }
        .drawer-content::-webkit-scrollbar-track  { background: var(--card-bg); }
        .drawer-content::-webkit-scrollbar-thumb  { background: var(--accent-color); border-radius: 3px; }

        @media (max-width: 1024px) {
            .discussion { width: 95%; margin: 10px auto; }
        }

        @media (max-width: 768px) {
            .discussion      { width: 98%; margin: 5px auto; padding: 15px; }
            .documents-drawer { width: 100%; right: -100%; }
            .document-item   { padding: 12px; }
            .doc-icon        { min-width: 40px; height: 40px; font-size: 12px; }
        }

        /* Footer */
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
            top: 0; left: 0; right: 0;
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

        .footer-logo   { position: relative; display: flex; align-items: center; gap: 15px; transition: transform 0.3s ease; }
        .footer-text   { color: var(--text-light); font-size: 16px; display: flex; align-items: center; gap: 15px; }
        .footer-social { display: flex; gap: 15px; justify-content: center; margin-top: 20px; }

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

        .social-icon:hover { background: #0288d1; transform: translateY(-3px); }

        .footer-copyright { margin-top: 15px; color: rgba(255, 255, 255, 0.7); font-size: 14px; }
        .footer-brand     { color: var(--text-light); font-style: italic; font-weight: 500; }
        .footer-brand:hover { color: #4CAF50; }

        @media (max-width: 768px) {
            .footer-content { flex-direction: column; gap: 20px; }
        }

        @keyframes floatIcon {
            0%   { transform: translateY(100%); opacity: 0; }
            50%  { opacity: 0.3; }
            100% { transform: translateY(-100%); opacity: 0; }
        }

        /* Image preview */
        .image-preview   { margin: 10px 0; position: relative; }

        .thumbnail-image {
            max-width: 200px;
            max-height: 150px;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .thumbnail-image:hover { transform: scale(1.05); }
        .image-actions          { margin-top: 5px; }

        .image-actions a {
            color: var(--accent-color);
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .image-actions a:hover { text-decoration: underline; }

        .image-modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
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

        .modal-content { max-width: 90%; max-height: 90%; display: block; margin: auto; }

        .close-modal {
            position: absolute;
            top: 20px; right: 30px;
            color: white;
            font-size: 30px;
            cursor: pointer;
            z-index: 1002;
            background: rgba(0, 0, 0, 0.5);
            width: 40px; height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close-modal:hover { color: var(--accent-color); transform: scale(1.1); }

        /* Aperçu fichiers avant envoi */
        .file-preview-container { margin: 10px 0; display: flex; flex-wrap: wrap; gap: 10px; }

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

        .file-preview-item .preview-info           { flex: 1; overflow: hidden; }
        .file-preview-item .preview-info .filename { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 14px; color: var(--text-light); }
        .file-preview-item .preview-info .filesize { font-size: 12px; color: #A6A6A6; }

        .file-preview-item .remove-file {
            position: absolute;
            top: -8px; right: -8px;
            width: 20px; height: 20px;
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

        .file-preview-item .remove-file:hover { background: rgba(220, 53, 69, 1); transform: scale(1.1); }

        .file-preview-image { max-width: 80px; max-height: 60px; border-radius: 4px; }

        /* ═══════════════════════════════════════════════════════════
           ██ INSERTION 2 — CSS MODULE PRÉSENCES ÉTUDIANT ██
        ═══════════════════════════════════════════════════════════ */

        /* Bouton Mes Présences — positionné au-dessus du bouton Documents */
        .attendance-student-btn {
            position: fixed;
            right: 20px;
            bottom: 80px;
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
            box-shadow: 0 4px 15px rgba(34, 160, 90, 0.35);
            transition: all 0.3s ease;
            z-index: 100;
        }

        .attendance-student-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 160, 90, 0.5);
        }

        /* Drawer présences étudiant */
        .att-student-drawer {
            position: fixed;
            top: 0;
            right: -520px;
            width: 500px;
            height: 100vh;
            background: linear-gradient(160deg, #051e34 0%, #062a1a 100%);
            box-shadow: -4px 0 20px rgba(0, 0, 0, 0.4);
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1001;
            border-left: 1px solid rgba(34, 160, 90, 0.25);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .att-student-drawer.open { right: 0; }

        /* Header du drawer */
        .att-drawer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            background: rgba(34, 160, 90, 0.12);
            border-bottom: 2px solid #22a05a;
            flex-shrink: 0;
        }

        .att-drawer-header h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #4ddb8a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .att-close-btn {
            background: transparent;
            border: none;
            color: var(--text-light);
            font-size: 22px;
            cursor: pointer;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .att-close-btn:hover { background: rgba(255, 255, 255, 0.1); transform: rotate(90deg); }

        /* Barre de stats (5 compteurs) */
        .att-stats-bar {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            padding: 16px 20px;
            background: rgba(0, 0, 0, 0.2);
            border-bottom: 1px solid rgba(34, 160, 90, 0.15);
            flex-shrink: 0;
        }

        .att-stat-item {
            text-align: center;
            padding: 10px 6px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            transition: transform 0.2s;
        }

        .att-stat-item:hover { transform: translateY(-2px); }

        .att-stat-num {
            font-size: 1.5rem;
            font-weight: 700;
            display: block;
            line-height: 1.1;
        }

        .att-stat-label {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.55);
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
        }

        .att-stat-item.total   .att-stat-num { color: #64b5f6; }
        .att-stat-item.present .att-stat-num { color: #4ddb8a; }
        .att-stat-item.absent  .att-stat-num { color: #f06060; }
        .att-stat-item.late    .att-stat-num { color: #ffd740; }
        .att-stat-item.just    .att-stat-num { color: #ab8ffa; }

        /* Barre de taux de présence */
        .att-rate-bar-wrap {
            padding: 12px 20px 8px;
            flex-shrink: 0;
            background: rgba(0, 0, 0, 0.15);
            border-bottom: 1px solid rgba(34, 160, 90, 0.1);
        }

        .att-rate-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.7);
        }

        .att-rate-pct { font-size: 16px; font-weight: 700; color: #4ddb8a; }

        .att-rate-bar-bg {
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 99px;
            overflow: hidden;
        }

        .att-rate-bar-fill {
            height: 100%;
            border-radius: 99px;
            background: linear-gradient(90deg, #22a05a, #4ddb8a);
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .att-rate-bar-fill.warn   { background: linear-gradient(90deg, #f57c00, #ffd740); }
        .att-rate-bar-fill.danger { background: linear-gradient(90deg, #c62828, #f06060); }

        /* Mini graphique d'évolution */
        .att-chart-wrap {
            padding: 14px 20px 10px;
            border-bottom: 1px solid rgba(34, 160, 90, 0.1);
            flex-shrink: 0;
        }

        .att-chart-title {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.45);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .att-chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 3px;
            height: 50px;
        }

        .att-chart-bar-wrap {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            height: 100%;
            justify-content: flex-end;
        }

        .att-chart-bar {
            width: 100%;
            border-radius: 3px 3px 0 0;
            min-height: 2px;
            transition: height 0.4s ease;
        }

        .att-chart-bar.present   { background: #4ddb8a; }
        .att-chart-bar.absent    { background: #f06060; }
        .att-chart-bar.late      { background: #ffd740; }
        .att-chart-bar.justified { background: #ab8ffa; }
        .att-chart-bar.unmarked  { background: rgba(255, 255, 255, 0.15); }

        .att-chart-bar-label { font-size: 9px; color: rgba(255, 255, 255, 0.3); }

        /* Filtres par statut */
        .att-filter-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .att-filter-btn {
            padding: 5px 12px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid transparent;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.06);
            color: rgba(255, 255, 255, 0.7);
            transition: all 0.2s;
        }

        .att-filter-btn[data-filter="all"].active      { background: rgba(100, 181, 246, 0.2); border-color: #64b5f6; color: #64b5f6; }
        .att-filter-btn[data-filter="present"].active  { background: rgba(77, 219, 138, 0.2);  border-color: #4ddb8a; color: #4ddb8a; }
        .att-filter-btn[data-filter="absent"].active   { background: rgba(240, 96, 96, 0.2);   border-color: #f06060; color: #f06060; }
        .att-filter-btn[data-filter="late"].active     { background: rgba(255, 215, 64, 0.2);  border-color: #ffd740; color: #ffd740; }
        .att-filter-btn[data-filter="justified"].active { background: rgba(171, 143, 250, 0.2); border-color: #ab8ffa; color: #ab8ffa; }

        /* Zone liste séances */
        .att-sessions-wrap {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: 0;
            padding: 12px 20px 20px;
            -webkit-overflow-scrolling: touch;
        }

        .att-sessions-wrap::-webkit-scrollbar       { width: 5px; }
        .att-sessions-wrap::-webkit-scrollbar-thumb  { background: #22a05a; border-radius: 3px; }

        /* Carte séance individuelle */
        .att-session-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            margin-bottom: 8px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.07);
            border-radius: 10px;
            transition: all 0.2s;
        }

        .att-session-card:hover { background: rgba(255, 255, 255, 0.07); border-color: rgba(34, 160, 90, 0.3); }

        .att-session-card.present   { border-left: 3px solid #4ddb8a; }
        .att-session-card.absent    { border-left: 3px solid #f06060; }
        .att-session-card.late      { border-left: 3px solid #ffd740; }
        .att-session-card.justified { border-left: 3px solid #ab8ffa; }
        .att-session-card.unmarked  { border-left: 3px solid rgba(255, 255, 255, 0.2); }

        .att-session-date        { min-width: 80px; text-align: center; }
        .att-session-date .day   { font-size: 1.4rem; font-weight: 700; color: #fff; line-height: 1; }
        .att-session-date .month { font-size: 11px; color: rgba(255, 255, 255, 0.5); text-transform: uppercase; letter-spacing: 0.5px; }

        .att-session-info  { flex: 1; }
        .att-session-time  { font-size: 12px; color: rgba(255, 255, 255, 0.5); margin-top: 2px; }

        /* Pills de statut (lecture seule) */
        .att-status-pill {
            padding: 4px 12px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .att-status-pill.present   { background: rgba(77, 219, 138, 0.15); color: #4ddb8a; border: 1px solid rgba(77, 219, 138, 0.3); }
        .att-status-pill.absent    { background: rgba(240, 96, 96, 0.15);  color: #f06060; border: 1px solid rgba(240, 96, 96, 0.3); }
        .att-status-pill.late      { background: rgba(255, 215, 64, 0.15); color: #ffd740; border: 1px solid rgba(255, 215, 64, 0.3); }
        .att-status-pill.justified { background: rgba(171, 143, 250, 0.15); color: #ab8ffa; border: 1px solid rgba(171, 143, 250, 0.3); }
        .att-status-pill.unmarked  { background: rgba(255, 255, 255, 0.07); color: rgba(255, 255, 255, 0.4); border: 1px solid rgba(255, 255, 255, 0.1); }

        .att-justification-text {
            font-size: 11px;
            color: rgba(171, 143, 250, 0.8);
            margin-top: 4px;
            font-style: italic;
        }

        /* États vide / loading */
        .att-empty-state { text-align: center; padding: 50px 20px; color: rgba(255, 255, 255, 0.35); }
        .att-empty-state i { font-size: 3rem; margin-bottom: 12px; display: block; }

        .att-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 30px;
            color: rgba(255, 255, 255, 0.5);
        }

        .att-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.15);
            border-top-color: #4ddb8a;
            border-radius: 50%;
            animation: attSpin 0.7s linear infinite;
        }

        @keyframes attSpin { to { transform: rotate(360deg); } }

        /* Overlay */
        .att-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            backdrop-filter: blur(2px);
        }

        .att-overlay.show { display: block; }

        @media (max-width: 768px) {
            .att-student-drawer { width: 100%; right: -100%; }
            .att-stats-bar      { grid-template-columns: repeat(3, 1fr); }
        }

        /* ════ FIN CSS PRÉSENCES ════ */

        /* ============================================================
           INSERTION 2 -- PROGRESSION ETUDIANT -- BOUTON + DRAWER
        ============================================================ */

        .progress-student-btn {
            position: fixed; right: 20px; bottom: 140px;
            padding: 12px 25px;
            background: linear-gradient(135deg, #4a1a6b, #7b2fa0);
            color: #fff; border: none; border-radius: 50px; cursor: pointer;
            display: flex; align-items: center; gap: 10px;
            font-weight: 500; box-shadow: 0 4px 15px rgba(123,47,160,.35);
            transition: all .3s ease; z-index: 100;
        }
        .progress-student-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(123,47,160,.5); }
        .drawer-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.5); z-index: 999; backdrop-filter: blur(2px);
        }
        .drawer-overlay.show { display: block; }
        .prog-student-drawer {
            position: fixed; top: 0; right: -520px; width: 500px; height: 100vh;
            background: linear-gradient(160deg, #051e34 0%, #1a0a2e 100%);
            box-shadow: -4px 0 20px rgba(0,0,0,.4);
            transition: all .35s cubic-bezier(.4,0,.2,1);
            z-index: 1002; border-left: 1px solid rgba(123,47,160,.25);
            display: flex; flex-direction: column; overflow: hidden;
        }
        .prog-student-drawer.open { right: 0; }
        .prog-stu-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 20px; background: rgba(123,47,160,.12); border-bottom: 2px solid #7b2fa0; flex-shrink: 0; }
        .prog-stu-header h3 { font-size: 1.3rem; font-weight: 700; color: #c084fc; margin: 0; display: flex; align-items: center; gap: 10px; }
        .prog-stu-close { background: transparent; border: none; color: #fff; font-size: 22px; cursor: pointer; width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: all .3s; }
        .prog-stu-close:hover { background: rgba(255,255,255,.1); transform: rotate(90deg); }
        .prog-stu-kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding: 14px 20px; background: rgba(0,0,0,.2); border-bottom: 1px solid rgba(123,47,160,.15); flex-shrink: 0; }
        .prog-stu-kpi { text-align: center; padding: 10px 6px; border-radius: 10px; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); }
        .prog-stu-kpi-num { font-size: 1.4rem; font-weight: 700; display: block; color: #c084fc; }
        .prog-stu-kpi-label { font-size: 10px; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: .5px; margin-top: 3px; display: block; }
        .prog-stu-bar-wrap { padding: 12px 20px 10px; flex-shrink: 0; background: rgba(0,0,0,.15); border-bottom: 1px solid rgba(123,47,160,.1); }
        .prog-stu-bar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-size: 13px; color: rgba(255,255,255,.7); }
        .prog-stu-bar-pct { font-size: 16px; font-weight: 700; color: #c084fc; }
        .prog-stu-bar-bg { height: 8px; background: rgba(255,255,255,.1); border-radius: 99px; overflow: hidden; }
        .prog-stu-bar-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #7b2fa0, #c084fc); transition: width .7s cubic-bezier(.4,0,.2,1); }
        .prog-stu-chapters-wrap { flex: 1; overflow-y: auto; overflow-x: hidden; min-height: 0; padding: 12px 20px 20px; -webkit-overflow-scrolling: touch; }
        .prog-stu-chapters-wrap::-webkit-scrollbar { width: 5px; }
        .prog-stu-chapters-wrap::-webkit-scrollbar-thumb { background: #7b2fa0; border-radius: 3px; }
        .prog-stu-chapter { margin-bottom: 10px; border: 1px solid rgba(123,47,160,.2); border-radius: 10px; overflow: hidden; }
        .prog-stu-chap-header { display: flex; align-items: center; gap: 10px; padding: 12px 14px; background: rgba(123,47,160,.08); cursor: pointer; transition: background .2s; user-select: none; }
        .prog-stu-chap-header:hover { background: rgba(123,47,160,.15); }
        .prog-stu-chap-num { min-width: 28px; height: 28px; border-radius: 50%; background: linear-gradient(135deg, #7b2fa0, #c084fc); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #fff; flex-shrink: 0; }
        .prog-stu-chap-title { flex: 1; font-size: 14px; font-weight: 600; color: #e2c9ff; }
        .prog-stu-chap-badge { font-size: 11px; color: rgba(255,255,255,.5); background: rgba(255,255,255,.06); padding: 2px 8px; border-radius: 99px; }
        .prog-stu-chap-arrow { font-size: 12px; color: rgba(255,255,255,.4); transition: transform .2s; }
        .prog-stu-chapter.open .prog-stu-chap-arrow { transform: rotate(180deg); }
        .prog-stu-sessions { display: none; padding: 4px 0; }
        .prog-stu-chapter.open .prog-stu-sessions { display: block; }
        .prog-stu-session { display: flex; align-items: flex-start; gap: 10px; padding: 10px 14px; border-top: 1px solid rgba(255,255,255,.05); transition: background .2s; }
        .prog-stu-session:hover { background: rgba(255,255,255,.03); }
        .prog-stu-sess-num { min-width: 24px; height: 24px; border-radius: 50%; background: rgba(192,132,252,.15); border: 1px solid rgba(192,132,252,.3); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: #c084fc; flex-shrink: 0; margin-top: 1px; }
        .prog-stu-sess-body { flex: 1; }
        .prog-stu-sess-title { font-size: 13px; font-weight: 600; color: #e2c9ff; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .prog-stu-sess-meta { font-size: 11px; color: rgba(255,255,255,.45); margin-top: 3px; display: flex; gap: 10px; flex-wrap: wrap; }
        .prog-stu-sess-desc-toggle { font-size: 11px; color: rgba(192,132,252,.7); cursor: pointer; border: none; background: transparent; padding: 0; display: inline-flex; align-items: center; gap: 3px; margin-top: 4px; transition: color .2s; }
        .prog-stu-sess-desc-toggle:hover { color: #c084fc; }
        .prog-stu-sess-desc { display: none; font-size: 12px; color: rgba(255,255,255,.55); margin-top: 6px; padding: 8px 10px; background: rgba(255,255,255,.04); border-radius: 6px; border-left: 2px solid rgba(123,47,160,.4); line-height: 1.5; }
        .prog-stu-sess-desc.open { display: block; }
        .prog-stu-loading { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 40px; color: rgba(255,255,255,.4); }
        .prog-stu-spinner { width: 20px; height: 20px; border: 2px solid rgba(255,255,255,.1); border-top-color: #c084fc; border-radius: 50%; animation: progStuSpin .7s linear infinite; }
        @keyframes progStuSpin { to { transform: rotate(360deg); } }
        .prog-stu-empty { text-align: center; padding: 50px 20px; color: rgba(255,255,255,.3); }
        .prog-stu-empty i { font-size: 3rem; margin-bottom: 12px; display: block; }
        @media (max-width: 768px) { .prog-student-drawer { width: 100%; right: -100%; } }
        /* ==== FIN CSS PROGRESSION ETUDIANT ==== */
    </style>
</head>
<body>

<!-- Barre de navigation -->
<?php include '../includes/header_discussion_student.php'; ?>

<!-- Zone de discussion -->
<div class="discussion">

    <script>
        // Année académique fixée par le contexte d'entrée (venant du dashboard),
        // remplace l'ancien select[name="year"] pour tous les appels AJAX de cette page.
        const FIXED_ACADEMIC_YEAR = <?php echo json_encode($filter_year); ?>;
    </script>

    <!-- Année académique consultée : fixée depuis le tableau de bord, non modifiable ici.
         Le changement d'année académique se fait désormais uniquement via student_dashboard.php,
         qui détermine la bonne classe historique avant de rediriger vers cette page. -->
    <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="font-size:13px;color:rgba(255,255,255,0.6);">
            <i class="fas fa-calendar-alt"></i> Année académique :
            <strong style="color:#fff;"><?php echo htmlspecialchars($filter_year); ?></strong>
            <?php if ($filter_year !== $current_year): ?>
                <span style="color:var(--accent-color);">(archive)</span>
            <?php endif; ?>
        </span>
    </div>

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
                                            <span class="doc-details">Taille du fichier : <?php echo round(filesize("../uploads/" . $row['file_path']) / 1024, 2); ?> Ko</span>
                                        </div>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Boutons d'action pour l'auteur du message -->
                        <?php 
                        $created_at      = strtotime($row['created_at']);
                        $now             = time();
                        $time_difference = $now - $created_at;

                        if ($row['sender_id'] == $user_id && $time_difference <= 1200 && $filter_year === $current_year): ?>
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
    <div class="send-message">
        <?php if ($filter_year === $current_year): ?>
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
        <?php else: ?>
        <p style="color:rgba(255,255,255,0.6);font-style:italic;">
            <i class="fas fa-lock"></i> Cette année académique est archivée : l'envoi de messages et de documents n'est plus possible.
        </p>
        <?php endif; ?>
    </div>

    <!-- Bouton Documents (existant) -->
    <button type="button" class="documents-btn">
        <i class="fas fa-folder"></i> Voir les Documents
    </button>

    <!-- ██ INSERTION 3a — BOUTON PRÉSENCES ÉTUDIANT ██ -->
    <!-- ██ INSERTION 3a — BOUTON PROGRESSION ÉTUDIANT ██ -->
    <button type="button" class="progress-student-btn" onclick="openProgDrawer()">
        <i class="fas fa-chart-line"></i> Progression
    </button>

    <button type="button" class="attendance-student-btn" onclick="openAttDrawer()">
        <i class="fas fa-clipboard-check"></i> Mes Présences
    </button>

    <!-- Bouton flottant Devoirs (gauche) -->
    <button type="button" class="assign-btn-student" title="Mes devoirs" onclick="ouvrirDevoirsEtudiant()">
        <span class="assign-btn-inner">
            <i class="fas fa-book-open"></i>
            <?php if ($pending_count > 0): ?>
            <span class="assign-badge" id="assignBadge"><?= $pending_count ?></span>
            <?php else: ?>
            <span class="assign-badge" id="assignBadge" style="display:none">0</span>
            <?php endif; ?>
        </span>
    </button>

    <!-- Drawer des documents (existant) -->
    <div class="documents-drawer" id="documentsDrawer">
        <div class="drawer-header">
            <h3>Documents</h3>
            <button type="button" class="close-drawer">×</button>
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
                                <span class="doc-details">Taille : <?php echo round(filesize("../uploads/" . $doc['file_path']) / 1024, 2); ?> Ko</span>
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
                                <span class="doc-details">Taille : <?php echo round(filesize("../uploads/" . $doc['file_path']) / 1024, 2); ?> Ko</span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="no-docs">Aucun document disponible.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ██ INSERTION 3b — OVERLAY + DRAWER PRÉSENCES ██ -->
    <div class="att-overlay" id="attOverlay" onclick="closeAttDrawer()"></div>

    <div class="att-student-drawer" id="attStudentDrawer">

        <!-- Header -->
        <div class="att-drawer-header">
            <div>
                <h3><i class="fas fa-calendar-check"></i> Mes Présences</h3>
                <div style="font-size:11px;color:rgba(255,255,255,.5);margin-top:3px;">
                    <?php echo htmlspecialchars($filter_year); ?><?php if ($filter_year === $current_year): ?> &mdash; Semestre <?php echo $current_semester; ?><?php endif; ?>
                </div>
            </div>
            <button class="att-close-btn" onclick="closeAttDrawer()">×</button>
        </div>

        <!-- Compteurs stats -->
        <div class="att-stats-bar">
            <div class="att-stat-item total">
                <span class="att-stat-num" id="attStatTotal">–</span>
                <span class="att-stat-label">Séances</span>
            </div>
            <div class="att-stat-item present">
                <span class="att-stat-num" id="attStatPresent">–</span>
                <span class="att-stat-label">Présent</span>
            </div>
            <div class="att-stat-item absent">
                <span class="att-stat-num" id="attStatAbsent">–</span>
                <span class="att-stat-label">Absent</span>
            </div>
            <div class="att-stat-item late">
                <span class="att-stat-num" id="attStatLate">–</span>
                <span class="att-stat-label">Retard</span>
            </div>
            <div class="att-stat-item just">
                <span class="att-stat-num" id="attStatJust">–</span>
                <span class="att-stat-label">Justifié</span>
            </div>
        </div>

        <!-- Barre taux de présence -->
        <div class="att-rate-bar-wrap">
            <div class="att-rate-header">
                <span>Taux de présence</span>
                <span class="att-rate-pct" id="attRatePct">–%</span>
            </div>
            <div class="att-rate-bar-bg">
                <div class="att-rate-bar-fill" id="attRateBarFill" style="width: 0%"></div>
            </div>
        </div>

        <!-- Mini graphique d'évolution (10 dernières séances) -->
        <div class="att-chart-wrap">
            <div class="att-chart-title">Évolution par séance (10 dernières)</div>
            <div class="att-chart-bars" id="attChartBars">
                <!-- Barres générées en JS -->
            </div>
        </div>

        <!-- Filtres par statut -->
        <div style="padding: 12px 20px 0; flex-shrink: 0;">
            <div class="att-filter-row">
                <button class="att-filter-btn active" data-filter="all"       onclick="attSetFilter('all')">Tout</button>
                <button class="att-filter-btn"         data-filter="present"  onclick="attSetFilter('present')">Présent</button>
                <button class="att-filter-btn"         data-filter="absent"   onclick="attSetFilter('absent')">Absent</button>
                <button class="att-filter-btn"         data-filter="late"     onclick="attSetFilter('late')">Retard</button>
                <button class="att-filter-btn"         data-filter="justified" onclick="attSetFilter('justified')">Justifié</button>
            </div>
        </div>

        <!-- Liste des séances -->
        <div class="att-sessions-wrap" id="attSessionsList">
            <div class="att-loading">
                <div class="att-spinner"></div> Chargement…
            </div>
        </div>

    </div>
    <!-- ══ FIN DRAWER PRÉSENCES ══ -->

    <!-- ██ INSERTION 3b — OVERLAY COMMUN + DRAWER PROGRESSION ÉTUDIANT ██ -->
    <div class="drawer-overlay" id="drawerOverlay" onclick="closeAllDrawers()"></div>

    <div class="prog-student-drawer" id="progStudentDrawer">
        <div class="prog-stu-header">
            <h3><i class="fas fa-chart-line"></i> Progression du cours</h3>
            <button class="prog-stu-close" onclick="closeProgDrawer()" title="Fermer">&#10005;</button>
        </div>
        <div class="prog-stu-kpis">
            <div class="prog-stu-kpi">
                <span class="prog-stu-kpi-num" id="progStuHoursDone">0</span>
                <span class="prog-stu-kpi-label">Heures effectu&#233;es</span>
            </div>
            <div class="prog-stu-kpi">
                <span class="prog-stu-kpi-num" id="progStuSessions">0</span>
                <span class="prog-stu-kpi-label">S&#233;ances r&#233;alis&#233;es</span>
            </div>
            <div class="prog-stu-kpi">
                <span class="prog-stu-kpi-num" id="progStuHoursPlanned">0</span>
                <span class="prog-stu-kpi-label">Heures pr&#233;vues</span>
            </div>
        </div>
        <div class="prog-stu-bar-wrap">
            <div class="prog-stu-bar-header">
                <span>Avancement du cours</span>
                <span class="prog-stu-bar-pct" id="progStuBarPct">0%</span>
            </div>
            <div class="prog-stu-bar-bg">
                <div class="prog-stu-bar-fill" id="progStuBarFill" style="width:0%"></div>
            </div>
        </div>
        <div class="prog-stu-chapters-wrap">
            <div id="progStuChaptersList">
                <div class="prog-stu-loading">
                    <div class="prog-stu-spinner"></div>
                    <span>Chargement...</span>
                </div>
            </div>
        </div>
    </div>
    <!-- ██ FIN INSERTION 3b ██ -->

</div>

<!-- ═══════════ MODAL DEVOIRS ÉTUDIANT ═══════════ -->
<div class="assign-stu-overlay" id="mDevoirsEtudiant">
    <div class="assign-stu-modal">
        <button class="close-btn" onclick="fermerDevoirsEtudiant()" title="Fermer">&times;</button>
        <h2><i class="fas fa-book-open" style="margin-right:8px"></i>Mes Devoirs</h2>
        <div id="stuAssignList">
            <div class="assign-stu-empty"><i class="fas fa-circle-notch fa-spin"></i> Chargement…</div>
        </div>
    </div>
</div>

<!-- Modal pour l'affichage d'images en plein écran -->
<div id="imageModal" class="image-modal">
    <span class="close-modal" onclick="closeImagePreview()">&times;</span>
    <img id="modalImage" class="modal-content">
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

<script src="../api/assets/js/main.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Récupération et application des couleurs stockées
    const mainColor   = localStorage.getItem('mainColor');
    const accentColor = localStorage.getItem('accentColor');
    const bgColor     = localStorage.getItem('bgColor');
    const themeColor  = localStorage.getItem('themeColor');

    if (mainColor) {
        document.documentElement.style.setProperty('--main-color', mainColor);
        document.documentElement.style.setProperty('--primary-bg', mainColor);
    }
    if (accentColor) {
        document.documentElement.style.setProperty('--accent', accentColor);
        document.documentElement.style.setProperty('--accent-color', accentColor);
    }
    if (bgColor) {
        document.documentElement.style.setProperty('--bg-color', bgColor);
        document.documentElement.style.setProperty('--secondary-bg', bgColor);
    }
    if (themeColor) {
        document.documentElement.style.setProperty('--primary-color', themeColor);
    }

    window.addEventListener('storage', function(e) {
        switch(e.key) {
            case 'mainColor':
                document.documentElement.style.setProperty('--main-color', e.newValue);
                document.documentElement.style.setProperty('--primary-bg', e.newValue);
                break;
            case 'accentColor':
                document.documentElement.style.setProperty('--accent', e.newValue);
                document.documentElement.style.setProperty('--accent-color', e.newValue);
                break;
            case 'bgColor':
                document.documentElement.style.setProperty('--bg-color', e.newValue);
                document.documentElement.style.setProperty('--secondary-bg', e.newValue);
                break;
            case 'themeColor':
                document.documentElement.style.setProperty('--primary-color', e.newValue);
                break;
        }
    });

    // Gestion du drawer documents
    window.toggleDrawer = function() {
        const drawer = document.getElementById('documentsDrawer');
        drawer.classList.toggle('open');
    };

    const navLinks      = document.querySelectorAll('nav a');
    const header        = document.querySelector('header');
    const floatingIcons = document.querySelectorAll('.floating-icon');
    const drawer        = document.getElementById('documentsDrawer');
    const documentsBtn  = document.querySelector('.documents-btn');
    const closeDrawerBtn = document.querySelector('.close-drawer');
    const messageGrid   = document.querySelector('.message-grid');

    navLinks.forEach(link => {
        link.addEventListener('mouseenter', function() { this.style.transform = 'translateY(-2px)'; });
        link.addEventListener('mouseleave', function() { this.style.transform = 'translateY(0)'; });
    });

    if (header && floatingIcons) {
        header.addEventListener('mouseenter', () => {
            floatingIcons.forEach(icon => { icon.style.opacity = '1'; resetAnimation(icon); });
        });
        header.addEventListener('mouseleave', () => {
            floatingIcons.forEach(icon => { icon.style.opacity = '0'; });
        });
    }

    if (documentsBtn) {
        documentsBtn.addEventListener('click', function(e) { e.preventDefault(); toggleDrawer(); });
    }

    if (closeDrawerBtn) {
        closeDrawerBtn.addEventListener('click', function(e) { e.preventDefault(); toggleDrawer(); });
    }

    if (drawer) {
        document.addEventListener('click', function(e) {
            if (drawer.classList.contains('open') &&
                !drawer.contains(e.target) &&
                !documentsBtn.contains(e.target)) {
                drawer.classList.remove('open');
            }
        });
        drawer.addEventListener('click', function(e) { e.stopPropagation(); });
    }

    if (messageGrid) { messageGrid.scrollTop = messageGrid.scrollHeight; }

    const buttons = document.querySelectorAll('button');
    buttons.forEach(button => {
        button.addEventListener('mouseenter', function() {
            if (!this.classList.contains('close-drawer')) this.style.transform = 'translateY(-2px)';
        });
        button.addEventListener('mouseleave', function() {
            if (!this.classList.contains('close-drawer')) this.style.transform = 'translateY(0)';
        });
    });

    const fileInput = document.querySelector('input[type="file"]');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const fileLabel = this.previousElementSibling;
            fileLabel.textContent = this.files.length > 0
                ? `${this.files.length} fichier(s) sélectionné(s)`
                : 'Sélectionner des documents';
        });
    }

    const messageForm = document.querySelector('form');
    if (messageForm) {
        messageForm.addEventListener('submit', () => { setTimeout(scrollToBottom, 100); });
    }

    const messageTextarea = document.querySelector('textarea[name="message"]');
    if (messageTextarea) {
        messageTextarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    }

    const isDarkTheme = localStorage.getItem('darkTheme') === 'true';
    if (isDarkTheme) { document.body.classList.add('dark-theme'); }
});

// Fonctions utilitaires
function resetAnimation(element) {
    element.style.animation = 'none';
    element.offsetHeight;
    element.style.animation = null;
}

function scrollToBottom() {
    const messageGrid = document.querySelector('.message-grid');
    if (messageGrid) { messageGrid.scrollTop = messageGrid.scrollHeight; }
}

function confirmDelete(messageId) {
    if (confirm('Voulez-vous vraiment supprimer ce message ?')) {
        document.querySelector(`form[data-message-id="${messageId}"]`).submit();
    }
}

function handleUploadError(error) {
    console.error('Erreur de téléchargement:', error);
    alert('Une erreur est survenue lors du téléchargement du fichier. Veuillez réessayer.');
}

const DEBUG = false;
function debug(message) { if (DEBUG) { console.log(`[Debug] ${message}`); } }

// Modal image
document.body.insertAdjacentHTML('beforeend', `
    <div id="imageModal" class="image-modal">
        <span class="close-modal" onclick="closeImagePreview()">&times;</span>
        <img id="modalImage" class="modal-content">
    </div>
`);

function openImagePreview(imageSrc) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    modal.style.display = 'flex';
    modalImg.src = imageSrc;
    document.body.style.overflow = 'hidden';
}

function closeImagePreview() {
    const modal = document.getElementById('imageModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

document.getElementById('imageModal').addEventListener('click', function(e) {
    if (e.target === this) { closeImagePreview(); }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('imageModal').style.display === 'flex') {
        closeImagePreview();
    }
});

// Prévisualisation fichiers avant envoi
function previewFiles(input) {
    const previewContainer = document.getElementById('file-preview-container');
    
    if (input.files && input.files.length > 0) {
        Array.from(input.files).forEach((file, index) => {
            const fileItem = document.createElement('div');
            fileItem.className = 'file-preview-item';
            
            const extension = file.name.split('.').pop().toLowerCase();
            const isImage   = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(extension);
            
            let previewContent = '';
            if (isImage) {
                const imageUrl = URL.createObjectURL(file);
                previewContent = `<img src="${imageUrl}" alt="${file.name}" class="file-preview-image">`;
            } else {
                const iconColor = getFileIconColor(extension);
                previewContent = `<div class="preview-icon" style="background: ${iconColor}">${extension}</div>`;
            }
            
            fileItem.innerHTML = `
                ${previewContent}
                <div class="preview-info">
                    <div class="filename">${file.name}</div>
                    <div class="filesize">${formatFileSize(file.size)}</div>
                </div>
                <div class="remove-file" onclick="removeFile(${index}, this)">×</div>
            `;
            
            previewContainer.appendChild(fileItem);
        });
    }
}

function getFileIconColor(extension) {
    const colors = {
        pdf:  'linear-gradient(135deg, #ff5722, #f44336)',
        doc:  'linear-gradient(135deg, #2196f3, #1976d2)',
        docx: 'linear-gradient(135deg, #2196f3, #1976d2)',
        xls:  'linear-gradient(135deg, #4caf50, #388e3c)',
        xlsx: 'linear-gradient(135deg, #4caf50, #388e3c)',
        ppt:  'linear-gradient(135deg, #ff9800, #f57c00)',
        pptx: 'linear-gradient(135deg, #ff9800, #f57c00)',
        txt:  'linear-gradient(135deg, #607d8b, #455a64)',
        zip:  'linear-gradient(135deg, #795548, #5d4037)',
        rar:  'linear-gradient(135deg, #795548, #5d4037)',
        mp3:  'linear-gradient(135deg, #9c27b0, #7b1fa2)',
        mp4:  'linear-gradient(135deg, #e91e63, #c2185b)',
    };
    return colors[extension] || 'linear-gradient(135deg, #9e9e9e, #616161)';
}

function formatFileSize(bytes) {
    if (bytes < 1024)    return bytes + ' octets';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' Ko';
    return (bytes / 1048576).toFixed(1) + ' Mo';
}

function removeFile(index, element) {
    const input = document.querySelector('input[type="file"]');
    const dt    = new DataTransfer();
    Array.from(input.files)
        .filter((file, i) => i !== index)
        .forEach(file => dt.items.add(file));
    input.files = dt.files;
    element.parentElement.remove();
    const fileLabel = input.previousElementSibling;
    fileLabel.textContent = input.files.length > 0
        ? `${input.files.length} fichier(s) sélectionné(s)`
        : 'Sélectionner des documents';
}
</script>

<script>
let lastMessageId = 0;

function loadMessages(force = false) {
    const _yr = FIXED_ACADEMIC_YEAR || '';
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
                    ? `<button class="delete-message" data-id="${msg.discussion_id}">Supprimer</button>`
                    : '';
                const div = document.createElement('div');
                div.innerHTML = `
                    <div class="message-card ${messageClass}">
                        <img src="${avatarPath}" alt="Avatar" width="40" height="40">
                        <div class="message-info">
                            <h4>${msg.name} <em>${msg.created_at}</em></h4>
                            <p>${msg.message}</p>
                            ${msg.document_id ? `<div class="file-preview"><a href="../uploads/${msg.file_path}" download>${msg.file_path}</a></div>` : ''}
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
            .then(() => {
                messageDiv.style.transition = 'opacity 0.3s';
                messageDiv.style.opacity = '0';
                setTimeout(() => messageDiv.remove(), 300);
            })
            .catch(() => alert('Erreur lors de la suppression.'));
    }
});
</script>


<!-- ══════════════════════════════════════════════════════════
     ██ INSERTION 4 — JS MODULE PRÉSENCES ÉTUDIANT ██
══════════════════════════════════════════════════════════════ -->
<script>
const ATT_COURSE_ID = <?php echo json_encode($course_id); ?>;

let attAllSessions = [];   // cache de toutes les séances
let attFilter      = 'all';
let attLoaded      = false;

// ─── Ouvrir le drawer ───
function openAttDrawer() {
    document.getElementById('attStudentDrawer').classList.add('open');
    document.getElementById('attOverlay').classList.add('show');
    document.body.style.overflow = 'hidden';
    if (!attLoaded) loadStudentAttendance();
}

// ─── Fermer le drawer ───
function closeAttDrawer() {
    document.getElementById('attStudentDrawer').classList.remove('open');
    document.getElementById('attOverlay').classList.remove('show');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAttDrawer();
});

// ─── Charger les données via AJAX ───
function loadStudentAttendance() {
    const list = document.getElementById('attSessionsList');
    list.innerHTML = '<div class="att-loading"><div class="att-spinner"></div> Chargement…</div>';

    const _attYr = FIXED_ACADEMIC_YEAR || '';
    const _attYrQ = _attYr ? '&year=' + encodeURIComponent(_attYr) : '';
    fetch('?course_id=' + ATT_COURSE_ID + '&ajax=student_attendance' + _attYrQ)
        .then(r => r.json())
        .then(data => {
            attAllSessions = data.sessions || [];
            attLoaded      = true;
            renderAttStats(data.stats || {});
            renderAttChart(attAllSessions);
            renderAttSessions();
        })
        .catch(() => {
            list.innerHTML = '<div class="att-empty-state"><i class="fas fa-exclamation-triangle"></i>Erreur de chargement.</div>';
        });
}

// ─── Afficher les compteurs et la barre de taux ───
function renderAttStats(stats) {
    document.getElementById('attStatTotal').textContent    = stats.total    ?? 0;
    document.getElementById('attStatPresent').textContent  = stats.present  ?? 0;
    document.getElementById('attStatAbsent').textContent   = stats.absent   ?? 0;
    document.getElementById('attStatLate').textContent     = stats.late     ?? 0;
    document.getElementById('attStatJust').textContent     = stats.justified ?? 0;

    const rate = stats.rate ?? 0;
    const rateColor = rate >= 75 ? '#4ddb8a' : rate >= 60 ? '#ffd740' : '#f06060';
    const ratePctEl = document.getElementById('attRatePct');
    ratePctEl.textContent = rate + '%';
    ratePctEl.style.color = rateColor;

    const fill = document.getElementById('attRateBarFill');
    fill.style.width = rate + '%';
    fill.className   = 'att-rate-bar-fill';
    if      (rate < 60) fill.classList.add('danger');
    else if (rate < 75) fill.classList.add('warn');
}

// ─── Mini graphique en barres (10 dernières séances) ───
function renderAttChart(sessions) {
    const container = document.getElementById('attChartBars');
    container.innerHTML = '';

    if (!sessions || sessions.length === 0) {
        container.innerHTML = '<span style="color:rgba(255,255,255,.3);font-size:11px;">Aucune séance</span>';
        return;
    }

    // Prendre les 10 dernières dans l'ordre chronologique
    const last10 = [...sessions].reverse().slice(0, 10).reverse();
    const heights = { present: 45, late: 30, justified: 35, absent: 20, unmarked: 10 };

    last10.forEach(s => {
        const status = s.status || 'unmarked';
        const d      = new Date(s.session_date);
        const label  = String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0');
        const h      = heights[status] || 10;
        const bw     = document.createElement('div');
        bw.className = 'att-chart-bar-wrap';
        bw.innerHTML = `
            <div class="att-chart-bar ${status}" style="height:${h}px"
                 title="${label} — ${attStatusLabel(status)}"></div>
            <span class="att-chart-bar-label">${label.split('/')[0]}</span>
        `;
        container.appendChild(bw);
    });
}

// ─── Changer le filtre actif ───
function attSetFilter(f) {
    attFilter = f;
    document.querySelectorAll('.att-filter-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.filter === f);
    });
    renderAttSessions();
}

// ─── Rendre la liste des séances ───
function renderAttSessions() {
    const list = document.getElementById('attSessionsList');

    if (!attAllSessions.length) {
        list.innerHTML = '<div class="att-empty-state"><i class="fas fa-calendar-times"></i>Aucune séance enregistrée pour ce cours.</div>';
        return;
    }

    const filtered = attFilter === 'all'
        ? attAllSessions
        : attAllSessions.filter(s => (s.status || 'unmarked') === attFilter);

    if (!filtered.length) {
        list.innerHTML = `<div class="att-empty-state"><i class="fas fa-filter"></i>Aucune séance avec le statut « ${attStatusLabel(attFilter)} ».</div>`;
        return;
    }

    list.innerHTML = filtered.map(s => attSessionCard(s)).join('');
}

// ─── Générer le HTML d'une carte séance ───
function attSessionCard(s) {
    const status = s.status || 'unmarked';
    const d      = new Date(s.session_date);
    const day    = String(d.getDate()).padStart(2, '0');
    const month  = d.toLocaleDateString('fr-FR', { month: 'short' });
    const year   = d.getFullYear();
    const time   = (s.start_time && s.end_time)
        ? s.start_time.slice(0, 5) + ' – ' + s.end_time.slice(0, 5)
        : 'Horaire non précisé';
    const fullDay    = d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
    const justHtml   = (status === 'justified' && s.justification)
        ? `<div class="att-justification-text"><i class="fas fa-info-circle"></i> ${escHtml(s.justification)}</div>`
        : '';

    return `
        <div class="att-session-card ${status}">
            <div class="att-session-date">
                <div class="day">${day}</div>
                <div class="month">${month} ${year}</div>
            </div>
            <div class="att-session-info">
                <div style="font-size:13px;font-weight:600;color:#fff;">${fullDay}</div>
                <div class="att-session-time">
                    <i class="fas fa-clock" style="font-size:10px;margin-right:3px;"></i>${time}
                </div>
                ${justHtml}
            </div>
            <span class="att-status-pill ${status}">${attStatusLabel(status)}</span>
        </div>
    `;
}

// ─── Helpers ───
function attStatusLabel(s) {
    return { present: 'Présent', absent: 'Absent', late: 'Retard', justified: 'Justifié', unmarked: 'Non marqué' }[s] || s;
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}
</script>
<!-- ══ FIN JS PRÉSENCES ══ -->

<!-- ======================================================
     INSERTION 4 — JS PROGRESSION ÉTUDIANT
====================================================== -->
<script>
/* --- Variables état --- */
let progStuLoaded = false;
const PROG_STU_COURSE_ID = <?php echo intval($course_id); ?>;

/* --- Ouverture / fermeture --- */
function openProgDrawer() {
    document.getElementById('progStudentDrawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('show');
    if (!progStuLoaded) loadStudentProgress();
}
function closeProgDrawer() {
    document.getElementById('progStudentDrawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('show');
}
function closeAllDrawers() {
    closeProgDrawer();
    // fermer drawer documents si ouvert
    const docsDrawer = document.getElementById('documentsDrawer');
    if (docsDrawer) docsDrawer.classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('show');
}

/* --- Chargement données --- */
function loadStudentProgress() {
    const list = document.getElementById('progStuChaptersList');
    list.innerHTML = '<div class="prog-stu-loading"><div class="prog-stu-spinner"></div><span>Chargement...</span></div>';

    const _progYr  = FIXED_ACADEMIC_YEAR || '';
    const _progYrQ = _progYr ? '&year=' + encodeURIComponent(_progYr) : '';
    fetch('?course_id=' + PROG_STU_COURSE_ID + '&ajax=student_progress' + _progYrQ)
        .then(r => r.json())
        .then(data => {
            progStuLoaded = true;
            renderProgStuKpis(data);
            renderProgStuChapters(data.chapters || []);
        })
        .catch(() => {
            list.innerHTML = '<div class="prog-stu-empty"><i class="fas fa-exclamation-circle"></i>Erreur de chargement.</div>';
        });
}

/* --- KPIs + barre --- */
function renderProgStuKpis(data) {
    document.getElementById('progStuHoursDone').textContent    = parseFloat(data.hours_done    || 0).toFixed(1).replace('.0', '') + 'h';
    document.getElementById('progStuSessions').textContent      = data.sessions_count || 0;
    document.getElementById('progStuHoursPlanned').textContent  = parseFloat(data.hours_planned || 0).toFixed(1).replace('.0', '') + 'h';
    const pct = data.progress_pct || 0;
    document.getElementById('progStuBarPct').textContent  = pct + '%';
    document.getElementById('progStuBarFill').style.width = pct + '%';
}

/* --- Chapitres --- */
function renderProgStuChapters(chapters) {
    const list = document.getElementById('progStuChaptersList');
    if (!chapters.length) {
        list.innerHTML = '<div class="prog-stu-empty"><i class="fas fa-book-open"></i>Aucun contenu disponible pour le moment.</div>';
        return;
    }
    list.innerHTML = chapters.map((ch, idx) => renderProgStuChapter(ch, idx)).join('');
    // Ouvrir le premier chapitre par défaut
    const firstChap = list.querySelector('.prog-stu-chapter');
    if (firstChap) firstChap.classList.add('open');
}

function renderProgStuChapter(ch, idx) {
    const sessions  = (ch.sessions || []);
    const nbSess    = sessions.length;
    const hours     = sessions.reduce((s, x) => s + parseFloat(x.hours || 0), 0);
    const hoursStr  = hours.toFixed(1).replace('.0', '');
    const doneH     = parseFloat(ch.hours_done || 0);
    const doneStr   = doneH.toFixed(1).replace('.0', '');
    const hoursLabel = doneH > 0 ? `${doneStr}h / ${hoursStr}h` : `${hoursStr}h`;
    const sessionsHtml = sessions.map(s => renderProgStuSession(s)).join('');

    return `<div class="prog-stu-chapter" id="progStuChap_${ch.id}">
        <div class="prog-stu-chap-header" onclick="toggleProgStuChapter(${ch.id})">
            <span class="prog-stu-chap-num">${ch.order_num}</span>
            <span class="prog-stu-chap-title">${escProgHtml(ch.title)}</span>
            <span class="prog-stu-chap-badge">${nbSess} s&#233;ance${nbSess > 1 ? 's' : ''} &bull; ${hoursLabel}</span>
            <i class="fas fa-chevron-down prog-stu-chap-arrow"></i>
        </div>
        <div class="prog-stu-sessions">${sessionsHtml || '<div style="padding:10px 14px;color:rgba(255,255,255,.35);font-size:12px">Aucune séance pour ce chapitre.</div>'}</div>
    </div>`;
}

function renderProgStuSession(s) {
    const dateStr = s.session_date ? formatProgDate(s.session_date) : '';
    const hoursStr = parseFloat(s.hours || 0).toFixed(1).replace('.0', '') + 'h';
    const descBtn = s.description
        ? `<button class="prog-stu-sess-desc-toggle" onclick="toggleProgStuDesc(${s.id}, this)">
               <i class="fas fa-info-circle"></i> Détails
           </button>
           <div class="prog-stu-sess-desc" id="progStuDesc_${s.id}">${escProgHtml(s.description)}</div>`
        : '';

    return `<div class="prog-stu-session${s.done ? ' prog-stu-sess-done' : ''}">
        <span class="prog-stu-sess-num">${s.global_num}</span>
        <div class="prog-stu-sess-body">
            <div class="prog-stu-sess-title">${escProgHtml(s.title)}</div>
            <div class="prog-stu-sess-meta">
                ${dateStr ? '<span><i class="fas fa-calendar-alt"></i> ' + dateStr + '</span>' : ''}
                <span><i class="fas fa-clock"></i> ${hoursStr}</span>
                ${s.done ? '<span style="color:#4caf50"><i class="fas fa-check-circle"></i> Effectu&#233;e</span>' : ''}
            </div>
            ${descBtn}
        </div>
    </div>`;
}

/* --- Toggles --- */
function toggleProgStuChapter(id) {
    const el = document.getElementById('progStuChap_' + id);
    if (el) el.classList.toggle('open');
}
function toggleProgStuDesc(id, btn) {
    const el = document.getElementById('progStuDesc_' + id);
    if (!el) return;
    el.classList.toggle('open');
    const icon = btn.querySelector('i');
    if (el.classList.contains('open')) {
        btn.innerHTML = '<i class="fas fa-chevron-up"></i> Masquer';
    } else {
        btn.innerHTML = '<i class="fas fa-info-circle"></i> Détails';
    }
}

/* --- Utilitaires --- */
function formatProgDate(dateStr) {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    } catch(e) { return dateStr; }
}
function escProgHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
}
</script>
<!-- ======================================================
     FIN INSERTION 4 — JS PROGRESSION ÉTUDIANT
====================================================== -->

<!-- ==========================================
     SYSTÈME UNIFIÉ DE POP-UPS (inchangé)
========================================== -->
<script>
function showPopup(popup) {
    let overlay = document.getElementById('popup-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'popup-overlay';
        overlay.style.cssText = `
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        `;
        
        overlay.innerHTML = `
            <div id="popup-content" style="
                background: white;
                padding: 30px;
                border-radius: 15px;
                max-width: 600px;
                width: 90%;
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
                position: relative;
                max-height: 90vh;
                overflow-y: auto;
            ">
                <button id="close-popup" style="
                    position: absolute;
                    top: 15px;
                    right: 15px;
                    background: #ff4757;
                    color: white;
                    border: none;
                    width: 30px;
                    height: 30px;
                    border-radius: 50%;
                    cursor: pointer;
                    font-size: 20px;
                    line-height: 1;
                    transition: all 0.3s;
                    z-index: 10;
                ">×</button>
                
                <div id="popup-body" style="text-align: center;">
                    <img id="popup-image" src="" style="max-width: 100%; max-height: 200px; margin-bottom: 20px; display: none; border-radius: 8px;">
                    <h2 id="popup-title" style="color: var(--text-light); margin-bottom: 15px;"></h2>
                    <p id="popup-message" style="color: #333; line-height: 1.6; white-space: pre-line; margin-bottom: 20px;"></p>
                    <div id="countdown" style="margin-top: 20px; font-size: 12px; color: #666;"></div>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
    }
    
    const title     = document.getElementById('popup-title');
    const message   = document.getElementById('popup-message');
    const image     = document.getElementById('popup-image');
    const closeBtn  = document.getElementById('close-popup');
    const countdown = document.getElementById('countdown');
    
    const oldPreview = document.getElementById('doc-preview');
    if (oldPreview) oldPreview.remove();
    image.style.display = 'none';
    title.textContent   = popup.title;
    
    const isDocument     = popup.image_url && popup.image_url.includes('uploads/documents/');
    const isRegularImage = popup.image_url && !isDocument && 
                           (popup.image_url.includes('uploads/popups/') || 
                            popup.image_url.match(/\.(jpg|jpeg|png|gif|webp)$/i));
    
    if (isDocument) {
        message.textContent = popup.message || 'Un nouveau document est disponible.';
        
        const fileName      = popup.image_url.split('/').pop();
        const fileExtension = fileName.split('.').pop().toLowerCase();
        
        const fileIcons = {
            'pdf': 'fa-file-pdf', 'doc': 'fa-file-word', 'docx': 'fa-file-word',
            'xls': 'fa-file-excel', 'xlsx': 'fa-file-excel',
            'ppt': 'fa-file-powerpoint', 'pptx': 'fa-file-powerpoint',
            'txt': 'fa-file-alt', 'zip': 'fa-file-archive', 'rar': 'fa-file-archive'
        };
        
        const iconClass  = fileIcons[fileExtension] || 'fa-file';
        const docPreview = document.createElement('div');
        docPreview.id    = 'doc-preview';
        docPreview.style.cssText = `
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            border-radius: 12px;
            margin: 20px 0;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        `;
        
        docPreview.innerHTML = `
            <i class="fas ${iconClass}" style="font-size: 64px; color: white; margin-bottom: 15px;"></i>
            <h3 style="color: white; margin: 10px 0; font-size: 18px;">${fileName}</h3>
            <p style="color: rgba(255, 255, 255, 0.9); font-size: 14px; margin: 10px 0;">
                Fichier ${fileExtension.toUpperCase()}
            </p>
            <a href="../${popup.image_url}" 
               download="${fileName}"
               style="display: inline-block; margin-top: 15px; padding: 12px 30px; background: white; color: #667eea; text-decoration: none; border-radius: 25px; font-weight: 600; font-size: 16px; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(255, 255, 255, 0.3);"
               onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(255, 255, 255, 0.4)';"
               onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(255, 255, 255, 0.3)';">
                <i class="fas fa-download" style="margin-right: 8px;"></i>
                Télécharger le document
            </a>
            <a href="../${popup.image_url}" 
               target="_blank"
               style="display: inline-block; margin: 15px 0 0 10px; padding: 12px 30px; background: rgba(255, 255, 255, 0.2); color: white; text-decoration: none; border-radius: 25px; font-weight: 600; font-size: 16px; transition: all 0.3s ease; border: 2px solid white;"
               onmouseover="this.style.background='rgba(255, 255, 255, 0.3)';"
               onmouseout="this.style.background='rgba(255, 255, 255, 0.2)';">
                <i class="fas fa-external-link-alt" style="margin-right: 8px;"></i>
                Ouvrir dans un nouvel onglet
            </a>
        `;
        
        message.parentNode.insertBefore(docPreview, message.nextSibling);
        countdown.innerHTML = '<strong style="color: #667eea;">Téléchargez le document ou fermez cette fenêtre</strong>';
        
    } else if (isRegularImage) {
        image.src           = '../' + popup.image_url;
        image.style.display = 'block';
        message.textContent = popup.message;
        
        if (popup.auto_close_duration > 0) {
            let seconds = popup.auto_close_duration;
            countdown.innerHTML = `Ce message se ferme automatiquement dans <strong>${seconds}</strong> secondes`;
            
            const timer = setInterval(() => {
                seconds--;
                countdown.innerHTML = `Ce message se ferme automatiquement dans <strong>${seconds}</strong> secondes`;
                if (seconds <= 0) { clearInterval(timer); closePopup(); }
            }, 1000);
            
            overlay.addEventListener('mouseenter', () => clearInterval(timer), { once: true });
        } else {
            countdown.innerHTML = 'Cliquez à l\'extérieur ou appuyez sur Échap pour fermer';
        }
        
    } else {
        message.textContent = popup.message;
        countdown.innerHTML = 'Cliquez à l\'extérieur ou appuyez sur Échap pour fermer';
    }
    
    overlay.style.display = 'flex';
    setTimeout(() => { overlay.style.opacity = '1'; }, 10);
    
    function closePopup() {
        overlay.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
            const docPreview = document.getElementById('doc-preview');
            if (docPreview) docPreview.remove();
        }, 300);
    }
    
    closeBtn.onclick = (e) => { e.stopPropagation(); closePopup(); };
    
    overlay.onclick = function(e) {
        if (e.target === overlay) { closePopup(); }
    };
    
    const escapeHandler = function(e) {
        if (e.key === 'Escape') { closePopup(); document.removeEventListener('keydown', escapeHandler); }
    };
    document.addEventListener('keydown', escapeHandler);
}

function checkForPopup() {
    fetch('../includes/check_popup.php')
        .then(response => {
            if (!response.ok) { throw new Error('Network response was not ok'); }
            return response.json();
        })
        .then(data => {
            console.log('Pop-up data received:', data);
            if (data.show && data.popup) { showPopup(data.popup); }
        })
        .catch(error => {
            console.error('Erreur lors de la vérification des pop-ups:', error);
        });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { setTimeout(checkForPopup, 1000); });
} else {
    setTimeout(checkForPopup, 1000);
}
</script>

<style>
#popup-overlay { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
#close-popup:hover { background: #ee5a6f !important; transform: scale(1.1); }
#popup-content { animation: popupSlideIn 0.4s ease-out; }
#popup-content::-webkit-scrollbar       { width: 8px; }
#popup-content::-webkit-scrollbar-track  { background: #f1f1f1; border-radius: 10px; }
#popup-content::-webkit-scrollbar-thumb  { background: #888; border-radius: 10px; }
#popup-content::-webkit-scrollbar-thumb:hover { background: #555; }
@keyframes popupSlideIn {
    from { transform: scale(0.8) translateY(-20px); opacity: 0; }
    to   { transform: scale(1) translateY(0); opacity: 1; }
}
#popup-overlay { z-index: 99999 !important; }

/* ── Bouton flottant devoirs étudiant (gauche) ── */
.assign-btn-student {
    position: fixed; left: 20px; bottom: 140px; z-index: 900;
    width: 50px; height: 50px; border-radius: 50%;
    background: #1a6295; color: #fff; border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; box-shadow: 0 4px 12px rgba(0,0,0,.25);
    transition: background .2s, transform .2s;
}
.assign-btn-student:hover { background: #154d72; transform: scale(1.08); }
.assign-btn-inner {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}
.assign-badge {
    position: absolute;
    top: -10px;
    right: -10px;
    background: #e74c3c;
    color: #fff;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    font-size: 11px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,.2);
    pointer-events: none;
}

/* ── Overlay + modal devoirs étudiant ── */
.assign-stu-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.55); z-index: 1100;
    align-items: center; justify-content: center;
}
.assign-stu-overlay.open { display: flex; }
.assign-stu-modal {
    background: #fff; border-radius: 12px; width: 700px; max-width: 95vw;
    max-height: 90vh; overflow-y: auto; padding: 28px 32px;
    box-shadow: 0 8px 32px rgba(0,0,0,.3); position: relative;
}
.assign-stu-modal h2 { margin: 0 0 18px; color: #1a6295; font-size: 1.3rem; }
.assign-stu-modal .close-btn {
    position: absolute; top: 14px; right: 18px;
    background: none; border: none; font-size: 22px; cursor: pointer; color: #888;
}
.stu-assign-card {
    border: 1px solid #cde0f0; border-radius: 8px; padding: 14px 16px;
    margin-bottom: 14px; background: #f0f8ff;
}
.stu-assign-title { font-weight: 600; color: #1a6295; margin-bottom: 4px; }
.stu-assign-meta { font-size: .83rem; color: #777; margin-bottom: 8px; }
.stu-assign-desc { font-size: .87rem; color: #555; margin-bottom: 10px; }
.stu-submit-form { border-top: 1px dashed #b0cfe0; padding-top: 10px; }
.stu-submit-form input[type=file] { display: block; margin-bottom: 8px; }
.stu-submit-form textarea {
    width: 100%; min-height: 55px; resize: vertical; padding: 7px 9px;
    border: 1px solid #c0d8e8; border-radius: 6px; font-size: .88rem;
    margin-bottom: 8px; box-sizing: border-box;
}
.stu-submit-btn {
    background: #1a6295; color: #fff; border: none; padding: 8px 20px;
    border-radius: 6px; cursor: pointer; font-size: .92rem; transition: background .2s;
}
.stu-submit-btn:hover { background: #154d72; }
.stu-submitted-badge {
    display: inline-block; background: #d4edda; color: #155724;
    border-radius: 20px; padding: 3px 12px; font-size: .8rem; margin-bottom: 8px;
}
.assign-stu-empty { color: #aaa; font-style: italic; text-align: center; padding: 24px 0; }
.badge-deadline-passee {
    display: inline-block; background: #FFF3E0; color: #f39c12;
    padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600;
    margin-top: 6px;
}
</style>

<!-- ═══════════ JS DEVOIRS ÉTUDIANT ═══════════ -->
<script>
(function() {
    const STU_COURSE_ID = <?php echo intval($course_id ?? 0); ?>;

    window.ouvrirDevoirsEtudiant = function() {
        document.getElementById('mDevoirsEtudiant').classList.add('open');
        chargerDevoirsEtudiant();
    };
    window.fermerDevoirsEtudiant = function() {
        document.getElementById('mDevoirsEtudiant').classList.remove('open');
    };
    document.getElementById('mDevoirsEtudiant').addEventListener('click', function(e) {
        if (e.target === this) fermerDevoirsEtudiant();
    });

    function _assignYrQ() {
        const yr = FIXED_ACADEMIC_YEAR || '';
        return yr ? '&year=' + encodeURIComponent(yr) : '';
    }

    function chargerDevoirsEtudiant() {
        const list = document.getElementById('stuAssignList');
        list.innerHTML = '<div class="assign-stu-empty"><i class="fas fa-circle-notch fa-spin"></i> Chargement…</div>';
        fetch(`?course_id=${STU_COURSE_ID}&action=get_student_assignments${_assignYrQ()}`)
            .then(r => r.json())
            .then(data => renderDevoirsEtudiant(data))
            .catch(() => { list.innerHTML = '<div class="assign-stu-empty">Erreur de chargement.</div>'; });
    }

    function renderDevoirsEtudiant(devoirs) {
        const list = document.getElementById('stuAssignList');
        if (!Array.isArray(devoirs) || devoirs.length === 0) {
            list.innerHTML = '<div class="assign-stu-empty">Aucun devoir pour ce cours.</div>';
            return;
        }
        list.innerHTML = devoirs.map(d => {
            const due = new Date(d.due_date);
            const dueStr = due.toLocaleDateString('fr-FR', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'});
            const now = new Date();
            const estExpire = now > due;
            const submitted = !!d.sub_id;
            const submittedAt = submitted ? new Date(d.sub_submitted_at).toLocaleDateString('fr-FR', {day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit'}) : null;

            let actionHtml;
            if (!estExpire) {
                // Délai en cours : formulaire d'envoi ou de remplacement
                actionHtml = `
                <div class="stu-submit-form">
                    <label style="font-size:.85rem;color:#555;display:block;margin-bottom:6px">${submitted ? 'Remplacer mon rendu' : 'Soumettre mon travail'}</label>
                    <input type="file" id="file-${d.id}" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.rar,.txt,.odt">
                    <textarea id="comment-${d.id}" placeholder="Commentaire (optionnel)"></textarea>
                    <button class="stu-submit-btn" onclick="soumettreDevoir(${d.id})"><i class="fas fa-upload"></i> ${submitted ? 'Remplacer' : 'Soumettre'}</button>
                    <div id="msg-${d.id}" style="margin-top:6px;font-size:.85rem"></div>
                </div>`;
            } else if (submitted) {
                // Délai dépassé + rendu existant : clôturé, téléchargement uniquement
                actionHtml = `<span class="badge-deadline-passee">🔒 Rendu clôturé</span>`;
            } else {
                // Délai dépassé + aucun rendu : impossible
                actionHtml = `<span class="badge-deadline-passee">❌ Délai dépassé — rendu impossible</span>`;
            }

            return `<div class="stu-assign-card" id="stua-${d.id}">
                <div class="stu-assign-title">${escHtmlStu(d.title)}</div>
                <div class="stu-assign-meta">
                    Date limite : <strong style="color:${estExpire?'#e74c3c':'#27ae60'}">${dueStr}</strong>
                    ${d.original_name ? `&nbsp;|&nbsp; <i class="fas fa-paperclip"></i> <a href="../uploads/assignments/${encodeURIComponent(d.file_path)}" target="_blank" style="color:#1a6295">${escHtmlStu(d.original_name)}</a>` : ''}
                </div>
                ${d.description ? `<div class="stu-assign-desc">${escHtmlStu(d.description)}</div>` : ''}
                ${submitted ? `<div class="stu-submitted-badge"><i class="fas fa-check-circle"></i> Rendu le ${submittedAt} — <a href="?course_id=${STU_COURSE_ID}&action=download_my_submission&assignment_id=${d.id}" style="color:#155724">Télécharger mon rendu</a></div>` : ''}
                ${actionHtml}
            </div>`;
        }).join('');
    }

    window.soumettreDevoir = function(assignId) {
        const fileEl    = document.getElementById('file-' + assignId);
        const commentEl = document.getElementById('comment-' + assignId);
        const msgEl     = document.getElementById('msg-' + assignId);
        if (!fileEl.files[0]) { msgEl.style.color='#e74c3c'; msgEl.textContent='Sélectionnez un fichier.'; return; }
        const fd = new FormData();
        fd.append('assignment_id', assignId);
        fd.append('file', fileEl.files[0]);
        fd.append('comment', commentEl ? commentEl.value : '');
        msgEl.style.color = '#888'; msgEl.textContent = 'Envoi en cours…';
        fetch(`?course_id=${STU_COURSE_ID}&action=submit_assignment`, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    msgEl.style.color = '#27ae60'; msgEl.textContent = 'Devoir soumis avec succès !';
                    updateAssignBadge();
                    setTimeout(chargerDevoirsEtudiant, 1200);
                } else {
                    msgEl.style.color = '#e74c3c'; msgEl.textContent = res.error || 'Erreur inconnue.';
                }
            })
            .catch(() => { msgEl.style.color='#e74c3c'; msgEl.textContent='Erreur réseau.'; });
    };

    function updateAssignBadge() {
        fetch(`?course_id=${STU_COURSE_ID}&action=get_pending_count${_assignYrQ()}`)
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('assignBadge');
                if (!badge) return;
                if (data.count > 0) {
                    badge.textContent = data.count;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            })
            .catch(() => {});
    }

    function escHtmlStu(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>
</body>
</html>