<?php
require_once '../includes/db_connect.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.html");
    exit();
}

// Gestion de la persistance du semestre pour l'enseignant
if (isset($_GET['semester'])) {
    $_SESSION['teacher_selected_semester'] = (int)$_GET['semester'];
    $_SESSION['message'] = [
        'type' => 'success',
        'text' => 'Semestre ' . $_GET['semester'] . ' sélectionné avec succès!'
    ];
}

// Utiliser le semestre sauvegardé ou par défaut le semestre 1
$semester = isset($_SESSION['teacher_selected_semester']) ? $_SESSION['teacher_selected_semester'] : 1;

require_once '../includes/db_config.php';
try {
    $pdo = get_pdo_connection(); // config .env (includes/db_config.php)
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

$teacher_id = $_SESSION['user_id'];
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;

// Gestion flexible de class_id
if ($class_id !== null) {
    // Requête pour une classe spécifique avec filtre par semestre
    $query_courses = "SELECT id, name, major, image_path, semester 
                      FROM courses 
                      WHERE teacher_id = :teacher_id 
                      AND semester = :semester 
                      AND JSON_CONTAINS(class_id, JSON_QUOTE(CAST(:class_id AS CHAR)), '$')";
    
    $stmt_courses = $pdo->prepare($query_courses);
    $stmt_courses->bindParam(':teacher_id', $teacher_id, PDO::PARAM_STR);
    $stmt_courses->bindParam(':semester', $semester, PDO::PARAM_INT);
    $stmt_courses->bindParam(':class_id', $class_id, PDO::PARAM_INT);
} else {
    // Afficher tous les cours de l'enseignant pour ce semestre
    $query_courses = "SELECT id, name, major, image_path, semester 
                      FROM courses 
                      WHERE teacher_id = :teacher_id 
                      AND semester = :semester";
    
    $stmt_courses = $pdo->prepare($query_courses);
    $stmt_courses->bindParam(':teacher_id', $teacher_id, PDO::PARAM_STR);
    $stmt_courses->bindParam(':semester', $semester, PDO::PARAM_INT);
}

$stmt_courses->execute();
$courses = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);

// Si aucun cours trouvé, message informatif
if (empty($courses)) {
    $context = $class_id ? "cette classe" : "vos classes";
    $_SESSION['message'] = [
        'type' => 'info',
        'text' => "Aucun cours trouvé pour le semestre $semester dans $context."
    ];
}

$query_announcements = "SELECT content FROM announcements 
                        WHERE announcement_type = 'global' 
                        OR announcement_type = 'teacher'";
$stmt_announcements = $pdo->prepare($query_announcements);
$stmt_announcements->execute();
$announcements = $stmt_announcements->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les informations de la classe si class_id est fourni
$class = null;
if ($class_id !== null) {
    $query_classes = "SELECT id, name, timetable_image_path 
                      FROM classes 
                      WHERE id = :class_id";
    $stmt_classes = $pdo->prepare($query_classes);
    $stmt_classes->bindParam(':class_id', $class_id, PDO::PARAM_INT);
    $stmt_classes->execute();
    $class = $stmt_classes->fetch(PDO::FETCH_ASSOC);
}

// Récupérer toutes les classes associées aux cours de l'enseignant
$query_teacher_classes = "SELECT DISTINCT c.id, c.name, c.timetable_image_path
                          FROM classes c
                          INNER JOIN courses co ON JSON_CONTAINS(co.class_id, JSON_QUOTE(CAST(c.id AS CHAR)), '$')
                          WHERE co.teacher_id = :teacher_id";
$stmt_teacher_classes = $pdo->prepare($query_teacher_classes);
$stmt_teacher_classes->bindParam(':teacher_id', $teacher_id, PDO::PARAM_STR);
$stmt_teacher_classes->execute();
$teacher_classes = $stmt_teacher_classes->fetchAll(PDO::FETCH_ASSOC);

// Récupérer l'avatar de l'enseignant
$teacherQuery = "SELECT avatar FROM users WHERE id = :teacher_id";
$teacherStmt = $pdo->prepare($teacherQuery);
$teacherStmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_STR);
$teacherStmt->execute();
$teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Cours - Enseignant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* REMPLACEZ TOUT LE CSS DANS LA SECTION <style> PAR CELUI-CI */

