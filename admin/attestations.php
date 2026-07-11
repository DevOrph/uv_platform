<?php
// ── Auth ──────────────────────────────────────────────────────────────────
require_once '../includes/db_connect.php';
require_once '../includes/db_pdo.php';
require_once '../includes/attestation_helpers.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.html');
    exit();
}

// ── Endpoint AJAX : retourne les périodes d'une année en JSON ─────────────
// Doit être avant tout echo/HTML pour que header() fonctionne.
if (isset($_GET['_periods_only'], $_GET['annee_academique'])) {
    $yr  = trim($_GET['annee_academique']);
    $stP = $pdo->prepare("SELECT id, name FROM evaluation_periods WHERE school_year = ? ORDER BY id");
    $stP->execute([$yr]);
    header('Content-Type: application/json');
    echo json_encode($stP->fetchAll());
    exit();
}

// ── Filtres GET ───────────────────────────────────────────────────────────
$filterYear    = trim($_GET['annee_academique'] ?? '');
$filterClassId = (int)($_GET['class_id'] ?? 0);
$filterMode    = in_array($_GET['mode'] ?? '', ['annee_complete', 'semestre'])
                 ? $_GET['mode'] : 'annee_complete';
$filterPeriodId = (int)($_GET['period_id'] ?? 0);

// ── Données du formulaire de filtres ─────────────────────────────────────
$stYears = $pdo->query("SELECT DISTINCT school_year FROM evaluation_periods ORDER BY school_year DESC");
$years   = $stYears->fetchAll(PDO::FETCH_COLUMN);

$stClasses = $pdo->query("SELECT id, name FROM classes ORDER BY name");
$classes   = $stClasses->fetchAll();

// Périodes disponibles pour l'année sélectionnée (pour le select JS + PHP)
$periodsForYear = [];
if ($filterYear) {
    $stAllPeriods = $pdo->prepare("SELECT id, name FROM evaluation_periods WHERE school_year = ? ORDER BY id");
    $stAllPeriods->execute([$filterYear]);
    $periodsForYear = $stAllPeriods->fetchAll();
}

// ── Calcul de la liste si filtre actif ───────────────────────────────────
$eligible    = [];
$ineligible  = [];
$periodIds   = [];
$warningMsg  = '';
$filterPeriodLabel = '';

if ($filterYear && $filterClassId) {
    // Toutes les périodes de l'année
    $stPeriods = $pdo->prepare("SELECT id, name FROM evaluation_periods WHERE school_year = ? ORDER BY id");
    $stPeriods->execute([$filterYear]);
    $allPeriods = $stPeriods->fetchAll();
    $periodIds  = array_column($allPeriods, 'id');

    if (empty($periodIds)) {
        $warningMsg = "Aucune période d'évaluation trouvée pour l'année «&nbsp;$filterYear&nbsp;».";
    } elseif ($filterMode === 'semestre' && !$filterPeriodId) {
        $warningMsg = "Veuillez sélectionner un semestre.";
    } else {
        // Vérifier que la période demandée est valide
        if ($filterMode === 'semestre') {
            $matchPeriod = array_filter($allPeriods, fn($p) => (int)$p['id'] === $filterPeriodId);
            if (empty($matchPeriod)) {
                $warningMsg = "Période sélectionnée invalide pour cette année académique.";
            } else {
                $filterPeriodLabel = reset($matchPeriod)['name'];
            }
        }

        if (!$warningMsg) {
            // Étudiants de la classe (non bloqués)
            $stStudents = $pdo->prepare(
                "SELECT id, name, birth_date, place_of_birth
                 FROM users
                 WHERE role='student' AND blocked=0 AND class_id=?
                 ORDER BY name"
            );
            $stStudents->execute([$filterClassId]);
            $students = $stStudents->fetchAll();

            // Nom de la classe (pré-remplit le champ filière)
            $stClass = $pdo->prepare("SELECT name FROM classes WHERE id = ? LIMIT 1");
            $stClass->execute([$filterClassId]);
            $className = $stClass->fetchColumn() ?: '';
            $defaultFiliere = trim(preg_replace('/\s*\(groupe\s*\d+\)\s*/i', '', $className));

            // Attestations déjà générées pour ces étudiants / cette année / ce mode
            if ($filterMode === 'semestre') {
                $stAttest = $pdo->prepare(
                    "SELECT * FROM attestations
                     WHERE annee_academique = ? AND evaluation_period_id = ?
                       AND student_id IN (SELECT id FROM users WHERE class_id = ? AND role='student')"
                );
                $stAttest->execute([$filterYear, $filterPeriodId, $filterClassId]);
            } else {
                $stAttest = $pdo->prepare(
                    "SELECT * FROM attestations
                     WHERE annee_academique = ? AND mode = 'annee_complete'
                       AND student_id IN (SELECT id FROM users WHERE class_id = ? AND role='student')"
                );
                $stAttest->execute([$filterYear, $filterClassId]);
            }
            $existingById = [];
            foreach ($stAttest->fetchAll() as $a) {
                $existingById[$a['student_id']] = $a;
            }

            foreach ($students as $student) {
                if ($filterMode === 'semestre') {
                    $result = computeStudentSemesterValidation($pdo, $student['id'], $filterClassId, $filterPeriodId);
                } else {
                    $result = computeStudentYearValidation($pdo, $student['id'], $filterClassId, $periodIds);
                }

                $row = [
                    'student'     => $student,
                    'average'     => $result['average'],
                    'mention'     => $result['mention'],
                    'attestation' => $existingById[$student['id']] ?? null,
                    'filiere'     => $defaultFiliere,
                ];

                if ($result['validated']) {
                    $eligible[] = $row;
                } else {
                    $ineligible[] = $row;
                }
            }
        }
    }
}

