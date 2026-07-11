<?php
/**
 * Endpoint de connexion pour l'API Mobile UV
 * 
 * POST /auth/login.php
 * Body: {"identifiant": "...", "password": "..."}
 */

require_once '../config/database.php';
require_once '../config/helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Méthode non autorisée', 405);
}

try {
    $data = getJsonInput();
    
    // Validation
    $validation = validateRequiredFields($data, ['identifiant', 'password']);
    if ($validation !== true) {
        sendError($validation, 400);
    }
    
    $identifiant = sanitizeInput($data['identifiant']);
    $password = $data['password'];
    
    // Connexion DB
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        sendError('Erreur de connexion à la base de données', 500);
    }
    
    // Rechercher l'utilisateur - CORRECTION ICI
    $query = "SELECT id, name, email, password, role, blocked, last_login 
              FROM users 
              WHERE id = ? OR email = ?
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([$identifiant, $identifiant]);
    
    $user = $stmt->fetch();
    
    if (!$user) {
        sendError('Identifiant ou mot de passe incorrect', 401);
    }
    
    // Vérifier si bloqué
    if ($user['blocked'] == 1) {
        sendError('Votre compte est bloqué. Contactez l\'administrateur.', 403);
    }
    
    // Vérifier le mot de passe
    if (!password_verify($password, $user['password'])) {
        sendError('Identifiant ou mot de passe incorrect', 401);
    }
    
    // Mettre à jour last_login
    $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->execute([$user['id']]);
    
    // Logger la connexion (optionnel)
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'mobile-app';
        
        $logQuery = "INSERT INTO user_logins (user_id, ip_address, user_agent, success) 
                     VALUES (?, ?, ?, 1)";
        $logStmt = $conn->prepare($logQuery);
        $logStmt->execute([$user['id'], $ip_address, $user_agent]);
    } catch (Exception $e) {
        logError("Erreur log connexion: " . $e->getMessage());
    }
    
    // Générer un token simple
    $token = bin2hex(random_bytes(32));
    
    // Réponse
    $response = [
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'last_login' => $user['last_login']
        ],
        'token' => $token
    ];
    
    sendSuccess($response, 'Connexion réussie');
    
} catch (Exception $e) {
    logError("Erreur login: " . $e->getMessage());
    sendError('Une erreur est survenue lors de la connexion', 500);
}
?>