<?php
session_start();
require_once '../includes/db_connect.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../pages/login.html");
    exit();
}



// Récupérer l'ID de l'enseignant connecté
$teacher_id = $_SESSION['user_id'];

// Récupérer les jours de la semaine
$query_weekdays = "SELECT id, name FROM weekdays ORDER BY id";
$weekdays_result = $conn->query($query_weekdays);
$weekdays = [];
while ($weekday = $weekdays_result->fetch_assoc()) {
    $weekdays[] = $weekday;
}

// Récupérer les créneaux horaires
$query_timeslots = "SELECT id, TIME_FORMAT(start_time, '%H:%i') as start_time, TIME_FORMAT(end_time, '%H:%i') as end_time, name 
                    FROM time_slots 
                    ORDER BY start_time";
$timeslots_result = $conn->query($query_timeslots);
$timeslots = [];
while ($timeslot = $timeslots_result->fetch_assoc()) {
    $timeslots[] = $timeslot;
}

// Récupérer les classes enseignées par le professeur (facultatif pour le filtrage)
$query_classes = "SELECT DISTINCT cl.id, cl.name 
                 FROM schedule s
                 JOIN classes cl ON s.class_id = cl.id
                 WHERE s.teacher_id = ?";
$stmt_classes = $conn->prepare($query_classes);
$stmt_classes->bind_param("s", $teacher_id);
$stmt_classes->execute();
$classes_result = $stmt_classes->get_result();
$classes = [];
while ($class = $classes_result->fetch_assoc()) {
    $classes[] = $class;
}

// Filtrage par classe (si sélectionné)
$class_filter = "";
$param_types = "s";
$params = array($teacher_id);

if (isset($_GET['class_id']) && !empty($_GET['class_id'])) {
    $class_filter = " AND s.class_id = ?";
    $param_types .= "i";
    $params[] = $_GET['class_id'];
}

// Récupérer l'emploi du temps du professeur
$current_date = date('Y-m-d');
$query_schedule = "SELECT s.id, c.name as course_name, c.id as course_id, 
                   r.name as classroom_name, cl.name as class_name, cl.id as class_id,
                   w.id as weekday_id, w.name as weekday_name, 
                   ts.id as timeslot_id, TIME_FORMAT(ts.start_time, '%H:%i') as start_time, 
                   TIME_FORMAT(ts.end_time, '%H:%i') as end_time
                   FROM schedule s
                   JOIN courses c ON s.course_id = c.id
                   JOIN classrooms r ON s.classroom_id = r.id
                   JOIN classes cl ON s.class_id = cl.id
                   JOIN weekdays w ON s.weekday_id = w.id
                   JOIN time_slots ts ON s.time_slot_id = ts.id
                   WHERE s.teacher_id = ?" . $class_filter . "
                   AND (s.start_date IS NULL OR s.start_date <= ?)
                   AND (s.end_date IS NULL OR s.end_date >= ?)
                   ORDER BY w.id, ts.start_time";

$stmt_schedule = $conn->prepare($query_schedule);
$param_types .= "ss"; // Ajouter les types pour les dates
$params[] = $current_date;
$params[] = $current_date;

// Créer un tableau de références pour bind_param
$bind_params = array($param_types);
foreach ($params as $key => $value) {
    $bind_params[] = &$params[$key];
}

// Appliquer tous les paramètres à bind_param
call_user_func_array(array($stmt_schedule, 'bind_param'), $bind_params);

$stmt_schedule->execute();
$schedule_result = $stmt_schedule->get_result();

$schedule = [];
while ($row = $schedule_result->fetch_assoc()) {
    $schedule[] = $row;
}

