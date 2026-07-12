<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/grade_lock.php';
require_once '../includes/super_admin.php';
require_once '../includes/semester_helper.php';

/** @var mysqli $conn Fourni par includes/db_connect.php */

// Vérification des droits d'accès
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    header('Location: ../pages/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// ── Contexte année académique ────────────────────────────────────────────────
$current_year    = ANNEE_ACADEMIQUE_COURANTE;
$available_years = get_school_years($conn);

// Fonction pour vérifier les permissions d'examen
function canAddExamGrade($conn, $user_id, $user_role) {
    // Un super administrateur a toujours accès
    if (is_super_admin($conn, $user_id)) {
        return true;
    }
    
    // Vérifier les permissions spéciales
    $query = "SELECT * FROM exam_permissions WHERE user_id = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

// Fonction pour récupérer les cours d'une classe
function getCoursesByClass($conn, $class_id, $user_id, $user_role) {
    $courses = [];
    
    try {
        // Construction de la requête selon le rôle
        $query = "SELECT DISTINCT c.id, c.name, c.coefficient, c.semester, c.total_hours
                  FROM courses c
                  WHERE JSON_CONTAINS(c.class_id, ?)";
        
        $params = [json_encode(strval($class_id))];
        $types = "s";
        
        // Restriction pour les enseignants (seulement leurs cours)
        if ($user_role === 'teacher') {
            $query .= " AND c.teacher_id = ?";
            $params[] = $user_id;
            $types .= "s";
        }
        
        $query .= " ORDER BY c.name";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $courses[] = [
                'id' => intval($row['id']),
                'name' => $row['name'],
                'coefficient' => floatval($row['coefficient']),
                'semester' => intval($row['semester']),
                'total_hours' => $row['total_hours'] ? intval($row['total_hours']) : null
            ];
        }
        
        // Si aucun cours trouvé avec la méthode JSON, essayer une approche alternative
        if (empty($courses)) {
            $like_query = "SELECT DISTINCT c.id, c.name, c.coefficient, c.semester, c.total_hours
                           FROM courses c
                           WHERE c.class_id LIKE ?";
            
            $like_params = ["%\"$class_id\"%"];
            
            if ($user_role === 'teacher') {
                $like_query .= " AND c.teacher_id = ?";
                $like_params[] = $user_id;
            }
            
            $like_query .= " ORDER BY c.name";
            
            $stmt = $conn->prepare($like_query);
            $stmt->bind_param(str_repeat('s', count($like_params)), ...$like_params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $courses[] = [
                    'id' => intval($row['id']),
                    'name' => $row['name'],
                    'coefficient' => floatval($row['coefficient']),
                    'semester' => intval($row['semester']),
                    'total_hours' => $row['total_hours'] ? intval($row['total_hours']) : null
                ];
            }
        }
        
    } catch (Exception $e) {
        error_log("Erreur getCoursesByClass: " . $e->getMessage());
    }
    
    return $courses;
}

// Fonction pour récupérer les étudiants d'une classe
function getStudentsByClass($conn, $class_id) {
    $students = [];
    
    try {
        $query = "SELECT id, name, email FROM users WHERE class_id = ? AND role = 'student' ORDER BY name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $class_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $students[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'email' => $row['email']
            ];
        }
    } catch (Exception $e) {
        error_log("Erreur getStudentsByClass: " . $e->getMessage());
    }
    
    return $students;
}

// Fonction pour récupérer les notes récentes (optionnellement filtrées par année)
function getRecentGrades($conn, $class_id, $limit = 10, $school_year = null) {
    $grades = [];

    try {
        $year_join  = '';
        $year_where = '';
        if ($school_year !== null && $school_year !== '') {
            $year_join  = " JOIN evaluation_periods ep ON g.evaluation_period_id = ep.id";
            $year_where = " AND ep.school_year = ?";
        }
        $query = "
            SELECT
                g.id,
                u.name AS student_name,
                c.name AS course_name,
                et.name AS evaluation_type,
                g.grade,
                g.comment,
                g.created_at
            FROM grades g
            JOIN users u ON g.student_id = u.id
            JOIN courses c ON g.course_id = c.id
            JOIN evaluation_types et ON g.evaluation_type_id = et.id
            $year_join
            WHERE u.class_id = ?
            $year_where
            ORDER BY g.created_at DESC
            LIMIT ?";

        $stmt = $conn->prepare($query);
        if ($year_where !== '') {
            $stmt->bind_param("isi", $class_id, $school_year, $limit);
        } else {
            $stmt->bind_param("ii", $class_id, $limit);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $grades[] = $row;
        }
    } catch (Exception $e) {
        error_log("Erreur getRecentGrades: " . $e->getMessage());
    }
    
    return $grades;
}

