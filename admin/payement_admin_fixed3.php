<?php
session_start();
require_once '../includes/db_connect.php';
// ===== fix collation / charset to avoid "Illegal mix of collations" =====
$conn->set_charset('utf8mb4'); // assure le charset de connexion
// forcer la collation de connexion (choisir unicode_ci ou general_ci selon préférence)
$conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");
// ======================================================================

// juste après require_once '../includes/db_connect.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // force mysqli à lancer des exceptions

// Vérification de l'authentification admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

// Endpoint AJAX pour afficher l'historique des paiements d'un personnel
if (isset($_GET['action']) && $_GET['action'] === 'get_staff_history' && isset($_GET['staff_id'])) {
    header('Content-Type: application/json; charset=utf-8');

    $staff_id = $_GET['staff_id'];

    try {
        $sql = "
            SELECT 
                sp.payment_date,
                spt.name AS type_name,
                sp.amount,
                sp.payment_method,
                sp.receipt_number,
                sp.status
            FROM staff_payments sp
            JOIN staff_payment_types spt ON sp.payment_type_id = spt.id
            WHERE sp.staff_id = ?
            ORDER BY sp.payment_date DESC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $staff_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $payments = [];
        while ($row = $result->fetch_assoc()) {
            $payments[] = $row;
        }

        // Récapitulatif
        $total_month = 0;
        $count = count($payments);
        foreach ($payments as $p) {
            if (date('Y-m', strtotime($p['payment_date'])) === date('Y-m')) {
                $total_month += floatval($p['amount']);
            }
        }

        echo json_encode([
            'success' => true,
            'count' => $count,
            'total_month' => $total_month,
            'payments' => $payments
        ], JSON_UNESCAPED_UNICODE);
        exit();

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

$admin_id = $_SESSION['user_id'];

// Fonction pour générer un token CSRF
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

$csrf_token = generateCSRFToken();

// === Création des tables nécessaires si elles n'existent pas ===
$create_tables_sql = "
CREATE TABLE IF NOT EXISTS staff_payment_types (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    category ENUM('salary','bonus','allowance','social','operational','supplier') NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS staff_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    staff_id VARCHAR(255) NOT NULL,
    payment_type_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payment_method ENUM('bank_transfer','cash','check','mobile_money') DEFAULT 'bank_transfer',
    reference_number VARCHAR(100),
    description TEXT,
    receipt_number VARCHAR(50),
    status ENUM('pending','processed','cancelled') DEFAULT 'processed',
    processed_by VARCHAR(255) NOT NULL,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_type_id) REFERENCES staff_payment_types(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS operational_expenses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    expense_type VARCHAR(100) NOT NULL,
    category ENUM('equipment','maintenance','utilities','supplies','services','other') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    expense_date DATE NOT NULL,
    vendor_name VARCHAR(200),
    invoice_number VARCHAR(100),
    description TEXT,
    payment_method ENUM('bank_transfer','cash','check') DEFAULT 'bank_transfer',
    reference_number VARCHAR(100),
    status ENUM('pending','paid','cancelled') DEFAULT 'paid',
    processed_by VARCHAR(255) NOT NULL,
    receipt_path VARCHAR(500),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
";

// Exécution (sécurisée) des statements de création
// On découpe par ";\n" pour éviter de casser si ; est suivi d'autres choses
$stmts = preg_split('/;\s*[\r\n]+/', $create_tables_sql);
foreach ($stmts as $stmt) {
    $s = trim($stmt);
    if ($s === '') continue;
    try {
        $conn->query($s);
    } catch (Exception $e) {
        // ignore errors (table exists ou autres) mais log pour debug
        error_log("[CREATE_TABLE] " . $e->getMessage());
    }
}

// Variables pour messages
$message = '';
$message_type = '';

// Read flash message from session (Post-Redirect-Get)
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}


// === Traitement des POST (actions) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérifier CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = "Token CSRF invalide.";
        $message_type = "error";
    } else {
        try {
            if (isset($_POST['action'])) {
                switch ($_POST['action']) {

case 'add_staff_payment':
    $staff_id = $_POST['staff_id'] ?? '';
    $payment_type_id = intval($_POST['payment_type_id'] ?? 0);
    $amount = $_POST['amount'] ?? '0';
    $amount = floatval(str_replace(',', '.', $amount));
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $description = $_POST['description'] ?? '';
    $reference = $_POST['reference'] ?? '';

    if (empty($staff_id) || $payment_type_id <= 0 || $amount <= 0) {
        throw new Exception("Champs obligatoires manquants ou invalides pour le paiement du personnel.");
    }

    // Générer un numéro de reçu unique
    $receipt_number = 'REC-STAFF-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $payment_date = date('Y-m-d'); // Date du jour
    $notes = 'Paiement enregistré automatiquement par administrateur.';

    // Colonnes complètes de ta table staff_payments
    $insert_payment = "INSERT INTO staff_payments 
        (staff_id, beneficiary_id, beneficiary_info, payment_type_id, amount, payment_date, payment_method, description, reference_number, receipt_number, status, processed_by, notes)
        VALUES (?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, 'processed', ?, ?)";

    $stmt = $conn->prepare($insert_payment);
    $stmt->bind_param(
        "sidsssssss",
        $staff_id,
        $payment_type_id,
        $amount,
        $payment_date,
        $payment_method,
        $description,
        $reference,
        $receipt_number,
        $admin_id,
        $notes
    );

    if ($stmt->execute()) {
        $new_payment_id = $conn->insert_id;

        // 🔹 Enregistrer dans l’historique
        $history_sql = "INSERT INTO payment_history 
            (payment_id, action, performed_by, details, new_value)
            VALUES (?, 'CREATE', ?, ?, ?)";
        $stmt_hist = $conn->prepare($history_sql);

        $details = "Création d'un paiement de {$amount} FCFA via {$payment_method} pour le personnel {$staff_id}";
        $new_value = json_encode([
            'staff_id' => $staff_id,
            'payment_type_id' => $payment_type_id,
            'amount' => $amount,
            'payment_method' => $payment_method,
            'payment_date' => $payment_date,
            'reference' => $reference,
            'receipt_number' => $receipt_number,
            'processed_by' => $admin_id
        ], JSON_UNESCAPED_UNICODE);

        $stmt_hist->bind_param("isss", $new_payment_id, $admin_id, $details, $new_value);
        $stmt_hist->execute();

        $message = "Paiement ajouté avec succès (Reçu: $receipt_number)";
        $message_type = 'success';
    } else {
        throw new Exception("Erreur lors de l'ajout du paiement : " . $conn->error);
    }
    break;

                    case 'add_operational_expense':
                        $expense_type = $_POST['expense_type'] ?? '';
                        $category = $_POST['category'] ?? 'other';
                        $amount = floatval(str_replace(',', '.', $_POST['amount'] ?? '0'));
                        $expense_date = $_POST['expense_date'] ?? date('Y-m-d');
                        $vendor_name = $_POST['vendor_name'] ?? '';
                        $invoice_number = $_POST['invoice_number'] ?? '';
                        $description = $_POST['description'] ?? '';
                        $payment_method = $_POST['payment_method'] ?? 'bank_transfer';
                        $reference = $_POST['reference'] ?? '';

                        if (empty($expense_type) || $amount <= 0) {
                            throw new Exception("Champs obligatoires manquants ou invalides pour la dépense opérationnelle.");
                        }

                        $insert_expense = "INSERT INTO operational_expenses (expense_type, category, amount, expense_date, vendor_name, invoice_number, description, payment_method, reference_number, processed_by) 
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $conn->prepare($insert_expense);
                        $stmt->bind_param("ssdsssssss", $expense_type, $category, $amount, $expense_date, $vendor_name, $invoice_number, $description, $payment_method, $reference, $admin_id);

                        if ($stmt->execute()) {
                            $message = "Dépense enregistrée avec succès.";
                            $message_type = 'success';
                        } else {
                            throw new Exception("Erreur lors de l'enregistrement de la dépense.");
                        }
                        break;

                    case 'add_payment_type':
                        $name = trim($_POST['type_name'] ?? '');
                        $category = $_POST['type_category'] ?? 'operational';
                        $description = $_POST['type_description'] ?? '';

                        if (empty($name)) {
                            throw new Exception("Le nom du type de paiement est obligatoire.");
                        }

                        $insert_type = "INSERT INTO staff_payment_types (name, category, description) VALUES (?, ?, ?)";
                        $stmt = $conn->prepare($insert_type);
                        $stmt->bind_param("sss", $name, $category, $description);

                        if ($stmt->execute()) {
                            $message = "Type de paiement ajouté avec succès.";
                            $message_type = 'success';
                        } else {
                            throw new Exception("Erreur lors de l'ajout du type de paiement.");
                        }
                        break;

                    case 'add_staff_member':
                        $staff_name = trim($_POST['staff_name'] ?? '');
                        $staff_email = trim($_POST['staff_email'] ?? '');
                        $staff_phone = trim($_POST['staff_phone'] ?? '');
                        $staff_role = $_POST['staff_role'] ?? 'teacher';
                        $staff_specialty = trim($_POST['staff_specialty'] ?? '');
                        $staff_address = trim($_POST['staff_address'] ?? '');

                        if (empty($staff_name) || empty($staff_email) || empty($staff_role)) {
                            throw new Exception("Tous les champs obligatoires doivent être remplis.");
                        }

                        if (!filter_var($staff_email, FILTER_VALIDATE_EMAIL)) {
                            throw new Exception("L'adresse email n'est pas valide.");
                        }

                        // Vérifier si l'email existe déjà
                        $check_email = "SELECT id FROM users WHERE email = ?";
                        $stmt = $conn->prepare($check_email);
                        $stmt->bind_param("s", $staff_email);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        if ($res && $res->num_rows > 0) {
                            throw new Exception("Un utilisateur avec cette adresse email existe déjà.");
                        }

                        // Générer un ID unique
                        $prefix = ($staff_role === 'teacher') ? 'UAS-PRP-' : 'ADMIN-';
                        $find_next_id = "SELECT id FROM users WHERE id LIKE ? ORDER BY id DESC LIMIT 1";
                        $stmt = $conn->prepare($find_next_id);
                        $search_pattern = $prefix . '%';
                        $stmt->bind_param("s", $search_pattern);
                        $stmt->execute();
                        $res2 = $stmt->get_result();
                        if ($res2 && $res2->num_rows > 0) {
                            $last_id = $res2->fetch_assoc()['id'];
                            $number = intval(substr($last_id, strlen($prefix))) + 1;
                        } else {
                            $number = 1;
                        }
                        $new_id = $prefix . str_pad($number, 2, '0', STR_PAD_LEFT);

                        // Générer un mot de passe temporaire
                        $temp_password = 'password123';
                        $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);

                        $insert_staff = "INSERT INTO users (id, name, email, phone, address, password, role, status, created_at) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())";
                        $stmt = $conn->prepare($insert_staff);
                        $stmt->bind_param("sssssss", $new_id, $staff_name, $staff_email, $staff_phone, $staff_address, $hashed_password, $staff_role);

                        if ($stmt->execute()) {
                            // Log admin si possible
                            $log_action = "INSERT INTO admin_logs (admin_id, action_type, description, ip_address, entity_id, entity_type, entity_name, created_at) 
                                           VALUES (?, 'CREATE_STAFF', ?, ?, ?, 'USER', ?, NOW())";
                            try {
                                $stmt_log = $conn->prepare($log_action);
                                $log_description = "Création d'un nouveau membre du personnel: $staff_name ($new_id)";
                                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                                $stmt_log->bind_param("sssss", $admin_id, $log_description, $ip_address, $new_id, $staff_name);
                                $stmt_log->execute();
                            } catch (Exception $e) {
                                error_log("[ADMIN_LOG] " . $e->getMessage());
                            }

                            $message = "Personnel ajouté avec succès (ID: $new_id). Mot de passe temporaire: $temp_password";
                            $message_type = 'success';
                        } else {
                            throw new Exception("Erreur lors de la création du membre du personnel.");
                        }
                        break;
case 'edit_payment_type':
    $type_id = $_POST['type_id'] ?? '';
    $name = trim($_POST['type_name'] ?? '');
    $category = $_POST['type_category'] ?? 'operational';
    $description = $_POST['type_description'] ?? '';

    if (empty($type_id) || empty($name)) {
        throw new Exception("Le nom du type de paiement est obligatoire.");
    }

    $update_sql = "UPDATE staff_payment_types SET name = ?, category = ?, description = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("ssss", $name, $category, $description, $type_id);

    if ($stmt->execute()) {
        $message = "Type de paiement modifié avec succès.";
        $message_type = 'success';
    } else {
        throw new Exception("Erreur lors de la modification du type de paiement.");
    }
    break;

                    default:
                        throw new Exception("Action inconnue.");
                } // end switch
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $message_type = 'error';
            error_log("[POST_ACTION] " . $e->getMessage());
        }
    }

// Post-Redirect-Get: prevent double form submission on success
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($message) && isset($message_type) && $message_type === 'success') {
        // store flash into session and redirect to the same page (GET)
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $message_type;
        $redirect = $_SERVER['REQUEST_URI'];
        header('Location: ' . $redirect);
        exit();
    }
}

}

