<?php
// Démarrer la session
session_start();
require_once '../includes/db_connect.php';

// Définir le fuseau horaire GMT+1
date_default_timezone_set('Europe/Paris'); // GMT+1 (CET/CEST)

// Vérifier si l'utilisateur est connecté et est un administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

// Si c'est une requête AJAX pour actualiser les données
if (isset($_GET['ajax']) && $_GET['ajax'] == 'refresh') {
    header('Content-Type: application/json');
    
    // Récupérer les statistiques actualisées
    $stats = [];
    
    // Étudiants
    $sql_students = "SELECT COUNT(*) AS total_students FROM users WHERE role = 'student' AND blocked = 0";
    $result_students = $conn->query($sql_students);
    $stats['total_students'] = $result_students->fetch_assoc()['total_students'];
    
    // Étudiants bloqués
    $sql_blocked_students = "SELECT COUNT(*) AS blocked_students FROM users WHERE role = 'student' AND blocked = 1";
    $result_blocked_students = $conn->query($sql_blocked_students);
    $stats['blocked_students'] = $result_blocked_students->fetch_assoc()['blocked_students'];

    // Enseignants
    $sql_teachers = "SELECT COUNT(*) AS total_teachers FROM users WHERE role = 'teacher' AND blocked = 0";
    $result_teachers = $conn->query($sql_teachers);
    $stats['total_teachers'] = $result_teachers->fetch_assoc()['total_teachers'];

    // Cours
    $sql_courses = "SELECT COUNT(*) AS total_courses FROM courses";
    $result_courses = $conn->query($sql_courses);
    $stats['total_courses'] = $result_courses->fetch_assoc()['total_courses'];

    // Classes
    $sql_classes = "SELECT COUNT(*) AS total_classes FROM classes";
    $result_classes = $conn->query($sql_classes);
    $stats['total_classes'] = $result_classes->fetch_assoc()['total_classes'];

    // Date et heure actuelles pour la détection des salles occupées (GMT+1)
    $current_day = date('N'); // 1 (lundi) à 7 (dimanche)
    $current_time = date('H:i:s');
    
    // Total des salles
    $query_total_classrooms = "SELECT COUNT(*) AS total_classrooms FROM classrooms";
    $result_total_classrooms = $conn->query($query_total_classrooms);
    $stats['total_classrooms'] = $result_total_classrooms->fetch_assoc()['total_classrooms'];

    // Salles actuellement occupées (requête corrigée et sécurisée)
    $query_occupied_classrooms = "
        SELECT COUNT(DISTINCT s.classroom_id) AS occupied_classrooms 
        FROM schedule s
        JOIN time_slots t ON s.time_slot_id = t.id
        WHERE s.weekday_id = ? 
        AND ? BETWEEN t.start_time AND t.end_time
        AND s.is_recurring = 1
        AND (s.start_date IS NULL OR s.start_date = '0000-00-00' OR s.start_date <= CURDATE())
        AND (s.end_date IS NULL OR s.end_date = '0000-00-00' OR s.end_date >= CURDATE())
    ";
    
    $stmt = $conn->prepare($query_occupied_classrooms);
    $stmt->bind_param("is", $current_day, $current_time);
    $stmt->execute();
    $result_occupied = $stmt->get_result();
    $stats['occupied_classrooms'] = $result_occupied->fetch_assoc()['occupied_classrooms'];
    
    // Salles disponibles
    $stats['available_classrooms'] = $stats['total_classrooms'] - $stats['occupied_classrooms'];
    
    // Connexions récentes (dernières 24h)
    $sql_recent_logins = "SELECT COUNT(*) AS recent_logins FROM user_logins WHERE login_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND success = 1";
    $result_recent_logins = $conn->query($sql_recent_logins);
    $stats['recent_logins'] = $result_recent_logins->fetch_assoc()['recent_logins'];
    
    // ✅ NOUVEAU: Inscriptions en attente
    $sql_pending = "SELECT COUNT(*) AS pending_count FROM users WHERE role = 'student' AND blocked = 1";
    $result_pending = $conn->query($sql_pending);
    $stats['pending_count'] = $result_pending->fetch_assoc()['pending_count'];
    
    // Informations de débogage pour vérifier les calculs
    $debug_info = [];
    $debug_info['current_day'] = $current_day;
    $debug_info['current_time'] = $current_time;
    $debug_info['server_timezone'] = date_default_timezone_get();
    $debug_info['current_datetime'] = date('Y-m-d H:i:s');
    
    // Détails des cours actuellement programmés
    $debug_query = "
        SELECT s.id, s.course_id, c.name as course_name, s.classroom_id, cl.name as classroom_name, 
               s.weekday_id, w.name as weekday_name, s.time_slot_id, 
               t.start_time, t.end_time, t.name as time_slot_name,
               s.start_date, s.end_date, s.is_recurring
        FROM schedule s
        JOIN courses c ON s.course_id = c.id
        JOIN classrooms cl ON s.classroom_id = cl.id
        JOIN weekdays w ON s.weekday_id = w.id
        JOIN time_slots t ON s.time_slot_id = t.id
        WHERE s.weekday_id = ? 
        AND ? BETWEEN t.start_time AND t.end_time
        AND s.is_recurring = 1
        AND (s.start_date IS NULL OR s.start_date = '0000-00-00' OR s.start_date <= CURDATE())
        AND (s.end_date IS NULL OR s.end_date = '0000-00-00' OR s.end_date >= CURDATE())
    ";
    
    $debug_stmt = $conn->prepare($debug_query);
    $debug_stmt->bind_param("is", $current_day, $current_time);
    $debug_stmt->execute();
    $debug_result = $debug_stmt->get_result();
    $debug_info['active_courses'] = [];
    while ($row = $debug_result->fetch_assoc()) {
        $debug_info['active_courses'][] = $row;
    }
    
    // Tous les créneaux programmés (pour débogage)
    $all_schedules_query = "
        SELECT s.*, c.name as course_name, cl.name as classroom_name, w.name as weekday_name, t.start_time, t.end_time
        FROM schedule s
        JOIN courses c ON s.course_id = c.id
        JOIN classrooms cl ON s.classroom_id = cl.id
        JOIN weekdays w ON s.weekday_id = w.id
        JOIN time_slots t ON s.time_slot_id = t.id
        ORDER BY s.weekday_id, t.start_time
    ";
    $all_schedules_result = $conn->query($all_schedules_query);
    $debug_info['all_schedules'] = [];
    while ($row = $all_schedules_result->fetch_assoc()) {
        $debug_info['all_schedules'][] = $row;
    }
    
    $stats['debug'] = $debug_info;
    
    // Timestamp pour vérifier la fraîcheur des données
    $stats['last_updated'] = date('Y-m-d H:i:s');
    
    echo json_encode($stats);
    $conn->close();
    exit();
}

