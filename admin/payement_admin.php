<?php
session_start();
require_once '../includes/db_connect.php';
// ===== fix collation / charset to avoid "Illegal mix of collations" =====
$conn->set_charset('utf8mb4');
$conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
$conn->query("SET collation_connection = 'utf8mb4_unicode_ci'");

ini_set('display_errors', 1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Vérification de l'authentification admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

// ===== NOUVEAU: Endpoint AJAX pour récupérer les données d'un reçu =====
if (isset($_GET['action']) && $_GET['action'] === 'get_payment_receipt' && isset($_GET['payment_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $payment_id = intval($_GET['payment_id']);
    
    try {
        $sql = "
            SELECT 
                sp.*,
                u.name AS staff_name,
                u.email AS staff_email,
                u.phone AS staff_phone,
                u.role AS staff_role,
                spt.name AS payment_type_name,
                spt.category,
                admin.name AS processed_by_name
            FROM staff_payments sp
            LEFT JOIN users u ON sp.staff_id = u.id
            LEFT JOIN staff_payment_types spt ON sp.payment_type_id = spt.id
            LEFT JOIN users admin ON sp.processed_by = admin.id
            WHERE sp.id = ?
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $payment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $payment = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'payment' => $payment
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Paiement introuvable'
            ]);
        }
        exit();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
        exit();
    }
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

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

$csrf_token = generateCSRFToken();

// Création des tables
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

$stmts = preg_split('/;\s*[\r\n]+/', $create_tables_sql);
foreach ($stmts as $stmt) {
    $s = trim($stmt);
    if ($s === '') continue;
    try {
        $conn->query($s);
    } catch (Exception $e) {
        error_log("[CREATE_TABLE] " . $e->getMessage());
    }
}

$message = '';
$message_type = '';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Traitement des POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    $receipt_number = 'REC-STAFF-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $payment_date = date('Y-m-d');
    $notes = 'Paiement enregistré automatiquement par administrateur.';

    $insert_payment = "INSERT INTO staff_payments 
        (staff_id, payment_type_id, amount, payment_date, payment_method, description, reference_number, receipt_number, status, processed_by, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'processed', ?, ?)";

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

                        $check_email = "SELECT id FROM users WHERE email = ?";
                        $stmt = $conn->prepare($check_email);
                        $stmt->bind_param("s", $staff_email);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        if ($res && $res->num_rows > 0) {
                            throw new Exception("Un utilisateur avec cette adresse email existe déjà.");
                        }

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

                        $temp_password = 'password123';
                        $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);

                        $insert_staff = "INSERT INTO users (id, name, email, phone, address, password, role, status, created_at) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())";
                        $stmt = $conn->prepare($insert_staff);
                        $stmt->bind_param("sssssss", $new_id, $staff_name, $staff_email, $staff_phone, $staff_address, $hashed_password, $staff_role);

                        if ($stmt->execute()) {
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
                }
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $message_type = 'error';
            error_log("[POST_ACTION] " . $e->getMessage());
        }
    }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($message) && isset($message_type) && $message_type === 'success') {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $message_type;
        $redirect = $_SERVER['REQUEST_URI'];
        header('Location: ' . $redirect);
        exit();
    }
}

}

// Récupérations pour affichage
$staff_data = [];
$payment_types = [];
$recent_payments = [];
$recent_expenses = [];
$total_staff_payments = $total_operational_expenses = $active_staff = $pending_payments = 0;