// Traitement AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    switch ($_GET['action']) {
        case 'get_students':
            if (isset($_GET['class_id'])) {
                $class_id = intval($_GET['class_id']);
                $students = getStudentsByClass($conn, $class_id);
                echo json_encode($students);
            } else {
                echo json_encode(['error' => 'Class ID manquant']);
            }
            exit();
            
        case 'get_courses':
            if (isset($_GET['class_id'])) {
                $class_id = intval($_GET['class_id']);
                $courses = getCoursesByClass($conn, $class_id, $user_id, $user_role);
                echo json_encode($courses);
            } else {
                echo json_encode(['error' => 'Class ID manquant']);
            }
            exit();
            
        case 'get_recent_grades':
            if (isset($_GET['class_id'])) {
                $class_id = intval($_GET['class_id']);
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
                $year = (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year']))
                    ? $_GET['year'] : null;
                $grades = getRecentGrades($conn, $class_id, $limit, $year);
                echo json_encode($grades);
            } else {
                echo json_encode(['error' => 'Class ID manquant']);
            }
            exit();
            
        case 'delete_grade':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_id'])) {
                $grade_id = intval($_POST['grade_id']);
                
                // Vérifier si l'utilisateur peut supprimer cette note
                if ($user_role === 'admin') {
                    $delete_query = "DELETE FROM grades WHERE id = ?";
                    $stmt = $conn->prepare($delete_query);
                    $stmt->bind_param("i", $grade_id);
                } else {
                    // Verrou : un enseignant ne supprime plus une note trop ancienne
                    if (grade_is_locked($conn, $grade_id, $user_role)) {
                        echo json_encode(['success' => false, 'message' => grade_lock_message($conn)]);
                        exit();
                    }
                    // Pour les enseignants, vérifier qu'ils sont propriétaires du cours
                    $delete_query = "DELETE g FROM grades g
                                    JOIN courses c ON g.course_id = c.id
                                    WHERE g.id = ? AND c.teacher_id = ?";
                    $stmt = $conn->prepare($delete_query);
                    $stmt->bind_param("is", $grade_id, $user_id);
                }
                
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    echo json_encode(['success' => true, 'message' => 'Note supprimée avec succès']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression ou note introuvable']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Données manquantes']);
            }
            exit();
    }
}

$can_add_exam_grades = canAddExamGrade($conn, $user_id, $user_role);

// Récupération des données nécessaires
$classes_query = "SELECT id, name FROM classes ORDER BY name";
$classes_result = $conn->query($classes_query);

$evaluation_types_query = "SELECT id, name FROM evaluation_types ORDER BY name";
$evaluation_types_result = $conn->query($evaluation_types_query);

// Périodes groupées par année académique (année la plus récente d'abord)
$periods_query = "SELECT id, name, school_year FROM evaluation_periods
                  ORDER BY school_year DESC, start_date ASC";
