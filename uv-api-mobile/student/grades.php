<?php
/**
 * Endpoint pour obtenir les notes d'un étudiant
 * 
 * @package UV-API-Mobile
 * @author Orphé MYENE & Filbert KASSA - Coding Enterprise
 * 
 * GET /api/student/grades.php?user_id=XXX&period_id=XXX (optionnel)
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
    $period_id = isset($_GET['period_id']) ? sanitizeInput($_GET['period_id']) : null;
    
    // Connexion à la base de données
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        sendError('Erreur de connexion à la base de données', 500);
    }
    
    // Récupérer les périodes d'évaluation disponibles
    $periodsQuery = "SELECT DISTINCT
                        ep.id,
                        ep.name,
                        ep.start_date,
                        ep.end_date,
                        ep.is_active
                     FROM evaluation_periods ep
                     INNER JOIN grades g ON g.period_id = ep.id
                     WHERE g.student_id = :user_id
                     ORDER BY ep.start_date DESC";
    
    $periodsStmt = $conn->prepare($periodsQuery);
    $periodsStmt->bindParam(':user_id', $user_id);
    $periodsStmt->execute();
    
    $periods = $periodsStmt->fetchAll();
    
    // Si une période spécifique est demandée, sinon prendre la plus récente
    if ($period_id === null && !empty($periods)) {
        $period_id = $periods[0]['id'];
    }
    
    // Récupérer les notes pour la période
    $gradesQuery = "SELECT 
                        g.id,
                        g.grade_value,
                        g.coefficient,
                        g.grade_date,
                        g.comments,
                        c.name as course_name,
                        c.code as course_code,
                        tu.name as teaching_unit_name,
                        tu.credits,
                        ep.name as period_name
                    FROM grades g
                    LEFT JOIN courses c ON g.course_id = c.id
                    LEFT JOIN teaching_units tu ON c.teaching_unit_id = tu.id
                    LEFT JOIN evaluation_periods ep ON g.period_id = ep.id
                    WHERE g.student_id = :user_id";
    
    if ($period_id !== null) {
        $gradesQuery .= " AND g.period_id = :period_id";
    }
    
    $gradesQuery .= " ORDER BY tu.name, c.name";
    
    $gradesStmt = $conn->prepare($gradesQuery);
    $gradesStmt->bindParam(':user_id', $user_id);
    
    if ($period_id !== null) {
        $gradesStmt->bindParam(':period_id', $period_id);
    }
    
    $gradesStmt->execute();
    $grades = $gradesStmt->fetchAll();
    
    // Calculer les moyennes par unité d'enseignement
    $unitAverages = [];
    $totalWeightedGrades = 0;
    $totalCoefficients = 0;
    
    foreach ($grades as $grade) {
        $unitName = $grade['teaching_unit_name'] ?? 'Sans unité';
        
        if (!isset($unitAverages[$unitName])) {
            $unitAverages[$unitName] = [
                'unit_name' => $unitName,
                'credits' => $grade['credits'],
                'courses' => [],
                'weighted_sum' => 0,
                'total_coefficient' => 0
            ];
        }
        
        $unitAverages[$unitName]['courses'][] = [
            'course_name' => $grade['course_name'],
            'course_code' => $grade['course_code'],
            'grade' => $grade['grade_value'],
            'coefficient' => $grade['coefficient'],
            'date' => $grade['grade_date'],
            'comments' => $grade['comments']
        ];
        
        $weightedGrade = $grade['grade_value'] * $grade['coefficient'];
        $unitAverages[$unitName]['weighted_sum'] += $weightedGrade;
        $unitAverages[$unitName]['total_coefficient'] += $grade['coefficient'];
        
        $totalWeightedGrades += $weightedGrade;
        $totalCoefficients += $grade['coefficient'];
    }
    
    // Calculer les moyennes
    foreach ($unitAverages as &$unit) {
        $unit['average'] = $unit['total_coefficient'] > 0 
            ? round($unit['weighted_sum'] / $unit['total_coefficient'], 2) 
            : 0;
    }
    
    $generalAverage = $totalCoefficients > 0 
        ? round($totalWeightedGrades / $totalCoefficients, 2) 
        : 0;
    
    // Préparer la réponse
    $response = [
        'periods' => $periods,
        'current_period_id' => $period_id,
        'grades' => $grades,
        'statistics' => [
            'units' => array_values($unitAverages),
            'general_average' => $generalAverage,
            'total_courses' => count($grades),
            'total_credits' => array_sum(array_column($unitAverages, 'credits'))
        ]
    ];
    
    sendSuccess($response, 'Notes récupérées avec succès');
    
} catch (Exception $e) {
    logError("Erreur grades: " . $e->getMessage());
    sendError('Une erreur est survenue lors de la récupération des notes', 500);
}
?>