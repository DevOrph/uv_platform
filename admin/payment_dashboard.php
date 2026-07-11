<?php
session_start();
require_once '../includes/db_connect.php';

// Vérification de l'authentification admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$admin_id = $_SESSION['user_id'];

// ── Filtre année académique ──────────────────────────────────────────────
// Par défaut : année courante depuis la table parametres (via db_connect.php).
// L'admin peut filtrer sur une autre année via ?annee=XXXX-XXXX.
// Si l'admin change l'année via GET → sauvegarder en session
if (!empty($_GET['annee']) && preg_match('/^\d{4}-\d{4}$/', $_GET['annee'])) {
    $annee_filtre = $_GET['annee'];
    $_SESSION['annee_filtre_paiement'] = $annee_filtre;
// Sinon lire depuis la session
} elseif (!empty($_SESSION['annee_filtre_paiement'])) {
    $annee_filtre = $_SESSION['annee_filtre_paiement'];
// Sinon défaut = année courante
} else {
    $annee_filtre = ANNEE_ACADEMIQUE_COURANTE;
}

// Années disponibles — union tuition_fees + paiements_enseignant
$annees_disponibles = [ANNEE_ACADEMIQUE_COURANTE];
$__yr = $conn->query("
    SELECT DISTINCT annee FROM (
        SELECT academic_year as annee FROM tuition_fees
        UNION
        SELECT annee_academique FROM paiements_enseignant
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

// Fonction pour générer un token CSRF
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function logAudit($conn, $action_type, $entity_type, $entity_id, $description, $old_val = null, $new_val = null, $performed_by = '', $ip = '') {
    try {
        $old_json = $old_val ? json_encode($old_val, JSON_UNESCAPED_UNICODE) : null;
        $new_json = $new_val ? json_encode($new_val, JSON_UNESCAPED_UNICODE) : null;
        $ua       = substr($_SERVER['HTTP_USER_AGENT'] ?? 'N/A', 0, 255);
        $eid_s    = (string)($entity_id ?? '');
        $stmt = $conn->prepare("INSERT INTO audit_log (action_type,entity_type,entity_id,description,old_value,new_value,performed_by,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?,?)");
        if ($stmt) {
            $stmt->bind_param("sssssssss", $action_type, $entity_type, $eid_s, $description, $old_json, $new_json, $performed_by, $ip, $ua);
            $stmt->execute();
        }
    } catch (Exception $e) {
        error_log("Audit: " . $e->getMessage());
    }
}

$csrf_token = generateCSRFToken();

// Flash message PRG
$message = '';
$message_type = '';
if (isset($_SESSION['flash_message'])) {
    $message      = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Gestion des requêtes AJAX pour l'historique des paiements
if (isset($_GET['action']) && $_GET['action'] === 'get_student_payments' && isset($_GET['student_id'])) {
    header('Content-Type: application/json');
    $student_id = $_GET['student_id'];
    
    try {
        // Récupérer les informations de l'étudiant
        $student_query = "SELECT u.id, u.name, u.email, c.name as class_name,
                         tf.total_amount, tf.due_date,
                         (SELECT SUM(amount_paid) FROM student_payments WHERE student_id = u.id AND status = 'validated') as total_paid
                         FROM users u
                         LEFT JOIN classes c ON u.class_id = c.id
                         LEFT JOIN tuition_fees tf ON c.id = tf.class_id AND tf.academic_year = '$annee_filtre'
                         WHERE u.id = ?";
        $stmt = $conn->prepare($student_query);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $student_info = $stmt->get_result()->fetch_assoc();
        
        // Récupérer l'historique des paiements (validés + annulés)
        $payments_query = "SELECT sp.*, u.name as recorded_by_name, cb.name as cancelled_by_name
                          FROM student_payments sp
                          LEFT JOIN users u ON sp.recorded_by = u.id
                          LEFT JOIN users cb ON sp.cancelled_by = cb.id
                          WHERE sp.student_id = ? AND sp.status IN ('validated','cancelled')
                          ORDER BY sp.payment_date DESC";
        $stmt = $conn->prepare($payments_query);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $payments = [];
        while ($payment = $result->fetch_assoc()) {
            $payments[] = $payment;
        }
        
        // Récupérer les échéances si elles existent
        $deadlines_query = "SELECT * FROM payment_deadlines 
                           WHERE student_id = ? 
                           ORDER BY due_date ASC";
        $stmt = $conn->prepare($deadlines_query);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $deadlines = [];
        while ($deadline = $result->fetch_assoc()) {
            $deadlines[] = $deadline;
        }
        
        echo json_encode([
            'success' => true,
            'student' => $student_info,
            'payments' => $payments,
            'deadlines' => $deadlines
        ]);
        exit();

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// ── Annuler un paiement étudiant ───────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'cancel_payment') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $data       = json_decode(file_get_contents('php://input'), true);
    $payment_id = intval($data['payment_id'] ?? 0);
    $motif      = trim($data['motif'] ?? '');
    try {
        if (empty($motif))    throw new Exception("Le motif d'annulation est obligatoire.");
        if ($payment_id <= 0) throw new Exception("Identifiant de paiement invalide.");

        // Vérifier que le paiement existe, est validé, et appartient à un étudiant de l'institution
        $chk = $conn->prepare(
            "SELECT sp.id, sp.amount_paid, sp.status, sp.student_id, u.name AS student_name
             FROM student_payments sp
             JOIN users u ON sp.student_id = u.id
             WHERE sp.id = ? AND sp.status = 'validated' AND u.role = 'student'"
        );
        $chk->bind_param("i", $payment_id);
        $chk->execute();
        $payment = $chk->get_result()->fetch_assoc();
        if (!$payment) throw new Exception("Paiement introuvable ou déjà annulé.");

        // Annuler le paiement
        $upd = $conn->prepare(
            "UPDATE student_payments SET status='cancelled', cancel_reason=?, cancelled_by=?, cancelled_at=NOW()
             WHERE id=? AND status='validated'"
        );
        $upd->bind_param("ssi", $motif, $admin_id, $payment_id);
        $upd->execute();
        if ($upd->affected_rows === 0) throw new Exception("Impossible d'annuler ce paiement.");

        // Traçabilité audit
        logAudit(
            $conn, 'CANCEL', 'student_payment', $payment_id,
            "Annulation paiement #{$payment_id} — Étudiant : {$payment['student_name']} ({$payment['student_id']}) — Montant : " .
            number_format($payment['amount_paid'], 0, ',', ' ') . " FCFA — Motif : {$motif}",
            ['status' => 'validated', 'amount_paid' => $payment['amount_paid']],
            ['status' => 'cancelled', 'cancel_reason' => $motif],
            $admin_id, $_SERVER['REMOTE_ADDR'] ?? ''
        );

        echo json_encode(['success' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Détail des échéances d'un étudiant (AJAX GET) ─────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_deadlines_detail' && isset($_GET['student_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $student_id = $_GET['student_id'];
    try {
        $stmt = $conn->prepare("
            SELECT u.id, u.name, u.email, c.name as class_name,
                   tf.id as tuition_fee_id, tf.total_amount,
                   COALESCE(tf.tuition_fee, 0) as tuition_fee,
                   COALESCE(sd.discount_amount, 0) as discount_amount,
                   (COALESCE(tf.total_amount,0) - COALESCE(sd.discount_amount,0)) as net_amount
            FROM users u
            LEFT JOIN classes c ON u.class_id = c.id
            LEFT JOIN tuition_fees tf ON c.id = tf.class_id AND tf.academic_year = ?
            LEFT JOIN student_discounts sd
                ON sd.student_id = u.id AND sd.tuition_fee_id = tf.id AND sd.academic_year = ?
            WHERE u.id = ?
        ");
        $stmt->bind_param("sss", $annee_filtre, $annee_filtre, $student_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();

        $stmt2 = $conn->prepare("SELECT * FROM payment_deadlines WHERE student_id = ? ORDER BY due_date ASC");
        $stmt2->bind_param("s", $student_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $deadlines = [];
        while ($row = $res2->fetch_assoc()) $deadlines[] = $row;

        echo json_encode(['success' => true, 'student' => $student, 'deadlines' => $deadlines]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Ajouter une échéance manuelle (AJAX POST) ──────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'add_deadline') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $student_id = trim($data['student_id'] ?? '');
        $due_date   = trim($data['due_date']   ?? '');
        $amount_due = floatval($data['amount_due'] ?? 0);
        $notes      = trim($data['notes'] ?? '');
        if (!$student_id)    throw new Exception("Étudiant requis.");
        if (!$due_date)      throw new Exception("Date requise.");
        if ($amount_due <= 0) throw new Exception("Montant invalide.");

        $tf = $conn->prepare("SELECT tf.id FROM tuition_fees tf JOIN users u ON u.class_id = tf.class_id WHERE u.id = ? AND tf.academic_year = ?");
        $tf->bind_param("ss", $student_id, $annee_filtre);
        $tf->execute();
        $tf_row = $tf->get_result()->fetch_assoc();
        if (!$tf_row) throw new Exception("Aucun frais de scolarité trouvé pour cet étudiant.");

        $ins = $conn->prepare("INSERT INTO payment_deadlines (student_id, tuition_fee_id, due_date, amount_due, amount_paid, status, notes, created_by) VALUES (?, ?, ?, ?, 0, 'pending', ?, ?)");
        $ins->bind_param("sisdss", $student_id, $tf_row['id'], $due_date, $amount_due, $notes, $admin_id);
        if (!$ins->execute()) throw new Exception("Erreur insertion : " . $conn->error);
        $new_id = $conn->insert_id;

        logAudit($conn, 'CREATE', 'payment_deadline', $new_id,
            "Ajout échéance manuelle — étudiant $student_id — $due_date — $amount_due FCFA",
            null, ['student_id' => $student_id, 'due_date' => $due_date, 'amount_due' => $amount_due],
            $admin_id, $_SERVER['REMOTE_ADDR'] ?? '');

        echo json_encode(['success' => true, 'message' => "Échéance ajoutée avec succès.", 'id' => $new_id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Modifier une échéance pending (AJAX POST) ──────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'update_deadline') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $dl_id      = intval($data['deadline_id'] ?? 0);
        $due_date   = trim($data['due_date'] ?? '');
        $amount_due = floatval($data['amount_due'] ?? 0);
        $notes      = trim($data['notes'] ?? '');
        if ($dl_id <= 0)      throw new Exception("ID échéance invalide.");
        if (!$due_date)       throw new Exception("Date requise.");
        if ($amount_due <= 0) throw new Exception("Montant invalide.");

        $chk = $conn->prepare("SELECT id, due_date, amount_due, status FROM payment_deadlines WHERE id = ?");
        $chk->bind_param("i", $dl_id);
        $chk->execute();
        $dl = $chk->get_result()->fetch_assoc();
        if (!$dl) throw new Exception("Échéance introuvable.");
        if ($dl['status'] !== 'pending') throw new Exception("Seules les échéances 'pending' sont modifiables.");

        $upd = $conn->prepare("UPDATE payment_deadlines SET due_date = ?, amount_due = ?, notes = ? WHERE id = ? AND status = 'pending'");
        $upd->bind_param("sdsi", $due_date, $amount_due, $notes, $dl_id);
        if (!$upd->execute() || $upd->affected_rows === 0) throw new Exception("Modification échouée.");

        logAudit($conn, 'UPDATE', 'payment_deadline', $dl_id,
            "Modification échéance #$dl_id",
            ['due_date' => $dl['due_date'], 'amount_due' => $dl['amount_due']],
            ['due_date' => $due_date, 'amount_due' => $amount_due],
            $admin_id, $_SERVER['REMOTE_ADDR'] ?? '');

        echo json_encode(['success' => true, 'message' => "Échéance modifiée avec succès."]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Supprimer une échéance pending non payée (AJAX POST) ───────────────────
if (isset($_GET['action']) && $_GET['action'] === 'delete_deadline') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $dl_id = intval($data['deadline_id'] ?? 0);
        if ($dl_id <= 0) throw new Exception("ID invalide.");

        $chk = $conn->prepare("SELECT id, student_id, status, amount_paid FROM payment_deadlines WHERE id = ?");
        $chk->bind_param("i", $dl_id);
        $chk->execute();
        $dl = $chk->get_result()->fetch_assoc();
        if (!$dl) throw new Exception("Échéance introuvable.");
        if ($dl['status'] !== 'pending') throw new Exception("Seules les échéances 'pending' sont supprimables.");
        if (floatval($dl['amount_paid']) > 0) throw new Exception("Cette échéance comporte un paiement partiel — impossible de la supprimer.");

        $del = $conn->prepare("DELETE FROM payment_deadlines WHERE id = ? AND status = 'pending' AND (amount_paid IS NULL OR amount_paid = 0)");
        $del->bind_param("i", $dl_id);
        if (!$del->execute() || $del->affected_rows === 0) throw new Exception("Suppression échouée.");

        logAudit($conn, 'DELETE', 'payment_deadline', $dl_id,
            "Suppression échéance #$dl_id — étudiant {$dl['student_id']}",
            ['status' => $dl['status'], 'amount_paid' => $dl['amount_paid']], null,
            $admin_id, $_SERVER['REMOTE_ADDR'] ?? '');

        echo json_encode(['success' => true, 'message' => "Échéance supprimée."]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Génération automatique des échéances (AJAX POST) ──────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'generate_deadlines_ajax') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $student_id     = trim($data['student_id'] ?? '');
        $installments   = intval($data['installments'] ?? 0);
        $first_deadline = trim($data['first_deadline'] ?? '');
        if (!$student_id || $installments < 1 || !$first_deadline)
            throw new Exception("Paramètres invalides.");

        $tf = $conn->prepare("SELECT tf.id FROM tuition_fees tf JOIN users u ON u.class_id = tf.class_id WHERE u.id = ? AND tf.academic_year = ?");
        $tf->bind_param("ss", $student_id, $annee_filtre);
        $tf->execute();
        $tf_row = $tf->get_result()->fetch_assoc();
        if (!$tf_row) throw new Exception("Aucun frais de scolarité trouvé.");

        $call = $conn->prepare("CALL GeneratePaymentDeadlines(?, ?, ?, ?, ?)");
        $call->bind_param("siiss", $student_id, $tf_row['id'], $installments, $first_deadline, $admin_id);
        if (!$call->execute()) throw new Exception("Erreur procédure : " . $conn->error);
        $call->close();
        while ($conn->more_results()) $conn->next_result();

        logAudit($conn, 'CREATE', 'payment_deadline', null,
            "Génération auto — étudiant $student_id — $installments tranches — 1ère : $first_deadline",
            null, ['student_id' => $student_id, 'installments' => $installments],
            $admin_id, $_SERVER['REMOTE_ADDR'] ?? '');

        echo json_encode(['success' => true, 'message' => "Échéances générées avec succès."]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Génération en masse par classe (AJAX POST) ────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'generate_class_deadlines') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $class_id       = intval($data['class_id'] ?? 0);
        $installments   = intval($data['installments'] ?? 0);
        $first_deadline = trim($data['first_deadline'] ?? '');
        if (!$class_id || $installments < 1 || !$first_deadline)
            throw new Exception("Paramètres invalides.");

        $students_stmt = $conn->prepare("
            SELECT u.id as student_id, tf.id as tuition_fee_id
            FROM users u
            JOIN classes c ON u.class_id = c.id
            JOIN tuition_fees tf ON c.id = tf.class_id AND tf.academic_year = ?
            WHERE c.id = ? AND u.role = 'student' AND u.blocked = 0 AND u.status = 'active'
              AND (SELECT COUNT(*) FROM payment_deadlines pd WHERE pd.student_id = u.id) = 0
        ");
        $students_stmt->bind_param("si", $annee_filtre, $class_id);
        $students_stmt->execute();
        $students_res = $students_stmt->get_result();

        $created = 0; $skipped = 0; $errors = 0;
        while ($stu = $students_res->fetch_assoc()) {
            try {
                $call = $conn->prepare("CALL GeneratePaymentDeadlines(?, ?, ?, ?, ?)");
                $call->bind_param("siiss", $stu['student_id'], $stu['tuition_fee_id'], $installments, $first_deadline, $admin_id);
                if ($call->execute()) {
                    $created++;
                    logAudit($conn, 'CREATE', 'payment_deadline', null,
                        "Génération masse classe $class_id — étudiant {$stu['student_id']} — $installments tranches",
                        null, ['student_id' => $stu['student_id'], 'class_id' => $class_id],
                        $admin_id, $_SERVER['REMOTE_ADDR'] ?? '');
                } else { $errors++; }
                $call->close();
                while ($conn->more_results()) $conn->next_result();
            } catch (Exception $ex) { $errors++; }
        }

        echo json_encode([
            'success' => true,
            'message' => "$created échéancier(s) créé(s), $skipped ignoré(s), $errors erreur(s).",
            'created' => $created, 'skipped' => $skipped, 'errors' => $errors
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Échéances ouvertes + frais d'un étudiant (AJAX GET) ────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_student_open_deadlines' && isset($_GET['student_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $student_id = $_GET['student_id'];
    try {
        $stmt = $conn->prepare("
            SELECT tf.id as tuition_fee_id, tf.total_amount,
                   COALESCE(tf.registration_fee,0) as registration_fee,
                   COALESCE(tf.tuition_fee,0)      as tuition_fee,
                   COALESCE(tf.insurance_fee,0)    as insurance_fee,
                   COALESCE(tf.library_fee,0)      as library_fee,
                   COALESCE(tf.practical_fee,0)    as practical_fee,
                   COALESCE(tf.other_fees,0)       as other_fees,
                   COALESCE(sd.discount_amount,0)  as discount_amount,
                   (COALESCE(tf.total_amount,0) - COALESCE(sd.discount_amount,0)) as net_amount
            FROM users u
            JOIN classes c ON u.class_id = c.id
            JOIN tuition_fees tf ON c.id = tf.class_id AND tf.academic_year = ?
            LEFT JOIN student_discounts sd
                ON sd.student_id = u.id AND sd.tuition_fee_id = tf.id AND sd.academic_year = ?
            WHERE u.id = ?
        ");
        $stmt->bind_param("sss", $annee_filtre, $annee_filtre, $student_id);
        $stmt->execute();
        $tf = $stmt->get_result()->fetch_assoc();
        if (!$tf) { echo json_encode(['success' => false, 'error' => 'Aucun frais de scolarité trouvé.']); exit(); }

        // Numérotation globale des échéances
        $stmt_all = $conn->prepare("SELECT id FROM payment_deadlines WHERE student_id = ? ORDER BY due_date ASC, id ASC");
        $stmt_all->bind_param("s", $student_id);
        $stmt_all->execute();
        $all_dl_ids = [];
        $res_all = $stmt_all->get_result();
        while ($r = $res_all->fetch_assoc()) $all_dl_ids[] = (int)$r['id'];

        // Échéances ouvertes (pending / partial / overdue)
        $stmt2 = $conn->prepare("
            SELECT id, due_date, amount_due, COALESCE(amount_paid,0) as amount_paid, status, notes,
                   (amount_due - COALESCE(amount_paid,0)) as remaining
            FROM payment_deadlines
            WHERE student_id = ? AND status IN ('pending','partial','overdue')
            ORDER BY due_date ASC, id ASC
        ");
        $stmt2->bind_param("s", $student_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $deadlines = [];
        while ($row = $res2->fetch_assoc()) {
            $idx = array_search((int)$row['id'], $all_dl_ids);
            $row['deadline_num'] = $idx !== false ? ($idx + 1) : null;
            $deadlines[] = $row;
        }

        // Montants déjà couverts par allocation_type via payment_allocations (nouveau système)
        $fees_paid = ['registration' => 0, 'tuition' => 0, 'insurance' => 0, 'library' => 0, 'practical' => 0, 'other' => 0];
        $stmt3 = $conn->prepare("
            SELECT pa.allocation_type, SUM(pa.amount) as total
            FROM payment_allocations pa
            JOIN student_payments sp ON pa.payment_id = sp.id
            WHERE sp.student_id = ? AND sp.status = 'validated' AND pa.deadline_id IS NULL
            GROUP BY pa.allocation_type
        ");
        $stmt3->bind_param("s", $student_id);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        while ($row = $res3->fetch_assoc()) {
            $ft = $row['allocation_type'];
            $fees_paid[$ft] = ($fees_paid[$ft] ?? 0) + floatval($row['total']);
        }
        // Paiements anciens (sans lignes d'allocation = avant la refonte)
        $stmt4 = $conn->prepare("
            SELECT sp.payment_type, SUM(sp.amount_paid) as total
            FROM student_payments sp
            WHERE sp.student_id = ? AND sp.status = 'validated'
              AND sp.payment_type NOT IN ('tuition')
              AND NOT EXISTS (SELECT 1 FROM payment_allocations pa2 WHERE pa2.payment_id = sp.id)
            GROUP BY sp.payment_type
        ");
        $stmt4->bind_param("s", $student_id);
        $stmt4->execute();
        $res4 = $stmt4->get_result();
        while ($row = $res4->fetch_assoc()) {
            $ft = $row['payment_type'];
            $fees_paid[$ft] = ($fees_paid[$ft] ?? 0) + floatval($row['total']);
        }

        echo json_encode(['success' => true, 'tuition_fee' => $tf, 'deadlines' => $deadlines, 'fees_paid' => $fees_paid]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Allocations d'un paiement (AJAX GET) ──────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_payment_allocations' && isset($_GET['payment_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $payment_id = intval($_GET['payment_id']);
    try {
        $sp_stmt = $conn->prepare("SELECT student_id FROM student_payments WHERE id = ?");
        $sp_stmt->bind_param("i", $payment_id);
        $sp_stmt->execute();
        $sp_row = $sp_stmt->get_result()->fetch_assoc();
        if (!$sp_row) throw new Exception("Paiement introuvable.");
        $student_id = $sp_row['student_id'];

        $stmt_all = $conn->prepare("SELECT id FROM payment_deadlines WHERE student_id = ? ORDER BY due_date ASC, id ASC");
        $stmt_all->bind_param("s", $student_id);
        $stmt_all->execute();
        $all_dl_ids = [];
        $res_all = $stmt_all->get_result();
        while ($r = $res_all->fetch_assoc()) $all_dl_ids[] = (int)$r['id'];

        $stmt = $conn->prepare("
            SELECT pa.id, pa.allocation_type, pa.amount, pa.deadline_id,
                   pd.due_date, pd.amount_due
            FROM payment_allocations pa
            LEFT JOIN payment_deadlines pd ON pa.deadline_id = pd.id
            WHERE pa.payment_id = ?
            ORDER BY pa.id ASC
        ");
        $stmt->bind_param("i", $payment_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $allocations = [];
        while ($row = $res->fetch_assoc()) {
            if ($row['deadline_id']) {
                $idx = array_search((int)$row['deadline_id'], $all_dl_ids);
                $row['deadline_num'] = $idx !== false ? ($idx + 1) : null;
            } else {
                $row['deadline_num'] = null;
            }
            $allocations[] = $row;
        }
        echo json_encode(['success' => true, 'allocations' => $allocations]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Paiement ventilé (AJAX POST) ────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'add_payment_ventilated') {
    ini_set('display_errors', 0);   // PHP warnings ne doivent pas corrompre la réponse JSON
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    if (($data['csrf_token'] ?? '') !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Token CSRF invalide.']);
        exit();
    }
    // Année académique : priorité au corps JSON, sinon valeur globale de la page
    if (!empty($data['annee']) && preg_match('/^\d{4}-\d{4}$/', $data['annee'])) {
        $annee_filtre = $data['annee'];
    }
    $inTransaction = false;
    try {
        $student_id     = trim($data['student_id']     ?? '');
        $payment_method = trim($data['payment_method'] ?? '');
        $reference      = trim($data['reference']      ?? '');
        $description    = trim($data['description']    ?? '');
        $allocations    = $data['allocations']          ?? [];

        if (!$student_id)       throw new Exception("Étudiant requis.");
        if (!$payment_method)   throw new Exception("Méthode de paiement requise.");
        if (empty($allocations)) throw new Exception("Aucune allocation saisie.");

        // Valider chaque allocation et sommer
        $total_amount = 0.0;
        foreach ($allocations as $alloc) {
            $amt = floatval($alloc['amount'] ?? 0);
            if ($amt <= 0) throw new Exception("Montant d'allocation invalide (doit être > 0).");
            $total_amount += $amt;
            if (!empty($alloc['deadline_id'])) {
                $dl_id_chk = intval($alloc['deadline_id']);
                $dl_stmt = $conn->prepare("SELECT id, amount_due, COALESCE(amount_paid,0) as paid, student_id FROM payment_deadlines WHERE id = ?");
                $dl_stmt->bind_param("i", $dl_id_chk);
                $dl_stmt->execute();
                $dl = $dl_stmt->get_result()->fetch_assoc();
                if (!$dl) throw new Exception("Échéance #{$alloc['deadline_id']} introuvable.");
                if ($dl['student_id'] !== $student_id) throw new Exception("Échéance ne correspond pas à l'étudiant.");
                $remaining_dl = floatval($dl['amount_due']) - floatval($dl['paid']);
                if ($amt > $remaining_dl + 0.01) throw new Exception(
                    "Allocation ({$amt} FCFA) > restant échéance #" . intval($alloc['deadline_id']) . " (" . round($remaining_dl) . " FCFA)."
                );
            }
        }

        // Valider les allocations de type frais ponctuels (registration, insurance, etc.)
        $fee_types_map = [
            'registration' => 'registration_fee',
            'insurance'    => 'insurance_fee',
            'library'      => 'library_fee',
            'practical'    => 'practical_fee',
            'other'        => 'other_fees',
        ];
        $fee_allocs = array_filter($allocations, function($a) use ($fee_types_map) {
            return empty($a['deadline_id']) && isset($fee_types_map[trim($a['allocation_type'] ?? '')]);
        });
        if (!empty($fee_allocs)) {
            $tf_comp_stmt = $conn->prepare("
                SELECT tf.registration_fee, tf.insurance_fee, tf.library_fee,
                       tf.practical_fee, tf.other_fees
                FROM tuition_fees tf
                JOIN users u ON u.class_id = tf.class_id
                WHERE u.id = ? AND tf.academic_year = '$annee_filtre'
            ");
            $tf_comp_stmt->bind_param("s", $student_id);
            $tf_comp_stmt->execute();
            $tf_comps = $tf_comp_stmt->get_result()->fetch_assoc();
            if (!$tf_comps) throw new Exception("Composants de frais introuvables pour cet étudiant.");

            $paid_by_type_stmt = $conn->prepare("
                SELECT pa.allocation_type, COALESCE(SUM(pa.amount), 0) AS paid
                FROM payment_allocations pa
                JOIN student_payments sp ON pa.payment_id = sp.id
                WHERE sp.student_id = ? AND sp.status = 'validated' AND pa.deadline_id IS NULL
                GROUP BY pa.allocation_type
            ");
            $paid_by_type_stmt->bind_param("s", $student_id);
            $paid_by_type_stmt->execute();
            $res_pt = $paid_by_type_stmt->get_result();
            $already_paid_by_type = [];
            while ($r = $res_pt->fetch_assoc()) {
                $already_paid_by_type[$r['allocation_type']] = floatval($r['paid']);
            }

            $fee_labels = [
                'registration' => 'Inscription', 'insurance' => 'Assurance',
                'library' => 'Bibliothèque',     'practical' => 'TP',
                'other'   => 'Autres frais',
            ];
            foreach ($fee_allocs as $alloc) {
                $ft  = trim($alloc['allocation_type']);
                $amt = floatval($alloc['amount'] ?? 0);
                $col = $fee_types_map[$ft];
                $component_total  = floatval($tf_comps[$col] ?? 0);
                $already_paid_ft  = $already_paid_by_type[$ft] ?? 0.0;
                $remaining_fee    = max(0.0, $component_total - $already_paid_ft);
                if ($amt > $remaining_fee + 0.01) {
                    throw new Exception(sprintf(
                        "Frais %s : allocation (%s FCFA) > restant (%s FCFA).",
                        $fee_labels[$ft] ?? $ft,
                        number_format($amt, 0, ',', ' '),
                        number_format($remaining_fee, 0, ',', ' ')
                    ));
                }
            }
        }

        // Vérifier le solde global restant
        $tuition_query = "SELECT tf.id,
            (COALESCE(tf.total_amount,0) - COALESCE(sd.discount_amount,0)
             - COALESCE(SUM(sp.amount_paid),0)) AS remaining_balance
            FROM tuition_fees tf
            JOIN users u ON u.class_id = tf.class_id
            LEFT JOIN student_discounts sd ON sd.student_id = u.id AND sd.tuition_fee_id = tf.id AND sd.academic_year = '$annee_filtre'
            LEFT JOIN student_payments sp ON sp.student_id = u.id AND sp.tuition_fee_id = tf.id AND sp.status = 'validated'
            WHERE u.id = ? AND tf.academic_year = '$annee_filtre'
            GROUP BY tf.id, tf.total_amount, sd.discount_amount";
        $stmt = $conn->prepare($tuition_query);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $tf_row = $stmt->get_result()->fetch_assoc();
        if (!$tf_row) throw new Exception("Aucun frais de scolarité trouvé pour cet étudiant.");
        $remaining_balance = floatval($tf_row['remaining_balance']);
        if ($total_amount <= 0)      throw new Exception("Le montant total doit être > 0.");
        if ($remaining_balance <= 0) throw new Exception("Cet étudiant a déjà réglé la totalité de ses frais.");
        if ($total_amount > $remaining_balance + 0.01) throw new Exception(sprintf(
            "Total allocations (%s FCFA) > solde restant (%s FCFA).",
            number_format($total_amount, 0, ',', ' '), number_format($remaining_balance, 0, ',', ' ')
        ));

        // Type primaire du paiement
        $payment_type = 'tuition';
        foreach ($allocations as $alloc) {
            if (!empty($alloc['deadline_id'])) { $payment_type = 'tuition'; break; }
            if (!empty($alloc['allocation_type']))    { $payment_type = $alloc['allocation_type']; break; }
        }

        $receipt_number = 'REC-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $conn->begin_transaction();
        $inTransaction = true;

        // INSERT student_payments
        $ins_pay = $conn->prepare("INSERT INTO student_payments (student_id, tuition_fee_id, amount_paid, payment_method, payment_type, reference_number, description, receipt_number, recorded_by, status) VALUES (?,?,?,?,?,?,?,?,?,'validated')");
        $ins_pay->bind_param("sidssssss", $student_id, $tf_row['id'], $total_amount, $payment_method, $payment_type, $reference, $description, $receipt_number, $admin_id);
        if (!$ins_pay->execute()) throw new Exception("Erreur insertion paiement : " . $conn->error);
        $new_payment_id = $conn->insert_id;

        // Supprimer les allocations insérées par le trigger (fallback) avant d'insérer les nôtres
        $conn->query("DELETE FROM payment_allocations WHERE payment_id = $new_payment_id");

        // INSERT payment_allocations
        $ins_alloc = $conn->prepare("INSERT INTO payment_allocations (payment_id, deadline_id, allocation_type, amount) VALUES (?,?,?,?)");
        foreach ($allocations as $alloc) {
            $dl_id    = !empty($alloc['deadline_id']) ? intval($alloc['deadline_id']) : null;
            $allocation_type = trim($alloc['allocation_type'] ?? ($dl_id !== null ? 'tuition' : 'other'));
            $amt      = floatval($alloc['amount']);
            $ins_alloc->bind_param("iisd", $new_payment_id, $dl_id, $allocation_type, $amt);
            if (!$ins_alloc->execute()) throw new Exception("Erreur insertion allocation : " . $conn->error);
        }

        // Écriture comptable OHADA (procédure optionnelle — peut être absente en local)
        try { $conn->query("CALL GenererEcritureStudentPayment($new_payment_id)"); }
        catch (\Throwable $ignored) {}

        $conn->commit();
        $inTransaction = false;

        // Synchroniser payment_deadlines depuis payment_allocations
        // (annule les effets du trigger after_payment_insert sur les frais ponctuels)
        $tf_id_rec = intval($tf_row['id']);
        $rec = $conn->prepare("CALL ReconcileStudentDeadlines(?, ?)");
        $rec->bind_param("si", $student_id, $tf_id_rec);
        $rec->execute();
        $rec->close();
        while ($conn->more_results()) $conn->next_result();

        logAudit($conn, 'CREATE', 'student_payment', $new_payment_id,
            "Paiement ventilé #{$new_payment_id} — Étudiant : {$student_id} — " .
            number_format($total_amount, 0, ',', ' ') . " FCFA — Reçu : {$receipt_number} — " .
            count($allocations) . " allocation(s)",
            null,
            ['student_id' => $student_id, 'amount' => $total_amount, 'allocations' => $allocations, 'receipt' => $receipt_number],
            $admin_id, $_SERVER['REMOTE_ADDR'] ?? '');

        echo json_encode(['success' => true, 'message' => "Paiement enregistré avec succès (Reçu : {$receipt_number})", 'receipt' => $receipt_number, 'payment_id' => $new_payment_id]);
    } catch (\Throwable $e) {
        if ($inTransaction) { try { $conn->rollback(); } catch (\Throwable $re) {} }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Vue générale dossier étudiant (AJAX GET) ──────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_student_dossier_overview' && isset($_GET['student_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $student_id = $_GET['student_id'];
    try {
        $stmt = $conn->prepare("
            SELECT u.id, u.name, u.nom, u.prenom, u.email, u.class_id, u.avatar,
                   c.name as class_name,
                   tf.total_amount, tf.due_date, tf.academic_year,
                   COALESCE(sd.discount_amount, 0) as discount_amount,
                   (COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0)) as net_amount,
                   COALESCE(paid.total_paid, 0) as amount_paid
            FROM users u
            LEFT JOIN classes c ON u.class_id = c.id
            LEFT JOIN tuition_fees tf ON c.id = tf.class_id AND tf.academic_year = ?
            LEFT JOIN student_discounts sd ON sd.student_id = u.id AND sd.tuition_fee_id = tf.id AND sd.academic_year = ?
            LEFT JOIN (
                SELECT student_id, SUM(amount_paid) as total_paid
                FROM student_payments WHERE status = 'validated'
                GROUP BY student_id
            ) paid ON u.id = paid.student_id
            WHERE u.id = ?
        ");
        $stmt->bind_param("sss", $annee_filtre, $annee_filtre, $student_id);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        if (!$info) throw new Exception("Étudiant introuvable.");

        $net   = floatval($info['net_amount']);
        $paid  = floatval($info['amount_paid']);
        $remaining = max(0, $net - $paid);
        $pct   = $net > 0 ? round(($paid / $net) * 100, 1) : 0;

        // Statut global
        $status = 'unpaid';
        if ($remaining <= 0 && $net > 0)        $status = 'paid';
        elseif ($remaining > 0 && $paid > 0) {
            $od = $conn->prepare("SELECT COUNT(*) as c FROM payment_deadlines WHERE student_id = ? AND status IN ('overdue','partial') AND due_date < CURDATE()");
            $od->bind_param("s", $student_id);
            $od->execute();
            $oc = $od->get_result()->fetch_assoc()['c'];
            $status = $oc > 0 ? 'overdue' : 'partial';
        } elseif ($remaining > 0 && $info['due_date'] && $info['due_date'] < date('Y-m-d')) {
            $status = 'overdue';
        }

        // Prochaine échéance
        $next_deadline = null;
        $nd = $conn->prepare("SELECT due_date, amount_due, COALESCE(amount_paid,0) as amount_paid FROM payment_deadlines WHERE student_id = ? AND status IN ('pending','partial','overdue') ORDER BY due_date ASC LIMIT 1");
        $nd->bind_param("s", $student_id);
        $nd->execute();
        $next_deadline = $nd->get_result()->fetch_assoc();

        // Nombre de messages non lus par admin (messages étudiant → admin non lus)
        $unread_stmt = $conn->prepare("SELECT COUNT(*) as c FROM finance_messages WHERE student_id = ? AND (read_by IS NULL OR read_by = '') AND status NOT IN ('resolved','closed')");
        $unread_stmt->bind_param("s", $student_id);
        $unread_stmt->execute();
        $unread_count = $unread_stmt->get_result()->fetch_assoc()['c'];

        // Initiales fallback
        $initials = '';
        $parts = explode(' ', trim($info['name']));
        foreach (array_slice($parts, 0, 2) as $p) $initials .= mb_strtoupper(mb_substr($p, 0, 1));

        echo json_encode([
            'success'       => true,
            'student'       => [
                'id'         => $info['id'],
                'name'       => $info['name'],
                'class_name' => $info['class_name'],
                'avatar'     => $info['avatar'],
                'initials'   => $initials,
                'academic_year' => $info['academic_year'] ?? $annee_filtre,
            ],
            'total_amount'  => floatval($info['total_amount']),
            'discount'      => floatval($info['discount_amount']),
            'net_amount'    => $net,
            'amount_paid'   => $paid,
            'remaining'     => $remaining,
            'pct'           => $pct,
            'status'        => $status,
            'next_deadline' => $next_deadline,
            'unread_messages' => (int)$unread_count,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Fil de messages d'un étudiant (AJAX GET) ──────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_student_messages' && isset($_GET['student_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    $student_id = $_GET['student_id'];
    try {
        // Marquer comme lus par l'admin les messages de l'étudiant non lus
        $upd = $conn->prepare("UPDATE finance_messages SET read_by = ?, read_date = NOW() WHERE student_id = ? AND (read_by IS NULL OR read_by = '') AND status NOT IN ('resolved','closed')");
        $upd->bind_param("ss", $admin_id, $student_id);
        $upd->execute();

        // Récupérer la/les conversation(s) de l'étudiant avec leur historique
        $stmt = $conn->prepare("SELECT fm.id, fm.subject, fm.status, fm.created_at FROM finance_messages fm WHERE fm.student_id = ? ORDER BY fm.created_at DESC");
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $msgs_res = $stmt->get_result();
        $threads  = [];
        while ($t = $msgs_res->fetch_assoc()) $threads[] = $t;

        // Historique trié ASC pour le fil conversationnel
        $history = [];
        if (!empty($threads)) {
            $ids_in = implode(',', array_map('intval', array_column($threads, 'id')));
            $h = $conn->query("
                SELECT fmh.id, fmh.message_id, fmh.user_id, fmh.user_type, fmh.message, fmh.created_at,
                       u.name as user_name
                FROM finance_message_history fmh
                LEFT JOIN users u ON fmh.user_id = u.id
                WHERE fmh.message_id IN ($ids_in)
                ORDER BY fmh.created_at ASC
            ");
            if ($h) while ($row = $h->fetch_assoc()) $history[] = $row;
        }

        // Conversation active (première non résolue)
        $active_thread = null;
        foreach ($threads as $t) {
            if (!in_array($t['status'], ['resolved', 'closed'])) { $active_thread = $t; break; }
        }
        if (!$active_thread && !empty($threads)) $active_thread = $threads[0];

        echo json_encode([
            'success'        => true,
            'threads'        => $threads,
            'history'        => $history,
            'active_thread'  => $active_thread,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Envoyer message admin → étudiant (AJAX POST) ─────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'send_finance_message') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $student_id = trim($data['student_id'] ?? '');
        $msg_text   = trim($data['message']    ?? '');
        if (!$student_id) throw new Exception("Étudiant requis.");
        if (!$msg_text)   throw new Exception("Message vide.");

        // Déduplication : même contenu dans les 10 dernières secondes
        $dup = $conn->prepare("SELECT fmh.id FROM finance_message_history fmh INNER JOIN finance_messages fm ON fmh.message_id = fm.id WHERE fm.student_id = ? AND fmh.user_id = ? AND fmh.user_type = 'admin' AND fmh.message = ? AND fmh.created_at >= NOW() - INTERVAL 10 SECOND LIMIT 1");
        $dup->bind_param("sss", $student_id, $admin_id, $msg_text);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $fdup = $conn->prepare("SELECT id FROM finance_messages WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
            $fdup->bind_param("s", $student_id);
            $fdup->execute();
            $tdup = $fdup->get_result()->fetch_assoc();
            echo json_encode(['success' => true, 'thread_id' => $tdup ? $tdup['id'] : 0, 'duplicate' => true]);
            exit();
        }

        // Trouver ou créer une conversation ouverte
        $find = $conn->prepare("SELECT id FROM finance_messages WHERE student_id = ? AND status NOT IN ('resolved','closed') ORDER BY created_at DESC LIMIT 1");
        $find->bind_param("s", $student_id);
        $find->execute();
        $thread = $find->get_result()->fetch_assoc();

        if ($thread) {
            $thread_id = $thread['id'];
            $upd = $conn->prepare("UPDATE finance_messages SET status='in_progress', updated_at=NOW() WHERE id=?");
            $upd->bind_param("i", $thread_id);
            $upd->execute();
        } else {
            $ins = $conn->prepare("INSERT INTO finance_messages (student_id, subject, message, status, priority) VALUES (?, 'Contact Administration', ?, 'in_progress', 'normal')");
            $ins->bind_param("ss", $student_id, $msg_text);
            $ins->execute();
            $thread_id = $conn->insert_id;
        }

        // Ajouter dans l'historique
        $hist = $conn->prepare("INSERT INTO finance_message_history (message_id, user_id, user_type, message) VALUES (?, ?, 'admin', ?)");
        $hist->bind_param("iss", $thread_id, $admin_id, $msg_text);
        $hist->execute();
        $hist_id = $hist->insert_id;

        // Notification pour l'étudiant (best-effort : n'échoue pas si la table n'accepte pas encore NULL)
        try {
            $notif_msg = "Nouveau message du service financier";
            $notif = $conn->prepare("INSERT INTO notifications (user_id, message, link, type, source_id, course_id) VALUES (?, ?, '../student/my_payments.php', 'payment', ?, NULL)");
            $notif->bind_param("ssi", $student_id, $notif_msg, $thread_id);
            $notif->execute();
        } catch (Exception $e) { /* notification non critique */ }

        echo json_encode(['success' => true, 'thread_id' => $thread_id, 'entry' => [
            'id'         => $hist_id,
            'message_id' => $thread_id,
            'user_id'    => $admin_id,
            'user_type'  => 'admin',
            'message'    => $msg_text,
            'created_at' => date('Y-m-d H:i:s'),
            'user_name'  => $_SESSION['name'] ?? 'Admin',
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Résoudre une conversation (AJAX POST) ─────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'resolve_conversation') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $thread_id  = intval($data['thread_id'] ?? 0);
        if ($thread_id <= 0) throw new Exception("ID invalide.");
        $upd = $conn->prepare("UPDATE finance_messages SET status='resolved', responded_by=?, response_date=NOW() WHERE id=?");
        $upd->bind_param("si", $admin_id, $thread_id);
        $upd->execute();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_payment':
                    $student_id = $_POST['student_id'];
                    $amount = floatval($_POST['amount']);
                    $payment_method = $_POST['payment_method'];
                    $payment_type = $_POST['payment_type'];
                    $reference = $_POST['reference'] ?? '';
                    $description = $_POST['description'] ?? '';
                    
                    // Récupérer les frais et calculer le solde restant dû
                    $tuition_query = "SELECT tf.id,
                                        (COALESCE(tf.total_amount, 0)
                                         - COALESCE(sd.discount_amount, 0)
                                         - COALESCE(SUM(sp.amount_paid), 0)) AS remaining_balance
                                    FROM tuition_fees tf
                                    JOIN users u ON u.class_id = tf.class_id
                                    LEFT JOIN student_discounts sd
                                        ON sd.student_id = u.id
                                        AND sd.tuition_fee_id = tf.id
                                        AND sd.academic_year = '$annee_filtre'
                                    LEFT JOIN student_payments sp
                                        ON sp.student_id = u.id
                                        AND sp.tuition_fee_id = tf.id
                                        AND sp.status = 'validated'
                                    WHERE u.id = ? AND tf.academic_year = '$annee_filtre'
                                    GROUP BY tf.id, tf.total_amount, sd.discount_amount";
                    $stmt = $conn->prepare($tuition_query);
                    $stmt->bind_param("s", $student_id);
                    $stmt->execute();
                    $tuition_result = $stmt->get_result();

                    if ($tuition_result->num_rows > 0) {
                        $tuition_fee = $tuition_result->fetch_assoc();

                        // Vérifier que le montant ne dépasse pas le solde restant
                        $remaining_balance = floatval($tuition_fee['remaining_balance']);
                        if ($amount <= 0) {
                            throw new Exception("Le montant doit être supérieur à 0");
                        }
                        if ($remaining_balance <= 0) {
                            throw new Exception("Cet étudiant a déjà réglé l'intégralité de ses frais");
                        }
                        if ($amount > $remaining_balance) {
                            throw new Exception(sprintf(
                                "Le montant saisi (%s FCFA) dépasse le solde restant dû (%s FCFA). Veuillez saisir un montant inférieur ou égal au solde.",
                                number_format($amount, 0, ',', ' '),
                                number_format($remaining_balance, 0, ',', ' ')
                            ));
                        }

                        // Générer un numéro de reçu unique
                        $receipt_number = 'REC-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        
                        // Insérer le paiement
                        $insert_payment = "INSERT INTO student_payments (student_id, tuition_fee_id, amount_paid, payment_method, 
                                         payment_type, reference_number, description, receipt_number, recorded_by, status) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'validated')";
                        $stmt = $conn->prepare($insert_payment);
                        $stmt->bind_param("sidssssss", $student_id, $tuition_fee['id'], $amount, $payment_method, 
                                        $payment_type, $reference, $description, $receipt_number, $admin_id);
                        
                        if ($stmt->execute()) {
                            $new_payment_id = $conn->insert_id;
                            // ── OHADA : génération automatique écriture comptable (optionnelle) ──
                            try { $conn->query("CALL GenererEcritureStudentPayment($new_payment_id)"); }
                            catch (\Throwable $ignored) {}
                            $message = "Paiement ajouté avec succès (Reçu: $receipt_number)";
                            $message_type = 'success';
                        } else {
                            throw new Exception("Erreur lors de l'ajout du paiement");
}
                    } else {
                        throw new Exception("Aucun frais de scolarité trouvé pour cet étudiant");
                    }
                    break;

                case 'add_tuition_fees':
                    $class_id = intval($_POST['class_id']);
                    $total_amount = floatval($_POST['total_amount']);
                    $registration_fee = floatval($_POST['registration_fee'] ?? 0);
                    $tuition_fee = floatval($_POST['tuition_fee'] ?? 0);
                    $insurance_fee = floatval($_POST['insurance_fee'] ?? 0);
                    $library_fee = floatval($_POST['library_fee'] ?? 0);
                    $practical_fee = floatval($_POST['practical_fee'] ?? 0);
                    $other_fees = floatval($_POST['other_fees'] ?? 0);
                    $due_date = $_POST['due_date'];
                    $installments = intval($_POST['installments'] ?? 1);
                    $description = $_POST['description'] ?? '';

                    // total_amount est toujours recalculé côté serveur = somme exacte des composants
                    $total_amount = $registration_fee + $tuition_fee + $insurance_fee + $library_fee + $practical_fee + $other_fees;

                    // Vérifier si les frais existent déjà pour cette classe
                    $check_query = "SELECT id FROM tuition_fees WHERE class_id = ? AND academic_year = '$annee_filtre'";
                    $stmt = $conn->prepare($check_query);
                    $stmt->bind_param("i", $class_id);
                    $stmt->execute();
                    $existing = $stmt->get_result();
                    
                    if ($existing->num_rows > 0) {
                        // Mettre à jour les frais existants
                        $update_query = "UPDATE tuition_fees SET total_amount = ?, registration_fee = ?, 
                                       tuition_fee = ?, insurance_fee = ?, library_fee = ?, practical_fee = ?, 
                                       other_fees = ?, due_date = ?, installments_count = ?, description = ? 
                                       WHERE class_id = ? AND academic_year = '$annee_filtre'";
                        $stmt = $conn->prepare($update_query);
                        $stmt->bind_param("dddddddsssi", $total_amount, $registration_fee, $tuition_fee, 
                                        $insurance_fee, $library_fee, $practical_fee, $other_fees, 
                                        $due_date, $installments, $description, $class_id);
                    } else {
                        // Créer de nouveaux frais
                        $insert_query = "INSERT INTO tuition_fees (class_id, academic_year, total_amount, 
                                       registration_fee, tuition_fee, insurance_fee, library_fee, practical_fee, 
                                       other_fees, due_date, installments_count, description, created_by, payment_plan_available) 
                                       VALUES (?, '$annee_filtre', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
                        $stmt = $conn->prepare($insert_query);
                        $stmt->bind_param("idddddddssss", $class_id, $total_amount, $registration_fee, 
                                        $tuition_fee, $insurance_fee, $library_fee, $practical_fee, 
                                        $other_fees, $due_date, $installments, $description, $admin_id);
                    }
                    
                    if ($stmt->execute()) {
                        $message = "Frais de scolarité configurés avec succès";
                        $message_type = 'success';
                    } else {
                        throw new Exception("Erreur lors de la configuration des frais");
                    }
                    break;

                case 'generate_deadlines':
                    $student_id = $_POST['student_id'];
                    $installments = intval($_POST['installments']);
                    $first_deadline = $_POST['first_deadline'];
                    
                    // Récupérer le tuition_fee_id pour cet étudiant
                    $tf_query = "SELECT tf.id, tf.total_amount FROM tuition_fees tf 
                                JOIN users u ON u.class_id = tf.class_id 
                                WHERE u.id = ? AND tf.academic_year = '$annee_filtre'";
                    $stmt = $conn->prepare($tf_query);
                    $stmt->bind_param("s", $student_id);
                    $stmt->execute();
                    $tf_result = $stmt->get_result()->fetch_assoc();
                    
                    if ($tf_result) {
                        // Utiliser la procédure stockée pour générer les échéances
                        $call_stmt = $conn->prepare("CALL GeneratePaymentDeadlines(?, ?, ?, ?, ?)");
                        $call_stmt->bind_param("siiss", $student_id, $tf_result['id'], $installments, $first_deadline, $admin_id);
                        
                        if ($call_stmt->execute()) {
                            $message = "Échéances générées avec succès pour l'étudiant";
                            $message_type = 'success';
                        } else {
                            throw new Exception("Erreur lors de la génération des échéances");
                        }
                        $call_stmt->close();
                    } else {
                        throw new Exception("Aucun frais de scolarité trouvé pour cet étudiant");
                    }
                    break;

                case 'add_discount':
                    $student_id     = $_POST['student_id'];
                    $discount_type  = $_POST['discount_type'];   // 'amount' | 'percent'
                    $discount_value = floatval($_POST['discount_value']);
                    $reason         = $_POST['reason'] ?? '';

                    // Récupérer scolarité pure + total de la classe
                    $tf_query = "SELECT tf.id, tf.total_amount, COALESCE(tf.tuition_fee, 0) AS tuition_fee
                                 FROM tuition_fees tf
                                 JOIN users u ON u.class_id = tf.class_id
                                 WHERE u.id = ? AND tf.academic_year = '$annee_filtre'";
                    $stmt = $conn->prepare($tf_query);
                    $stmt->bind_param("s", $student_id);
                    $stmt->execute();
                    $tf_row = $stmt->get_result()->fetch_assoc();

                    if (!$tf_row) {
                        throw new Exception("Aucun frais de scolarité trouvé pour cet étudiant");
                    }

                    // La réduction s'applique sur la scolarité pure (fallback total pour lignes legacy)
                    $discount_base = $tf_row['tuition_fee'] > 0 ? $tf_row['tuition_fee'] : $tf_row['total_amount'];

                    // Calculer le montant de la réduction
                    if ($discount_type === 'percent') {
                        if ($discount_value <= 0 || $discount_value > 100) {
                            throw new Exception("Le pourcentage doit être compris entre 1 et 100");
                        }
                        $discount_amount = round($discount_base * $discount_value / 100, 2);
                    } else {
                        $discount_amount = $discount_value;
                    }

                    if ($discount_amount <= 0) {
                        throw new Exception("Le montant de la réduction doit être supérieur à 0");
                    }
                    if ($discount_amount > $discount_base) {
                        throw new Exception("La réduction ne peut pas dépasser la scolarité de base (" . number_format($discount_base, 0, ',', ' ') . " FCFA)");
                    }

                    // Supprimer toute réduction existante pour cet étudiant / cette année
                    $del = $conn->prepare("DELETE FROM student_discounts WHERE student_id = ? AND academic_year = '$annee_filtre'");
                    $del->bind_param("s", $student_id);
                    $del->execute();

                    // Insérer la nouvelle réduction
                    $ins = $conn->prepare("INSERT INTO student_discounts
                        (student_id, tuition_fee_id, discount_type, discount_value, discount_amount, reason, academic_year, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, '$annee_filtre', ?)");
                    $ins->bind_param("sissdss", $student_id, $tf_row['id'], $discount_type, $discount_value, $discount_amount, $reason, $admin_id);

                    if ($ins->execute()) {
                        $label = $discount_type === 'percent'
                            ? number_format($discount_value, 0) . '%'
                            : number_format($discount_amount, 0, ',', ' ') . ' FCFA';
                        $message = "Réduction de $label appliquée avec succès";
                        $message_type = 'success';
                    } else {
                        throw new Exception("Erreur lors de l'enregistrement de la réduction");
                    }
                    break;

                case 'respond_message':
                    $message_id = intval($_POST['message_id']);
                    $response = $_POST['response'];
                    $new_status = $_POST['new_status'] ?? 'resolved';
                    
                    $update_query = "UPDATE finance_messages SET response = ?, status = ?, 
                                   responded_by = ?, response_date = NOW() 
                                   WHERE id = ?";
                    $stmt = $conn->prepare($update_query);
                    $stmt->bind_param("sssi", $response, $new_status, $admin_id, $message_id);
                    
                    if ($stmt->execute()) {
                        // Ajouter à l'historique
                        $history_query = "INSERT INTO finance_message_history (message_id, user_id, user_type, message) 
                                        VALUES (?, ?, 'admin', ?)";
                        $hist_stmt = $conn->prepare($history_query);
                        $hist_stmt->bind_param("iss", $message_id, $admin_id, $response);
                        $hist_stmt->execute();
                        
                        $message = "Réponse envoyée avec succès";
                        $message_type = 'success';
                    } else {
                        throw new Exception("Erreur lors de l'envoi de la réponse");
                    }
                    break;

                case 'update_message_status':
                    $message_id = intval($_POST['message_id']);
                    $new_status = $_POST['status'];
                    
                    $update_query = "UPDATE finance_messages SET status = ?, read_by = ?, read_date = NOW() 
                                   WHERE id = ?";
                    $stmt = $conn->prepare($update_query);
                    $stmt->bind_param("ssi", $new_status, $admin_id, $message_id);
                    
                    if ($stmt->execute()) {
                        $message = "Statut du message mis à jour";
                        $message_type = 'success';
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'error';
    }
    // PRG : après un POST réussi, rediriger en GET pour éviter les double-soumissions
    if ($message_type === 'success') {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type']    = 'success';
        $redirect = strtok($_SERVER['REQUEST_URI'], '?') . '?annee=' . urlencode($annee_filtre);
        header('Location: ' . $redirect);
        exit();
    }
}

try {
    // ── Création automatique de la table student_discounts si elle n'existe pas ──
    $conn->query("
        CREATE TABLE IF NOT EXISTS student_discounts (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            student_id      VARCHAR(50)     NOT NULL,
            tuition_fee_id  INT             NOT NULL,
            academic_year   VARCHAR(9)      NOT NULL DEFAULT '2024-2025',
            discount_type   ENUM('amount','percent') NOT NULL DEFAULT 'amount',
            discount_value  DECIMAL(10,2)   NOT NULL,
            discount_amount DECIMAL(10,2)   NOT NULL,
            reason          TEXT            NULL,
            created_by      VARCHAR(50)     NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_student_year (student_id, academic_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    // ─────────────────────────────────────────────────────────────────────────────
    // Corriger la collation si la table a été créée avec utf8mb4_unicode_ci
    $conn->query("ALTER TABLE student_discounts
        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

    // ── Migrations student_payments : support annulation (idempotent) ─────────
    $conn->query("ALTER TABLE student_payments MODIFY COLUMN status ENUM('pending','validated','cancelled') DEFAULT 'validated'");
    foreach ([
        'cancel_reason' => 'cancel_reason TEXT NULL',
        'cancelled_by'  => 'cancelled_by VARCHAR(255) NULL',
        'cancelled_at'  => 'cancelled_at DATETIME NULL',
    ] as $col_name => $col_def) {
        $col_check = $conn->query(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'student_payments'
               AND COLUMN_NAME  = '$col_name'"
        );
        if ($col_check && $col_check->num_rows === 0) {
            $conn->query("ALTER TABLE student_payments ADD COLUMN $col_def");
        }
    }
    // ──────────────────────────────────────────────────────────────────────────

    // Récupération des statistiques globales
    $stats_query = "
        SELECT
            COALESCE(SUM(tf.total_amount), 0) as total_expected,
            COALESCE(SUM(sp.amount_paid), 0) as total_collected,
            COUNT(DISTINCT u.id) as total_students,
            COUNT(DISTINCT CASE WHEN sp.amount_paid >= tf.total_amount THEN u.id END) as students_paid,
            COUNT(DISTINCT CASE WHEN tf.due_date < CURDATE() AND COALESCE(sp.amount_paid, 0) < tf.total_amount THEN u.id END) as students_overdue
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        LEFT JOIN (
            SELECT class_id, MAX(id) AS id
            FROM tuition_fees
            WHERE academic_year = '$annee_filtre'
            GROUP BY class_id
        ) tf_dedup ON tf_dedup.class_id = c.id
        LEFT JOIN tuition_fees tf ON tf.id = tf_dedup.id
        LEFT JOIN (
            SELECT student_id, tuition_fee_id, SUM(amount_paid) as amount_paid
            FROM student_payments
            WHERE status = 'validated'
            GROUP BY student_id, tuition_fee_id
        ) sp ON u.id = sp.student_id AND tf.id = sp.tuition_fee_id
        WHERE u.role = 'student' AND u.blocked = 0 AND u.status = 'active'
    ";
    $stats_result = $conn->query($stats_query);
    $stats = $stats_result->fetch_assoc();

    $total_expected = $stats['total_expected'] ?? 0;
    $total_collected = $stats['total_collected'] ?? 0;
    $total_pending = $total_expected - $total_collected;
    $students_paid = $stats['students_paid'] ?? 0;
    $overdue_students = $stats['students_overdue'] ?? 0;
    $total_students = $stats['total_students'] ?? 0;

    // Récupération des messages non lus
    $unread_messages_query = "SELECT COUNT(*) as count FROM finance_messages WHERE status IN ('new', 'in_progress')";
    $unread_result = $conn->query($unread_messages_query);
    $unread_messages_count = $unread_result->fetch_assoc()['count'];

    // Récupération des étudiants avec leur statut de paiement par classe
    $students_by_class_query = "
        SELECT 
            c.id as class_id,
            c.name as class_name,
            u.id as student_id,
            u.name as student_name,
            u.email as student_email,
            COALESCE(tf.total_amount, 0) as total_amount,
            COALESCE(sd.discount_amount, 0) as discount_amount,
            sd.discount_type as discount_type,
            sd.discount_value as discount_value,
            sd.reason as discount_reason,
            (COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0)) as net_amount,
            tf.id as tuition_fee_id,
            tf.due_date,
            tf.installments_count,
            COALESCE(payments.total_paid, 0) as total_paid,
            ((COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0)) - COALESCE(payments.total_paid, 0)) as remaining_balance,
            CASE
                WHEN COALESCE(tf.total_amount, 0) = 0 THEN 'no_fees'
                -- Branche avec échéances générées : statut dérivé de payment_deadlines
                WHEN (SELECT COUNT(*) FROM payment_deadlines pd2 WHERE pd2.student_id = u.id AND pd2.tuition_fee_id = tf.id) > 0
                     AND ((COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0)) - COALESCE(payments.total_paid, 0)) <= 0
                     THEN 'paid'
                WHEN (SELECT COUNT(*) FROM payment_deadlines pd2 WHERE pd2.student_id = u.id AND pd2.tuition_fee_id = tf.id) > 0
                     AND (SELECT COUNT(*) FROM payment_deadlines pd3
                          WHERE pd3.student_id = u.id AND pd3.tuition_fee_id = tf.id
                            AND (pd3.status = 'overdue' OR (pd3.status = 'partial' AND pd3.due_date < CURDATE()))) > 0
                     THEN 'overdue'
                WHEN (SELECT COUNT(*) FROM payment_deadlines pd2 WHERE pd2.student_id = u.id AND pd2.tuition_fee_id = tf.id) > 0
                     AND COALESCE(payments.total_paid, 0) > 0
                     THEN 'partial'
                WHEN (SELECT COUNT(*) FROM payment_deadlines pd2 WHERE pd2.student_id = u.id AND pd2.tuition_fee_id = tf.id) > 0
                     THEN 'unpaid'
                -- Fallback sans échéances : basé sur due_date globale
                WHEN ((COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0)) - COALESCE(payments.total_paid, 0)) <= 0
                     THEN 'paid'
                WHEN tf.due_date < CURDATE() AND ((COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0)) - COALESCE(payments.total_paid, 0)) > 0
                     THEN 'overdue'
                WHEN COALESCE(payments.total_paid, 0) > 0 THEN 'partial'
                ELSE 'unpaid'
            END as payment_status,
            CASE 
                WHEN (COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0)) > 0 
                THEN ROUND((COALESCE(payments.total_paid, 0) / (COALESCE(tf.total_amount, 0) - COALESCE(sd.discount_amount, 0))) * 100, 1)
                ELSE 0
            END as payment_percentage,
            payments.last_payment_date,
            COALESCE(payments.payment_count, 0) as payment_count,
            (SELECT COUNT(*) FROM payment_deadlines pd WHERE pd.student_id = u.id AND pd.tuition_fee_id = tf.id) as has_deadlines
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        LEFT JOIN (
            SELECT class_id, MAX(id) AS id
            FROM tuition_fees
            WHERE academic_year = '$annee_filtre'
            GROUP BY class_id
        ) tf_dedup ON tf_dedup.class_id = c.id
        LEFT JOIN tuition_fees tf ON tf.id = tf_dedup.id
        LEFT JOIN student_discounts sd ON u.id COLLATE utf8mb4_general_ci = sd.student_id COLLATE utf8mb4_general_ci AND tf.id = sd.tuition_fee_id AND sd.academic_year = '$annee_filtre'
        LEFT JOIN (
            SELECT 
                student_id, 
                tuition_fee_id, 
                SUM(amount_paid) as total_paid,
                MAX(payment_date) as last_payment_date,
                COUNT(*) as payment_count
            FROM student_payments 
            WHERE status = 'validated' 
            GROUP BY student_id, tuition_fee_id
        ) payments ON u.id = payments.student_id AND tf.id = payments.tuition_fee_id
        WHERE u.role = 'student' AND u.blocked = 0 AND u.status = 'active'
        ORDER BY c.name, u.name
    ";
    $students_result = $conn->query($students_by_class_query);
    $students_by_class = [];
    if ($students_result === false) {
        throw new Exception("Erreur requête étudiants : " . $conn->error);
    }
    while ($student = $students_result->fetch_assoc()) {
        $class_name = $student['class_name'] ?? 'Non assigné';
        if (!isset($students_by_class[$class_name])) {
            $students_by_class[$class_name] = [];
        }
        $students_by_class[$class_name][] = $student;
    }

    // Récupération des classes pour les formulaires
    $classes_query = "SELECT id, name FROM classes ORDER BY name";
    $classes_result = $conn->query($classes_query);
    $classes = [];
    if ($classes_result) {
        while ($class = $classes_result->fetch_assoc()) {
            $classes[] = $class;
        }
    }

    // Récupération de l'historique des paiements récents (validés + annulés)
    $recent_payments_query = "
        SELECT
            sp.*,
            u.name as student_name,
            u.id as student_id,
            c.name as class_name,
            recorder.name as recorded_by_name,
            canceller.name as cancelled_by_name
        FROM student_payments sp
        JOIN users u ON sp.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        LEFT JOIN users recorder ON sp.recorded_by = recorder.id
        LEFT JOIN users canceller ON sp.cancelled_by = canceller.id
        WHERE sp.status IN ('validated', 'cancelled')
        ORDER BY sp.payment_date DESC
        LIMIT 50
    ";
    $payments_result = $conn->query($recent_payments_query);
    $recent_payments = [];
    if ($payments_result) {
        while ($payment = $payments_result->fetch_assoc()) {
            $recent_payments[] = $payment;
        }
    }

    // Récupération des statistiques par classe
    $class_stats_query = "
        SELECT 
            c.id as class_id,
            c.name as class_name,
            COUNT(u.id) as student_count,
            COALESCE(tf.total_amount, 0) as expected_per_student,
            COALESCE(SUM(payments.total_paid), 0) as total_collected,
            CASE 
                WHEN COUNT(u.id) > 0 AND COALESCE(tf.total_amount, 0) > 0
                THEN ROUND((COALESCE(SUM(payments.total_paid), 0) / (COUNT(u.id) * tf.total_amount)) * 100, 1)
                ELSE 0
            END as collection_rate,
            COUNT(CASE WHEN payments.total_paid >= tf.total_amount THEN 1 END) as students_paid_count
        FROM classes c
        LEFT JOIN users u ON c.id = u.class_id AND u.role = 'student' AND u.blocked = 0 AND u.status = 'active'
        LEFT JOIN tuition_fees tf ON c.id = tf.class_id AND tf.academic_year = '$annee_filtre'
        LEFT JOIN (
            SELECT student_id, tuition_fee_id, SUM(amount_paid) as total_paid
            FROM student_payments 
            WHERE status = 'validated' 
            GROUP BY student_id, tuition_fee_id
        ) payments ON u.id = payments.student_id AND tf.id = payments.tuition_fee_id
        WHERE EXISTS (SELECT 1 FROM users WHERE class_id = c.id AND role = 'student' AND status = 'active')
        GROUP BY c.id, tf.total_amount
        ORDER BY c.name
    ";
    $class_stats_result = $conn->query($class_stats_query);
    $class_statistics = [];
    if ($class_stats_result) {
        while ($class_stat = $class_stats_result->fetch_assoc()) {
            $class_statistics[] = $class_stat;
        }
    }

    // Récupération des messages des étudiants
    $messages_query = "
        SELECT 
            fm.*,
            u.name as student_name,
            u.email as student_email,
            c.name as class_name,
            responder.name as responded_by_name
        FROM finance_messages fm
        JOIN users u ON fm.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        LEFT JOIN users responder ON fm.responded_by = responder.id
        ORDER BY 
            CASE fm.priority
                WHEN 'urgent' THEN 1
                WHEN 'high' THEN 2
                WHEN 'normal' THEN 3
                WHEN 'low' THEN 4
            END,
            fm.created_at DESC
        LIMIT 50
    ";
    $messages_result = $conn->query($messages_query);
    $finance_messages = [];
    if ($messages_result) {
        while ($msg = $messages_result->fetch_assoc()) {
            $finance_messages[] = $msg;
        }
    }

} catch (Exception $e) {
    error_log("Erreur payment admin: " . $e->getMessage());
    $error_message = "Erreur SQL : " . $e->getMessage();
    
    // Valeurs par défaut
    $total_expected = $total_collected = $total_pending = $students_paid = $overdue_students = $total_students = 0;
    $students_by_class = $classes = $recent_payments = $class_statistics = $finance_messages = [];
    $unread_messages_count = 0;
}

function safe_number_format($number, $decimals = 0) {
    return is_null($number) || !is_numeric($number) ? '0' : number_format($number, $decimals, ',', ' ');
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Paiements - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --card-bg: rgba(255, 255, 255, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            color: var(--text-light);
            min-height: 100vh;
        }

        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid var(--border-color);
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        h1 {
            font-size: 28px;
            color: var(--accent-color);
            margin-bottom: 15px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        nav ul {
            list-style: none;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 5px;
        }

        nav a {
            color: var(--text-light);
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            position: relative;
        }

        nav a:hover, nav a.active {
            background: rgba(3, 155, 229, 0.1);
            transform: translateY(-2px);
        }

        .badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--danger-color);
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: bold;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        .alert.show {
            display: flex;
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            border: 1px solid var(--danger-color);
            color: var(--danger-color);
        }

        .page-header {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--accent-color);
            font-size: 24px;
        }

        .page-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .btn-primary { background: var(--accent-color); color: white; }
        .btn-success { background: var(--success-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }
        .btn-info { background: var(--info-color); color: white; }
        .btn-small { padding: 8px 12px; font-size: 12px; }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .stat-card.success::before { background: var(--success-color); }
        .stat-card.warning::before { background: var(--warning-color); }
        .stat-card.danger::before { background: var(--danger-color); }
        .stat-card.info::before { background: var(--info-color); }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .stat-title {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #ccc;
            font-weight: 600;
        }

        .stat-icon {
            font-size: 24px;
            color: var(--accent-color);
        }

        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .stat-change {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
            color: #ccc;
        }

        .tabs {
            display: flex;
            background: var(--card-bg);
            border-radius: 12px 12px 0 0;
            border: 1px solid var(--border-color);
            border-bottom: none;
            margin-bottom: 0;
        }

        .tab-button {
            padding: 15px 25px;
            background: transparent;
            border: none;
            color: #ccc;
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 12px 12px 0 0;
            font-weight: 500;
            position: relative;
        }

        .tab-button.active {
            background: var(--accent-color);
            color: white;
        }

        .tab-content {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0 0 12px 12px;
            padding: 25px;
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .class-section {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }

        .class-header {
            padding: 15px 20px;
            background: var(--accent-color);
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .class-title {
            font-weight: bold;
            font-size: 16px;
        }

        .class-stats {
            display: flex;
            gap: 15px;
            font-size: 14px;
        }

        .class-content {
            padding: 20px;
            display: none;
        }

        .class-content.active {
            display: block;
        }

        .student-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .student-table th,
        .student-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .student-table th {
            background: rgba(255, 255, 255, 0.1);
            color: var(--accent-color);
            font-weight: 600;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: white;
        }

        .status-paid { background: var(--success-color); }
        .status-partial { background: var(--warning-color); }
        .status-overdue { background: var(--danger-color); }
        .status-unpaid { background: #95a5a6; }
        .status-no-fees { background: var(--info-color); }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--primary-bg);
            margin: 2% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            border: 1px solid var(--border-color);
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            color: var(--accent-color);
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-close {
            color: #ccc;
            font-size: 24px;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .modal-close:hover {
            color: var(--text-light);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #ccc;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 14px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 2px rgba(3, 155, 229, 0.2);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--danger-color), var(--warning-color), var(--success-color));
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .message-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--info-color);
            transition: all 0.3s ease;
        }

        .message-item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateX(5px);
        }

        .message-item.urgent {
            border-left-color: var(--danger-color);
        }

        .message-item.high {
            border-left-color: var(--warning-color);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .message-student {
            font-weight: bold;
            color: var(--accent-color);
        }

        .message-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-new { background: var(--warning-color); color: white; }
        .status-read { background: var(--info-color); color: white; }
        .status-in_progress { background: #9b59b6; color: white; }
        .status-resolved { background: var(--success-color); color: white; }
        .status-closed { background: #95a5a6; color: white; }

        .priority-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }

        .priority-urgent { background: var(--danger-color); color: white; }
        .priority-high { background: var(--warning-color); color: white; }
        .priority-normal { background: var(--info-color); color: white; }
        .priority-low { background: #95a5a6; color: white; }

        select {
            background-color: #f0f0f0;
            color: #333;
            border: 1px solid #ccc;
            padding: 5px;
            border-radius: 4px;
        }

        option {
            background: #0c2d48;
            color: #ffffff;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @media (max-width: 768px) {
            .container { padding: 20px 15px; }
            .page-header { flex-direction: column; align-items: stretch; }
            .page-actions { justify-content: center; }
            .stats-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
            .tabs { flex-wrap: wrap; }
            .class-stats { flex-direction: column; gap: 5px; }
            .student-table { font-size: 12px; }
            .student-table th, .student-table td { padding: 8px; }
        }

        /* ── Tableau de ventilation du paiement ── */
        .alloc-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 8px; }
        .alloc-table th { background: rgba(255,255,255,0.1); color: var(--accent-color); padding: 8px 10px; text-align: left; font-weight: 600; }
        .alloc-table td { padding: 7px 10px; border-bottom: 1px solid rgba(255,255,255,0.06); vertical-align: middle; }
        .alloc-table tr:hover td { background: rgba(255,255,255,0.03); }
        .alloc-amount-input {
            width: 120px; padding: 5px 8px;
            background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.25);
            border-radius: 4px; color: white; font-size: 13px; text-align: right;
        }
        .alloc-amount-input:focus { outline: none; border-color: var(--accent-color); }
        .alloc-amount-input:disabled { opacity: 0.35; cursor: not-allowed; }

        /* ── Accordéon allocations dans l'historique ── */
        .pay-expand-row { display: none; background: rgba(3,155,229,0.05); }
        .pay-expand-row.open { display: table-row; }
        .pay-expand-cell { padding: 12px 20px !important; border-bottom: 2px solid var(--accent-color) !important; }
        .pay-alloc-card { font-size: 12px; color: #ccc; }
        .pay-alloc-card table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .pay-alloc-card td { padding: 5px 10px; border-bottom: 1px solid rgba(255,255,255,0.07); }
        .pay-alloc-card td:first-child { color: var(--accent-color); font-weight: 500; }

        /* ── Modal Dossier Financier Fullscreen ── */
        #studentDossierModal { background: rgba(0,0,0,0.85); z-index: 2000; }
        .dossier-modal-content {
            background: linear-gradient(135deg, #051e34 0%, #0c2d48 100%);
            margin: 0; width: 100%; height: 100%; max-width: none;
            border-radius: 0; display: flex; flex-direction: column; overflow: hidden;
        }
        .dossier-header {
            background: var(--secondary-bg); padding: 14px 24px;
            display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid var(--border-color); flex-shrink: 0;
        }
        .dossier-header h2 { color: var(--accent-color); font-size: 18px; display:flex; align-items:center; gap:10px; }
        .dossier-close { color: #ccc; font-size: 28px; cursor: pointer; line-height:1; }
        .dossier-close:hover { color: white; }
        .dossier-tabs {
            display: flex; background: rgba(0,0,0,0.2);
            border-bottom: 1px solid var(--border-color); flex-shrink: 0; flex-wrap: wrap;
        }
        .dossier-tab-btn {
            padding: 13px 22px; background: transparent; border: none;
            color: #aaa; cursor: pointer; font-size: 14px; font-weight: 500;
            transition: all .2s; position: relative; white-space: nowrap;
        }
        .dossier-tab-btn:hover { color: white; background: rgba(255,255,255,0.05); }
        .dossier-tab-btn.active { color: var(--accent-color); border-bottom: 3px solid var(--accent-color); }
        .dossier-tab-btn .tab-badge {
            position: absolute; top: 6px; right: 6px;
            background: var(--danger-color); color: white;
            font-size: 10px; padding: 1px 5px; border-radius: 8px; font-weight: bold;
        }
        .dossier-body { flex: 1; overflow-y: auto; padding: 24px; }
        .dossier-tab-content { display: none; }
        .dossier-tab-content.active { display: block; }

        /* Vue générale — circular gauge */
        .dossier-overview-grid {
            display: grid; grid-template-columns: 240px 1fr; gap: 24px; margin-bottom: 24px;
        }
        .dossier-profile { text-align: center; }
        .dossier-avatar {
            width: 90px; height: 90px; border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-color), #1a4a8e);
            display: flex; align-items: center; justify-content: center;
            font-size: 32px; font-weight: bold; color: white;
            margin: 0 auto 12px; overflow: hidden;
        }
        .dossier-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .dossier-student-name { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
        .dossier-student-id { color: #aaa; font-size: 13px; font-family: monospace; margin-bottom: 4px; }
        .dossier-student-class { color: var(--accent-color); font-size: 13px; }

        .circular-gauge-wrap { display: flex; flex-direction: column; align-items: center; margin-top: 16px; }
        .circular-gauge {
            width: 110px; height: 110px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: conic-gradient(var(--success-color) 0% var(--pct, 0%), rgba(255,255,255,0.1) var(--pct, 0%) 100%);
            box-shadow: 0 0 0 6px rgba(0,0,0,0.2);
        }
        .circular-gauge-inner {
            width: 80px; height: 80px; border-radius: 50%;
            background: #0c2d48; display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: bold; color: var(--success-color);
        }
        .circular-gauge-label { margin-top: 8px; font-size: 12px; color: #aaa; }

        .dossier-cards-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px;
        }
        .dossier-card {
            background: rgba(255,255,255,0.06); border-radius: 10px;
            padding: 16px; border: 1px solid var(--border-color); text-align: center;
        }
        .dossier-card-value { font-size: 20px; font-weight: bold; margin-bottom: 4px; }
        .dossier-card-label { font-size: 12px; color: #aaa; }
        .dossier-status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 18px; border-radius: 20px; font-size: 13px; font-weight: 600;
            margin-top: 14px;
        }

        /* Messaging bubbles */
        .dossier-chat-wrap {
            display: flex; flex-direction: column; height: calc(100vh - 260px); min-height: 300px;
        }
        .dossier-chat-messages {
            flex: 1; overflow-y: auto; padding: 12px 0; display: flex; flex-direction: column; gap: 10px;
        }
        .chat-bubble-wrap { display: flex; gap: 10px; max-width: 75%; }
        .chat-bubble-wrap.student-bubble { align-self: flex-start; }
        .chat-bubble-wrap.admin-bubble { align-self: flex-end; flex-direction: row-reverse; }
        .chat-avatar-small {
            width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: bold; color: white;
        }
        .chat-bubble {
            padding: 10px 14px; border-radius: 14px; font-size: 13px; line-height: 1.5; max-width: 100%;
        }
        .student-bubble .chat-bubble { background: rgba(255,255,255,0.1); border-radius: 14px 14px 14px 4px; color: #e0e0e0; }
        .admin-bubble .chat-bubble { background: var(--accent-color); border-radius: 14px 14px 4px 14px; color: white; }
        .chat-meta { font-size: 11px; color: #777; margin-top: 3px; }
        .admin-bubble .chat-meta { text-align: right; }

        .dossier-chat-input-area {
            padding-top: 12px; border-top: 1px solid var(--border-color); display: flex; gap: 10px; flex-shrink: 0;
        }
        .dossier-chat-input {
            flex: 1; padding: 10px 14px; background: rgba(255,255,255,0.08);
            border: 1px solid var(--border-color); border-radius: 20px;
            color: white; font-size: 13px; resize: none; min-height: 40px; max-height: 100px;
        }
        .dossier-chat-input:focus { outline: none; border-color: var(--accent-color); }
        .dossier-chat-send {
            background: var(--accent-color); color: white; border: none;
            border-radius: 20px; padding: 0 14px; height: 40px; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 6px; flex-shrink: 0;
            transition: background .2s, opacity .2s; align-self: flex-end;
            font-size: 13px; white-space: nowrap;
        }
        .dossier-chat-send:hover:not(:disabled) { background: #0288c7; }
        .dossier-chat-send:disabled { opacity: 0.7; cursor: not-allowed; }
        .dossier-spinner {
            text-align: center; padding: 60px 20px; color: #aaa;
        }
        .dossier-spinner i { font-size: 36px; color: var(--accent-color); margin-bottom: 14px; display: block; }
        @media (max-width: 768px) {
            .dossier-overview-grid { grid-template-columns: 1fr; }
            .dossier-body { padding: 14px; }
        }
    </style>
    <meta name="csrf-token" content="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
    <script>
    (function() {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!token) return;
        window.CSRF_TOKEN = token;
        const originalFetch = window.fetch;
        window.fetch = function(url, options = {}) {
            const method = (options.method || 'GET').toUpperCase();
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
                        <a href="payment_dashboard.php" class="active">
                            <i class="fas fa-money-bill-wave"></i> Paiements
                            <span class="badge" id="global-unread-badge-nav" <?php echo $unread_messages_count > 0 ? '' : 'style="display:none;"'; ?>><?php echo $unread_messages_count; ?></span>
                        </a>
                    </li>
                    <li><a href="stats_recouvrement.php"><i class="fas fa-chart-line"></i> Statistiques</a></li>
                    <li><a href="payment_admin.php"><i class="fas fa-money-bill-wave"></i> Personnel</a></li>
                    <li><a href="comptabilite.php"><i class="fas fa-calculator"></i> Comptabilité</a></li>
                    <li><a href="admin_profile.php"><i class="fas fa-user-cog"></i> Profil</a></li>
                    <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="container">
        <!-- Messages d'alerte -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?> show">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="alert alert-error show">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
        <?php endif; ?>

        <div class="alert alert-success" id="successAlert">
            <i class="fas fa-check-circle"></i>
            <span id="successMessage"></span>
        </div>
        
        <div class="alert alert-error" id="errorAlert">
            <i class="fas fa-exclamation-circle"></i>
            <span id="errorMessage"></span>
        </div>

        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-money-bill-wave"></i>
                Gestion des Paiements
            </h2>
            <div class="page-actions">
                <!-- Sélecteur d'année académique -->
                <form method="GET" action="" style="display:inline-flex;align-items:center;gap:6px;margin-right:8px;">
                    <label for="annee_select" style="font-size:13px;color:#a0b4c8;white-space:nowrap;">
                        <i class="fas fa-calendar-alt"></i> Année :
                    </label>
                    <select id="annee_select" name="annee" onchange="this.form.submit()"
                            style="background:#0a2a45;color:#e0eaf5;border:1px solid #1e4060;border-radius:6px;padding:6px 10px;font-size:13px;cursor:pointer;">
                        <?php foreach ($annees_disponibles as $__a): ?>
                        <option value="<?php echo htmlspecialchars($__a); ?>"
                            <?php echo ($__a === $annee_filtre) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($__a); ?>
                        </option>
                        <?php endforeach; unset($__a); ?>
                    </select>
                </form>
                <button class="btn btn-primary" onclick="openModal('addPaymentModal')">
                    <i class="fas fa-plus"></i> Nouveau Paiement
                </button>
                <button class="btn btn-success" onclick="openModal('manageTuitionModal')">
                    <i class="fas fa-cog"></i> Gérer Frais
                </button>
                <button class="btn btn-warning" onclick="exportPayments()">
                    <i class="fas fa-download"></i> Export Excel
                </button>
                <a href="stats_recouvrement.php" class="btn btn-info" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                    <i class="fas fa-chart-line"></i> Statistiques
                </a>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-title">Total Encaissé</span>
                    <i class="stat-icon fas fa-coins"></i>
                </div>
                <div class="stat-value"><?php echo safe_number_format($total_collected); ?> FCFA</div>
                <div class="stat-change">
                    <i class="fas fa-arrow-up"></i> Année <?php echo htmlspecialchars($annee_filtre); ?>
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-header">
                    <span class="stat-title">En Attente</span>
                    <i class="stat-icon fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo safe_number_format($total_pending); ?> FCFA</div>
                <div class="stat-change">
                    <i class="fas fa-exclamation-triangle"></i> À recouvrer
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-header">
                    <span class="stat-title">Étudiants à Jour</span>
                    <i class="stat-icon fas fa-check-circle"></i>
                </div>
                <div class="stat-value"><?php echo safe_number_format($students_paid); ?></div>
                <div class="stat-change">
                    <i class="fas fa-percent"></i> <?php echo $total_students > 0 ? round(($students_paid / $total_students) * 100, 1) : 0; ?>% du total
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-header">
                    <span class="stat-title">Retards de Paiement</span>
                    <i class="stat-icon fas fa-exclamation-circle"></i>
                </div>
                <div class="stat-value"><?php echo safe_number_format($overdue_students); ?></div>
                <div class="stat-change">
                    <i class="fas fa-calendar-times"></i> En retard
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-header">
                    <span class="stat-title">Messages Non Lus</span>
                    <i class="stat-icon fas fa-envelope"></i>
                </div>
                <div class="stat-value" id="global-unread-stat-value"><?php echo $unread_messages_count; ?></div>
                <div class="stat-change">
                    <i class="fas fa-exclamation-circle"></i> À traiter
                </div>
            </div>
        </div>

        <!-- Onglets -->
        <div class="tabs">
            <button class="tab-button active" onclick="showTab('students')">
                <i class="fas fa-users"></i> Étudiants par Classe
            </button>
            <button class="tab-button" onclick="showTab('statistics')">
                <i class="fas fa-chart-bar"></i> Statistiques par Classe
            </button>
            <button class="tab-button" onclick="showTab('history')">
                <i class="fas fa-history"></i> Historique des Paiements
            </button>
            <button class="tab-button" onclick="showTab('messages')">
                <i class="fas fa-envelope"></i> Messages Étudiants
                <span class="badge" id="global-unread-badge-tab" <?php echo $unread_messages_count > 0 ? '' : 'style="display:none;"'; ?>><?php echo $unread_messages_count; ?></span>
            </button>
        </div>

        <!-- Contenu Étudiants par Classe -->
        <div class="tab-content active" id="students-tab">
            <?php if (empty($students_by_class)): ?>
                <div style="text-align: center; padding: 40px;">
                    <div style="font-size: 48px; margin-bottom: 20px; color: var(--accent-color);">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <h3 style="margin-bottom: 10px;">Aucune donnée disponible</h3>
                    <p style="color: #ccc; margin-bottom: 20px;">Configurez d'abord les frais de scolarité pour voir les étudiants.</p>
                    <button class="btn btn-primary" onclick="openModal('manageTuitionModal')">
                        <i class="fas fa-cog"></i> Configurer les Frais
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($students_by_class as $class_name => $students): ?>
                <?php
                    $class_id_val  = $students[0]['class_id'] ?? 0;
                    $badge_green   = count(array_filter($students, function($s) { return $s['payment_status'] === 'paid'; }));
                    $badge_orange  = count(array_filter($students, function($s) { return $s['payment_status'] === 'partial'; }));
                    $badge_red     = count(array_filter($students, function($s) { return $s['payment_status'] === 'overdue' || $s['payment_status'] === 'unpaid'; }));
                    $no_dl_raw     = array_values(array_filter($students, function($s) { return $s['has_deadlines'] == 0 && $s['total_amount'] > 0; }));
                    $no_dl_list    = array_map(function($s) { return ['id' => $s['student_id'], 'name' => $s['student_name']]; }, $no_dl_raw);
                    $no_dl_json    = htmlspecialchars(json_encode($no_dl_list), ENT_QUOTES, 'UTF-8');
                ?>
                <div class="class-section">
                    <div class="class-header" onclick="toggleClass('class-<?php echo md5($class_name); ?>')">
                        <div class="class-title">
                            <i class="fas fa-users"></i> <?php echo htmlspecialchars($class_name); ?>
                            <small style="font-weight:normal; margin-left:6px; opacity:0.8;"><?php echo count($students); ?> étudiants</small>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <?php if ($badge_green > 0): ?>
                            <span style="background:var(--success-color); color:white; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:bold;">
                                <i class="fas fa-check-circle"></i> <?php echo $badge_green; ?> à jour
                            </span>
                            <?php endif; ?>
                            <?php if ($badge_orange > 0): ?>
                            <span style="background:var(--warning-color); color:white; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:bold;">
                                <i class="fas fa-clock"></i> <?php echo $badge_orange; ?> partiel
                            </span>
                            <?php endif; ?>
                            <?php if ($badge_red > 0): ?>
                            <span style="background:var(--danger-color); color:white; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:bold;">
                                <i class="fas fa-exclamation-circle"></i> <?php echo $badge_red; ?> en retard
                            </span>
                            <?php endif; ?>
                            <?php if (!empty($no_dl_list) && $class_id_val): ?>
                            <button class="btn btn-small"
                                style="background:#e67e22; color:white; font-size:11px; padding:5px 10px;"
                                onclick="event.stopPropagation(); openClassDeadlinesModal(<?php echo $class_id_val; ?>, '<?php echo htmlspecialchars(addslashes($class_name)); ?>', <?php echo $no_dl_json; ?>)">
                                <i class="fas fa-calendar-plus"></i> Générer échéanciers classe
                            </button>
                            <?php endif; ?>
                            <?php if ($class_id_val): ?>
                            <a href="export_payments_excel.php?class_id=<?php echo $class_id_val; ?>&amp;annee=<?php echo urlencode($annee_filtre); ?>"
                               target="_blank"
                               class="btn btn-small"
                               style="background:#1a6b3c; color:white; font-size:11px; padding:5px 10px; text-decoration:none;"
                               onclick="event.stopPropagation()">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="class-content" id="class-<?php echo md5($class_name); ?>">
                        <table class="student-table">
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th>Email</th>
                                    <th>Total à Payer</th>
                                    <th>Montant Payé</th>
                                    <th>Restant</th>
                                    <th>Progression</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($student['student_name']); ?></strong><br>
                                        <small style="color: #ccc;"><?php echo htmlspecialchars($student['student_id']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['student_email']); ?></td>
                                    <td>
                                        <strong><?php echo safe_number_format($student['total_amount']); ?> FCFA</strong>
                                        <?php if ($student['discount_amount'] > 0): ?>
                                            <br><small style="color: var(--danger-color);">
                                                <i class="fas fa-tag"></i> -<?php echo safe_number_format($student['discount_amount']); ?> FCFA
                                                <?php if ($student['discount_type'] === 'percent'): ?>
                                                    (<?php echo $student['discount_value']; ?>%)
                                                <?php endif; ?>
                                            </small>
                                            <br><small style="color: var(--accent-color);">
                                                Net : <?php echo safe_number_format($student['net_amount']); ?> FCFA
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color: var(--success-color);"><?php echo safe_number_format($student['total_paid']); ?> FCFA</td>
                                    <td style="color: <?php echo $student['remaining_balance'] > 0 ? 'var(--warning-color)' : 'var(--success-color)'; ?>;">
                                        <?php echo safe_number_format($student['remaining_balance']); ?> FCFA
                                    </td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $student['payment_percentage']; ?>%"></div>
                                        </div>
                                        <small><?php echo $student['payment_percentage']; ?>%</small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $student['payment_status']; ?>">
                                            <?php
                                            $status_labels = [
                                                'paid' => 'Payé',
                                                'partial' => 'Partiel',
                                                'overdue' => 'En retard',
                                                'unpaid' => 'Non payé',
                                                'no_fees' => 'Pas de frais'
                                            ];
                                            echo $status_labels[$student['payment_status']] ?? 'Inconnu';
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-small btn-primary" onclick="addPaymentForStudent('<?php echo $student['student_id']; ?>', '<?php echo htmlspecialchars($student['student_name']); ?>')">
                                            <i class="fas fa-plus"></i> Paiement
                                        </button>
                                        <button class="btn btn-small" style="background:#8e44ad;color:white;"
                                            onclick="openDiscountModal('<?php echo $student['student_id']; ?>', '<?php echo htmlspecialchars(addslashes($student['student_name'])); ?>', <?php echo $student['total_amount']; ?>, <?php echo $student['discount_amount']; ?>)">
                                            <i class="fas fa-tag"></i> Réduction
                                        </button>
                                        <?php if ($student['tuition_fee_id']): ?>
                                        <?php if ($student['has_deadlines'] == 0): ?>
                                        <button class="btn btn-small btn-primary"
                                            onclick="manageDeadlines('<?php echo $student['student_id']; ?>', '<?php echo htmlspecialchars(addslashes($student['student_name'])); ?>', 0)">
                                            <i class="fas fa-calendar-plus"></i> Créer échéancier
                                        </button>
                                        <?php else: ?>
                                        <button class="btn btn-small btn-warning"
                                            onclick="manageDeadlines('<?php echo $student['student_id']; ?>', '<?php echo htmlspecialchars(addslashes($student['student_name'])); ?>', <?php echo intval($student['has_deadlines']); ?>)">
                                            <i class="fas fa-calendar-alt"></i> Modifier échéancier
                                            <span style="background:rgba(0,0,0,0.25); border-radius:10px; padding:1px 6px; font-size:11px; margin-left:2px;"><?php echo intval($student['has_deadlines']); ?></span>
                                        </button>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($student['payment_count'] > 0): ?>
                                        <button class="btn btn-small btn-info" onclick="viewPaymentHistory('<?php echo $student['student_id']; ?>')">
                                            <i class="fas fa-history"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-small" style="background:#1a4a6e;color:white;"
                                            onclick="openStudentDossier('<?php echo $student['student_id']; ?>',
                                                '<?php echo htmlspecialchars(addslashes($student['student_name'])); ?>')">
                                            <i class="fas fa-folder-open"></i> Dossier
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Contenu Statistiques par Classe -->
        <div class="tab-content" id="statistics-tab">
            <?php if (empty($class_statistics)): ?>
                <div style="text-align: center; padding: 40px; color: #ccc;">
                    <i class="fas fa-chart-bar" style="font-size: 48px; margin-bottom: 20px;"></i>
                    <h3>Aucune statistique disponible</h3>
                    <p>Les statistiques s'afficheront une fois les frais configurés.</p>
                </div>
            <?php else: ?>
                <div class="stats-grid">
                    <?php foreach ($class_statistics as $class_stat): ?>
                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-title"><?php echo htmlspecialchars($class_stat['class_name']); ?></span>
                            <i class="stat-icon fas fa-graduation-cap"></i>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                            <div>
                                <div style="font-size: 18px; font-weight: bold; color: var(--accent-color);">
                                    <?php echo $class_stat['student_count']; ?>
                                </div>
                                <div style="font-size: 12px; color: #ccc;">Étudiants</div>
                            </div>
                            <div>
                                <div style="font-size: 18px; font-weight: bold; color: var(--success-color);">
                                    <?php echo $class_stat['students_paid_count']; ?>
                                </div>
                                <div style="font-size: 12px; color: #ccc;">À jour</div>
                            </div>
                        </div>
                        <div style="margin-bottom: 10px;">
                            <div style="font-size: 16px; font-weight: bold;">
                                <?php echo safe_number_format($class_stat['total_collected']); ?> FCFA
                            </div>
                            <div style="font-size: 12px; color: #ccc;">Collecté</div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $class_stat['collection_rate']; ?>%"></div>
                        </div>
                        <div style="margin-top: 5px; font-size: 12px; color: #ccc;">
                            Taux de collecte: <?php echo $class_stat['collection_rate']; ?>%
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Contenu Historique des Paiements -->
        <div class="tab-content" id="history-tab">
            <?php if (empty($recent_payments)): ?>
                <div style="text-align: center; padding: 40px; color: #ccc;">
                    <i class="fas fa-history" style="font-size: 48px; margin-bottom: 20px;"></i>
                    <h3>Aucun paiement enregistré</h3>
                    <p>L'historique des paiements s'affichera ici.</p>
                </div>
            <?php else: ?>
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Étudiant</th>
                            <th>Classe</th>
                            <th>Montant</th>
                            <th>Méthode</th>
                            <th>Type</th>
                            <th>Reçu</th>
                            <th>Enregistré par</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_payments as $payment):
                            $is_cancelled = ($payment['status'] === 'cancelled');
                        ?>
                        <tr style="<?php echo $is_cancelled ? 'opacity:0.65;' : ''; ?>">
                            <td><?php echo date('d/m/Y H:i', strtotime($payment['payment_date'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($payment['student_name']); ?></strong><br>
                                <small style="color: #ccc;"><?php echo htmlspecialchars($payment['student_id']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($payment['class_name'] ?? 'N/A'); ?></td>
                            <td style="color: <?php echo $is_cancelled ? 'var(--danger-color)' : 'var(--success-color)'; ?>; font-weight: bold;">
                                <?php if ($is_cancelled): ?><s><?php endif; ?>
                                <?php echo safe_number_format($payment['amount_paid']); ?> FCFA
                                <?php if ($is_cancelled): ?></s><?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $methods = [
                                    'cash' => 'Espèces',
                                    'bank_transfer' => 'Virement',
                                    'mobile_money' => 'Mobile Money',
                                    'check' => 'Chèque',
                                    'other' => 'Autre'
                                ];
                                echo $methods[$payment['payment_method']] ?? $payment['payment_method'];
                                ?>
                            </td>
                            <td>
                                <?php
                                $types = [
                                    'registration' => 'Inscription',
                                    'tuition' => 'Scolarité',
                                    'insurance' => 'Assurance',
                                    'library' => 'Bibliothèque',
                                    'practical' => 'TP',
                                    'other' => 'Autre'
                                ];
                                echo $types[$payment['payment_type']] ?? $payment['payment_type'];
                                ?>
                            </td>
                            <td>
                                <?php if ($payment['receipt_number']): ?>
                                <span style="font-family: monospace; background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px;">
                                    <?php echo htmlspecialchars($payment['receipt_number']); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($payment['recorded_by_name'] ?? 'N/A'); ?></td>
                            <td>
                                <?php if ($is_cancelled): ?>
                                <span class="status-badge" style="background:var(--danger-color);">Annulé</span>
                                <?php if (!empty($payment['cancel_reason'])): ?>
                                <br><small style="color:#ccc;" title="<?php echo htmlspecialchars($payment['cancel_reason']); ?>">
                                    <i class="fas fa-comment-slash"></i>
                                    <?php echo htmlspecialchars(mb_strimwidth($payment['cancel_reason'], 0, 30, '…')); ?>
                                </small>
                                <?php endif; ?>
                                <?php if (!empty($payment['cancelled_by_name'])): ?>
                                <br><small style="color:#999;">par <?php echo htmlspecialchars($payment['cancelled_by_name']); ?></small>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="status-badge status-paid">Validé</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!-- Bouton accordéon ventilation -->
                                <button class="btn btn-small" style="background:#2c6e8a; color:white;"
                                    onclick="togglePaymentAlloc(<?php echo $payment['id']; ?>, this)"
                                    title="Voir la ventilation">
                                    <i class="fas fa-sitemap"></i>
                                </button>
                                <?php if (!$is_cancelled): ?>
                                <?php if (!empty($payment['receipt_number'])): ?>
                                <a href="print_receipt.php?receipt_id=<?php echo urlencode($payment['receipt_number']); ?>"
                                   target="_blank"
                                   class="btn btn-small btn-info"
                                   title="Générer le reçu PDF">
                                    <i class="fas fa-print"></i> Reçu PDF
                                </a>
                                <?php endif; ?>
                                <button class="btn btn-small btn-danger"
                                    onclick="openCancelModal(
                                        <?php echo $payment['id']; ?>,
                                        '<?php echo htmlspecialchars(addslashes($payment['student_name'])); ?>',
                                        <?php echo floatval($payment['amount_paid']); ?>
                                    )">
                                    <i class="fas fa-times-circle"></i> Annuler
                                </button>
                                <?php else: ?>
                                <span style="color:#666; font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <!-- Ligne accordéon ventilation (cachée par défaut) -->
                        <tr class="pay-expand-row" id="expand-row-<?php echo $payment['id']; ?>">
                            <td colspan="10" class="pay-expand-cell">
                                <div id="alloc-content-<?php echo $payment['id']; ?>" class="pay-alloc-card">
                                    <div style="text-align:center; padding:10px; color:#ccc;">
                                        <i class="fas fa-spinner fa-spin"></i> Chargement des allocations…
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Contenu Messages Étudiants -->
        <div class="tab-content" id="messages-tab">
            <?php if (empty($finance_messages)): ?>
                <div style="text-align: center; padding: 40px; color: #ccc;">
                    <i class="fas fa-envelope" style="font-size: 48px; margin-bottom: 20px;"></i>
                    <h3>Aucun message</h3>
                    <p>Les messages des étudiants s'afficheront ici.</p>
                </div>
            <?php else: ?>
                <div style="margin-bottom: 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <h3 style="color: var(--accent-color); margin:0;">
                        <i class="fas fa-inbox"></i> Boîte de Réception
                        <span style="font-size:14px; font-weight:normal; color:#aaa;">(<?php echo count($finance_messages); ?> conversations)</span>
                    </h3>
                    <small style="color:#777;"><i class="fas fa-mouse-pointer"></i> Cliquez sur une carte pour ouvrir la conversation</small>
                </div>

                <?php
                $statuses_map = ['new'=>'Nouveau','read'=>'Lu','in_progress'=>'En cours','resolved'=>'Résolu','closed'=>'Fermé'];
                foreach ($finance_messages as $msg):
                    $is_unread = ($msg['status'] === 'new');
                ?>
                <div class="message-item <?php echo $msg['priority']; ?>"
                     id="msg-item-<?php echo $msg['id']; ?>"
                     data-sid="<?php echo htmlspecialchars($msg['student_id']); ?>"
                     data-sname="<?php echo htmlspecialchars($msg['student_name']); ?>"
                     style="cursor:pointer; <?php echo $is_unread ? 'border-left-color:var(--danger-color); background:rgba(231,76,60,0.06);' : ''; ?> transition:background .2s;"
                     onclick="openStudentDossier(this.dataset.sid, this.dataset.sname, 'messages')"
                     onmouseenter="this.style.background='rgba(255,255,255,0.08)'"
                     onmouseleave="this.style.background='<?php echo $is_unread ? 'rgba(231,76,60,0.06)' : ''; ?>'">

                    <div class="message-header" style="margin-bottom:8px;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1;">
                            <?php if ($is_unread): ?>
                            <span style="width:8px;height:8px;border-radius:50%;background:var(--danger-color);flex-shrink:0;" title="Non lu"></span>
                            <?php endif; ?>
                            <span class="message-student" style="font-size:15px;"><?php echo htmlspecialchars($msg['student_name']); ?></span>
                            <span class="priority-badge priority-<?php echo $msg['priority']; ?>" style="font-size:10px;">
                                <?php echo strtoupper($msg['priority']); ?>
                            </span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
                            <span class="message-status status-<?php echo $msg['status']; ?>" id="msg-status-<?php echo $msg['id']; ?>">
                                <?php echo $statuses_map[$msg['status']] ?? $msg['status']; ?>
                            </span>
                        </div>
                    </div>

                    <div style="font-size:12px;color:#888;margin-bottom:8px;">
                        <i class="fas fa-graduation-cap" style="margin-right:4px;"></i><?php echo htmlspecialchars($msg['class_name'] ?? '—'); ?>
                        &nbsp;·&nbsp;
                        <i class="fas fa-clock" style="margin-right:4px;"></i><?php echo date('d/m/Y H:i', strtotime($msg['created_at'])); ?>
                    </div>

                    <div style="color:#ccc;font-size:13px;line-height:1.5;margin-bottom:6px;">
                        <?php echo nl2br(htmlspecialchars(mb_substr($msg['message'], 0, 160))); ?><?php if (mb_strlen($msg['message']) > 160): ?><span style="color:#777;">…</span><?php endif; ?>
                    </div>

                    <?php if ($msg['response']): ?>
                    <div style="background:rgba(46,204,113,0.08);padding:8px 12px;border-radius:6px;border-left:3px solid var(--success-color);font-size:12px;color:#ccc;margin-top:6px;">
                        <i class="fas fa-reply" style="color:var(--success-color);margin-right:4px;"></i>
                        <strong style="color:var(--success-color);">Répondu</strong> —
                        <?php echo htmlspecialchars(mb_substr($msg['response'], 0, 100)); ?><?php if (mb_strlen($msg['response']) > 100): ?>…<?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div style="margin-top:10px;display:flex;gap:8px;" onclick="event.stopPropagation();">
                        <button class="btn btn-small btn-primary"
                                onclick="openStudentDossier(this.closest('[data-sid]').dataset.sid, this.closest('[data-sid]').dataset.sname, 'messages')">
                            <i class="fas fa-comments"></i> Ouvrir conversation
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Ajouter Paiement — formulaire ventilé 2 étapes -->
    <div class="modal" id="addPaymentModal">
        <div class="modal-content" style="max-width: 840px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-plus"></i> Ajouter un Paiement
                </h3>
                <span class="modal-close" onclick="closeModal('addPaymentModal')">&times;</span>
            </div>

            <!-- Étape A : Sélection étudiant -->
            <div class="form-group">
                <label>Étudiant *</label>
                <select id="pay_student_id" class="form-control" required onchange="onPayStudentChange()">
                    <option value="">Sélectionner un étudiant</option>
                    <?php foreach ($students_by_class as $class_name => $students): ?>
                        <optgroup label="<?php echo htmlspecialchars($class_name); ?>">
                            <?php foreach ($students as $student): ?>
                            <option value="<?php echo $student['student_id']; ?>"
                                    data-balance="<?php echo $student['remaining_balance']; ?>">
                                <?php echo htmlspecialchars($student['student_name'] . ' — ' . $student['student_id']); ?>
                                (Restant : <?php echo safe_number_format($student['remaining_balance']); ?> FCFA)
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="pay-balance-info" style="display:none; background:rgba(3,155,229,0.12); border:1px solid var(--accent-color); border-radius:6px; padding:10px 14px; margin-top:-12px; margin-bottom:16px; font-size:13px;">
                <i class="fas fa-wallet"></i> Solde restant global :
                <strong id="pay-balance-amount" style="color:var(--accent-color);">—</strong>
            </div>

            <!-- Étape B : Ventilation (chargée via AJAX après sélection) -->
            <div id="payment-step-b" style="display:none;">
                <hr style="border:none; border-top:1px solid var(--border-color); margin-bottom:16px;">

                <div id="pay-ventilation-content">
                    <div style="text-align:center; padding:24px; color:#ccc;">
                        <i class="fas fa-spinner fa-spin" style="font-size:28px; color:var(--accent-color);"></i>
                        <p style="margin-top:8px;">Chargement des échéances…</p>
                    </div>
                </div>

                <!-- Méthode, référence, notes -->
                <hr style="border:none; border-top:1px solid var(--border-color); margin:16px 0;">
                <div class="form-row">
                    <div class="form-group">
                        <label>Méthode de paiement *</label>
                        <select id="pay_method" class="form-control" required>
                            <option value="cash">Espèces</option>
                            <option value="bank_transfer">Virement bancaire</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="check">Chèque</option>
                            <option value="other">Autre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Référence</label>
                        <input type="text" id="pay_reference" class="form-control" placeholder="Numéro de référence">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="pay_description" class="form-control" rows="2" placeholder="Description optionnelle"></textarea>
                </div>

                <!-- Barre de total + actions -->
                <div style="background:rgba(3,155,229,0.1); border:1px solid var(--accent-color); border-radius:8px; padding:14px 18px; margin-top:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="font-size:15px; font-weight:bold;">
                        <i class="fas fa-calculator"></i> Total calculé :
                        <span id="pay-total-display" style="color:var(--accent-color); font-size:20px; margin-left:6px;">0 FCFA</span>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <button type="button" class="btn" onclick="closeModal('addPaymentModal')"
                                style="background:#95a5a6; color:white;">Annuler</button>
                        <button type="button" id="pay-submit-btn" class="btn btn-success" onclick="submitVentilatedPayment()">
                            <i class="fas fa-save"></i> Enregistrer le Paiement
                        </button>
                    </div>
                </div>
                <div id="pay-submit-error" style="display:none; margin-top:8px; color:var(--danger-color); font-size:13px; padding:8px 12px; background:rgba(231,76,60,0.1); border-radius:6px; border:1px solid var(--danger-color);">
                    <i class="fas fa-exclamation-triangle"></i> <span id="pay-submit-error-text"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Gérer Frais de Scolarité -->
    <div class="modal" id="manageTuitionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-cog"></i> Gérer les Frais de Scolarité
                </h3>
                <span class="modal-close" onclick="closeModal('manageTuitionModal')">&times;</span>
            </div>
            <form method="POST" id="tuitionForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_tuition_fees">
                
                <div class="form-group">
                    <label>Classe *</label>
                    <select name="class_id" class="form-control" required>
                        <option value="">Sélectionner une classe</option>
                        <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>">
                            <?php echo htmlspecialchars($class['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Montant Total (FCFA)</label>
                    <input type="number" name="total_amount" class="form-control" min="0" step="0.01" readonly style="background:#f5f5f5; cursor:default;" value="0">
                    <small style="color:#888;">Calculé automatiquement depuis le détail des frais ci-dessous</small>
                </div>

                <h4 style="color: var(--accent-color); margin: 20px 0 15px 0; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
                    Détail des Frais
                </h4>

                <div class="form-row">
                    <div class="form-group">
                        <label>Frais d'inscription</label>
                        <input type="number" name="registration_fee" class="form-control" min="0" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label>Frais de scolarité</label>
                        <input type="number" name="tuition_fee" class="form-control" min="0" step="0.01" value="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Assurance</label>
                        <input type="number" name="insurance_fee" class="form-control" min="0" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label>Bibliothèque</label>
                        <input type="number" name="library_fee" class="form-control" min="0" step="0.01" value="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Travaux pratiques</label>
                        <input type="number" name="practical_fee" class="form-control" min="0" step="0.01" value="0">
                    </div>
                    <div class="form-group">
                        <label>Autres frais</label>
                        <input type="number" name="other_fees" class="form-control" min="0" step="0.01" value="0">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Date limite de paiement *</label>
                        <input type="date" name="due_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Nombre d'échéances</label>
                        <input type="number" name="installments" class="form-control" min="1" max="12" value="1">
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Description des frais (optionnel)"></textarea>
                </div>

                <div style="text-align: right; margin-top: 25px;">
                    <button type="button" class="btn" onclick="closeModal('manageTuitionModal')" style="background: #95a5a6; color: white; margin-right: 10px;">
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Enregistrer les Frais
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Gérer les Échéances (version complète) -->
    <div class="modal" id="manageDeadlinesModal">
        <div class="modal-content" style="max-width: 920px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-calendar-alt"></i> Échéances —
                    <span id="dl_modal_student_name" style="color:white;"></span>
                </h3>
                <span class="modal-close" onclick="closeModal('manageDeadlinesModal')">&times;</span>
            </div>

            <!-- ── Section haute : tableau des échéances existantes ── -->
            <div id="dl_existing_section" style="min-height:80px;">
                <div style="text-align:center; padding:20px; color:#ccc;">
                    <i class="fas fa-spinner fa-spin" style="font-size:28px;"></i>
                </div>
            </div>

            <hr style="border:none; border-top:1px solid var(--border-color); margin:20px 0;">

            <!-- ── Section basse : ajout manuel ── -->
            <div>
                <h4 style="color:var(--accent-color); margin-bottom:14px; font-size:14px;">
                    <i class="fas fa-plus-circle"></i> Ajouter une échéance manuelle
                </h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Date limite *</label>
                        <input type="date" id="new_dl_date" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Montant (FCFA) *</label>
                        <input type="number" id="new_dl_amount" class="form-control" min="1" step="1" placeholder="0">
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <input type="text" id="new_dl_notes" class="form-control" placeholder="Optionnel">
                </div>
                <button type="button" class="btn btn-primary btn-small" onclick="addDeadline()">
                    <i class="fas fa-plus"></i> Ajouter cette échéance
                </button>
            </div>

            <hr style="border:none; border-top:1px solid var(--border-color); margin:20px 0;">

            <!-- ── Section génération automatique ── -->
            <div>
                <button type="button" class="btn btn-success btn-small" onclick="toggleAutoGenForm()">
                    <i class="fas fa-magic"></i> Générer automatiquement
                </button>
                <div id="auto_gen_form" style="display:none; margin-top:14px; background:rgba(3,155,229,0.08); padding:16px; border-radius:8px; border:1px solid rgba(3,155,229,0.3);">
                    <h4 style="margin-bottom:14px; font-size:14px; color:var(--accent-color);">
                        <i class="fas fa-magic"></i> Génération automatique (procédure GeneratePaymentDeadlines)
                    </h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nombre de tranches *</label>
                            <input type="number" id="gen_installments" class="form-control" min="1" max="24" value="3">
                        </div>
                        <div class="form-group">
                            <label>Date 1ère échéance *</label>
                            <input type="date" id="gen_first_date" class="form-control">
                        </div>
                    </div>
                    <div id="gen_warning" style="display:none; background:rgba(243,156,18,0.15); border:1px solid var(--warning-color); border-radius:6px; padding:10px; margin-bottom:12px; font-size:13px; color:var(--warning-color);">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Attention :</strong> Des échéances avec paiements existent. La procédure recalculera l'échéancier en tenant compte des paiements déjà alloués.
                    </div>
                    <div style="display:flex; gap:10px;">
                        <button type="button" class="btn btn-success btn-small" onclick="generateDeadlinesAuto()">
                            <i class="fas fa-magic"></i> Générer
                        </button>
                        <button type="button" class="btn btn-small" onclick="toggleAutoGenForm()" style="background:#95a5a6; color:white;">
                            Annuler
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Répondre au Message -->
    <div class="modal" id="respondMessageModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-reply"></i> Répondre au Message
                </h3>
                <span class="modal-close" onclick="closeModal('respondMessageModal')">&times;</span>
            </div>
            <form method="POST" id="respondForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="respond_message">
                <input type="hidden" name="message_id" id="respond_message_id">
                
                <div id="original_message_display" style="background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <!-- Le message original sera affiché ici -->
                </div>

                <div class="form-group">
                    <label>Votre Réponse *</label>
                    <textarea name="response" class="form-control" rows="5" required placeholder="Écrivez votre réponse..."></textarea>
                </div>

                <div class="form-group">
                    <label>Nouveau Statut *</label>
                    <select name="new_status" class="form-control" required>
                        <option value="in_progress">En cours de traitement</option>
                        <option value="resolved">Résolu</option>
                        <option value="closed">Fermé</option>
                    </select>
                </div>

                <div style="text-align: right; margin-top: 25px;">
                    <button type="button" class="btn" onclick="closeModal('respondMessageModal')" style="background: #95a5a6; color: white; margin-right: 10px;">
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-paper-plane"></i> Envoyer la Réponse
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Voir Message -->
    <div class="modal" id="viewMessageModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-envelope-open"></i> Détails du Message
                </h3>
                <span class="modal-close" onclick="closeModal('viewMessageModal')">&times;</span>
            </div>
            <div id="message_details_content">
                <!-- Le contenu sera chargé dynamiquement -->
            </div>
        </div>
    </div>

    <!-- Modal Historique des Paiements Étudiant -->
    <div class="modal" id="studentPaymentHistoryModal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-history"></i> Historique des Paiements
                </h3>
                <span class="modal-close" onclick="closeModal('studentPaymentHistoryModal')">&times;</span>
            </div>
            <div id="student_payment_history_content">
                <div style="text-align: center; padding: 40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 48px; color: var(--accent-color);"></i>
                    <p style="margin-top: 20px; color: #ccc;">Chargement de l'historique...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Modifier une échéance -->
    <div class="modal" id="editDeadlineModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-edit"></i> Modifier l'échéance
                </h3>
                <span class="modal-close" onclick="closeModal('editDeadlineModal')">&times;</span>
            </div>
            <input type="hidden" id="edit_dl_id">
            <div class="form-group">
                <label>Date limite *</label>
                <input type="date" id="edit_dl_date" class="form-control">
            </div>
            <div class="form-group">
                <label>Montant (FCFA) *</label>
                <input type="number" id="edit_dl_amount" class="form-control" min="1" step="1">
            </div>
            <div class="form-group">
                <label>Notes</label>
                <input type="text" id="edit_dl_notes" class="form-control" placeholder="Optionnel">
            </div>
            <div style="text-align:right; margin-top:20px;">
                <button type="button" class="btn" onclick="closeModal('editDeadlineModal')"
                    style="background:#95a5a6; color:white; margin-right:10px;">Annuler</button>
                <button type="button" class="btn btn-success" onclick="saveDeadlineEdit()">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Génération en masse par classe -->
    <div class="modal" id="classDeadlinesModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-calendar-plus"></i> Générer échéanciers —
                    <span id="class_dl_name" style="color:white;"></span>
                </h3>
                <span class="modal-close" onclick="closeModal('classDeadlinesModal')">&times;</span>
            </div>

            <!-- Liste des étudiants sans échéancier -->
            <div id="class_dl_students_list" style="margin-bottom:20px;"></div>

            <!-- Paramètres -->
            <div class="form-row">
                <div class="form-group">
                    <label>Nombre de tranches *</label>
                    <input type="number" id="class_dl_installments" class="form-control" min="1" max="24" value="3">
                </div>
                <div class="form-group">
                    <label>Date 1ère échéance *</label>
                    <input type="date" id="class_dl_first_date" class="form-control">
                </div>
            </div>

            <input type="hidden" id="class_dl_class_id">

            <!-- Rapport (affiché après génération) -->
            <div id="class_dl_report" style="display:none; margin-bottom:16px;"></div>

            <div style="text-align:right; margin-top:10px;">
                <button type="button" class="btn" onclick="closeModal('classDeadlinesModal')"
                    style="background:#95a5a6; color:white; margin-right:10px;">Annuler</button>
                <button type="button" id="class_dl_generate_btn" class="btn btn-success" onclick="confirmGenerateClassDeadlines()">
                    <i class="fas fa-magic"></i> Générer pour tous
                </button>
            </div>
        </div>
    </div>

<?php include '../includes/footer.php'; ?>

    <script>
        // Variables globales pour stocker les données
        const messagesData = <?php echo json_encode($finance_messages); ?>;

        // Fonctions de gestion des modales
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Fermeture des modales en cliquant à l'extérieur
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            });
        }

        // Gestion des onglets
        function showTab(tabName) {
            // Masquer tous les contenus d'onglets
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));
            
            // Masquer tous les boutons d'onglets actifs
            const buttons = document.querySelectorAll('.tab-button');
            buttons.forEach(button => button.classList.remove('active'));
            
            // Afficher le contenu sélectionné
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activer le bouton sélectionné
            event.target.classList.add('active');
        }

        // Toggle des classes d'étudiants
        function toggleClass(classId) {
            const content = document.getElementById(classId);
            content.classList.toggle('active');
        }

        // Ajouter un paiement pour un étudiant spécifique
        function addPaymentForStudent(studentId, studentName) {
            openModal('addPaymentModal');
            // Réinitialiser le formulaire
            document.getElementById('pay_student_id').value = '';
            document.getElementById('pay-balance-info').style.display = 'none';
            document.getElementById('payment-step-b').style.display = 'none';
            // Pré-sélectionner et déclencher le chargement
            const sel = document.getElementById('pay_student_id');
            sel.value = studentId;
            sel.dispatchEvent(new Event('change'));
        }

        // ── Gestion des échéances ──────────────────────────────────────────
        const _anneeFiltre = '<?php echo addslashes($annee_filtre); ?>';
        let dlCurrentStudentId   = null;
        let dlCurrentStudentData = null;

        function manageDeadlines(studentId, studentName, hasDeadlines) {
            dlCurrentStudentId   = studentId;
            dlCurrentStudentData = null;
            document.getElementById('dl_modal_student_name').textContent = studentName;

            // Reset formulaires
            document.getElementById('new_dl_date').value   = '';
            document.getElementById('new_dl_amount').value = '';
            document.getElementById('new_dl_notes').value  = '';
            document.getElementById('auto_gen_form').style.display = 'none';

            // Date par défaut génération auto = J+30
            const d = new Date();
            d.setDate(d.getDate() + 30);
            document.getElementById('gen_first_date').value = d.toISOString().split('T')[0];

            openModal('manageDeadlinesModal');
            loadStudentDeadlines();
        }

        function loadStudentDeadlines() {
            if (!dlCurrentStudentId) return;
            document.getElementById('dl_existing_section').innerHTML =
                '<div style="text-align:center; padding:24px; color:#ccc;">' +
                '<i class="fas fa-spinner fa-spin" style="font-size:28px; color:var(--accent-color);"></i>' +
                '<p style="margin-top:10px;">Chargement des échéances…</p></div>';

            fetch('?action=get_deadlines_detail&student_id=' + encodeURIComponent(dlCurrentStudentId) + '&annee=' + encodeURIComponent(_anneeFiltre))
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        dlCurrentStudentData = data;
                        renderDeadlinesTable(data);
                    } else {
                        document.getElementById('dl_existing_section').innerHTML =
                            '<div style="color:var(--danger-color); padding:12px;"><i class="fas fa-exclamation-triangle"></i> ' +
                            (data.error || 'Erreur de chargement.') + '</div>';
                    }
                })
                .catch(() => {
                    document.getElementById('dl_existing_section').innerHTML =
                        '<div style="color:var(--danger-color); padding:12px;"><i class="fas fa-exclamation-triangle"></i> Erreur de connexion.</div>';
                });
        }

        function renderDeadlinesTable(data) {
            const deadlines  = data.deadlines || [];
            const student    = data.student   || {};
            const netAmount  = parseFloat(student.net_amount  || 0);
            const tuitionFee = parseFloat(student.tuition_fee || 0);
            const discount   = parseFloat(student.discount_amount || 0);
            // Base de l'échéancier = scolarité pure nette de remise (fallback : net global pour lignes legacy)
            const tuitionNet = tuitionFee > 0 ? Math.max(0, tuitionFee - discount) : netAmount;

            const statusBadge = {
                pending: '<span style="background:var(--warning-color);color:white;padding:2px 8px;border-radius:10px;font-size:11px;">En attente</span>',
                paid:    '<span style="background:var(--success-color);color:white;padding:2px 8px;border-radius:10px;font-size:11px;">Payée</span>',
                overdue: '<span style="background:var(--danger-color);color:white;padding:2px 8px;border-radius:10px;font-size:11px;">En retard</span>',
                partial: '<span style="background:var(--info-color);color:white;padding:2px 8px;border-radius:10px;font-size:11px;">Partielle</span>'
            };

            let html = '';

            if (deadlines.length === 0) {
                html = '<div style="text-align:center; padding:20px; color:#ccc; background:rgba(255,255,255,0.04); border-radius:8px; margin-bottom:4px;">' +
                       '<i class="fas fa-calendar-times" style="font-size:28px; margin-bottom:8px;"></i>' +
                       '<p style="margin:0;">Aucune échéance enregistrée pour cet étudiant.</p></div>';
            } else {
                const totalEch  = deadlines.reduce((s, d) => s + parseFloat(d.amount_due  || 0), 0);
                const totalPaid = deadlines.reduce((s, d) => s + parseFloat(d.amount_paid || 0), 0);
                const diff      = Math.abs(totalEch - tuitionNet);
                const mismatch  = diff > 1 && tuitionNet > 0;

                html += '<h4 style="color:var(--accent-color); margin-bottom:10px; font-size:14px;">' +
                        '<i class="fas fa-list-ol"></i> Échéances existantes (' + deadlines.length + ')</h4>' +
                        '<div style="overflow-x:auto;">' +
                        '<table style="width:100%; border-collapse:collapse; font-size:13px;">' +
                        '<thead><tr style="background:rgba(255,255,255,0.1);">' +
                        '<th style="padding:8px 10px; color:var(--accent-color);">N°</th>' +
                        '<th style="padding:8px 10px; color:var(--accent-color);">Date limite</th>' +
                        '<th style="padding:8px 10px; text-align:right; color:var(--accent-color);">Montant dû</th>' +
                        '<th style="padding:8px 10px; text-align:right; color:var(--accent-color);">Montant payé</th>' +
                        '<th style="padding:8px 10px; text-align:center; color:var(--accent-color);">Statut</th>' +
                        '<th style="padding:8px 10px; text-align:center; color:var(--accent-color);">Actions</th>' +
                        '</tr></thead><tbody>';

                deadlines.forEach((dl, idx) => {
                    const amtDue  = parseFloat(dl.amount_due  || 0);
                    const amtPaid = parseFloat(dl.amount_paid || 0);
                    const canEdit   = dl.status === 'pending';
                    const canDelete = dl.status === 'pending' && amtPaid === 0;
                    const notesEsc  = (dl.notes || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");

                    html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.07);">' +
                        '<td style="padding:8px 10px;">' + (idx+1) + '</td>' +
                        '<td style="padding:8px 10px;">' + new Date(dl.due_date + 'T00:00:00').toLocaleDateString('fr-FR') + '</td>' +
                        '<td style="padding:8px 10px; text-align:right;">' + amtDue.toLocaleString('fr-FR') + ' FCFA</td>' +
                        '<td style="padding:8px 10px; text-align:right; color:' + (amtPaid > 0 ? 'var(--success-color)' : '#999') + ';">' + amtPaid.toLocaleString('fr-FR') + ' FCFA</td>' +
                        '<td style="padding:8px 10px; text-align:center;">' + (statusBadge[dl.status] || dl.status) + '</td>' +
                        '<td style="padding:8px 10px; text-align:center; white-space:nowrap;">' +
                        (canEdit   ? '<button class="btn btn-small btn-info" style="padding:4px 8px;" onclick="openEditDeadline(' + dl.id + ',\'' + dl.due_date + '\',' + amtDue + ',\'' + notesEsc + '\')"><i class="fas fa-edit"></i></button>' : '') +
                        (canDelete ? ' <button class="btn btn-small btn-danger" style="padding:4px 8px;" onclick="deleteDeadline(' + dl.id + ')"><i class="fas fa-trash"></i></button>' : '') +
                        (!canEdit && !canDelete ? '<span style="color:#666; font-size:12px;">—</span>' : '') +
                        '</td></tr>';
                });

                html += '</tbody>' +
                        '<tfoot><tr style="background:rgba(255,255,255,0.06); font-weight:bold;">' +
                        '<td colspan="2" style="padding:8px 10px;">Total échéancier</td>' +
                        '<td style="padding:8px 10px; text-align:right;">' + totalEch.toLocaleString('fr-FR') + ' FCFA</td>' +
                        '<td style="padding:8px 10px; text-align:right; color:var(--success-color);">' + totalPaid.toLocaleString('fr-FR') + ' FCFA</td>' +
                        '<td colspan="2" style="padding:8px 10px; text-align:right; font-size:12px; color:#aaa;">' +
                        'Scolarité nette : ' + tuitionNet.toLocaleString('fr-FR') + ' FCFA</td>' +
                        '</tr></tfoot></table></div>';

                if (mismatch) {
                    html += '<div style="margin-top:10px; background:rgba(231,76,60,0.15); border:1px solid var(--danger-color); border-radius:6px; padding:10px; font-size:13px; color:var(--danger-color);">' +
                            '<i class="fas fa-exclamation-triangle"></i> <strong>Attention :</strong> ' +
                            'Total échéancier (' + totalEch.toLocaleString('fr-FR') + ' FCFA) ≠ scolarité nette (' + tuitionNet.toLocaleString('fr-FR') + ' FCFA). ' +
                            'Différence : ' + diff.toLocaleString('fr-FR') + ' FCFA.</div>';
                }
            }

            document.getElementById('dl_existing_section').innerHTML = html;

            // Afficher l'avertissement génération auto si des paiements partiels existent
            const hasPaidDl = deadlines.some(d => d.status === 'paid' || parseFloat(d.amount_paid || 0) > 0);
            document.getElementById('gen_warning').style.display = hasPaidDl ? 'block' : 'none';
        }

        function addDeadline() {
            const date   = document.getElementById('new_dl_date').value;
            const amount = parseFloat(document.getElementById('new_dl_amount').value) || 0;
            const notes  = document.getElementById('new_dl_notes').value;
            if (!date)       { showAlert('error', 'Veuillez saisir une date limite.'); return; }
            if (amount <= 0) { showAlert('error', 'Veuillez saisir un montant valide.'); return; }

            fetch('?action=add_deadline&annee=' + encodeURIComponent(_anneeFiltre), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_id: dlCurrentStudentId, due_date: date, amount_due: amount, notes: notes })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    document.getElementById('new_dl_date').value = '';
                    document.getElementById('new_dl_amount').value = '';
                    document.getElementById('new_dl_notes').value = '';
                    loadStudentDeadlines();
                } else { showAlert('error', data.error || "Erreur lors de l'ajout."); }
            })
            .catch(() => showAlert('error', 'Erreur de connexion.'));
        }

        function openEditDeadline(id, dueDate, amountDue, notes) {
            document.getElementById('edit_dl_id').value     = id;
            document.getElementById('edit_dl_date').value   = dueDate;
            document.getElementById('edit_dl_amount').value = amountDue;
            document.getElementById('edit_dl_notes').value  = notes || '';
            openModal('editDeadlineModal');
        }

        function saveDeadlineEdit() {
            const id     = parseInt(document.getElementById('edit_dl_id').value);
            const date   = document.getElementById('edit_dl_date').value;
            const amount = parseFloat(document.getElementById('edit_dl_amount').value) || 0;
            const notes  = document.getElementById('edit_dl_notes').value;
            if (!date || amount <= 0) { showAlert('error', 'Données invalides.'); return; }

            fetch('?action=update_deadline&annee=' + encodeURIComponent(_anneeFiltre), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ deadline_id: id, due_date: date, amount_due: amount, notes: notes })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    closeModal('editDeadlineModal');
                    showAlert('success', data.message);
                    loadStudentDeadlines();
                } else { showAlert('error', data.error || 'Erreur.'); }
            })
            .catch(() => showAlert('error', 'Erreur de connexion.'));
        }

        function deleteDeadline(id) {
            if (!confirm('Supprimer cette échéance ? Action irréversible.')) return;
            fetch('?action=delete_deadline&annee=' + encodeURIComponent(_anneeFiltre), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ deadline_id: id })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) { showAlert('success', data.message); loadStudentDeadlines(); }
                else { showAlert('error', data.error || 'Erreur de suppression.'); }
            })
            .catch(() => showAlert('error', 'Erreur de connexion.'));
        }

        function toggleAutoGenForm() {
            const f = document.getElementById('auto_gen_form');
            f.style.display = f.style.display === 'none' ? 'block' : 'none';
        }

        function generateDeadlinesAuto() {
            const installments   = parseInt(document.getElementById('gen_installments').value) || 0;
            const first_deadline = document.getElementById('gen_first_date').value;
            if (installments < 1) { showAlert('error', 'Nombre de tranches invalide.'); return; }
            if (!first_deadline)  { showAlert('error', 'Date de première échéance requise.'); return; }

            const hasPaid = dlCurrentStudentData && dlCurrentStudentData.deadlines &&
                dlCurrentStudentData.deadlines.some(d => d.status === 'paid' || parseFloat(d.amount_paid || 0) > 0);
            if (hasPaid && !confirm('Des paiements existent sur cet échéancier. La procédure va recalculer les tranches restantes. Confirmer ?')) return;

            fetch('?action=generate_deadlines_ajax&annee=' + encodeURIComponent(_anneeFiltre), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ student_id: dlCurrentStudentId, installments: installments, first_deadline: first_deadline })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    document.getElementById('auto_gen_form').style.display = 'none';
                    loadStudentDeadlines();
                } else { showAlert('error', data.error || 'Erreur lors de la génération.'); }
            })
            .catch(() => showAlert('error', 'Erreur de connexion.'));
        }

        // ── Génération en masse par classe ─────────────────────────────────
        function openClassDeadlinesModal(classId, className, studentsNoDeadlines) {
            document.getElementById('class_dl_class_id').value  = classId;
            document.getElementById('class_dl_name').textContent = className;
            document.getElementById('class_dl_installments').value = 3;
            document.getElementById('class_dl_report').style.display = 'none';

            const d = new Date();
            d.setDate(d.getDate() + 30);
            document.getElementById('class_dl_first_date').value = d.toISOString().split('T')[0];

            const btn = document.getElementById('class_dl_generate_btn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-magic"></i> Générer pour tous';

            let listHtml = '';
            if (!studentsNoDeadlines || studentsNoDeadlines.length === 0) {
                listHtml = '<div style="color:var(--success-color); padding:12px; text-align:center;">' +
                           '<i class="fas fa-check-circle"></i> Tous les étudiants de cette classe ont déjà un échéancier.</div>';
                btn.disabled = true;
            } else {
                listHtml = '<div style="background:rgba(255,255,255,0.05); border-radius:8px; padding:14px; margin-bottom:16px;">' +
                           '<p style="color:#ccc; margin-bottom:8px;"><strong style="color:var(--accent-color);">' +
                           studentsNoDeadlines.length + ' étudiant(s)</strong> sans échéancier :</p>' +
                           '<ul style="color:#ccc; padding-left:20px; max-height:150px; overflow-y:auto; margin:0;">' +
                           studentsNoDeadlines.map(s => '<li>' + s.name + '</li>').join('') +
                           '</ul></div>';
            }
            document.getElementById('class_dl_students_list').innerHTML = listHtml;
            openModal('classDeadlinesModal');
        }

        function confirmGenerateClassDeadlines() {
            const classId       = parseInt(document.getElementById('class_dl_class_id').value);
            const installments  = parseInt(document.getElementById('class_dl_installments').value) || 0;
            const firstDeadline = document.getElementById('class_dl_first_date').value;
            const btn           = document.getElementById('class_dl_generate_btn');
            const report        = document.getElementById('class_dl_report');

            if (installments < 1) { showAlert('error', 'Nombre de tranches invalide.'); return; }
            if (!firstDeadline)   { showAlert('error', 'Date de première échéance requise.'); return; }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Génération en cours…';

            fetch('?action=generate_class_deadlines&annee=' + encodeURIComponent(_anneeFiltre), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ class_id: classId, installments: installments, first_deadline: firstDeadline })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const color = data.errors > 0 ? 'var(--warning-color)' : 'var(--success-color)';
                    report.innerHTML = '<div style="background:rgba(46,204,113,0.1); border:1px solid ' + color + '; border-radius:6px; padding:12px; font-size:14px; color:' + color + ';">' +
                        '<i class="fas fa-check-circle"></i> <strong>' + data.message + '</strong></div>';
                    report.style.display = 'block';
                    btn.innerHTML = '<i class="fas fa-sync"></i> Recharger la page';
                    btn.disabled = false;
                    btn.onclick = () => location.reload();
                } else {
                    showAlert('error', data.error || 'Erreur.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-magic"></i> Générer pour tous';
                }
            })
            .catch(() => {
                showAlert('error', 'Erreur de connexion.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-magic"></i> Générer pour tous';
            });
        }
        // ────────────────────────────────────────────────────────────────────

        // ── Accordéon allocations dans l'onglet Historique ───────────────────
        const _allocLoaded = {};

        function togglePaymentAlloc(paymentId, btn) {
            const row     = document.getElementById('expand-row-' + paymentId);
            const content = document.getElementById('alloc-content-' + paymentId);
            const isOpen  = row.classList.toggle('open');

            if (btn) {
                btn.innerHTML = isOpen
                    ? '<i class="fas fa-times"></i>'
                    : '<i class="fas fa-sitemap"></i>';
            }

            if (!isOpen || _allocLoaded[paymentId]) return;

            _allocLoaded[paymentId] = true;
            fetch('?action=get_payment_allocations&payment_id=' + paymentId)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderAllocContent(content, data.allocations);
                    } else {
                        content.innerHTML = '<div style="color:var(--danger-color);padding:8px;"><i class="fas fa-exclamation-triangle"></i> ' +
                            (data.error || 'Erreur de chargement.') + '</div>';
                    }
                })
                .catch(() => {
                    content.innerHTML = '<div style="color:var(--danger-color);padding:8px;"><i class="fas fa-exclamation-triangle"></i> Erreur de connexion.</div>';
                });
        }

        function renderAllocContent(container, allocations) {
            if (!allocations || allocations.length === 0) {
                container.innerHTML =
                    '<div style="color:#aaa;font-style:italic;padding:6px 0;">' +
                    '<i class="fas fa-info-circle"></i> ' +
                    'Ventilation non disponible (paiement antérieur à la refonte).</div>';
                return;
            }
            let html = '<div style="font-size:12px;font-weight:600;color:var(--accent-color);margin-bottom:6px;">' +
                       '<i class="fas fa-sitemap"></i> Ventilation du paiement</div>' +
                       '<table style="width:100%;border-collapse:collapse;">' +
                       '<thead><tr style="background:rgba(255,255,255,0.08);">' +
                       '<th style="padding:5px 10px;text-align:left;font-size:11px;color:var(--accent-color);">Type</th>' +
                       '<th style="padding:5px 10px;text-align:left;font-size:11px;color:var(--accent-color);">Échéance liée</th>' +
                       '<th style="padding:5px 10px;text-align:right;font-size:11px;color:var(--accent-color);">Montant affecté</th>' +
                       '</tr></thead><tbody>';

            allocations.forEach(a => {
                const typeLabel = _feeTypeLabels[a.allocation_type] || a.allocation_type || '—';
                let echeanceInfo = '—';
                if (a.deadline_id) {
                    const dl_num  = a.deadline_num ? 'Éch. ' + a.deadline_num : 'Éch. #' + a.deadline_id;
                    const dl_date = a.due_date ? new Date(a.due_date + 'T00:00:00').toLocaleDateString('fr-FR') : '';
                    echeanceInfo  = dl_num + (dl_date ? ' (' + dl_date + ')' : '');
                }
                html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.05);">' +
                    '<td style="padding:5px 10px;color:#ccc;">' + typeLabel + '</td>' +
                    '<td style="padding:5px 10px;color:#aaa;">' + echeanceInfo + '</td>' +
                    '<td style="padding:5px 10px;text-align:right;color:var(--success-color);font-weight:600;">' +
                    parseFloat(a.amount).toLocaleString('fr-FR') + ' FCFA</td>' +
                    '</tr>';
            });

            const total = allocations.reduce((s, a) => s + parseFloat(a.amount || 0), 0);
            html += '<tr style="background:rgba(255,255,255,0.06);font-weight:bold;">' +
                    '<td colspan="2" style="padding:5px 10px;color:#ccc;">Total ventilé</td>' +
                    '<td style="padding:5px 10px;text-align:right;color:var(--accent-color);">' +
                    total.toLocaleString('fr-FR') + ' FCFA</td>' +
                    '</tr></tbody></table>';

            container.innerHTML = html;
        }
        // ─────────────────────────────────────────────────────────────────────

        // Voir l'historique des paiements d'un étudiant
        function viewPaymentHistory(studentId) {
            // Ouvrir le modal
            openModal('studentPaymentHistoryModal');
            
            // Charger les données via AJAX
            fetch(`?action=get_student_payments&student_id=${studentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayPaymentHistory(data);
                    } else {
                        document.getElementById('student_payment_history_content').innerHTML = `
                            <div style="text-align: center; padding: 40px; color: var(--danger-color);">
                                <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 20px;"></i>
                                <h3>Erreur</h3>
                                <p>${data.error || 'Impossible de charger l\'historique'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('student_payment_history_content').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: var(--danger-color);">
                            <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 20px;"></i>
                            <h3>Erreur de connexion</h3>
                            <p>Impossible de charger l'historique des paiements</p>
                        </div>
                    `;
                });
        }

        // Afficher l'historique des paiements (avec accordéon ventilation)
        const _histAllocLoaded = {};

        function displayPaymentHistory(data) {
            const student   = data.student;
            const payments  = data.payments;
            const deadlines = data.deadlines;

            const totalPaid   = parseFloat(student.total_paid   || 0);
            const totalAmount = parseFloat(student.total_amount || 0);
            const remaining   = totalAmount - totalPaid;
            const percentage  = totalAmount > 0 ? ((totalPaid / totalAmount) * 100).toFixed(1) : 0;

            const methodLabels = { cash:'Espèces', bank_transfer:'Virement', mobile_money:'Mobile Money', check:'Chèque', other:'Autre' };

            // ── Résumé étudiant ──
            let html = `
                <div style="background:var(--card-bg);padding:20px;border-radius:8px;margin-bottom:20px;border:1px solid var(--border-color);">
                    <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:15px;">
                        <div>
                            <h3 style="color:var(--accent-color);margin-bottom:5px;"><i class="fas fa-user-graduate"></i> ${student.name}</h3>
                            <p style="color:#ccc;margin:0;"><i class="fas fa-id-card"></i> ${student.id} | <i class="fas fa-envelope"></i> ${student.email}</p>
                            ${student.class_name ? `<p style="color:#ccc;margin:5px 0 0;"><i class="fas fa-users"></i> ${student.class_name}</p>` : ''}
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:24px;font-weight:bold;color:var(--success-color);">${totalPaid.toLocaleString('fr-FR')} FCFA</div>
                            <div style="font-size:12px;color:#ccc;">Payé sur ${totalAmount.toLocaleString('fr-FR')} FCFA</div>
                        </div>
                    </div>
                    <div class="progress-bar" style="margin-bottom:10px;">
                        <div class="progress-fill" style="width:${percentage}%"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px;margin-top:15px;">
                        <div style="text-align:center;padding:10px;background:rgba(46,204,113,0.1);border-radius:6px;">
                            <div style="font-size:18px;font-weight:bold;color:var(--success-color);">${percentage}%</div>
                            <div style="font-size:11px;color:#ccc;">Progression</div>
                        </div>
                        <div style="text-align:center;padding:10px;background:rgba(243,156,18,0.1);border-radius:6px;">
                            <div style="font-size:18px;font-weight:bold;color:var(--warning-color);">${remaining.toLocaleString('fr-FR')}</div>
                            <div style="font-size:11px;color:#ccc;">Restant (FCFA)</div>
                        </div>
                        <div style="text-align:center;padding:10px;background:rgba(3,155,229,0.1);border-radius:6px;">
                            <div style="font-size:18px;font-weight:bold;color:var(--info-color);">${payments.length}</div>
                            <div style="font-size:11px;color:#ccc;">Paiements</div>
                        </div>
                    </div>
                </div>`;

            // ── Plan d'échéancier ──
            if (deadlines && deadlines.length > 0) {
                html += `<div style="margin-bottom:20px;">
                    <h4 style="color:var(--accent-color);margin-bottom:15px;"><i class="fas fa-calendar-alt"></i> Plan d'Échéancier (${deadlines.length} échéances)</h4>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;">`;
                deadlines.forEach((dl, idx) => {
                    const isPaid   = dl.status === 'paid';
                    const isOvd    = new Date(dl.due_date) < new Date() && !isPaid;
                    const clr      = isPaid ? 'var(--success-color)' : isOvd ? 'var(--danger-color)' : 'var(--warning-color)';
                    const ico      = isPaid ? 'check-circle' : isOvd ? 'exclamation-circle' : 'clock';
                    html += `<div style="background:rgba(255,255,255,0.05);padding:12px;border-radius:6px;border-left:3px solid ${clr};">
                        <div style="font-size:11px;color:#999;margin-bottom:4px;">Échéance ${idx+1}</div>
                        <div style="font-weight:bold;color:white;margin-bottom:4px;">${parseFloat(dl.amount_due).toLocaleString('fr-FR')} FCFA</div>
                        <div style="font-size:12px;color:#ccc;margin-bottom:6px;"><i class="fas fa-calendar"></i> ${new Date(dl.due_date+'T00:00:00').toLocaleDateString('fr-FR')}</div>
                        <div style="font-size:11px;color:${clr};"><i class="fas fa-${ico}"></i> ${isPaid?'Payée':isOvd?'En retard':'En attente'}</div>
                    </div>`;
                });
                html += `</div></div>`;
            }

            // ── Paiements avec accordéon ──
            html += `<div><h4 style="color:var(--accent-color);margin-bottom:15px;"><i class="fas fa-receipt"></i> Historique des Paiements</h4>`;

            if (payments.length === 0) {
                html += `<div style="text-align:center;padding:40px;color:#ccc;">
                    <i class="fas fa-inbox" style="font-size:48px;margin-bottom:20px;opacity:0.5;"></i>
                    <h3>Aucun paiement enregistré</h3>
                    <p>Cet étudiant n'a effectué aucun paiement.</p></div>`;
            } else {
                html += `<div style="max-height:500px;overflow-y:auto;">`;

                payments.forEach((payment, index) => {
                    const isCancelled = payment.status === 'cancelled';
                    const brdClr = isCancelled ? 'var(--danger-color)' : 'var(--success-color)';
                    const cardId = 'hist-alloc-' + payment.id;
                    html += `
                        <div style="background:rgba(255,255,255,0.05);padding:15px;border-radius:8px;margin-bottom:10px;border-left:3px solid ${brdClr};${isCancelled?'opacity:0.7;':''}">
                            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:10px;">
                                <div>
                                    <div style="font-size:11px;color:${brdClr};margin-bottom:5px;">
                                        <i class="fas fa-${isCancelled?'times':'check'}-circle"></i>
                                        Paiement #${payments.length - index}${isCancelled?' — <span style=\'background:var(--danger-color);color:white;padding:1px 6px;border-radius:4px;font-size:10px;\'>ANNULÉ</span>':''}
                                    </div>
                                    <div style="font-size:20px;font-weight:bold;color:${brdClr};">
                                        ${isCancelled?'<s>':''}${parseFloat(payment.amount_paid).toLocaleString('fr-FR')} FCFA${isCancelled?'</s>':''}
                                    </div>
                                </div>
                                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                                    <div style="font-size:12px;color:#ccc;">
                                        <i class="fas fa-calendar"></i> ${new Date(payment.payment_date).toLocaleDateString('fr-FR')}
                                        ${new Date(payment.payment_date).toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'})}
                                    </div>
                                    <button onclick="toggleHistAlloc(${payment.id}, '${cardId}', this)"
                                        style="background:rgba(3,155,229,0.2);border:1px solid var(--accent-color);color:var(--accent-color);padding:3px 10px;border-radius:14px;cursor:pointer;font-size:11px;">
                                        <i class="fas fa-sitemap"></i> Ventilation
                                    </button>
                                </div>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                                <div>
                                    <div style="font-size:11px;color:#999;">Méthode</div>
                                    <div style="font-size:13px;color:#ccc;"><i class="fas fa-money-bill-wave"></i> ${methodLabels[payment.payment_method]||payment.payment_method}</div>
                                </div>
                                <div>
                                    <div style="font-size:11px;color:#999;">Type déclaré</div>
                                    <div style="font-size:13px;color:#ccc;"><i class="fas fa-tag"></i> ${_feeTypeLabels[payment.payment_type]||payment.payment_type}</div>
                                </div>
                            </div>

                            ${payment.receipt_number?`<div style="margin-bottom:8px;"><div style="font-size:11px;color:#999;">Reçu</div><div style="font-family:monospace;background:rgba(255,255,255,0.1);padding:3px 8px;border-radius:4px;display:inline-block;font-size:12px;">${payment.receipt_number}</div></div>`:''}
                            ${payment.reference_number?`<div style="margin-bottom:8px;"><div style="font-size:11px;color:#999;">Référence</div><div style="font-size:12px;color:#ccc;">${payment.reference_number}</div></div>`:''}
                            ${payment.description?`<div style="margin-bottom:8px;"><div style="font-size:11px;color:#999;">Notes</div><div style="font-size:12px;color:#ccc;">${payment.description}</div></div>`:''}
                            ${isCancelled&&payment.cancel_reason?`<div style="margin-bottom:8px;padding:6px 10px;background:rgba(231,76,60,0.1);border-radius:4px;font-size:12px;color:var(--danger-color);"><i class="fas fa-comment-slash"></i> Motif : ${payment.cancel_reason}</div>`:''}

                            <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-color);font-size:11px;color:#999;">
                                <i class="fas fa-user"></i> Enregistré par ${payment.recorded_by_name||'Admin'}
                            </div>

                            <!-- Accordéon allocations -->
                            <div id="${cardId}" style="display:none;margin-top:12px;padding:10px;background:rgba(3,155,229,0.06);border:1px solid rgba(3,155,229,0.2);border-radius:6px;">
                                <div style="text-align:center;color:#ccc;font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Chargement…</div>
                            </div>
                        </div>`;
                });
                html += `</div>`;
            }
            html += `</div>`;
            document.getElementById('student_payment_history_content').innerHTML = html;
        }

        function toggleHistAlloc(paymentId, cardId, btn) {
            const panel = document.getElementById(cardId);
            if (!panel) return;
            const isOpen = panel.style.display !== 'none';
            panel.style.display = isOpen ? 'none' : 'block';
            if (btn) btn.innerHTML = isOpen ? '<i class="fas fa-sitemap"></i> Ventilation' : '<i class="fas fa-times"></i> Fermer';
            if (isOpen || _histAllocLoaded[paymentId]) return;

            _histAllocLoaded[paymentId] = true;
            fetch('?action=get_payment_allocations&payment_id=' + paymentId)
                .then(r => r.json())
                .then(data => {
                    if (data.success) { renderAllocContent(panel, data.allocations); }
                    else { panel.innerHTML = '<div style="color:var(--danger-color);font-size:12px;"><i class="fas fa-exclamation-triangle"></i> ' + (data.error||'Erreur') + '</div>'; }
                })
                .catch(() => { panel.innerHTML = '<div style="color:var(--danger-color);font-size:12px;"><i class="fas fa-exclamation-triangle"></i> Erreur de connexion.</div>'; });
        }

        // Voir les détails d'un message
        function viewMessage(messageId) {
            const message = messagesData.find(m => m.id == messageId);
            if (!message) return;

            const statusLabels = {
                'new': 'Nouveau',
                'read': 'Lu',
                'in_progress': 'En cours',
                'resolved': 'Résolu',
                'closed': 'Fermé'
            };

            const priorityLabels = {
                'urgent': 'Urgent',
                'high': 'Haute',
                'normal': 'Normale',
                'low': 'Basse'
            };

            let content = `
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <div>
                            <strong style="color: var(--accent-color); font-size: 16px;">
                                ${message.student_name}
                            </strong>
                            <br>
                            <small style="color: #ccc;">${message.email || ''} - ${message.class_name || ''}</small>
                        </div>
                        <div>
                            <span class="priority-badge priority-${message.priority}">
                                ${priorityLabels[message.priority]}
                            </span>
                        </div>
                    </div>

                    <div style="background: rgba(255,255,255,0.05); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                        <div style="margin-bottom: 10px;">
                            <strong style="color: var(--accent-color);">Sujet:</strong>
                            <p style="margin: 5px 0;">${message.subject}</p>
                        </div>
                        <div>
                            <strong style="color: var(--accent-color);">Message:</strong>
                            <p style="margin: 5px 0; white-space: pre-wrap;">${message.message}</p>
                        </div>
                        <div style="margin-top: 10px; font-size: 12px; color: #999;">
                            Envoyé le ${new Date(message.created_at).toLocaleString('fr-FR')}
                        </div>
                    </div>

                    ${message.response ? `
                        <div style="background: rgba(46, 204, 113, 0.1); padding: 15px; border-radius: 8px; border-left: 3px solid var(--success-color);">
                            <strong style="color: var(--success-color);">Réponse:</strong>
                            <p style="margin: 10px 0; white-space: pre-wrap;">${message.response}</p>
                            <div style="font-size: 12px; color: #999;">
                                Par ${message.responded_by_name || 'Admin'} - 
                                ${new Date(message.response_date).toLocaleString('fr-FR')}
                            </div>
                        </div>
                    ` : ''}

                    <div style="margin-top: 20px; text-align: center;">
                        <span class="message-status status-${message.status}">
                            Statut: ${statusLabels[message.status]}
                        </span>
                    </div>
                </div>
                
                ${message.status !== 'resolved' && message.status !== 'closed' ? `
                    <div style="text-align: center; margin-top: 20px;">
                        <button class="btn btn-success" onclick="closeModal('viewMessageModal'); respondMessage(${message.id});">
                            <i class="fas fa-reply"></i> Répondre
                        </button>
                    </div>
                ` : ''}
            `;

            document.getElementById('message_details_content').innerHTML = content;
            openModal('viewMessageModal');

            // Marquer comme lu si nouveau
            if (message.status === 'new') {
                markAsRead(messageId);
            }
        }

        // Répondre à un message
        function respondMessage(messageId) {
            const message = messagesData.find(m => m.id == messageId);
            if (!message) return;

            document.getElementById('respond_message_id').value = messageId;
            
            const originalMsg = `
                <strong style="color: var(--accent-color);">Message original de ${message.student_name}:</strong>
                <div style="margin-top: 10px;">
                    <strong>Sujet:</strong> ${message.subject}<br>
                    <strong>Message:</strong><br>
                    <p style="margin: 10px 0; white-space: pre-wrap;">${message.message}</p>
                </div>
            `;
            
            document.getElementById('original_message_display').innerHTML = originalMsg;
            openModal('respondMessageModal');
        }

        // Marquer un message comme lu (mise à jour DOM, sans rechargement)
        function markAsRead(messageId) {
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('action', 'update_message_status');
            formData.append('message_id', messageId);
            formData.append('status', 'read');

            fetch('', { method: 'POST', body: formData })
            .then(r => {
                if (!r.ok) return;
                const statusEl = document.getElementById('msg-status-' + messageId);
                if (statusEl && statusEl.classList.contains('status-new')) {
                    statusEl.className = 'message-status status-read';
                    statusEl.textContent = 'Lu';
                }
                const item = document.getElementById('msg-item-' + messageId);
                if (item) {
                    const dot = item.querySelector('span[title="Non lu"]');
                    if (dot) dot.remove();
                    item.style.borderLeftColor = '';
                    item.style.background = '';
                }
                _updateGlobalUnread(-1);
            });
        }

        // Export Excel (simulation)
        function exportPayments() {
            showAlert('success', 'Export Excel en cours de développement...');
        }

        // Afficher les alertes
        function showAlert(type, message) {
            const alertElement = document.getElementById(type + 'Alert');
            const messageElement = document.getElementById(type + 'Message');
            
            if (alertElement && messageElement) {
                messageElement.textContent = message;
                alertElement.classList.add('show');
                
                setTimeout(() => {
                    alertElement.classList.remove('show');
                }, 5000);
            }
        }

        // ── Formulaire paiement ventilé ──────────────────────────────────────
        let _payCurrentStudentId = null;
        const _feeTypeLabels = {
            registration: 'Inscription', tuition: 'Scolarité', insurance: 'Assurance',
            library: 'Bibliothèque',     practical: 'Travaux pratiques', other: 'Autres frais',
            installment: 'Tranche'
        };

        function onPayStudentChange() {
            const sel       = document.getElementById('pay_student_id');
            const studentId = sel.value;
            const balInfo   = document.getElementById('pay-balance-info');
            const stepB     = document.getElementById('payment-step-b');

            if (!studentId) {
                balInfo.style.display = 'none';
                stepB.style.display   = 'none';
                _payCurrentStudentId  = null;
                return;
            }
            _payCurrentStudentId = studentId;

            const opt     = sel.selectedOptions[0];
            const balance = parseFloat(opt ? opt.dataset.balance : 0) || 0;
            document.getElementById('pay-balance-amount').textContent = balance.toLocaleString('fr-FR') + ' FCFA';
            balInfo.style.display = 'block';

            stepB.style.display = 'block';
            document.getElementById('pay-ventilation-content').innerHTML =
                '<div style="text-align:center;padding:24px;color:#ccc;">' +
                '<i class="fas fa-spinner fa-spin" style="font-size:28px;color:var(--accent-color);"></i>' +
                '<p style="margin-top:8px;">Chargement des échéances…</p></div>';
            document.getElementById('pay-total-display').textContent = '0 FCFA';
            document.getElementById('pay-submit-error').style.display = 'none';

            fetch('?action=get_student_open_deadlines&student_id=' + encodeURIComponent(studentId) + '&annee=' + encodeURIComponent(_anneeFiltre))
                .then(r => r.json())
                .then(data => {
                    if (data.success) { renderVentilationForm(data); }
                    else {
                        document.getElementById('pay-ventilation-content').innerHTML =
                            '<div style="color:var(--danger-color);padding:12px;border:1px solid var(--danger-color);border-radius:6px;">' +
                            '<i class="fas fa-exclamation-triangle"></i> ' + (data.error || 'Erreur de chargement.') + '</div>';
                    }
                })
                .catch(() => {
                    document.getElementById('pay-ventilation-content').innerHTML =
                        '<div style="color:var(--danger-color);padding:12px;"><i class="fas fa-exclamation-triangle"></i> Erreur de connexion.</div>';
                });
        }

        function renderVentilationForm(data) {
            const tf        = data.tuition_fee || {};
            const deadlines = data.deadlines   || [];
            const feesPaid  = data.fees_paid   || {};

            let html = '';

            // ── Échéances ouvertes ──
            if (deadlines.length === 0) {
                html += '<div style="background:rgba(46,204,113,0.1);border:1px solid var(--success-color);border-radius:6px;padding:12px 14px;margin-bottom:16px;font-size:13px;color:var(--success-color);">' +
                        '<i class="fas fa-check-circle"></i> Aucune échéance ouverte — toutes les échéances sont soldées.</div>';
            } else {
                html += '<h4 style="color:var(--accent-color);margin-bottom:10px;font-size:14px;"><i class="fas fa-list-ol"></i> Échéances de scolarité</h4>' +
                        '<div style="overflow-x:auto;"><table class="alloc-table">' +
                        '<thead><tr><th style="width:36px;"></th><th>N°</th><th>Date limite</th><th style="text-align:right;">Montant dû</th><th style="text-align:right;">Restant</th><th>À payer (FCFA)</th></tr></thead><tbody>';

                deadlines.forEach(dl => {
                    const remaining  = parseFloat(dl.remaining || 0);
                    const statusClr  = dl.status === 'overdue' ? 'var(--danger-color)' : dl.status === 'partial' ? 'var(--info-color)' : 'var(--warning-color)';
                    const statusLbl  = dl.status === 'overdue' ? 'En retard' : dl.status === 'partial' ? 'Partiel' : 'En attente';
                    const dueDate    = new Date(dl.due_date + 'T00:00:00').toLocaleDateString('fr-FR');
                    const key        = 'deadline:' + dl.id;

                    html += '<tr>' +
                        '<td><input type="checkbox" class="alloc-checkbox" id="chk-' + key + '" value="' + key + '" onchange="payAllocToggle(this)" style="cursor:pointer;width:15px;height:15px;"></td>' +
                        '<td><label for="chk-' + key + '" style="cursor:pointer;font-weight:500;">Éch. ' + (dl.deadline_num || '?') + '</label></td>' +
                        '<td style="white-space:nowrap;">' + dueDate +
                          ' <span style="background:' + statusClr + ';color:white;padding:1px 7px;border-radius:8px;font-size:10px;font-weight:600;">' + statusLbl + '</span></td>' +
                        '<td style="text-align:right;">' + parseFloat(dl.amount_due).toLocaleString('fr-FR') + ' FCFA</td>' +
                        '<td style="text-align:right;color:var(--warning-color);font-weight:500;">' + remaining.toLocaleString('fr-FR') + ' FCFA</td>' +
                        '<td><input type="number" class="alloc-amount-input" id="amt-' + key + '" value="' + remaining.toFixed(0) + '" min="1" max="' + remaining.toFixed(0) + '" step="1" oninput="payAllocCalcTotal()" disabled></td>' +
                        '</tr>';
                });
                html += '</tbody></table></div>';
            }

            // ── Frais ponctuels ──
            const feeKeys  = ['registration','insurance','library','practical','other'];
            const feeAmts  = {
                registration: parseFloat(tf.registration_fee || 0),
                insurance:    parseFloat(tf.insurance_fee    || 0),
                library:      parseFloat(tf.library_fee      || 0),
                practical:    parseFloat(tf.practical_fee    || 0),
                other:        parseFloat(tf.other_fees       || 0)
            };
            const anyFeeRemaining = feeKeys.some(ft => feeAmts[ft] > 0 && Math.max(0, feeAmts[ft] - parseFloat(feesPaid[ft] || 0)) > 0);

            if (anyFeeRemaining) {
                html += '<h4 style="color:var(--accent-color);margin:16px 0 10px;font-size:14px;"><i class="fas fa-receipt"></i> Frais ponctuels</h4>' +
                        '<div style="overflow-x:auto;"><table class="alloc-table">' +
                        '<thead><tr><th style="width:36px;"></th><th>Type</th><th style="text-align:right;">Montant classe</th><th style="text-align:right;">Déjà payé</th><th style="text-align:right;">Restant</th><th>À payer (FCFA)</th></tr></thead><tbody>';

                feeKeys.forEach(ft => {
                    const total  = feeAmts[ft];
                    if (total <= 0) return;
                    const paid   = parseFloat(feesPaid[ft] || 0);
                    const remain = Math.max(0, total - paid);
                    if (remain <= 0) return;
                    const key    = 'fee:' + ft;

                    html += '<tr>' +
                        '<td><input type="checkbox" class="alloc-checkbox" id="chk-' + key + '" value="' + key + '" onchange="payAllocToggle(this)" style="cursor:pointer;width:15px;height:15px;"></td>' +
                        '<td><label for="chk-' + key + '" style="cursor:pointer;font-weight:500;">' + (_feeTypeLabels[ft] || ft) + '</label></td>' +
                        '<td style="text-align:right;">' + total.toLocaleString('fr-FR') + ' FCFA</td>' +
                        '<td style="text-align:right;color:var(--success-color);">' + paid.toLocaleString('fr-FR') + ' FCFA</td>' +
                        '<td style="text-align:right;color:var(--warning-color);font-weight:500;">' + remain.toLocaleString('fr-FR') + ' FCFA</td>' +
                        '<td><input type="number" class="alloc-amount-input" id="amt-' + key + '" value="' + remain.toFixed(0) + '" min="1" max="' + remain.toFixed(0) + '" step="1" oninput="payAllocCalcTotal()" disabled></td>' +
                        '</tr>';
                });
                html += '</tbody></table></div>';
            }

            if (deadlines.length === 0 && !anyFeeRemaining) {
                html += '<div style="text-align:center;padding:20px;color:#aaa;"><i class="fas fa-check-circle" style="color:var(--success-color);"></i> Aucun frais restant à ventiler.</div>';
            }

            document.getElementById('pay-ventilation-content').innerHTML = html;
        }

        function payAllocToggle(cb) {
            const amtInput = document.getElementById('amt-' + cb.value);
            if (amtInput) amtInput.disabled = !cb.checked;
            payAllocCalcTotal();
        }

        function payAllocCalcTotal() {
            let total = 0;
            document.querySelectorAll('.alloc-checkbox:checked').forEach(cb => {
                const inp = document.getElementById('amt-' + cb.value);
                if (inp) total += parseFloat(inp.value) || 0;
            });
            document.getElementById('pay-total-display').textContent = total.toLocaleString('fr-FR') + ' FCFA';
        }

        async function submitVentilatedPayment() {
            const studentId = _payCurrentStudentId;
            if (!studentId) { showAlert('error', 'Veuillez sélectionner un étudiant.'); return; }

            const allocations = [];
            document.querySelectorAll('.alloc-checkbox:checked').forEach(cb => {
                const parts = cb.value.split(':');
                const type  = parts[0];
                const id    = parts.slice(1).join(':');
                const inp   = document.getElementById('amt-' + cb.value);
                const amt   = parseFloat(inp ? inp.value : 0) || 0;
                if (type === 'deadline') {
                    allocations.push({ deadline_id: parseInt(id), allocation_type: 'tuition', amount: amt });
                } else {
                    allocations.push({ deadline_id: null, allocation_type: id, amount: amt });
                }
            });

            const errEl = document.getElementById('pay-submit-error');
            const errTxt = document.getElementById('pay-submit-error-text');

            if (allocations.length === 0) {
                errTxt.textContent = 'Cochez au moins une ligne à régler.';
                errEl.style.display = 'block'; return;
            }
            const total = allocations.reduce((s, a) => s + a.amount, 0);
            if (total <= 0) {
                errTxt.textContent = 'Le total des allocations doit être supérieur à 0.';
                errEl.style.display = 'block'; return;
            }

            const btn = document.getElementById('pay-submit-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement…';
            errEl.style.display = 'none';

            try {
                const resp = await fetch('?action=add_payment_ventilated', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        csrf_token:     '<?php echo $csrf_token; ?>',
                        student_id:     studentId,
                        annee:          _anneeFiltre,
                        payment_method: document.getElementById('pay_method').value,
                        reference:      document.getElementById('pay_reference').value,
                        description:    document.getElementById('pay_description').value,
                        allocations:    allocations
                    })
                }).then(r => r.json());

                if (resp.success) {
                    closeModal('addPaymentModal');
                    showAlert('success', resp.message);
                    setTimeout(() => location.reload(), 1800);
                } else {
                    errTxt.textContent = resp.error || 'Erreur inconnue.';
                    errEl.style.display = 'block';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer le Paiement';
                }
            } catch(e) {
                errTxt.textContent = 'Erreur de connexion.';
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer le Paiement';
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        // Auto-calcul du total des frais
        function updateTotalAmount() {
            const fields = ['registration_fee', 'tuition_fee', 'insurance_fee', 'library_fee', 'practical_fee', 'other_fees'];
            let total = 0;
            
            fields.forEach(field => {
                const value = parseFloat(document.querySelector(`input[name="${field}"]`).value) || 0;
                total += value;
            });
            
            document.querySelector('input[name="total_amount"]').value = total.toFixed(2);
        }

        // Ajouter les écouteurs pour le calcul automatique
        document.addEventListener('DOMContentLoaded', function() {
            const feeFields = ['registration_fee', 'tuition_fee', 'insurance_fee', 'library_fee', 'practical_fee', 'other_fees'];
            feeFields.forEach(field => {
                const input = document.querySelector(`input[name="${field}"]`);
                if (input) {
                    input.addEventListener('input', updateTotalAmount);
                }
            });

            // Animation d'entrée pour les cartes
            const cards = document.querySelectorAll('.stat-card, .class-section');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Masquer les alertes après 5 secondes
            setTimeout(() => {
                document.querySelectorAll('.alert.show').forEach(alert => {
                    alert.classList.remove('show');
                });
            }, 5000);
        });

        // Gestion des raccourcis clavier
        document.addEventListener('keydown', function(e) {
            // Ctrl+N pour nouveau paiement
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                openModal('addPaymentModal');
            }
            
            // Échap pour fermer les modales
            if (e.key === 'Escape') {
                const openModals = document.querySelectorAll('.modal[style*="display: block"]');
                openModals.forEach(modal => {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                });
            }
        });
        // ── Annulation de paiement ─────────────────────────────────────────
        function openCancelModal(paymentId, studentName, amount) {
            document.getElementById('cancel-payment-id').value = paymentId;
            document.getElementById('cancel-payment-info').innerHTML = `
                <strong style="color:var(--danger-color);">
                    <i class="fas fa-exclamation-circle"></i> Paiement #${paymentId}
                </strong>
                <div style="margin-top:8px; color:#ccc;">
                    Étudiant&nbsp;: <strong style="color:white;">${studentName}</strong><br>
                    Montant&nbsp;&nbsp;: <strong style="color:var(--warning-color);">${parseFloat(amount).toLocaleString('fr-FR')} FCFA</strong>
                </div>
            `;
            document.getElementById('cancel-motif').value = '';
            document.getElementById('cancel-motif-error').style.display = 'none';
            document.getElementById('cancel-motif').style.borderColor = '';
            const btn = document.getElementById('cancel-confirm-btn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-times-circle"></i> Confirmer l\'annulation';
            openModal('cancelPaymentModal');
        }

        function confirmCancelPayment() {
            const paymentId = parseInt(document.getElementById('cancel-payment-id').value);
            const motif     = document.getElementById('cancel-motif').value.trim();
            const btn       = document.getElementById('cancel-confirm-btn');
            const errEl     = document.getElementById('cancel-motif-error');
            const motifEl   = document.getElementById('cancel-motif');

            if (!motif) {
                motifEl.style.borderColor = 'var(--danger-color)';
                errEl.style.display = 'inline';
                motifEl.focus();
                return;
            }
            motifEl.style.borderColor = '';
            errEl.style.display = 'none';

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Annulation…';

            fetch('?action=cancel_payment', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ payment_id: paymentId, motif: motif })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    closeModal('cancelPaymentModal');
                    showAlert('success', 'Paiement annulé avec succès. La page va se recharger…');
                    setTimeout(() => location.reload(), 1800);
                } else {
                    showAlert('error', data.error || 'Erreur lors de l\'annulation');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-times-circle"></i> Confirmer l\'annulation';
                }
            })
            .catch(() => {
                showAlert('error', 'Erreur de connexion. Veuillez réessayer.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-times-circle"></i> Confirmer l\'annulation';
            });
        }
        // ────────────────────────────────────────────────────────────────────

        // ── Réduction individuelle ──────────────────────────────────────────
        let discountBaseAmount = 0;

        function openDiscountModal(studentId, studentName, baseAmount, existingDiscount) {
            document.getElementById('discount_student_id').value   = studentId;
            document.getElementById('discount_student_name').textContent = studentName;
            discountBaseAmount = parseFloat(baseAmount) || 0;
            document.getElementById('discount_base_amount').textContent =
                discountBaseAmount.toLocaleString('fr-FR') + ' FCFA';

            const notice = document.getElementById('discount_existing_notice');
            notice.style.display = parseFloat(existingDiscount) > 0 ? 'block' : 'none';

            // Réinitialiser le formulaire
            document.getElementById('discount_type').value  = 'amount';
            document.getElementById('discount_value').value = '';
            document.getElementById('discount_value_label').textContent = 'Montant de la réduction (FCFA) *';
            document.getElementById('discount_preview').style.display = 'none';

            openModal('discountModal');
        }

        function updateDiscountPreview() {
            const type  = document.getElementById('discount_type').value;
            const value = parseFloat(document.getElementById('discount_value').value) || 0;
            const label = document.getElementById('discount_value_label');

            label.textContent = type === 'percent'
                ? 'Pourcentage de réduction (%) *'
                : 'Montant de la réduction (FCFA) *';

            if (value <= 0 || discountBaseAmount <= 0) {
                document.getElementById('discount_preview').style.display = 'none';
                return;
            }

            let discountAmt = type === 'percent'
                ? discountBaseAmount * value / 100
                : value;
            discountAmt = Math.min(discountAmt, discountBaseAmount);
            const net = discountBaseAmount - discountAmt;

            document.getElementById('prev_base').textContent     = discountBaseAmount.toLocaleString('fr-FR') + ' FCFA';
            document.getElementById('prev_discount').textContent = '- ' + discountAmt.toLocaleString('fr-FR', {minimumFractionDigits:0, maximumFractionDigits:2}) + ' FCFA';
            document.getElementById('prev_net').textContent      = net.toLocaleString('fr-FR', {minimumFractionDigits:0, maximumFractionDigits:2}) + ' FCFA';
            document.getElementById('discount_preview').style.display = 'block';
        }
        // ────────────────────────────────────────────────────────────────────

        // ══════════════════════════════════════════════════════════════════════
        // ── DOSSIER FINANCIER ÉTUDIANT ────────────────────────────────────────
        // ══════════════════════════════════════════════════════════════════════
        function _escHtml(t) { return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
        let _dossier = { studentId: null, studentName: null, threadId: null, sending: false, adminName: '<?php echo htmlspecialchars(addslashes($_SESSION['name'] ?? 'Admin'), ENT_QUOTES); ?>' };

        // ── Compteur global messages non lus ──────────────────────────────────
        let _globalUnreadCount = <?php echo (int)$unread_messages_count; ?>;
        function _updateGlobalUnread(delta) {
            _globalUnreadCount = Math.max(0, _globalUnreadCount + delta);
            ['global-unread-badge-nav', 'global-unread-badge-tab'].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                el.textContent = _globalUnreadCount;
                el.style.display = _globalUnreadCount > 0 ? '' : 'none';
            });
            const sv = document.getElementById('global-unread-stat-value');
            if (sv) sv.textContent = _globalUnreadCount;
        }
        const _feeTypesFR = { registration:'Inscription', tuition:'Scolarité', insurance:'Assurance', library:'Bibliothèque', practical:'TP', other:'Autre' };
        const _statusFR   = { paid:'Soldé', partial:'Partiel', overdue:'En Retard', unpaid:'Impayé', no_fees:'Pas de frais' };
        const _statusColors = { paid:'var(--success-color)', partial:'var(--warning-color)', overdue:'var(--danger-color)', unpaid:'#95a5a6', no_fees:'#95a5a6' };

        function openStudentDossier(studentId, studentName, forceTab) {
            _dossier.studentId   = studentId;
            _dossier.studentName = studentName;
            _dossier.threadId    = null;

            document.getElementById('dossier-student-title').textContent = studentName;
            document.getElementById('dossier-loading').style.display = 'block';
            document.querySelectorAll('.dossier-tab-content').forEach(t => t.style.display = 'none');
            document.querySelectorAll('.dossier-tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelector('.dossier-tab-btn').classList.add('active');
            document.getElementById('dossier-msg-tab-btn').innerHTML = '<i class="fas fa-comments"></i> Messages';

            openModal('studentDossierModal');

            Promise.all([
                fetch('?action=get_student_dossier_overview&student_id=' + encodeURIComponent(studentId)).then(r => r.json()),
                fetch('?action=get_student_messages&student_id=' + encodeURIComponent(studentId)).then(r => r.json()),
            ]).then(([overview, msgs]) => {
                document.getElementById('dossier-loading').style.display = 'none';

                if (overview.success) renderDossierOverview(overview);
                if (msgs.success)     renderDossierMessages(msgs);

                // Mettre à jour le compteur global et le DOM de la Boîte de réception
                if (overview.success && overview.unread_messages > 0) {
                    _updateGlobalUnread(-overview.unread_messages);
                    // Marquer visuellement les items de la Boîte de réception comme lus
                    document.querySelectorAll('.message-item[data-sid]').forEach(item => {
                        if (item.dataset.sid !== String(studentId)) return;
                        const dot = item.querySelector('span[title="Non lu"]');
                        if (dot) dot.remove();
                        item.style.borderLeftColor = '';
                        item.style.background = '';
                        item.setAttribute('onmouseleave', "this.style.background=''");
                        const statusEl = item.querySelector('.message-status');
                        if (statusEl && statusEl.classList.contains('status-new')) {
                            statusEl.className = 'message-status status-read';
                            statusEl.textContent = 'Lu';
                        }
                    });
                }

                // Charger l'échéancier et l'historique en arrière-plan
                loadDossierEcheancier(studentId);
                loadDossierHistory(studentId);

                // Choix de l'onglet à afficher
                if (forceTab === 'messages') {
                    const msgBtn = document.getElementById('dossier-msg-tab-btn');
                    switchDossierTab('messages', msgBtn);
                } else if (overview.success && overview.unread_messages > 0) {
                    const msgBtn = document.getElementById('dossier-msg-tab-btn');
                    switchDossierTab('messages', msgBtn);
                } else {
                    document.getElementById('dossier-tab-overview').style.display = 'block';
                }
            }).catch(() => {
                document.getElementById('dossier-loading').innerHTML = '<div style="color:var(--danger-color);text-align:center;padding:40px;"><i class="fas fa-exclamation-triangle"></i> Erreur de chargement.</div>';
            });
        }

        function switchDossierTab(tab, btn) {
            document.querySelectorAll('.dossier-tab-content').forEach(t => t.style.display = 'none');
            document.querySelectorAll('.dossier-tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById('dossier-tab-' + tab).style.display = 'block';
            if (btn) btn.classList.add('active');
        }

        function renderDossierOverview(data) {
            const s = data.student;
            // Avatar
            const av = document.getElementById('dossier-avatar');
            if (s.avatar && s.avatar !== 'default_avatar.png') {
                av.innerHTML = `<img src="../uploads/avatars/${s.avatar}" alt="Photo">`;
            } else {
                av.textContent = s.initials;
            }
            document.getElementById('dossier-name').textContent  = s.name;
            document.getElementById('dossier-id').textContent    = 'ID : ' + s.id;
            document.getElementById('dossier-class').textContent = s.class_name + ' — ' + s.academic_year;

            // Jauge circulaire
            const pct = data.pct;
            const gauge = document.getElementById('dossier-gauge');
            gauge.style.setProperty('--pct', pct + '%');
            document.getElementById('dossier-gauge-pct').textContent = pct + '%';

            // Badge statut
            const statusEl = document.getElementById('dossier-global-status');
            const color = _statusColors[data.status] || '#aaa';
            statusEl.innerHTML = `<span class="dossier-status-badge" style="background:${color}20; border:1px solid ${color}; color:${color};">
                <i class="fas fa-circle" style="font-size:8px;"></i> ${_statusFR[data.status] || data.status}
            </span>`;

            // Cards
            const fmt = v => parseFloat(v||0).toLocaleString('fr-FR', {maximumFractionDigits:0}) + ' FCFA';
            document.getElementById('dc-total').textContent     = fmt(data.net_amount);
            document.getElementById('dc-paid').textContent      = fmt(data.amount_paid);
            document.getElementById('dc-remaining').textContent = fmt(data.remaining);
            if (data.next_deadline) {
                const d = data.next_deadline;
                const nd = new Date(d.due_date);
                document.getElementById('dc-next-date').textContent   = nd.toLocaleDateString('fr-FR');
                document.getElementById('dc-next-amount').textContent = fmt(d.amount_due - d.amount_paid) + ' restant';
            } else {
                document.getElementById('dc-next-date').textContent   = '—';
                document.getElementById('dc-next-amount').textContent = '';
            }

            // Badge onglet messages
            const msgBtn = document.getElementById('dossier-msg-tab-btn');
            if (data.unread_messages > 0) {
                msgBtn.innerHTML = `<i class="fas fa-comments"></i> Messages <span class="tab-badge">${data.unread_messages}</span>`;
            }
        }

        function loadDossierEcheancier(studentId) {
            fetch('?action=get_deadlines_detail&student_id=' + encodeURIComponent(studentId))
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        document.getElementById('dossier-echeancier-content').innerHTML =
                            '<div style="color:var(--danger-color);padding:20px;"><i class="fas fa-exclamation-triangle"></i> ' + (data.error||'Erreur') + '</div>';
                        return;
                    }
                    const deadlines = data.deadlines || [];
                    const student   = data.student   || {};
                    if (!deadlines.length) {
                        document.getElementById('dossier-echeancier-content').innerHTML = `
                            <div style="text-align:center;padding:40px;color:#aaa;">
                                <i class="fas fa-calendar-alt" style="font-size:36px;margin-bottom:12px;display:block;opacity:.4;"></i>
                                Aucune échéance. <br><br>
                                <button class="btn btn-primary btn-small" onclick="manageDeadlines('${studentId}', '${_dossier.studentName}', 0); closeModal('studentDossierModal');">
                                    <i class="fas fa-calendar-plus"></i> Créer un échéancier
                                </button>
                            </div>`;
                        return;
                    }
                    let html = `<div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <h3 style="color:var(--accent-color);"><i class="fas fa-calendar-check"></i> Échéancier — ${deadlines.length} échéance(s)</h3>
                        <button class="btn btn-small btn-warning" onclick="manageDeadlines('${studentId}','${_dossier.studentName}',${deadlines.length}); closeModal('studentDossierModal');">
                            <i class="fas fa-edit"></i> Modifier
                        </button>
                    </div>
                    <div style="overflow-x:auto;">
                    <table class="student-table">
                        <thead><tr><th>#</th><th>Échéance</th><th>Date</th><th>Montant dû</th><th>Payé</th><th>Restant</th><th>Statut</th></tr></thead><tbody>`;
                    const statusMap = {pending:'En attente', paid:'Payé', partial:'Partiel', overdue:'En retard'};
                    const statusClr = {pending:'#95a5a6', paid:'var(--success-color)', partial:'var(--warning-color)', overdue:'var(--danger-color)'};
                    deadlines.forEach((d, i) => {
                        const st   = d.status || 'pending';
                        const rest = (parseFloat(d.amount_due||0) - parseFloat(d.amount_paid||0)).toLocaleString('fr-FR', {maximumFractionDigits:0});
                        html += `<tr>
                            <td>${i+1}</td>
                            <td>Échéance ${i+1}/${deadlines.length}</td>
                            <td>${new Date(d.due_date).toLocaleDateString('fr-FR')}</td>
                            <td style="color:var(--accent-color);">${parseFloat(d.amount_due||0).toLocaleString('fr-FR')} FCFA</td>
                            <td style="color:var(--success-color);">${parseFloat(d.amount_paid||0).toLocaleString('fr-FR')} FCFA</td>
                            <td style="color:var(--warning-color);">${rest} FCFA</td>
                            <td><span style="background:${statusClr[st]}20;color:${statusClr[st]};padding:3px 8px;border-radius:10px;font-size:11px;font-weight:600;">${statusMap[st]||st}</span></td>
                        </tr>`;
                    });
                    html += '</tbody></table></div>';
                    document.getElementById('dossier-echeancier-content').innerHTML = html;
                })
                .catch(() => {
                    document.getElementById('dossier-echeancier-content').innerHTML =
                        '<div style="color:var(--danger-color);padding:20px;"><i class="fas fa-exclamation-triangle"></i> Erreur de chargement.</div>';
                });
        }

        function loadDossierHistory(studentId) {
            fetch('?action=get_student_payments&student_id=' + encodeURIComponent(studentId))
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        document.getElementById('dossier-payments-content').innerHTML =
                            '<div style="color:var(--danger-color);padding:20px;"><i class="fas fa-exclamation-triangle"></i> ' + (data.error||'Erreur') + '</div>';
                        return;
                    }
                    const payments = data.payments || [];
                    if (!payments.length) {
                        document.getElementById('dossier-payments-content').innerHTML = `
                            <div style="text-align:center;padding:40px;color:#aaa;">
                                <i class="fas fa-receipt" style="font-size:36px;margin-bottom:12px;display:block;opacity:.4;"></i>
                                Aucun paiement enregistré.
                            </div>`;
                        return;
                    }
                    const methods = {cash:'Espèces', bank_transfer:'Virement', mobile_money:'Mobile Money', check:'Chèque', other:'Autre'};
                    const _exportUrl = `export_payments_excel.php?mode=student&student_id=${encodeURIComponent(studentId)}&annee=${encodeURIComponent(_anneeFiltre)}`;
                    let html = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <span style="color:#aaa;font-size:13px;"><i class="fas fa-receipt" style="margin-right:5px;"></i>${payments.length} paiement(s)</span>
                        <a href="${_exportUrl}" target="_blank"
                           style="background:#27ae60;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                    </div>
                    <div style="overflow-x:auto;"><table class="student-table">
                        <thead><tr><th>Date</th><th>Montant</th><th>Méthode</th><th>Reçu</th><th>Statut</th><th>Actions</th></tr></thead><tbody>`;
                    payments.forEach(p => {
                        const isCancelled = p.status === 'cancelled';
                        const paidAt = new Date(p.payment_date).toLocaleDateString('fr-FR');
                        html += `<tr style="${isCancelled?'opacity:.65;':''}">
                            <td>${paidAt}</td>
                            <td style="color:${isCancelled?'var(--danger-color)':'var(--success-color)'};font-weight:bold;">
                                ${isCancelled?'<s>':''}${parseFloat(p.amount_paid).toLocaleString('fr-FR')} FCFA${isCancelled?'</s>':''}
                            </td>
                            <td>${methods[p.payment_method]||p.payment_method}</td>
                            <td><span style="font-family:monospace;font-size:12px;">${p.receipt_number||'—'}</span></td>
                            <td>`;
                        if (isCancelled) {
                            html += `<span style="background:rgba(231,76,60,.15);color:var(--danger-color);padding:3px 8px;border-radius:10px;font-size:11px;">Annulé</span>`;
                        } else {
                            html += `<span style="background:rgba(46,204,113,.15);color:var(--success-color);padding:3px 8px;border-radius:10px;font-size:11px;">Validé</span>`;
                        }
                        html += `</td><td style="display:flex;gap:6px;flex-wrap:wrap;">`;
                        html += `<button onclick="toggleDossierAlloc(${p.id})" style="background:rgba(3,155,229,.2);border:1px solid var(--accent-color);color:var(--accent-color);padding:3px 8px;border-radius:10px;cursor:pointer;font-size:11px;" title="Ventilation"><i class="fas fa-sitemap"></i></button>`;
                        if (!isCancelled && p.receipt_number) {
                            html += `<a href="print_receipt.php?receipt_id=${encodeURIComponent(p.receipt_number)}" target="_blank" style="background:rgba(52,152,219,.2);border:1px solid var(--info-color);color:var(--info-color);padding:3px 8px;border-radius:10px;font-size:11px;text-decoration:none;" title="Reçu PDF"><i class="fas fa-print"></i></a>`;
                        }
                        if (!isCancelled) {
                            html += `<button onclick="closeModal('studentDossierModal'); openCancelModal(${p.id}, '${_dossier.studentName.replace(/'/g,"\\'")}', ${p.amount_paid});" style="background:rgba(231,76,60,.2);border:1px solid var(--danger-color);color:var(--danger-color);padding:3px 8px;border-radius:10px;cursor:pointer;font-size:11px;" title="Annuler"><i class="fas fa-times-circle"></i></button>`;
                        }
                        html += `</td></tr>
                        <tr id="dalloc-row-${p.id}" style="display:none;background:rgba(3,155,229,.05);">
                            <td colspan="6" style="padding:10px 16px;">
                                <div id="dalloc-content-${p.id}" style="font-size:12px;color:#ccc;"><i class="fas fa-spinner fa-spin"></i> Chargement…</div>
                            </td>
                        </tr>`;
                    });
                    html += '</tbody></table></div>';
                    document.getElementById('dossier-payments-content').innerHTML = html;
                })
                .catch(() => {
                    document.getElementById('dossier-payments-content').innerHTML =
                        '<div style="color:var(--danger-color);padding:20px;"><i class="fas fa-exclamation-triangle"></i> Erreur de chargement.</div>';
                });
        }

        const _dallocLoaded = {};
        function toggleDossierAlloc(paymentId) {
            const row = document.getElementById('dalloc-row-' + paymentId);
            if (!row) return;
            const isOpen = row.style.display !== 'none';
            row.style.display = isOpen ? 'none' : 'table-row';
            if (isOpen || _dallocLoaded[paymentId]) return;
            _dallocLoaded[paymentId] = true;
            fetch('?action=get_payment_allocations&payment_id=' + paymentId)
                .then(r => r.json())
                .then(data => {
                    const el = document.getElementById('dalloc-content-' + paymentId);
                    if (!data.success || !data.allocations.length) {
                        el.innerHTML = '<em style="color:#777;">Ventilation non disponible.</em>'; return;
                    }
                    let h = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                    h += '<tr style="color:var(--accent-color);"><th style="text-align:left;padding:4px 8px;">Type</th><th style="text-align:right;padding:4px 8px;">Montant</th><th style="text-align:left;padding:4px 8px;">Échéance</th></tr>';
                    data.allocations.forEach(a => {
                        h += `<tr><td style="padding:4px 8px;">${_feeTypesFR[a.allocation_type]||a.allocation_type}</td>
                               <td style="padding:4px 8px;text-align:right;color:var(--success-color);">${parseFloat(a.amount).toLocaleString('fr-FR')} FCFA</td>
                               <td style="padding:4px 8px;color:#aaa;">${a.deadline_id ? 'Éch.'+a.deadline_num : '—'}</td></tr>`;
                    });
                    h += '</table>';
                    el.innerHTML = h;
                })
                .catch(() => { document.getElementById('dalloc-content-' + paymentId).innerHTML = '<em style="color:var(--danger-color);">Erreur.</em>'; });
        }

        function _buildDossierBubble(msg) {
            const isAdmin  = msg.user_type === 'admin';
            const side     = isAdmin ? 'admin-bubble' : 'student-bubble';
            const initials = msg.user_name ? msg.user_name.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase() : (isAdmin?'A':'E');
            const avatarBg = isAdmin ? 'var(--accent-color)' : 'rgba(255,255,255,0.15)';
            const time     = new Date(msg.created_at).toLocaleString('fr-FR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
            const el = document.createElement('div');
            el.className = 'chat-bubble-wrap ' + side;
            el.innerHTML = `<div class="chat-avatar-small" style="background:${avatarBg};">${initials}</div>
                <div>
                    <div class="chat-bubble">${_escHtml(msg.message).replace(/\n/g,'<br>')}</div>
                    <div class="chat-meta">${_escHtml(msg.user_name||'Utilisateur')} · ${time}</div>
                </div>`;
            return el;
        }

        function appendDossierBubble(msg, animate) {
            const container = document.getElementById('dossier-chat-messages');
            const placeholder = container.querySelector('[data-placeholder]');
            if (placeholder) placeholder.remove();
            const el = _buildDossierBubble(msg);
            if (animate) {
                el.style.opacity = '0';
                el.style.transform = 'translateY(8px)';
                el.style.transition = 'opacity .3s ease, transform .3s ease';
                container.appendChild(el);
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }));
            } else {
                container.appendChild(el);
            }
            container.scrollTop = container.scrollHeight;
        }

        function setDossierSendState(state, errMsg) {
            const btn   = document.getElementById('dossier-chat-send-btn');
            const icon  = document.getElementById('dossier-send-icon');
            const text  = document.getElementById('dossier-send-text');
            const input = document.getElementById('dossier-chat-input');
            const errEl = document.getElementById('dossier-chat-send-error');
            if (!btn) return;
            btn.style.background = '';
            btn.style.opacity    = '1';
            if (errEl) errEl.style.display = 'none';
            switch (state) {
                case 'loading':
                    btn.disabled      = true;
                    btn.style.opacity = '0.8';
                    icon.className    = 'fas fa-circle-notch fa-spin';
                    text.textContent  = 'Envoi…';
                    input.disabled    = true;
                    break;
                case 'success':
                    btn.disabled         = true;
                    btn.style.background = '#27ae60';
                    icon.className       = 'fas fa-check';
                    text.textContent     = 'Envoyé !';
                    input.disabled       = false;
                    input.value          = '';
                    setTimeout(() => { input.focus(); setDossierSendState('normal'); }, 1500);
                    break;
                case 'error':
                    btn.disabled         = false;
                    btn.style.background = 'var(--danger-color)';
                    icon.className       = 'fas fa-times';
                    text.textContent     = 'Échec — Réessayer';
                    input.disabled       = false;
                    if (errEl && errMsg) { errEl.textContent = errMsg; errEl.style.display = 'block'; }
                    break;
                default: // normal
                    btn.disabled     = false;
                    icon.className   = 'fas fa-paper-plane';
                    text.textContent = 'Envoyer';
                    input.disabled   = false;
            }
        }

        function renderDossierMessages(data) {
            const container = document.getElementById('dossier-chat-messages');
            const history   = data.history || [];
            const thread    = data.active_thread;

            if (thread) {
                _dossier.threadId = thread.id;
                const isOpen = !['resolved','closed'].includes(thread.status);
                const resolveBtn = document.getElementById('dossier-resolve-btn');
                resolveBtn.style.display = isOpen ? 'inline-flex' : 'none';
                const statusLabels = {new:'Nouveau', in_progress:'En cours', resolved:'Résolu', closed:'Fermé', read:'Lu'};
                document.getElementById('dossier-chat-status-label').textContent = 'Conversation : ' + (statusLabels[thread.status]||thread.status);
            }

            if (!history.length) {
                container.innerHTML = `<div data-placeholder style="text-align:center;padding:40px;color:#aaa;">
                    <i class="fas fa-comments" style="font-size:36px;margin-bottom:12px;display:block;opacity:.4;"></i>
                    Aucun message. Envoyez le premier message ci-dessous.
                </div>`;
                return;
            }

            container.innerHTML = '';
            history.forEach(msg => container.appendChild(_buildDossierBubble(msg)));
            container.scrollTop = container.scrollHeight;
        }

        function dossierSendMessage() {
            const input = document.getElementById('dossier-chat-input');
            const text  = input.value.trim();
            if (!text || !_dossier.studentId || _dossier.sending) return;
            _dossier.sending = true;
            setDossierSendState('loading');

            fetch('?action=send_finance_message', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({student_id: _dossier.studentId, message: text})
            })
            .then(r => r.json())
            .then(data => {
                _dossier.sending = false;
                if (data.success) {
                    _dossier.threadId = data.thread_id;
                    setDossierSendState('success');
                    if (data.entry) appendDossierBubble(data.entry, true);
                } else {
                    setDossierSendState('error', data.error || 'Erreur envoi message.');
                }
            })
            .catch(() => {
                _dossier.sending = false;
                setDossierSendState('error', 'Erreur de connexion.');
            });
        }

        function dossierResolveConversation() {
            if (!_dossier.threadId) return;
            fetch('?action=resolve_conversation', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({thread_id: _dossier.threadId})
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('dossier-resolve-btn').style.display = 'none';
                    document.getElementById('dossier-chat-status-label').textContent = 'Conversation : Résolu';
                    showAlert('success', 'Conversation marquée comme résolue.');
                } else {
                    showAlert('error', data.error || 'Erreur.');
                }
            });
        }
        // ══ fin DOSSIER ══════════════════════════════════════════════════════

    </script>
    <!-- Modal Réduction Individuelle -->
    <div class="modal" id="discountModal">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-tag"></i> Réduction individuelle
                </h3>
                <span class="modal-close" onclick="closeModal('discountModal')">&times;</span>
            </div>
            <form method="POST" id="discountForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action"     value="add_discount">
                <input type="hidden" name="student_id" id="discount_student_id">

                <div style="background:rgba(142,68,173,0.15); border:1px solid #8e44ad; border-radius:8px; padding:12px 16px; margin-bottom:20px;">
                    <strong id="discount_student_name" style="color:#ce93d8; font-size:15px;"></strong><br>
                    <small style="color:#ccc;">
                        Montant de base de la classe :
                        <strong id="discount_base_amount" style="color:white;"></strong>
                    </small>
                    <div id="discount_existing_notice" style="display:none; margin-top:6px; color:var(--warning-color); font-size:12px;">
                        <i class="fas fa-exclamation-triangle"></i> Une réduction existe déjà — elle sera remplacée.
                    </div>
                </div>

                <div class="form-group">
                    <label>Type de réduction *</label>
                    <select name="discount_type" id="discount_type" class="form-control" required onchange="updateDiscountPreview()">
                        <option value="amount">Montant fixe (FCFA)</option>
                        <option value="percent">Pourcentage (%)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label id="discount_value_label">Montant de la réduction (FCFA) *</label>
                    <input type="number" name="discount_value" id="discount_value" class="form-control"
                           min="1" step="0.01" required oninput="updateDiscountPreview()">
                </div>

                <div id="discount_preview" style="display:none; background:rgba(46,204,113,0.1); border:1px solid var(--success-color); border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:13px;">
                    <table style="width:100%; color:#ccc; border-collapse:collapse;">
                        <tr><td>Montant de base</td><td style="text-align:right; color:white;" id="prev_base">—</td></tr>
                        <tr><td>Réduction</td>      <td style="text-align:right; color:var(--success-color);" id="prev_discount">—</td></tr>
                        <tr style="border-top:1px solid rgba(255,255,255,0.2);">
                            <td><strong>Net à payer</strong></td>
                            <td style="text-align:right; color:var(--accent-color); font-weight:bold;" id="prev_net">—</td>
                        </tr>
                    </table>
                </div>

                <div class="form-group">
                    <label>Motif de la réduction</label>
                    <textarea name="reason" class="form-control" rows="2" placeholder="Ex: Bourse, situation familiale, mérite…"></textarea>
                </div>

                <div style="text-align:right; margin-top:20px;">
                    <button type="button" class="btn" onclick="closeModal('discountModal')" style="background:#95a5a6;color:white;margin-right:10px;">
                        Annuler
                    </button>
                    <button type="submit" class="btn" style="background:#8e44ad;color:white;">
                        <i class="fas fa-save"></i> Appliquer la réduction
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══ Modal Dossier Financier Complet ═══ -->
    <div class="modal" id="studentDossierModal" style="display:none; overflow:hidden;">
        <div class="dossier-modal-content modal-content" style="max-width:none; margin:0; height:100vh; border-radius:0; padding:0; display:flex; flex-direction:column;">
            <div class="dossier-header">
                <h2><i class="fas fa-folder-open"></i> Dossier Financier — <span id="dossier-student-title">…</span></h2>
                <span class="dossier-close" onclick="closeModal('studentDossierModal')">&times;</span>
            </div>
            <div class="dossier-tabs">
                <button class="dossier-tab-btn active" onclick="switchDossierTab('overview', this)">
                    <i class="fas fa-info-circle"></i> Vue Générale
                </button>
                <button class="dossier-tab-btn" onclick="switchDossierTab('echeancier', this)">
                    <i class="fas fa-calendar-alt"></i> Échéancier
                </button>
                <button class="dossier-tab-btn" onclick="switchDossierTab('historique', this)">
                    <i class="fas fa-history"></i> Paiements
                </button>
                <button class="dossier-tab-btn" id="dossier-msg-tab-btn" onclick="switchDossierTab('messages', this)">
                    <i class="fas fa-comments"></i> Messages
                </button>
            </div>
            <div class="dossier-body">
                <!-- Spinner initial -->
                <div id="dossier-loading" class="dossier-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    Chargement du dossier…
                </div>

                <!-- Onglet 1 : Vue générale -->
                <div class="dossier-tab-content" id="dossier-tab-overview" style="display:none;">
                    <div class="dossier-overview-grid">
                        <div class="dossier-profile">
                            <div class="dossier-avatar" id="dossier-avatar"></div>
                            <div class="dossier-student-name" id="dossier-name"></div>
                            <div class="dossier-student-id" id="dossier-id"></div>
                            <div class="dossier-student-class" id="dossier-class"></div>
                            <div class="circular-gauge-wrap">
                                <div class="circular-gauge" id="dossier-gauge">
                                    <div class="circular-gauge-inner" id="dossier-gauge-pct">0%</div>
                                </div>
                                <div class="circular-gauge-label">Progression paiement</div>
                            </div>
                            <div style="margin-top:12px;" id="dossier-global-status"></div>
                        </div>
                        <div>
                            <div class="dossier-cards-grid" id="dossier-cards">
                                <div class="dossier-card">
                                    <div class="dossier-card-value" id="dc-total" style="color:var(--accent-color)">—</div>
                                    <div class="dossier-card-label">Total Frais</div>
                                </div>
                                <div class="dossier-card">
                                    <div class="dossier-card-value" id="dc-paid" style="color:var(--success-color)">—</div>
                                    <div class="dossier-card-label">Montant Payé</div>
                                </div>
                                <div class="dossier-card">
                                    <div class="dossier-card-value" id="dc-remaining" style="color:var(--warning-color)">—</div>
                                    <div class="dossier-card-label">Reste Dû</div>
                                </div>
                                <div class="dossier-card" id="dc-next-deadline-card">
                                    <div class="dossier-card-value" id="dc-next-date" style="color:var(--info-color)">—</div>
                                    <div class="dossier-card-label">Prochaine Échéance</div>
                                    <div id="dc-next-amount" style="font-size:13px; color:#ccc; margin-top:4px;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Onglet 2 : Échéancier (réutilise la modal existante via proxy) -->
                <div class="dossier-tab-content" id="dossier-tab-echeancier" style="display:none;">
                    <div id="dossier-echeancier-content">
                        <div style="text-align:center;padding:40px;color:#aaa;">
                            <i class="fas fa-spinner fa-spin" style="font-size:28px;color:var(--accent-color);margin-bottom:12px;display:block;"></i>
                            Chargement de l'échéancier…
                        </div>
                    </div>
                </div>

                <!-- Onglet 3 : Historique paiements -->
                <div class="dossier-tab-content" id="dossier-tab-historique" style="display:none;">
                    <div id="dossier-payments-content">
                        <div style="text-align:center;padding:40px;color:#aaa;">
                            <i class="fas fa-spinner fa-spin" style="font-size:28px;color:var(--accent-color);margin-bottom:12px;display:block;"></i>
                            Chargement de l'historique…
                        </div>
                    </div>
                </div>

                <!-- Onglet 4 : Messages -->
                <div class="dossier-tab-content" id="dossier-tab-messages" style="display:none;">
                    <div class="dossier-chat-wrap">
                        <div id="dossier-chat-header" style="margin-bottom:10px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                            <span id="dossier-chat-status-label" style="font-size:13px; color:#aaa;"></span>
                            <button id="dossier-resolve-btn" class="btn btn-small btn-success" onclick="dossierResolveConversation()" style="display:none;">
                                <i class="fas fa-check-double"></i> Marquer résolu
                            </button>
                        </div>
                        <div class="dossier-chat-messages" id="dossier-chat-messages">
                            <div style="text-align:center;padding:40px;color:#aaa;">
                                <i class="fas fa-comments" style="font-size:36px;margin-bottom:12px;display:block;opacity:.4;"></i>
                                Aucun message pour le moment.
                            </div>
                        </div>
                        <div>
                            <div class="dossier-chat-input-area">
                                <textarea class="dossier-chat-input" id="dossier-chat-input" rows="1"
                                    placeholder="Écrire un message à l'étudiant…"
                                    onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();dossierSendMessage();}"></textarea>
                                <button class="dossier-chat-send" id="dossier-chat-send-btn" onclick="dossierSendMessage()" title="Envoyer">
                                    <i class="fas fa-paper-plane" id="dossier-send-icon"></i>
                                    <span id="dossier-send-text">Envoyer</span>
                                </button>
                            </div>
                            <div id="dossier-chat-send-error" style="display:none; color:var(--danger-color); font-size:12px; margin-top:6px;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- ═══ fin Modal Dossier ═══ -->

    <!-- Modal Annuler un paiement étudiant -->
    <div class="modal" id="cancelPaymentModal">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h3 class="modal-title" style="color:var(--danger-color);">
                    <i class="fas fa-times-circle"></i> Annuler un paiement
                </h3>
                <span class="modal-close" onclick="closeModal('cancelPaymentModal')">&times;</span>
            </div>

            <div id="cancel-payment-info" style="background:rgba(231,76,60,0.12); border:1px solid var(--danger-color); border-radius:8px; padding:14px 16px; margin-bottom:20px;">
                <!-- Rempli par JS -->
            </div>

            <div class="form-group">
                <label>Motif d'annulation <span style="color:var(--danger-color);">*</span></label>
                <textarea id="cancel-motif" class="form-control" rows="3"
                    placeholder="Ex : saisie erronée, doublon, remboursement…" required></textarea>
                <small id="cancel-motif-error" style="color:var(--danger-color); display:none;">
                    Le motif est obligatoire.
                </small>
            </div>

            <div style="background:rgba(243,156,18,0.1); border:1px solid var(--warning-color); border-radius:6px; padding:10px 14px; margin-bottom:20px; font-size:13px; color:var(--warning-color);">
                <i class="fas fa-exclamation-triangle"></i>
                Cette action est irréversible. Le paiement sera marqué <strong>Annulé</strong>
                et restera visible dans l'historique pour la traçabilité.
            </div>

            <input type="hidden" id="cancel-payment-id">
            <div style="text-align:right;">
                <button type="button" class="btn" onclick="closeModal('cancelPaymentModal')"
                        style="background:#95a5a6;color:white;margin-right:10px;">
                    Fermer
                </button>
                <button type="button" class="btn btn-danger" id="cancel-confirm-btn" onclick="confirmCancelPayment()">
                    <i class="fas fa-times-circle"></i> Confirmer l'annulation
                </button>
            </div>
        </div>
    </div>

</body>
</html>