:root {
    --primary-bg: #051e34;
    --secondary-bg: #0c2d48;
    --accent-color: #039be5;
    --text-light: #ffffff;
    --border-color: rgba(255, 255, 255, 0.1);
    --error-color: #dc3545;
    --success-color: #28a745;
    --info-color: #17a2b8;
    --warning-color: #ffc107;
    --drawer-bg: #0c2d48;
    --transition: all 0.3s ease;
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
    overflow-x: hidden;
}

/* ========== TOAST NOTIFICATIONS ========== */
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 400px;
}

.toast {
    padding: 15px 20px;
    border-radius: 8px;
    color: white;
    font-weight: 500;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    gap: 10px;
    transform: translateX(100%);
    animation: slideIn 0.3s ease forwards;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}

.toast.success {
    background: linear-gradient(135deg, var(--success-color), #2ecc71);
}

.toast.error {
    background: linear-gradient(135deg, var(--error-color), #c0392b);
}

.toast.warning {
    background: linear-gradient(135deg, var(--warning-color), #e67e22);
}

.toast.info {
    background: linear-gradient(135deg, var(--info-color), #2980b9);
}

.toast::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    height: 3px;
    background: rgba(255, 255, 255, 0.3);
    animation: progress 5s linear forwards;
}

.toast .toast-icon {
    font-size: 18px;
}

.toast .toast-close {
    margin-left: auto;
    cursor: pointer;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast .toast-close:hover {
    opacity: 1;
}

/* ========== HEADER ========== */
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
    display: flex;
    flex-direction: column;
    align-items: center;
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

/* Navigation */
nav {
    display: flex;
    justify-content: center;
    width: 100%;
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
    transition: var(--transition);
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

/* User Settings */
.user-settings {
    position: absolute;
    right: 80px;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    gap: 15px;
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

/* Drawer Toggle */
.drawer-toggle {
    background: linear-gradient(135deg, var(--accent-color), #0277bd);
    border: none;
    color: var(--text-light);
    padding: 10px 20px;
    border-radius: 50px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    font-weight: 500;
    box-shadow: 0 2px 8px rgba(3, 155, 229, 0.3);
    transition: var(--transition);
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
}

.drawer-toggle:hover {
    transform: translateY(-50%) translateY(-2px);
    box-shadow: 0 4px 12px rgba(3, 155, 229, 0.4);
    background: linear-gradient(135deg, #0277bd, var(--accent-color));
}

/* Floating Icons */
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

/* ========== DASHBOARD CONTENT ========== */
.dashboard-content {
    padding: 30px;
    max-width: 1400px;
    margin: 0 auto;
    flex: 1;
    width: 100%;
}

.courses-header {
    text-align: center;
    margin: 15px 0;
    padding: 0 15px;
}

.courses-header h2 {
    color: var(--text-light);
    font-size: 2em;
    margin-bottom: 10px;
}

.semester-info {
    background: linear-gradient(135deg, var(--accent-color), var(--secondary-bg));
    color: white;
    padding: 10px 20px;
    border-radius: 25px;
    display: inline-block;
    margin-top: 10px;
    font-weight: 500;
}

.class-info-banner {
    background: linear-gradient(135deg, var(--secondary-bg), var(--accent-color));
    padding: 15px 25px;
    border-radius: 15px;
    margin-bottom: 20px;
    text-align: center;
    border: 1px solid var(--border-color);
}

.course-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 25px;
    padding: 20px 0;
    opacity: 0;
    transform: translateY(20px);
    animation: fadeIn 0.5s ease forwards;
    width: 100%;
}

.course-card {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    transition: var(--transition);
    cursor: pointer;
    border: 1px solid var(--border-color);
    position: relative;
    aspect-ratio: 1 / 1;
    display: flex;
    flex-direction: column;
}

.course-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--accent-color), var(--success-color));
    transform: scaleX(0);
    transition: transform 0.3s ease;
}

.course-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
}

.course-card:hover::before {
    transform: scaleX(1);
}

.course-card img {
    width: 100%;
    height: 70%;
    object-fit: cover;
}

.course-info {
    padding: 20px;
    height: 30%;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.course-info h3 {
    margin: 0 0 10px 0;
    color: var(--text-light);
    text-align: center;
}

.course-info p {
    color: var(--accent-color);
    margin: 0;
    font-weight: 500;
    text-align: center;
}

/* ========== ANNOUNCEMENTS ========== */
.announcements {
    background: var(--secondary-bg);
    border-radius: 15px;
    padding: 20px;
    margin-top: 30px;
    backdrop-filter: blur(10px);
    border: 1px solid var(--border-color);
    overflow: hidden;
    white-space: nowrap;
    width: 100%;
}

.announcement-content {
    display: flex;
    animation: scroll-left 20s linear infinite;
}

.announcement-item {
    flex-shrink: 0;
    padding: 0 20px;
    color: var(--text-light);
}

/* ========== DRAWER ========== */
.drawer {
    position: fixed;
    right: -400px;
    top: 0;
    width: 350px;
    height: 100%;
    background-color: var(--drawer-bg);
    box-shadow: -2px 0 10px rgba(0, 0, 0, 0.2);
    transition: var(--transition);
    padding: 20px;
    z-index: 1000;
    color: var(--text-light);
    overflow-y: auto;
}

.drawer.open {
    right: 0;
}

.close-drawer {
    position: absolute;
    top: 10px;
    right: 10px;
    background: none;
    border: none;
    font-size: 1.5rem;
    color: white;
    cursor: pointer;
    transition: var(--transition);
}

.close-drawer:hover {
    color: var(--error-color);
    transform: rotate(90deg);
}

.drawer h2 {
    font-size: 1.5rem;
    color: white;
    margin-bottom: 25px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--accent-color);
}

.drawer-section {
    margin-bottom: 25px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    transition: background-color 0.3s ease;
}

.drawer-section:hover {
    background: rgba(255, 255, 255, 0.15);
}

.drawer-section h3 {
    font-size: 1.1rem;
    color: white;
    margin-bottom: 15px;
}

.drawer-btn {
    background: linear-gradient(45deg, var(--accent-color), var(--secondary-bg));
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    margin: 10px 0;
    width: 100%;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: block;
    text-align: center;
    font-weight: 500;
}

.drawer-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    background: linear-gradient(45deg, var(--accent-color), var(--success-color));
}

.semester-select, .class-select {
    width: 100%;
    padding: 12px;
    border: 1px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px;
    background-color: rgba(255, 255, 255, 0.1);
    color: white;
    font-size: 0.95rem;
    margin-bottom: 15px;
    transition: var(--transition);
}

.semester-select:hover, .class-select:hover {
    border-color: var(--accent-color);
    background-color: rgba(255, 255, 255, 0.15);
}

.semester-select:focus, .class-select:focus {
    outline: none;
    border-color: var(--accent-color);
    box-shadow: 0 0 0 2px rgba(3, 155, 229, 0.3);
}

.semester-select option, .class-select option {
    background-color: var(--secondary-bg);
    color: white;
}

/* ========== EMPTY STATE ========== */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: rgba(255, 255, 255, 0.7);
}

.empty-state i {
    font-size: 48px;
    color: var(--accent-color);
    margin-bottom: 20px;
}

.empty-state h3 {
    font-size: 1.5em;
    margin-bottom: 10px;
    color: var(--text-light);
}

.empty-state p {
    font-size: 1em;
    line-height: 1.6;
}

/* ========== FOOTER ========== */
footer {
    background: var(--secondary-bg);
    color: var(--text-light);
    padding: 20px;
    text-align: center;
    margin-top: auto;
    border-top: 1px solid var(--border-color);
    width: 100%;
}

/* ========== ANIMATIONS ========== */
@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

@keyframes scroll-left {
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

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideIn {
    from { transform: translateX(100%); }
    to { transform: translateX(0); }
}

@keyframes slideOut {
    from { transform: translateX(0); }
    to { transform: translateX(100%); }
}

@keyframes progress {
    from { width: 100%; }
    to { width: 0%; }
}

/* ========== RESPONSIVE DESIGN ========== */

/* Tablettes (portrait) */
@media (max-width: 1024px) {
    .header-content {
        padding: 0 15px;
    }

    .course-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 20px;
    }

    .dashboard-content {
        padding: 20px 15px;
    }

    .drawer {
        width: 320px;
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

    /* User settings repositionné */
    .user-settings {
        position: static;
        transform: none;
        margin-top: 15px;
        justify-content: center;
        width: 100%;
    }

    /* Drawer toggle repositionné */
    .drawer-toggle {
        position: static;
        transform: none;
        margin: 15px auto 0;
    }

    .drawer-toggle span {
        display: inline;
    }

    /* Drawer pleine largeur */
    .drawer {
        width: 100%;
        right: -100%;
    }

    /* Dashboard */
    .dashboard-content {
        padding: 15px 10px;
    }

    .courses-header h2 {
        font-size: 1.5em;
    }

    .semester-info {
        font-size: 14px;
        padding: 8px 15px;
    }

    /* Grid en une colonne sur mobile */
    .course-grid {
        grid-template-columns: 1fr;
        gap: 15px;
        padding: 10px 0;
    }

    .course-card {
        aspect-ratio: 1 / 1;
        max-width: 500px;
        margin: 0 auto;
    }

    .course-info {
        padding: 15px;
    }

    .course-info h3 {
        font-size: 16px;
    }

    .course-info p {
        font-size: 14px;
    }

    /* Announcements */
    .announcements {
        padding: 15px;
        margin-top: 20px;
    }

    .announcement-item {
        font-size: 14px;
        padding: 0 15px;
    }

    /* Toast notifications */
    .toast-container {
        right: 10px;
        left: 10px;
        max-width: calc(100% - 20px);
    }

    .toast {
        font-size: 14px;
        padding: 12px 15px;
    }

    /* Masquer les icônes flottantes */
    .floating-icons {
        display: none;
    }

    /* Class info banner */
    .class-info-banner {
        padding: 12px 15px;
    }

    .class-info-banner h3 {
        font-size: 16px;
    }

    .class-info-banner p {
        font-size: 14px;
    }

    /* Drawer sections */
    .drawer-section {
        padding: 12px;
    }

    .drawer-section h3 {
        font-size: 1rem;
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

    .courses-header h2 {
        font-size: 1.3em;
    }

    .semester-info {
        font-size: 13px;
        padding: 6px 12px;
    }

    nav a {
        font-size: 14px;
        padding: 10px 12px;
    }

    .course-grid {
        padding: 5px 0;
        gap: 12px;
    }

    .course-card {
        max-width: 400px;
    }

    .course-info {
        padding: 12px;
    }

    .course-info h3 {
        font-size: 15px;
    }

    .course-info p {
        font-size: 13px;
    }

    .announcements {
        padding: 12px;
    }

    .announcement-item {
        font-size: 13px;
    }

    .user-icon {
        width: 30px;
        height: 30px;
    }

    .drawer-toggle {
        font-size: 14px;
        padding: 8px 16px;
    }

    .drawer h2 {
        font-size: 1.3rem;
    }

    .empty-state {
        padding: 40px 15px;
    }

    .empty-state i {
        font-size: 36px;
    }

    .empty-state h3 {
        font-size: 1.2em;
    }

    .empty-state p {
        font-size: 0.9em;
    }
}

/* Très grands écrans */
@media (min-width: 1400px) {
    .course-grid {
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 30px;
    }
}
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container" id="toast-container"></div>

    <header>
        <div class="header-content">
            <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
            <nav>
                <ul>
                    <li><a href="teacher_dashboard.php"><i class="fas fa-home"></i> Accueil</a></li>
                    <li><a href="grades_management.php"><i class="fas fa-chart-line"></i> Notes</a></li>
                    <li><a href="quiz_manage.php"><i class="fas fa-question-circle"></i> Mes Quiz</a></li>
                    <li><a href="../professor/teacher_schedule.php"><i class="fas fa-calendar-alt"></i> Mon emploi du temps</a></li>
                    <li><a href="teacher_profile.php"><i class="fas fa-user"></i> Profil</a></li>
                    <li><a href="../pages/logout.php" style="color: var(--error-color);"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
                </ul>
            </nav>
            
            <?php if ($teacher && $teacher['avatar']): ?>
            <div class="user-settings">
                <img src="../uploads/avatars/<?php echo htmlspecialchars($teacher['avatar']); ?>" class="user-icon" alt="User Avatar">
            </div>
            <?php endif; ?>
            
            <button class="drawer-toggle" id="drawer-toggle-right">
                <i class="fas fa-sliders-h"></i>
                <span>Menu</span>
            </button>
        </div>
        <div class="floating-icons">
            <i class="floating-icon fas fa-graduation-cap"></i>
            <i class="floating-icon fas fa-book"></i>
            <i class="floating-icon fas fa-chart-line"></i>
            <i class="floating-icon fas fa-user-graduate"></i>
            <i class="floating-icon fas fa-chalkboard-teacher"></i>
        </div>
    </header>

    <div class="dashboard-content">
        <div class="courses-header">
            <h2>Mes Cours</h2>
            <div class="semester-info">
                Semestre <?= $semester ?> 
                <?php if ($class): ?>
                    - <?= htmlspecialchars($class['name']) ?>
                <?php else: ?>
                    - Toutes les classes
                <?php endif ?>
                - <?= count($courses) ?> cours disponibles
            </div>
        </div>

        <?php if ($class): ?>
        <div class="class-info-banner">
            <h3><i class="fas fa-users"></i> <?= htmlspecialchars($class['name']) ?></h3>
            <p>Vous consultez les cours spécifiques à cette classe</p>
        </div>
        <?php endif; ?>

        <div class="course-grid">
            <?php if (!empty($courses)): ?>
                <?php foreach ($courses as $index => $course): ?>
                    <div class="course-card" onclick="window.location.href='manage_discussions.php?course_id=<?php echo urlencode($course['id']); ?>'" style="animation-delay: <?= $index * 0.1 ?>s;">
                        <img src="<?php echo htmlspecialchars($course['image_path']); ?>" alt="Image du cours">
                        <div class="course-info">
                            <h3><?php echo htmlspecialchars($course['name']); ?></h3>
                            <p><?php echo htmlspecialchars($course['major']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state" style="grid-column: 1 / -1;">
                    <i class="fas fa-book-open"></i>
                    <h3>Aucun cours disponible</h3>
                    <p>Aucun cours trouvé pour le semestre <?= $semester ?>.<br>
                    <?php if ($class): ?>
                        dans la classe "<?= htmlspecialchars($class['name']) ?>".
                    <?php endif; ?>
                    Vous pouvez changer de semestre dans le menu latéral.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="announcements">
            <div class="announcement-content">
                <?php 
                if (!empty($announcements)) {
                    foreach ($announcements as $announcement) {
                        echo '<div class="announcement-item">' . htmlspecialchars($announcement['content']) . '</div>';
                    }
                } else {
                    echo "<div class='announcement-item'>Aucune annonce trouvée.</div>";
                }
                ?>
            </div>
        </div>
    </div>

    <div class="drawer" id="drawer">
        <button class="close-drawer" id="close-drawer">
            <i class="fas fa-times"></i>
        </button>
        <h2><i class="fas fa-cog"></i> Menu</h2>
        
        <!-- Section Classe -->
        <div class="drawer-section">
            <h3><i class="fas fa-users"></i> Sélection de classe</h3>
            <select class="class-select" id="class-select">
                <option value="">Toutes les classes</option>
                <?php foreach ($teacher_classes as $teacherClass): ?>
                    <option value="<?= $teacherClass['id'] ?>" <?= ($class_id == $teacherClass['id']) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($teacherClass['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p style="font-size: 0.85rem; color: rgba(255,255,255,0.7); margin-top: 10px;">
                Filtrer les cours par classe spécifique
            </p>
        </div>

        <!-- Section Semestre -->
        <div class="drawer-section">
            <h3><i class="fas fa-calendar-week"></i> Gestion des Semestres</h3>
            <select class="semester-select" id="semester-select">
                <option value="1" <?= ($semester == 1) ? 'selected' : ''; ?>>Semestre 1</option>
                <option value="2" <?= ($semester == 2) ? 'selected' : ''; ?>>Semestre 2</option>
            </select>
            <p style="font-size: 0.85rem; color: rgba(255,255,255,0.7); margin-top: 10px;">
                Choisissez le semestre pour afficher les cours correspondants
            </p>
        </div>

        <!-- Section Actions Rapides -->
        <div class="drawer-section">
            <h3><i class="fas fa-bolt"></i> Actions Rapides</h3>
            <a href="teacher_dashboard.php" class="drawer-btn">
                <i class="fas fa-users"></i> Mes classes
            </a>
            <button class="drawer-btn" id="timetable-button" onclick="toggleTimetable()">
                <i class="fas fa-calendar-alt"></i> Emplois du temps
            </button>
            <div id="timetable-section" style="display: none; margin-top: 15px;">
                <?php if (!empty($teacher_classes)): ?>
                    <h4 style="color: white; margin-bottom: 15px;">Emplois du temps</h4>
                    <?php foreach ($teacher_classes as $teacherClass): ?>
                        <div style="margin-bottom: 20px; padding: 10px; background: rgba(255,255,255,0.1); border-radius: 8px;">
                            <h5 style="color: var(--accent-color); margin-bottom: 10px;">
                                <?php echo htmlspecialchars($teacherClass['name']); ?>
                            </h5>
                            <?php if (!empty($teacherClass['timetable_image_path'])): ?>
                                <img src="../uploads/<?php echo htmlspecialchars($teacherClass['timetable_image_path']); ?>" 
                                     alt="Emploi du temps <?php echo htmlspecialchars($teacherClass['name']); ?>" 
                                     style="width:100%; max-width:300px; border-radius: 8px;">
                            <?php else: ?>
                                <p style="color: rgba(255,255,255,0.7); font-size: 0.9rem;">Aucun emploi du temps disponible.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: rgba(255,255,255,0.7);">Aucune classe associée trouvée.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Section Statistiques -->
        <div class="drawer-section">
            <h3><i class="fas fa-chart-bar"></i> Statistiques</h3>
            <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; margin-bottom: 10px;">
                <p style="margin: 0; font-size: 0.9rem;">
                    <i class="fas fa-book"></i> 
                    <strong><?= count($courses) ?></strong> cours 
                    <?= $class_id ? 'dans cette classe' : 'au total' ?>
                </p>
            </div>
            <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; margin-bottom: 10px;">
                <p style="margin: 0; font-size: 0.9rem;">
                    <i class="fas fa-users"></i> 
                    <strong><?= count($teacher_classes) ?></strong> classe(s) assignée(s)
                </p>
            </div>
            <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px;">
                <p style="margin: 0; font-size: 0.9rem;">
                    <i class="fas fa-bullhorn"></i> 
                    <strong><?= count($announcements) ?></strong> annonces actives
                </p>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Éléments du DOM
            const drawerToggle = document.getElementById('drawer-toggle-right');
            const drawer = document.getElementById('drawer');
            const closeDrawer = document.getElementById('close-drawer');
            const semesterSelect = document.getElementById('semester-select');
            const classSelect = document.getElementById('class-select');
            const timetableSection = document.getElementById('timetable-section');

            // ===== SYSTÈME DE NOTIFICATIONS =====
            function showToast(message, type = 'info', duration = 5000) {
                const toastContainer = document.getElementById('toast-container');
                const toast = document.createElement('div');
                toast.className = `toast ${type}`;
                
                const icons = {
                    success: 'fas fa-check-circle',
                    error: 'fas fa-exclamation-circle',
                    warning: 'fas fa-exclamation-triangle',
                    info: 'fas fa-info-circle'
                };

                toast.innerHTML = `
                    <i class="toast-icon ${icons[type]}"></i>
                    <span class="toast-message">${message}</span>
                    <i class="toast-close fas fa-times"></i>
                `;

                toastContainer.appendChild(toast);

                const autoRemove = setTimeout(() => removeToast(toast), duration);

                toast.querySelector('.toast-close').addEventListener('click', () => {
                    clearTimeout(autoRemove);
                    removeToast(toast);
                });

                toast.addEventListener('click', () => {
                    clearTimeout(autoRemove);
                    removeToast(toast);
                });
            }

            function removeToast(toast) {
                toast.style.animation = 'slideOut 0.3s ease forwards';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.parentNode.removeChild(toast);
                    }
                }, 300);
            }

            // ===== GESTION DE LA CLASSE =====
            if (classSelect) {
                classSelect.addEventListener('change', function() {
                    const selectedClass = this.value;
                    const currentClass = '<?= $class_id ?>';
                    
                    if (selectedClass != currentClass) {
                        const className = this.options[this.selectedIndex].text;
                        const message = selectedClass ? 
                            `Filtrer par la classe "${className}"` : 
                            'Afficher toutes les classes';
                        
                        showToast(message + '...', 'info');
                        setTimeout(() => {
                            const currentUrl = new URL(window.location);
                            if (selectedClass) {
                                currentUrl.searchParams.set('class_id', selectedClass);
                            } else {
                                currentUrl.searchParams.delete('class_id');
                            }
                            // Conserver le semestre
                            currentUrl.searchParams.set('semester', '<?= $semester ?>');
                            window.location.href = currentUrl.toString();
                        }, 1000);
                    } else {
                        showToast('Classe déjà sélectionnée', 'info', 3000);
                    }
                });
            }

            // ===== GESTION DU SEMESTRE =====
            if (semesterSelect) {
                semesterSelect.addEventListener('change', function() {
                    const selectedSemester = this.value;
                    const currentSemester = <?= $semester ?>;
                    
                    if (selectedSemester != currentSemester) {
                        showToast(`Chargement du semestre ${selectedSemester}...`, 'info');
                        setTimeout(() => {
                            const currentUrl = new URL(window.location);
                            currentUrl.searchParams.set('semester', selectedSemester);
                            window.location.href = currentUrl.toString();
                        }, 1000);
                    } else {
                        showToast('Semestre déjà sélectionné', 'info', 3000);
                    }
                });
            }

            // ===== GESTION DU DRAWER =====
            if (drawerToggle) {
                drawerToggle.addEventListener('click', () => {
                    drawer.classList.toggle('open');
                    if (drawer.classList.contains('open')) {
                        showToast('Menu ouvert', 'info', 2000);
                    }
                });
            }

            if (closeDrawer) {
                closeDrawer.addEventListener('click', () => {
                    drawer.classList.remove('open');
                    timetableSection.style.display = 'none';
                    showToast('Menu fermé', 'info', 2000);
                });
            }

            // ===== GESTION DE L'EMPLOI DU TEMPS =====
            window.toggleTimetable = function() {
                if (timetableSection.style.display === 'none') {
                    timetableSection.style.display = 'block';
                    showToast('Emplois du temps affichés', 'success', 3000);
                } else {
                    timetableSection.style.display = 'none';
                    showToast('Emplois du temps masqués', 'info', 3000);
                }
            };

            // ===== ANIMATIONS =====
            function initializeAnimations() {
                const navLinks = document.querySelectorAll('nav a');
                const header = document.querySelector('header');
                const floatingIcons = document.querySelectorAll('.floating-icon');

                // Animation des liens
                navLinks.forEach(link => {
                    link.addEventListener('mouseenter', function() {
                        this.style.transform = 'translateY(-2px)';
                    });
                    link.addEventListener('mouseleave', function() {
                        this.style.transform = 'translateY(0)';
                    });
                });

                // Animation des icônes flottantes
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

                // Animation des cartes de cours
                document.querySelectorAll('.course-card').forEach((card, index) => {
                    card.style.animation = `fadeIn 0.5s ease forwards ${index * 0.1}s`;
                    card.style.opacity = '0';
                    
                    card.addEventListener('click', function() {
                        this.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            this.style.transform = 'translateY(-5px) scale(1)';
                        }, 100);
                    });
                });
            }

            // ===== FERMETURE DRAWER EN CLIQUANT À L'EXTÉRIEUR =====
            document.addEventListener('click', (e) => {
                if (!drawer.contains(e.target) && !drawerToggle.contains(e.target)) {
                    drawer.classList.remove('open');
                    timetableSection.style.display = 'none';
                }
            });

            // ===== ANIMATION DU TICKER D'ANNONCES =====
            const announcementContent = document.querySelector('.announcement-content');
            if (announcementContent) {
                announcementContent.innerHTML += announcementContent.innerHTML;
            }

            // ===== INITIALISATION =====
            initializeAnimations();

            // Affichage des messages
            <?php if (isset($_SESSION['message'])): ?>
                showToast(
                    '<?= addslashes($_SESSION['message']['text']) ?>', 
                    '<?= $_SESSION['message']['type'] ?>', 
                    5000
                );
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>

            // Message de bienvenue
            setTimeout(() => {
                showToast(
                    `Cours - Semestre <?= $semester ?>`, 
                    'success', 
                    4000
                );
            }, 500);
        });
    </script>
</body>
</html>