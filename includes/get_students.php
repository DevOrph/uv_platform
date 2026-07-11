<?php
// Inclusion de la connexion à la base de données
require_once '../includes/db_connect.php';

// Vérification si la class_id est passée en paramètre
if (!isset($_GET['class_id']) || empty($_GET['class_id'])) {
    echo json_encode(['error' => 'Invalid class ID']);
    exit;
}

$class_id = intval($_GET['class_id']); // Protection contre les injections SQL

// Préparation et exécution de la requête pour récupérer les étudiants associés à une classe
$query = $conn->prepare("SELECT id, name FROM users WHERE class_id = ? AND role = 'student'");
$query->bind_param("i", $class_id);
$query->execute();
$result = $query->get_result();

// Conversion des résultats en tableau associatif
$students = [];
while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

// Vérification si aucun étudiant n'est trouvé
if (empty($students)) {
    echo json_encode(['error' => 'Aucun étudiant trouvé pour cette classe']);
    exit;
}

// Envoi des données au format JSON
echo json_encode($students);
exit;
?>
