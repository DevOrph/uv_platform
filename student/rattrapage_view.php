<?php
require_once '../includes/db_connect.php';

// db_connect.php a déjà vérifié user_id et démarré la session
$student_id = $_SESSION['user_id'];

// Connexion PDO via le helper central (config .env)
try {
    $pdo = get_pdo_connection();
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
} catch (PDOException $e) {
    die("Erreur PDO : " . $e->getMessage());
}

// Filtre optionnel par période
$period_filter = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;

// Périodes disponibles pour cet étudiant
$periodsStmt = $pdo->prepare("
    SELECT DISTINCT ep.id, ep.name
    FROM rattrapages r
    JOIN evaluation_periods ep ON r.evaluation_period_id = ep.id
    WHERE r.student_id = :sid
    ORDER BY ep.name DESC
");
$periodsStmt->execute([':sid' => $student_id]);
$periods = $periodsStmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des rattrapages de l'étudiant
$params = [':sid' => $student_id];
$where  = 'WHERE r.student_id = :sid';
if ($period_filter) {
    $where  .= ' AND r.evaluation_period_id = :pid';
    $params[':pid'] = $period_filter;
}

$rattStmt = $pdo->prepare("
    SELECT r.*,
           co.name  AS course_name,
           ep.name  AS period_name,
           tu.name  AS ue_name,
           tu.code  AS ue_code,
           gb.name  AS graded_by_name
    FROM rattrapages r
    JOIN courses co          ON r.course_id             = co.id
    JOIN evaluation_periods ep ON r.evaluation_period_id = ep.id
    LEFT JOIN teaching_units tu ON co.teaching_unit_id  = tu.id
    LEFT JOIN users gb       ON r.graded_by             = gb.id
    $where
    ORDER BY ep.name DESC, tu.code, co.name
");
$rattStmt->execute($params);
$rattrapages = $rattStmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques rapides
$total   = count($rattrapages);
$pending = 0;
$graded  = 0;
$passed  = 0;
foreach ($rattrapages as $r) {
    if ($r['status'] === 'graded') {
        $graded++;
        if ($r['grade'] >= 10) $passed++;
    } else {
        $pending++;
    }
}

$reason_labels = [
    'average_low'      => ['label' => 'Moyenne < 10',              'icon' => 'fa-chart-line',          'color' => '#e74c3c'],
    'ue_not_validated' => ['label' => 'UE non validée',            'icon' => 'fa-layer-group',         'color' => '#f39c12'],
    'both'             => ['label' => 'Moy. < 10 & UE non validée','icon' => 'fa-exclamation-triangle','color' => '#e74c3c'],
];

// Déterminer le message héro
if ($total === 0) {
    $hero_icon  = 'fa-check-circle';
    $hero_color = '#2ecc71';
    $hero_msg   = 'Aucun rattrapage enregistré';
    $hero_sub   = 'Vous n\'avez pas de rattrapage à passer cette période.';
} elseif ($pending === 0) {
    $hero_icon  = 'fa-check-double';
    $hero_color = '#2ecc71';
    $hero_msg   = 'Tous vos rattrapages ont été notés';
    $hero_sub   = $passed . ' réussi' . ($passed > 1 ? 's' : '') . ' sur ' . $graded . ' noté' . ($graded > 1 ? 's' : '') . '.';
} elseif ($pending === $total) {
    $hero_icon  = 'fa-hourglass-half';
    $hero_color = '#f39c12';
    $hero_msg   = $pending . ' rattrapage' . ($pending > 1 ? 's' : '') . ' en attente de notation';
    $hero_sub   = 'Votre enseignant n\'a pas encore saisi les notes. Revenez plus tard.';
} else {
    $hero_icon  = 'fa-clipboard-list';
    $hero_color = '#039be5';
    $hero_msg   = $pending . ' rattrapage' . ($pending > 1 ? 's' : '') . ' en attente · ' . $passed . ' réussi' . ($passed > 1 ? 's' : '');
    $hero_sub   = $graded . ' noté' . ($graded > 1 ? 's' : '') . ' sur ' . $total . ' au total.';
}
$pct_graded = $total > 0 ? round($graded / $total * 100) : 0;
$pct_passed = $total > 0 ? round($passed / $total * 100) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Rattrapages — Étudiant</title>
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
        main { flex:1; max-width:900px; margin:0 auto; padding:30px 20px; width:100%; }
        h2 { font-size:20px; color:var(--accent-color); margin-bottom:20px; display:flex; align-items:center; gap:10px; }

        /* Hero */
        .hero-card { background:var(--secondary-bg); border-radius:14px; padding:24px 28px; margin-bottom:24px; border:1px solid var(--border-color); display:flex; align-items:center; gap:22px; flex-wrap:wrap; }
        .hero-icon { font-size:44px; flex-shrink:0; }
        .hero-text { flex:1; min-width:200px; }
        .hero-title { font-size:20px; font-weight:800; margin-bottom:4px; }
        .hero-sub { font-size:13px; color:rgba(255,255,255,.6); margin-bottom:14px; }
        .progress-outer { background:rgba(255,255,255,.08); border-radius:20px; height:10px; overflow:hidden; margin-bottom:6px; }
        .progress-inner { height:100%; border-radius:20px; transition:width .7s ease; }
        .progress-labels { display:flex; justify-content:space-between; font-size:11px; color:rgba(255,255,255,.4); }
        .hero-pills { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
        .hero-pill { display:inline-flex; align-items:center; gap:6px; padding:5px 14px; border-radius:20px; font-size:12px; font-weight:700; }
        .pill-wait    { background:rgba(243,156,18,.15); color:var(--warning-color); border:1px solid rgba(243,156,18,.3); }
        .pill-graded  { background:rgba(3,155,229,.12);  color:var(--accent-color);  border:1px solid rgba(3,155,229,.3); }
        .pill-pass    { background:rgba(46,204,113,.12); color:var(--success-color); border:1px solid rgba(46,204,113,.3); }
        .pill-fail    { background:rgba(231,76,60,.1);   color:var(--danger-color);  border:1px solid rgba(231,76,60,.3); }

        /* Filtre période */
        .filter-row { display:flex; gap:12px; align-items:center; margin-bottom:22px; flex-wrap:wrap; }
        .filter-row label { font-size:13px; color:rgba(255,255,255,.7); }
        select { background:#0d3152; color:var(--text-light); border:1px solid var(--border-color); border-radius:6px; padding:8px 12px; font-size:13px; }
        select:focus { outline:none; border-color:var(--accent-color); }
        .btn-outline { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:6px; border:1px solid var(--accent-color); background:transparent; color:var(--accent-color); cursor:pointer; font-size:13px; font-weight:600; text-decoration:none; transition:background .2s; }
        .btn-outline:hover { background:rgba(3,155,229,.15); }

        /* Groupe période */
        .period-group { margin-bottom:32px; }
        .period-title { font-size:15px; font-weight:700; color:var(--accent-color); padding:10px 16px; background:rgba(3,155,229,.1); border-left:3px solid var(--accent-color); border-radius:0 6px 6px 0; margin-bottom:14px; }

        /* Groupe UE dans la période */
        .ue-group { margin-bottom:16px; }
        .ue-group-label { font-size:12px; font-weight:600; color:rgba(255,255,255,.45); letter-spacing:.06em; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:7px; padding-left:2px; }
        .ue-group-label::after { content:''; flex:1; height:1px; background:rgba(255,255,255,.08); }

        /* Carte rattrapage */
        .ratt-card { background:var(--secondary-bg); border-radius:10px; border:1px solid var(--border-color); margin-bottom:10px; overflow:hidden; transition:transform .18s, box-shadow .18s; }
        .ratt-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.3); }
        .ratt-card.graded-pass { border-color:rgba(46,204,113,.45); }
        .ratt-card.graded-fail { border-color:rgba(231,76,60,.35); }
        .ratt-card.pending-card { border-color:rgba(243,156,18,.35); }

        .ratt-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; gap:10px; flex-wrap:wrap; }
        .ratt-title { font-size:15px; font-weight:700; }

        /* Corps: note à gauche (grand), infos à droite */
        .ratt-body { display:flex; align-items:stretch; border-top:1px solid var(--border-color); }
        .ratt-note-block { padding:16px 22px; min-width:140px; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; border-right:1px solid var(--border-color); flex-shrink:0; }
        .ratt-note-label { font-size:10px; text-transform:uppercase; letter-spacing:.07em; color:rgba(255,255,255,.4); margin-bottom:5px; }
        .ratt-note-value { font-size:32px; font-weight:800; line-height:1; }
        .ratt-note-value.low     { color:var(--danger-color); }
        .ratt-note-value.ok      { color:var(--success-color); }
        .ratt-note-value.waiting { color:var(--warning-color); font-size:22px; }
        .ratt-note-sub { font-size:11px; color:rgba(255,255,255,.35); margin-top:4px; }
        .ratt-details { padding:14px 18px; display:flex; flex-direction:column; gap:8px; flex:1; }
        .ratt-detail-row { display:flex; align-items:center; gap:8px; font-size:13px; }
        .ratt-detail-row .detail-label { color:rgba(255,255,255,.45); min-width:120px; font-size:12px; }
        .ratt-detail-row .detail-val { font-weight:600; }
        .ratt-pending-msg { background:rgba(243,156,18,.08); border:1px solid rgba(243,156,18,.25); border-radius:7px; padding:10px 14px; font-size:12px; color:rgba(243,156,18,.9); display:flex; align-items:center; gap:8px; margin-top:4px; }

        .ratt-comment { padding:0 18px 14px; font-size:13px; color:rgba(255,255,255,.55); font-style:italic; border-top:1px solid rgba(255,255,255,.05); padding-top:10px; margin-top:0; }

        /* Badges */
        .badge { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600; }
        .badge-pending { background:rgba(243,156,18,.2);  color:var(--warning-color); }
        .badge-graded  { background:rgba(46,204,113,.2);  color:var(--success-color); }
        .badge-fail    { background:rgba(231,76,60,.15);   color:var(--danger-color); }
        .badge-reason  { background:rgba(3,155,229,.15);   color:var(--accent-color); font-size:11px; }

        /* Divers */
        .no-data { text-align:center; padding:50px 20px; color:rgba(255,255,255,.4); }
        .no-data i { font-size:50px; display:block; margin-bottom:15px; }

        @media(max-width:500px) {
            .ratt-body { flex-direction:column; }
            .ratt-note-block { border-right:none; border-bottom:1px solid var(--border-color); flex-direction:row; gap:16px; align-items:center; justify-content:flex-start; }
        }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
        <nav><ul>
            <li><a href="student_dashboard.php"><i class="fas fa-home"></i> Accueil</a></li>
            <li><a href="student_grades.php"><i class="fas fa-chart-line"></i> Notes</a></li>
            <li><a href="rattrapage_view.php" class="active"><i class="fas fa-redo"></i> Rattrapage</a></li>
            <li><a href="student_profile.php"><i class="fas fa-user"></i> Profil</a></li>
            <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
        </ul></nav>
    </div>
</header>

<main>
    <h2><i class="fas fa-redo"></i> Mes Rattrapages</h2>

    <?php if (!empty($rattrapages)): ?>

        <!-- Carte héro -->
        <div class="hero-card">
            <i class="fas <?= $hero_icon ?> hero-icon" style="color:<?= $hero_color ?>"></i>
            <div class="hero-text">
                <div class="hero-title" style="color:<?= $hero_color ?>"><?= $hero_msg ?></div>
                <div class="hero-sub"><?= $hero_sub ?></div>
                <div class="progress-outer">
                    <div class="progress-inner" style="width:<?= $pct_graded ?>%;background:var(--accent-color)"></div>
                </div>
                <div class="progress-labels">
                    <span><?= $graded ?> / <?= $total ?> notés</span>
                    <span><?= $pct_graded ?>%</span>
                </div>
                <div class="hero-pills">
                    <?php if ($pending > 0): ?>
                        <span class="hero-pill pill-wait"><i class="fas fa-hourglass-half"></i> <?= $pending ?> en attente</span>
                    <?php endif; ?>
                    <?php if ($passed > 0): ?>
                        <span class="hero-pill pill-pass"><i class="fas fa-check-circle"></i> <?= $passed ?> réussi<?= $passed > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                    <?php if ($graded - $passed > 0): ?>
                        <span class="hero-pill pill-fail"><i class="fas fa-times-circle"></i> <?= $graded - $passed ?> échoué<?= ($graded - $passed) > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Filtre période -->
        <?php if (!empty($periods)): ?>
        <form method="GET" class="filter-row">
            <label><i class="fas fa-calendar-alt"></i> Période :</label>
            <select name="period_id" onchange="this.form.submit()">
                <option value="">Toutes les périodes</option>
                <?php foreach ($periods as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['id'] == $period_filter ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($period_filter): ?>
                <a href="rattrapage_view.php" class="btn-outline"><i class="fas fa-times"></i> Tout voir</a>
            <?php endif; ?>
        </form>
        <?php endif; ?>

        <!-- Regroupement par période puis par UE -->
        <?php
        $by_period = [];
        foreach ($rattrapages as $r) {
            $pname  = $r['period_name'];
            $ue_key = $r['ue_code'] ?: '__none__';
            $ue_lbl = $r['ue_code'] ? ($r['ue_code'] . ' — ' . $r['ue_name']) : null;
            if (!isset($by_period[$pname][$ue_key])) {
                $by_period[$pname][$ue_key] = ['label' => $ue_lbl, 'rows' => []];
            }
            $by_period[$pname][$ue_key]['rows'][] = $r;
        }
        foreach ($by_period as $period_name => $ue_groups):
        ?>
        <div class="period-group">
            <div class="period-title"><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($period_name) ?></div>

            <?php foreach ($ue_groups as $ue_key => $ue_group): ?>

                <?php if ($ue_group['label']): ?>
                <div class="ue-group">
                    <div class="ue-group-label">
                        <i class="fas fa-layer-group"></i>
                        <?= htmlspecialchars($ue_group['label']) ?>
                    </div>
                <?php else: ?>
                <div class="ue-group">
                <?php endif; ?>

                <?php foreach ($ue_group['rows'] as $r):
                    $isGraded  = $r['status'] === 'graded';
                    $isPassed  = $isGraded && $r['grade'] >= 10;
                    $cardClass = $isGraded ? ($isPassed ? 'graded-pass' : 'graded-fail') : 'pending-card';
                    $reasonInfo = $reason_labels[$r['eligibility_reason']] ?? ['label' => $r['eligibility_reason'], 'icon' => 'fa-question', 'color' => '#fff'];
                ?>
                <div class="ratt-card <?= $cardClass ?>">
                    <div class="ratt-header">
                        <div style="font-size:15px;font-weight:700"><?= htmlspecialchars($r['course_name']) ?></div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                            <span class="badge badge-reason">
                                <i class="fas <?= $reasonInfo['icon'] ?>"></i>
                                <?= $reasonInfo['label'] ?>
                            </span>
                            <?php if ($isGraded): ?>
                                <span class="badge <?= $isPassed ? 'badge-graded' : 'badge-fail' ?>">
                                    <i class="fas <?= $isPassed ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                    <?= $isPassed ? 'Réussi' : 'Échoué' ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-pending">
                                    <i class="fas fa-hourglass-half"></i> En attente
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ratt-body">
                        <!-- Note de rattrapage — mise en avant -->
                        <div class="ratt-note-block">
                            <div class="ratt-note-label">Note rattrapage</div>
                            <?php if ($isGraded): ?>
                                <div class="ratt-note-value <?= $isPassed ? 'ok' : 'low' ?>">
                                    <?= number_format($r['grade'], 2) ?>
                                </div>
                                <div class="ratt-note-sub">/ 20</div>
                            <?php else: ?>
                                <div class="ratt-note-value waiting">
                                    <i class="fas fa-hourglass-half"></i>
                                </div>
                                <div class="ratt-note-sub">en attente</div>
                            <?php endif; ?>
                        </div>

                        <!-- Détails -->
                        <div class="ratt-details">
                            <div class="ratt-detail-row">
                                <span class="detail-label">Moyenne originale</span>
                                <span class="detail-val" style="color:<?= $r['original_average'] < 10 ? 'var(--danger-color)' : 'var(--success-color)' ?>">
                                    <?= $r['original_average'] !== null ? number_format($r['original_average'], 2) . ' / 20' : '—' ?>
                                </span>
                            </div>
                            <?php if ($isGraded): ?>
                            <div class="ratt-detail-row">
                                <span class="detail-label">Noté par</span>
                                <span class="detail-val"><?= $r['graded_by_name'] ? htmlspecialchars($r['graded_by_name']) : '—' ?></span>
                            </div>
                            <div class="ratt-detail-row">
                                <span class="detail-label">Date de notation</span>
                                <span class="detail-val" style="font-weight:400;color:rgba(255,255,255,.7)">
                                    <?= $r['graded_at'] ? date('d/m/Y \à H:i', strtotime($r['graded_at'])) : '—' ?>
                                </span>
                            </div>
                            <?php else: ?>
                            <div class="ratt-pending-msg">
                                <i class="fas fa-info-circle"></i>
                                Votre enseignant n'a pas encore saisi la note de rattrapage. Revenez consulter cette page ultérieurement.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($isGraded && $r['comment']): ?>
                    <div class="ratt-comment">
                        <i class="fas fa-comment-alt"></i> <?= htmlspecialchars($r['comment']) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                </div><!-- /.ue-group -->
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-check-circle" style="color:var(--success-color)"></i>
            <p>Aucun rattrapage enregistré pour votre compte.</p>
            <p style="margin-top:8px;font-size:13px;">Si vous pensez être éligible, contactez l'administration.</p>
        </div>
    <?php endif; ?>
</main>

<?php include '../includes/footer.php'; ?>
</body>
</html>
