<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID manquant']);
    exit();
}

try {
    $conn->begin_transaction();
    
    // Vérifier si des notes sont liées à cette période
    $check = $conn->prepare("SELECT COUNT(*) as count FROM grades WHERE evaluation_period_id = ?");
    $check->bind_param("i", $data['id']);
    $check->execute();
    $result = $check->get_result()->fetch_assoc();
    
    if ($result['count'] > 0) {
        throw new Exception('Cette période contient des notes et ne peut pas être supprimée');
    }
    
    // Suppression de la période
    $stmt = $conn->prepare("DELETE FROM evaluation_periods WHERE id = ?");
    $stmt->bind_param("i", $data['id']);
    $stmt->execute();
    
    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>