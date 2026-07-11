<?php
require_once '../includes/db_connect.php';
require_once '../includes/quiz_functions.php';

// Contrôle du rôle
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../pages/login.php?error=access_denied");
    exit();
}

$student_id = $_SESSION['user_id'];

$success_msg = $_SESSION['quiz_student_msg'] ?? '';
$error_msg   = $_SESSION['quiz_student_err'] ?? '';
unset($_SESSION['quiz_student_msg'], $_SESSION['quiz_student_err']);

// ── Classe de l'étudiant ─────────────────────────────────────
$stmt = $conn->prepare("SELECT class_id FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$class_id = $row ? (int) $row['class_id'] : 0;

// ── Quiz visibles : publiés ou fermés, cours dont courses.class_id
//    (JSON) contient la classe de l'étudiant (note 2 du cahier des charges,
//    même convention JSON_QUOTE que le reste du code) ────────────────────────
$quizzes = [];
if ($class_id > 0) {
    $class_str = (string) $class_id;
    $annee     = ANNEE_ACADEMIQUE_COURANTE;
    $stmt = $conn->prepare("
        SELECT z.*, c.name AS course_name,
               (SELECT COUNT(*) FROM quiz_question_links l WHERE l.quiz_id = z.id) AS nb_questions,
               (SELECT COUNT(*) FROM quiz_attempts qa
                 WHERE qa.quiz_id = z.id AND qa.student_id = ?
                   AND qa.status IN ('submitted','expired'))                        AS nb_finished,
               (SELECT COUNT(*) FROM quiz_attempts qa
                 WHERE qa.quiz_id = z.id AND qa.student_id = ?
                   AND qa.status = 'in_progress')                                   AS nb_in_progress
        FROM quizzes z
        JOIN courses c ON c.id = z.course_id
        WHERE z.status IN ('published','closed')
          AND z.annee_academique = ?
          AND JSON_CONTAINS(c.class_id, JSON_QUOTE(?))
        ORDER BY z.status = 'published' DESC, z.end_date DESC
    ");
    $stmt->bind_param("ssss", $student_id, $student_id, $annee, $class_str);
    $stmt->execute();
    $quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$now = quiz_db_time($conn); // horloge MySQL (fuseaux PHP/MySQL différents)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
    <title>Mes Quiz — Étudiant</title>
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
        .header-content { max-width:1100px; margin:0 auto; padding:0 20px; text-align:center; }
        .header-content h1 { font-size:22px; color:var(--accent-color); margin-bottom:15px; }
        nav ul { list-style:none; display:flex; justify-content:center; gap:15px; flex-wrap:wrap; }
        nav a { color:var(--text-light); text-decoration:none; padding:7px 14px; border-radius:5px; display:flex; align-items:center; gap:7px; transition:background .3s; }
        nav a:hover { background:rgba(3,155,229,.15); }
        nav a.active { background:rgba(3,155,229,.25); }
        nav a[href*="logout"] { color:#dc3545; }
        main { flex:1; max-width:1100px; margin:0 auto; padding:30px 20px; width:100%; }
        h2 { font-size:20px; color:var(--accent-color); margin-bottom:20px; display:flex; align-items:center; gap:10px; }

        .alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:rgba(46,204,113,.12); border:1px solid rgba(46,204,113,.35); color:var(--success-color); }
        .alert-error   { background:rgba(231,76,60,.12);  border:1px solid rgba(231,76,60,.35);  color:var(--danger-color); }

        .quiz-card { background:var(--secondary-bg); border:1px solid var(--border-color); border-radius:10px; padding:18px 22px; margin-bottom:16px; display:flex; gap:18px; align-items:center; flex-wrap:wrap; }
        .quiz-card .qc-main { flex:1; min-width:250px; }
        .quiz-card .qc-title { font-size:16px; font-weight:700; margin-bottom:5px; }
        .quiz-card .qc-course { font-size:13px; color:var(--accent-color); margin-bottom:8px; }
        .quiz-card .qc-meta { display:flex; gap:14px; flex-wrap:wrap; font-size:12px; color:rgba(255,255,255,.55); }
        .quiz-card .qc-meta span { display:flex; align-items:center; gap:5px; }
        .qc-desc { font-size:13px; color:rgba(255,255,255,.65); margin-top:8px; }
        .qc-actions { display:flex; flex-direction:column; gap:8px; align-items:flex-end; }

        .btn { display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border-radius:6px; border:none; cursor:pointer; font-size:13px; font-weight:600; transition:all .3s; text-decoration:none; white-space:nowrap; }
        .btn-primary { background:var(--accent-color); color:#fff; }
        .btn-primary:hover { background:#0288c7; }
        .btn-warning { background:rgba(243,156,18,.9); color:#3d2703; }
        .btn-outline { background:transparent; color:var(--text-light); border:1px solid var(--border-color); }
        .btn-outline:hover { border-color:var(--accent-color); color:var(--accent-color); }

        .badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
        .badge-open      { background:rgba(46,204,113,.2);  color:var(--success-color); }
        .badge-upcoming  { background:rgba(3,155,229,.18);  color:var(--accent-color); }
        .badge-ended     { background:rgba(255,255,255,.12); color:rgba(255,255,255,.6); }
        .badge-closed    { background:rgba(231,76,60,.18);  color:var(--danger-color); }
        .badge-progress  { background:rgba(243,156,18,.18); color:var(--warning-color); }
        .badge-done      { background:rgba(46,204,113,.2);  color:var(--success-color); }

        .no-data { text-align:center; padding:50px; color:rgba(255,255,255,.4); }
        .no-data i { display:block; font-size:44px; margin-bottom:14px; }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle — Étudiant</h1>
        <nav><ul>
            <li><a href="student_dashboard.php"><i class="fas fa-home"></i> Accueil</a></li>
            <li><a href="student_grades.php"><i class="fas fa-chart-line"></i> Mes Notes</a></li>
            <li><a href="quiz_list.php" class="active"><i class="fas fa-clipboard-question"></i> Mes Quiz</a></li>
            <li><a href="student_profile.php"><i class="fas fa-user"></i> Profil</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul></nav>
    </div>
</header>

<main>
    <h2><i class="fas fa-clipboard-question"></i> Mes Quiz</h2>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <?php if (!empty($quizzes)): ?>
        <?php foreach ($quizzes as $z):
            $start = strtotime($z['start_date']);
            $end   = strtotime($z['end_date']);
            $is_published = $z['status'] === 'published';
            $window_open  = $is_published && $now >= $start && $now <= $end;
            $upcoming     = $is_published && $now < $start;
            $has_progress = $z['nb_in_progress'] > 0;
            $finished     = (int) $z['nb_finished'];
            $attempts_left = max(0, (int) $z['max_attempts'] - $finished);
            $can_take = $window_open && ($has_progress || $attempts_left > 0);
        ?>
        <div class="quiz-card">
            <div class="qc-main">
                <div class="qc-title"><?= htmlspecialchars($z['title']) ?></div>
                <div class="qc-course"><i class="fas fa-book"></i> <?= htmlspecialchars($z['course_name']) ?></div>
                <div class="qc-meta">
                    <span><i class="fas fa-calendar"></i> Du <?= date('d/m/Y H:i', $start) ?> au <?= date('d/m/Y H:i', $end) ?></span>
                    <span><i class="fas fa-list-ol"></i> <?= (int) $z['nb_questions'] ?> question(s)</span>
                    <span><i class="fas fa-stopwatch"></i> <?= $z['duration_minutes'] ? (int) $z['duration_minutes'] . ' min' : 'Sans limite de temps' ?></span>
                    <span><i class="fas fa-rotate"></i> Tentatives : <?= $finished ?> / <?= (int) $z['max_attempts'] ?></span>
                </div>
                <?php if (!empty($z['description'])): ?>
                    <div class="qc-desc"><?= nl2br(htmlspecialchars($z['description'])) ?></div>
                <?php endif; ?>
            </div>
            <div class="qc-actions">
                <?php if ($z['status'] === 'closed'): ?>
                    <span class="badge badge-closed"><i class="fas fa-lock"></i> Fermé</span>
                <?php elseif ($upcoming): ?>
                    <span class="badge badge-upcoming"><i class="fas fa-clock"></i> Ouvre le <?= date('d/m à H:i', $start) ?></span>
                <?php elseif ($window_open): ?>
                    <?php if ($has_progress): ?>
                        <span class="badge badge-progress"><i class="fas fa-hourglass-half"></i> Tentative en cours</span>
                    <?php elseif ($attempts_left > 0): ?>
                        <span class="badge badge-open"><i class="fas fa-door-open"></i> Ouvert</span>
                    <?php else: ?>
                        <span class="badge badge-done"><i class="fas fa-check"></i> Tentatives épuisées</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="badge badge-ended"><i class="fas fa-calendar-xmark"></i> Période terminée</span>
                <?php endif; ?>

                <?php if ($can_take): ?>
                    <a class="btn <?= $has_progress ? 'btn-warning' : 'btn-primary' ?>" href="quiz_take.php?quiz_id=<?= $z['id'] ?>">
                        <?php if ($has_progress): ?>
                            <i class="fas fa-play"></i> Reprendre
                        <?php else: ?>
                            <i class="fas fa-pen"></i> <?= $finished > 0 ? 'Nouvelle tentative' : 'Commencer' ?>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
                <?php if ($finished > 0): ?>
                    <a class="btn btn-outline" href="quiz_result.php?quiz_id=<?= $z['id'] ?>"><i class="fas fa-square-poll-vertical"></i> Mes résultats</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-clipboard-question"></i>
            <p>Aucun quiz disponible pour vos cours pour le moment.</p>
        </div>
    <?php endif; ?>
</main>

<?php include '../includes/footer.php'; ?>
</body>
</html>
