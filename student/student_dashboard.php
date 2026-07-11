<?php
require_once '../includes/db_connect.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.html");
    exit();
}

// Gestion de la persistance du semestre
if (isset($_GET['semester'])) {
    $_SESSION['selected_semester'] = (int)$_GET['semester'];
    $_SESSION['message'] = [
        'type' => 'success',
        'text' => 'Semestre ' . $_GET['semester'] . ' sélectionné avec succès!'
    ];
}

// Utiliser le semestre sauvegardé ou par défaut le semestre 1
$semester = isset($_SESSION['selected_semester']) ? $_SESSION['selected_semester'] : 1;

// Récupérer l'ID de l'utilisateur connecté
$student_id = $_SESSION['user_id'];

// ============================================================
// GESTION DE L'ANNÉE ACADÉMIQUE CONSULTÉE
// ============================================================
$current_academic_year = defined('ANNEE_ACADEMIQUE_COURANTE')
    ? ANNEE_ACADEMIQUE_COURANTE
    : date('Y') . '-' . (date('Y') + 1);

if (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year'])) {
    $_SESSION['selected_academic_year'] = $_GET['year'];
    if ($_GET['year'] !== $current_academic_year) {
        $_SESSION['message'] = [
            'type' => 'success',
            'text' => 'Année académique ' . $_GET['year'] . ' sélectionnée avec succès!'
        ];
    }
}

$selected_academic_year = (isset($_SESSION['selected_academic_year']) && preg_match('/^\d{4}-\d{4}$/', $_SESSION['selected_academic_year']))
    ? $_SESSION['selected_academic_year']
    : $current_academic_year;

// Liste des années académiques connues pour cet étudiant (historique + année courante)
$academic_years = [];
$stmt_years = $conn->prepare("SELECT DISTINCT academic_year FROM student_class_history WHERE student_id = ? ORDER BY academic_year DESC");
if ($stmt_years) {
    $stmt_years->bind_param("s", $student_id);
    $stmt_years->execute();
    $res_years = $stmt_years->get_result();
    while ($row_y = $res_years->fetch_assoc()) {
        $academic_years[] = $row_y['academic_year'];
    }
    $stmt_years->close();
}
if (!in_array($current_academic_year, $academic_years, true)) {
    array_unshift($academic_years, $current_academic_year);
}

// ============================================================
// Récupération de la classe de l'étudiant POUR L'ANNÉE SÉLECTIONNÉE
// — l'année courante utilise la classe active (users.class_id),
// — une année passée utilise la classe historique (student_class_history)
//   afin que le contenu affiché corresponde à ce que l'étudiant a réellement vécu.
// ============================================================
$class_id = null;

