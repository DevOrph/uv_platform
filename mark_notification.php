<?php
// mark_notification.php
session_start();
require_once '../includes/db_connect.php';


// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: pages/login.html");
    exit();
}

// Vérifier si l'ID de notification et l'URL de redirection sont fournis
if (!isset($_GET['notification_id']) || !isset($_GET['redirect'])) {
    header("Location: index.php");
    exit();
}



// Récupérer les paramètres
$notification_id = $_GET['notification_id'];
$redirect_url = $_GET['redirect'];

// Vérifier que la notification appartient bien à l'utilisateur
$sql = "SELECT * FROM notifications WHERE id = ? AND user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $notification_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // La notification n'existe pas ou n'appartient pas à l'utilisateur
    header("Location: index.php");
    exit();
}

// Marquer la notification comme lue
$sql = "UPDATE notifications SET is_read = 1 WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $notification_id);
$stmt->execute();

// Si c'est une requête AJAX, retourner un statut JSON
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Rediriger vers l'URL de destination
header("Location: " . $redirect_url);
exit();
?>