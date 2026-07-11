<?php
session_start();
require_once '../includes/db_connect.php';

// Vérifier si l'utilisateur est connecté et est un super administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../pages/login.html");
    exit();
}



// Filtres
$admin_filter = isset($_GET['admin_id']) ? $_GET['admin_id'] : '';
$action_filter = isset($_GET['action_type']) ? $_GET['action_type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Construction de la requête SQL avec filtres
$sql_logs = "SELECT a.id, a.created_at, u.name as admin_name, a.admin_id, a.action_type, 
                    a.description, a.entity_type, a.entity_id, a.ip_address
             FROM admin_logs a
             JOIN users u ON a.admin_id = u.id";

// Conditions de filtrage
$where_conditions = [];
$params = [];
$types = "";

if (!empty($admin_filter)) {
    $where_conditions[] = "a.admin_id = ?";
    $params[] = $admin_filter;
    $types .= "s";
}

if (!empty($action_filter)) {
    $where_conditions[] = "a.action_type = ?";
    $params[] = $action_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $where_conditions[] = "a.created_at >= ?";
    $params[] = $date_from . " 00:00:00";
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "a.created_at <= ?";
    $params[] = $date_to . " 23:59:59";
    $types .= "s";
}

// Ajouter les conditions à la requête
if (!empty($where_conditions)) {
    $sql_logs .= " WHERE " . implode(" AND ", $where_conditions);
}

// Ajouter l'ordre
$sql_logs .= " ORDER BY a.created_at DESC";

// Exécuter la requête
$stmt = $conn->prepare($sql_logs);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Paramètres du fichier CSV
$filename = 'admin_logs_export_' . date('Y-m-d_H-i-s') . '.csv';
$delimiter = ',';

// Générer le fichier CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Créer un gestionnaire de fichier pour écrire
$output = fopen('php://output', 'w');

// Écrire l'en-tête UTF-8 BOM pour Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Écrire l'en-tête CSV
fputcsv($output, [
    'ID', 
    'Date et Heure', 
    'Administrateur',
    'ID Admin',
    'Type d\'action',
    'Description',
    'Type d\'entité',
    'ID Entité',
    'Adresse IP'
], $delimiter);

// Écrire les données
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['id'],
        $row['created_at'],
        $row['admin_name'],
        $row['admin_id'],
        $row['action_type'],
        $row['description'],
        $row['entity_type'] ?? 'N/A',
        $row['entity_id'] ?? 'N/A',
        $row['ip_address']
    ], $delimiter);
}

// Fermer la connexion à la base de données
$conn->close();

// Sortir pour terminer le téléchargement
exit();
?>