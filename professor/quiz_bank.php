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
// ENDPOINTS AJAX (JSON)
// ============================================================

// ── Lecture d'une question pour édition (GET) ───────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_question') {
    header('Content-Type: application/json');
    $qid      = (int) ($_GET['id'] ?? 0);
    $question = quiz_user_owns_question($conn, $qid, $user_id, $user_role);
    if (!$question) {
        echo json_encode(['success' => false, 'message' => 'Question introuvable ou accès refusé']);
        exit();
    }

    $options = [];
    $stmt = $conn->prepare("SELECT id, option_text, is_correct, display_order FROM quiz_question_options WHERE question_id = ? ORDER BY display_order ASC, id ASC");
    $stmt->bind_param("i", $qid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $options[] = ['text' => $row['option_text'], 'correct' => (bool) $row['is_correct']];
    }

    $accepted = [];
    $stmt = $conn->prepare("SELECT accepted_value FROM quiz_short_answers WHERE question_id = ? ORDER BY id ASC");
    $stmt->bind_param("i", $qid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $accepted[] = $row['accepted_value'];
    }

    echo json_encode([
        'success'  => true,
        'question' => [
            'id'            => (int) $question['id'],
            'course_id'     => (int) $question['course_id'],
            'type'          => $question['type'],
            'question_text' => $question['question_text'],
            'points'        => (float) $question['points'],
            'explanation'   => $question['explanation'],
            'is_active'     => (int) $question['is_active'],
            'locked'        => quiz_question_is_locked($conn, $qid),
        ],
        'options'  => $options,
        'accepted' => $accepted,
    ]);
    exit();
}

