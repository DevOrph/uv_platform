<?php
ob_start();
session_start();
require_once '../includes/db_connect.php';

$conn->set_charset('utf8mb4');
$conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$admin_id  = $_SESSION['user_id'];
$institution = INSTITUTION_ID;

// ── CSRF ──────────────────────────────────────────────────────────────
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token']))
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
$csrf_token = generateCSRFToken();

// ── AJAX : générer les écritures manquantes pour les paiements existants ──
if (isset($_GET['action']) && $_GET['action'] === 'sync_ecritures') {
    header('Content-Type: application/json');
    $synced = 0;
    $errors = 0;

    // Student payments
    $res = $conn->query("SELECT id FROM student_payments WHERE status='validated'");
    if ($res) while ($row = $res->fetch_assoc()) {
        $id = intval($row['id']);
        $check = $conn->query("SELECT id FROM ecritures_comptables WHERE source_type='student_payment' AND source_id=$id LIMIT 1");
        if ($check && $check->num_rows === 0) {
            try {
                $conn->query("CALL GenererEcritureStudentPayment($id)");
                // Vider les résultats multiples de la procédure
                while ($conn->more_results()) $conn->next_result();
                $synced++;
            } catch (Exception $e) { $errors++; }
        }
    }

    // Staff payments
    $res = $conn->query("SELECT id FROM staff_payments WHERE status='processed'");
    if ($res) while ($row = $res->fetch_assoc()) {
        $id = intval($row['id']);
        $check = $conn->query("SELECT id FROM ecritures_comptables WHERE source_type='staff_payment' AND source_id=$id LIMIT 1");
        if ($check && $check->num_rows === 0) {
            try {
                $conn->query("CALL GenererEcritureStaffPayment($id)");
                while ($conn->more_results()) $conn->next_result();
                $synced++;
            } catch (Exception $e) { $errors++; }
        }
    }

    // Expenses
    $res = $conn->query("SELECT id FROM operational_expenses WHERE status='paid'");
    if ($res) while ($row = $res->fetch_assoc()) {
        $id = intval($row['id']);
        $check = $conn->query("SELECT id FROM ecritures_comptables WHERE source_type='expense' AND source_id=$id LIMIT 1");
        if ($check && $check->num_rows === 0) {
            try {
                $conn->query("CALL GenererEcritureExpense($id)");
                while ($conn->more_results()) $conn->next_result();
                $synced++;
            } catch (Exception $e) { $errors++; }
        }
    }

    echo json_encode(['success' => true, 'synced' => $synced, 'errors' => $errors]);
    exit();
}

// ── AJAX : synchroniser versements_cours sans écriture ────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'sync_versements_cours') {
    header('Content-Type: application/json');
    $synced = 0;
    $errors = 0;
    $res = $conn->query("
        SELECT id FROM versements_cours vc
        WHERE vc.statut != 'annule'
          AND NOT EXISTS (
            SELECT 1 FROM ecritures_comptables ec
            WHERE ec.source_type = 'versement_cours'
              AND ec.source_id = vc.id
        )
    ");
    if ($res) while ($row = $res->fetch_assoc()) {
        $id = intval($row['id']);
        try {
            $conn->query("CALL GenererEcritureVersementCours($id)");
            while ($conn->more_results()) $conn->next_result();
            $synced++;
        } catch (Exception $e) { $errors++; }
    }
    echo json_encode(['success' => true, 'synced' => $synced, 'errors' => $errors]);
    exit();
}

// ── AJAX : journal paginé ─────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_journal') {
    header('Content-Type: application/json');
    $page     = max(1, intval($_GET['page'] ?? 1));
    $per_page = intval($_GET['per_page'] ?? ($_SESSION['journal_per_page'] ?? 10));
    $per_page = in_array($per_page, [10, 25, 50, 100]) ? $per_page : 10;
    $_SESSION['journal_per_page'] = $per_page;
    $ex_id    = intval($_GET['exercice_id'] ?? 0);
    $offset   = ($page - 1) * $per_page;
    $total    = 0;
    $rows     = [];
    if ($ex_id) {
        $r = $conn->query("SELECT COUNT(*) AS t FROM ecritures_comptables ec JOIN journaux_comptables jc ON jc.id = ec.journal_id WHERE ec.exercice_id = $ex_id");
        if ($r) $total = intval($r->fetch_assoc()['t']);
        $r = $conn->query("
            SELECT ec.id, ec.date_ecriture, ec.numero_piece, ec.libelle,
                   ec.compte_debit, ec.compte_credit, ec.montant,
                   ec.source_type, ec.statut, ec.motif_annulation,
                   jc.code AS journal_code
            FROM ecritures_comptables ec
            JOIN journaux_comptables jc ON jc.id = ec.journal_id
            WHERE ec.exercice_id = $ex_id
            ORDER BY ec.date_ecriture DESC, ec.id DESC
            LIMIT $per_page OFFSET $offset
        ");
        if ($r) while ($row = $r->fetch_assoc()) $rows[] = $row;
    }
    echo json_encode([
        'success'     => true,
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => $total > 0 ? (int)ceil($total / $per_page) : 1
    ]);
    exit();
}

// ── AJAX : données grand livre par compte ──────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_grand_livre' && isset($_GET['compte'])) {
    header('Content-Type: application/json');
    $compte = $conn->real_escape_string($_GET['compte']);
    $annee  = intval($_GET['annee'] ?? date('Y'));
    $res = $conn->query("
        SELECT date_ecriture, numero_piece, libelle, debit, credit
        FROM vue_grand_livre
        WHERE compte = '$compte' AND annee = $annee
          AND (institution_id = '$institution' OR institution_id IS NULL)
        ORDER BY date_ecriture ASC
    ");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode(['success' => true, 'rows' => $rows]);
    exit();
}

