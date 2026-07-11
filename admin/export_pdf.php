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
$annee_filtre = intval($_GET['annee'] ?? date('Y'));
$type_export  = $_GET['type'] ?? 'bilan'; // bilan | resultat | complet

// ── Exercice ──────────────────────────────────────────────────────────
$ex = $conn->query("SELECT * FROM exercices_comptables WHERE institution_id='$institution' AND annee=$annee_filtre LIMIT 1");
$exercice    = $ex ? $ex->fetch_assoc() : null;
$exercice_id = $exercice['id'] ?? 0;

// ── Compte de résultat ────────────────────────────────────────────────
$total_produits = $total_charges = 0;
$detail_produits = $detail_charges = [];

if ($exercice_id) {
    // Produits par compte
    $res = $conn->query("
        SELECT cc.code, cc.libelle,
            COALESCE(SUM(ec.montant),0) AS montant
        FROM ecritures_comptables ec
        JOIN comptes_comptables cc ON ec.compte_credit = cc.code
        WHERE ec.exercice_id = $exercice_id AND cc.type = 'produit'
        GROUP BY cc.code, cc.libelle
        HAVING montant > 0
        ORDER BY cc.code
    ");
    if ($res) while ($r = $res->fetch_assoc()) {
        $detail_produits[] = $r;
        $total_produits += $r['montant'];
    }

    // Charges par compte
    $res = $conn->query("
        SELECT cc.code, cc.libelle,
            COALESCE(SUM(ec.montant),0) AS montant
        FROM ecritures_comptables ec
        JOIN comptes_comptables cc ON ec.compte_debit = cc.code
        WHERE ec.exercice_id = $exercice_id AND cc.type = 'charge'
        GROUP BY cc.code, cc.libelle
        HAVING montant > 0
        ORDER BY cc.code
    ");
    if ($res) while ($r = $res->fetch_assoc()) {
        $detail_charges[] = $r;
        $total_charges += $r['montant'];
    }
}
$resultat_net = $total_produits - $total_charges;

// ── Bilan ─────────────────────────────────────────────────────────────
$actif_total = $passif_total = 0;
$bilan_actif = $bilan_passif = [];

if ($exercice_id) {
    $res = $conn->query("
        SELECT cc.code, cc.libelle,
            COALESCE(SUM(CASE WHEN ec.compte_debit=cc.code  THEN ec.montant ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN ec.compte_credit=cc.code THEN ec.montant ELSE 0 END),0) AS solde
        FROM comptes_comptables cc
        LEFT JOIN ecritures_comptables ec
            ON (ec.compte_debit=cc.code OR ec.compte_credit=cc.code)
            AND ec.exercice_id=$exercice_id
        WHERE cc.type='actif' AND cc.is_active=1
        GROUP BY cc.code, cc.libelle
        HAVING solde != 0
        ORDER BY cc.code
    ");
    if ($res) while ($r = $res->fetch_assoc()) {
        $bilan_actif[] = $r;
        $actif_total += $r['solde'];
    }

    $res = $conn->query("
        SELECT cc.code, cc.libelle,
            COALESCE(SUM(CASE WHEN ec.compte_credit=cc.code THEN ec.montant ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN ec.compte_debit=cc.code  THEN ec.montant ELSE 0 END),0) AS solde
        FROM comptes_comptables cc
        LEFT JOIN ecritures_comptables ec
            ON (ec.compte_debit=cc.code OR ec.compte_credit=cc.code)
            AND ec.exercice_id=$exercice_id
        WHERE cc.type='passif' AND cc.is_active=1
        GROUP BY cc.code, cc.libelle
        HAVING solde != 0
        ORDER BY cc.code
    ");
    if ($res) while ($r = $res->fetch_assoc()) {
        $bilan_passif[] = $r;
        $passif_total += $r['solde'];
    }
}

// ── Retenues DGI du mois ──────────────────────────────────────────────
$retenues = [];
$total_retenues = 0;
$res = $conn->query("
    SELECT rs.*, u.name as staff_name_check
    FROM retenues_source rs
    LEFT JOIN users u ON rs.staff_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
    WHERE YEAR(rs.created_at) = $annee_filtre
    ORDER BY rs.created_at DESC
");
if ($res) while ($r = $res->fetch_assoc()) {
    $retenues[] = $r;
    if ($r['statut'] === 'a_reverser') $total_retenues += $r['montant_retenue'];
}

// ── Années disponibles pour le sélecteur ──────────────────────────────
$all_annees = [];
$res_annees = $conn->query("SELECT annee FROM exercices_comptables WHERE institution_id='$institution' ORDER BY annee DESC");
if ($res_annees) while ($a = $res_annees->fetch_assoc()) $all_annees[] = $a['annee'];

$conn->close();

function nf($n) { return number_format(floatval($n), 0, ',', ' '); }
$date_impression = date('d/m/Y à H:i');
$titre_doc = $type_export === 'resultat' ? 'Compte de Résultat' : ($type_export === 'bilan' ? 'Bilan' : 'États Financiers Complets');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title><?= $titre_doc ?> — UAS <?= $annee_filtre ?></title>
<style>
/* ── BASE ── */
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: 'Arial', sans-serif;
    font-size: 11px;
    color: #1a1a2e;
    background: #f5f7fa;
}

/* ── BOUTONS (masqués à l'impression) ── */
.no-print {
    background: #051e34;
    padding: 15px 30px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.no-print h1 {
    color: #039be5;
    font-size: 18px;
    flex: 1;
}
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: opacity .2s;
}
.btn:hover { opacity: .85; }
.btn-print  { background: #039be5; color: white; }
.btn-back   { background: rgba(255,255,255,.1); color: white; border: 1px solid rgba(255,255,255,.2); }
.btn-year   { background: rgba(255,255,255,.1); color: white; border: 1px solid rgba(255,255,255,.2); font-size: 12px; padding: 8px 12px; }
.btn-year.active { background: #039be5; }

/* ── DOCUMENT ── */
.document {
    max-width: 900px;
    margin: 20px auto;
    background: white;
    box-shadow: 0 2px 20px rgba(0,0,0,.1);
    border-radius: 4px;
    overflow: hidden;
}

/* ── EN-TÊTE ── */
.doc-header {
    background: linear-gradient(135deg, #051e34 0%, #0c2d48 100%);
    color: white;
    padding: 30px 40px;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}
.doc-header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: #039be5;
    margin-bottom: 4px;
}
.doc-header-left p {
    font-size: 12px;
    color: #7fa8c4;
    margin-bottom: 2px;
}
.doc-header-right {
    text-align: right;
    font-size: 11px;
    color: #7fa8c4;
}
.doc-header-right .exercice-badge {
    background: rgba(3,155,229,.2);
    border: 1px solid rgba(3,155,229,.4);
    color: #039be5;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 700;
    font-size: 13px;
    display: inline-block;
    margin-bottom: 8px;
}
.ohada-badge {
    background: rgba(212,168,67,.15);
    border: 1px solid rgba(212,168,67,.4);
    color: #d4a843;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
    display: inline-block;
    margin-top: 6px;
}

/* ── SECTION TITRE ── */
.section-title {
    background: #039be5;
    color: white;
    padding: 10px 40px;
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 0;
}
.section-title.charges  { background: #e74c3c; }
.section-title.passif   { background: #d4a843; color: #1a1a2e; }
.section-title.retenues { background: #9b59b6; }

/* ── TABLEAU ── */
.doc-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}
.doc-table th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    padding: 9px 20px;
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #6c757d;
}
.doc-table td {
    padding: 8px 20px;
    border-bottom: 1px solid #f0f0f0;
    font-size: 11px;
}
.doc-table tr:hover td { background: #f8f9fa; }
.doc-table .code { font-family: monospace; color: #6c757d; font-size: 10px; }
.doc-table .amount { text-align: right; font-weight: 600; font-family: monospace; }
.doc-table .amount.produit { color: #27ae60; }
.doc-table .amount.charge  { color: #e74c3c; }
.doc-table .amount.actif   { color: #2980b9; }
.doc-table .amount.passif  { color: #d4a843; }

/* ── LIGNES TOTAL ── */
.total-row td {
    background: #f8f9fa !important;
    font-weight: 700 !important;
    font-size: 12px !important;
    border-top: 2px solid #dee2e6 !important;
    padding: 12px 20px !important;
}
.resultat-row td {
    background: #051e34 !important;
    color: white !important;
    font-weight: 700 !important;
    font-size: 13px !important;
    padding: 14px 20px !important;
}
.resultat-row .amount { color: white !important; }

/* ── KPI CARDS ── */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
    border-bottom: 1px solid #dee2e6;
}
.kpi-card {
    padding: 20px 30px;
    text-align: center;
    border-right: 1px solid #dee2e6;
}
.kpi-card:last-child { border-right: none; }
.kpi-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #6c757d;
    margin-bottom: 6px;
}
.kpi-value {
    font-size: 24px;
    font-weight: 700;
    font-family: monospace;
    margin-bottom: 2px;
}
.kpi-value.green  { color: #27ae60; }
.kpi-value.red    { color: #e74c3c; }
.kpi-value.blue   { color: #2980b9; }
.kpi-sub { font-size: 10px; color: #6c757d; }

/* ── BILAN GRID ── */
.bilan-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
}
.bilan-col { border-right: 1px solid #dee2e6; }
.bilan-col:last-child { border-right: none; }

/* ── PIED DE PAGE ── */
.doc-footer {
    background: #f8f9fa;
    border-top: 1px solid #dee2e6;
    padding: 15px 40px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 10px;
    color: #6c757d;
}
.doc-footer .signature-zone {
    display: flex;
    gap: 60px;
}
.signature-box {
    text-align: center;
}
.signature-box .line {
    width: 120px;
    border-bottom: 1px solid #aaa;
    margin: 30px auto 6px;
}

/* ── PAGE BREAK ── */
.page-break { page-break-before: always; }

/* ── IMPRESSION ── */
@media print {
    body { background: white; font-size: 10px; }
    .no-print { display: none !important; }
    .document {
        max-width: 100%;
        margin: 0;
        box-shadow: none;
        border-radius: 0;
    }
    .doc-table tr:hover td { background: transparent; }
    @page {
        size: A4;
        margin: 15mm 12mm;
    }
}
</style>
</head>
<body>

<!-- BARRE DE CONTRÔLE -->
<div class="no-print">
    <h1>📄 Export États Financiers</h1>

    <!-- Sélecteur d'année -->
    <div style="display:flex;gap:6px;align-items:center;">
        <span style="color:#7fa8c4;font-size:12px;">Exercice :</span>
        <?php foreach($all_annees as $a): ?>
        <a href="?annee=<?= $a ?>&type=<?= $type_export ?>" class="btn btn-year <?= $a==$annee_filtre?'active':'' ?>">
            <?= $a ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Sélecteur de type -->
    <div style="display:flex;gap:6px;align-items:center;">
        <a href="?annee=<?= $annee_filtre ?>&type=complet"   class="btn btn-year <?= $type_export==='complet'  ?'active':'' ?>">Complet</a>
        <a href="?annee=<?= $annee_filtre ?>&type=resultat"  class="btn btn-year <?= $type_export==='resultat' ?'active':'' ?>">Résultat</a>
        <a href="?annee=<?= $annee_filtre ?>&type=bilan"     class="btn btn-year <?= $type_export==='bilan'    ?'active':'' ?>">Bilan</a>
    </div>

    <button class="btn btn-print" onclick="window.print()">🖨️ Imprimer / Enregistrer PDF</button>
    <a href="comptabilite.php" class="btn btn-back">← Retour</a>
</div>

<!-- DOCUMENT PRINCIPAL -->
<div class="document">

    <!-- EN-TÊTE -->
    <div class="doc-header">
        <div class="doc-header-left">
            <h2>Université Virtuelle — UAS</h2>
            <p>Université Africaine des Sciences</p>
            <p>Libreville, Gabon</p>
            <div class="ohada-badge">SYSCOHADA RÉVISÉ</div>
        </div>
        <div class="doc-header-right">
            <div class="exercice-badge">Exercice <?= $annee_filtre ?></div>
            <p><?= $titre_doc ?></p>
            <p style="margin-top:6px;">Imprimé le <?= $date_impression ?></p>
            <p>Par : <?= htmlspecialchars($_SESSION['user_id'] ?? 'Admin') ?></p>
        </div>
    </div>

    <?php if ($type_export === 'complet' || $type_export === 'resultat'): ?>
    <!-- ══════════════════════════════════════════════
         COMPTE DE RÉSULTAT
    ══════════════════════════════════════════════ -->

    <!-- KPI résumé -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Total Produits (Cl. 7)</div>
            <div class="kpi-value green"><?= nf($total_produits) ?></div>
            <div class="kpi-sub">FCFA</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Total Charges (Cl. 6)</div>
            <div class="kpi-value red"><?= nf($total_charges) ?></div>
            <div class="kpi-sub">FCFA</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Résultat Net</div>
            <div class="kpi-value <?= $resultat_net >= 0 ? 'green' : 'red' ?>"><?= nf(abs($resultat_net)) ?></div>
            <div class="kpi-sub"><?= $resultat_net >= 0 ? 'BÉNÉFICE' : 'DÉFICIT' ?> — FCFA</div>
        </div>
    </div>

    <!-- PRODUITS -->
    <div class="section-title">📈 Produits — Classe 7</div>
    <table class="doc-table">
        <thead>
            <tr>
                <th style="width:80px">Compte</th>
                <th>Libellé</th>
                <th style="text-align:right;width:160px">Montant (FCFA)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($detail_produits)): ?>
            <tr><td colspan="3" style="text-align:center;padding:20px;color:#aaa;">Aucun produit enregistré</td></tr>
            <?php else: foreach ($detail_produits as $p): ?>
            <tr>
                <td class="code"><?= $p['code'] ?></td>
                <td><?= htmlspecialchars($p['libelle']) ?></td>
                <td class="amount produit"><?= nf($p['montant']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="2"><strong>TOTAL PRODUITS</strong></td>
                <td class="amount produit"><?= nf($total_produits) ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- CHARGES -->
    <div class="section-title charges">📉 Charges — Classe 6</div>
    <table class="doc-table">
        <thead>
            <tr>
                <th style="width:80px">Compte</th>
                <th>Libellé</th>
                <th style="text-align:right;width:160px">Montant (FCFA)</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($detail_charges)): ?>
            <tr><td colspan="3" style="text-align:center;padding:20px;color:#aaa;">Aucune charge enregistrée</td></tr>
            <?php else: foreach ($detail_charges as $c): ?>
            <tr>
                <td class="code"><?= $c['code'] ?></td>
                <td><?= htmlspecialchars($c['libelle']) ?></td>
                <td class="amount charge"><?= nf($c['montant']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="2"><strong>TOTAL CHARGES</strong></td>
                <td class="amount charge"><?= nf($total_charges) ?></td>
            </tr>
            <tr class="resultat-row">
                <td colspan="2"><strong><?= $resultat_net >= 0 ? '✅ RÉSULTAT BÉNÉFICIAIRE' : '❌ RÉSULTAT DÉFICITAIRE' ?></strong></td>
                <td class="amount"><?= nf(abs($resultat_net)) ?> FCFA</td>
            </tr>
        </tfoot>
    </table>

    <!-- RETENUES DGI -->
    <?php if (!empty($retenues)): ?>
    <div class="section-title retenues">🏛️ Retenues IRPP 9.5% — À reverser à la DGI</div>
    <table class="doc-table">
        <thead>
            <tr>
                <th>Personnel</th>
                <th>Période</th>
                <th style="text-align:right">Brut</th>
                <th style="text-align:right">Retenue 9.5%</th>
                <th style="text-align:right">Net versé</th>
                <th style="text-align:center">Statut</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($retenues as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['staff_name']) ?></td>
                <td><?= $r['periode'] ?></td>
                <td class="amount"><?= nf($r['montant_brut']) ?></td>
                <td class="amount charge"><?= nf($r['montant_retenue']) ?></td>
                <td class="amount produit"><?= nf($r['montant_net']) ?></td>
                <td style="text-align:center">
                    <span style="padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;
                        background:<?= $r['statut']==='reversee'?'#27ae60':'#e74c3c' ?>;color:white;">
                        <?= $r['statut'] === 'reversee' ? 'Reversée' : 'À reverser' ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3"><strong>TOTAL RETENUES À REVERSER À LA DGI</strong></td>
                <td class="amount charge"><?= nf($total_retenues) ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <?php endif; ?>

    <?php if ($type_export === 'complet'): ?>
    <div class="page-break"></div>
    <?php endif; ?>

    <?php if ($type_export === 'complet' || $type_export === 'bilan'): ?>
    <!-- ══════════════════════════════════════════════
         BILAN
    ══════════════════════════════════════════════ -->
    <div class="doc-header" style="padding:20px 40px;">
        <div>
            <div style="font-size:16px;font-weight:700;color:#039be5;">Bilan au 31/12/<?= $annee_filtre ?></div>
            <div style="font-size:11px;color:#7fa8c4;margin-top:4px;">Université Virtuelle — UAS · SYSCOHADA Révisé</div>
        </div>
        <div style="text-align:right;font-size:11px;color:#7fa8c4;">
            <div>Total Actif : <strong style="color:#039be5;"><?= nf($actif_total) ?> FCFA</strong></div>
            <div>Total Passif : <strong style="color:#d4a843;"><?= nf($passif_total + $resultat_net) ?> FCFA</strong></div>
        </div>
    </div>

    <div class="bilan-grid">
        <!-- ACTIF -->
        <div class="bilan-col">
            <div class="section-title" style="font-size:12px;padding:8px 20px;">ACTIF</div>
            <table class="doc-table">
                <thead>
                    <tr>
                        <th style="width:60px">Cpte</th>
                        <th>Libellé</th>
                        <th style="text-align:right;width:120px">Solde</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bilan_actif)): ?>
                    <tr><td colspan="3" style="text-align:center;padding:20px;color:#aaa;">—</td></tr>
                    <?php else: foreach ($bilan_actif as $a): ?>
                    <tr>
                        <td class="code"><?= $a['code'] ?></td>
                        <td style="font-size:10px;"><?= htmlspecialchars($a['libelle']) ?></td>
                        <td class="amount actif"><?= nf($a['solde']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2"><strong>TOTAL ACTIF</strong></td>
                        <td class="amount actif"><?= nf($actif_total) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- PASSIF -->
        <div class="bilan-col">
            <div class="section-title passif" style="font-size:12px;padding:8px 20px;">PASSIF</div>
            <table class="doc-table">
                <thead>
                    <tr>
                        <th style="width:60px">Cpte</th>
                        <th>Libellé</th>
                        <th style="text-align:right;width:120px">Solde</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bilan_passif)): ?>
                    <tr><td colspan="3" style="text-align:center;padding:20px;color:#aaa;">—</td></tr>
                    <?php else: foreach ($bilan_passif as $p): ?>
                    <tr>
                        <td class="code"><?= $p['code'] ?></td>
                        <td style="font-size:10px;"><?= htmlspecialchars($p['libelle']) ?></td>
                        <td class="amount passif"><?= nf($p['solde']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    <?php if ($resultat_net != 0): ?>
                    <tr>
                        <td class="code">130</td>
                        <td style="font-size:10px;">Résultat net de l'exercice</td>
                        <td class="amount <?= $resultat_net >= 0 ? 'produit' : 'charge' ?>"><?= nf($resultat_net) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2"><strong>TOTAL PASSIF</strong></td>
                        <td class="amount passif"><?= nf($passif_total + $resultat_net) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- PIED DE PAGE -->
    <div class="doc-footer">
        <div>
            <strong>Université Virtuelle — UAS</strong> · Libreville, Gabon<br>
            Document généré le <?= $date_impression ?> · Conforme SYSCOHADA Révisé<br>
            <em>Powered by Coding Enterprise</em>
        </div>
        <div class="signature-zone">
            <div class="signature-box">
                <div class="line"></div>
                <div>Responsable Financier</div>
            </div>
            <div class="signature-box">
                <div class="line"></div>
                <div>Direction Générale</div>
            </div>
        </div>
    </div>

</div><!-- /document -->

</body>
</html>