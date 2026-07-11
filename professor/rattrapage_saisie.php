<?php
require_once '../includes/db_connect.php';

// Contrôle du rôle
if (!in_array($_SESSION['role'], ['teacher', 'admin'])) {
    header("Location: ../pages/login.html");
    exit();
}

$teacher_id = $_SESSION['user_id'];

// Vérification permission rattrapage (admin toujours autorisé)
$can_saisie = ($_SESSION['role'] === 'admin');
if (!$can_saisie) {
    $permCheck = $conn->prepare("SELECT id FROM exam_permissions WHERE user_id = ? AND is_active = 1 AND allow_rattrapage = 1 AND (expires_at IS NULL OR expires_at > NOW())");
    $permCheck->bind_param("s", $teacher_id);
    $permCheck->execute();
    $can_saisie = $permCheck->get_result()->num_rows > 0;
}

// Connexion PDO en réutilisant les credentials de db_connect.php
try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
} catch (PDOException $e) {
    die("Erreur PDO : " . $e->getMessage());
}

// ── Traitement du formulaire de saisie ──────────────────────────────────────
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_grade') {
    if (!$can_saisie) {
        $error_msg = "Vous n'avez pas l'autorisation de saisir des notes de rattrapage.";
    } else {
        $ratt_id   = (int)($_POST['ratt_id']   ?? 0);
        $grade_val = trim($_POST['grade']       ?? '');
        $comment   = trim($_POST['comment']     ?? '');

        if ($ratt_id <= 0 || $grade_val === '' || !is_numeric($grade_val)) {
            $error_msg = "Identifiant ou note invalide.";
        } else {
            $grade_val = (float)$grade_val;
            if ($grade_val < 0 || $grade_val > 20) {
                $error_msg = "La note doit être comprise entre 0 et 20.";
            } else {
                // Vérifier que ce rattrapage appartient bien à un cours de cet enseignant
                $checkStmt = $pdo->prepare("
                    SELECT r.id FROM rattrapages r
                    JOIN courses c ON r.course_id = c.id
                    WHERE r.id = :rid AND c.teacher_id = :tid AND r.status = 'pending'
                ");
                $checkStmt->execute([':rid' => $ratt_id, ':tid' => $teacher_id]);

                if (!$checkStmt->fetch()) {
                    $error_msg = "Rattrapage introuvable ou déjà noté.";
                } else {
                    $updateStmt = $pdo->prepare("
                        UPDATE rattrapages
                        SET grade = :grade, comment = :comment, status = 'graded',
                            graded_at = NOW(), graded_by = :graded_by
                        WHERE id = :rid
                    ");
                    $updateStmt->execute([
                        ':grade'     => $grade_val,
                        ':comment'   => $comment ?: null,
                        ':graded_by' => $teacher_id,
                        ':rid'       => $ratt_id,
                    ]);
                    $success_msg = "Note de rattrapage enregistrée avec succès.";
                }
            }
        }
    }
}

// ── Classes et périodes de cet enseignant ───────────────────────────────────
$classesStmt = $pdo->prepare("
    SELECT DISTINCT cl.id, cl.name
    FROM classes cl
    INNER JOIN courses co ON JSON_CONTAINS(co.class_id, JSON_QUOTE(CAST(cl.id AS CHAR)), '$')
    WHERE co.teacher_id = :tid
    ORDER BY cl.name
");
$classesStmt->execute([':tid' => $teacher_id]);
$classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

$periods = $pdo->query("SELECT id, name FROM evaluation_periods ORDER BY name DESC")->fetchAll(PDO::FETCH_ASSOC);

$class_id  = isset($_GET['class_id'])  ? (int)$_GET['class_id']  : 0;
$period_id = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;

$rattrapages_data = [];
$class_name  = '';
$period_name = '';

if ($class_id && $period_id) {
    foreach ($classes as $c) { if ($c['id'] == $class_id)  $class_name  = $c['name']; }
    foreach ($periods as $p) { if ($p['id'] == $period_id) $period_name = $p['name']; }

    // Rattrapages en attente pour les cours de cet enseignant dans cette classe/période
    $rattStmt = $pdo->prepare("
        SELECT r.*,
               u.name   AS student_name,
               co.name  AS course_name,
               tu.name  AS ue_name,
               tu.code  AS ue_code
        FROM rattrapages r
        JOIN users u    ON r.student_id = u.id
        JOIN courses co ON r.course_id  = co.id
        LEFT JOIN teaching_units tu ON co.teaching_unit_id = tu.id
        WHERE co.teacher_id           = :tid
          AND u.class_id              = :cid
          AND r.evaluation_period_id  = :pid
        ORDER BY CASE r.status WHEN 'pending' THEN 0 ELSE 1 END, u.name, co.name
    ");
    $rattStmt->execute([':tid' => $teacher_id, ':cid' => $class_id, ':pid' => $period_id]);
    $rattrapages_data = $rattStmt->fetchAll(PDO::FETCH_ASSOC);
}

$reason_labels = [
    'average_low'      => 'Moyenne < 10',
    'ue_not_validated' => 'UE non validée',
    'both'             => 'Moy. < 10 + UE non validée',
];

$cnt_pending = 0;
$cnt_graded  = 0;
foreach ($rattrapages_data as $r) {
    $r['status'] === 'graded' ? $cnt_graded++ : $cnt_pending++;
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
    <title>Saisie Rattrapage — Enseignant</title>
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
        select, input[type=text], input[type=number], textarea {
            background:#0d3152; color:var(--text-light); border:1px solid var(--border-color);
            border-radius:6px; padding:9px 12px; font-size:14px; width:100%;
        }
        select:focus, input:focus, textarea:focus { outline:none; border-color:var(--accent-color); }
        textarea { resize:vertical; min-height:55px; font-family:inherit; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:9px 18px; border-radius:6px; border:none; cursor:pointer; font-size:13px; font-weight:600; transition:all .3s; text-decoration:none; white-space:nowrap; }
        .btn-primary { background:var(--accent-color); color:#fff; }
        .btn-primary:hover { background:#0288c7; }
        .btn-save { background:#1a5276; color:#fff; border:1px solid var(--accent-color); }
        .btn-save:hover { background:var(--accent-color); }

        /* Alertes */
        .alert { padding:14px 18px; border-radius:8px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:rgba(46,204,113,.12); border:1px solid rgba(46,204,113,.35); color:var(--success-color); }
        .alert-error   { background:rgba(231,76,60,.12);  border:1px solid rgba(231,76,60,.35);  color:var(--danger-color); }

        /* Bannière de priorité */
        .priority-banner { display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px; }
        .priority-pill { display:flex; align-items:center; gap:10px; padding:12px 20px; border-radius:10px; font-size:14px; font-weight:600; flex:1; min-width:160px; }
        .priority-pill.pill-pending { background:rgba(243,156,18,.15); border:1px solid rgba(243,156,18,.35); color:var(--warning-color); }
        .priority-pill.pill-done    { background:rgba(46,204,113,.12); border:1px solid rgba(46,204,113,.3);  color:var(--success-color); }
        .priority-pill .pill-count  { font-size:26px; font-weight:800; }

        /* Onglets de filtre */
        .tabs-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:18px; }
        .tab-btn { padding:7px 16px; border-radius:20px; border:1px solid var(--border-color); background:transparent; color:rgba(255,255,255,.6); cursor:pointer; font-size:13px; font-weight:600; transition:all .2s; }
        .tab-btn:hover { border-color:var(--accent-color); color:var(--text-light); }
        .tab-btn.active { background:var(--accent-color); border-color:var(--accent-color); color:#fff; }
        .tab-btn .tab-count { background:rgba(255,255,255,.2); border-radius:10px; padding:1px 7px; margin-left:5px; font-size:11px; }
        .tab-btn.active .tab-count { background:rgba(255,255,255,.3); }

        /* Tableau */
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        thead th { background:rgba(3,155,229,.3); padding:11px 12px; text-align:left; white-space:nowrap; }
        tbody tr { transition:background .15s; }
        tbody tr:nth-child(even) { background:rgba(255,255,255,.03); }
        tbody tr:hover { background:rgba(3,155,229,.08); }
        tbody tr[data-hidden="1"] { display:none; }
        td { padding:9px 12px; border-bottom:1px solid var(--border-color); vertical-align:middle; }

        /* Séparateur "Déjà notés" */
        .separator-row td { background:rgba(255,255,255,.04); padding:7px 12px; font-size:12px; color:rgba(255,255,255,.4); text-align:center; border-top:2px solid var(--border-color); letter-spacing:.08em; text-transform:uppercase; }

        /* Badges */
        .badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:600; }
        .badge-pending { background:rgba(243,156,18,.2); color:var(--warning-color); }
        .badge-graded  { background:rgba(46,204,113,.2); color:var(--success-color); }
        .badge-reason  { background:rgba(3,155,229,.15); color:var(--accent-color); }
        .avg-low { color:var(--danger-color); font-weight:700; }
        .avg-ok  { color:var(--success-color); font-weight:700; }

        /* Formulaire de saisie inline */
        .grade-form { display:flex; flex-direction:column; gap:7px; min-width:220px; }
        .grade-form-row { display:flex; gap:7px; align-items:center; }
        .grade-form input[type=number] { width:90px; flex-shrink:0; }
        .grade-form textarea { font-size:12px; min-height:48px; }
        .graded-display { font-weight:700; color:var(--success-color); font-size:15px; }
        .graded-meta { font-size:11px; color:rgba(255,255,255,.4); margin-top:3px; }

        /* Barre de recherche */
        .search-row { margin-bottom:12px; position:relative; }
        .search-row input { padding-left:36px; }
        .search-row .search-icon { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:rgba(255,255,255,.4); pointer-events:none; }

        /* Divers */
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
            <li><a href="quiz_manage.php"><i class="fas fa-question-circle"></i> Mes Quiz</a></li>
            <li><a href="rattrapage_saisie.php" class="active"><i class="fas fa-redo"></i> Rattrapage</a></li>
            <li><a href="teacher_profile.php"><i class="fas fa-user"></i> Profil</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul></nav>
    </div>
</header>

<main>
    <h2><i class="fas fa-redo"></i> Saisie des notes de rattrapage</h2>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>
    <?php if (!$can_saisie): ?>
        <div class="alert alert-error">
            <i class="fas fa-lock"></i>
            Vous n'êtes pas autorisé à saisir des notes de rattrapage. Contactez l'administration.
        </div>
    <?php endif; ?>

    <!-- Filtres principaux -->
    <div class="filter-card">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-users"></i> Classe</label>
                    <select name="class_id" required>
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $c['id'] == $class_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar-alt"></i> Période</label>
                    <select name="period_id" required>
                        <option value="">-- Sélectionner --</option>
                        <?php foreach ($periods as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $p['id'] == $period_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="flex:0">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Afficher</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($class_id && $period_id): ?>

        <?php if (!empty($rattrapages_data)): ?>

            <!-- Bannière de priorité -->
            <div class="priority-banner">
                <div class="priority-pill pill-pending">
                    <i class="fas fa-hourglass-half fa-lg"></i>
                    <div>
                        <div class="pill-count"><?= $cnt_pending ?></div>
                        <div style="font-size:12px">à noter</div>
                    </div>
                    <?php if ($cnt_pending === 0): ?>
                        <span style="margin-left:auto;font-size:12px;color:var(--success-color)"><i class="fas fa-check"></i> Tout est noté !</span>
                    <?php endif; ?>
                </div>
                <div class="priority-pill pill-done">
                    <i class="fas fa-check-circle fa-lg"></i>
                    <div>
                        <div class="pill-count"><?= $cnt_graded ?></div>
                        <div style="font-size:12px">déjà notés</div>
                    </div>
                </div>
            </div>

            <!-- Onglets + recherche -->
            <div class="tabs-row">
                <button class="tab-btn active" onclick="setTab('all',this)">
                    Tous <span class="tab-count"><?= count($rattrapages_data) ?></span>
                </button>
                <button class="tab-btn" onclick="setTab('pending',this)">
                    <i class="fas fa-hourglass-half"></i> À noter
                    <span class="tab-count"><?= $cnt_pending ?></span>
                </button>
                <button class="tab-btn" onclick="setTab('graded',this)">
                    <i class="fas fa-check"></i> Notés
                    <span class="tab-count"><?= $cnt_graded ?></span>
                </button>
            </div>

            <div class="search-row">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchInput" placeholder="Rechercher un étudiant ou une matière…" oninput="applyFilters()">
            </div>

            <div class="table-wrapper">
                <table id="rattTable">
                    <thead>
                        <tr>
                            <th><i class="fas fa-user-graduate"></i> Étudiant</th>
                            <th>UE</th>
                            <th><i class="fas fa-book"></i> Matière</th>
                            <th>Moy. originale</th>
                            <th>Raison</th>
                            <th>Statut</th>
                            <th style="min-width:240px">Note de rattrapage</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $separatorInserted = false;
                    foreach ($rattrapages_data as $r):
                        if (!$separatorInserted && $r['status'] === 'graded' && $cnt_graded > 0 && $cnt_pending > 0):
                            $separatorInserted = true;
                    ?>
                        <tr class="separator-row" data-separator="1">
                            <td colspan="7"><i class="fas fa-check-double"></i> &nbsp;Déjà notés</td>
                        </tr>
                    <?php endif; ?>
                        <tr data-student="<?= htmlspecialchars(strtolower($r['student_name'])) ?>"
                            data-course="<?= htmlspecialchars(strtolower($r['course_name'])) ?>"
                            data-status="<?= $r['status'] ?>"
                            <?= $r['status'] === 'pending' ? 'style="background:rgba(243,156,18,.04)"' : '' ?>>
                            <td><?= htmlspecialchars($r['student_name']) ?></td>
                            <td>
                                <?php if ($r['ue_code']): ?>
                                    <span style="font-size:12px;color:var(--accent-color)"><?= htmlspecialchars($r['ue_code']) ?></span>
                                <?php else: ?>
                                    <span style="color:rgba(255,255,255,.3)">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($r['course_name']) ?></td>
                            <td class="<?= $r['original_average'] < 10 ? 'avg-low' : 'avg-ok' ?>">
                                <?= $r['original_average'] !== null ? number_format($r['original_average'], 2) . ' / 20' : '—' ?>
                            </td>
                            <td>
                                <span class="badge badge-reason">
                                    <?= $reason_labels[$r['eligibility_reason']] ?? $r['eligibility_reason'] ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?= $r['status'] ?>">
                                    <?= $r['status'] === 'graded' ? '<i class="fas fa-check"></i> Noté' : '<i class="fas fa-hourglass-half"></i> En attente' ?>
                                </span>
                            </td>
                            <td>
                            <?php if ($r['status'] === 'pending'): ?>
                                <?php if ($can_saisie): ?>
                                <form method="POST" action="?class_id=<?= $class_id ?>&period_id=<?= $period_id ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                    <input type="hidden" name="action"   value="save_grade">
                                    <input type="hidden" name="ratt_id" value="<?= $r['id'] ?>">
                                    <div class="grade-form">
                                        <div class="grade-form-row">
                                            <input type="number" name="grade" step="0.01" min="0" max="20"
                                                   placeholder="Note /20" required>
                                            <button type="submit" class="btn btn-save">
                                                <i class="fas fa-save"></i> Enregistrer
                                            </button>
                                        </div>
                                        <textarea name="comment" placeholder="Commentaire (optionnel)…"></textarea>
                                    </div>
                                </form>
                                <?php else: ?>
                                <span style="color:rgba(255,255,255,.35);font-size:13px"><i class="fas fa-lock"></i> Non autorisé</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="graded-display">
                                    <i class="fas fa-check-circle"></i> <?= number_format($r['grade'], 2) ?> / 20
                                </div>
                                <?php if ($r['comment']): ?>
                                    <div class="graded-meta"><i class="fas fa-comment-alt"></i> <?= htmlspecialchars($r['comment']) ?></div>
                                <?php endif; ?>
                                <div class="graded-meta">
                                    <?= $r['graded_at'] ? date('d/m/Y \à H:i', strtotime($r['graded_at'])) : '' ?>
                                </div>
                            <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <div class="no-data">
                <i class="fas fa-check-circle" style="color:var(--success-color)"></i>
                <p>Aucun étudiant éligible au rattrapage pour vos cours dans cette classe et cette période.</p>
                <p style="margin-top:8px;font-size:13px;color:rgba(255,255,255,.35)">
                    L'éligibilité est calculée par l'administration depuis la page <em>Gestion des rattrapages</em>.
                </p>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-filter"></i>
            <p>Sélectionnez une classe et une période pour afficher les rattrapages à noter.</p>
        </div>
    <?php endif; ?>
</main>

<script>
let currentTab = 'all';

function setTab(tab, btn) {
    currentTab = tab;
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilters();
}

function applyFilters() {
    const search = document.getElementById('searchInput') ? document.getElementById('searchInput').value.toLowerCase().trim() : '';
    const rows = document.querySelectorAll('#rattTable tbody tr:not([data-separator])');

    rows.forEach(tr => {
        const student = tr.dataset.student || '';
        const course  = tr.dataset.course  || '';
        const status  = tr.dataset.status  || '';

        const matchSearch = !search || student.includes(search) || course.includes(search);
        const matchTab    = currentTab === 'all' || status === currentTab;

        if (matchSearch && matchTab) {
            tr.style.display = '';
        } else {
            tr.style.display = 'none';
        }
    });

    // Masquer le séparateur si on filtre sur un seul onglet
    const sep = document.querySelector('#rattTable tbody tr[data-separator]');
    if (sep) {
        sep.style.display = (currentTab === 'all' && !search) ? '' : 'none';
    }
}
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>
