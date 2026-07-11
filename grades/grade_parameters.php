<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../pages/login.php');
    exit();
}

// Traitement des ajouts de types d'évaluation
if (isset($_POST['add_type'])) {
    $name = filter_var($_POST['name'], FILTER_SANITIZE_STRING);
    $coefficient = filter_var($_POST['coefficient'], FILTER_VALIDATE_FLOAT);

    $stmt = $conn->prepare("INSERT INTO evaluation_types (name, coefficient) VALUES (?, ?)");
    $stmt->bind_param("sd", $name, $coefficient);
    $stmt->execute();
    
    // Assurez-vous que l'insertion a réussi avant de rediriger ou recharger
    if ($stmt->affected_rows > 0) {
        // Une fois l'ajout effectué, recharger la page
        header("Location: ".$_SERVER['PHP_SELF']);
        exit();
    }
}

// Traitement des modifications de types d'évaluation
if (isset($_POST['update_type'])) {
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    $name = filter_var($_POST['name'], FILTER_SANITIZE_STRING);
    $coefficient = filter_var($_POST['coefficient'], FILTER_VALIDATE_FLOAT);

    $stmt = $conn->prepare("UPDATE evaluation_types SET name=?, coefficient=? WHERE id=?");
    $stmt->bind_param("sdi", $name, $coefficient, $id);
    $stmt->execute();
}

// Traitement des suppressions de types d'évaluation
if (isset($_POST['delete_type'])) {
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT);

    $stmt = $conn->prepare("DELETE FROM evaluation_types WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
}

// Récupération des types d'évaluation
$types = $conn->query("SELECT * FROM evaluation_types ORDER BY name");
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Paramètres d'Évaluation</title>
    <style>/* Global Styles */
body {
    font-family: Arial, sans-serif;
    background-color: #121212;
    margin: 0;
    padding: 0;
    color: var(--text-light);
}

/* Container */
.container {
    width: 80%;
    margin: auto;
    overflow: hidden;
}

/* Section Styling */
.section {
    background: rgba(255, 255, 255, 0.1);
    padding: 20px;
    margin: 20px 0;
    border-radius: 10px;
    box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.2);
}

.section h2 {
    text-align: center;
    color: var(--text-light);
    background: var(--secondary-bg);
    padding: 10px;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);
}

/* Form Styling */
.add-form {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    justify-content: space-between;
    margin-bottom: 20px;
}

.add-form input,
.add-form button {
    flex: 1;
    min-width: 200px;
    padding: 10px;
    border-radius: 5px;
    border: 1px solid var(--border-color);
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-light);
    font-size: 1em;
    transition: all 0.3s ease;
}

.add-form input::placeholder {
    color: rgba(255, 255, 255, 0.7);
}

.add-form input:focus,
.add-form button:hover {
    border-color: var(--accent-color);
    background: rgba(3, 155, 229, 0.2);
}

.add-form button {
    cursor: pointer;
    background: var(--accent-color);
    color: var(--text-light);
    font-weight: bold;
    text-transform: uppercase;
}

.add-form button:hover {
    background: var(--hover-color);
}

/* Table Styling */
table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

thead th {
    background: var(--secondary-bg);
    color: var(--text-light);
    text-align: left;
    padding: 12px;
    text-transform: uppercase;
}

tbody tr:nth-child(even) {
    background: rgba(255, 255, 255, 0.05);
}

tbody tr:hover {
    background: rgba(3, 155, 229, 0.2);
}

tbody td {
    padding: 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    color: var(--text-light);
    text-align: left;
}

/* Buttons */
.btn-edit,
.btn-delete {
    padding: 8px 15px;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9em;
    text-transform: uppercase;
    border: none;
}

.btn-edit {
    background: #4CAF50;
    color: var(--text-light);
}

.btn-edit:hover {
    background: #45a049;
}

.btn-delete {
    background: #e74c3c;
    color: var(--text-light);
}

.btn-delete:hover {
    background: #c0392b;
}
</style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Paramètres d'Évaluation</h1>

        <!-- Formulaire d'ajout de type d'évaluation -->
        <div class="section">
    <h2>Ajouter un Type d'Évaluation</h2>
    <form method="post" class="add-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
        <div class="form-group">
            <input type="text" name="name" placeholder="Nom du type" required>
            <input type="number" name="coefficient" step="0.1" min="0.1" max="1" placeholder="Coefficient" required>
            <button type="submit" name="add_type">Ajouter</button>
        </div>
    </form>
</div>


        <!-- Liste des types d'évaluation -->
        <div class="section">
            <h2>Types d'Évaluation</h2>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Coefficient</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($type = $types->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($type['name']); ?></td>
                            <td><?php echo $type['coefficient']; ?></td>
                            <td>
                                <button onclick="editType(<?php echo $type['id']; ?>)" class="btn-edit">Modifier</button>
                                <button onclick="deleteType(<?php echo $type['id']; ?>)" class="btn-delete">Supprimer</button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function editType(id) {
        const name = prompt('Nouveau nom :');
        const coefficient = prompt('Nouveau coefficient :');
        
        if (name && coefficient) {
            // Envoyer la modification via AJAX
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `update_type=true&id=${id}&name=${name}&coefficient=${coefficient}`
            })
            .then(response => response.text())
            .then(() => location.reload()); // Recharger la page
        }
    }

    function deleteType(id) {
        if (confirm('Voulez-vous vraiment supprimer ce type d\'évaluation ?')) {
            // Envoyer la demande de suppression via AJAX
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `delete_type=true&id=${id}`
            })
            .then(response => response.text())
            .then(() => location.reload()); // Recharger la page
        }
    }
    </script>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