if ($selected_academic_year === $current_academic_year) {
    $query_class = "SELECT class_id FROM users WHERE id = CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci AND role = 'student'";
    $stmt_class = $conn->prepare($query_class);

    if (!$stmt_class) {
        die("Prepare failed: " . $conn->error);
    }

    $stmt_class->bind_param("s", $student_id);
    $stmt_class->execute();
    $class_result = $stmt_class->get_result()->fetch_assoc();
    $stmt_class->close();

    if ($class_result) {
        $class_id = $class_result['class_id'];
    }
} else {
    $stmt_hist = $conn->prepare("
        SELECT class_id FROM student_class_history
        WHERE student_id = ? AND academic_year = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt_hist->bind_param("ss", $student_id, $selected_academic_year);
    $stmt_hist->execute();
    $hist_result = $stmt_hist->get_result()->fetch_assoc();
    $stmt_hist->close();

    if ($hist_result) {
        $class_id = $hist_result['class_id'];
    }
}

// Récupération des cours pour le semestre sélectionné
$courses = [];
if ($class_id !== null) {
    // Vérifier si la colonne class_id existe
    $check_columns = "SHOW COLUMNS FROM courses";
    $columns_result = $conn->query($check_columns);
    $has_class_id = false;
    
    if ($columns_result) {
        while ($column = $columns_result->fetch_assoc()) {
            if ($column['Field'] === 'class_id') {
                $has_class_id = true;
                break;
            }
        }
    }

    if ($has_class_id) {
        // Requête modifiée avec conversion de collation explicite
        $query_courses = "SELECT id, name, major, image_path, class_id 
                         FROM courses 
                         WHERE semester = ? 
                         AND CONVERT(class_id USING utf8mb4) COLLATE utf8mb4_general_ci IS NOT NULL";
        
        $stmt_courses = $conn->prepare($query_courses);
        
        if (!$stmt_courses) {
            die("Prepare failed for courses query: " . $conn->error);
        }
        
        $stmt_courses->bind_param("i", $semester);
        $stmt_courses->execute();
        $result_courses = $stmt_courses->get_result();
        
        $filtered_courses = [];
        if ($result_courses && $result_courses->num_rows > 0) {
            while ($row = $result_courses->fetch_assoc()) {
                if (isset($row['class_id']) && !empty($row['class_id'])) {
                    try {
                        $course_classes = json_decode($row['class_id'], true);
                        if (is_array($course_classes) && in_array($class_id, $course_classes)) {
                            $filtered_courses[] = $row;
                        }
                    } catch (Exception $e) {
                        error_log("Erreur de décodage JSON pour le cours ID " . $row['id'] . ": " . $e->getMessage());
                    }
                }
            }
            $courses = $filtered_courses;
        }
    } else {
        // Fallback query sans filtrage par classe
        $query_courses = "SELECT id, name, major, image_path 
                         FROM courses 
                         WHERE semester = ?";
        
        $stmt_courses = $conn->prepare($query_courses);
        
        if (!$stmt_courses) {
            die("Prepare failed for simple courses query: " . $conn->error);
        }
        
        $stmt_courses->bind_param("i", $semester);
        $stmt_courses->execute();
        $result_courses = $stmt_courses->get_result();
        
        if ($result_courses && $result_courses->num_rows > 0) {
            while ($row = $result_courses->fetch_assoc()) {
                $courses[] = $row;
            }
        }
    }
    
    if (empty($courses)) {
        $_SESSION['message'] = [
            'type' => 'info',
            'text' => "Aucun cours trouvé pour la classe ID : $class_id en semestre $semester"
        ];
    }
} else {
    $_SESSION['message'] = ($selected_academic_year === $current_academic_year)
        ? ['type' => 'warning', 'text' => "Vous n'êtes associé à aucune classe. Veuillez contacter l'administration."]
        : ['type' => 'info', 'text' => "Aucune classe trouvée pour l'année $selected_academic_year."];
}

// Récupérer les annonces avec conversion de collation
$query_announcements = "SELECT content FROM announcements WHERE announcement_type = 'global'";
$result_announcements = $conn->query($query_announcements);

$announcements = [];
if ($result_announcements && $result_announcements->num_rows > 0) {
    while ($row = $result_announcements->fetch_assoc()) {
        $announcements[] = $row;
    }
}

// Ajouter les annonces spécifiques à la classe avec conversion de collation
if (!empty($class_id)) {
    $query_class_announcements = "SELECT content FROM announcements 
                                 WHERE announcement_type = 'class' 
                                 AND CONVERT(class_id USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci";
    $stmt_class_announcements = $conn->prepare($query_class_announcements);
    
    if ($stmt_class_announcements) {
        $stmt_class_announcements->bind_param("s", $class_id);
        $stmt_class_announcements->execute();
        $result_class_announcements = $stmt_class_announcements->get_result();
        
        if ($result_class_announcements && $result_class_announcements->num_rows > 0) {
            while ($row = $result_class_announcements->fetch_assoc()) {
                $announcements[] = $row;
            }
        }
    }
}

// Récupérer l'emploi du temps de la classe avec conversion de collation
$timetable_image_path = null;
if (!empty($class_id)) {
    $query_timetable = "SELECT timetable_image_path FROM classes 
                       WHERE CONVERT(id USING utf8mb4) COLLATE utf8mb4_general_ci = CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci";
    $stmt_timetable = $conn->prepare($query_timetable);
    
    if ($stmt_timetable) {
        $stmt_timetable->bind_param("s", $class_id);
        $stmt_timetable->execute();
        $timetable_result = $stmt_timetable->get_result()->fetch_assoc();
        $timetable_image_path = $timetable_result ? $timetable_result['timetable_image_path'] : null;
    }
}
?>

<!-- Le reste du HTML reste identique -->
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes cours - Application Université</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="../pages/styles.css">
    
    <style>
        :root {
            --primary-color: #051e34;
            --primary-color-hover: #0288d1;
            --background-color: #051e34;
            --text-color: #ffffff;
            --success-color: #27ae60;
            --error-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
            --accent-color: rgba(3, 155, 229, 0.5);
        }

        /* Styles de base avec thème bleu professionnel par défaut */
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--background-color);
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        /* Toast Notifications */
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

        /* Confirmation Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background-color: #ffffff;
            margin: 15% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            transform: scale(0.8);
            animation: scaleIn 0.3s ease forwards;
            color: #333;
        }

        .modal-content h3 {
            margin-top: 0;
            color: var(--primary-color);
            font-size: 1.3em;
        }

        .modal-content p {
            color: #666;
            margin: 20px 0;
            line-height: 1.5;
        }

        .modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 25px;
        }

        .modal-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            min-width: 100px;
        }

        .modal-btn.confirm {
            background: var(--success-color);
            color: white;
        }

        .modal-btn.confirm:hover {
            background: #229954;
            transform: translateY(-2px);
        }

        .modal-btn.cancel {
            background: #95a5a6;
            color: white;
        }

        .modal-btn.cancel:hover {
            background: #7f8c8d;
            transform: translateY(-2px);
        }

        @keyframes scaleIn {
            from { transform: scale(0.8); }
            to { transform: scale(1); }
        }

        /* Header styles */
        header {
            background-color: var(--primary-color);
            color: white;
            padding: 20px;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        header .logo h1 {
    color: #039be5;
    margin: 0;
    font-size: 24px;
}

        /* Navigation styles */
        nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            justify-content: center;
            gap: 20px;
        }

        nav ul li {
            display: inline;
        }

        .nav-button {
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 5px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.1);
        }

        .nav-button:hover {
            background: #039be5;
            transform: translateY(-2px);
        }

        /* Floating Icons Styles */
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

        .floating-icon:nth-child(1) { 
            left: 10%; 
            top: 20%; 
            animation-delay: 0s; 
        }
        .floating-icon:nth-child(2) { 
            left: 30%; 
            top: 60%; 
            animation-delay: 0.5s; 
        }
        .floating-icon:nth-child(3) { 
            left: 50%; 
            top: 30%; 
            animation-delay: 1s; 
        }
        .floating-icon:nth-child(4) { 
            left: 70%; 
            top: 50%; 
            animation-delay: 1.5s; 
        }
        .floating-icon:nth-child(5) { 
            left: 90%; 
            top: 40%; 
            animation-delay: 2s; 
        }

        @keyframes floatIcon {
            0% { transform: translateY(100%); opacity: 0; }
            50% { opacity: 0.3; }
            100% { transform: translateY(-100%); opacity: 0; }
        }

        /* Annonces styles */
        .announcements {
            background-color: var(--warning-color);
            color: white;
            padding: 15px;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .announcement-content {
            display: flex;
            animation: scroll-left 20s linear infinite;
        }

        /* Grille des cours styles */
        .courses-header {
            text-align: center;
            margin: 15px 0;
            padding: 0 15px;
        }

        .courses-header h2 {
            color: var(--text-color);
            font-size: 2em;
            margin-bottom: 10px;
        }

        .semester-info {
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            display: inline-block;
            margin-top: 10px;
            font-weight: 500;
        }

        .course-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 15px;
            padding: 15px;
            margin: 0 auto;
            max-width: 1000px;
            width: 100%;
            box-sizing: border-box;
        }

        .course-card {
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 8px;
            box-shadow: 0 8px 8px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 280px;
            cursor: pointer;
            position: relative;
        }

        .course-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .course-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.15);
        }

        .course-card:hover::before {
            transform: scaleX(1);
        }

        .course-card img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            transition: transform 0.3s ease;
            border-radius: 8px 8px 0 0;
        }

        .course-card:hover img {
            transform: scale(1.05);
        }

        .course-info {
            padding: 15px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .course-info h3 {
            margin: 0 0 10px 0;
            font-size: 1.2rem;
            color: #333;
        }

        .course-info p {
            margin: 0;
            color: #666;
            flex-grow: 1;
        }

        /* Drawer styles */
        .drawer {
            position: fixed;
            top: 0;
            right: -300px;
            width: 300px;
            height: 100%;
            background: linear-gradient(145deg, var(--primary-color), var(--primary-color-hover));
            box-shadow: -5px 0 15px rgba(0, 0, 0, 0.3);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            padding: 25px;
            border-left: 1px solid rgba(255, 255, 255, 0.1);
            overflow-y: auto;
            color: white;
        }

        .drawer.open {
            right: 0;
            transform: translateX(0);
        }

        .drawer h2 {
            font-size: 1.5rem;
            color: white;
            margin-bottom: 25px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--accent-color);
        }

        .drawer-section {
            margin-bottom: 30px;
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
            width: 100%;
            background: #039be5;
            color: white;
            padding: 12px 20px;
            margin-bottom: 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }

        .drawer-btn:hover {
            background: var(--primary-color-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }

        .drawer-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .drawer-section img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
            border-radius: 8px;
        }

        .drawer-toggle {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 50px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            font-weight: 500;
            box-shadow: 0 2px 8px rgba(3, 155, 229, 0.3);
            transition: all 0.3s ease;
        }

        .drawer-toggle i {
            font-size: 16px;
        }

        .drawer-toggle span {
            display: inline-block;
        }

        .drawer-toggle:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(3, 155, 229, 0.4);
            background: linear-gradient(135deg, #0277bd, var(--accent-color));
        }

        .drawer-toggle:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(3, 155, 229, 0.3);
        }

        @media (max-width: 768px) {
            .drawer-toggle span {
                display: none;
            }
            
            .drawer-toggle {
                padding: 10px;
            }
        }

        .semester-select {
            width: 100%;
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 0.95rem;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .semester-select:hover {
            border-color: var(--accent-color);
            background-color: rgba(255, 255, 255, 0.15);
        }

        .semester-select:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 2px rgba(3, 155, 229, 0.3);
        }

        .semester-select option {
            background-color: var(--primary-color);
            color: white;
        }

        .color-picker {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 15px 0;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
        }

        .color-option {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid transparent;
            position: relative;
        }

        .color-option:hover {
            transform: scale(1.2);
            box-shadow: 0 0 15px rgba(255, 255, 255, 0.5);
            border-color: white;
        }

        .color-option::after {
            content: attr(title);
            position: absolute;
            top: -35px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .color-option:hover::after {
            opacity: 1;
        }

        /* Options d'arrière-plan */
        .background-options {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin: 15px 0;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
        }

        .bg-option {
            width: 50px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
        }

        .bg-option:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.3);
            border-color: var(--accent-color);
        }

        .bg-option::after {
            content: attr(title);
            position: absolute;
            top: -35px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .bg-option:hover::after {
            opacity: 1;
        }

        .custom-color-input {
            width: 100%;
            height: 40px;
            margin: 15px 0;
            padding: 5px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

        .custom-color-input::-webkit-color-swatch {
            border: none;
            border-radius: 8px;
            padding: 0;
        }

        .custom-color-input::-webkit-color-swatch-wrapper {
            border: none;
            border-radius: 8px;
            padding: 0;
        }

        .drawer-close {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 24px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .drawer-close:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        /* Theme sombre simple */
        body.dark-theme {
            --background-color: #1a1a1a;
            --text-color: #ffffff;
        }

        body.dark-theme .course-card {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
        }

        body.dark-theme .course-info h3,
        body.dark-theme .course-info p {
            color: white;
        }

        /* Animations */
        @keyframes scroll-left {
            0% { transform: translateX(100%); }
            100% { transform: translateX(-100%); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Footer styles */
        footer {
            background-color: var(--primary-color);
            color: white;
            text-align: center;
            padding: 20px;
            margin-top: auto;
            position: relative;
        }

        .footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(to right, #039be5, #4CAF50, #039be5);
            animation: shimmer 2s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 30px;
            flex-wrap: wrap;
            padding: 0 20px;
        }

        .footer-logo {
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s ease;
        }

        .footer-logo:hover {
            transform: scale(1.05);
        }

        .footer-text {
            color: #ffffff;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.3s ease;
        }

        .footer-copyright {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 15px;
        }

        .footer-brand {
            color: #039be5;
            font-style: italic;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .footer-brand:hover {
            color: #4CAF50;
        }

        .footer-social {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .social-icon {
            color: #ffffff;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .social-icon:hover {
            background: #039be5;
            transform: translateY(-3px);
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .course-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                padding: 10px;
            }

            .nav-button {
                padding: 8px 15px;
            }

            .drawer {
                width: 280px;
            }

            .toast-container {
                right: 10px;
                left: 10px;
                max-width: none;
            }

            .modal-content {
                width: 95%;
            }

            .footer-content {
                flex-direction: column;
                gap: 20px;
            }
        }

        @media (max-width: 480px) {
            .course-grid {
                grid-template-columns: 1fr;
            }

            .drawer {
                width: 100%;
                right: -100%;
            }

            .modal-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Floating Icons Background -->
    <div class="floating-icons">
        <i class="floating-icon fas fa-graduation-cap"></i>
        <i class="floating-icon fas fa-book"></i>
        <i class="floating-icon fas fa-user-graduate"></i>
        <i class="floating-icon fas fa-certificate"></i>
        <i class="floating-icon fas fa-microscope"></i>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toast-container"></div>

    <!-- Confirmation Modal -->
    <div class="modal" id="confirmationModal">
        <div class="modal-content">
            <h3 id="modalTitle">Confirmation</h3>
            <p id="modalMessage">Êtes-vous sûr de vouloir effectuer cette action ?</p>
            <div class="modal-buttons">
                <button class="modal-btn confirm" id="confirmBtn">Confirmer</button>
                <button class="modal-btn cancel" id="cancelBtn">Annuler</button>
            </div>
        </div>
    </div>

    <?php include '../includes/header_student.php'; ?>

    <div class="drawer" id="drawer">
        <span class="drawer-close" id="drawer-close">&times;</span>
        <h2>Menu</h2>
        
        <div class="drawer-section">
            <h3>Année académique</h3>
            <select class="semester-select" id="year-select">
                <?php foreach ($academic_years as $yr): ?>
                    <option value="<?= htmlspecialchars($yr) ?>" <?= ($yr === $selected_academic_year) ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($yr) ?><?= ($yr === $current_academic_year) ? ' (courante)' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="drawer-section">
            <h3>Semestre</h3>
            <select class="semester-select" id="semester-select">
                <option value="1" <?= ($semester == 1) ? 'selected' : ''; ?>>Semestre 1</option>
                <option value="2" <?= ($semester == 2) ? 'selected' : ''; ?>>Semestre 2</option>
            </select>
        </div>
        
        <div class="drawer-section">
            <h3>Outils</h3>
            <button class="drawer-btn" id="show-schedule">Emploi du temps</button>
            <div class="drawer-section" id="timetable-section" style="display: none;">
                <?php if ($timetable_image_path && file_exists("../uploads/" . $timetable_image_path)): ?>
                    <img src="../uploads/<?= htmlspecialchars($timetable_image_path) ?>" alt="Emploi du temps">
                <?php else: ?>
                    <p>Aucun emploi du temps disponible.</p>
                <?php endif; ?>
            </div>
            <button class="drawer-btn" id="show-grades" onclick="confirmNavigation('student_grades.php', 'Consulter les notes');">Notes</button>
        </div>
        
        <div class="drawer-section">
            <h3>Personnalisation</h3>
            <button class="drawer-btn" id="toggle-theme">Mode sombre/clair</button>
            
            <h4 style="font-size: 0.9rem; margin: 15px 0 10px 0; color: white;">Couleurs du thème</h4>
            <div class="color-picker">
                <div class="color-option" style="background-color: #051e34;" data-color="#051e34" title="Bleu professionnel"></div>
                <div class="color-option" style="background-color: #2c3e50;" data-color="#2c3e50" title="Gris ardoise"></div>
                <div class="color-option" style="background-color: #27ae60;" data-color="#27ae60" title="Vert émeraude"></div>
                <div class="color-option" style="background-color: #8e44ad;" data-color="#8e44ad" title="Violet améthyste"></div>
                <div class="color-option" style="background-color: #e67e22;" data-color="#e67e22" title="Orange carotte"></div>
                <div class="color-option" style="background-color: #34495e;" data-color="#34495e" title="Gris acier"></div>
            </div>
            <input type="color" id="custom-color" class="custom-color-input" value="#051e34">
            
            <h4 style="font-size: 0.9rem; margin: 15px 0 10px 0; color: white;">Arrière-plans</h4>
            <div class="background-options">
                <div class="bg-option" data-bg="#051e34" style="background: #051e34;" title="Bleu professionnel"></div>
                <div class="bg-option" data-bg="linear-gradient(135deg, #667eea 0%, #764ba2 100%)" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);" title="Violet dégradé"></div>
                <div class="bg-option" data-bg="linear-gradient(135deg, #2c3e50 0%, #34495e 100%)" style="background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);" title="Gris foncé"></div>
                <div class="bg-option" data-bg="linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);" title="Bleu cyan"></div>
                <div class="bg-option" data-bg="linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);" title="Vert menthe"></div>
                <div class="bg-option" data-bg="#1a1a1a" style="background: #1a1a1a;" title="Noir moderne"></div>
                <div class="bg-option" data-bg="linear-gradient(135deg, #051e34 0%, #039be5 100%)" style="background: linear-gradient(135deg, #051e34 0%, #039be5 100%);" title="Bleu dégradé"></div>
                <div class="bg-option" data-bg="linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%)" style="background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);" title="Pêche dégradé"></div>
            </div>
            
            <button class="drawer-btn" onclick="restoreDefaultColors()">Restaurer couleurs page des notes</button>
        </div>
    </div>

    <section>
        <div class="announcements">
            <div class="announcement-content">
                <?php if (!empty($announcements)): ?>
                    <?php foreach ($announcements as $announcement): ?>
                        <div class="announcement-item"><?= htmlspecialchars($announcement['content']) ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Aucune annonce trouvée.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="courses-header">
            <h2>Mes Cours</h2>
            <div class="semester-info">
                Année <?= htmlspecialchars($selected_academic_year) ?> — Semestre <?= $semester ?> - <?= count($courses) ?> cours disponibles
            </div>
        </div>
        
        <div class="course-grid" id="course-grid">
    <?php if (!empty($courses)): ?>
        <?php foreach ($courses as $index => $course): ?>
            <div class="course-card"
                 onclick="confirmCourseAccess(
                     <?= htmlspecialchars(json_encode($course['id']), ENT_QUOTES, 'UTF-8') ?>,
                     <?= htmlspecialchars(json_encode($course['name']), ENT_QUOTES, 'UTF-8') ?>
                 );"
                 style="animation-delay: <?= $index * 0.1 ?>s;">
                <img src="<?= htmlspecialchars($course['image_path']) ?>" alt="Image du cours">
                <div class="course-info">
                    <h3><?= htmlspecialchars($course['name']) ?></h3>
                    <p><?= htmlspecialchars($course['major']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--text-color);">
            <p>Aucun cours trouvé pour votre classe.</p>
        </div>
    <?php endif; ?>
</div>

    </section>
        
    <?php include '../includes/footer.php'; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Couleurs par défaut correspondant à la page des notes
        const DEFAULT_COLORS = {
            primaryColor: '#051e34',
            backgroundColor: '#051e34',
            textColor: '#ffffff',
            accentColor: '#039be5'
        };

        // Éléments du DOM
        const drawerToggleRight = document.getElementById('drawer-toggle-right');
        const drawerClose = document.getElementById('drawer-close');
        const drawer = document.getElementById('drawer');
        const semesterSelect = document.getElementById('semester-select');
        const yearSelect = document.getElementById('year-select');
        const showScheduleBtn = document.getElementById('show-schedule');
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

        // ===== MODAL DE CONFIRMATION =====
        function showConfirmationModal(title, message, onConfirm) {
            const modal = document.getElementById('confirmationModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');
            const confirmBtn = document.getElementById('confirmBtn');
            const cancelBtn = document.getElementById('cancelBtn');

            modalTitle.textContent = title;
            modalMessage.textContent = message;
            modal.style.display = 'block';

            const handleConfirm = () => {
                modal.style.display = 'none';
                onConfirm();
                cleanup();
            };

            const handleCancel = () => {
                modal.style.display = 'none';
                showToast('Action annulée', 'info', 3000);
                cleanup();
            };

            const cleanup = () => {
                confirmBtn.removeEventListener('click', handleConfirm);
                cancelBtn.removeEventListener('click', handleCancel);
                modal.removeEventListener('click', handleOutsideClick);
            };

            const handleOutsideClick = (e) => {
                if (e.target === modal) {
                    handleCancel();
                }
            };

            confirmBtn.addEventListener('click', handleConfirm);
            cancelBtn.addEventListener('click', handleCancel);
            modal.addEventListener('click', handleOutsideClick);
        }

        // ===== GESTION DES THÈMES SIMPLE =====
        function loadThemePreferences() {
            const savedPrimaryColor = localStorage.getItem('themePrimaryColor') || DEFAULT_COLORS.primaryColor;
            const savedBackgroundColor = localStorage.getItem('themeBackgroundColor') || DEFAULT_COLORS.backgroundColor;
            const savedTextColor = localStorage.getItem('themeTextColor') || DEFAULT_COLORS.textColor;
            const savedAccentColor = localStorage.getItem('themeAccentColor') || DEFAULT_COLORS.accentColor;
            const isDarkTheme = localStorage.getItem('darkTheme') !== 'false'; // Mode sombre par défaut

            document.documentElement.style.setProperty('--primary-color', savedPrimaryColor);
            document.documentElement.style.setProperty('--background-color', savedBackgroundColor);
            document.documentElement.style.setProperty('--text-color', savedTextColor);
            document.documentElement.style.setProperty('--accent-color', savedAccentColor);

            document.body.style.background = savedBackgroundColor;
            document.body.style.color = savedTextColor;

            const header = document.querySelector('header');
            const footer = document.querySelector('footer');
            if (header) header.style.backgroundColor = savedPrimaryColor;
            if (footer) footer.style.backgroundColor = savedPrimaryColor;

            if (isDarkTheme) {
                document.body.classList.add('dark-theme');
            }

            const customColorInput = document.getElementById('custom-color');
            if (customColorInput) {
                customColorInput.value = savedPrimaryColor;
            }
        }

        function changeThemeColor(color) {
            document.documentElement.style.setProperty('--primary-color', color);
            document.documentElement.style.setProperty('--accent-color', color);
            
            const header = document.querySelector('header');
            const footer = document.querySelector('footer');
            if (header) header.style.backgroundColor = color;
            if (footer) footer.style.backgroundColor = color;
            
            localStorage.setItem('themePrimaryColor', color);
            localStorage.setItem('themeAccentColor', color);
            
            const customColorInput = document.getElementById('custom-color');
            if (customColorInput) {
                customColorInput.value = color;
            }
            
            showToast('Couleur du thème modifiée - compatible page des notes', 'success', 3000);
        }

        function changeBackgroundColor(background) {
            let textColor = '#ffffff';
            
            if (background.includes('ffecd2') || background.includes('fcb69f')) {
                textColor = '#333333';
            }
            
            document.documentElement.style.setProperty('--background-color', background);
            document.documentElement.style.setProperty('--text-color', textColor);
            
            document.body.style.background = background;
            document.body.style.color = textColor;
            
            localStorage.setItem('themeBackgroundColor', background);
            localStorage.setItem('themeTextColor', textColor);
            
            showToast('Arrière-plan modifié', 'success', 3000);
        }

        function toggleTheme() {
            document.body.classList.toggle('dark-theme');
            const isDarkTheme = document.body.classList.contains('dark-theme');
            localStorage.setItem('darkTheme', isDarkTheme);
            
            const themeMsg = isDarkTheme ? 'Mode sombre activé' : 'Mode clair activé';
            showToast(themeMsg, 'success', 3000);
        }

        // ===== FONCTIONS GLOBALES =====
        window.confirmCourseAccess = function(courseId, courseName) {
            showConfirmationModal(
                'Accéder au cours',
                `Voulez-vous accéder au cours "${courseName}" ?`,
                () => {
                    showToast(`Accès au cours ${courseName}...`, 'info', 2000);
                    setTimeout(() => {
                        const selectedYear = <?= json_encode($selected_academic_year) ?>;
                        window.location.href = `manage_discussions.php?course_id=${courseId}&year=${encodeURIComponent(selectedYear)}`;
                    }, 500);
                }
            );
        };

        window.confirmNavigation = function(url, action) {
            showConfirmationModal(
                'Navigation',
                `Voulez-vous ${action} ?`,
                () => {
                    showToast(`Redirection vers ${action}...`, 'info', 2000);
                    setTimeout(() => {
                        window.location.href = url;
                    }, 500);
                }
            );
        };

        window.restoreDefaultColors = function() {
            showConfirmationModal(
                'Restaurer les couleurs',
                'Voulez-vous restaurer les couleurs compatibles avec la page des notes ?',
                () => {
                    document.documentElement.style.setProperty('--primary-color', DEFAULT_COLORS.primaryColor);
                    document.documentElement.style.setProperty('--background-color', DEFAULT_COLORS.backgroundColor);
                    document.documentElement.style.setProperty('--text-color', DEFAULT_COLORS.textColor);
                    document.documentElement.style.setProperty('--accent-color', DEFAULT_COLORS.accentColor);
                    
                    document.body.style.background = DEFAULT_COLORS.backgroundColor;
                    document.body.style.color = DEFAULT_COLORS.textColor;
                    
                    const header = document.querySelector('header');
                    const footer = document.querySelector('footer');
                    if (header) header.style.backgroundColor = DEFAULT_COLORS.primaryColor;
                    if (footer) footer.style.backgroundColor = DEFAULT_COLORS.primaryColor;
                    
                    document.body.classList.remove('dark-theme');
                    
                    const customColorInput = document.getElementById('custom-color');
                    if (customColorInput) {
                        customColorInput.value = DEFAULT_COLORS.primaryColor;
                    }
                    
                    // Nettoyer le localStorage
                    localStorage.removeItem('themePrimaryColor');
                    localStorage.removeItem('themeBackgroundColor');
                    localStorage.removeItem('themeTextColor');
                    localStorage.removeItem('themeAccentColor');
                    localStorage.removeItem('darkTheme');
                    
                    showToast('Couleurs page des notes restaurées', 'success', 4000);
                }
            );
        };

        // ===== GESTION DU SEMESTRE =====
        if (semesterSelect) {
            semesterSelect.addEventListener('change', function() {
                const selectedSemester = this.value;
                const currentSemester = <?= $semester ?>;
                
                if (selectedSemester != currentSemester) {
                    showConfirmationModal(
                        'Changer de semestre',
                        `Voulez-vous passer au semestre ${selectedSemester} ?`,
                        () => {
                            showToast(`Chargement du semestre ${selectedSemester}...`, 'info');
                            setTimeout(() => {
                                window.location.href = `?semester=${selectedSemester}`;
                            }, 1000);
                        }
                    );
                } else {
                    showToast('Semestre déjà sélectionné', 'info', 3000);
                }
            });
        }

        // ===== GESTION DE L'ANNÉE ACADÉMIQUE =====
        if (yearSelect) {
            yearSelect.addEventListener('change', function() {
                const selectedYear = this.value;
                const currentYear = <?= json_encode($selected_academic_year) ?>;

                if (selectedYear !== currentYear) {
                    showConfirmationModal(
                        'Changer d\'année académique',
                        `Voulez-vous consulter l'année ${selectedYear} ? Vous verrez les cours de la classe dans laquelle vous étiez inscrit(e) cette année-là.`,
                        () => {
                            showToast(`Chargement de l'année ${selectedYear}...`, 'info');
                            setTimeout(() => {
                                window.location.href = `?year=${encodeURIComponent(selectedYear)}`;
                            }, 1000);
                        }
                    );
                } else {
                    showToast('Année déjà sélectionnée', 'info', 3000);
                }
            });
        }

        // ===== GESTION DU DRAWER =====
        if (drawerToggleRight) {
            drawerToggleRight.addEventListener('click', () => {
                drawer.classList.toggle('open');
                if (drawer.classList.contains('open')) {
                    showToast('Menu ouvert', 'info', 2000);
                }
            });
        }

        if (drawerClose) {
            drawerClose.addEventListener('click', () => {
                drawer.classList.remove('open');
                timetableSection.style.display = 'none';
                showToast('Menu fermé', 'info', 2000);
            });
        }

        // ===== GESTION DE L'EMPLOI DU TEMPS =====
        if (showScheduleBtn) {
            showScheduleBtn.addEventListener('click', function() {
                if (timetableSection.style.display === 'none') {
                    timetableSection.style.display = 'block';
                    showToast('Emploi du temps affiché', 'success', 3000);
                } else {
                    timetableSection.style.display = 'none';
                    showToast('Emploi du temps masqué', 'info', 3000);
                }
            });
        }

        // ===== GESTION DES THÈMES =====
        const themeToggleBtn = document.getElementById('toggle-theme');
        if (themeToggleBtn) {
            themeToggleBtn.addEventListener('click', toggleTheme);
        }

        // ===== GESTION DES COULEURS =====
        function initializeColorPicker() {
            document.querySelectorAll('.color-option').forEach(option => {
                option.addEventListener('click', (e) => {
                    const color = e.target.getAttribute('data-color');
                    changeThemeColor(color);
                });
            });

            const customColorInput = document.getElementById('custom-color');
            if (customColorInput) {
                customColorInput.addEventListener('change', (e) => {
                    changeThemeColor(e.target.value);
                });
            }

            document.querySelectorAll('.bg-option').forEach(option => {
                option.addEventListener('click', (e) => {
                    const background = e.target.getAttribute('data-bg');
                    changeBackgroundColor(background);
                });
            });
        }

        // ===== AUTRES INITIALISATIONS =====
        function initializeNavButtons() {
            document.querySelectorAll('.nav-button').forEach(button => {
                button.addEventListener('mouseover', function() {
                    this.style.transform = 'translateY(-3px)';
                    this.style.backgroundColor = 'var(--accent-color)';
                });

                button.addEventListener('mouseout', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
                });
            });
        }

        function initializeAnimations() {
            document.querySelectorAll('.course-card').forEach((card, index) => {
                card.style.animation = `fadeIn 0.5s ease forwards ${index * 0.1}s`;
                card.style.opacity = '0';
                
                card.addEventListener('click', function() {
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = 'translateY(-3px) scale(1)';
                    }, 100);
                });
            });
        }

        // ===== FERMETURE DRAWER =====
        document.addEventListener('click', function(event) {
            if (drawer && 
                drawer.classList.contains('open') &&
                !drawer.contains(event.target) && 
                !event.target.matches('.drawer-toggle') && 
                !event.target.matches('.drawer-toggle *')) {
                drawer.classList.remove('open');
                timetableSection.style.display = 'none';
            }
        });

        // ===== INITIALISATION =====
        loadThemePreferences();
        initializeNavButtons();
        initializeColorPicker();
        initializeAnimations();

        // Messages
        <?php if (isset($_SESSION['message'])): ?>
            showToast(
                '<?= addslashes($_SESSION['message']['text']) ?>', 
                '<?= $_SESSION['message']['type'] ?>', 
                5000
            );
            <?php unset($_SESSION['message']); ?>
        <?php endif; ?>

        setTimeout(() => {
            showToast(
                `Dashboard - Semestre <?= $semester ?>`, 
                'success', 
                4000
            );
        }, 500);
    });

    // Fonctions utilitaires
    function changeSemester(semester) {
        window.location.href = "?semester=" + semester;
    }

    function fetchCoursesBySemester(semester) {
        window.location.href = `student_dashboard.php?semester=${semester}`;
    }
    </script>
    <script src="../assets/js/main.js"></script>
    <script>
    // ========================================
