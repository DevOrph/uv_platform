<?php
session_start(); 

// Vérifier si l'utilisateur est connecté et est un enseignant
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'teacher') {
    header("Location: ../pages/login.html");
    exit();
}

// Récupérer l'ID de l'enseignant
$teacher_id = $_SESSION['user_id'];

// Connexion à la base de données (config .env via includes/db_config.php)
require_once '../includes/db_config.php';

// Connexion PDO
try {
    $pdo = get_pdo_connection();

    // Définir le charset et la collation
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    
    // Récupérer les classes du professeur
    $query = "SELECT DISTINCT cl.id, cl.name, cl.image_path
              FROM classes cl
              INNER JOIN courses co ON JSON_CONTAINS(co.class_id, JSON_QUOTE(CAST(cl.id AS CHAR)), '$')
              WHERE co.teacher_id = :teacher_id";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_STR);
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pour le débogage
    if (empty($classes)) {
        error_log("Aucune classe trouvée pour l'enseignant avec ID: $teacher_id");
    } else {
        error_log("Nombre de classes trouvées: " . count($classes));
    }
    
    // Récupérer les annonces
$announcementsQuery = "SELECT * FROM announcements 
                       WHERE (announcement_type = 'global' 
                              OR (announcement_type = 'class' AND JSON_CONTAINS((SELECT GROUP_CONCAT(class_id SEPARATOR ',') FROM courses WHERE teacher_id = :teacher_id), JSON_QUOTE(class_id), '$'))
                              OR (announcement_type = 'teacher'))
                       ORDER BY created_at DESC";
    $announcementsStmt = $pdo->prepare($announcementsQuery);
    $announcementsStmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_STR);
    $announcementsStmt->execute();
    $announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer l'avatar de l'enseignant
    $teacherQuery = "SELECT avatar FROM users WHERE id = :teacher_id";
    $teacherStmt = $pdo->prepare($teacherQuery);
    $teacherStmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_STR);
    $teacherStmt->execute();
    $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// Gestion de la recherche
