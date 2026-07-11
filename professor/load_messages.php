<?php
// Connexion locale (config .env via includes/db_config.php)
require_once __DIR__ . '/../includes/db_config.php';
try {
    $conn = get_db_connection();
} catch (RuntimeException $e) {
    die("Échec de connexion : " . $e->getMessage());
}

// Année académique courante (calcul inline — évite une dépendance circulaire)
if (!defined('ANNEE_ACADEMIQUE_COURANTE')) {
    $m     = (int) date('n');
    $y     = (int) date('Y');
    $annee = $m >= 9 ? "$y-" . ($y + 1) : ($y - 1) . "-$y";
    $r     = $conn->query("SELECT valeur FROM parametres WHERE cle = 'annee_academique_courante' LIMIT 1");
    if ($r && $r->num_rows > 0) {
        $forced = trim($r->fetch_assoc()['valeur']);
        if ($forced !== '') $annee = $forced;
    }
    define('ANNEE_ACADEMIQUE_COURANTE', $annee);
}

if (!isset($_GET['course_id'])) {
    echo '<div class="error-message">ID du cours non spécifié.</div>';
    $conn->close();
    exit();
}

$course_id = intval($_GET['course_id']);

// Filtre par année (archive ou courante)
$filter_year = (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year']))
    ? $_GET['year']
    : ANNEE_ACADEMIQUE_COURANTE;

$sql = "
    SELECT d.id, d.sender_id, d.message, d.created_at, u.name, u.avatar
    FROM discussions d
    JOIN users u ON d.sender_id = u.id
    LEFT JOIN documents doc ON doc.discussion_id = d.id
    WHERE d.course_id = ? AND d.academic_year = ?
    ORDER BY d.created_at ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $course_id, $filter_year);
$stmt->execute();
$messages = $stmt->get_result();

if ($messages->num_rows > 0) {
    while ($row = $messages->fetch_assoc()) {
        $date           = new DateTime($row["created_at"]);
        $formatted_date = $date->format('d/m/Y à H:i');

        $avatar_path = !empty($row["avatar"])
            ? "../uploads/avatars/" . htmlspecialchars($row["avatar"])
            : "../assets/img/default-avatar.png";

        echo '<div class="message-card">';
        echo '<img src="' . $avatar_path . '" alt="Photo de profil">';
        echo '<div class="message-info">';
        echo '<h4>' . htmlspecialchars($row["name"]) . '</h4>';
        echo '<p>' . nl2br(htmlspecialchars($row["message"])) . '</p>';
        echo '<em>' . $formatted_date . '</em>';
        echo '</div>';
        echo '</div>';
    }
} else {
    echo '<div class="no-messages">Aucun message dans cette discussion. Soyez le premier à poster !</div>';
}

$stmt->close();
$conn->close();
?>