$periods_result = $conn->query($periods_query);
$periods_by_year = [];
while ($p = $periods_result->fetch_assoc()) {
    $periods_by_year[$p['school_year'] ?: 'Sans année'][] = $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_grade'])) {
    $student_id = $_POST['student_id'];
    $course_id = $_POST['course_id'];
    $evaluation_type_id = $_POST['evaluation_type_id'];
    $evaluation_period_id = $_POST['evaluation_period_id'];
    $grade = $_POST['grade'];
    $comment = $_POST['comment'] ?? '';

    // Vérification des permissions pour les examens
    if ($evaluation_type_id == 2) { // Type "Examen"
        if (!$can_add_exam_grades) {
            $error = "Vous n'avez pas l'autorisation d'ajouter des notes d'examen.";
        } else {
            // Vérification d’unicité de l’examen
            $check_exam_query = "SELECT id FROM grades 
                                 WHERE student_id = ? AND course_id = ? 
                                 AND evaluation_type_id = ? AND evaluation_period_id = ?";
            $stmt = $conn->prepare($check_exam_query);
            $stmt->bind_param("siii", $student_id, $course_id, $evaluation_type_id, $evaluation_period_id);
            $stmt->execute();
            $stmt->store_result(); // Important

            if ($stmt->num_rows > 0) {
                $error = "Une note d'examen existe déjà pour cet étudiant, ce cours et cette période.";
            }
        }
    }

    if (!isset($error)) {
        // Vérifier si l'enseignant enseigne ce cours (sauf pour admin)
        if ($user_role === 'teacher') {
            $course_check = "SELECT id FROM courses WHERE id = ? AND teacher_id = ?";
            $stmt = $conn->prepare($course_check);
            $stmt->bind_param("is", $course_id, $user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $error = "Vous ne pouvez ajouter des notes que pour vos propres cours.";
            }
        }
    }

    if (!isset($error)) {
        $insert_query = "INSERT INTO grades (student_id, course_id, evaluation_type_id, evaluation_period_id, grade, comment, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("siiiiss", $student_id, $course_id, $evaluation_type_id, $evaluation_period_id, $grade, $comment, $user_id);

        if ($stmt->execute()) {
            $success = "Note ajoutée avec succès !";
        } else {
            $error = "Erreur lors de l'ajout de la note : " . $conn->error;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
    <script>
    (function() {
        var t = document.querySelector('meta[name="csrf-token"]')?.content;
        if (!t) return;
        var o = window.fetch;
        window.fetch = function(u, p) {
            p = p || {};
            if ((p.method || 'GET').toUpperCase() === 'GET') return o(u, p);
            p.headers = p.headers || {};
            if (p.headers instanceof Headers) {
                p.headers.set('X-CSRF-Token', t);
            } else {
                p.headers['X-CSRF-Token'] = t;
            }
            return o(u, p);
        };
    })();
    </script>
    <title>Gestion des Notes - UV Platform</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #4CAF50;
            --error-color: #f44336;
            --warning-color: #ff9800;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Google Sans', Arial, sans-serif;
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            color: var(--text-light);
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            overflow-x: hidden;
        }

        /* STYLES DU HEADER */
        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid var(--border-color);
            position: relative;
            width: 100%;
            z-index: 1000;
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Bouton du drawer */
        .drawer-toggle {
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 20px;
            cursor: pointer;
            padding: 10px;
            border-radius: 5px;
            transition: all 0.3s ease;
            z-index: 1001;
            flex-shrink: 0;
        }

        .drawer-toggle:hover {
            background: rgba(3, 155, 229, 0.1);
            color: var(--accent-color);
        }

        .drawer-toggle i {
            transition: transform 0.3s ease;
        }

        .drawer-toggle.active i {
            transform: rotate(90deg);
        }

        /* Container pour le contenu du header */
        .header-main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Styles des icônes flottantes */
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

        @keyframes floatIcon {
            0% { transform: translateY(100%); opacity: 0; }
            50% { opacity: 0.3; }
            100% { transform: translateY(-100%); opacity: 0; }
        }

        .header-main-content h1 {
            font-size: 24px;
            color: var(--accent-color);
            margin: 0 0 20px 0;
            text-align: center;
        }

        /* Style de la navigation */
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
        }

        nav a:hover {
            background: rgba(3, 155, 229, 0.1);
        }

        nav a[href*="logout"] {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        nav a[href*="logout"]:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        /* DROPDOWN STYLES */
        .nav-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .dropdown-content {
            display: none;
            position: absolute;
            background: var(--secondary-bg);
            min-width: 250px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
            border-radius: 8px;
            z-index: 1000;
            top: 100%;
            left: 0;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }
        
        .dropdown-content.show {
            display: block;
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .dropdown-content a {
            color: var(--text-light) !important;
            padding: 12px 16px !important;
            text-decoration: none;
            display: flex !important;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            border-radius: 0 !important;
        }
        
        .dropdown-content a:first-child {
            border-radius: 8px 8px 0 0 !important;
        }
        
        .dropdown-content a:last-child {
            border-radius: 0 0 8px 8px !important;
        }
        
        .dropdown-content a:hover {
            background: rgba(3, 155, 229, 0.2) !important;
            color: var(--accent-color) !important;
        }
        
        .dropdown-arrow {
            margin-left: 5px;
            transition: transform 0.3s ease;
        }
        
        .nav-dropdown.active .dropdown-arrow {
            transform: rotate(180deg);
        }

        /* STYLES DU DRAWER */
        .drawer-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1050;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .drawer-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .drawer {
            position: fixed;
            top: 0;
            left: -350px;
            width: 350px;
            height: 100%;
            background: var(--secondary-bg);
            border-right: 1px solid var(--border-color);
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
            z-index: 1051;
            transition: left 0.3s ease;
            overflow-y: auto;
        }

        .drawer.active {
            left: 0;
        }

        .drawer-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--primary-bg);
        }

        .drawer-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--accent-color);
            margin: 0;
        }

        .drawer-close {
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 18px;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.3s ease;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .drawer-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .drawer-content {
            padding: 20px;
        }

        .drawer-section-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--accent-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 20px 0 15px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--border-color);
        }

        .drawer-user-info {
            padding: 20px;
            background: var(--primary-bg);
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }

        .drawer-user-name {
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 16px;
        }

        .drawer-user-role {
            font-size: 12px;
            color: var(--accent-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .drawer-menu-item {
            margin-bottom: 5px;
        }

        .drawer-menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: var(--text-light);
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            position: relative;
        }

        .drawer-menu-link:hover {
            background: rgba(3, 155, 229, 0.1);
            color: var(--accent-color);
            transform: translateX(5px);
        }

        .drawer-menu-link.active {
            background: rgba(3, 155, 229, 0.2);
            color: var(--accent-color);
            border-left: 3px solid var(--accent-color);
        }

        .drawer-menu-link i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .permission-indicator {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 10px;
            letter-spacing: 0.5px;
        }

        .permission-indicator.permission-granted {
            background: #4CAF50;
            color: white;
        }

        .permission-indicator.permission-limited {
            background: #ff9800;
            color: white;
        }

        /* CONTENU PRINCIPAL */
        .dashboard-container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            transition: margin-left 0.3s ease;
        }

        .page-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: var(--text-light);
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.3);
        }

        .page-header h1 {
            margin: 0;
            font-size: 28px;
        }

        .page-header p {
            margin: 5px 0 0 0;
            opacity: 0.9;
        }

        .permission-banner {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .permission-banner.has-exam-permission {
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white;
        }

        .permission-banner.no-exam-permission {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
        }

        .grade-form {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }

        .grade-form h2 {
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--accent-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group select,
        .form-group input,
        .form-group textarea {
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group select:focus,
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(3, 155, 229, 0.2);
        }

        .form-group select option {
            background: var(--secondary-bg);
            color: var(--text-light);
        }

        .form-group textarea {
            resize: vertical;
            font-family: inherit;
        }

        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .grade-input-container {
            position: relative;
        }

        .grade-preview {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.3s ease;
        }

        .grade-preview.grade-excellent {
            background: #4CAF50;
            color: white;
        }

        .grade-preview.grade-good {
            background: #2196F3;
            color: white;
        }

        .grade-preview.grade-average {
            background: #ff9800;
            color: white;
        }

        .grade-preview.grade-poor {
            background: #f44336;
            color: white;
        }

        .grade-preview.invalid {
            background: #9e9e9e;
            color: white;
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: var(--text-light);
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.4);
        }

        .btn-submit:disabled {
            background: #666;
            cursor: not-allowed;
            transform: none;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .alert.success {
            background: linear-gradient(135deg, var(--success-color), #45a049);
            color: white;
        }

        .alert.error {
            background: linear-gradient(135deg, var(--error-color), #d32f2f);
            color: white;
        }

        .grades-table-container {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 20px;
            margin-top: 30px;
        }

        .grades-table-container h2 {
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--accent-color);
        }

        .grades-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .grades-table th {
            background: var(--secondary-bg);
            color: var(--text-light);
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid var(--accent-color);
        }

        .grades-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .grades-table tr:nth-child(even) {
            background: rgba(255, 255, 255, 0.05);
        }

        .grades-table tr:hover {
            background: rgba(3, 155, 229, 0.2);
        }

        .exam-restriction-notice {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 14px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-edit, .btn-delete {
            padding: 8px 12px;
            margin: 0 2px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-edit {
            background: #4CAF50;
            color: white;
        }

        .btn-edit:hover {
            background: #45a049;
            transform: translateY(-1px);
        }

        .btn-delete {
            background: #f44336;
            color: white;
        }

        .btn-delete:hover {
            background: #da190b;
            transform: translateY(-1px);
        }

        .grade-display {
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 14px;
        }

        .grade-display.grade-excellent {
            background: #4CAF50;
            color: white;
        }

        .grade-display.grade-good {
            background: #2196F3;
            color: white;
        }

        .grade-display.grade-average {
            background: #ff9800;
            color: white;
        }

        .grade-display.grade-poor {
            background: #f44336;
            color: white;
        }

        .loading {
            text-align: center;
            color: rgba(255, 255, 255, 0.7);
            padding: 20px;
        }

        .empty-state {
            text-align: center;
            color: rgba(255, 255, 255, 0.7);
            padding: 40px;
        }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .drawer {
                width: 300px;
                left: -300px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-container {
                margin: 10px;
                padding: 15px;
            }

            .header-content {
                flex-direction: column;
                gap: 10px;
            }

            .drawer-toggle {
                position: absolute;
                left: 20px;
                top: 50%;
                transform: translateY(-50%);
            }

            nav ul {
                flex-direction: column;
                align-items: center;
                width: 100%;
            }

            nav ul li {
                width: 100%;
            }

            nav a {
                width: 100%;
                justify-content: center;
            }

            /* Mobile dropdown adjustments */
            .nav-dropdown {
                width: 100%;
            }
            
            .dropdown-content {
                position: static;
                min-width: 100%;
                box-shadow: none;
                border: none;
                background: rgba(3, 155, 229, 0.1);
                border-radius: 8px;
                margin-top: 10px;
                transform: none;
            }

            .grades-table {
                font-size: 14px;
            }

            .grades-table th,
            .grades-table td {
                padding: 8px;
            }

            .page-header {
                padding: 15px;
            }

            .page-header h1 {
                font-size: 22px;
            }
        }

        @media (max-width: 480px) {
            .drawer {
                width: 280px;
                left: -280px;
            }

            .dashboard-container {
                margin: 5px;
                padding: 10px;
            }

            .grade-form {
                padding: 15px;
            }

            .grades-table-container {
                padding: 15px;
            }

            .btn-edit, .btn-delete {
                padding: 6px 8px;
                font-size: 12px;
            }
        }
        .btn-view:hover {
    background: rgba(3, 155, 229, 0.3) !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(3, 155, 229, 0.3);
}

.btn-view.active {
    background: var(--accent-color) !important;
    box-shadow: 0 4px 15px rgba(3, 155, 229, 0.4);
}
    </style>
</head>
<body>
    <!-- Overlay du drawer -->
    <div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>

    <!-- Drawer -->
    <nav class="drawer" id="drawer">
        <div class="drawer-header">
            <h3 class="drawer-title">
                <i class="fas fa-chart-bar"></i>
                Navigation Notes
            </h3>
            <button class="drawer-close" onclick="closeDrawer()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="drawer-content">
            <!-- Info utilisateur -->
            <div class="drawer-user-info">
                <div class="drawer-user-name"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Utilisateur'); ?></div>
                <div class="drawer-user-role"><?php echo ucfirst($user_role); ?> - ID: <?php echo htmlspecialchars($user_id); ?></div>
            </div>

            <!-- Menu de gestion des notes -->
            <div class="drawer-section-title">GESTION DES NOTES</div>
            <div class="drawer-menu-item">
                <a href="../grades/grades_management.php" class="drawer-menu-link <?php echo basename($_SERVER['PHP_SELF']) == 'grades_management.php' ? 'active' : ''; ?>">
                    <i class="fas fa-plus"></i>
                    <span>Saisir des Notes</span>
                    <?php if ($can_add_exam_grades): ?>
                        <span class="permission-indicator permission-granted">EXAM</span>
                    <?php else: ?>
                        <span class="permission-indicator permission-limited">CC</span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="drawer-menu-item">
                <a href="../grades/global_grades.php" class="drawer-menu-link <?php echo basename($_SERVER['PHP_SELF']) == 'global_grades.php' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i>
                    <span>Toutes les Notes</span>
                </a>
            </div>
            <div class="drawer-menu-item">
                <a href="../grades/grade_statistics.php" class="drawer-menu-link <?php echo basename($_SERVER['PHP_SELF']) == 'grade_statistics.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>Statistiques des Notes</span>
                </a>
            </div>
            <div class="drawer-menu-item">
                <a href="../grades/evaluation_periods.php" class="drawer-menu-link <?php echo basename($_SERVER['PHP_SELF']) == 'evaluation_periods.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar"></i>
                    <span>Périodes d'Évaluation</span>
                </a>
            </div>

            <?php if ($user_role === 'admin' || is_super_admin($conn, $user_id)): ?>
                <div class="drawer-section-title">PERMISSIONS D'EXAMEN</div>
                <div class="drawer-menu-item">
                    <a href="../admin/exam_permissions.php" class="drawer-menu-link <?php echo basename($_SERVER['PHP_SELF']) == 'exam_permissions.php' ? 'active' : ''; ?>">
                        <i class="fas fa-shield-alt"></i>
                        <span>Gérer les Permissions</span>
                    </a>
                </div>
                <div class="drawer-menu-item">
                    <a href="../admin/admin_permissions_overview.php" class="drawer-menu-link">
                        <i class="fas fa-eye"></i>
                        <span>Vue d'ensemble</span>
                    </a>
                </div>
            <?php endif; ?>

            <?php if ($user_role === 'admin'): ?>
                <div class="drawer-section-title">ADMINISTRATION</div>
                <div class="drawer-menu-item">
                    <a href="../admin/admin_dashboard.php" class="drawer-menu-link">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Tableau de bord</span>
                    </a>
                </div>
                <div class="drawer-menu-item">
                    <a href="../admin/user_management.php" class="drawer-menu-link">
                        <i class="fas fa-users"></i>
                        <span>Gestion Utilisateurs</span>
                    </a>
                </div>
                <div class="drawer-menu-item">
                    <a href="../admin/course_management.php" class="drawer-menu-link">
                        <i class="fas fa-book"></i>
                        <span>Gestion Cours</span>
                    </a>
                </div>
                <div class="drawer-menu-item">
                    <a href="../admin/class_management.php" class="drawer-menu-link">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Gestion Classes</span>
                    </a>
                </div>
            <?php endif; ?>

            <div class="drawer-section-title">OUTILS</div>
            <div class="drawer-menu-item">
                <a href="../grades/grade_export.php" class="drawer-menu-link">
                    <i class="fas fa-download"></i>
                    <span>Export des Données</span>
                </a>
            </div>
            <div class="drawer-section-title">GESTION DES NOTES</div>

                <!-- Vue Formulaire -->
                <div class="drawer-menu-item">
                    <a href="../grades/grades_management.php" class="drawer-menu-link <?php echo basename($_SERVER['PHP_SELF']) == 'grades_management.php' ? 'active' : ''; ?>">
                        <i class="fas fa-wpforms"></i>
                        <span>Saisie Formulaire</span>
                    </a>
                </div>

                <!-- Vue Tableau (NOUVEAU) -->
                <div class="drawer-menu-item">
                    <a href="../grades/grades_table_view.php" class="drawer-menu-link <?php echo basename($_SERVER['PHP_SELF']) == 'grades_table_view.php' ? 'active' : ''; ?>">
                        <i class="fas fa-table"></i>
                        <span>Saisie Tableau</span>
                    </a>
                </div>

                <div class="drawer-menu-item">
                    <a href="../grades/global_grades.php" class="drawer-menu-link">
                        <i class="fas fa-list"></i>
                        <span>Toutes les Notes</span>
                    </a>
                </div>
            
            <?php if ($user_role === 'teacher'): ?>
                <div class="drawer-menu-item">
                    <a href="../teacher/teacher_dashboard.php" class="drawer-menu-link">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span>Mon Espace Enseignant</span>
                    </a>
                </div>
            <?php endif; ?>

            <div class="drawer-section-title">GÉNÉRAL</div>
            <div class="drawer-menu-item">
                <a href="../admin/announcement_management.php" class="drawer-menu-link">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                </a>
            </div>
            <div class="drawer-menu-item">
                <a href="#" class="drawer-menu-link">
                    <i class="fas fa-question-circle"></i>
                    <span>Aide</span>
                </a>
            </div>
            <div class="drawer-menu-item">
                <a href="../pages/logout.php" class="drawer-menu-link" style="color: #dc3545;">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Header -->
    <header>
        <div class="header-content">
            <!-- Bouton pour ouvrir le drawer -->
            <button class="drawer-toggle" id="drawerToggle" onclick="toggleDrawer()">
                <i class="fas fa-bars"></i>
            </button>

            <div class="header-main-content">
                <!-- Icônes flottantes -->
                <div class="floating-icons">
                    <i class="floating-icon fas fa-graduation-cap"></i>
                    <i class="floating-icon fas fa-book"></i>
                    <i class="floating-icon fas fa-user-graduate"></i>
                    <i class="floating-icon fas fa-pencil-alt"></i>
                    <i class="floating-icon fas fa-chart-line"></i>
                </div>

                <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
                
                <nav>
                    <ul>
                        <li><a href="../admin/admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a></li>
                        <li class="nav-dropdown">
                            <a href="#" id="dropdownToggle" style="color: var(--accent-color);">
                                <i class="fas fa-chart-bar"></i> Mes notes
                                <i class="fas fa-chevron-down dropdown-arrow"></i>
                            </a>
                            <div class="dropdown-content" id="dropdownContent">
                                <a href="../grades/global_grades.php"><i class="fas fa-globe"></i> Vue globale</a>
                                <a href="../grades/grade_reports.php"><i class="fas fa-file-alt"></i> Bulletins</a>
                                <a href="../grades/grade_parameters.php"><i class="fas fa-cog"></i> Paramètres</a>
                                <a href="../grades/grade_export.php"><i class="fas fa-file-export"></i> Export</a>
                                <a href="../grades/evaluation_periods.php"><i class="fas fa-calendar-alt"></i> Périodes</a>
                            </div>
                        </li>                        
                        <li><a href="../admin/schedule_management.php"><i class="fas fa-calendar-alt"></i> Emploi du temps</a></li>
                        <li><a href="../admin/admin_profile.php"><i class="fas fa-user"></i> Profil</a></li>
                        <li><a href="../pages/logout.php" style="color: #dc3545;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
                    </ul>
                </nav>
            </div>
        </div>
    </header>

    <!-- Contenu principal de la page -->
    <div class="dashboard-container">
        <div class="page-header">
            <i class="fas fa-graduation-cap"></i>
            <div>
                <h1>Gestion des Notes</h1>
                <p>Saisie et suivi des évaluations</p>
            </div>
        </div>
        <!-- NOUVEAU : Bouton de basculement entre les vues -->
        <div style="background: rgba(255, 255, 255, 0.1); padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 15px; align-items: center;">
            <span style="font-weight: 600;"><i class="fas fa-eye"></i> Mode d'affichage :</span>
            <a href="grades_management.php" class="btn-view active" style="background: var(--accent-color); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-wpforms"></i> Vue Formulaire
            </a>
            <a href="grades_table_view.php" class="btn-view" style="background: rgba(255, 255, 255, 0.1); color: white; padding: 10px 20px; border-radius: 8px; text-decoration: none; display: flex; align-items: center; gap: 8px; transition: all 0.3s;">
                <i class="fas fa-table"></i> Vue Tableau
            </a>
        </div>

        <!-- Bannière des permissions -->
        <div class="permission-banner <?php echo $can_add_exam_grades ? 'has-exam-permission' : 'no-exam-permission'; ?>">
            <i class="fas <?php echo $can_add_exam_grades ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
            <span>
                <?php if ($can_add_exam_grades): ?>
                    Vous avez l'autorisation d'ajouter des notes d'examen
                <?php else: ?>
                    Vous ne pouvez ajouter que des notes de devoirs, contrôles continus et TP
                <?php endif; ?>
            </span>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <!-- Formulaire d'ajout de note -->
        <div class="grade-form">
            <h2><i class="fas fa-plus-circle"></i> Ajouter une Note</h2>
            
            <form method="POST" id="gradeForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="class">
                            <i class="fas fa-users"></i>
                            Classe
                        </label>
                        <select id="class" name="class_id" required onchange="loadStudentsAndCourses(this.value)">
                            <option value="">Sélectionner une classe</option>
                            <?php while ($class = $classes_result->fetch_assoc()): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="student">
                            <i class="fas fa-user-graduate"></i>
                            Étudiant
                        </label>
                        <select id="student" name="student_id" required disabled>
                            <option value="">Choisir d'abord une classe</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="course">
                            <i class="fas fa-book"></i>
                            Cours
                        </label>
                        <select id="course" name="course_id" required disabled>
                            <option value="">Choisir d'abord une classe</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="evaluation_type">
                            <i class="fas fa-clipboard-list"></i>
                            Type d'évaluation
                        </label>
                        <select id="evaluation_type" name="evaluation_type_id" required onchange="checkExamPermission(this.value)">
                            <option value="">Sélectionner un type</option>
                            <?php while ($type = $evaluation_types_result->fetch_assoc()): ?>
                                <option value="<?php echo $type['id']; ?>" 
                                        <?php echo ($type['id'] == 2 && !$can_add_exam_grades) ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?>
                                    <?php echo ($type['id'] == 2 && !$can_add_exam_grades) ? ' (Non autorisé)' : ''; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <div id="examRestrictionNotice" class="exam-restriction-notice" style="display: none;">
                            <i class="fas fa-lock"></i>
                            <span>Seul un super administrateur ou les utilisateurs autorisés peuvent ajouter des notes d'examen</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="period">
                            <i class="fas fa-calendar-alt"></i>
                            Période d'évaluation
                        </label>
                        <select id="period" name="evaluation_period_id" required>
                            <option value="">Sélectionner une période</option>
                            <?php foreach ($periods_by_year as $py_year => $py_periods): ?>
                                <optgroup label="<?php echo htmlspecialchars($py_year); ?><?php echo $py_year === $current_year ? ' (courante)' : ''; ?>">
                                    <?php foreach ($py_periods as $period): ?>
                                        <option value="<?php echo $period['id']; ?>">
                                            <?php echo htmlspecialchars($period['name']); ?> — <?php echo htmlspecialchars($py_year); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="grade">
                            <i class="fas fa-star"></i>
                            Note (/20)
                        </label>
                        <div class="grade-input-container">
                            <input type="number" id="grade" name="grade" step="0.25" min="0" max="20" 
                                   required oninput="previewGrade(this.value)">
                            <div id="gradePreview" class="grade-preview"></div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="comment">
                        <i class="fas fa-comment"></i>
                        Commentaire (optionnel)
                    </label>
                    <textarea id="comment" name="comment" rows="3" 
                              placeholder="Ajouter un commentaire sur cette évaluation..."></textarea>
                </div>

                <button type="submit" name="add_grade" class="btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i>
                    Ajouter la Note
                </button>
            </form>
        </div>

        <!-- Tableau des notes récentes -->
        <div class="grades-table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap;">
                <h2 style="margin: 0;"><i class="fas fa-history"></i> Notes Récentes</h2>
                <select id="recentYearSelect" onchange="reloadRecentGrades()" title="Filtrer par année académique">
                    <option value="">Toutes les années</option>
                    <?php foreach ($available_years as $yr): ?>
                        <option value="<?php echo htmlspecialchars($yr); ?>"
                                <?php echo $yr === $current_year ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($yr); ?><?php echo $yr === $current_year ? ' (courante)' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="recentGradesTable" class="loading">
                <p>Sélectionnez une classe pour voir les notes récentes</p>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        const canAddExamGrades = <?php echo $can_add_exam_grades ? 'true' : 'false'; ?>;
        let dropdownTimeout;

        // Fonctions pour gérer le drawer
        function toggleDrawer() {
            const drawer = document.getElementById('drawer');
            const overlay = document.getElementById('drawerOverlay');
            const toggle = document.getElementById('drawerToggle');
            
            const isOpen = drawer.classList.contains('active');
            
            if (isOpen) {
                closeDrawer();
            } else {
                openDrawer();
            }
        }

        function openDrawer() {
            const drawer = document.getElementById('drawer');
            const overlay = document.getElementById('drawerOverlay');
            const toggle = document.getElementById('drawerToggle');
            
            drawer.classList.add('active');
            overlay.classList.add('active');
            toggle.classList.add('active');
            
            // Empêcher le défilement du body quand le drawer est ouvert
            document.body.style.overflow = 'hidden';
        }

        function closeDrawer() {
            const drawer = document.getElementById('drawer');
            const overlay = document.getElementById('drawerOverlay');
            const toggle = document.getElementById('drawerToggle');
            
            drawer.classList.remove('active');
            overlay.classList.remove('active');
            toggle.classList.remove('active');
            
            // Restaurer le défilement du body
            document.body.style.overflow = 'auto';
        }

        // Gestion du dropdown
        function toggleDropdown() {
            const dropdown = document.querySelector('.nav-dropdown');
            const dropdownContent = document.getElementById('dropdownContent');
            
            dropdown.classList.toggle('active');
            dropdownContent.classList.toggle('show');
        }

        function closeDropdown() {
            const dropdown = document.querySelector('.nav-dropdown');
            const dropdownContent = document.getElementById('dropdownContent');
            
            dropdown.classList.remove('active');
            dropdownContent.classList.remove('show');
        }

        // Event listeners
        document.addEventListener('DOMContentLoaded', function() {
            const dropdown = document.querySelector('.nav-dropdown');
            const dropdownToggle = document.getElementById('dropdownToggle');
            const dropdownContent = document.getElementById('dropdownContent');

            // Pour desktop - hover
            if (window.innerWidth > 768) {
                dropdown.addEventListener('mouseenter', function() {
                    clearTimeout(dropdownTimeout);
                    dropdown.classList.add('active');
                    dropdownContent.classList.add('show');
                });

                dropdown.addEventListener('mouseleave', function() {
                    dropdownTimeout = setTimeout(() => {
                        dropdown.classList.remove('active');
                        dropdownContent.classList.remove('show');
                    }, 100);
                });
            }

            // Pour mobile et desktop - click
            dropdownToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleDropdown();
            });

            // Fermer dropdown si on clique ailleurs
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target)) {
                    closeDropdown();
                }
            });

            // Fermer dropdown avec Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeDropdown();
                    closeDrawer();
                }
            });

            // Gérer le redimensionnement
            window.addEventListener('resize', function() {
                if (window.innerWidth <= 768) {
                    closeDropdown();
                }
            });
        });

        async function loadStudentsAndCourses(classId) {
            const studentSelect = document.getElementById('student');
            const courseSelect = document.getElementById('course');
            
            if (!classId) {
                studentSelect.disabled = true;
                courseSelect.disabled = true;
                studentSelect.innerHTML = '<option value="">Choisir d\'abord une classe</option>';
                courseSelect.innerHTML = '<option value="">Choisir d\'abord une classe</option>';
                document.getElementById('recentGradesTable').innerHTML = '<p class="loading">Sélectionnez une classe pour voir les notes récentes</p>';
                return;
            }

            try {
                // Charger les étudiants
                const studentsResponse = await fetch(`?action=get_students&class_id=${classId}`);
                const students = await studentsResponse.json();
                
                if (students.error) {
                    studentSelect.innerHTML = '<option value="">Erreur de chargement</option>';
                } else {
                    studentSelect.innerHTML = '<option value="">Sélectionner un étudiant</option>';
                    students.forEach(student => {
                        studentSelect.innerHTML += `<option value="${student.id}">${student.name}</option>`;
                    });
                    studentSelect.disabled = false;
                }

                // Charger les cours
                const coursesResponse = await fetch(`?action=get_courses&class_id=${classId}`);
                const courses = await coursesResponse.json();
                
                if (courses.error) {
                    courseSelect.innerHTML = '<option value="">Erreur de chargement</option>';
                } else {
                    courseSelect.innerHTML = '<option value="">Sélectionner un cours</option>';
                    courses.forEach(course => {
                        courseSelect.innerHTML += `<option value="${course.id}">${course.name}</option>`;
                    });
                    courseSelect.disabled = false;
                }

                // Charger les notes récentes
                await loadRecentGrades(classId);

            } catch (error) {
                console.error('Erreur:', error);
                studentSelect.innerHTML = '<option value="">Erreur de chargement</option>';
                courseSelect.innerHTML = '<option value="">Erreur de chargement</option>';
            }
        }

        function checkExamPermission(evaluationType) {
            const notice = document.getElementById('examRestrictionNotice');
            const submitBtn = document.getElementById('submitBtn');
            
            if (evaluationType == '2' && !canAddExamGrades) {
                notice.style.display = 'flex';
                submitBtn.disabled = true;
            } else {
                notice.style.display = 'none';
                submitBtn.disabled = false;
            }
        }

        function previewGrade(value) {
            const preview = document.getElementById('gradePreview');
            const numValue = parseFloat(value);
            
            if (isNaN(numValue) || numValue < 0 || numValue > 20) {
                preview.className = 'grade-preview invalid';
                preview.textContent = 'Invalide';
                return;
            }

            let gradeClass = '';
            if (numValue >= 16) gradeClass = 'grade-excellent';
            else if (numValue >= 14) gradeClass = 'grade-good';
            else if (numValue >= 10) gradeClass = 'grade-average';
            else gradeClass = 'grade-poor';

            preview.className = `grade-preview ${gradeClass}`;
            preview.textContent = `${numValue.toFixed(2)}/20`;
        }

        function reloadRecentGrades() {
            const classSel = document.getElementById('class');
            if (classSel && classSel.value) loadRecentGrades(classSel.value);
        }

        async function loadRecentGrades(classId) {
            const container = document.getElementById('recentGradesTable');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Chargement des notes...</div>';

            const yearSel = document.getElementById('recentYearSelect');
            const yearParam = yearSel && yearSel.value ? `&year=${encodeURIComponent(yearSel.value)}` : '';

            try {
                const response = await fetch(`?action=get_recent_grades&class_id=${classId}&limit=10${yearParam}`);
                const grades = await response.json();
                
                if (grades.error) {
                    container.innerHTML = `<p style="color: #f44336;">Erreur: ${grades.error}</p>`;
                    return;
                }

                if (grades.length === 0) {
                    container.innerHTML = '<div class="empty-state"><i class="fas fa-clipboard"></i><p>Aucune note récente</p></div>';
                    return;
                }

                let tableHTML = `
                    <table class="grades-table">
                        <thead>
                            <tr>
                                <th>Étudiant</th>
                                <th>Cours</th>
                                <th>Type</th>
                                <th>Note</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                grades.forEach(grade => {
                    tableHTML += `
                        <tr>
                            <td>${grade.student_name}</td>
                            <td>${grade.course_name}</td>
                            <td>${grade.evaluation_type}</td>
                            <td><span class="grade-display ${getGradeClass(grade.grade)}">${grade.grade}/20</span></td>
                            <td>${new Date(grade.created_at).toLocaleDateString()}</td>
                            <td>
                                <button onclick="editGrade(${grade.id})" class="btn-edit" title="Modifier">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="deleteGrade(${grade.id})" class="btn-delete" title="Supprimer">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                });

                tableHTML += '</tbody></table>';
                container.innerHTML = tableHTML;

            } catch (error) {
                console.error('Erreur:', error);
                container.innerHTML = '<p style="color: #f44336;">Erreur lors du chargement des notes</p>';
            }
        }

        function getGradeClass(grade) {
            const value = parseFloat(grade);
            if (value >= 16) return 'grade-excellent';
            if (value >= 14) return 'grade-good';
            if (value >= 10) return 'grade-average';
            return 'grade-poor';
        }

        function editGrade(gradeId) {
            // Rediriger vers la page de modification
            window.location.href = `edit_grade.php?id=${gradeId}`;
        }

        async function deleteGrade(gradeId) {
            if (!confirm('Êtes-vous sûr de vouloir supprimer cette note ?')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('grade_id', gradeId);

                const response = await fetch('?action=delete_grade', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    // Recharger les notes récentes
                    const classId = document.getElementById('class').value;
                    if (classId) {
                        await loadRecentGrades(classId);
                    }
                    alert('Note supprimée avec succès');
                } else {
                    alert('Erreur: ' + data.message);
                }
            } catch (error) {
                console.error('Erreur:', error);
                alert('Erreur lors de la suppression');
            }
        }

        // Validation du formulaire
        document.getElementById('gradeForm').addEventListener('submit', function(e) {
            const evaluationType = document.getElementById('evaluation_type').value;
            
            if (evaluationType == '2' && !canAddExamGrades) {
                e.preventDefault();
                alert('Vous n\'avez pas l\'autorisation d\'ajouter des notes d\'examen.');
                return false;
            }
        });
    </script>
</body>
    <?php include '../includes/footer.php'; ?>

</html>