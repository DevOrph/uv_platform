<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit();
}

$classroom_id = isset($_GET['classroom_id']) ? intval($_GET['classroom_id']) : 0;
$weekday_id = isset($_GET['weekday_id']) ? intval($_GET['weekday_id']) : 0;
$timeslot_id = isset($_GET['timeslot_id']) ? intval($_GET['timeslot_id']) : 0;
$schedule_id = isset($_GET['schedule_id']) ? intval($_GET['schedule_id']) : 0;

if (!$classroom_id || !$weekday_id || !$timeslot_id) {
    echo json_encode(['conflict' => false]);
    exit();
}

// Vérifier les conflits
$query = "SELECT 
    s.id,
    c.name as course_name,
    u.name as teacher_name,
    cl.name as class_name,
    r.name as classroom_name
FROM schedule s
JOIN courses c ON s.course_id = c.id
JOIN users u ON s.teacher_id = u.id
JOIN classes cl ON s.class_id = cl.id
JOIN classrooms r ON s.classroom_id = r.id
WHERE s.classroom_id = ? 
  AND s.weekday_id = ? 
  AND s.time_slot_id = ?";

if ($schedule_id > 0) {
    $query .= " AND s.id != ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiii", $classroom_id, $weekday_id, $timeslot_id, $schedule_id);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $classroom_id, $weekday_id, $timeslot_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $conflict = $result->fetch_assoc();
    echo json_encode([
        'conflict' => true,
        'conflict' => $conflict
    ]);
} else {
    echo json_encode(['conflict' => false]);
}

$stmt->close();