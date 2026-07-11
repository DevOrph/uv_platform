<?php
require_once '../includes/db_connect.php';
require_once '../includes/quiz_functions.php';

// Contrôle du rôle
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'admin'])) {
    header("Location: ../pages/login.php?error=access_denied");
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// ============================================================
// AJAX GET : questions actives de la banque pour un cours
// (pas de is_correct : inutile ici)
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_bank_questions') {
    header('Content-Type: application/json');
    $course_id = (int) ($_GET['course_id'] ?? 0);
    if (!quiz_user_owns_course($conn, $course_id, $user_id, $user_role)) {
        echo json_encode(['success' => false, 'message' => 'Cours invalide ou accès refusé']);
        exit();
    }
    if ($user_role === 'admin') {
        $stmt = $conn->prepare("SELECT id, type, question_text, points FROM quiz_questions WHERE course_id = ? AND is_active = 1 ORDER BY created_at DESC");
        $stmt->bind_param("i", $course_id);
    } else {
        $stmt = $conn->prepare("SELECT id, type, question_text, points FROM quiz_questions WHERE course_id = ? AND teacher_id = ? AND is_active = 1 ORDER BY created_at DESC");
        $stmt->bind_param("is", $course_id, $user_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'id'     => (int) $row['id'],
            'type'   => $row['type'],
            'text'   => $row['question_text'],
            'points' => (float) $row['points'],
        ];
    }
    echo json_encode(['success' => true, 'questions' => $out]);
    exit();
}

// ============================================================
// AJAX POST (JSON) : actions de cycle de vie
// ============================================================
$raw_input = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    header('Content-Type: application/json');
    $payload = json_decode(file_get_contents('php://input'), true);
    $action  = $payload['action'] ?? '';
    $quiz_id = (int) ($payload['quiz_id'] ?? 0);

    $quiz = quiz_user_owns_quiz($conn, $quiz_id, $user_id, $user_role);
    if (!$quiz) {
        echo json_encode(['success' => false, 'message' => 'Quiz introuvable ou accès refusé']);
        exit();
    }

    switch ($action) {
        // ── Brouillon → Publié ───────────────────────────────
        case 'publish':
            if ($quiz['status'] !== 'draft') {
                echo json_encode(['success' => false, 'message' => 'Seul un brouillon peut être publié']);
                exit();
            }
            $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM quiz_question_links WHERE quiz_id = ?");
            $stmt->bind_param("i", $quiz_id);
            $stmt->execute();
            $nb = (int) $stmt->get_result()->fetch_assoc()['n'];
            if ($nb === 0) {
                echo json_encode(['success' => false, 'message' => 'Impossible de publier : le quiz ne contient aucune question']);
                exit();
            }
            if (!empty($quiz['counts_in_average']) && (empty($quiz['evaluation_type_id']) || empty($quiz['evaluation_period_id']))) {
                echo json_encode(['success' => false, 'message' => "Impossible de publier : type et période d'évaluation requis pour l'injection des notes"]);
                exit();
            }
            $stmt = $conn->prepare("UPDATE quizzes SET status = 'published' WHERE id = ?");
            $stmt->bind_param("i", $quiz_id);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => 'Quiz publié : il sera visible des étudiants pendant la période définie']);
            exit();

        // ── Publié → Brouillon (si aucune tentative) ─────────
        case 'revert_draft':
            if ($quiz['status'] !== 'published') {
                echo json_encode(['success' => false, 'message' => 'Seul un quiz publié peut repasser en brouillon']);
                exit();
            }
            $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM quiz_attempts WHERE quiz_id = ?");
            $stmt->bind_param("i", $quiz_id);
            $stmt->execute();
            if ((int) $stmt->get_result()->fetch_assoc()['n'] > 0) {
                echo json_encode(['success' => false, 'message' => 'Impossible : des étudiants ont déjà commencé ce quiz']);
                exit();
            }
            $stmt = $conn->prepare("UPDATE quizzes SET status = 'draft' WHERE id = ?");
            $stmt->bind_param("i", $quiz_id);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => 'Quiz repassé en brouillon']);
            exit();

        // ── Publié → Fermé (+ injection des notes) ───────────
        case 'close':
            if ($quiz['status'] !== 'published') {
                echo json_encode(['success' => false, 'message' => 'Seul un quiz publié peut être fermé']);
                exit();
            }
            $result = quiz_close_and_inject($conn, $quiz);
            echo json_encode($result);
            exit();

        // ── Suppression (brouillon uniquement) ───────────────
        case 'delete_quiz':
            if ($quiz['status'] !== 'draft') {
                echo json_encode(['success' => false, 'message' => 'Seul un brouillon peut être supprimé']);
                exit();
            }
            $stmt = $conn->prepare("DELETE FROM quizzes WHERE id = ?"); // cascade sur les liens
            $stmt->bind_param("i", $quiz_id);
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => 'Brouillon supprimé']);
            exit();
    }

    echo json_encode(['success' => false, 'message' => 'Action inconnue']);
    exit();
}