// SYSTÈME COMPLET DE GESTION DES POP-UPS
// ========================================

// Variable globale pour suivre si un pop-up est en cours d'affichage
let isPopupDisplaying = false;
let popupQueue = [];

/**
 * Fonction principale pour vérifier et afficher les pop-ups
 */
function checkForPopup() {
    // Ne pas vérifier s'il y a déjà un pop-up affiché
    if (isPopupDisplaying) {
        return;
    }
    
    fetch('../includes/check_popup.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Erreur réseau: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Pop-up data reçues:', data);
            
            if (data.show && data.popup) {
                isPopupDisplaying = true;
                showPopup(data.popup, data.popup.has_more);
            } else {
                console.log('Aucun pop-up à afficher');
            }
        })
        .catch(error => {
            console.error('Erreur lors de la récupération des pop-ups:', error);
        });
}

/**
 * Fonction pour afficher un pop-up (document ou image publicitaire)
 */
function showPopup(popup, hasMore = false) {
    console.log('Affichage du pop-up:', popup);
    
    // Créer ou récupérer l'overlay
    let overlay = document.getElementById('popup-overlay');
    if (!overlay) {
        overlay = createPopupOverlay();
    }
    
    const title = document.getElementById('popup-title');
    const message = document.getElementById('popup-message');
    const image = document.getElementById('popup-image');
    const closeBtn = document.getElementById('close-popup');
    const countdown = document.getElementById('countdown');
    const popupBody = document.getElementById('popup-body');
    
    // Nettoyer les anciens contenus
    const oldPreview = document.getElementById('doc-preview');
    if (oldPreview) oldPreview.remove();
    image.style.display = 'none';
    
    // Remplir le titre
    title.textContent = popup.title;
    
    // Déterminer le type de contenu
    const isDocument = popup.is_document || (popup.image_url && popup.image_url.includes('uploads/documents/'));
    const isRegularImage = popup.image_url && !isDocument;
    
    if (isDocument) {
        // ========== AFFICHAGE DOCUMENT ==========
        displayDocument(popup, message, countdown, hasMore);
    } else if (isRegularImage) {
        // ========== AFFICHAGE IMAGE PUBLICITAIRE ==========
        displayAdvertisement(popup, image, message, countdown, overlay, hasMore);
    } else {
        // ========== AFFICHAGE TEXTE UNIQUEMENT ==========
        displayTextOnly(popup, message, countdown, hasMore);
    }
    
    // Afficher le pop-up avec animation
    overlay.style.display = 'flex';
    setTimeout(() => {
        overlay.style.opacity = '1';
    }, 10);
    
    // Gestionnaires de fermeture
    setupCloseHandlers(overlay, closeBtn, hasMore);
}

