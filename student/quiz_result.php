<?php
require_once '../includes/db_connect.php';
require_once '../includes/quiz_functions.php';

// Contrôle du rôle
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../pages/login.php?error=access_denied");
    exit();
}

$student_id = $_SESSION['user_id'];

$quiz_id = (int) ($_GET['quiz_id'] ?? 0);
$quiz    = $quiz_id ? quiz_student_get_quiz($conn, $quiz_id, $student_id) : null;

if (!$quiz) {
    $_SESSION['quiz_student_err'] = "Quiz introuvable ou non accessible.";
    header("Location: quiz_list.php");
    exit();
}

$success_msg = $_SESSION['quiz_student_msg'] ?? '';
unset($_SESSION['quiz_student_msg']);

// ── Tentatives terminées de CET étudiant ─────────────────────
$stmt = $conn->prepare("
    SELECT * FROM quiz_attempts
    WHERE quiz_id = ? AND student_id = ? AND status IN ('submitted','expired')
    ORDER BY attempt_number ASC
");
$stmt->bind_param("is", $quiz_id, $student_id);
$stmt->execute();
$attempts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (empty($attempts)) {
    $_SESSION['quiz_student_err'] = "Aucune tentative terminée pour ce quiz.";
    header("Location: quiz_list.php");
    exit();
}

// ── Tentative affichée (paramètre optionnel, toujours vérifiée) ──
$selected = end($attempts); // par défaut : la dernière
if (isset($_GET['attempt_id'])) {
    $wanted = (int) $_GET['attempt_id'];
    foreach ($attempts as $a) {
        if ((int) $a['id'] === $wanted) {
            $selected = $a;
            break;
        }
    }
}

// ── Règles d'affichage selon show_correction (jamais de fuite avant) ──
// never        → ni score ni correction
// after_submit → score + correction dès la soumission
// after_close  → score + correction uniquement quand le quiz est fermé
$show_results = $quiz['show_correction'] === 'after_submit'
             || ($quiz['show_correction'] === 'after_close' && $quiz['status'] === 'closed');

// Note retenue selon la méthode (uniquement si les résultats sont visibles)
$retained = null;
if ($show_results) {
    $grades = array_map(static fn($a) => (float) $a['final_grade'], array_filter($attempts, static fn($a) => $a['final_grade'] !== null));
    if ($grades) {
        switch ($quiz['grading_method']) {
            case 'average': $retained = round(array_sum($grades) / count($grades), 2); break;
            case 'last':    $retained = end($grades); break;
            case 'best':
            default:        $retained = max($grades); break;
        }
    }
}

// Détail de correction (questions dans l'ordre de la tentative sélectionnée)
$questions = [];
$detail    = [];
$answers   = [];
if ($show_results) {
    $questions = quiz_load_quiz_questions($conn, $quiz_id);
    if (!empty($quiz['shuffle_questions'])) {
        $questions = quiz_seeded_shuffle($questions, (int) $selected['id']);
    }
    $detail  = json_decode($selected['detail'] ?? '{}', true) ?: [];
    $answers = json_decode($selected['answers'] ?? '{}', true) ?: [];
}

$attempt_labels = ['submitted' => 'Soumise', 'expired' => 'Expirée (temps écoulé)'];
$method_labels  = ['best' => 'meilleure tentative', 'last' => 'dernière tentative', 'average' => 'moyenne des tentatives'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Résultats — <?= htmlspecialchars($quiz['title']) ?></title>
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
        .header-content { max-width:900px; margin:0 auto; padding:0 20px; text-align:center; }
        .header-content h1 { font-size:22px; color:var(--accent-color); margin-bottom:15px; }
        nav ul { list-style:none; display:flex; justify-content:center; gap:15px; flex-wrap:wrap; }
        nav a { color:var(--text-light); text-decoration:none; padding:7px 14px; border-radius:5px; display:flex; align-items:center; gap:7px; transition:background .3s; }
        nav a:hover { background:rgba(3,155,229,.15); }
        nav a.active { background:rgba(3,155,229,.25); }
        nav a[href*="logout"] { color:#dc3545; }
        main { flex:1; max-width:900px; margin:0 auto; padding:30px 20px; width:100%; }
        h2 { font-size:19px; color:var(--accent-color); margin-bottom:6px; }
        .sub { font-size:13px; color:rgba(255,255,255,.55); margin-bottom:22px; }

        .alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:rgba(46,204,113,.12); border:1px solid rgba(46,204,113,.35); color:var(--success-color); }
        .alert-info    { background:rgba(3,155,229,.1);   border:1px solid rgba(3,155,229,.3);   color:var(--accent-color); }

        .score-banner { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:24px; }
        .score-box { flex:1; min-width:160px; background:var(--secondary-bg); border:1px solid var(--border-color); border-radius:12px; padding:18px 20px; text-align:center; }
        .score-box .sb-label { font-size:12px; color:rgba(255,255,255,.55); margin-bottom:8px; }
        .score-box .sb-value { font-size:30px; font-weight:800; }
        .sb-good { color:var(--success-color); }
        .sb-mid  { color:var(--warning-color); }
        .sb-bad  { color:var(--danger-color); }
        .sb-neutral { color:var(--accent-color); }

        .attempts-nav { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:22px; align-items:center; }
        .attempts-nav .an-label { font-size:13px; color:rgba(255,255,255,.55); }
        .attempt-pill { padding:6px 14px; border-radius:18px; border:1px solid var(--border-color); font-size:12px; font-weight:600; color:rgba(255,255,255,.7); text-decoration:none; transition:all .2s; }
        .attempt-pill:hover { border-color:var(--accent-color); color:var(--text-light); }
        .attempt-pill.active { background:var(--accent-color); border-color:var(--accent-color); color:#fff; }

        .badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; }
        .badge-submitted { background:rgba(46,204,113,.2); color:var(--success-color); }
        .badge-expired   { background:rgba(243,156,18,.18); color:var(--warning-color); }

        .question-card { background:var(--secondary-bg); border:1px solid var(--border-color); border-radius:10px; padding:18px 22px; margin-bottom:16px; }
        .question-card.q-correct { border-left:4px solid var(--success-color); }
        .question-card.q-partial { border-left:4px solid var(--warning-color); }
        .question-card.q-wrong   { border-left:4px solid var(--danger-color); }
        .q-head { display:flex; justify-content:space-between; gap:12px; margin-bottom:10px; align-items:baseline; }
        .q-num { font-size:13px; font-weight:700; color:var(--accent-color); }
        .q-score { font-size:13px; font-weight:700; white-space:nowrap; }
        .q-text { font-size:14px; line-height:1.5; margin-bottom:14px; white-space:pre-wrap; }
        .a-line { display:flex; align-items:flex-start; gap:9px; padding:7px 12px; border-radius:7px; margin-bottom:6px; font-size:13px; line-height:1.4; border:1px solid transparent; }
        .a-line i { margin-top:2px; flex-shrink:0; }
        .a-correct-chosen { background:rgba(46,204,113,.12); border-color:rgba(46,204,113,.3); color:var(--success-color); }
        .a-wrong-chosen   { background:rgba(231,76,60,.1);   border-color:rgba(231,76,60,.3);  color:var(--danger-color); }
        .a-correct-missed { background:rgba(46,204,113,.05); border-color:rgba(46,204,113,.2); color:rgba(46,204,113,.75); }
        .a-neutral        { color:rgba(255,255,255,.55); }
        .short-block { font-size:13px; margin-bottom:6px; }
        .short-block .sb-tag { display:inline-block; width:130px; color:rgba(255,255,255,.5); }
        .explanation { margin-top:12px; background:rgba(3,155,229,.08); border:1px solid rgba(3,155,229,.22); border-radius:8px; padding:11px 14px; font-size:13px; color:rgba(255,255,255,.75); }
        .explanation i { color:var(--accent-color); margin-right:6px; }

        .btn { display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border-radius:6px; border:1px solid var(--border-color); cursor:pointer; font-size:13px; font-weight:600; text-decoration:none; color:var(--text-light); background:transparent; transition:all .3s; }
        .btn:hover { border-color:var(--accent-color); color:var(--accent-color); }
        .back-row { margin-bottom:20px; }
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
    <div class="back-row">
        <a href="quiz_list.php" class="btn"><i class="fas fa-arrow-left"></i> Retour à mes quiz</a>
    </div>

    <h2><i class="fas fa-square-poll-vertical"></i> Résultats — <?= htmlspecialchars($quiz['title']) ?></h2>
    <div class="sub"><?= htmlspecialchars($quiz['course_name']) ?></div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>

    <?php if (!$show_results): ?>

        <div class="alert alert-info">
            <i class="fas fa-hourglass-half"></i>
            <?php if ($quiz['show_correction'] === 'never'): ?>
                Votre tentative a bien été enregistrée. Les résultats de ce quiz seront communiqués par votre enseignant.
            <?php else: ?>
                Votre tentative a bien été enregistrée. Le score et la correction seront visibles à la fermeture du quiz
                (prévue le <?= date('d/m/Y à H:i', strtotime($quiz['end_date'])) ?>).
            <?php endif; ?>
        </div>
        <?php foreach ($attempts as $a): ?>
            <div class="sub" style="margin-bottom:8px">
                Tentative n° <?= (int) $a['attempt_number'] ?> —
                <span class="badge badge-<?= $a['status'] ?>"><?= $attempt_labels[$a['status']] ?></span>
                <?= $a['submitted_at'] ? ' le ' . date('d/m/Y à H:i', strtotime($a['submitted_at'])) : '' ?>
            </div>
        <?php endforeach; ?>

    <?php else: ?>

        <!-- Bandeaux de score -->
        <?php
            $grade = $selected['final_grade'] !== null ? (float) $selected['final_grade'] : null;
            $gcls  = $grade === null ? 'sb-neutral' : ($grade >= 10 ? 'sb-good' : ($grade >= 7 ? 'sb-mid' : 'sb-bad'));
        ?>
        <div class="score-banner">
            <div class="score-box">
                <div class="sb-label">Tentative n° <?= (int) $selected['attempt_number'] ?> — <?= $attempt_labels[$selected['status']] ?></div>
                <div class="sb-value <?= $gcls ?>"><?= $grade !== null ? number_format($grade, 2) : '—' ?> <span style="font-size:15px">/ 20</span></div>
            </div>
            <div class="score-box">
                <div class="sb-label">Points obtenus</div>
                <div class="sb-value sb-neutral" style="font-size:24px">
                    <?= number_format((float) $selected['raw_score'], 2) ?> / <?= number_format((float) $selected['max_score'], 2) ?>
                </div>
            </div>
            <?php if (count($attempts) > 1 && $retained !== null): ?>
            <div class="score-box">
                <div class="sb-label">Note retenue (<?= $method_labels[$quiz['grading_method']] ?? '' ?>)</div>
                <div class="sb-value sb-neutral"><?= number_format($retained, 2) ?> <span style="font-size:15px">/ 20</span></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (count($attempts) > 1): ?>
        <div class="attempts-nav">
            <span class="an-label">Tentatives :</span>
            <?php foreach ($attempts as $a): ?>
                <a class="attempt-pill <?= $a['id'] == $selected['id'] ? 'active' : '' ?>"
                   href="quiz_result.php?quiz_id=<?= $quiz_id ?>&attempt_id=<?= $a['id'] ?>">
                    n° <?= (int) $a['attempt_number'] ?> — <?= $a['final_grade'] !== null ? number_format((float) $a['final_grade'], 2) : '—' ?>/20
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Correction détaillée -->
        <?php $num = 1; foreach ($questions as $qid => $q):
            $d      = $detail[(string) $qid] ?? ['earned' => 0, 'max' => $q['points']];
            $earned = (float) $d['earned'];
            $max    = (float) $d['max'];
            $state  = $earned >= $max - 0.005 ? 'q-correct' : ($earned > 0 ? 'q-partial' : 'q-wrong');
            $answer = $answers[(string) $qid] ?? null;
            $chosen_ids  = is_array($answer) ? array_map('intval', $answer) : [];
            $chosen_text = is_string($answer) ? $answer : '';
        ?>
        <div class="question-card <?= $state ?>">
            <div class="q-head">
                <span class="q-num">Question <?= $num ?></span>
                <span class="q-score <?= $earned >= $max - 0.005 ? 'sb-good' : ($earned > 0 ? 'sb-mid' : 'sb-bad') ?>">
                    <?= number_format($earned, 2) ?> / <?= number_format($max, 2) ?> pt
                </span>
            </div>
            <div class="q-text"><?= htmlspecialchars($q['question_text']) ?></div>

            <?php if ($q['type'] === 'short_answer'): ?>
                <div class="short-block">
                    <span class="sb-tag">Votre réponse :</span>
                    <?php if ($chosen_text !== ''): ?>
                        <strong style="color:<?= $earned > 0 ? 'var(--success-color)' : 'var(--danger-color)' ?>"><?= htmlspecialchars($chosen_text) ?></strong>
                    <?php else: ?>
                        <em style="color:rgba(255,255,255,.4)">Aucune réponse</em>
                    <?php endif; ?>
                </div>
                <?php if ($earned < $max && !empty($q['accepted'])): ?>
                    <div class="short-block">
                        <span class="sb-tag">Réponse attendue :</span>
                        <strong style="color:var(--success-color)"><?= htmlspecialchars($q['accepted'][0]) ?></strong>
                    </div>
                <?php endif; ?>
            <?php else:
                $opts = $q['options'];
                if (!empty($quiz['shuffle_options'])) {
                    $opts = quiz_seeded_shuffle($opts, ((int) $selected['id']) * 31 + $qid);
                }
                foreach ($opts as $oid => $opt):
                    $chosen  = in_array($oid, $chosen_ids, true);
                    $correct = !empty($opt['is_correct']);
                    if ($chosen && $correct)      { $cls = 'a-correct-chosen'; $icon = 'fa-check-circle'; }
                    elseif ($chosen && !$correct) { $cls = 'a-wrong-chosen';   $icon = 'fa-times-circle'; }
                    elseif (!$chosen && $correct) { $cls = 'a-correct-missed'; $icon = 'fa-circle-check'; }
                    else                          { $cls = 'a-neutral';        $icon = 'fa-circle'; }
            ?>
                <div class="a-line <?= $cls ?>">
                    <i class="<?= $cls === 'a-neutral' ? 'far' : 'fas' ?> <?= $icon ?>"></i>
                    <span>
                        <?= htmlspecialchars($opt['text']) ?>
                        <?php if (!$chosen && $correct): ?> <em style="font-size:11px">(bonne réponse non cochée)</em><?php endif; ?>
                        <?php if ($chosen && !$correct): ?> <em style="font-size:11px">(votre choix)</em><?php endif; ?>
                    </span>
                </div>
            <?php endforeach; endif; ?>

            <?php if (!empty($q['explanation'])): ?>
                <div class="explanation"><i class="fas fa-lightbulb"></i><?= nl2br(htmlspecialchars($q['explanation'])) ?></div>
            <?php endif; ?>
        </div>
        <?php $num++; endforeach; ?>

    <?php endif; ?>
</main>

<?php include '../includes/footer.php'; ?>
</body>
</html>
