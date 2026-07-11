<?php
require_once '../includes/db_connect.php';
require_once '../includes/quiz_functions.php';

// Contrôle du rôle
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../pages/login.php?error=access_denied");
    exit();
}

$student_id = $_SESSION['user_id'];

// ============================================================
// AJAX POST (JSON) : autosave + soumission
// Le timer fait AUTORITÉ CÔTÉ SERVEUR (note 3) : toute requête
// au-delà de l'échéance + 30 s bascule la tentative en 'expired'
// et la corrige avec les réponses autosauvées.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $payload    = json_decode(file_get_contents('php://input'), true);
    $action     = $payload['action'] ?? '';
    $attempt_id = (int) ($payload['attempt_id'] ?? 0);

    // Tentative appartenant à CET étudiant, encore en cours
    $stmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE id = ? AND student_id = ?");
    $stmt->bind_param("is", $attempt_id, $student_id);
    $stmt->execute();
    $attempt = $stmt->get_result()->fetch_assoc();

    if (!$attempt) {
        echo json_encode(['success' => false, 'message' => 'Tentative introuvable ou accès refusé']);
        exit();
    }

    $quiz = quiz_student_get_quiz($conn, (int) $attempt['quiz_id'], $student_id);
    if (!$quiz) {
        echo json_encode(['success' => false, 'message' => 'Quiz inaccessible']);
        exit();
    }

    $redirect = 'quiz_result.php?quiz_id=' . (int) $quiz['id'];

    if ($attempt['status'] !== 'in_progress') {
        // Déjà soumise/expirée (double clic, onglet dupliqué…)
        echo json_encode(['success' => true, 'expired' => true, 'redirect' => $redirect]);
        exit();
    }

    // ── Échéance dépassée → correction avec les réponses autosauvées ──
    if (quiz_attempt_is_expired($conn, $quiz, $attempt)) {
        quiz_finalize_attempt($conn, $quiz, $attempt, 'expired');
        echo json_encode([
            'success'  => true,
            'expired'  => true,
            'message'  => 'Temps écoulé : votre tentative a été corrigée avec les réponses sauvegardées.',
            'redirect' => $redirect,
        ]);
        exit();
    }

    $questions = quiz_load_quiz_questions($conn, (int) $quiz['id']);
    $answers   = quiz_sanitize_answers($payload['answers'] ?? null, $questions);

    if ($action === 'autosave') {
        $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("UPDATE quiz_attempts SET answers = ?, last_saved_at = NOW() WHERE id = ? AND status = 'in_progress'");
        $stmt->bind_param("si", $json, $attempt_id);
        $stmt->execute();
        echo json_encode(['success' => true, 'saved_at' => date('H:i:s')]);
        exit();
    }

    if ($action === 'submit') {
        // Enregistrer les réponses finales puis corriger
        $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
        $stmt = $conn->prepare("UPDATE quiz_attempts SET answers = ?, last_saved_at = NOW() WHERE id = ? AND status = 'in_progress'");
        $stmt->bind_param("si", $json, $attempt_id);
        $stmt->execute();

        $attempt['answers'] = $json;
        quiz_finalize_attempt($conn, $quiz, $attempt, 'submitted');

        echo json_encode(['success' => true, 'redirect' => $redirect]);
        exit();
    }

    echo json_encode(['success' => false, 'message' => 'Action inconnue']);
    exit();
}

// ============================================================
// GET : démarrage ou reprise d'une tentative
// ============================================================
$quiz_id = (int) ($_GET['quiz_id'] ?? 0);
$quiz    = $quiz_id ? quiz_student_get_quiz($conn, $quiz_id, $student_id) : null;

if (!$quiz) {
    $_SESSION['quiz_student_err'] = "Quiz introuvable ou non accessible.";
    header("Location: quiz_list.php");
    exit();
}

$now   = quiz_db_time($conn); // horloge MySQL (fuseaux PHP/MySQL différents)
$start = strtotime($quiz['start_date']);
$end   = strtotime($quiz['end_date']);

// ── Tentative en cours existante ? ───────────────────────────
$stmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? AND status = 'in_progress' ORDER BY attempt_number DESC LIMIT 1");
$stmt->bind_param("is", $quiz_id, $student_id);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();