/**
 * Créer l'overlay du pop-up
 */
function createPopupOverlay() {
    const overlay = document.createElement('div');
    overlay.id = 'popup-overlay';
    overlay.style.cssText = `
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        z-index: 99999;
        justify-content: center;
        align-items: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    `;
    
    overlay.innerHTML = `
        <div id="popup-content" style="
            background: white;
            padding: 30px;
            border-radius: 15px;
            max-width: 600px;
            width: 90%;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        ">
            <button id="close-popup" style="
                position: absolute;
                top: 15px;
                right: 15px;
                background: #ff4757;
                color: white;
                border: none;
                width: 35px;
                height: 35px;
                border-radius: 50%;
                cursor: pointer;
                font-size: 22px;
                line-height: 1;
                transition: all 0.3s;
                z-index: 10;
                display: flex;
                align-items: center;
                justify-content: center;
            ">×</button>
            
            <div id="popup-body" style="text-align: center;">
                <img id="popup-image" src="" style="
                    max-width: 100%;
                    max-height: 300px;
                    margin-bottom: 20px;
                    display: none;
                    border-radius: 8px;
                    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
                ">
                <h2 id="popup-title" style="
                    color: #051e34;
                    margin-bottom: 15px;
                    font-size: 24px;
                "></h2>
                <p id="popup-message" style="
                    color: #333;
                    line-height: 1.6;
                    white-space: pre-line;
                    margin-bottom: 20px;
                    font-size: 16px;
                "></p>
                <div id="countdown" style="
                    margin-top: 20px;
                    font-size: 14px;
                    color: #666;
                    font-weight: 500;
                "></div>
            </div>
        </div>
    `;
    
    document.body.appendChild(overlay);
    return overlay;
}

