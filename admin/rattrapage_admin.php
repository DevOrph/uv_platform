<?php
require_once '../includes/db_connect.php';

// Contrôle du rôle (db_connect.php a déjà vérifié user_id)
if ($_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$admin_id = $_SESSION['user_id'];

// Connexion PDO en réutilisant les credentials de db_connect.php
try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
} catch (PDOException $e) {
    die("Erreur PDO : " . $e->getMessage());
}

$class_id  = isset($_GET['class_id'])  ? (int)$_GET['class_id']  : 0;
$period_id = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;

$flash_success = '';
$flash_error   = '';

// ── Saisie / modification de note par l'admin ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_save_grade') {
    $ratt_id   = (int)($_POST['ratt_id']   ?? 0);
    $grade_raw = trim($_POST['grade']       ?? '');
    $comment   = trim($_POST['comment']     ?? '');

    if ($ratt_id <= 0 || $grade_raw === '' || !is_numeric($grade_raw)) {
        $flash_error = "Identifiant ou note invalide.";
    } else {
        $grade_val = (float)$grade_raw;
        if ($grade_val < 0 || $grade_val > 20) {
            $flash_error = "La note doit être entre 0 et 20.";
        } else {
            $chkStmt = $pdo->prepare("SELECT id FROM rattrapages WHERE id = :rid");
            $chkStmt->execute([':rid' => $ratt_id]);
            if (!$chkStmt->fetch()) {
                $flash_error = "Rattrapage introuvable.";
            } else {
                $updStmt = $pdo->prepare("
                    UPDATE rattrapages
                    SET grade = :grade, comment = :comment,
                        status = 'graded', graded_at = NOW(), graded_by = :by
                    WHERE id = :rid
                ");
                $updStmt->execute([
                    ':grade'   => $grade_val,
                    ':comment' => $comment ?: null,
                    ':by'      => $admin_id,
                    ':rid'     => $ratt_id,
                ]);
                header("Location: rattrapage_admin.php?class_id={$class_id}&period_id={$period_id}&saved=1");
                exit();
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $flash_success = "Note de rattrapage enregistrée avec succès.";
}

$classes = $pdo->query("SELECT id, name FROM classes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$periods = $pdo->query("SELECT id, name FROM evaluation_periods ORDER BY name DESC")->fetchAll(PDO::FETCH_ASSOC);

$rattrapages_data = [];
$stats = ['total' => 0, 'pending' => 0, 'graded' => 0];
$class_name  = '';
$period_name = '';

if ($class_id && $period_id) {
    // Noms pour l'affichage
    foreach ($classes as $c) { if ($c['id'] == $class_id)  $class_name  = $c['name']; }
    foreach ($periods as $p) { if ($p['id'] == $period_id) $period_name = $p['name']; }

    // Identifiants des types d'évaluation
    $devoir_type_id = null;
    $exam_type_id   = null;
    foreach ($pdo->query("SELECT id, name FROM evaluation_types")->fetchAll(PDO::FETCH_ASSOC) as $et) {
        $lower = strtolower(trim($et['name']));
        if ($lower === 'devoir')  $devoir_type_id = (int)$et['id'];
        if ($lower === 'examen')  $exam_type_id   = (int)$et['id'];
    }

    // Étudiants de la classe
    $studentsStmt = $pdo->prepare("
        SELECT id, name FROM users
        WHERE class_id = :cid AND role = 'student' AND blocked = 0
        ORDER BY name
    ");
    $studentsStmt->execute([':cid' => $class_id]);
    $students    = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
    $student_ids = array_column($students, 'id');

    if (!empty($student_ids)) {
        // Toutes les notes de ces étudiants pour cette période (une seule requête)
        $ph = implode(',', array_fill(0, count($student_ids), '?'));
        $bulkStmt = $pdo->prepare("
            SELECT student_id, course_id, evaluation_type_id, grade
            FROM grades
            WHERE student_id IN ($ph) AND evaluation_period_id = ?
        ");
        $bulkStmt->execute(array_merge($student_ids, [$period_id]));
        $grades_index = [];
        foreach ($bulkStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $grades_index[$g['student_id']][$g['course_id']][$g['evaluation_type_id']][] = (float)$g['grade'];
        }

        // UE de la classe pour cette période
        $uesStmt = $pdo->prepare("
            SELECT id, code, name FROM teaching_units
            WHERE class_id = :cid AND semester = :pid
            ORDER BY display_order
        ");
        $uesStmt->execute([':cid' => $class_id, ':pid' => $period_id]);
        $ues = $uesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Cours par UE
        $ue_courses = [];
        foreach ($ues as $ue) {
            $csStmt = $pdo->prepare("
                SELECT id, name, coefficient FROM courses
                WHERE teaching_unit_id = :uid
                  AND JSON_CONTAINS(class_id, JSON_QUOTE(CAST(:cid AS CHAR)), '$')
                  AND semester = :pid
                ORDER BY display_order
            ");
            $csStmt->execute([':uid' => $ue['id'], ':cid' => (string)$class_id, ':pid' => $period_id]);
            $ue_courses[$ue['id']] = $csStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fallback sans UE
        $fallback_courses = [];
        if (empty($ues)) {
            $fbStmt = $pdo->prepare("
                SELECT id, name, coefficient FROM courses
                WHERE JSON_CONTAINS(class_id, JSON_QUOTE(CAST(:cid AS CHAR)), '$')
                  AND semester = :pid
            ");
            $fbStmt->execute([':cid' => (string)$class_id, ':pid' => $period_id]);
            $fallback_courses = $fbStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Calcule la moyenne d'une matière pour un étudiant
        $courseAvg = function(array $sg, int $cid, ?int $dt, ?int $et): float {
            $devoirs = $sg[$cid][$dt] ?? [];
            $dAvg    = count($devoirs) > 0 ? array_sum($devoirs) / count($devoirs) : 0;
            $exams   = $sg[$cid][$et]  ?? [];
            $eGrade  = count($exams)   > 0 ? (float)$exams[0] : 0;
            return round($dAvg * 0.4 + $eGrade * 0.6, 2);
        };

        // Préparer la requête d'upsert une seule fois
        $upsertStmt = $pdo->prepare("
            INSERT INTO rattrapages
                (student_id, course_id, evaluation_period_id, eligibility_reason, original_average, status, created_by)
            VALUES
                (:sid, :cid, :pid, :reason, :avg, 'pending', :creator)
            ON DUPLICATE KEY UPDATE
                eligibility_reason = IF(status = 'pending', VALUES(eligibility_reason), eligibility_reason),
                original_average   = IF(status = 'pending', VALUES(original_average),   original_average)
        ");

        foreach ($students as $student) {
            $sid = $student['id'];
            $sg  = $grades_index[$sid] ?? [];

            if (!empty($ues)) {
                foreach ($ues as $ue) {
                    $courses = $ue_courses[$ue['id']] ?? [];
                    if (empty($courses)) continue;

                    $ueWeightedSum  = 0;
                    $ueTotalCredits = 0;
                    $ueHasElim      = false;
                    $courseAvgs     = [];

                    foreach ($courses as $course) {
                        $avg = $courseAvg($sg, $course['id'], $devoir_type_id, $exam_type_id);
                        if ($avg < 8) $ueHasElim = true;
                        $ueWeightedSum  += $avg * $course['coefficient'];
                        $ueTotalCredits += $course['coefficient'];
                        $courseAvgs[]    = ['course' => $course, 'avg' => $avg];
                    }

                    $ueAvg       = $ueTotalCredits > 0 ? $ueWeightedSum / $ueTotalCredits : 0;
                    $ueValidated = ($ueAvg >= 10 && !$ueHasElim);

                    foreach ($courseAvgs as $ca) {
                        $avgLow = $ca['avg'] < 10;
                        $ueFail = !$ueValidated;
                        if (!$avgLow && !$ueFail) continue;

                        $reason = ($avgLow && $ueFail) ? 'both' : ($avgLow ? 'average_low' : 'ue_not_validated');
                        $upsertStmt->execute([
                            ':sid'     => $sid,
                            ':cid'     => $ca['course']['id'],
                            ':pid'     => $period_id,
                            ':reason'  => $reason,
                            ':avg'     => $ca['avg'],
                            ':creator' => $admin_id,
                        ]);
                    }
                }
            } else {
                foreach ($fallback_courses as $course) {
                    $avg = $courseAvg($sg, $course['id'], $devoir_type_id, $exam_type_id);
                    if ($avg >= 10) continue;
                    $upsertStmt->execute([
                        ':sid'     => $sid,
                        ':cid'     => $course['id'],
                        ':pid'     => $period_id,
                        ':reason'  => 'average_low',
                        ':avg'     => $avg,
                        ':creator' => $admin_id,
                    ]);
                }
            }
        }

        // Lecture pour affichage
        $dispStmt = $pdo->prepare("
            SELECT r.*,
                   u.name  AS student_name,
                   co.name AS course_name,
                   tu.name AS ue_name,
                   tu.code AS ue_code,
                   gb.name AS graded_by_name
            FROM rattrapages r
            JOIN users u   ON r.student_id = u.id
            JOIN courses co ON r.course_id  = co.id
            LEFT JOIN teaching_units tu ON co.teaching_unit_id = tu.id
            LEFT JOIN users gb ON r.graded_by = gb.id
            WHERE u.class_id = :cid AND r.evaluation_period_id = :pid
            ORDER BY u.name, tu.code, co.name
        ");
        $dispStmt->execute([':cid' => $class_id, ':pid' => $period_id]);
        $rattrapages_data = $dispStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rattrapages_data as $r) {
            $stats['total']++;
            $r['status'] === 'graded' ? $stats['graded']++ : $stats['pending']++;
        }
    }
}

$reason_labels = [
    'average_low'      => 'Moyenne < 10',
    'ue_not_validated' => 'UE non validée',
    'both'             => 'Moyenne < 10 + UE non validée',
];

// Regroupement par UE pour affichage
$by_ue = [];
$ue_list_for_filter = [];
foreach ($rattrapages_data as $r) {
    $ue_key = $r['ue_code'] ?: '__none__';
    $ue_label = $r['ue_code'] ? ($r['ue_code'] . ' — ' . $r['ue_name']) : 'Sans UE';
    if (!isset($by_ue[$ue_key])) {
        $by_ue[$ue_key] = ['label' => $ue_label, 'code' => $r['ue_code'] ?: '', 'rows' => [], 'pending' => 0, 'graded' => 0];
        $ue_list_for_filter[$ue_key] = $ue_label;
    }
    $by_ue[$ue_key]['rows'][] = $r;
    if ($r['status'] === 'graded') $by_ue[$ue_key]['graded']++;
    else $by_ue[$ue_key]['pending']++;
}
$pct = $stats['total'] > 0 ? round($stats['graded'] / $stats['total'] * 100) : 0;
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
    <title>Rattrapage — Administration</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

        /* Filtres */
        .filter-card { background:var(--secondary-bg); border-radius:10px; padding:20px; margin-bottom:25px; border:1px solid var(--border-color); }
        .filter-row { display:flex; gap:15px; flex-wrap:wrap; align-items:flex-end; }
        .filter-group { display:flex; flex-direction:column; gap:6px; flex:1; min-width:180px; }
        .filter-group label { font-size:13px; color:rgba(255,255,255,.7); }
        select, input[type=text] { background:#0d3152; color:var(--text-light); border:1px solid var(--border-color); border-radius:6px; padding:9px 12px; font-size:14px; width:100%; }
        select:focus, input[type=text]:focus { outline:none; border-color:var(--accent-color); }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:6px; border:none; cursor:pointer; font-size:14px; font-weight:600; transition:all .3s; text-decoration:none; }
        .btn-primary { background:var(--accent-color); color:#fff; }
        .btn-primary:hover { background:#0288c7; }

        /* Stats */
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:15px; margin-bottom:20px; }
        .stat-card { background:var(--secondary-bg); border-radius:10px; padding:18px; text-align:center; border:1px solid var(--border-color); }
        .stat-card .stat-value { font-size:30px; font-weight:700; color:var(--accent-color); }
        .stat-card.pending .stat-value { color:var(--warning-color); }
        .stat-card.graded  .stat-value { color:var(--success-color); }
        .stat-card .stat-label { font-size:12px; color:rgba(255,255,255,.6); margin-top:4px; }
        .progress-card { display:flex; flex-direction:column; justify-content:center; gap:8px; }
        .progress-label { font-size:13px; color:rgba(255,255,255,.7); display:flex; justify-content:space-between; }
        .progress-outer { background:rgba(255,255,255,.1); border-radius:20px; height:12px; overflow:hidden; }
        .progress-inner { height:100%; border-radius:20px; background:var(--success-color); transition:width .6s ease; }
        .progress-pct { font-size:22px; font-weight:700; color:var(--success-color); text-align:center; }

        /* Barre de recherche/filtre secondaire */
        .toolbar { display:flex; gap:12px; flex-wrap:wrap; align-items:center; margin-bottom:18px; }
        .toolbar input[type=text] { max-width:260px; }
        .toolbar select { max-width:200px; }

        /* Sections UE */
        .ue-section { margin-bottom:18px; border:1px solid var(--border-color); border-radius:10px; overflow:hidden; }
        .ue-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:rgba(3,155,229,.12); cursor:pointer; user-select:none; gap:10px; flex-wrap:wrap; }
        .ue-header:hover { background:rgba(3,155,229,.2); }
        .ue-header-left { display:flex; align-items:center; gap:10px; font-weight:700; font-size:14px; }
        .ue-header-right { display:flex; align-items:center; gap:12px; font-size:13px; }
        .ue-badge-pending { background:rgba(243,156,18,.2); color:var(--warning-color); border-radius:10px; padding:2px 10px; font-size:12px; font-weight:600; }
        .ue-badge-done    { background:rgba(46,204,113,.2);  color:var(--success-color); border-radius:10px; padding:2px 10px; font-size:12px; font-weight:600; }
        .ue-toggle { transition:transform .25s; color:rgba(255,255,255,.5); }
        .ue-section.collapsed .ue-toggle { transform:rotate(-90deg); }
        .ue-body { overflow:hidden; }
        .ue-section.collapsed .ue-body { display:none; }

        /* Tableau */
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        thead th { background:rgba(3,155,229,.3); padding:10px 12px; text-align:left; white-space:nowrap; cursor:pointer; position:relative; }
        thead th:hover { background:rgba(3,155,229,.45); }
        thead th .sort-icon { margin-left:5px; opacity:.5; font-size:11px; }
        thead th.sort-asc .sort-icon::after  { content:'▲'; opacity:1; }
        thead th.sort-desc .sort-icon::after { content:'▼'; opacity:1; }
        thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content:'⇅'; }
        tbody tr { transition:background .15s; }
        tbody tr:nth-child(even) { background:rgba(255,255,255,.03); }
        tbody tr:hover { background:rgba(3,155,229,.1); }
        tbody tr[data-hidden="1"] { display:none; }
        td { padding:9px 12px; border-bottom:1px solid var(--border-color); vertical-align:middle; }

        /* Badges */
        .badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; }
        .badge-pending { background:rgba(243,156,18,.2); color:var(--warning-color); }
        .badge-graded  { background:rgba(46,204,113,.2); color:var(--success-color); }
        .badge-reason  { background:rgba(3,155,229,.2);  color:var(--accent-color); }
        .avg-low { color:var(--danger-color); font-weight:700; }
        .avg-ok  { color:var(--success-color); font-weight:700; }

        /* Divers */
        .alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; display:flex; align-items:flex-start; gap:10px; }
        .alert-info    { background:rgba(3,155,229,.12); border:1px solid rgba(3,155,229,.3); }
        .alert-success { background:rgba(46,204,113,.12); border:1px solid rgba(46,204,113,.3); color:var(--success-color); }
        .alert-error   { background:rgba(231,76,60,.12);  border:1px solid rgba(231,76,60,.3);  color:var(--danger-color); }
        .no-data { text-align:center; padding:50px 20px; color:rgba(255,255,255,.4); }
        .no-data i { display:block; font-size:48px; margin-bottom:15px; }
        .count-badge { background:rgba(255,255,255,.1); border-radius:10px; padding:2px 8px; font-size:12px; font-weight:600; }

        /* Saisie de note dans le tableau */
        .saisie-cell { min-width:260px; }
        .grade-form { display:flex; flex-direction:column; gap:6px; }
        .grade-form-top { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
        .grade-form-top input[type=number] {
            width:90px; background:#0d3152; color:var(--text-light);
            border:1px solid var(--border-color); border-radius:6px; padding:7px 10px; font-size:13px;
        }
        .grade-form-top input[type=number]:focus { outline:none; border-color:var(--accent-color); }
        .grade-form textarea {
            background:#0d3152; color:var(--text-light); border:1px solid var(--border-color);
            border-radius:6px; padding:7px 10px; font-size:12px; width:100%; min-height:44px;
            resize:vertical; font-family:inherit;
        }
        .grade-form textarea:focus { outline:none; border-color:var(--accent-color); }
        .btn-save {
            display:inline-flex; align-items:center; gap:5px; padding:7px 13px; border-radius:6px;
            border:none; cursor:pointer; font-size:12px; font-weight:600;
            background:var(--accent-color); color:#fff; transition:background .2s; white-space:nowrap;
        }
        .btn-save:hover { background:#0288c7; }
        .btn-cancel {
            display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px;
            border-radius:6px; border:1px solid rgba(255,255,255,.2); cursor:pointer;
            font-size:13px; background:transparent; color:rgba(255,255,255,.5); transition:all .2s;
        }
        .btn-cancel:hover { border-color:var(--danger-color); color:var(--danger-color); }
        .btn-edit-toggle {
            display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:5px;
            border:1px solid rgba(3,155,229,.4); cursor:pointer; font-size:11px; font-weight:600;
            background:rgba(3,155,229,.08); color:var(--accent-color); transition:all .2s; margin-top:6px;
        }
        .btn-edit-toggle:hover { background:rgba(3,155,229,.22); }
        .grade-val { font-size:15px; font-weight:700; }
        .grade-meta { font-size:11px; color:rgba(255,255,255,.45); margin-top:3px; }
        .grade-comment-display { font-size:11px; color:rgba(255,255,255,.5); font-style:italic; margin-top:2px; }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle — Administration</h1>
        <nav><ul>
            <li><a href="admin_dashboard.php"><i class="fas fa-home"></i> Accueil</a></li>
            <li><a href="../grades/grades_management.php"><i class="fas fa-chart-bar"></i> Notes</a></li>
            <li><a href="rattrapage_admin.php" class="active"><i class="fas fa-redo"></i> Rattrapage</a></li>
            <li><a href="admin_profile.php"><i class="fas fa-user-cog"></i> Profil</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul></nav>
    </div>
</header>

<main>
    <h2><i class="fas fa-redo"></i> Gestion des Rattrapages</h2>

    <?php if ($flash_success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle" style="flex-shrink:0;margin-top:2px"></i> <?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>
    <?php if ($flash_error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle" style="flex-shrink:0;margin-top:2px"></i> <?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>

    <!-- Filtres principaux -->
    <div class="filter-card">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="class_id"><i class="fas fa-users"></i> Classe</label>
                    <select name="class_id" id="class_id" required>
                        <option value="">-- Sélectionner une classe --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] == $class_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="period_id"><i class="fas fa-calendar-alt"></i> Période</label>
                    <select name="period_id" id="period_id" required>
                        <option value="">-- Sélectionner une période --</option>
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $p['id'] == $period_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="flex:0">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync-alt"></i> Calculer éligibilité
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($class_id && $period_id): ?>

        <div class="alert alert-info">
            <i class="fas fa-info-circle" style="margin-top:2px;flex-shrink:0"></i>
            <div>
                Éligibilité calculée pour <strong><?= htmlspecialchars($class_name) ?></strong>
                — période <strong><?= htmlspecialchars($period_name) ?></strong>.
                Les entrées <em>en attente</em> sont recalculées automatiquement ; les rattrapages déjà notés sont préservés.
            </div>
        </div>

        <!-- Statistiques + progression -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total'] ?></div>
                <div class="stat-label">Éligibles total</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value"><?= $stats['pending'] ?></div>
                <div class="stat-label"><i class="fas fa-hourglass-half"></i> En attente</div>
            </div>
            <div class="stat-card graded">
                <div class="stat-value"><?= $stats['graded'] ?></div>
                <div class="stat-label"><i class="fas fa-check-circle"></i> Notés</div>
            </div>
            <div class="stat-card progress-card">
                <div class="progress-pct"><?= $pct ?>%</div>
                <div class="progress-outer">
                    <div class="progress-inner" style="width:<?= $pct ?>%"></div>
                </div>
                <div class="progress-label">
                    <span>Progression</span>
                    <span><?= $stats['graded'] ?> / <?= $stats['total'] ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($rattrapages_data)): ?>

        <!-- Barre de recherche et filtres secondaires -->
        <div class="toolbar">
            <input type="text" id="searchInput" placeholder="🔍  Rechercher un étudiant ou une matière…" oninput="applyFilters()">
            <select id="filterStatus" onchange="applyFilters()">
                <option value="">Tous les statuts</option>
                <option value="pending">En attente</option>
                <option value="graded">Notés</option>
            </select>
            <select id="filterUE" onchange="applyFilters()">
                <option value="">Toutes les UE</option>
                <?php foreach ($ue_list_for_filter as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <span id="resultCount" class="count-badge" style="display:none"></span>
        </div>

        <!-- Sections par UE -->
        <?php foreach ($by_ue as $ue_key => $ue_data): ?>
        <div class="ue-section" id="ue-sec-<?= htmlspecialchars($ue_key) ?>">
            <div class="ue-header" onclick="toggleUE('<?= htmlspecialchars($ue_key) ?>')">
                <div class="ue-header-left">
                    <i class="fas fa-layer-group" style="color:var(--accent-color)"></i>
                    <?= htmlspecialchars($ue_data['label']) ?>
                </div>
                <div class="ue-header-right">
                    <?php if ($ue_data['pending'] > 0): ?>
                        <span class="ue-badge-pending"><i class="fas fa-hourglass-half"></i> <?= $ue_data['pending'] ?> en attente</span>
                    <?php endif; ?>
                    <?php if ($ue_data['graded'] > 0): ?>
                        <span class="ue-badge-done"><i class="fas fa-check"></i> <?= $ue_data['graded'] ?> notés</span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down ue-toggle"></i>
                </div>
            </div>
            <div class="ue-body">
                <div class="table-wrapper">
                    <table data-ue-key="<?= htmlspecialchars($ue_key) ?>">
                        <thead>
                            <tr>
                                <th onclick="sortTable(this,0)"><i class="fas fa-user-graduate"></i> Étudiant <span class="sort-icon"></span></th>
                                <th onclick="sortTable(this,1)"><i class="fas fa-book"></i> Matière <span class="sort-icon"></span></th>
                                <th onclick="sortTable(this,2)">Moy. orig. <span class="sort-icon"></span></th>
                                <th>Raison</th>
                                <th onclick="sortTable(this,4)">Statut <span class="sort-icon"></span></th>
                                <th style="min-width:270px">Note de rattrapage</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ue_data['rows'] as $r): ?>
                            <tr data-student="<?= htmlspecialchars(strtolower($r['student_name'])) ?>"
                                data-course="<?= htmlspecialchars(strtolower($r['course_name'])) ?>"
                                data-status="<?= $r['status'] ?>"
                                data-ue="<?= htmlspecialchars($ue_key) ?>">
                                <td><?= htmlspecialchars($r['student_name']) ?></td>
                                <td><?= htmlspecialchars($r['course_name']) ?></td>
                                <td class="<?= $r['original_average'] < 10 ? 'avg-low' : 'avg-ok' ?>" data-val="<?= $r['original_average'] ?>">
                                    <?= $r['original_average'] !== null ? number_format($r['original_average'], 2) : '—' ?>
                                </td>
                                <td>
                                    <span class="badge badge-reason">
                                        <?= $reason_labels[$r['eligibility_reason']] ?? $r['eligibility_reason'] ?>
                                    </span>
                                </td>
                                <td data-val="<?= $r['status'] === 'graded' ? 1 : 0 ?>">
                                    <span class="badge badge-<?= $r['status'] ?>">
                                        <?= $r['status'] === 'graded' ? '<i class="fas fa-check"></i> Noté' : '<i class="fas fa-hourglass-half"></i> En attente' ?>
                                    </span>
                                </td>
                                <td class="saisie-cell">
                                <?php if ($r['status'] === 'pending'): ?>
                                    <form method="POST" action="?class_id=<?= $class_id ?>&period_id=<?= $period_id ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                        <input type="hidden" name="action"  value="admin_save_grade">
                                        <input type="hidden" name="ratt_id" value="<?= $r['id'] ?>">
                                        <div class="grade-form">
                                            <div class="grade-form-top">
                                                <input type="number" name="grade" step="0.01" min="0" max="20" placeholder="Note /20" required>
                                                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer</button>
                                            </div>
                                            <textarea name="comment" placeholder="Commentaire (optionnel)…"></textarea>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <!-- Affichage lecture seule -->
                                    <div id="gr-<?= $r['id'] ?>">
                                        <span class="grade-val <?= $r['grade'] >= 10 ? 'avg-ok' : 'avg-low' ?>">
                                            <i class="fas <?= $r['grade'] >= 10 ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                            <?= number_format($r['grade'], 2) ?> / 20
                                        </span>
                                        <div class="grade-meta">
                                            <?= $r['graded_by_name'] ? htmlspecialchars($r['graded_by_name']) : '' ?>
                                            <?= $r['graded_at'] ? ' · ' . date('d/m/Y H:i', strtotime($r['graded_at'])) : '' ?>
                                        </div>
                                        <?php if ($r['comment']): ?>
                                            <div class="grade-comment-display">
                                                <?= htmlspecialchars(mb_substr($r['comment'], 0, 60)) ?><?= mb_strlen($r['comment']) > 60 ? '…' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                        <button type="button" class="btn-edit-toggle" onclick="toggleEdit(<?= $r['id'] ?>)">
                                            <i class="fas fa-pen"></i> Modifier
                                        </button>
                                    </div>
                                    <!-- Formulaire de modification (masqué) -->
                                    <div id="ge-<?= $r['id'] ?>" style="display:none">
                                        <form method="POST" action="?class_id=<?= $class_id ?>&period_id=<?= $period_id ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                            <input type="hidden" name="action"  value="admin_save_grade">
                                            <input type="hidden" name="ratt_id" value="<?= $r['id'] ?>">
                                            <div class="grade-form">
                                                <div class="grade-form-top">
                                                    <input type="number" name="grade" step="0.01" min="0" max="20"
                                                           value="<?= htmlspecialchars((string)$r['grade']) ?>" required>
                                                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Mettre à jour</button>
                                                    <button type="button" class="btn-cancel" onclick="toggleEdit(<?= $r['id'] ?>)" title="Annuler">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                                <textarea name="comment" placeholder="Commentaire (optionnel)…"><?= htmlspecialchars($r['comment'] ?? '') ?></textarea>
                                            </div>
                                        </form>
                                    </div>
                                <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Message quand aucun résultat après filtrage -->
        <div id="noFilterResult" style="display:none" class="no-data">
            <i class="fas fa-search"></i>
            <p>Aucune ligne ne correspond aux filtres actifs.</p>
        </div>

        <?php else: ?>
            <div class="no-data">
                <i class="fas fa-check-circle" style="color:var(--success-color)"></i>
                <p>Aucun étudiant éligible au rattrapage pour cette classe et cette période.</p>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-filter"></i>
            <p>Sélectionnez une classe et une période pour calculer les éligibilités.</p>
        </div>
    <?php endif; ?>
</main>

<script>
function toggleUE(key) {
    const sec = document.getElementById('ue-sec-' + key);
    if (sec) sec.classList.toggle('collapsed');
}

function toggleEdit(id) {
    const display = document.getElementById('gr-' + id);
    const form    = document.getElementById('ge-' + id);
    if (!display || !form) return;
    const opening = form.style.display === 'none';
    display.style.display = opening ? 'none' : '';
    form.style.display    = opening ? ''     : 'none';
}

function applyFilters() {
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    const statusF = document.getElementById('filterStatus').value;
    const ueF     = document.getElementById('filterUE').value;

    let visible = 0;
    document.querySelectorAll('tbody tr').forEach(tr => {
        const student = tr.dataset.student || '';
        const course  = tr.dataset.course  || '';
        const status  = tr.dataset.status  || '';
        const ue      = tr.dataset.ue      || '';

        const matchSearch = !search || student.includes(search) || course.includes(search);
        const matchStatus = !statusF || status === statusF;
        const matchUE     = !ueF    || ue === ueF;

        if (matchSearch && matchStatus && matchUE) {
            tr.removeAttribute('data-hidden');
            tr.style.display = '';
            visible++;
        } else {
            tr.dataset.hidden = '1';
            tr.style.display = 'none';
        }
    });

    // Afficher/masquer les sections UE vides
    document.querySelectorAll('.ue-section').forEach(sec => {
        const anyVisible = [...sec.querySelectorAll('tbody tr')].some(r => r.style.display !== 'none');
        sec.style.display = anyVisible ? '' : 'none';
    });

    const countEl = document.getElementById('resultCount');
    const hasFilter = search || statusF || ueF;
    if (hasFilter) {
        countEl.style.display = '';
        countEl.textContent = visible + ' résultat' + (visible > 1 ? 's' : '');
    } else {
        countEl.style.display = 'none';
        document.querySelectorAll('.ue-section').forEach(s => s.style.display = '');
    }

    document.getElementById('noFilterResult').style.display = (hasFilter && visible === 0) ? '' : 'none';
}

function sortTable(th, colIndex) {
    const table = th.closest('table');
    const tbody = table.querySelector('tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr'));

    const currentDir = th.classList.contains('sort-asc') ? 'asc' : 'desc';
    const newDir = currentDir === 'asc' ? 'desc' : 'asc';

    table.querySelectorAll('thead th').forEach(t => t.classList.remove('sort-asc','sort-desc'));
    th.classList.add('sort-' + newDir);

    rows.sort((a, b) => {
        const cellA = a.cells[colIndex];
        const cellB = b.cells[colIndex];
        const valA = cellA.dataset.val !== undefined ? parseFloat(cellA.dataset.val) : NaN;
        const valB = cellB.dataset.val !== undefined ? parseFloat(cellB.dataset.val) : NaN;

        let cmp;
        if (!isNaN(valA) && !isNaN(valB)) {
            cmp = valA - valB;
        } else {
            cmp = (cellA.textContent.trim()).localeCompare(cellB.textContent.trim(), 'fr');
        }
        return newDir === 'asc' ? cmp : -cmp;
    });

    rows.forEach(r => tbody.appendChild(r));
}
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>
