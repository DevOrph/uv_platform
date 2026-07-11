<?php
/**
 * Endpoint pour obtenir le profil d'un étudiant
 * 
 * @package UV-API-Mobile
 * @author Orphé MYENE & Filbert KASSA - Coding Enterprise
 * 
 * GET /api/student/profile.php?user_id=XXX
 */

require_once '../config/database.php';
require_once '../config/helpers.php';

// Configurer les headers CORS
setCorsHeaders();

// Vérifier que c'est une requête GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Méthode non autorisée', 405);
}

try {
    // Récupérer l'ID utilisateur
    if (!isset($_GET['user_id']) || empty($_GET['user_id'])) {
        sendError('ID utilisateur requis', 400);
    }
    
    $user_id = sanitizeInput($_GET['user_id']);
    
    // Connexion à la base de données
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        sendError('Erreur de connexion à la base de données', 500);
    }
    
    // Récupérer les informations de l'utilisateur
    $query = "SELECT 
                u.id,
                u.name,
                u.email,
                u.phone,
                u.date_of_birth,
                u.gender,
                u.address,
                u.city,
                u.country,
                u.profile_photo,
                u.role,
                u.created_at,
                u.last_login,
                u.blocked
              FROM users u
              WHERE u.id = :user_id AND u.role = 'student'
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    
    $user = $stmt->fetch();
    
    if (!$user) {
        sendError('Étudiant non trouvé', 404);
    }
    
    // Récupérer les informations académiques si l'étudiant est inscrit
    $academicQuery = "SELECT 
                        sr.class_id,
                        c.name as class_name,
                        c.level,
                        c.department,
                        sr.academic_year,
                        sr.registration_date,
                        sr.status
                      FROM student_registrations sr
                      LEFT JOIN classes c ON sr.class_id = c.id
                      WHERE sr.student_id = :user_id
                      ORDER BY sr.registration_date DESC
                      LIMIT 1";
    
    $academicStmt = $conn->prepare($academicQuery);
    $academicStmt->bindParam(':user_id', $user_id);
    $academicStmt->execute();
    
    $academic = $academicStmt->fetch();
    
    // Préparer la réponse
    $response = [
        'profile' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'date_of_birth' => $user['date_of_birth'],
            'gender' => $user['gender'],
            'address' => $user['address'],
            'city' => $user['city'],
            'country' => $user['country'],
            'profile_photo' => $user['profile_photo'],
            'created_at' => $user['created_at'],
            'last_login' => $user['last_login']
        ],
        'academic' => $academic ? [
            'class_id' => $academic['class_id'],
            'class_name' => $academic['class_name'],
            'level' => $academic['level'],
            'department' => $academic['department'],
            'academic_year' => $academic['academic_year'],
            'registration_date' => $academic['registration_date'],
            'status' => $academic['status']
        ] : null
    ];
    
    sendSuccess($response, 'Profil récupéré avec succès');
    
} catch (Exception $e) {
    logError("Erreur profil: " . $e->getMessage());
    sendError('Une erreur est survenue lors de la récupération du profil', 500);
}
?>