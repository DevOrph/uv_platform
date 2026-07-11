<?php
/**
 * check_popup_login.php
 * Récupère les pop-ups centraux pour la page de login
 */
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

try {
    $current_datetime = date('Y-m-d H:i:s');
    
    // Initialiser le tableau des pop-ups affichés dans cette session
    if (!isset($_SESSION['login_popups_shown'])) {
        $_SESSION['login_popups_shown'] = [];
    }
    
    // Récupérer les pop-ups centraux actifs (non bannières)
    $query = "
        SELECT 
            id, title, message, image_url, priority, target_roles,
            auto_close_duration, show_once_per_session
        FROM popups
        WHERE is_active = 1
        AND (display_type = 'popup' OR display_type IS NULL)
        AND start_date <= ?
        AND end_date >= ?
        AND (target_roles = 'all' OR target_roles IS NULL)
        ORDER BY priority DESC, created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Erreur préparation requête: " . $conn->error);
    }
    
    $stmt->bind_param("ss", $current_datetime, $current_datetime);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $popup = null;
    $has_more = false;
    $total_found = 0;
    
    while ($row = $result->fetch_assoc()) {
        $total_found++;
        $popup_id = (int)$row['id'];
        
        // Si show_once_per_session et déjà affiché, passer au suivant
        if ($row['show_once_per_session'] && in_array($popup_id, $_SESSION['login_popups_shown'])) {
            continue;
        }
        
        // Premier pop-up non affiché trouvé
        if ($popup === null) {
            $popup = [
                'id' => $popup_id,
                'title' => $row['title'],
                'message' => $row['message'],
                'image_url' => $row['image_url'],
                'has_image' => !empty($row['image_url']),
                'priority' => (int)$row['priority'],
                'auto_close_duration' => (int)$row['auto_close_duration'],
                'show_once_per_session' => (bool)$row['show_once_per_session']
            ];
            
            // Marquer comme affiché si nécessaire
            if ($row['show_once_per_session']) {
                $_SESSION['login_popups_shown'][] = $popup_id;
            }
        } else {
            // Il y a d'autres pop-ups après celui-ci
            $has_more = true;
        }
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'show' => ($popup !== null),
        'popup' => $popup,
        'has_more' => $has_more,
        'debug' => [
            'total_found' => $total_found,
            'already_shown' => count($_SESSION['login_popups_shown']),
            'current_time' => $current_datetime
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'show' => false
    ]);
}
?>