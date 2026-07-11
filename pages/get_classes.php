<?php
// get_classes.php - VERSION CORRIGÉE
session_start();
require_once '../includes/db_connect_public.php';
// ✅ CORRECTION #1: Forcer l'encodage UTF-8 pour l'API JSON
header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, "utf8mb4");

try {
    // Récupérer toutes les classes actives
    $query = "SELECT id, name FROM classes ORDER BY name ASC";
    $result = $conn->query($query);
    
    if (!$result) {
        throw new Exception("Erreur de requête : " . $conn->error);
    }
    
    $classes = [];
    while ($row = $result->fetch_assoc()) {
        $classes[] = [
            'id' => (int)$row['id'],
            'name' => $row['name']
        ];
    }
    
    // Retourner les données en JSON UTF-8
    echo json_encode([
        'success' => true,
        'data' => $classes,
        'count' => count($classes)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Logger l'erreur
    error_log("Erreur get_classes.php : " . $e->getMessage());
    
    // Retourner une erreur en JSON
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Impossible de charger les classes',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

$conn->close();
?>