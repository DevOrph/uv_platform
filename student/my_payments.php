<?php
session_start();
require_once '../includes/db_connect.php';

// Vérification de l'authentification
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../pages/login.html");
    exit();
}

$student_id = $_SESSION['user_id'];

// Fonction pour générer un token CSRF
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

$csrf_token = generateCSRFToken();

// Traitement des actions POST
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $_SESSION['csrf_token']) {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'send_message':
                    $subject = $_POST['subject'];
                    $message_text = $_POST['message'];
                    $priority = $_POST['priority'] ?? 'normal';
                    
                    $insert_query = "INSERT INTO finance_messages (student_id, subject, message, priority, status) 
                                   VALUES (?, ?, ?, ?, 'new')";
                    $stmt = $conn->prepare($insert_query);
                    $stmt->bind_param("ssss", $student_id, $subject, $message_text, $priority);
                    
                    if ($stmt->execute()) {
                        // Ajouter à l'historique
                        $message_id = $stmt->insert_id;
                        $history_query = "INSERT INTO finance_message_history (message_id, user_id, user_type, message) 
                                        VALUES (?, ?, 'student', ?)";
                        $hist_stmt = $conn->prepare($history_query);
                        $hist_stmt->bind_param("iss", $message_id, $student_id, $message_text);
                        $hist_stmt->execute();
                        
                        $message = "Votre message a été envoyé avec succès au service financier";
                        $message_type = 'success';
                    } else {
                        throw new Exception("Erreur lors de l'envoi du message");
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'error';
    }
}

