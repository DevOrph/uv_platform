<?php
session_start();
require_once '../includes/db_connect.php';
require_once __DIR__ . '/super_admin.php';

// Vérification des droits d'accès
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Accès non autorisé']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Paramètres de la requête
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
$course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;

try {
    // Construction de la requête selon le rôle
    $query = "SELECT 
                g.id,
                g.grade,
                g.comment,
                g.created_at,
                g.created_by,
                u.name as student_name,
                u.id as student_id,
                c.name as course_name,
                c.id as course_id,
                et.name as evaluation_type,
                et.id as evaluation_type_id,
                ep.name as period_name,
                cl.name as class_name
              FROM grades g
              JOIN users u ON g.student_id = u.id
              JOIN courses c ON g.course_id = c.id
              JOIN evaluation_types et ON g.evaluation_type_id = et.id
              JOIN evaluation_periods ep ON g.evaluation_period_id = ep.id
              LEFT JOIN classes cl ON u.class_id = cl.id
              WHERE 1=1";
    
    $params = [];
    $types = "";
    
    // Filtre par classe si spécifié
    if ($class_id > 0) {
        $query .= " AND u.class_id = ?";
        $params[] = $class_id;
        $types .= "i";
    }
    
    // Filtre par cours si spécifié
    if ($course_id > 0) {
        $query .= " AND c.id = ?";
        $params[] = $course_id;
        $types .= "i";
    }
    
    // Restriction pour les enseignants (seulement leurs cours)
    if ($user_role === 'teacher') {
        $query .= " AND (g.created_by = ? OR c.teacher_id = ?)";
        $params[] = $user_id;
        $params[] = $user_id;
        $types .= "ss";
    }
    
    // Tri par date de création (plus récent en premier)
    $query .= " ORDER BY g.created_at DESC";
    
    // Limite si spécifiée
    if ($limit > 0) {
        $query .= " LIMIT ?";
        $params[] = $limit;
        $types .= "i";
    }
    
    // Préparation et exécution de la requête
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $grades = [];
    while ($row = $result->fetch_assoc()) {
        // Calculer si l'utilisateur peut modifier cette note
        $can_edit = false;
        if ($user_role === 'admin') {
            $can_edit = true;
        } elseif ($user_role === 'teacher') {
            $can_edit = ($row['created_by'] === $user_id);
        }
        
        // Vérifier les permissions d'examen si c'est une note d'examen
        $can_edit_exam = true;
        if ($row['evaluation_type_id'] == 2) { // Type "Examen"
            if (!is_super_admin($conn, $user_id)) {
                // Vérifier les permissions spéciales
                $perm_query = "SELECT id FROM exam_permissions 
                              WHERE user_id = ? AND is_active = 1 
                              AND (expires_at IS NULL OR expires_at > NOW())";
                $perm_stmt = $conn->prepare($perm_query);
                $perm_stmt->bind_param("s", $user_id);
                $perm_stmt->execute();
                $can_edit_exam = $perm_stmt->get_result()->num_rows > 0;
            }
        }
        
        $grades[] = [
            'id' => $row['id'],
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name'],
            'course_id' => $row['course_id'],
            'course_name' => $row['course_name'],
            'evaluation_type' => $row['evaluation_type'],
            'evaluation_type_id' => $row['evaluation_type_id'],
            'period_name' => $row['period_name'],
            'class_name' => $row['class_name'],
            'grade' => floatval($row['grade']),
            'comment' => $row['comment'],
            'created_at' => $row['created_at'],
            'created_by' => $row['created_by'],
            'can_edit' => $can_edit && $can_edit_exam,
            'is_exam' => $row['evaluation_type_id'] == 2,
            'can_edit_exam' => $can_edit_exam
        ];
    }
    
    // Statistiques supplémentaires si demandées
    $stats = [];
    if (isset($_GET['include_stats']) && $_GET['include_stats'] === 'true') {
        // Calcul de la moyenne de classe
        if ($class_id > 0) {
            $avg_query = "SELECT AVG(g.grade) as class_average
                         FROM grades g
                         JOIN users u ON g.student_id = u.id
                         WHERE u.class_id = ?";
            $avg_stmt = $conn->prepare($avg_query);
            $avg_stmt->bind_param("i", $class_id);
            $avg_stmt->execute();
            $avg_result = $avg_stmt->get_result()->fetch_assoc();
            $stats['class_average'] = round($avg_result['class_average'], 2);
        }
        
        // Nombre total de notes
        $count_query = str_replace("SELECT g.id,", "SELECT COUNT(*) as total", 
                                 str_replace(" ORDER BY g.created_at DESC", "", 
                                           str_replace(" LIMIT ?", "", $query)));
        $count_params = array_slice($params, 0, -1); // Enlever le paramètre LIMIT
        $count_types = substr($types, 0, -1);
        
        $count_stmt = $conn->prepare($count_query);
        if (!empty($count_params)) {
            $count_stmt->bind_param($count_types, ...$count_params);
        }
        $count_stmt->execute();
        $count_result = $count_stmt->get_result()->fetch_assoc();
        $stats['total_grades'] = $count_result['total'];
    }
    
    // Réponse JSON
    $response = [
        'success' => true,
        'grades' => $grades,
        'count' => count($grades)
    ];
    
    if (!empty($stats)) {
        $response['stats'] = $stats;
    }
    
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("Erreur get_recent_grades: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur lors de la récupération des notes',
        'details' => $e->getMessage()
    ]);
}
?>