if ($attempt && quiz_attempt_is_expired($conn, $quiz, $attempt)) {
    // Reprise trop tardive → corriger avec l'autosave et montrer le résultat
    quiz_finalize_attempt($conn, $quiz, $attempt, 'expired');
    $_SESSION['quiz_student_msg'] = "Temps écoulé : votre tentative a été corrigée avec les réponses sauvegardées.";
    header("Location: quiz_result.php?quiz_id=$quiz_id");
    exit();
}

if (!$attempt) {
    // ── Contrôles serveur avant création ─────────────────────
    if ($quiz['status'] !== 'published' || $now < $start || $now > $end) {
        $_SESSION['quiz_student_err'] = "Ce quiz n'est pas ouvert actuellement.";
        header("Location: quiz_list.php");
        exit();
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS n, COALESCE(MAX(attempt_number), 0) AS max_num FROM quiz_attempts WHERE quiz_id = ? AND student_id = ?");
    $stmt->bind_param("is", $quiz_id, $student_id);
    $stmt->execute();
    $agg = $stmt->get_result()->fetch_assoc();

    if ((int) $agg['n'] >= (int) $quiz['max_attempts']) {
        $_SESSION['quiz_student_err'] = "Vous avez utilisé toutes vos tentatives pour ce quiz.";
        header("Location: quiz_list.php");
        exit();
    }

    $attempt_number = (int) $agg['max_num'] + 1;
    $ip             = $_SERVER['REMOTE_ADDR'] ?? null;

    $attempt_id = 0;
    try {
        $stmt = $conn->prepare("INSERT INTO quiz_attempts (quiz_id, student_id, attempt_number, answers, status, ip_address) VALUES (?, ?, ?, '{}', 'in_progress', ?)");
        $stmt->bind_param("isis", $quiz_id, $student_id, $attempt_number, $ip);
        if ($stmt->execute()) {
            $attempt_id = $conn->insert_id;
        }
    } catch (mysqli_sql_exception $e) {
        // Course avec un double clic : l'unique key uq_attempt a bloqué
    }
    if (!$attempt_id) {
        // Recharger la tentative créée en parallèle (double clic / double onglet)
        $stmt = $conn->prepare("SELECT id FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? AND attempt_number = ?");
        $stmt->bind_param("isi", $quiz_id, $student_id, $attempt_number);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            $_SESSION['quiz_student_err'] = "Impossible de démarrer la tentative. Réessayez.";
            header("Location: quiz_list.php");
            exit();
        }
        $attempt_id = (int) $row['id'];
    }

    $stmt = $conn->prepare("SELECT * FROM quiz_attempts WHERE id = ?");
    $stmt->bind_param("i", $attempt_id);
    $stmt->execute();
    $attempt = $stmt->get_result()->fetch_assoc();
}

// ── Questions + mélange déterministe seedé par l'ID de tentative (note 4) ──
$questions = quiz_load_quiz_questions($conn, $quiz_id);
if (empty($questions)) {
    $_SESSION['quiz_student_err'] = "Ce quiz ne contient aucune question.";
    header("Location: quiz_list.php");
    exit();
}

$attempt_id = (int) $attempt['id'];

if (!empty($quiz['shuffle_questions'])) {
    $questions = quiz_seeded_shuffle($questions, $attempt_id);
}
if (!empty($quiz['shuffle_options'])) {
    foreach ($questions as $qid => $q) {
        if (!empty($q['options'])) {
            $questions[$qid]['options'] = quiz_seeded_shuffle($q['options'], $attempt_id * 31 + $qid);
        }
    }
}

$saved_answers = json_decode($attempt['answers'] ?? '{}', true);
if (!is_array($saved_answers)) {
    $saved_answers = [];
}