// ── Récupérer le fil de messages étudiant (AJAX GET) ──────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_my_messages') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $since = isset($_GET['since']) ? trim($_GET['since']) : null;

        // Marquer la conversation comme lue (new → read) quand l'étudiant consulte
        $upd = $conn->prepare("UPDATE finance_messages SET status='read' WHERE student_id = ? AND status = 'new'");
        $upd->bind_param("s", $student_id);
        $upd->execute();

        // Threads de l'étudiant
        $stmt = $conn->prepare("SELECT fm.id, fm.subject, fm.status, fm.created_at FROM finance_messages fm WHERE fm.student_id = ? ORDER BY fm.created_at DESC");
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $threads = [];
        $res = $stmt->get_result();
        while ($t = $res->fetch_assoc()) $threads[] = $t;

        // Polling incrémental : retourner uniquement les nouveaux messages depuis $since
        if ($since !== null) {
            $new_history = [];
            if (!empty($threads)) {
                $ids_in    = implode(',', array_map('intval', array_column($threads, 'id')));
                $since_esc = $conn->real_escape_string($since);
                $h = $conn->query("
                    SELECT fmh.id, fmh.message_id, fmh.user_id, fmh.user_type, fmh.message, fmh.created_at,
                           u.name as user_name
                    FROM finance_message_history fmh
                    LEFT JOIN users u ON fmh.user_id = u.id
                    WHERE fmh.message_id IN ($ids_in) AND fmh.created_at > '$since_esc'
                    ORDER BY fmh.created_at ASC
                ");
                if ($h) while ($row = $h->fetch_assoc()) $new_history[] = $row;
            }
            echo json_encode(['success' => true, 'new_history' => $new_history]);
            exit();
        }

        // Historique complet trié ASC
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

        // Compter messages admin non lus par l'étudiant
        $unread_admin = 0;
        if (!empty($threads)) {
            $ids_in = implode(',', array_map('intval', array_column($threads, 'id')));
            $ur = $conn->query("SELECT COUNT(*) as c FROM finance_message_history WHERE message_id IN ($ids_in) AND user_type='admin'");
            if ($ur) $unread_admin = (int)($ur->fetch_assoc()['c']);
        }

        $active_thread = null;
        foreach ($threads as $t) {
            if (!in_array($t['status'], ['resolved','closed'])) { $active_thread = $t; break; }
        }
        if (!$active_thread && !empty($threads)) $active_thread = $threads[0];

        echo json_encode([
            'success' => true,
            'threads' => $threads,
            'history' => $history,
            'active_thread' => $active_thread,
            'unread_admin' => $unread_admin,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// ── Étudiant envoie un message à l'admin (AJAX POST) ──────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'send_my_message') {
    header('Content-Type: application/json; charset=utf-8');
    $data = json_decode(file_get_contents('php://input'), true);
    try {
        $msg_text = trim($data['message'] ?? '');
        if (!$msg_text) throw new Exception("Message vide.");

        // Déduplication : même contenu dans les 10 dernières secondes
        $dup = $conn->prepare("SELECT fmh.id FROM finance_message_history fmh INNER JOIN finance_messages fm ON fmh.message_id = fm.id WHERE fm.student_id = ? AND fmh.user_type = 'student' AND fmh.message = ? AND fmh.created_at >= NOW() - INTERVAL 10 SECOND LIMIT 1");
        $dup->bind_param("ss", $student_id, $msg_text);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            $fdup = $conn->prepare("SELECT id FROM finance_messages WHERE student_id = ? ORDER BY created_at DESC LIMIT 1");
            $fdup->bind_param("s", $student_id);
            $fdup->execute();
            $tdup = $fdup->get_result()->fetch_assoc();
            echo json_encode(['success' => true, 'thread_id' => $tdup ? $tdup['id'] : 0, 'duplicate' => true]);
            exit();
        }

        // Chercher une conversation ouverte existante
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
            $ins = $conn->prepare("INSERT INTO finance_messages (student_id, subject, message, status, priority) VALUES (?, 'Message Étudiant', ?, 'new', 'normal')");
            $ins->bind_param("ss", $student_id, $msg_text);
            $ins->execute();
            $thread_id = $conn->insert_id;
        }

        // Ajouter dans l'historique
        $hist = $conn->prepare("INSERT INTO finance_message_history (message_id, user_id, user_type, message) VALUES (?, ?, 'student', ?)");
        $hist->bind_param("iss", $thread_id, $student_id, $msg_text);
        $hist->execute();
        $hist_id = $hist->insert_id;

        // Notifier les admins (best-effort : n'échoue pas si la table n'accepte pas encore NULL)
        try {
            $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND status='active' LIMIT 5");
            if ($admins) {
                $notif = $conn->prepare("INSERT INTO notifications (user_id, message, link, type, source_id, course_id) VALUES (?, ?, '../admin/payment_dashboard.php', 'payment', ?, NULL)");
                $notif_msg = "Nouveau message de " . ($_SESSION['name'] ?? 'un étudiant');
                while ($adm = $admins->fetch_assoc()) {
                    $notif->bind_param("ssi", $adm['id'], $notif_msg, $thread_id);
                    $notif->execute();
                }
            }
        } catch (Exception $e) { /* notification non critique */ }

        echo json_encode(['success' => true, 'thread_id' => $thread_id, 'entry' => [
            'id'         => $hist_id,
            'message_id' => $thread_id,
            'user_id'    => $student_id,
            'user_type'  => 'student',
            'message'    => $msg_text,
            'created_at' => date('Y-m-d H:i:s'),
            'user_name'  => $_SESSION['name'] ?? 'Étudiant',
        ]]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

try {
    // Récupération des informations de l'étudiant
    $student_query = "SELECT u.name, u.id, u.email, u.phone, c.name as class_name, c.id as class_id 
                     FROM users u 
                     LEFT JOIN classes c ON u.class_id = c.id 
                     WHERE u.id = ? AND u.role = 'student'";
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $student_info = $stmt->get_result()->fetch_assoc();

    if (!$student_info) {
        throw new Exception("Étudiant introuvable");
    }

    // Récupération des frais de scolarité pour la classe de l'étudiant
    $tuition_query = "SELECT * FROM tuition_fees
                     WHERE class_id = ? AND academic_year = '" . ANNEE_ACADEMIQUE_COURANTE . "'";
    $stmt = $conn->prepare($tuition_query);
    $stmt->bind_param("i", $student_info['class_id']);
    $stmt->execute();
    $tuition_fees = $stmt->get_result()->fetch_assoc();

    // Récupération de la réduction accordée par l'administration
    $discount = null;
    $discount_amount = 0;
    if ($tuition_fees) {
        $discount_query = "SELECT * FROM student_discounts
                          WHERE student_id = ? AND academic_year = '" . ANNEE_ACADEMIQUE_COURANTE . "'";
        $stmt = $conn->prepare($discount_query);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $discount = $stmt->get_result()->fetch_assoc();
        $discount_amount = $discount ? floatval($discount['discount_amount']) : 0;
    }

    // Récupération des paiements de l'étudiant pour l'année académique courante
    $payments_query = "SELECT sp.*, tf.total_amount as tuition_total
                      FROM student_payments sp
                      INNER JOIN tuition_fees tf ON sp.tuition_fee_id = tf.id
                      WHERE sp.student_id = ? AND sp.status = 'validated'
                      AND tf.academic_year = '" . ANNEE_ACADEMIQUE_COURANTE . "'
                      ORDER BY sp.payment_date DESC";
    $stmt = $conn->prepare($payments_query);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $payments_result = $stmt->get_result();
    $payments = [];
    $total_paid = 0;

    while ($payment = $payments_result->fetch_assoc()) {
        $payments[] = $payment;
        $total_paid += $payment['amount_paid'];
    }

    // Récupération des échéances pour l'année académique courante
    $deadlines_query = "SELECT pd.*,
                              DATEDIFF(pd.due_date, CURDATE()) as days_until_due,
                              CASE
                                  WHEN pd.amount_paid >= pd.amount_due THEN 'paid'
                                  WHEN pd.due_date < CURDATE() AND pd.amount_paid < pd.amount_due THEN 'overdue'
                                  WHEN pd.amount_paid > 0 THEN 'partial'
                                  ELSE 'pending'
                              END as computed_status
                       FROM payment_deadlines pd
                       WHERE pd.student_id = ?
                       AND pd.tuition_fee_id IN (
                           SELECT id FROM tuition_fees
                           WHERE academic_year = '" . ANNEE_ACADEMIQUE_COURANTE . "'
                       )
                       ORDER BY pd.installment_number";
    $stmt = $conn->prepare($deadlines_query);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $deadlines_result = $stmt->get_result();
    $deadlines = [];
    while ($deadline = $deadlines_result->fetch_assoc()) {
        $deadlines[] = $deadline;
    }

    // Récupération des messages échangés avec le service financier
    $messages_query = "SELECT fm.*, 
                             responder.name as responded_by_name
                      FROM finance_messages fm
                      LEFT JOIN users responder ON fm.responded_by = responder.id
                      WHERE fm.student_id = ?
                      ORDER BY fm.created_at DESC";
    $stmt = $conn->prepare($messages_query);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $messages_result = $stmt->get_result();
    $student_messages = [];
    while ($msg = $messages_result->fetch_assoc()) {
        $student_messages[] = $msg;
    }

    // Calculs de statut (réduction déduite du total)
    $total_amount = $tuition_fees ? floatval($tuition_fees['total_amount']) : 0;
    $net_amount = $total_amount - $discount_amount;
    $remaining_balance = $net_amount - $total_paid;
    $payment_percentage = $net_amount > 0 ? round(($total_paid / $net_amount) * 100, 2) : 0;

    // Détermination du statut global
    $payment_status = 'unpaid';
    if ($remaining_balance <= 0) {
        $payment_status = 'paid';
    } elseif ($tuition_fees && $tuition_fees['due_date'] < date('Y-m-d') && $remaining_balance > 0) {
        $payment_status = 'overdue';
    } elseif ($total_paid > 0) {
        $payment_status = 'partial';
    }

    // Vérifier s'il y a des échéances en retard
    $overdue_deadlines_count = 0;
    foreach ($deadlines as $deadline) {
        if ($deadline['computed_status'] === 'overdue') {
            $overdue_deadlines_count++;
        }
    }

} catch (Exception $e) {
    error_log("Erreur payment student: " . $e->getMessage());
    $error_message = "Erreur lors du chargement des données de paiement";
}

// Chargement des coordonnées de paiement configurées par l'admin
$pay_param_keys = ['banque_nom', 'banque_compte', 'mobile_money_nom', 'mobile_money_numero',
                   'contact_telephone', 'contact_email_admin'];
$pay_params = array_fill_keys($pay_param_keys, '');
$ph = implode(',', array_fill(0, count($pay_param_keys), '?'));
$pp_stmt = $conn->prepare("SELECT cle, valeur FROM parametres WHERE cle IN ($ph)");
$pp_stmt->bind_param(str_repeat('s', count($pay_param_keys)), ...$pay_param_keys);
$pp_stmt->execute();
$pp_result = $pp_stmt->get_result();
while ($pp_row = $pp_result->fetch_assoc()) {
    $pay_params[$pp_row['cle']] = trim($pp_row['valeur']);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Paiements - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --info-color: #3498db;
            --text-light: #ffffff;
            --card-bg: rgba(255, 255, 255, 0.1);
            --border-color: rgba(255, 255, 255, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-bg) 100%);
            color: var(--text-light);
            min-height: 100vh;
        }

        /* Header */
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
        }

        nav a:hover, nav a.active {
            background: rgba(3, 155, 229, 0.1);
            transform: translateY(-2px);
        }

        .container {
            max-width: 1200px;
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
        }

        .alert.show {
            display: flex;
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }

        .alert-warning {
            background: rgba(243, 156, 18, 0.1);
            border: 1px solid var(--warning-color);
            color: var(--warning-color);
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
            text-align: center;
        }

        .page-header h2 {
            color: var(--accent-color);
            font-size: 24px;
            margin-bottom: 10px;
        }

        .student-info {
            color: #ccc;
            font-size: 16px;
        }

        .student-info strong {
            color: var(--text-light);
        }

        .status-card {
            background: linear-gradient(135deg, var(--card-bg), rgba(3, 155, 229, 0.1));
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }

        .status-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .status-title {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--accent-color);
            font-size: 20px;
        }

        .academic-year {
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .progress-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .progress-item {
            text-align: center;
            padding: 20px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }

        .progress-value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .progress-label {
            color: #ccc;
            font-size: 14px;
        }

        .progress-bar-container {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 25px;
            padding: 8px;
            margin: 20px 0;
        }

        .progress-bar {
            height: 20px;
            background: linear-gradient(90deg, var(--accent-color), var(--success-color));
            border-radius: 15px;
            transition: width 0.8s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
            min-width: 60px;
        }

        .status-badge {
            padding: 12px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .status-paid { 
            background: var(--success-color); 
            color: white; 
        }
        
        .status-partial { 
            background: var(--warning-color); 
            color: white; 
        }
        
        .status-unpaid { 
            background: var(--danger-color); 
            color: white; 
        }
        
        .status-overdue { 
            background: #8e44ad; 
            color: white; 
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid var(--border-color);
        }

        .card h3 {
            color: var(--accent-color);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .fee-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .fee-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--info-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .fee-name {
            font-weight: 500;
        }

        .fee-amount {
            font-weight: bold;
            color: var(--accent-color);
        }

        .payment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .payment-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .payment-info {
            flex: 1;
        }

        .payment-date {
            font-size: 12px;
            color: #ccc;
            margin-bottom: 5px;
        }

        .payment-details {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .payment-amount {
            font-weight: bold;
            color: var(--success-color);
            font-size: 18px;
        }

        .payment-method {
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }

        .payment-type {
            background: var(--accent-color);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: white;
        }

        .btn {
            padding: 12px 25px;
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

        .btn-primary { 
            background: var(--accent-color); 
            color: white; 
        }
        
        .btn-success { 
            background: var(--success-color); 
            color: white; 
        }
        
        .btn-warning { 
            background: var(--warning-color); 
            color: white; 
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin: 30px 0;
            flex-wrap: wrap;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #ccc;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: var(--accent-color);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: var(--primary-color);
            margin: 5% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            border: 1px solid var(--border-color);
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
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
        }

        .modal-close:hover {
            color: var(--text-light);
        }

        .urgency-notice {
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.1), rgba(243, 156, 18, 0.1));
            border: 1px solid var(--warning-color);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }

        .urgency-notice h4 {
            color: var(--warning-color);
            margin-bottom: 10px;
        }

        .contact-info {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            border: 1px solid var(--border-color);
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .deadline-timeline {
            position: relative;
            padding-left: 30px;
            margin: 20px 0;
        }

        .deadline-timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border-color);
        }

        .deadline-item {
            position: relative;
            padding: 15px;
            margin-bottom: 15px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border-left: 4px solid var(--info-color);
        }

        .deadline-item.overdue {
            border-left-color: var(--danger-color);
        }

        .deadline-item.paid {
            border-left-color: var(--success-color);
        }

        .deadline-item.partial {
            border-left-color: var(--warning-color);
        }

        .deadline-item::before {
            content: '';
            position: absolute;
            left: -24px;
            top: 20px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--info-color);
        }

        .deadline-item.overdue::before { background: var(--danger-color); }
        .deadline-item.paid::before { background: var(--success-color); }
        .deadline-item.partial::before { background: var(--warning-color); }

        .deadline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .deadline-number {
            font-weight: bold;
            color: var(--accent-color);
        }

        .deadline-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .deadline-status.pending { background: #95a5a6; color: white; }
        .deadline-status.paid { background: var(--success-color); color: white; }
        .deadline-status.overdue { background: var(--danger-color); color: white; }
        .deadline-status.partial { background: var(--warning-color); color: white; }

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
        }

        .message-item.resolved {
            border-left-color: var(--success-color);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
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

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #ccc;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 10px;
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

        select {
            background-color: #f0f0f0;
            color: #333;
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 6px;
        }

        option {
            background: #0c2d48;
            color: #ffffff;
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

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @media (max-width: 768px) {
            .progress-info {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .payment-item {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .payment-details {
                flex-direction: column;
                gap: 10px;
            }

            .status-header {
                flex-direction: column;
                text-align: center;
            }

            .action-buttons {
                flex-direction: column;
                align-items: center;
            }

            .fee-grid {
                grid-template-columns: 1fr;
            }

            .tabs {
                flex-wrap: wrap;
            }
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
    <!-- Header -->
    <header>
        <div class="header-content">
            <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
            <nav>
                <ul>
                    <li><a href="student_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="student_dashboard.php"><i class="fas fa-book"></i> Mes Cours</a></li>
                    <li><a href="student_grades.php"><i class="fas fa-chart-line"></i> Notes</a></li>
                    <li><a href="my_payments.php" class="active"><i class="fas fa-money-bill-wave"></i> Paiements</a></li>
                    <li><a href="student_profile.php"><i class="fas fa-user"></i> Profil</a></li>
                    <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="container">
        <!-- Messages d'alerte -->
        <?php if (isset($error_message)): ?>
        <div class="alert alert-error show">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error_message); ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?> show">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <?php endif; ?>

        <div class="alert alert-success" id="successAlert">
            <i class="fas fa-check-circle"></i>
            <span id="successMessage"></span>
        </div>
        
        <div class="alert alert-warning" id="warningAlert">
            <i class="fas fa-exclamation-triangle"></i>
            <span id="warningMessage"></span>
        </div>
        
        <div class="alert alert-error" id="errorAlert">
            <i class="fas fa-exclamation-circle"></i>
            <span id="errorMessage"></span>
        </div>

        <!-- En-tête de page -->
        <div class="page-header">
            <h2><i class="fas fa-money-bill-wave"></i> Mes Paiements</h2>
            <div class="student-info">
                <strong><?php echo htmlspecialchars($student_info['name']); ?></strong> - 
                <?php echo htmlspecialchars($student_info['id']); ?> - 
                <?php echo htmlspecialchars($student_info['class_name']); ?>
            </div>
        </div>

        <!-- Notice d'urgence si en retard -->
        <?php if ($overdue_deadlines_count > 0): ?>
        <div class="urgency-notice">
            <h4><i class="fas fa-exclamation-triangle"></i> Attention - Échéances en Retard</h4>
            <p>Vous avez <?php echo $overdue_deadlines_count; ?> échéance(s) de paiement en retard. 
               Veuillez régulariser votre situation rapidement pour éviter toute suspension de vos cours.</p>
        </div>
        <?php elseif ($payment_status === 'overdue'): ?>
        <div class="urgency-notice">
            <h4><i class="fas fa-exclamation-triangle"></i> Attention - Paiement en Retard</h4>
            <p>Votre paiement a dépassé la date limite du <?php echo date('d/m/Y', strtotime($tuition_fees['due_date'])); ?>. 
               Veuillez régulariser votre situation rapidement pour éviter toute suspension de vos cours.</p>
        </div>
        <?php endif; ?>

        <!-- Statut des paiements -->
        <div class="status-card">
            <div class="status-header">
                <h3 class="status-title">
                    <i class="fas fa-chart-pie"></i> Statut de mes Paiements
                </h3>
                <div class="academic-year">
                    <i class="fas fa-calendar"></i> 
                    <span><?php echo $tuition_fees ? htmlspecialchars($tuition_fees['academic_year']) : htmlspecialchars(ANNEE_ACADEMIQUE_COURANTE); ?></span>
                </div>
            </div>

            <div class="progress-info">
                <div class="progress-item">
                    <div class="progress-value"><?php echo number_format($total_amount, 0, ',', ' '); ?> FCFA</div>
                    <div class="progress-label">Montant Brut</div>
                </div>
                <?php if ($discount_amount > 0): ?>
                <div class="progress-item">
                    <div class="progress-value" style="color: #8e44ad;">-<?php echo number_format($discount_amount, 0, ',', ' '); ?> FCFA</div>
                    <div class="progress-label">
                        Réduction
                        <?php if ($discount['discount_type'] === 'percent'): ?>
                            (<?php echo number_format($discount['discount_value'], 0); ?>%)
                        <?php endif; ?>
                    </div>
                </div>
                <div class="progress-item">
                    <div class="progress-value" style="color: var(--accent-color);"><?php echo number_format($net_amount, 0, ',', ' '); ?> FCFA</div>
                    <div class="progress-label">Montant Net à Payer</div>
                </div>
                <?php else: ?>
                <div class="progress-item">
                    <div class="progress-value" style="color: var(--accent-color);"><?php echo number_format($net_amount, 0, ',', ' '); ?> FCFA</div>
                    <div class="progress-label">Montant à Payer</div>
                </div>
                <?php endif; ?>
                <div class="progress-item">
                    <div class="progress-value" style="color: var(--success-color);"><?php echo number_format($total_paid, 0, ',', ' '); ?> FCFA</div>
                    <div class="progress-label">Montant Payé</div>
                </div>
                <div class="progress-item">
                    <div class="progress-value" style="color: var(--warning-color);"><?php echo number_format($remaining_balance, 0, ',', ' '); ?> FCFA</div>
                    <div class="progress-label">Restant à Payer</div>
                </div>
                <div class="progress-item">
                    <div class="progress-value"><?php echo $payment_percentage; ?>%</div>
                    <div class="progress-label">Progression</div>
                </div>
            </div>

            <div class="progress-bar-container">
                <div class="progress-bar" style="width: <?php echo $payment_percentage; ?>%;">
                    <?php echo $payment_percentage; ?>%
                </div>
            </div>

            <div style="text-align: center; margin-top: 20px;">
                <span class="status-badge status-<?php echo $payment_status; ?>">
                    <?php
                    switch($payment_status) {
                        case 'paid':
                            echo '<i class="fas fa-check-circle"></i> Payé';
                            break;
                        case 'partial':
                            echo '<i class="fas fa-clock"></i> Partiel';
                            break;
                        case 'overdue':
                            echo '<i class="fas fa-exclamation-triangle"></i> En Retard';
                            break;
                        default:
                            echo '<i class="fas fa-times-circle"></i> Non Payé';
                    }
                    ?>
                </span>
            </div>
        </div>

        <!-- Onglets -->
        <div class="tabs">
            <button class="tab-button active" onclick="showTab('overview')">
                <i class="fas fa-info-circle"></i> Vue d'ensemble
            </button>
            <button class="tab-button" onclick="showTab('deadlines')">
                <i class="fas fa-calendar-alt"></i> Mes Échéances
            </button>
            <button class="tab-button" onclick="showTab('history')">
                <i class="fas fa-history"></i> Historique
            </button>
            <button class="tab-button" id="tab-btn-messages" onclick="showTab('messages')" style="position:relative;">
                <i class="fas fa-envelope"></i> Messages
                <span id="nav-msg-badge" style="display:none;position:absolute;top:6px;right:6px;background:var(--danger-color);color:white;font-size:10px;padding:1px 5px;border-radius:8px;font-weight:bold;"></span>
            </button>
        </div>

        <!-- Onglet Vue d'ensemble -->
        <div class="tab-content active" id="overview-tab">
            <!-- Détail des frais -->
            <?php if ($tuition_fees): ?>
            <div class="card">
                <h3><i class="fas fa-list-ul"></i> Détail des Frais de Scolarité</h3>
                <div class="fee-grid">
                    <?php if ($tuition_fees['registration_fee'] > 0): ?>
                    <div class="fee-item">
                        <span class="fee-name">Frais d'Inscription</span>
                        <span class="fee-amount"><?php echo number_format($tuition_fees['registration_fee'], 0, ',', ' '); ?> FCFA</span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($tuition_fees['tuition_fee'] > 0): ?>
                    <div class="fee-item">
                        <span class="fee-name">Frais de Scolarité</span>
                        <span class="fee-amount"><?php echo number_format($tuition_fees['tuition_fee'], 0, ',', ' '); ?> FCFA</span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($tuition_fees['insurance_fee'] > 0): ?>
                    <div class="fee-item">
                        <span class="fee-name">Assurance</span>
                        <span class="fee-amount"><?php echo number_format($tuition_fees['insurance_fee'], 0, ',', ' '); ?> FCFA</span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($tuition_fees['library_fee'] > 0): ?>
                    <div class="fee-item">
                        <span class="fee-name">Bibliothèque</span>
                        <span class="fee-amount"><?php echo number_format($tuition_fees['library_fee'], 0, ',', ' '); ?> FCFA</span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($tuition_fees['practical_fee'] > 0): ?>
                    <div class="fee-item">
                        <span class="fee-name">Travaux Pratiques</span>
                        <span class="fee-amount"><?php echo number_format($tuition_fees['practical_fee'], 0, ',', ' '); ?> FCFA</span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($tuition_fees['other_fees'] > 0): ?>
                    <div class="fee-item">
                        <span class="fee-name">Autres Frais</span>
                        <span class="fee-amount"><?php echo number_format($tuition_fees['other_fees'], 0, ',', ' '); ?> FCFA</span>
                    </div>
                    <?php endif; ?>

                    <?php if ($discount_amount > 0): ?>
                    <div class="fee-item" style="border-left-color: #8e44ad; grid-column: 1 / -1;">
                        <span class="fee-name">
                            <i class="fas fa-tag" style="color:#8e44ad;"></i>
                            Réduction accordée
                            <?php if ($discount['discount_type'] === 'percent'): ?>
                                (<?php echo number_format($discount['discount_value'], 0); ?>%)
                            <?php endif; ?>
                            <?php if (!empty($discount['reason'])): ?>
                                <br><small style="color:#ccc;font-weight:normal;"><?php echo htmlspecialchars($discount['reason']); ?></small>
                            <?php endif; ?>
                        </span>
                        <span class="fee-amount" style="color:#8e44ad;">-<?php echo number_format($discount_amount, 0, ',', ' '); ?> FCFA</span>
                    </div>
                    <div class="fee-item" style="border-left-color: var(--accent-color); grid-column: 1 / -1; background: rgba(3,155,229,0.08);">
                        <span class="fee-name" style="font-weight:bold;">Total Net à Payer</span>
                        <span class="fee-amount" style="color: var(--accent-color); font-size:18px;"><?php echo number_format($net_amount, 0, ',', ' '); ?> FCFA</span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($tuition_fees['due_date']): ?>
                <div style="margin-top: 20px; text-align: center; padding: 15px; background: rgba(255, 255, 255, 0.05); border-radius: 8px;">
                    <i class="fas fa-calendar-times"></i>
                    <strong>Date limite de paiement : <?php echo date('d/m/Y', strtotime($tuition_fees['due_date'])); ?></strong>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Informations de paiement -->
            <div class="card">
                <h3><i class="fas fa-info-circle"></i> Comment Payer</h3>

                <div style="margin-bottom: 20px;">
                    <h4 style="color: var(--warning-color); margin-bottom: 10px;">
                        <i class="fas fa-money-bill-wave"></i> Espèces
                    </h4>
                    <p style="color: #ccc; margin-bottom: 10px;">Rendez-vous au service financier du campus avec :</p>
                    <ul style="color: #ccc; margin-left: 20px;">
                        <li>Votre carte d'étudiant</li>
                        <li>Le montant exact</li>
                        <li>Une pièce d'identité</li>
                    </ul>
                </div>

                <?php if ($pay_params['banque_nom'] !== '' || $pay_params['banque_compte'] !== ''): ?>
                <div style="margin-bottom: 20px;">
                    <h4 style="color: var(--info-color); margin-bottom: 10px;">
                        <i class="fas fa-university"></i> Virement Bancaire
                    </h4>
                    <div style="background: rgba(255, 255, 255, 0.05); padding: 15px; border-radius: 8px;">
                        <?php if ($pay_params['banque_nom'] !== ''): ?>
                        <p><strong>Banque :</strong> <?php echo htmlspecialchars($pay_params['banque_nom']); ?></p>
                        <?php endif; ?>
                        <?php if ($pay_params['banque_compte'] !== ''): ?>
                        <p><strong>N° Compte :</strong> <?php echo htmlspecialchars($pay_params['banque_compte']); ?></p>
                        <?php endif; ?>
                        <p style="margin-top: 10px; color: var(--warning-color);">
                            <i class="fas fa-exclamation-triangle"></i>
                            N'oubliez pas de mentionner votre ID étudiant (<?php echo htmlspecialchars($student_id); ?>) en référence
                        </p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($pay_params['mobile_money_nom'] !== '' || $pay_params['mobile_money_numero'] !== ''): ?>
                <div style="margin-bottom: 20px;">
                    <h4 style="color: var(--success-color); margin-bottom: 10px;">
                        <i class="fas fa-mobile-alt"></i> Mobile Money
                    </h4>
                    <div style="background: rgba(255, 255, 255, 0.05); padding: 15px; border-radius: 8px;">
                        <?php if ($pay_params['mobile_money_nom'] !== '' && $pay_params['mobile_money_numero'] !== ''): ?>
                        <p><strong><?php echo htmlspecialchars($pay_params['mobile_money_nom']); ?> :</strong>
                           <?php echo htmlspecialchars($pay_params['mobile_money_numero']); ?></p>
                        <?php elseif ($pay_params['mobile_money_numero'] !== ''): ?>
                        <p><strong>Numéro :</strong> <?php echo htmlspecialchars($pay_params['mobile_money_numero']); ?></p>
                        <?php endif; ?>
                        <p style="margin-top: 10px; color: var(--warning-color);">
                            <i class="fas fa-exclamation-triangle"></i>
                            Envoyez une capture d'écran de la transaction au service financier
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Onglet Mes Échéances -->
        <div class="tab-content" id="deadlines-tab">
            <?php if (empty($deadlines)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-alt"></i>
                    <h4>Aucune échéance configurée</h4>
                    <p>Aucun plan de paiement échelonné n'a été défini pour votre compte. 
                       Veuillez contacter le service financier pour plus d'informations.</p>
                    <button class="btn btn-warning" onclick="showTab('messages'); document.querySelector('.tab-button:nth-child(4)').click();">
                        <i class="fas fa-envelope"></i> Contacter le Service Financier
                    </button>
                </div>
            <?php else: ?>
                <div class="card">
                    <h3><i class="fas fa-calendar-check"></i> Mon Plan de Paiement</h3>
                    <p style="color: #ccc; margin-bottom: 20px;">
                        Votre paiement est réparti en <?php echo count($deadlines); ?> échéances. 
                        Voici le calendrier de vos paiements :
                    </p>
                    
                    <div class="deadline-timeline">
                        <?php foreach ($deadlines as $deadline): ?>
                        <div class="deadline-item <?php echo $deadline['computed_status']; ?>">
                            <div class="deadline-header">
                                <span class="deadline-number">
                                    Échéance <?php echo $deadline['installment_number']; ?> / <?php echo count($deadlines); ?>
                                </span>
                                <span class="deadline-status <?php echo $deadline['computed_status']; ?>">
                                    <?php
                                    $status_labels = [
                                        'paid' => 'Payé',
                                        'partial' => 'Partiel',
                                        'overdue' => 'En retard',
                                        'pending' => 'En attente'
                                    ];
                                    echo $status_labels[$deadline['computed_status']] ?? 'Inconnu';
                                    ?>
                                </span>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 10px;">
                                <div>
                                    <div style="font-size: 12px; color: #ccc;">Date limite</div>
                                    <div style="font-weight: bold;">
                                        <?php echo date('d/m/Y', strtotime($deadline['due_date'])); ?>
                                    </div>
                                    <?php if ($deadline['days_until_due'] >= 0 && $deadline['computed_status'] !== 'paid'): ?>
                                    <div style="font-size: 11px; color: var(--warning-color);">
                                        <?php echo $deadline['days_until_due']; ?> jours restants
                                    </div>
                                    <?php elseif ($deadline['computed_status'] === 'overdue'): ?>
                                    <div style="font-size: 11px; color: var(--danger-color);">
                                        Retard de <?php echo abs($deadline['days_until_due']); ?> jours
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div>
                                    <div style="font-size: 12px; color: #ccc;">Montant dû</div>
                                    <div style="font-weight: bold; color: var(--accent-color);">
                                        <?php echo number_format($deadline['amount_due'], 0, ',', ' '); ?> FCFA
                                    </div>
                                </div>
                                
                                <div>
                                    <div style="font-size: 12px; color: #ccc;">Montant payé</div>
                                    <div style="font-weight: bold; color: var(--success-color);">
                                        <?php echo number_format($deadline['amount_paid'], 0, ',', ' '); ?> FCFA
                                    </div>
                                </div>
                                
                                <div>
                                    <div style="font-size: 12px; color: #ccc;">Restant</div>
                                    <div style="font-weight: bold; color: <?php echo ($deadline['amount_due'] - $deadline['amount_paid']) > 0 ? 'var(--warning-color)' : 'var(--success-color)'; ?>;">
                                        <?php echo number_format($deadline['amount_due'] - $deadline['amount_paid'], 0, ',', ' '); ?> FCFA
                                    </div>
                                </div>
                            </div>

                            <?php if ($deadline['notes']): ?>
                            <div style="margin-top: 10px; padding: 10px; background: rgba(255, 255, 255, 0.05); border-radius: 6px;">
                                <small style="color: #ccc;">
                                    <i class="fas fa-sticky-note"></i> <?php echo htmlspecialchars($deadline['notes']); ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Onglet Historique des paiements -->
        <div class="tab-content" id="history-tab">
            <div class="card">
                <h3><i class="fas fa-history"></i> Historique de mes Paiements</h3>
                
                <div id="paymentHistory">
                    <?php if (empty($payments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-receipt"></i>
                        <h4>Aucun paiement enregistré</h4>
                        <p>Vous n'avez effectué aucun paiement pour le moment.</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                        <div class="payment-item">
                            <div class="payment-info">
                                <div class="payment-date">
                                    <?php echo date('d/m/Y à H:i', strtotime($payment['payment_date'])); ?>
                                </div>
                                <div class="payment-details">
                                    <span class="payment-amount"><?php echo number_format($payment['amount_paid'], 0, ',', ' '); ?> FCFA</span>
                                    <span class="payment-method"><?php 
                                        $methods = [
                                            'cash' => 'Espèces',
                                            'bank_transfer' => 'Virement bancaire',
                                            'mobile_money' => 'Mobile Money',
                                            'check' => 'Chèque',
                                            'other' => 'Autre'
                                        ];
                                        echo $methods[$payment['payment_method']] ?? $payment['payment_method'];
                                    ?></span>
                                    <span class="payment-type"><?php 
                                        $types = [
                                            'registration' => 'Inscription',
                                            'tuition' => 'Scolarité',
                                            'insurance' => 'Assurance',
                                            'library' => 'Bibliothèque',
                                            'practical' => 'TP',
                                            'installment' => 'Échéance',
                                            'other' => 'Autre'
                                        ];
                                        echo $types[$payment['payment_type']] ?? $payment['payment_type'];
                                    ?></span>
                                    <?php if ($payment['installment_number']): ?>
                                    <span style="font-size: 12px; color: #ccc;">
                                        (Échéance <?php echo $payment['installment_number']; ?>)
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($payment['reference_number']): ?>
                                <div style="margin-top: 5px; font-size: 12px; color: #999;">
                                    Référence: <?php echo htmlspecialchars($payment['reference_number']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="status-badge status-paid" style="padding: 6px 12px;">
                                    <i class="fas fa-check-circle"></i> Validé
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Onglet Messages — Fil conversationnel -->
        <div class="tab-content" id="messages-tab">
            <div class="card" style="padding:0; overflow:hidden;">
                <div style="padding:16px 20px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <h3 style="margin:0; color:var(--accent-color);"><i class="fas fa-comments"></i> Service Financier</h3>
                    <span id="msg-thread-status" style="font-size:13px; color:#aaa;"></span>
                </div>
                <div id="student-chat-messages" style="padding:16px; height:420px; overflow-y:auto; display:flex; flex-direction:column; gap:10px;">
                    <div style="text-align:center; padding:40px; color:#aaa;">
                        <i class="fas fa-spinner fa-spin" style="font-size:28px; color:var(--accent-color); margin-bottom:12px; display:block;"></i>
                        Chargement de la conversation…
                    </div>
                </div>
                <div style="padding:12px 16px; border-top:1px solid var(--border-color);">
                    <div style="display:flex; gap:10px; align-items:flex-end;">
                        <textarea id="student-chat-input" rows="2" placeholder="Écrire un message au service financier…"
                            style="flex:1; padding:10px 14px; background:rgba(255,255,255,0.08); border:1px solid var(--border-color); border-radius:14px; color:white; font-size:13px; resize:none; font-family:inherit;"
                            onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();studentSendMessage();}"></textarea>
                        <button id="student-chat-send-btn" onclick="studentSendMessage()"
                            style="background:var(--success-color); color:white; border:none; border-radius:20px; padding:0 16px; height:42px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; flex-shrink:0; transition:background .2s; font-size:13px; white-space:nowrap;"
                            title="Envoyer">
                            <i class="fas fa-paper-plane" id="student-send-icon"></i>
                            <span id="student-send-text">Envoyer</span>
                        </button>
                    </div>
                    <div id="student-chat-send-error" style="display:none; color:var(--danger-color); font-size:12px; margin-top:6px;"></div>
                </div>
            </div>
        </div>

        <!-- Informations de contact -->
        <div class="contact-info">
            <h4 style="color: var(--accent-color); margin-bottom: 15px;">
                <i class="fas fa-phone"></i> Service Financier - Université Virtuelle
            </h4>
            <?php if ($pay_params['contact_email_admin'] !== ''): ?>
            <div class="contact-item">
                <i class="fas fa-envelope"></i>
                <span><?php echo htmlspecialchars($pay_params['contact_email_admin']); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($pay_params['contact_telephone'] !== ''): ?>
            <div class="contact-item">
                <i class="fas fa-phone"></i>
                <span><?php echo htmlspecialchars($pay_params['contact_telephone']); ?></span>
            </div>
            <?php endif; ?>
            <div class="contact-item">
                <i class="fas fa-clock"></i>
                <span>Lundi - Vendredi : 8h00 - 17h00</span>
            </div>
            <div class="contact-item">
                <i class="fas fa-map-marker-alt"></i>
                <span>Campus Université Virtuelle, Libreville</span>
            </div>
        </div>
    </div>

    <script>
        // Fonctions de gestion des modales
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        window.onclick = function(event) {
            document.querySelectorAll('.modal').forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            });
        };

        // Gestion des onglets
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
            if (tabName === 'messages') {
                // Réinitialiser le badge immédiatement ; renderStudentChat
                // sauvegarde ensuite le vrai timestamp serveur dans localStorage
                _studentChat.unreadAdmin = 0;
                updateMsgNavBadge(0);
                loadStudentMessages();
            }
        }

        function showAlert(type, message) {
            document.querySelectorAll('.alert').forEach(a => a.classList.remove('show'));
            const el  = document.getElementById(type + 'Alert');
            const msg = document.getElementById(type + 'Message');
            if (el && msg) {
                msg.textContent = message;
                el.classList.add('show');
                setTimeout(() => el.classList.remove('show'), 5000);
            }
        }

        // ══════════════════════════════════════════════════════════════════════
        // ── MESSAGERIE CONVERSATIONNELLE ÉTUDIANT ─────────────────────────────
        // ══════════════════════════════════════════════════════════════════════
        function escapeHtml(t) {
            return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }
        let _studentChat = { lastCount: 0, pollTimer: null, lastTimestamp: null, sending: false, unreadAdmin: 0 };
        const _LAST_READ_KEY = 'finance_msg_last_read';

        function _buildStudentBubble(msg) {
            const isAdmin   = msg.user_type === 'admin';
            const initials  = msg.user_name ? msg.user_name.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase() : (isAdmin?'A':'E');
            const avatarBg  = isAdmin ? 'var(--accent-color)' : 'var(--success-color)';
            const bubbleBg  = isAdmin ? 'rgba(3,155,229,0.15)' : 'var(--success-color)';
            const bubbleClr = isAdmin ? '#e0e8f0' : 'white';
            const bubbleRad = isAdmin ? '14px 14px 14px 4px' : '14px 14px 4px 14px';
            const align     = isAdmin ? 'flex-start' : 'flex-end';
            const flexDir   = isAdmin ? 'row' : 'row-reverse';
            const time      = new Date(msg.created_at).toLocaleString('fr-FR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
            const wrap = document.createElement('div');
            wrap.style.cssText = `display:flex;gap:8px;flex-direction:${flexDir};align-self:${align};max-width:78%;`;
            wrap.innerHTML = `<div style="width:30px;height:30px;border-radius:50%;background:${avatarBg};flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:bold;color:white;">${initials}</div>
                <div>
                    <div style="padding:10px 14px;border-radius:${bubbleRad};background:${bubbleBg};color:${bubbleClr};font-size:13px;line-height:1.5;">${escapeHtml(msg.message).replace(/\n/g,'<br>')}</div>
                    <div style="font-size:11px;color:#777;margin-top:3px;text-align:${isAdmin?'left':'right'};">${escapeHtml(msg.user_name||'?')} · ${time}</div>
                </div>`;
            return wrap;
        }

        function appendStudentBubble(msg, animate) {
            const container = document.getElementById('student-chat-messages');
            const placeholder = container.querySelector('[data-placeholder]');
            if (placeholder) placeholder.remove();
            const wrap = _buildStudentBubble(msg);
            if (animate) {
                wrap.style.opacity = '0';
                wrap.style.transform = 'translateY(8px)';
                wrap.style.transition = 'opacity .3s ease, transform .3s ease';
                container.appendChild(wrap);
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    wrap.style.opacity = '1';
                    wrap.style.transform = 'translateY(0)';
                }));
            } else {
                container.appendChild(wrap);
            }
            container.scrollTop = container.scrollHeight;
            _studentChat.lastCount++;
            if (msg.created_at) _studentChat.lastTimestamp = msg.created_at;

            // Indicateur non-lu : si l'onglet Messages n'est pas visible, incrémenter le badge
            if (msg.user_type === 'admin') {
                const tabVisible = document.getElementById('messages-tab')?.classList.contains('active');
                if (!tabVisible) {
                    _studentChat.unreadAdmin++;
                    updateMsgNavBadge(_studentChat.unreadAdmin);
                } else {
                    localStorage.setItem(_LAST_READ_KEY, msg.created_at);
                }
            }
        }

        function setSendState(state, errMsg) {
            const btn   = document.getElementById('student-chat-send-btn');
            const icon  = document.getElementById('student-send-icon');
            const text  = document.getElementById('student-send-text');
            const input = document.getElementById('student-chat-input');
            const errEl = document.getElementById('student-chat-send-error');
            if (!btn) return;
            btn.style.background = '';
            btn.style.cursor     = '';
            btn.style.opacity    = '1';
            if (errEl) errEl.style.display = 'none';
            switch (state) {
                case 'loading':
                    btn.disabled      = true;
                    btn.style.cursor  = 'not-allowed';
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
                    setTimeout(() => { input.focus(); setSendState('normal'); }, 1500);
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
                    btn.disabled      = false;
                    icon.className    = 'fas fa-paper-plane';
                    text.textContent  = 'Envoyer';
                    input.disabled    = false;
            }
        }

        function loadStudentMessages(silent) {
            const url = (_studentChat.lastTimestamp && silent)
                ? '?action=get_my_messages&since=' + encodeURIComponent(_studentChat.lastTimestamp)
                : '?action=get_my_messages';
            return fetch(url)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) return;
                    if (data.new_history !== undefined) {
                        // Polling incrémental : appendStudentBubble gère le badge
                        data.new_history.forEach(msg => appendStudentBubble(msg, true));
                    } else {
                        renderStudentChat(data, silent);
                    }
                })
                .catch(() => {});
        }

        function renderStudentChat(data, silent) {
            const container   = document.getElementById('student-chat-messages');
            const statusLabel = document.getElementById('msg-thread-status');
            const history     = data.history || [];
            const thread      = data.active_thread;

            if (thread) {
                const map = {new:'Nouveau', read:'Lu', in_progress:'En cours', resolved:'Résolu', closed:'Fermé'};
                statusLabel.textContent = map[thread.status] || thread.status;
            } else {
                statusLabel.textContent = '';
            }

            // Badge non-lu : toujours basé sur le timestamp serveur pour éviter les décalages UTC
            const tabVisible = document.getElementById('messages-tab')?.classList.contains('active');
            if (tabVisible) {
                // Onglet visible → tout est lu : sauver le timestamp du dernier message reçu
                if (history.length > 0) {
                    localStorage.setItem(_LAST_READ_KEY, history[history.length - 1].created_at);
                }
                _studentChat.unreadAdmin = 0;
                updateMsgNavBadge(0);
            } else {
                // Onglet caché → calculer les non-lus par rapport au dernier timestamp sauvé
                const lastRead = localStorage.getItem(_LAST_READ_KEY) || '2000-01-01 00:00:00';
                _studentChat.unreadAdmin = history.filter(m => m.user_type === 'admin' && m.created_at > lastRead).length;
                updateMsgNavBadge(_studentChat.unreadAdmin);
            }

            if (!history.length) {
                if (!silent) {
                    container.innerHTML = `<div data-placeholder style="text-align:center;padding:40px;color:#aaa;margin:auto;">
                        <i class="fas fa-comments" style="font-size:40px;opacity:.3;margin-bottom:12px;display:block;"></i>
                        Pas encore de message.<br>
                        <span style="font-size:13px;">Écrivez ci-dessous pour contacter le service financier.</span>
                    </div>`;
                }
                return;
            }

            const atBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 60;
            const newCount = history.length;

            if (!silent || newCount !== _studentChat.lastCount) {
                container.innerHTML = '';
                history.forEach(msg => container.appendChild(_buildStudentBubble(msg)));
                _studentChat.lastCount = newCount;
                if (history.length > 0) _studentChat.lastTimestamp = history[history.length - 1].created_at;
                if (atBottom || !silent) container.scrollTop = container.scrollHeight;
            }
        }

        function studentSendMessage() {
            const input = document.getElementById('student-chat-input');
            const text  = input.value.trim();
            if (!text || _studentChat.sending) return;
            _studentChat.sending = true;
            setSendState('loading');

            fetch('?action=send_my_message', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({message: text})
            })
            .then(r => r.json())
            .then(data => {
                _studentChat.sending = false;
                if (data.success) {
                    setSendState('success');
                    if (data.entry) appendStudentBubble(data.entry, true);
                } else {
                    setSendState('error', data.error || 'Erreur lors de l\'envoi.');
                }
            })
            .catch(() => {
                _studentChat.sending = false;
                setSendState('error', 'Erreur de connexion. Vérifiez votre réseau.');
            });
        }

        function updateMsgNavBadge(count) {
            const navMsg = document.querySelector('nav a[href*="my_payments"] .msg-badge, #nav-msg-badge');
            if (!navMsg) return;
            navMsg.textContent = count > 0 ? count : '';
            navMsg.style.display = count > 0 ? 'inline' : 'none';
        }

        function startMessagePolling() {
            clearInterval(_studentChat.pollTimer);
            const delay = document.hidden ? 60000 : 30000;
            _studentChat.pollTimer = setInterval(() => loadStudentMessages(true), delay);
        }

        document.addEventListener('visibilitychange', function() {
            clearInterval(_studentChat.pollTimer);
            if (!document.hidden) loadStudentMessages(true);
            startMessagePolling();
        });

        // ══ Init ══════════════════════════════════════════════════════════════
        document.addEventListener('DOMContentLoaded', function() {
            // Animations cartes
            document.querySelectorAll('.card, .status-card').forEach((card, i) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all .5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, i * 100);
            });

            // URL params alert
            const p = new URLSearchParams(window.location.search);
            if (p.get('message') && p.get('type')) showAlert(p.get('type'), decodeURIComponent(p.get('message')));
            setTimeout(() => document.querySelectorAll('.alert.show').forEach(a => a.classList.remove('show')), 5000);

            // Charger messages au démarrage (pour le badge) + démarrer polling
            loadStudentMessages(true);
            startMessagePolling();

            <?php if ($overdue_deadlines_count > 0): ?>
            setTimeout(() => showAlert('warning', 'Vous avez <?php echo $overdue_deadlines_count; ?> échéance(s) en retard. Veuillez régulariser rapidement.'), 2000);
            <?php elseif ($payment_status === 'overdue'): ?>
            setTimeout(() => showAlert('warning', 'Votre paiement est en retard. Veuillez régulariser rapidement.'), 2000);
            <?php elseif ($payment_status === 'partial'): ?>
            setTimeout(() => showAlert('warning', 'Votre paiement est partiel. Restant : <?php echo number_format($remaining_balance, 0, ',', ' '); ?> FCFA.'), 2000);
            <?php elseif ($payment_status === 'paid'): ?>
            setTimeout(() => showAlert('success', 'Félicitations ! Tous vos frais de scolarité sont à jour.'), 2000);
            <?php endif; ?>
        });
        // ══ fin messagerie ════════════════════════════════════════════════════
    </script>
</body>
</html>