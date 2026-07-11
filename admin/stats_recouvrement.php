<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$admin_id = $_SESSION['user_id'];

// Année filtre lue depuis la session (mise à jour via AJAX ou GET dans les autres pages)
$annee_filtre = $_SESSION['annee_filtre_paiement'] ?? ANNEE_ACADEMIQUE_COURANTE;

// ── Années disponibles — union tuition_fees + paiements_enseignant ────────────
$annees_disponibles = [ANNEE_ACADEMIQUE_COURANTE];
$__yr = $conn->query("
    SELECT DISTINCT annee FROM (
        SELECT academic_year as annee FROM tuition_fees
        UNION
        SELECT annee_academique FROM paiements_enseignant
        WHERE annee_academique IS NOT NULL AND annee_academique != ''
    ) t ORDER BY annee DESC
");
if ($__yr) {
    while ($__r = $__yr->fetch_assoc()) {
        if (!in_array($__r['annee'], $annees_disponibles)) {
            $annees_disponibles[] = $__r['annee'];
        }
    }
}
rsort($annees_disponibles);
unset($__yr, $__r);

// ── Classes disponibles ───────────────────────────────────────────────────────
$classes_disponibles = [];
$rc = $conn->query("
    SELECT DISTINCT c.id, c.name FROM classes c
    JOIN tuition_fees tf ON tf.class_id = c.id
    ORDER BY c.name
");
if ($rc) {
    while ($row = $rc->fetch_assoc()) $classes_disponibles[] = $row;
}

// ── Badge messages non lus (nav) ─────────────────────────────────────────────
$unread_messages_count = 0;
$um = $conn->query("SELECT COUNT(*) as count FROM finance_messages WHERE status IN ('new', 'in_progress')");
if ($um) $unread_messages_count = (int)$um->fetch_assoc()['count'];

// ── AJAX — sauvegarder l'année en session ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'save_annee_session') {
    header('Content-Type: application/json; charset=utf-8');
    $a = $_GET['annee'] ?? '';
    if (preg_match('/^\d{4}-\d{4}$/', $a)) {
        $_SESSION['annee_filtre_paiement'] = $a;
    }
    echo json_encode(['success' => true]);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// AJAX — données pour toutes les sections
// ══════════════════════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'get_all_data') {
    header('Content-Type: application/json; charset=utf-8');

    $annee = (isset($_GET['annee']) && preg_match('/^\d{4}-\d{4}$/', $_GET['annee']))
        ? $_GET['annee']
        : ANNEE_ACADEMIQUE_COURANTE;

    $classe_id  = (isset($_GET['classe_id']) && ctype_digit((string)$_GET['classe_id']))
        ? intval($_GET['classe_id'])
        : 0;

    // Condition classe sûre (classe_id validé via intval + ctype_digit)
    $cc = $classe_id > 0 ? "AND tf.class_id = $classe_id" : "";

    try {
        // ── KPI 1 : taux global ───────────────────────────────────────────────
        $s = $conn->prepare("
            SELECT COALESCE(SUM(pd.amount_due),  0) AS total_du,
                   COALESCE(SUM(pd.amount_paid), 0) AS total_paye
            FROM payment_deadlines pd
            JOIN tuition_fees tf ON tf.id = pd.tuition_fee_id AND tf.academic_year = ?
            WHERE 1=1 $cc
        ");
        $s->bind_param("s", $annee);
        $s->execute();
        $k1 = $s->get_result()->fetch_assoc();
        $total_du   = (float)$k1['total_du'];
        $total_paye = (float)$k1['total_paye'];
        $taux_global = $total_du > 0 ? round($total_paye / $total_du * 100, 1) : 0;

        // ── KPI 2 : encaissements mois courant / précédent ────────────────────
        $s = $conn->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN DATE_FORMAT(sp.payment_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')
                                  THEN sp.amount_paid ELSE 0 END), 0) AS mois_courant,
                COALESCE(SUM(CASE WHEN DATE_FORMAT(sp.payment_date,'%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m')
                                  THEN sp.amount_paid ELSE 0 END), 0) AS mois_precedent
            FROM student_payments sp
            JOIN tuition_fees tf ON tf.id = sp.tuition_fee_id AND tf.academic_year = ?
            WHERE sp.status = 'validated' $cc
        ");
        $s->bind_param("s", $annee);
        $s->execute();
        $k2 = $s->get_result()->fetch_assoc();
        $mois_courant   = (float)$k2['mois_courant'];
        $mois_precedent = (float)$k2['mois_precedent'];
        $variation_mois = $mois_precedent > 0
            ? round(($mois_courant - $mois_precedent) / $mois_precedent * 100, 1)
            : ($mois_courant > 0 ? 100 : 0);

        // ── KPI 3 : étudiants en retard ───────────────────────────────────────
        $s = $conn->prepare("
            SELECT COUNT(DISTINCT pd.student_id) AS en_retard
            FROM payment_deadlines pd
            JOIN tuition_fees tf ON tf.id = pd.tuition_fee_id AND tf.academic_year = ?
            WHERE pd.status = 'overdue' $cc
        ");
        $s->bind_param("s", $annee);
        $s->execute();
        $en_retard = (int)$s->get_result()->fetch_assoc()['en_retard'];

        $s = $conn->prepare("
            SELECT COUNT(DISTINCT u.id) AS total_actifs
            FROM users u
            JOIN tuition_fees tf ON tf.class_id = u.class_id AND tf.academic_year = ?
            WHERE u.role = 'student' AND u.status = 'active' $cc
        ");
        $s->bind_param("s", $annee);
        $s->execute();
        $total_actifs = (int)$s->get_result()->fetch_assoc()['total_actifs'];

        // ── KPI 4 : montant total en retard ───────────────────────────────────
        $s = $conn->prepare("
            SELECT COALESCE(SUM(pd.amount_due - pd.amount_paid), 0) AS montant_retard
            FROM payment_deadlines pd
            JOIN tuition_fees tf ON tf.id = pd.tuition_fee_id AND tf.academic_year = ?
            WHERE pd.status = 'overdue' $cc
        ");
        $s->bind_param("s", $annee);
        $s->execute();
        $montant_retard = (float)$s->get_result()->fetch_assoc()['montant_retard'];

        // ── Section 2 : courbe 12 derniers mois ──────────────────────────────
        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = date('Y-m', strtotime("-{$i} months"));
        }
        $months_fr = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
        $month_labels = array_map(function($m) use ($months_fr) {
            [$y, $mo] = explode('-', $m);
            return $months_fr[(int)$mo - 1] . ' ' . $y;
        }, $months);

        $encaisse_map = array_fill_keys($months, 0.0);
        $prevu_map    = array_fill_keys($months, 0.0);

        $s = $conn->prepare("
            SELECT DATE_FORMAT(sp.payment_date,'%Y-%m') AS mois,
                   SUM(sp.amount_paid) AS montant
            FROM student_payments sp
            JOIN tuition_fees tf ON tf.id = sp.tuition_fee_id AND tf.academic_year = ?
            WHERE sp.status = 'validated'
              AND sp.payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH),'%Y-%m-01')
              $cc
            GROUP BY mois
        ");
        $s->bind_param("s", $annee);
        $s->execute();
        $res = $s->get_result();
        while ($row = $res->fetch_assoc()) {
            if (isset($encaisse_map[$row['mois']])) $encaisse_map[$row['mois']] = (float)$row['montant'];
        }

        $s = $conn->prepare("
            SELECT DATE_FORMAT(pd.due_date,'%Y-%m') AS mois,
                   SUM(pd.amount_due) AS montant
            FROM payment_deadlines pd
            JOIN tuition_fees tf ON tf.id = pd.tuition_fee_id AND tf.academic_year = ?
            WHERE pd.due_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH),'%Y-%m-01')
              $cc
            GROUP BY mois
        ");
        $s->bind_param("s", $annee);
        $s->execute();
        $res = $s->get_result();
        while ($row = $res->fetch_assoc()) {
            if (isset($prevu_map[$row['mois']])) $prevu_map[$row['mois']] = (float)$row['montant'];
        }

        // ── Section 3 : taux par classe ───────────────────────────────────────
        $where_cls = $classe_id > 0 ? "AND tf.class_id = $classe_id" : "";
        $s = $conn->prepare("
            SELECT c.id, c.name AS class_name, c.code,
                   COUNT(DISTINCT u.id) AS nb_students,
                   COALESCE(SUM(pd.amount_due),  0) AS total_du,
                   COALESCE(SUM(pd.amount_paid), 0) AS total_paye
            FROM classes c
            JOIN tuition_fees tf ON tf.class_id = c.id AND tf.academic_year = ?
            JOIN users u ON u.class_id = c.id AND u.role = 'student' AND u.status = 'active'
            LEFT JOIN payment_deadlines pd ON pd.student_id = u.id AND pd.tuition_fee_id = tf.id
            WHERE 1=1 $where_cls
            GROUP BY c.id, c.name, c.code
            ORDER BY CASE WHEN COALESCE(SUM(pd.amount_due),0) > 0
                          THEN COALESCE(SUM(pd.amount_paid),0) / COALESCE(SUM(pd.amount_due),0)
                          ELSE 0 END DESC
        ");
        $s->bind_param("s", $annee);
        $s->execute();
        $classes_data = [];
        $res = $s->get_result();
        while ($row = $res->fetch_assoc()) {
            $row['taux'] = $row['total_du'] > 0
                ? round((float)$row['total_paye'] / (float)$row['total_du'] * 100, 1)
                : 0;
            $classes_data[] = $row;
        }

        // ── Section 4 : méthodes de paiement ─────────────────────────────────
        $s = $conn->prepare("
            SELECT sp.payment_method,
                   COUNT(*) AS nb_transactions,
                   SUM(sp.amount_paid) AS montant
            FROM student_payments sp
            JOIN tuition_fees tf ON tf.id = sp.tuition_fee_id AND tf.academic_year = ?
            WHERE sp.status = 'validated' $cc
            GROUP BY sp.payment_method
            ORDER BY montant DESC
        ");
        $s->bind_param("s", $annee);
        $s->execute();
        $methodes_data = [];
        $total_methodes = 0.0;
        $res = $s->get_result();
        while ($row = $res->fetch_assoc()) {
            $methodes_data[] = [
                'method' => $row['payment_method'],
                'montant' => (float)$row['montant'],
                'nb'      => (int)$row['nb_transactions'],
            ];
            $total_methodes += (float)$row['montant'];
        }
        foreach ($methodes_data as &$m) {
            $m['pct'] = $total_methodes > 0 ? round($m['montant'] / $total_methodes * 100, 1) : 0;
        }
        unset($m);

        // ── Section 5 : top 10 solde restant ─────────────────────────────────
        $s = $conn->prepare("
            SELECT u.id, u.name, c.name AS class_name,
                   COALESCE(SUM(pd.amount_due),  0) AS total_du,
                   COALESCE(SUM(pd.amount_paid), 0) AS total_paye,
                   COALESCE(SUM(pd.amount_due - pd.amount_paid), 0) AS restant,
                   (SELECT MAX(sp2.payment_date)
                    FROM student_payments sp2
                    WHERE sp2.student_id = u.id AND sp2.status = 'validated') AS derniere_activite
            FROM users u
            JOIN classes c ON c.id = u.class_id
            JOIN tuition_fees tf ON tf.class_id = u.class_id AND tf.academic_year = ?
            LEFT JOIN payment_deadlines pd ON pd.student_id = u.id AND pd.tuition_fee_id = tf.id
            WHERE u.role = 'student' $cc
            GROUP BY u.id, u.name, c.name
            HAVING restant > 0
            ORDER BY restant DESC
            LIMIT 10
        ");
        $s->bind_param("s", $annee);
        $s->execute();
        $top10_data = [];
        $res = $s->get_result();
        while ($row = $res->fetch_assoc()) $top10_data[] = $row;

        // ── Section 6 : évolution taux mensuel ───────────────────────────────
        $enc_evo = array_fill_keys($months, 0.0);
        $prev_evo = array_fill_keys($months, 0.0);

        // Réutilise les données déjà calculées
        foreach ($encaisse_map as $m => $v) $enc_evo[$m] = $v;
        foreach ($prevu_map    as $m => $v) $prev_evo[$m] = $v;

        $taux_evo = [];
        foreach ($months as $m) {
            $enc = $enc_evo[$m];
            $pre = $prev_evo[$m];
            $taux_evo[] = $pre > 0 ? round($enc / $pre * 100, 1) : 0;
        }

        echo json_encode([
            'success' => true,
            'kpis' => [
                'taux_global'     => $taux_global,
                'total_du'        => $total_du,
                'total_paye'      => $total_paye,
                'mois_courant'    => $mois_courant,
                'mois_precedent'  => $mois_precedent,
                'variation_mois'  => $variation_mois,
                'en_retard'       => $en_retard,
                'total_actifs'    => $total_actifs,
                'montant_retard'  => $montant_retard,
            ],
            'courbe' => [
                'labels'   => $month_labels,
                'encaisse' => array_values($encaisse_map),
                'prevu'    => array_values($prevu_map),
            ],
            'classes'  => $classes_data,
            'methodes' => $methodes_data,
            'top10'    => $top10_data,
            'evolution' => [
                'labels' => $month_labels,
                'taux'   => $taux_evo,
            ],
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── AJAX — détail étudiants d'une classe (accordéon) ─────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_class_students') {
    header('Content-Type: application/json; charset=utf-8');

    $class_id = (isset($_GET['class_id']) && ctype_digit((string)$_GET['class_id']))
        ? intval($_GET['class_id'])
        : 0;
    $annee = (isset($_GET['annee']) && preg_match('/^\d{4}-\d{4}$/', $_GET['annee']))
        ? $_GET['annee']
        : ANNEE_ACADEMIQUE_COURANTE;

    if ($class_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Classe invalide']);
        exit();
    }

    $s = $conn->prepare("
        SELECT u.id, u.name,
               COALESCE(SUM(pd.amount_due),  0) AS total_du,
               COALESCE(SUM(pd.amount_paid), 0) AS total_paye,
               COALESCE(SUM(pd.amount_due - pd.amount_paid), 0) AS restant
        FROM users u
        JOIN tuition_fees tf ON tf.class_id = ? AND tf.academic_year = ?
        LEFT JOIN payment_deadlines pd ON pd.student_id = u.id AND pd.tuition_fee_id = tf.id
        WHERE u.class_id = ? AND u.role = 'student' AND u.status = 'active'
        GROUP BY u.id, u.name
        ORDER BY restant DESC
    ");
    $s->bind_param("isi", $class_id, $annee, $class_id);
    $s->execute();
    $res = $s->get_result();
    $students = [];
    while ($row = $res->fetch_assoc()) $students[] = $row;

    echo json_encode(['success' => true, 'students' => $students], JSON_UNESCAPED_UNICODE);
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiques de Recouvrement - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
            --info-color:    #3498db;
            --card-bg:       rgba(255,255,255,0.07);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            color: var(--text-light);
            min-height: 100vh;
        }

        /* ── Header / Nav ─────────────────────────────────────────────────── */
        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            border-bottom: 1px solid var(--border-color);
            position: sticky; top: 0; z-index: 100;
        }
        .header-content { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
        h1 {
            font-size: 26px;
            color: var(--accent-color);
            margin-bottom: 12px;
            text-align: center;
            display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        nav ul { list-style:none; display:flex; justify-content:center; flex-wrap:wrap; gap:4px; }
        nav a {
            color: var(--text-light);
            text-decoration: none;
            padding: 9px 14px;
            border-radius: 5px;
            display: flex; align-items: center; gap: 7px;
            transition: all .25s ease;
            font-size: 14px;
        }
        nav a:hover, nav a.active {
            background: var(--accent-color);
            color: #fff;
        }
        .badge {
            background: var(--danger-color);
            color: #fff;
            border-radius: 50%;
            width: 18px; height: 18px;
            font-size: 11px;
            display: flex; align-items: center; justify-content: center;
        }

        /* ── Container ───────────────────────────────────────────────────── */
        .container { max-width: 1400px; margin: 0 auto; padding: 24px 20px; }

        /* ── Titre page ──────────────────────────────────────────────────── */
        .page-title {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 20px;
        }
        .page-title h2 { font-size: 22px; color: var(--accent-color); display:flex; align-items:center; gap:10px; }
        .page-title a { font-size: 13px; color: #aaa; text-decoration: none; }
        .page-title a:hover { color: #fff; }

        /* ── Filtres ──────────────────────────────────────────────────────── */
        .filters-bar {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 16px 20px;
            display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap;
            margin-bottom: 28px;
        }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-size: 12px; color: #aaa; text-transform: uppercase; letter-spacing:.5px; }
        .filter-group select {
            background: var(--secondary-bg);
            border: 1px solid var(--border-color);
            color: var(--text-light);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            min-width: 180px;
        }
        .filter-group select:focus { outline: none; border-color: var(--accent-color); }
        #loading-indicator {
            display: none; align-items: center; gap: 8px;
            margin-left: auto; color: var(--accent-color); font-size: 14px;
        }
        #loading-indicator.visible { display: flex; }

        /* ── KPI Cards ────────────────────────────────────────────────────── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        @media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 480px) { .kpi-grid { grid-template-columns: 1fr; } }

        .kpi-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            position: relative;
            overflow: hidden;
            transition: transform .2s;
        }
        .kpi-card:hover { transform: translateY(-2px); }
        .kpi-card::before {
            content: '';
            position: absolute; top:0; left:0; right:0; height: 3px;
        }
        .kpi-card.card-taux::before      { background: var(--success-color); }
        .kpi-card.card-encaisse::before  { background: var(--accent-color); }
        .kpi-card.card-retard::before    { background: var(--warning-color); }
        .kpi-card.card-montant::before   { background: var(--danger-color); }

        .kpi-icon {
            width: 44px; height: 44px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
            margin-bottom: 12px;
        }
        .card-taux    .kpi-icon { background: rgba(46,204,113,.15); color: var(--success-color); }
        .card-encaisse .kpi-icon { background: rgba(3,155,229,.15);  color: var(--accent-color); }
        .card-retard  .kpi-icon { background: rgba(243,156,18,.15); color: var(--warning-color); }
        .card-montant .kpi-icon { background: rgba(231,76,60,.15);  color: var(--danger-color); }

        .kpi-label { font-size: 12px; color: #aaa; text-transform: uppercase; letter-spacing:.5px; margin-bottom: 6px; }
        .kpi-value { font-size: 28px; font-weight: 700; line-height: 1; margin-bottom: 6px; }
        .kpi-sub   { font-size: 12px; color: #aaa; }
        .kpi-variation { font-size: 13px; font-weight: 600; }
        .kpi-variation.positive { color: var(--success-color); }
        .kpi-variation.negative { color: var(--danger-color); }

        .taux-bar-wrap {
            margin-top: 10px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px; height: 6px; overflow: hidden;
        }
        .taux-bar-fill {
            height: 100%; border-radius: 10px;
            background: var(--success-color);
            transition: width .6s ease;
        }

        /* ── Section cards ───────────────────────────────────────────────── */
        .section-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 22px;
            margin-bottom: 24px;
        }
        .section-title {
            font-size: 16px; font-weight: 600;
            color: var(--accent-color);
            display: flex; align-items: center; gap: 9px;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        /* ── 2 colonnes ──────────────────────────────────────────────────── */
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        @media (max-width: 820px) { .two-col { grid-template-columns: 1fr; } }

        /* ── Graphiques ──────────────────────────────────────────────────── */
        .chart-container { position: relative; height: 280px; }
        .chart-container-sm { position: relative; height: 260px; }

        /* ── Tableau classes ─────────────────────────────────────────────── */
        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left; padding: 10px 12px;
            font-size: 12px; text-transform: uppercase; letter-spacing:.5px;
            color: #aaa;
            border-bottom: 1px solid var(--border-color);
        }
        td {
            padding: 11px 12px;
            font-size: 14px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }

        .class-row { cursor: pointer; transition: background .15s; }
        .class-row:hover { background: rgba(255,255,255,0.05); }
        .class-row.expanded { background: rgba(3,155,229,0.08); }

        .progress-bar-wrap {
            background: rgba(255,255,255,0.1);
            border-radius: 10px; height: 8px; overflow: hidden; min-width: 80px;
        }
        .progress-bar-fill {
            height: 100%; border-radius: 10px;
            transition: width .5s ease;
        }
        .badge-code {
            font-size: 11px; padding: 2px 7px;
            background: rgba(3,155,229,.2); color: var(--accent-color);
            border-radius: 4px; margin-left: 6px;
        }

        .accordion-row td { padding: 0; }
        .accordion-content {
            padding: 16px 20px;
            background: rgba(0,0,0,0.2);
            border-top: 1px solid var(--border-color);
        }
        .inner-table th { color: #888; }
        .inner-table td { font-size: 13px; }

        /* ── Donut legend ────────────────────────────────────────────────── */
        .donut-legend { margin-top: 14px; display: flex; flex-direction: column; gap: 10px; }
        .donut-legend-item {
            display: flex; align-items: center; gap: 10px;
            font-size: 13px;
        }
        .dot {
            width: 12px; height: 12px; border-radius: 50%;
            flex-shrink: 0;
        }
        .donut-legend-item .amount { margin-left: auto; font-weight: 600; }
        .donut-legend-item .pct { color: #aaa; min-width: 40px; text-align: right; }

        /* ── Top 10 ──────────────────────────────────────────────────────── */
        .rank {
            display: inline-flex; align-items: center; justify-content: center;
            width: 22px; height: 22px;
            border-radius: 50%;
            background: rgba(3,155,229,.2); color: var(--accent-color);
            font-size: 11px; font-weight: 700;
            margin-right: 5px;
        }
        .btn-dossier {
            display: inline-flex; align-items: center; gap: 5px;
            background: rgba(3,155,229,.15);
            color: var(--accent-color);
            padding: 5px 10px; border-radius: 6px;
            text-decoration: none; font-size: 12px;
            transition: background .2s;
            white-space: nowrap;
        }
        .btn-dossier:hover { background: var(--accent-color); color: #fff; }

        /* ── Empty state ─────────────────────────────────────────────────── */
        .empty-state {
            text-align: center; padding: 40px 20px;
            color: #666; font-size: 14px;
        }
        .empty-state i { font-size: 36px; margin-bottom: 12px; display: block; }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
        <nav>
            <ul>
                <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="user_management.php"><i class="fas fa-users"></i> Utilisateurs</a></li>
                <li><a href="course_management.php"><i class="fas fa-book"></i> Cours</a></li>
                <li>
                    <a href="payment_dashboard.php">
                        <i class="fas fa-money-bill-wave"></i> Paiements
                        <?php if ($unread_messages_count > 0): ?>
                        <span class="badge"><?php echo $unread_messages_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li><a href="stats_recouvrement.php" class="active"><i class="fas fa-chart-line"></i> Statistiques</a></li>
                <li><a href="payment_admin.php"><i class="fas fa-money-bill-wave"></i> Personnel</a></li>
                <li><a href="comptabilite.php"><i class="fas fa-calculator"></i> Comptabilité</a></li>
                <li><a href="admin_profile.php"><i class="fas fa-user-cog"></i> Profil</a></li>
                <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
            </ul>
        </nav>
    </div>
</header>

<div class="container">

    <!-- Titre page -->
    <div class="page-title">
        <h2><i class="fas fa-chart-pie"></i> Statistiques de Recouvrement</h2>
        <a href="payment_dashboard.php"><i class="fas fa-arrow-left"></i> Retour aux Paiements</a>
    </div>

    <!-- Filtres globaux -->
    <div class="filters-bar">
        <div class="filter-group">
            <label><i class="fas fa-calendar-alt"></i> Année académique</label>
            <select id="filtre-annee">
                <?php foreach ($annees_disponibles as $an): ?>
                <option value="<?php echo htmlspecialchars($an); ?>"
                    <?php echo $an === $annee_filtre ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($an); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-layer-group"></i> Classe</label>
            <select id="filtre-classe">
                <option value="0">Toutes les classes</option>
                <?php foreach ($classes_disponibles as $cls): ?>
                <option value="<?php echo (int)$cls['id']; ?>">
                    <?php echo htmlspecialchars($cls['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div id="loading-indicator">
            <i class="fas fa-spinner fa-spin"></i> Chargement…
        </div>
    </div>

    <!-- ── KPI Cards ─────────────────────────────────────────────────────── -->
    <div class="kpi-grid">
        <div class="kpi-card card-taux">
            <div class="kpi-icon"><i class="fas fa-percent"></i></div>
            <div class="kpi-label">Taux de recouvrement global</div>
            <div class="kpi-value" id="kpi-taux">—</div>
            <div class="taux-bar-wrap"><div class="taux-bar-fill" id="kpi-taux-bar" style="width:0%"></div></div>
            <div class="kpi-sub" id="kpi-taux-detail" style="margin-top:6px">—</div>
        </div>

        <div class="kpi-card card-encaisse">
            <div class="kpi-icon"><i class="fas fa-coins"></i></div>
            <div class="kpi-label">Encaissements ce mois</div>
            <div class="kpi-value" id="kpi-mois">—</div>
            <div class="kpi-variation" id="kpi-variation">—</div>
            <div class="kpi-sub" id="kpi-mois-precedent" style="margin-top:4px">—</div>
        </div>

        <div class="kpi-card card-retard">
            <div class="kpi-icon"><i class="fas fa-user-clock"></i></div>
            <div class="kpi-label">Étudiants en retard</div>
            <div class="kpi-value" id="kpi-retard-count">—</div>
            <div class="kpi-sub" id="kpi-retard-label">sur total actifs</div>
        </div>

        <div class="kpi-card card-montant">
            <div class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="kpi-label">Montant total en retard</div>
            <div class="kpi-value" id="kpi-montant-retard" style="font-size:22px">—</div>
            <div class="kpi-sub">échéances overdue</div>
        </div>
    </div>

    <!-- ── Section 2 : courbe encaissements vs prévu ────────────────────── -->
    <div class="section-card">
        <div class="section-title">
            <i class="fas fa-chart-line"></i>
            Encaissements vs Prévu — 12 derniers mois
        </div>
        <div class="chart-container">
            <canvas id="chart-courbe"></canvas>
        </div>
    </div>

    <!-- ── Section 3 & 4 : classes + donut ──────────────────────────────── -->
    <div class="two-col">
        <!-- Section 3 : par classe -->
        <div class="section-card" style="margin-bottom:0">
            <div class="section-title">
                <i class="fas fa-school"></i>
                Taux de paiement par classe
                <span style="font-size:11px;color:#666;margin-left:auto">Cliquer pour détail</span>
            </div>
            <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>Classe</th>
                            <th style="text-align:center">Étudiants</th>
                            <th style="text-align:right">Total dû</th>
                            <th style="text-align:right">Payé</th>
                            <th style="text-align:center">Taux</th>
                            <th>Progression</th>
                        </tr>
                    </thead>
                    <tbody id="table-classes-body">
                        <tr><td colspan="6" class="empty-state"><i class="fas fa-spinner fa-spin"></i> Chargement…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section 4 : donut méthodes -->
        <div class="section-card" style="margin-bottom:0">
            <div class="section-title">
                <i class="fas fa-chart-pie"></i>
                Répartition par méthode de paiement
            </div>
            <div class="chart-container-sm">
                <canvas id="chart-donut"></canvas>
            </div>
            <div class="donut-legend" id="donut-legend"></div>
        </div>
    </div>

    <!-- ── Section 5 : top 10 solde restant ─────────────────────────────── -->
    <div class="section-card" style="margin-top:24px">
        <div class="section-title">
            <i class="fas fa-trophy"></i>
            Top 10 — Plus grands soldes restants
        </div>
        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Étudiant</th>
                        <th>Classe</th>
                        <th style="text-align:right">Total dû</th>
                        <th style="text-align:right">Payé</th>
                        <th style="text-align:right">Restant</th>
                        <th style="text-align:center">Dernière activité</th>
                        <th style="text-align:center">Action</th>
                    </tr>
                </thead>
                <tbody id="table-top10-body">
                    <tr><td colspan="7" class="empty-state"><i class="fas fa-spinner fa-spin"></i> Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Section 6 : évolution taux mensuel ───────────────────────────── -->
    <div class="section-card">
        <div class="section-title">
            <i class="fas fa-chart-bar"></i>
            Évolution du taux de recouvrement mensuel (%)
            <span style="font-size:12px;color:#666;margin-left:auto">
                <span style="color:var(--success-color)">■</span> ≥80% &nbsp;
                <span style="color:var(--warning-color)">■</span> 50-79% &nbsp;
                <span style="color:var(--danger-color)">■</span> &lt;50%
            </span>
        </div>
        <div class="chart-container">
            <canvas id="chart-evolution"></canvas>
        </div>
    </div>

</div><!-- /container -->

<script>
'use strict';

// ── Utilitaires ───────────────────────────────────────────────────────────────
const fmtN = n => new Intl.NumberFormat('fr-FR').format(Math.round(parseFloat(n) || 0));
const methodLabels = {
    cash: 'Cash',
    bank_transfer: 'Virement bancaire',
    mobile_money: 'Mobile Money',
    check: 'Chèque',
    other: 'Autre'
};
const COLORS = ['#2ecc71','#3498db','#f39c12','#9b59b6','#e74c3c'];

let chartCourbe   = null;
let chartDonut    = null;
let chartEvolution = null;

// ── Chargement principal ──────────────────────────────────────────────────────
async function loadData() {
    const annee     = document.getElementById('filtre-annee').value;
    const classe_id = document.getElementById('filtre-classe').value;

    document.getElementById('loading-indicator').classList.add('visible');

    try {
        const url = `stats_recouvrement.php?action=get_all_data`
                  + `&annee=${encodeURIComponent(annee)}`
                  + `&classe_id=${encodeURIComponent(classe_id)}`;
        const res = await fetch(url);
        const data = await res.json();

        if (!data.success) throw new Error(data.error || 'Erreur serveur');

        renderKPIs(data.kpis);
        renderCourbe(data.courbe);
        renderClasses(data.classes, annee);
        renderMethodes(data.methodes);
        renderTop10(data.top10);
        renderEvolution(data.evolution);
    } catch (e) {
        console.error('loadData:', e);
    } finally {
        document.getElementById('loading-indicator').classList.remove('visible');
    }
}

// ── KPIs ─────────────────────────────────────────────────────────────────────
function renderKPIs(k) {
    // Taux global
    document.getElementById('kpi-taux').textContent = k.taux_global + '%';
    document.getElementById('kpi-taux-bar').style.width = Math.min(k.taux_global, 100) + '%';
    document.getElementById('kpi-taux-detail').textContent =
        fmtN(k.total_paye) + ' / ' + fmtN(k.total_du) + ' FCFA';

    // Encaissements mois
    document.getElementById('kpi-mois').textContent = fmtN(k.mois_courant) + ' FCFA';
    const varEl = document.getElementById('kpi-variation');
    const v = parseFloat(k.variation_mois);
    varEl.textContent = (v >= 0 ? '▲ +' : '▼ ') + v + '% vs mois préc.';
    varEl.className = 'kpi-variation ' + (v >= 0 ? 'positive' : 'negative');
    document.getElementById('kpi-mois-precedent').textContent =
        'Mois préc. : ' + fmtN(k.mois_precedent) + ' FCFA';

    // Retard
    document.getElementById('kpi-retard-count').textContent = k.en_retard + ' / ' + k.total_actifs;
    document.getElementById('kpi-retard-label').textContent = 'étudiants en retard';

    // Montant retard
    document.getElementById('kpi-montant-retard').textContent = fmtN(k.montant_retard) + ' FCFA';
}

// ── Courbe ────────────────────────────────────────────────────────────────────
function renderCourbe(courbe) {
    if (chartCourbe) chartCourbe.destroy();
    const ctx = document.getElementById('chart-courbe').getContext('2d');
    chartCourbe = new Chart(ctx, {
        type: 'line',
        data: {
            labels: courbe.labels,
            datasets: [
                {
                    label: 'Encaissé',
                    data: courbe.encaisse,
                    borderColor: '#2ecc71',
                    backgroundColor: 'rgba(46,204,113,0.12)',
                    tension: 0.35, fill: true, pointRadius: 4,
                },
                {
                    label: 'Prévu',
                    data: courbe.prevu,
                    borderColor: '#f39c12',
                    backgroundColor: 'rgba(243,156,18,0.08)',
                    tension: 0.35, fill: true, pointRadius: 4,
                    borderDash: [6, 4],
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#ccc', font: { size: 13 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.dataset.label + ' : ' + fmtN(ctx.parsed.y) + ' FCFA'
                    }
                }
            },
            scales: {
                x: { ticks: { color: '#aaa' }, grid: { color: 'rgba(255,255,255,0.07)' } },
                y: {
                    ticks: { color: '#aaa', callback: v => fmtN(v) },
                    grid: { color: 'rgba(255,255,255,0.07)' }
                }
            }
        }
    });
}

// ── Tableau classes ───────────────────────────────────────────────────────────
function renderClasses(classes, annee) {
    const tbody = document.getElementById('table-classes-body');
    tbody.innerHTML = '';

    if (!classes.length) {
        tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><i class="fas fa-info-circle"></i>Aucune donnée pour ces filtres.</div></td></tr>';
        return;
    }

    classes.forEach(cls => {
        const taux = parseFloat(cls.taux);
        const color = taux >= 80 ? '#2ecc71' : taux >= 50 ? '#f39c12' : '#e74c3c';

        // Ligne principale
        const tr = document.createElement('tr');
        tr.className = 'class-row';
        tr.dataset.classId = cls.id;
        tr.dataset.annee   = annee;
        tr.innerHTML = `
            <td>
                <i class="fas fa-chevron-right" style="font-size:10px;color:#666;margin-right:6px;transition:transform .2s" id="arrow-${cls.id}"></i>
                <strong>${escHtml(cls.class_name)}</strong>
                ${cls.code ? `<span class="badge-code">${escHtml(cls.code)}</span>` : ''}
            </td>
            <td style="text-align:center">${cls.nb_students}</td>
            <td style="text-align:right">${fmtN(cls.total_du)} FCFA</td>
            <td style="text-align:right">${fmtN(cls.total_paye)} FCFA</td>
            <td style="text-align:center"><strong style="color:${color}">${taux}%</strong></td>
            <td>
                <div class="progress-bar-wrap">
                    <div class="progress-bar-fill" style="width:${taux}%;background:${color}"></div>
                </div>
            </td>
        `;
        tbody.appendChild(tr);

        // Ligne accordéon
        const trAcc = document.createElement('tr');
        trAcc.className = 'accordion-row';
        trAcc.id = 'acc-' + cls.id;
        trAcc.style.display = 'none';
        trAcc.innerHTML = `<td colspan="6"><div class="accordion-content" id="acc-content-${cls.id}"></div></td>`;
        tbody.appendChild(trAcc);

        tr.addEventListener('click', () => toggleAccordion(cls.id, tr, annee));
    });
}

async function toggleAccordion(classId, row, annee) {
    const accRow     = document.getElementById('acc-' + classId);
    const accContent = document.getElementById('acc-content-' + classId);
    const arrow      = document.getElementById('arrow-' + classId);
    const isOpen     = accRow.style.display !== 'none';

    // Ferme tous
    document.querySelectorAll('.accordion-row').forEach(r => { r.style.display = 'none'; });
    document.querySelectorAll('.class-row').forEach(r => { r.classList.remove('expanded'); });
    document.querySelectorAll('[id^="arrow-"]').forEach(a => { a.style.transform = ''; });

    if (isOpen) return;

    accRow.style.display = '';
    row.classList.add('expanded');
    arrow.style.transform = 'rotate(90deg)';
    accContent.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Chargement…';

    try {
        const res = await fetch(
            `stats_recouvrement.php?action=get_class_students&class_id=${classId}&annee=${encodeURIComponent(annee)}`
        );
        const data = await res.json();

        if (!data.success || !data.students.length) {
            accContent.innerHTML = '<p style="color:#888;padding:10px">Aucun étudiant trouvé.</p>';
            return;
        }

        let html = `<table class="inner-table">
            <thead><tr>
                <th>Étudiant</th>
                <th style="text-align:right">Total dû</th>
                <th style="text-align:right">Payé</th>
                <th style="text-align:right">Restant</th>
                <th style="text-align:center">Statut</th>
            </tr></thead><tbody>`;

        data.students.forEach(s => {
            const restant = parseFloat(s.restant);
            const du      = parseFloat(s.total_du);
            const paye    = parseFloat(s.total_paye);
            let statusColor, statusLabel;
            if (restant <= 0)       { statusColor = '#2ecc71'; statusLabel = 'Soldé';   }
            else if (paye > 0)      { statusColor = '#f39c12'; statusLabel = 'Partiel'; }
            else                    { statusColor = '#e74c3c'; statusLabel = 'Impayé';  }

            html += `<tr>
                <td>${escHtml(s.name)}</td>
                <td style="text-align:right">${fmtN(du)} FCFA</td>
                <td style="text-align:right">${fmtN(paye)} FCFA</td>
                <td style="text-align:right;color:${restant > 0 ? '#e74c3c' : '#2ecc71'};font-weight:600">
                    ${fmtN(restant)} FCFA
                </td>
                <td style="text-align:center">
                    <span style="background:${statusColor};color:#fff;padding:2px 9px;border-radius:4px;font-size:11px">${statusLabel}</span>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        accContent.innerHTML = html;

    } catch (e) {
        accContent.innerHTML = '<p style="color:#e74c3c">Erreur de chargement.</p>';
    }
}

// ── Donut méthodes ────────────────────────────────────────────────────────────
function renderMethodes(methodes) {
    if (chartDonut) chartDonut.destroy();

    if (!methodes.length) {
        document.getElementById('donut-legend').innerHTML =
            '<div class="empty-state"><i class="fas fa-info-circle"></i> Aucun paiement enregistré.</div>';
        return;
    }

    const ctx = document.getElementById('chart-donut').getContext('2d');
    const labels  = methodes.map(m => methodLabels[m.method] || m.method);
    const amounts = methodes.map(m => m.montant);

    chartDonut = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: amounts,
                backgroundColor: COLORS.slice(0, methodes.length),
                borderWidth: 2,
                borderColor: '#0c2d48',
                hoverOffset: 6,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const m = methodes[ctx.dataIndex];
                            return ` ${fmtN(m.montant)} FCFA (${m.pct}%)`;
                        }
                    }
                }
            }
        }
    });

    const legendEl = document.getElementById('donut-legend');
    legendEl.innerHTML = methodes.map((m, i) => `
        <div class="donut-legend-item">
            <span class="dot" style="background:${COLORS[i % COLORS.length]}"></span>
            <span>${escHtml(methodLabels[m.method] || m.method)}</span>
            <span class="amount">${fmtN(m.montant)} FCFA</span>
            <span class="pct">${m.pct}%</span>
        </div>
    `).join('');
}

// ── Top 10 ────────────────────────────────────────────────────────────────────
function renderTop10(top10) {
    const tbody = document.getElementById('table-top10-body');
    tbody.innerHTML = '';

    if (!top10.length) {
        tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state">
            <i class="fas fa-check-circle" style="color:var(--success-color)"></i>
            Aucun étudiant avec solde restant — excellent taux de recouvrement !
        </div></td></tr>`;
        return;
    }

    top10.forEach((s, i) => {
        const activite = s.derniere_activite
            ? new Date(s.derniere_activite).toLocaleDateString('fr-FR')
            : '<span style="color:#666">Jamais</span>';

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><span class="rank">#${i + 1}</span> ${escHtml(s.name)}</td>
            <td style="font-size:12px;color:#aaa">${escHtml(s.class_name)}</td>
            <td style="text-align:right">${fmtN(s.total_du)} FCFA</td>
            <td style="text-align:right">${fmtN(s.total_paye)} FCFA</td>
            <td style="text-align:right;color:var(--danger-color);font-weight:700">${fmtN(s.restant)} FCFA</td>
            <td style="text-align:center;font-size:13px">${activite}</td>
            <td style="text-align:center">
                <a href="payment_dashboard.php?student=${encodeURIComponent(s.id)}" class="btn-dossier" target="_blank">
                    <i class="fas fa-folder-open"></i> Dossier
                </a>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

// ── Évolution taux mensuel ────────────────────────────────────────────────────
function renderEvolution(evolution) {
    if (chartEvolution) chartEvolution.destroy();
    const ctx = document.getElementById('chart-evolution').getContext('2d');
    chartEvolution = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: evolution.labels,
            datasets: [{
                label: 'Taux de recouvrement (%)',
                data: evolution.taux,
                backgroundColor: evolution.taux.map(t =>
                    t >= 80 ? 'rgba(46,204,113,0.8)' :
                    t >= 50 ? 'rgba(243,156,18,0.8)' :
                              'rgba(231,76,60,0.8)'
                ),
                borderRadius: 5,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.y}%` } }
            },
            scales: {
                x: { ticks: { color: '#aaa' }, grid: { color: 'rgba(255,255,255,0.07)' } },
                y: {
                    min: 0, max: 100,
                    ticks: { color: '#aaa', callback: v => v + '%' },
                    grid: { color: 'rgba(255,255,255,0.07)' }
                }
            }
        }
    });
}

// ── Sécurité XSS ─────────────────────────────────────────────────────────────
function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    loadData();
    document.getElementById('filtre-annee').addEventListener('change', () => {
        const annee = document.getElementById('filtre-annee').value;
        fetch('stats_recouvrement.php?action=save_annee_session&annee=' + encodeURIComponent(annee));
        loadData();
    });
    document.getElementById('filtre-classe').addEventListener('change', loadData);
});
</script>
</body>
</html>
