<?php
require_once '../includes/db_connect.php';
require_once '../includes/super_admin.php';

// Vérifier si l'utilisateur est connecté et est un administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

// Vérifier les permissions de gestion des paiements
$user_id = $_SESSION['user_id'];
$has_payment_permission = false;

if (is_super_admin($conn, $user_id)) {
    $has_payment_permission = true;
} else {
    $check_permission = "SELECT * FROM payment_permissions 
                        WHERE user_id = ? AND is_active = 1 
                        AND (expires_at IS NULL OR expires_at > NOW())";
    $stmt = $conn->prepare($check_permission);
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $permission_result = $stmt->get_result();
    
    if ($permission_result->num_rows > 0) {
        $has_payment_permission = true;
    }
    $stmt->close();
}

if (!$has_payment_permission) {
    $_SESSION['error'] = "Vous n'avez pas la permission d'accéder à l'historique des paiements.";
    header("Location: admin_dashboard.php");
    exit();
}

// Traitement des requêtes AJAX
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['ajax']) {
        case 'payments':
            echo json_encode(getPaymentsHistory($conn));
            break;
        case 'export':
            exportPaymentsHistory($conn);
            break;
        case 'stats':
            echo json_encode(getPaymentHistoryStats($conn));
            break;
    }
    exit();
}

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF manuelle : /api/ est exempt de verify_csrf() automatique
    $csrf_token    = $_POST['csrf_token'] ?? '';
    $session_token = $_SESSION['csrf_token'] ?? '';
    if (empty($csrf_token) || !hash_equals($session_token, $csrf_token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Token CSRF invalide', 'code' => 403]);
        exit;
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'cancel_payment':
            cancelPayment($conn, $user_id);
            break;
        case 'validate_payment':
            validatePayment($conn, $user_id);
            break;
    }
}

function getPaymentsHistory($conn) {
    // Paramètres de filtrage
    $filters = [];
    $params = [];
    $types = "";
    
    // Filtres de date
    if (!empty($_GET['date_from'])) {
        $filters[] = "sp.payment_date >= ?";
        $params[] = $_GET['date_from'] . ' 00:00:00';
        $types .= "s";
    }
    
    if (!empty($_GET['date_to'])) {
        $filters[] = "sp.payment_date <= ?";
        $params[] = $_GET['date_to'] . ' 23:59:59';
        $types .= "s";
    }
    
    // Filtre par classe
    if (!empty($_GET['class_filter'])) {
        $filters[] = "c.id = ?";
        $params[] = $_GET['class_filter'];
        $types .= "i";
    }
    
    // Filtre par méthode de paiement
    if (!empty($_GET['method_filter'])) {
        $filters[] = "sp.payment_method = ?";
        $params[] = $_GET['method_filter'];
        $types .= "s";
    }
    
    // Filtre par statut
    if (!empty($_GET['status_filter'])) {
        $filters[] = "sp.status = ?";
        $params[] = $_GET['status_filter'];
        $types .= "s";
    }
    
    // Filtre par type de paiement
    if (!empty($_GET['type_filter'])) {
        $filters[] = "sp.payment_type = ?";
        $params[] = $_GET['type_filter'];
        $types .= "s";
    }
    
    // Recherche par nom d'étudiant
    if (!empty($_GET['search'])) {
        $filters[] = "(u.name LIKE ? OR u.id LIKE ?)";
        $search_term = "%" . $_GET['search'] . "%";
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ss";
    }
    
    $where_clause = empty($filters) ? "" : "WHERE " . implode(" AND ", $filters);
    
    // Pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(10, intval($_GET['limit']))) : 25;
    $offset = ($page - 1) * $limit;
    
    // Requête principale
    $query = "SELECT sp.*, u.name as student_name, u.id as student_id,
                     recorder.name as recorder_name, validator.name as validator_name,
                     tf.academic_year, c.name as class_name,
                     CASE 
                        WHEN sp.status = 'validated' THEN 'Validé'
                        WHEN sp.status = 'pending' THEN 'En attente'
                        WHEN sp.status = 'rejected' THEN 'Rejeté'
                        WHEN sp.status = 'cancelled' THEN 'Annulé'
                        ELSE sp.status
                     END as status_text
              FROM student_payments sp
              JOIN users u ON sp.student_id = u.id
              JOIN users recorder ON sp.recorded_by = recorder.id
              LEFT JOIN users validator ON sp.validated_by = validator.id
              JOIN tuition_fees tf ON sp.tuition_fee_id = tf.id
              LEFT JOIN classes c ON u.class_id = c.id
              $where_clause
              ORDER BY sp.payment_date DESC
              LIMIT ? OFFSET ?";
    
    if (!empty($params)) {
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    
    // Compter le total pour la pagination
    $count_query = "SELECT COUNT(*) as total
                   FROM student_payments sp
                   JOIN users u ON sp.student_id = u.id
                   JOIN tuition_fees tf ON sp.tuition_fee_id = tf.id
                   LEFT JOIN classes c ON u.class_id = c.id
                   $where_clause";
    
    if (!empty($filters)) {
        $count_params = array_slice($params, 0, -2); // Enlever limit et offset
        $count_types = substr($types, 0, -2);
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->bind_param($count_types, ...$count_params);
        $count_stmt->execute();
        $total = $count_stmt->get_result()->fetch_assoc()['total'];
        $count_stmt->close();
    } else {
        $total = $conn->query($count_query)->fetch_assoc()['total'];
    }
    
    if (isset($stmt)) {
        $stmt->close();
    }
    
    return [
        'payments' => $payments,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_records' => $total,
            'per_page' => $limit
        ]
    ];
}

