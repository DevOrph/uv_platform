<?php
ob_start();
ini_set('display_errors', 0);
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/semester_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$sql_user = "SELECT name FROM users WHERE id = '" . $conn->real_escape_string($user_id) . "'";
$admin_name = $conn->query($sql_user)->fetch_assoc()['name'] ?? 'Admin';

$current_period   = get_current_period($conn);
$current_semester = $current_period['semester'] ?? 1;
$semester_label   = $current_semester === 2 ? 'Semestre 2' : 'Semestre 1';

// ============================================================
// HANDLER POST — Justification d'absence
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    ob_clean();
    ini_set('display_errors', 0);
    header('Content-Type: application/json');

    try {
        if ($_POST['ajax'] === 'justify_absence') {
            $record_id     = trim($_POST['record_id']     ?? '');
            $justification = trim($_POST['justification'] ?? '');

            if (empty($record_id) || empty($justification)) {
                echo json_encode(['error' => 'Données manquantes']);
                exit();
            }

            if (!preg_match('/^[0-9a-f\-]{36}$/i', $record_id)) {
                echo json_encode(['error' => 'ID invalide']);
                exit();
            }
            $record_id     = $conn->real_escape_string($record_id);
            $justification = $conn->real_escape_string($justification);

            error_log("JUSTIFY: record_id=" . $record_id);
            error_log("JUSTIFY: admin_id=" . $user_id);
            error_log("JUSTIFY: motif=" . substr($justification, 0, 50));

            // Vérifier que le record existe, appartient à l'institution et est bien absent
            $check = $conn->query("
                SELECT ar.id FROM attendance_records ar
                JOIN attendance_sessions att_s ON att_s.id = ar.session_id
                JOIN classes cl ON cl.id = att_s.class_id
                WHERE ar.id = '$record_id' AND ar.status IN ('absent', 'justified')
                LIMIT 1
            ");

            if (!$check || $check->num_rows === 0) {
                echo json_encode(['error' => 'Enregistrement introuvable ou déjà traité']);
                exit();
            }

            $updated_by = intval($user_id);
            $result = $conn->query("
                UPDATE attendance_records
                SET status        = 'justified',
                    justification = '$justification',
                    updated_by    = $updated_by,
                    updated_at    = NOW()
                WHERE id = '$record_id' AND status IN ('absent', 'justified')
            ");

            error_log("JUSTIFY: affected_rows=" . $conn->affected_rows);
            error_log("JUSTIFY: last_error=" . $conn->error);

            if ($result === false) {
                echo json_encode(['error' => 'Erreur SQL : ' . $conn->error]);
                exit();
            }

            if ($conn->affected_rows > 0) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Aucune ligne modifiée']);
            }
        } elseif ($_POST['ajax'] === 'edit_status') {
            $record_id     = trim($_POST['record_id']  ?? '');
            $new_status    = trim($_POST['new_status'] ?? '');
            $justification = trim($_POST['justification'] ?? '');

            $allowed = ['present', 'absent', 'late', 'justified'];
            if (empty($record_id) || !in_array($new_status, $allowed, true)) {
                echo json_encode(['error' => 'Données invalides']);
                exit();
            }
            if ($new_status === 'justified' && empty($justification)) {
                echo json_encode(['error' => 'Motif obligatoire pour une absence justifiée']);
                exit();
            }
            if (!preg_match('/^[0-9a-f\-]{36}$/i', $record_id)) {
                echo json_encode(['error' => 'ID invalide']);
                exit();
            }

            $record_id = $conn->real_escape_string($record_id);

            // Récupérer l'ancien statut pour le log d'audit
            $check = $conn->query("
                SELECT ar.status FROM attendance_records ar
                JOIN attendance_sessions att_s ON att_s.id = ar.session_id
                WHERE ar.id = '$record_id'
                LIMIT 1
            ");
            if (!$check || $check->num_rows === 0) {
                echo json_encode(['error' => 'Enregistrement introuvable']);
                exit();
            }
            $old_status = $check->fetch_assoc()['status'];

            $updated_by      = intval($user_id);
            $new_status_safe = $conn->real_escape_string($new_status);
            $just_sql        = ($new_status === 'justified')
                               ? "'" . $conn->real_escape_string($justification) . "'"
                               : 'NULL';

            $result = $conn->query("
                UPDATE attendance_records
                SET status        = '$new_status_safe',
                    justification = $just_sql,
                    updated_by    = $updated_by,
                    updated_at    = NOW()
                WHERE id = '$record_id'
            ");

            if ($result === false) {
                echo json_encode(['error' => 'Erreur SQL : ' . $conn->error]);
                exit();
            }

            // Log dans audit_log
            $old_val = $conn->real_escape_string(json_encode(['status' => $old_status]));
            $new_val = $conn->real_escape_string(json_encode(['status' => $new_status]));
            $ip      = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
            $ua      = $conn->real_escape_string(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));
            $desc    = $conn->real_escape_string("Modification statut présence : $old_status → $new_status");
            $conn->query("
                INSERT INTO audit_log
                    (action_type, entity_type, entity_id, description, old_value, new_value, performed_by, ip_address, user_agent)
                VALUES
                    ('UPDATE', 'attendance_record', '$record_id', '$desc', '$old_val', '$new_val', '$updated_by', '$ip', '$ua')
            ");

            echo json_encode(['success' => true]);
        } elseif ($_POST['ajax'] === 'delete_attendance_session') {
            $session_id = trim($_POST['session_id'] ?? '');

            if (!preg_match('/^[0-9a-f\-]{36}$/i', $session_id)) {
                echo json_encode(['error' => 'ID de séance invalide']);
                exit();
            }
            $session_id = $conn->real_escape_string($session_id);

            // Vérifier que la séance existe et appartient à une classe valide
            $check = $conn->query("
                SELECT att_s.id, c.name AS course_name, att_s.session_date
                FROM attendance_sessions att_s
                JOIN courses c  ON c.id  = att_s.course_id
                JOIN classes cl ON cl.id = att_s.class_id
                WHERE att_s.id = '$session_id'
                LIMIT 1
            ");

            if (!$check || $check->num_rows === 0) {
                echo json_encode(['error' => 'Séance introuvable']);
                exit();
            }
            $session_info = $check->fetch_assoc();

            // Compter les enregistrements avant suppression
            $count_res  = $conn->query("SELECT COUNT(*) AS n FROM attendance_records WHERE session_id = '$session_id'");
            $nb_records = $count_res ? intval($count_res->fetch_assoc()['n']) : 0;

            // Délier la course_session si liée
            $conn->query("UPDATE course_sessions SET attendance_session_id = NULL WHERE attendance_session_id = '$session_id'");

            // Supprimer les enregistrements de présence
            $conn->query("DELETE FROM attendance_records WHERE session_id = '$session_id'");

            // Supprimer la séance
            $result = $conn->query("DELETE FROM attendance_sessions WHERE id = '$session_id'");

            if ($result === false) {
                echo json_encode(['error' => 'Erreur SQL : ' . $conn->error]);
                exit();
            }

            // Logger dans audit_log
            $updated_by  = intval($user_id);
            $ip          = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
            $ua          = $conn->real_escape_string(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));
            $desc_raw    = "Suppression séance : " . $session_info['course_name'] . " — " . $session_info['session_date'] . " ($nb_records enreg.)";
            $desc        = $conn->real_escape_string($desc_raw);
            $old_val     = $conn->real_escape_string(json_encode([
                'session_id'      => $session_id,
                'course'          => $session_info['course_name'],
                'date'            => $session_info['session_date'],
                'records_deleted' => $nb_records,
            ]));
            $conn->query("
                INSERT INTO audit_log
                    (action_type, entity_type, entity_id, description, old_value, new_value, performed_by, ip_address, user_agent)
                VALUES
                    ('DELETE', 'attendance_session', '$session_id', '$desc', '$old_val', NULL, '$updated_by', '$ip', '$ua')
            ");

            echo json_encode(['success' => true, 'records_deleted' => $nb_records]);
        } else {
            echo json_encode(['error' => 'Action inconnue']);
        }
    } catch (\Throwable $e) {
        error_log("JUSTIFY: throwable=" . $e->getMessage() . " line=" . $e->getLine());
        echo json_encode(['error' => 'Erreur serveur']);
    }
    exit();
}

// ============================================================
// HANDLER AJAX
// ============================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];

    // ── Liste des classes ─────────────────────────────────
    if ($action === 'get_classes') {
        $res     = $conn->query("SELECT id, name FROM classes ORDER BY name ASC");
        $classes = [];
        while ($row = $res->fetch_assoc()) $classes[] = $row;
        echo json_encode($classes);
        exit();
    }

    // ── Liste des cours ───────────────────────────────────
    if ($action === 'get_courses') {
        $class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
        $sql = "SELECT c.id, c.name, u.name AS teacher_name, c.class_id AS class_ids_json
                FROM courses c LEFT JOIN users u ON u.id = c.teacher_id ORDER BY c.name ASC";
        $res     = $conn->query($sql);
        $courses = [];
        while ($row = $res->fetch_assoc()) {
            if ($class_id > 0) {
                $ids = json_decode($row['class_ids_json'], true) ?? [];
                if (!in_array((string)$class_id, array_map('strval', $ids))) continue;
            }
            $courses[] = ['id' => $row['id'], 'name' => $row['name'], 'teacher' => $row['teacher_name']];
        }
        echo json_encode($courses);
        exit();
    }

    // ── Liste des étudiants ───────────────────────────────
    if ($action === 'get_students') {
        $class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
        $sql = "SELECT id, name FROM users WHERE role='student'" .
               ($class_id > 0 ? " AND class_id = $class_id" : "") .
               " ORDER BY name ASC";
        $res      = $conn->query($sql);
        $students = [];
        while ($row = $res->fetch_assoc()) $students[] = $row;
        echo json_encode($students);
        exit();
    }

    // ── KPIs globaux ──────────────────────────────────────
    if ($action === 'get_kpis') {
        $kpi_ay = (isset($_GET['academic_year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['academic_year']))
                  ? $conn->real_escape_string($_GET['academic_year'])
                  : ANNEE_ACADEMIQUE_COURANTE;
        $kpi_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
        $kpis = [];

        $r = $conn->query("SELECT COUNT(*) AS n FROM attendance_sessions
                           WHERE academic_year = '$kpi_ay' OR academic_year IS NULL");
        $kpis['total_sessions'] = $r->fetch_assoc()['n'];

        $class_filter_kpi = $kpi_class_id > 0
            ? "AND JSON_CONTAINS(c.class_id, CAST($kpi_class_id AS JSON))"
            : '';
        $r = $conn->query("
            SELECT COUNT(*) AS n
            FROM course_sessions cs
            JOIN course_chapters cc ON cc.id = cs.chapter_id
            JOIN courses c ON c.id = cs.course_id
            WHERE cs.session_date < CURDATE()
              AND cs.session_date IS NOT NULL
              AND cs.attendance_session_id IS NULL
              AND cs.academic_year = '$kpi_ay'
              $class_filter_kpi
        ");
        $kpis['sessions_no_records'] = ($r ? $r->fetch_assoc()['n'] : 0);

        $r = $conn->query("SELECT COUNT(*) AS n FROM attendance_records ar
                           JOIN attendance_sessions att_s ON att_s.id = ar.session_id
                           WHERE att_s.academic_year = '$kpi_ay' OR att_s.academic_year IS NULL");
        $kpis['total_records'] = $r->fetch_assoc()['n'];

        $r = $conn->query("SELECT COUNT(*) AS n FROM attendance_records ar
                           JOIN attendance_sessions att_s ON att_s.id = ar.session_id
                           WHERE ar.status = 'present'
                             AND (att_s.academic_year = '$kpi_ay' OR att_s.academic_year IS NULL)");
        $kpis['total_present'] = $r->fetch_assoc()['n'];

        $r = $conn->query("SELECT COUNT(*) AS n FROM attendance_records ar
                           JOIN attendance_sessions att_s ON att_s.id = ar.session_id
                           WHERE ar.status = 'absent'
                             AND (att_s.academic_year = '$kpi_ay' OR att_s.academic_year IS NULL)");
        $kpis['total_absent'] = $r->fetch_assoc()['n'];

        $r = $conn->query("SELECT COUNT(*) AS n FROM attendance_records ar
                           JOIN attendance_sessions att_s ON att_s.id = ar.session_id
                           WHERE ar.status = 'late'
                             AND (att_s.academic_year = '$kpi_ay' OR att_s.academic_year IS NULL)");
        $kpis['total_late'] = $r->fetch_assoc()['n'];

        $r = $conn->query("SELECT COUNT(*) AS n FROM attendance_records ar
                           JOIN attendance_sessions att_s ON att_s.id = ar.session_id
                           WHERE ar.status = 'justified'
                             AND (att_s.academic_year = '$kpi_ay' OR att_s.academic_year IS NULL)");
        $kpis['total_justified'] = $r->fetch_assoc()['n'];

        // Formule cohérente : (présent + retard + justifié) / total enregistrements
        $kpis['global_rate'] = $kpis['total_records'] > 0
            ? round((($kpis['total_present'] + $kpis['total_late'] + $kpis['total_justified']) / $kpis['total_records']) * 100) : 0;

        // Top 5 absentéisme pour l'année académique
        $r = $conn->query("
            SELECT u.name, COUNT(*) AS absences
            FROM attendance_records ar
            JOIN users u ON u.id = ar.student_id
            JOIN attendance_sessions att_s ON att_s.id = ar.session_id
            WHERE ar.status = 'absent'
              AND (att_s.academic_year = '$kpi_ay' OR att_s.academic_year IS NULL)
            GROUP BY ar.student_id ORDER BY absences DESC LIMIT 5
        ");
        $kpis['top_absent'] = [];
        while ($row = $r->fetch_assoc()) $kpis['top_absent'][] = $row;

        // Évolution 30 derniers jours pour l'année académique
        $r = $conn->query("
            SELECT att_s.session_date,
                   SUM(ar.status='present')   AS present,
                   SUM(ar.status='absent')    AS absent,
                   COUNT(*) AS total
            FROM attendance_sessions att_s
            JOIN attendance_records ar ON ar.session_id = att_s.id
            WHERE att_s.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND (att_s.academic_year = '$kpi_ay' OR att_s.academic_year IS NULL)
            GROUP BY att_s.session_date ORDER BY att_s.session_date ASC
        ");
        $kpis['evolution'] = [];
        while ($row = $r->fetch_assoc()) $kpis['evolution'][] = $row;

        echo json_encode($kpis);
        exit();
    }

    // ── Tableau principal ─────────────────────────────────
    if ($action === 'get_attendance') {
        $course_id    = isset($_GET['course_id'])    ? intval($_GET['course_id'])                         : 0;
        $class_id     = isset($_GET['class_id'])     ? intval($_GET['class_id'])                          : 0;
        $student_id   = isset($_GET['student_id'])   ? $conn->real_escape_string($_GET['student_id'])     : '';
        $date_from    = isset($_GET['date_from'])    ? $conn->real_escape_string($_GET['date_from'])      : '';
        $date_to      = isset($_GET['date_to'])      ? $conn->real_escape_string($_GET['date_to'])        : '';
        $filter_ay    = (isset($_GET['academic_year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['academic_year']))
                        ? $conn->real_escape_string($_GET['academic_year'])
                        : ANNEE_ACADEMIQUE_COURANTE;

        $per_page = 100;
        $page     = max(1, intval($_GET['page'] ?? 1));

        $status_allowed = ['present', 'absent', 'late', 'justified'];
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';
        if (!in_array($status, $status_allowed, true)) $status = '';

        $where = ["1=1"];
        if ($course_id  > 0)   $where[] = "att_s.course_id = $course_id";
        if ($class_id   > 0)   $where[] = "att_s.class_id  = $class_id";
        if ($student_id !== '') $where[] = "ar.student_id   = '$student_id'";
        if ($date_from  !== '') $where[] = "att_s.session_date >= '$date_from'";
        if ($date_to    !== '') $where[] = "att_s.session_date <= '$date_to'";
        if ($status     !== '') $where[] = "ar.status = '$status'";
        // Filtre par année académique (NULL = données migrées sans année, rattachées à l'année courante)
        $where[] = "(att_s.academic_year = '$filter_ay' OR att_s.academic_year IS NULL)";
        $where_sql = implode(' AND ', $where);

        $base_from = "
            FROM attendance_sessions att_s
            JOIN courses c               ON c.id  = att_s.course_id
            LEFT JOIN classes cl         ON cl.id = att_s.class_id
            LEFT JOIN users u_teacher    ON u_teacher.id = att_s.teacher_id
            LEFT JOIN time_slots ts      ON ts.id = att_s.time_slot_id
            LEFT JOIN attendance_records ar      ON ar.session_id = att_s.id
            LEFT JOIN users u_student    ON u_student.id = ar.student_id
            WHERE $where_sql
        ";

        $count_res  = $conn->query("SELECT COUNT(*) AS total $base_from");
        $total      = $count_res ? intval($count_res->fetch_assoc()['total']) : 0;
        $total_pages = max(1, (int)ceil($total / $per_page));
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql = "
            SELECT
                att_s.id           AS session_id,
                att_s.course_id    AS course_id,
                att_s.session_date,
                c.name             AS course_name,
                COALESCE(cl.name, '[Classe supprimée]')           AS class_name,
                COALESCE(u_teacher.name, '[Enseignant supprimé]') AS teacher_name,
                ts.start_time,
                ts.end_time,
                att_s.duration     AS session_duration,
                u_student.id       AS student_id,
                u_student.name     AS student_name,
                ar.id              AS record_id,
                ar.status,
                ar.justification,
                ar.marked_at
            $base_from
            ORDER BY att_s.session_date DESC, cl.name ASC, u_student.name ASC
            LIMIT $per_page OFFSET $offset
        ";

        $res  = $conn->query($sql);
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }

        $from = $total > 0 ? $offset + 1 : 0;
        $to   = min($page * $per_page, $total);

        echo json_encode([
            'rows' => $rows,
            'pagination' => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => $total_pages,
                'from'        => $from,
                'to'          => $to,
            ],
        ]);
        exit();
    }

    // ── Export Excel ─────────────────────────────────────
    if ($action === 'export_excel') {
        while (ob_get_level()) ob_end_clean();
        require_once '../vendor/autoload.php';

        $course_id  = isset($_GET['course_id'])  ? intval($_GET['course_id'])                     : 0;
        $class_id   = isset($_GET['class_id'])   ? intval($_GET['class_id'])                      : 0;
        $student_id = isset($_GET['student_id']) ? $conn->real_escape_string($_GET['student_id']) : '';
        $date_from  = isset($_GET['date_from'])  ? $conn->real_escape_string($_GET['date_from'])  : '';
        $date_to    = isset($_GET['date_to'])    ? $conn->real_escape_string($_GET['date_to'])    : '';
        $filter_ay  = (isset($_GET['academic_year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['academic_year']))
                      ? $conn->real_escape_string($_GET['academic_year'])
                      : ANNEE_ACADEMIQUE_COURANTE;

        $where = ["1=1"];
        if ($course_id  > 0)   $where[] = "att_s.course_id = $course_id";
        if ($class_id   > 0)   $where[] = "att_s.class_id  = $class_id";
        if ($student_id !== '') $where[] = "ar.student_id   = '$student_id'";
        if ($date_from  !== '') $where[] = "att_s.session_date >= '$date_from'";
        if ($date_to    !== '') $where[] = "att_s.session_date <= '$date_to'";
        $where[] = "(att_s.academic_year = '$filter_ay' OR att_s.academic_year IS NULL)";
        $where_sql = implode(' AND ', $where);

        $sql = "
            SELECT
                att_s.session_date,
                c.name             AS course_name,
                cl.name            AS class_name,
                u_teacher.name     AS teacher_name,
                ts.start_time,
                ts.end_time,
                att_s.duration     AS session_duration,
                u_student.id       AS student_id,
                u_student.name     AS student_name,
                ar.status,
                ar.justification,
                COALESCE(ar.updated_at, ar.marked_at) AS modified_at
            FROM attendance_sessions att_s
            JOIN courses c          ON c.id  = att_s.course_id
            JOIN classes cl         ON cl.id = att_s.class_id
            JOIN users u_teacher    ON u_teacher.id = att_s.teacher_id
            LEFT JOIN time_slots ts ON ts.id = att_s.time_slot_id
            JOIN attendance_records ar   ON ar.session_id = att_s.id
            JOIN users u_student         ON u_student.id  = ar.student_id
            WHERE $where_sql
            ORDER BY att_s.session_date DESC, cl.name ASC, u_student.name ASC
        ";
        $res = $conn->query($sql);

        // — Classe : nom affiché + slug pour le filename
        $class_display = '';
        $class_slug    = '';
        if ($class_id > 0) {
            $cr = $conn->query("SELECT name FROM classes WHERE id = $class_id LIMIT 1");
            if ($cr && $rc = $cr->fetch_assoc()) {
                $class_display = $rc['name'];
                $class_slug    = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $rc['name']);
            }
        }

        // — Cours : nom pour résumé filtres
        $course_display = '';
        if ($course_id > 0) {
            $cr2 = $conn->query("SELECT name FROM courses WHERE id = $course_id LIMIT 1");
            if ($cr2 && $rc2 = $cr2->fetch_assoc()) $course_display = $rc2['name'];
        }

        // — Filename
        if ($class_slug) {
            $fn_from  = $date_from ?: date('Y-m-d');
            $fn_to    = $date_to   ?: date('Y-m-d');
            $filename = "presences_{$class_slug}_{$fn_from}_{$fn_to}.xlsx";
        } else {
            $filename = 'presences_toutes_classes_' . date('Y-m-d') . '.xlsx';
        }

        // — Résumé des filtres (ligne 3)
        $fparts = ["Année : $filter_ay"];
        if ($class_display)  $fparts[] = "Classe : $class_display";
        if ($course_display) $fparts[] = "Cours : $course_display";
        if ($date_from)      $fparts[] = "Du : $date_from";
        if ($date_to)        $fparts[] = "Au : $date_to";
        $filter_summary = implode(' | ', $fparts);

        // — Création du classeur
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('UV Platform')->setTitle('Présences — UV');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Présences');

        // — Lignes de titre (1–3)
        $sheet->setCellValue('A1', 'Gestion des Présences — UV');
        $sheet->setCellValue('A2', 'Exporté le : ' . date('d/m/Y à H:i'));
        $sheet->setCellValue('A3', 'Filtres : ' . $filter_summary);
        // Ligne 4 vide — ligne 5 : en-têtes colonnes

        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF051E34']],
        ]);
        $sheet->getStyle('A2:A3')->applyFromArray([
            'font' => ['italic' => true, 'color' => ['argb' => 'FF7F8C8D']],
        ]);

        // — En-têtes colonnes (ligne 5)
        $headers = ['Date','Cours','Classe','Enseignant','Horaire','Étudiant','Matricule','Statut','Justification','Modifié le'];
        foreach ($headers as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue($col . '5', $h);
        }
        $sheet->getStyle('A5:J5')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                            'startColor' => ['argb' => 'FF1A3A5C']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                            'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                             'color'       => ['argb' => 'FFBDC3C7']]],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(22);

        // — Largeurs colonnes (A–J)
        $colWidths = [14, 30, 18, 25, 14, 28, 25, 14, 38, 20];
        foreach ($colWidths as $i => $w) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // — Données à partir de la ligne 6
        $statusColors = [
            'absent'    => 'FFFFCCCC',
            'justified' => 'FFFFE5CC',
            'late'      => 'FFFFF3CC',
            'present'   => 'FFCCFFCC',
        ];
        $statusLabels = [
            'present'   => 'Présent',
            'absent'    => 'Absent',
            'late'      => 'Retard',
            'justified' => 'Justifié',
        ];

        $rowNum = 6;
        $rowIdx = 0;
        while ($row = $res->fetch_assoc()) {
            $horaire = ($row['start_time'] && $row['end_time'])
                ? substr($row['start_time'], 0, 5) . ' – ' . substr($row['end_time'], 0, 5)
                : ($row['session_duration'] ? 'Durée : ' . $row['session_duration'] . 'h' : '–');

            $status       = $row['status'] ?? '';
            $statusLabel  = $statusLabels[$status] ?? $status;
            $dateFormatted = $row['session_date'] ? date('d/m/Y', strtotime($row['session_date'])) : '–';
            $modifiedAt    = $row['modified_at']  ? date('d/m/Y H:i', strtotime($row['modified_at'])) : '–';

            $sheet->setCellValue('A' . $rowNum, $dateFormatted);
            $sheet->setCellValue('B' . $rowNum, $row['course_name']   ?? '');
            $sheet->setCellValue('C' . $rowNum, $row['class_name']    ?? '');
            $sheet->setCellValue('D' . $rowNum, $row['teacher_name']  ?? '');
            $sheet->setCellValue('E' . $rowNum, $horaire);
            $sheet->setCellValue('F' . $rowNum, $row['student_name']  ?? '');
            $sheet->setCellValue('G' . $rowNum, $row['student_id']    ?? '');
            $sheet->setCellValue('H' . $rowNum, $statusLabel);
            $sheet->setCellValue('I' . $rowNum, $row['justification'] ?? '');
            $sheet->setCellValue('J' . $rowNum, $modifiedAt);

            $bgColor = $statusColors[$status] ?? ($rowIdx % 2 === 0 ? 'FFFFFFFF' : 'FFF8F9F9');
            $sheet->getStyle("A{$rowNum}:J{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                                'startColor' => ['argb' => $bgColor]],
                'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                                                 'color'       => ['argb' => 'FFE0E0E0']]],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(18);

            $rowNum++;
            $rowIdx++;
        }

        // — Auto-filtre + Freeze pane
        if ($rowNum > 6) {
            $sheet->setAutoFilter('A5:J' . ($rowNum - 1));
        }
        $sheet->freezePane('A6');

        // — Sortie
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit();
    }

    echo json_encode(['error' => 'Action inconnue']);
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Présences - UV Platform</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary-bg:   #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light:   #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
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
            position: relative;
        }

        header::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(to right, #039be5, #4CAF50, #039be5);
            animation: shimmer 2s infinite linear;
        }

        @keyframes shimmer {
            0%   { background-position: -200% center; }
            100% { background-position:  200% center; }
        }

        /* ── Container ── */
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            flex: 1;
        }

        /* ── Page Header ── */
        .page-header {
            margin-bottom: 30px;
            padding: 20px;
            background: var(--secondary-bg);
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid var(--border-color);
        }

        .page-header i { font-size: 24px; color: var(--accent-color); }

        .page-header h1 { font-size: 22px; font-weight: 600; }

        /* ── Stats Grid (KPI cards) ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease;
        }

        .stat-card:hover { transform: translateY(-5px); }

        .stat-card i { font-size: 32px; color: var(--accent-color); margin-bottom: 10px; }

        .stat-card h3 { margin: 10px 0; color: #ffffff; font-size: 14px; }

        .stat-card p { font-size: 28px; font-weight: bold; margin: 0; color: var(--accent-color); }

        /* ── Charts Grid ── */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            height: 320px;
            position: relative;
        }

        .chart-container h3 {
            font-size: 14px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Filters Card ── */
        .filters-card {
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }

        .filters-card h3 {
            font-size: 14px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filters-row {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-group { flex: 1; min-width: 160px; }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255,255,255,0.7);
            font-size: 13px;
        }

        .filter-group select,
        .filter-group input[type="date"] {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            background: rgba(255,255,255,0.1);
            border: 1px solid var(--border-color);
            color: #ffffff;
            font-family: 'Google Sans', Arial, sans-serif;
            font-size: 13px;
            outline: none;
            transition: border-color 0.2s;
        }

        .filter-group select:focus,
        .filter-group input[type="date"]:focus { border-color: var(--accent-color); }

        .filter-group select option { background: #0c2d48; color: #fff; }

        /* ── Buttons ── */
        .btn-primary {
            background: var(--accent-color);
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Google Sans', Arial, sans-serif;
            font-size: 14px;
            transition: background 0.3s;
            white-space: nowrap;
        }

        .btn-primary:hover { background: #0288d1; }

        .btn-export {
            background: rgba(255,255,255,0.1);
            color: #ffffff;
            border: 1px solid var(--border-color);
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: 'Google Sans', Arial, sans-serif;
            font-size: 14px;
            transition: background 0.3s;
            white-space: nowrap;
        }

        .btn-export:hover { background: rgba(255,255,255,0.18); }

        .filters-actions { display: flex; gap: 10px; align-items: flex-end; }

        /* ── Table Card ── */
        .grade-table {
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }

        .table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .table-toolbar h3 {
            font-size: 14px;
            color: rgba(255,255,255,0.7);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .table-toolbar-right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

        /* Tabs statut */
        .status-tabs { display: flex; gap: 6px; flex-wrap: wrap; }

        .status-tab {
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid var(--border-color);
            cursor: pointer;
            background: rgba(255,255,255,0.06);
            color: rgba(255,255,255,0.6);
            transition: all 0.2s;
        }

        .status-tab.active,
        .status-tab[data-s="all"].active       { background: rgba(3,155,229,0.2);  border-color: #039be5; color: #039be5; }
        .status-tab[data-s="present"].active   { background: rgba(46,204,113,0.2); border-color: #2ecc71; color: #2ecc71; }
        .status-tab[data-s="absent"].active    { background: rgba(231,76,60,0.2);  border-color: #e74c3c; color: #e74c3c; }
        .status-tab[data-s="late"].active      { background: rgba(241,196,15,0.2); border-color: #f1c40f; color: #f1c40f; }
        .status-tab[data-s="justified"].active { background: rgba(243,156,18,0.2);  border-color: #f39c12; color: #f39c12; }

        /* Recherche */
        .search-wrap { position: relative; display: flex; align-items: center; }
        .search-wrap i { position: absolute; left: 10px; color: rgba(255,255,255,0.4); font-size: 13px; }

        .search-input {
            background: rgba(255,255,255,0.1);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            padding: 8px 12px 8px 32px;
            color: #fff;
            font-family: 'Google Sans', Arial, sans-serif;
            font-size: 13px;
            width: 200px;
            outline: none;
            transition: border-color 0.2s;
        }

        .search-input:focus { border-color: var(--accent-color); }
        .search-input::placeholder { color: rgba(255,255,255,0.3); }

        /* Table */
        .table-scroll { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; }

        th {
            background: rgba(255,255,255,0.1);
            padding: 12px;
            text-align: left;
            color: rgba(255,255,255,0.7);
            font-size: 13px;
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }

        th:hover { color: #fff; }
        th.sort-asc::after  { content: ' ↑'; color: var(--accent-color); }
        th.sort-desc::after { content: ' ↓'; color: var(--accent-color); }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            color: #ffffff;
            font-size: 13px;
            white-space: nowrap;
        }

        tbody tr:hover { background: rgba(255,255,255,0.04); }
        tbody tr:last-child td { border-bottom: none; }

        /* Pills statut */
        .grade-value {
            font-weight: bold;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-block;
            min-width: 70px;
            text-align: center;
            font-size: 12px;
        }

        .pill-present   { background: rgba(46,204,113,0.2);  color: #2ecc71; }
        .pill-absent    { background: rgba(231,76,60,0.2);   color: #e74c3c; }
        .pill-late      { background: rgba(241,196,15,0.2);  color: #f1c40f; }
        .pill-justified { background: rgba(243,156,18,0.2);  color: #f39c12; }
        .pill-no-record { background: rgba(243,156,18,0.12); color: #f39c12; border: 1px dashed rgba(243,156,18,0.5); }

        .btn-justify {
            background: rgba(243,156,18,0.15);
            color: #f39c12;
            border: 1px solid rgba(243,156,18,0.4);
            padding: 4px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            font-family: 'Google Sans', Arial, sans-serif;
            transition: background 0.2s;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-justify:hover { background: rgba(243,156,18,0.3); }

        .btn-edit {
            background: rgba(3,155,229,0.15);
            color: #039be5;
            border: 1px solid rgba(3,155,229,0.4);
            padding: 4px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            font-family: 'Google Sans', Arial, sans-serif;
            transition: background 0.2s;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-edit:hover { background: rgba(3,155,229,0.3); }

        .btn-delete-session {
            background: rgba(231,76,60,0.15);
            color: #e74c3c;
            border: 1px solid rgba(231,76,60,0.4);
            padding: 4px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            font-family: 'Google Sans', Arial, sans-serif;
            transition: background 0.2s;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn-delete-session:hover { background: rgba(231,76,60,0.3); }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 6px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .page-btn {
            padding: 6px 12px;
            border-radius: 5px;
            background: rgba(255,255,255,0.1);
            border: 1px solid var(--border-color);
            color: rgba(255,255,255,0.7);
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .page-btn:hover,
        .page-btn.active { background: rgba(3,155,229,0.2); border-color: var(--accent-color); color: var(--accent-color); }
        .page-btn:disabled { opacity: 0.3; cursor: not-allowed; }

        /* Résultat count */
        .result-count { font-size: 13px; color: rgba(255,255,255,0.5); }
        .result-count strong { color: var(--accent-color); }

        /* Top absentéisme inline dans chart-container */
        .absent-list { display: flex; flex-direction: column; gap: 12px; padding-top: 4px; }
        .absent-row  { display: flex; align-items: center; gap: 10px; }
        .absent-rank { font-size: 12px; color: rgba(255,255,255,0.3); min-width: 18px; }
        .absent-name { flex:1; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .absent-bar-wrap { width: 80px; height: 5px; background: rgba(255,255,255,0.08); border-radius: 99px; overflow: hidden; }
        .absent-bar-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #c0392b, #e74c3c); }
        .absent-count { font-size: 12px; color: #e74c3c; min-width: 24px; text-align: right; }

        /* Empty / loading */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: rgba(255,255,255,0.5);
        }
        .empty-state i { font-size: 48px; color: var(--accent-color); margin-bottom: 16px; display: block; }

        .loading-spinner {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            gap: 12px;
            color: rgba(255,255,255,0.4);
        }

        .spinner {
            width: 28px; height: 28px;
            border: 2px solid rgba(3,155,229,0.2);
            border-top-color: var(--accent-color);
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            z-index: 9000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .modal-overlay.show { display: flex; }

        .modal-box {
            background: #0c2d48;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            width: 90%;
            max-width: 520px;
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: fadeIn 0.25s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            background: rgba(255,255,255,0.05);
        }

        .modal-header h3 { font-size: 16px; color: var(--accent-color); }

        .modal-close {
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.6);
            font-size: 22px;
            cursor: pointer;
            padding: 0 4px;
            transition: color 0.2s;
        }

        .modal-close:hover { color: #e74c3c; }

        .modal-body { padding: 20px; overflow-y: auto; flex: 1; }

        .modal-stats-mini {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }

        .msm-item {
            background: rgba(255,255,255,0.08);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px 8px;
            text-align: center;
        }

        .msm-val   { font-size: 1.5rem; font-weight: bold; }
        .msm-label { font-size: 11px; color: rgba(255,255,255,0.5); margin-top: 4px; text-transform: uppercase; }

        /* Responsive */
        @media (max-width: 768px) {
            .charts-grid  { grid-template-columns: 1fr; }
            .filters-row  { flex-direction: column; }
            .table-toolbar { flex-direction: column; align-items: flex-start; }
            .modal-stats-mini { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<?php include '../includes/header_admin.php'; ?>

<div class="dashboard-container">

    <!-- En-tête de page -->
    <div class="page-header">
        <i class="fas fa-clipboard-list"></i>
        <h1>Gestion des Présences</h1>
        <span style="margin-left:auto;background:rgba(3,155,229,0.15);border:1px solid rgba(3,155,229,0.4);
                     color:#039be5;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;white-space:nowrap;">
            <i class="fas fa-calendar-alt" style="margin-right:6px;"></i>
            <?php echo htmlspecialchars($semester_label . ' — ' . ANNEE_ACADEMIQUE_COURANTE); ?>
        </span>
    </div>

    <!-- KPI Cards -->
    <div class="stats-grid" id="kpiGrid">
        <div class="stat-card">
            <i class="fas fa-calendar-check"></i>
            <h3>Séances enregistrées</h3>
            <p id="kpiSessions">–</p>
        </div>
        <div class="stat-card">
            <i class="fas fa-user-check"></i>
            <h3>Présences totales</h3>
            <p id="kpiPresent">–</p>
        </div>
        <div class="stat-card">
            <i class="fas fa-user-times"></i>
            <h3>Absences totales</h3>
            <p id="kpiAbsent">–</p>
        </div>
        <div class="stat-card">
            <i class="fas fa-chart-line"></i>
            <h3>Taux de présence global</h3>
            <p id="kpiRate">–%</p>
        </div>
        <div class="stat-card">
            <i class="fas fa-database"></i>
            <h3>Entrées totales</h3>
            <p id="kpiRecords">–</p>
        </div>
        <div class="stat-card" id="kpiNoRecordCard">
            <i class="fas fa-exclamation-triangle" id="kpiNoRecordIcon" style="color:#f39c12;"></i>
            <h3>Appels non faits</h3>
            <p id="kpiNoRecord" style="color:#f39c12;">–</p>
        </div>
    </div>

    <!-- Graphiques -->
    <div class="charts-grid">
        <div class="chart-container">
            <h3><i class="fas fa-chart-area" style="color:var(--accent-color);"></i> Évolution sur 30 jours</h3>
            <canvas id="evolutionChart"></canvas>
        </div>
        <div class="chart-container">
            <h3><i class="fas fa-exclamation-triangle" style="color:#e74c3c;"></i> Top 5 absentéisme</h3>
            <div class="absent-list" id="absentList">
                <div class="loading-spinner"><div class="spinner"></div> Chargement…</div>
            </div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="filters-card">
        <h3><i class="fas fa-sliders-h" style="color:var(--accent-color);"></i> Filtres de recherche</h3>
        <div class="filters-row">
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> Année académique</label>
                <select id="fAcademicYear" onchange="loadKPIs()">
                    <option value="<?php echo htmlspecialchars(ANNEE_ACADEMIQUE_COURANTE); ?>" selected>
                        <?php echo htmlspecialchars(ANNEE_ACADEMIQUE_COURANTE); ?> (courante)
                    </option>
                    <?php
                    $ay_res = $conn->query(
                        "SELECT DISTINCT academic_year FROM attendance_sessions
                         WHERE academic_year IS NOT NULL
                         ORDER BY academic_year DESC"
                    );
                    $seen = [ANNEE_ACADEMIQUE_COURANTE];
                    while ($ay = $ay_res->fetch_assoc()) {
                        if (!in_array($ay['academic_year'], $seen)) {
                            $seen[] = $ay['academic_year'];
                            echo '<option value="' . htmlspecialchars($ay['academic_year']) . '">'
                                . htmlspecialchars($ay['academic_year']) . '</option>';
                        }
                    }
                    ?>
                    <option value="">Toutes les années</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-graduation-cap"></i> Classe</label>
                <select id="fClass" onchange="onClassChange()">
                    <option value="">Toutes les classes</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-book"></i> Cours</label>
                <select id="fCourse">
                    <option value="">Tous les cours</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-user"></i> Étudiant</label>
                <select id="fStudent">
                    <option value="">Tous les étudiants</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> Date début</label>
                <input type="date" id="fDateFrom">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> Date fin</label>
                <input type="date" id="fDateTo">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-eye"></i> Afficher</label>
                <select id="fPresence" onchange="applyClientFilters()">
                    <option value="all">Tous</option>
                    <option value="with_records">Avec présences</option>
                    <option value="no_records">Sans présences</option>
                </select>
            </div>
            <div class="filters-actions">
                <button class="btn-primary" onclick="applyFilters()">
                    <i class="fas fa-search"></i> Rechercher
                </button>
                <button class="btn-export" onclick="resetFilters()">
                    <i class="fas fa-times"></i> Reset
                </button>
                <button class="btn-export" onclick="exportExcel()">
                    <i class="fas fa-file-excel"></i> Export Excel
                </button>
            </div>
        </div>
    </div>

    <!-- Tableau des présences -->
    <div class="grade-table" id="attendanceTable">
        <div class="table-toolbar">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <h3><i class="fas fa-list" style="color:var(--accent-color);"></i> Résultats</h3>
                <span class="result-count"><strong id="rowCount">0</strong> entrée(s)</span>
                <div class="status-tabs">
                    <button class="status-tab active" data-s="all"        onclick="setStatusFilter('all')">Tout</button>
                    <button class="status-tab"         data-s="present"   onclick="setStatusFilter('present')">Présent</button>
                    <button class="status-tab"         data-s="absent"    onclick="setStatusFilter('absent')">Absent</button>
                    <button class="status-tab"         data-s="late"      onclick="setStatusFilter('late')">Retard</button>
                    <button class="status-tab"         data-s="justified" onclick="setStatusFilter('justified')">Justifié</button>
                </div>
            </div>
            <div class="table-toolbar-right">
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" class="search-input" id="searchInput"
                           placeholder="Rechercher…"
                           oninput="onSearch(this.value)">
                </div>
            </div>
        </div>

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th onclick="sortTable('session_date')">Date</th>
                        <th onclick="sortTable('course_name')">Cours</th>
                        <th onclick="sortTable('class_name')">Classe</th>
                        <th onclick="sortTable('teacher_name')">Enseignant</th>
                        <th>Horaire</th>
                        <th onclick="sortTable('student_name')">Étudiant</th>
                        <th onclick="sortTable('status')">Statut</th>
                        <th>Justification</th>
                        <th>Actions</th>
                        <th>Actions séance</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <tr>
                        <td colspan="10">
                            <div class="loading-spinner"><div class="spinner"></div> Chargement…</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="pagination" id="pagination"></div>
    </div>

</div><!-- /dashboard-container -->

<!-- Modal détail étudiant -->
<div class="modal-overlay" id="studentModal" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle">Détail étudiant</h3>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
    </div>
</div>

<!-- Modal justification absence -->
<div class="modal-overlay" id="justifyModal" onclick="if(event.target===this)closeJustifyModal()">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="jModalTitle"><i class="fas fa-check-circle" style="color:#f39c12;margin-right:8px;"></i> Justifier l'absence</h3>
            <button class="modal-close" onclick="closeJustifyModal()">×</button>
        </div>
        <div class="modal-body">
            <div style="margin-bottom:16px;">
                <div style="font-size:12px;color:rgba(255,255,255,0.5);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Étudiant</div>
                <div id="jModalStudent" style="font-size:15px;font-weight:600;"></div>
            </div>
            <div style="margin-bottom:20px;">
                <div style="font-size:12px;color:rgba(255,255,255,0.5);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Cours — Date</div>
                <div id="jModalCourse" style="font-size:14px;color:rgba(255,255,255,0.8);"></div>
            </div>
            <div style="margin-bottom:20px;">
                <label style="display:block;font-size:13px;color:rgba(255,255,255,0.7);margin-bottom:8px;">
                    Motif <span style="color:#e74c3c;">*</span>
                </label>
                <textarea id="jMotif" rows="4" placeholder="Saisir le motif de justification…"
                          style="width:100%;padding:10px;border-radius:5px;background:rgba(255,255,255,0.1);
                                 border:1px solid rgba(255,255,255,0.2);color:#fff;
                                 font-family:'Google Sans',Arial,sans-serif;font-size:13px;
                                 outline:none;resize:vertical;transition:border-color 0.2s;"
                          onfocus="this.style.borderColor='#f39c12'"
                          onblur="this.style.borderColor='rgba(255,255,255,0.2)'"></textarea>
                <div id="jMotifError" style="color:#e74c3c;font-size:12px;margin-top:4px;display:none;">
                    Le motif est obligatoire.
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="btn-export" onclick="closeJustifyModal()">Annuler</button>
                <button id="jConfirmBtn" onclick="submitJustification()"
                        style="background:#f39c12;color:#fff;border:none;padding:10px 20px;
                               border-radius:5px;cursor:pointer;font-family:'Google Sans',Arial,sans-serif;
                               font-size:14px;display:inline-flex;align-items:center;gap:8px;
                               transition:background 0.3s;">
                    <i class="fas fa-check"></i> Confirmer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal modification de statut -->
<div class="modal-overlay" id="editStatusModal" onclick="if(event.target===this)closeEditStatusModal()">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="eModalTitle">
                <i class="fas fa-pencil-alt" style="color:#039be5;margin-right:8px;"></i>
                Modifier le statut de présence
            </h3>
            <button class="modal-close" onclick="closeEditStatusModal()">×</button>
        </div>
        <div class="modal-body">
            <div style="margin-bottom:16px;">
                <div style="font-size:12px;color:rgba(255,255,255,0.5);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Étudiant</div>
                <div id="eModalStudent" style="font-size:15px;font-weight:600;"></div>
            </div>
            <div style="margin-bottom:20px;">
                <div style="font-size:12px;color:rgba(255,255,255,0.5);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Cours — Date</div>
                <div id="eModalCourse" style="font-size:14px;color:rgba(255,255,255,0.8);"></div>
            </div>
            <div style="margin-bottom:20px;">
                <label style="display:block;font-size:13px;color:rgba(255,255,255,0.7);margin-bottom:8px;">
                    Nouveau statut <span style="color:#e74c3c;">*</span>
                </label>
                <select id="eNewStatus" onchange="onEditStatusChange()"
                        style="width:100%;padding:10px;border-radius:5px;
                               background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);
                               color:#fff;font-family:'Google Sans',Arial,sans-serif;font-size:13px;
                               outline:none;transition:border-color 0.2s;"
                        onfocus="this.style.borderColor='#039be5'"
                        onblur="this.style.borderColor='rgba(255,255,255,0.2)'">
                    <option value="present">Présent</option>
                    <option value="absent">Absent</option>
                    <option value="late">En retard</option>
                    <option value="justified">Justifié</option>
                </select>
            </div>
            <div id="eMotifGroup" style="display:none;margin-bottom:20px;">
                <label style="display:block;font-size:13px;color:rgba(255,255,255,0.7);margin-bottom:8px;">
                    Motif <span style="color:#e74c3c;">*</span>
                </label>
                <textarea id="eMotif" rows="4" placeholder="Saisir le motif de justification…"
                          style="width:100%;padding:10px;border-radius:5px;background:rgba(255,255,255,0.1);
                                 border:1px solid rgba(255,255,255,0.2);color:#fff;
                                 font-family:'Google Sans',Arial,sans-serif;font-size:13px;
                                 outline:none;resize:vertical;transition:border-color 0.2s;"
                          onfocus="this.style.borderColor='#039be5'"
                          onblur="this.style.borderColor='rgba(255,255,255,0.2)'"></textarea>
                <div id="eMotifError" style="color:#e74c3c;font-size:12px;margin-top:4px;display:none;">
                    Le motif est obligatoire pour une absence justifiée.
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="btn-export" onclick="closeEditStatusModal()">Annuler</button>
                <button id="eConfirmBtn" onclick="submitEditStatus()"
                        style="background:#039be5;color:#fff;border:none;padding:10px 20px;
                               border-radius:5px;cursor:pointer;font-family:'Google Sans',Arial,sans-serif;
                               font-size:14px;display:inline-flex;align-items:center;gap:8px;
                               transition:background 0.3s;">
                    <i class="fas fa-check"></i> Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal suppression séance -->
<div class="modal-overlay" id="deleteSessionModal" onclick="if(event.target===this)closeDeleteSessionModal()">
    <div class="modal-box">
        <div class="modal-header">
            <h3>
                <i class="fas fa-trash-alt" style="color:#e74c3c;margin-right:8px;"></i>
                Supprimer cette séance ?
            </h3>
            <button class="modal-close" onclick="closeDeleteSessionModal()">×</button>
        </div>
        <div class="modal-body">
            <div style="margin-bottom:14px;">
                <div style="font-size:12px;color:rgba(255,255,255,0.5);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Cours</div>
                <div id="dsModalCourse" style="font-size:15px;font-weight:600;"></div>
            </div>
            <div style="margin-bottom:14px;">
                <div style="font-size:12px;color:rgba(255,255,255,0.5);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Date</div>
                <div id="dsModalDate" style="font-size:14px;color:rgba(255,255,255,0.8);"></div>
            </div>
            <div style="margin-bottom:14px;">
                <div style="font-size:12px;color:rgba(255,255,255,0.5);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Horaire</div>
                <div id="dsModalTime" style="font-size:14px;color:rgba(255,255,255,0.8);"></div>
            </div>
            <div style="margin-bottom:20px;">
                <div style="font-size:12px;color:rgba(255,255,255,0.5);margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em;">Présences enregistrées</div>
                <div id="dsModalRecords" style="font-size:14px;color:rgba(255,255,255,0.8);"></div>
            </div>
            <div id="dsWarning" style="display:none;background:rgba(231,76,60,0.1);border:1px solid rgba(231,76,60,0.4);
                                        border-radius:6px;padding:12px;margin-bottom:20px;">
                <i class="fas fa-exclamation-triangle" style="color:#e74c3c;margin-right:8px;"></i>
                <span id="dsWarningText" style="color:#e74c3c;font-size:13px;"></span>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="btn-export" onclick="closeDeleteSessionModal()">Annuler</button>
                <button id="dsConfirmBtn" onclick="confirmDeleteSession()"
                        style="background:#e74c3c;color:#fff;border:none;padding:10px 20px;
                               border-radius:5px;cursor:pointer;font-family:'Google Sans',Arial,sans-serif;
                               font-size:14px;display:inline-flex;align-items:center;gap:8px;
                               transition:background 0.3s;">
                    <i class="fas fa-trash-alt"></i> Supprimer
                </button>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
// ═══════════════════════════════════════
//  ÉTAT GLOBAL
// ═══════════════════════════════════════
let allRows        = [];
let filteredRows   = [];
let currentPage    = 1;
let totalPages     = 1;
let totalRows      = 0;
let currentStatus  = '';
let sortKey        = 'session_date';
let sortDir        = 'desc';
let presenceFilter = 'all';
let searchQuery    = '';
let evolutionChartInstance = null;

// ═══════════════════════════════════════
//  INIT
// ═══════════════════════════════════════
document.addEventListener('DOMContentLoaded', function () {
    // Dates par défaut : mois en cours
    const now = new Date();
    const y   = now.getFullYear();
    const m   = String(now.getMonth() + 1).padStart(2, '0');
    document.getElementById('fDateFrom').value = `${y}-${m}-01`;
    document.getElementById('fDateTo').value   = now.toISOString().slice(0, 10);

    loadKPIs();
    loadClasses();
    loadCourses(0);
    loadStudents(0);
    applyFilters();
});

// ═══════════════════════════════════════
//  KPIs & GRAPHIQUES
// ═══════════════════════════════════════
function loadKPIs() {
    const ay  = document.getElementById('fAcademicYear')?.value || '';
    const cid = document.getElementById('fClass')?.value || '';
    let url = '?ajax=get_kpis';
    if (ay)  url += '&academic_year=' + encodeURIComponent(ay);
    if (cid) url += '&class_id=' + encodeURIComponent(cid);
    fetch(url)
        .then(r => r.json())
        .then(d => {
            document.getElementById('kpiSessions').textContent = d.total_sessions ?? 0;
            document.getElementById('kpiPresent').textContent  = d.total_present  ?? 0;
            document.getElementById('kpiAbsent').textContent   = d.total_absent   ?? 0;
            document.getElementById('kpiRate').textContent     = (d.global_rate ?? 0) + '%';
            document.getElementById('kpiRecords').textContent  = d.total_records  ?? 0;

            const missing = d.sessions_no_records ?? 0;
            const kpiEl   = document.getElementById('kpiNoRecord');
            const iconEl  = document.getElementById('kpiNoRecordIcon');
            const cardEl  = document.getElementById('kpiNoRecordCard');
            kpiEl.textContent = missing;
            if (missing > 0) {
                kpiEl.style.color        = '#e74c3c';
                iconEl.style.color       = '#e74c3c';
                cardEl.style.borderColor = 'rgba(231,76,60,0.4)';
            } else {
                kpiEl.style.color        = '#f39c12';
                iconEl.style.color       = '#f39c12';
                cardEl.style.borderColor = '';
            }
            renderEvolutionChart(d.evolution || []);
            renderAbsentList(d.top_absent    || []);
        });
}

function renderEvolutionChart(data) {
    const ctx = document.getElementById('evolutionChart').getContext('2d');
    if (evolutionChartInstance) evolutionChartInstance.destroy();

    if (!data.length) {
        ctx.fillStyle = 'rgba(255,255,255,0.2)';
        ctx.font = '13px Google Sans';
        ctx.fillText('Aucune donnée disponible', 60, 100);
        return;
    }

    evolutionChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: data.map(d => d.session_date ? d.session_date.slice(5) : ''),
            datasets: [
                {
                    label: 'Présents',
                    data: data.map(d => parseInt(d.present) || 0),
                    borderColor: '#039be5',
                    backgroundColor: 'rgba(3,155,229,0.12)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 3,
                },
                {
                    label: 'Absents',
                    data: data.map(d => parseInt(d.absent) || 0),
                    borderColor: '#e74c3c',
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    borderDash: [5, 3],
                    fill: false,
                    tension: 0.3,
                    pointRadius: 2,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: 'rgba(255,255,255,0.6)', font: { size: 11 } } }
            },
            scales: {
                x: { ticks: { color: 'rgba(255,255,255,0.4)', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.05)' } },
                y: { ticks: { color: 'rgba(255,255,255,0.4)', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true }
            }
        }
    });
}

function renderAbsentList(data) {
    const list = document.getElementById('absentList');
    if (!data.length) {
        list.innerHTML = '<div style="text-align:center;color:rgba(255,255,255,0.3);padding:20px;font-size:13px;">Aucune donnée</div>';
        return;
    }
    const max = parseInt(data[0]?.absences) || 1;
    list.innerHTML = data.map((d, i) => `
        <div class="absent-row">
            <span class="absent-rank">${i + 1}</span>
            <span class="absent-name" title="${esc(d.name)}">${esc(d.name)}</span>
            <div class="absent-bar-wrap">
                <div class="absent-bar-fill" style="width:${Math.round((d.absences / max) * 100)}%"></div>
            </div>
            <span class="absent-count">${d.absences}</span>
        </div>
    `).join('');
}

// ═══════════════════════════════════════
//  CHARGEMENT DES SELECTS
// ═══════════════════════════════════════
function loadClasses() {
    fetch('?ajax=get_classes')
        .then(r => r.json())
        .then(data => {
            const sel = document.getElementById('fClass');
            data.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id; opt.textContent = c.name;
                sel.appendChild(opt);
            });
        });
}

function loadCourses(classId, afterLoad) {
    const url = '?ajax=get_courses' + (classId > 0 ? '&class_id=' + classId : '');
    fetch(url).then(r => r.json()).then(data => {
        const sel = document.getElementById('fCourse');
        sel.innerHTML = '<option value="">Tous les cours</option>';
        data.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name + (c.teacher ? ' — ' + c.teacher : '');
            sel.appendChild(opt);
        });
        if (typeof afterLoad === 'function') afterLoad();
    });
}

function loadStudents(classId) {
    const url = '?ajax=get_students' + (classId > 0 ? '&class_id=' + classId : '');
    fetch(url).then(r => r.json()).then(data => {
        const sel = document.getElementById('fStudent');
        sel.innerHTML = '<option value="">Tous les étudiants</option>';
        data.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id; opt.textContent = s.name;
            sel.appendChild(opt);
        });
    });
}

function onClassChange() {
    const cid = parseInt(document.getElementById('fClass').value) || 0;
    loadCourses(cid);
    loadStudents(cid);
    loadKPIs();
}

// ═══════════════════════════════════════
//  FILTRES & CHARGEMENT
// ═══════════════════════════════════════
function applyFilters() {
    currentPage = 1;
    loadAttendance();
}

function loadAttendance() {
    const params = new URLSearchParams({
        ajax:          'get_attendance',
        course_id:     document.getElementById('fCourse').value       || '',
        class_id:      document.getElementById('fClass').value        || '',
        student_id:    document.getElementById('fStudent').value      || '',
        date_from:     document.getElementById('fDateFrom').value     || '',
        date_to:       document.getElementById('fDateTo').value       || '',
        academic_year: document.getElementById('fAcademicYear').value || '',
        status:        currentStatus,
        page:          currentPage,
    });

    document.getElementById('tableBody').innerHTML =
        '<tr><td colspan="10"><div class="loading-spinner"><div class="spinner"></div> Chargement…</div></td></tr>';

    fetch('?' + params.toString())
        .then(r => r.json())
        .then(d => {
            const pag   = d.pagination || {};
            totalRows   = pag.total       || 0;
            totalPages  = pag.total_pages || 1;
            currentPage = pag.page        || 1;
            allRows     = d.rows          || [];
            applyClientFilters();
        });
}

function resetFilters() {
    document.getElementById('fClass').value    = '';
    document.getElementById('fCourse').value   = '';
    document.getElementById('fStudent').value  = '';
    document.getElementById('fDateFrom').value = '';
    document.getElementById('fDateTo').value   = '';
    document.getElementById('fPresence').value = 'all';
    presenceFilter = 'all';
    currentStatus  = '';
    currentPage    = 1;
    searchQuery    = '';
    document.getElementById('searchInput').value = '';
    document.querySelectorAll('.status-tab').forEach(b => b.classList.toggle('active', b.dataset.s === 'all'));
    loadCourses(0);
    loadStudents(0);
    loadAttendance();
}

function exportExcel() {
    const params = new URLSearchParams({
        ajax:          'export_excel',
        course_id:     document.getElementById('fCourse').value       || '',
        class_id:      document.getElementById('fClass').value        || '',
        student_id:    document.getElementById('fStudent').value      || '',
        date_from:     document.getElementById('fDateFrom').value     || '',
        date_to:       document.getElementById('fDateTo').value       || '',
        academic_year: document.getElementById('fAcademicYear').value || '',
    });
    window.location.href = '?' + params.toString();
}

// ═══════════════════════════════════════
//  FILTRES CLIENT
// ═══════════════════════════════════════
function applyClientFilters() {
    let rows = [...allRows];

    // Filtre "Afficher" (avec/sans présences)
    presenceFilter = document.getElementById('fPresence')?.value || 'all';
    if (presenceFilter === 'with_records') rows = rows.filter(r => r.record_id !== null);
    else if (presenceFilter === 'no_records') rows = rows.filter(r => r.record_id === null);

    if (searchQuery) {
        const q = searchQuery.toLowerCase();
        rows = rows.filter(r =>
            (r.student_name  || '').toLowerCase().includes(q) ||
            (r.course_name   || '').toLowerCase().includes(q) ||
            (r.class_name    || '').toLowerCase().includes(q) ||
            (r.teacher_name  || '').toLowerCase().includes(q) ||
            (r.student_id    || '').toLowerCase().includes(q)
        );
    }

    rows.sort((a, b) => {
        const va = (a[sortKey] || '').toString();
        const vb = (b[sortKey] || '').toString();
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });

    filteredRows = rows;
    renderTable();
}

function setStatusFilter(s) {
    currentStatus = (s === 'all') ? '' : s;
    currentPage   = 1;
    document.querySelectorAll('.status-tab').forEach(b => b.classList.toggle('active', b.dataset.s === s));
    loadAttendance();
}

function onSearch(q) { searchQuery = q; applyClientFilters(); }

function sortTable(key) {
    sortDir = sortKey === key ? (sortDir === 'asc' ? 'desc' : 'asc') : 'asc';
    sortKey = key;
    applyClientFilters();
}

// ═══════════════════════════════════════
//  RENDU TABLEAU + PAGINATION
// ═══════════════════════════════════════
function renderTable() {
    const tbody = document.getElementById('tableBody');
    const total = filteredRows.length;

    document.getElementById('rowCount').textContent = total;

    if (!total) {
        tbody.innerHTML = `<tr><td colspan="10">
            <div class="empty-state">
                <i class="fas fa-filter"></i>
                <p>Aucun résultat pour cette sélection.</p>
            </div>
        </td></tr>`;
        renderPagination();
        return;
    }

    const labelMap = { present: 'Présent', absent: 'Absent', late: 'Retard', justified: 'Justifié' };

    const seenSessions = new Set();
    tbody.innerHTML = filteredRows.map(r => {
        const time = (r.start_time && r.end_time)
            ? r.start_time.slice(0,5) + ' – ' + r.end_time.slice(0,5)
            : (r.session_duration
                ? 'Durée : ' + r.session_duration + 'h'
                : '–');

        const isFirstSession = !seenSessions.has(r.session_id);
        if (isFirstSession) seenSessions.add(r.session_id);

        const sessionRecordCount = isFirstSession
            ? allRows.filter(row => String(row.session_id) === String(r.session_id) && row.record_id !== null).length
            : 0;

        const deleteSessionBtn = isFirstSession
            ? `<button class="btn-delete-session"
                       data-session-id="${r.session_id}"
                       data-course="${esc(r.course_name)}"
                       data-date="${esc(r.session_date)}"
                       data-time="${esc(time)}"
                       data-record-count="${sessionRecordCount}"
                       onclick="openDeleteSessionModal(this)">
                   <i class="fas fa-trash-alt"></i> Supprimer la séance
               </button>`
            : '';

        const _teacherHtml = r.teacher_name === '[Enseignant supprimé]'
            ? '<span style="color:#888;font-style:italic">[Enseignant supprimé]</span>'
            : esc(r.teacher_name);
        const _classHtml = r.class_name === '[Classe supprimée]'
            ? '<span style="color:#888;font-style:italic">[Classe supprimée]</span>'
            : esc(r.class_name);

        // Séance sans aucun enregistrement de présence
        if (r.record_id === null) {
            return `<tr style="opacity:0.85;">
                <td>${fmtDate(r.session_date)}</td>
                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;" title="${esc(r.course_name)}">${esc(r.course_name)}</td>
                <td>${_classHtml}</td>
                <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;" title="${esc(r.teacher_name)}">${_teacherHtml}</td>
                <td style="color:rgba(255,255,255,0.5);font-size:12px;">${time}</td>
                <td style="color:rgba(255,255,255,0.3);">—</td>
                <td><span class="grade-value pill-no-record">⚠️ Appel non fait</span></td>
                <td style="color:rgba(255,255,255,0.3);">—</td>
                <td>
                    <button class="btn-export" style="font-size:12px;padding:4px 10px;"
                            onclick="goToCourse(${parseInt(r.course_id)||0})">
                        <i class="fas fa-eye"></i> Voir le cours
                    </button>
                </td>
                <td style="white-space:nowrap;">${deleteSessionBtn}</td>
            </tr>`;
        }

        const just = r.justification
            ? `<span title="${esc(r.justification)}" style="color:#f39c12;cursor:help;">${esc(r.justification.slice(0,30))}${r.justification.length>30?'…':''}</span>`
            : '<span style="color:rgba(255,255,255,0.3);">–</span>';

        // Attributs communs pour les boutons d'édition de statut
        const editAttrs = `data-record-id="${r.record_id}"
                           data-student="${esc(r.student_name)}"
                           data-course="${esc(r.course_name)}"
                           data-date="${esc(r.session_date)}"
                           data-current-status="${r.status}"
                           data-justification="${esc(r.justification)}"`;

        const btnEditStatus = `<button class="btn-edit" ${editAttrs} onclick="openEditStatusModal(this)">
                   <i class="fas fa-pencil-alt"></i> Modifier
               </button>`;

        let actionBtns = '';
        if (r.status === 'absent') {
            actionBtns =
                `<button class="btn-justify"
                         data-record-id="${r.record_id}"
                         data-student="${esc(r.student_name)}"
                         data-course="${esc(r.course_name)}"
                         data-date="${esc(r.session_date)}"
                         data-mode="add"
                         onclick="openJustifyModal(this)">
                     <i class="fas fa-check"></i> Justifier
                 </button> ` + btnEditStatus;
        } else if (r.status === 'justified') {
            actionBtns =
                `<button class="btn-edit"
                         data-record-id="${r.record_id}"
                         data-student="${esc(r.student_name)}"
                         data-course="${esc(r.course_name)}"
                         data-date="${esc(r.session_date)}"
                         data-justification="${esc(r.justification)}"
                         data-mode="edit"
                         onclick="openJustifyModal(this)">
                     <i class="fas fa-comment-alt"></i> Modifier justif.
                 </button> ` +
                `<button class="btn-edit" ${editAttrs} onclick="openEditStatusModal(this)">
                     <i class="fas fa-exchange-alt"></i> Modifier statut
                 </button>`;
        } else {
            // present, late
            actionBtns = btnEditStatus;
        }

        return `<tr>
            <td>${fmtDate(r.session_date)}</td>
            <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;" title="${esc(r.course_name)}">${esc(r.course_name)}</td>
            <td>${_classHtml}</td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;" title="${esc(r.teacher_name)}">${_teacherHtml}</td>
            <td style="color:rgba(255,255,255,0.5);font-size:12px;">${time}</td>
            <td>
                <span style="cursor:pointer;color:var(--accent-color);text-decoration:underline;"
                      onclick="openStudentModal('${esc(r.student_id)}','${esc(r.student_name)}')">
                    ${esc(r.student_name)}
                </span>
            </td>
            <td><span class="grade-value pill-${r.status}">${labelMap[r.status]||r.status}</span></td>
            <td>${just}</td>
            <td style="white-space:nowrap;">${actionBtns}</td>
            <td style="white-space:nowrap;">${deleteSessionBtn}</td>
        </tr>`;
    }).join('');

    renderPagination();
}

function renderPagination() {
    const pag = document.getElementById('pagination');
    if (!totalRows) { pag.innerHTML = ''; return; }

    const from = (currentPage - 1) * 100 + 1;
    const to   = Math.min(currentPage * 100, totalRows);
    const fmt  = n => n.toLocaleString('fr-FR');

    let html = `<span class="result-count" style="margin-right:auto;">Entrées <strong>${fmt(from)}</strong> – <strong>${fmt(to)}</strong> sur <strong>${fmt(totalRows)}</strong></span>`;

    if (totalPages > 1) {
        html += `<button class="page-btn" onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''}>← Préc</button>`;

        // Collecte des numéros à afficher : 1, ellipsis, plage autour de la page, ellipsis, last
        const nums = [];
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                nums.push(i);
            }
        }

        let prev = 0;
        for (const p of nums) {
            if (p - prev > 1) {
                html += `<span style="color:rgba(255,255,255,0.3);padding:0 4px;">…</span>`;
            }
            html += `<button class="page-btn ${p === currentPage ? 'active' : ''}" onclick="changePage(${p})">${p}</button>`;
            prev = p;
        }

        html += `<button class="page-btn" onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''}>Suiv →</button>`;
    }

    pag.innerHTML = html;
}

function changePage(n) {
    if (n < 1 || n > totalPages) return;
    currentPage = n;
    loadAttendance();
    document.getElementById('attendanceTable').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ═══════════════════════════════════════
//  MODAL DÉTAIL ÉTUDIANT
// ═══════════════════════════════════════
function openStudentModal(studentId, studentName) {
    const rows    = allRows.filter(r => r.student_id === studentId);
    const present = rows.filter(r => r.status === 'present').length;
    const absent  = rows.filter(r => r.status === 'absent').length;
    const late    = rows.filter(r => r.status === 'late').length;
    const just    = rows.filter(r => r.status === 'justified').length;
    const total   = rows.length;
    const rate    = total > 0 ? Math.round(((present + late + just) / total) * 100) : 0;
    const rateColor = rate >= 75 ? '#2ecc71' : rate >= 50 ? '#f1c40f' : '#e74c3c';

    document.getElementById('modalTitle').textContent = studentName;
    document.getElementById('modalBody').innerHTML = `
        <div class="modal-stats-mini">
            <div class="msm-item">
                <div class="msm-val" style="color:var(--accent-color)">${total}</div>
                <div class="msm-label">Séances</div>
            </div>
            <div class="msm-item">
                <div class="msm-val" style="color:#2ecc71">${present}</div>
                <div class="msm-label">Présent</div>
            </div>
            <div class="msm-item">
                <div class="msm-val" style="color:#e74c3c">${absent}</div>
                <div class="msm-label">Absent</div>
            </div>
            <div class="msm-item">
                <div class="msm-val" style="color:${rateColor}">${rate}%</div>
                <div class="msm-label">Taux</div>
            </div>
        </div>
        <div style="height:6px;background:rgba(255,255,255,0.08);border-radius:99px;overflow:hidden;margin-bottom:8px;">
            <div style="height:100%;width:${rate}%;background:${rateColor};border-radius:99px;transition:width .5s;"></div>
        </div>
        <p style="font-size:11px;color:rgba(255,255,255,0.35);text-align:right;margin-bottom:16px;">
            <i class="fas fa-info-circle"></i> Taux calculé sur la période et les filtres sélectionnés
        </p>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Cours</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                ${rows.slice(0, 50).map(r => `
                    <tr>
                        <td style="font-size:12px;color:rgba(255,255,255,0.6);">${fmtDate(r.session_date)}</td>
                        <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="${esc(r.course_name)}">${esc(r.course_name)}</td>
                        <td><span class="grade-value pill-${r.status}">${{present:'Présent',absent:'Absent',late:'Retard',justified:'Justifié'}[r.status]||r.status}</span></td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
        ${rows.length > 50 ? '<p style="font-size:11px;color:rgba(255,255,255,0.3);margin-top:8px;">Limité à 50 entrées. Utilisez les filtres pour plus.</p>' : ''}
    `;
    document.getElementById('studentModal').classList.add('show');
}

function closeModal() { document.getElementById('studentModal').classList.remove('show'); }

// ═══════════════════════════════════════
//  MODAL JUSTIFICATION ABSENCE
// ═══════════════════════════════════════
let currentJustifyRecordId = null;

function openJustifyModal(btn) {
    const isEdit = btn.dataset.mode === 'edit';
    currentJustifyRecordId = btn.dataset.recordId;
    document.getElementById('jModalStudent').textContent = btn.dataset.student;
    document.getElementById('jModalCourse').textContent  =
        btn.dataset.course + ' — ' + fmtDate(btn.dataset.date);
    document.getElementById('jMotif').value              = isEdit ? (btn.dataset.justification || '') : '';
    document.getElementById('jMotifError').style.display = 'none';
    document.getElementById('jModalTitle').innerHTML     = isEdit
        ? '<i class="fas fa-pencil-alt" style="color:#039be5;margin-right:8px;"></i> Modifier la justification'
        : '<i class="fas fa-check-circle" style="color:#f39c12;margin-right:8px;"></i> Justifier l\'absence';
    const confirmBtn = document.getElementById('jConfirmBtn');
    confirmBtn.disabled   = false;
    confirmBtn.innerHTML  = '<i class="fas fa-check"></i> Confirmer';
    document.getElementById('justifyModal').classList.add('show');
    setTimeout(() => document.getElementById('jMotif').focus(), 120);
}

function closeJustifyModal() {
    document.getElementById('justifyModal').classList.remove('show');
    currentJustifyRecordId = null;
}

function submitJustification() {
    const motif = document.getElementById('jMotif').value.trim();
    if (!motif) {
        document.getElementById('jMotifError').style.display = 'block';
        return;
    }
    document.getElementById('jMotifError').style.display = 'none';

    const btn = document.getElementById('jConfirmBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi…';

    const body = new FormData();
    body.append('ajax',          'justify_absence');
    body.append('record_id',     currentJustifyRecordId);
    body.append('justification', motif);

    fetch(window.location.pathname, { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                // Mettre à jour allRows en mémoire
                const idx = allRows.findIndex(r => String(r.record_id) === String(currentJustifyRecordId));
                if (idx !== -1) {
                    allRows[idx].status        = 'justified';
                    allRows[idx].justification = motif;
                }
                closeJustifyModal();
                applyClientFilters(); // re-render le tableau
                loadKPIs();           // rafraîchir les compteurs
            } else {
                alert(d.error || 'Erreur lors de la justification');
                btn.disabled  = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Confirmer';
            }
        })
        .catch(() => {
            alert('Erreur réseau — veuillez réessayer');
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Confirmer';
        });
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeModal(); closeJustifyModal(); closeEditStatusModal(); closeDeleteSessionModal(); }
});

// ═══════════════════════════════════════
//  MODAL MODIFICATION DE STATUT
// ═══════════════════════════════════════
let currentEditRecordId = null;

function openEditStatusModal(btn) {
    currentEditRecordId = btn.dataset.recordId;
    document.getElementById('eModalStudent').textContent = btn.dataset.student;
    document.getElementById('eModalCourse').textContent  =
        btn.dataset.course + ' — ' + fmtDate(btn.dataset.date);
    document.getElementById('eNewStatus').value = btn.dataset.currentStatus || 'present';
    document.getElementById('eMotif').value     = btn.dataset.justification || '';
    document.getElementById('eMotifError').style.display = 'none';
    onEditStatusChange();
    const confirmBtn = document.getElementById('eConfirmBtn');
    confirmBtn.disabled  = false;
    confirmBtn.innerHTML = '<i class="fas fa-check"></i> Enregistrer';
    document.getElementById('editStatusModal').classList.add('show');
    setTimeout(() => document.getElementById('eNewStatus').focus(), 120);
}

function closeEditStatusModal() {
    document.getElementById('editStatusModal').classList.remove('show');
    currentEditRecordId = null;
}

function onEditStatusChange() {
    const isJustified = document.getElementById('eNewStatus').value === 'justified';
    document.getElementById('eMotifGroup').style.display = isJustified ? 'block' : 'none';
    if (!isJustified) document.getElementById('eMotifError').style.display = 'none';
}

function submitEditStatus() {
    const newStatus = document.getElementById('eNewStatus').value;
    const motif     = document.getElementById('eMotif').value.trim();

    if (newStatus === 'justified' && !motif) {
        document.getElementById('eMotifError').style.display = 'block';
        return;
    }
    document.getElementById('eMotifError').style.display = 'none';

    const btn = document.getElementById('eConfirmBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi…';

    const body = new FormData();
    body.append('ajax',       'edit_status');
    body.append('record_id',  currentEditRecordId);
    body.append('new_status', newStatus);
    if (newStatus === 'justified') body.append('justification', motif);

    fetch(window.location.pathname, { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                const idx = allRows.findIndex(r => String(r.record_id) === String(currentEditRecordId));
                if (idx !== -1) {
                    allRows[idx].status        = newStatus;
                    allRows[idx].justification = newStatus === 'justified' ? motif : null;
                }
                closeEditStatusModal();
                applyClientFilters();
                loadKPIs();
            } else {
                alert(d.error || 'Erreur lors de la modification');
                btn.disabled  = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Enregistrer';
            }
        })
        .catch(() => {
            alert('Erreur réseau — veuillez réessayer');
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Enregistrer';
        });
}

// ═══════════════════════════════════════
//  SUPPRESSION SÉANCE
// ═══════════════════════════════════════
let currentDeleteSessionId = null;

function openDeleteSessionModal(btn) {
    currentDeleteSessionId = btn.dataset.sessionId;
    const recordCount = parseInt(btn.dataset.recordCount) || 0;

    document.getElementById('dsModalCourse').textContent  = btn.dataset.course;
    document.getElementById('dsModalDate').textContent    = fmtDate(btn.dataset.date);
    document.getElementById('dsModalTime').textContent    = btn.dataset.time;
    document.getElementById('dsModalRecords').textContent = recordCount + ' enregistrement(s)';

    const warning = document.getElementById('dsWarning');
    if (recordCount > 0) {
        document.getElementById('dsWarningText').textContent =
            `Attention : ${recordCount} enregistrement(s) de présence seront définitivement supprimés.`;
        warning.style.display = 'block';
    } else {
        warning.style.display = 'none';
    }

    const confirmBtn = document.getElementById('dsConfirmBtn');
    confirmBtn.disabled  = false;
    confirmBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Supprimer';
    document.getElementById('deleteSessionModal').classList.add('show');
}

function closeDeleteSessionModal() {
    document.getElementById('deleteSessionModal').classList.remove('show');
    currentDeleteSessionId = null;
}

function confirmDeleteSession() {
    if (!currentDeleteSessionId) return;

    const btn = document.getElementById('dsConfirmBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Suppression…';

    const body = new FormData();
    body.append('ajax',       'delete_attendance_session');
    body.append('session_id', currentDeleteSessionId);

    fetch(window.location.pathname, { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                const sid = String(currentDeleteSessionId);
                allRows = allRows.filter(r => String(r.session_id) !== sid);
                closeDeleteSessionModal();
                applyClientFilters();
                loadKPIs();
                showToast(`Séance supprimée. ${d.records_deleted} enregistrement(s) supprimé(s).`, '#2ecc71');
            } else {
                alert(d.error || 'Erreur lors de la suppression');
                btn.disabled  = false;
                btn.innerHTML = '<i class="fas fa-trash-alt"></i> Supprimer';
            }
        })
        .catch(() => {
            alert('Erreur réseau — veuillez réessayer');
            btn.disabled  = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> Supprimer';
        });
}

function showToast(msg, color) {
    const toast = document.createElement('div');
    toast.style.cssText = `position:fixed;bottom:24px;right:24px;background:${color || '#039be5'};
        color:#fff;padding:14px 20px;border-radius:8px;font-size:14px;
        font-family:'Google Sans',Arial,sans-serif;z-index:99999;
        box-shadow:0 4px 12px rgba(0,0,0,0.3);display:flex;align-items:center;gap:10px;`;
    toast.innerHTML = `<i class="fas fa-check-circle"></i> ${msg}`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

// ═══════════════════════════════════════
//  NAVIGATION VERS UN COURS
// ═══════════════════════════════════════
function goToCourse(courseId) {
    if (!courseId) return;
    document.getElementById('fClass').value    = '';
    document.getElementById('fPresence').value = 'all';
    presenceFilter = 'all';
    loadCourses(0, () => {
        document.getElementById('fCourse').value = courseId;
        applyFilters();
    });
    loadStudents(0);
}

// ═══════════════════════════════════════
//  UTILITAIRES
// ═══════════════════════════════════════
function esc(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtDate(d) {
    if (!d) return '–';
    return new Date(d).toLocaleDateString('fr-FR', { day:'2-digit', month:'2-digit', year:'numeric' });
}
</script>

</body>
</html>