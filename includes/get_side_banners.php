<?php
/**
 * get_side_banners.php
 * Récupère les bannières publicitaires actives pour affichage latéral
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Ajout pour éviter les erreurs CORS

require_once 'db_connect.php';

try {
    $current_datetime = date('Y-m-d H:i:s');
    
    // Récupérer les bannières latérales actives
    $query = "
        SELECT 
            id, title, message, image_url, priority, target_roles,
            auto_close_duration, show_once_per_session
        FROM popups
        WHERE is_active = 1
        AND display_type = 'sidebar'
        AND image_url IS NOT NULL
        AND image_url != ''
        AND start_date <= ?
        AND end_date >= ?
        AND (target_roles = 'all' OR target_roles IS NULL)
        ORDER BY priority DESC, created_at DESC
        LIMIT 10
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Erreur préparation requête: " . $conn->error);
    }
    
    $stmt->bind_param("ss", $current_datetime, $current_datetime);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $banners = [];
    while ($row = $result->fetch_assoc()) {
        $banners[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'message' => $row['message'],
            'image_url' => $row['image_url'],
            'priority' => (int)$row['priority'],
            'target_roles' => $row['target_roles'],
            'auto_close_duration' => (int)$row['auto_close_duration'],
            'show_once_per_session' => (bool)$row['show_once_per_session']
        ];
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'banners' => $banners,
        'count' => count($banners),
        'debug' => [
            'current_time' => $current_datetime,
            'query_executed' => true
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>