<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php'; // contient log_admin_action()

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$current_admin_id = $_SESSION['user_id'];

$error_message = "";
$success_message = "";

// Ajouter une classe
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_class'])) {
    $name = trim($_POST['name']);
    $image_path = null;
    $timetable_image_path = null;

    if (empty($name) || empty($_FILES['image']['name']) || empty($_FILES['timetable_image']['name'])) {
        $error_message = "Tous les champs sont requis.";
    } else {
        $target_dir = "../uploads/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

        $image_file = $target_dir . basename($_FILES["image"]["name"]);
        $timetable_file = $target_dir . basename($_FILES["timetable_image"]["name"]);

        $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
        $check_image = getimagesize($_FILES["image"]["tmp_name"]);
        $check_timetable = getimagesize($_FILES["timetable_image"]["tmp_name"]);

        if ($check_image === false || $check_timetable === false || 
            !in_array(strtolower(pathinfo($image_file, PATHINFO_EXTENSION)), $allowed_types) ||
            !in_array(strtolower(pathinfo($timetable_file, PATHINFO_EXTENSION)), $allowed_types)) {
            $error_message = "Fichiers invalides ou format non autorisé.";
        } else {
            if (move_uploaded_file($_FILES["image"]["tmp_name"], $image_file) &&
                move_uploaded_file($_FILES["timetable_image"]["tmp_name"], $timetable_file)) {

                $stmt = $conn->prepare("INSERT INTO classes (name, image_path, timetable_image_path) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $name, $image_file, $timetable_file);
                if ($stmt->execute()) {
                    $success_message = "Classe ajoutée avec succès.";

                    // Log admin
                    log_admin_action(
                        $conn,
                        $current_admin_id,
                        'add_class',
                        "Ajout de la classe $name",
                        $conn->insert_id,
                        'class',
                        $name,
                        null,
                        json_encode([
                            'name' => $name,
                            'image_path' => $image_file,
                            'timetable_image_path' => $timetable_file
                        ])
                    );
                } else {
                    $error_message = "Erreur lors de l'ajout : " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "Erreur lors du téléchargement des fichiers.";
            }
        }
    }
}

// Mise à jour d'une classe
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_class'])) {
    $class_id = $_POST['class_id'];
    $name = trim($_POST['name']);
    $image_path = $_POST['existing_image'];
    $timetable_image_path = $_POST['existing_timetable_image'];

    // Récupérer l'ancien état pour le log
    $old_stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
    $old_stmt->bind_param("i", $class_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    if (empty($name)) {
        $error_message = "Veuillez remplir tous les champs.";
    } else {
        $target_dir = "../uploads/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

        if (!empty($_FILES['image']['name'])) {
            $file = $target_dir . basename($_FILES["image"]["name"]);
            if (move_uploaded_file($_FILES["image"]["tmp_name"], $file)) $image_path = $file;
        }
        if (!empty($_FILES['timetable_image']['name'])) {
            $file = $target_dir . basename($_FILES["timetable_image"]["name"]);
            if (move_uploaded_file($_FILES["timetable_image"]["tmp_name"], $file)) $timetable_image_path = $file;
        }

        $stmt = $conn->prepare("UPDATE classes SET name = ?, image_path = ?, timetable_image_path = ? WHERE id = ?");
        $stmt->bind_param("sssi", $name, $image_path, $timetable_image_path, $class_id);
        if ($stmt->execute()) {
            $success_message = "Classe modifiée avec succès.";

            // Log admin
            log_admin_action(
                $conn,
                $current_admin_id,
                'edit_class',
                "Modification de la classe ID $class_id",
                $class_id,
                'class',
                $name,
                json_encode($old_result),
                json_encode([
                    'name' => $name,
                    'image_path' => $image_path,
                    'timetable_image_path' => $timetable_image_path
                ])
            );
        } else {
            $error_message = "Erreur lors de la modification : " . $stmt->error;
        }
        $stmt->close();
    }
}

// Suppression d'une classe
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_class'])) {
    $class_id = $_POST['class_id'];

    // Récupérer l'ancien état pour le log
    $old_stmt = $conn->prepare("SELECT * FROM classes WHERE id = ?");
    $old_stmt->bind_param("i", $class_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    // Supprimer les fichiers
    if (file_exists($old_result['image_path'])) unlink($old_result['image_path']);
    if (file_exists($old_result['timetable_image_path'])) unlink($old_result['timetable_image_path']);

    $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
    $stmt->bind_param("i", $class_id);
    if ($stmt->execute()) {
        $success_message = "Classe supprimée avec succès.";

        // Log admin
        log_admin_action(
            $conn,
            $current_admin_id,
            'delete_class',
            "Suppression de la classe ID $class_id",
            $class_id,
            'class',
            $old_result['name'],
            json_encode($old_result),
            null
        );
    } else {
        $error_message = "Erreur lors de la suppression : " . $stmt->error;
    }
    $stmt->close();
}
?>


<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Classes - Tableau de Bord Administrateur</title>
    
</head>
<?php include '../includes/header.php'; ?>

<body>
    

    <main>
        <h2>Gestion des Classes</h2>
        <div style="margin-bottom:16px;">
            <a href="gestion_filieres.php"
               style="display:inline-flex;align-items:center;gap:7px;padding:8px 16px;background:rgba(3,155,229,0.15);border:1px solid rgba(3,155,229,0.4);border-radius:6px;color:#039be5;text-decoration:none;font-weight:600;font-size:.88rem;transition:background .2s;"
               onmouseover="this.style.background='rgba(3,155,229,0.28)'"
               onmouseout="this.style.background='rgba(3,155,229,0.15)'">
                <i class="fas fa-sitemap"></i> Gérer les filières &amp; cheminements
            </a>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <h3>Ajouter une nouvelle classe</h3>
            <input type="text" name="name" placeholder="Nom de la classe" required>
            <input type="file" name="image" accept="image/*" required>
            <input type="file" name="timetable_image" accept="image/*" required>
            <input type="submit" name="add_class" value="Ajouter la Classe">
        </form>

        <h3>Liste des Classes</h3>
        <?php
        if ($error_message) {
            echo "<div class='error'>$error_message</div>";
        }
        if ($success_message) {
            echo "<div class='success'>$success_message</div>";
        }

        $sql = "SELECT * FROM classes";
        $result = $conn->query($sql);

        if ($result->num_rows > 0) {
            echo "<table>
                    <tr>
                        <th>ID</th>
                        <th>Nom</th>
                        <th>Image</th>
                        <th>Emploi du Temps</th>
                        <th>Actions</th>
                    </tr>";
            while ($row = $result->fetch_assoc()) {
                echo "<tr>
                <form method='POST' enctype='multipart/form-data'>
                    <input type='hidden' name='csrf_token' value='" . htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) . "'>
                    <td>{$row['id']}</td>
                    <td><input type='text' name='name' value='{$row['name']}' required></td>
                    <td><img src='{$row['image_path']}' alt='Image de la classe' width='100'><br>
                        <input type='file' name='image' accept='image/*'></td>
                    <td><img src='{$row['timetable_image_path']}' alt='Emploi du temps' width='100'><br>
                        <input type='file' name='timetable_image' accept='image/*'></td>
                    <td>
                        <input type='hidden' name='class_id' value='{$row['id']}'>
                        <input type='hidden' name='existing_image' value='{$row['image_path']}'>
                        <input type='hidden' name='existing_timetable_image' value='{$row['timetable_image_path']}'>
                        <input type='submit' name='edit_class' value='Enregistrer'>
                        <input type='submit' name='delete_class' value='Supprimer' onclick='return confirm(\"Êtes-vous sûr de vouloir supprimer cette classe ?\");'>
                    </td>
                </form>
            </tr>";
    }
    echo "</table>";
} else {
    echo "Aucune classe trouvée.";
}
?>
</main>

<?php include '../includes/footer.php'; ?>

</body>
</html>

<?php
$conn->close();
?>
<style>
.dropdown {
    position: relative;
    display: inline-block;
}

.dropdown-content {
    display: none;
    position: absolute;
    background-color: blue;
    min-width: 160px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.2);
    z-index: 1;
}

