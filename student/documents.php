<?php
// student/documents.php
include '../includes/db_connect.php';
include '../includes/header.php';
session_start(); // Assurez-vous que la session est démarrée

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['document'])) {
    $target_dir = "../uploads/"; // Dossier où les fichiers seront uploadés
    $target_file = $target_dir . basename($_FILES["document"]["name"]);
    $uploadOk = 1;
    $fileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    // Vérification des conditions d'upload
    if (file_exists($target_file)) {
        echo "Désolé, ce fichier existe déjà.";
        $uploadOk = 0;
    }
    if ($_FILES["document"]["size"] > 5000000) { // Limite à 5 Mo
        echo "Désolé, votre fichier est trop gros.";
        $uploadOk = 0;
    }
    if ($fileType != "pdf" && $fileType != "doc" && $fileType != "docx") {
        echo "Désolé, seuls les fichiers PDF, DOC et DOCX sont autorisés.";
        $uploadOk = 0;
    }

    // Essayez de télécharger le fichier si tout est bon
    if ($uploadOk == 1) {
        if (move_uploaded_file($_FILES["document"]["tmp_name"], $target_file)) {
            // Enregistrer les informations dans la base de données
            $uploaded_by = $_SESSION['user_id'];
            $sql = "INSERT INTO documents (file_path, uploaded_by, course_id) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $target_file, $uploaded_by, $course_id);
            if ($stmt->execute()) {
                echo "Le fichier ". htmlspecialchars(basename($_FILES["document"]["name"])). " a été téléchargé.";
            } else {
                echo "Erreur : " . $stmt->error;
            }
        } else {
            echo "Désolé, une erreur est survenue lors du téléchargement de votre fichier.";
        }
    }
}

// Récupérer les documents pour un cours donné
$sql = "SELECT * FROM documents WHERE course_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $course_id);
$stmt->execute();
$result = $stmt->get_result();

?>

<h2>Documents</h2>
<div class="documents-section">
    <form action="documents.php" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
        <input type="file" name="document" required>
        <button type="submit">Télécharger un document</button>
    </form>
    
    <?php while ($row = $result->fetch_assoc()): ?>
        <div class="document-item">
            <a href="<?= htmlspecialchars($row['file_path']) ?>"><?= htmlspecialchars($row['file_path']) ?></a>
            <span>Partagé par : <?= htmlspecialchars($row['uploaded_by']) ?></span>
        </div>
    <?php endwhile; ?>
</div>

<?php
include '../includes/footer.php';
?>
