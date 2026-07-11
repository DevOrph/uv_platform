<?php
/**
 * API pour gérer les messages des étudiants au service financier
 * Fichier : /api/contact_finance.php
 */

session_start();
header('Content-Type: application/json');
require_once '../includes/db_connect.php';

// Fonction pour envoyer une réponse JSON
function sendResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

// Vérification de l'authentification
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    sendResponse(false, 'Non autorisé');
}

// Vérification de la méthode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Méthode non autorisée');
}

// Vérification du token CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    sendResponse(false, 'Token CSRF invalide');
}

$student_id = $_SESSION['user_id'];

try {
    // Récupération et validation des données
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';

    // Validation des données
    if (empty($subject)) {
        sendResponse(false, 'Le sujet est obligatoire');
    }

    if (empty($message)) {
        sendResponse(false, 'Le message est obligatoire');
    }

    if (strlen($subject) > 255) {
        sendResponse(false, 'Le sujet est trop long (max 255 caractères)');
    }

    if (strlen($message) > 5000) {
        sendResponse(false, 'Le message est trop long (max 5000 caractères)');
    }

    // Validation de la priorité
    $valid_priorities = ['low', 'normal', 'high', 'urgent'];
    if (!in_array($priority, $valid_priorities)) {
        $priority = 'normal';
    }

    // Vérifier si l'étudiant a déjà envoyé trop de messages récemment (anti-spam)
    $spam_check_query = "SELECT COUNT(*) as count FROM finance_messages 
                        WHERE student_id = ? 
                        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
    $stmt = $conn->prepare($spam_check_query);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $spam_result = $stmt->get_result()->fetch_assoc();
    
    if ($spam_result['count'] >= 5) {
        sendResponse(false, 'Vous avez envoyé trop de messages récemment. Veuillez patienter avant de réessayer.');
    }

    // Insertion du message
    $insert_query = "INSERT INTO finance_messages (student_id, subject, message, priority, status) 
                    VALUES (?, ?, ?, ?, 'new')";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("ssss", $student_id, $subject, $message, $priority);
    
    if (!$stmt->execute()) {
        throw new Exception("Erreur lors de l'insertion du message");
    }

    $message_id = $stmt->insert_id;

    // Ajouter à l'historique
    $history_query = "INSERT INTO finance_message_history (message_id, user_id, user_type, message) 
                     VALUES (?, ?, 'student', ?)";
    $hist_stmt = $conn->prepare($history_query);
    $hist_stmt->bind_param("iss", $message_id, $student_id, $message);
    $hist_stmt->execute();

    // Optionnel : Envoyer une notification email à l'admin
    // sendEmailToAdmin($student_id, $subject, $message, $priority);

    // Log de l'action
    $log_query = "INSERT INTO admin_logs (user_id, action, details) 
                 VALUES (?, 'student_message_sent', ?)";
    $log_details = "Message envoyé au service financier - Sujet: $subject";
    $log_stmt = $conn->prepare($log_query);
    $log_stmt->bind_param("ss", $student_id, $log_details);
    $log_stmt->execute();

    sendResponse(true, 'Votre message a été envoyé avec succès. Le service financier vous répondra dans les plus brefs délais.', [
        'message_id' => $message_id
    ]);

} catch (Exception $e) {
    error_log("Erreur contact_finance: " . $e->getMessage());
    sendResponse(false, 'Une erreur est survenue lors de l\'envoi de votre message. Veuillez réessayer.');
} finally {
    $conn->close();
}

// Fonction optionnelle pour envoyer un email à l'admin
function sendEmailToAdmin($student_id, $subject, $message, $priority) {
    global $conn;
    
    // Récupérer les infos de l'étudiant
    $student_query = "SELECT name, email FROM users WHERE id = ?";
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    
    if (!$student) {
        return false;
    }

    $to = "finance@universite-virtuelle.ga"; // Email du service financier
    $email_subject = "[" . strtoupper($priority) . "] Nouveau message étudiant - " . $subject;
    
    $email_body = "Un nouveau message a été reçu du service financier.\n\n";
    $email_body .= "Étudiant: " . $student['name'] . " (" . $student_id . ")\n";
    $email_body .= "Email: " . $student['email'] . "\n";
    $email_body .= "Priorité: " . strtoupper($priority) . "\n\n";
    $email_body .= "Sujet: " . $subject . "\n\n";
    $email_body .= "Message:\n" . $message . "\n\n";
    $email_body .= "---\n";
    $email_body .= "Connectez-vous à l'interface admin pour répondre: https://universite-virtuelle.ga/admin/payment_dashboard.php\n";
    
    $headers = "From: no-reply@universite-virtuelle.ga\r\n";
    $headers .= "Reply-To: " . $student['email'] . "\r\n";
    $headers .= "X-Priority: " . ($priority === 'urgent' ? '1' : ($priority === 'high' ? '2' : '3')) . "\r\n";
    
    return mail($to, $email_subject, $email_body, $headers);
}
?>