// Fermer les requêtes préparées
$stmt_classes->close();
$stmt_schedule->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon emploi du temps - Université Virtuelle</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
    :root {
        --primary-bg: #051e34;
        --secondary-bg: #0c2d48;
        --accent-color: #039be5;
        --text-light: #ffffff;
        --border-color: rgba(255, 255, 255, 0.1);
        --error-color: #dc3545;
        --success-color: #28a745;
        --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        -webkit-tap-highlight-color: transparent;
    }

    html {
        font-size: 16px;
        scroll-behavior: smooth;
    }

    body {
        font-family: 'Google Sans', 'Segoe UI', system-ui, -apple-system, sans-serif;
        background-color: var(--primary-bg);
        color: var(--text-light);
        min-height: 100vh;
        line-height: 1.5;
        overflow-x: hidden;
        display: flex;
        flex-direction: column;
    }

    .container {
        width: 100%;
        max-width: 1400px;
        margin: 0 auto;
        padding: clamp(12px, 3vw, 24px);
        flex: 1;
    }

    h1, h2, h3, h4 {
        color: var(--accent-color);
        margin-bottom: clamp(12px, 2vw, 24px);
        font-weight: 600;
        line-height: 1.3;
    }

    h1 {
        font-size: clamp(1.5rem, 4vw, 2.5rem);
        text-align: center;
        padding: clamp(10px, 2vw, 20px) 0;
    }

    h2 {
        font-size: clamp(1.25rem, 3vw, 2rem);
    }

    h3 {
        font-size: clamp(1.1rem, 2.5vw, 1.5rem);
    }

    /* Main Schedule Container */
    .schedule-container {
        background: rgba(255, 255, 255, 0.03);
        border-radius: clamp(8px, 2vw, 16px);
        padding: clamp(16px, 3vw, 24px);
        border: 1px solid var(--border-color);
        margin-top: clamp(16px, 3vw, 24px);
        box-shadow: var(--card-shadow);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }

    /* Header Actions */
    .header-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        gap: clamp(12px, 2vw, 20px);
        margin-bottom: clamp(16px, 3vw, 24px);
        padding-bottom: clamp(12px, 2vw, 16px);
        border-bottom: 1px solid var(--border-color);
    }

    .filter-container {
        display: flex;
        flex-wrap: wrap;
        gap: clamp(8px, 1.5vw, 12px);
        align-items: center;
        flex: 1;
        min-width: 250px;
    }

    .filter-container form {
        display: flex;
        flex-wrap: wrap;
        gap: clamp(8px, 1.5vw, 12px);
        width: 100%;
    }

    .filter-container label {
        font-weight: 500;
        font-size: clamp(0.875rem, 1.5vw, 1rem);
        white-space: nowrap;
    }

    .filter-container select {
        padding: clamp(8px, 1.5vw, 12px);
        border-radius: 8px;
        background-color: rgba(255, 255, 255, 0.08);
        color: var(--text-light);
        border: 1px solid var(--border-color);
        font-size: clamp(0.875rem, 1.5vw, 1rem);
        min-width: 150px;
        flex: 1;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .filter-container select:focus {
        outline: none;
        border-color: var(--accent-color);
        box-shadow: 0 0 0 2px rgba(3, 155, 229, 0.2);
    }

    .filter-container button,
    .schedule-options button {
        padding: clamp(10px, 1.5vw, 14px) clamp(16px, 2vw, 24px);
        border-radius: 8px;
        background: linear-gradient(135deg, var(--accent-color), #0277d1);
        color: var(--text-light);
        border: none;
        cursor: pointer;
        font-weight: 500;
        font-size: clamp(0.875rem, 1.5vw, 1rem);
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        white-space: nowrap;
        flex-shrink: 0;
        min-height: 44px;
    }

    .filter-container button:hover,
    .schedule-options button:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(3, 155, 229, 0.3);
    }

    .filter-container button:active,
    .schedule-options button:active {
        transform: translateY(0);
    }

    .schedule-options {
        display: flex;
        flex-wrap: wrap;
        gap: clamp(8px, 1.5vw, 12px);
        flex: 1;
        justify-content: flex-end;
        min-width: 250px;
    }

    /* View Toggle */
    .view-toggle {
        display: flex;
        gap: clamp(6px, 1vw, 10px);
        margin-bottom: clamp(16px, 3vw, 24px);
        flex-wrap: wrap;
        justify-content: center;
    }

    .view-toggle button {
        padding: clamp(10px, 1.5vw, 14px) clamp(20px, 2.5vw, 30px);
        border-radius: 8px;
        background-color: rgba(255, 255, 255, 0.05);
        color: var(--text-light);
        border: 1px solid var(--border-color);
        cursor: pointer;
        font-weight: 500;
        font-size: clamp(0.875rem, 1.5vw, 1rem);
        transition: all 0.3s ease;
        flex: 1;
        min-width: 120px;
        text-align: center;
    }

    .view-toggle button.active {
        background: linear-gradient(135deg, var(--accent-color), #0277d1);
        border-color: var(--accent-color);
    }

    .view-toggle button:hover:not(.active) {
        background-color: rgba(255, 255, 255, 0.1);
        transform: translateY(-1px);
    }

    /* Week View Table */
    #week-view {
        overflow-x: auto;
        margin: 0 calc(clamp(12px, 3vw, 24px) * -1);
        padding: 0 clamp(12px, 3vw, 24px);
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        scrollbar-color: var(--accent-color) rgba(255, 255, 255, 0.1);
    }

    #week-view::-webkit-scrollbar {
        height: 8px;
    }

    #week-view::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
    }

    #week-view::-webkit-scrollbar-thumb {
        background: var(--accent-color);
        border-radius: 4px;
    }

    .timetable {
        width: 100%;
        min-width: 800px;
        border-collapse: separate;
        border-spacing: 2px;
        margin: clamp(16px, 3vw, 24px) 0;
        font-size: clamp(0.75rem, 1.5vw, 0.875rem);
    }

    .timetable th {
        background-color: var(--secondary-bg);
        color: var(--text-light);
        padding: clamp(10px, 2vw, 16px);
        text-align: center;
        font-weight: 600;
        border-radius: 8px;
        position: sticky;
        top: 0;
        z-index: 10;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }

    .timetable th.today-highlight {
        background: linear-gradient(135deg, var(--accent-color), #0277d1);
        position: relative;
        overflow: visible;
    }

    .timetable th.today-highlight::after {
        content: 'Aujourd\'hui';
        position: absolute;
        top: -10px;
        left: 50%;
        transform: translateX(-50%);
        background-color: var(--accent-color);
        padding: 2px 10px;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .timetable td {
        padding: 0;
        height: clamp(80px, 15vw, 120px);
        min-height: 80px;
        vertical-align: top;
        background-color: rgba(255, 255, 255, 0.03);
        border-radius: 6px;
        transition: background-color 0.3s ease;
    }

    .timetable td:hover {
        background-color: rgba(255, 255, 255, 0.05);
    }

    .timetable td.time-column {
        width: clamp(70px, 10vw, 100px);
        padding: clamp(8px, 1.5vw, 12px);
        text-align: center;
        background-color: var(--secondary-bg);
        font-weight: 600;
        vertical-align: middle;
        position: sticky;
        left: 0;
        z-index: 5;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.2);
    }

    .timetable thead th:first-child {
        position: sticky;
        left: 0;
        z-index: 15;
        box-shadow: 2px 0 8px rgba(0, 0, 0, 0.2);
    }

    /* Course Blocks */
    .course-block {
        height: 100%;
        padding: clamp(8px, 1.5vw, 12px);
        border-radius: 6px;
        background: linear-gradient(135deg, rgba(3, 155, 229, 0.15), rgba(3, 155, 229, 0.25));
        border-left: 4px solid var(--accent-color);
        transition: all 0.3s ease;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        overflow: hidden;
        cursor: pointer;
        position: relative;
        min-height: 80px;
    }

    .course-block:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(3, 155, 229, 0.3);
        background: linear-gradient(135deg, rgba(3, 155, 229, 0.25), rgba(3, 155, 229, 0.35));
    }

    .course-block .course-name {
        font-weight: 600;
        font-size: clamp(0.75rem, 1.5vw, 0.9rem);
        margin-bottom: 4px;
        line-height: 1.3;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }

    .course-block .course-details {
        font-size: clamp(0.65rem, 1.2vw, 0.75rem);
        display: flex;
        flex-direction: column;
        gap: 3px;
        color: rgba(255, 255, 255, 0.8);
    }

    .course-block .course-details span {
        display: flex;
        align-items: center;
        gap: 5px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .course-block .course-details i {
        font-size: 0.8em;
        flex-shrink: 0;
    }

    .empty-slot {
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        color: rgba(255, 255, 255, 0.3);
        font-style: italic;
        padding: 10px;
        text-align: center;
        font-size: clamp(0.7rem, 1.2vw, 0.8rem);
    }

    /* Day View Navigation */
    .day-navigation {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: clamp(10px, 2vw, 20px);
        margin-bottom: clamp(16px, 3vw, 24px);
        flex-wrap: wrap;
    }

    .day-navigation button {
        background-color: rgba(255, 255, 255, 0.08);
        color: var(--text-light);
        border: 1px solid var(--border-color);
        width: clamp(36px, 5vw, 44px);
        height: clamp(36px, 5vw, 44px);
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 1.2rem;
        flex-shrink: 0;
    }

    .day-navigation button:hover {
        background-color: var(--accent-color);
        transform: scale(1.1);
    }

    .day-navigation #selected-date {
        font-size: clamp(1rem, 2vw, 1.2rem);
        font-weight: 600;
        color: var(--accent-color);
        text-align: center;
        min-width: 200px;
    }

    /* Day Timetable */
    .day-timetable {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        font-size: clamp(0.85rem, 1.5vw, 1rem);
    }

    .day-timetable th {
        background-color: var(--secondary-bg);
        color: var(--text-light);
        padding: clamp(12px, 2vw, 16px);
        text-align: center;
        font-weight: 600;
        border-bottom: 2px solid var(--accent-color);
    }

    .day-timetable td {
        padding: clamp(12px, 2vw, 16px);
        vertical-align: middle;
        border-bottom: 1px solid var(--border-color);
    }

    .day-timetable td.time-column {
        width: clamp(80px, 10vw, 120px);
        font-weight: 600;
        background-color: rgba(255, 255, 255, 0.03);
    }

    /* Stats Section */
    .schedule-stats {
        margin-top: clamp(24px, 4vw, 40px);
        padding: clamp(16px, 3vw, 24px);
        background: rgba(255, 255, 255, 0.03);
        border-radius: 12px;
        border: 1px solid var(--border-color);
        box-shadow: var(--card-shadow);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: clamp(12px, 2vw, 20px);
        margin-top: clamp(12px, 2vw, 20px);
    }

    .stat-card {
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.02));
        padding: clamp(16px, 2.5vw, 24px);
        border-radius: 10px;
        text-align: center;
        border: 1px solid var(--border-color);
        transition: all 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(3, 155, 229, 0.2);
        border-color: var(--accent-color);
    }

    .stat-card h4 {
        font-size: clamp(0.875rem, 1.5vw, 1rem);
        margin-bottom: clamp(8px, 1.5vw, 12px);
        color: rgba(255, 255, 255, 0.9);
    }

    .stat-card .stat-value {
        font-size: clamp(1.5rem, 3vw, 2.5rem);
        font-weight: 700;
        color: var(--accent-color);
        line-height: 1.2;
    }

    /* Legend */
    .schedule-legend {
        margin-top: clamp(16px, 3vw, 24px);
        display: flex;
        flex-wrap: wrap;
        gap: clamp(12px, 2vw, 20px);
        justify-content: center;
        align-items: center;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: clamp(0.75rem, 1.2vw, 0.875rem);
    }

    .legend-color {
        width: clamp(16px, 2vw, 20px);
        height: clamp(16px, 2vw, 20px);
        border-radius: 4px;
        flex-shrink: 0;
    }

    .legend-color.course {
        background: linear-gradient(135deg, var(--accent-color), #0277d1);
    }

    /* Animations */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .course-block,
    .stat-card,
    .day-timetable tr {
        animation: fadeInUp 0.5s ease forwards;
    }

    /* Responsive Breakpoints */
    @media (max-width: 768px) {
        .header-actions {
            flex-direction: column;
            align-items: stretch;
        }
        
        .filter-container,
        .schedule-options {
            width: 100%;
            min-width: unset;
        }
        
        .filter-container form {
            flex-direction: column;
        }
        
        .filter-container select {
            min-width: unset;
            width: 100%;
        }
        
        .timetable th.today-highlight::after {
            font-size: 0.6rem;
            top: -8px;
            padding: 1px 6px;
        }
        
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        #week-view::after {
            content: '← Faites défiler →';
            display: block;
            text-align: center;
            color: rgba(255, 255, 255, 0.5);
            padding: 10px 0;
            font-size: 0.8rem;
            font-style: italic;
        }
    }

    @media (max-width: 480px) {
        .container {
            padding: 8px;
        }
        
        .schedule-container {
            padding: 12px;
            border-radius: 8px;
        }
        
        .view-toggle {
            flex-direction: column;
        }
        
        .view-toggle button {
            min-width: unset;
            width: 100%;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .timetable {
            min-width: 600px;
            font-size: 0.7rem;
        }
        
        .timetable td.time-column {
            width: 60px;
            padding: 6px;
            font-size: 0.7rem;
        }
        
        .course-block {
            padding: 6px;
            min-height: 70px;
        }
        
        .course-block .course-name {
            font-size: 0.7rem;
        }
        
        .course-block .course-details {
            font-size: 0.65rem;
        }
        
        .day-timetable th,
        .day-timetable td {
            padding: 8px;
            font-size: 0.8rem;
        }
    }

    @media (max-width: 360px) {
        .timetable {
            min-width: 500px;
        }
        
        .timetable td.time-column {
            width: 50px;
        }
        
        .day-navigation {
            flex-direction: column;
            gap: 8px;
        }
        
        .day-navigation #selected-date {
            order: -1;
            min-width: unset;
        }
    }

    /* Landscape Mode Optimization */
    @media (max-height: 600px) and (orientation: landscape) {
        .timetable td {
            height: 60px;
            min-height: 60px;
        }
        
        .course-block {
            min-height: 60px;
            padding: 4px;
        }
        
        .course-block .course-name {
            font-size: 0.7rem;
            -webkit-line-clamp: 1;
        }
        
        .course-block .course-details {
            display: none;
        }
    }

    /* High DPI Screens */
    @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
        .course-block,
        .stat-card {
            border-width: 0.5px;
        }
    }

    /* Print Styles */
    @media print {
        body {
            background-color: white;
            color: black;
        }
        
        .schedule-container {
            box-shadow: none;
            border: 1px solid #ddd;
        }
        
        .filter-container,
        .schedule-options,
        .view-toggle,
        .schedule-stats,
        .schedule-legend {
            display: none;
        }
        
        .timetable {
            border: 1px solid #000;
        }
        
        .timetable th,
        .timetable td {
            border: 1px solid #000;
            background-color: white !important;
            color: black !important;
        }
        
        .course-block {
            background-color: #f0f0f0 !important;
            border-left: 2px solid #000;
        }
    }

    /* Reduced Motion */
    @media (prefers-reduced-motion: reduce) {
        * {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
        }
    }