// === Récupérations pour affichage ===
$staff_data = [];
$payment_types = [];
$recent_payments = [];
$recent_expenses = [];
$total_staff_payments = $total_operational_expenses = $active_staff = $pending_payments = 0;

try {
    // Statistiques globales
    $stats_query = "
        SELECT 
            COALESCE(SUM(CASE WHEN spt.category IN ('salary','bonus','allowance','social') THEN sp.amount END), 0) as total_staff_payments,
            COALESCE(SUM(CASE WHEN oe.category IS NOT NULL THEN oe.amount END), 0) as total_operational_expenses,
            COUNT(DISTINCT CASE WHEN u.role IN ('teacher','admin') AND u.status = 'active' THEN u.id END) as active_staff,
            COALESCE(SUM(sp.amount), 0) AS total_paid
        FROM users u
        LEFT JOIN staff_payments sp ON u.id = sp.staff_id AND MONTH(sp.payment_date) = MONTH(CURRENT_DATE) AND YEAR(sp.payment_date) = YEAR(CURRENT_DATE)
        LEFT JOIN staff_payment_types spt ON sp.payment_type_id = spt.id
        LEFT JOIN operational_expenses oe ON MONTH(oe.expense_date) = MONTH(CURRENT_DATE) AND YEAR(oe.expense_date) = YEAR(CURRENT_DATE)
        WHERE u.role IN ('teacher','admin') OR oe.id IS NOT NULL
    ";
    $stats_result = $conn->query($stats_query);
    if ($stats_result) {
        $stats = $stats_result->fetch_assoc();
        $total_staff_payments = $stats['total_staff_payments'] ?? 0;
        $total_operational_expenses = $stats['total_operational_expenses'] ?? 0;
        $active_staff = $stats['active_staff'] ?? 0;
        $pending_payments = $stats['pending_payments'] ?? 0;
    }

    // Récupération du personnel
    $staff_query = "
    SELECT 
        u.id as staff_id,
        u.name as staff_name,
        u.email as staff_email,
        u.role,
        COALESCE(SUM(CASE WHEN MONTH(sp.payment_date) = MONTH(CURRENT_DATE) AND YEAR(sp.payment_date) = YEAR(CURRENT_DATE) THEN sp.amount END), 0) as monthly_total,
        COALESCE(MAX(sp.payment_date), NULL) as last_payment_date,
        COALESCE(SUM(sp.amount), 0) AS total_paid
    FROM users u
    LEFT JOIN staff_payments sp ON u.id COLLATE utf8mb4_unicode_ci = sp.staff_id COLLATE utf8mb4_unicode_ci
    WHERE u.role IN ('teacher','admin') AND (u.blocked = 0 OR u.blocked IS NULL)
    GROUP BY u.id, u.name, u.email, u.role
    ORDER BY u.role, u.name
";

    $staff_result = $conn->query($staff_query);
    if ($staff_result === false) {
        error_log("[DEBUG] staff_query FAILED: " . $conn->error);
        $staff_data = [];
    } else {
        $staff_data = $staff_result->fetch_all(MYSQLI_ASSOC);
        error_log("[DEBUG] staff_query OK, rows=" . count($staff_data));
    }

    // Récupération des types de paiement
    $payment_types_query = "SELECT * FROM staff_payment_types WHERE is_active = 1 ORDER BY category, name";
    $payment_types_result = $conn->query($payment_types_query);
    if ($payment_types_result === false) {
        error_log("[DEBUG] payment_types_query FAILED: " . $conn->error);
        $payment_types = [];
    } else {
        $payment_types = $payment_types_result->fetch_all(MYSQLI_ASSOC);
        error_log("[DEBUG] payment_types_query OK, rows=" . count($payment_types));
    }

    // Récupération des paiements récents
    $recent_payments_query = "
    SELECT 
        sp.*,
        u.name as staff_name,
        spt.name as payment_type_name,
        spt.category,
        admin.name as processed_by_name
    FROM staff_payments sp
    LEFT JOIN users u ON sp.staff_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
    LEFT JOIN staff_payment_types spt ON sp.payment_type_id = spt.id
    LEFT JOIN users admin ON sp.processed_by COLLATE utf8mb4_unicode_ci = admin.id COLLATE utf8mb4_unicode_ci
    ORDER BY sp.payment_date DESC
    LIMIT 20
";

    $payments_result = $conn->query($recent_payments_query);
    if ($payments_result) {
        while ($payment = $payments_result->fetch_assoc()) {
            $recent_payments[] = $payment;
        }
    }

    // Récupération des dépenses opérationnelles récentes
    $expenses_query = "
        SELECT 
            oe.*,
            u.name as processed_by_name
        FROM operational_expenses oe
        LEFT JOIN users u ON oe.processed_by = u.id
        ORDER BY oe.expense_date DESC
        LIMIT 20
    ";
    $expenses_result = $conn->query($expenses_query);
    if ($expenses_result) {
        while ($expense = $expenses_result->fetch_assoc()) {
            $recent_expenses[] = $expense;
        }
    }

} catch (Exception $e) {
    error_log("Erreur lors du chargement des données: " . $e->getMessage());
    $message = "Erreur lors du chargement des données : " . $e->getMessage();
    $message_type = 'error';
    // valeurs par défaut déjà définies
}

