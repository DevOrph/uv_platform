<?php
require_once '../includes/db_connect.php';

if (!isset($_GET['class_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Class ID is required']);
    exit;
}

$class_id = intval($_GET['class_id']);
$query = "
    SELECT 
        u.name AS student_name,
        c.name AS course_name,
        et.name AS evaluation_type,
        g.grade,
        g.comment,
        g.created_at
    FROM grades g
    JOIN users u ON g.student_id = u.id
    JOIN courses c ON g.course_id = c.id
    JOIN evaluation_types et ON g.evaluation_type_id = et.id
    WHERE u.class_id = ?
    ORDER BY u.name, c.name, g.created_at";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();

$notes = [];
while ($row = $result->fetch_assoc()) {
    $notes[] = $row;
}

echo json_encode($notes);