/**
 * Afficher un document
 */
function displayDocument(popup, message, countdown, hasMore) {
    message.textContent = popup.message || 'Un nouveau document est disponible.';
    
    const fileName = popup.image_url.split('/').pop();
    const fileExtension = fileName.split('.').pop().toLowerCase();
    
    const fileIcons = {
        'pdf': 'fa-file-pdf',
        'doc': 'fa-file-word',
        'docx': 'fa-file-word',
        'xls': 'fa-file-excel',
        'xlsx': 'fa-file-excel',
        'ppt': 'fa-file-powerpoint',
        'pptx': 'fa-file-powerpoint',
        'txt': 'fa-file-alt',
        'zip': 'fa-file-archive',
        'rar': 'fa-file-archive'
    };
    
    const iconClass = fileIcons[fileExtension] || 'fa-file';
    
    const docPreview = document.createElement('div');
    docPreview.id = 'doc-preview';
    docPreview.style.cssText = `
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        padding: 30px;
        border-radius: 12px;
        margin: 20px 0;
        text-align: center;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    `;
    
    docPreview.innerHTML = `
        <i class="fas ${iconClass}" style="font-size: 72px; color: white; margin-bottom: 15px;"></i>
        <h3 style="color: white; margin: 10px 0; font-size: 18px;">${fileName}</h3>
        <p style="color: rgba(255, 255, 255, 0.9); font-size: 14px; margin: 10px 0;">
            Document ${fileExtension.toUpperCase()}
        </p>
        <a href="../${popup.image_url}" 
           download="${fileName}"
           style="
               display: inline-block;
               margin-top: 15px;
               padding: 12px 30px;
               background: white;
               color: #667eea;
               text-decoration: none;
               border-radius: 25px;
               font-weight: 600;
               font-size: 16px;
               transition: all 0.3s ease;
               box-shadow: 0 4px 12px rgba(255, 255, 255, 0.3);
           "
           onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(255, 255, 255, 0.4)';"
           onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(255, 255, 255, 0.3)';">
            <i class="fas fa-download" style="margin-right: 8px;"></i>
            Télécharger
        </a>
        <a href="../${popup.image_url}" 
           target="_blank"
           style="
               display: inline-block;
               margin: 15px 0 0 10px;
               padding: 12px 30px;
               background: rgba(255, 255, 255, 0.2);
               color: white;
               text-decoration: none;
               border-radius: 25px;
               font-weight: 600;
               font-size: 16px;
               transition: all 0.3s ease;
               border: 2px solid white;
           "
           onmouseover="this.style.background='rgba(255, 255, 255, 0.3)';"
           onmouseout="this.style.background='rgba(255, 255, 255, 0.2)';">
            <i class="fas fa-external-link-alt" style="margin-right: 8px;"></i>
            Ouvrir
        </a>
    `;
    
    message.parentNode.insertBefore(docPreview, message.nextSibling);
    
    const closeText = hasMore ? 'Fermez pour voir le prochain' : 'Cliquez pour fermer';
    countdown.innerHTML = `<strong style="color: #667eea;">${closeText}</strong>`;
}

