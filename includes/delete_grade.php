<?php
session_start();
require_once 'db_connect.php';
require_once 'grade_lock.php';
require_once 'super_admin.php';

// Vérification des droits d'accès
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Fonction pour vérifier les permissions d'examen
function canDeleteExamGrade($conn, $user_id) {
    if (is_super_admin($conn, $user_id)) {
        return true;
    }
    
    $query = "SELECT id FROM exam_permissions 
              WHERE user_id = ? AND is_active = 1 
              AND (expires_at IS NULL OR expires_at > NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit();
}

// Récupérer l'ID de la note à supprimer
$grade_id = isset($_POST['grade_id']) ? intval($_POST['grade_id']) : 0;

if ($grade_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID de note invalide']);
    exit();
}

// Verrou : un enseignant ne supprime plus une note trop ancienne
if ($user_role === 'teacher' && grade_is_locked($conn, $grade_id, $user_role)) {
    echo json_encode(['success' => false, 'message' => grade_lock_message($conn)]);
    exit();
}

try {
    $conn->begin_transaction();
    
    // Récupérer les informations de la note avec vérifications de sécurité
    $query = "SELECT g.*, c.teacher_id, et.name as evaluation_type_name, u.name as student_name
              FROM grades g
              JOIN courses c ON g.course_id = c.id
              JOIN evaluation_types et ON g.evaluation_type_id = et.id
              JOIN users u ON g.student_id = u.id
              WHERE g.id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $grade_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Note introuvable');
    }
    
    $grade = $result->fetch_assoc();
    
    // Vérification des permissions selon le rôle
    if ($user_role === 'teacher') {
        // Un enseignant ne peut supprimer que :
        // 1. Les notes qu'il a créées OU
        // 2. Les notes des cours qu'il enseigne
        if ($grade['created_by'] !== $user_id && $grade['teacher_id'] !== $user_id) {
            throw new Exception('Vous ne pouvez supprimer que vos propres notes ou celles de vos cours');
        }
    }
    
    // Vérification spéciale pour les notes d'examen
    if ($grade['evaluation_type_id'] == 2) { // Type "Examen"
        if (!canDeleteExamGrade($conn, $user_id)) {
            throw new Exception('Vous n\'avez pas l\'autorisation de supprimer les notes d\'examen');
        }
    }
    
    // Vérification de la date de création (optionnel - limite dans le temps)
    $created_at = strtotime($grade['created_at']);
    $now = time();
    $time_limit = 24 * 60 * 60; // 24 heures en secondes
    
    // Seuls les admins peuvent supprimer des notes anciennes
    if ($user_role === 'teacher' && ($now - $created_at) > $time_limit) {
        throw new Exception('Vous ne pouvez supprimer une note que dans les 24h suivant sa création');
    }
    
    // Enregistrement dans l'historique avant suppression
    try {
        // Définir la variable pour le trigger de suppression
        $conn->query("SET @deleted_by = '$user_id'");
        
        // Enregistrement manuel si la table grade_history n'a pas de trigger
        $history_query = "INSERT INTO grade_history 
                         (grade_id, action, performed_by, details, old_value) 
                         VALUES (?, 'DELETE', ?, ?, ?)";
        
        $details = "Note supprimée: {$grade['grade']}/20 - {$grade['evaluation_type_name']} - Étudiant: {$grade['student_name']}";
        $old_value = json_encode([
            'grade' => $grade['grade'],
            'evaluation_type_id' => $grade['evaluation_type_id'],
            'comment' => $grade['comment'],
            'student_id' => $grade['student_id'],
            'course_id' => $grade['course_id']
        ]);
        
        $stmt = $conn->prepare($history_query);
        $stmt->bind_param("isss", $grade_id, $user_id, $details, $old_value);
        $stmt->execute();
        
    } catch (Exception $e) {
        // Si l'historique échoue, on continue quand même (table peut ne pas exister)
        error_log("Erreur historique suppression: " . $e->getMessage());
    }
    
    // Suppression de la note
    $delete_query = "DELETE FROM grades WHERE id = ?";
    $stmt = $conn->prepare($delete_query);
    $stmt->bind_param("i", $grade_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Erreur lors de la suppression de la note');
    }
    
    if ($stmt->affected_rows === 0) {
        throw new Exception('Aucune note supprimée - elle pourrait avoir déjà été supprimée');
    }
    
    // Log de l'action dans les logs admin si disponible
    try {
        if (file_exists('utils/admin_logger.php')) {
            require_once 'utils/admin_logger.php';
            logAdminAction(
                $user_id, 
                'DELETE_GRADE', 
                "Suppression de la note ID: $grade_id - Note: {$grade['grade']}/20", 
                $_SERVER['REMOTE_ADDR'],
                $grade_id,
                'GRADE'
            );
        }
    } catch (Exception $e) {
        // Continuer même si le log échoue
        error_log("Erreur log admin: " . $e->getMessage());
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Note supprimée avec succès',
        'deleted_grade' => [
            'id' => $grade_id,
            'grade' => $grade['grade'],
            'student_name' => $grade['student_name'],
            'evaluation_type' => $grade['evaluation_type_name']
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("Erreur suppression note: " . $e->getMessage());
    
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>