// ── POST : annuler écriture (AJAX) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['csrf_token'] ?? '') === $_SESSION['csrf_token']
    && ($_POST['action'] ?? '') === 'annuler_ecriture') {
    header('Content-Type: application/json');
    try {
        $ec_id = intval($_POST['ecriture_id']);
        $motif = trim($_POST['motif_annulation'] ?? '');
        if (!$ec_id) throw new Exception("ID d'écriture invalide.");
        if (!$motif) throw new Exception("Un motif d'annulation est obligatoire.");
        $chk = $conn->query("
            SELECT ec.id, ec.statut, ec.source_type, ex.statut AS ex_statut
            FROM ecritures_comptables ec
            JOIN exercices_comptables ex ON ec.exercice_id = ex.id
            WHERE ec.id = $ec_id
              AND (ec.institution_id = '$institution' OR ec.institution_id IS NULL)
            LIMIT 1");
        $ec_data = $chk ? $chk->fetch_assoc() : null;
        if (!$ec_data) throw new Exception("Écriture introuvable.");
        if ($ec_data['statut'] === 'annule') throw new Exception("Cette écriture est déjà annulée.");
        if ($ec_data['ex_statut'] === 'cloture') throw new Exception("Impossible d'annuler une écriture d'un exercice clôturé.");
        $motif_esc = $conn->real_escape_string($motif);
        $admin_esc = $conn->real_escape_string($admin_id);
        // old_value / new_value sont soumis à CHECK (json_valid(...))
        $old_json  = $conn->real_escape_string(json_encode(['statut' => 'valide']));
        $new_json  = $conn->real_escape_string(json_encode(['statut' => 'annule', 'motif' => $motif]));
        $conn->query("UPDATE ecritures_comptables
            SET statut='annule', annule_par='$admin_esc', annule_le=NOW(), motif_annulation='$motif_esc'
            WHERE id=$ec_id");
        $conn->query("INSERT INTO audit_log
            (action_type, entity_type, entity_id, description, old_value, new_value, performed_by)
            VALUES (
                'CANCEL', 'ecriture_comptable', $ec_id,
                'Annulation écriture #$ec_id : $motif_esc',
                '$old_json',
                '$new_json',
                '$admin_esc'
            )");
        $resp = ['success' => true];
        if (($ec_data['source_type'] ?? '') === 'student_payment') {
            $resp['warning'] = "Le paiement étudiant source reste validé dans le module paiements. Si nécessaire, annulez aussi le paiement depuis le tableau de bord paiements.";
        }
        echo json_encode($resp);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── POST : écriture manuelle ───────────────────────────────────────────
$message = $message_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['csrf_token'] ?? '') === $_SESSION['csrf_token']) {
    if (($_POST['action'] ?? '') === 'add_ecriture_manuelle') {
        try {
            $ex_id   = intval($_POST['exercice_id']);
            $jnl_id  = intval($_POST['journal_id']);
            $date    = trim($_POST['date_ecriture'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
                throw new Exception("Format de date invalide.");
            $d_obj = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d_obj || $d_obj->format('Y-m-d') !== $date)
                throw new Exception("Date invalide.");
            $date = $conn->real_escape_string($date);
            $piece   = $conn->real_escape_string($_POST['numero_piece'] ?? '');
            $lib     = $conn->real_escape_string($_POST['libelle']);
            $debit   = $conn->real_escape_string($_POST['compte_debit']);
            $credit  = $conn->real_escape_string($_POST['compte_credit']);
            $montant = floatval(str_replace(',', '.', $_POST['montant']));

            if (!$ex_id || !$jnl_id || !$date || !$lib || !$debit || !$credit || $montant <= 0)
                throw new Exception("Tous les champs obligatoires doivent être remplis.");

            if ($debit === $credit)
                throw new Exception("Le compte débit et le compte crédit ne peuvent pas être identiques.");

            // Valider que la date est dans la période de l'exercice
            $ex_check = $conn->query("SELECT date_debut, date_fin, statut FROM exercices_comptables WHERE id=$ex_id LIMIT 1");
            $ex_data  = $ex_check ? $ex_check->fetch_assoc() : null;
            if ($ex_data) {
                if ($ex_data['statut'] === 'cloture')
                    throw new Exception("Cet exercice est clôturé. Aucune nouvelle écriture n'est possible.");
                if ($date < $ex_data['date_debut'] || $date > $ex_data['date_fin'])
                    throw new Exception("La date doit être dans l'exercice sélectionné ({$ex_data['date_debut']} → {$ex_data['date_fin']}).");
            }

            $conn->query("INSERT INTO ecritures_comptables
                (exercice_id, journal_id, date_ecriture, numero_piece, libelle,
                 compte_debit, compte_credit, montant, source_type, institution_id, created_by)
                VALUES ($ex_id, $jnl_id, '$date', '$piece', '$lib',
                        '$debit', '$credit', $montant, 'manuel', '$institution', '$admin_id')");
            $_SESSION['compta_flash'] = [
                'message' => "Écriture enregistrée avec succès.",
                'type'    => 'success'
            ];
            error_log("PRG: about to redirect, ob_level=" . ob_get_level() . " headers_sent=" . (headers_sent($file, $line) ? "YES:$file:$line" : "NO"));
            ob_end_clean();
            header('Location: ' . $_SERVER['PHP_SELF']
                . '?annee='       . intval($_GET['annee'] ?? date('Y'))
                . '&institution=' . urlencode($institution)
                . '#journal', true, 303);
            exit;
        } catch (Exception $e) {
            $message = $e->getMessage();
            $message_type = 'error';
        }
    }
}

// ── DONNÉES ────────────────────────────────────────────────────────────
$annee_filtre = intval($_GET['annee'] ?? date('Y'));

if (!empty($_SESSION['compta_flash'])) {
    $message      = $_SESSION['compta_flash']['message'];
    $message_type = $_SESSION['compta_flash']['type'];
    unset($_SESSION['compta_flash']);
}

// Exercice courant
$ex_result = $conn->query("SELECT * FROM exercices_comptables WHERE institution_id='$institution' AND annee=$annee_filtre LIMIT 1");
$exercice  = $ex_result ? $ex_result->fetch_assoc() : null;
$exercice_id = $exercice['id'] ?? 0;

$no_exercice_message = '';
if (!$exercice || $exercice_id == 0) {
    $no_exercice_message = "Aucun exercice comptable trouvé pour l'année $annee_filtre. Veuillez créer un exercice dans la configuration comptable.";
}

// Tous les exercices pour filtre
$all_exercices = [];
$res = $conn->query("SELECT * FROM exercices_comptables WHERE institution_id='$institution' ORDER BY annee DESC");
if ($res) while ($r = $res->fetch_assoc()) $all_exercices[] = $r;

// Journaux
$journaux = [];
$res = $conn->query("SELECT * FROM journaux_comptables WHERE is_active=1 ORDER BY code");
if ($res) while ($r = $res->fetch_assoc()) $journaux[] = $r;

// Comptes (actifs + inactifs ayant des écritures)
$comptes = [];
$res = $conn->query("
    SELECT * FROM comptes_comptables
    WHERE is_active = 1
       OR code IN (
           SELECT DISTINCT compte_debit  FROM ecritures_comptables
           UNION
           SELECT DISTINCT compte_credit FROM ecritures_comptables
       )
    ORDER BY code
");
if ($res) while ($r = $res->fetch_assoc()) $comptes[] = $r;

// Stats tableau de bord
$total_produits = $total_charges = $total_ecritures = 0;
$solde_tresorerie = 0;

if ($exercice_id) {
    $r = $conn->query("
        SELECT
            COALESCE(SUM(CASE WHEN COALESCE(cc.type,'')='produit' THEN ec.montant ELSE 0 END),0) as produits,
            COALESCE(SUM(CASE WHEN COALESCE(cc2.type,'')='charge'  THEN ec.montant ELSE 0 END),0) as charges,
            COUNT(*) as nb
        FROM ecritures_comptables ec
        LEFT JOIN comptes_comptables cc  ON ec.compte_credit = cc.code
        LEFT JOIN comptes_comptables cc2 ON ec.compte_debit  = cc2.code
        WHERE ec.exercice_id = $exercice_id AND ec.statut = 'valide'
    ");
    if ($r) {
        $s = $r->fetch_assoc();
        $total_produits  = $s['produits'];
        $total_charges   = $s['charges'];
        $total_ecritures = $s['nb'];
    }
    $r2 = $conn->query("
        SELECT
            COALESCE(SUM(CASE WHEN compte_debit  IN ('511','512','521','522') THEN montant ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN compte_credit IN ('511','512','521','522') THEN montant ELSE 0 END),0) as tresorerie
        FROM ecritures_comptables WHERE exercice_id = $exercice_id AND statut = 'valide'
    ");
    if ($r2) $solde_tresorerie = $r2->fetch_assoc()['tresorerie'] ?? 0;
}

$resultat_net = $total_produits - $total_charges;

// Écritures récentes pour le widget tableau de bord
$ecritures = [];
if ($exercice_id) {
    $res = $conn->query("
        SELECT ec.*, jc.libelle as journal_nom, jc.code as journal_code
        FROM ecritures_comptables ec
        JOIN journaux_comptables jc ON ec.journal_id = jc.id
        WHERE ec.exercice_id = $exercice_id AND ec.statut = 'valide'
        ORDER BY ec.date_ecriture DESC, ec.id DESC
        LIMIT 5
    ");
    if ($res) while ($r = $res->fetch_assoc()) $ecritures[] = $r;
}

// Distribution par journal pour le graphique camembert (exercice complet)
$journal_distribution = [];
if ($exercice_id) {
    $res = $conn->query("
        SELECT jc.code, COUNT(*) AS cnt
        FROM ecritures_comptables ec
        JOIN journaux_comptables jc ON jc.id = ec.journal_id
        WHERE ec.exercice_id = $exercice_id AND ec.statut = 'valide'
        GROUP BY jc.code ORDER BY cnt DESC
    ");
    if ($res) while ($r = $res->fetch_assoc()) $journal_distribution[$r['code']] = intval($r['cnt']);
}

// Balance
$balance = [];
if ($exercice_id) {
    $res = $conn->query("SELECT * FROM vue_balance WHERE annee=$annee_filtre AND (institution_id = '$institution' OR institution_id IS NULL) ORDER BY code");
    if ($res) while ($r = $res->fetch_assoc()) $balance[] = $r;
}

// Données graphique mensuel (produits vs charges) — 1 requête au lieu de 12
$graph_data = array_fill(0, 12, ['produits' => 0, 'charges' => 0]);
if ($exercice_id) {
    $res_graph = $conn->query("
        SELECT
            MONTH(ec.date_ecriture) AS mois,
            COALESCE(SUM(CASE WHEN cc.type='produit'  THEN ec.montant ELSE 0 END), 0) AS produits,
            COALESCE(SUM(CASE WHEN cc2.type='charge'  THEN ec.montant ELSE 0 END), 0) AS charges
        FROM ecritures_comptables ec
        LEFT JOIN comptes_comptables cc  ON ec.compte_credit = cc.code
        LEFT JOIN comptes_comptables cc2 ON ec.compte_debit  = cc2.code
        WHERE ec.exercice_id = $exercice_id AND ec.statut = 'valide'
        GROUP BY MONTH(ec.date_ecriture)
        ORDER BY mois ASC
    ");
    if ($res_graph) while ($row = $res_graph->fetch_assoc()) {
        $idx = intval($row['mois']) - 1;
        $graph_data[$idx] = ['produits' => floatval($row['produits']), 'charges' => floatval($row['charges'])];
    }
}

// Bilan simplifié
$actif_total = $passif_total = 0;
$bilan_actif = $bilan_passif = [];
if ($exercice_id) {
    // Actif
    $res = $conn->query("
        SELECT cc.code, cc.libelle,
            COALESCE(SUM(CASE WHEN ec.compte_debit=cc.code  THEN ec.montant ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN ec.compte_credit=cc.code THEN ec.montant ELSE 0 END),0) AS solde
        FROM comptes_comptables cc
        LEFT JOIN ecritures_comptables ec ON (ec.compte_debit=cc.code OR ec.compte_credit=cc.code)
            AND ec.exercice_id=$exercice_id AND ec.statut='valide'
        WHERE cc.type='actif' AND cc.is_active=1
        GROUP BY cc.code, cc.libelle
        HAVING solde != 0
        ORDER BY cc.code
    ");
    if ($res) while ($r = $res->fetch_assoc()) {
        $bilan_actif[] = $r;
        $actif_total += $r['solde'];
    }
    // Passif
    $res = $conn->query("
        SELECT cc.code, cc.libelle,
            COALESCE(SUM(CASE WHEN ec.compte_credit=cc.code THEN ec.montant ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN ec.compte_debit=cc.code  THEN ec.montant ELSE 0 END),0) AS solde
        FROM comptes_comptables cc
        LEFT JOIN ecritures_comptables ec ON (ec.compte_debit=cc.code OR ec.compte_credit=cc.code)
            AND ec.exercice_id=$exercice_id AND ec.statut='valide'
        WHERE cc.type IN ('passif', 'bilan') AND cc.is_active=1
        GROUP BY cc.code, cc.libelle
        HAVING solde != 0
        ORDER BY cc.code
    ");
    if ($res) while ($r = $res->fetch_assoc()) {
        $bilan_passif[] = $r;
        $passif_total += $r['solde'];
    }
}

function nf($n) { return number_format(floatval($n), 0, ',', ' '); }

// Nombre de paiements anciens non synchronisés (rattrapage)
$count_unsynced = 0;
$r = $conn->query("
    SELECT
        (SELECT COUNT(*) FROM student_payments sp
         WHERE sp.status = 'validated'
           AND NOT EXISTS (SELECT 1 FROM ecritures_comptables ec
                           WHERE ec.source_type = 'student_payment' AND ec.source_id = sp.id)) +
        (SELECT COUNT(*) FROM staff_payments sp
         WHERE sp.status = 'processed'
           AND NOT EXISTS (SELECT 1 FROM ecritures_comptables ec
                           WHERE ec.source_type = 'staff_payment' AND ec.source_id = sp.id)) +
        (SELECT COUNT(*) FROM operational_expenses oe
         WHERE oe.status = 'paid'
           AND NOT EXISTS (SELECT 1 FROM ecritures_comptables ec
                           WHERE ec.source_type = 'expense' AND ec.source_id = oe.id))
    AS total
");
if ($r) $count_unsynced = intval($r->fetch_assoc()['total'] ?? 0);

// Versements cours non synchronisés
$count_unsynced_versements = 0;
$r = $conn->query("
    SELECT COUNT(*) AS total FROM versements_cours vc
    WHERE NOT EXISTS (
        SELECT 1 FROM ecritures_comptables ec
        WHERE ec.source_type = 'versement_cours'
          AND ec.source_id = vc.id
    )
");
if ($r) $count_unsynced_versements = intval($r->fetch_assoc()['total'] ?? 0);

// Écritures valides référençant un compte inconnu (débit ou crédit absent du plan comptable)
$count_comptes_inconnus = 0;
$r = $conn->query("
    SELECT COUNT(*) AS nb FROM ecritures_comptables ec
    WHERE ec.statut = 'valide'
    AND (
        NOT EXISTS (SELECT 1 FROM comptes_comptables cc WHERE cc.code = ec.compte_debit)
        OR NOT EXISTS (SELECT 1 FROM comptes_comptables cc WHERE cc.code = ec.compte_credit)
    )
");
if ($r) $count_comptes_inconnus = intval($r->fetch_assoc()['nb'] ?? 0);

// Écritures non générées faute d'exercice (table créée par migration 025)
$count_erreurs_comptables = 0;
$r = $conn->query("SELECT COUNT(*) AS nb FROM erreurs_comptables");
if ($r) $count_erreurs_comptables = intval($r->fetch_assoc()['nb'] ?? 0);

$initial_per_page_journal = intval($_SESSION['journal_per_page'] ?? 10);
$initial_per_page_journal = in_array($initial_per_page_journal, [10, 25, 50, 100]) ? $initial_per_page_journal : 10;

$conn->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Comptabilité OHADA — Université Virtuelle</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
:root {
    --primary-bg:    #051e34;
    --secondary-bg:  #0c2d48;
    --accent-color:  #039be5;
    --text-light:    #ffffff;
    --border-color:  rgba(255, 255, 255, 0.1);
    --success-color: #2ecc71;
    --warning-color: #f39c12;
    --danger-color:  #e74c3c;
    --info-color:    #3498db;
    --card-bg:       rgba(255, 255, 255, 0.1);
    --ohada-gold:    #d4a843;
    --bg:     #051e34;
    --bg2:    #0c2d48;
    --accent: #039be5;
    --green:  #2ecc71;
    --red:    #e74c3c;
    --yellow: #f39c12;
    --border: rgba(255, 255, 255, 0.1);
    --text:   #ffffff;
    --muted:  #cccccc;
    --card:   rgba(255, 255, 255, 0.1);
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
    color: var(--text-light);
    min-height: 100vh;
}

/* ── HEADER ── */
header {
    background: var(--secondary-bg);
    padding: 15px 0;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    border-bottom: 1px solid var(--border-color);
}
.hdr { max-width: 1400px; margin: 0 auto; padding: 0 20px; }
.hdr h1 {
    font-size: 28px; color: var(--accent-color); margin-bottom: 15px;
    text-align: center; display: flex; align-items: center; justify-content: center; gap: 10px;
}
nav ul { list-style: none; display: flex; justify-content: center; flex-wrap: wrap; gap: 5px; }
nav a {
    color: var(--text-light); text-decoration: none; padding: 10px 15px;
    border-radius: 5px; display: flex; align-items: center; gap: 8px;
    transition: all 0.3s ease; font-size: 14px;
}
nav a:hover, nav a.active { background: rgba(3,155,229,0.1); transform: translateY(-2px); }

/* ── CONTAINER ── */
.container{max-width:1400px;margin:0 auto;padding:28px 24px;}

/* ── ALERT ── */
.alert{padding:14px 18px;border-radius:8px;margin-bottom:20px;display:none;align-items:center;gap:10px;font-size:14px;}
.alert.show{display:flex;}
.alert-success{background:rgba(0,230,118,.08);border:1px solid var(--green);color:var(--green);}
.alert-error{background:rgba(255,23,68,.08);border:1px solid var(--red);color:var(--red);}

/* ── PAGE HEADER ── */
.page-hdr{
    display:flex;justify-content:space-between;align-items:center;
    flex-wrap:wrap;gap:16px;margin-bottom:28px;
    background:var(--card);border:1px solid var(--border);
    border-radius:14px;padding:22px 28px;
    backdrop-filter:blur(8px);
}
.page-hdr-left h2{font-family:'Space Mono',monospace;font-size:20px;color:var(--accent);display:flex;align-items:center;gap:10px;}
.page-hdr-left p{color:var(--muted);font-size:13px;margin-top:4px;}
.ohada-badge{
    background:linear-gradient(135deg,rgba(212,168,67,.2),rgba(212,168,67,.05));
    border:1px solid rgba(212,168,67,.4);
    color:var(--ohada-gold);
    padding:6px 14px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:1.5px;
}
.hdr-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}

/* ── FILTRE ANNÉE ── */
.year-filter select{
    background:var(--bg2);border:1px solid var(--border);
    color:var(--text);padding:9px 14px;border-radius:8px;font-size:14px;cursor:pointer;
}

/* ── BOUTONS ── */
.btn{
    padding:10px 18px;border:none;border-radius:8px;cursor:pointer;
    display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:600;
    transition:all .2s;text-decoration:none;font-family:'DM Sans',sans-serif;
}
.btn-primary{background:var(--accent);color:#fff;}
.btn-success{background:var(--green);color:#051e34;}
.btn-warning{background:var(--yellow);color:#051e34;}
.btn-danger{background:var(--red);color:#fff;}
.btn-ghost{background:rgba(3,155,229,.1);color:var(--accent);border:1px solid var(--border);}
.btn-sm{padding:7px 12px;font-size:12px;}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(0,0,0,.3);}

/* ── STATS GRID ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:28px;}
.stat-card{
    background:var(--card);border:1px solid var(--border);
    border-radius:12px;padding:22px;position:relative;overflow:hidden;
    backdrop-filter:blur(8px);transition:transform .2s;
}
.stat-card:hover{transform:translateY(-2px);}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.stat-card.green::before{background:linear-gradient(90deg,var(--green),#00c853);}
.stat-card.red::before{background:linear-gradient(90deg,var(--red),#d50000);}
.stat-card.blue::before{background:linear-gradient(90deg,var(--accent),var(--accent2));}
.stat-card.gold::before{background:linear-gradient(90deg,var(--ohada-gold),#f9a825);}
.stat-label{font-size:11px;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);margin-bottom:10px;}
.stat-val{font-family:'Space Mono',monospace;font-size:26px;font-weight:700;line-height:1;}
.stat-val.pos{color:var(--green);}
.stat-val.neg{color:var(--red);}
.stat-val.blue{color:var(--accent);}
.stat-val.gold{color:var(--ohada-gold);}
.stat-sub{font-size:12px;color:var(--muted);margin-top:6px;}
.stat-icon{position:absolute;right:20px;top:20px;font-size:28px;opacity:.15;}

/* ── TABS ── */
.tabs-wrap{margin-bottom:0;}
.tabs{display:flex;gap:0;background:var(--card);border:1px solid var(--border);border-bottom:none;border-radius:12px 12px 0 0;overflow-x:auto;}
.tab-btn{
    padding:14px 22px;background:transparent;border:none;color:var(--muted);
    cursor:pointer;font-size:13px;font-weight:600;white-space:nowrap;
    transition:all .2s;display:flex;align-items:center;gap:8px;font-family:'DM Sans',sans-serif;
    border-bottom:3px solid transparent;
}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent);background:rgba(3,155,229,.06);}
.tab-btn:hover:not(.active){color:var(--text);background:rgba(255,255,255,.04);}
.tab-content{
    background:var(--card);border:1px solid var(--border);
    border-radius:0 0 12px 12px;padding:26px;display:none;
    backdrop-filter:blur(8px);
}
.tab-content.active{display:block;}

/* ── TABLE ── */
.data-table{width:100%;border-collapse:collapse;font-size:13px;}
.data-table th{
    background:rgba(3,155,229,.08);color:var(--accent);
    padding:11px 14px;text-align:left;font-weight:600;
    font-size:11px;text-transform:uppercase;letter-spacing:.8px;
    border-bottom:1px solid var(--border);
}
.data-table td{padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.04);}
.data-table tr:hover td{background:rgba(3,155,229,.04);}
.mono{font-family:'Space Mono',monospace;font-size:12px;}
.tag{padding:3px 10px;border-radius:12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;}
.tag-caisse{background:rgba(0,230,118,.15);color:var(--green);}
.tag-banque{background:rgba(3,155,229,.15);color:var(--accent);}
.tag-ventes{background:rgba(212,168,67,.15);color:var(--ohada-gold);}
.tag-achats{background:rgba(255,23,68,.15);color:var(--red);}
.tag-salaires{background:rgba(156,39,176,.15);color:#ce93d8;}
.tag-od{background:rgba(255,255,255,.08);color:var(--muted);}
.tag-manuel{background:rgba(255,215,64,.1);color:var(--yellow);}
.tag-annule{background:rgba(231,76,60,.18);color:var(--red);border:1px solid rgba(231,76,60,.3);}
tr.ecriture-annulee>td{opacity:.4;}
tr.ecriture-annulee>td:last-child{opacity:1;}

/* ── CHART ── */
.chart-wrap{background:rgba(0,0,0,.2);border-radius:10px;padding:20px;margin-bottom:24px;}

/* ── BALANCE ── */
.balance-classe{
    background:rgba(3,155,229,.05);border:1px solid var(--border);
    border-radius:8px;margin-bottom:12px;overflow:hidden;
}
.balance-classe-hdr{
    padding:12px 18px;background:rgba(3,155,229,.1);
    font-family:'Space Mono',monospace;font-size:13px;font-weight:700;
    color:var(--accent);cursor:pointer;display:flex;justify-content:space-between;
}
.balance-inner{padding:0 10px;}

/* ── BILAN ── */
.bilan-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.bilan-col{background:rgba(0,0,0,.2);border-radius:10px;padding:20px;}
.bilan-col h3{font-family:'Space Mono',monospace;font-size:14px;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid var(--border);}
.bilan-col h3.actif{color:var(--accent);}
.bilan-col h3.passif{color:var(--ohada-gold);}
.bilan-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:13px;}
.bilan-row .code{font-family:'Space Mono',monospace;font-size:11px;color:var(--muted);margin-right:8px;}
.bilan-total{
    display:flex;justify-content:space-between;padding:12px 0;
    font-weight:700;font-family:'Space Mono',monospace;font-size:14px;
    margin-top:8px;border-top:2px solid var(--border);
}

/* ── MODAL ── */
.modal{display:none;position:fixed;z-index:200;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);}
.modal-box{
    background:var(--bg2);border:1px solid var(--border);
    border-radius:14px;width:90%;max-width:600px;
    margin:3% auto;padding:28px;max-height:90vh;overflow-y:auto;
}
.modal-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:22px;padding-bottom:14px;border-bottom:1px solid var(--border);}
.modal-hdr h3{font-family:'Space Mono',monospace;color:var(--accent);font-size:16px;}
.modal-close{color:var(--muted);font-size:22px;cursor:pointer;transition:color .2s;}
.modal-close:hover{color:var(--text);}

/* ── FORM ── */
.form-group{margin-bottom:18px;}
.form-group label{display:block;margin-bottom:7px;color:var(--muted);font-size:13px;font-weight:500;}
.form-control{
    width:100%;padding:11px 14px;border:1px solid var(--border);
    border-radius:8px;background:rgba(255,255,255,.05);color:var(--text);
    font-size:14px;font-family:'DM Sans',sans-serif;transition:border-color .2s;
}
.form-control:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(3,155,229,.1);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}

/* ── SYNC BANNER ── */
.sync-banner{
    background:linear-gradient(135deg,rgba(212,168,67,.1),rgba(3,155,229,.05));
    border:1px solid rgba(212,168,67,.25);border-radius:10px;
    padding:14px 20px;margin-bottom:20px;display:flex;
    justify-content:space-between;align-items:center;gap:12px;
    font-size:13px;color:var(--ohada-gold);
}

/* ── EMPTY ── */
.empty{text-align:center;padding:50px 20px;color:var(--muted);}
.empty i{font-size:48px;margin-bottom:16px;opacity:.3;}

/* ── JOURNAL TOOLBAR & PAGINATION ── */
.journal-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.journal-toolbar select{
    background:var(--bg2);border:1px solid var(--border);color:var(--text);
    padding:6px 10px;border-radius:8px;font-size:12px;cursor:pointer;
}
.journal-goto{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--muted);}
.journal-goto input{
    width:62px;padding:5px 8px;background:rgba(255,255,255,.05);
    border:1px solid var(--border);border-radius:6px;color:var(--text);
    font-size:12px;font-family:inherit;
}
.journal-goto input:focus{outline:none;border-color:var(--accent);}
.journal-pagi{display:flex;gap:4px;align-items:center;flex-wrap:wrap;justify-content:center;margin-top:14px;}
.journal-pagi .btn-page-cur{
    background:var(--accent)!important;color:#fff!important;min-width:34px;
}
.journal-pagi .btn-ghost[disabled]{opacity:.35;pointer-events:none;}

/* ── RESPONSIVE ── */
@media(max-width:768px){
    .stats-grid{grid-template-columns:1fr 1fr;}
    .bilan-grid{grid-template-columns:1fr;}
    .form-row{grid-template-columns:1fr;}
    .page-hdr{flex-direction:column;}
}
</style>
</head>
<body>

<header>
  <div class="hdr">
    <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle &nbsp;<span style="color:var(--ohada-gold);font-size:11px;background:rgba(212,168,67,0.12);border:1px solid rgba(212,168,67,0.3);padding:3px 8px;border-radius:4px;letter-spacing:1px;font-weight:700;">OHADA</span></h1>
    <nav><ul>
      <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
      <li><a href="user_management.php"><i class="fas fa-users"></i> Utilisateurs</a></li>
      <li><a href="course_management.php"><i class="fas fa-book"></i> Cours</a></li>
      <li><a href="payment_dashboard.php"><i class="fas fa-credit-card"></i> Paiements</a></li>
      <li><a href="payment_admin.php"><i class="fas fa-money-bill-wave"></i> Personnel</a></li>
      <li><a href="comptabilite.php" class="active"><i class="fas fa-calculator"></i> Comptabilité</a></li>
      <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
    </ul></nav>
  </div>
</header>

<div class="container">

  <?php if($message): ?>
  <div class="alert alert-<?= $message_type ?> show">
    <i class="fas fa-<?= $message_type==='success'?'check-circle':'exclamation-circle' ?>"></i>
    <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <?php if($no_exercice_message): ?>
  <div style="background:rgba(231,76,60,.12);border:1px solid var(--red);border-radius:10px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:10px;color:var(--red);font-size:14px;">
    <i class="fas fa-exclamation-triangle"></i>
    <?= htmlspecialchars($no_exercice_message) ?>
  </div>
  <?php endif; ?>

  <?php if($exercice && ($exercice['statut'] ?? '') === 'cloture'): ?>
  <div style="background:rgba(243,156,18,.12);border:1px solid var(--yellow);border-radius:10px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:10px;color:var(--yellow);font-size:14px;">
    <i class="fas fa-lock"></i>
    Cet exercice est clôturé. Aucune nouvelle écriture n'est possible.
  </div>
  <?php endif; ?>

  <?php if($count_comptes_inconnus > 0): ?>
  <div style="background:rgba(243,156,18,.12);border:1px solid var(--yellow);border-radius:10px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:10px;color:var(--yellow);font-size:14px;">
    <i class="fas fa-exclamation-triangle"></i>
    &#9888;&#65039; <?= $count_comptes_inconnus ?> écriture(s) référencent des comptes inconnus — vérifiez le plan comptable.
  </div>
  <?php endif; ?>

  <?php if($count_erreurs_comptables > 0): ?>
  <div style="background:rgba(231,76,60,.12);border:1px solid var(--red);border-radius:10px;padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:10px;color:var(--red);font-size:14px;">
    <i class="fas fa-exclamation-triangle"></i>
    &#9888; <?= $count_erreurs_comptables ?> écriture(s) n'ont pas pu être générées faute d'exercice ouvert — consultez la table <strong>erreurs_comptables</strong>.
  </div>
  <?php endif; ?>

  <!-- Page header -->
  <div class="page-hdr">
    <div class="page-hdr-left">
      <h2><i class="fas fa-calculator"></i> Comptabilité OHADA &nbsp;<span class="ohada-badge">SYSCOHADA RÉVISÉ</span></h2>
      <p>Institution : <strong><?= htmlspecialchars($institution) ?></strong> &nbsp;·&nbsp; Exercice <?= $annee_filtre ?> &nbsp;·&nbsp; <?= $total_ecritures ?> écriture(s)</p>
    </div>
    <div class="hdr-actions">
      <!-- Filtre année -->
      <form method="GET" class="year-filter">
        <select name="annee" onchange="this.form.submit()">
          <?php foreach($all_exercices as $ex): ?>
          <option value="<?= $ex['annee'] ?>" <?= $ex['annee']==$annee_filtre?'selected':'' ?>>
            <?= $ex['annee'] ?> — <?= $ex['statut'] ?>
          </option>
          <?php endforeach; ?>
        </select>
      </form>
      <button class="btn btn-ghost btn-sm" onclick="syncEcritures()">
        <i class="fas fa-sync-alt"></i>
        Anciens paiements (avant le 13/06/2026)
        <?php if ($count_unsynced > 0): ?>
        <span style="background:var(--yellow);color:#051e34;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700;margin-left:2px;"><?= $count_unsynced ?></span>
        <?php endif; ?>
      </button>
      <button class="btn btn-ghost btn-sm" onclick="syncVersementsCours()">
        <i class="fas fa-chalkboard-teacher"></i>
        Versements cours
        <?php if ($count_unsynced_versements > 0): ?>
        <span style="background:var(--yellow);color:#051e34;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700;margin-left:2px;"><?= $count_unsynced_versements ?></span>
        <?php endif; ?>
      </button>
      <a href="export_excel.php?annee=<?= $annee_filtre ?>" class="btn btn-success btn-sm">
          <i class="fas fa-file-excel"></i> Exporter Excel
      </a>
      <?php if($exercice && ($exercice['statut'] ?? '') === 'cloture'): ?>
      <button class="btn btn-primary btn-sm" disabled title="Exercice clôturé" style="opacity:.45;cursor:not-allowed;">
        <i class="fas fa-lock"></i> Écriture manuelle
      </button>
      <?php else: ?>
      <button class="btn btn-primary btn-sm" onclick="openModal('modalEcriture')">
        <i class="fas fa-plus"></i> Écriture manuelle
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Sync banner -->
  <div class="sync-banner" id="syncBanner" style="display:none;">
    <span><i class="fas fa-info-circle"></i> &nbsp;<span id="syncMsg"></span></span>
    <button class="btn btn-sm btn-ghost" onclick="document.getElementById('syncBanner').style.display='none'">✕</button>
  </div>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card green">
      <div class="stat-label">Total Produits</div>
      <div class="stat-val pos"><?= nf($total_produits) ?></div>
      <div class="stat-sub">FCFA — Classe 7</div>
      <i class="fas fa-arrow-trend-up stat-icon"></i>
    </div>
    <div class="stat-card red">
      <div class="stat-label">Total Charges</div>
      <div class="stat-val neg"><?= nf($total_charges) ?></div>
      <div class="stat-sub">FCFA — Classe 6</div>
      <i class="fas fa-arrow-trend-down stat-icon"></i>
    </div>
    <div class="stat-card <?= $resultat_net>=0?'green':'red' ?>">
      <div class="stat-label">Résultat Net</div>
      <div class="stat-val <?= $resultat_net>=0?'pos':'neg' ?>"><?= nf($resultat_net) ?></div>
      <div class="stat-sub">FCFA — Compte 130</div>
      <i class="fas fa-scale-balanced stat-icon"></i>
    </div>
    <div class="stat-card blue">
      <div class="stat-label">Trésorerie</div>
      <div class="stat-val blue"><?= nf($solde_tresorerie) ?></div>
      <div class="stat-sub">FCFA — Classe 5</div>
      <i class="fas fa-vault stat-icon"></i>
    </div>
    <div class="stat-card gold">
      <div class="stat-label">Écritures</div>
      <div class="stat-val gold"><?= $total_ecritures ?></div>
      <div class="stat-sub">Exercice <?= $annee_filtre ?></div>
      <i class="fas fa-file-invoice stat-icon"></i>
    </div>
  </div>

  <!-- Tabs -->
  <div class="tabs-wrap">
    <div class="tabs">
      <button class="tab-btn active" onclick="showTab('dashboard',this)"><i class="fas fa-chart-line"></i> Tableau de bord</button>
      <button class="tab-btn" onclick="showTab('journal',this)"><i class="fas fa-book-open"></i> Journal</button>
      <button class="tab-btn" onclick="showTab('grandlivre',this)"><i class="fas fa-list"></i> Grand livre</button>
      <button class="tab-btn" onclick="showTab('balance',this)"><i class="fas fa-balance-scale"></i> Balance</button>
      <button class="tab-btn" onclick="showTab('bilan',this)"><i class="fas fa-file-alt"></i> États financiers</button>
    </div>

    <!-- TAB : Tableau de bord -->
    <div class="tab-content active" id="tab-dashboard">
      <div class="chart-wrap">
        <h3 style="font-family:'Space Mono',monospace;font-size:13px;color:var(--muted);margin-bottom:16px;text-transform:uppercase;letter-spacing:1px;">
          Produits vs Charges — <?= $annee_filtre ?>
        </h3>
        <canvas id="chartMensuel" height="90"></canvas>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
        <div style="background:rgba(0,0,0,.2);border-radius:10px;padding:20px;">
          <h4 style="font-family:'Space Mono',monospace;font-size:12px;color:var(--muted);margin-bottom:14px;text-transform:uppercase;">Répartition par journal</h4>
          <canvas id="chartJournal" height="180"></canvas>
        </div>
        <div style="background:rgba(0,0,0,.2);border-radius:10px;padding:20px;">
          <h4 style="font-family:'Space Mono',monospace;font-size:12px;color:var(--muted);margin-bottom:14px;text-transform:uppercase;">Dernières écritures</h4>
          <?php if(empty($ecritures)): ?>
            <div class="empty"><i class="fas fa-inbox"></i><p>Aucune écriture.<br>Cliquez sur <strong>Synchroniser</strong>.</p></div>
          <?php else: foreach(array_slice($ecritures,0,5) as $e): ?>
          <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);font-size:12px;">
            <div>
              <div style="color:var(--text);font-weight:500;"><?= htmlspecialchars(substr($e['libelle'],0,35)) ?><?= strlen($e['libelle'])>35?'…':'' ?></div>
              <div style="color:var(--muted);font-size:11px;"><?= date('d/m/Y',strtotime($e['date_ecriture'])) ?> · <?= htmlspecialchars($e['journal_code']) ?></div>
            </div>
            <div style="font-family:'Space Mono',monospace;font-size:12px;color:var(--green);white-space:nowrap;"><?= nf($e['montant']) ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- TAB : Journal -->
    <div class="tab-content" id="tab-journal">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
        <h3 style="font-family:'Space Mono',monospace;font-size:14px;color:var(--accent);">Journal des écritures — <?= $annee_filtre ?></h3>
        <div style="display:flex;gap:8px;">
          <a href="rapport_financier.php?annee=<?= $annee_filtre ?>&type=complet" target="_blank" class="btn btn-warning btn-sm">
            <i class="fas fa-file-invoice-dollar"></i> Rapports
          </a>
          <?php if($exercice && ($exercice['statut'] ?? '') === 'cloture'): ?>
          <button class="btn btn-primary btn-sm" disabled title="Exercice clôturé" style="opacity:.45;cursor:not-allowed;">
            <i class="fas fa-lock"></i> Nouvelle écriture
          </button>
          <?php else: ?>
          <button class="btn btn-primary btn-sm" onclick="openModal('modalEcriture')">
            <i class="fas fa-plus"></i> Nouvelle écriture
          </button>
          <?php endif; ?>
        </div>
      </div>
      <!-- Toolbar journal -->
      <div class="journal-toolbar">
        <span id="journalInfo" style="font-size:12px;color:var(--muted);">Chargement…</span>
        <div style="margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
          <select id="journalPerPage" onchange="changePerPage(this.value)">
            <option value="10"  <?= $initial_per_page_journal===10  ?'selected':'' ?>>10 par page</option>
            <option value="25"  <?= $initial_per_page_journal===25  ?'selected':'' ?>>25 par page</option>
            <option value="50"  <?= $initial_per_page_journal===50  ?'selected':'' ?>>50 par page</option>
            <option value="100" <?= $initial_per_page_journal===100 ?'selected':'' ?>>100 par page</option>
          </select>
          <div class="journal-goto">
            <span>Aller à</span>
            <input type="number" id="journalGotoPage" min="1" placeholder="Page"
                   onkeydown="if(event.key==='Enter')gotoPage()">
            <button class="btn btn-ghost btn-sm" onclick="gotoPage()" style="padding:5px 10px;">OK</button>
          </div>
        </div>
      </div>
      <!-- Contenu journal (chargé par AJAX) -->
      <div id="journalContent" style="overflow-x:auto;">
        <div class="empty"><i class="fas fa-spinner fa-spin"></i><p>Chargement du journal…</p></div>
      </div>
      <div id="journalPagination" class="journal-pagi"></div>
    </div>

    <!-- TAB : Grand livre -->
    <div class="tab-content" id="tab-grandlivre">
      <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;align-items:center;">
        <select id="glCompte" class="form-control" style="max-width:320px;" onchange="loadGrandLivre()">
          <option value="">— Sélectionner un compte —</option>
          <?php foreach($comptes as $c): ?>
          <option value="<?= $c['code'] ?>"><?= $c['code'] ?> — <?= htmlspecialchars($c['libelle']) ?><?= !$c['is_active'] ? ' (désactivé)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <span style="color:var(--muted);font-size:13px;">Exercice <?= $annee_filtre ?></span>
      </div>
      <div id="glContent">
        <div class="empty"><i class="fas fa-search"></i><p>Sélectionnez un compte pour afficher ses mouvements.</p></div>
      </div>
    </div>

    <!-- TAB : Balance -->
    <div class="tab-content" id="tab-balance">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
        <h3 style="font-family:'Space Mono',monospace;font-size:14px;color:var(--accent);">Balance générale — <?= $annee_filtre ?></h3>
        <button class="btn btn-ghost btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Imprimer</button>
      </div>
      <?php if(empty($balance)): ?>
        <div class="empty"><i class="fas fa-balance-scale"></i><p>Aucune donnée. Synchronisez d'abord les écritures.</p></div>
      <?php else:
        $classes_balance = [];
        $grand_total_debit = 0;
        $grand_total_credit = 0;
        foreach($balance as $b) {
            $classes_balance[$b['classe']][] = $b;
            $grand_total_debit  += floatval($b['total_debit']);
            $grand_total_credit += floatval($b['total_credit']);
        }
        $labels_classes = [1=>'Ressources durables',2=>'Actif immobilisé',3=>'Stocks',4=>'Tiers',5=>'Trésorerie',6=>'Charges',7=>'Produits'];
      ?>
      <?php foreach($classes_balance as $cl => $rows): ?>
      <div class="balance-classe">
        <div class="balance-classe-hdr" onclick="toggleBalanceClasse('bc<?= $cl ?>')">
          <span>Classe <?= $cl ?> — <?= $labels_classes[$cl]??'' ?></span>
          <i class="fas fa-chevron-down"></i>
        </div>
        <div class="balance-inner" id="bc<?= $cl ?>">
          <table class="data-table">
            <thead><tr>
              <th>Code</th><th>Libellé</th>
              <th style="text-align:right">Débit</th>
              <th style="text-align:right">Crédit</th>
              <th style="text-align:right">Solde Débiteur</th>
              <th style="text-align:right">Solde Créditeur</th>
            </tr></thead>
            <tbody>
            <?php foreach($rows as $b): ?>
            <tr>
              <td class="mono"><?= $b['code'] ?></td>
              <td><?= htmlspecialchars($b['libelle']) ?></td>
              <td class="mono" style="text-align:right;color:var(--accent)"><?= nf($b['total_debit']) ?></td>
              <td class="mono" style="text-align:right;color:var(--ohada-gold)"><?= nf($b['total_credit']) ?></td>
              <td class="mono" style="text-align:right;color:<?= $b['solde_debiteur']>0?'var(--green)':'var(--muted)' ?>"><?= $b['solde_debiteur']>0?nf($b['solde_debiteur']):'—' ?></td>
              <td class="mono" style="text-align:right;color:<?= $b['solde_crediteur']>0?'var(--yellow)':'var(--muted)' ?>"><?= $b['solde_crediteur']>0?nf($b['solde_crediteur']):'—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Total général balance -->
      <?php $ecart_balance = abs($grand_total_debit - $grand_total_credit); ?>
      <table class="data-table" style="margin-top:12px;border-top:2px solid var(--border);">
        <tbody>
          <tr class="balance-total" style="font-weight:700;background:rgba(3,155,229,.08);">
            <td colspan="2" style="font-family:'Space Mono',monospace;font-size:13px;color:var(--accent);">TOTAL GÉNÉRAL</td>
            <td class="mono" style="text-align:right;color:var(--accent);"><?= nf($grand_total_debit) ?> FCFA</td>
            <td class="mono" style="text-align:right;color:var(--ohada-gold);"><?= nf($grand_total_credit) ?> FCFA</td>
            <td class="mono" style="text-align:right;color:var(--muted);">—</td>
            <td class="mono" style="text-align:right;color:var(--muted);">—</td>
          </tr>
        </tbody>
      </table>
      <?php if($ecart_balance > 0.01): ?>
      <div style="background:rgba(231,76,60,.15);border:1px solid var(--red);border-radius:8px;padding:14px 18px;margin-top:12px;color:var(--red);font-size:13px;">
        ⚠️ DÉSÉQUILIBRE : la balance n'est pas équilibrée. Écart : <?= nf($ecart_balance) ?> FCFA. Vérifiez les écritures.
      </div>
      <?php else: ?>
      <div style="background:rgba(46,204,113,.1);border:1px solid var(--green);border-radius:8px;padding:10px 16px;margin-top:12px;color:var(--green);font-size:13px;font-weight:600;">
        ✓ Balance équilibrée
      </div>
      <?php endif; ?>

      <?php endif; ?>
    </div>

    <!-- TAB : États financiers -->
    <div class="tab-content" id="tab-bilan">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
        <div>
          <h3 style="font-family:'Space Mono',monospace;font-size:14px;color:var(--accent);">Bilan simplifié — <?= $annee_filtre ?></h3>
          <p style="font-size:12px;color:var(--muted);margin-top:4px;">Conforme SYSCOHADA Révisé</p>
        </div>
        <div style="display:flex;gap:8px;">
          <button class="btn btn-ghost btn-sm" onclick="window.print()">
            <i class="fas fa-print"></i> Imprimer
          </button>
          <a href="export_pdf.php?annee=<?= $annee_filtre ?>&type=complet" target="_blank" class="btn btn-primary btn-sm">
            <i class="fas fa-file-pdf"></i> Exporter PDF
          </a>
        </div>
      </div>

      <!-- Compte de résultat simplifié -->
      <div style="background:rgba(0,0,0,.2);border-radius:10px;padding:20px;margin-bottom:20px;">
        <h4 style="font-family:'Space Mono',monospace;font-size:13px;color:var(--muted);margin-bottom:14px;text-transform:uppercase;">Compte de résultat</h4>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;text-align:center;">
          <div style="padding:16px;background:rgba(0,230,118,.08);border-radius:8px;border:1px solid rgba(0,230,118,.15);">
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Produits (Cl.7)</div>
            <div style="font-family:'Space Mono',monospace;font-size:20px;color:var(--green)"><?= nf($total_produits) ?></div>
            <div style="font-size:11px;color:var(--muted);">FCFA</div>
          </div>
          <div style="padding:16px;background:rgba(255,23,68,.08);border-radius:8px;border:1px solid rgba(255,23,68,.15);">
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Charges (Cl.6)</div>
            <div style="font-family:'Space Mono',monospace;font-size:20px;color:var(--red)"><?= nf($total_charges) ?></div>
            <div style="font-size:11px;color:var(--muted);">FCFA</div>
          </div>
          <div style="padding:16px;background:rgba(3,155,229,.08);border-radius:8px;border:1px solid rgba(3,155,229,.2);">
            <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Résultat net</div>
            <div style="font-family:'Space Mono',monospace;font-size:20px;color:<?= $resultat_net>=0?'var(--green)':'var(--red)' ?>"><?= nf($resultat_net) ?></div>
            <div style="font-size:11px;color:var(--muted);"><?= $resultat_net>=0?'Bénéfice':'Déficit' ?></div>
          </div>
        </div>
      </div>

      <!-- Bilan actif/passif -->
      <?php if(empty($bilan_actif) && empty($bilan_passif)): ?>
        <div class="empty"><i class="fas fa-file-alt"></i><p>Aucune donnée. Synchronisez d'abord les écritures.</p></div>
      <?php else: ?>
      <div class="bilan-grid">
        <div class="bilan-col">
          <h3 class="actif"><i class="fas fa-plus-circle"></i> ACTIF</h3>
          <?php foreach($bilan_actif as $row): ?>
          <div class="bilan-row">
            <div><span class="code"><?= $row['code'] ?></span><?= htmlspecialchars($row['libelle']) ?></div>
            <div class="mono" style="color:var(--accent)"><?= nf($row['solde']) ?></div>
          </div>
          <?php endforeach; ?>
          <div class="bilan-total">
            <span style="color:var(--accent)">TOTAL ACTIF</span>
            <span style="color:var(--accent)"><?= nf($actif_total) ?> FCFA</span>
          </div>
        </div>
        <div class="bilan-col">
          <h3 class="passif"><i class="fas fa-minus-circle"></i> PASSIF</h3>
          <?php foreach($bilan_passif as $row): ?>
          <div class="bilan-row">
            <div><span class="code"><?= $row['code'] ?></span><?= htmlspecialchars($row['libelle']) ?></div>
            <div class="mono" style="color:var(--ohada-gold)"><?= nf($row['solde']) ?></div>
          </div>
          <?php endforeach; ?>
          <!-- Résultat net dans le passif -->
          <?php if($resultat_net != 0): ?>
          <div class="bilan-row">
            <div><span class="code">130</span>Résultat net de l'exercice</div>
            <div class="mono" style="color:<?= $resultat_net>=0?'var(--green)':'var(--red)' ?>"><?= nf($resultat_net) ?></div>
          </div>
          <?php endif; ?>
          <div class="bilan-total">
            <span style="color:var(--ohada-gold)">TOTAL PASSIF</span>
            <span style="color:var(--ohada-gold)"><?= nf($passif_total + $resultat_net) ?> FCFA</span>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /tabs -->
</div><!-- /container -->

<!-- Modal : Écriture manuelle -->
<div class="modal" id="modalEcriture">
  <div class="modal-box">
    <div class="modal-hdr">
      <h3><i class="fas fa-pen"></i> Nouvelle écriture manuelle</h3>
      <span class="modal-close" onclick="closeModal('modalEcriture')">&times;</span>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
      <input type="hidden" name="action" value="add_ecriture_manuelle">
      <div class="form-row">
        <div class="form-group">
          <label>Exercice *</label>
          <select name="exercice_id" class="form-control" required>
            <?php foreach($all_exercices as $ex): ?>
            <option value="<?= $ex['id'] ?>" <?= $ex['annee']==$annee_filtre?'selected':'' ?>>
              <?= $ex['annee'] ?> — <?= $ex['statut'] ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Journal *</label>
          <select name="journal_id" class="form-control" required>
            <?php foreach($journaux as $j): ?>
            <option value="<?= $j['id'] ?>"><?= $j['code'] ?> — <?= htmlspecialchars($j['libelle']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="date_ecriture" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
          <label>N° Pièce</label>
          <input type="text" name="numero_piece" class="form-control" placeholder="ex: FAC-2026-001">
        </div>
      </div>
      <div class="form-group">
        <label>Libellé *</label>
        <input type="text" name="libelle" class="form-control" placeholder="Description de l'opération" required>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Compte Débit *</label>
          <select name="compte_debit" class="form-control" required>
            <option value="">— Choisir —</option>
            <?php foreach($comptes as $c): ?>
            <option value="<?= $c['code'] ?>"><?= $c['code'] ?> — <?= htmlspecialchars($c['libelle']) ?><?= !$c['is_active'] ? ' (désactivé)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Compte Crédit *</label>
          <select name="compte_credit" class="form-control" required>
            <option value="">— Choisir —</option>
            <?php foreach($comptes as $c): ?>
            <option value="<?= $c['code'] ?>"><?= $c['code'] ?> — <?= htmlspecialchars($c['libelle']) ?><?= !$c['is_active'] ? ' (désactivé)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Montant (FCFA) *</label>
        <input type="number" name="montant" class="form-control" min="1" step="1" placeholder="0" required>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
        <button type="button" class="btn btn-ghost" onclick="closeModal('modalEcriture')">Annuler</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<!-- Modal : Annulation écriture -->
<div class="modal" id="modalAnnulation">
  <div class="modal-box" style="max-width:500px;">
    <div class="modal-hdr">
      <h3><i class="fas fa-ban" style="color:var(--red)"></i> Annuler l'écriture</h3>
      <span class="modal-close" onclick="closeModal('modalAnnulation')">&times;</span>
    </div>
    <div id="annulationDetails" style="background:rgba(0,0,0,.25);border-radius:8px;padding:14px;margin-bottom:18px;font-size:13px;line-height:1.9;"></div>
    <div class="form-group">
      <label>Motif d'annulation *</label>
      <textarea id="motifAnnulation" class="form-control" rows="3"
        placeholder="Expliquez la raison de l'annulation…" style="resize:vertical;"></textarea>
    </div>
    <div id="annulationWarning" style="display:none;background:rgba(243,156,18,.15);border:1px solid var(--yellow);border-radius:8px;padding:12px 16px;margin-top:14px;color:var(--yellow);font-size:13px;line-height:1.6;">
      <i class="fas fa-exclamation-triangle"></i> <span id="annulationWarningText"></span>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px;">
      <button type="button" class="btn btn-ghost" onclick="closeModal('modalAnnulation')">Fermer</button>
      <button type="button" class="btn btn-danger" id="btnConfirmAnnulation" onclick="confirmerAnnulation()">
        <i class="fas fa-ban"></i> Confirmer l'annulation
      </button>
    </div>
  </div>
</div>
<script>
// ── Tabs ──────────────────────────────────────────────────────────
function showTab(name, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
    if (name === 'journal') loadJournal(journalPage, journalPerPage);
}

// ── Modal ─────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'block'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
window.onclick = e => { if (e.target.classList.contains('modal')) e.target.style.display = 'none'; };

// ── Balance toggle ────────────────────────────────────────────────
function toggleBalanceClasse(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// ── Sync écritures ────────────────────────────────────────────────
function syncEcritures() {
    const banner = document.getElementById('syncBanner');
    const msg    = document.getElementById('syncMsg');
    msg.textContent = 'Synchronisation en cours…';
    banner.style.display = 'flex';
    fetch('?action=sync_ecritures')
        .then(r => r.json())
        .then(d => {
            const syncMsg = document.getElementById('syncMsg');
            if (d.synced === 0) {
                syncMsg.textContent = 'Tout est déjà à jour — aucune nouvelle écriture à générer.';
            } else {
                syncMsg.textContent = d.synced + ' nouvelle(s) écriture(s) générée(s).' + (d.errors > 0 ? ' (' + d.errors + ' ignorée(s))' : '') + ' Rechargement...';
            }
            setTimeout(() => location.reload(), 2000);
        })
        .catch(() => { msg.textContent = 'Erreur lors de la synchronisation.'; });
}

function syncVersementsCours() {
    const banner = document.getElementById('syncBanner');
    const msg    = document.getElementById('syncMsg');
    msg.textContent = 'Synchronisation versements cours en cours…';
    banner.style.display = 'flex';
    fetch('?action=sync_versements_cours')
        .then(r => r.json())
        .then(d => {
            if (d.synced === 0) {
                msg.textContent = 'Versements cours : tout est déjà à jour.';
            } else {
                msg.textContent = d.synced + ' versement(s) cours synchronisé(s).' + (d.errors > 0 ? ' (' + d.errors + ' ignoré(s))' : '') + ' Rechargement…';
                setTimeout(() => location.reload(), 2000);
            }
        })
        .catch(() => { msg.textContent = 'Erreur lors de la synchronisation versements cours.'; });
}

// ── Grand livre AJAX ──────────────────────────────────────────────
function loadGrandLivre() {
    const compte = document.getElementById('glCompte').value;
    const annee  = <?= $annee_filtre ?>;
    const cont   = document.getElementById('glContent');
    if (!compte) { cont.innerHTML = '<div class="empty"><i class="fas fa-search"></i><p>Sélectionnez un compte.</p></div>'; return; }
    cont.innerHTML = '<div class="empty"><i class="fas fa-spinner fa-spin"></i><p>Chargement…</p></div>';
    fetch(`?action=get_grand_livre&compte=${encodeURIComponent(compte)}&annee=${annee}`)
        .then(r => r.json())
        .then(d => {
            if (!d.rows.length) {
                cont.innerHTML = '<div class="empty"><i class="fas fa-inbox"></i><p>Aucun mouvement pour ce compte.</p></div>';
                return;
            }
            let totalD = 0, totalC = 0;
            let rows = d.rows.map(r => {
                totalD += parseFloat(r.debit);
                totalC += parseFloat(r.credit);
                return `<tr>
                    <td class="mono">${r.date_ecriture}</td>
                    <td class="mono" style="color:var(--muted)">${r.numero_piece||'—'}</td>
                    <td>${r.libelle}</td>
                    <td class="mono" style="color:var(--accent);text-align:right">${parseFloat(r.debit)>0?fmt(r.debit):'—'}</td>
                    <td class="mono" style="color:var(--ohada-gold);text-align:right">${parseFloat(r.credit)>0?fmt(r.credit):'—'}</td>
                </tr>`;
            }).join('');
            const solde = totalD - totalC;
            cont.innerHTML = `
                <table class="data-table">
                    <thead><tr><th>Date</th><th>Pièce</th><th>Libellé</th><th style="text-align:right">Débit</th><th style="text-align:right">Crédit</th></tr></thead>
                    <tbody>${rows}</tbody>
                    <tfoot>
                        <tr style="font-weight:700;border-top:2px solid var(--border);">
                            <td colspan="3" style="font-family:'Space Mono',monospace;color:var(--muted)">TOTAUX</td>
                            <td class="mono" style="text-align:right;color:var(--accent)">${fmt(totalD)}</td>
                            <td class="mono" style="text-align:right;color:var(--ohada-gold)">${fmt(totalC)}</td>
                        </tr>
                        <tr>
                            <td colspan="3" style="font-family:'Space Mono',monospace;color:var(--muted);font-size:12px;">SOLDE</td>
                            <td colspan="2" class="mono" style="text-align:right;color:${solde>=0?'var(--green)':'var(--red)'};font-weight:700">${fmt(Math.abs(solde))} ${solde>=0?'(Débiteur)':'(Créditeur)'}</td>
                        </tr>
                    </tfoot>
                </table>`;
        });
}
function fmt(n) { return new Intl.NumberFormat('fr-FR').format(parseFloat(n)||0); }

// ── Charts ────────────────────────────────────────────────────────
const mois = ['Jan','Fév','Mar','Avr','Mai','Jun','Jul','Aoû','Sep','Oct','Nov','Déc'];
const gdata = <?= json_encode($graph_data) ?>;

new Chart(document.getElementById('chartMensuel'), {
    type: 'bar',
    data: {
        labels: mois,
        datasets: [
            { label: 'Produits', data: gdata.map(d=>d.produits), backgroundColor: 'rgba(0,230,118,.6)', borderColor: 'rgba(0,230,118,1)', borderWidth: 1, borderRadius: 4 },
            { label: 'Charges',  data: gdata.map(d=>d.charges),  backgroundColor: 'rgba(255,23,68,.5)',  borderColor: 'rgba(255,23,68,1)',  borderWidth: 1, borderRadius: 4 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: true,
        plugins: { legend: { labels: { color: '#7fa8c4', font: { family: 'DM Sans' } } } },
        scales: {
            x: { ticks: { color: '#7fa8c4' }, grid: { color: 'rgba(255,255,255,.04)' } },
            y: { ticks: { color: '#7fa8c4', callback: v => new Intl.NumberFormat('fr-FR',{notation:'compact'}).format(v) }, grid: { color: 'rgba(255,255,255,.04)' } }
        }
    }
});

// Répartition par journal (exercice complet)
const journalDist = <?= json_encode($journal_distribution) ?>;
if (Object.keys(journalDist).length) {
    new Chart(document.getElementById('chartJournal'), {
        type: 'doughnut',
        data: {
            labels: Object.keys(journalDist),
            datasets: [{ data: Object.values(journalDist), backgroundColor: ['#039be5','#00e676','#ffd740','#ff1744','#ce93d8','#00e5ff','#d4a843'], borderWidth: 0 }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position:'bottom', labels: { color:'#7fa8c4', font:{family:'DM Sans'}, padding:12, boxWidth:12 } } }
        }
    });
}

// Auto-hide alert
document.querySelectorAll('.alert.show').forEach(a => setTimeout(()=>a.classList.remove('show'),5000));

// ── Journal AJAX paginé ───────────────────────────────────────────────
const EXERCICE_ID   = <?= intval($exercice_id) ?>;
const IS_CLOTURE    = <?= ($exercice && ($exercice['statut']??'') === 'cloture') ? 'true' : 'false' ?>;
let journalPage      = 1;
let journalPerPage   = <?= intval($initial_per_page_journal) ?>;
let journalTotalPgs  = 1;

function loadJournal(page, perPage) {
    page    = Math.max(1, parseInt(page)    || journalPage);
    perPage = Math.max(1, parseInt(perPage) || journalPerPage);
    const cont = document.getElementById('journalContent');
    const pagi = document.getElementById('journalPagination');
    cont.innerHTML = '<div class="empty"><i class="fas fa-spinner fa-spin"></i><p>Chargement…</p></div>';
    if (pagi) pagi.innerHTML = '';

    fetch(`?action=get_journal&exercice_id=${EXERCICE_ID}&page=${page}&per_page=${perPage}`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                cont.innerHTML = '<div class="empty"><i class="fas fa-exclamation-circle"></i><p>Erreur de chargement.</p></div>';
                return;
            }
            journalPage     = d.page;
            journalPerPage  = d.per_page;
            journalTotalPgs = d.total_pages;

            const from = d.total > 0 ? (d.page - 1) * d.per_page + 1 : 0;
            const to   = Math.min(d.page * d.per_page, d.total);
            document.getElementById('journalInfo').textContent =
                `Entrées ${from}–${to} sur ${d.total}`;
            // Mettre à jour le max du champ "Aller à"
            const gotoInput = document.getElementById('journalGotoPage');
            if (gotoInput) gotoInput.max = journalTotalPgs;

            if (!d.rows.length) {
                cont.innerHTML = '<div class="empty"><i class="fas fa-book-open"></i>' +
                    '<p>Aucune écriture pour cet exercice.<br>' +
                    'Cliquez sur <strong>Synchroniser</strong> pour importer les paiements existants.</p></div>';
                renderJournalPagination();
                return;
            }

            let html = '<table class="data-table"><thead><tr>' +
                '<th>Date</th><th>Pièce</th><th>Libellé</th>' +
                '<th>Journal</th><th>Débit</th><th>Crédit</th>' +
                '<th class="mono">Montant</th><th>Source</th><th></th>' +
                '</tr></thead><tbody>';

            d.rows.forEach(e => {
                const annulee = e.statut === 'annule';
                const jcode   = e.journal_code;
                const jtag    = jcode==='CAI'?'caisse':(jcode==='BNQ'||jcode==='MOB'?'banque':
                                (jcode==='VTE'?'ventes':(jcode==='ACH'?'achats':
                                (jcode==='SAL'?'salaires':'od'))));
                const date    = fmtDate(e.date_ecriture);
                let motifHtml = '';
                if (annulee && e.motif_annulation)
                    motifHtml = `<div style="font-size:11px;color:var(--red);font-style:italic;margin-top:3px;">↳ ${esc(e.motif_annulation)}</div>`;
                let actionHtml = '';
                if (annulee) {
                    actionHtml = '<span class="tag tag-annule"><i class="fas fa-ban"></i> Annulée</span>';
                } else if (!IS_CLOTURE) {
                    actionHtml = `<button class="btn btn-danger btn-sm btn-annuler-ecriture"
                        style="padding:4px 9px;font-size:11px;" title="Annuler cette écriture"
                        data-id="${e.id}"
                        data-libelle="${esc(e.libelle)}"
                        data-montant="${fmt(e.montant)}"
                        data-date="${date}"
                        data-debit="${esc(e.compte_debit)}"
                        data-credit="${esc(e.compte_credit)}">
                        <i class="fas fa-ban"></i></button>`;
                }
                html += `<tr${annulee?' class="ecriture-annulee"':''}>
                    <td class="mono">${date}</td>
                    <td class="mono" style="color:var(--muted)">${esc(e.numero_piece||'—')}</td>
                    <td>${esc(e.libelle)}${motifHtml}</td>
                    <td><span class="tag tag-${jtag}">${jcode}</span></td>
                    <td class="mono" style="color:var(--accent)">${esc(e.compte_debit)}</td>
                    <td class="mono" style="color:var(--ohada-gold)">${esc(e.compte_credit)}</td>
                    <td class="mono" style="color:${annulee?'var(--muted)':'var(--green)'}">${fmt(e.montant)}</td>
                    <td><span class="tag ${e.source_type==='manuel'?'tag-manuel':'tag-od'}">${e.source_type.replace(/_/g,' ')}</span></td>
                    <td style="white-space:nowrap">${actionHtml}</td>
                </tr>`;
            });
            html += '</tbody></table>';
            cont.innerHTML = html;
            renderJournalPagination();
        })
        .catch(() => {
            cont.innerHTML = '<div class="empty"><i class="fas fa-exclamation-circle"></i><p>Erreur réseau.</p></div>';
        });
}

function renderJournalPagination() {
    const pagi = document.getElementById('journalPagination');
    if (!pagi) return;
    const p     = journalPage;
    const total = journalTotalPgs;
    if (total <= 1) { pagi.innerHTML = ''; return; }

    // Calcul des pages à afficher (max 7 boutons autour de la courante)
    let pages = [];
    if (total <= 7) {
        for (let i = 1; i <= total; i++) pages.push(i);
    } else {
        pages.push(1);
        if (p > 3)          pages.push('…');
        const lo = Math.max(2, p - 1);
        const hi = Math.min(total - 1, p + 1);
        for (let i = lo; i <= hi; i++) pages.push(i);
        if (p < total - 2)  pages.push('…');
        pages.push(total);
    }

    let html = '';
    // ← Préc.
    html += p > 1
        ? `<button class="btn btn-ghost btn-sm" onclick="loadJournal(${p-1})"><i class="fas fa-chevron-left"></i> Préc.</button>`
        : `<button class="btn btn-ghost btn-sm" disabled><i class="fas fa-chevron-left"></i> Préc.</button>`;

    pages.forEach(pg => {
        if (pg === '…') {
            html += `<span style="color:var(--muted);padding:6px 4px;font-size:13px;">…</span>`;
        } else if (pg === p) {
            html += `<button class="btn btn-sm btn-page-cur" style="min-width:34px;">${pg}</button>`;
        } else {
            html += `<button class="btn btn-ghost btn-sm" onclick="loadJournal(${pg})" style="min-width:34px;">${pg}</button>`;
        }
    });

    // Suiv. →
    html += p < total
        ? `<button class="btn btn-ghost btn-sm" onclick="loadJournal(${p+1})">Suiv. <i class="fas fa-chevron-right"></i></button>`
        : `<button class="btn btn-ghost btn-sm" disabled>Suiv. <i class="fas fa-chevron-right"></i></button>`;

    pagi.innerHTML = html;
}

function changePerPage(val) {
    journalPerPage = parseInt(val) || 10;
    loadJournal(1, journalPerPage);
}

function gotoPage() {
    const input = document.getElementById('journalGotoPage');
    const page  = Math.max(1, Math.min(journalTotalPgs, parseInt(input.value) || 1));
    input.value = '';
    loadJournal(page);
}

function fmtDate(d) {
    if (!d) return '—';
    const parts = d.substring(0, 10).split('-');
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

function esc(str) {
    return String(str||'')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Annulation écriture ───────────────────────────────────────────────
let currentAnnulationId = null;

document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-annuler-ecriture');
    if (!btn) return;
    annulerEcriture(
        btn.dataset.id,
        btn.dataset.libelle,
        btn.dataset.montant,
        btn.dataset.date,
        btn.dataset.debit,
        btn.dataset.credit
    );
});

function annulerEcriture(id, libelle, montant, date, debit, credit) {
    currentAnnulationId = id;
    document.getElementById('annulationDetails').innerHTML =
        `<div style="display:grid;grid-template-columns:110px 1fr;gap:4px 12px;">
            <span style="color:var(--muted)">Libellé :</span><span>${libelle}</span>
            <span style="color:var(--muted)">Date :</span><span>${date}</span>
            <span style="color:var(--muted)">Comptes :</span><span style="font-family:'Space Mono',monospace">${debit} → ${credit}</span>
            <span style="color:var(--muted)">Montant :</span><span style="font-family:'Space Mono',monospace;color:var(--green)">${montant} FCFA</span>
        </div>`;
    document.getElementById('motifAnnulation').value = '';
    const btn = document.getElementById('btnConfirmAnnulation');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-ban"></i> Confirmer l\'annulation';
    openModal('modalAnnulation');
}

function confirmerAnnulation() {
    const motif = document.getElementById('motifAnnulation').value.trim();
    if (!motif) { alert('Le motif d\'annulation est obligatoire.'); return; }
    const btn = document.getElementById('btnConfirmAnnulation');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Annulation…';
    const fd = new FormData();
    fd.append('csrf_token', '<?= $csrf_token ?>');
    fd.append('action', 'annuler_ecriture');
    fd.append('ecriture_id', currentAnnulationId);
    fd.append('motif_annulation', motif);
    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                if (d.warning) {
                    document.getElementById('annulationWarningText').textContent = d.warning;
                    document.getElementById('annulationWarning').style.display = 'block';
                    btn.disabled = true;
                    setTimeout(() => { closeModal('modalAnnulation'); location.reload(); }, 4000);
                } else {
                    closeModal('modalAnnulation');
                    location.reload();
                }
            } else {
                alert('Erreur : ' + (d.error || 'Annulation impossible'));
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-ban"></i> Confirmer l\'annulation';
            }
        })
        .catch(() => {
            alert('Erreur de communication.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-ban"></i> Confirmer l\'annulation';
        });
}
</script>
<?php include '../includes/footer.php'; ?>