// Code pour l'affichage initial de la page
$sql_students = "SELECT COUNT(*) AS total_students FROM users WHERE role = 'student' AND blocked = 0";
$result_students = $conn->query($sql_students);
$total_students = $result_students->fetch_assoc()['total_students'];

$sql_blocked_students = "SELECT COUNT(*) AS blocked_students FROM users WHERE role = 'student' AND blocked = 1";
$result_blocked_students = $conn->query($sql_blocked_students);
$blocked_students = $result_blocked_students->fetch_assoc()['blocked_students'];

$sql_teachers = "SELECT COUNT(*) AS total_teachers FROM users WHERE role = 'teacher' AND blocked = 0";
$result_teachers = $conn->query($sql_teachers);
$total_teachers = $result_teachers->fetch_assoc()['total_teachers'];

$sql_courses = "SELECT COUNT(*) AS total_courses FROM courses";
$result_courses = $conn->query($sql_courses);
$total_courses = $result_courses->fetch_assoc()['total_courses'];

$sql_classes = "SELECT COUNT(*) AS total_classes FROM classes";
$result_classes = $conn->query($sql_classes);
$total_classes = $result_classes->fetch_assoc()['total_classes'];

// Date et heure actuelles (GMT+1)
$current_day = date('N');
$current_time = date('H:i:s');

// Total des salles
$query_total_classrooms = "SELECT COUNT(*) AS total_classrooms FROM classrooms";
$result_total_classrooms = $conn->query($query_total_classrooms);
$total_classrooms = $result_total_classrooms->fetch_assoc()['total_classrooms'];

