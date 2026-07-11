<?php
require_once 'db_connect.php';

if (!isset($_GET['student_id'])) {
    echo json_encode([]);
    exit();
}

$student_id = $_GET['student_id'];

$query = "
    SELECT 
        s.name AS student_name, 
        c.name AS course_name, 
        et.name AS evaluation_type, 
        g.grade, 
        g.comment, 
        g.created_at 
    FROM grades g
    JOIN users s ON g.student_id = s.id
    JOIN courses c ON g.course_id = c.id
    JOIN evaluation_types et ON g.evaluation_type_id = et.id
    WHERE g.student_id = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

$notes = [];
while ($row = $result->fetch_assoc()) {
    $notes[] = $row;
}

echo json_encode($notes);