// ── Historique global (toutes classes, 50 derniers) ───────────────────────
$stHistory = $pdo->query(
    "SELECT a.*, u.name AS student_name,
            ep.name AS period_name
     FROM attestations a
     JOIN users u ON u.id = a.student_id
     LEFT JOIN evaluation_periods ep ON ep.id = a.evaluation_period_id
     ORDER BY a.created_at DESC
     LIMIT 50"
);
$history = $stHistory->fetchAll();

// ── Libellé du mode courant pour l'affichage ──────────────────────────────
$modeLabel = $filterMode === 'semestre'
    ? ($filterPeriodLabel ?: 'Semestre')
    : 'Année complète';

// Sérialiser les périodes pour le JS
$periodsJson = json_encode($periodsForYear);
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
<title>Attestations de Réussite — IFSE</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
:root {
    --bg:        #051e34;
    --surface:   #0c2d48;
    --accent:    #039be5;
    --orange:    #E87722;
    --green:     #14a761;
    --red:       #e53935;
    --text:      #ffffff;
    --muted:     rgba(255,255,255,.55);
    --border:    rgba(255,255,255,.1);
    --card-bg:   rgba(255,255,255,.06);
    --radius:    10px;
    --shadow:    0 4px 20px rgba(0,0,0,.35);
}

*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: 'Google Sans', Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ── HEADER ── */
header {
    background: var(--surface);
    padding: 0 30px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid var(--border);
    position: sticky; top:0; z-index:100;
    box-shadow: 0 2px 12px rgba(0,0,0,.4);
}
.brand { display:flex; align-items:center; gap:12px; }
.brand-icon {
    width:38px; height:38px;
    background: linear-gradient(135deg,var(--orange),#c05e10);
    border-radius:9px;
    display:flex; align-items:center; justify-content:center;
    font-size:18px; box-shadow: 0 4px 12px rgba(232,119,34,.35);
}
.brand-title { font-size:16pt; font-weight:700; letter-spacing:.3px; }
.brand-title span { color:var(--orange); }
.back-link {
    color:var(--muted); font-size:9pt; text-decoration:none;
    display:flex; align-items:center; gap:6px; transition:color .2s;
}
.back-link:hover { color:var(--text); }

/* ── MAIN ── */
.main { max-width:1200px; margin:0 auto; padding:28px 20px; flex:1; width:100%; }

/* ── CARDS ── */
.card {
    background: var(--card-bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    margin-bottom: 22px;
    overflow: hidden;
}
.card-head {
    padding: 14px 22px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,.03);
}
.card-head h2 { font-size:12pt; font-weight:700; }
.card-head p  { font-size:8pt; color:var(--muted); margin-top:2px; }
.card-icon {
    width:32px; height:32px; border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    font-size:15px; flex-shrink:0;
}
.ci-orange { background:rgba(232,119,34,.18); color:var(--orange); }
.ci-blue   { background:rgba(3,155,229,.18);  color:var(--accent); }
.ci-green  { background:rgba(20,167,97,.18);  color:var(--green); }
.ci-red    { background:rgba(229,57,53,.18);  color:var(--red); }
.card-body { padding:22px; }

/* ── MODE TOGGLE ── */
.mode-toggle {
    display: flex;
    gap: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}
.mode-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 18px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    cursor: pointer;
    background: rgba(255,255,255,.05);
    color: var(--muted);
    font-size: 9.5pt;
    font-weight: 600;
    transition: all .18s;
    user-select: none;
}
.mode-btn input[type=radio] { display:none; }
.mode-btn.active {
    border-color: var(--accent);
    background: rgba(3,155,229,.12);
    color: var(--text);
    box-shadow: 0 0 0 2px rgba(3,155,229,.2);
}
.mode-btn:hover:not(.active) { background:rgba(255,255,255,.09); color:var(--text); }
.mode-dot {
    width:10px; height:10px; border-radius:50%;
    border: 2px solid var(--muted); flex-shrink:0; transition: all .18s;
}
.mode-btn.active .mode-dot { background:var(--accent); border-color:var(--accent); }

