<?php
require_once __DIR__ . '/db_connect.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit();
}

if (!isset($_GET['course_id'])) {
    exit();
}

$course_id = intval($_GET['course_id']);

// Récupérer uniquement les nouveaux messages
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

// Année académique : paramètre GET validé ou calcul automatique
if (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year'])) {
    $filter_year = $_GET['year'];
} else {
    $filter_year = ANNEE_ACADEMIQUE_COURANTE;
}

$stmt = $conn->prepare("
    SELECT d.id AS discussion_id, d.sender_id, d.message, d.created_at, u.name, u.avatar,
           doc.id AS document_id, doc.file_path, COALESCE(doc.original_name, doc.file_path) AS original_name
    FROM discussions d
    JOIN users u ON d.sender_id = u.id
    LEFT JOIN documents doc ON d.id = doc.discussion_id
    WHERE d.course_id = ? AND d.id > ? AND d.academic_year = ?
    ORDER BY d.created_at ASC
");
$stmt->bind_param("iis", $course_id, $last_id, $filter_year);
$stmt->execute();
$result = $stmt->get_result();

$response = [];
while ($row = $result->fetch_assoc()) {
    $response[] = $row;
}

header('Content-Type: application/json');
echo json_encode($response);
?>