/**
 * Afficher une publicité avec image
 */
function displayAdvertisement(popup, image, message, countdown, overlay, hasMore) {
    image.src = '../' + popup.image_url;
    image.style.display = 'block';
    message.textContent = popup.message;
    
    // Fermeture automatique
    if (popup.auto_close_duration > 0) {
        let seconds = popup.auto_close_duration;
        const nextText = hasMore ? ' (puis prochain pop-up)' : '';
        countdown.innerHTML = `Fermeture automatique dans <strong>${seconds}</strong> secondes${nextText}`;
        
        const timer = setInterval(() => {
            seconds--;
            countdown.innerHTML = `Fermeture automatique dans <strong>${seconds}</strong> secondes${nextText}`;
            
            if (seconds <= 0) {
                clearInterval(timer);
                closePopupAndCheckNext(hasMore);
            }
        }, 1000);
        
        // Annuler le timer si l'utilisateur interagit
        overlay.addEventListener('mouseenter', () => {
            clearInterval(timer);
            const closeText = hasMore ? 'Fermez pour voir le prochain' : 'Cliquez pour fermer';
            countdown.innerHTML = closeText;
        }, { once: true });
    } else {
        const closeText = hasMore ? 'Fermez pour voir le prochain' : 'Cliquez pour fermer';
        countdown.innerHTML = closeText;
    }
}

/**
 * Afficher un pop-up texte uniquement
 */