try {
    $stats_query = "
        SELECT 
            COALESCE(SUM(CASE WHEN spt.category IN ('salary','bonus','allowance','social') THEN sp.amount END), 0) as total_staff_payments,
            (SELECT COALESCE(SUM(amount), 0) FROM operational_expenses 
             WHERE MONTH(expense_date) = MONTH(CURRENT_DATE) 
             AND YEAR(expense_date) = YEAR(CURRENT_DATE)) as total_operational_expenses,
            COUNT(DISTINCT CASE WHEN u.role IN ('teacher','admin') AND u.status = 'active' THEN u.id END) as active_staff,
            COUNT(DISTINCT CASE WHEN sp.status = 'pending' THEN sp.id END) as pending_payments
        FROM users u
        LEFT JOIN staff_payments sp ON u.id = sp.staff_id 
            AND MONTH(sp.payment_date) = MONTH(CURRENT_DATE) 
            AND YEAR(sp.payment_date) = YEAR(CURRENT_DATE)
        LEFT JOIN staff_payment_types spt ON sp.payment_type_id = spt.id
        WHERE u.role IN ('teacher','admin')
    ";
    $stats_result = $conn->query($stats_query);
    if ($stats_result) {
        $stats = $stats_result->fetch_assoc();
        $total_staff_payments = $stats['total_staff_payments'] ?? 0;
        $total_operational_expenses = $stats['total_operational_expenses'] ?? 0;
        $active_staff = $stats['active_staff'] ?? 0;
        $pending_payments = $stats['pending_payments'] ?? 0;
    }

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

    $payment_types_query = "SELECT * FROM staff_payment_types WHERE is_active = 1 ORDER BY category, name";
    $payment_types_result = $conn->query($payment_types_query);
    if ($payment_types_result === false) {
        error_log("[DEBUG] payment_types_query FAILED: " . $conn->error);
        $payment_types = [];
    } else {
        $payment_types = $payment_types_result->fetch_all(MYSQLI_ASSOC);
        error_log("[DEBUG] payment_types_query OK, rows=" . count($payment_types));
    }

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
}

