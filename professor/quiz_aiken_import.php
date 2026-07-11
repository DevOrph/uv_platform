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

$courses = quiz_get_teacher_courses($conn, $user_id, $user_role);

$success_msg = '';
$error_msg   = '';
$preview     = null; // ['questions' => [...], 'errors' => [...], 'course_id' =>, 'points' =>]

// ============================================================
// ÉTAPE B : confirmation de l'import (depuis la prévisualisation en session)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_import') {
    $stored = $_SESSION['aiken_preview'] ?? null;

    if (!$stored || empty($stored['questions'])) {
        $error_msg = "Aucune prévisualisation en attente. Importez d'abord un fichier.";
    } elseif (!quiz_user_owns_course($conn, (int) $stored['course_id'], $user_id, $user_role)) {
        $error_msg = "Cours invalide ou accès refusé.";
    } else {
        $course_id = (int) $stored['course_id'];
        $points    = (float) $stored['points'];
        $annee     = ANNEE_ACADEMIQUE_COURANTE;

        try {
            $conn->begin_transaction();

            $ins_q = $conn->prepare("INSERT INTO quiz_questions (course_id, teacher_id, type, question_text, points, annee_academique) VALUES (?, ?, 'single_choice', ?, ?, ?)");
            $ins_o = $conn->prepare("INSERT INTO quiz_question_options (question_id, option_text, is_correct, display_order) VALUES (?, ?, ?, ?)");

            $count = 0;
            foreach ($stored['questions'] as $q) {
                $ins_q->bind_param("issds", $course_id, $user_id, $q['text'], $points, $annee);
                if (!$ins_q->execute()) {
                    throw new Exception("Erreur d'insertion de la question " . ($count + 1));
                }
                $qid = $conn->insert_id;

                $order = 0;
                foreach ($q['options'] as $letter => $text) {
                    $is_correct = ($letter === $q['answer']) ? 1 : 0;
                    $ins_o->bind_param("isii", $qid, $text, $is_correct, $order);
                    if (!$ins_o->execute()) {
                        throw new Exception("Erreur d'insertion d'une option de la question " . ($count + 1));
                    }
                    $order++;
                }
                $count++;
            }

            $conn->commit();
            unset($_SESSION['aiken_preview']);
            $success_msg = "$count question(s) importée(s) dans la banque avec succès.";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Import annulé : " . $e->getMessage();
        }
    }
}

// ============================================================
// Annulation de la prévisualisation
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_import') {
    unset($_SESSION['aiken_preview']);
    $success_msg = "Prévisualisation annulée.";
}