.dropdown:hover .dropdown-content {
    display: block;
}

.dropdown-content a {
    color: #333;
    padding: 12px 16px;
    text-decoration: none;
    display: block;
}

.dropdown-content a:hover {
    background-color: #BF4444FF;
}

/* Variables globales */
:root {
    --primary-bg: #051e34;
    --secondary-bg: #0c2d48;
    --accent-color: #039be5;
    --text-light: #ffffff;
    --border-color: rgba(255, 255, 255, 0.1);
    --error-color: #e74c3c;
    --success-color: #2ecc71;
}

/* Corps de la page */
body {
    margin: 0;
    font-family: 'Google Sans', Arial, sans-serif;
    background-color: var(--primary-bg);
    color: var(--text-light);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* En-tête */
header.navbar {
    background: var(--secondary-bg);
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border-color);
}

header.navbar .logo h1 {
    color: var(--text-light);
    margin: 0;
}

header.navbar nav ul {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    gap: 20px;
}

header.navbar nav ul li {
    position: relative;
}

header.navbar nav ul li a {
    text-decoration: none;
    color: var(--text-light);
    padding: 8px 16px;
    border-radius: 5px;
    transition: background 0.3s;
}

header.navbar nav ul li a:hover {
    background: var(--accent-color);
}

header.navbar nav ul li .dropdown-content {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    background: var(--secondary-bg);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    border-radius: 5px;
    overflow: hidden;
    z-index: 1000;
}

