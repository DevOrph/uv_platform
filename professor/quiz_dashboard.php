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

// ── Liste des quiz pour le sélecteur ─────────────────────────
if ($user_role === 'admin') {
    $stmt = $conn->prepare("SELECT z.id, z.title, z.status, c.name AS course_name FROM quizzes z JOIN courses c ON c.id = z.course_id ORDER BY z.created_at DESC");
} else {
    $stmt = $conn->prepare("SELECT z.id, z.title, z.status, c.name AS course_name FROM quizzes z JOIN courses c ON c.id = z.course_id WHERE z.teacher_id = ? ORDER BY z.created_at DESC");
    $stmt->bind_param("s", $user_id);
}
$stmt->execute();
$all_quizzes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$quiz_id = (int) ($_GET['quiz_id'] ?? 0);
$quiz    = $quiz_id ? quiz_user_owns_quiz($conn, $quiz_id, $user_id, $user_role) : null;
if ($quiz_id && !$quiz) {
    $quiz_id = 0; // accès refusé → retour au sélecteur
}

$attempts       = [];
$question_stats = [];
$summary        = ['students' => 0, 'attempts' => 0, 'avg' => null, 'min' => null, 'max' => null];
$questions      = [];
$retained       = [];

if ($quiz) {
    $questions = quiz_load_quiz_questions($conn, $quiz_id);

    // ── Tentatives (avec nom étudiant) ───────────────────────
    $stmt = $conn->prepare("
        SELECT qa.*, u.name AS student_name
        FROM quiz_attempts qa
        JOIN users u ON u.id = qa.student_id
        WHERE qa.quiz_id = ?
        ORDER BY u.name ASC, qa.attempt_number ASC
    ");
    $stmt->bind_param("i", $quiz_id);
    $stmt->execute();
    $attempts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // ── Statistiques globales (sur tentatives corrigées) ─────
    $graded_scores = [];
    $students_set  = [];
    foreach ($attempts as $a) {
        $students_set[$a['student_id']] = true;
        if (in_array($a['status'], ['submitted', 'expired'], true) && $a['final_grade'] !== null) {
            $graded_scores[] = (float) $a['final_grade'];
        }
    }
    $summary['students'] = count($students_set);
    $summary['attempts'] = count($attempts);
    if ($graded_scores) {
        $summary['avg'] = round(array_sum($graded_scores) / count($graded_scores), 2);
        $summary['min'] = min($graded_scores);
        $summary['max'] = max($graded_scores);
    }

    // Note retenue par étudiant (selon grading_method)
    $retained = quiz_get_retained_grades($conn, $quiz);

    // ── Statistiques par question (taux de réussite) ─────────
    foreach ($questions as $qid => $q) {
        $question_stats[$qid] = ['earned' => 0.0, 'max' => 0.0, 'count' => 0];
    }
    foreach ($attempts as $a) {
        if (!in_array($a['status'], ['submitted', 'expired'], true) || $a['detail'] === null) {
            continue;
        }
        $detail = json_decode($a['detail'], true);
        if (!is_array($detail)) {
            continue;
        }
        foreach ($detail as $qid => $d) {
            $qid = (int) $qid;
            if (!isset($question_stats[$qid])) {
                continue;
            }
            $question_stats[$qid]['earned'] += (float) ($d['earned'] ?? 0);
            $question_stats[$qid]['max']    += (float) ($d['max'] ?? 0);
            $question_stats[$qid]['count']++;
        }
    }
}

$status_labels  = ['draft' => 'Brouillon', 'published' => 'Publié', 'closed' => 'Fermé'];
$attempt_labels = ['in_progress' => 'En cours', 'submitted' => 'Soumise', 'expired' => 'Expirée'];
$type_labels    = [
    'single_choice'   => 'QCM simple',
    'multiple_choice' => 'QCM multiple',
    'true_false'      => 'Vrai / Faux',
    'short_answer'    => 'Réponse courte',
];
$method_labels = ['best' => 'Meilleure tentative', 'last' => 'Dernière tentative', 'average' => 'Moyenne des tentatives'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
    <title>Tableau de bord Quiz — Enseignant</title>
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
        h3 { font-size:16px; color:var(--accent-color); margin:26px 0 14px; display:flex; align-items:center; gap:8px; }

        .filter-card { background:var(--secondary-bg); border-radius:10px; padding:20px; margin-bottom:25px; border:1px solid var(--border-color); }
        .filter-row { display:flex; gap:15px; flex-wrap:wrap; align-items:flex-end; }
        .filter-group { display:flex; flex-direction:column; gap:6px; flex:1; min-width:220px; }
        .filter-group label { font-size:13px; color:rgba(255,255,255,.7); }
        select, input[type=text] {
            background:#0d3152; color:var(--text-light); border:1px solid var(--border-color);
            border-radius:6px; padding:9px 12px; font-size:14px; width:100%;
        }
        select:focus, input:focus { outline:none; border-color:var(--accent-color); }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border-radius:6px; border:none; cursor:pointer; font-size:13px; font-weight:600; transition:all .3s; text-decoration:none; white-space:nowrap; }
        .btn-primary { background:var(--accent-color); color:#fff; }
        .btn-primary:hover { background:#0288c7; }

        .stat-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:14px; margin-bottom:8px; }
        .stat-card { background:var(--secondary-bg); border:1px solid var(--border-color); border-radius:10px; padding:16px 18px; }
        .stat-card .sc-label { font-size:12px; color:rgba(255,255,255,.55); margin-bottom:7px; display:flex; align-items:center; gap:6px; }
        .stat-card .sc-value { font-size:24px; font-weight:800; color:var(--accent-color); }
        .stat-card .sc-value.green { color:var(--success-color); }
        .stat-card .sc-value.orange { color:var(--warning-color); }

        .quiz-meta { display:flex; gap:14px; flex-wrap:wrap; font-size:13px; color:rgba(255,255,255,.6); margin-bottom:20px; }
        .quiz-meta span { display:flex; align-items:center; gap:6px; }
        .quiz-meta strong { color:var(--text-light); }

        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        thead th { background:rgba(3,155,229,.3); padding:11px 12px; text-align:left; white-space:nowrap; }
        tbody tr:nth-child(even) { background:rgba(255,255,255,.03); }
        tbody tr:hover { background:rgba(3,155,229,.08); }
        td { padding:9px 12px; border-bottom:1px solid var(--border-color); vertical-align:middle; }

        .badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; white-space:nowrap; }
        .badge-draft       { background:rgba(255,255,255,.12); color:rgba(255,255,255,.75); }
        .badge-published   { background:rgba(46,204,113,.2);  color:var(--success-color); }
        .badge-closed      { background:rgba(231,76,60,.18);  color:var(--danger-color); }
        .badge-in_progress { background:rgba(243,156,18,.18); color:var(--warning-color); }
        .badge-submitted   { background:rgba(46,204,113,.2);  color:var(--success-color); }
        .badge-expired     { background:rgba(231,76,60,.18);  color:var(--danger-color); }
        .badge-retained    { background:rgba(3,155,229,.18);  color:var(--accent-color); }

        .rate-bar { background:rgba(255,255,255,.08); border-radius:8px; height:14px; min-width:120px; overflow:hidden; position:relative; }
        .rate-bar .rate-fill { height:100%; border-radius:8px; transition:width .4s; }
        .rate-good { background:var(--success-color); }
        .rate-mid  { background:var(--warning-color); }
        .rate-bad  { background:var(--danger-color); }
        .rate-text { font-weight:700; font-size:12px; white-space:nowrap; }

        .q-text-cell { max-width:340px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .grade-cell { font-weight:700; }
        .grade-good { color:var(--success-color); }
        .grade-mid  { color:var(--warning-color); }
        .grade-bad  { color:var(--danger-color); }

        .no-data { text-align:center; padding:40px; color:rgba(255,255,255,.4); }
        .no-data i { display:block; font-size:44px; margin-bottom:14px; }
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
            <li><a href="quiz_dashboard.php" class="active"><i class="fas fa-chart-pie"></i> Résultats</a></li>
            <li><a href="teacher_profile.php"><i class="fas fa-user"></i> Profil</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul></nav>
    </div>
</header>

<main>
    <h2><i class="fas fa-chart-pie"></i> Tableau de bord des quiz</h2>

    <!-- Sélecteur de quiz -->
    <div class="filter-card">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-clipboard-question"></i> Quiz</label>
                    <select name="quiz_id" required>
                        <option value="">-- Sélectionner un quiz --</option>
                        <?php foreach ($all_quizzes as $z): ?>
                            <option value="<?= $z['id'] ?>" <?= $z['id'] == $quiz_id ? 'selected' : '' ?>>
                                [<?= $status_labels[$z['status']] ?>] <?= htmlspecialchars($z['title']) ?> — <?= htmlspecialchars($z['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="flex:0">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> Afficher</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($quiz): ?>

        <div class="quiz-meta">
            <span><i class="fas fa-clipboard-question"></i> <strong><?= htmlspecialchars($quiz['title']) ?></strong></span>
            <span class="badge badge-<?= $quiz['status'] ?>"><?= $status_labels[$quiz['status']] ?></span>
            <?php if ($quiz['grade_injected']): ?><span class="badge badge-retained"><i class="fas fa-check"></i> Notes injectées</span><?php endif; ?>
            <span><i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($quiz['start_date'])) ?> → <?= date('d/m/Y H:i', strtotime($quiz['end_date'])) ?></span>
            <span><i class="fas fa-stopwatch"></i> <?= $quiz['duration_minutes'] ? (int) $quiz['duration_minutes'] . ' min' : 'Sans limite' ?></span>
            <span><i class="fas fa-rotate"></i> <?= (int) $quiz['max_attempts'] ?> tentative(s) max</span>
            <span><i class="fas fa-calculator"></i> <?= $method_labels[$quiz['grading_method']] ?? $quiz['grading_method'] ?></span>
        </div>

        <!-- Statistiques globales -->
        <div class="stat-cards">
            <div class="stat-card">
                <div class="sc-label"><i class="fas fa-users"></i> Participants</div>
                <div class="sc-value"><?= $summary['students'] ?></div>
            </div>
            <div class="stat-card">
                <div class="sc-label"><i class="fas fa-list-check"></i> Tentatives</div>
                <div class="sc-value"><?= $summary['attempts'] ?></div>
            </div>
            <div class="stat-card">
                <div class="sc-label"><i class="fas fa-chart-simple"></i> Moyenne /20</div>
                <div class="sc-value <?= $summary['avg'] !== null ? ($summary['avg'] >= 10 ? 'green' : 'orange') : '' ?>">
                    <?= $summary['avg'] !== null ? number_format($summary['avg'], 2) : '—' ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="sc-label"><i class="fas fa-arrow-down"></i> Note min</div>
                <div class="sc-value"><?= $summary['min'] !== null ? number_format($summary['min'], 2) : '—' ?></div>
            </div>
            <div class="stat-card">
                <div class="sc-label"><i class="fas fa-arrow-up"></i> Note max</div>
                <div class="sc-value"><?= $summary['max'] !== null ? number_format($summary['max'], 2) : '—' ?></div>
            </div>
        </div>

        <!-- Stats par question -->
        <h3><i class="fas fa-percent"></i> Taux de réussite par question</h3>
        <?php if (!empty($questions)): ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Question</th>
                        <th>Type</th>
                        <th>Barème</th>
                        <th>Réponses corrigées</th>
                        <th style="min-width:200px">Taux de réussite</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i = 1; foreach ($questions as $qid => $q):
                    $st   = $question_stats[$qid] ?? ['earned' => 0, 'max' => 0, 'count' => 0];
                    $rate = $st['max'] > 0 ? round($st['earned'] / $st['max'] * 100, 1) : null;
                    $cls  = $rate === null ? '' : ($rate >= 60 ? 'rate-good' : ($rate >= 35 ? 'rate-mid' : 'rate-bad'));
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="q-text-cell" title="<?= htmlspecialchars($q['question_text']) ?>"><?= htmlspecialchars($q['question_text']) ?></td>
                        <td><?= $type_labels[$q['type']] ?? $q['type'] ?></td>
                        <td><?= rtrim(rtrim(number_format($q['points'], 2, '.', ''), '0'), '.') ?> pt</td>
                        <td><?= (int) $st['count'] ?></td>
                        <td>
                            <?php if ($rate !== null): ?>
                                <div style="display:flex;align-items:center;gap:10px">
                                    <div class="rate-bar" style="flex:1"><div class="rate-fill <?= $cls ?>" style="width:<?= $rate ?>%"></div></div>
                                    <span class="rate-text"><?= $rate ?> %</span>
                                </div>
                            <?php else: ?>
                                <span style="color:rgba(255,255,255,.3)">Aucune donnée</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="no-data"><i class="fas fa-inbox"></i><p>Ce quiz ne contient aucune question.</p></div>
        <?php endif; ?>

        <!-- Tentatives -->
        <h3><i class="fas fa-user-graduate"></i> Détail par étudiant</h3>
        <?php if (!empty($attempts)): ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Étudiant</th>
                        <th>Tentative</th>
                        <th>Commencée</th>
                        <th>Soumise</th>
                        <th>Statut</th>
                        <th>Score brut</th>
                        <th>Note /20</th>
                        <th>Retenue</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($attempts as $a):
                    $grade = $a['final_grade'] !== null ? (float) $a['final_grade'] : null;
                    $gcls  = $grade === null ? '' : ($grade >= 10 ? 'grade-good' : ($grade >= 7 ? 'grade-mid' : 'grade-bad'));
                    $is_retained = isset($retained[$a['student_id']]) && $grade !== null
                        && abs($retained[$a['student_id']] - $grade) < 0.005;
                ?>
                    <tr>
                        <td><?= htmlspecialchars($a['student_name']) ?></td>
                        <td>n° <?= (int) $a['attempt_number'] ?></td>
                        <td style="white-space:nowrap"><?= date('d/m H:i', strtotime($a['started_at'])) ?></td>
                        <td style="white-space:nowrap"><?= $a['submitted_at'] ? date('d/m H:i', strtotime($a['submitted_at'])) : '—' ?></td>
                        <td><span class="badge badge-<?= $a['status'] ?>"><?= $attempt_labels[$a['status']] ?? $a['status'] ?></span></td>
                        <td><?= $a['raw_score'] !== null ? number_format((float) $a['raw_score'], 2) . ' / ' . number_format((float) $a['max_score'], 2) : '—' ?></td>
                        <td class="grade-cell <?= $gcls ?>"><?= $grade !== null ? number_format($grade, 2) : '—' ?></td>
                        <td>
                            <?php if ($is_retained && $quiz['max_attempts'] > 1): ?>
                                <span class="badge badge-retained" title="Note retenue (<?= $method_labels[$quiz['grading_method']] ?>)"><i class="fas fa-star"></i></span>
                            <?php elseif ($is_retained): ?>
                                <span class="badge badge-retained"><i class="fas fa-star"></i></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="no-data"><i class="fas fa-hourglass-half"></i><p>Aucune tentative pour ce quiz pour le moment.</p></div>
        <?php endif; ?>

    <?php elseif (!empty($all_quizzes)): ?>
        <div class="no-data">
            <i class="fas fa-chart-pie"></i>
            <p>Sélectionnez un quiz pour afficher ses résultats.</p>
        </div>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-clipboard-question"></i>
            <p>Aucun quiz créé pour le moment. <a href="quiz_manage.php?new=1" style="color:var(--accent-color)">Créer un quiz</a></p>
        </div>
    <?php endif; ?>
</main>

<?php include '../includes/footer.php'; ?>
</body>
</html>
