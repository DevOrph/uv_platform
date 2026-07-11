<?php
session_start();
require_once '../includes/db_connect.php';
$conn->set_charset('utf8mb4');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../pages/login.html"); exit();
}
$user_id = $_SESSION['user_id'];

function nf($n) { return number_format(floatval($n), 0, ',', ' '); }

// Engagements de l'enseignant avec total versé et restant
$sql_eng = "
    SELECT pe.*,
           COALESCE(SUM(CASE WHEN vc.statut != 'annule' THEN vc.montant ELSE 0 END), 0) AS total_verse,
           pe.montant_total_net - COALESCE(SUM(CASE WHEN vc.statut != 'annule' THEN vc.montant ELSE 0 END), 0) AS restant
    FROM paiements_enseignant pe
    LEFT JOIN versements_cours vc ON vc.paiement_id = pe.id
    WHERE pe.enseignant_id = ? AND pe.statut != 'annule'
    GROUP BY pe.id
    ORDER BY pe.created_at DESC
";
$stmt = $conn->prepare($sql_eng);
$stmt->bind_param("s", $user_id);
$stmt->execute();
$engagements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Paiements libres traités
$sql_lib = "
    SELECT sp.*, spt.name AS type_name
    FROM staff_payments sp
    JOIN staff_payment_types spt ON spt.id = sp.payment_type_id
    WHERE sp.staff_id = ? AND sp.status = 'processed'
    ORDER BY sp.payment_date DESC
";
$stmt2 = $conn->prepare($sql_lib);
$stmt2->bind_param("s", $user_id);
$stmt2->execute();
$paiements_libres = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

// KPIs
$total_brut    = array_sum(array_column($engagements, 'montant_total_brut'));
$total_verse   = array_sum(array_column($engagements, 'total_verse'));
$total_restant = array_sum(array_column($engagements, 'restant'));
$total_libres  = array_sum(array_column($paiements_libres, 'amount_net'));

// Nom de l'enseignant
$stmt3 = $conn->prepare("SELECT name AS nom FROM users WHERE id=?");
$stmt3->bind_param("s", $user_id);
$stmt3->execute();
$teacher_name = $stmt3->get_result()->fetch_assoc()['nom'] ?? 'Enseignant';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Honoraires — <?= htmlspecialchars($teacher_name) ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
    :root {
        --primary-bg: #051e34;
        --secondary-bg: #0c2d48;
        --card: #0d2137;
        --accent: #039be5;
        --green: #2ecc71;
        --yellow: #f1c40f;
        --orange: #e67e22;
        --red: #e74c3c;
        --teal: #1abc9c;
        --text: #e8f0fe;
        --muted: #7f9ab0;
        --border: rgba(255,255,255,0.08);
    }
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Google Sans',Arial,sans-serif; background:var(--primary-bg); color:var(--text); min-height:100vh; }

    header {
        background:var(--secondary-bg);
        padding:15px 0;
        border-bottom:1px solid var(--border);
        position:relative;
    }
    header::after {
        content:''; position:absolute; bottom:0; left:0; right:0; height:2px;
        background:linear-gradient(to right,#039be5,#4CAF50,#039be5);
    }
    .header-content { max-width:1200px; margin:0 auto; padding:0 20px; }
    h1 { font-size:22px; color:var(--accent); text-align:center; margin-bottom:16px; display:flex; align-items:center; justify-content:center; gap:10px; }
    nav ul { list-style:none; display:flex; gap:16px; flex-wrap:wrap; justify-content:center; }
    nav a { color:var(--text); text-decoration:none; padding:7px 14px; border-radius:6px; display:flex; align-items:center; gap:7px; font-size:13px; transition:background .2s; }
    nav a:hover { background:rgba(3,155,229,.12); }
    nav a.active { background:rgba(3,155,229,.18); color:var(--accent); }
    nav a[href*="logout"] { color:#dc3545; }

    .container { max-width:1100px; margin:28px auto; padding:0 20px; }

    .page-title { font-size:20px; font-weight:700; color:var(--accent); margin-bottom:20px; display:flex; align-items:center; gap:10px; }

    /* KPIs */
    .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px; margin-bottom:28px; }
    .kpi-card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:18px 20px; }
    .kpi-label { font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.7px; margin-bottom:6px; }
    .kpi-value { font-size:22px; font-weight:800; }

    /* Sections */
    .section { background:var(--card); border:1px solid var(--border); border-radius:12px; margin-bottom:24px; overflow:hidden; }
    .section-hdr { padding:14px 18px; border-bottom:1px solid var(--border); font-size:13px; font-weight:700; display:flex; align-items:center; gap:8px; }

    /* Table */
    table { width:100%; border-collapse:collapse; font-size:12px; }
    th { padding:10px 12px; background:rgba(255,255,255,.04); color:var(--muted); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; text-align:left; }
    td { padding:10px 12px; border-top:1px solid var(--border); }
    tr:hover td { background:rgba(255,255,255,.025); }

    .badge { padding:2px 9px; border-radius:10px; font-size:11px; font-weight:700; }
    .badge-brouillon { background:rgba(127,154,176,.2); color:var(--muted); }
    .badge-partiel   { background:rgba(241,196,15,.15); color:var(--yellow); }
    .badge-complete  { background:rgba(46,204,113,.15); color:var(--green); }
    .badge-annule    { background:rgba(231,76,60,.15);  color:var(--red); }

    .empty-state { text-align:center; padding:36px; color:var(--muted); }
    .empty-state i { font-size:32px; opacity:.25; margin-bottom:10px; display:block; }

    @media(max-width:600px){
        .kpi-grid { grid-template-columns:1fr 1fr; }
        nav ul { gap:8px; }
    }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
        <nav>
            <ul>
                <li><a href="teacher_dashboard.php"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a></li>
                <li><a href="courses.php"><i class="fas fa-book"></i> Mes cours</a></li>
                <li><a href="grades_management.php"><i class="fas fa-chart-bar"></i> Notes</a></li>
                <li><a href="quiz_manage.php"><i class="fas fa-question-circle"></i> Mes Quiz</a></li>
                <li><a href="manage_discussions.php"><i class="fas fa-comments"></i> Discussions</a></li>
                <li><a href="teacher_profile.php"><i class="fas fa-user"></i> Profil</a></li>
                <li><a href="mes_honoraires.php" class="active"><i class="fas fa-money-bill-wave"></i> Mes Honoraires</a></li>
                <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
            </ul>
        </nav>
    </div>