if (isset($_GET['search'])) {
    $searchTerm = '%' . $_GET['search'] . '%';
    $query = "SELECT DISTINCT cl.id, cl.name, cl.image_path
              FROM classes cl
              INNER JOIN courses co ON JSON_CONTAINS(co.class_id, JSON_QUOTE(CAST(cl.id AS CHAR)), '$')
              WHERE co.teacher_id = :teacher_id
              AND cl.name LIKE :searchTerm";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_STR);
    $stmt->bindParam(':searchTerm', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        ob_start();
        if (!empty($classes)) {
            foreach ($classes as $class) {
                ?>
                <div class="class-card" onclick="window.location.href='courses.php?class_id=<?php echo $class['id']; ?>'">
                    <img src="<?php echo htmlspecialchars($class['image_path'] ?: 'default_image.jpg'); ?>" alt="Class Image">
                    <div class="class-info">
                        <h3><?php echo htmlspecialchars($class['name']); ?></h3>
                    </div>
                </div>
                <?php
            }
        } else {
            echo "<p>Aucune classe trouvée.</p>";
        }
        $output = ob_get_clean();
        echo $output;
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Classes - Enseignant</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
    /* REMPLACEZ TOUT VOTRE CSS EXISTANT PAR CELUI-CI */

:root {
    --primary-bg: #051e34;
    --secondary-bg: #0c2d48;
    --accent-color: #039be5;
    --text-light: #ffffff;
    --border-color: rgba(255, 255, 255, 0.1);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Google Sans', Arial, sans-serif;
    background-color: var(--primary-bg);
    color: var(--text-light);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    overflow-x: hidden; /* Évite le scroll horizontal */
}

/* Header */
header {
    background: var(--secondary-bg);
    padding: 15px 0;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    border-bottom: 1px solid var(--border-color);
    position: relative;
    width: 100%;
}

header::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: linear-gradient(to right, #039be5, #4CAF50, #039be5);
    animation: shimmer 2s infinite linear;
}

.header-content {
    max-width: 1400px;
    margin: 0 auto;
    padding: 0 20px;
    position: relative;
    overflow: hidden;
}

h1 {
    font-size: 24px;
    color: var(--accent-color);
    margin: 0 0 20px 0;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

nav {
    display: flex;
    justify-content: center;
}

nav ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    justify-content: center;
}

nav a {
    color: var(--text-light);
    text-decoration: none;
    padding: 8px 16px;
    border-radius: 5px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    position: relative;
}

nav a::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 2px;
    background: var(--accent-color);
    transform: scaleX(0);
    transition: transform 0.3s ease;
    transform-origin: right;
}

nav a:hover::before {
    transform: scaleX(1);
    transform-origin: left;
}

nav a:hover {
    background: rgba(3, 155, 229, 0.1);
    transform: translateY(-2px);
}

nav a[href*="logout"] {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

nav a[href*="logout"]:hover {
    background: rgba(220, 53, 69, 0.2);
}

.user-settings {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    gap: 15px;
}

.user-settings input {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid var(--border-color);
    color: var(--text-light);
    padding: 8px 15px;
    border-radius: 5px;
    width: 200px;
    transition: all 0.3s ease;
}

.user-settings input:focus {
    outline: none;
    border-color: var(--accent-color);
    background: rgba(255, 255, 255, 0.15);
}

.user-settings input::placeholder {
    color: rgba(255, 255, 255, 0.5);
}

.user-icon {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    border: 2px solid var(--accent-color);
    transition: transform 0.3s ease;
    cursor: pointer;
    object-fit: cover;
}

.user-icon:hover {
    transform: scale(1.1);
}

/* Icônes flottantes */
.floating-icons {
    position: absolute;
    width: 100%;
    height: 100%;
    top: 0;
    left: 0;
    pointer-events: none;
    z-index: 1;
}

.floating-icon {
    position: absolute;
    font-size: 20px;
    color: var(--accent-color);
    opacity: 0;
    animation: floatIcon 3s ease-in-out infinite;
}

.floating-icon:nth-child(1) { left: 10%; top: 20%; animation-delay: 0s; }
.floating-icon:nth-child(2) { left: 30%; top: 60%; animation-delay: 0.5s; }
.floating-icon:nth-child(3) { left: 50%; top: 30%; animation-delay: 1s; }
.floating-icon:nth-child(4) { left: 70%; top: 50%; animation-delay: 1.5s; }
.floating-icon:nth-child(5) { left: 90%; top: 40%; animation-delay: 2s; }

/* Dashboard */
.dashboard-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
    width: 100%;
}

h2 {
    text-align: center;
    padding: 20px;
    color: var(--accent-color);
    font-size: 26px;
}

.class-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 25px;
    padding: 20px;
    width: 100%;
}

.class-card {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    cursor: pointer;
    aspect-ratio: 1 / 1; /* IMPORTANT: Garde les cartes carrées */
    display: flex;
    flex-direction: column;
}

.class-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
}

.class-card img {
    width: 100%;
    height: 70%; /* 70% de la hauteur de la carte */
    object-fit: cover;
}

.class-info {
    padding: 15px;
    height: 30%; /* 30% de la hauteur de la carte */
    display: flex;
    align-items: center;
    justify-content: center;
}

.class-info h3 {
    margin: 0;
    color: var(--text-light);
    font-size: 18px;
    text-align: center;
}

/* Announcements */
.announcements-ticker {
    background: var(--secondary-bg);
    margin: 20px auto;
    max-width: 1400px;
    width: calc(100% - 40px);
    height: 60px;
    overflow: hidden;
    border-radius: 10px;
    border: 1px solid var(--border-color);
}

.announcements-content {
    height: 100%;
    display: flex;
    align-items: center;
    animation: ticker 20s linear infinite;
    padding: 0 20px;
    white-space: nowrap;
}

