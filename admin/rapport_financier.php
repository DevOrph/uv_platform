<?php
session_start();
require_once '../includes/db_connect.php';

$conn->set_charset('utf8mb4');
$conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$institution  = INSTITUTION_ID;
$annee        = intval($_GET['annee'] ?? date('Y'));
$type_rapport = $_GET['type'] ?? 'complet'; // complet | journal | depenses | encaissements
$format       = $_GET['format'] ?? 'html';  // html | pdf | excel
$date_export  = date('d/m/Y à H:i');

// ── Exercice ──────────────────────────────────────────────────────────
$ex = $conn->query("SELECT * FROM exercices_comptables WHERE institution_id='$institution' AND annee=$annee LIMIT 1");
$exercice    = $ex ? $ex->fetch_assoc() : null;
$exercice_id = $exercice['id'] ?? 0;

// ── Toutes les années disponibles ─────────────────────────────────────
$all_annees = [];
$res = $conn->query("SELECT annee FROM exercices_comptables WHERE institution_id='$institution' ORDER BY annee DESC");
if ($res) while ($r = $res->fetch_assoc()) $all_annees[] = $r['annee'];

// ── Journal des écritures ──────────────────────────────────────────────
$ecritures = [];
if ($exercice_id) {
    $res = $conn->query("
        SELECT ec.date_ecriture, ec.numero_piece, ec.libelle,
               jc.code as journal_code, jc.libelle as journal_nom,
               ec.compte_debit, cc_d.libelle as lib_debit,
               ec.compte_credit, cc_c.libelle as lib_credit,
               ec.montant, ec.source_type
        FROM ecritures_comptables ec
        JOIN journaux_comptables jc ON ec.journal_id = jc.id
        LEFT JOIN comptes_comptables cc_d ON ec.compte_debit  = cc_d.code
        LEFT JOIN comptes_comptables cc_c ON ec.compte_credit = cc_c.code
        WHERE ec.exercice_id = $exercice_id
        ORDER BY ec.date_ecriture ASC, ec.id ASC
    ");
    if ($res) while ($r = $res->fetch_assoc()) $ecritures[] = $r;
}

// ── Paiements étudiants ───────────────────────────────────────────────
$pmt_etudiants = [];
$total_encaisse = 0;
$res = $conn->query("
    SELECT sp.payment_date, sp.receipt_number, sp.student_id,
           u.name as student_name,
           sp.payment_type, sp.payment_method, sp.amount_paid
    FROM student_payments sp
    LEFT JOIN users u ON sp.student_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
    WHERE sp.status='validated' AND YEAR(sp.payment_date)=$annee
    ORDER BY sp.payment_date ASC
");
if ($res) while ($r = $res->fetch_assoc()) {
    $pmt_etudiants[] = $r;
    $total_encaisse += floatval($r['amount_paid']);
}

// ── Paiements personnel ───────────────────────────────────────────────
$pmt_staff = [];
$total_staff = 0; $total_retenues = 0; $total_net = 0;
$res = $conn->query("
    SELECT sp.payment_date, sp.receipt_number,
           u.name as staff_name, u.role,
           spt.name as type_name, spt.category,
           sp.payment_method, sp.amount,
           COALESCE(rs.montant_retenue,0) as retenue,
           COALESCE(rs.montant_net, sp.amount) as net
    FROM staff_payments sp
    LEFT JOIN users u ON sp.staff_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
    LEFT JOIN staff_payment_types spt ON sp.payment_type_id = spt.id
    LEFT JOIN retenues_source rs ON rs.staff_payment_id = sp.id
    WHERE sp.status='processed' AND YEAR(sp.payment_date)=$annee
    ORDER BY sp.payment_date ASC
");
if ($res) while ($r = $res->fetch_assoc()) {
    $pmt_staff[] = $r;
    $total_staff += floatval($r['amount']);
    $total_retenues += floatval($r['retenue']);
    $total_net += floatval($r['net']);
}

// ── Dépenses opérationnelles ──────────────────────────────────────────
$depenses = [];
$total_depenses = 0;
$res = $conn->query("
    SELECT oe.expense_date, oe.expense_type, oe.category,
           oe.vendor_name, oe.invoice_number,
           oe.payment_method, oe.amount, oe.status
    FROM operational_expenses oe
    WHERE YEAR(oe.expense_date)=$annee
    ORDER BY oe.expense_date ASC
");
if ($res) while ($r = $res->fetch_assoc()) {
    $depenses[] = $r;
    if ($r['status'] === 'paid') $total_depenses += floatval($r['amount']);
}

// ── Retenues DGI ──────────────────────────────────────────────────────
$retenues_dgi = [];
$total_dgi = 0;
$res = $conn->query("
    SELECT rs.periode, rs.staff_name, rs.montant_brut,
           rs.taux_retenue, rs.montant_retenue, rs.montant_net, rs.statut
    FROM retenues_source rs
    WHERE YEAR(rs.created_at)=$annee
    ORDER BY rs.periode ASC, rs.staff_name ASC
");
if ($res) while ($r = $res->fetch_assoc()) {
    $retenues_dgi[] = $r;
    if ($r['statut'] === 'a_reverser') $total_dgi += floatval($r['montant_retenue']);
}

// ── Honoraires cours (versements_cours) ──────────────────────────────
$versements_cours_list = [];
$total_honoraires_verse = 0;
$total_ret_cours = 0;
$res = $conn->query("
    SELECT vc.date_versement, vc.receipt_number, vc.montant AS montant_verse,
           vc.payment_method,
           COALESCE(u.name, pe.enseignant_id) AS enseignant_nom,
           pe.montant_total_brut, pe.montant_retenue,
           pe.montant_total_net, pe.nb_heures_total,
           pe.semestre, pe.annee_academique
    FROM versements_cours vc
    JOIN paiements_enseignant pe ON vc.paiement_id = pe.id
    LEFT JOIN users u ON CONVERT(pe.enseignant_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci
    WHERE YEAR(vc.date_versement) = $annee
    ORDER BY vc.date_versement ASC
");
if ($res) while ($r = $res->fetch_assoc()) {
    $versements_cours_list[] = $r;
    $total_honoraires_verse += floatval($r['montant_verse']);
}
$res2 = $conn->query("SELECT COALESCE(SUM(pe.montant_retenue),0) AS total FROM paiements_enseignant pe WHERE pe.statut != 'annule' AND pe.montant_retenue > 0 AND EXISTS (SELECT 1 FROM versements_cours vc WHERE vc.paiement_id = pe.id AND YEAR(vc.date_versement) = $annee)");
if ($res2) { $r2 = $res2->fetch_assoc(); $total_ret_cours = floatval($r2['total']); }

// ── États financiers ──────────────────────────────────────────────────
$total_produits = $total_charges = 0;
if ($exercice_id) {
    $r = $conn->query("SELECT COALESCE(SUM(CASE WHEN cc.type='produit' THEN ec.montant ELSE 0 END),0) as p, COALESCE(SUM(CASE WHEN cc2.type='charge' THEN ec.montant ELSE 0 END),0) as c FROM ecritures_comptables ec JOIN comptes_comptables cc ON ec.compte_credit=cc.code JOIN comptes_comptables cc2 ON ec.compte_debit=cc2.code WHERE ec.exercice_id=$exercice_id");
    if ($r) { $s=$r->fetch_assoc(); $total_produits=$s['p']; $total_charges=$s['c']; }
}
$resultat_net = $total_produits - $total_charges;
$total_sorties = $total_net + $total_depenses + $total_honoraires_verse;
$total_dgi_global = $total_dgi + $total_ret_cours;
$solde_tresorerie = $total_encaisse - $total_sorties;

$conn->close();

function nf($n) { return number_format(floatval($n),0,',',' '); }

$types_pmt = ['registration'=>"Frais d'inscription",'tuition'=>'Frais de scolarité','insurance'=>'Assurance','library'=>'Bibliothèque','practical'=>'TP','other'=>'Autre'];
$meth      = ['cash'=>'Espèces','bank_transfer'=>'Virement','mobile_money'=>'Mobile Money','check'=>'Chèque','other'=>'Autre'];
$cats_dep  = ['equipment'=>'Équipement','maintenance'=>'Maintenance','utilities'=>'Services','supplies'=>'Fournitures','services'=>'Services','other'=>'Autre'];
$roles     = ['teacher'=>'Enseignant','admin'=>'Administrateur'];

$titres = [
    'complet'         => 'Rapport Financier Complet',
    'journal'         => 'Journal des Écritures Comptables',
    'depenses'        => 'Rapport des Décaissements',
    'encaissements'   => 'Rapport des Encaissements',
];
$titre_doc = $titres[$type_rapport] ?? 'Rapport Financier';

// ── Si format=excel → rediriger vers export_excel avec paramètres ──────
if ($format === 'excel') {
    // On génère le Excel directement ici
    // (même logique que export_excel.php mais filtré selon type_rapport)
    // Pour simplifier : on redirige vers export_excel.php
    header("Location: export_excel.php?annee=$annee&type=$type_rapport");
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $titre_doc ?> — UV <?= $annee ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>
/* ── BASE ── */
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',sans-serif;font-size:11px;color:#1a1a2e;background:#f0f4f8;}

/* ── BARRE DE CONTRÔLE ── */
.toolbar{
    background:#051e34;padding:14px 30px;
    display:flex;align-items:center;gap:12px;flex-wrap:wrap;
    position:sticky;top:0;z-index:100;box-shadow:0 2px 8px rgba(0,0,0,.3);
}
.toolbar h1{color:#039be5;font-size:16px;flex:1;display:flex;align-items:center;gap:8px;}
.btn{
    padding:9px 16px;border:none;border-radius:6px;cursor:pointer;
    font-size:12px;font-weight:600;display:inline-flex;align-items:center;
    gap:7px;text-decoration:none;transition:opacity .2s;
}
.btn:hover{opacity:.85;}
.btn-print{background:#039be5;color:white;}
.btn-excel{background:#27ae60;color:white;}
.btn-back{background:rgba(255,255,255,.1);color:white;border:1px solid rgba(255,255,255,.2);}
.btn-type{background:rgba(255,255,255,.08);color:#ccc;border:1px solid rgba(255,255,255,.15);font-size:11px;padding:7px 12px;}
.btn-type.active{background:#039be5;color:white;border-color:#039be5;}
.separator{width:1px;height:30px;background:rgba(255,255,255,.15);}
.year-select{
    background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
    color:white;padding:8px 12px;border-radius:6px;font-size:12px;
}

/* ── DOCUMENT ── */
.document{max-width:960px;margin:20px auto;background:white;border-radius:6px;box-shadow:0 2px 20px rgba(0,0,0,.08);overflow:hidden;}

/* ── EN-TÊTE ── */
.doc-header{
    background:linear-gradient(135deg,#051e34 0%,#0c2d48 100%);
    color:white;padding:28px 40px;
    display:flex;justify-content:space-between;align-items:flex-start;
}
.doc-header-left h2{font-size:20px;font-weight:700;color:#039be5;margin-bottom:4px;}
.doc-header-left p{font-size:11px;color:#7fa8c4;margin-bottom:2px;}
.doc-header-right{text-align:right;font-size:11px;color:#7fa8c4;}
.ex-badge{background:rgba(3,155,229,.2);border:1px solid rgba(3,155,229,.4);color:#039be5;padding:4px 12px;border-radius:20px;font-weight:700;font-size:12px;display:inline-block;margin-bottom:6px;}
.ohada-badge{background:rgba(212,168,67,.15);border:1px solid rgba(212,168,67,.4);color:#d4a843;padding:2px 10px;border-radius:10px;font-size:10px;font-weight:700;display:inline-block;margin-top:4px;}

/* ── KPI RÉSUMÉ ── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));border-bottom:1px solid #e8ecf0;}
.kpi-card{padding:18px 22px;text-align:center;border-right:1px solid #e8ecf0;}
.kpi-card:last-child{border-right:none;}
.kpi-label{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:#6c757d;margin-bottom:6px;}
.kpi-value{font-size:20px;font-weight:700;margin-bottom:2px;}
.kpi-value.green{color:#27ae60;} .kpi-value.red{color:#e74c3c;}
.kpi-value.blue{color:#039be5;} .kpi-value.gold{color:#d4a843;}
.kpi-sub{font-size:10px;color:#6c757d;}

/* ── SECTION TITRE ── */
.section-title{
    background:#039be5;color:white;
    padding:10px 30px;font-size:12px;font-weight:700;
    text-transform:uppercase;letter-spacing:1px;
    display:flex;align-items:center;gap:8px;
}
.section-title.green{background:#27ae60;}
.section-title.red{background:#e74c3c;}
.section-title.gold{background:#d4a843;color:#1a1a2e;}
.section-title.dark{background:#051e34;}
.section-title.purple{background:#8e44ad;}
.section-title.teal{background:#1abc9c;}

/* ── TABLEAU ── */
.doc-table{width:100%;border-collapse:collapse;}
.doc-table th{
    background:#f8f9fa;border-bottom:2px solid #dee2e6;
    padding:9px 16px;text-align:left;font-size:10px;
    font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6c757d;
}
.doc-table td{padding:8px 16px;border-bottom:1px solid #f0f0f0;font-size:11px;}
.doc-table tr:hover td{background:#f8f9fa;}
.doc-table .mono{font-family:monospace;font-size:10px;}
.doc-table .amount{text-align:right;font-weight:600;font-family:monospace;}
.doc-table .amount.green{color:#27ae60;} .doc-table .amount.red{color:#e74c3c;}
.doc-table .amount.blue{color:#039be5;} .doc-table .amount.gold{color:#d4a843;}
.total-row td{background:#f0f4f8!important;font-weight:700!important;border-top:2px solid #dee2e6!important;padding:10px 16px!important;}
.alt-row td{background:#fafbfc;}

/* ── RÉSULTAT FINAL ── */
.result-box{
    margin:0;padding:18px 30px;
    display:flex;justify-content:space-between;align-items:center;
}
.result-box.green{background:#d5f5e3;border-top:3px solid #27ae60;}
.result-box.red{background:#fadbd8;border-top:3px solid #e74c3c;}
.result-label{font-size:14px;font-weight:700;}
.result-label.green{color:#1e8449;} .result-label.red{color:#922b21;}
.result-amount{font-size:20px;font-weight:700;font-family:monospace;}

/* ── PIED DE PAGE ── */
.doc-footer{
    background:#f8f9fa;border-top:1px solid #dee2e6;
    padding:16px 30px;display:flex;justify-content:space-between;
    align-items:center;font-size:10px;color:#6c757d;
}
.sig-zone{display:flex;gap:60px;}
.sig-box{text-align:center;}
.sig-line{width:120px;border-bottom:1px solid #aaa;margin:28px auto 6px;}

/* ── BADGE TYPE ── */
.tag{padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;}
.tag-especes{background:#d5f5e3;color:#1e8449;}
.tag-virement{background:#d6eaf8;color:#1a5276;}
.tag-mobile{background:#fef9e7;color:#9a7d0a;}
.tag-cheque{background:#f4ecf7;color:#6c3483;}

/* ── IMPRESSION ── */
@media print {
    body{background:white;font-size:10px;}
    .toolbar{display:none!important;}
    .document{max-width:100%;margin:0;box-shadow:none;border-radius:0;}
    .doc-table tr:hover td{background:transparent;}
    .alt-row td{background:#fafbfc;}
    @page{size:A4;margin:12mm 10mm;}
    .page-break{page-break-before:always;}
}
</style>
</head>
<body>

<!-- ── BARRE DE CONTRÔLE ── -->
<div class="toolbar">
    <h1><i class="fas fa-file-invoice-dollar"></i> Rapports Financiers — UV</h1>

    <!-- Sélecteur année -->
    <form method="GET" style="display:inline">
        <input type="hidden" name="type" value="<?= $type_rapport ?>">
        <input type="hidden" name="format" value="html">
        <select name="annee" class="year-select" onchange="this.form.submit()">
            <?php foreach($all_annees as $a): ?>
            <option value="<?= $a ?>" <?= $a==$annee?'selected':'' ?>><?= $a ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="separator"></div>

    <!-- Sélecteur type de rapport -->
    <a href="?annee=<?= $annee ?>&type=complet&format=html"       class="btn btn-type <?= $type_rapport==='complet'?'active':'' ?>"><i class="fas fa-th-list"></i> Complet</a>
    <a href="?annee=<?= $annee ?>&type=journal&format=html"       class="btn btn-type <?= $type_rapport==='journal'?'active':'' ?>"><i class="fas fa-book"></i> Journal OHADA</a>
    <a href="?annee=<?= $annee ?>&type=depenses&format=html"      class="btn btn-type <?= $type_rapport==='depenses'?'active':'' ?>"><i class="fas fa-arrow-up"></i> Décaissements</a>
    <a href="?annee=<?= $annee ?>&type=encaissements&format=html" class="btn btn-type <?= $type_rapport==='encaissements'?'active':'' ?>"><i class="fas fa-arrow-down"></i> Encaissements</a>

    <div class="separator"></div>

    <!-- Export -->
    <button class="btn btn-print" onclick="window.print()"><i class="fas fa-print"></i> PDF / Imprimer</button>
    <a href="export_excel.php?annee=<?= $annee ?>&type=<?= $type_rapport ?>" class="btn btn-excel" target="_blank"><i class="fas fa-file-excel"></i> Excel</a>
    <a href="comptabilite.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Retour</a>
</div>

<!-- ── DOCUMENT ── -->
<div class="document">

    <!-- EN-TÊTE -->
    <div class="doc-header">
        <div class="doc-header-left">
            <h2>Université Virtuelle — ISMM</h2>
            <p>Institut des Sciences
et des Métiers de la Mer</p>
            <p>Libreville, Gabon</p>
            <div class="ohada-badge">SYSCOHADA RÉVISÉ</div>
        </div>
        <div class="doc-header-right">
            <div class="ex-badge">Exercice <?= $annee ?></div>
            <p><?= $titre_doc ?></p>
            <p style="margin-top:6px;">Imprimé le <?= $date_export ?></p>
        </div>
    </div>

    <!-- KPI RÉSUMÉ (toujours visible) -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Total Encaissé</div>
            <div class="kpi-value green"><?= nf($total_encaisse) ?></div>
            <div class="kpi-sub">FCFA — <?= count($pmt_etudiants) ?> paiements</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Honoraires Cours</div>
            <div class="kpi-value" style="color:#1abc9c"><?= nf($total_honoraires_verse) ?></div>
            <div class="kpi-sub">FCFA versés — <?= count($versements_cours_list) ?> tranche(s)</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Total Décaissé</div>
            <div class="kpi-value red"><?= nf($total_sorties) ?></div>
            <div class="kpi-sub">FCFA — Cours + Personnel + Dépenses</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Retenues DGI</div>
            <div class="kpi-value gold"><?= nf($total_dgi_global) ?></div>
            <div class="kpi-sub">FCFA — IRPP 9.5% (cours + libre)</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Solde Trésorerie</div>
            <div class="kpi-value <?= $solde_tresorerie>=0?'blue':'red' ?>"><?= nf(abs($solde_tresorerie)) ?></div>
            <div class="kpi-sub"><?= $solde_tresorerie>=0?'Excédent':'Déficit' ?> FCFA</div>
        </div>
    </div>

    <?php if ($type_rapport === 'complet' || $type_rapport === 'encaissements'): ?>
    <!-- ══ SECTION 1 : ENCAISSEMENTS ══ -->
    <div class="section-title green"><i class="fas fa-arrow-circle-down"></i> Encaissements — Paiements Étudiants</div>
    <table class="doc-table">
        <thead><tr>
            <th>Date</th><th>N° Reçu</th><th>Étudiant</th>
            <th>Type</th><th>Méthode</th><th style="text-align:right">Montant</th>
        </tr></thead>
        <tbody>
        <?php if (empty($pmt_etudiants)): ?>
        <tr><td colspan="6" style="text-align:center;padding:20px;color:#aaa;">Aucun encaissement</td></tr>
        <?php else: foreach ($pmt_etudiants as $i=>$p): ?>
        <tr class="<?= $i%2?'alt-row':'' ?>">
            <td class="mono"><?= date('d/m/Y',strtotime($p['payment_date'])) ?></td>
            <td class="mono" style="color:#6c757d"><?= htmlspecialchars($p['receipt_number']??'') ?></td>
            <td><?= htmlspecialchars($p['student_name']??$p['student_id']??'') ?></td>
            <td><?= $types_pmt[$p['payment_type']]??$p['payment_type'] ?></td>
            <td><span class="tag tag-<?= $p['payment_method']==='cash'?'especes':($p['payment_method']==='bank_transfer'?'virement':($p['payment_method']==='mobile_money'?'mobile':'cheque')) ?>"><?= $meth[$p['payment_method']]??$p['payment_method'] ?></span></td>
            <td class="amount green"><?= nf($p['amount_paid']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
        <tr class="total-row">
            <td colspan="5"><strong>TOTAL ENCAISSEMENTS</strong> — <?= count($pmt_etudiants) ?> paiements</td>
            <td class="amount green"><?= nf($total_encaisse) ?> FCFA</td>
        </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <?php if ($type_rapport === 'complet' || $type_rapport === 'depenses'): ?>

    <?php if ($type_rapport === 'complet'): ?>
    <div class="page-break"></div>
    <?php endif; ?>

    <!-- ══ SECTION 2 : PAIEMENTS PERSONNEL ══ -->
    <div class="section-title red"><i class="fas fa-users"></i> Décaissements — Paiements Personnel</div>
    <table class="doc-table">
        <thead><tr>
            <th>Date</th><th>N° Reçu</th><th>Personnel</th><th>Fonction</th>
            <th>Type</th><th>Méthode</th>
            <th style="text-align:right">Brut</th>
            <th style="text-align:right">Retenue 9.5%</th>
            <th style="text-align:right">Net Versé</th>
        </tr></thead>
        <tbody>
        <?php if (empty($pmt_staff)): ?>
        <tr><td colspan="9" style="text-align:center;padding:20px;color:#aaa;">Aucun paiement personnel</td></tr>
        <?php else: foreach ($pmt_staff as $i=>$p): ?>
        <tr class="<?= $i%2?'alt-row':'' ?>">
            <td class="mono"><?= date('d/m/Y',strtotime($p['payment_date'])) ?></td>
            <td class="mono" style="color:#6c757d"><?= htmlspecialchars($p['receipt_number']??'') ?></td>
            <td><?= htmlspecialchars($p['staff_name']??'') ?></td>
            <td><span style="padding:2px 8px;background:<?= $p['role']==='teacher'?'#d5f5e3':'#d6eaf8' ?>;color:<?= $p['role']==='teacher'?'#1e8449':'#1a5276' ?>;border-radius:10px;font-size:10px;font-weight:700;"><?= $roles[$p['role']]??$p['role'] ?></span></td>
            <td><?= $p['type_name']??'' ?></td>
            <td><?= $meth[$p['payment_method']]??$p['payment_method'] ?></td>
            <td class="amount"><?= nf($p['amount']) ?></td>
            <td class="amount red"><?= floatval($p['retenue'])>0?nf($p['retenue']):'—' ?></td>
            <td class="amount green"><?= nf($p['net']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
        <tr class="total-row">
            <td colspan="6"><strong>TOTAL PERSONNEL</strong> — <?= count($pmt_staff) ?> paiements</td>
            <td class="amount"><?= nf($total_staff) ?> FCFA</td>
            <td class="amount red"><?= nf($total_retenues) ?> FCFA</td>
            <td class="amount green"><?= nf($total_net) ?> FCFA</td>
        </tr>
        </tfoot>
    </table>

    <!-- ══ SECTION 3 : HONORAIRES COURS ══ -->
    <div class="section-title teal"><i class="fas fa-chalkboard-teacher"></i> Décaissements — Honoraires Cours Versés</div>
    <table class="doc-table">
        <thead><tr>
            <th>Date</th><th>Enseignant</th><th>Période</th>
            <th style="text-align:center">Heures</th>
            <th style="text-align:right">Brut Engagement</th>
            <th style="text-align:right">Retenue 9.5%</th>
            <th style="text-align:right">Versé</th>
            <th>Méthode</th>
        </tr></thead>
        <tbody>
        <?php if (empty($versements_cours_list)): ?>
        <tr><td colspan="8" style="text-align:center;padding:20px;color:#aaa;">Aucun honoraire cours versé pour <?= $annee ?></td></tr>
        <?php else: foreach ($versements_cours_list as $i=>$v): ?>
        <tr class="<?= $i%2?'alt-row':'' ?>">
            <td class="mono"><?= date('d/m/Y',strtotime($v['date_versement'])) ?></td>
            <td style="font-weight:600;"><?= htmlspecialchars($v['enseignant_nom']??'—') ?></td>
            <td style="font-size:10px;color:#6c757d;"><?= htmlspecialchars($v['semestre']??'') ?> <?= htmlspecialchars($v['annee_academique']??'') ?></td>
            <td style="text-align:center;"><?= floatval($v['nb_heures_total']??0)>0?number_format(floatval($v['nb_heures_total']),1,',','').'h':'—' ?></td>
            <td class="amount"><?= nf($v['montant_total_brut']) ?></td>
            <td class="amount red"><?= floatval($v['montant_retenue']??0)>0?nf($v['montant_retenue']):'—' ?></td>
            <td class="amount" style="color:#1abc9c;font-weight:700;"><?= nf($v['montant_verse']) ?></td>
            <td><?= $meth[$v['payment_method']]??$v['payment_method'] ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
        <tr class="total-row">
            <td colspan="6"><strong>TOTAL HONORAIRES COURS</strong> — <?= count($versements_cours_list) ?> versement(s)</td>
            <td class="amount" style="color:#1abc9c;"><?= nf($total_honoraires_verse) ?> FCFA</td>
            <td></td>
        </tr>
        </tfoot>
    </table>

    <!-- ══ SECTION 4 : DÉPENSES OPÉRATIONNELLES ══ -->
    <div class="section-title red"><i class="fas fa-receipt"></i> Décaissements — Dépenses Opérationnelles</div>
    <table class="doc-table">
        <thead><tr>
            <th>Date</th><th>Type de Dépense</th><th>Catégorie</th>
            <th>Fournisseur</th><th>N° Facture</th><th>Méthode</th>
            <th style="text-align:right">Montant</th>
        </tr></thead>
        <tbody>
        <?php if (empty($depenses)): ?>
        <tr><td colspan="7" style="text-align:center;padding:20px;color:#aaa;">Aucune dépense</td></tr>
        <?php else: foreach ($depenses as $i=>$d): ?>
        <tr class="<?= $i%2?'alt-row':'' ?>">
            <td class="mono"><?= date('d/m/Y',strtotime($d['expense_date'])) ?></td>
            <td><?= htmlspecialchars($d['expense_type']) ?></td>
            <td><?= $cats_dep[$d['category']]??$d['category'] ?></td>
            <td><?= htmlspecialchars($d['vendor_name']??'N/A') ?></td>
            <td class="mono" style="color:#6c757d"><?= htmlspecialchars($d['invoice_number']??'N/A') ?></td>
            <td><?= $meth[$d['payment_method']]??$d['payment_method'] ?></td>
            <td class="amount red"><?= nf($d['amount']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
        <tr class="total-row">
            <td colspan="6"><strong>TOTAL DÉPENSES</strong> — <?= count($depenses) ?> dépenses</td>
            <td class="amount red"><?= nf($total_depenses) ?> FCFA</td>
        </tr>
        </tfoot>
    </table>

    <!-- ══ SECTION 4 : RETENUES DGI ══ -->
    <?php if (!empty($retenues_dgi)): ?>
    <div class="section-title gold"><i class="fas fa-landmark"></i> Retenues IRPP 9.5% — À Reverser à la DGI</div>
    <table class="doc-table">
        <thead><tr>
            <th>Période</th><th>Enseignant</th>
            <th style="text-align:right">Brut</th>
            <th style="text-align:center">Taux</th>
            <th style="text-align:right">Retenue</th>
            <th style="text-align:right">Net Versé</th>
            <th>Statut</th>
        </tr></thead>
        <tbody>
        <?php foreach ($retenues_dgi as $i=>$r): ?>
        <tr class="<?= $i%2?'alt-row':'' ?>">
            <td><?= htmlspecialchars($r['periode']) ?></td>
            <td><?= htmlspecialchars($r['staff_name']) ?></td>
            <td class="amount"><?= nf($r['montant_brut']) ?></td>
            <td style="text-align:center"><?= $r['taux_retenue'] ?>%</td>
            <td class="amount red"><?= nf($r['montant_retenue']) ?></td>
            <td class="amount green"><?= nf($r['montant_net']) ?></td>
            <td><span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:<?= $r['statut']==='reversee'?'#d5f5e3':'#fef9e7' ?>;color:<?= $r['statut']==='reversee'?'#1e8449':'#9a7d0a' ?>;"><?= $r['statut']==='reversee'?'Reversée':'À reverser' ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr class="total-row">
            <td colspan="4"><strong>TOTAL À REVERSER À LA DGI</strong></td>
            <td class="amount red"><?= nf($total_dgi) ?> FCFA</td>
            <td colspan="2"></td>
        </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- ══ BILAN DE TRÉSORERIE ══ -->
    <div class="section-title dark"><i class="fas fa-balance-scale"></i> Bilan de Trésorerie — <?= $annee ?></div>
    <table class="doc-table">
        <thead><tr><th>Libellé</th><th style="text-align:right">Montant (FCFA)</th><th>Note</th></tr></thead>
        <tbody>
        <tr><td style="color:#27ae60;font-weight:600"><i class="fas fa-plus-circle"></i> Total Encaissements</td><td class="amount green"><?= nf($total_encaisse) ?></td><td style="color:#6c757d;font-size:10px">Paiements étudiants validés</td></tr>
        <tr class="alt-row"><td style="color:#1abc9c;font-weight:600"><i class="fas fa-minus-circle"></i> Honoraires Cours (Versés)</td><td class="amount" style="color:#1abc9c;"><?= nf($total_honoraires_verse) ?></td><td style="color:#6c757d;font-size:10px">Paiements enseignants — versements_cours</td></tr>
        <tr><td style="color:#e74c3c;font-weight:600"><i class="fas fa-minus-circle"></i> Paiements Personnel Libre (Net)</td><td class="amount red"><?= nf($total_net) ?></td><td style="color:#6c757d;font-size:10px">Paiements libres — staff_payments</td></tr>
        <tr class="alt-row"><td style="color:#e74c3c;font-weight:600"><i class="fas fa-minus-circle"></i> Dépenses Opérationnelles</td><td class="amount red"><?= nf($total_depenses) ?></td><td style="color:#6c757d;font-size:10px">Achats et services</td></tr>
        <tr><td style="color:#d4a843;font-weight:600"><i class="fas fa-landmark"></i> Retenues DGI (cours + libre)</td><td class="amount gold"><?= nf($total_dgi_global) ?></td><td style="color:#6c757d;font-size:10px">IRPP 9.5% — honoraires + paiements libres</td></tr>
        </tbody>
        <tfoot>
        <tr class="total-row">
            <td><strong>SOLDE NET DE TRÉSORERIE</strong></td>
            <td class="amount <?= $solde_tresorerie>=0?'green':'red' ?>"><?= nf(abs($solde_tresorerie)) ?> FCFA</td>
            <td style="font-weight:700;color:<?= $solde_tresorerie>=0?'#27ae60':'#e74c3c' ?>"><?= $solde_tresorerie>=0?'✅ Excédent':'❌ Déficit' ?></td>
        </tr>
        </tfoot>
    </table>

    <?php endif; ?>

    <?php if ($type_rapport === 'complet' || $type_rapport === 'journal'): ?>

    <?php if ($type_rapport === 'complet'): ?>
    <div class="page-break"></div>
    <?php endif; ?>

    <!-- ══ JOURNAL DES ÉCRITURES OHADA ══ -->
    <div class="section-title purple"><i class="fas fa-book-open"></i> Journal des Écritures Comptables — SYSCOHADA</div>
    <table class="doc-table">
        <thead><tr>
            <th>Date</th><th>N° Pièce</th><th>Libellé</th><th>Journal</th>
            <th>Débit</th><th>Libellé Débit</th>
            <th>Crédit</th><th>Libellé Crédit</th>
            <th style="text-align:right">Montant</th>
        </tr></thead>
        <tbody>
        <?php if (empty($ecritures)): ?>
        <tr><td colspan="9" style="text-align:center;padding:20px;color:#aaa;">Aucune écriture — Synchronisez d'abord les paiements.</td></tr>
        <?php else:
            $total_j = 0;
            foreach ($ecritures as $i=>$e):
            $total_j += floatval($e['montant']);
        ?>
        <tr class="<?= $i%2?'alt-row':'' ?>">
            <td class="mono"><?= date('d/m/Y',strtotime($e['date_ecriture'])) ?></td>
            <td class="mono" style="color:#6c757d"><?= htmlspecialchars($e['numero_piece']??'') ?></td>
            <td><?= htmlspecialchars($e['libelle']) ?></td>
            <td><span style="padding:2px 7px;background:#e8f4fd;color:#039be5;border-radius:8px;font-size:10px;font-weight:700;"><?= $e['journal_code'] ?></span></td>
            <td class="mono" style="color:#039be5"><?= $e['compte_debit'] ?></td>
            <td style="font-size:10px;color:#6c757d"><?= htmlspecialchars(substr($e['lib_debit']??'',0,25)) ?></td>
            <td class="mono" style="color:#d4a843"><?= $e['compte_credit'] ?></td>
            <td style="font-size:10px;color:#6c757d"><?= htmlspecialchars(substr($e['lib_credit']??'',0,25)) ?></td>
            <td class="amount blue"><?= nf($e['montant']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <?php if (!empty($ecritures)): ?>
        <tfoot>
        <tr class="total-row">
            <td colspan="8"><strong>TOTAL JOURNAL</strong> — <?= count($ecritures) ?> écritures</td>
            <td class="amount blue"><?= nf($total_j) ?> FCFA</td>
        </tr>
        </tfoot>
        <?php endif; ?>
    </table>

    <?php endif; ?>

    <!-- PIED DE PAGE -->
    <div class="doc-footer">
        <div>
            <strong>Université Virtuelle — ISMM</strong> · Libreville, Gabon<br>
            Document généré le <?= $date_export ?> · Conforme SYSCOHADA Révisé<br>
            <em>Powered by Coding Enterprise</em>
        </div>
        <div class="sig-zone">
            <div class="sig-box">
                <div class="sig-line"></div>
                <div>Responsable Financier</div>
            </div>
            <div class="sig-box">
                <div class="sig-line"></div>
                <div>Direction Générale</div>
            </div>
        </div>
    </div>

</div><!-- /document -->

</body>
</html>