</header>

<div class="container">
    <div class="page-title">
        <i class="fas fa-money-bill-wave" style="color:var(--green);"></i>
        Mes Honoraires — <?= htmlspecialchars($teacher_name) ?>
    </div>

    <!-- KPIs -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Total brut engagements</div>
            <div class="kpi-value" style="color:var(--accent);"><?= nf($total_brut) ?> <span style="font-size:13px;font-weight:400;">FCFA</span></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Total versé</div>
            <div class="kpi-value" style="color:var(--green);"><?= nf($total_verse) ?> <span style="font-size:13px;font-weight:400;">FCFA</span></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Restant à recevoir</div>
            <div class="kpi-value" style="color:<?= $total_restant > 0 ? 'var(--orange)' : 'var(--green)' ?>;"><?= nf($total_restant) ?> <span style="font-size:13px;font-weight:400;">FCFA</span></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Total paiements libres</div>
            <div class="kpi-value" style="color:var(--yellow);"><?= nf($total_libres) ?> <span style="font-size:13px;font-weight:400;">FCFA</span></div>
        </div>
    </div>

    <!-- Engagements cours -->
    <div class="section">
        <div class="section-hdr">
            <i class="fas fa-chalkboard-teacher" style="color:var(--accent);"></i>
            Engagements Cours (<?= count($engagements) ?>)
        </div>
        <?php if (empty($engagements)): ?>
        <div class="empty-state"><i class="fas fa-chalkboard-teacher"></i><p>Aucun engagement cours.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr>
                <th>Période</th>
                <th>H. Effectuées</th>
                <th>Net Total</th>
                <th>Versé</th>
                <th>Restant</th>
                <th>Statut</th>
            </tr></thead>
            <tbody>
            <?php foreach ($engagements as $e):
                $restant_e = max(0, floatval($e['restant']));
                $verse_e   = floatval($e['total_verse']);
            ?>
            <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($e['periode']) ?></td>
                <td style="color:var(--yellow);"><?= $e['nb_heures_total'] > 0 ? number_format($e['nb_heures_total'],1).'h' : '—' ?></td>
                <td style="font-weight:700;color:var(--accent);"><?= nf($e['montant_total_net']) ?> FCFA</td>
                <td style="color:var(--green);font-weight:600;"><?= nf($verse_e) ?> FCFA</td>
                <td style="color:<?= $restant_e > 0 ? 'var(--orange)' : 'var(--green)' ?>;font-weight:700;"><?= nf($restant_e) ?> FCFA</td>
                <td><span class="badge badge-<?= $e['statut'] ?>"><?= ucfirst($e['statut']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Paiements libres -->
    <div class="section">
        <div class="section-hdr">
            <i class="fas fa-money-check-alt" style="color:var(--yellow);"></i>
            Paiements Libres (<?= count($paiements_libres) ?>)
        </div>
        <?php if (empty($paiements_libres)): ?>
        <div class="empty-state"><i class="fas fa-money-check-alt"></i><p>Aucun paiement libre enregistré.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead><tr>
                <th>Date</th>
                <th>Type</th>
                <th>Brut</th>
                <th>Retenue</th>
                <th>Net</th>
                <th>Méthode</th>
            </tr></thead>
            <tbody>
            <?php
            $methods = ['cash'=>'Espèces','bank_transfer'=>'Virement','check'=>'Chèque','mobile_money'=>'Mobile Money'];
            foreach ($paiements_libres as $p):
                $brut = floatval($p['amount_brut']);
                $ret  = floatval($p['amount_retenue']);
                $net  = floatval($p['amount_net']);
            ?>
            <tr>
                <td><?= date('d/m/Y', strtotime($p['payment_date'])) ?></td>
                <td><?= htmlspecialchars($p['type_name']) ?></td>
                <td style="color:var(--muted);"><?= nf($brut) ?> FCFA</td>
                <td style="color:var(--red);"><?= $ret > 0 ? '−'.nf($ret).' FCFA' : '—' ?></td>
                <td style="color:var(--green);font-weight:700;"><?= nf($net) ?> FCFA</td>
                <td style="color:var(--muted);font-size:11px;"><?= $methods[$p['payment_method']] ?? $p['payment_method'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