.announcement-item {
    white-space: nowrap;
    margin-right: 50px;
    color: var(--text-light);
}

/* Footer */
footer {
    margin-top: auto;
    background: var(--secondary-bg);
    padding: 20px;
    text-align: center;
    border-top: 1px solid var(--border-color);
    width: 100%;
}

/* Animations */
@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

@keyframes ticker {
    0% { transform: translateX(100%); }
    100% { transform: translateX(-100%); }
}

@keyframes floatIcon {
    0% {
        transform: translateY(100%);
        opacity: 0;
    }
    50% {
        opacity: 0.3;
    }
    100% {
        transform: translateY(-100%);
        opacity: 0;
    }
}

/* ========== RESPONSIVE DESIGN ========== */

/* Tablettes (portrait) */
@media (max-width: 1024px) {
    .header-content {
        padding: 0 15px;
    }

    .class-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
        padding: 15px;
    }

    .user-settings input {
        width: 150px;
    }
}

/* Tablettes et mobiles */
@media (max-width: 768px) {
    body {
        font-size: 14px;
    }

    /* Header */
    h1 {
        font-size: 20px;
        margin: 0 0 15px 0;
    }

    /* Navigation en colonne */
    nav ul {
        flex-direction: column;
        gap: 10px;
        width: 100%;
    }

    nav li {
        width: 100%;
    }

    nav a {
        width: 100%;
        justify-content: center;
        padding: 12px 16px;
    }

    /* User settings sous la navigation */
    .user-settings {
        position: static;
        transform: none;
        margin-top: 15px;
        justify-content: center;
        flex-direction: row;
        width: 100%;
    }

    .user-settings input {
        width: calc(100% - 60px);
        max-width: 300px;
    }

    /* Dashboard */
    .dashboard-container {
        padding: 15px 10px;
    }

    h2 {
        font-size: 22px;
        padding: 15px 10px;
    }

    /* Grid en une colonne sur mobile */
    .class-grid {
        grid-template-columns: 1fr;
        gap: 15px;
        padding: 10px;
    }

    .class-card {
        aspect-ratio: 1 / 1; /* Reste carré */
        max-width: 500px; /* Limite la taille sur mobile */
        margin: 0 auto; /* Centre les cartes */
    }

    .class-info h3 {
        font-size: 16px;
    }

    /* Announcements */
    .announcements-ticker {
        height: 50px;
        margin: 15px 10px;
        width: calc(100% - 20px);
    }

    .announcements-content {
        font-size: 14px;
        padding: 0 15px;
    }

    .announcement-item {
        margin-right: 30px;
    }

    /* Masquer les icônes flottantes sur mobile */
    .floating-icons {
        display: none;
    }

    /* Footer */
    footer {
        padding: 15px 10px;
        font-size: 13px;
    }
}

/* Petits mobiles */
@media (max-width: 480px) {
    h1 {
        font-size: 18px;
        flex-direction: column;
        gap: 5px;
    }

    h1 i {
        font-size: 20px;
    }

    h2 {
        font-size: 20px;
        padding: 12px 10px;
    }

    nav a {
        font-size: 14px;
        padding: 10px 12px;
    }

    .class-grid {
        padding: 5px;
        gap: 12px;
    }

    .class-card {
        max-width: 400px;
    }

    .class-info {
        padding: 12px;
    }

    .class-info h3 {
        font-size: 15px;
    }

    .announcements-ticker {
        height: 45px;
    }

    .announcements-content {
        font-size: 13px;
    }

    .user-icon {
        width: 30px;
        height: 30px;
    }

    .user-settings input {
        font-size: 14px;
        padding: 8px 12px;
    }
}

/* Très grands écrans */
@media (min-width: 1400px) {
    .class-grid {
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 30px;
    }
}
    
