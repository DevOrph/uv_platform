<?php
/**
 * Fonctions utilitaires pour l'API Mobile UV
 * 
 * @package UV-API-Mobile
 * @author Orphé MYENE & Filbert KASSA - Coding Enterprise
 */

/**
 * Configurer les headers CORS pour permettre les requêtes depuis l'application mobile
 */
function setCorsHeaders() {
    // Permettre les requêtes depuis n'importe quelle origine (à restreindre en production)
    header("Access-Control-Allow-Origin: *");
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
    header("Access-Control-Max-Age: 3600");
    header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
    
    // Gérer les requêtes OPTIONS (preflight)
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * Envoyer une réponse JSON
 * 
 * @param int $statusCode Code HTTP
 * @param array $data Données à renvoyer
 */
function sendJsonResponse($statusCode, $data) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Envoyer une réponse de succès
 * 
 * @param array $data Données à renvoyer
 * @param string $message Message de succès
 */
function sendSuccess($data = [], $message = "Succès") {
    sendJsonResponse(200, [
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * Envoyer une réponse d'erreur
 * 
 * @param string $message Message d'erreur
 * @param int $statusCode Code HTTP (par défaut 400)
 */
function sendError($message, $statusCode = 400) {
    sendJsonResponse($statusCode, [
        'success' => false,
        'message' => $message,
        'data' => null
    ]);
}

/**
 * Valider que les champs requis sont présents
 * 
 * @param array $data Données à valider
 * @param array $requiredFields Champs requis
 * @return bool|string true si valide, message d'erreur sinon
 */
function validateRequiredFields($data, $requiredFields) {
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty(trim($data[$field]))) {
            return "Le champ '$field' est requis";
        }
    }
    return true;
}

/**
 * Nettoyer les données d'entrée
 * 
 * @param string $data Données à nettoyer
 * @return string Données nettoyées
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Vérifier le token JWT (à implémenter plus tard si nécessaire)
 * 
 * @return array|bool Données du token si valide, false sinon
 */
function verifyToken() {
    // Pour l'instant, nous allons utiliser des sessions simples
    // Plus tard, vous pouvez implémenter JWT
    
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        return false;
    }
    
    $token = str_replace('Bearer ', '', $headers['Authorization']);
    
    // TODO: Implémenter la vérification JWT
    // Pour l'instant, on retourne true
    return ['token' => $token];
}

/**
 * Logger les erreurs
 * 
 * @param string $message Message d'erreur
 */
function logError($message) {
    $logFile = __DIR__ . '/../logs/error.log';
    $logDir = dirname($logFile);
    
    if (!file_exists($logDir)) {
        mkdir($logDir, 0777, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message" . PHP_EOL;
    
    error_log($logMessage, 3, $logFile);
}

/**
 * Obtenir les données POST en JSON
 * 
 * @return array|null
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}
?>