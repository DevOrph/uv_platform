<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error' => 'Non autorisé']);
    exit();
}

$student_id = trim($_GET['student_id'] ?? '');
if (!$student_id) {
    echo json_encode(['error' => 'ID étudiant manquant']);
    exit();
}

$ur = $conn->prepare("SELECT name FROM users WHERE id = ? AND role = 'student'");
$ur->bind_param('s', $student_id);
$ur->execute();
$u = $ur->get_result()->fetch_assoc();
$ur->close();

if (!$u) {
    echo json_encode(['error' => 'Étudiant non trouvé']);
    exit();
}

$hr = $conn->prepare("
    SELECT sch.id, sch.academic_year, sch.start_date, sch.end_date,
           sch.status, sch.notes,
           c.name AS class_name, c.code AS class_code,
           f.name AS filiere_name, f.code AS filiere_code
    FROM student_class_history sch
    JOIN classes c ON c.id = sch.class_id
    LEFT JOIN filieres f ON f.id = c.filiere_id
    WHERE sch.student_id = ?
    ORDER BY sch.academic_year ASC, sch.start_date ASC
");
$hr->bind_param('s', $student_id);
$hr->execute();
$history = $hr->get_result()->fetch_all(MYSQLI_ASSOC);
$hr->close();

echo json_encode([
    'success' => true,
    'name'    => $u['name'],
    'history' => $history,
]);