header.navbar nav ul li:hover .dropdown-content {
    display: block;
}

header.navbar nav ul li .dropdown-content a {
    display: block;
    padding: 10px 15px;
    text-decoration: none;
    color: var(--text-light);
    transition: background 0.3s;
}

header.navbar nav ul li .dropdown-content a:hover {
    background: var(--accent-color);
}

/* Contenu principal */
main {
    padding: 20px;
    flex: 1;
}

main h2 {
    margin-bottom: 20px;
}

/* Messages de feedback */
.error, .success {
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 20px;
    font-weight: bold;
}

.error {
    background: var(--error-color);
    color: var(--text-light);
}

.success {
    background: var(--success-color);
    color: var(--text-light);
}

/* Formulaires */
form {
    margin-bottom: 30px;
    background: rgba(255, 255, 255, 0.1);
    padding: 20px;
    border-radius: 10px;
    border: 1px solid var(--border-color);
}

form h3 {
    margin-bottom: 15px;
}

form input[type="text"], form input[type="file"], form input[type="submit"] {
    width: 100%;
    margin-bottom: 15px;
    padding: 10px;
    border-radius: 5px;
    border: 1px solid var(--border-color);
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-light);
}

form input[type="submit"] {
    background: var(--accent-color);
    color: var(--text-light);
    border: none;
    cursor: pointer;
    transition: background 0.3s;
}

form input[type="submit"]:hover {
    background: #0288d1;
}

/* Tableaux */
main table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    overflow: hidden;
}

main table th {
    background: var(--secondary-bg);
    padding: 12px;
    text-align: left;
    color: var(--text-light);
}

main table td {
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-light);
}

main table img {
    border-radius: 5px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    max-width: 100px;
    height: auto;
}

main table input[type="text"], main table input[type="file"] {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid var(--border-color);
    color: var(--text-light);
    padding: 5px;
    border-radius: 5px;
    width: 100%;
    margin-bottom: 10px;
}

main table button {
    padding: 5px 10px;
    border: none;
    border-radius: 5px;
    background: var(--accent-color);
    color: var(--text-light);
    cursor: pointer;
    transition: background 0.3s;
}

main table button:hover {
    background: #0288d1;
}

</style>