// Petit debug visible dans la source HTML (retirer en production)
echo "<!-- DEBUG: staff_rows=" . count($staff_data) . " payment_types=" . count($payment_types) . " recent_payments=" . count($recent_payments) . " recent_expenses=" . count($recent_expenses) . " -->";

// Helper
function safe_number_format($number, $decimals = 0) {
    if (is_null($number) || !is_numeric($number)) return '0';
    return number_format($number, $decimals, ',', ' ');
}

$conn->close();
?>


<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion Paiements Personnel - Université Virtuelle</title>
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
        }

        nav a:hover, nav a.active {
            background: rgba(3, 155, 229, 0.1);
            transform: translateY(-2px);
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

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
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

        .status-processed { background: var(--success-color); }
        .status-pending { background: var(--warning-color); }
        .status-cancelled { background: var(--danger-color); }

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
            .data-table { font-size: 12px; }
            .data-table th, .data-table td { padding: 8px; }
        }

        /* Styles pour les reçus */
        .receipt-preview {
            background: white;
            color: #333;
            border-radius: 12px;
            padding: 30px;
            margin-top: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            max-width: 600px;
            margin: 20px auto;
        }

        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
            margin-bottom: 25px;
        }

        .receipt-logo {
            font-size: 36px;
            color: var(--accent-color);
            margin-bottom: 10px;
        }

        .receipt-title {
            font-size: 28px;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }

        .receipt-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 25px;
        }

        .receipt-section h4 {
            color: var(--accent-color);
            font-weight: 700;
            margin-bottom: 10px;
            text-transform: uppercase;
            font-size: 14px;
        }

        .receipt-info {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .receipt-total {
            background: var(--accent-color);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-top: 20px;
        }

        .category-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .category-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .category-card:hover {
            background: rgba(3, 155, 229, 0.1);
            border-color: var(--accent-color);
            transform: translateY(-2px);
        }

        .category-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
        }

        .category-icon {
            width: 50px;
            height: 50px;
            background: var(--accent-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }

        .category-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-light);
        }

        .category-description {
            font-size: 14px;
            color: #ccc;
            line-height: 1.4;
        }
        select option[value="add_new"] {
    background: linear-gradient(135deg, #f39c12, #e67e22) !important;
    color: white !important;
    font-weight: bold !important;
    border-top: 2px solid #d35400 !important;
    margin-top: 5px !important;
}

.staff-add-option {
    background: #f39c12 !important;
    color: white !important;
    font-weight: bold !important;
    text-align: center !important;
    padding: 10px !important;
}

/* Style pour les messages de succès avec informations importantes */
.alert-success strong {
    font-size: 1.1em;
    display: block;
    margin-top: 10px;
    padding: 10px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 5px;
}
    </style>
</head>
<body>
    <?php echo '<!-- DEBUG: staff_rows=' . count($staff_data) . ' payment_types=' . count($payment_types) . ' -->'; ?>

    <header>
        <div class="header-content">
            <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
            <nav>
                <ul>
                    <li><a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                    <li><a href="user_management.php"><i class="fas fa-users"></i> Utilisateurs</a></li>
                    <li><a href="course_management.php"><i class="fas fa-book"></i> Cours</a></li>
                    <li><a href="payment_dashboard.php"><i class="fas fa-credit-card"></i> Étudiants</a></li>
                    <li><a href="staff_payment_dashboard.php" class="active"><i class="fas fa-money-bill-wave"></i> Personnel</a></li>
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
                <i class="fas fa-users-cog"></i>
                Gestion Paiements Personnel
            </h2>
            <div class="page-actions">
                <button class="btn btn-primary" onclick="openModal('addStaffPaymentModal')">
                    <i class="fas fa-plus"></i> Paiement Personnel
                </button>
                <button class="btn btn-success" onclick="openModal('addExpenseModal')">
                    <i class="fas fa-receipt"></i> Dépense Opérationnelle
                </button>
                <button class="btn btn-warning" onclick="generateReport()">
                    <i class="fas fa-chart-bar"></i> Rapport Financier
                </button>
                <button class="btn btn-info" onclick="openModal('paymentTypeModal')">
                    <i class="fas fa-cog"></i> Types de Paiement
                </button>
                <button class="btn btn-warning" onclick="openModal('addStaffModal')">
                    <i class="fas fa-user-plus"></i> Ajouter Personnel
                </button>
                <button class="btn btn-info" onclick="openModal('addBeneficiaryModal')">
                    <i class="fas fa-user-tie"></i> Ajouter Bénéficiaire
                </button>
            </div>
        </div>
        <div class="modal" id="addStaffModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <i class="fas fa-user-plus"></i> Nouveau Membre du Personnel
                    </h3>
                    <span class="modal-close" onclick="closeModal('addStaffModal')">&times;</span>
                </div>
                <form method="POST" id="addStaffForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_staff_member">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nom complet *</label>
                            <input type="text" name="staff_name" class="form-control" placeholder="Ex: M. DUPONT Jean" required>
                        </div>
                        <div class="form-group">
                            <label>Email *</label>
                            <input type="email" name="staff_email" class="form-control" placeholder="Ex: dupont@universite.com" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Téléphone</label>
                            <input type="tel" name="staff_phone" class="form-control" placeholder="Ex: +241 01 23 45 67">
                        </div>
                        <div class="form-group">
                            <label>Fonction *</label>
                            <select name="staff_role" class="form-control" required>
                                <option value="">Sélectionner une fonction</option>
                                <option value="teacher">Enseignant</option>
                                <option value="admin">Administrateur</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Spécialité/Département</label>
                        <input type="text" name="staff_specialty" class="form-control" placeholder="Ex: Informatique, Génie Civil, Administration...">
                    </div>

                    <div class="form-group">
                        <label>Adresse</label>
                        <textarea name="staff_address" class="form-control" rows="2" placeholder="Adresse complète (optionnel)"></textarea>
                    </div>

                    <div style="text-align: right; margin-top: 25px;">
                        <button type="button" class="btn" onclick="closeModal('addStaffModal')" style="background: #95a5a6; color: white; margin-right: 10px;">
                            Annuler
                        </button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-user-plus"></i> Ajouter le Personnel
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <!-- 3. NOUVEAU MODAL POUR AJOUTER UN BÉNÉFICIAIRE -->
        <div class="modal" id="addBeneficiaryModal">
            <div class="modal-content" style="max-width: 800px;">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <i class="fas fa-user-tie"></i> Nouveau Bénéficiaire
                    </h3>
                    <span class="modal-close" onclick="closeModal('addBeneficiaryModal')">&times;</span>
                </div>
                <form method="POST" id="addBeneficiaryForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add_beneficiary">
                    
                    <!-- Informations de base -->
                    <h4 style="color: var(--accent-color); margin-bottom: 15px; border-bottom: 1px solid var(--border-color); padding-bottom: 5px;">
                        <i class="fas fa-user"></i> Informations de base
                    </h4>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nom complet/Raison sociale *</label>
                            <input type="text" name="beneficiary_name" class="form-control" placeholder="Ex: M. DUPONT Jean / SARL TechServices" required>
                        </div>
                        <div class="form-group">
                            <label>Type de bénéficiaire *</label>
                            <select name="beneficiary_type" class="form-control" required onchange="toggleBeneficiaryFields()">
                                <option value="">Sélectionner le type</option>
                                <option value="personnel">Personnel (Employé)</option>
                                <option value="fournisseur">Fournisseur</option>
                                <option value="service">Prestataire de services</option>
                                <option value="prestataire">Consultant/Prestataire</option>
                                <option value="autre">Autre</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="beneficiary_email" class="form-control" placeholder="email@exemple.com">
                        </div>
                        <div class="form-group">
                            <label>Téléphone</label>
                            <input type="tel" name="beneficiary_phone" class="form-control" placeholder="+241 XX XX XX XX">
                        </div>
                    </div>

                    <!-- Informations professionnelles (pour personnel) -->
                    <div id="personnel-fields" style="display: none;">
                        <h4 style="color: var(--accent-color); margin: 20px 0 15px 0; border-bottom: 1px solid var(--border-color); padding-bottom: 5px;">
                            <i class="fas fa-briefcase"></i> Informations professionnelles
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Fonction/Poste</label>
                                <input type="text" name="beneficiary_fonction" class="form-control" placeholder="Ex: Technicien, Secrétaire, Gardien">
                            </div>
                            <div class="form-group">
                                <label>Département/Service</label>
                                <input type="text" name="beneficiary_departement" class="form-control" placeholder="Ex: Maintenance, Administration">
                            </div>
                        </div>
                    </div>

                    <!-- Informations entreprise (pour fournisseurs/prestataires) -->
                    <div id="entreprise-fields" style="display: none;">
                        <h4 style="color: var(--accent-color); margin: 20px 0 15px 0; border-bottom: 1px solid var(--border-color); padding-bottom: 5px;">
                            <i class="fas fa-building"></i> Informations entreprise
                        </h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Nom de l'entreprise</label>
                                <input type="text" name="beneficiary_entreprise" class="form-control" placeholder="Ex: SARL TechnoServices">
                            </div>
                            <div class="form-group">
                                <label>Secteur d'activité</label>
                                <input type="text" name="beneficiary_secteur" class="form-control" placeholder="Ex: Informatique, BTP, Services">
                            </div>
                        </div>
                    </div>

                    <!-- Informations bancaires -->
                    <h4 style="color: var(--accent-color); margin: 20px 0 15px 0; border-bottom: 1px solid var(--border-color); padding-bottom: 5px;">
                        <i class="fas fa-university"></i> Informations bancaires (optionnel)
                    </h4>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Numéro de compte</label>
                            <input type="text" name="beneficiary_compte" class="form-control" placeholder="Ex: 12345678901234567890">
                        </div>
                        <div class="form-group">
                            <label>Banque</label>
                            <input type="text" name="beneficiary_banque" class="form-control" placeholder="Ex: BGFI, UGB, Ecobank">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Adresse complète</label>
                        <textarea name="beneficiary_address" class="form-control" rows="2" placeholder="Adresse complète du bénéficiaire"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Description/Notes</label>
                        <textarea name="beneficiary_description" class="form-control" rows="2" placeholder="Informations supplémentaires (spécialité, conditions de paiement, etc.)"></textarea>
                    </div>

                    <div style="text-align: right; margin-top: 25px;">
                        <button type="button" class="btn" onclick="closeModal('addBeneficiaryModal')" style="background: #95a5a6; color: white; margin-right: 10px;">
                            Annuler
                        </button>
                        <button type="submit" class="btn btn-info">
                            <i class="fas fa-save"></i> Ajouter le Bénéficiaire
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-title">Paiements Personnel</span>
                    <i class="stat-icon fas fa-money-check-alt"></i>
                </div>
                <div class="stat-value"><?php echo safe_number_format($total_staff_payments); ?> FCFA</div>
                <div class="stat-change">
                    <i class="fas fa-calendar"></i> Ce mois
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-header">
                    <span class="stat-title">Dépenses Opérationnelles</span>
                    <i class="stat-icon fas fa-building"></i>
                </div>
                <div class="stat-value"><?php echo safe_number_format($total_operational_expenses); ?> FCFA</div>
                <div class="stat-change">
                    <i class="fas fa-tools"></i> Ce mois
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-header">
                    <span class="stat-title">Personnel Actif</span>
                    <i class="stat-icon fas fa-user-friends"></i>
                </div>
                <div class="stat-value"><?php echo safe_number_format($active_staff); ?></div>
                <div class="stat-change">
                    <i class="fas fa-users"></i> Employés
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-header">
                    <span class="stat-title">Paiements En Attente</span>
                    <i class="stat-icon fas fa-clock"></i>
                </div>
                <div class="stat-value"><?php echo safe_number_format($pending_payments); ?></div>
                <div class="stat-change">
                    <i class="fas fa-exclamation-triangle"></i> À traiter
                </div>
            </div>
        </div>

        <!-- Onglets -->
        <div class="tabs">
            <button class="tab-button active" onclick="showTab('staff')">
                <i class="fas fa-users"></i> Personnel
            </button>
            <button class="tab-button" onclick="showTab('payments')">
                <i class="fas fa-money-bill-wave"></i> Paiements Récents
            </button>
            <button class="tab-button" onclick="showTab('expenses')">
                <i class="fas fa-receipt"></i> Dépenses Opérationnelles
            </button>
            <button class="tab-button" onclick="showTab('categories')">
                <i class="fas fa-tags"></i> Types de Paiement
            </button>
        </div>

        <!-- Contenu Personnel -->
        <div class="tab-content active" id="staff-tab">
            <?php if (empty($staff_data)): ?>
                <div style="text-align: center; padding: 40px;">
                    <div style="font-size: 48px; margin-bottom: 20px; color: var(--accent-color);">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <h3 style="margin-bottom: 10px;">Aucun personnel trouvé</h3>
                    <p style="color: #ccc;">Ajoutez du personnel dans la gestion des utilisateurs.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Personnel</th>
                            <th>Fonction</th>
                            <th>Email</th>
                            <th>Paiements ce Mois</th>
                            <th>Dernier Paiement</th>
                            <th>Paiement total (FCFA)</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staff_data as $staff): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 40px; height: 40px; border-radius: 50%; background: var(--accent-color); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                                        <?php echo strtoupper(substr($staff['staff_name'], 0, 2)); ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($staff['staff_name']); ?></div>
                                        <div style="font-size: 12px; color: #ccc;"><?php echo htmlspecialchars($staff['staff_id']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span style="padding: 4px 12px; background: <?php echo $staff['role'] === 'teacher' ? 'var(--success-color)' : 'var(--info-color)'; ?>; color: white; border-radius: 20px; font-size: 11px; text-transform: uppercase;">
                                    <?php echo $staff['role'] === 'teacher' ? 'Enseignant' : 'Administrateur'; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($staff['staff_email']); ?></td>
                            <td style="font-weight: 700; color: var(--success-color);">
                                <?php echo safe_number_format($staff['monthly_total']); ?> FCFA
                            </td>
                            <td>
<?php 
if (!empty($staff['last_payment_date']) && $staff['last_payment_date'] !== 'Jamais') {
    echo date('d/m/Y', strtotime($staff['last_payment_date']));
} else {
    echo '<span style="color: var(--warning-color);">Jamais</span>';
}
?>
                            </td>
                            <td style="font-weight:700; color:var(--success-color);">
  <?php echo safe_number_format($staff['total_paid']); ?> FCFA
</td>

                            <td>
                                <button class="btn btn-small btn-primary" onclick="addPaymentForStaff('<?php echo $staff['staff_id']; ?>', '<?php echo htmlspecialchars($staff['staff_name']); ?>')">
                                    <i class="fas fa-plus"></i> Paiement
                                </button>
<button class="btn btn-small btn-success" onclick="viewStaffHistory('<?php echo $staff['staff_id']; ?>')">
    <i class="fas fa-history"></i> Historique
</button>

                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Contenu Paiements Récents -->
        <div class="tab-content" id="payments-tab">
            <?php if (empty($recent_payments)): ?>
                <div style="text-align: center; padding: 40px; color: #ccc;">
                    <i class="fas fa-money-bill-wave" style="font-size: 48px; margin-bottom: 20px;"></i>
                    <h3>Aucun paiement enregistré</h3>
                    <p>Les paiements du personnel s'afficheront ici.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Personnel</th>
                            <th>Type de Paiement</th>
                            <th>Montant</th>
                            <th>Méthode</th>
                            <th>Reçu</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_payments as $payment): ?>
                        <tr>
                            <td><?php echo date('d/m/Y H:i', strtotime($payment['payment_date'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($payment['staff_name']); ?></strong><br>
                                <small style="color: #ccc;"><?php echo htmlspecialchars($payment['staff_id']); ?></small>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($payment['payment_type_name']); ?></div>
                                <small style="color: var(--accent-color); text-transform: uppercase; font-weight: 600;">
                                    <?php 
                                    $categories = [
                                        'salary' => 'Salaire',
                                        'bonus' => 'Prime',
                                        'allowance' => 'Indemnité',
                                        'social' => 'Social'
                                    ];
                                    echo $categories[$payment['category']] ?? $payment['category'];
                                    ?>
                                </small>
                            </td>
                            <td style="color: var(--success-color); font-weight: bold;">
                                <?php echo safe_number_format($payment['amount']); ?> FCFA
                            </td>
                            <td>
                                <?php
                                $methods = [
                                    'bank_transfer' => 'Virement',
                                    'cash' => 'Espèces',
                                    'check' => 'Chèque',
                                    'mobile_money' => 'Mobile Money'
                                ];
                                echo $methods[$payment['payment_method']] ?? $payment['payment_method'];
                                ?>
                            </td>
                            <td>
                                <?php if ($payment['receipt_number']): ?>
                                <span style="font-family: monospace; background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px;">
                                    <?php echo htmlspecialchars($payment['receipt_number']); ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $payment['status']; ?>">
                                    <?php
                                    $statuses = [
                                        'processed' => 'Traité',
                                        'pending' => 'En attente',
                                        'cancelled' => 'Annulé'
                                    ];
                                    echo $statuses[$payment['status']] ?? $payment['status'];
                                    ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-small btn-success" onclick="printReceipt(<?php echo $payment['id']; ?>)">
                                    <i class="fas fa-print"></i> Reçu
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Contenu Dépenses Opérationnelles -->
        <div class="tab-content" id="expenses-tab">
            <?php if (empty($recent_expenses)): ?>
                <div style="text-align: center; padding: 40px; color: #ccc;">
                    <i class="fas fa-receipt" style="font-size: 48px; margin-bottom: 20px;"></i>
                    <h3>Aucune dépense enregistrée</h3>
                    <p>Les dépenses opérationnelles s'afficheront ici.</p>
                </div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type de Dépense</th>
                            <th>Catégorie</th>
                            <th>Fournisseur</th>
                            <th>Montant</th>
                            <th>N° Facture</th>
                            <th>Statut</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_expenses as $expense): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($expense['expense_date'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($expense['expense_type']); ?></strong></td>
                            <td>
                                <span style="padding: 4px 8px; background: var(--info-color); color: white; border-radius: 12px; font-size: 11px; text-transform: uppercase;">
                                    <?php
                                    $categories = [
                                        'equipment' => 'Équipement',
                                        'maintenance' => 'Maintenance',
                                        'utilities' => 'Services',
                                        'supplies' => 'Fournitures',
                                        'services' => 'Services',
                                        'other' => 'Autre'
                                    ];
                                    echo $categories[$expense['category']] ?? $expense['category'];
                                    ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($expense['vendor_name'] ?? 'N/A'); ?></td>
                            <td style="color: var(--danger-color); font-weight: bold;">
                                <?php echo safe_number_format($expense['amount']); ?> FCFA
                            </td>
                            <td>
                                <?php if ($expense['invoice_number']): ?>
                                <span style="font-family: monospace; background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px;">
                                    <?php echo htmlspecialchars($expense['invoice_number']); ?>
                                </span>
                                <?php else: ?>
                                <span style="color: #ccc;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $expense['status'] === 'paid' ? 'processed' : $expense['status']; ?>">
                                    <?php
                                    $statuses = [
                                        'paid' => 'Payé',
                                        'pending' => 'En attente',
                                        'cancelled' => 'Annulé'
                                    ];
                                    echo $statuses[$expense['status']] ?? $expense['status'];
                                    ?>
                                </span>
                            </td>
<td>
    <!-- Bouton Modifier -->
    <button class="btn btn-small btn-info"
        onclick="editExpense(
            '<?php echo $expense['id']; ?>',
            '<?php echo htmlspecialchars($expense['expense_type'], ENT_QUOTES); ?>',
            '<?php echo htmlspecialchars($expense['category'], ENT_QUOTES); ?>',
            '<?php echo htmlspecialchars($expense['vendor_name'] ?? '', ENT_QUOTES); ?>',
            '<?php echo htmlspecialchars($expense['amount'], ENT_QUOTES); ?>',
            '<?php echo htmlspecialchars($expense['invoice_number'] ?? '', ENT_QUOTES); ?>',
            '<?php echo htmlspecialchars($expense['description'] ?? '', ENT_QUOTES); ?>'
        )">
        <i class="fas fa-edit"></i> Modifier
    </button>

    <!-- Bouton Supprimer -->
    <button class="btn btn-small btn-danger" onclick="deleteExpense('<?php echo $expense['id']; ?>')">
        <i class="fas fa-trash"></i> Supprimer
    </button>
</td>

                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Contenu Types de Paiement -->
        <div class="tab-content" id="categories-tab">
            <div class="category-cards">
                <?php 
                $categories = ['salary', 'bonus', 'allowance', 'social'];
                $category_icons = [
                    'salary' => 'fas fa-money-check-alt',
                    'bonus' => 'fas fa-gift',
                    'allowance' => 'fas fa-hand-holding-usd',
                    'social' => 'fas fa-shield-alt'
                ];
                $category_names = [
                    'salary' => 'Salaires',
                    'bonus' => 'Primes & Bonus',
                    'allowance' => 'Indemnités',
                    'social' => 'Charges Sociales'
                ];
                
                foreach ($categories as $category):
                    $types_in_category = array_filter($payment_types, function($type) use ($category) {
                        return $type['category'] === $category;
                    });
                ?>
                <div class="category-card">
                    <div class="category-header">
                        <div class="category-icon">
                            <i class="<?php echo $category_icons[$category] ?? 'fas fa-tag'; ?>"></i>
                        </div>
                        <div class="category-title"><?php echo $category_names[$category] ?? ucfirst($category); ?></div>
                    </div>
                    <div class="category-description">
                        <strong><?php echo count($types_in_category); ?> types configurés</strong><br>
                        <?php 
                        $type_names = array_slice(array_column($types_in_category, 'name'), 0, 3);
                        echo implode(', ', $type_names);
                        if (count($types_in_category) > 3) echo '...';
                        ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nom du Type</th>
                        <th>Catégorie</th>
                        <th>Description</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payment_types as $type): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($type['name']); ?></strong></td>
                        <td>
                            <span style="padding: 4px 12px; background: var(--accent-color); color: white; border-radius: 20px; font-size: 11px; text-transform: uppercase;">
                                <?php echo $category_names[$type['category']] ?? $type['category']; ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($type['description'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="status-badge <?php echo $type['is_active'] ? 'status-processed' : 'status-cancelled'; ?>">
                                <?php echo $type['is_active'] ? 'Actif' : 'Inactif'; ?>
                            </span>
                        </td>
                        <td>
<button class="btn btn-small btn-info"
    onclick="editPaymentType(
        '<?php echo $type['id']; ?>',
        '<?php echo htmlspecialchars($type['name'], ENT_QUOTES); ?>',
        '<?php echo htmlspecialchars($type['category'], ENT_QUOTES); ?>',
        '<?php echo htmlspecialchars($type['description'] ?? '', ENT_QUOTES); ?>'
    )">
    <i class="fas fa-edit"></i> Modifier
</button>

                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal Paiement Personnel -->
    <div class="modal" id="addStaffPaymentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-plus"></i> Nouveau Paiement Personnel
                </h3>
                <span class="modal-close" onclick="closeModal('addStaffPaymentModal')">&times;</span>
            </div>
            <form method="POST" id="staffPaymentForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_staff_payment">
                
                <div class="form-group">
                    <label>Personnel *</label>
                    <select name="staff_id" class="form-control" required>
                        <option value="">Sélectionner un membre du personnel</option>
                        <?php foreach ($staff_data as $staff): ?>
<option value="<?php echo htmlspecialchars($staff['staff_id']); ?>">
    <?php echo htmlspecialchars($staff['staff_name'] . ' (' . ($staff['role'] === 'teacher' ? 'Enseignant' : 'Administrateur') . ')'); ?>
</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Type de Paiement *</label>
                        <select name="payment_type_id" class="form-control" required>
                            <option value="">Choisir le type</option>
                            <?php 
                            $current_category = '';
                            foreach ($payment_types as $type):
                                if ($type['category'] !== $current_category):
                                    if ($current_category !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . ($category_names[$type['category']] ?? $type['category']) . '">';
                                    $current_category = $type['category'];
                                endif;
                            ?>
                            <option value="<?php echo $type['id']; ?>">
                                <?php echo htmlspecialchars($type['name']); ?>
                            </option>
                            <?php endforeach; ?>
                            <?php if (!empty($payment_types)): ?>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Montant (FCFA) *</label>
                        <input type="number" name="amount" class="form-control" min="0" step="0.01" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Méthode de paiement *</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="bank_transfer">Virement bancaire</option>
                            <option value="cash">Espèces</option>
                            <option value="check">Chèque</option>
                            <option value="mobile_money">Mobile Money</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Référence</label>
                        <input type="text" name="reference" class="form-control" placeholder="Numéro de référence">
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Description du paiement (optionnel)"></textarea>
                </div>

                <div style="text-align: right; margin-top: 25px;">
                    <button type="button" class="btn" onclick="closeModal('addStaffPaymentModal')" style="background: #95a5a6; color: white; margin-right: 10px;">
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Enregistrer le Paiement
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Dépense Opérationnelle -->
    <div class="modal" id="addExpenseModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-receipt"></i> Nouvelle Dépense Opérationnelle
                </h3>
                <span class="modal-close" onclick="closeModal('addExpenseModal')">&times;</span>
            </div>
            <form method="POST" id="expenseForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_operational_expense">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Type de Dépense *</label>
                        <input type="text" name="expense_type" class="form-control" placeholder="Ex: Achat ordinateurs" required>
                    </div>
                    <div class="form-group">
                        <label>Catégorie *</label>
                        <select name="category" class="form-control" required>
                            <option value="equipment">Équipement</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="utilities">Services publics</option>
                            <option value="supplies">Fournitures</option>
                            <option value="services">Services</option>
                            <option value="other">Autre</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Montant (FCFA) *</label>
                        <input type="number" name="amount" class="form-control" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Date de Dépense *</label>
                        <input type="date" name="expense_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Nom du Fournisseur</label>
                        <input type="text" name="vendor_name" class="form-control" placeholder="Nom du fournisseur">
                    </div>
                    <div class="form-group">
                        <label>N° Facture</label>
                        <input type="text" name="invoice_number" class="form-control" placeholder="Numéro de facture">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Méthode de paiement *</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="bank_transfer">Virement bancaire</option>
                            <option value="cash">Espèces</option>
                            <option value="check">Chèque</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Référence</label>
                        <input type="text" name="reference" class="form-control" placeholder="Référence de paiement">
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Description de la dépense"></textarea>
                </div>

                <div style="text-align: right; margin-top: 25px;">
                    <button type="button" class="btn" onclick="closeModal('addExpenseModal')" style="background: #95a5a6; color: white; margin-right: 10px;">
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Enregistrer la Dépense
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Type de Paiement -->
    <div class="modal" id="paymentTypeModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-plus"></i> Nouveau Type de Paiement
                </h3>
                <span class="modal-close" onclick="closeModal('paymentTypeModal')">&times;</span>
            </div>
            <form method="POST" id="paymentTypeForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_payment_type">
                
                <div class="form-group">
                    <label>Nom du Type *</label>
                    <input type="text" name="type_name" class="form-control" placeholder="Ex: Prime de rendement" required>
                </div>

                <div class="form-group">
                    <label>Catégorie *</label>
                    <select name="type_category" class="form-control" required>
                        <option value="">Sélectionner une catégorie</option>
                        <option value="salary">Salaire</option>
                        <option value="bonus">Prime & Bonus</option>
                        <option value="allowance">Indemnités</option>
                        <option value="social">Charges Sociales</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="type_description" class="form-control" rows="3" placeholder="Description du type de paiement"></textarea>
                </div>

                <div style="text-align: right; margin-top: 25px;">
                    <button type="button" class="btn" onclick="closeModal('paymentTypeModal')" style="background: #95a5a6; color: white; margin-right: 10px;">
                        Annuler
                    </button>
                    <button type="submit" class="btn btn-info">
                        <i class="fas fa-save"></i> Ajouter le Type
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Historique Personnel -->
    <div class="modal" id="staffHistoryModal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-history"></i> Historique des Paiements
                </h3>
                <span class="modal-close" onclick="closeModal('staffHistoryModal')">&times;</span>
            </div>
            <div id="staffHistoryContent">
                <!-- Contenu dynamique -->
            </div>
        </div>
    </div>

    <!-- Modal Reçu -->
    <div class="modal" id="receiptModal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-receipt"></i> Reçu de Paiement
                </h3>
                <span class="modal-close" onclick="closeModal('receiptModal')">&times;</span>
            </div>
            <div id="receiptContent">
                <!-- Contenu du reçu -->
            </div>
            <div style="text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                <button class="btn btn-primary" onclick="printReceiptContent()">
                    <i class="fas fa-print"></i> Imprimer
                </button>
                <button class="btn btn-success" onclick="downloadReceiptPDF()">
                    <i class="fas fa-download"></i> Télécharger PDF
                </button>
            </div>
        </div>
    </div>

    <script>
        // Gestion des onglets
        function showTab(tabName) {
            // Cacher tous les contenus
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));
            
            // Désactiver tous les boutons
            const buttons = document.querySelectorAll('.tab-button');
            buttons.forEach(button => button.classList.remove('active'));
            
            // Activer le contenu et bouton sélectionnés
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }

        // Gestion des modals
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Fermer modal en cliquant à l'extérieur
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Ajouter paiement pour un personnel spécifique
        function addPaymentForStaff(staffId, staffName) {
            const modal = document.getElementById('addStaffPaymentModal');
            const select = modal.querySelector('select[name="staff_id"]');
            
            // Préselectionner le personnel
            select.value = staffId;
            
            // Modifier le titre du modal
            modal.querySelector('.modal-title').innerHTML = 
                '<i class="fas fa-plus"></i> Nouveau Paiement pour ' + staffName;
            
            openModal('addStaffPaymentModal');
        }

        // Voir l'historique des paiements d'un personnel
function viewStaffHistory(staffId) {
    const content = document.getElementById('staffHistoryContent');
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i><br>Chargement...</div>';
    openModal('staffHistoryModal');

    fetch('?action=get_staff_history&staff_id=' + encodeURIComponent(staffId))
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                content.innerHTML = '<div style="text-align:center;color:red;">Erreur: ' + (data.error || 'Impossible de charger les données') + '</div>';
                return;
            }

            const payments = data.payments || [];
            const totalMonth = data.total_month || 0;
            const count = data.count || 0;

            if (payments.length === 0) {
                content.innerHTML = '<div style="text-align:center;padding:30px;color:#999;">Aucun paiement trouvé pour ce personnel.</div>';
                return;
            }

            let rowsHtml = '';
            payments.forEach(p => {
                const date = p.payment_date ? new Date(p.payment_date).toLocaleDateString() : '-';
                const type = p.type_name || '-';
                const amount = parseFloat(p.amount || 0).toLocaleString('fr-FR') + ' FCFA';
                const method = p.payment_method || '-';
                const receipt = p.receipt_number || '-';
                const statusLabel = (p.status === 'processed') ? 
                    '<span class="status-badge status-processed">Traité</span>' :
                    (p.status === 'pending') ? '<span class="status-badge status-pending">En attente</span>' :
                    '<span class="status-badge status-cancelled">Annulé</span>';

                rowsHtml += `
                    <tr>
                        <td>${date}</td>
                        <td>${type}</td>
                        <td style="color: var(--success-color); font-weight: bold;">${amount}</td>
                        <td>${method}</td>
                        <td><span style="font-family: monospace; background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px;">${receipt}</span></td>
                        <td>${statusLabel}</td>
                    </tr>
                `;
            });

            content.innerHTML = `
                <div style="margin-bottom: 20px;">
                    <h4 style="color: var(--accent-color); margin-bottom: 15px;">Résumé des Paiements</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                        <div style="background: rgba(46, 204, 113, 0.1); padding: 15px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 20px; font-weight: bold; color: var(--success-color);">${totalMonth.toLocaleString('fr-FR')} FCFA</div>
                            <div style="font-size: 12px; color: #ccc;">Total ce mois</div>
                        </div>
                        <div style="background: rgba(52, 152, 219, 0.1); padding: 15px; border-radius: 8px; text-align: center;">
                            <div style="font-size: 20px; font-weight: bold; color: var(--info-color);">${count}</div>
                            <div style="font-size: 12px; color: #ccc;">Nombre de paiements</div>
                        </div>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Montant</th>
                            <th>Méthode</th>
                            <th>Reçu</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rowsHtml}
                    </tbody>
                </table>
            `;
        })
        .catch(error => {
            console.error(error);
            content.innerHTML = '<div style="text-align:center;color:red;">Erreur de chargement.</div>';
        });
}

        // Imprimer un reçu
        function printReceipt(paymentId) {
            const content = document.getElementById('receiptContent');
            
            // Générer le contenu du reçu
            content.innerHTML = generateReceiptHTML(paymentId);
            
            openModal('receiptModal');
        }

        // Générer le HTML du reçu
        function generateReceiptHTML(paymentId) {
            // Données simulées (remplacer par un appel AJAX)
            const receiptData = {
                id: paymentId,
                receiptNumber: 'REC-STAFF-2024-1234',
                date: new Date().toLocaleDateString('fr-FR'),
                staffName: 'M. DUPONT Jean',
                staffId: 'UAS-PRP-001',
                paymentType: 'Salaire de base',
                amount: '350,000',
                method: 'Virement bancaire',
                reference: 'VIR-2024-5678',
                processedBy: 'Admin Principal'
            };

            return `
                <div class="receipt-preview">
                    <div class="receipt-header">
                        <div class="receipt-logo">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="receipt-title">UNIVERSITÉ VIRTUELLE</div>
                        <div style="font-size: 16px; color: #666;">REÇU DE PAIEMENT PERSONNEL</div>
                        <div style="font-size: 14px; margin-top: 10px; font-weight: bold;">N° ${receiptData.receiptNumber}</div>
                    </div>

                    <div class="receipt-details">
                        <div class="receipt-section">
                            <h4>Informations Personnel</h4>
                            <div class="receipt-info">
                                <span>Nom:</span>
                                <strong>${receiptData.staffName}</strong>
                            </div>
                            <div class="receipt-info">
                                <span>ID Personnel:</span>
                                <strong>${receiptData.staffId}</strong>
                            </div>
                            <div class="receipt-info">
                                <span>Date:</span>
                                <strong>${receiptData.date}</strong>
                            </div>
                        </div>

                        <div class="receipt-section">
                            <h4>Détails du Paiement</h4>
                            <div class="receipt-info">
                                <span>Type:</span>
                                <strong>${receiptData.paymentType}</strong>
                            </div>
                            <div class="receipt-info">
                                <span>Méthode:</span>
                                <strong>${receiptData.method}</strong>
                            </div>
                            <div class="receipt-info">
                                <span>Référence:</span>
                                <strong>${receiptData.reference}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="receipt-total">
                        <div style="font-size: 18px; margin-bottom: 5px;">MONTANT TOTAL</div>
                        <div style="font-size: 32px; font-weight: bold;">${receiptData.amount} FCFA</div>
                    </div>

                    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; color: #666;">
                        <div style="margin-bottom: 10px;">Traité par: ${receiptData.processedBy}</div>
                        <div style="font-size: 12px;">Ce reçu certifie le paiement effectué à la date indiquée</div>
                        <div style="font-size: 12px; margin-top: 10px;">Université Virtuelle - Système de Gestion des Paiements</div>
                    </div>
                </div>
            `;
        }

        // Imprimer le contenu du reçu
        function printReceiptContent() {
            const content = document.getElementById('receiptContent').innerHTML;
            const printWindow = window.open('', '_blank');
            
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Reçu de Paiement</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
                        .receipt-preview { background: white; color: #333; max-width: 600px; margin: 0 auto; }
                        .receipt-header { text-align: center; border-bottom: 2px solid #eee; padding-bottom: 20px; margin-bottom: 25px; }
                        .receipt-logo { font-size: 36px; color: #039be5; margin-bottom: 10px; }
                        .receipt-title { font-size: 28px; font-weight: 700; color: #333; margin-bottom: 10px; }
                        .receipt-details { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 25px; }
                        .receipt-section h4 { color: #039be5; font-weight: 700; margin-bottom: 10px; text-transform: uppercase; font-size: 14px; }
                        .receipt-info { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
                        .receipt-total { background: #039be5; color: white; padding: 20px; border-radius: 8px; text-align: center; margin-top: 20px; }
                        @media print { body { margin: 0; } .no-print { display: none; } }
                    </style>
                </head>
                <body onload="window.print(); window.close();">
                    ${content}
                </body>
                </html>
            `);
            
            printWindow.document.close();
        }

        // Télécharger le reçu en PDF (simulation)
        function downloadReceiptPDF() {
            showMessage('Fonctionnalité de téléchargement PDF en cours de développement', 'info');
        }

        // Générer un rapport financier
        function generateReport() {
            showMessage('Génération du rapport en cours...', 'info');
            
            // Simulation de génération de rapport
            setTimeout(() => {
                const reportData = {
                    totalStaffPayments: <?php echo $total_staff_payments; ?>,
                    totalExpenses: <?php echo $total_operational_expenses; ?>,
                    activeStaff: <?php echo $active_staff; ?>,
                    pendingPayments: <?php echo $pending_payments; ?>
                };
                
                const reportContent = `
                    RAPPORT FINANCIER PERSONNEL - ${new Date().toLocaleDateString('fr-FR')}
                    
                    RÉSUMÉ MENSUEL:
                    • Paiements Personnel: ${formatNumber(reportData.totalStaffPayments)} FCFA
                    • Dépenses Opérationnelles: ${formatNumber(reportData.totalExpenses)} FCFA
                    • Personnel Actif: ${reportData.activeStaff} employés
                    • Paiements en Attente: ${reportData.pendingPayments}
                    
                    Total des Sorties: ${formatNumber(reportData.totalStaffPayments + reportData.totalExpenses)} FCFA
                `;
                
                // Créer et télécharger le fichier
                const blob = new Blob([reportContent], { type: 'text/plain' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `rapport-financier-${new Date().toISOString().split('T')[0]}.txt`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                
                showMessage('Rapport généré et téléchargé avec succès', 'success');
            }, 2000);
        }
    // Injection du CSRF token depuis PHP
    window.csrfToken = '<?php echo $csrf_token; ?>';

    function editPaymentType(typeId, name, category, description) {
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.style.display = 'flex';
        modal.style.justifyContent = 'center';
        modal.style.alignItems = 'center';
        modal.style.background = 'rgba(0,0,0,0.6)';
        modal.style.position = 'fixed';
        modal.style.top = '0';
        modal.style.left = '0';
        modal.style.width = '100%';
        modal.style.height = '100%';
        modal.style.zIndex = '9999';

        modal.innerHTML = `
            <div style="background:#1c1f26; padding:25px; border-radius:12px; width:450px; color:white;">
                <h3 style="margin-bottom:20px; color:var(--accent-color);">
                    <i class="fas fa-pen"></i> Modifier le Type de Paiement
                </h3>
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="${window.csrfToken}">
                    <input type="hidden" name="action" value="edit_payment_type">
                    <input type="hidden" name="type_id" value="${typeId}">
                    
                    <label>Nom du type *</label>
                    <input type="text" name="type_name" value="${name}" required style="width:100%; padding:8px; margin-bottom:12px; border-radius:6px; border:none;">
                    
                    <label>Catégorie *</label>
                    <select name="type_category" required style="width:100%; padding:8px; margin-bottom:12px; border-radius:6px; border:none;">
                        <option value="salary" ${category==='salary'?'selected':''}>Salaire</option>
                        <option value="bonus" ${category==='bonus'?'selected':''}>Prime</option>
                        <option value="allowance" ${category==='allowance'?'selected':''}>Indemnité</option>
                        <option value="social" ${category==='social'?'selected':''}>Social</option>
                        <option value="operational" ${category==='operational'?'selected':''}>Opérationnel</option>
                        <option value="supplier" ${category==='supplier'?'selected':''}>Fournisseur</option>
                    </select>

                    <label>Description</label>
                    <textarea name="type_description" rows="3" style="width:100%; padding:8px; border-radius:6px; border:none;">${description||''}</textarea>

                    <div style="display:flex; justify-content:flex-end; margin-top:20px; gap:10px;">
                        <button type="button" onclick="document.body.removeChild(this.closest('.modal'))" 
                            style="background:#444; color:white; padding:8px 16px; border:none; border-radius:6px;">
                            Annuler
                        </button>
                        <button type="submit" style="background:var(--accent-color); color:white; padding:8px 16px; border:none; border-radius:6px;">
                            Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
    }


function editExpense(id, type, category, vendor, amount, invoice, description) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.style.cssText = "display:flex;justify-content:center;align-items:center;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;";

    modal.innerHTML = `
        <div style="background:#1c1f26;padding:25px;border-radius:12px;width:500px;color:white;">
            <h3 style="margin-bottom:20px;color:var(--accent-color);"><i class="fas fa-pen"></i> Modifier la Dépense</h3>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="${window.csrfToken}">
                <input type="hidden" name="action" value="edit_operational_expense">
                <input type="hidden" name="expense_id" value="${id}">

                <label>Type de Dépense *</label>
                <input type="text" name="expense_type" value="${type}" required style="width:100%;margin-bottom:12px;padding:8px;border-radius:6px;border:none;">

                <label>Catégorie *</label>
                <select name="category" required style="width:100%;margin-bottom:12px;padding:8px;border-radius:6px;border:none;">
                    <option value="equipment" ${category==='equipment'?'selected':''}>Équipement</option>
                    <option value="maintenance" ${category==='maintenance'?'selected':''}>Maintenance</option>
                    <option value="utilities" ${category==='utilities'?'selected':''}>Services</option>
                    <option value="supplies" ${category==='supplies'?'selected':''}>Fournitures</option>
                    <option value="services" ${category==='services'?'selected':''}>Services</option>
                    <option value="other" ${category==='other'?'selected':''}>Autre</option>
                </select>

                <label>Fournisseur</label>
                <input type="text" name="vendor_name" value="${vendor}" style="width:100%;margin-bottom:12px;padding:8px;border-radius:6px;border:none;">

                <label>Montant *</label>
                <input type="number" step="0.01" name="amount" value="${amount}" required style="width:100%;margin-bottom:12px;padding:8px;border-radius:6px;border:none;">

                <label>N° Facture</label>
                <input type="text" name="invoice_number" value="${invoice}" style="width:100%;margin-bottom:12px;padding:8px;border-radius:6px;border:none;">

                <label>Description</label>
                <textarea name="description" rows="3" style="width:100%;padding:8px;border-radius:6px;border:none;">${description}</textarea>

                <div style="display:flex;justify-content:flex-end;margin-top:20px;gap:10px;">
                    <button type="button" onclick="document.body.removeChild(this.closest('.modal'))" style="background:#444;color:white;padding:8px 16px;border:none;border-radius:6px;">Annuler</button>
                    <button type="submit" style="background:var(--accent-color);color:white;padding:8px 16px;border:none;border-radius:6px;">Enregistrer</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
}
function deleteExpense(id) {
    if (confirm("Voulez-vous vraiment supprimer cette dépense ? Cette action est irréversible.")) {
        const form = document.createElement('form');
        form.method = "POST";
        form.action = "";

        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="${window.csrfToken}">
            <input type="hidden" name="action" value="delete_operational_expense">
            <input type="hidden" name="expense_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}



        // Afficher un message
        function showMessage(message, type) {
            const alertId = type === 'success' ? 'successAlert' : 'errorAlert';
            const messageId = type === 'success' ? 'successMessage' : 'errorMessage';
            
            document.getElementById(messageId).textContent = message;
            document.getElementById(alertId).classList.add('show');
            
            setTimeout(() => {
                document.getElementById(alertId).classList.remove('show');
            }, 5000);
        }

        // Formater un nombre
        function formatNumber(number) {
            return new Intl.NumberFormat('fr-FR').format(number);
        }

        // Validation des formulaires
        document.addEventListener('DOMContentLoaded', function() {
            // Validation du formulaire de paiement personnel
            const staffPaymentForm = document.getElementById('staffPaymentForm');
            if (staffPaymentForm) {
                staffPaymentForm.addEventListener('submit', function(e) {
                    const amount = parseFloat(document.querySelector('input[name="amount"]').value);
                    if (amount <= 0) {
                        e.preventDefault();
                        showMessage('Le montant doit être supérieur à 0', 'error');
                        return false;
                    }
                });
            }

            // Validation du formulaire de dépense
            const expenseForm = document.getElementById('expenseForm');
            if (expenseForm) {
                expenseForm.addEventListener('submit', function(e) {
                    const amount = parseFloat(document.querySelector('input[name="amount"]').value);
                    if (amount <= 0) {
                        e.preventDefault();
                        showMessage('Le montant doit être supérieur à 0', 'error');
                        return false;
                    }
                });
            }

            // Auto-hide alerts après 5 secondes
            const alerts = document.querySelectorAll('.alert.show');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.remove('show');
                }, 5000);
            });
        });

        // Recherche en temps réel dans les tableaux
        function addSearchToTable(tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.placeholder = 'Rechercher...';
            searchInput.className = 'form-control';
            searchInput.style.marginBottom = '15px';
            
            table.parentNode.insertBefore(searchInput, table);
            
            searchInput.addEventListener('keyup', function() {
                const filter = this.value.toLowerCase();
                const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                
                for (let i = 0; i < rows.length; i++) {
                    const row = rows[i];
                    const cells = row.getElementsByTagName('td');
                    let found = false;
                    
                    for (let j = 0; j < cells.length; j++) {
                        if (cells[j].textContent.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                    
                    row.style.display = found ? '' : 'none';
                }
            });
        }

        // Animation au chargement
        document.addEventListener('DOMContentLoaded', function() {
            // Animation des cartes statistiques
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
    <script>
        // Fonction pour ajouter le bouton "Ajouter Personnel" dans le select
        function updateStaffSelect() {
            const staffSelect = document.querySelector('select[name="staff_id"]');
            if (staffSelect) {
                // Vérifier si l'option existe déjà
                const existingOption = staffSelect.querySelector('option[value="add_new"]');
                if (!existingOption) {
                    const addOption = document.createElement('option');
                    addOption.value = 'add_new';
                    addOption.style.background = '#f39c12';
                    addOption.style.color = 'white';
                    addOption.style.fontWeight = 'bold';
                    addOption.textContent = '+ Ajouter un nouveau membre du personnel';
                    staffSelect.appendChild(addOption);
                }
            }
        }

        // Gérer la sélection "Ajouter Personnel"
        document.addEventListener('DOMContentLoaded', function() {
            updateStaffSelect();
            
            const staffSelect = document.querySelector('select[name="staff_id"]');
            if (staffSelect) {
                staffSelect.addEventListener('change', function() {
                    if (this.value === 'add_new') {
                        closeModal('addStaffPaymentModal');
                        openModal('addStaffModal');
                        // Réinitialiser la sélection
                        this.value = '';
                    }
                });
            }
        });

        // Fonction pour recharger la liste du personnel après ajout
        function refreshStaffList() {
            // Cette fonction sera appelée après l'ajout réussi d'un personnel
            location.reload(); // Solution simple, ou vous pouvez faire un appel AJAX
        }
        </script>
</body>
</html>