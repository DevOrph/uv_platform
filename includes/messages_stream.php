<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$course_id = intval($_GET['course_id'] ?? 0);

// ID max actuel
$sql = "SELECT MAX(id) AS last_id FROM discussions WHERE course_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$last_id = $row['last_id'] ?? 0;
$stmt->close();

// Boucle qui attend un nouveau message
while (true) {
    $sql = "SELECT MAX(id) AS new_id FROM discussions WHERE course_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $new_id = $row['new_id'] ?? 0;
    $stmt->close();

    if ($new_id > $last_id) {
        // Nouveau message → prévenir le client
        echo "data: new_message\n\n";
        ob_flush();
        flush();
        break; // on ferme, le client va rouvrir la connexion SSE automatiquement
    }

    // attendre un peu avant de re-check
    sleep(1);
}
