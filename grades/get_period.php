<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error' => 'Accès refusé']);
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'ID manquant']);
    exit();
}

$id = intval($_GET['id']);

$stmt = $conn->prepare("SELECT * FROM evaluation_periods WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();

echo json_encode($data);
?>