// Salles occupées (requête corrigée)
$query_occupied_classrooms = "
    SELECT COUNT(DISTINCT s.classroom_id) AS occupied_classrooms 
    FROM schedule s
    JOIN time_slots t ON s.time_slot_id = t.id
    WHERE s.weekday_id = ? 
    AND ? BETWEEN t.start_time AND t.end_time
    AND s.is_recurring = 1
    AND (s.start_date IS NULL OR s.start_date = '0000-00-00' OR s.start_date <= CURDATE())
    AND (s.end_date IS NULL OR s.end_date = '0000-00-00' OR s.end_date >= CURDATE())
";

$stmt = $conn->prepare($query_occupied_classrooms);
$stmt->bind_param("is", $current_day, $current_time);
$stmt->execute();
$result_occupied = $stmt->get_result();
$occupied_classrooms = $result_occupied->fetch_assoc()['occupied_classrooms'];

$available_classrooms = $total_classrooms - $occupied_classrooms;

// Connexions récentes
$sql_recent_logins = "SELECT COUNT(*) AS recent_logins FROM user_logins WHERE login_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND success = 1";
$result_recent_logins = $conn->query($sql_recent_logins);
$recent_logins = $result_recent_logins->fetch_assoc()['recent_logins'];

// ✅ NOUVEAU: Inscriptions en attente
$sql_pending = "SELECT COUNT(*) AS pending_count FROM users WHERE role = 'student' AND blocked = 1";
$result_pending = $conn->query($sql_pending);
$pending_count = $result_pending->fetch_assoc()['pending_count'];