// ── Actions POST (JSON) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $payload = json_decode(file_get_contents('php://input'), true);
    $action  = $payload['action'] ?? '';

    // ── Activation / désactivation ───────────────────────────
    if ($action === 'toggle_active') {
        $qid      = (int) ($payload['question_id'] ?? 0);
        $question = quiz_user_owns_question($conn, $qid, $user_id, $user_role);
        if (!$question) {
            echo json_encode(['success' => false, 'message' => 'Question introuvable ou accès refusé']);
            exit();
        }
        $new_state = $question['is_active'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE quiz_questions SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_state, $qid);
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => $new_state ? 'Question réactivée' : 'Question désactivée', 'is_active' => $new_state]);
        exit();
    }

    // ── Création / édition ───────────────────────────────────
    if ($action === 'save_question') {
        $qid           = (int) ($payload['question_id'] ?? 0);
        $course_id     = (int) ($payload['course_id'] ?? 0);
        $type          = $payload['type'] ?? '';
        $question_text = trim($payload['question_text'] ?? '');
        $points        = (float) ($payload['points'] ?? 0);
        $explanation   = trim($payload['explanation'] ?? '');
        $options       = $payload['options'] ?? [];
        $short_answers = $payload['short_answers'] ?? [];
        $tf_correct    = $payload['tf_correct'] ?? '';

        // ── Validations générales ────────────────────────────
        if (!in_array($type, ['single_choice', 'multiple_choice', 'true_false', 'short_answer'], true)) {
            echo json_encode(['success' => false, 'message' => 'Type de question invalide']);
            exit();
        }
        if ($question_text === '') {
            echo json_encode(['success' => false, 'message' => 'Le texte de la question est obligatoire']);
            exit();
        }
        if ($points <= 0 || $points > 100) {
            echo json_encode(['success' => false, 'message' => 'Le barème doit être compris entre 0.25 et 100 points']);
            exit();
        }
        if (!quiz_user_owns_course($conn, $course_id, $user_id, $user_role)) {
            echo json_encode(['success' => false, 'message' => 'Cours invalide ou accès refusé']);
            exit();
        }

        // ── Validations par type + préparation des lignes ────
        $option_rows = []; // [text, is_correct]
        $answer_rows = []; // [accepted_value]

        if ($type === 'single_choice' || $type === 'multiple_choice') {
            $nb_correct = 0;
            foreach ($options as $opt) {
                $text = trim($opt['text'] ?? '');
                if ($text === '') {
                    continue;
                }
                $correct = !empty($opt['correct']) ? 1 : 0;
                $nb_correct += $correct;
                $option_rows[] = [$text, $correct];
            }
            if (count($option_rows) < 2) {
                echo json_encode(['success' => false, 'message' => 'Au moins 2 options sont requises']);
                exit();
            }
            if ($type === 'single_choice' && $nb_correct !== 1) {
                echo json_encode(['success' => false, 'message' => 'Un QCM simple doit avoir exactement 1 bonne réponse']);
                exit();
            }
            if ($type === 'multiple_choice' && $nb_correct < 1) {
                echo json_encode(['success' => false, 'message' => 'Un QCM multiple doit avoir au moins 1 bonne réponse']);
                exit();
            }
        } elseif ($type === 'true_false') {
            // Génération automatique des 2 options Vrai / Faux
            if (!in_array($tf_correct, ['true', 'false'], true)) {
                echo json_encode(['success' => false, 'message' => 'Indiquez si la bonne réponse est Vrai ou Faux']);
                exit();
            }
            $option_rows[] = ['Vrai', $tf_correct === 'true' ? 1 : 0];
            $option_rows[] = ['Faux', $tf_correct === 'false' ? 1 : 0];
        } elseif ($type === 'short_answer') {
            $seen = [];
            foreach ((array) $short_answers as $variant) {
                $normalized = quiz_normalize_answer((string) $variant);
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $answer_rows[]     = $normalized;
            }
            if (empty($answer_rows)) {
                echo json_encode(['success' => false, 'message' => 'Au moins une réponse acceptée est requise']);
                exit();
            }
        }

        // ── Édition : propriété + verrou structurel ──────────
        if ($qid > 0) {
            $existing = quiz_user_owns_question($conn, $qid, $user_id, $user_role);
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Question introuvable ou accès refusé']);
                exit();
            }
            if (quiz_question_is_locked($conn, $qid)) {
                echo json_encode(['success' => false, 'message' => "Cette question est utilisée dans un quiz ayant déjà des tentatives : elle ne peut plus être modifiée. Désactivez-la et créez-en une nouvelle."]);
                exit();
            }
        }

        try {
            $conn->begin_transaction();

            if ($qid > 0) {
                $stmt = $conn->prepare("UPDATE quiz_questions SET course_id = ?, type = ?, question_text = ?, points = ?, explanation = ? WHERE id = ?");
                $explanation_db = $explanation !== '' ? $explanation : null;
                $stmt->bind_param("issdsi", $course_id, $type, $question_text, $points, $explanation_db, $qid);
                $stmt->execute();

                // Remplacement complet des options / variantes
                $del = $conn->prepare("DELETE FROM quiz_question_options WHERE question_id = ?");
                $del->bind_param("i", $qid);
                $del->execute();
                $del = $conn->prepare("DELETE FROM quiz_short_answers WHERE question_id = ?");
                $del->bind_param("i", $qid);
                $del->execute();
            } else {
                $annee = ANNEE_ACADEMIQUE_COURANTE;
                $stmt = $conn->prepare("INSERT INTO quiz_questions (course_id, teacher_id, type, question_text, points, explanation, annee_academique) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $explanation_db = $explanation !== '' ? $explanation : null;
                $stmt->bind_param("isssdss", $course_id, $user_id, $type, $question_text, $points, $explanation_db, $annee);
                $stmt->execute();
                $qid = $conn->insert_id;
            }

            if (!empty($option_rows)) {
                $ins = $conn->prepare("INSERT INTO quiz_question_options (question_id, option_text, is_correct, display_order) VALUES (?, ?, ?, ?)");
                foreach ($option_rows as $order => [$text, $correct]) {
                    $ins->bind_param("isii", $qid, $text, $correct, $order);
                    $ins->execute();
                }
            }
            if (!empty($answer_rows)) {
                $ins = $conn->prepare("INSERT INTO quiz_short_answers (question_id, accepted_value) VALUES (?, ?)");
                foreach ($answer_rows as $value) {
                    $ins->bind_param("is", $qid, $value);
                    $ins->execute();
                }
            }

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Question enregistrée', 'question_id' => $qid]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
        }
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Action inconnue']);
    exit();
}

