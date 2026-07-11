<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/semester_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}

$user_id   = $_SESSION['user_id'];
$sql_user  = "SELECT name FROM users WHERE id = '" . $conn->real_escape_string($user_id) . "'";
$admin_name = $conn->query($sql_user)->fetch_assoc()['name'] ?? 'Admin';

// ============================================================
// HANDLERS AJAX
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];

    // ── KPIs globaux ─────────────────────────────────────────
    if ($action === 'get_kpis') {
        $kpis     = [];
        $ay       = isset($_GET['academic_year']) ? $conn->real_escape_string($_GET['academic_year']) : '';
        $ay_where = $ay ? "WHERE academic_year = '$ay'" : '';
        $ay_cs    = $ay ? "AND cs.academic_year = '$ay'" : '';
        $ay_cs_where = $ay ? "WHERE cs.academic_year = '$ay'" : '';

        $r = $conn->query("SELECT COUNT(DISTINCT course_id) AS n FROM course_chapters $ay_where");
        $kpis['courses_with_content'] = (int)($r->fetch_assoc()['n'] ?? 0);

        $r = $conn->query("SELECT COUNT(*) AS n FROM course_chapters $ay_where");
        $kpis['total_chapters'] = (int)($r->fetch_assoc()['n'] ?? 0);

        $r = $conn->query("SELECT COUNT(*) AS n FROM course_sessions $ay_where");
        $kpis['total_sessions'] = (int)($r->fetch_assoc()['n'] ?? 0);

        // Heures : attendance_sessions.duration si appel fait, sinon course_sessions.hours
        $r = $conn->query("
            SELECT COALESCE(SUM(COALESCE(att_s.duration, cs.hours)), 0) AS h
            FROM course_sessions cs
            LEFT JOIN attendance_sessions att_s ON att_s.id = cs.attendance_session_id
            $ay_cs_where
        ");
        $kpis['total_hours'] = round((float)($r->fetch_assoc()['h'] ?? 0), 1);

        $sql_avg = "
            SELECT c.id,
                   COALESCE(c.total_hours, 0) AS planned,
                   COALESCE(SUM(COALESCE(att_s.duration, cs.hours)), 0) AS done
            FROM courses c
            LEFT JOIN course_sessions cs ON cs.course_id = c.id $ay_cs
            LEFT JOIN attendance_sessions att_s ON att_s.id = cs.attendance_session_id
            GROUP BY c.id
            HAVING planned > 0
        ";
        $res_avg = $conn->query($sql_avg);
        $pcts = [];
        while ($row = $res_avg->fetch_assoc()) {
            $pcts[] = min(100, round(($row['done'] / $row['planned']) * 100));
        }
        $kpis['avg_progress'] = count($pcts) > 0 ? round(array_sum($pcts) / count($pcts)) : 0;

        // Séances passées sans appel fait (pour badge alerte)
        $r2 = $conn->query("
            SELECT COUNT(*) AS n
            FROM course_sessions cs
            JOIN course_chapters cc ON cc.id = cs.chapter_id
            JOIN courses c ON c.id = cs.course_id
            WHERE cs.session_date < CURDATE()
              AND cs.session_date IS NOT NULL
              AND cs.attendance_session_id IS NULL
              " . ($ay ? "AND cs.academic_year = '$ay'" : '') . "
        ");
        $kpis['missing_calls'] = ($r2 ? (int)$r2->fetch_assoc()['n'] : 0);

        echo json_encode($kpis);
        exit();
    }

    // ── Liste des classes ─────────────────────────────────────
    if ($action === 'get_classes') {
        $res = $conn->query("SELECT id, name FROM classes ORDER BY name ASC");
        $classes = [];
        while ($row = $res->fetch_assoc()) $classes[] = $row;
        echo json_encode($classes);
        exit();
    }

    // ── Tableau de progression par cours ─────────────────────
    if ($action === 'get_progress_table') {
        $class_id   = isset($_GET['class_id'])      ? intval($_GET['class_id'])                         : 0;
        $teacher_id = isset($_GET['teacher_id'])    ? $conn->real_escape_string($_GET['teacher_id'])    : '';
        $search     = isset($_GET['search'])        ? $conn->real_escape_string(trim($_GET['search']))  : '';
        $ay          = isset($_GET['academic_year']) ? $conn->real_escape_string($_GET['academic_year']) : '';
        $ay_where    = $ay ? "WHERE academic_year = '$ay'" : '';
        $cs_ay_where = $ay ? "WHERE cs.academic_year = '$ay'" : '';

        $where_parts = [];
        if ($search !== '') {
            $where_parts[] = "(c.name LIKE '%$search%' OR u.name LIKE '%$search%')";
        }
        if ($teacher_id !== '') {
            $where_parts[] = "c.teacher_id = '$teacher_id'";
        }
        $where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

        $sql = "
            SELECT
                c.id                              AS course_id,
                c.name                            AS course_name,
                c.total_hours                     AS planned_hours,
                c.class_id                        AS class_ids_json,
                COALESCE(u.name, '[Enseignant supprimé]') AS teacher_name,
                COALESCE(ch_agg.chapters_count, 0) AS chapters_count,
                COALESCE(cs_agg.sessions_count, 0) AS sessions_count,
                COALESCE(cs_agg.done_hours,     0) AS done_hours,
                cs_agg.last_session_date           AS last_session_date
            FROM courses c
            LEFT JOIN users u ON u.id = c.teacher_id
            LEFT JOIN (
                SELECT course_id, COUNT(*) AS chapters_count
                FROM course_chapters
                $ay_where
                GROUP BY course_id
            ) ch_agg ON ch_agg.course_id = c.id
            LEFT JOIN (
                SELECT cs.course_id,
                       COUNT(*)                                   AS sessions_count,
                       SUM(COALESCE(att_s.duration, cs.hours))   AS done_hours,
                       MAX(cs.session_date)                       AS last_session_date
                FROM course_sessions cs
                LEFT JOIN attendance_sessions att_s ON att_s.id = cs.attendance_session_id
                $cs_ay_where
                GROUP BY cs.course_id
            ) cs_agg ON cs_agg.course_id = c.id
            $where_sql
            GROUP BY c.id, c.name, c.total_hours, c.class_id, u.name,
                     ch_agg.chapters_count, cs_agg.sessions_count,
                     cs_agg.done_hours, cs_agg.last_session_date
            ORDER BY c.name ASC
        ";

        $res      = $conn->query($sql);
        $raw_rows = [];
        while ($row = $res->fetch_assoc()) {
            // Filtrer par classe si demandé
            if ($class_id > 0) {
                $ids = json_decode($row['class_ids_json'], true) ?? [];
                if (!in_array((string)$class_id, array_map('strval', $ids))) continue;
            }
            $raw_rows[] = $row;
        }

        // Collecter tous les class_ids uniques, charger tous les noms en une seule requête
        $all_class_ids = [];
        foreach ($raw_rows as $row) {
            foreach (json_decode($row['class_ids_json'], true) ?? [] as $id) {
                $cid_int = intval($id);
                if ($cid_int > 0) $all_class_ids[$cid_int] = true;
            }
        }
        $class_name_map = [];
        if (!empty($all_class_ids)) {
            $ids_str = implode(',', array_keys($all_class_ids));
            $res_cl  = $conn->query("SELECT id, name FROM classes WHERE id IN ($ids_str)");
            while ($cl = $res_cl->fetch_assoc()) {
                $class_name_map[(int)$cl['id']] = $cl['name'];
            }
        }

        $rows = [];
        foreach ($raw_rows as $row) {
            $ids_arr     = json_decode($row['class_ids_json'], true) ?? [];
            $class_names = [];
            foreach ($ids_arr as $id) {
                $name = $class_name_map[intval($id)] ?? null;
                if ($name !== null) $class_names[] = $name;
            }
            $planned = (float)($row['planned_hours'] ?? 0);
            $done    = (float)($row['done_hours']    ?? 0);
            $pct     = $planned > 0 ? min(100, round(($done / $planned) * 100)) : 0;

            $rows[] = [
                'course_id'         => $row['course_id'],
                'course_name'       => $row['course_name'],
                'teacher_name'      => $row['teacher_name'] ?? '—',
                'classes'           => implode(', ', $class_names) ?: (!empty($ids_arr) ? '[Classe supprimée]' : '—'),
                'planned_hours'     => $planned,
                'done_hours'        => round($done, 1),
                'chapters_count'    => (int)$row['chapters_count'],
                'sessions_count'    => (int)$row['sessions_count'],
                'progress_pct'      => $pct,
                'last_session_date' => $row['last_session_date'] ?? null,
            ];
        }
        echo json_encode($rows);
        exit();
    }

    // ── Détail d'un cours (chapitres + séances) ───────────────
    if ($action === 'get_course_detail') {
        $cid   = intval($_GET['course_id'] ?? 0);
        $ay    = isset($_GET['academic_year']) ? $conn->real_escape_string($_GET['academic_year']) : '';
        $ay_ch = $ay ? "AND academic_year = '$ay'" : '';
        $ay_cs = $ay ? "AND cs.academic_year = '$ay'" : '';

        // Infos cours
        $sql_c = "SELECT c.name, c.total_hours, u.name AS teacher FROM courses c
                  LEFT JOIN users u ON u.id = c.teacher_id WHERE c.id = $cid";
        $res_c = $conn->query($sql_c);
        $course = $res_c->fetch_assoc();
        if (!$course) { echo json_encode(['error' => 'Cours introuvable']); exit(); }

        // Précharger toutes les séances en une requête
        $sql_all_sess = "
            SELECT cs.id, cs.chapter_id, cs.session_number, cs.title, cs.description,
                   cs.hours, cs.session_date, cs.start_time, cs.end_time,
                   cs.attendance_session_id,
                   att_s.duration AS att_duration
            FROM course_sessions cs
            LEFT JOIN attendance_sessions att_s ON att_s.id = cs.attendance_session_id
            WHERE cs.course_id = $cid $ay_cs
            ORDER BY cs.chapter_id ASC, cs.session_number ASC
        ";
        $res_all_sess = $conn->query($sql_all_sess);
        $sessions_by_chapter = [];
        while ($sess = $res_all_sess->fetch_assoc()) {
            $sessions_by_chapter[$sess['chapter_id']][] = $sess;
        }

        // Chapitres + séances
        $sql_ch = "SELECT id, title, order_num FROM course_chapters WHERE course_id = $cid $ay_ch ORDER BY order_num ASC";
        $res_ch = $conn->query($sql_ch);
        $chapters   = [];
        $total_done = 0;
        $total_sess = 0;
        $global_n   = 0;

        while ($ch = $res_ch->fetch_assoc()) {
            $sessions = [];
            foreach ($sessions_by_chapter[$ch['id']] ?? [] as $sess) {
                $global_n++;
                $sess['global_num'] = $global_n;
                $effective = ($sess['att_duration'] !== null)
                    ? (float)$sess['att_duration']
                    : (float)$sess['hours'];
                $sess['effective_hours'] = round($effective, 1);
                $sess['call_done']       = ($sess['attendance_session_id'] !== null);
                $sessions[]  = $sess;
                $total_done += $effective;
                $total_sess++;
            }
            $ch['sessions'] = $sessions;
            $chapters[]     = $ch;
        }

        $planned = (float)($course['total_hours'] ?? 0);
        $pct     = $planned > 0 ? min(100, round(($total_done / $planned) * 100)) : 0;

        echo json_encode([
            'course_name'    => $course['name'],
            'teacher'        => $course['teacher'] ?? '—',
            'planned_hours'  => $planned,
            'done_hours'     => round($total_done, 1),
            'sessions_count' => $total_sess,
            'progress_pct'   => $pct,
            'chapters'       => $chapters,
        ]);
        exit();
    }

    // ── Liste enseignants (pour filtre) ───────────────────────
    if ($action === 'get_teachers') {
        $res = $conn->query("SELECT DISTINCT u.id, u.name FROM users u
                             INNER JOIN courses c ON c.teacher_id = u.id
                             WHERE u.role = 'teacher' ORDER BY u.name ASC");
        $teachers = [];
        while ($row = $res->fetch_assoc()) $teachers[] = $row;
        echo json_encode($teachers);
        exit();
    }

    // ── Années académiques disponibles ────────────────────────
    if ($action === 'get_academic_years') {
        $r = $conn->query("
            SELECT DISTINCT academic_year FROM course_sessions
            WHERE academic_year IS NOT NULL AND academic_year != ''
            UNION
            SELECT DISTINCT academic_year FROM course_chapters
            WHERE academic_year IS NOT NULL AND academic_year != ''
            ORDER BY academic_year DESC
        ");
        $years = [];
        while ($row = $r->fetch_assoc()) $years[] = $row['academic_year'];
        echo json_encode($years);
        exit();
    }

    // ── Séances sans appel ─────────────────────────────────────
    if ($action === 'get_missing_calls') {
        $mc_ay       = isset($_GET['academic_year']) ? $conn->real_escape_string($_GET['academic_year']) : '';
        $mc_class_id = isset($_GET['class_id'])     ? intval($_GET['class_id'])                         : 0;
        $mc_teach_id = isset($_GET['teacher_id'])   ? $conn->real_escape_string($_GET['teacher_id'])    : '';

        $where_mc = ["cs.session_date < CURDATE()", "cs.session_date IS NOT NULL", "cs.attendance_session_id IS NULL"];
        if ($mc_ay)       $where_mc[] = "cs.academic_year = '$mc_ay'";
        if ($mc_class_id) $where_mc[] = "JSON_CONTAINS(c.class_id, CONCAT('\"', $mc_class_id, '\"'))";
        if ($mc_teach_id) $where_mc[] = "c.teacher_id = '$mc_teach_id'";
        $where_sql_mc = 'WHERE ' . implode(' AND ', $where_mc);

        $r = $conn->query("
            SELECT cs.id, cs.session_date, cs.start_time, cs.end_time, cs.hours,
                   cc.title AS chapter, c.name AS course_name, c.id AS course_id,
                   COALESCE(GROUP_CONCAT(DISTINCT cl.name ORDER BY cl.name SEPARATOR ', '), '[Classe supprimée]') AS class_name,
                   COALESCE(u.name, '[Enseignant supprimé]') AS teacher_name
            FROM course_sessions cs
            JOIN course_chapters cc ON cc.id = cs.chapter_id
            JOIN courses c ON c.id = cs.course_id
            LEFT JOIN classes cl ON JSON_CONTAINS(c.class_id, CONCAT('\"', cl.id, '\"'))
            LEFT JOIN users u ON u.id = c.teacher_id
            $where_sql_mc
            GROUP BY cs.id, cs.session_date, cs.start_time, cs.end_time,
                     cs.hours, cc.title, c.name, c.id, u.name
            ORDER BY cs.session_date DESC
            LIMIT 50
        ");
        $rows = [];
        if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
        echo json_encode($rows);
        exit();
    }

    // ── Modifier une séance ───────────────────────────────────
    if ($action === 'edit_course_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $session_id  = intval($_POST['session_id'] ?? 0);
        $title       = $conn->real_escape_string(trim($_POST['title']        ?? ''));
        $description = $conn->real_escape_string(trim($_POST['description']  ?? ''));
        $date        = $conn->real_escape_string($_POST['session_date']       ?? '');
        $start       = $conn->real_escape_string($_POST['start_time']         ?? '');
        $end         = $conn->real_escape_string($_POST['end_time']           ?? '');
        // Normaliser HH:MM → HH:MM:SS (input type="time" envoie HH:MM)
        if (preg_match('/^\d{2}:\d{2}$/', $start)) $start .= ':00';
        if (preg_match('/^\d{2}:\d{2}$/', $end))   $end   .= ':00';

        if (!$session_id || !$date || !$start || !$end) {
            echo json_encode(['error' => 'Champs requis manquants']); exit();
        }
        if ($end <= $start) {
            echo json_encode(['error' => "L'heure de fin doit être après l'heure de début."]); exit();
        }
        // Calculer la durée en PHP pour valider
        list($sh, $sm) = array_map('intval', explode(':', $start));
        list($eh, $em) = array_map('intval', explode(':', $end));
        $hours_calc = ((($eh * 60 + $em) - ($sh * 60 + $sm)) / 60);
        if ($hours_calc < 0.5 || $hours_calc > 8) {
            echo json_encode(['error' => 'La durée doit être comprise entre 0h30 et 8h.']); exit();
        }

        $hours = round($hours_calc, 2);
        $conn->query("
            UPDATE course_sessions SET
                title        = '$title',
                description  = '$description',
                session_date = '$date',
                start_time   = '$start',
                end_time     = '$end',
                hours        = $hours,
                updated_at   = NOW()
            WHERE id = $session_id
        ");
        if ($conn->error) {
            echo json_encode(['error' => 'Erreur base de données : ' . $conn->error]); exit();
        }
        $uid_esc = $conn->real_escape_string($user_id);
        @$conn->query("INSERT INTO audit_log (action_type, entity_type, entity_id, description, performed_by, performed_at)
            VALUES ('UPDATE', 'course_sessions', '$session_id',
            'Modification séance : date=$date start=$start end=$end', '$uid_esc', NOW())");
        echo json_encode(['success' => true]); exit();
    }

    // ── Supprimer une séance ───────────────────────────────────
    if ($action === 'delete_course_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $session_id = intval($_POST['session_id'] ?? 0);
        if (!$session_id) {
            echo json_encode(['error' => 'ID séance manquant']); exit();
        }
        // 1. Délier les attendance_sessions
        $conn->query("UPDATE attendance_sessions SET course_session_id = NULL WHERE course_session_id = $session_id");
        // 2. Supprimer le lien dans course_sessions
        $conn->query("UPDATE course_sessions SET attendance_session_id = NULL WHERE id = $session_id");
        // 3. Supprimer la séance
        $conn->query("DELETE FROM course_sessions WHERE id = $session_id");
        if ($conn->error) {
            echo json_encode(['error' => 'Erreur base de données : ' . $conn->error]); exit();
        }
        $uid_esc = $conn->real_escape_string($user_id);
        @$conn->query("INSERT INTO audit_log (action_type, entity_type, entity_id, description, performed_by, performed_at)
            VALUES ('DELETE', 'course_sessions', '$session_id', 'Suppression séance', '$uid_esc', NOW())");
        echo json_encode(['success' => true]); exit();
    }

    // ── Modifier un chapitre ──────────────────────────────────
    if ($action === 'edit_chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chapter_id = intval($_POST['chapter_id'] ?? 0);
        $title      = $conn->real_escape_string(trim($_POST['title'] ?? ''));
        if (!$chapter_id || $title === '') {
            echo json_encode(['error' => 'Titre requis']); exit();
        }
        if (mb_strlen($title) > 255) {
            echo json_encode(['error' => 'Titre trop long (255 caractères max)']); exit();
        }
        $chk = $conn->query("SELECT id FROM course_chapters WHERE id = $chapter_id");
        if (!$chk || !$chk->fetch_assoc()) {
            echo json_encode(['error' => 'Chapitre introuvable']); exit();
        }
        $conn->query("UPDATE course_chapters SET title = '$title' WHERE id = $chapter_id");
        if ($conn->error) {
            echo json_encode(['error' => 'Erreur base de données : ' . $conn->error]); exit();
        }
        $uid_esc = $conn->real_escape_string($user_id);
        @$conn->query("INSERT INTO audit_log (action_type, entity_type, entity_id, description, performed_by, performed_at)
            VALUES ('UPDATE', 'course_chapters', '$chapter_id', 'Modification chapitre', '$uid_esc', NOW())");
        echo json_encode(['success' => true]); exit();
    }

    // ── Supprimer un chapitre ─────────────────────────────────
    if ($action === 'delete_chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $chapter_id = intval($_POST['chapter_id'] ?? 0);
        $confirmed  = !empty($_POST['confirmed']);
        if (!$chapter_id) {
            echo json_encode(['error' => 'ID chapitre manquant']); exit();
        }
        $chk = $conn->query("SELECT id FROM course_chapters WHERE id = $chapter_id");
        if (!$chk || !$chk->fetch_assoc()) {
            echo json_encode(['error' => 'Chapitre introuvable']); exit();
        }
        // Compter séances et celles avec appel
        $r_total  = $conn->query("SELECT COUNT(*) AS n FROM course_sessions WHERE chapter_id = $chapter_id");
        $total    = (int)($r_total->fetch_assoc()['n'] ?? 0);
        $r_att    = $conn->query("SELECT COUNT(*) AS n FROM course_sessions WHERE chapter_id = $chapter_id AND attendance_session_id IS NOT NULL");
        $with_att = (int)($r_att->fetch_assoc()['n'] ?? 0);

        if (!$confirmed) {
            // Toujours renvoyer les stats — la confirmation est gérée côté JS
            echo json_encode([
                'needs_confirm'      => ($total > 0),
                'total_sessions'     => $total,
                'sessions_with_call' => $with_att,
            ]); exit();
        }

        // Suppression confirmée
        $conn->query("UPDATE attendance_sessions SET course_session_id = NULL
            WHERE course_session_id IN (SELECT id FROM course_sessions WHERE chapter_id = $chapter_id)");
        $conn->query("UPDATE course_sessions SET attendance_session_id = NULL WHERE chapter_id = $chapter_id");
        $conn->query("DELETE FROM course_sessions WHERE chapter_id = $chapter_id");
        $conn->query("DELETE FROM course_chapters WHERE id = $chapter_id");
        if ($conn->error) {
            echo json_encode(['error' => 'Erreur base de données : ' . $conn->error]); exit();
        }
        $uid_esc = $conn->real_escape_string($user_id);
        @$conn->query("INSERT INTO audit_log (action_type, entity_type, entity_id, description, performed_by, performed_at)
            VALUES ('DELETE', 'course_chapters', '$chapter_id', 'Suppression chapitre et séances', '$uid_esc', NOW())");
        echo json_encode(['success' => true]); exit();
    }

    // ── Ajouter un chapitre ───────────────────────────────────
    if ($action === 'add_chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $course_id = intval($_POST['course_id'] ?? 0);
        $title     = $conn->real_escape_string(trim($_POST['title'] ?? ''));
        $ay        = $conn->real_escape_string($_POST['academic_year'] ?? '');
        if (!$course_id || $title === '') {
            echo json_encode(['error' => 'Cours et titre requis']); exit();
        }
        if (mb_strlen($title) > 255) {
            echo json_encode(['error' => 'Titre trop long (255 caractères max)']); exit();
        }
        $ay_filter = $ay ? "AND academic_year = '$ay'" : '';
        $r = $conn->query("SELECT COALESCE(MAX(order_num), 0) AS mx FROM course_chapters
            WHERE course_id = $course_id $ay_filter");
        $order_num = (int)($r->fetch_assoc()['mx'] ?? 0) + 1;
        $uid_esc = $conn->real_escape_string($user_id);
        $conn->query("INSERT INTO course_chapters (course_id, title, academic_year, order_num, created_by)
            VALUES ($course_id, '$title', '$ay', $order_num, '$uid_esc')");
        if ($conn->error) {
            echo json_encode(['error' => 'Erreur base de données : ' . $conn->error]); exit();
        }
        $new_id = $conn->insert_id;
        @$conn->query("INSERT INTO audit_log (action_type, entity_type, entity_id, description, performed_by, performed_at)
            VALUES ('CREATE', 'course_chapters', '$new_id', 'Ajout chapitre : $title', '$uid_esc', NOW())");
        echo json_encode(['success' => true, 'chapter_id' => $new_id]); exit();
    }

    // ── Ajouter une séance ────────────────────────────────────
    if ($action === 'add_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $course_id   = intval($_POST['course_id']  ?? 0);
        $chapter_id  = intval($_POST['chapter_id'] ?? 0);
        $title       = $conn->real_escape_string(trim($_POST['title']        ?? ''));
        $description = $conn->real_escape_string(trim($_POST['description']  ?? ''));
        $date        = $conn->real_escape_string($_POST['session_date']       ?? '');
        $start       = $conn->real_escape_string($_POST['start_time']         ?? '');
        $end         = $conn->real_escape_string($_POST['end_time']           ?? '');
        $ay          = $conn->real_escape_string($_POST['academic_year']      ?? '');
        // Normaliser HH:MM → HH:MM:SS
        if (preg_match('/^\d{2}:\d{2}$/', $start)) $start .= ':00';
        if (preg_match('/^\d{2}:\d{2}$/', $end))   $end   .= ':00';
        if (!$course_id || !$chapter_id || !$date || !$start || !$end) {
            echo json_encode(['error' => 'Champs requis manquants']); exit();
        }
        if ($end <= $start) {
            echo json_encode(['error' => "L'heure de fin doit être après l'heure de début."]); exit();
        }
        list($sh, $sm) = array_map('intval', explode(':', $start));
        list($eh, $em) = array_map('intval', explode(':', $end));
        $hours_calc = ((($eh * 60 + $em) - ($sh * 60 + $sm)) / 60);
        if ($hours_calc < 0.5 || $hours_calc > 8) {
            echo json_encode(['error' => 'La durée doit être entre 0h30 et 8h.']); exit();
        }
        $hours = round($hours_calc, 2);
        $r = $conn->query("SELECT COALESCE(MAX(session_number), 0) AS mx FROM course_sessions
            WHERE chapter_id = $chapter_id");
        $sess_num = (int)($r->fetch_assoc()['mx'] ?? 0) + 1;
        $uid_esc  = $conn->real_escape_string($user_id);
        $ay_val   = $ay ? "'$ay'" : 'NULL';
        $conn->query("INSERT INTO course_sessions
            (course_id, chapter_id, title, description, session_date, start_time, end_time,
             hours, academic_year, created_by, session_number)
            VALUES ($course_id, $chapter_id, '$title', '$description', '$date', '$start', '$end',
                    $hours, $ay_val, '$uid_esc', $sess_num)");
        if ($conn->error) {
            echo json_encode(['error' => 'Erreur base de données : ' . $conn->error]); exit();
        }
        $new_id = $conn->insert_id;
        @$conn->query("INSERT INTO audit_log (action_type, entity_type, entity_id, description, performed_by, performed_at)
            VALUES ('CREATE', 'course_sessions', '$new_id',
            'Ajout séance : date=$date start=$start end=$end', '$uid_esc', NOW())");
        echo json_encode(['success' => true, 'session_id' => $new_id]); exit();
    }

    echo json_encode(['error' => 'Action inconnue']);
    exit();
}
// ============================================================
// FIN HANDLERS AJAX
// ============================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Progression des Cours — UV Platform</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg:   #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light:   #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --prog-color:   #7b2fa0;
            --prog-light:   #c084fc;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background-color: var(--primary-bg);
            color: var(--text-light);
            font-family: 'Google Sans', Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-bottom: 1px solid var(--border-color);
        }

        /* ── Layout principal ── */
        .dashboard-container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
            flex: 1;
        }
        .page-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            padding: 20px 25px;
            background: var(--secondary-bg);
            border-radius: 12px;
            border-bottom: 3px solid var(--prog-color);
            box-shadow: 0 4px 12px rgba(0,0,0,.2);
        }
        .page-header i { font-size: 28px; color: var(--prog-light); }
        .page-header h1 { font-size: 1.6rem; font-weight: 700; color: #fff; }
        .page-header .page-header-sub { font-size: 13px; color: rgba(255,255,255,.5); margin-top: 3px; }

        /* ── KPI Cards ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: var(--secondary-bg);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid var(--border-color);
            transition: transform .3s, box-shadow .3s;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--prog-color), var(--prog-light));
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,.3); }
        .stat-card i { font-size: 28px; color: var(--prog-light); margin-bottom: 10px; }
        .stat-card h3 { font-size: 13px; color: rgba(255,255,255,.6); margin-bottom: 8px; font-weight: 400; }
        .stat-card p { font-size: 2rem; font-weight: 700; color: var(--prog-light); }
        .stat-card.accent p { color: #4CAF50; }

        /* ── Filtres ── */
        .filters-card {
            background: var(--secondary-bg);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
        }
        .filters-card h3 {
            font-size: 15px;
            font-weight: 600;
            color: var(--prog-light);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filters-row {
            display: grid;
            grid-template-columns: 1fr 2fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
        }
        .filter-group label {
            display: block;
            font-size: 12px;
            color: rgba(255,255,255,.5);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px 14px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            outline: none;
            transition: border-color .2s;
        }
        .filter-group input:focus,
        .filter-group select option { background: var(--secondary-bg); }
        .filter-group input:focus,
        .filter-group select:focus { border-color: var(--prog-color); }
        .btn-filter {
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--prog-color), var(--prog-light));
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all .3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-filter:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(123,47,160,.4); }
        .btn-reset {
            padding: 10px 16px;
            background: rgba(255,255,255,.08);
            color: rgba(255,255,255,.7);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: all .2s;
        }
        .btn-reset:hover { background: rgba(255,255,255,.14); color: #fff; }

        /* ── Tableau principal ── */
        .table-card {
            background: var(--secondary-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
            margin-bottom: 30px;
        }
        .table-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            background: rgba(123,47,160,.08);
        }
        .table-card-header h3 {
            font-size: 15px;
            font-weight: 600;
            color: var(--prog-light);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .table-count {
            font-size: 12px;
            color: rgba(255,255,255,.45);
            background: rgba(255,255,255,.06);
            padding: 3px 10px;
            border-radius: 99px;
        }
        .table-wrapper { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        thead th {
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: rgba(255,255,255,.5);
            text-transform: uppercase;
            letter-spacing: .5px;
            border-bottom: 1px solid var(--border-color);
            background: rgba(0,0,0,.15);
            cursor: pointer;
            white-space: nowrap;
            user-select: none;
        }
        thead th:hover { color: var(--prog-light); }
        thead th.sort-asc::after  { content: ' ↑'; color: var(--prog-light); }
        thead th.sort-desc::after { content: ' ↓'; color: var(--prog-light); }
        tbody tr {
            border-bottom: 1px solid rgba(255,255,255,.05);
            transition: background .15s;
            cursor: pointer;
        }
        tbody tr:hover { background: rgba(123,47,160,.07); }
        tbody tr:last-child { border-bottom: none; }
        td { padding: 13px 16px; color: rgba(255,255,255,.85); vertical-align: middle; }
        td.course-name { font-weight: 600; color: #fff; }
        td.teacher-name { color: rgba(255,255,255,.6); font-size: 13px; }

        /* Barre de progression inline */
        .prog-bar-wrap { display: flex; align-items: center; gap: 10px; min-width: 160px; }
        .prog-bar-bg { flex: 1; height: 7px; background: rgba(255,255,255,.08); border-radius: 99px; overflow: hidden; }
        .prog-bar-fill { height: 100%; border-radius: 99px; transition: width .5s; background: linear-gradient(90deg, #7b2fa0, #c084fc); }
        .prog-bar-fill.low    { background: linear-gradient(90deg, #c0392b, #e74c3c); }
        .prog-bar-fill.medium { background: linear-gradient(90deg, #e67e22, #f39c12); }
        .prog-bar-fill.high   { background: linear-gradient(90deg, #27ae60, #2ecc71); }
        .prog-pct { font-size: 13px; font-weight: 700; color: var(--prog-light); min-width: 38px; text-align: right; }

        /* Badge statut */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge.none     { background: rgba(255,255,255,.06); color: rgba(255,255,255,.4); border: 1px solid rgba(255,255,255,.1); }
        .badge.started  { background: rgba(230,126,34,.12); color: #f39c12; border: 1px solid rgba(230,126,34,.25); }
        .badge.mid      { background: rgba(123,47,160,.15); color: #c084fc; border: 1px solid rgba(123,47,160,.3); }
        .badge.done     { background: rgba(39,174,96,.12); color: #2ecc71; border: 1px solid rgba(39,174,96,.25); }

        /* Loading / Vide */
        .table-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 50px;
            color: rgba(255,255,255,.35);
            font-size: 15px;
        }
        .spinner {
            width: 22px; height: 22px;
            border: 2px solid rgba(255,255,255,.1);
            border-top-color: var(--prog-light);
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .table-empty { text-align: center; padding: 50px; color: rgba(255,255,255,.3); }
        .table-empty i { font-size: 2.5rem; display: block; margin-bottom: 12px; }

        /* ── Modal détail cours ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.65);
            z-index: 2000;
            backdrop-filter: blur(3px);
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: linear-gradient(160deg, #051e34 0%, #0c1a2e 100%);
            border: 1px solid rgba(123,47,160,.3);
            border-radius: 16px;
            width: 100%;
            max-width: 700px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 60px rgba(0,0,0,.5);
            animation: modalIn .25s ease;
        }
        @keyframes modalIn { from { transform: scale(.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            padding: 20px 24px;
            border-bottom: 2px solid var(--prog-color);
            background: rgba(123,47,160,.1);
            flex-shrink: 0;
            border-radius: 16px 16px 0 0;
        }
        .modal-title h2 { font-size: 1.2rem; font-weight: 700; color: var(--prog-light); }
        .modal-title p  { font-size: 13px; color: rgba(255,255,255,.5); margin-top: 4px; }
        .modal-close {
            background: transparent;
            border: none;
            color: rgba(255,255,255,.6);
            font-size: 22px;
            cursor: pointer;
            width: 36px; height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .2s;
            flex-shrink: 0;
        }
        .modal-close:hover { background: rgba(255,255,255,.1); color: #fff; transform: rotate(90deg); }

        /* KPIs modal */
        .modal-kpis {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            padding: 16px 24px;
            border-bottom: 1px solid rgba(255,255,255,.06);
            flex-shrink: 0;
        }
        .modal-kpi { text-align: center; padding: 10px; background: rgba(255,255,255,.03); border-radius: 10px; border: 1px solid rgba(255,255,255,.06); }
        .modal-kpi-num { font-size: 1.3rem; font-weight: 700; color: var(--prog-light); display: block; }
        .modal-kpi-lbl { font-size: 10px; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .4px; margin-top: 2px; display: block; }

        /* Barre progression modal */
        .modal-bar-wrap { padding: 12px 24px; border-bottom: 1px solid rgba(255,255,255,.05); flex-shrink: 0; }
        .modal-bar-header { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px; color: rgba(255,255,255,.6); }
        .modal-bar-pct { font-weight: 700; color: var(--prog-light); font-size: 15px; }
        .modal-bar-bg  { height: 9px; background: rgba(255,255,255,.08); border-radius: 99px; overflow: hidden; }
        .modal-bar-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--prog-color), var(--prog-light)); transition: width .7s; }

        /* Chapitres modal */
        .modal-chapters { flex: 1; overflow-y: auto; padding: 14px 24px 20px; }
        .modal-chapters::-webkit-scrollbar { width: 5px; }
        .modal-chapters::-webkit-scrollbar-thumb { background: var(--prog-color); border-radius: 3px; }
        .modal-chap { margin-bottom: 10px; border: 1px solid rgba(123,47,160,.18); border-radius: 10px; overflow: hidden; }
        .modal-chap-head { display: flex; align-items: center; gap: 10px; padding: 11px 14px; background: rgba(123,47,160,.07); cursor: pointer; transition: background .2s; }
        .modal-chap-head:hover { background: rgba(123,47,160,.14); }
        .modal-chap-num { min-width: 26px; height: 26px; border-radius: 50%; background: linear-gradient(135deg, var(--prog-color), var(--prog-light)); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #fff; flex-shrink: 0; }
        .modal-chap-title { flex: 1; font-size: 13px; font-weight: 600; color: #dac9ff; }
        .modal-chap-badge { font-size: 11px; color: rgba(255,255,255,.4); background: rgba(255,255,255,.05); padding: 2px 8px; border-radius: 99px; }
        .modal-chap-arrow { font-size: 11px; color: rgba(255,255,255,.35); transition: transform .2s; }
        .modal-chap.open .modal-chap-arrow { transform: rotate(180deg); }
        .modal-sess-list { display: none; }
        .modal-chap.open .modal-sess-list { display: block; }
        .modal-sess {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 9px 14px;
            border-top: 1px solid rgba(255,255,255,.04);
        }
        .modal-sess-n { min-width: 22px; height: 22px; border-radius: 50%; background: rgba(192,132,252,.12); border: 1px solid rgba(192,132,252,.25); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: var(--prog-light); flex-shrink: 0; margin-top: 1px; }
        .modal-sess-body { flex: 1; min-width: 0; }
        .modal-sess-title { font-size: 13px; font-weight: 500; color: rgba(255,255,255,.85); }
        .modal-sess-meta { font-size: 11px; color: rgba(255,255,255,.4); margin-top: 3px; display: flex; gap: 12px; }
        .modal-chap-empty { padding: 10px 14px; font-size: 12px; color: rgba(255,255,255,.3); }

        /* Loading modal */
        .modal-loading { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 60px; color: rgba(255,255,255,.35); }

        /* ── Boutons séance (modifier / supprimer) ── */
        .sess-btn-edit {
            padding: 3px 9px;
            background: rgba(3,155,229,.15);
            color: #29b6f6;
            border: 1px solid rgba(3,155,229,.3);
            border-radius: 6px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            transition: background .2s;
            white-space: nowrap;
        }
        .sess-btn-edit:hover { background: rgba(3,155,229,.3); }
        .sess-btn-del {
            padding: 3px 9px;
            background: rgba(231,76,60,.13);
            color: #e74c3c;
            border: 1px solid rgba(231,76,60,.28);
            border-radius: 6px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            transition: background .2s;
            white-space: nowrap;
        }
        .sess-btn-del:hover { background: rgba(231,76,60,.27); }

        /* ── Modal édition séance ── */
        #editSessionModal .modal-box { max-width: 480px; }
        .edit-form-body {
            padding: 20px 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            overflow-y: auto;
        }
        .edit-row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
        .edit-error {
            display: none;
            padding: 10px 14px;
            background: rgba(231,76,60,.12);
            border: 1px solid rgba(231,76,60,.3);
            border-radius: 8px;
            color: #e74c3c;
            font-size: 13px;
        }
        .edit-textarea {
            width: 100%;
            padding: 10px 14px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            outline: none;
            resize: vertical;
            min-height: 68px;
            font-family: inherit;
            transition: border-color .2s;
        }
        .edit-textarea:focus { border-color: var(--prog-color); }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .filters-row { grid-template-columns: 1fr 1fr 1fr; }
            .modal-kpis { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 600px) {
            .filters-row { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .modal-box { max-height: 95vh; }
        }
    </style>
</head>
<body>

<?php include '../includes/header_admin.php'; ?>

<div class="dashboard-container">

    <!-- En-tête de page -->
    <div class="page-header">
        <i class="fas fa-chart-line"></i>
        <div>
            <h1>Progression des Cours</h1>
            <div class="page-header-sub">Supervision de l'avancement pédagogique par cours et par enseignant</div>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="stats-grid" id="kpiGrid">
        <div class="stat-card">
            <i class="fas fa-book-open"></i>
            <h3>Cours avec contenu</h3>
            <p id="kpiCoursesContent">–</p>
        </div>
        <div class="stat-card">
            <i class="fas fa-list"></i>
            <h3>Chapitres créés</h3>
            <p id="kpiChapters">–</p>
        </div>
        <div class="stat-card">
            <i class="fas fa-chalkboard-teacher"></i>
            <h3>Séances réalisées</h3>
            <p id="kpiSessions">–</p>
        </div>
        <div class="stat-card">
            <i class="fas fa-clock"></i>
            <h3>Heures effectuées</h3>
            <p id="kpiHours">–</p>
        </div>
        <div class="stat-card accent">
            <i class="fas fa-percentage"></i>
            <h3>Progression moyenne</h3>
            <p id="kpiAvgProgress">–</p>
        </div>
        <div class="stat-card" id="kpiMissingCard">
            <i class="fas fa-exclamation-triangle" id="kpiMissingIcon" style="color:#f39c12;"></i>
            <h3>Appels non faits</h3>
            <p id="kpiMissing" style="color:#f39c12;">–</p>
        </div>
    </div>

    <!-- Séances sans appel -->
    <div id="missingSessions" class="table-card" style="display:none;margin-bottom:24px;">
        <div class="table-card-header" style="cursor:pointer;" onclick="toggleMissingSessions()">
            <h3 style="color:#e74c3c;">
                <i class="fas fa-calendar-times"></i>
                Séances sans appel
                <span id="missingBadge" style="margin-left:8px;background:#e74c3c;color:#fff;
                      padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;"></span>
            </h3>
            <i id="missingToggleIcon" class="fas fa-chevron-down" style="color:rgba(255,255,255,.4);transition:transform .2s;"></i>
        </div>
        <div id="missingBody">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Cours</th>
                            <th>Classe</th>
                            <th>Enseignant</th>
                            <th>Horaire</th>
                            <th>Chapitre</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="missingTableBody">
                        <tr><td colspan="7"><div class="table-loading"><div class="spinner"></div>Chargement…</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="filters-card">
        <h3><i class="fas fa-filter"></i> Filtres</h3>
        <div class="filters-row">
            <div class="filter-group">
                <label>Année académique</label>
                <select id="yearFilter" onchange="applyAllFilters()">
                    <option value="">Toutes les années</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Recherche</label>
                <input type="text" id="searchInput" placeholder="Nom du cours ou de l'enseignant…" oninput="applyFilters()">
            </div>
            <div class="filter-group">
                <label>Classe</label>
                <select id="classFilter" onchange="applyFilters()">
                    <option value="">Toutes les classes</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Enseignant</label>
                <select id="teacherFilter" onchange="applyFilters()">
                    <option value="">Tous les enseignants</option>
                </select>
            </div>
            <div style="display:flex;gap:8px">
                <button class="btn-filter" onclick="applyFilters()">
                    <i class="fas fa-search"></i> Filtrer
                </button>
                <button class="btn-reset" onclick="resetFilters()" title="Réinitialiser">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Tableau de progression -->
    <div class="table-card">
        <div class="table-card-header">
            <h3><i class="fas fa-table"></i> Avancement par cours</h3>
            <span class="table-count" id="tableCount">…</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th onclick="sortTable('course_name')">Cours</th>
                        <th onclick="sortTable('teacher_name')">Enseignant</th>
                        <th onclick="sortTable('classes')">Classes</th>
                        <th onclick="sortTable('chapters_count')">Chapitres</th>
                        <th onclick="sortTable('sessions_count')">Séances</th>
                        <th onclick="sortTable('done_hours')">Heures faites</th>
                        <th onclick="sortTable('planned_hours')">Heures prévues</th>
                        <th onclick="sortTable('progress_pct')">Progression</th>
                        <th onclick="sortTable('last_session_date')">Dernière séance</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody id="progressTableBody">
                    <tr>
                        <td colspan="10">
                            <div class="table-loading">
                                <div class="spinner"></div>
                                Chargement…
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /.dashboard-container -->

<?php include '../includes/footer.php'; ?>

<!-- ══════════════════════════════════════════════════════════
     MODAL DÉTAIL COURS
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="detailModal" onclick="closeModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">
                <h2 id="modalCourseTitle">—</h2>
                <p id="modalCourseTeacher">—</p>
            </div>
            <button class="modal-close" onclick="closeDetailModal()">&#10005;</button>
        </div>
        <div class="modal-kpis">
            <div class="modal-kpi">
                <span class="modal-kpi-num" id="modalHoursDone">–</span>
                <span class="modal-kpi-lbl">Heures effectuées</span>
            </div>
            <div class="modal-kpi">
                <span class="modal-kpi-num" id="modalSessions">–</span>
                <span class="modal-kpi-lbl">Séances</span>
            </div>
            <div class="modal-kpi">
                <span class="modal-kpi-num" id="modalHoursPlanned">–</span>
                <span class="modal-kpi-lbl">Heures prévues</span>
            </div>
            <div class="modal-kpi">
                <span class="modal-kpi-num" id="modalPct">–</span>
                <span class="modal-kpi-lbl">Avancement</span>
            </div>
        </div>
        <div class="modal-bar-wrap">
            <div class="modal-bar-header">
                <span>Progression du cours</span>
                <span class="modal-bar-pct" id="modalBarPct">0%</span>
            </div>
            <div class="modal-bar-bg">
                <div class="modal-bar-fill" id="modalBarFill" style="width:0%"></div>
            </div>
        </div>
        <div class="modal-chapters" id="modalChapters">
            <div class="modal-loading">
                <div class="spinner"></div> Chargement du contenu…
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL ÉDITION SÉANCE
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editSessionModal" onclick="closeEditModal(event)">
    <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">
                <h2>Modifier la séance</h2>
                <p id="editSessSubtitle"></p>
            </div>
            <button class="modal-close" onclick="closeEditModal()">&#10005;</button>
        </div>
        <div class="edit-form-body">
            <input type="hidden" id="editSessId">

            <div class="filter-group">
                <label>Titre</label>
                <input type="text" id="editSessTitle" placeholder="Titre de la séance">
            </div>

            <div class="filter-group">
                <label>Description</label>
                <textarea id="editSessDesc" class="edit-textarea" placeholder="Description (optionnel)"></textarea>
            </div>

            <div class="filter-group">
                <label>Date</label>
                <input type="date" id="editSessDate">
            </div>

            <div class="edit-row3">
                <div class="filter-group">
                    <label>Heure début</label>
                    <input type="time" id="editSessStart" oninput="calcEditHours()">
                </div>
                <div class="filter-group">
                    <label>Heure fin</label>
                    <input type="time" id="editSessEnd" oninput="calcEditHours()">
                </div>
                <div class="filter-group">
                    <label>Durée</label>
                    <input type="text" id="editSessHours" readonly placeholder="—"
                        style="background:rgba(255,255,255,.03);cursor:default;color:var(--prog-light);font-weight:700;">
                </div>
            </div>

            <div class="edit-error" id="editSessError"></div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px">
                <button onclick="closeEditModal()"
                    style="padding:10px 20px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.7);
                           border:1px solid rgba(255,255,255,.12);border-radius:8px;cursor:pointer;font-size:14px;">
                    Annuler
                </button>
                <button onclick="submitEditSession()" id="editSessSubmit"
                    style="padding:10px 20px;background:linear-gradient(135deg,#039be5,#0277bd);
                           color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL CONFIRMATION SUPPRESSION SÉANCE
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="deleteSessionModal" onclick="closeDeleteModal(event)">
    <div class="modal-box" style="max-width:420px" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">
                <h2 style="color:#e74c3c;">Supprimer cette séance ?</h2>
            </div>
            <button class="modal-close" onclick="closeDeleteModal()">&#10005;</button>
        </div>
        <div style="padding:20px 24px 24px">
            <p id="deleteSessionMsg" style="color:rgba(255,255,255,.75);font-size:14px;line-height:1.5"></p>
            <div id="deleteSessionAttWarn" style="display:none;margin-top:12px;padding:12px 14px;
                 background:rgba(243,156,18,.1);border:1px solid rgba(243,156,18,.3);
                 border-radius:8px;color:#f39c12;font-size:13px;line-height:1.5">
                ⚠️ Un appel est lié à cette séance. Les présences seront conservées mais le lien sera rompu.
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <button onclick="closeDeleteModal()"
                    style="padding:10px 20px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.7);
                           border:1px solid rgba(255,255,255,.12);border-radius:8px;cursor:pointer;font-size:14px;">
                    Annuler
                </button>
                <button onclick="doDeleteSession()"
                    style="padding:10px 20px;background:linear-gradient(135deg,#c0392b,#e74c3c);
                           color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;">
                    🗑 Supprimer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL ÉDITION CHAPITRE
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editChapterModal" onclick="closeEditChapterModal(event)">
    <div class="modal-box" style="max-width:420px" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">
                <h2>Modifier le chapitre</h2>
            </div>
            <button class="modal-close" onclick="closeEditChapterModal()">&#10005;</button>
        </div>
        <div class="edit-form-body">
            <input type="hidden" id="editChapId">
            <div class="filter-group">
                <label>Titre</label>
                <input type="text" id="editChapTitle" placeholder="Titre du chapitre">
            </div>
            <div class="edit-error" id="editChapError"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px">
                <button onclick="closeEditChapterModal()"
                    style="padding:10px 20px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.7);
                           border:1px solid rgba(255,255,255,.12);border-radius:8px;cursor:pointer;font-size:14px;">
                    Annuler
                </button>
                <button onclick="submitEditChapter()" id="editChapSubmit"
                    style="padding:10px 20px;background:linear-gradient(135deg,#039be5,#0277bd);
                           color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL CONFIRMATION SUPPRESSION CHAPITRE
══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="deleteChapterModal" onclick="closeDeleteChapterModal(event)">
    <div class="modal-box" style="max-width:420px" onclick="event.stopPropagation()">
        <div class="modal-header">
            <div class="modal-title">
                <h2 style="color:#e74c3c;">Supprimer ce chapitre ?</h2>
            </div>
            <button class="modal-close" onclick="closeDeleteChapterModal()">&#10005;</button>
        </div>
        <div style="padding:20px 24px 24px">
            <p id="deleteChapMsg" style="color:rgba(255,255,255,.75);font-size:14px;line-height:1.5"></p>
            <div id="deleteChapAttWarn" style="display:none;margin-top:12px;padding:12px 14px;
                 background:rgba(243,156,18,.1);border:1px solid rgba(243,156,18,.3);
                 border-radius:8px;color:#f39c12;font-size:13px;line-height:1.5"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
                <button onclick="closeDeleteChapterModal()"
                    style="padding:10px 20px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.7);
                           border:1px solid rgba(255,255,255,.12);border-radius:8px;cursor:pointer;font-size:14px;">
                    Annuler
                </button>
                <button onclick="doDeleteChapter()"
                    style="padding:10px 20px;background:linear-gradient(135deg,#c0392b,#e74c3c);
                           color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px;">
                    🗑 Supprimer
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════
//  ÉTAT GLOBAL
// ═══════════════════════════════════════════════════════════
let allRows                = [];
let sortKey                = 'progress_pct';
let sortDir                = 'desc';
let currentYear            = '<?= ANNEE_ACADEMIQUE_COURANTE ?>';
let currentDetailCourseId  = 0;
let sessionDataMap         = {};
let pendingDeleteSessionId = null;
let pendingDeleteChapterId = null;

// ═══════════════════════════════════════════════════════════
//  INITIALISATION
// ═══════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    loadAcademicYears();
    loadClasses();
    loadTeachers();
});

// ═══════════════════════════════════════════════════════════
//  KPIs
// ═══════════════════════════════════════════════════════════
function loadKpis() {
    const year  = document.getElementById('yearFilter').value;
    const classId  = document.getElementById('classFilter').value;
    const teachId  = document.getElementById('teacherFilter').value;
    let url = '?ajax=get_kpis';
    if (year)    url += '&academic_year=' + encodeURIComponent(year);
    if (classId) url += '&class_id='      + encodeURIComponent(classId);
    if (teachId) url += '&teacher_id='    + encodeURIComponent(teachId);
    fetch(url)
        .then(r => r.json())
        .then(d => {
            document.getElementById('kpiCoursesContent').textContent = d.courses_with_content ?? '–';
            document.getElementById('kpiChapters').textContent       = d.total_chapters       ?? '–';
            document.getElementById('kpiSessions').textContent       = d.total_sessions       ?? '–';
            document.getElementById('kpiHours').textContent          = (d.total_hours ?? 0) + 'h';
            document.getElementById('kpiAvgProgress').textContent    = (d.avg_progress ?? 0) + '%';

            const missing  = d.missing_calls ?? 0;
            const kpiEl    = document.getElementById('kpiMissing');
            const iconEl   = document.getElementById('kpiMissingIcon');
            const cardEl   = document.getElementById('kpiMissingCard');
            kpiEl.textContent = missing;
            if (missing > 0) {
                kpiEl.style.color   = '#e74c3c';
                iconEl.style.color  = '#e74c3c';
                cardEl.style.borderColor = 'rgba(231,76,60,0.45)';
                loadMissingCalls();
            } else {
                kpiEl.style.color   = '#f39c12';
                iconEl.style.color  = '#f39c12';
                cardEl.style.borderColor = '';
                document.getElementById('missingSessions').style.display = 'none';
            }
        })
        .catch(() => {});
}

let missingCollapsed = false;

function loadMissingCalls() {
    const year    = document.getElementById('yearFilter').value;
    const classId = document.getElementById('classFilter').value;
    const teachId = document.getElementById('teacherFilter').value;
    let url = '?ajax=get_missing_calls';
    if (year)    url += '&academic_year=' + encodeURIComponent(year);
    if (classId) url += '&class_id='      + encodeURIComponent(classId);
    if (teachId) url += '&teacher_id='    + encodeURIComponent(teachId);

    document.getElementById('missingSessions').style.display = '';
    document.getElementById('missingTableBody').innerHTML =
        '<tr><td colspan="7"><div class="table-loading"><div class="spinner"></div>Chargement…</div></td></tr>';

    fetch(url)
        .then(r => r.json())
        .then(rows => {
            document.getElementById('missingBadge').textContent =
                rows.length + (rows.length === 50 ? '+' : '');
            if (!rows.length) {
                document.getElementById('missingTableBody').innerHTML =
                    '<tr><td colspan="7"><div class="table-empty"><i class="fas fa-check-circle"></i>Aucune séance sans appel.</div></td></tr>';
                return;
            }
            document.getElementById('missingTableBody').innerHTML = rows.map(r => {
                const time = (r.start_time && r.end_time)
                    ? r.start_time.slice(0,5) + ' – ' + r.end_time.slice(0,5)
                    : (r.hours ? r.hours + 'h' : '–');
                return `<tr>
                    <td style="font-size:12px;color:rgba(255,255,255,.5)">${formatDate(r.session_date)}</td>
                    <td style="font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="${escHtml(r.course_name)}">${escHtml(r.course_name)}</td>
                    <td style="font-size:12px;color:rgba(255,255,255,.55)">${r.class_name === '[Classe supprimée]' ? '<span style="color:#888;font-style:italic">[Classe supprimée]</span>' : escHtml(r.class_name || '–')}</td>
                    <td class="teacher-name">${r.teacher_name === '[Enseignant supprimé]' ? '<span style="color:#888;font-style:italic">[Enseignant supprimé]</span>' : escHtml(r.teacher_name || '–')}</td>
                    <td style="font-size:12px;color:rgba(255,255,255,.5)">${time}</td>
                    <td style="font-size:12px;color:rgba(255,255,255,.55);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="${escHtml(r.chapter)}">${escHtml(r.chapter || '–')}</td>
                    <td>
                        <button class="btn-filter" style="font-size:12px;padding:6px 14px;background:rgba(123,47,160,.25);
                                border:1px solid rgba(192,132,252,.3);"
                                onclick="event.stopPropagation();openDetailModal(${parseInt(r.course_id)||0})">
                            <i class="fas fa-eye"></i> Voir le cours
                        </button>
                    </td>
                </tr>`;
            }).join('');
        });
}

function toggleMissingSessions() {
    missingCollapsed = !missingCollapsed;
    document.getElementById('missingBody').style.display   = missingCollapsed ? 'none' : '';
    document.getElementById('missingToggleIcon').className = missingCollapsed
        ? 'fas fa-chevron-right' : 'fas fa-chevron-down';
}

// ═══════════════════════════════════════════════════════════
//  FILTRES — Chargement des sélects
// ═══════════════════════════════════════════════════════════
function loadAcademicYears() {
    fetch('?ajax=get_academic_years')
        .then(r => r.json())
        .then(years => {
            const sel = document.getElementById('yearFilter');
            years.forEach(y => {
                const opt = document.createElement('option');
                opt.value       = y;
                opt.textContent = y;
                sel.appendChild(opt);
            });
            if (years.includes(currentYear)) sel.value = currentYear;
            loadKpis();
            loadTable();
        })
        .catch(() => { loadKpis(); loadTable(); });
}
function loadClasses() {
    fetch('?ajax=get_classes')
        .then(r => r.json())
        .then(classes => {
            const sel = document.getElementById('classFilter');
            classes.forEach(cl => {
                const opt = document.createElement('option');
                opt.value       = cl.id;
                opt.textContent = cl.name;
                sel.appendChild(opt);
            });
        });
}
function loadTeachers() {
    fetch('?ajax=get_teachers')
        .then(r => r.json())
        .then(teachers => {
            const sel = document.getElementById('teacherFilter');
            teachers.forEach(t => {
                const opt = document.createElement('option');
                opt.value       = t.id;
                opt.textContent = t.name;
                sel.appendChild(opt);
            });
        });
}

// ═══════════════════════════════════════════════════════════
//  TABLEAU
// ═══════════════════════════════════════════════════════════
function loadTable() {
    const tbody   = document.getElementById('progressTableBody');
    const search  = document.getElementById('searchInput').value.trim();
    const classId = document.getElementById('classFilter').value;
    const teachId = document.getElementById('teacherFilter').value;

    tbody.innerHTML = '<tr><td colspan="10"><div class="table-loading"><div class="spinner"></div>Chargement…</div></td></tr>';

    const year    = document.getElementById('yearFilter').value;
    let url = '?ajax=get_progress_table';
    if (search)  url += '&search='        + encodeURIComponent(search);
    if (classId) url += '&class_id='      + encodeURIComponent(classId);
    if (teachId) url += '&teacher_id='    + encodeURIComponent(teachId);
    if (year)    url += '&academic_year=' + encodeURIComponent(year);

    fetch(url)
        .then(r => r.json())
        .then(rows => {
            allRows = rows;
            renderTable();
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="10"><div class="table-empty"><i class="fas fa-exclamation-circle"></i>Erreur de chargement.</div></td></tr>';
        });
}

function applyFilters()    { loadKpis(); loadTable(); }
function applyAllFilters() { loadKpis(); loadTable(); }

function resetFilters() {
    document.getElementById('searchInput').value   = '';
    document.getElementById('classFilter').value   = '';
    document.getElementById('teacherFilter').value = '';
    document.getElementById('yearFilter').value    = currentYear;
    loadKpis();
    loadTable();
}

function sortTable(key) {
    if (sortKey === key) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey = key;
        sortDir = 'asc';
    }
    renderTable();
    // Mettre à jour les indicateurs visuels
    document.querySelectorAll('thead th').forEach(th => {
        th.classList.remove('sort-asc', 'sort-desc');
    });
    // Identifier le th par position
    const cols = ['course_name','teacher_name','classes','chapters_count','sessions_count','done_hours','planned_hours','progress_pct','last_session_date',''];
    const idx  = cols.indexOf(key);
    if (idx >= 0) {
        const ths = document.querySelectorAll('thead th');
        if (ths[idx]) ths[idx].classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
    }
}

function renderTable() {
    const tbody = document.getElementById('progressTableBody');
    if (!allRows.length) {
        tbody.innerHTML = '<tr><td colspan="10"><div class="table-empty"><i class="fas fa-search"></i>Aucun cours trouvé.</div></td></tr>';
        document.getElementById('tableCount').textContent = '0 cours';
        return;
    }

    // Tri
    const sorted = [...allRows].sort((a, b) => {
        let va = a[sortKey], vb = b[sortKey];
        if (va === null || va === undefined) va = '';
        if (vb === null || vb === undefined) vb = '';
        if (typeof va === 'number') return sortDir === 'asc' ? va - vb : vb - va;
        return sortDir === 'asc'
            ? String(va).localeCompare(String(vb), 'fr')
            : String(vb).localeCompare(String(va), 'fr');
    });

    tbody.innerHTML = sorted.map(row => renderRow(row)).join('');
    document.getElementById('tableCount').textContent = sorted.length + ' cours';
}

function renderRow(row) {
    const pct      = row.progress_pct;
    const barClass = pct < 25 ? 'low' : pct < 75 ? 'medium' : 'high';
    const badge    = getBadge(pct);
    const dateStr  = row.last_session_date ? formatDate(row.last_session_date) : '—';
    const doneStr  = row.done_hours ? row.done_hours + 'h' : '0h';
    const planStr  = row.planned_hours ? row.planned_hours + 'h' : '—';

    return `<tr onclick="openDetailModal(${row.course_id})">
        <td class="course-name">${escHtml(row.course_name)}</td>
        <td class="teacher-name">${row.teacher_name === '[Enseignant supprimé]' ? '<span style="color:#888;font-style:italic">[Enseignant supprimé]</span>' : escHtml(row.teacher_name)}</td>
        <td style="font-size:12px;color:rgba(255,255,255,.55)">${row.classes === '[Classe supprimée]' ? '<span style="color:#888;font-style:italic">[Classe supprimée]</span>' : escHtml(row.classes)}</td>
        <td style="text-align:center">${row.chapters_count}</td>
        <td style="text-align:center">${row.sessions_count}</td>
        <td style="font-weight:600;color:#c084fc">${doneStr}</td>
        <td style="color:rgba(255,255,255,.5)">${planStr}</td>
        <td>
            <div class="prog-bar-wrap">
                <div class="prog-bar-bg">
                    <div class="prog-bar-fill ${barClass}" style="width:${pct}%"></div>
                </div>
                <span class="prog-pct">${pct}%</span>
            </div>
        </td>
        <td style="font-size:12px;color:rgba(255,255,255,.5)">${dateStr}</td>
        <td>${badge}</td>
    </tr>`;
}

function getBadge(pct) {
    if (pct === 0)   return '<span class="badge none"><i class="fas fa-circle"></i> Non commencé</span>';
    if (pct < 30)    return '<span class="badge started"><i class="fas fa-hourglass-start"></i> Démarré</span>';
    if (pct < 80)    return '<span class="badge mid"><i class="fas fa-hourglass-half"></i> En cours</span>';
    return               '<span class="badge done"><i class="fas fa-check-circle"></i> Avancé</span>';
}

// ═══════════════════════════════════════════════════════════
//  MODAL DÉTAIL
// ═══════════════════════════════════════════════════════════
function openDetailModal(courseId) {
    currentDetailCourseId = courseId;
    sessionDataMap = {};
    document.getElementById('detailModal').classList.add('open');
    document.getElementById('modalChapters').innerHTML = '<div class="modal-loading"><div class="spinner"></div>Chargement…</div>';
    document.getElementById('modalCourseTitle').textContent  = '–';
    document.getElementById('modalCourseTeacher').textContent = '–';
    document.getElementById('modalBarFill').style.width = '0%';
    document.getElementById('modalBarPct').textContent  = '0%';

    const year = document.getElementById('yearFilter').value;
    let detailUrl = '?ajax=get_course_detail&course_id=' + courseId;
    if (year) detailUrl += '&academic_year=' + encodeURIComponent(year);
    fetch(detailUrl)
        .then(r => r.json())
        .then(d => {
            if (d.error) {
                document.getElementById('modalChapters').innerHTML = '<div class="modal-loading">Cours introuvable.</div>';
                return;
            }
            document.getElementById('modalCourseTitle').textContent   = d.course_name;
            document.getElementById('modalCourseTeacher').textContent  = '👤 ' + d.teacher;
            document.getElementById('modalHoursDone').textContent      = d.done_hours + 'h';
            document.getElementById('modalSessions').textContent       = d.sessions_count;
            document.getElementById('modalHoursPlanned').textContent   = d.planned_hours ? d.planned_hours + 'h' : '—';
            document.getElementById('modalPct').textContent            = d.progress_pct + '%';
            document.getElementById('modalBarPct').textContent         = d.progress_pct + '%';
            // Barre avec délai pour animation
            setTimeout(() => {
                document.getElementById('modalBarFill').style.width = d.progress_pct + '%';
            }, 80);
            renderModalChapters(d.chapters || []);
        })
        .catch(() => {
            document.getElementById('modalChapters').innerHTML = '<div class="modal-loading">Erreur de chargement.</div>';
        });
}

function renderModalChapters(chapters) {
    const wrap      = document.getElementById('modalChapters');
    const today     = new Date().toISOString().slice(0, 10);
    const iStyle    = 'width:100%;padding:7px 10px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:6px;color:#fff;font-size:13px;outline:none;box-sizing:border-box';
    const lblStyle  = 'display:block;font-size:11px;color:rgba(255,255,255,.4);margin-bottom:3px';

    let chapHtml = '';
    if (!chapters.length) {
        chapHtml = '<div style="text-align:center;padding:30px;color:rgba(255,255,255,.3)"><i class="fas fa-book-open" style="font-size:2rem;display:block;margin-bottom:10px"></i>Aucun contenu défini pour ce cours.</div>';
    } else {
        chapHtml = chapters.map((ch, idx) => {
            const sessions = ch.sessions || [];
            const nbSess   = sessions.length;
            const hours    = sessions.reduce((acc, x) => acc + parseFloat(x.hours || 0), 0);

            const sessionsHtml = sessions.map(s => {
                sessionDataMap[s.id] = s;

                const hoursVal = parseFloat(s.effective_hours ?? s.hours ?? 0);
                const hoursStr = hoursVal.toFixed(1).replace('.0', '') + 'h';
                const timeStr  = (s.start_time && s.end_time)
                    ? s.start_time.slice(0, 5) + ' – ' + s.end_time.slice(0, 5)
                    : '';

                let callBadge;
                if (s.call_done) {
                    callBadge = '<span style="background:rgba(39,174,96,.15);color:#2ecc71;border:1px solid rgba(39,174,96,.3);padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;">✓ Appel fait</span>';
                } else if (s.session_date && s.session_date < today) {
                    callBadge = '<span style="background:rgba(231,76,60,.15);color:#e74c3c;border:1px solid rgba(231,76,60,.3);padding:1px 7px;border-radius:99px;font-size:10px;font-weight:700;">⚠ Appel manquant</span>';
                } else {
                    callBadge = '<span style="background:rgba(255,255,255,.06);color:rgba(255,255,255,.3);border:1px solid rgba(255,255,255,.1);padding:1px 7px;border-radius:99px;font-size:10px;">À venir</span>';
                }

                const descId  = 'sdesc_' + s.id;
                const isLong  = (s.description || '').length > 100;
                const descHtml = s.description
                    ? '<div id="' + descId + '" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;color:rgba(255,255,255,.4);font-size:0.85em;margin-top:4px">'
                      + escHtml(s.description)
                      + '</div>'
                      + (isLong ? '<span onclick="expandDesc(\'' + descId + '\',this)" style="color:#a78bfa;font-size:0.8em;cursor:pointer;user-select:none">Voir plus</span>' : '')
                    : '';

                const metaParts = [];
                if (s.session_date) metaParts.push('<span><i class="fas fa-calendar-alt"></i> ' + formatDate(s.session_date) + '</span>');
                if (timeStr)        metaParts.push('<span><i class="fas fa-clock"></i> ' + timeStr + '</span>');
                metaParts.push('<span><i class="fas fa-hourglass-half"></i> ' + hoursStr + '</span>');
                metaParts.push('<span>' + callBadge + '</span>');

                return '<div class="modal-sess">'
                    + '<span class="modal-sess-n">' + s.global_num + '</span>'
                    + '<div class="modal-sess-body">'
                    + '<div class="modal-sess-title">'
                    + '<span style="color:rgba(255,255,255,.38);font-size:10px;font-weight:700;margin-right:5px;text-transform:uppercase;letter-spacing:.4px">S' + s.session_number + '</span>'
                    + escHtml(s.title || '–')
                    + '</div>'
                    + descHtml
                    + '<div class="modal-sess-meta">' + metaParts.join('') + '</div>'
                    + '</div>'
                    + '<div style="display:flex;gap:5px;flex-shrink:0;align-items:flex-start;padding-top:2px;margin-left:8px">'
                    + '<button class="sess-btn-edit"'
                    + ' data-session-id="' + s.id + '"'
                    + ' data-title="' + escAttr(s.title || '') + '"'
                    + ' data-description="' + escAttr(s.description || '') + '"'
                    + ' data-date="' + escAttr(s.session_date || '') + '"'
                    + ' data-start="' + escAttr(s.start_time ? s.start_time.slice(0, 5) : '') + '"'
                    + ' data-end="' + escAttr(s.end_time ? s.end_time.slice(0, 5) : '') + '"'
                    + ' data-hours="' + (s.hours || 0) + '"'
                    + ' data-session-number="' + s.session_number + '"'
                    + ' onclick="event.stopPropagation();openEditSessionModal(this)" title="Modifier">✏️ Modifier</button>'
                    + '<button class="sess-btn-del"'
                    + ' data-session-id="' + s.id + '"'
                    + ' data-att-session-id="' + escAttr(s.attendance_session_id || '') + '"'
                    + ' onclick="event.stopPropagation();confirmDeleteSession(this)" title="Supprimer">🗑 Supprimer</button>'
                    + '</div>'
                    + '</div>';
            }).join('') || '<div class="modal-chap-empty">Aucune séance.</div>';

            // Panneau "+ Séance" (dans modal-sess-list, caché quand l'accordéon est fermé)
            const addSessSection =
                '<div style="border-top:1px solid rgba(255,255,255,.04);padding:8px 14px">'
                + '<button id="showAddSessBtn_' + ch.id + '"'
                + ' onclick="showAddSessionForm(' + ch.id + ')"'
                + ' style="width:100%;padding:6px;border:1px dashed rgba(192,132,252,.25);background:rgba(123,47,160,.04);'
                + 'color:rgba(192,132,252,.5);border-radius:6px;cursor:pointer;font-size:12px">+ Ajouter une séance</button>'
                + '<div id="addSessPanel_' + ch.id + '" style="display:none;margin-top:8px;padding:12px;'
                + 'background:rgba(123,47,160,.07);border:1px solid rgba(123,47,160,.2);border-radius:8px">'
                + '<div style="font-size:12px;font-weight:600;color:rgba(192,132,252,.8);margin-bottom:10px">Nouvelle séance</div>'
                + '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px">'
                + '<div><label style="' + lblStyle + '">Date *</label>'
                + '<input type="date" id="addSessDate_' + ch.id + '" value="' + today + '" style="' + iStyle + '" oninput="calcAddSessHours(' + ch.id + ')"></div>'
                + '<div><label style="' + lblStyle + '">Début *</label>'
                + '<input type="time" id="addSessStart_' + ch.id + '" style="' + iStyle + '" oninput="calcAddSessHours(' + ch.id + ')"></div>'
                + '<div><label style="' + lblStyle + '">Fin *</label>'
                + '<input type="time" id="addSessEnd_' + ch.id + '" style="' + iStyle + '" oninput="calcAddSessHours(' + ch.id + ')"></div>'
                + '</div>'
                + '<div style="margin-bottom:8px"><label style="' + lblStyle + '">Titre (optionnel)</label>'
                + '<input type="text" id="addSessTitle_' + ch.id + '" placeholder="Titre de la séance" style="' + iStyle + '"></div>'
                + '<div style="margin-bottom:8px"><label style="' + lblStyle + '">Description (optionnel)</label>'
                + '<textarea id="addSessDesc_' + ch.id + '" rows="2" placeholder="Description (optionnel)"'
                + ' style="' + iStyle + ';resize:vertical"></textarea></div>'
                + '<div id="addSessHoursDisplay_' + ch.id + '" style="font-size:12px;color:var(--prog-light);font-weight:600;margin-bottom:8px">Durée : —</div>'
                + '<div id="addSessErr_' + ch.id + '" style="display:none;color:#e74c3c;font-size:12px;margin-bottom:8px"></div>'
                + '<div style="display:flex;gap:8px;justify-content:flex-end">'
                + '<button onclick="cancelAddSession(' + ch.id + ')" style="padding:6px 14px;background:rgba(255,255,255,.07);color:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.1);border-radius:6px;cursor:pointer;font-size:12px">Annuler</button>'
                + '<button onclick="submitAddSession(' + ch.id + ')" id="addSessSubmitBtn_' + ch.id + '" style="padding:6px 14px;background:linear-gradient(135deg,var(--prog-color),var(--prog-light));color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600">+ Ajouter</button>'
                + '</div></div></div>';

            const isOpen = idx === 0 ? 'open' : '';
            return '<div class="modal-chap ' + isOpen + '" id="mchap_' + ch.id + '">'
                + '<div class="modal-chap-head" onclick="toggleModalChap(' + ch.id + ')">'
                + '<span class="modal-chap-num">' + ch.order_num + '</span>'
                + '<span class="modal-chap-title" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escHtml(ch.title) + '</span>'
                + '<div style="flex-shrink:0;display:flex;align-items:center;gap:4px;margin-left:6px">'
                + '<span class="modal-chap-badge">' + nbSess + ' séance' + (nbSess > 1 ? 's' : '') + ' · ' + hours.toFixed(1).replace('.0', '') + 'h</span>'
                + '<button class="sess-btn-edit" style="padding:2px 8px;font-size:10px"'
                + ' data-chapter-id="' + ch.id + '"'
                + ' data-title="' + escAttr(ch.title) + '"'
                + ' onclick="event.stopPropagation();openEditChapterModal(this)" title="Modifier le chapitre">✏️</button>'
                + '<button class="sess-btn-del" style="padding:2px 8px;font-size:10px"'
                + ' data-chapter-id="' + ch.id + '"'
                + ' data-sessions-count="' + nbSess + '"'
                + ' onclick="event.stopPropagation();confirmDeleteChapter(this)" title="Supprimer le chapitre">🗑</button>'
                + '<i class="fas fa-chevron-down modal-chap-arrow"></i>'
                + '</div>'
                + '</div>'
                + '<div class="modal-sess-list">' + sessionsHtml + addSessSection + '</div>'
                + '</div>';
        }).join('');
    }

    // Panneau "+ Nouveau chapitre" (toujours affiché, sous la liste)
    const addChapSection =
        '<div style="padding:12px 0 0">'
        + '<button onclick="showAddChapterForm()" id="addChapBtn"'
        + ' style="width:100%;padding:10px;border:1px dashed rgba(192,132,252,.25);background:rgba(123,47,160,.04);'
        + 'color:rgba(192,132,252,.55);border-radius:8px;cursor:pointer;font-size:13px">+ Nouveau chapitre</button>'
        + '<div id="addChapForm" style="display:none;margin-top:10px;padding:14px;'
        + 'background:rgba(123,47,160,.07);border:1px solid rgba(123,47,160,.2);border-radius:8px">'
        + '<div style="font-size:12px;font-weight:600;color:rgba(192,132,252,.8);margin-bottom:10px">Nouveau chapitre</div>'
        + '<label style="' + lblStyle + '">Titre *</label>'
        + '<input type="text" id="addChapTitleInput" placeholder="Titre du chapitre" style="' + iStyle + ';margin-bottom:8px">'
        + '<div id="addChapErr" style="display:none;color:#e74c3c;font-size:12px;margin-bottom:8px"></div>'
        + '<div style="display:flex;gap:8px;justify-content:flex-end">'
        + '<button onclick="cancelAddChapter()" style="padding:6px 14px;background:rgba(255,255,255,.07);color:rgba(255,255,255,.6);border:1px solid rgba(255,255,255,.1);border-radius:6px;cursor:pointer;font-size:12px">Annuler</button>'
        + '<button onclick="submitAddChapter()" id="addChapSubmitBtn" style="padding:6px 14px;background:linear-gradient(135deg,var(--prog-color),var(--prog-light));color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600">+ Ajouter</button>'
        + '</div></div></div>';

    wrap.innerHTML = chapHtml + addChapSection;
}

function toggleModalChap(id) {
    const el = document.getElementById('mchap_' + id);
    if (el) el.classList.toggle('open');
}

function expandDesc(id, link) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = 'block';
        el.style.webkitLineClamp = 'unset';
        el.style.overflow = 'visible';
    }
    if (link) link.style.display = 'none';
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('open');
}
function closeModal(e) {
    if (e.target === document.getElementById('detailModal')) closeDetailModal();
}
document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (document.getElementById('deleteChapterModal').classList.contains('open')) {
        document.getElementById('deleteChapterModal').classList.remove('open');
        pendingDeleteChapterId = null;
    } else if (document.getElementById('editChapterModal').classList.contains('open')) {
        document.getElementById('editChapterModal').classList.remove('open');
    } else if (document.getElementById('deleteSessionModal').classList.contains('open')) {
        document.getElementById('deleteSessionModal').classList.remove('open');
        pendingDeleteSessionId = null;
    } else if (document.getElementById('editSessionModal').classList.contains('open')) {
        document.getElementById('editSessionModal').classList.remove('open');
    } else {
        closeDetailModal();
    }
});

// ═══════════════════════════════════════════════════════════
//  MODAL ÉDITION SÉANCE
// ═══════════════════════════════════════════════════════════
function openEditSessionModal(btn) {
    const sessionId = btn.dataset.sessionId;
    const title     = btn.dataset.title       || '';
    const desc      = btn.dataset.description || '';
    const date      = btn.dataset.date        || '';
    const start     = btn.dataset.start       || '';
    const end       = btn.dataset.end         || '';
    const sessNum   = btn.dataset.sessionNumber || '';

    document.getElementById('editSessId').value    = sessionId;
    document.getElementById('editSessTitle').value = title;
    document.getElementById('editSessDesc').value  = desc;
    document.getElementById('editSessDate').value  = date;
    document.getElementById('editSessStart').value = start;
    document.getElementById('editSessEnd').value   = end;
    document.getElementById('editSessSubtitle').textContent = 'Séance ' + sessNum + (title ? ' · ' + title : '');
    document.getElementById('editSessError').style.display = 'none';
    document.getElementById('editSessSubmit').disabled = false;
    document.getElementById('editSessSubmit').innerHTML = '<i class="fas fa-save"></i> Enregistrer';
    calcEditHours();
    document.getElementById('editSessionModal').classList.add('open');
}

function calcEditHours() {
    const start = document.getElementById('editSessStart').value;
    const end   = document.getElementById('editSessEnd').value;
    const el    = document.getElementById('editSessHours');
    if (start && end && end > start) {
        const [sh, sm] = start.split(':').map(Number);
        const [eh, em] = end.split(':').map(Number);
        const h = ((eh * 60 + em) - (sh * 60 + sm)) / 60;
        el.value = h.toFixed(1).replace('.0', '') + 'h';
    } else {
        el.value = '—';
    }
}

function showEditError(msg) {
    const el = document.getElementById('editSessError');
    el.textContent = msg;
    el.style.display = '';
}

function submitEditSession() {
    const btn   = document.getElementById('editSessSubmit');
    const errEl = document.getElementById('editSessError');
    errEl.style.display = 'none';

    const sessionId = document.getElementById('editSessId').value;
    const title     = document.getElementById('editSessTitle').value.trim();
    const desc      = document.getElementById('editSessDesc').value.trim();
    const date      = document.getElementById('editSessDate').value;
    const start     = document.getElementById('editSessStart').value;
    const end       = document.getElementById('editSessEnd').value;

    if (!date)          { showEditError('La date est requise.'); return; }
    if (!start || !end) { showEditError("Les heures de début et de fin sont requises."); return; }
    if (end <= start)   { showEditError("L'heure de fin doit être après l'heure de début."); return; }

    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    const hours = ((eh * 60 + em) - (sh * 60 + sm)) / 60;
    if (hours < 0.5 || hours > 8) { showEditError('La durée doit être entre 0h30 et 8h.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement…';

    const body = new FormData();
    body.append('session_id',   sessionId);
    body.append('title',        title);
    body.append('description',  desc);
    body.append('session_date', date);
    body.append('start_time',   start);
    body.append('end_time',     end);

    fetch('?ajax=edit_course_session', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer';
            if (d.error) { showEditError(d.error); return; }
            document.getElementById('editSessionModal').classList.remove('open');
            reloadCourseDetail();
            applyFilters();
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer';
            showEditError('Erreur réseau. Veuillez réessayer.');
        });
}

function closeEditModal(e) {
    if (e instanceof Event && e.target !== document.getElementById('editSessionModal')) return;
    document.getElementById('editSessionModal').classList.remove('open');
}

// ═══════════════════════════════════════════════════════════
//  SUPPRESSION SÉANCE
// ═══════════════════════════════════════════════════════════
function confirmDeleteSession(btn) {
    const sessionId    = btn.dataset.sessionId;
    const attSessionId = btn.dataset.attSessionId || '';
    pendingDeleteSessionId = sessionId;

    const warnEl = document.getElementById('deleteSessionAttWarn');
    const msgEl  = document.getElementById('deleteSessionMsg');

    if (attSessionId) {
        warnEl.style.display = '';
        msgEl.textContent    = 'Voulez-vous vraiment supprimer cette séance ?';
    } else {
        warnEl.style.display = 'none';
        msgEl.textContent    = 'Cette action est irréversible.';
    }
    document.getElementById('deleteSessionModal').classList.add('open');
}

function closeDeleteModal(e) {
    if (e instanceof Event && e.target !== document.getElementById('deleteSessionModal')) return;
    document.getElementById('deleteSessionModal').classList.remove('open');
    pendingDeleteSessionId = null;
}

function doDeleteSession() {
    document.getElementById('deleteSessionModal').classList.remove('open');
    if (pendingDeleteSessionId) executeDeleteSession(pendingDeleteSessionId);
    pendingDeleteSessionId = null;
}

function executeDeleteSession(sessionId) {
    const body = new FormData();
    body.append('session_id', sessionId);
    fetch('?ajax=delete_course_session', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            if (d.error) { alert('Erreur : ' + d.error); return; }
            reloadCourseDetail();
            applyFilters();
        })
        .catch(() => alert('Erreur réseau.'));
}

// ─── Rechargement du détail modal après modification ─────────
function reloadCourseDetail() {
    if (!currentDetailCourseId) return;
    openDetailModal(currentDetailCourseId);
}

// ═══════════════════════════════════════════════════════════
//  MODAL ÉDITION CHAPITRE
// ═══════════════════════════════════════════════════════════
function openEditChapterModal(btn) {
    document.getElementById('editChapId').value    = btn.dataset.chapterId;
    document.getElementById('editChapTitle').value = btn.dataset.title || '';
    document.getElementById('editChapError').style.display = 'none';
    document.getElementById('editChapSubmit').disabled = false;
    document.getElementById('editChapSubmit').innerHTML = '<i class="fas fa-save"></i> Enregistrer';
    document.getElementById('editChapterModal').classList.add('open');
}

function showChapError(msg) {
    const el = document.getElementById('editChapError');
    el.textContent = msg;
    el.style.display = '';
}

function submitEditChapter() {
    const btn       = document.getElementById('editChapSubmit');
    const chapterId = document.getElementById('editChapId').value;
    const title     = document.getElementById('editChapTitle').value.trim();

    document.getElementById('editChapError').style.display = 'none';
    if (!title)          { showChapError('Le titre est requis.'); return; }
    if (title.length > 255) { showChapError('Titre trop long (255 caractères max).'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement…';

    const body = new FormData();
    body.append('chapter_id', chapterId);
    body.append('title',      title);

    fetch('?ajax=edit_chapter', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer';
            if (d.error) { showChapError(d.error); return; }
            document.getElementById('editChapterModal').classList.remove('open');
            reloadCourseDetail();
            applyFilters();
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer';
            showChapError('Erreur réseau.');
        });
}

function closeEditChapterModal(e) {
    if (e instanceof Event && e.target !== document.getElementById('editChapterModal')) return;
    document.getElementById('editChapterModal').classList.remove('open');
}

// ═══════════════════════════════════════════════════════════
//  SUPPRESSION CHAPITRE
// ═══════════════════════════════════════════════════════════
function confirmDeleteChapter(btn) {
    const chapterId = btn.dataset.chapterId;
    pendingDeleteChapterId = chapterId;

    const body = new FormData();
    body.append('chapter_id', chapterId);
    fetch('?ajax=delete_chapter', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            if (d.error) { alert('Erreur : ' + d.error); pendingDeleteChapterId = null; return; }
            const total    = d.total_sessions    || 0;
            const withCall = d.sessions_with_call || 0;
            const msgEl  = document.getElementById('deleteChapMsg');
            const warnEl = document.getElementById('deleteChapAttWarn');
            if (total === 0) {
                msgEl.textContent = 'Supprimer ce chapitre ? Cette action est irréversible.';
            } else {
                msgEl.textContent = 'Ce chapitre contient ' + total + ' séance' + (total > 1 ? 's' : '') + '. Toutes seront supprimées définitivement.';
            }
            if (withCall > 0) {
                warnEl.style.display = '';
                warnEl.textContent = '⚠️ ' + withCall + ' séance' + (withCall > 1 ? 's ont' : ' a')
                    + ' un appel enregistré. Les présences seront conservées mais les liens seront rompus.';
            } else {
                warnEl.style.display = 'none';
            }
            document.getElementById('deleteChapterModal').classList.add('open');
        })
        .catch(() => { alert('Erreur réseau.'); pendingDeleteChapterId = null; });
}

function closeDeleteChapterModal(e) {
    if (e instanceof Event && e.target !== document.getElementById('deleteChapterModal')) return;
    document.getElementById('deleteChapterModal').classList.remove('open');
    pendingDeleteChapterId = null;
}

function doDeleteChapter() {
    document.getElementById('deleteChapterModal').classList.remove('open');
    if (pendingDeleteChapterId) executeDeleteChapter(pendingDeleteChapterId);
    pendingDeleteChapterId = null;
}

function executeDeleteChapter(chapterId) {
    const body = new FormData();
    body.append('chapter_id', chapterId);
    body.append('confirmed',  '1');
    fetch('?ajax=delete_chapter', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            if (d.error) { alert('Erreur : ' + d.error); return; }
            reloadCourseDetail();
            applyFilters();
        })
        .catch(() => alert('Erreur réseau.'));
}

// ═══════════════════════════════════════════════════════════
//  UTILITAIRES
// ═══════════════════════════════════════════════════════════
function formatDate(dateStr) {
    if (!dateStr) return '—';
    try {
        return new Date(dateStr + 'T00:00:00').toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
    } catch(e) { return dateStr; }
}
function escHtml(str) {
    if (!str) return '';
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
}
function escAttr(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

// ═══════════════════════════════════════════════════════════
//  AJOUT CHAPITRE (inline)
// ═══════════════════════════════════════════════════════════
function showAddChapterForm() {
    document.getElementById('addChapBtn').style.display = 'none';
    document.getElementById('addChapForm').style.display = '';
    document.getElementById('addChapTitleInput').focus();
}

function cancelAddChapter() {
    document.getElementById('addChapBtn').style.display = '';
    document.getElementById('addChapForm').style.display = 'none';
    document.getElementById('addChapTitleInput').value = '';
    document.getElementById('addChapErr').style.display = 'none';
}

function submitAddChapter() {
    const btn   = document.getElementById('addChapSubmitBtn');
    const title = (document.getElementById('addChapTitleInput').value || '').trim();
    const errEl = document.getElementById('addChapErr');
    errEl.style.display = 'none';

    if (!title)           { errEl.textContent = 'Le titre est requis.';               errEl.style.display = ''; return; }
    if (title.length > 255) { errEl.textContent = 'Titre trop long (255 max).';       errEl.style.display = ''; return; }

    btn.disabled    = true;
    btn.textContent = '…';

    const body = new FormData();
    body.append('course_id',     currentDetailCourseId);
    body.append('title',         title);
    body.append('academic_year', document.getElementById('yearFilter').value);

    fetch('?ajax=add_chapter', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            btn.disabled    = false;
            btn.textContent = '+ Ajouter';
            if (d.error) { errEl.textContent = d.error; errEl.style.display = ''; return; }
            reloadCourseDetail();
            applyFilters();
        })
        .catch(() => {
            btn.disabled    = false;
            btn.textContent = '+ Ajouter';
            errEl.textContent = 'Erreur réseau.';
            errEl.style.display = '';
        });
}

// ═══════════════════════════════════════════════════════════
//  AJOUT SÉANCE (inline)
// ═══════════════════════════════════════════════════════════
function showAddSessionForm(chapterId) {
    document.getElementById('showAddSessBtn_' + chapterId).style.display = 'none';
    document.getElementById('addSessPanel_'   + chapterId).style.display = '';
    const dateEl = document.getElementById('addSessDate_' + chapterId);
    if (dateEl && !dateEl.value) dateEl.value = new Date().toISOString().slice(0, 10);
    const startEl = document.getElementById('addSessStart_' + chapterId);
    if (startEl) startEl.focus();
}

function cancelAddSession(chapterId) {
    document.getElementById('showAddSessBtn_'      + chapterId).style.display = '';
    document.getElementById('addSessPanel_'        + chapterId).style.display = 'none';
    document.getElementById('addSessStart_'        + chapterId).value = '';
    document.getElementById('addSessEnd_'          + chapterId).value = '';
    document.getElementById('addSessTitle_'        + chapterId).value = '';
    document.getElementById('addSessDesc_'         + chapterId).value = '';
    document.getElementById('addSessHoursDisplay_' + chapterId).textContent = 'Durée : —';
    const errEl = document.getElementById('addSessErr_' + chapterId);
    if (errEl) errEl.style.display = 'none';
}

function calcAddSessHours(chapterId) {
    const start = document.getElementById('addSessStart_' + chapterId).value;
    const end   = document.getElementById('addSessEnd_'   + chapterId).value;
    const el    = document.getElementById('addSessHoursDisplay_' + chapterId);
    if (start && end && end > start) {
        const [sh, sm] = start.split(':').map(Number);
        const [eh, em] = end.split(':').map(Number);
        const h = ((eh * 60 + em) - (sh * 60 + sm)) / 60;
        el.textContent = 'Durée : ' + h.toFixed(1).replace('.0', '') + 'h';
    } else {
        el.textContent = 'Durée : —';
    }
}

function submitAddSession(chapterId) {
    const btn   = document.getElementById('addSessSubmitBtn_' + chapterId);
    const errEl = document.getElementById('addSessErr_' + chapterId);
    errEl.style.display = 'none';

    const date  = document.getElementById('addSessDate_'  + chapterId).value;
    const start = document.getElementById('addSessStart_' + chapterId).value;
    const end   = document.getElementById('addSessEnd_'   + chapterId).value;
    const title = (document.getElementById('addSessTitle_' + chapterId).value || '').trim();
    const desc  = (document.getElementById('addSessDesc_'  + chapterId).value || '').trim();

    if (!date)          { errEl.textContent = 'La date est requise.';                           errEl.style.display = ''; return; }
    if (!start || !end) { errEl.textContent = 'Les heures de début et fin sont requises.';      errEl.style.display = ''; return; }
    if (end <= start)   { errEl.textContent = "L'heure de fin doit être après l'heure de début."; errEl.style.display = ''; return; }
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    const hours = ((eh * 60 + em) - (sh * 60 + sm)) / 60;
    if (hours < 0.5 || hours > 8) { errEl.textContent = 'La durée doit être entre 0h30 et 8h.'; errEl.style.display = ''; return; }

    btn.disabled    = true;
    btn.textContent = '…';

    const body = new FormData();
    body.append('course_id',     currentDetailCourseId);
    body.append('chapter_id',    chapterId);
    body.append('title',         title);
    body.append('description',   desc);
    body.append('session_date',  date);
    body.append('start_time',    start);
    body.append('end_time',      end);
    body.append('academic_year', document.getElementById('yearFilter').value);

    fetch('?ajax=add_session', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            btn.disabled    = false;
            btn.textContent = '+ Ajouter';
            if (d.error) { errEl.textContent = d.error; errEl.style.display = ''; return; }
            reloadCourseDetail();
            applyFilters();
        })
        .catch(() => {
            btn.disabled    = false;
            btn.textContent = '+ Ajouter';
            errEl.textContent = 'Erreur réseau.';
            errEl.style.display = '';
        });
}
</script>
</body>
</html>