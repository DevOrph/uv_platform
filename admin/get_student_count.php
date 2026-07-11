<?php
// get_student_count.php - API pour compter les étudiants dans une classe
session_start();
require_once '../includes/db_connect.php';

// Vérifier l'authentification admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Non autorisé']);
    exit();
}

header('Content-Type: application/json');

$class_id = $_GET['class_id'] ?? null;

if (empty($class_id)) {
    // Compter tous les étudiants validés (sans classe)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND blocked = 0");
} else {
    // Compter les étudiants validés dans cette classe
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role = 'student' AND class_id = ? AND blocked = 0");
    $stmt->bind_param("s", $class_id);
}

$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$count = $row['count'] ?? 0;

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'count' => (int)$count
]);
?>