echo "<!-- DEBUG: staff_rows=" . count($staff_data) . " payment_types=" . count($payment_types) . " recent_payments=" . count($recent_payments) . " recent_expenses=" . count($recent_expenses) . " -->";

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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
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

        .alert-success strong {
            font-size: 1.1em;
            display: block;
            margin-top: 10px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 5px;
        }
        
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

        /* Spinner de chargement */
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-left-color: var(--accent-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: white;
            z-index: 10;
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
        <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> show">
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
            </div>
        </div>

        <!-- Modals (suite identique mais j'ajoute le code JavaScript dynamique à la fin) -->
        
        <div class="modal" id="addStaffModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title">
                        <i class="fas fa-user-plus"></i> Nouveau Membre du Personnel
                    </h3>
                    <span class="modal-close" onclick="closeModal('addStaffModal')">&times;</span>
                </div>
                <form method="POST" id="addStaffForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                                <button class="btn btn-small btn-info" onclick="editExpense('<?php echo $expense['id']; ?>')">
                                    <i class="fas fa-edit"></i> Modifier
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
                            <button class="btn btn-small btn-info" onclick="editPaymentType('<?php echo $type['id']; ?>')">
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
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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

    <!-- Modal Reçu DYNAMIQUE -->
    <div class="modal" id="receiptModal">
        <div class="modal-content" style="max-width: 800px; position: relative;">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-receipt"></i> Reçu de Paiement
                </h3>
                <span class="modal-close" onclick="closeModal('receiptModal')">&times;</span>
            </div>
            <div id="receiptContent">
                <!-- Contenu du reçu chargé dynamiquement -->
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
        window.csrfToken = '<?php echo $csrf_token; ?>';

        // Gestion des onglets
        function showTab(tabName) {
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));
            
            const buttons = document.querySelectorAll('.tab-button');
            buttons.forEach(button => button.classList.remove('active'));
            
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
            select.value = staffId;
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

        // ===== FONCTION PRINCIPALE: Charger et afficher un reçu dynamiquement =====
        function printReceipt(paymentId) {
            const content = document.getElementById('receiptContent');
            const modal = document.getElementById('receiptModal');
            
            // Afficher un spinner de chargement
            content.innerHTML = `
                <div class="loading-overlay">
                    <div class="spinner"></div>
                    <p style="margin-top: 15px;">Chargement du reçu...</p>
                </div>
            `;
            
            openModal('receiptModal');

            // Appel AJAX pour récupérer les données du paiement
            fetch('?action=get_payment_receipt&payment_id=' + paymentId)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        content.innerHTML = `
                            <div style="text-align: center; padding: 40px; color: var(--danger-color);">
                                <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                                <h3>Erreur</h3>
                                <p>${data.error || 'Impossible de charger le reçu'}</p>
                            </div>
                        `;
                        return;
                    }

                    // Générer le HTML du reçu avec les données réelles
                    content.innerHTML = generateReceiptHTML(data.payment);
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    content.innerHTML = `
                        <div style="text-align: center; padding: 40px; color: var(--danger-color);">
                            <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                            <h3>Erreur de connexion</h3>
                            <p>Impossible de charger le reçu. Veuillez réessayer.</p>
                        </div>
                    `;
                });
        }

        // Générer le HTML du reçu ISMM (format exact de l'image)
        function generateReceiptHTML(payment) {
            const paymentDate = new Date(payment.payment_date);
            const day = String(paymentDate.getDate()).padStart(2, '0');
            const month = String(paymentDate.getMonth() + 1).padStart(2, '0');
            const year = paymentDate.getFullYear();

            const amount = parseFloat(payment.amount || 0);
            const formattedAmount = amount.toLocaleString('fr-FR', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });

            // Extraire le numéro du reçu (format 000151)
            const receiptNum = payment.receipt_number ? payment.receipt_number.split('-').pop() : '000001';
            const paddedReceiptNum = receiptNum.padStart(6, '0');

            // Déterminer la méthode de paiement
            const isCash = payment.payment_method === 'cash';
            const isAvance = payment.payment_method === 'mobile_money';
            const isCheque = payment.payment_method === 'check';

            return `
                <div class="receipt-preview" id="printableReceipt" style="
                    background: white;
                    color: #000;
                    padding: 30px 40px;
                    max-width: 800px;
                    margin: 0 auto;
                    font-family: 'Courier New', monospace;
                    font-size: 13px;
                    line-height: 1.6;
                    border: 1px solid #ddd;
                ">
                    <!-- En-tête avec logo et informations -->
                    <div style="display: grid; grid-template-columns: 150px 1fr 200px; gap: 20px; margin-bottom: 30px; align-items: start;">
                        <!-- Logo gauche (image en noir et blanc) -->
                        <div style="text-align: center;">
                            <img src="../assets/images/logo_ismm.jpg" alt="Logo ISMM" style="
                                width: 120px; 
                                height: 120px; 
                                object-fit: contain;
                                filter: grayscale(100%) contrast(1.2);
                                -webkit-filter: grayscale(100%) contrast(1.2);
                            " onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div style="width: 120px; height: 120px; border: 2px solid #000; border-radius: 50%; display: none; align-items: center; justify-content: center; margin: 0 auto;">
                                <div style="text-align: center;">
                                    <div style="font-size: 24px; font-weight: bold;">ISMM</div>
                                    <div style="font-size: 9px; margin-top: 5px;">GABON</div>
                                </div>
                            </div>
                            <div style="font-size: 9px; margin-top: 8px; font-weight: bold;">Nous construisons l'avenir</div>
                        </div>

                        <!-- Informations centre -->
                        <div style="text-align: center;">
                            <div style="font-size: 18px; font-weight: bold; margin-bottom: 5px;">INSTITUT DES SCIENCES</div>
                            <div style="font-size: 18px; font-weight: bold; margin-bottom: 10px;">DES MÉTIERS DE LA MER</div>
                            <div style="font-size: 12px; margin-bottom: 3px;">E-mail:ismmgabon1@gmail.com</div>
                            <div style="font-size: 12px; font-weight: bold;">Ambowè à gauche-Charbonnages</div>
                            <div style="font-size: 12px; font-weight: bold;">060256262</div>
                        </div>

                        <!-- Date et BPF droite (date automatique) -->
                        <div style="text-align: right; font-size: 12px;">
                            <div style="margin-bottom: 10px;">( ISMM )</div>
                            <div style="margin-bottom: 20px;">
                                Libreville le <strong>${day}/${month}/${year}</strong>
                            </div>
                            <div>BPF <strong>${formattedAmount}</strong> F CFA</div>
                        </div>
                    </div>

                    <!-- Titre REÇU DE CAISSE -->
                    <div style="text-align: center; margin: 30px 0;">
                        <div style="font-size: 22px; font-weight: bold; text-decoration: underline; display: inline-block;">
                            REÇU DE CAISSE
                        </div>
                        <div style="float: right; color: #c00; font-size: 18px; font-weight: bold; margin-top: -25px;">
                            N° ${paddedReceiptNum}
                        </div>
                    </div>

                    <!-- Ligne M/Mlle -->
                    <div style="margin: 25px 0; border-bottom: 1px dotted #000; padding-bottom: 5px;">
                        <span style="font-weight: bold;">M/Mlle:</span>
                        <span style="margin-left: 10px;">${payment.staff_name || ''}</span>
                    </div>

                    <!-- Ligne Motif -->
                    <div style="margin: 25px 0; border-bottom: 1px dotted #000; padding-bottom: 5px;">
                        <span style="font-weight: bold;">Motif:</span>
                        <span style="margin-left: 10px;">${payment.payment_type_name || ''} ${payment.description ? '- ' + payment.description : ''}</span>
                    </div>

                    <!-- Mode de paiement -->
                    <div style="margin: 25px 0; display: flex; justify-content: center; gap: 40px; align-items: center;">
                        <span style="font-weight: bold;">MODE DE PAIEMENT</span>
                        <label style="display: inline-flex; align-items: center; gap: 5px;">
                            <input type="checkbox" ${isCash ? 'checked' : ''} disabled style="width: 16px; height: 16px;">
                            <span>CASH</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 5px;">
                            <input type="checkbox" ${isAvance ? 'checked' : ''} disabled style="width: 16px; height: 16px;">
                            <span>AVANCE</span>
                        </label>
                        <label style="display: inline-flex; align-items: center; gap: 5px;">
                            <input type="checkbox" ${isCheque ? 'checked' : ''} disabled style="width: 16px; height: 16px;">
                            <span>CHEQUE</span>
                        </label>
                    </div>

                    <!-- Montant en lettres -->
                    <div style="margin: 25px 0; border-bottom: 1px dotted #000; padding-bottom: 5px;">
                        <span style="font-weight: bold;">Montant reçu en Lettres:</span>
                        <span style="margin-left: 10px; text-transform: capitalize;">${numberToWords(amount)} francs CFA</span>
                    </div>

                    <!-- Séparation -->
                    <div style="border-top: 1px dotted #000; margin: 25px 0;"></div>

                    <!-- Avance et Reste à payer -->
                    <div style="margin: 25px 0; display: flex; justify-content: space-between; border-bottom: 1px dotted #000; padding-bottom: 5px;">
                        <div>
                            <span style="font-weight: bold;">Avance:</span>
                            <span style="margin-left: 10px;">${formattedAmount} F CFA</span>
                        </div>
                        <div>
                            <span style="font-weight: bold;">Reste à payer :</span>
                            <span style="margin-left: 10px;">0 F CFA</span>
                        </div>
                    </div>

                    <!-- Pied de page -->
                    <div style="display: flex; justify-content: space-between; margin-top: 50px; padding-top: 20px; border-top: 1px solid #000;">
                        <div style="font-weight: bold; text-decoration: underline;">VISA CLIENT</div>
                        <div style="font-weight: bold; text-decoration: underline;">COMPTABILITÉ</div>
                    </div>

                    <!-- Informations bas de page -->
                    <div style="margin-top: 40px; text-align: center; font-size: 10px; border-top: 1px solid #000; padding-top: 15px;">
                        <div style="font-weight: bold; margin-bottom: 5px;">Institut des Sciences et des Métiers de la Mer (ISMM)</div>
                        <div style="font-weight: bold; margin-bottom: 5px;">Libreville - Charbonnages (Ambowè à gauche)</div>
                        <div>BP.: 17004 lIBREVILLE - E-mail : ismmgabon1@gmail.com -Tél. : +241 60 25 62 62</div>
                    </div>
                </div>
            `;
        }

        // Convertir un nombre en lettres (simplifié)
        function numberToWords(num) {
            if (num === 0) return "zéro";
            
            const units = ["", "un", "deux", "trois", "quatre", "cinq", "six", "sept", "huit", "neuf"];
            const teens = ["dix", "onze", "douze", "treize", "quatorze", "quinze", "seize", "dix-sept", "dix-huit", "dix-neuf"];
            const tens = ["", "", "vingt", "trente", "quarante", "cinquante", "soixante", "soixante-dix", "quatre-vingt", "quatre-vingt-dix"];
            
            function convertHundreds(n) {
                let result = "";
                
                const hundred = Math.floor(n / 100);
                const remainder = n % 100;
                
                if (hundred > 0) {
                    result += hundred === 1 ? "cent" : units[hundred] + " cent";
                    if (remainder > 0) result += " ";
                }
                
                if (remainder >= 20) {
                    const ten = Math.floor(remainder / 10);
                    const unit = remainder % 10;
                    result += tens[ten];
                    if (unit > 0) result += "-" + units[unit];
                } else if (remainder >= 10) {
                    result += teens[remainder - 10];
                } else if (remainder > 0) {
                    result += units[remainder];
                }
                
                return result;
            }
            
            const million = Math.floor(num / 1000000);
            const thousand = Math.floor((num % 1000000) / 1000);
            const hundreds = num % 1000;
            
            let result = "";
            
            if (million > 0) {
                result += convertHundreds(million) + " million" + (million > 1 ? "s" : "");
                if (thousand > 0 || hundreds > 0) result += " ";
            }
            
            if (thousand > 0) {
                result += thousand === 1 ? "mille" : convertHundreds(thousand) + " mille";
                if (hundreds > 0) result += " ";
            }
            
            if (hundreds > 0) {
                result += convertHundreds(hundreds);
            }
            
            return result.trim();
        }

        // Imprimer le contenu du reçu
        function printReceiptContent() {
            const content = document.getElementById('printableReceipt');
            if (!content) {
                showMessage('Aucun reçu à imprimer', 'error');
                return;
            }
            
            const printWindow = window.open('', '_blank');
            
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Reçu de Paiement</title>
                    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
                    <style>
                        body { 
                            font-family: Arial, sans-serif; 
                            margin: 0; 
                            padding: 20px;
                            background: white;
                        }
                        .receipt-preview { 
                            background: white; 
                            color: #333; 
                            max-width: 800px; 
                            margin: 0 auto;
                            padding: 30px;
                        }
                        .receipt-header { 
                            text-align: center; 
                            border-bottom: 2px solid #eee; 
                            padding-bottom: 20px; 
                            margin-bottom: 25px; 
                        }
                        .receipt-logo { 
                            font-size: 48px; 
                            color: #039be5; 
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
                            color: #039be5; 
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
                            background: #039be5; 
                            color: white; 
                            padding: 25px; 
                            border-radius: 8px; 
                            text-align: center; 
                            margin-top: 20px; 
                        }
                        @media print { 
                            body { margin: 0; padding: 10px; } 
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body onload="window.print(); window.close();">
                    ${content.outerHTML}
                </body>
                </html>
            `);
            
            printWindow.document.close();
        }

        // Télécharger le reçu en PDF avec jsPDF (IDENTIQUE au reçu affiché)
        function downloadReceiptPDF() {
            const content = document.getElementById('printableReceipt');
            if (!content) {
                showMessage('Aucun reçu à télécharger', 'error');
                return;
            }

            try {
                // Utiliser html2canvas pour capturer le reçu exactement comme affiché
                if (typeof html2canvas === 'undefined') {
                    // Charger html2canvas dynamiquement
                    const script = document.createElement('script');
                    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
                    script.onload = function() {
                        generatePDFFromCanvas();
                    };
                    document.head.appendChild(script);
                } else {
                    generatePDFFromCanvas();
                }
                
            } catch (error) {
                console.error('Erreur PDF:', error);
                showMessage('Erreur lors de la génération du PDF', 'error');
            }
        }

        function generatePDFFromCanvas() {
            const content = document.getElementById('printableReceipt');
            
            html2canvas(content, {
                scale: 2,
                backgroundColor: '#ffffff',
                logging: false,
                useCORS: true
            }).then(canvas => {
                const { jsPDF } = window.jspdf;
                
                // Calculer les dimensions
                const imgWidth = 210; // A4 width in mm
                const imgHeight = (canvas.height * imgWidth) / canvas.width;
                
                const pdf = new jsPDF('p', 'mm', 'a4');
                const imgData = canvas.toDataURL('image/png');
                
                pdf.addImage(imgData, 'PNG', 0, 0, imgWidth, imgHeight);
                
                // Récupérer le numéro de reçu pour le nom du fichier
                const receiptNum = content.querySelector('.receipt-header div:nth-child(4)')?.textContent || 'RECU';
                const filename = `${receiptNum.replace(/[^a-zA-Z0-9]/g, '_')}_${Date.now()}.pdf`;
                
                pdf.save(filename);
                showMessage('PDF téléchargé avec succès', 'success');
            }).catch(error => {
                console.error('Erreur html2canvas:', error);
                showMessage('Erreur lors de la génération du PDF', 'error');
            });
        }

        // Générer un rapport financier
        function generateReport() {
            showMessage('Génération du rapport en cours...', 'info');
            
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

        function editPaymentType(typeId) {
            showMessage('Fonction de modification en développement', 'info');
        }

        function editExpense(expenseId) {
            showMessage('Fonction de modification en développement', 'info');
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

            const expenseForm = document.getElementById('expenseForm');
            if (expenseForm) {
                expenseForm.addEventListener('submit', function(e) {
                    const amount = parseFloat(this.querySelector('input[name="amount"]').value);
                    if (amount <= 0) {
                        e.preventDefault();
                        showMessage('Le montant doit être supérieur à 0', 'error');
                        return false;
                    }
                });
            }

            const alerts = document.querySelectorAll('.alert.show');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.remove('show');
                }, 5000);
            });
        });
    </script>
</body>
</html>