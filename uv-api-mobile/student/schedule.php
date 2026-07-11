<?php
/**
 * Endpoint pour obtenir l'emploi du temps d'un étudiant
 * 
 * @package UV-API-Mobile
 * @author Orphé MYENE & Filbert KASSA - Coding Enterprise
 * 
 * GET /api/student/schedule.php?user_id=XXX&week_offset=0 (optionnel)
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
    $week_offset = isset($_GET['week_offset']) ? (int)$_GET['week_offset'] : 0;
    
    // Connexion à la base de données
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        sendError('Erreur de connexion à la base de données', 500);
    }
    
    // Récupérer la classe de l'étudiant
    $classQuery = "SELECT class_id 
                   FROM student_registrations 
                   WHERE student_id = :user_id 
                   ORDER BY registration_date DESC 
                   LIMIT 1";
    
    $classStmt = $conn->prepare($classQuery);
    $classStmt->bindParam(':user_id', $user_id);
    $classStmt->execute();
    
    $studentClass = $classStmt->fetch();
    
    if (!$studentClass) {
        sendError('Étudiant non inscrit dans une classe', 404);
    }
    
    $class_id = $studentClass['class_id'];
    
    // Calculer les dates de la semaine
    $today = new DateTime();
    $today->modify(($week_offset * 7) . ' days');
    
    // Obtenir le lundi de la semaine
    $monday = clone $today;
    $dayOfWeek = $monday->format('N'); // 1 (lundi) à 7 (dimanche)
    $monday->modify('-' . ($dayOfWeek - 1) . ' days');
    
    // Obtenir le dimanche de la semaine
    $sunday = clone $monday;
    $sunday->modify('+6 days');
    
    $startDate = $monday->format('Y-m-d');
    $endDate = $sunday->format('Y-m-d');
    
    // Récupérer l'emploi du temps pour la semaine
    $scheduleQuery = "SELECT 
                        s.id,
                        s.day_of_week,
                        s.start_time,
                        s.end_time,
                        s.room,
                        s.schedule_date,
                        c.name as course_name,
                        c.code as course_code,
                        c.type as course_type,
                        u.name as teacher_name,
                        cl.name as class_name
                      FROM schedules s
                      LEFT JOIN courses c ON s.course_id = c.id
                      LEFT JOIN users u ON s.teacher_id = u.id
                      LEFT JOIN classes cl ON s.class_id = cl.id
                      WHERE s.class_id = :class_id
                      AND (
                          (s.schedule_date IS NULL AND s.is_recurring = 1) 
                          OR (s.schedule_date BETWEEN :start_date AND :end_date)
                      )
                      ORDER BY 
                        FIELD(s.day_of_week, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'),
                        s.start_time";
    
    $scheduleStmt = $conn->prepare($scheduleQuery);
    $scheduleStmt->bindParam(':class_id', $class_id);
    $scheduleStmt->bindParam(':start_date', $startDate);
    $scheduleStmt->bindParam(':end_date', $endDate);
    $scheduleStmt->execute();
    
    $schedules = $scheduleStmt->fetchAll();
    
    // Organiser les cours par jour
    $weekDays = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    $scheduleByDay = [];
    
    foreach ($weekDays as $index => $day) {
        $date = clone $monday;
        $date->modify('+' . $index . ' days');
        
        $scheduleByDay[$day] = [
            'day' => $day,
            'date' => $date->format('Y-m-d'),
            'formatted_date' => $date->format('d/m/Y'),
            'courses' => []
        ];
    }
    
    // Remplir les cours
    foreach ($schedules as $schedule) {
        $day = $schedule['day_of_week'];
        
        if (isset($scheduleByDay[$day])) {
            $scheduleByDay[$day]['courses'][] = [
                'id' => $schedule['id'],
                'course_name' => $schedule['course_name'],
                'course_code' => $schedule['course_code'],
                'course_type' => $schedule['course_type'],
                'teacher_name' => $schedule['teacher_name'],
                'start_time' => $schedule['start_time'],
                'end_time' => $schedule['end_time'],
                'room' => $schedule['room'],
                'specific_date' => $schedule['schedule_date']
            ];
        }
    }
    
    // Préparer la réponse
    $response = [
        'week_info' => [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'week_offset' => $week_offset,
            'formatted_range' => $monday->format('d/m/Y') . ' - ' . $sunday->format('d/m/Y')
        ],
        'class_info' => [
            'class_id' => $class_id,
            'class_name' => $schedules[0]['class_name'] ?? 'N/A'
        ],
        'schedule' => array_values($scheduleByDay)
    ];
    
    sendSuccess($response, 'Emploi du temps récupéré avec succès');
    
} catch (Exception $e) {
    logError("Erreur schedule: " . $e->getMessage());
    sendError('Une erreur est survenue lors de la récupération de l\'emploi du temps', 500);
}
?>