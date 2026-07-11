<?php
session_start();
require_once 'db_connect.php';
require_once __DIR__ . '/super_admin.php';

// Vérification des droits d'accès
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Accès non autorisé']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Récupération du paramètre class_id
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

if ($class_id <= 0) {
    echo json_encode(['error' => 'ID de classe invalide']);
    exit();
}

try {
    // Construction de la requête selon le rôle
    $query = "SELECT DISTINCT c.id, c.name, c.coefficient, c.semester, c.total_hours
              FROM courses c
              WHERE JSON_CONTAINS(c.class_id, ?)";
    
    $params = [json_encode(strval($class_id))];
    $types = "s";
    
    // Restriction pour les enseignants (seulement leurs cours)
    if ($user_role === 'teacher') {
        $query .= " AND c.teacher_id = ?";
        $params[] = $user_id;
        $types .= "s";
    }
    
    $query .= " ORDER BY c.name";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $courses = [];
    while ($row = $result->fetch_assoc()) {
        // Nettoyer et décoder le JSON des classes
        $class_ids_json = $row['class_id'] ?? '[]';
        $class_ids = json_decode($class_ids_json, true);
        
        // Vérifier si la classe demandée est bien dans la liste
        if (is_array($class_ids) && in_array(strval($class_id), $class_ids)) {
            $courses[] = [
                'id' => intval($row['id']),
                'name' => $row['name'],
                'coefficient' => floatval($row['coefficient']),
                'semester' => intval($row['semester']),
                'total_hours' => $row['total_hours'] ? intval($row['total_hours']) : null
            ];
        }
    }
    
    // Si aucun cours trouvé avec la méthode JSON, essayer une approche alternative
    if (empty($courses)) {
        // Recherche avec LIKE pour compatibilité avec d'anciens formats
        $like_query = "SELECT DISTINCT c.id, c.name, c.coefficient, c.semester, c.total_hours
                       FROM courses c
                       WHERE c.class_id LIKE ?";
        
        $like_params = ["%\"$class_id\"%"];
        
        if ($user_role === 'teacher') {
            $like_query .= " AND c.teacher_id = ?";
            $like_params[] = $user_id;
        }
        
        $like_query .= " ORDER BY c.name";
        
        $stmt = $conn->prepare($like_query);
        $stmt->bind_param(str_repeat('s', count($like_params)), ...$like_params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $courses[] = [
                'id' => intval($row['id']),
                'name' => $row['name'],
                'coefficient' => floatval($row['coefficient']),
                'semester' => intval($row['semester']),
                'total_hours' => $row['total_hours'] ? intval($row['total_hours']) : null
            ];
        }
    }
    
    // Ajouter des informations supplémentaires si demandées
    if (isset($_GET['include_teacher_info']) && $_GET['include_teacher_info'] === 'true') {
        foreach ($courses as &$course) {
            $teacher_query = "SELECT u.name as teacher_name, u.id as teacher_id 
                             FROM courses c 
                             JOIN users u ON c.teacher_id = u.id 
                             WHERE c.id = ?";
            $teacher_stmt = $conn->prepare($teacher_query);
            $teacher_stmt->bind_param("i", $course['id']);
            $teacher_stmt->execute();
            $teacher_result = $teacher_stmt->get_result();
            $teacher_info = $teacher_result->fetch_assoc();
            
            if ($teacher_info) {
                $course['teacher_name'] = $teacher_info['teacher_name'];
                $course['teacher_id'] = $teacher_info['teacher_id'];
            }
        }
    }
    
    // Statistiques additionnelles si demandées
    $stats = [];
    if (isset($_GET['include_stats']) && $_GET['include_stats'] === 'true') {
        foreach ($courses as $course) {
            $stats_query = "SELECT 
                              COUNT(g.id) as total_grades,
                              AVG(g.grade) as average_grade,
                              MIN(g.grade) as min_grade,
                              MAX(g.grade) as max_grade
                           FROM grades g
                           JOIN users u ON g.student_id = u.id
                           WHERE g.course_id = ? AND u.class_id = ?";
            
            $stats_stmt = $conn->prepare($stats_query);
            $stats_stmt->bind_param("ii", $course['id'], $class_id);
            $stats_stmt->execute();
            $stats_result = $stats_stmt->get_result();
            $course_stats = $stats_result->fetch_assoc();
            
            $stats[$course['id']] = [
                'total_grades' => intval($course_stats['total_grades']),
                'average_grade' => $course_stats['average_grade'] ? round(floatval($course_stats['average_grade']), 2) : null,
                'min_grade' => $course_stats['min_grade'] ? floatval($course_stats['min_grade']) : null,
                'max_grade' => $course_stats['max_grade'] ? floatval($course_stats['max_grade']) : null
            ];
        }
    }
    
    // Vérifier les permissions pour chaque cours si c'est un enseignant
    if ($user_role === 'teacher') {
        foreach ($courses as &$course) {
            // Vérifier si l'enseignant peut ajouter des notes d'examen pour ce cours
            $can_add_exam = is_super_admin($conn, $user_id);
            if (!$can_add_exam) {
                $perm_query = "SELECT id FROM exam_permissions 
                              WHERE user_id = ? AND is_active = 1 
                              AND (expires_at IS NULL OR expires_at > NOW())";
                $perm_stmt = $conn->prepare($perm_query);
                $perm_stmt->bind_param("s", $user_id);
                $perm_stmt->execute();
                $can_add_exam = $perm_stmt->get_result()->num_rows > 0;
            }
            
            $course['can_add_exam_grades'] = $can_add_exam;
        }
    }
    
    // Réponse JSON
    $response = [
        'success' => true,
        'courses' => $courses,
        'count' => count($courses),
        'class_id' => $class_id
    ];
    
    if (!empty($stats)) {
        $response['stats'] = $stats;
    }
    
    // Informations de débogage si demandées
    if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
        $response['debug'] = [
            'user_role' => $user_role,
            'user_id' => $user_id,
            'query_used' => $query,
            'params_used' => $params
        ];
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erreur get_courses: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur lors de la récupération des cours',
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>