// ============================================================
// AFFICHAGE : liste des questions (filtres serveur)
// ============================================================
$courses = quiz_get_teacher_courses($conn, $user_id, $user_role);

$filter_course = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
$filter_type   = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';

$sql = "SELECT q.*, c.name AS course_name,
               (SELECT COUNT(*) FROM quiz_question_options o WHERE o.question_id = q.id)  AS nb_options,
               (SELECT COUNT(*) FROM quiz_short_answers s WHERE s.question_id = q.id)     AS nb_variants,
               (SELECT COUNT(*) FROM quiz_question_links l WHERE l.question_id = q.id)    AS nb_quizzes
        FROM quiz_questions q
        JOIN courses c ON c.id = q.course_id
        WHERE 1=1";
$params = [];
$types  = "";

if ($user_role !== 'admin') {
    $sql     .= " AND q.teacher_id = ?";
    $params[] = $user_id;
    $types   .= "s";
}
if ($filter_course > 0) {
    $sql     .= " AND q.course_id = ?";
    $params[] = $filter_course;
    $types   .= "i";
}
if (in_array($filter_type, ['single_choice', 'multiple_choice', 'true_false', 'short_answer'], true)) {
    $sql     .= " AND q.type = ?";
    $params[] = $filter_type;
    $types   .= "s";
}
if ($filter_status === 'active') {
    $sql .= " AND q.is_active = 1";
} elseif ($filter_status === 'inactive') {
    $sql .= " AND q.is_active = 0";
}
$sql .= " ORDER BY q.created_at DESC, q.id DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$type_labels = [
    'single_choice'   => 'QCM simple',
    'multiple_choice' => 'QCM multiple',
    'true_false'      => 'Vrai / Faux',
    'short_answer'    => 'Réponse courte',
];
$cnt_active   = 0;
$cnt_inactive = 0;
foreach ($questions as $q) {
    $q['is_active'] ? $cnt_active++ : $cnt_inactive++;
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
    <title>Banque de questions — Quiz</title>
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

        .filter-card { background:var(--secondary-bg); border-radius:10px; padding:20px; margin-bottom:25px; border:1px solid var(--border-color); }
        .filter-row { display:flex; gap:15px; flex-wrap:wrap; align-items:flex-end; }
        .filter-group { display:flex; flex-direction:column; gap:6px; flex:1; min-width:170px; }
        .filter-group label { font-size:13px; color:rgba(255,255,255,.7); }
        select, input[type=text], input[type=number], textarea {
            background:#0d3152; color:var(--text-light); border:1px solid var(--border-color);
            border-radius:6px; padding:9px 12px; font-size:14px; width:100%;
        }
        select:focus, input:focus, textarea:focus { outline:none; border-color:var(--accent-color); }
        textarea { resize:vertical; min-height:70px; font-family:inherit; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border-radius:6px; border:none; cursor:pointer; font-size:13px; font-weight:600; transition:all .3s; text-decoration:none; white-space:nowrap; }
        .btn-primary { background:var(--accent-color); color:#fff; }
        .btn-primary:hover { background:#0288c7; }
        .btn-outline { background:transparent; color:var(--text-light); border:1px solid var(--border-color); }
        .btn-outline:hover { border-color:var(--accent-color); color:var(--accent-color); }
        .btn-danger-outline { background:transparent; color:var(--danger-color); border:1px solid rgba(231,76,60,.4); }
        .btn-danger-outline:hover { background:rgba(231,76,60,.12); }
        .btn-sm { padding:5px 11px; font-size:12px; }

        .alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; display:none; align-items:center; gap:10px; }
        .alert.show { display:flex; }
        .alert-success { background:rgba(46,204,113,.12); border:1px solid rgba(46,204,113,.35); color:var(--success-color); }
        .alert-error   { background:rgba(231,76,60,.12);  border:1px solid rgba(231,76,60,.35);  color:var(--danger-color); }

        .toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
        .search-row { position:relative; flex:1; min-width:220px; }
        .search-row input { padding-left:36px; }
        .search-row .search-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:rgba(255,255,255,.4); pointer-events:none; }

        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        thead th { background:rgba(3,155,229,.3); padding:11px 12px; text-align:left; white-space:nowrap; }
        tbody tr { transition:background .15s; }
        tbody tr:nth-child(even) { background:rgba(255,255,255,.03); }
        tbody tr:hover { background:rgba(3,155,229,.08); }
        td { padding:9px 12px; border-bottom:1px solid var(--border-color); vertical-align:middle; }
        .q-text { max-width:380px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .q-inactive td { opacity:.45; }

        .badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
        .badge-single_choice   { background:rgba(3,155,229,.18);  color:var(--accent-color); }
        .badge-multiple_choice { background:rgba(155,89,182,.2);  color:#bb8fce; }
        .badge-true_false      { background:rgba(46,204,113,.15); color:var(--success-color); }
        .badge-short_answer    { background:rgba(243,156,18,.18); color:var(--warning-color); }
        .badge-active   { background:rgba(46,204,113,.2); color:var(--success-color); }
        .badge-inactive { background:rgba(231,76,60,.18); color:var(--danger-color); }
        .badge-used { background:rgba(255,255,255,.12); color:rgba(255,255,255,.7); }

        .no-data { text-align:center; padding:40px; color:rgba(255,255,255,.4); }
        .no-data i { display:block; font-size:44px; margin-bottom:14px; }

        /* Modal */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:100; align-items:flex-start; justify-content:center; padding:40px 16px; overflow-y:auto; }
        .modal-overlay.open { display:flex; }
        .modal { background:var(--secondary-bg); border:1px solid var(--border-color); border-radius:12px; width:100%; max-width:680px; padding:24px; }
        .modal h3 { color:var(--accent-color); font-size:17px; margin-bottom:18px; display:flex; align-items:center; gap:9px; }
        .form-grid { display:grid; grid-template-columns:2fr 1fr; gap:14px; margin-bottom:14px; }
        .form-group { display:flex; flex-direction:column; gap:6px; }
        .form-group label { font-size:13px; color:rgba(255,255,255,.7); }
        .form-group.full { grid-column:1 / -1; }
        .modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }

        /* Options dynamiques */
        .option-row { display:flex; gap:8px; align-items:center; margin-bottom:8px; }
        .option-row input[type=text] { flex:1; }
        .option-correct { display:flex; align-items:center; gap:5px; font-size:12px; color:rgba(255,255,255,.7); white-space:nowrap; cursor:pointer; }
        .option-remove { background:none; border:none; color:var(--danger-color); cursor:pointer; font-size:15px; padding:4px; }
        .tf-choice { display:flex; gap:18px; padding:6px 0; }
        .tf-choice label { display:flex; align-items:center; gap:7px; cursor:pointer; }
        .hint { font-size:12px; color:rgba(255,255,255,.45); margin-top:4px; }
        .modal-lock-warning { background:rgba(243,156,18,.12); border:1px solid rgba(243,156,18,.35); color:var(--warning-color); padding:10px 14px; border-radius:8px; font-size:13px; margin-bottom:14px; display:none; }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle — Enseignant</h1>
        <nav><ul>
            <li><a href="teacher_dashboard.php"><i class="fas fa-home"></i> Accueil</a></li>
            <li><a href="grades_management.php"><i class="fas fa-chart-line"></i> Notes</a></li>
            <li><a href="quiz_bank.php" class="active"><i class="fas fa-database"></i> Banque de questions</a></li>
            <li><a href="quiz_manage.php"><i class="fas fa-clipboard-question"></i> Mes Quiz</a></li>
            <li><a href="quiz_aiken_import.php"><i class="fas fa-file-import"></i> Import Aiken</a></li>
            <li><a href="teacher_profile.php"><i class="fas fa-user"></i> Profil</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul></nav>
    </div>
</header>

<main>
    <h2><i class="fas fa-database"></i> Banque de questions</h2>

    <div class="alert alert-success" id="alertSuccess"><i class="fas fa-check-circle"></i> <span></span></div>
    <div class="alert alert-error" id="alertError"><i class="fas fa-exclamation-circle"></i> <span></span></div>

    <!-- Filtres -->
    <div class="filter-card">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-book"></i> Cours</label>
                    <select name="course_id">
                        <option value="">Tous mes cours</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] == $filter_course ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (S<?= $c['semester'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-shapes"></i> Type</label>
                    <select name="type">
                        <option value="">Tous les types</option>
                        <?php foreach ($type_labels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $val === $filter_type ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-toggle-on"></i> Statut</label>
                    <select name="status">
                        <option value="">Tous</option>
                        <option value="active"   <?= $filter_status === 'active' ? 'selected' : '' ?>>Actives</option>
                        <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Désactivées</option>
                    </select>
                </div>
                <div class="filter-group" style="flex:0">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filtrer</button>
                </div>
            </div>
        </form>
    </div>

    <div class="toolbar">
        <div class="search-row">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="searchInput" placeholder="Rechercher dans les questions…" oninput="applySearch()">
        </div>
        <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Nouvelle question</button>
    </div>

    <?php if (!empty($questions)): ?>
    <div class="table-wrapper">
        <table id="questionsTable">
            <thead>
                <tr>
                    <th><i class="fas fa-question"></i> Question</th>
                    <th>Cours</th>
                    <th>Type</th>
                    <th>Barème</th>
                    <th>Réponses</th>
                    <th>Utilisée</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($questions as $q): ?>
                <tr class="<?= $q['is_active'] ? '' : 'q-inactive' ?>"
                    id="qrow-<?= $q['id'] ?>"
                    data-search="<?= htmlspecialchars(mb_strtolower($q['question_text'] . ' ' . $q['course_name'])) ?>">
                    <td class="q-text" title="<?= htmlspecialchars($q['question_text']) ?>"><?= htmlspecialchars($q['question_text']) ?></td>
                    <td><?= htmlspecialchars($q['course_name']) ?></td>
                    <td><span class="badge badge-<?= $q['type'] ?>"><?= $type_labels[$q['type']] ?></span></td>
                    <td><?= rtrim(rtrim(number_format((float) $q['points'], 2, '.', ''), '0'), '.') ?> pt</td>
                    <td>
                        <?php if ($q['type'] === 'short_answer'): ?>
                            <?= (int) $q['nb_variants'] ?> variante(s)
                        <?php else: ?>
                            <?= (int) $q['nb_options'] ?> option(s)
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($q['nb_quizzes'] > 0): ?>
                            <span class="badge badge-used"><i class="fas fa-link"></i> <?= (int) $q['nb_quizzes'] ?> quiz</span>
                        <?php else: ?>
                            <span style="color:rgba(255,255,255,.3)">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $q['is_active'] ? 'badge-active' : 'badge-inactive' ?>" id="qstatus-<?= $q['id'] ?>">
                            <?= $q['is_active'] ? 'Active' : 'Désactivée' ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap">
                        <button class="btn btn-outline btn-sm" onclick="editQuestion(<?= $q['id'] ?>)" title="Éditer"><i class="fas fa-pen"></i></button>
                        <button class="btn <?= $q['is_active'] ? 'btn-danger-outline' : 'btn-outline' ?> btn-sm"
                                onclick="toggleActive(<?= $q['id'] ?>)"
                                id="qtoggle-<?= $q['id'] ?>"
                                title="<?= $q['is_active'] ? 'Désactiver' : 'Réactiver' ?>">
                            <i class="fas <?= $q['is_active'] ? 'fa-ban' : 'fa-rotate-left' ?>"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-inbox"></i>
            <p>Aucune question dans la banque pour ces critères.</p>
            <p style="margin-top:8px;font-size:13px;color:rgba(255,255,255,.35)">
                Créez votre première question ou importez un fichier Aiken depuis Moodle.
            </p>
        </div>
    <?php endif; ?>
</main>

<!-- Modal création / édition -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Nouvelle question</h3>
        <div class="modal-lock-warning" id="lockWarning">
            <i class="fas fa-lock"></i> Cette question est utilisée dans un quiz ayant des tentatives : modification impossible.
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label><i class="fas fa-book"></i> Cours *</label>
                <select id="fCourse">
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (S<?= $c['semester'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label><i class="fas fa-star"></i> Barème (points) *</label>
                <input type="number" id="fPoints" step="0.25" min="0.25" max="100" value="1">
            </div>
            <div class="form-group full">
                <label><i class="fas fa-shapes"></i> Type de question *</label>
                <select id="fType" onchange="renderTypeFields()">
                    <?php foreach ($type_labels as $val => $label): ?>
                        <option value="<?= $val ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full">
                <label><i class="fas fa-question-circle"></i> Énoncé *</label>
                <textarea id="fText" placeholder="Texte de la question…"></textarea>
            </div>
        </div>

        <!-- Zone spécifique au type -->
        <div class="form-group full" id="typeZone"></div>

        <div class="form-group full" style="margin-top:14px">
            <label><i class="fas fa-lightbulb"></i> Explication (affichée après correction, optionnel)</label>
            <textarea id="fExplanation" placeholder="Explication pédagogique…" style="min-height:55px"></textarea>
        </div>

        <div class="modal-actions">
            <button class="btn btn-outline" onclick="closeModal()"><i class="fas fa-times"></i> Annuler</button>
            <button class="btn btn-primary" id="saveBtn" onclick="saveQuestion()"><i class="fas fa-save"></i> Enregistrer</button>
        </div>
    </div>
</div>

<script>
let editingId = 0;
let editingLocked = false;

// ── Alertes ──────────────────────────────────────────────────
function showAlert(type, message) {
    const el = document.getElementById(type === 'success' ? 'alertSuccess' : 'alertError');
    el.querySelector('span').textContent = message;
    el.classList.add('show');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => el.classList.remove('show'), 5000);
}

// ── Recherche client ─────────────────────────────────────────
function applySearch() {
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    document.querySelectorAll('#questionsTable tbody tr').forEach(tr => {
        tr.style.display = (!search || (tr.dataset.search || '').includes(search)) ? '' : 'none';
    });
}

// ── Modal ────────────────────────────────────────────────────
function openModal() {
    editingId = 0;
    editingLocked = false;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Nouvelle question';
    document.getElementById('lockWarning').style.display = 'none';
    document.getElementById('fCourse').value = document.querySelector('select[name="course_id"]').value || document.getElementById('fCourse').options[0]?.value;
    document.getElementById('fPoints').value = 1;
    document.getElementById('fType').value = 'single_choice';
    document.getElementById('fType').disabled = false;
    document.getElementById('fText').value = '';
    document.getElementById('fExplanation').value = '';
    document.getElementById('saveBtn').disabled = false;
    renderTypeFields();
    document.getElementById('modalOverlay').classList.add('open');
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('open');
}
document.getElementById('modalOverlay').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeModal();
});

// ── Zone dynamique selon le type ─────────────────────────────
function renderTypeFields(data) {
    const type = document.getElementById('fType').value;
    const zone = document.getElementById('typeZone');
    zone.innerHTML = '';

    if (type === 'single_choice' || type === 'multiple_choice') {
        const isSingle = type === 'single_choice';
        zone.innerHTML = `
            <label><i class="fas fa-list"></i> Options ${isSingle ? '(cochez LA bonne réponse)' : '(cochez les bonnes réponses)'} *</label>
            <div id="optionsList"></div>
            <button type="button" class="btn btn-outline btn-sm" onclick="addOptionRow()"><i class="fas fa-plus"></i> Ajouter une option</button>
            <div class="hint">${isSingle ? 'Exactement une bonne réponse. Correction : tout ou rien.' : 'Au moins une bonne réponse. Points partiels possibles selon le réglage du quiz.'}</div>
        `;
        const options = (data && data.options && data.options.length) ? data.options : [{text:'',correct:false},{text:'',correct:false},{text:'',correct:false}];
        options.forEach(o => addOptionRow(o.text, o.correct));
    } else if (type === 'true_false') {
        let correct = 'true';
        if (data && data.options) {
            const vrai = data.options.find(o => o.text === 'Vrai');
            correct = (vrai && vrai.correct) ? 'true' : 'false';
        }
        zone.innerHTML = `
            <label><i class="fas fa-check-double"></i> Bonne réponse *</label>
            <div class="tf-choice">
                <label><input type="radio" name="tfCorrect" value="true" ${correct === 'true' ? 'checked' : ''}> <i class="fas fa-check" style="color:var(--success-color)"></i> Vrai</label>
                <label><input type="radio" name="tfCorrect" value="false" ${correct === 'false' ? 'checked' : ''}> <i class="fas fa-times" style="color:var(--danger-color)"></i> Faux</label>
            </div>
            <div class="hint">Les deux options « Vrai » / « Faux » sont générées automatiquement.</div>
        `;
    } else if (type === 'short_answer') {
        zone.innerHTML = `
            <label><i class="fas fa-keyboard"></i> Réponses acceptées (une par ligne) *</label>
            <textarea id="fVariants" placeholder="photosynthèse\nla photosynthèse"></textarea>
            <div class="hint">La comparaison ignore la casse, les accents et les espaces superflus. Chaque ligne est une variante acceptée.</div>
        `;
        if (data && data.accepted) {
            document.getElementById('fVariants').value = data.accepted.join('\n');
        }
    }
}

function addOptionRow(text = '', correct = false) {
    const type = document.getElementById('fType').value;
    const list = document.getElementById('optionsList');
    const row  = document.createElement('div');
    row.className = 'option-row';
    const inputType = type === 'single_choice' ? 'radio' : 'checkbox';
    row.innerHTML = `
        <input type="text" placeholder="Texte de l'option…" value="">
        <label class="option-correct"><input type="${inputType}" name="optCorrect" ${correct ? 'checked' : ''}> Correcte</label>
        <button type="button" class="option-remove" title="Supprimer" onclick="this.parentElement.remove()"><i class="fas fa-trash"></i></button>
    `;
    row.querySelector('input[type=text]').value = text;
    list.appendChild(row);
}

// ── Édition ──────────────────────────────────────────────────
async function editQuestion(id) {
    try {
        const res  = await fetch(`quiz_bank.php?action=get_question&id=${id}`);
        const data = await res.json();
        if (!data.success) { showAlert('error', data.message); return; }

        editingId = data.question.id;
        editingLocked = !!data.question.locked;

        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen"></i> Éditer la question';
        document.getElementById('lockWarning').style.display = editingLocked ? 'block' : 'none';
        document.getElementById('saveBtn').disabled = editingLocked;
        document.getElementById('fCourse').value = data.question.course_id;
        document.getElementById('fPoints').value = data.question.points;
        document.getElementById('fType').value = data.question.type;
        document.getElementById('fType').disabled = false;
        document.getElementById('fText').value = data.question.question_text;
        document.getElementById('fExplanation').value = data.question.explanation || '';
        renderTypeFields(data);
        document.getElementById('modalOverlay').classList.add('open');
    } catch (e) {
        showAlert('error', 'Erreur réseau lors du chargement de la question');
    }
}

// ── Enregistrement ───────────────────────────────────────────
async function saveQuestion() {
    const type = document.getElementById('fType').value;

    const payload = {
        action:        'save_question',
        question_id:   editingId,
        course_id:     parseInt(document.getElementById('fCourse').value, 10),
        type:          type,
        question_text: document.getElementById('fText').value.trim(),
        points:        parseFloat(document.getElementById('fPoints').value),
        explanation:   document.getElementById('fExplanation').value.trim(),
        options:       [],
        short_answers: [],
        tf_correct:    ''
    };

    if (type === 'single_choice' || type === 'multiple_choice') {
        document.querySelectorAll('#optionsList .option-row').forEach(row => {
            payload.options.push({
                text:    row.querySelector('input[type=text]').value.trim(),
                correct: row.querySelector('input[name=optCorrect]').checked
            });
        });
    } else if (type === 'true_false') {
        const checked = document.querySelector('input[name=tfCorrect]:checked');
        payload.tf_correct = checked ? checked.value : '';
    } else if (type === 'short_answer') {
        payload.short_answers = document.getElementById('fVariants').value.split('\n').map(s => s.trim()).filter(Boolean);
    }

    // Validations client rapides (le serveur revalide tout)
    if (!payload.question_text) { showAlert('error', "L'énoncé est obligatoire"); return; }
    if (!(payload.points > 0))  { showAlert('error', 'Le barème doit être positif'); return; }

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    try {
        const res  = await fetch('quiz_bank.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            closeModal();
            // Recharger pour rafraîchir la liste (conserve les filtres GET)
            sessionStorage.setItem('quizBankMsg', data.message);
            window.location.reload();
        } else {
            showAlert('error', data.message);
            btn.disabled = false;
        }
    } catch (e) {
        showAlert('error', 'Erreur réseau lors de l\'enregistrement');
        btn.disabled = false;
    }
}

// ── Désactivation / réactivation ─────────────────────────────
async function toggleActive(id) {
    try {
        const res  = await fetch('quiz_bank.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'toggle_active', question_id: id })
        });
        const data = await res.json();
        if (data.success) {
            const row    = document.getElementById('qrow-' + id);
            const status = document.getElementById('qstatus-' + id);
            const toggle = document.getElementById('qtoggle-' + id);
            if (data.is_active) {
                row.classList.remove('q-inactive');
                status.className = 'badge badge-active';
                status.textContent = 'Active';
                toggle.className = 'btn btn-danger-outline btn-sm';
                toggle.title = 'Désactiver';
                toggle.innerHTML = '<i class="fas fa-ban"></i>';
            } else {
                row.classList.add('q-inactive');
                status.className = 'badge badge-inactive';
                status.textContent = 'Désactivée';
                toggle.className = 'btn btn-outline btn-sm';
                toggle.title = 'Réactiver';
                toggle.innerHTML = '<i class="fas fa-rotate-left"></i>';
            }
            showAlert('success', data.message);
        } else {
            showAlert('error', data.message);
        }
    } catch (e) {
        showAlert('error', 'Erreur réseau');
    }
}

// Message après rechargement post-enregistrement
const pendingMsg = sessionStorage.getItem('quizBankMsg');
if (pendingMsg) {
    sessionStorage.removeItem('quizBankMsg');
    showAlert('success', pendingMsg);
}
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>