// ============================================================
// POST classique : enregistrement d'un quiz (création / édition brouillon)
// ============================================================
$success_msg = $_SESSION['quiz_manage_msg'] ?? '';
unset($_SESSION['quiz_manage_msg']);
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_quiz') {
    $quiz_id     = (int) ($_POST['quiz_id'] ?? 0);
    $course_id   = (int) ($_POST['course_id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date  = $_POST['start_date'] ?? '';
    $end_date    = $_POST['end_date'] ?? '';
    $duration    = ($_POST['duration_minutes'] ?? '') !== '' ? max(1, (int) $_POST['duration_minutes']) : null;
    $max_attempts      = max(1, (int) ($_POST['max_attempts'] ?? 1));
    $shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;
    $shuffle_options   = isset($_POST['shuffle_options']) ? 1 : 0;
    $partial_credit    = isset($_POST['partial_credit']) ? 1 : 0;
    $counts_in_average = isset($_POST['counts_in_average']) ? 1 : 0;
    $show_correction   = $_POST['show_correction'] ?? 'after_close';
    $grading_method    = $_POST['grading_method'] ?? 'best';
    $eval_type_id      = (int) ($_POST['evaluation_type_id'] ?? 0) ?: null;
    $eval_period_id    = (int) ($_POST['evaluation_period_id'] ?? 0) ?: null;

    $question_ids = array_map('intval', (array) ($_POST['question_ids'] ?? []));
    $overrides    = (array) ($_POST['points_override'] ?? []);

    // ── Validations ──────────────────────────────────────────
    if ($title === '') {
        $error_msg = "Le titre est obligatoire.";
    } elseif (!quiz_user_owns_course($conn, $course_id, $user_id, $user_role)) {
        $error_msg = "Cours invalide ou accès refusé.";
    } elseif (!in_array($show_correction, ['never', 'after_submit', 'after_close'], true)
           || !in_array($grading_method, ['best', 'last', 'average'], true)) {
        $error_msg = "Valeur d'option invalide.";
    } elseif (!$start_date || !$end_date || strtotime($end_date) === false || strtotime($start_date) === false) {
        $error_msg = "Dates de début et de fin obligatoires.";
    } elseif (strtotime($end_date) <= strtotime($start_date)) {
        $error_msg = "La date de fin doit être postérieure à la date de début.";
    } elseif ($counts_in_average && (!$eval_type_id || !$eval_period_id)) {
        $error_msg = "Type et période d'évaluation obligatoires pour un quiz compté dans la moyenne.";
    }

    // Vérifier que chaque question appartient bien à l'enseignant et au cours
    if (!$error_msg && !empty($question_ids)) {
        $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
        if ($user_role === 'admin') {
            $sql_check = "SELECT COUNT(*) AS n FROM quiz_questions WHERE id IN ($placeholders) AND course_id = ?";
            $types_chk = str_repeat('i', count($question_ids)) . "i";
            $params    = array_merge($question_ids, [$course_id]);
        } else {
            $sql_check = "SELECT COUNT(*) AS n FROM quiz_questions WHERE id IN ($placeholders) AND course_id = ? AND teacher_id = ?";
            $types_chk = str_repeat('i', count($question_ids)) . "is";
            $params    = array_merge($question_ids, [$course_id, $user_id]);
        }
        $stmt = $conn->prepare($sql_check);
        $stmt->bind_param($types_chk, ...$params);
        $stmt->execute();
        if ((int) $stmt->get_result()->fetch_assoc()['n'] !== count($question_ids)) {
            $error_msg = "Une ou plusieurs questions sélectionnées sont invalides pour ce cours.";
        }
    }

    // Édition : uniquement un brouillon dont on est propriétaire
    if (!$error_msg && $quiz_id > 0) {
        $existing = quiz_user_owns_quiz($conn, $quiz_id, $user_id, $user_role);
        if (!$existing) {
            $error_msg = "Quiz introuvable ou accès refusé.";
        } elseif ($existing['status'] !== 'draft') {
            $error_msg = "Seul un brouillon peut être modifié.";
        }
    }

    if (!$error_msg) {
        try {
            $conn->begin_transaction();

            $description_db = $description !== '' ? $description : null;

            if ($quiz_id > 0) {
                $stmt = $conn->prepare("
                    UPDATE quizzes SET course_id = ?, title = ?, description = ?, start_date = ?, end_date = ?,
                        duration_minutes = ?, max_attempts = ?, shuffle_questions = ?, shuffle_options = ?,
                        show_correction = ?, grading_method = ?, partial_credit = ?, counts_in_average = ?,
                        evaluation_type_id = ?, evaluation_period_id = ?
                    WHERE id = ?
                ");
                $stmt->bind_param(
                    "issssiiiissiiiii",
                    $course_id, $title, $description_db, $start_date, $end_date,
                    $duration, $max_attempts, $shuffle_questions, $shuffle_options,
                    $show_correction, $grading_method, $partial_credit, $counts_in_average,
                    $eval_type_id, $eval_period_id, $quiz_id
                );
                $stmt->execute();

                $del = $conn->prepare("DELETE FROM quiz_question_links WHERE quiz_id = ?");
                $del->bind_param("i", $quiz_id);
                $del->execute();
            } else {
                $annee = ANNEE_ACADEMIQUE_COURANTE;
                $stmt = $conn->prepare("
                    INSERT INTO quizzes (course_id, teacher_id, annee_academique, title, description,
                        start_date, end_date, duration_minutes, max_attempts, shuffle_questions, shuffle_options,
                        show_correction, grading_method, partial_credit, counts_in_average,
                        evaluation_type_id, evaluation_period_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
                ");
                $stmt->bind_param(
                    "issssssiiiissiiii",
                    $course_id, $user_id, $annee, $title, $description_db,
                    $start_date, $end_date, $duration, $max_attempts, $shuffle_questions, $shuffle_options,
                    $show_correction, $grading_method, $partial_credit, $counts_in_average,
                    $eval_type_id, $eval_period_id
                );
                $stmt->execute();
                $quiz_id = $conn->insert_id;
            }

            if (!empty($question_ids)) {
                $ins = $conn->prepare("INSERT INTO quiz_question_links (quiz_id, question_id, display_order, points_override) VALUES (?, ?, ?, ?)");
                foreach (array_values(array_unique($question_ids)) as $order => $qid) {
                    $ov = isset($overrides[$qid]) && $overrides[$qid] !== '' ? (float) $overrides[$qid] : null;
                    if ($ov !== null && $ov <= 0) {
                        $ov = null;
                    }
                    $ins->bind_param("iiid", $quiz_id, $qid, $order, $ov);
                    $ins->execute();
                }
            }

            $conn->commit();
            $_SESSION['quiz_manage_msg'] = "Quiz enregistré (brouillon).";
            header("Location: quiz_manage.php");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Erreur lors de l'enregistrement : " . $e->getMessage();
        }
    }
}

// ============================================================
// Données d'affichage
// ============================================================
$courses = quiz_get_teacher_courses($conn, $user_id, $user_role);

$eval_types   = $conn->query("SELECT id, name FROM evaluation_types ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$eval_periods = $conn->query("SELECT id, name, school_year FROM evaluation_periods ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);

// ── Vue formulaire (création / édition) ─────────────────────
$edit_quiz  = null;
$edit_links = [];
$show_form  = isset($_GET['new']);

if (isset($_GET['edit'])) {
    $edit_quiz = quiz_user_owns_quiz($conn, (int) $_GET['edit'], $user_id, $user_role);
    if (!$edit_quiz) {
        $error_msg = "Quiz introuvable ou accès refusé.";
    } elseif ($edit_quiz['status'] !== 'draft') {
        $error_msg = "Seul un brouillon peut être modifié.";
        $edit_quiz = null;
    } else {
        $show_form = true;
        $stmt = $conn->prepare("
            SELECT l.question_id, l.display_order, l.points_override, q.question_text, q.type, q.points
            FROM quiz_question_links l
            JOIN quiz_questions q ON q.id = l.question_id
            WHERE l.quiz_id = ?
            ORDER BY l.display_order ASC
        ");
        $stmt->bind_param("i", $edit_quiz['id']);
        $stmt->execute();
        $edit_links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

// ── Vue liste ────────────────────────────────────────────────
$quizzes = [];
if (!$show_form) {
    $sql = "SELECT z.*, c.name AS course_name,
                   (SELECT COUNT(*) FROM quiz_question_links l WHERE l.quiz_id = z.id) AS nb_questions,
                   (SELECT COUNT(DISTINCT qa.student_id) FROM quiz_attempts qa WHERE qa.quiz_id = z.id) AS nb_students
            FROM quizzes z
            JOIN courses c ON c.id = z.course_id";
    if ($user_role === 'admin') {
        $sql .= " ORDER BY z.created_at DESC";
        $stmt = $conn->prepare($sql);
    } else {
        $sql .= " WHERE z.teacher_id = ? ORDER BY z.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $user_id);
    }
    $stmt->execute();
    $quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$status_labels = ['draft' => 'Brouillon', 'published' => 'Publié', 'closed' => 'Fermé'];
$type_labels   = [
    'single_choice'   => 'QCM',
    'multiple_choice' => 'QCM-M',
    'true_false'      => 'V/F',
    'short_answer'    => 'R.courte',
];

function dt_local(?string $mysql_dt): string
{
    if (!$mysql_dt) return '';
    return date('Y-m-d\TH:i', strtotime($mysql_dt));
}
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
    <title>Mes Quiz — Enseignant</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg:    #051e34;
            --secondary-bg:  #0c2d48;
            --accent-color:  #039be5;
            --text-light:    #ffffff;
            --border-color:  rgba(255,255,255,0.1);
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color:  #e74c3c;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Google Sans', Arial, sans-serif; background: var(--primary-bg); color: var(--text-light); min-height: 100vh; display: flex; flex-direction: column; }
        header { background: var(--secondary-bg); padding: 15px 0; border-bottom: 1px solid var(--border-color); position: relative; }
        header::after { content:''; position:absolute; bottom:0; left:0; right:0; height:2px; background:linear-gradient(to right,#039be5,#4CAF50,#039be5); animation:shimmer 2s infinite linear; }
        @keyframes shimmer { 0%{transform:translateX(-100%)} 100%{transform:translateX(100%)} }
        .header-content { max-width:1200px; margin:0 auto; padding:0 20px; text-align:center; }
        .header-content h1 { font-size:22px; color:var(--accent-color); margin-bottom:15px; }
        nav ul { list-style:none; display:flex; justify-content:center; gap:15px; flex-wrap:wrap; }
        nav a { color:var(--text-light); text-decoration:none; padding:7px 14px; border-radius:5px; display:flex; align-items:center; gap:7px; transition:background .3s; }
        nav a:hover { background:rgba(3,155,229,.15); }
        nav a.active { background:rgba(3,155,229,.25); }
        nav a[href*="logout"] { color:#dc3545; }
        main { flex:1; max-width:1200px; margin:0 auto; padding:30px 20px; width:100%; }
        h2 { font-size:20px; color:var(--accent-color); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        h3 { font-size:15px; color:var(--accent-color); margin:20px 0 12px; display:flex; align-items:center; gap:8px; }

        .card { background:var(--secondary-bg); border-radius:10px; padding:20px; margin-bottom:22px; border:1px solid var(--border-color); }
        .form-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; }
        .form-group { display:flex; flex-direction:column; gap:6px; }
        .form-group label { font-size:13px; color:rgba(255,255,255,.7); }
        .form-group.full { grid-column:1 / -1; }
        select, input[type=text], input[type=number], input[type=datetime-local], textarea {
            background:#0d3152; color:var(--text-light); border:1px solid var(--border-color);
            border-radius:6px; padding:9px 12px; font-size:14px; width:100%;
        }
        select:focus, input:focus, textarea:focus { outline:none; border-color:var(--accent-color); }
        textarea { resize:vertical; min-height:60px; font-family:inherit; }
        input[type=datetime-local] { color-scheme: dark; }
        .check-line { display:flex; align-items:center; gap:9px; font-size:13px; color:rgba(255,255,255,.8); cursor:pointer; padding:6px 0; }
        .check-line input { width:auto; }
        .hint { font-size:12px; color:rgba(255,255,255,.45); }

        .btn { display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border-radius:6px; border:none; cursor:pointer; font-size:13px; font-weight:600; transition:all .3s; text-decoration:none; white-space:nowrap; }
        .btn-primary { background:var(--accent-color); color:#fff; }
        .btn-primary:hover { background:#0288c7; }
        .btn-success { background:var(--success-color); color:#06371d; }
        .btn-success:hover { filter:brightness(1.1); }
        .btn-warning { background:rgba(243,156,18,.85); color:#3d2703; }
        .btn-outline { background:transparent; color:var(--text-light); border:1px solid var(--border-color); }
        .btn-outline:hover { border-color:var(--accent-color); color:var(--accent-color); }
        .btn-danger-outline { background:transparent; color:var(--danger-color); border:1px solid rgba(231,76,60,.4); }
        .btn-danger-outline:hover { background:rgba(231,76,60,.12); }
        .btn-sm { padding:5px 11px; font-size:12px; }

        .alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:rgba(46,204,113,.12); border:1px solid rgba(46,204,113,.35); color:var(--success-color); }
        .alert-error   { background:rgba(231,76,60,.12);  border:1px solid rgba(231,76,60,.35);  color:var(--danger-color); }
        .alert-js { display:none; }
        .alert-js.show { display:flex; }

        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        thead th { background:rgba(3,155,229,.3); padding:11px 12px; text-align:left; white-space:nowrap; }
        tbody tr:nth-child(even) { background:rgba(255,255,255,.03); }
        tbody tr:hover { background:rgba(3,155,229,.08); }
        td { padding:9px 12px; border-bottom:1px solid var(--border-color); vertical-align:middle; }

        .badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
        .badge-draft     { background:rgba(255,255,255,.12); color:rgba(255,255,255,.75); }
        .badge-published { background:rgba(46,204,113,.2);  color:var(--success-color); }
        .badge-closed    { background:rgba(231,76,60,.18);  color:var(--danger-color); }
        .badge-injected  { background:rgba(3,155,229,.18);  color:var(--accent-color); }

        .no-data { text-align:center; padding:40px; color:rgba(255,255,255,.4); }
        .no-data i { display:block; font-size:44px; margin-bottom:14px; }
        .toolbar { display:flex; justify-content:flex-end; margin-bottom:18px; }

        /* Sélecteur de questions */
        .selector-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media (max-width: 800px) { .selector-grid { grid-template-columns:1fr; } }
        .q-panel { background:#0a2740; border:1px solid var(--border-color); border-radius:8px; padding:12px; max-height:420px; overflow-y:auto; }
        .q-panel-title { font-size:13px; font-weight:700; color:rgba(255,255,255,.6); margin-bottom:10px; display:flex; align-items:center; gap:7px; text-transform:uppercase; letter-spacing:.05em; }
        .q-item { display:flex; align-items:center; gap:9px; padding:8px 10px; border:1px solid var(--border-color); border-radius:7px; margin-bottom:7px; font-size:13px; background:rgba(255,255,255,.02); }
        .q-item .qi-text { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .q-item .qi-type { font-size:10px; padding:2px 7px; border-radius:9px; background:rgba(3,155,229,.18); color:var(--accent-color); flex-shrink:0; }
        .q-item button { background:none; border:none; cursor:pointer; font-size:14px; padding:3px 5px; flex-shrink:0; }
        .q-item .qi-add { color:var(--success-color); }
        .q-item .qi-remove { color:var(--danger-color); }
        .q-item .qi-move { color:rgba(255,255,255,.5); }
        .q-item .qi-move:hover { color:var(--accent-color); }
        .q-item input.qi-override { width:74px; padding:4px 7px; font-size:12px; flex-shrink:0; }
        .selected-empty { text-align:center; color:rgba(255,255,255,.35); padding:20px; font-size:13px; }
        .total-points { text-align:right; font-size:13px; color:var(--accent-color); font-weight:700; margin-top:8px; }
        .form-actions { display:flex; justify-content:flex-end; gap:12px; margin-top:20px; }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle — Enseignant</h1>
        <nav><ul>
            <li><a href="teacher_dashboard.php"><i class="fas fa-home"></i> Accueil</a></li>
            <li><a href="grades_management.php"><i class="fas fa-chart-line"></i> Notes</a></li>
            <li><a href="quiz_bank.php"><i class="fas fa-database"></i> Banque de questions</a></li>
            <li><a href="quiz_manage.php" class="active"><i class="fas fa-clipboard-question"></i> Mes Quiz</a></li>
            <li><a href="quiz_aiken_import.php"><i class="fas fa-file-import"></i> Import Aiken</a></li>
            <li><a href="teacher_profile.php"><i class="fas fa-user"></i> Profil</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul></nav>
    </div>
</header>

<main>
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>
    <div class="alert alert-success alert-js" id="alertSuccess"><i class="fas fa-check-circle"></i> <span></span></div>
    <div class="alert alert-error alert-js" id="alertError"><i class="fas fa-exclamation-circle"></i> <span></span></div>

<?php if ($show_form): ?>
    <!-- ================= FORMULAIRE ================= -->
    <h2>
        <i class="fas <?= $edit_quiz ? 'fa-pen' : 'fa-plus-circle' ?>"></i>
        <?= $edit_quiz ? 'Modifier le quiz (brouillon)' : 'Nouveau quiz' ?>
    </h2>

    <form method="POST" action="quiz_manage.php" id="quizForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="save_quiz">
        <input type="hidden" name="quiz_id" value="<?= $edit_quiz ? (int) $edit_quiz['id'] : 0 ?>">

        <div class="card">
            <h3 style="margin-top:0"><i class="fas fa-circle-info"></i> Informations générales</h3>
            <div class="form-grid">
                <div class="form-group full">
                    <label>Titre *</label>
                    <input type="text" name="title" required maxlength="255"
                           value="<?= htmlspecialchars($edit_quiz['title'] ?? '') ?>" placeholder="Ex : Quiz chapitre 3 — Les réseaux">
                </div>
                <div class="form-group full">
                    <label>Description (consignes affichées aux étudiants)</label>
                    <textarea name="description" placeholder="Consignes, thème, documents autorisés…"><?= htmlspecialchars($edit_quiz['description'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-book"></i> Cours *</label>
                    <select name="course_id" id="fCourse" required onchange="loadBankQuestions(true)">
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($edit_quiz && $edit_quiz['course_id'] == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (S<?= $c['semester'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-plus"></i> Ouverture *</label>
                    <input type="datetime-local" name="start_date" required value="<?= dt_local($edit_quiz['start_date'] ?? null) ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calendar-xmark"></i> Fermeture *</label>
                    <input type="datetime-local" name="end_date" required value="<?= dt_local($edit_quiz['end_date'] ?? null) ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-stopwatch"></i> Durée (minutes)</label>
                    <input type="number" name="duration_minutes" min="1" max="600"
                           value="<?= htmlspecialchars((string) ($edit_quiz['duration_minutes'] ?? '')) ?>" placeholder="Vide = sans limite">
                    <span class="hint">Contrôlée côté serveur (+30 s de grâce)</span>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-rotate"></i> Tentatives max *</label>
                    <input type="number" name="max_attempts" min="1" max="10" required
                           value="<?= (int) ($edit_quiz['max_attempts'] ?? 1) ?>">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-calculator"></i> Note retenue (si plusieurs tentatives)</label>
                    <select name="grading_method">
                        <option value="best"    <?= ($edit_quiz['grading_method'] ?? 'best') === 'best' ? 'selected' : '' ?>>Meilleure tentative</option>
                        <option value="last"    <?= ($edit_quiz['grading_method'] ?? '') === 'last' ? 'selected' : '' ?>>Dernière tentative</option>
                        <option value="average" <?= ($edit_quiz['grading_method'] ?? '') === 'average' ? 'selected' : '' ?>>Moyenne des tentatives</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-eye"></i> Affichage de la correction</label>
                    <select name="show_correction">
                        <option value="never"        <?= ($edit_quiz['show_correction'] ?? '') === 'never' ? 'selected' : '' ?>>Jamais</option>
                        <option value="after_submit" <?= ($edit_quiz['show_correction'] ?? '') === 'after_submit' ? 'selected' : '' ?>>Après soumission</option>
                        <option value="after_close"  <?= ($edit_quiz['show_correction'] ?? 'after_close') === 'after_close' ? 'selected' : '' ?>>Après fermeture du quiz</option>
                    </select>
                </div>
            </div>

            <div style="display:flex; gap:26px; flex-wrap:wrap; margin-top:14px">
                <label class="check-line"><input type="checkbox" name="shuffle_questions" <?= !isset($edit_quiz) || !empty($edit_quiz['shuffle_questions']) ? 'checked' : '' ?>> Mélanger l'ordre des questions</label>
                <label class="check-line"><input type="checkbox" name="shuffle_options" <?= !isset($edit_quiz) || !empty($edit_quiz['shuffle_options']) ? 'checked' : '' ?>> Mélanger les options des QCM</label>
                <label class="check-line"><input type="checkbox" name="partial_credit" <?= !isset($edit_quiz) || !empty($edit_quiz['partial_credit']) ? 'checked' : '' ?>> Points partiels sur les QCM multiples</label>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-top:0"><i class="fas fa-chart-line"></i> Notation (injection dans le relevé de notes)</h3>
            <label class="check-line" style="margin-bottom:12px">
                <input type="checkbox" name="counts_in_average" id="fCounts" onchange="toggleEvalFields()"
                       <?= !isset($edit_quiz) || !empty($edit_quiz['counts_in_average']) ? 'checked' : '' ?>>
                Compter ce quiz dans la moyenne (injection automatique à la fermeture)
            </label>
            <div class="form-grid" id="evalFields">
                <div class="form-group">
                    <label>Type d'évaluation *</label>
                    <select name="evaluation_type_id">
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($eval_types as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($edit_quiz['evaluation_type_id'] ?? '') == $t['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Période d'évaluation *</label>
                    <select name="evaluation_period_id">
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($eval_periods as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($edit_quiz['evaluation_period_id'] ?? '') == $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name'] . ' (' . $p['school_year'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <span class="hint">La note injectée est celle retenue selon la méthode choisie, ramenée sur 20.</span>
        </div>

        <div class="card">
            <h3 style="margin-top:0"><i class="fas fa-list-check"></i> Questions du quiz</h3>
            <div class="selector-grid">
                <div>
                    <div class="q-panel-title"><i class="fas fa-database"></i> Banque du cours <span id="bankCount"></span></div>
                    <div class="q-panel" id="bankPanel">
                        <div class="selected-empty">Sélectionnez d'abord un cours.</div>
                    </div>
                </div>
                <div>
                    <div class="q-panel-title"><i class="fas fa-check"></i> Sélectionnées (dans l'ordre) <span id="selCount"></span></div>
                    <div class="q-panel" id="selectedPanel">
                        <div class="selected-empty">Aucune question sélectionnée.</div>
                    </div>
                    <div class="total-points" id="totalPoints"></div>
                </div>
            </div>
            <span class="hint">Le barème de chaque question peut être surchargé pour ce quiz (champ « pts »). L'ordre affiché ici est l'ordre de référence (avant mélange éventuel).</span>
            <div id="hiddenInputs"></div>
        </div>

        <div class="form-actions">
            <a href="quiz_manage.php" class="btn btn-outline"><i class="fas fa-times"></i> Annuler</a>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer le brouillon</button>
        </div>
    </form>

<?php else: ?>
    <!-- ================= LISTE ================= -->
    <h2><i class="fas fa-clipboard-question"></i> Mes Quiz</h2>

    <div class="toolbar">
        <a href="quiz_manage.php?new=1" class="btn btn-primary"><i class="fas fa-plus"></i> Nouveau quiz</a>
    </div>

    <?php if (!empty($quizzes)): ?>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Titre</th>
                    <th>Cours</th>
                    <th>Période d'ouverture</th>
                    <th>Durée</th>
                    <th>Questions</th>
                    <th>Étudiants</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($quizzes as $z): ?>
                <tr>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($z['title']) ?>">
                        <?= htmlspecialchars($z['title']) ?>
                    </td>
                    <td><?= htmlspecialchars($z['course_name']) ?></td>
                    <td style="white-space:nowrap">
                        <?= date('d/m/Y H:i', strtotime($z['start_date'])) ?><br>
                        <span style="color:rgba(255,255,255,.5)">→ <?= date('d/m/Y H:i', strtotime($z['end_date'])) ?></span>
                    </td>
                    <td><?= $z['duration_minutes'] ? (int) $z['duration_minutes'] . ' min' : '—' ?></td>
                    <td><?= (int) $z['nb_questions'] ?></td>
                    <td><?= (int) $z['nb_students'] ?></td>
                    <td>
                        <span class="badge badge-<?= $z['status'] ?>"><?= $status_labels[$z['status']] ?></span>
                        <?php if ($z['grade_injected']): ?>
                            <span class="badge badge-injected" title="Notes injectées dans le relevé"><i class="fas fa-check"></i> Notes</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <a class="btn btn-outline btn-sm" href="quiz_dashboard.php?quiz_id=<?= $z['id'] ?>" title="Tableau de bord"><i class="fas fa-chart-pie"></i></a>
                        <?php if ($z['status'] === 'draft'): ?>
                            <a class="btn btn-outline btn-sm" href="quiz_manage.php?edit=<?= $z['id'] ?>" title="Modifier"><i class="fas fa-pen"></i></a>
                            <button class="btn btn-success btn-sm" onclick="lifecycle('publish', <?= $z['id'] ?>, 'Publier ce quiz ?')" title="Publier"><i class="fas fa-paper-plane"></i></button>
                            <button class="btn btn-danger-outline btn-sm" onclick="lifecycle('delete_quiz', <?= $z['id'] ?>, 'Supprimer définitivement ce brouillon ?')" title="Supprimer"><i class="fas fa-trash"></i></button>
                        <?php elseif ($z['status'] === 'published'): ?>
                            <button class="btn btn-outline btn-sm" onclick="lifecycle('revert_draft', <?= $z['id'] ?>, 'Repasser ce quiz en brouillon ? (possible uniquement sans tentatives)')" title="Repasser en brouillon"><i class="fas fa-rotate-left"></i></button>
                            <button class="btn btn-warning btn-sm" onclick="lifecycle('close', <?= $z['id'] ?>, 'Fermer ce quiz ? Les tentatives en cours seront corrigées avec les réponses sauvegardées, et les notes seront injectées dans le relevé. Cette action est irréversible.')" title="Fermer + injecter les notes"><i class="fas fa-lock"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-clipboard-question"></i>
            <p>Aucun quiz pour le moment.</p>
            <p style="margin-top:8px;font-size:13px;color:rgba(255,255,255,.35)">
                Créez d'abord vos questions dans la <a href="quiz_bank.php" style="color:var(--accent-color)">banque de questions</a>, puis assemblez-les en quiz.
            </p>
        </div>
    <?php endif; ?>
<?php endif; ?>
</main>

<script>
function showAlert(type, message) {
    const el = document.getElementById(type === 'success' ? 'alertSuccess' : 'alertError');
    el.querySelector('span').textContent = message;
    el.classList.add('show');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => el.classList.remove('show'), 6000);
}

// ── Actions de cycle de vie (liste) ──────────────────────────
async function lifecycle(action, quizId, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    try {
        const res  = await fetch('quiz_manage.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: action, quiz_id: quizId })
        });
        const data = await res.json();
        if (data.success) {
            sessionStorage.setItem('quizManageMsg', data.message);
            window.location.reload();
        } else {
            showAlert('error', data.message);
        }
    } catch (e) {
        showAlert('error', 'Erreur réseau');
    }
}
const pendingMsg = sessionStorage.getItem('quizManageMsg');
if (pendingMsg) {
    sessionStorage.removeItem('quizManageMsg');
    showAlert('success', pendingMsg);
}

<?php if ($show_form): ?>
// ── Sélecteur de questions ───────────────────────────────────
// selected = [{id, text, type, points, override}]
let bank     = [];
let selected = <?= json_encode(array_map(static function ($l) {
    return [
        'id'       => (int) $l['question_id'],
        'text'     => $l['question_text'],
        'type'     => $l['type'],
        'points'   => (float) $l['points'],
        'override' => $l['points_override'] !== null ? (float) $l['points_override'] : null,
    ];
}, $edit_links), JSON_UNESCAPED_UNICODE) ?>;

const TYPE_LABELS = <?= json_encode($type_labels) ?>;

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

async function loadBankQuestions(resetSelection) {
    const courseId = document.getElementById('fCourse').value;
    const panel    = document.getElementById('bankPanel');
    if (resetSelection) {
        // Changement de cours : la sélection n'est plus valide
        if (selected.length && !confirm('Changer de cours videra la sélection de questions. Continuer ?')) {
            return;
        }
        if (selected.length) selected = [];
    }
    if (!courseId) {
        panel.innerHTML = '<div class="selected-empty">Sélectionnez d\'abord un cours.</div>';
        bank = [];
        renderPanels();
        return;
    }
    panel.innerHTML = '<div class="selected-empty"><i class="fas fa-spinner fa-spin"></i> Chargement…</div>';
    try {
        const res  = await fetch(`quiz_manage.php?action=get_bank_questions&course_id=${courseId}`);
        const data = await res.json();
        if (!data.success) { showAlert('error', data.message); return; }
        bank = data.questions;
        renderPanels();
    } catch (e) {
        panel.innerHTML = '<div class="selected-empty">Erreur de chargement.</div>';
    }
}

function isSelected(id) {
    return selected.some(q => q.id === id);
}

function addQuestion(id) {
    const q = bank.find(b => b.id === id);
    if (!q || isSelected(id)) return;
    selected.push({ id: q.id, text: q.text, type: q.type, points: q.points, override: null });
    renderPanels();
}

function removeQuestion(id) {
    selected = selected.filter(q => q.id !== id);
    renderPanels();
}

function moveQuestion(id, dir) {
    const i = selected.findIndex(q => q.id === id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= selected.length) return;
    [selected[i], selected[j]] = [selected[j], selected[i]];
    renderPanels();
}

function setOverride(id, value) {
    const q = selected.find(s => s.id === id);
    if (q) q.override = value === '' ? null : parseFloat(value);
    updateTotals();
    syncHiddenInputs();
}

function renderPanels() {
    const bankPanel = document.getElementById('bankPanel');
    const selPanel  = document.getElementById('selectedPanel');

    // Banque (questions non encore sélectionnées)
    const available = bank.filter(q => !isSelected(q.id));
    if (!bank.length) {
        bankPanel.innerHTML = '<div class="selected-empty">Aucune question active dans la banque pour ce cours.<br><a href="quiz_bank.php" style="color:var(--accent-color)">Créer des questions</a></div>';
    } else if (!available.length) {
        bankPanel.innerHTML = '<div class="selected-empty">Toutes les questions sont sélectionnées.</div>';
    } else {
        bankPanel.innerHTML = available.map(q => `
            <div class="q-item">
                <span class="qi-type">${TYPE_LABELS[q.type] || q.type}</span>
                <span class="qi-text" title="${escapeHtml(q.text)}">${escapeHtml(q.text)}</span>
                <span style="font-size:11px;color:rgba(255,255,255,.45);flex-shrink:0">${q.points} pt</span>
                <button type="button" class="qi-add" title="Ajouter" onclick="addQuestion(${q.id})"><i class="fas fa-plus-circle"></i></button>
            </div>
        `).join('');
    }
    document.getElementById('bankCount').textContent = bank.length ? `(${available.length}/${bank.length})` : '';

    // Sélection
    if (!selected.length) {
        selPanel.innerHTML = '<div class="selected-empty">Aucune question sélectionnée.</div>';
    } else {
        selPanel.innerHTML = selected.map((q, i) => `
            <div class="q-item">
                <span style="color:rgba(255,255,255,.4);font-size:11px;width:18px;flex-shrink:0">${i + 1}.</span>
                <span class="qi-type">${TYPE_LABELS[q.type] || q.type}</span>
                <span class="qi-text" title="${escapeHtml(q.text)}">${escapeHtml(q.text)}</span>
                <input type="number" class="qi-override" step="0.25" min="0.25" placeholder="${q.points} pt"
                       value="${q.override !== null ? q.override : ''}"
                       title="Barème pour ce quiz (vide = ${q.points} pt)"
                       onchange="setOverride(${q.id}, this.value)">
                <button type="button" class="qi-move" title="Monter" onclick="moveQuestion(${q.id}, -1)"><i class="fas fa-chevron-up"></i></button>
                <button type="button" class="qi-move" title="Descendre" onclick="moveQuestion(${q.id}, 1)"><i class="fas fa-chevron-down"></i></button>
                <button type="button" class="qi-remove" title="Retirer" onclick="removeQuestion(${q.id})"><i class="fas fa-times-circle"></i></button>
            </div>
        `).join('');
    }
    document.getElementById('selCount').textContent = selected.length ? `(${selected.length})` : '';

    updateTotals();
    syncHiddenInputs();
}

function updateTotals() {
    const total = selected.reduce((sum, q) => sum + (q.override !== null && q.override > 0 ? q.override : q.points), 0);
    document.getElementById('totalPoints').textContent = selected.length
        ? `Total : ${Math.round(total * 100) / 100} points → note ramenée sur 20`
        : '';
}

function syncHiddenInputs() {
    const zone = document.getElementById('hiddenInputs');
    zone.innerHTML = selected.map(q => `
        <input type="hidden" name="question_ids[]" value="${q.id}">
        ${q.override !== null && q.override > 0 ? `<input type="hidden" name="points_override[${q.id}]" value="${q.override}">` : ''}
    `).join('');
}

function toggleEvalFields() {
    const on = document.getElementById('fCounts').checked;
    document.getElementById('evalFields').style.opacity = on ? '1' : '.35';
    document.getElementById('evalFields').querySelectorAll('select').forEach(s => s.disabled = !on);
}

document.getElementById('quizForm').addEventListener('submit', function (e) {
    syncHiddenInputs();
    if (!selected.length && !confirm('Aucune question sélectionnée : le quiz sera enregistré vide (non publiable). Continuer ?')) {
        e.preventDefault();
    }
});

toggleEvalFields();
if (document.getElementById('fCourse').value) {
    loadBankQuestions(false);
}
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>
