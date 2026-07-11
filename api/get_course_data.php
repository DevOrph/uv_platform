<?php
session_start();
require_once '../includes/db_connect.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit();
}

if (!isset($_GET['course_id'])) {
    echo json_encode(['success' => false, 'message' => 'ID de cours manquant']);
    exit();
}

$course_id = intval($_GET['course_id']);

// Récupérer les informations du cours
$query = "SELECT teacher_id, class_id, name FROM courses WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Cours non trouvé']);
    exit();
}

$course = $result->fetch_assoc();
$class_ids = json_decode($course['class_id'], true);

// Validation: s'assurer que class_ids est un tableau
if (!is_array($class_ids)) {
    $class_ids = [];
}

// Récupérer les noms des classes
$classes = [];
if (!empty($class_ids)) {
    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
    $types = str_repeat('i', count($class_ids));
    
    $class_query = "SELECT id, name FROM classes WHERE id IN ($placeholders) ORDER BY name";
    $class_stmt = $conn->prepare($class_query);
    $class_stmt->bind_param($types, ...$class_ids);
    $class_stmt->execute();
    $class_result = $class_stmt->get_result();
    
    while ($class = $class_result->fetch_assoc()) {
        $classes[] = $class;
    }
    $class_stmt->close();
}

// Vérifier les créneaux déjà occupés pour ce cours
$occupied_query = "SELECT 
    w.name as weekday,
    ts.name as timeslot,
    r.name as classroom
FROM schedule s
JOIN weekdays w ON s.weekday_id = w.id
JOIN time_slots ts ON s.time_slot_id = ts.id
JOIN classrooms r ON s.classroom_id = r.id
WHERE s.course_id = ?
ORDER BY w.id, ts.start_time";

$occupied_stmt = $conn->prepare($occupied_query);
$occupied_stmt->bind_param("i", $course_id);
$occupied_stmt->execute();
$occupied_result = $occupied_stmt->get_result();

$occupied_slots = [];
while ($slot = $occupied_result->fetch_assoc()) {
    $occupied_slots[] = $slot;
}
$occupied_stmt->close();

// Suggestion de créneaux disponibles
$suggestion_query = "SELECT 
    w.id as weekday_id,
    w.name as weekday,
    ts.id as timeslot_id,
    ts.name as timeslot,
    COUNT(s.id) as occupation_count
FROM weekdays w
CROSS JOIN time_slots ts
LEFT JOIN schedule s ON s.weekday_id = w.id AND s.time_slot_id = ts.id
GROUP BY w.id, ts.id
HAVING occupation_count < (SELECT COUNT(*) FROM classrooms) / 2
ORDER BY occupation_count, RAND()
LIMIT 5";

$suggestion_result = $conn->query($suggestion_query);
$suggestions = [];
while ($sugg = $suggestion_result->fetch_assoc()) {
    $suggestions[] = $sugg;
}

$stmt->close();

// ✅ AJOUT: Retourner aussi les class_ids pour la validation côté frontend
echo json_encode([
    'success' => true,
    'course_id' => $course_id,  // ✅ Ajouté
    'teacher_id' => $course['teacher_id'],
    'course_name' => $course['name'],
    'classes' => $classes,
    'class_ids' => $class_ids,  // ✅ Ajouté
    'occupied_slots' => $occupied_slots,
    'suggestions' => $suggestions
]);