function displayTextOnly(popup, message, countdown, hasMore) {
    message.textContent = popup.message;
    const closeText = hasMore ? 'Fermez pour voir le prochain' : 'Cliquez pour fermer';
    countdown.innerHTML = closeText;
}

/**
 * Configurer les gestionnaires de fermeture
 */
function setupCloseHandlers(overlay, closeBtn, hasMore) {
    // Fonction pour fermer le pop-up
    function closePopup() {
        overlay.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
            const docPreview = document.getElementById('doc-preview');
            if (docPreview) docPreview.remove();
            
            // Vérifier s'il y a d'autres pop-ups après fermeture
            isPopupDisplaying = false;
            if (hasMore) {
                setTimeout(() => {
                    checkForPopup();
                }, 500);
            }
        }, 300);
    }
    
    // Bouton de fermeture
    closeBtn.onclick = (e) => {
        e.stopPropagation();
        closePopupAndCheckNext(hasMore);
    };
    
    // Clic en dehors
    overlay.onclick = function(e) {
        if (e.target === overlay) {
            closePopupAndCheckNext(hasMore);
        }
    };
    
    // Touche Échap
    const escapeHandler = function(e) {
        if (e.key === 'Escape') {
            closePopupAndCheckNext(hasMore);
            document.removeEventListener('keydown', escapeHandler);
        }
    };
    document.addEventListener('keydown', escapeHandler);
}

/**
 * Fermer le pop-up et vérifier s'il y en a d'autres
 */
function closePopupAndCheckNext(hasMore) {
    const overlay = document.getElementById('popup-overlay');
    if (!overlay) return;
    
    overlay.style.opacity = '0';
    setTimeout(() => {
        overlay.style.display = 'none';
        const docPreview = document.getElementById('doc-preview');
        if (docPreview) docPreview.remove();
        
        isPopupDisplaying = false;
        
        // Si d'autres pop-ups sont disponibles, attendre 500ms puis vérifier
        if (hasMore) {
            setTimeout(() => {
                checkForPopup();
            }, 500);
        }
    }, 300);
}

/**
 * Initialisation au chargement de la page
 */
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(checkForPopup, 1000);
    });
} else {
    setTimeout(checkForPopup, 1000);
}
</script>

<style>
/* Animation d'apparition */
#popup-overlay {
    animation: fadeIn 0.3s;
}

#popup-content {
    animation: slideUp 0.5s;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from { transform: translate(-50%, -40%); opacity: 0; }
    to { transform: translate(-50%, -50%); opacity: 1; }
}
</style>

<script>
// Fonction unifiée pour afficher tous types de pop-ups
function showPopup(popup) {
    // Créer l'overlay si il n'existe pas
    let overlay = document.getElementById('popup-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'popup-overlay';
        overlay.style.cssText = `
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        `;
        
        overlay.innerHTML = `
            <div id="popup-content" style="
                background: white;
                padding: 30px;
                border-radius: 15px;
                max-width: 600px;
                width: 90%;
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
                position: relative;
                max-height: 90vh;
                overflow-y: auto;
            ">
                <button id="close-popup" style="
                    position: absolute;
                    top: 15px;
                    right: 15px;
                    background: #ff4757;
                    color: white;
                    border: none;
                    width: 30px;
                    height: 30px;
                    border-radius: 50%;
                    cursor: pointer;
                    font-size: 20px;
                    line-height: 1;
                    transition: all 0.3s;
                    z-index: 10;
                ">×</button>
                
                <div id="popup-body" style="text-align: center;">
                    <img id="popup-image" src="" style="max-width: 100%; max-height: 200px; margin-bottom: 20px; display: none; border-radius: 8px;">
                    <h2 id="popup-title" style="color: #051e34; margin-bottom: 15px;"></h2>
                    <p id="popup-message" style="color: #333; line-height: 1.6; white-space: pre-line; margin-bottom: 20px;"></p>
                    <div id="countdown" style="margin-top: 20px; font-size: 12px; color: #666;"></div>
                </div>
            </div>
        `;
        
        document.body.appendChild(overlay);
    }
    
    const title = document.getElementById('popup-title');
    const message = document.getElementById('popup-message');
    const image = document.getElementById('popup-image');
    const closeBtn = document.getElementById('close-popup');
    const countdown = document.getElementById('countdown');
    const popupBody = document.getElementById('popup-body');
    
    // Nettoyer les anciens contenus
    const oldPreview = document.getElementById('doc-preview');
    if (oldPreview) oldPreview.remove();
    image.style.display = 'none';
    
    // Remplir le titre
    title.textContent = popup.title;
    
    // Vérifier le type de contenu
    const isDocument = popup.image_url && popup.image_url.includes('uploads/documents/');
    const isRegularImage = popup.image_url && !isDocument && 
                          (popup.image_url.includes('uploads/popups/') || 
                           popup.image_url.match(/\.(jpg|jpeg|png|gif|webp)$/i));
    
    if (isDocument) {
        // ========== CAS 1: DOCUMENT ==========
        message.textContent = popup.message || 'Un nouveau document est disponible.';
        
        const fileName = popup.image_url.split('/').pop();
        const fileExtension = fileName.split('.').pop().toLowerCase();
        
        const fileIcons = {
            'pdf': 'fa-file-pdf',
            'doc': 'fa-file-word',
            'docx': 'fa-file-word',
            'xls': 'fa-file-excel',
            'xlsx': 'fa-file-excel',
            'ppt': 'fa-file-powerpoint',
            'pptx': 'fa-file-powerpoint',
            'txt': 'fa-file-alt',
            'zip': 'fa-file-archive',
            'rar': 'fa-file-archive'
        };
        
        const iconClass = fileIcons[fileExtension] || 'fa-file';
        
        const docPreview = document.createElement('div');
        docPreview.id = 'doc-preview';
        docPreview.style.cssText = `
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            border-radius: 12px;
            margin: 20px 0;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        `;
        
        docPreview.innerHTML = `
            <i class="fas ${iconClass}" style="font-size: 64px; color: white; margin-bottom: 15px;"></i>
            <h3 style="color: white; margin: 10px 0; font-size: 18px;">${fileName}</h3>
            <p style="color: rgba(255, 255, 255, 0.9); font-size: 14px; margin: 10px 0;">
                Fichier ${fileExtension.toUpperCase()}
            </p>
            <a href="../${popup.image_url}" 
               download="${fileName}"
               style="
                   display: inline-block;
                   margin-top: 15px;
                   padding: 12px 30px;
                   background: white;
                   color: #667eea;
                   text-decoration: none;
                   border-radius: 25px;
                   font-weight: 600;
                   font-size: 16px;
                   transition: all 0.3s ease;
                   box-shadow: 0 4px 12px rgba(255, 255, 255, 0.3);
               "
               onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(255, 255, 255, 0.4)';"
               onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(255, 255, 255, 0.3)';">
                <i class="fas fa-download" style="margin-right: 8px;"></i>
                Télécharger le document
            </a>
            <a href="../${popup.image_url}" 
               target="_blank"
               style="
                   display: inline-block;
                   margin: 15px 0 0 10px;
                   padding: 12px 30px;
                   background: rgba(255, 255, 255, 0.2);
                   color: white;
                   text-decoration: none;
                   border-radius: 25px;
                   font-weight: 600;
                   font-size: 16px;
                   transition: all 0.3s ease;
                   border: 2px solid white;
               "
               onmouseover="this.style.background='rgba(255, 255, 255, 0.3)';"
               onmouseout="this.style.background='rgba(255, 255, 255, 0.2)';">
                <i class="fas fa-external-link-alt" style="margin-right: 8px;"></i>
                Ouvrir dans un nouvel onglet
            </a>
        `;
        
        message.parentNode.insertBefore(docPreview, message.nextSibling);
        countdown.innerHTML = '<strong style="color: #667eea;">Téléchargez le document ou fermez cette fenêtre</strong>';
        
    } else if (isRegularImage) {
        // ========== CAS 2: IMAGE PUBLICITAIRE ==========
        image.src = '../' + popup.image_url;
        image.style.display = 'block';
        message.textContent = popup.message;
        
        // Fermeture automatique pour les pubs
        if (popup.auto_close_duration > 0) {
            let seconds = popup.auto_close_duration;
            countdown.innerHTML = `Ce message se ferme automatiquement dans <strong>${seconds}</strong> secondes`;
            
            const timer = setInterval(() => {
                seconds--;
                countdown.innerHTML = `Ce message se ferme automatiquement dans <strong>${seconds}</strong> secondes`;
                
                if (seconds <= 0) {
                    clearInterval(timer);
                    closePopup();
                }
            }, 1000);
            
            overlay.addEventListener('mouseenter', () => clearInterval(timer), { once: true });
        } else {
            countdown.innerHTML = 'Cliquez à l\'extérieur ou appuyez sur Échap pour fermer';
        }
        
    } else {
        // ========== CAS 3: TEXTE UNIQUEMENT ==========
        message.textContent = popup.message;
        countdown.innerHTML = 'Cliquez à l\'extérieur ou appuyez sur Échap pour fermer';
    }
    
    // Afficher le pop-up avec animation
    overlay.style.display = 'flex';
    setTimeout(() => {
        overlay.style.opacity = '1';
    }, 10);
    
    // Fonction pour fermer
    function closePopup() {
        overlay.style.opacity = '0';
        setTimeout(() => {
            overlay.style.display = 'none';
            const docPreview = document.getElementById('doc-preview');
            if (docPreview) docPreview.remove();
        }, 300);
    }
    
    // Gestionnaires d'événements de fermeture
    closeBtn.onclick = (e) => {
        e.stopPropagation();
        closePopup();
    };
    
    overlay.onclick = function(e) {
        if (e.target === overlay) {
            closePopup();
        }
    };
    
    // Touche Échap pour fermer
    const escapeHandler = function(e) {
        if (e.key === 'Escape') {
            closePopup();
            document.removeEventListener('keydown', escapeHandler);
        }
    };
    document.addEventListener('keydown', escapeHandler);
}