function getPaymentHistoryStats($conn) {
    $stats = [];
    
    // Période par défaut : 30 derniers jours
    $date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    
    // Total des paiements sur la période
    $query = "SELECT 
                COUNT(*) as total_transactions,
                SUM(amount_paid) as total_amount,
                AVG(amount_paid) as average_amount,
                payment_method,
                COUNT(*) as method_count
              FROM student_payments 
              WHERE payment_date BETWEEN ? AND ?
              AND status = 'validated'
              GROUP BY payment_method";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $date_from . ' 00:00:00', $date_to . ' 23:59:59');
    $stmt->execute();
    $result = $stmt->get_result();
    
    $methods = [];
    $total_transactions = 0;
    $total_amount = 0;
    
    while ($row = $result->fetch_assoc()) {
        $methods[] = $row;
        $total_transactions += $row['method_count'];
        $total_amount += $row['total_amount'];
    }
    
    // Statistiques par jour
    $daily_query = "SELECT 
                      DATE(payment_date) as payment_day,
                      COUNT(*) as daily_count,
                      SUM(amount_paid) as daily_amount
                    FROM student_payments 
                    WHERE payment_date BETWEEN ? AND ?
                    AND status = 'validated'
                    GROUP BY DATE(payment_date)
                    ORDER BY payment_day";
    
    $stmt2 = $conn->prepare($daily_query);
    $stmt2->bind_param("ss", $date_from . ' 00:00:00', $date_to . ' 23:59:59');
    $stmt2->execute();
    $daily_result = $stmt2->get_result();
    
    $daily_stats = [];
    while ($row = $daily_result->fetch_assoc()) {
        $daily_stats[] = $row;
    }
    
    $stmt->close();
    $stmt2->close();
    
    return [
        'total_transactions' => $total_transactions,
        'total_amount' => $total_amount,
        'average_amount' => $total_transactions > 0 ? $total_amount / $total_transactions : 0,
        'methods' => $methods,
        'daily_stats' => $daily_stats,
        'period' => ['from' => $date_from, 'to' => $date_to]
    ];
}

