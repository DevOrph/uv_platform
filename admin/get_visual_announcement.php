<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

if (!isset($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    exit();
}

$id = intval($_GET['id']);
$stmt = $conn->prepare("SELECT * FROM visual_announcements WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$announcement = $result->fetch_assoc();

header('Content-Type: application/json');
echo json_encode($announcement);
?>