$seconds_left = quiz_attempt_seconds_left($conn, $quiz, $attempt);
$nb_questions = count($questions);
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
    <title><?= htmlspecialchars($quiz['title']) ?> — Quiz</title>
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
        body { font-family: 'Google Sans', Arial, sans-serif; background: var(--primary-bg); color: var(--text-light); min-height: 100vh; }
        main { max-width:860px; margin:0 auto; padding:24px 20px 60px; }

        /* Barre sticky : titre + timer + autosave */
        .quiz-topbar { position:sticky; top:0; z-index:50; background:var(--secondary-bg); border:1px solid var(--border-color); border-radius:0 0 12px 12px; padding:14px 20px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; box-shadow:0 4px 18px rgba(0,0,0,.35); }
        .quiz-topbar .qt-title { flex:1; min-width:200px; }
        .quiz-topbar .qt-title h1 { font-size:16px; color:var(--accent-color); }
        .quiz-topbar .qt-title .qt-course { font-size:12px; color:rgba(255,255,255,.55); margin-top:3px; }
        .qt-progress { font-size:12px; color:rgba(255,255,255,.6); white-space:nowrap; }
        .qt-progress strong { color:var(--accent-color); font-size:15px; }
        .qt-save { font-size:11px; color:rgba(255,255,255,.45); display:flex; align-items:center; gap:5px; white-space:nowrap; }
        .qt-save.saving { color:var(--warning-color); }
        .qt-save.saved  { color:var(--success-color); }
        .qt-save.error  { color:var(--danger-color); }
        .qt-timer { font-size:20px; font-weight:800; font-variant-numeric:tabular-nums; padding:7px 15px; border-radius:8px; background:rgba(3,155,229,.15); color:var(--accent-color); display:flex; align-items:center; gap:8px; }
        .qt-timer.warn { background:rgba(243,156,18,.18); color:var(--warning-color); }
        .qt-timer.danger { background:rgba(231,76,60,.2); color:var(--danger-color); animation:pulse 1s infinite; }
        @keyframes pulse { 50% { opacity:.55; } }

        .quiz-desc { background:rgba(3,155,229,.08); border:1px solid rgba(3,155,229,.25); border-radius:10px; padding:14px 18px; margin:20px 0; font-size:13px; color:rgba(255,255,255,.75); }

        .question-card { background:var(--secondary-bg); border:1px solid var(--border-color); border-radius:10px; padding:20px 22px; margin-bottom:18px; }
        .question-card.answered { border-color:rgba(46,204,113,.35); }
        .q-head { display:flex; justify-content:space-between; gap:12px; margin-bottom:12px; align-items:baseline; }
        .q-num { font-size:13px; font-weight:700; color:var(--accent-color); }
        .q-points { font-size:12px; color:rgba(255,255,255,.5); white-space:nowrap; }
        .q-text { font-size:15px; line-height:1.55; margin-bottom:16px; white-space:pre-wrap; }
        .q-choice { display:flex; align-items:flex-start; gap:11px; padding:10px 13px; border:1px solid var(--border-color); border-radius:8px; margin-bottom:8px; cursor:pointer; transition:all .15s; font-size:14px; line-height:1.4; }
        .q-choice:hover { border-color:var(--accent-color); background:rgba(3,155,229,.07); }
        .q-choice input { margin-top:2px; accent-color:var(--accent-color); }
        .q-choice.selected { border-color:var(--accent-color); background:rgba(3,155,229,.12); }
        input[type=text].q-short { background:#0d3152; color:var(--text-light); border:1px solid var(--border-color); border-radius:6px; padding:10px 14px; font-size:14px; width:100%; }
        input[type=text].q-short:focus { outline:none; border-color:var(--accent-color); }
        .q-hint { font-size:11px; color:rgba(255,255,255,.4); margin-top:6px; }

        .submit-zone { text-align:center; margin-top:30px; }
        .btn { display:inline-flex; align-items:center; gap:9px; padding:13px 34px; border-radius:8px; border:none; cursor:pointer; font-size:15px; font-weight:700; transition:all .3s; }
        .btn-submit { background:var(--success-color); color:#06371d; }
        .btn-submit:hover { filter:brightness(1.12); }
        .btn-submit:disabled { opacity:.5; cursor:not-allowed; }
        .submit-note { font-size:12px; color:rgba(255,255,255,.45); margin-top:10px; }
    </style>
</head>
<body>
<main>
    <div class="quiz-topbar">
        <div class="qt-title">
            <h1><i class="fas fa-clipboard-question"></i> <?= htmlspecialchars($quiz['title']) ?></h1>
            <div class="qt-course"><?= htmlspecialchars($quiz['course_name']) ?> — Tentative n° <?= (int) $attempt['attempt_number'] ?> / <?= (int) $quiz['max_attempts'] ?></div>
        </div>
        <div class="qt-progress"><strong id="answeredCount">0</strong> / <?= $nb_questions ?> répondue(s)</div>
        <div class="qt-save" id="saveStatus"><i class="fas fa-cloud"></i> <span>Sauvegarde automatique active</span></div>
        <div class="qt-timer" id="timer" data-seconds="<?= $seconds_left ?>" data-show="<?= (!empty($quiz['duration_minutes']) || $seconds_left < 86400) ? 1 : 0 ?>">
            <i class="fas fa-stopwatch"></i> <span id="timerText">--:--</span>
        </div>
    </div>

    <?php if (!empty($quiz['description'])): ?>
        <div class="quiz-desc"><i class="fas fa-circle-info"></i> <?= nl2br(htmlspecialchars($quiz['description'])) ?></div>
    <?php endif; ?>

    <form id="quizForm" onsubmit="return false">
    <?php $num = 1; foreach ($questions as $qid => $q):
        $saved = $saved_answers[(string) $qid] ?? null;
        $saved_ids  = is_array($saved) ? array_map('intval', $saved) : [];
        $saved_text = is_string($saved) ? $saved : '';
    ?>
        <div class="question-card" data-qid="<?= $qid ?>" data-type="<?= $q['type'] ?>">
            <div class="q-head">
                <span class="q-num">Question <?= $num ?> / <?= $nb_questions ?></span>
                <span class="q-points"><?= rtrim(rtrim(number_format($q['points'], 2, '.', ''), '0'), '.') ?> pt</span>
            </div>
            <div class="q-text"><?= htmlspecialchars($q['question_text']) ?></div>

            <?php if ($q['type'] === 'short_answer'): ?>
                <input type="text" class="q-short" maxlength="255" placeholder="Votre réponse…"
                       value="<?= htmlspecialchars($saved_text) ?>" autocomplete="off">
                <div class="q-hint">Réponse libre — la casse et les accents sont ignorés à la correction.</div>
            <?php else:
                $is_multi   = $q['type'] === 'multiple_choice';
                $input_type = $is_multi ? 'checkbox' : 'radio';
            ?>
                <?php foreach ($q['options'] as $oid => $opt): ?>
                    <label class="q-choice <?= in_array($oid, $saved_ids, true) ? 'selected' : '' ?>">
                        <input type="<?= $input_type ?>" name="q_<?= $qid ?>" value="<?= $oid ?>"
                               <?= in_array($oid, $saved_ids, true) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($opt['text']) ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if ($is_multi): ?>
                    <div class="q-hint">Plusieurs réponses possibles.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php $num++; endforeach; ?>
    </form>

    <div class="submit-zone">
        <button class="btn btn-submit" id="submitBtn" onclick="submitQuiz(false)">
            <i class="fas fa-paper-plane"></i> Soumettre mes réponses
        </button>
        <div class="submit-note">Vos réponses sont sauvegardées automatiquement toutes les 30 secondes et à chaque modification.</div>
    </div>
</main>

<script>
const ATTEMPT_ID = <?= $attempt_id ?>;
let submitted    = false;
let saveTimer    = null;

// ── Collecte des réponses : {qid: [option_ids] | "texte"} ────
function collectAnswers() {
    const answers = {};
    document.querySelectorAll('.question-card').forEach(card => {
        const qid  = card.dataset.qid;
        const type = card.dataset.type;
        if (type === 'short_answer') {
            const val = card.querySelector('input.q-short').value.trim();
            if (val !== '') answers[qid] = val;
        } else {
            const ids = [...card.querySelectorAll('input:checked')].map(i => parseInt(i.value, 10));
            if (ids.length) answers[qid] = ids;
        }
    });
    return answers;
}

function updateProgress() {
    const n = Object.keys(collectAnswers()).length;
    document.getElementById('answeredCount').textContent = n;
    document.querySelectorAll('.question-card').forEach(card => {
        const type = card.dataset.type;
        const has  = type === 'short_answer'
            ? card.querySelector('input.q-short').value.trim() !== ''
            : card.querySelector('input:checked') !== null;
        card.classList.toggle('answered', has);
    });
    // Surbrillance des choix cochés
    document.querySelectorAll('.q-choice').forEach(label => {
        label.classList.toggle('selected', label.querySelector('input').checked);
    });
}

// ── Autosave (30 s + à chaque changement, débounce 1 s) ──────
function setSaveStatus(cls, icon, text) {
    const el = document.getElementById('saveStatus');
    el.className = 'qt-save ' + cls;
    el.innerHTML = `<i class="fas ${icon}"></i> <span>${text}</span>`;
}

async function autosave() {
    if (submitted) return;
    setSaveStatus('saving', 'fa-arrows-rotate fa-spin', 'Sauvegarde…');
    try {
        const res  = await fetch('quiz_take.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'autosave', attempt_id: ATTEMPT_ID, answers: collectAnswers() })
        });
        const data = await res.json();
        if (data.expired) { handleExpired(data); return; }
        if (data.success) {
            setSaveStatus('saved', 'fa-cloud-arrow-up', 'Enregistré à ' + data.saved_at);
        } else {
            setSaveStatus('error', 'fa-triangle-exclamation', data.message || 'Erreur de sauvegarde');
        }
    } catch (e) {
        // Connexion instable : on retentera au prochain cycle
        setSaveStatus('error', 'fa-wifi', 'Hors ligne — nouvelle tentative bientôt');
    }
}

function scheduleAutosave() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(autosave, 1000);
}

