<?php
require_once '../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = filter_var($_POST['period_name'], FILTER_SANITIZE_STRING);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $school_year = filter_var($_POST['school_year'], FILTER_SANITIZE_STRING);

    $stmt = $conn->prepare("INSERT INTO evaluation_periods (name, start_date, end_date, school_year) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $name, $start_date, $end_date, $school_year);
    
    if ($stmt->execute()) {
        echo "Période ajoutée";
    } else {
        echo "Erreur";
    }
}
?>
