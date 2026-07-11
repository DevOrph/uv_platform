<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json');

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['show' => false, 'popup' => null]);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Récupérer la classe de l'utilisateur si c'est un étudiant
$user_class_id = null;
if ($user_role === 'student') {
    $stmt = $conn->prepare("SELECT class_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user_class_id = $row['class_id'];
    }
    $stmt->close();
}

// Initialiser la liste des pop-ups déjà vus dans cette session
if (!isset($_SESSION['seen_popups'])) {
    $_SESSION['seen_popups'] = [];
}

// Date actuelle
$current_date = date('Y-m-d H:i:s');

// Requête pour récupérer les pop-ups actifs
$query = "
    SELECT * FROM popups 
    WHERE is_active = 1 
    AND start_date <= ? 
    AND end_date >= ?
    AND (
        target_roles = 'all' 
        OR target_roles = ?
    )
    ORDER BY priority DESC, created_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("sss", $current_date, $current_date, $user_role);
$stmt->execute();
$result = $stmt->get_result();

$available_popups = [];

while ($popup = $result->fetch_assoc()) {
    // Vérifier si le pop-up a déjà été vu dans cette session
    if ($popup['show_once_per_session'] == 1 && in_array($popup['id'], $_SESSION['seen_popups'])) {
        continue;
    }
    
    // Vérifier si le pop-up est destiné à une classe spécifique
    if (!empty($popup['class_id'])) {
        $target_classes = json_decode($popup['class_id'], true);
        
        // Si c'est un tableau de classes
        if (is_array($target_classes)) {
            // Si l'utilisateur n'a pas de classe ou sa classe n'est pas dans la liste
            if ($user_class_id === null || !in_array($user_class_id, $target_classes)) {
                continue;
            }
        } else {
            // Si c'est une seule classe (format ancien)
            if ($user_class_id === null || $popup['class_id'] != $user_class_id) {
                continue;
            }
        }
    }
    
    // Ce pop-up est valide, l'ajouter à la liste
    $available_popups[] = $popup;
}

$stmt->close();

// S'il y a des pop-ups disponibles
if (!empty($available_popups)) {
    // Prendre le premier pop-up (priorité la plus haute)
    $popup_to_show = $available_popups[0];
    
    // Marquer ce pop-up comme vu
    $_SESSION['seen_popups'][] = $popup_to_show['id'];
    
    // Déterminer le type de pop-up (document ou image)
    $is_document = false;
    if (!empty($popup_to_show['image_url']) && strpos($popup_to_show['image_url'], 'uploads/documents/') !== false) {
        $is_document = true;
    }
    
    // Préparer la réponse
    $response = [
        'show' => true,
        'popup' => [
            'id' => $popup_to_show['id'],
            'title' => $popup_to_show['title'],
            'message' => $popup_to_show['message'],
            'image_url' => $popup_to_show['image_url'],
            'auto_close_duration' => intval($popup_to_show['auto_close_duration']),
            'is_document' => $is_document,
            'has_more' => count($available_popups) > 1 // Indique s'il y a d'autres pop-ups à afficher
        ]
    ];
    
    echo json_encode($response);
} else {
    echo json_encode(['show' => false, 'popup' => null]);
}

$conn->close();
?>