// Récupérer les annonces récentes
$sql_announcements = "SELECT * FROM announcements ORDER BY created_at DESC LIMIT 5";
$result_announcements = $conn->query($sql_announcements);
$announcements = [];
if ($result_announcements->num_rows > 0) {
    while ($row = $result_announcements->fetch_assoc()) {
        $announcements[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Administrateur - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Variables globales */
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --card-bg: rgba(255, 255, 255, 0.1);
        }

        /* Reset et structure de base */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            color: var(--text-light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header */
        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid var(--border-color);
            position: relative;
            z-index: 1000;
        }

        header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--accent-color), var(--success-color), var(--accent-color));
            animation: shimmer 2s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        h1 {
            font-size: 28px;
            color: var(--accent-color);
            margin-bottom: 15px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* Navigation */
        nav ul {
            list-style: none;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 5px;
        }

        nav a {
            color: var(--text-light);
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            white-space: nowrap;
            position: relative;
        }

        nav a:hover {
            background: rgba(3, 155, 229, 0.1);
            transform: translateY(-2px);
        }

        /* ✅ NOUVEAU: Badge notification pour inscriptions en attente */
        .pending-btn {
            position: relative;
            background: #f39c12 !important;
            color: white !important;
            font-weight: bold;
            animation: glow 2s ease-in-out infinite;
        }

        .pending-btn:hover {
            background: #e67e22 !important;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #e74c3c;
            color: white;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            animation: badge-bounce 1s ease-in-out infinite;
        }

        .notification-badge.zero {
            background: #95a5a6;
            animation: none;
        }

        @keyframes glow {
            0%, 100% { box-shadow: 0 0 5px rgba(243, 156, 18, 0.5); }
            50% { box-shadow: 0 0 20px rgba(243, 156, 18, 0.8); }
        }

        @keyframes badge-bounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        /* Dropdown */
        .dropdown {
            position: relative;
        }

        .dropdown > a::after {
            content: '▼';
            font-size: 10px;
            margin-left: 5px;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: var(--secondary-bg);
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.3);
            border-radius: 5px;
            overflow: hidden;
            z-index: 1001;
        }

        .dropdown:hover .dropdown-content {
            display: block;
        }

        .dropdown-content a {
            padding: 12px 15px;
            border-radius: 0;
        }

        .dropdown-content a:hover {
            background: var(--accent-color);
        }

        /* Contenu principal */
        .dashboard-container {
            flex: 1;
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
            width: 100%;
        }

        .page-header {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid var(--border-color);
        }

        .page-header h2 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--accent-color);
            font-size: 24px;
        }

        .last-updated {
            font-size: 12px;
            color: #ccc;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .refresh-indicator {
            color: var(--success-color);
            animation: pulse 1s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* Timezone indicator */
        .timezone-info {
            font-size: 11px;
            color: #aaa;
            margin-top: 5px;
        }

        /* Grille de statistiques */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 25px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--accent-color);
            transition: background 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
        }

        .stat-card.success::before { background: var(--success-color); }
        .stat-card.warning::before { background: var(--warning-color); }
        .stat-card.danger::before { background: var(--danger-color); }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }

        .stat-title {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #ccc;
            font-weight: 600;
        }

        .stat-icon {
            font-size: 24px;
            color: var(--accent-color);
        }

        .stat-value {
            font-size: 36px;
            font-weight: bold;
            color: var(--text-light);
            margin-bottom: 5px;
        }

        .stat-change {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .stat-change.positive {
            color: var(--success-color);
        }

        .stat-change.negative {
            color: var(--danger-color);
        }

        /* Section des annonces */
        .announcements-section {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 25px;
            border: 1px solid var(--border-color);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 20px;
            color: var(--accent-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .announcements-table {
            width: 100%;
            border-collapse: collapse;
        }

        .announcements-table th,
        .announcements-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .announcements-table th {
            background: rgba(255, 255, 255, 0.05);
            font-weight: 600;
            color: var(--accent-color);
        }

        .announcements-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .btn {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
            font-size: 12px;
        }

        .btn:hover {
            background: #0288d1;
            transform: translateY(-1px);
        }

        /* Footer */
        footer {
            background: var(--secondary-bg);
            padding: 20px 0;
            text-align: center;
            border-top: 1px solid var(--border-color);
            margin-top: auto;
        }

        footer p {
            color: #ccc;
            font-size: 14px;
        }

        /* Debug info */
        .debug-info {
            background: rgba(0, 0, 0, 0.3);
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-family: monospace;
            font-size: 12px;
            max-height: 200px;
            overflow-y: auto;
        }

        .debug-toggle {
            cursor: pointer;
            color: var(--accent-color);
            text-decoration: underline;
            font-size: 12px;
            margin-bottom: 10px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 20px 15px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            nav ul {
                flex-direction: column;
                align-items: stretch;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .announcements-table {
                font-size: 14px;
            }
        }

        /* Auto-refresh indicator */
        .auto-refresh-status {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--success-color);
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            font-size: 12px;
            z-index: 1000;
            display: none;
        }

        .special-button a {
            background-color: #28a745;
            color: white !important;
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }
        
        .special-button a:hover {
            background-color: #218838;
            text-decoration: none;
        }

        .dropdown-nav {
            position: relative;
        }
        
        .nav-link {
            color: #ffffff;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .nav-link:hover {
            background: rgba(3, 155, 229, 0.1);
            transform: translateY(-2px);
        }
        
        .nav-arrow {
            font-size: 10px;
            transition: transform 0.3s ease;
        }
        
        .dropdown-nav.active .nav-arrow {
            transform: rotate(180deg);
        }
        
        .payment-submenu {
            position: absolute;
            top: 100%;
            left: 0;
            background: #0a2238;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 6px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            min-width: 200px;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            list-style: none;
            padding: 5px 0;
            margin: 3px 0 0 0;
        }
        
        .dropdown-nav.active .payment-submenu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .payment-submenu li {
            margin: 0;
        }
        
        .payment-submenu a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            color: #ffffff;
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .payment-submenu a:hover {
            background: rgba(3, 155, 229, 0.15);
            padding-left: 18px;
            transform: none;
        }
        
        .payment-submenu i {
            font-size: 14px;
            color: #039be5;
            min-width: 16px;
        }
        
        /* Animation fluide pour mobile */
        @media (max-width: 768px) {
            .payment-submenu {
                min-width: 180px;
                right: 0;
                left: auto;
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
                    <li><a href="user_management.php"><i class="fas fa-users"></i> Utilisateurs</a></li>
                    
                    <!-- ✅ NOUVEAU: Bouton Inscriptions en attente -->
                    <li>
                        <a href="pending_registrations.php" class="pending-btn">
                            <i class="fas fa-user-clock"></i> Inscriptions
                            <span class="notification-badge <?php echo $pending_count == 0 ? 'zero' : ''; ?>" id="pending-badge">
                                <?php echo $pending_count; ?>
                            </span>
                        </a>
                    </li>
                    
                    <li><a href="manage_admins.php"><i class="fas fa-user-shield"></i> Gestion Admins</a></li>
    
                    <li><a href="course_management.php"><i class="fas fa-book"></i> Cours</a></li>
                    <li><a href="course_progress.php"><i class="fas fa-chart-line"></i> Progression</a></li>
                    <li><a href="admin_attendance.php"><i class="fas fa-clipboard-list"></i> Présences</a></li>
                    <!-- Dropdown Paiements -->
                    <li class="dropdown-nav">
                        <a href="#" class="nav-link" onclick="togglePaymentMenu(event)">
                            <i class="fas fa-credit-card"></i> Paiements 
                            <i class="fas fa-chevron-down nav-arrow"></i>
                        </a>
                        <ul class="payment-submenu">
                            <li>
                                <a href="payment_dashboard.php">
                                    <i class="fas fa-graduation-cap"></i> Paiements Étudiants
                                </a>
                            </li>
                            <li>
                                <a href="payment_admin.php">
                                    <i class="fas fa-users-cog"></i> Paiements Personnel
                                </a>
                            </li>
                            <li><a href="comptabilite.php"><i class="fas fa-calculator"></i> Comptabilité</a></li>

                        </ul>
                    </li>
    
                    <li class="dropdown">
                        <a href="../grades/grades_management.php"><i class="fas fa-chart-bar"></i> Notes</a>
                        <div class="dropdown-content">
                            <a href="../grades/global_grades.php"><i class="fas fa-globe"></i> Vue globale</a>
                            <a href="../grades/grade_reports.php"><i class="fas fa-file-alt"></i> Bulletins</a>
                            <a href="../grades/grade_parameters.php"><i class="fas fa-cog"></i> Paramètres</a>
                            <a href="../grades/grade_export.php"><i class="fas fa-file-export"></i> Export</a>
                            <a href="../grades/evaluation_periods.php"><i class="fas fa-calendar-alt"></i> Périodes</a>
                            <a href="rattrapage_admin.php"><i class="fas fa-redo"></i> Rattrapages</a>
                            <a href="attestations.php"><i class="fas fa-certificate"></i> Attestations</a>
                        </div>
                    </li>
                    <li class="dropdown">
                        <a href="#"><i class="fas fa-school"></i> Classes</a>
                        <div class="dropdown-content">
                            <a href="class_management.php"><i class="fas fa-list"></i> Liste des classes</a>
                        </div>
                    </li>
                    <li><a href="../admin/schedule_management.php"><i class="fas fa-calendar-alt"></i> Planning</a></li>
                    <li class="dropdown">
                        <a href="announcement_management.php"><i class="fas fa-comments"></i> Communications</a>
                        <div class="dropdown-content">
                            <a href="announcement_management.php"><i class="fas fa-bullhorn"></i> Annonces</a>
                        </div>
                    </li>
                    <li><a href="admin_profile.php"><i class="fas fa-user-cog"></i> Profil</a></li>
                    <li><a href="../pages/logout.php"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <div class="dashboard-container">
        <div class="page-header">
            <h2><i class="fas fa-tachometer-alt"></i> Tableau de Bord</h2>
            <div class="last-updated">
                <i class="fas fa-clock"></i>
                <div>
                    <span id="last-updated-time">Dernière mise à jour : <?php echo date('H:i:s'); ?></span>
                    <div class="timezone-info">Fuseau horaire : <?php echo date_default_timezone_get(); ?> (GMT+1)</div>
                </div>
                <span class="refresh-indicator" id="refresh-indicator" style="display: none;">
                    <i class="fas fa-sync fa-spin"></i>
                </span>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-title">Étudiants Actifs</span>
                    <i class="stat-icon fas fa-user-graduate"></i>
                </div>
                <div class="stat-value" id="total-students"><?php echo $total_students; ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-arrow-up"></i> Actifs
                </div>
            </div>

            <div class="stat-card warning">
                <div class="stat-header">
                    <span class="stat-title">Étudiants Bloqués</span>
                    <i class="stat-icon fas fa-user-slash"></i>
                </div>
                <div class="stat-value" id="blocked-students"><?php echo $blocked_students; ?></div>
                <div class="stat-change negative">
                    <i class="fas fa-ban"></i> Bloqués
                </div>
            </div>

            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-title">Enseignants</span>
                    <i class="stat-icon fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-value" id="total-teachers"><?php echo $total_teachers; ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-check"></i> Actifs
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Cours Total</span>
                    <i class="stat-icon fas fa-book"></i>
                </div>
                <div class="stat-value" id="total-courses"><?php echo $total_courses; ?></div>
                <div class="stat-change">
                    <i class="fas fa-info-circle"></i> Disponibles
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Classes</span>
                    <i class="stat-icon fas fa-school"></i>
                </div>
                <div class="stat-value" id="total-classes"><?php echo $total_classes; ?></div>
                <div class="stat-change">
                    <i class="fas fa-list"></i> Créées
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Salles Totales</span>
                    <i class="stat-icon fas fa-door-open"></i>
                </div>
                <div class="stat-value" id="total-classrooms"><?php echo $total_classrooms; ?></div>
                <div class="stat-change">
                    <i class="fas fa-building"></i> Disponibles
                </div>
            </div>

            <div class="stat-card danger">
                <div class="stat-header">
                    <span class="stat-title">Salles Occupées</span>
                    <i class="stat-icon fas fa-door-closed"></i>
                </div>
                <div class="stat-value" id="occupied-classrooms"><?php echo $occupied_classrooms; ?></div>
                <div class="stat-change negative">
                    <i class="fas fa-clock"></i> En cours
                </div>
            </div>

            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-title">Salles Libres</span>
                    <i class="stat-icon fas fa-door-open"></i>
                </div>
                <div class="stat-value" id="available-classrooms"><?php echo $available_classrooms; ?></div>
                <div class="stat-change positive">
                    <i class="fas fa-check"></i> Disponibles
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <span class="stat-title">Connexions 24h</span>
                    <i class="stat-icon fas fa-sign-in-alt"></i>
                </div>
                <div class="stat-value" id="recent-logins"><?php echo $recent_logins; ?></div>
                <div class="stat-change">
                    <i class="fas fa-users"></i> Récentes
                </div>
            </div>
        </div>

        <div class="announcements-section">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-bullhorn"></i> Annonces Récentes
                </h3>
                <a href="announcement_management.php" class="btn">
                    <i class="fas fa-plus"></i> Gérer les annonces
                </a>
            </div>
            
            <?php if (!empty($announcements)): ?>
                <table class="announcements-table">
                    <thead>
                        <tr>
                            <th>Contenu</th>
                            <th>Date de Création</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($announcements as $announcement): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($announcement['content'], 0, 100)) . (strlen($announcement['content']) > 100 ? '...' : ''); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($announcement['created_at'])); ?></td>
                                <td>
                                    <a href="announcement_management.php?id=<?php echo $announcement['id']; ?>" class="btn">
                                        <i class="fas fa-edit"></i> Gérer
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; color: #ccc; padding: 20px;">
                    <i class="fas fa-info-circle"></i> Aucune annonce disponible.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="auto-refresh-status" id="auto-refresh-status">
        <i class="fas fa-sync"></i> Actualisation automatique activée
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        let debugMode = false;

        function toggleDebug() {
            debugMode = !debugMode;
            const debugInfo = document.getElementById('debug-info');
            debugInfo.style.display = debugMode ? 'block' : 'none';
        }

        // Fonction pour actualiser les données
        function refreshData() {
            const refreshIndicator = document.getElementById('refresh-indicator');
            refreshIndicator.style.display = 'inline';
            
            fetch('?ajax=refresh')
                .then(response => response.json())
                .then(data => {
                    console.log('Données reçues:', data);
                    
                    // Mettre à jour les statistiques
                    document.getElementById('total-students').textContent = data.total_students;
                    document.getElementById('blocked-students').textContent = data.blocked_students;
                    document.getElementById('total-teachers').textContent = data.total_teachers;
                    document.getElementById('total-courses').textContent = data.total_courses;
                    document.getElementById('total-classes').textContent = data.total_classes;
                    document.getElementById('total-classrooms').textContent = data.total_classrooms;
                    document.getElementById('occupied-classrooms').textContent = data.occupied_classrooms;
                    document.getElementById('available-classrooms').textContent = data.available_classrooms;
                    document.getElementById('recent-logins').textContent = data.recent_logins;
                    
                    // ✅ NOUVEAU: Mettre à jour le badge des inscriptions en attente
                    const pendingBadge = document.getElementById('pending-badge');
                    if (pendingBadge && data.pending_count !== undefined) {
                        pendingBadge.textContent = data.pending_count;
                        if (data.pending_count == 0) {
                            pendingBadge.classList.add('zero');
                        } else {
                            pendingBadge.classList.remove('zero');
                        }
                    }
                    
                    // Mettre à jour l'heure de dernière mise à jour
                    const now = new Date();
                    document.getElementById('last-updated-time').textContent = 
                        `Dernière mise à jour : ${now.toLocaleTimeString()}`;
                    
                    // Mettre à jour les informations de débogage si affichées
                    if (debugMode && data.debug) {
                        const debugInfo = document.getElementById('debug-info');
                        debugInfo.innerHTML = `
                            <strong>Informations de débogage détaillées:</strong><br>
                            Jour actuel: ${data.debug.current_day} (${['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'][data.debug.current_day % 7]})<br>
                            Heure actuelle: ${data.debug.current_time}<br>
                            Date complète: ${data.debug.current_datetime}<br>
                            Fuseau horaire: ${data.debug.server_timezone}<br>
                            Total salles: ${data.total_classrooms}<br>
                            Salles occupées: ${data.occupied_classrooms}<br>
                            Salles disponibles: ${data.available_classrooms}<br>
                            <br><strong>Cours actifs maintenant:</strong><br>
                            ${data.debug.active_courses.length > 0 ? 
                                data.debug.active_courses.map(course => 
                                    `- ${course.course_name} (Salle: ${course.classroom_name}, ${course.start_time}-${course.end_time})`
                                ).join('<br>') : 
                                'Aucun cours actif'}
                            <br><br><strong>Tous les créneaux programmés:</strong><br>
                            ${data.debug.all_schedules.map(schedule => 
                                `- ${schedule.course_name} | ${schedule.weekday_name} ${schedule.start_time}-${schedule.end_time} | Salle: ${schedule.classroom_name}`
                            ).join('<br>')}
                        `;
                    }
                    
                    refreshIndicator.style.display = 'none';
                    
                    // Afficher temporairement le statut d'actualisation
                    const status = document.getElementById('auto-refresh-status');
                    status.style.display = 'block';
                    setTimeout(() => {
                        status.style.display = 'none';
                    }, 2000);
                })
                .catch(error => {
                    console.error('Erreur lors de l\'actualisation:', error);
                    refreshIndicator.style.display = 'none';
                });
        }

        // Actualisation automatique toutes les 30 secondes
        setInterval(refreshData, 30000);

        // Actualisation au clic sur l'indicateur de mise à jour
        document.getElementById('last-updated-time').addEventListener('click', refreshData);

        // Actualisation lors du retour sur la page (visibility change)
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                refreshData();
            }
        });

        // Afficher l'heure en temps réel
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleTimeString();
        }

        setInterval(updateClock, 1000);

        function togglePaymentMenu(event) {
            event.preventDefault();
            event.stopPropagation();
            
            const dropdown = event.target.closest('.dropdown-nav');
            const isActive = dropdown.classList.contains('active');
            
            // Fermer tous les autres menus déroulants
            document.querySelectorAll('.dropdown-nav.active').forEach(d => {
                if (d !== dropdown) {
                    d.classList.remove('active');
                }
            });
            
            // Toggle du menu actuel
            dropdown.classList.toggle('active', !isActive);
        }
        
        // Fermer le menu en cliquant ailleurs
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.dropdown-nav')) {
                document.querySelectorAll('.dropdown-nav.active').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });
        
        // Fermer avec Echap
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.querySelectorAll('.dropdown-nav.active').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });
    </script>
</body>
</html>