
<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/grade_lock.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID manquant']);
    exit();
}

// Vérification que la note appartient à l'enseignant
$check_query = "SELECT id FROM grades WHERE id = ? AND created_by = ?";
$stmt = $conn->prepare($check_query);
$stmt->bind_param("is", $data['id'], $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Note introuvable ou non autorisée']);
    exit();
}

// Verrou : un enseignant ne supprime plus une note trop ancienne
if ($_SESSION['role'] === 'teacher' && grade_is_locked($conn, (int) $data['id'], $_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => grade_lock_message($conn)]);
    exit();
}

try {
    $conn->begin_transaction();

    // Enregistrement dans l'historique
    $log_query = "INSERT INTO grade_history (grade_id, action, performed_by, details) 
                  SELECT id, 'DELETE', ?, CONCAT('Note supprimée: ', grade, '/20')
                  FROM grades WHERE id = ?";
    $stmt = $conn->prepare($log_query);
    $stmt->bind_param("si", $_SESSION['user_id'], $data['id']);
    $stmt->execute();

    // Suppression de la note
    $delete_query = "DELETE FROM grades WHERE id = ? AND created_by = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("is", $data['id'], $_SESSION['user_id']);
    $stmt->execute();

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    error_log("Erreur suppression note: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression']);
}
