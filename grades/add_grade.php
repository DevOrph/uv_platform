<?php
session_start();
require_once '../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification des droits
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
        echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
        exit();
    }

    // Validation des données reçues
    $required_fields = ['student_id', 'course_id', 'evaluation_type_id', 'grade'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            echo json_encode(['success' => false, 'message' => 'Tous les champs obligatoires doivent être remplis']);
            exit();
        }
    }

    // Nettoyage et validation des données
    $student_id = filter_var($_POST['student_id'], FILTER_SANITIZE_STRING);
    $course_id = filter_var($_POST['course_id'], FILTER_VALIDATE_INT);
    $evaluation_type_id = filter_var($_POST['evaluation_type_id'], FILTER_VALIDATE_INT);
    $grade = filter_var($_POST['grade'], FILTER_VALIDATE_FLOAT);
    $comment = isset($_POST['comment']) ? filter_var($_POST['comment'], FILTER_SANITIZE_STRING) : '';
    $created_by = $_SESSION['user_id'];

    // Validation de la note
    if ($grade < 0 || $grade > 20) {
        echo json_encode(['success' => false, 'message' => 'La note doit être comprise entre 0 et 20']);
        exit();
    }

    // Vérification de l'existence de l'étudiant et du cours
    $check_query = "SELECT u.id as student_exists, c.id as course_exists 
                   FROM users u, courses c 
                   WHERE u.id = ? AND c.id = ? AND u.role = 'student'";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("si", $student_id, $course_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();

    if (!$check_result['student_exists'] || !$check_result['course_exists']) {
        echo json_encode(['success' => false, 'message' => 'Étudiant ou cours invalide']);
        exit();
    }

// Récupération de la période d'évaluation actuelle (une seule fois ici)
$period_query = "SELECT id FROM evaluation_periods 
                WHERE CURRENT_DATE BETWEEN start_date AND end_date";
$period_result = $conn->query($period_query);
$period = $period_result->fetch_assoc();

if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Aucune période d\'évaluation active']);
    exit();
}

$period_id = $period['id'];


    try {
        // Début de la transaction
        $conn->begin_transaction();

        // Insertion de la note
        $insert_stmt = $conn->prepare("INSERT INTO grades (student_id, course_id, evaluation_type_id, 
                                    evaluation_period_id, grade, comment, created_by) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        $insert_stmt->bind_param("siiiiss", $student_id, $course_id, $evaluation_type_id, 
                               $period_id, $grade, $comment, $created_by);

        if (!$insert_stmt->execute()) {
            throw new Exception('Erreur lors de l\'insertion de la note');
        }

        // Calcul de la nouvelle moyenne
        $average_query = "CALL calculate_student_average(?, ?, ?)";
        $average_stmt = $conn->prepare($average_query);
        $average_stmt->bind_param("sii", $student_id, $course_id, $period_id);
        
        if (!$average_stmt->execute()) {
            throw new Exception('Erreur lors du calcul de la moyenne');
        }

        // Validation de la transaction
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Note ajoutée avec succès']);

    } catch (Exception $e) {
        // Annulation de la transaction en cas d'erreur
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
}
?>