</style>
</head>
<body>
    <header>
        
        <div class="header-content">
        <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
            <nav>
                <ul>
                    <li><a href="teacher_dashboard.php"><i class="fas fa-home"></i> Accueil</a></li>
                    <li><a href="grades_management.php"><i class="fas fa-chart-line"></i> Notes</a></li>
                    <li><a href="quiz_manage.php"><i class="fas fa-question-circle"></i> Mes Quiz</a></li>
                    <li><a href="rattrapage_saisie.php"><i class="fas fa-redo"></i> Rattrapage</a></li>
                    <li><a href="teacher_profile.php"><i class="fas fa-user"></i> Profil</a></li>
                    <li><a href="mes_honoraires.php"><i class="fas fa-money-bill-wave"></i> Mes Honoraires</a></li>
                    <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
                </ul>
            </nav>
            <div class="user-settings">
                <img src="../uploads/avatars/<?php echo htmlspecialchars(string: $teacher['avatar']); ?>" class="user-icon" alt="User Avatar" href="teacher_profile.php">
            </div>
        </div>
        <div class="floating-icons">
            <i class="floating-icon fas fa-graduation-cap"></i>
            <i class="floating-icon fas fa-book"></i>
            <i class="floating-icon fas fa-chart-line"></i>
            <i class="floating-icon fas fa-user-graduate"></i>
            <i class="floating-icon fas fa-chalkboard-teacher"></i>
        </div>
    </header>

    <div class="dashboard-container">
        <h2>Mes Classes</h2>
        <div class="class-grid" id="classGrid">
            <?php if (!empty($classes)): ?>
                <?php foreach ($classes as $class): ?>
                    <div class="class-card" onclick="window.location.href='courses.php?class_id=<?php echo $class['id']; ?>'">
                        <img src="<?php echo htmlspecialchars($class['image_path'] ?: 'default_image.jpg'); ?>" alt="Class Image">
                        <div class="class-info">
                            <h3><?php echo htmlspecialchars($class['name']); ?></h3>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Aucune classe trouvée.</p>
            <?php endif; ?>
        </div>

        <div class="announcements-ticker">
            <div class="announcements-content">
                <?php foreach ($announcements as $announcement): ?>
                    <div class="announcement-item">
                        <?php echo htmlspecialchars($announcement['content']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>


    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Animation des liens au survol
    const navLinks = document.querySelectorAll('nav a');
    navLinks.forEach(link => {
        link.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        link.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });

    // Animation de l'icône utilisateur
    const userIcon = document.querySelector('.user-icon');
    if (userIcon) {
        userIcon.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.1)';
        });
        userIcon.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    }

    // Fonction de recherche
    function searchClasses(searchTerm) {
        const xhr = new XMLHttpRequest();
        xhr.open('GET', '?search=' + encodeURIComponent(searchTerm), true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                const grid = document.getElementById('classGrid');
                grid.style.opacity = '0';
                setTimeout(() => {
                    grid.innerHTML = xhr.responseText;
                    grid.style.opacity = '1';
                }, 300);
            }
        };
        xhr.send();
    }

    // Animation du ticker d'annonces
    const content = document.querySelector('.announcements-content');
    if (content) {
        content.innerHTML += content.innerHTML;
    }
    // Animation des icônes flottantes
    const header = document.querySelector('header');
    const floatingIcons = document.querySelectorAll('.floating-icon');

    header.addEventListener('mouseenter', () => {
        floatingIcons.forEach(icon => {
            icon.style.opacity = '1';
        });
    });

    header.addEventListener('mouseleave', () => {
        floatingIcons.forEach(icon => {
            icon.style.opacity = '0';
        });
    });

    // Fonction pour réinitialiser les animations
    function resetAnimation(element) {
        element.style.animation = 'none';
        element.offsetHeight; // Déclenche un reflow
        element.style.animation = null;
    }

    // Réinitialiser les animations lorsque le header est survolé à nouveau
    header.addEventListener('mouseenter', () => {
        floatingIcons.forEach(icon => {
            resetAnimation(icon);
        });
    });
});
</script>
</body>
</html>