document.getElementById('quizForm').addEventListener('change', () => { updateProgress(); scheduleAutosave(); });
document.getElementById('quizForm').addEventListener('input',  e => {
    if (e.target.classList.contains('q-short')) { updateProgress(); scheduleAutosave(); }
});
setInterval(autosave, 30000);

// ── Timer JS (cosmétique : le serveur fait autorité) ─────────
let secondsLeft = parseInt(document.getElementById('timer').dataset.seconds, 10);
const showTimer = document.getElementById('timer').dataset.show === '1';
if (!showTimer) document.getElementById('timer').style.display = 'none';

function fmt(s) {
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return (h > 0 ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
}

function tick() {
    if (submitted) return;
    const timer = document.getElementById('timer');
    document.getElementById('timerText').textContent = fmt(Math.max(0, secondsLeft));
    if (secondsLeft <= 60)       timer.className = 'qt-timer danger';
    else if (secondsLeft <= 300) timer.className = 'qt-timer warn';
    if (secondsLeft <= 0) {
        submitQuiz(true); // dans la fenêtre de grâce serveur de 30 s
        return;
    }
    secondsLeft--;
    setTimeout(tick, 1000);
}
tick();

// ── Soumission ───────────────────────────────────────────────
function handleExpired(data) {
    submitted = true;
    alert(data.message || 'Temps écoulé : votre tentative a été corrigée avec les réponses sauvegardées.');
    window.location.href = data.redirect;
}

async function submitQuiz(auto) {
    if (submitted) return;
    if (!auto) {
        const n = Object.keys(collectAnswers()).length;
        const warn = n < <?= $nb_questions ?> ? `\n⚠ ${<?= $nb_questions ?> - n} question(s) sans réponse.` : '';
        if (!confirm('Soumettre définitivement vos réponses ?' + warn)) return;
    }
    submitted = true;
    document.getElementById('submitBtn').disabled = true;
    try {
        const res  = await fetch('quiz_take.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'submit', attempt_id: ATTEMPT_ID, answers: collectAnswers() })
        });
        const data = await res.json();
        if (data.success) {
            window.location.href = data.redirect;
        } else {
            submitted = false;
            document.getElementById('submitBtn').disabled = false;
            alert(data.message || 'Erreur lors de la soumission. Réessayez.');
        }
    } catch (e) {
        submitted = false;
        document.getElementById('submitBtn').disabled = false;
        alert('Erreur réseau : vos réponses restent sauvegardées, réessayez.');
    }
}

// ── Avertissement avant fermeture de l'onglet ────────────────
window.addEventListener('beforeunload', e => {
    if (!submitted) { e.preventDefault(); e.returnValue = ''; }
});

updateProgress();
</script>
</body>
</html>