// ============================================================
// ÉTAPE A : upload + parsing + prévisualisation
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_aiken') {
    $course_id = (int) ($_POST['course_id'] ?? 0);
    $points    = (float) ($_POST['points'] ?? 1);

    if (!quiz_user_owns_course($conn, $course_id, $user_id, $user_role)) {
        $error_msg = "Cours invalide ou accès refusé.";
    } elseif ($points <= 0 || $points > 100) {
        $error_msg = "Le barème par question doit être compris entre 0.25 et 100.";
    } elseif (empty($_FILES['aiken_file']) || $_FILES['aiken_file']['error'] !== UPLOAD_ERR_OK) {
        $error_msg = "Aucun fichier reçu ou erreur d'upload.";
    } elseif ($_FILES['aiken_file']['size'] > 1048576) {
        $error_msg = "Fichier trop volumineux (1 Mo maximum).";
    } else {
        $ext = strtolower(pathinfo($_FILES['aiken_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'txt') {
            $error_msg = "Seuls les fichiers .txt au format Aiken sont acceptés.";
        } else {
            $content = file_get_contents($_FILES['aiken_file']['tmp_name']);
            if ($content === false || trim($content) === '') {
                $error_msg = "Fichier vide ou illisible.";
            } elseif (!mb_check_encoding($content, 'UTF-8')) {
                // Tolérance : conversion depuis Latin-1 (exports Windows)
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
            }

            if (!$error_msg) {
                $parsed = quiz_parse_aiken($content);
                if (empty($parsed['questions'])) {
                    $error_msg = "Aucune question valide trouvée dans le fichier."
                               . (!empty($parsed['errors']) ? " (" . count($parsed['errors']) . " erreur(s) de format)" : "");
                    $preview = null;
                    if (!empty($parsed['errors'])) {
                        $preview = ['questions' => [], 'errors' => $parsed['errors'], 'course_id' => $course_id, 'points' => $points];
                    }
                } else {
                    // Stocker en session pour l'étape de confirmation
                    $_SESSION['aiken_preview'] = [
                        'questions' => $parsed['questions'],
                        'errors'    => $parsed['errors'],
                        'course_id' => $course_id,
                        'points'    => $points,
                        'filename'  => $_FILES['aiken_file']['name'],
                    ];
                }
            }
        }
    }
}

// Prévisualisation en attente (après upload ou rafraîchissement)
if (!$preview && !empty($_SESSION['aiken_preview'])) {
    $preview = $_SESSION['aiken_preview'];
}

$course_names = [];
foreach ($courses as $c) {
    $course_names[$c['id']] = $c['name'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
    <title>Import Aiken — Quiz</title>
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
        main { flex:1; max-width:1000px; margin:0 auto; padding:30px 20px; width:100%; }
        h2 { font-size:20px; color:var(--accent-color); margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        h3 { font-size:16px; color:var(--accent-color); margin:22px 0 12px; display:flex; align-items:center; gap:8px; }

        .card { background:var(--secondary-bg); border-radius:10px; padding:20px; margin-bottom:25px; border:1px solid var(--border-color); }
        .filter-row { display:flex; gap:15px; flex-wrap:wrap; align-items:flex-end; }
        .filter-group { display:flex; flex-direction:column; gap:6px; flex:1; min-width:180px; }
        .filter-group label { font-size:13px; color:rgba(255,255,255,.7); }
        select, input[type=text], input[type=number], input[type=file] {
            background:#0d3152; color:var(--text-light); border:1px solid var(--border-color);
            border-radius:6px; padding:9px 12px; font-size:14px; width:100%;
        }
        select:focus, input:focus { outline:none; border-color:var(--accent-color); }
        input[type=file]::file-selector-button {
            background:var(--accent-color); color:#fff; border:none; border-radius:5px;
            padding:6px 12px; margin-right:10px; cursor:pointer; font-size:13px;
        }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border-radius:6px; border:none; cursor:pointer; font-size:13px; font-weight:600; transition:all .3s; text-decoration:none; white-space:nowrap; }
        .btn-primary { background:var(--accent-color); color:#fff; }
        .btn-primary:hover { background:#0288c7; }
        .btn-success { background:var(--success-color); color:#06371d; }
        .btn-success:hover { filter:brightness(1.1); }
        .btn-outline { background:transparent; color:var(--text-light); border:1px solid var(--border-color); }
        .btn-outline:hover { border-color:var(--accent-color); color:var(--accent-color); }

        .alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:rgba(46,204,113,.12); border:1px solid rgba(46,204,113,.35); color:var(--success-color); }
        .alert-error   { background:rgba(231,76,60,.12);  border:1px solid rgba(231,76,60,.35);  color:var(--danger-color); }
        .alert-warning { background:rgba(243,156,18,.12); border:1px solid rgba(243,156,18,.35); color:var(--warning-color); flex-direction:column; align-items:flex-start; }

        .format-example { background:#04182b; border:1px solid var(--border-color); border-radius:8px; padding:14px 18px; font-family:monospace; font-size:13px; line-height:1.7; color:rgba(255,255,255,.75); white-space:pre; overflow-x:auto; }

        .preview-question { background:#0a2740; border:1px solid var(--border-color); border-radius:8px; padding:14px 18px; margin-bottom:12px; }
        .preview-question .q-num { color:var(--accent-color); font-weight:700; font-size:13px; margin-bottom:6px; }
        .preview-question .q-text { margin-bottom:10px; white-space:pre-wrap; }
        .preview-option { display:flex; align-items:center; gap:8px; padding:4px 0 4px 12px; font-size:13px; color:rgba(255,255,255,.75); }
        .preview-option.correct { color:var(--success-color); font-weight:600; }
        .preview-meta { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px; font-size:13px; color:rgba(255,255,255,.6); }
        .preview-meta strong { color:var(--text-light); }
        .error-list { margin:8px 0 0 18px; font-size:13px; }
        .error-list li { margin-bottom:4px; }
        .confirm-bar { display:flex; gap:12px; align-items:center; margin-top:18px; flex-wrap:wrap; }
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
            <li><a href="quiz_manage.php"><i class="fas fa-clipboard-question"></i> Mes Quiz</a></li>
            <li><a href="quiz_aiken_import.php" class="active"><i class="fas fa-file-import"></i> Import Aiken</a></li>
            <li><a href="teacher_profile.php"><i class="fas fa-user"></i> Profil</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul></nav>
    </div>
</header>

<main>
    <h2><i class="fas fa-file-import"></i> Import de questions au format Aiken</h2>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?>
            <?php if (strpos($success_msg, 'importée') !== false): ?>
                &nbsp;<a href="quiz_bank.php" style="color:inherit;text-decoration:underline">Voir la banque de questions</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <?php if (!$preview || empty($preview['questions'])): ?>

    <!-- ÉTAPE A : upload -->
    <div class="card">
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="upload_aiken">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-book"></i> Cours de destination *</label>
                    <select name="course_id" required>
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (S<?= $c['semester'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="max-width:180px">
                    <label><i class="fas fa-star"></i> Barème par question</label>
                    <input type="number" name="points" step="0.25" min="0.25" max="100" value="1" required>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-file-alt"></i> Fichier Aiken (.txt) *</label>
                    <input type="file" name="aiken_file" accept=".txt" required>
                </div>
                <div class="filter-group" style="flex:0">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Analyser</button>
                </div>
            </div>
        </form>
    </div>

    <h3><i class="fas fa-circle-info"></i> Rappel du format Aiken</h3>
    <div class="format-example">Quelle est la capitale du Gabon ?
A. Douala
B. Libreville
C. Yaoundé
D. Brazzaville
ANSWER: B

La photosynthèse produit de l'oxygène.
A. Vrai
B. Faux
ANSWER: A</div>
    <p style="margin-top:12px;font-size:13px;color:rgba(255,255,255,.5)">
        Chaque question est séparée par une ligne vide. Les questions importées sont créées en <strong>QCM simple</strong>
        dans la banque — vous pourrez ensuite les éditer depuis la <a href="quiz_bank.php" style="color:var(--accent-color)">banque de questions</a>.
        Export depuis Moodle : <em>Banque de questions → Exporter → Format Aiken</em>.
    </p>

    <?php else: ?>

    <!-- ÉTAPE B : prévisualisation + confirmation -->
    <div class="preview-meta">
        <span><i class="fas fa-file-alt"></i> Fichier : <strong><?= htmlspecialchars($preview['filename'] ?? '') ?></strong></span>
        <span><i class="fas fa-book"></i> Cours : <strong><?= htmlspecialchars($course_names[$preview['course_id']] ?? '?') ?></strong></span>
        <span><i class="fas fa-star"></i> Barème : <strong><?= htmlspecialchars((string) $preview['points']) ?> pt / question</strong></span>
        <span><i class="fas fa-list-ol"></i> Questions valides : <strong style="color:var(--success-color)"><?= count($preview['questions']) ?></strong></span>
    </div>

    <?php if (!empty($preview['errors'])): ?>
        <div class="alert alert-warning">
            <span><i class="fas fa-triangle-exclamation"></i> <strong><?= count($preview['errors']) ?> bloc(s) ignoré(s)</strong> (ils ne seront pas importés) :</span>
            <ul class="error-list">
                <?php foreach ($preview['errors'] as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php foreach ($preview['questions'] as $i => $q): ?>
        <div class="preview-question">
            <div class="q-num">Question <?= $i + 1 ?></div>
            <div class="q-text"><?= htmlspecialchars($q['text']) ?></div>
            <?php foreach ($q['options'] as $letter => $text): ?>
                <div class="preview-option <?= $letter === $q['answer'] ? 'correct' : '' ?>">
                    <?php if ($letter === $q['answer']): ?>
                        <i class="fas fa-check-circle"></i>
                    <?php else: ?>
                        <i class="far fa-circle"></i>
                    <?php endif; ?>
                    <strong><?= $letter ?>.</strong> <?= htmlspecialchars($text) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <div class="confirm-bar">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="confirm_import">
            <button type="submit" class="btn btn-success">
                <i class="fas fa-check"></i> Confirmer l'import de <?= count($preview['questions']) ?> question(s)
            </button>
        </form>
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <input type="hidden" name="action" value="cancel_import">
            <button type="submit" class="btn btn-outline"><i class="fas fa-times"></i> Annuler</button>
        </form>
    </div>

    <?php endif; ?>
</main>

<?php include '../includes/footer.php'; ?>
</body>
</html>
