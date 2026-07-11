<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
if (!isset($data['id'], $data['name'], $data['start_date'], $data['end_date'], $data['school_year'])) {
    echo json_encode(['success' => false, 'message' => 'Données incomplètes.']);
    exit();
}

$id = intval($data['id']);
$name = filter_var($data['name'], FILTER_SANITIZE_STRING);
$start_date = $data['start_date'];
$end_date = $data['end_date'];
$school_year = filter_var($data['school_year'], FILTER_SANITIZE_STRING);

$stmt = $conn->prepare("UPDATE evaluation_periods SET name = ?, start_date = ?, end_date = ?, school_year = ? WHERE id = ?");
$stmt->bind_param("ssssi", $name, $start_date, $end_date, $school_year, $id);
$success = $stmt->execute();

if ($success) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour.']);
}
?>
