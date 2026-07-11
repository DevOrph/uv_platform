<?php
// test_classes.php
require_once '../includes/db_connect.php';

echo "<h1>Test des Classes</h1>";

// Test 1: Connexion DB
if ($conn) {
    echo "✅ Connexion DB réussie<br>";
} else {
    echo "❌ Erreur connexion DB<br>";
    exit;
}

// Test 2: Récupération des classes
$sql = "SELECT id, name FROM classes ORDER BY id";
$result = $conn->query($sql);

if ($result) {
    echo "✅ Requête SQL réussie<br>";
    echo "Nombre de classes: " . $result->num_rows . "<br><br>";
    
    while ($row = $result->fetch_assoc()) {
        echo "ID: " . $row['id'] . " | Nom: " . $row['name'] . "<br>";
    }
} else {
    echo "❌ Erreur requête SQL: " . $conn->error . "<br>";
}

$conn->close();
?>