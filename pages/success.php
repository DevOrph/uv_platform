<?php
session_start();
require_once '../includes/db_connect.php';

// Vérification des droits d'accès
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    header('Location: ../pages/login.php');
    exit();
}

// Vérification des données POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['course_id']) || empty($_POST['grades'])) {
    header('Location: ../pages/error.php');
    exit();
}

$course_id = intval($_POST['course_id']);
$grades = $_POST['grades'];
$evaluation_types = $_POST['evaluation_types'];
$comments = $_POST['comments'];
$user_id = $_SESSION['user_id'];

// Préparation de l'insertion des notes
$stmt = $conn->prepare(
    "INSERT INTO grades (student_id, course_id, evaluation_type_id, grade, comment, created_by, created_at) 
     VALUES (?, ?, ?, ?, ?, ?, NOW())"
);

if (!$stmt) {
    die("Erreur de préparation de la requête : " . $conn->error);
}

$conn->begin_transaction();

try {
    foreach ($grades as $student_id => $grade) {
        $evaluation_type_id = intval($evaluation_types[$student_id]);
        $comment = isset($comments[$student_id]) ? $comments[$student_id] : null;

        $stmt->bind_param("iiidss", $student_id, $course_id, $evaluation_type_id, $grade, $comment, $user_id);
        $stmt->execute();
    }

    $conn->commit();
    $_SESSION['success_message'] = "Les notes ont été enregistrées avec succès.";
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error_message'] = "Erreur lors de l'enregistrement des notes : " . $e->getMessage();
}

$stmt->close();
$conn->close();

header('Location: ../pages/success.php');
exit();
?>