function cancelPayment($conn, $user_id) {
    try {
        $payment_id = intval($_POST['payment_id']);
        
        if (!is_super_admin($conn, $user_id)) {
            throw new Exception("Seul un super administrateur peut annuler un paiement");
        }
        
        // Vérifier que le paiement existe
        $check_query = "SELECT * FROM student_payments WHERE id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("i", $payment_id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$payment) {
            throw new Exception("Paiement non trouvé");
        }
        
        if ($payment['status'] === 'cancelled') {
            throw new Exception("Ce paiement est déjà annulé");
        }
        
        // Annuler le paiement
        $update_query = "UPDATE student_payments SET status = 'cancelled' WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("i", $payment_id);
        
        if ($stmt->execute()) {
            // Ajouter à l'historique
            $history_query = "INSERT INTO payment_history (payment_id, action, performed_by, details) 
                             VALUES (?, 'CANCEL', ?, ?)";
            $details = "Paiement annulé par " . $user_id;
            $stmt_history = $conn->prepare($history_query);
            $stmt_history->bind_param("iss", $payment_id, $user_id, $details);
            $stmt_history->execute();
            $stmt_history->close();
            
            $_SESSION['success'] = "Paiement annulé avec succès";
        } else {
            throw new Exception("Erreur lors de l'annulation");
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
    
    header("Location: payment_history.php");
    exit();
}

function validatePayment($conn, $user_id) {
    try {
        $payment_id = intval($_POST['payment_id']);
        
        // Vérifier les permissions
        if (!is_super_admin($conn, $user_id)) {
            $check_permission = "SELECT can_validate FROM payment_permissions 
                                WHERE user_id = ? AND is_active = 1 
                                AND (expires_at IS NULL OR expires_at > NOW())";
            $stmt = $conn->prepare($check_permission);
            $stmt->bind_param("s", $user_id);
            $stmt->execute();
            $permission = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if (!$permission || !$permission['can_validate']) {
                throw new Exception("Vous n'avez pas la permission de valider les paiements");
            }
        }
        
        // Vérifier que le paiement existe et est en attente
        $check_query = "SELECT * FROM student_payments WHERE id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("i", $payment_id);
        $stmt->execute();
        $payment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$payment) {
            throw new Exception("Paiement non trouvé");
        }
        
        if ($payment['status'] === 'validated') {
            throw new Exception("Ce paiement est déjà validé");
        }
        
        if ($payment['status'] === 'cancelled') {
            throw new Exception("Impossible de valider un paiement annulé");
        }
        
        // Valider le paiement
        $update_query = "UPDATE student_payments 
                        SET status = 'validated', 
                            validated_by = ?,
                            validated_at = NOW()
                        WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("si", $user_id, $payment_id);
        
        if ($stmt->execute()) {
            // Ajouter à l'historique
            $history_query = "INSERT INTO payment_history (payment_id, action, performed_by, details) 
                             VALUES (?, 'VALIDATE', ?, ?)";
            $details = "Paiement validé par " . $user_id;
            $stmt_history = $conn->prepare($history_query);
            $stmt_history->bind_param("iss", $payment_id, $user_id, $details);
            $stmt_history->execute();
            $stmt_history->close();
            
            $_SESSION['success'] = "Paiement validé avec succès";
        } else {
            throw new Exception("Erreur lors de la validation");
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur: " . $e->getMessage();
    }
    
    header("Location: payment_history.php");
    exit();
}

function exportPaymentsHistory($conn) {
    // Construire les mêmes filtres que getPaymentsHistory mais sans LIMIT pour exporter l'intégralité
    $filters = [];
    $params = [];
    $types = "";

    if (!empty($_GET['date_from'])) {
        $filters[] = "sp.payment_date >= ?";
        $params[] = $_GET['date_from'] . ' 00:00:00';
        $types .= "s";
    }

    if (!empty($_GET['date_to'])) {
        $filters[] = "sp.payment_date <= ?";
        $params[] = $_GET['date_to'] . ' 23:59:59';
        $types .= "s";
    }

    if (!empty($_GET['class_filter'])) {
        $filters[] = "c.id = ?";
        $params[] = $_GET['class_filter'];
        $types .= "i";
    }

    if (!empty($_GET['method_filter'])) {
        $filters[] = "sp.payment_method = ?";
        $params[] = $_GET['method_filter'];
        $types .= "s";
    }

    if (!empty($_GET['status_filter'])) {
        $filters[] = "sp.status = ?";
        $params[] = $_GET['status_filter'];
        $types .= "s";
    }

    if (!empty($_GET['type_filter'])) {
        $filters[] = "sp.payment_type = ?";
        $params[] = $_GET['type_filter'];
        $types .= "s";
    }

    if (!empty($_GET['search'])) {
        $filters[] = "(u.name LIKE ? OR u.id LIKE ?)";
        $search_term = "%" . $_GET['search'] . "%";
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ss";
    }

    $where_clause = empty($filters) ? "" : "WHERE " . implode(" AND ", $filters);

    $query = "SELECT sp.*, u.name as student_name, u.id as student_id,
                     recorder.name as recorder_name, validator.name as validator_name,
                     tf.academic_year, c.name as class_name
              FROM student_payments sp
              JOIN users u ON sp.student_id = u.id
              JOIN users recorder ON sp.recorded_by = recorder.id
              LEFT JOIN users validator ON sp.validated_by = validator.id
              JOIN tuition_fees tf ON sp.tuition_fee_id = tf.id
              LEFT JOIN classes c ON u.class_id = c.id
              $where_clause
              ORDER BY sp.payment_date DESC";

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        // Fallback: send a plain error
        header('Content-Type: text/plain; charset=utf-8');
        echo "Erreur préparation requête d'export.";
        exit();
    }

    if (!empty($params)) {
        // bind_param requires references for call_user_func_array on some PHP versions
        $bind_names = [];
        $bind_names[] = $types;
        for ($i = 0; $i < count($params); $i++) {
            $bind_names[] = &$params[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind_names);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    // Envoyer les headers CSV (remplace le Content-Type JSON précédemment envoyé)
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payments_export_' . date('Ymd_His') . '.csv"');

    $output = fopen('php://output', 'w');
    // BOM UTF-8 pour garantir les accents dans Excel
    fprintf($output, "\xEF\xBB\xBF");

    // En
    $stmt->close();
    exit();
}

// Récupérer les données pour l'affichage
$history_data = getPaymentsHistory($conn);
$payments = $history_data['payments'];
$pagination = $history_data['pagination'];
$stats = getPaymentHistoryStats($conn);

// Récupérer les classes pour les filtres
$classes_query = "SELECT id, name FROM classes ORDER BY name";
$classes_result = $conn->query($classes_query);
$classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $classes[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des Paiements - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js" rel="preload" as="script">
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

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
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
        .btn-small { padding: 8px 12px; font-size: 12px; }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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
            background: var(--accent-color);
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

        .filters {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }

        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group label {
            font-size: 12px;
            color: #ccc;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-control {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            font-size: 14px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 2px rgba(3, 155, 229, 0.2);
        }

        .table-container {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
            overflow-x: auto;
            margin-bottom: 30px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--accent-color);
            position: sticky;
            top: 0;
        }

        .table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-validated { background: var(--success-color); color: white; }
        .status-pending { background: var(--warning-color); color: white; }
        .status-rejected { background: var(--danger-color); color: white; }
        .status-cancelled { background: #6c757d; color: white; }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }

        .pagination button {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-light);
            border-radius: 6px;
            cursor: pointer;
        }

        .pagination button:hover:not(:disabled) {
            background: var(--accent-color);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination .current {
            background: var(--accent-color);
            border-color: var(--accent-color);
        }

        .chart-container {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }

        @media (max-width: 768px) {
            .container { padding: 20px 15px; }
            .page-header { flex-direction: column; align-items: stretch; }
            .page-actions { justify-content: center; }
            .stats-grid { grid-template-columns: 1fr; }
            .filters-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include '../includes/header_admin.php'; ?>

    <div class="container">
        <!-- Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-history"></i>
                Historique des Paiements
            </h2>
            <div class="page-actions">
                <button class="btn btn-success" onclick="exportData()">
                    <i class="fas fa-download"></i> Exporter
                </button>
                <button class="btn btn-primary" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i> Actualiser
                </button>
                <a href="payment_dashboard.php" class="btn btn-warning">
                    <i class="fas fa-arrow-left"></i> Retour
                </a>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-title">Total Transactions</span>
                    <i class="stat-icon fas fa-receipt"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
                <div style="font-size: 12px; color: #ccc;">
                    Sur la période sélectionnée
                </div>
            </div>

            <div class="stat-card info">
                <div class="stat-header">
                    <span class="stat-title">Montant Total</span>
                    <i class="stat-icon fas fa-coins"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['total_amount']); ?> FCFA</div>
                <div style="font-size: 12px; color: #ccc;">
                    Validé et encaissé
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-header">
                    <span class="stat-title">Montant Moyen</span>
                    <i class="stat-icon fas fa-chart-line"></i>
                </div>
                <div class="stat-value"><?php echo number_format($stats['average_amount']); ?> FCFA</div>
                <div style="font-size: 12px; color: #ccc;">
                    Par transaction
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-header">
                    <span class="stat-title">Période</span>
                    <i class="stat-icon fas fa-calendar"></i>
                </div>
                <div style="font-size: 16px; font-weight: bold; margin-bottom: 5px;">
                    <?php echo date('d/m', strtotime($stats['period']['from'])); ?> - 
                    <?php echo date('d/m/Y', strtotime($stats['period']['to'])); ?>
                </div>
                <div style="font-size: 12px; color: #ccc;">
                    Derniers 30 jours par défaut
                </div>
            </div>
        </div>

        <!-- Graphique des paiements -->
        <div class="chart-container">
            <h3 style="margin-bottom: 20px; color: var(--accent-color);">
                <i class="fas fa-chart-area"></i> Évolution des Paiements
            </h3>
            <canvas id="paymentsChart" height="100"></canvas>
        </div>

        <!-- Filtres -->
        <div class="filters">
            <form method="GET" id="filterForm">
                <div class="filters-row">
                    <div class="form-group">
                        <label>Date de début</label>
                        <input type="date" class="form-control" name="date_from"
                               value="<?php echo htmlspecialchars($_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days')), ENT_QUOTES); ?>">
                    </div>
                    <div class="form-group">
                        <label>Date de fin</label>
                        <input type="date" class="form-control" name="date_to"
                               value="<?php echo htmlspecialchars($_GET['date_to'] ?? date('Y-m-d'), ENT_QUOTES); ?>">
                    </div>
                    <div class="form-group">
                        <label>Classe</label>
                        <select class="form-control" name="class_filter">
                            <option value="">Toutes les classes</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" 
                                    <?php echo ($_GET['class_filter'] ?? '') == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Méthode</label>
                        <select class="form-control" name="method_filter">
                            <option value="">Toutes méthodes</option>
                            <option value="cash" <?php echo ($_GET['method_filter'] ?? '') == 'cash' ? 'selected' : ''; ?>>Espèces</option>
                            <option value="bank_transfer" <?php echo ($_GET['method_filter'] ?? '') == 'bank_transfer' ? 'selected' : ''; ?>>Virement</option>
                            <option value="mobile_money" <?php echo ($_GET['method_filter'] ?? '') == 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                            <option value="check" <?php echo ($_GET['method_filter'] ?? '') == 'check' ? 'selected' : ''; ?>>Chèque</option>
                            <option value="other" <?php echo ($_GET['method_filter'] ?? '') == 'other' ? 'selected' : ''; ?>>Autre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Statut</label>
                        <select class="form-control" name="status_filter">
                            <option value="">Tous statuts</option>
                            <option value="validated" <?php echo ($_GET['status_filter'] ?? '') == 'validated' ? 'selected' : ''; ?>>Validé</option>
                            <option value="pending" <?php echo ($_GET['status_filter'] ?? '') == 'pending' ? 'selected' : ''; ?>>En attente</option>
                            <option value="rejected" <?php echo ($_GET['status_filter'] ?? '') == 'rejected' ? 'selected' : ''; ?>>Rejeté</option>
                            <option value="cancelled" <?php echo ($_GET['status_filter'] ?? '') == 'cancelled' ? 'selected' : ''; ?>>Annulé</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select class="form-control" name="type_filter">
                            <option value="">Tous types</option>
                            <option value="registration" <?php echo ($_GET['type_filter'] ?? '') == 'registration' ? 'selected' : ''; ?>>Inscription</option>
                            <option value="tuition" <?php echo ($_GET['type_filter'] ?? '') == 'tuition' ? 'selected' : ''; ?>>Scolarité</option>
                            <option value="insurance" <?php echo ($_GET['type_filter'] ?? '') == 'insurance' ? 'selected' : ''; ?>>Assurance</option>
                            <option value="library" <?php echo ($_GET['type_filter'] ?? '') == 'library' ? 'selected' : ''; ?>>Bibliothèque</option>
                            <option value="practical" <?php echo ($_GET['type_filter'] ?? '') == 'practical' ? 'selected' : ''; ?>>TP</option>
                            <option value="installment" <?php echo ($_GET['type_filter'] ?? '') == 'installment' ? 'selected' : ''; ?>>Échéance</option>
                            <option value="other" <?php echo ($_GET['type_filter'] ?? '') == 'other' ? 'selected' : ''; ?>>Autre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Rechercher</label>
                        <input type="text" class="form-control" name="search" 
                               placeholder="Nom ou ID étudiant" 
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Filtrer
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tableau des paiements -->
        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="color: var(--accent-color); margin: 0;">
                    <i class="fas fa-list"></i> Liste des Paiements
                </h3>
                <div style="margin-left: auto;">
                    <select class="form-control" style="width: auto; display: inline-block;" 
                            onchange="changePageSize(this.value)">
                        <option value="25" <?php echo ($_GET['limit'] ?? 25) == 25 ? 'selected' : ''; ?>>25 par page</option>
                        <option value="50" <?php echo ($_GET['limit'] ?? 25) == 50 ? 'selected' : ''; ?>>50 par page</option>
                        <option value="100" <?php echo ($_GET['limit'] ?? 25) == 100 ? 'selected' : ''; ?>>100 par page</option>
                    </select>
                </div>
            </div>
            
            <table class="table">
                <thead>
                    <tr>
                        <th>Date & Heure</th>
                        <th>Étudiant</th>
                        <th>Classe</th>
                        <th>Montant</th>
                        <th>Méthode</th>
                        <th>Type</th>
                        <th>Référence</th>
                        <th>Reçu</th>
                        <th>Enregistré par</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 40px; color: #ccc;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 10px;"></i><br>
                            Aucun paiement trouvé pour les critères sélectionnés
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td>
                                <strong><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></strong><br>
                                <small style="color: #ccc;"><?php echo date('H:i', strtotime($payment['payment_date'])); ?></small>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($payment['student_name']); ?></strong><br>
                                    <small style="color: #ccc;"><?php echo htmlspecialchars($payment['student_id']); ?></small>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($payment['class_name'] ?? 'Non assigné'); ?></td>
                            <td>
                                <strong style="color: var(--success-color);">
                                    <?php echo number_format($payment['amount_paid']); ?> FCFA
                                </strong>
                            </td>
                            <td>
                                <?php
                                $method_icons = [
                                    'cash' => 'fas fa-money-bill-wave',
                                    'bank_transfer' => 'fas fa-university',
                                    'mobile_money' => 'fas fa-mobile-alt',
                                    'check' => 'fas fa-money-check',
                                    'other' => 'fas fa-question-circle'
                                ];
                                $method_names = [
                                    'cash' => 'Espèces',
                                    'bank_transfer' => 'Virement',
                                    'mobile_money' => 'Mobile Money',
                                    'check' => 'Chèque',
                                    'other' => 'Autre'
                                ];
                                ?>
                                <i class="<?php echo $method_icons[$payment['payment_method']] ?? 'fas fa-question'; ?>"></i>
                                <?php echo $method_names[$payment['payment_method']] ?? $payment['payment_method']; ?>
                            </td>
                            <td>
                                <?php
                                $type_names = [
                                    'registration' => 'Inscription',
                                    'tuition' => 'Scolarité',
                                    'insurance' => 'Assurance',
                                    'library' => 'Bibliothèque',
                                    'practical' => 'TP',
                                    'installment' => 'Échéance',
                                    'other' => 'Autre'
                                ];
                                echo $type_names[$payment['payment_type']] ?? $payment['payment_type'];
                                ?>
                                <?php if ($payment['installment_number']): ?>
                                    <br><small style="color: #ccc;">N° <?php echo $payment['installment_number']; ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($payment['reference_number']): ?>
                                    <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px;">
                                        <?php echo htmlspecialchars($payment['reference_number']); ?>
                                    </code>
                                <?php else: ?>
                                    <span style="color: #ccc;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($payment['receipt_number']): ?>
                                    <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px;">
                                        <?php echo htmlspecialchars($payment['receipt_number']); ?>
                                    </code>
                                <?php else: ?>
                                    <span style="color: #ccc;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>
                                    <strong><?php echo htmlspecialchars($payment['recorder_name']); ?></strong>
                                    <?php if ($payment['validator_name'] && $payment['validator_name'] !== $payment['recorder_name']): ?>
                                        <br><small style="color: #ccc;">Validé par: <?php echo htmlspecialchars($payment['validator_name']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $payment['status']; ?>">
                                    <?php echo $payment['status_text']; ?>
                                </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px;">
                                    <button class="btn btn-small btn-primary" 
                                            onclick="viewPaymentDetails(<?php echo $payment['id']; ?>)"
                                            title="Voir détails">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <?php if ($payment['status'] === 'validated'): ?>
                                    <button class="btn btn-small btn-success" 
                                            onclick="printReceipt(<?php echo $payment['id']; ?>)"
                                            title="Imprimer reçu">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if (is_super_admin($conn, $user_id) && $payment['status'] !== 'cancelled'): ?>
                                    <button class="btn btn-small btn-danger" 
                                            onclick="cancelPayment(<?php echo $payment['id']; ?>)"
                                            title="Annuler paiement">
                                        <i class="fas fa-times"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($pagination['total_pages'] > 1): ?>
            <div class="pagination">
                <button onclick="goToPage(1)" <?php echo $pagination['current_page'] == 1 ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-double-left"></i>
                </button>
                <button onclick="goToPage(<?php echo $pagination['current_page'] - 1; ?>)" 
                        <?php echo $pagination['current_page'] == 1 ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-left"></i>
                </button>
                
                <?php
                $start = max(1, $pagination['current_page'] - 2);
                $end = min($pagination['total_pages'], $pagination['current_page'] + 2);
                
                for ($i = $start; $i <= $end; $i++):
                ?>
                <button onclick="goToPage(<?php echo $i; ?>)" 
                        class="<?php echo $i == $pagination['current_page'] ? 'current' : ''; ?>">
                    <?php echo $i; ?>
                </button>
                <?php endfor; ?>
                
                <button onclick="goToPage(<?php echo $pagination['current_page'] + 1; ?>)" 
                        <?php echo $pagination['current_page'] == $pagination['total_pages'] ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-right"></i>
                </button>
                <button onclick="goToPage(<?php echo $pagination['total_pages']; ?>)" 
                        <?php echo $pagination['current_page'] == $pagination['total_pages'] ? 'disabled' : ''; ?>>
                    <i class="fas fa-angle-double-right"></i>
                </button>
            </div>
            
            <div style="text-align: center; margin-top: 15px; color: #ccc; font-size: 14px;">
                Affichage de <?php echo (($pagination['current_page'] - 1) * $pagination['per_page']) + 1; ?> à 
                <?php echo min($pagination['current_page'] * $pagination['per_page'], $pagination['total_records']); ?> 
                sur <?php echo $pagination['total_records']; ?> enregistrements
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Configuration du graphique
        const dailyStats = <?php echo json_encode($stats['daily_stats']); ?>;
        
        const ctx = document.getElementById('paymentsChart').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: dailyStats.map(stat => {
                    const date = new Date(stat.payment_day);
                    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
                }),
                datasets: [
                    {
                        label: 'Nombre de paiements',
                        data: dailyStats.map(stat => stat.daily_count),
                        borderColor: '#039be5',
                        backgroundColor: 'rgba(3, 155, 229, 0.1)',
                        yAxisID: 'y'
                    },
                    {
                        label: 'Montant (FCFA)',
                        data: dailyStats.map(stat => stat.daily_amount),
                        borderColor: '#2ecc71',
                        backgroundColor: 'rgba(46, 204, 113, 0.1)',
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        labels: {
                            color: '#ffffff'
                        }
                    },
                    title: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            color: '#ffffff'
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        ticks: {
                            color: '#ffffff'
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        ticks: {
                            color: '#ffffff',
                            callback: function(value) {
                                return new Intl.NumberFormat('fr-FR').format(value) + ' FCFA';
                            }
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });

        // Fonctions JavaScript
        function goToPage(page) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.location.href = url.toString();
        }

        function changePageSize(limit) {
            const url = new URL(window.location);
            url.searchParams.set('limit', limit);
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }

        function exportData() {
            const url = new URL(window.location);
            url.searchParams.set('ajax', 'export');
            window.open(url.toString(), '_blank');
        }

        function refreshData() {
            window.location.reload();
        }

        function viewPaymentDetails(paymentId) {
            alert(`Détails du paiement #${paymentId} - Fonctionnalité à implémenter`);
        }

        function printReceipt(paymentId) {
            if (confirm(`Imprimer le reçu pour le paiement #${paymentId} ?`)) {
                alert(`Impression du reçu #${paymentId} en cours...`);
            }
        }

        function cancelPayment(paymentId) {
            if (confirm('Êtes-vous sûr de vouloir annuler ce paiement ?\n\nCette action est irréversible.')) {
                const form = document.createElement('form');
                form.method = 'POST';

                const csrfInput = document.createElement('input');
                csrfInput.type  = 'hidden';
                csrfInput.name  = 'csrf_token';
                csrfInput.value = document.querySelector('meta[name="csrf-token"]')?.content
                                  || window.CSRF_TOKEN || '';
                form.appendChild(csrfInput);

                const actionInput = document.createElement('input');
                actionInput.type  = 'hidden';
                actionInput.name  = 'action';
                actionInput.value = 'cancel_payment';
                form.appendChild(actionInput);

                const paymentInput = document.createElement('input');
                paymentInput.type  = 'hidden';
                paymentInput.name  = 'payment_id';
                paymentInput.value = paymentId;
                form.appendChild(paymentInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Gestion des messages d'alerte avec auto-disparition
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });

        console.log('Page historique des paiements initialisée');
    </script>
</body>
</html>