</style>
</head>
<body>
<?php include '../includes/header_discussion.php'; ?>

    <div class="container">
        <h1><i class="fas fa-calendar-alt"></i> Mon emploi du temps d'enseignement</h1>

        <div class="schedule-container">
            <div class="header-actions">
                <div class="filter-container">
                    <form method="GET" action="">
                        <label for="class_id">Filtrer par classe:</label>
                        <select name="class_id" id="class_id">
                            <option value="">Toutes les classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo (isset($_GET['class_id']) && $_GET['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                    <?php echo $class['name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Filtrer</button>
                    </form>
                </div>
                <div class="schedule-options">
                    <button id="btn-today" title="Afficher aujourd'hui">
                        <i class="fas fa-calendar-day"></i> Aujourd'hui
                    </button>
                    <button id="btn-print" title="Imprimer l'emploi du temps">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                </div>
            </div>

            <div class="view-toggle">
                <button class="active" data-view="week">Vue hebdomadaire</button>
                <button data-view="day">Vue journalière</button>
            </div>

            <div id="week-view">
                <table class="timetable">
                    <thead>
                        <tr>
                            <th>Horaire</th>
                            <?php foreach ($weekdays as $weekday): ?>
                                <th><?php echo $weekday['name']; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($timeslots as $timeslot): ?>
                            <tr>
                                <td class="time-column">
                                    <?php echo $timeslot['start_time']; ?><br>-<br><?php echo $timeslot['end_time']; ?>
                                </td>
                                
                                <?php foreach ($weekdays as $weekday): ?>
                                    <td>
                                        <?php
                                        $has_course = false;
                                        foreach ($schedule as $course) {
                                            if ($course['weekday_id'] == $weekday['id'] && $course['timeslot_id'] == $timeslot['id']) {
                                                $has_course = true;
                                                echo '<div class="course-block">';
                                                echo '<div class="course-name">' . $course['course_name'] . '</div>';
                                                echo '<div class="course-details">';
                                                echo '<span><i class="fas fa-users"></i> ' . $course['class_name'] . '</span>';
                                                echo '<span><i class="fas fa-chalkboard"></i> ' . $course['classroom_name'] . '</span>';
                                                echo '<span><i class="fas fa-clock"></i> ' . $course['start_time'] . ' - ' . $course['end_time'] . '</span>';
                                                echo '</div>';
                                                echo '</div>';
                                                break;
                                            }                   

                                        }
                                        
                                        if (!$has_course) {
                                            echo '<div class="empty-slot">Aucun cours</div>';
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="day-view" style="display: none;">
                <h3 id="day-title">Emploi du temps pour <span id="current-day">aujourd'hui</span></h3>
                <div class="day-navigation">
                    <button id="prev-day"><i class="fas fa-chevron-left"></i></button>
                    <span id="selected-date"><?php echo date('d/m/Y'); ?></span>
                    <button id="next-day"><i class="fas fa-chevron-right"></i></button>
                </div>
                
                <table class="timetable day-timetable">
                    <thead>
                        <tr>
                            <th>Horaire</th>
                            <th>Cours</th>
                            <th>Classe</th>
                            <th>Salle</th>
                        </tr>
                    </thead>
                    <tbody id="day-schedule-body">
                        <!-- Contenu généré dynamiquement par JavaScript -->
                    </tbody>
                </table>
            </div>

            <div class="schedule-stats">
                <h3>Résumé de mon emploi du temps</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <h4>Heures d'enseignement hebdomadaires</h4>
                        <div class="stat-value" id="total-hours">Calcul...</div>
                    </div>
                    <div class="stat-card">
                        <h4>Nombre de cours</h4>
                        <div class="stat-value" id="total-courses">Calcul...</div>
                    </div>
                    <div class="stat-card">
                        <h4>Classes enseignées</h4>
                        <div class="stat-value" id="total-classes">Calcul...</div>
                    </div>
                </div>
            </div>

            <div class="schedule-legend">
                <div class="legend-item">
                    <div class="legend-color course"></div>
                    <span>Cours programmé</span>
                </div>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Données de l'emploi du temps
            const scheduleData = <?php echo json_encode($schedule); ?>;
            const weekdays = <?php echo json_encode($weekdays); ?>;
            const timeslots = <?php echo json_encode($timeslots); ?>;
            
            // Gestion des vues (hebdomadaire/journalière)
            const weekView = document.getElementById('week-view');
            const dayView = document.getElementById('day-view');
            const viewButtons = document.querySelectorAll('.view-toggle button');
            
            viewButtons.forEach(button => {
                button.addEventListener('click', function() {
                    viewButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    const view = this.getAttribute('data-view');
                    if (view === 'week') {
                        weekView.style.display = 'block';
                        dayView.style.display = 'none';
                    } else {
                        weekView.style.display = 'none';
                        dayView.style.display = 'block';
                        updateDayView();
                    }
                });
            });
            
            // Fonction pour obtenir le jour de la semaine (0-6, où 0 est Dimanche)
            function getDayOfWeek(dateStr) {
                const date = new Date(dateStr);
                return date.getDay();
            }
            
            // Gestion de la vue journalière
            let currentDate = new Date();
            const dayTitle = document.getElementById('current-day');
            const selectedDate = document.getElementById('selected-date');
            const prevDayBtn = document.getElementById('prev-day');
            const nextDayBtn = document.getElementById('next-day');
            const dayScheduleBody = document.getElementById('day-schedule-body');
            
            function formatDate(date) {
                const day = String(date.getDate()).padStart(2, '0');
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const year = date.getFullYear();
                return `${day}/${month}/${year}`;
            }
            
            function updateDayView() {
                // Mettre à jour l'affichage de la date
                selectedDate.textContent = formatDate(currentDate);
                
                // Déterminer le jour de la semaine (1-7, où 1 est Lundi dans notre base de données)
                let dayOfWeek = getDayOfWeek(currentDate);
                dayOfWeek = dayOfWeek === 0 ? 7 : dayOfWeek; // Convertir dimanche (0) en 7
                
                // Trouver le nom du jour
                const dayName = weekdays.find(day => day.id == dayOfWeek)?.name || 'Inconnu';
                dayTitle.textContent = `Emploi du temps pour ${dayName}`;
                
                // Vider le contenu actuel
                dayScheduleBody.innerHTML = '';
                
                // Filtrer les cours pour ce jour
                const daySchedule = scheduleData.filter(course => course.weekday_id == dayOfWeek);
                
                if (daySchedule.length === 0) {
                    const row = document.createElement('tr');
                    const noClassCell = document.createElement('td');
                    noClassCell.colSpan = 4;
                    noClassCell.textContent = 'Aucun cours pour ce jour';
                    noClassCell.style.textAlign = 'center';
                    noClassCell.style.padding = '20px';
                    row.appendChild(noClassCell);
                    dayScheduleBody.appendChild(row);
                    return;
                }
                
                // Trier par heure de début
                daySchedule.sort((a, b) => {
                    return a.start_time.localeCompare(b.start_time);
                });
                
                // Générer les lignes pour chaque cours
                daySchedule.forEach(course => {
                    const row = document.createElement('tr');
                    
                    const timeCell = document.createElement('td');
                    timeCell.className = 'time-column';
                    timeCell.innerHTML = `${course.start_time}<br>-<br>${course.end_time}`;
                    row.appendChild(timeCell);
                    
                    const courseCell = document.createElement('td');
                    courseCell.textContent = course.course_name;
                    row.appendChild(courseCell);
                    
                    const classCell = document.createElement('td');
                    classCell.textContent = course.class_name;
                    row.appendChild(classCell);
                    
                    const roomCell = document.createElement('td');
                    roomCell.textContent = course.classroom_name;
                    row.appendChild(roomCell);
                    
                    dayScheduleBody.appendChild(row);
                });
            }
            
            // Navigation entre les jours
            prevDayBtn.addEventListener('click', function() {
                currentDate.setDate(currentDate.getDate() - 1);
                updateDayView();
            });
            
            nextDayBtn.addEventListener('click', function() {
                currentDate.setDate(currentDate.getDate() + 1);
                updateDayView();
            });
            
            // Bouton Aujourd'hui
            document.getElementById('btn-today').addEventListener('click', function() {
                currentDate = new Date();
                
                // Si en vue journalière, mettre à jour l'affichage
                if (dayView.style.display !== 'none') {
                    updateDayView();
                }
                
                // Faire défiler vers le jour actuel dans la vue hebdomadaire
                highlightToday();
            });
            
            function highlightToday() {
                // Mettre en évidence la colonne correspondant au jour actuel
                const today = new Date();
                let dayOfWeek = today.getDay();
                dayOfWeek = dayOfWeek === 0 ? 7 : dayOfWeek; // Convertir dimanche (0) en 7
                
                // Supprimer la mise en évidence précédente
                document.querySelectorAll('.timetable th.today-highlight').forEach(el => {
                    el.classList.remove('today-highlight');
                });
                
                // Ajouter la mise en évidence
                const headers = document.querySelectorAll('.timetable thead th');
                if (headers.length > dayOfWeek) {
                    headers[dayOfWeek].classList.add('today-highlight');
                }
            }
            
            // Calculer les statistiques
            function calculateStats() {
                // Heures d'enseignement hebdomadaires
                let totalMinutes = 0;
                const uniqueCourses = new Set();
                const uniqueClasses = new Set();
                
                scheduleData.forEach(course => {
                    // Calculer la durée en minutes
                    const startTimeParts = course.start_time.split(':').map(Number);
                    const endTimeParts = course.end_time.split(':').map(Number);
                    
                    const startMinutes = startTimeParts[0] * 60 + startTimeParts[1];
                    const endMinutes = endTimeParts[0] * 60 + endTimeParts[1];
                    
                    totalMinutes += endMinutes - startMinutes;
                    
                    // Compter les cours uniques
                    uniqueCourses.add(course.course_id);
                    
                    // Compter les classes uniques
                    uniqueClasses.add(course.class_id);
                });
                
                // Convertir les minutes en heures
                const totalHours = Math.round(totalMinutes / 60 * 10) / 10; // Arrondi à 1 décimale
                
                // Mettre à jour l'affichage
                document.getElementById('total-hours').textContent = `${totalHours}h`;
                document.getElementById('total-courses').textContent = uniqueCourses.size;
                document.getElementById('total-classes').textContent = uniqueClasses.size;
            }
            
            // Impression de l'emploi du temps
            document.getElementById('btn-print').addEventListener('click', function() {
                window.print();
            });
            
            // Initialisation
            updateDayView();
            highlightToday();
            calculateStats();
            
            // Ajouter des styles pour l'impression
            const printStyles = document.createElement('style');
            printStyles.textContent = `
                @media print {
                    header, footer, .schedule-options, .view-toggle, .day-navigation, .filter-container, .schedule-stats {
                        display: none !important;
                    }
                    
                    body, .container, .schedule-container {
                        background-color: white !important;
                        color: black !important;
                    }
                    
                    .timetable th {
                        background-color: #f0f0f0 !important;
                        color: black !important;
                    }
                    
                    .timetable td.time-column {
                        background-color: #f0f0f0 !important;
                        color: black !important;
                    }
                    
                    .course-block {
                        background-color: #e1f5fe !important;
                        color: black !important;
                        border: 1px solid #b3e5fc !important;
                    }
                    
                    .empty-slot {
                        color: #999 !important;
                    }
                    
                    h1, h2, h3 {
                        color: #0288d1 !important;
                    }
                }
            `;
            document.head.appendChild(printStyles);
        });
    </script>
</body>
</html>