/* ── FILTER FORM ── */
.filter-grid {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 14px;
    align-items: end;
}
.filter-grid-sem {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr auto;
    gap: 14px;
    align-items: end;
}
.form-group label {
    display:block; font-size:8pt; font-weight:700;
    text-transform:uppercase; letter-spacing:.8px;
    color:var(--muted); margin-bottom:6px;
}
.form-control {
    width:100%; padding:10px 14px;
    background:rgba(255,255,255,.07); border:1.5px solid var(--border);
    border-radius:8px; color:var(--text); font-size:10pt;
    outline:none; transition:border-color .2s, box-shadow .2s;
    appearance:none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='7'%3E%3Cpath d='M6 7L0 0h12z' fill='%23ffffff55'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 12px center; padding-right:36px;
}
.form-control option { background:#0c2d48; color:#fff; }
.form-control:focus  { border-color:var(--accent); box-shadow:0 0 0 3px rgba(3,155,229,.2); }

.btn {
    padding:10px 22px; border:none; border-radius:8px;
    font-size:10pt; font-weight:700; cursor:pointer;
    transition:all .18s; display:inline-flex; align-items:center; gap:7px;
    text-decoration:none; white-space:nowrap;
}
.btn-primary {
    background:linear-gradient(135deg,var(--accent),#0277bd);
    color:#fff; box-shadow:0 4px 14px rgba(3,155,229,.3);
}
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(3,155,229,.4); }
.btn-orange {
    background:linear-gradient(135deg,var(--orange),#c05e10);
    color:#fff; box-shadow:0 4px 12px rgba(232,119,34,.3);
}
.btn-orange:hover { transform:translateY(-1px); }
.btn-ghost {
    background:rgba(255,255,255,.08); color:var(--muted);
    border:1px solid var(--border);
}
.btn-ghost:hover { background:rgba(255,255,255,.14); color:var(--text); }

/* ── SUMMARY BAR ── */
.summary-bar {
    display:flex; gap:14px; margin-bottom:18px; flex-wrap:wrap;
}
.summary-chip {
    padding:8px 18px; border-radius:20px; font-size:9.5pt; font-weight:700;
    display:flex; align-items:center; gap:7px;
}
.chip-green { background:rgba(20,167,97,.18); border:1px solid rgba(20,167,97,.4); color:#5de0a0; }
.chip-red   { background:rgba(229,57,53,.15); border:1px solid rgba(229,57,53,.3);  color:#ff8a80; }
.chip-blue  { background:rgba(3,155,229,.15); border:1px solid rgba(3,155,229,.3);  color:#80d8ff; }

/* ── TABLES ── */
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:9.5pt; }
thead th {
    background:rgba(255,255,255,.06); padding:10px 14px;
    text-align:left; font-size:8pt; font-weight:700;
    text-transform:uppercase; letter-spacing:.7px; color:var(--muted);
    border-bottom:1px solid var(--border);
}
tbody tr { border-bottom:1px solid rgba(255,255,255,.05); transition:background .15s; }
tbody tr:hover { background:rgba(255,255,255,.04); }
tbody td { padding:10px 14px; vertical-align:middle; }

/* ── MENTION BADGES ── */
.badge {
    display:inline-block; padding:3px 10px; border-radius:12px;
    font-size:7.5pt; font-weight:700; letter-spacing:.4px;
}
.b-insuf   { background:rgba(229,57,53,.2);  color:#ff8a80;  border:1px solid rgba(229,57,53,.3); }
.b-pass    { background:rgba(255,193,7,.15); color:#ffe082;  border:1px solid rgba(255,193,7,.3); }
.b-ab      { background:rgba(3,155,229,.15); color:#80d8ff;  border:1px solid rgba(3,155,229,.3); }
.b-bien    { background:rgba(20,167,97,.18); color:#5de0a0;  border:1px solid rgba(20,167,97,.4); }
.b-tb      { background:rgba(156,39,176,.18);color:#ea80fc;  border:1px solid rgba(156,39,176,.4); }

/* Badges de type d'attestation */
.b-annee   { background:rgba(3,155,229,.15); color:#80d8ff;  border:1px solid rgba(3,155,229,.3); }
.b-sem     { background:rgba(232,119,34,.15);color:#ffcc80;  border:1px solid rgba(232,119,34,.3); }

/* ── MINI FORM (generate) ── */
.mini-form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.mini-input {
    padding:6px 10px; background:rgba(255,255,255,.07);
    border:1px solid var(--border); border-radius:6px;
    color:var(--text); font-size:8.5pt; outline:none;
    transition:border-color .2s; width:160px;
}
.mini-input:focus { border-color:var(--accent); }
.mini-input::placeholder { color:var(--muted); }

/* ── WARNING / INFO BANNERS ── */
.banner {
    padding:12px 18px; border-radius:8px; margin-bottom:18px;
    font-size:9.5pt; display:flex; align-items:center; gap:9px;
}
.banner-warn  { background:rgba(255,193,7,.12); border:1px solid rgba(255,193,7,.3); color:#ffe082; }
.banner-info  { background:rgba(3,155,229,.12); border:1px solid rgba(3,155,229,.3); color:#80d8ff; }
.banner-green { background:rgba(20,167,97,.12); border:1px solid rgba(20,167,97,.3); color:#5de0a0; }

/* ── SECTION TITLES ── */
.section-title {
    font-size:11pt; font-weight:700; margin-bottom:14px;
    display:flex; align-items:center; gap:8px;
}
.dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.dot-green { background:var(--green); }
.dot-red   { background:var(--red); }

/* ── MODAL ── */
.modal-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.7); backdrop-filter:blur(5px);
    z-index:500; align-items:center; justify-content:center;
}
.modal-overlay.open { display:flex; }
.modal {
    background:#0e2840; border:1px solid var(--border);
    border-radius:14px; padding:28px; width:480px; max-width:95vw;
    box-shadow:0 20px 60px rgba(0,0,0,.6);
    animation:fadeUp .2s ease;
}
@keyframes fadeUp {
    from { opacity:0; transform:translateY(12px); }
    to   { opacity:1; transform:translateY(0); }
}
.modal h3 { font-size:13pt; font-weight:700; margin-bottom:4px; }
.modal .modal-sub { font-size:9pt; color:var(--muted); margin-bottom:20px; }
.modal-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:20px; }
.modal-grid .full { grid-column:1/-1; }
.modal label {
    display:block; font-size:7.5pt; font-weight:700;
    text-transform:uppercase; letter-spacing:.7px; color:var(--muted); margin-bottom:5px;
}
.modal input[type=text] {
    width:100%; padding:9px 12px;
    background:rgba(255,255,255,.07); border:1.5px solid var(--border);
    border-radius:7px; color:var(--text); font-size:10pt; outline:none;
    transition:border-color .2s;
}
.modal input[type=text]:focus { border-color:var(--accent); }
.modal-mode-badge {
    display:inline-flex; align-items:center; gap:6px;
    padding:5px 12px; border-radius:20px; font-size:8.5pt; font-weight:700;
    margin-bottom:14px;
}
.modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top:4px; }

/* ── LOADING OVERLAY ── */
.loading-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(5,30,52,.85); backdrop-filter:blur(6px);
    z-index:999; align-items:center; justify-content:center;
    flex-direction:column; gap:16px;
}
.loading-overlay.show { display:flex; }
.spinner {
    width:48px; height:48px;
    border:4px solid rgba(255,255,255,.15);
    border-top-color:var(--orange);
    border-radius:50%;
    animation:spin .7s linear infinite;
}
@keyframes spin { to { transform:rotate(360deg); } }
.loading-text { font-size:12pt; font-weight:600; color:#fff; }
</style>
</head>
<body>

<!-- ── HEADER ──────────────────────────────────────────────────────────── -->
<header>
    <div class="brand">
        <div class="brand-icon">🎓</div>
        <div class="brand-title">Attestations <span>IFSE</span></div>
    </div>
    <a href="admin_dashboard.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Tableau de bord
    </a>
</header>

<div class="main">

    <!-- ── FILTRE ──────────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-head">
            <div class="card-icon ci-blue"><i class="fas fa-filter"></i></div>
            <div>
                <h2>Filtrer les étudiants</h2>
                <p>Sélectionner le mode, l'année académique et la classe</p>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" action="" id="filterForm">

                <!-- Mode de génération -->
                <div style="margin-bottom:18px;">
                    <div class="form-group" style="margin-bottom:10px;">
                        <label>Mode de génération</label>
                    </div>
                    <div class="mode-toggle">
                        <label class="mode-btn <?= $filterMode === 'annee_complete' ? 'active' : '' ?>"
                               id="btn-annee" onclick="setMode('annee_complete')">
                            <input type="radio" name="mode" value="annee_complete"
                                   <?= $filterMode === 'annee_complete' ? 'checked' : '' ?>>
                            <span class="mode-dot"></span>
                            <i class="fas fa-calendar-alt"></i>
                            Année complète (S1 + S2 validés)
                        </label>
                        <label class="mode-btn <?= $filterMode === 'semestre' ? 'active' : '' ?>"
                               id="btn-sem" onclick="setMode('semestre')">
                            <input type="radio" name="mode" value="semestre"
                                   <?= $filterMode === 'semestre' ? 'checked' : '' ?>>
                            <span class="mode-dot"></span>
                            <i class="fas fa-calendar-week"></i>
                            Semestre unique (S1 ou S2)
                        </label>
                    </div>
                </div>

                <!-- Grille de filtres -->
                <div id="filterGrid" class="<?= $filterMode === 'semestre' ? 'filter-grid-sem' : 'filter-grid' ?>">
                    <div class="form-group">
                        <label>Année académique</label>
                        <select name="annee_academique" class="form-control" id="selectYear"
                                onchange="refreshPeriods()" required>
                            <option value="">— Choisir une année —</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?= htmlspecialchars($y) ?>"
                                    <?= $filterYear === $y ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($y) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Classe</label>
                        <select name="class_id" class="form-control" required>
                            <option value="">— Choisir une classe —</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>"
                                    <?= $filterClassId === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Filtre période — visible uniquement en mode semestre -->
                    <div class="form-group" id="periodGroup"
                         style="<?= $filterMode !== 'semestre' ? 'display:none;' : '' ?>">
                        <label>Semestre</label>
                        <select name="period_id" class="form-control" id="selectPeriod">
                            <option value="">— Choisir —</option>
                            <?php foreach ($periodsForYear as $p): ?>
                                <option value="<?= $p['id'] ?>"
                                    <?= $filterPeriodId === (int)$p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Afficher
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── WARNING / INFO ───────────────────────────────────────────────── -->
    <?php if ($warningMsg): ?>
        <div class="banner banner-warn"><i class="fas fa-exclamation-triangle"></i> <?= $warningMsg ?></div>
    <?php elseif ($filterYear && $filterClassId && !empty($periodIds) && !$warningMsg): ?>
        <div class="banner banner-info">
            <i class="fas fa-info-circle"></i>
            Année <strong><?= htmlspecialchars($filterYear) ?></strong> —
            <?php if ($filterMode === 'semestre'): ?>
                Mode <strong>semestre</strong> — Période : <strong><?= htmlspecialchars($filterPeriodLabel) ?></strong> —
            <?php else: ?>
                Mode <strong>année complète</strong> —
            <?php endif; ?>
            <?= count($eligible) + count($ineligible) ?> étudiant(s) dans la classe
        </div>
    <?php endif; ?>

    <!-- ── RÉSULTATS ────────────────────────────────────────────────────── -->
    <?php if ($filterYear && $filterClassId && !$warningMsg && !empty($periodIds)): ?>

        <div class="summary-bar">
            <div class="summary-chip chip-green">
                <i class="fas fa-check-circle"></i>
                <?= count($eligible) ?> admis
            </div>
            <div class="summary-chip chip-red">
                <i class="fas fa-times-circle"></i>
                <?= count($ineligible) ?> ajourné(s)
            </div>
            <div class="summary-chip chip-blue">
                <i class="fas fa-users"></i>
                <?= count($eligible) + count($ineligible) ?> au total
            </div>
        </div>

        <!-- Étudiants admis -->
        <div class="card">
            <div class="card-head">
                <div class="card-icon ci-green"><i class="fas fa-graduation-cap"></i></div>
                <div>
                    <h2>Étudiants admis — attestation éligible</h2>
                    <p>
                    <?php if ($filterMode === 'semestre'): ?>
                        Ces étudiants ont validé <strong><?= htmlspecialchars($filterPeriodLabel) ?></strong>
                    <?php else: ?>
                        Ces étudiants ont validé toutes leurs UE sur les deux semestres
                    <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($eligible)): ?>
                    <div class="banner banner-warn">
                        <i class="fas fa-info-circle"></i>
                        Aucun étudiant n'est éligible pour ce filtre.
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th>Matricule</th>
                                    <th>Moyenne</th>
                                    <th>Mention</th>
                                    <th>Statut attestation</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($eligible as $row):
                                $s       = $row['student'];
                                $mention = $row['mention'];
                                $avg     = $row['average'];
                                $attest  = $row['attestation'];
                                $badgeCls = match($mention) {
                                    'PASSABLE'   => 'b-pass',
                                    'ASSEZ BIEN' => 'b-ab',
                                    'BIEN'       => 'b-bien',
                                    'TRÈS BIEN'  => 'b-tb',
                                    default      => 'b-insuf',
                                };
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                                    <td style="color:var(--muted); font-size:8.5pt;">
                                        <?= htmlspecialchars($s['id']) ?>
                                    </td>
                                    <td>
                                        <strong style="color:var(--green);"><?= number_format($avg, 2, ',', '') ?>/20</strong>
                                    </td>
                                    <td>
                                        <span class="badge <?= $badgeCls ?>"><?= htmlspecialchars($mention) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($attest): ?>
                                            <span style="color:var(--green); font-size:8.5pt;">
                                                <i class="fas fa-check"></i>
                                                N° <?= htmlspecialchars($attest['numero_enregistrement']) ?>
                                                — <?= date('d/m/Y', strtotime($attest['created_at'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color:var(--muted); font-size:8.5pt;">
                                                <i class="fas fa-clock"></i> Non générée
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($attest): ?>
                                            <a href="generate_attestation.php?attestation_id=<?= $attest['id'] ?>"
                                               class="btn btn-ghost" style="font-size:8.5pt; padding:6px 14px;"
                                               target="_blank">
                                                <i class="fas fa-download"></i> Retélécharger
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-orange"
                                                style="font-size:8.5pt; padding:6px 14px;"
                                                onclick="openModal(
                                                    '<?= htmlspecialchars($s['id'], ENT_QUOTES) ?>',
                                                    '<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>',
                                                    '<?= htmlspecialchars($row['filiere'], ENT_QUOTES) ?>',
                                                    '<?= htmlspecialchars($filterYear, ENT_QUOTES) ?>',
                                                    '<?= htmlspecialchars($filterMode, ENT_QUOTES) ?>',
                                                    <?= $filterMode === 'semestre' ? $filterPeriodId : 0 ?>,
                                                    '<?= htmlspecialchars($filterPeriodLabel, ENT_QUOTES) ?>'
                                                )">
                                                <i class="fas fa-certificate"></i> Générer
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Étudiants ajournés -->
        <?php if (!empty($ineligible)): ?>
        <div class="card">
            <div class="card-head">
                <div class="card-icon ci-red"><i class="fas fa-times-circle"></i></div>
                <div>
                    <h2>Étudiants ajournés — non éligibles</h2>
                    <p>Ces étudiants n'ont pas validé toutes leurs UE</p>
                </div>
            </div>
            <div class="card-body">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Étudiant</th>
                                <th>Matricule</th>
                                <th>Moyenne</th>
                                <th>Mention</th>
                                <th>Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ineligible as $row):
                            $s   = $row['student'];
                            $avg = $row['average'];
                        ?>
                            <tr style="opacity:.65;">
                                <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                                <td style="color:var(--muted); font-size:8.5pt;">
                                    <?= htmlspecialchars($s['id']) ?>
                                </td>
                                <td>
                                    <span style="color:var(--red);"><?= number_format($avg, 2, ',', '') ?>/20</span>
                                </td>
                                <td>
                                    <span class="badge b-insuf"><?= htmlspecialchars($row['mention']) ?></span>
                                </td>
                                <td style="color:var(--red); font-size:8.5pt;">
                                    <i class="fas fa-ban"></i> Non éligible
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    <!-- ── HISTORIQUE ──────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-head">
            <div class="card-icon ci-orange"><i class="fas fa-history"></i></div>
            <div>
                <h2>Historique des attestations générées</h2>
                <p>50 dernières attestations — toutes classes confondues</p>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($history)): ?>
                <div class="banner banner-info">
                    <i class="fas fa-info-circle"></i>
                    Aucune attestation n'a encore été générée.
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>N° Enregistrement</th>
                                <th>Étudiant</th>
                                <th>Type</th>
                                <th>Année</th>
                                <th>Filière</th>
                                <th>Mention</th>
                                <th>Date émission</th>
                                <th>Généré le</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($history as $h):
                            $badgeCls = match(strtoupper($h['mention'])) {
                                'PASSABLE'   => 'b-pass',
                                'ASSEZ BIEN' => 'b-ab',
                                'BIEN'       => 'b-bien',
                                'TRÈS BIEN'  => 'b-tb',
                                default      => 'b-insuf',
                            };
                            $isAnnee = ($h['mode'] ?? 'annee_complete') === 'annee_complete';
                        ?>
                            <tr>
                                <td>
                                    <code style="background:rgba(255,255,255,.08); padding:2px 7px; border-radius:4px; font-size:9pt;">
                                        <?= htmlspecialchars($h['numero_enregistrement']) ?>
                                    </code>
                                </td>
                                <td><strong><?= htmlspecialchars($h['student_name']) ?></strong></td>
                                <td>
                                    <?php if ($isAnnee): ?>
                                        <span class="badge b-annee">
                                            <i class="fas fa-calendar-alt" style="font-size:7pt;"></i>
                                            Année
                                        </span>
                                    <?php else: ?>
                                        <span class="badge b-sem" title="<?= htmlspecialchars($h['period_name'] ?? '') ?>">
                                            <i class="fas fa-calendar-week" style="font-size:7pt;"></i>
                                            <?= htmlspecialchars($h['period_name'] ?? 'Semestre') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($h['annee_academique']) ?></td>
                                <td style="font-size:8.5pt; color:var(--muted);">
                                    <?= htmlspecialchars($h['filiere']) ?>
                                </td>
                                <td><span class="badge <?= $badgeCls ?>"><?= htmlspecialchars($h['mention']) ?></span></td>
                                <td style="font-size:8.5pt;">
                                    <?= date('d/m/Y', strtotime($h['date_emission'])) ?>
                                </td>
                                <td style="font-size:8pt; color:var(--muted);">
                                    <?= date('d/m/Y H:i', strtotime($h['created_at'])) ?>
                                </td>
                                <td>
                                    <a href="generate_attestation.php?attestation_id=<?= $h['id'] ?>"
                                       class="btn btn-ghost" style="font-size:8pt; padding:5px 12px;"
                                       target="_blank">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /main -->

<!-- ── MODAL GÉNÉRATION ──────────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <h3><i class="fas fa-certificate" style="color:var(--orange);"></i>&nbsp; Générer l'attestation</h3>
        <div class="modal-sub" id="modal-sub">Vérifiez et complétez les informations avant de générer le PDF.</div>

        <div id="modal-mode-badge" class="modal-mode-badge b-annee" style="display:none;"></div>

        <form method="POST" action="generate_attestation.php" id="generateForm">
            <input type="hidden" name="csrf_token"       value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <input type="hidden" name="student_id"       id="m_student_id">
            <input type="hidden" name="annee_academique" id="m_annee">
            <input type="hidden" name="mode"             id="m_mode">
            <input type="hidden" name="period_id"        id="m_period_id">

            <div class="modal-grid">
                <div class="full">
                    <label>Filière <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="filiere" id="m_filiere" required placeholder="Sciences Infirmières">
                </div>
                <div>
                    <label>Type de diplôme</label>
                    <input type="text" name="type_diplome" id="m_type_diplome" value="LICENCE PROFESSIONNELLE">
                </div>
                <div>
                    <label>Promotion</label>
                    <input type="text" name="promotion" id="m_promotion" placeholder="2024-2025">
                </div>
                <div>
                    <label>Date d'émission</label>
                    <input type="text" name="date_emission" id="m_date" value="<?= date('d/m/Y') ?>">
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal()">Annuler</button>
                <button type="submit" class="btn btn-orange" onclick="return submitAttestation()">
                    <i class="fas fa-file-pdf"></i> Générer le PDF
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ── LOADER ── -->
<div class="loading-overlay" id="loader">
    <div class="spinner"></div>
    <div class="loading-text">Génération du PDF en cours…</div>
</div>

<script>
// Périodes disponibles pour l'année en cours (injectées par PHP)
const PERIODS_FOR_YEAR = <?= $periodsJson ?>;

// ── Mode toggle ────────────────────────────────────────────────────────────
function setMode(mode) {
    document.querySelector('input[name=mode][value=' + mode + ']').checked = true;

    ['annee_complete', 'semestre'].forEach(m => {
        document.getElementById('btn-' + (m === 'annee_complete' ? 'annee' : 'sem'))
            .classList.toggle('active', m === mode);
    });

    const isSem = mode === 'semestre';
    document.getElementById('periodGroup').style.display = isSem ? '' : 'none';
    document.getElementById('filterGrid').className = isSem ? 'filter-grid-sem' : 'filter-grid';
}

// ── Refresh du select période quand l'année change ─────────────────────────
function refreshPeriods() {
    const year = document.getElementById('selectYear').value;
    const sel  = document.getElementById('selectPeriod');
    sel.innerHTML = '<option value="">— Choisir —</option>';

    if (!year) return;

    // Appel AJAX léger pour récupérer les périodes de l'année choisie
    fetch('?annee_academique=' + encodeURIComponent(year) + '&_periods_only=1')
        .then(r => r.json())
        .then(periods => {
            periods.forEach(p => {
                const o = document.createElement('option');
                o.value = p.id;
                o.textContent = p.name;
                sel.appendChild(o);
            });
        })
        .catch(() => {
            // En cas d'échec, on laisse vide — le PHP rechargera via submit
        });
}

</script>

<script>
// ── Modal ──────────────────────────────────────────────────────────────────
function openModal(studentId, studentName, filiere, annee, mode, periodId, periodLabel) {
    // ── DEBUG LOG ──────────────────────────────────────────────────────────
    console.log('[openModal]', { studentId, studentName, filiere, annee, mode, periodId, periodLabel });
    // ──────────────────────────────────────────────────────────────────────

    document.getElementById('m_student_id').value = studentId;
    document.getElementById('m_annee').value      = annee;
    document.getElementById('m_filiere').value    = filiere;
    document.getElementById('m_promotion').value  = annee;
    document.getElementById('m_mode').value       = mode;
    document.getElementById('m_period_id').value  = periodId || 0;

    // Sous-titre modal
    let sub = 'Étudiant : ' + studentName + ' — Année : ' + annee;
    if (mode === 'semestre' && periodLabel) sub += ' — ' + periodLabel;
    document.getElementById('modal-sub').textContent = sub;

    // Badge de mode
    const badge = document.getElementById('modal-mode-badge');
    if (mode === 'semestre') {
        badge.className = 'modal-mode-badge b-sem';
        badge.innerHTML = '<i class="fas fa-calendar-week"></i> Semestre — ' + (periodLabel || '');
        badge.style.display = 'inline-flex';
    } else {
        badge.className = 'modal-mode-badge b-annee';
        badge.innerHTML = '<i class="fas fa-calendar-alt"></i> Année complète';
        badge.style.display = 'inline-flex';
    }

    document.getElementById('modalOverlay').classList.add('open');
}

function closeModal() {
    document.getElementById('modalOverlay').classList.remove('open');
}

function submitAttestation() {
    const fields = {
        student_id:       document.getElementById('m_student_id').value,
        annee_academique: document.getElementById('m_annee').value,
        filiere:          document.getElementById('m_filiere').value,
        mode:             document.getElementById('m_mode').value,
        period_id:        document.getElementById('m_period_id').value,
    };
    console.log('[submitAttestation]', fields);

    if (!fields.student_id || !fields.annee_academique || !fields.filiere) {
        alert('❌ Champ manquant :\n' + JSON.stringify(fields, null, 2));
        return false;
    }

    // Fermer le modal, montrer le loader, laisser le navigateur télécharger le PDF
    // (réponse Content-Disposition: attachment — pas de navigation, pas de popup)
    closeModal();
    showLoader();
    return true;
}

function showLoader() {
    setTimeout(() => document.getElementById('loader').classList.add('show'), 100);
    setTimeout(() => document.getElementById('loader').classList.remove('show'), 8000);
}

document.getElementById('modalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

window.addEventListener('pageshow', () => {
    document.getElementById('loader').classList.remove('show');
});
</script>
</body>
</html>