// Fonction pour vérifier les pop-ups
function checkForPopup() {
    fetch('../includes/check_popup.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Pop-up data received:', data); // Debug
            if (data.show && data.popup) {
                showPopup(data.popup);
            }
        })
        .catch(error => {
            console.error('Erreur lors de la vérification des pop-ups:', error);
        });
}

// Initialisation selon l'état du DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(checkForPopup, 1000);
    });
} else {
    setTimeout(checkForPopup, 1000);
}
</script>

<style>
/* ==========================================
   STYLES POUR LE SYSTÈME DE POP-UPS
   ========================================== */

/* Overlay du pop-up */
#popup-overlay {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    backdrop-filter: blur(5px);
}

/* Bouton de fermeture */
#close-popup {
    font-family: Arial, sans-serif;
}

#close-popup:hover {
    background: #ee5a6f !important;
    transform: scale(1.1) rotate(90deg);
    box-shadow: 0 4px 15px rgba(255, 71, 87, 0.5);
}

#close-popup:active {
    transform: scale(0.95);
}

/* Contenu du pop-up */
#popup-content {
    animation: popupSlideIn 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

/* Scrollbar personnalisée */
#popup-content::-webkit-scrollbar {
    width: 8px;
}

#popup-content::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

#popup-content::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #667eea 0%, #764ba2 100%);
    border-radius: 10px;
}

#popup-content::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #764ba2 0%, #667eea 100%);
}

/* Image du pop-up */
#popup-image {
    transition: transform 0.3s ease;
}

#popup-image:hover {
    transform: scale(1.02);
}

/* Titre du pop-up */
#popup-title {
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

/* Message du pop-up */
#popup-message {
    text-align: justify;
}

/* Zone de compte à rebours */
#countdown {
    background: rgba(102, 126, 234, 0.1);
    padding: 10px;
    border-radius: 8px;
    border: 1px solid rgba(102, 126, 234, 0.3);
}

#countdown strong {
    color: #667eea;
}

/* Prévisualisation de document */
#doc-preview {
    transition: transform 0.3s ease;
}

#doc-preview:hover {
    transform: translateY(-5px);
}

#doc-preview i {
    animation: bounceIcon 2s ease-in-out infinite;
}

#doc-preview a {
    display: inline-block;
}

/* Animations */
@keyframes popupSlideIn {
    from {
        transform: scale(0.7) translateY(-30px);
        opacity: 0;
    }
    to {
        transform: scale(1) translateY(0);
        opacity: 1;
    }
}

@keyframes bounceIcon {
    0%, 100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-10px);
    }
}

/* Animation de pulsation pour le bouton de fermeture */
@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(255, 71, 87, 0.7);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(255, 71, 87, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(255, 71, 87, 0);
    }
}

#close-popup {
    animation: pulse 2s infinite;
}

/* Responsive Design */
@media (max-width: 768px) {
    #popup-content {
        width: 95%;
        padding: 20px;
        max-height: 85vh;
    }
    
    #popup-title {
        font-size: 20px;
    }
    
    #popup-message {
        font-size: 14px;
    }
    
    #doc-preview {
        padding: 20px;
    }
    
    #doc-preview h3 {
        font-size: 16px;
    }
    
    #doc-preview a {
        font-size: 14px;
        padding: 10px 20px;
    }
    
    #close-popup {
        width: 30px;
        height: 30px;
        font-size: 20px;
        top: 10px;
        right: 10px;
    }
}

@media (max-width: 480px) {
    #popup-content {
        width: 98%;
        padding: 15px;
    }
    
    #popup-title {
        font-size: 18px;
        margin-bottom: 10px;
    }
    
    #popup-message {
        font-size: 13px;
        margin-bottom: 15px;
    }
    
    #doc-preview {
        padding: 15px;
    }
    
    #doc-preview i {
        font-size: 48px;
    }
    
    #doc-preview a {
        display: block;
        margin: 10px 0 !important;
        width: 100%;
        text-align: center;
    }
    
    #countdown {
        font-size: 12px;
        padding: 8px;
    }
}

/* Effet de flou sur le contenu derrière le pop-up */
body.popup-active > *:not(#popup-overlay) {
    filter: blur(3px);
    transition: filter 0.3s ease;
}

/* Désactiver le scroll du body quand le pop-up est ouvert */
body.popup-active {
    overflow: hidden;
}

/* Style pour les icônes Font Awesome dans les documents */
.fa-file-pdf { color: #e74c3c; }
.fa-file-word { color: #2980b9; }
.fa-file-excel { color: #27ae60; }
.fa-file-powerpoint { color: #e67e22; }
.fa-file-alt { color: #95a5a6; }
.fa-file-archive { color: #f39c12; }
.fa-file { color: #7f8c8d; }

/* Animation de chargement pour les images */
#popup-image {
    background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading 1.5s ease-in-out infinite;
}

#popup-image.loaded {
    background: none;
    animation: none;
}

@keyframes loading {
    0% {
        background-position: 200% 0;
    }
    100% {
        background-position: -200% 0;
    }
}

/* Badge de notification pour les nouveaux pop-ups */
.popup-badge {
    position: absolute;
    top: -5px;
    right: -5px;
    background: #ff4757;
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
    animation: badgePulse 1s ease-in-out infinite;
}

@keyframes badgePulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.2);
    }
}
</style>
</body>
</html>