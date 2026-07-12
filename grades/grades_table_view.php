<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/grade_lock.php';
require_once '../includes/super_admin.php';
require_once '../includes/semester_helper.php';

/** @var mysqli $conn Fourni par includes/db_connect.php */

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    header("Location: ../pages/login.php?error=access_denied");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// ── Contexte année académique (sélecteur multi-années) ──────────────────────
$current_year    = ANNEE_ACADEMIQUE_COURANTE;
$available_years = get_school_years($conn);
$selected_year   = (isset($_GET['year'])
        && preg_match('/^\d{4}-\d{4}$/', $_GET['year'])
        && in_array($_GET['year'], $available_years, true))
    ? $_GET['year'] : $current_year;
$is_archive_year = ($selected_year !== $current_year);
// Périodes de l'année courante : cible unique de toute écriture de note
$current_year_period_ids = get_period_ids_for_year($conn, $current_year);

// Fonction pour vérifier les permissions d'examen
function canAddExamGrade($conn, $user_id) {
    if (is_super_admin($conn, $user_id)) {
        return true;
    }
    
    $query = "SELECT id FROM exam_permissions 
              WHERE user_id = ? AND is_active = 1 
              AND (expires_at IS NULL OR expires_at > NOW())";
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
        $query = "SELECT DISTINCT c.id, c.name, c.coefficient, c.semester, c.total_hours
                  FROM courses c
                  WHERE JSON_CONTAINS(c.class_id, ?)";
        
        $params = [json_encode(strval($class_id))];
        $types = "s";
        
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

// Fonction pour récupérer TOUTES les notes d'une classe avec type d'évaluation
function getGradesByClass($conn, $class_id, $evaluation_type_id = null, $period_ids = null) {
    $grades = [];

    try {
        $query = "SELECT g.*, u.id as student_id, c.id as course_id
                  FROM grades g
                  JOIN users u ON g.student_id = u.id
                  JOIN courses c ON g.course_id = c.id
                  WHERE u.class_id = ?";

        $params = [$class_id];
        $types = "i";

        if ($evaluation_type_id !== null) {
            $query .= " AND g.evaluation_type_id = ?";
            $params[] = $evaluation_type_id;
            $types .= "i";
        }

        // Filtre par année académique (liste d'ids de périodes)
        if (is_array($period_ids)) {
            if (empty($period_ids)) {
                // Année sans période : aucune note à afficher
                $query .= " AND 1 = 0";
            } else {
                $in = implode(',', array_map('intval', $period_ids));
                $query .= " AND g.evaluation_period_id IN ($in)";
            }
        }

        $query .= " ORDER BY g.created_at ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $key = $row['student_id'] . '_' . $row['course_id'];
            if (!isset($grades[$key])) {
                $grades[$key] = [];
            }
            $grades[$key][] = [
                'id' => $row['id'],
                'grade' => $row['grade'],
                'comment' => $row['comment'],
                'created_at' => $row['created_at']
            ];
        }
    } catch (Exception $e) {
        error_log("Erreur getGradesByClass: " . $e->getMessage());
    }
    
    return $grades;
}

// Fonction pour obtenir le nombre maximum d'évaluations par cours
function getMaxEvaluationsPerCourse($grades, $courses, $students) {
    $maxEvals = [];
    
    foreach ($courses as $course) {
        $maxCount = 0;
        foreach ($students as $student) {
            $key = $student['id'] . '_' . $course['id'];
            $count = isset($grades[$key]) ? count($grades[$key]) : 0;
            if ($count > $maxCount) {
                $maxCount = $count;
            }
        }
        $maxEvals[$course['id']] = max($maxCount, 1);
    }
    
    return $maxEvals;
}

// Traitement AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Année archivée = lecture seule : aucune écriture hors année courante
    if ($is_archive_year && $_SERVER['REQUEST_METHOD'] === 'POST') {
        echo json_encode([
            'success' => false,
            'message' => "Année $selected_year archivée : consultation seule. Repassez sur $current_year pour modifier des notes."
        ]);
        exit();
    }

    switch ($_GET['action']) {
        case 'get_courses':
            if (isset($_GET['class_id'])) {
                $class_id = intval($_GET['class_id']);
                $courses = getCoursesByClass($conn, $class_id, $user_id, $user_role);
                echo json_encode($courses);
            } else {
                echo json_encode(['error' => 'Class ID manquant']);
            }
            exit();
            
        case 'get_class_matrix':
            if (isset($_GET['class_id']) && isset($_GET['evaluation_type_id'])) {
                $class_id = intval($_GET['class_id']);
                $evaluation_type_id = intval($_GET['evaluation_type_id']);

                // Notes limitées à l'année sélectionnée (courante ou archive)
                $matrix_period_ids = get_period_ids_for_year($conn, $selected_year);

                $students = getStudentsByClass($conn, $class_id);
                $courses = getCoursesByClass($conn, $class_id, $user_id, $user_role);
                $grades = getGradesByClass($conn, $class_id, $evaluation_type_id, $matrix_period_ids);
                $maxEvals = getMaxEvaluationsPerCourse($grades, $courses, $students);

                echo json_encode([
                    'students' => $students,
                    'courses' => $courses,
                    'grades' => $grades,
                    'maxEvaluations' => $maxEvals,
                    'year' => $selected_year,
                    'is_archive' => $is_archive_year
                ]);
            } else {
                echo json_encode(['error' => 'Paramètres manquants']);
            }
            exit();
            
        case 'save_grade':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $student_id = $_POST['student_id'];
                $course_id = intval($_POST['course_id']);
                $evaluation_type_id = intval($_POST['evaluation_type_id']);
                $grade = floatval($_POST['grade']);
                $comment = $_POST['comment'] ?? '';
                $grade_id = isset($_POST['grade_id']) && $_POST['grade_id'] !== '' ? intval($_POST['grade_id']) : null;
                
                if ($grade < 0 || $grade > 20) {
                    echo json_encode(['success' => false, 'message' => 'La note doit être entre 0 et 20']);
                    exit();
                }
                
                $can_add_exam_grades = canAddExamGrade($conn, $user_id);
                if ($evaluation_type_id == 2 && !$can_add_exam_grades) {
                    echo json_encode(['success' => false, 'message' => 'Pas d\'autorisation pour les examens']);
                    exit();
                }
                
                if ($user_role === 'teacher') {
                    $course_check = "SELECT semester FROM courses WHERE id = ? AND teacher_id = ?";
                    $stmt = $conn->prepare($course_check);
                    $stmt->bind_param("is", $course_id, $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows === 0) {
                        echo json_encode(['success' => false, 'message' => 'Non autorisé pour ce cours']);
                        exit();
                    }
                    $course = $result->fetch_assoc();
                    $semester = $course['semester'];
                } else {
                    $semester_query = "SELECT semester FROM courses WHERE id = ?";
                    $stmt = $conn->prepare($semester_query);
                    $stmt->bind_param("i", $course_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $course = $result->fetch_assoc();
                    $semester = $course['semester'];
                }
                
                if ($grade_id) {
                    // Verrou : un enseignant ne modifie plus une note trop ancienne
                    if ($user_role === 'teacher' && grade_is_locked($conn, $grade_id, $user_role)) {
                        echo json_encode(['success' => false, 'message' => grade_lock_message($conn)]);
                        exit();
                    }
                    $update_query = "UPDATE grades SET grade = ?, comment = ?, updated_at = NOW(), updated_by = ?
                                   WHERE id = ?";
                    $stmt = $conn->prepare($update_query);
                    $stmt->bind_param("dssi", $grade, $comment, $user_id, $grade_id);
                    
                    if ($stmt->execute()) {
                        echo json_encode(['success' => true, 'message' => 'Note mise à jour', 'grade_id' => $grade_id]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Erreur mise à jour']);
                    }
                } else {
                    // Période réelle de l'année courante pour ce semestre
                    // (avant : le n° de semestre servait d'id de période → notes
                    // rattachées à la mauvaise année académique)
                    $target_period_id = get_period_id_for($conn, (int) $semester, $current_year)
                        ?? (int) $semester;
                    $insert_query = "INSERT INTO grades
                                    (student_id, course_id, evaluation_type_id, grade, comment, evaluation_period_id, created_by)
                                    VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($insert_query);
                    $stmt->bind_param("siidsis", $student_id, $course_id, $evaluation_type_id, $grade, $comment, $target_period_id, $user_id);
                    
                    if ($stmt->execute()) {
                        echo json_encode(['success' => true, 'message' => 'Note ajoutée', 'grade_id' => $conn->insert_id]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Erreur ajout']);
                    }
                }
            }
            exit();
            
        case 'update_comment':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_id'])) {
                $grade_id = intval($_POST['grade_id']);
                $comment = $_POST['comment'] ?? '';
                
                if ($user_role === 'admin') {
                    $update_query = "UPDATE grades SET comment = ?, updated_at = NOW(), updated_by = ? WHERE id = ?";
                    $stmt = $conn->prepare($update_query);
                    $stmt->bind_param("ssi", $comment, $user_id, $grade_id);
                } else {
                    // Verrou : un enseignant ne modifie plus une note trop ancienne
                    if (grade_is_locked($conn, $grade_id, $user_role)) {
                        echo json_encode(['success' => false, 'message' => grade_lock_message($conn)]);
                        exit();
                    }
                    $update_query = "UPDATE grades g
                                   JOIN courses c ON g.course_id = c.id
                                   SET g.comment = ?, g.updated_at = NOW(), g.updated_by = ?
                                   WHERE g.id = ? AND c.teacher_id = ?";
                    $stmt = $conn->prepare($update_query);
                    $stmt->bind_param("ssis", $comment, $user_id, $grade_id, $user_id);
                }
                
                if ($stmt->execute()) {
                    echo json_encode(['success' => true, 'message' => 'Commentaire mis à jour']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Données manquantes']);
            }
            exit();
            
        case 'add_evaluation_column':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id']) && isset($_POST['evaluation_type_id'])) {
                $course_id = intval($_POST['course_id']);
                $evaluation_type_id = intval($_POST['evaluation_type_id']);
                $class_id = intval($_POST['class_id']);
                
                $can_add_exam_grades = canAddExamGrade($conn, $user_id);
                if ($evaluation_type_id == 2 && !$can_add_exam_grades) {
                    echo json_encode(['success' => false, 'message' => 'Pas d\'autorisation pour les examens']);
                    exit();
                }
                
                if ($user_role === 'teacher') {
                    $course_check = "SELECT id FROM courses WHERE id = ? AND teacher_id = ?";
                    $stmt = $conn->prepare($course_check);
                    $stmt->bind_param("is", $course_id, $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows === 0) {
                        echo json_encode(['success' => false, 'message' => 'Non autorisé pour ce cours']);
                        exit();
                    }
                }
                
                $students = getStudentsByClass($conn, $class_id);
                
                if (count($students) > 0) {
                    $first_student = $students[0]['id'];
                    $count_query = "SELECT COUNT(*) as count FROM grades 
                                   WHERE student_id = ? AND course_id = ? AND evaluation_type_id = ?";
                    $stmt = $conn->prepare($count_query);
                    $stmt->bind_param("sii", $first_student, $course_id, $evaluation_type_id);
                    $stmt->execute();
                    $count_result = $stmt->get_result();
                    $current_count = $count_result->fetch_assoc()['count'];
                    
                    $new_eval_number = $current_count + 1;
                    
                    echo json_encode([
                        'success' => true, 
                        'message' => "Colonne d'évaluation #{$new_eval_number} créée. Vous pouvez maintenant saisir les notes.",
                        'eval_number' => $new_eval_number
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Aucun étudiant dans cette classe']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Données manquantes']);
            }
            exit();
            
        case 'delete_grade':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grade_id'])) {
                $grade_id = intval($_POST['grade_id']);
                
                $set_var_query = "SET @deleted_by_user = ?";
                $stmt_var = $conn->prepare($set_var_query);
                $stmt_var->bind_param("s", $user_id);
                $stmt_var->execute();
                
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

$can_add_exam_grades = canAddExamGrade($conn, $user_id);

// Récupération des classes
if ($user_role === 'admin') {
    $classes_query = "SELECT id, name FROM classes ORDER BY name";
    $stmt = $conn->prepare($classes_query);
} else {
    $classes_query = "
        SELECT DISTINCT cl.id, cl.name
        FROM courses c
        JOIN classes cl ON JSON_CONTAINS(c.class_id, JSON_QUOTE(CAST(cl.id AS CHAR)))
        WHERE c.teacher_id = ?
        ORDER BY cl.name
    ";
    $stmt = $conn->prepare($classes_query);
    $stmt->bind_param("s", $user_id);
}

$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Récupération des types d'évaluation
$eval_types_query = "SELECT id, name FROM evaluation_types ORDER BY name";
$eval_types_result = $conn->query($eval_types_query);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vue Tableau - Gestion des Notes</title>
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
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Google Sans', Arial, sans-serif;
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            color: var(--text-light);
            min-height: 100vh;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: var(--text-light);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.3);
        }

        .page-header h1 {
            margin: 0 0 5px 0;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .view-switcher {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .view-switcher span {
            font-weight: 600;
        }

        .btn-view {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .btn-view:hover {
            background: rgba(3, 155, 229, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.3);
        }

        .btn-view.active {
            background: var(--accent-color);
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.4);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .filters-section {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            border: 1px solid var(--border-color);
        }

        .filters-section h3 {
            margin: 0 0 20px 0;
            color: var(--accent-color);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            margin-bottom: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-group select {
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            font-size: 14px;
        }

        .filter-group select:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(3, 155, 229, 0.2);
        }

        .filter-group select option {
            background: var(--secondary-bg);
        }

        .btn-load {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .btn-load:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.4);
        }

        .btn-load:disabled {
            background: #666;
            cursor: not-allowed;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert.success {
            background: linear-gradient(135deg, var(--success-color), #45a049);
            color: white;
        }

        .alert.error {
            background: linear-gradient(135deg, var(--error-color), #d32f2f);
            color: white;
        }

        .alert.info {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: white;
        }

        .excel-table-container {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            border: 1px solid var(--border-color);
            overflow-x: auto;
        }

        .excel-table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .excel-table-header h2 {
            margin: 0;
            color: var(--accent-color);
        }

        .excel-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 800px;
        }

        .excel-table th {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: white;
            padding: 12px 8px;
            font-weight: 600;
            text-align: center;
            position: sticky;
            top: 0;
            z-index: 10;
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-size: 13px;
        }

        .excel-table th:first-child {
            position: sticky;
            left: 0;
            z-index: 20;
            min-width: 200px;
            text-align: left;
        }

        .excel-table td {
            padding: 5px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
            background: rgba(255, 255, 255, 0.05);
        }

        .excel-table td:first-child {
            position: sticky;
            left: 0;
            background: rgba(12, 45, 72, 0.95);
            font-weight: 600;
            text-align: left;
            padding-left: 15px;
            z-index: 5;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
        }

        .excel-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.08);
        }

        .excel-table tbody tr:nth-child(even) td:first-child {
            background: rgba(5, 30, 52, 0.95);
        }

        .grade-cell {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            flex-wrap: wrap;
        }

        .grade-input {
            width: 70px;
            padding: 6px;
            border: 2px solid transparent;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .grade-input:focus {
            border-color: var(--accent-color);
            background: rgba(255, 255, 255, 0.2);
            outline: none;
            box-shadow: 0 0 0 3px rgba(3, 155, 229, 0.2);
        }

        .grade-input.has-value {
            background: rgba(76, 175, 80, 0.2);
            border-color: #2ecc71;
        }

        .grade-input.error {
            background: rgba(231, 76, 60, 0.2);
            border-color: #e74c3c;
        }

        .grade-input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .comment-btn {
            padding: 4px 8px;
            background: rgba(52, 152, 219, 0.3);
            border: 1px solid #3498db;
            border-radius: 4px;
            cursor: pointer;
            color: #3498db;
            font-size: 11px;
            transition: all 0.3s ease;
        }

        .comment-btn:hover {
            background: rgba(52, 152, 219, 0.5);
        }

        .comment-btn.has-comment {
            background: rgba(241, 196, 15, 0.3);
            border-color: #f1c40f;
            color: #f1c40f;
        }

        .btn-add-eval {
            margin-left: 10px;
            padding: 5px 10px;
            background: rgba(46, 204, 113, 0.3);
            border: 1px solid #2ecc71;
            color: #2ecc71;
            border-radius: 5px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-add-eval:hover {
            background: rgba(46, 204, 113, 0.5);
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(46, 204, 113, 0.4);
        }

        .save-indicator {
            font-size: 11px;
            min-width: 15px;
        }

        .save-indicator.saving {
            color: var(--warning-color);
        }

        .save-indicator.saved {
            color: var(--success-color);
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
        }

        .stat-item {
            text-align: center;
            padding: 10px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--accent-color);
        }

        .stat-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 5px;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: linear-gradient(135deg, var(--secondary-bg), var(--primary-bg));
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            color: var(--accent-color);
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .modal-body textarea {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
        }

        .modal-body textarea:focus {
            border-color: var(--accent-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(3, 155, 229, 0.2);
        }

        .modal-footer {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }

        .modal-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .modal-btn-primary {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: white;
        }

        .modal-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.4);
        }

        .modal-btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .modal-btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: rgba(255, 255, 255, 0.7);
        }

        .loading i {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .empty-state {
            text-align: center;
            padding: 60px;
            color: rgba(255, 255, 255, 0.7);
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }

            .excel-table {
                font-size: 12px;
            }

            .grade-input {
                width: 60px;
                font-size: 12px;
            }

            .container {
                padding: 10px;
            }

            .view-switcher {
                flex-direction: column;
                align-items: stretch;
            }

            .btn-view {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header_discussion.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-table"></i> Gestion des Notes - Vue Tableau</h1>
            <p>Saisie et modification rapide de toutes les évaluations par classe</p>
        </div>

        <?php if (!$can_add_exam_grades): ?>
            <div class="alert info">
                <i class="fas fa-info-circle"></i>
                <span>Information : Vous ne pouvez ajouter que des notes de devoirs, contrôles continus et TP. Les notes d'examen nécessitent une permission spéciale.</span>
            </div>
        <?php endif; ?>

        <div id="alertContainer"></div>

        <div class="filters-section">
            <h3><i class="fas fa-filter"></i> Filtres de sélection</h3>
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="class">
                        <i class="fas fa-users"></i>
                        Classe
                    </label>
                    <select id="class" onchange="checkLoadButton()">
                        <option value="">Sélectionner une classe</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>">
                                <?php echo htmlspecialchars($class['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="evalType">
                        <i class="fas fa-clipboard-list"></i>
                        Type d'évaluation
                    </label>
                    <select id="evalType" onchange="checkLoadButton()">
                        <option value="">Sélectionner un type</option>
                        <?php while ($type = $eval_types_result->fetch_assoc()): ?>
                            <option value="<?php echo $type['id']; ?>"
                                    <?php echo ($type['id'] == 2 && !$can_add_exam_grades) ? 'disabled' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                                <?php echo ($type['id'] == 2 && !$can_add_exam_grades) ? ' - Non autorisé' : ''; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="yearSelect">
                        <i class="fas fa-calendar-alt"></i>
                        Année académique
                    </label>
                    <select id="yearSelect" onchange="checkLoadButton()">
                        <?php foreach ($available_years as $yr): ?>
                            <option value="<?php echo htmlspecialchars($yr); ?>"
                                    <?php echo $yr === $selected_year ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($yr); ?><?php echo $yr === $current_year ? ' (courante)' : ' (archive)'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

        </div>

        <div class="excel-table-container" id="tableContainer" style="display: none;">
            <div class="loading">
                <i class="fas fa-spinner fa-pulse"></i>
                <p>Sélectionnez une classe et un type d'évaluation : le tableau se charge automatiquement</p>
            </div>
        </div>
    </div>

    <!-- Modal pour les commentaires -->
    <div class="modal-overlay" id="commentModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-comment"></i> Commentaire</h3>
                <button class="modal-close" onclick="closeCommentModal()">&times;</button>
            </div>
            <div class="modal-body">
                <textarea id="commentTextarea" placeholder="Entrez votre commentaire ici..."></textarea>
            </div>
            <div class="modal-footer">
                <button class="modal-btn modal-btn-secondary" onclick="closeCommentModal()">Annuler</button>
                <button class="modal-btn modal-btn-primary" onclick="saveComment()">
                    <i class="fas fa-save"></i> Enregistrer
                </button>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        const canAddExamGrades = <?php echo $can_add_exam_grades ? 'true' : 'false'; ?>;
        const CURRENT_YEAR = <?php echo json_encode($current_year); ?>;
        let currentMatrixYear = CURRENT_YEAR;
        let currentCommentInput = null;

        // ── Mémorisation du dernier contexte de travail (classe / type / année) ──
        const GM_STORE_KEY = 'uv_admin_grades_last_context';

        function saveWorkContext(classId, evalTypeId, year) {
            try {
                localStorage.setItem(GM_STORE_KEY, JSON.stringify({ classId, evalTypeId, year }));
            } catch (e) { /* stockage indisponible : tant pis */ }
        }

        function restoreWorkContext() {
            let ctx = null;
            try {
                ctx = JSON.parse(localStorage.getItem(GM_STORE_KEY) || 'null');
            } catch (e) { return; }
            if (!ctx) return;

            const classSel = document.getElementById('class');
            const typeSel  = document.getElementById('evalType');
            const yearSel  = document.getElementById('yearSelect');

            // Ne restaurer que des options encore existantes (classe supprimée, etc.)
            if (ctx.classId && classSel.querySelector(`option[value="${ctx.classId}"]`)) {
                classSel.value = ctx.classId;
            }
            if (ctx.evalTypeId) {
                const opt = typeSel.querySelector(`option[value="${ctx.evalTypeId}"]`);
                if (opt && !opt.disabled) typeSel.value = ctx.evalTypeId;
            }
            if (ctx.year && yearSel.querySelector(`option[value="${ctx.year}"]`)) {
                yearSel.value = ctx.year;
            }

            // Déclenche le chargement automatique si classe + type sont restaurés
            checkLoadButton();
        }

        document.addEventListener('DOMContentLoaded', restoreWorkContext);

        // Chargement automatique dès que classe + type sont sélectionnés
        function checkLoadButton() {
            const classId = document.getElementById('class').value;
            const evalTypeId = document.getElementById('evalType').value;

            if (classId && evalTypeId) {
                loadGradesTable();
            }
        }

        async function loadGradesTable() {
            const classId = document.getElementById('class').value;
            const evalTypeId = document.getElementById('evalType').value;
            currentMatrixYear = document.getElementById('yearSelect').value || CURRENT_YEAR;
            const container = document.getElementById('tableContainer');

            if (classId && evalTypeId) {
                saveWorkContext(classId, evalTypeId, currentMatrixYear);
            }

            container.style.display = 'block';
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-pulse"></i><p>Chargement des données...</p></div>';

            if (evalTypeId == 2 && !canAddExamGrades) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-lock"></i>
                        <h3>Accès refusé</h3>
                        <p>Vous n'avez pas l'autorisation d'ajouter des notes d'examen</p>
                    </div>
                `;
                return;
            }

            try {
                const response = await fetch(`?action=get_class_matrix&class_id=${classId}&evaluation_type_id=${evalTypeId}&year=${encodeURIComponent(currentMatrixYear)}`);
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                renderExcelTable(data, classId, evalTypeId);
            } catch (error) {
                console.error('Erreur:', error);
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Erreur de chargement</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }

        function renderExcelTable(data, classId, evalTypeId) {
            const container = document.getElementById('tableContainer');
            const { students, courses, grades, maxEvaluations } = data;
            const isArchive = !!data.is_archive;
            const matrixYear = data.year || CURRENT_YEAR;

            if (students.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-user-slash"></i>
                        <h3>Aucun étudiant dans cette classe</h3>
                    </div>
                `;
                return;
            }

            if (courses.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <h3>Aucun cours disponible pour cette classe</h3>
                    </div>
                `;
                return;
            }

            let totalGrades = 0;
            Object.values(grades).forEach(gradeArray => {
                totalGrades += gradeArray.length;
            });

            let totalExpected = 0;
            courses.forEach(course => {
                totalExpected += students.length * maxEvaluations[course.id];
            });

            let html = `
                <div class="excel-table-header">
                    <h2>
                        <i class="fas fa-users"></i>
                        ${students.length} étudiants - ${courses.length} cours — ${matrixYear}
                    </h2>
                    <button class="modal-btn modal-btn-primary" onclick="loadGradesTable()" style="padding: 10px 20px;">
                        <i class="fas fa-sync-alt"></i> Actualiser
                    </button>
                </div>
                ${isArchive ? `
                <div style="margin-bottom: 20px; padding: 12px 18px; border-radius: 8px; background: rgba(241, 196, 15, 0.15); border: 1px solid #f1c40f; color: #f1c40f;">
                    <i class="fas fa-lock"></i>
                    Année ${matrixYear} archivée — consultation seule. Repassez sur ${CURRENT_YEAR} pour modifier des notes.
                </div>` : ''}

                <div class="quick-stats">
                    <div class="stat-item">
                        <div class="stat-value">${students.length}</div>
                        <div class="stat-label">Étudiants</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">${courses.length}</div>
                        <div class="stat-label">Cours</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">${totalGrades}</div>
                        <div class="stat-label">Notes saisies</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">${totalExpected > 0 ? ((totalGrades / totalExpected) * 100).toFixed(1) : 0}%</div>
                        <div class="stat-label">Complété</div>
                    </div>
                </div>

                <div style="overflow-x: auto; margin-top: 20px;">
                    <table class="excel-table">
                        <thead>
                            <tr>
                                <th style="min-width: 200px;">Étudiant</th>
            `;

            courses.forEach(course => {
                const numEvals = maxEvaluations[course.id];
                html += `<th colspan="${numEvals + (isArchive ? 0 : 1)}" style="background: linear-gradient(135deg, #2c3e50, #34495e); position: relative;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span>${course.name}<br><small>(Coef. ${course.coefficient})</small></span>
                                ${isArchive ? '' : `
                                <button onclick="addEvaluationColumn(${course.id}, ${evalTypeId}, ${classId})"
                                        class="btn-add-eval"
                                        title="Ajouter une nouvelle évaluation">
                                    <i class="fas fa-plus"></i> Nouvelle
                                </button>`}
                            </div>
                        </th>`;
            });

            html += `</tr><tr><th>Nom</th>`;

            courses.forEach(course => {
                const numEvals = maxEvaluations[course.id];
                for (let i = 0; i < numEvals; i++) {
                    html += `
                        <th style="min-width: 110px; background: linear-gradient(135deg, var(--accent-color), #0277bd);">
                            Éval ${i + 1}
                        </th>
                    `;
                }
                if (!isArchive) {
                    html += `
                        <th style="min-width: 110px; background: linear-gradient(135deg, rgba(46, 204, 113, 0.3), rgba(39, 174, 96, 0.3)); border: 2px dashed #2ecc71;">
                            <span style="color: #2ecc71; font-size: 11px;">Nouvelle</span>
                        </th>
                    `;
                }
            });

            html += `</tr></thead><tbody>`;

            students.forEach(student => {
                html += `<tr><td>${student.name}</td>`;
                
                courses.forEach(course => {
                    const key = `${student.id}_${course.id}`;
                    const studentGrades = grades[key] || [];
                    const numEvals = maxEvaluations[course.id];
                    
                    for (let i = 0; i < numEvals; i++) {
                        const gradeData = studentGrades[i] || null;
                        const value = gradeData ? gradeData.grade : '';
                        const hasValue = gradeData ? 'has-value' : '';
                        const gradeId = gradeData ? gradeData.id : '';
                        const comment = gradeData ? gradeData.comment : '';
                        const hasComment = comment ? 'has-comment' : '';
                        
                        html += `
                            <td>
                                <div class="grade-cell">
                                    <input type="number"
                                           class="grade-input ${hasValue}"
                                           min="0"
                                           max="20"
                                           step="0.25"
                                           value="${value}"
                                           data-student-id="${student.id}"
                                           data-course-id="${course.id}"
                                           data-eval-type-id="${evalTypeId}"
                                           data-eval-index="${i}"
                                           data-grade-id="${gradeId}"
                                           ${isArchive ? 'disabled' : `onchange="saveGrade(this)" onblur="saveGrade(this)"`}
                                           placeholder="--">
                                    <button class="comment-btn ${hasComment}"
                                            onclick="openCommentModal(this, '${comment.replace(/'/g, "&apos;")}')"
                                            title="${comment || 'Ajouter un commentaire'}"
                                            data-grade-id="${gradeId}">
                                        <i class="fas fa-comment"></i>
                                    </button>
                                    <span class="save-indicator"></span>
                                </div>
                            </td>
                        `;
                    }

                    if (!isArchive) {
                        html += `
                            <td style="background: rgba(46, 204, 113, 0.05);">
                                <div class="grade-cell">
                                    <input type="number"
                                           class="grade-input"
                                           min="0"
                                           max="20"
                                           step="0.25"
                                           value=""
                                           data-student-id="${student.id}"
                                           data-course-id="${course.id}"
                                           data-eval-type-id="${evalTypeId}"
                                           data-eval-index="${numEvals}"
                                           data-grade-id=""
                                           onchange="saveGrade(this)"
                                           onblur="saveGrade(this)"
                                           placeholder="--"
                                           style="border: 2px dashed #2ecc71;">
                                    <button class="comment-btn"
                                            onclick="openCommentModal(this, '')"
                                            title="Ajouter un commentaire"
                                            data-grade-id="">
                                        <i class="fas fa-comment"></i>
                                    </button>
                                    <span class="save-indicator"></span>
                                </div>
                            </td>
                        `;
                    }
                });
                
                html += `</tr>`;
            });

            html += `</tbody></table></div>
                    <div style="margin-top: 20px; padding: 15px; background: rgba(46, 204, 113, 0.1); border-left: 4px solid #2ecc71; border-radius: 8px;">
                        <p style="margin: 0; color: rgba(255,255,255,0.9); font-size: 14px;">
                            <strong><i class="fas fa-lightbulb"></i> Utilisation :</strong>
                        </p>
                        <ul style="margin: 10px 0 0 20px; color: rgba(255,255,255,0.8); font-size: 13px;">
                            <li>Toutes les évaluations existantes sont affichées</li>
                            <li>Cliquez sur <strong style="color: #2ecc71;">➕ Nouvelle</strong> pour ajouter une colonne d'évaluation</li>
                            <li>Les notes se sauvegardent automatiquement ✅</li>
                            <li>Utilisez l'icône <i class="fas fa-comment"></i> pour ajouter un commentaire</li>
                        </ul>
                    </div>`;
            container.innerHTML = html;
        }

        async function addEvaluationColumn(courseId, evalTypeId, classId) {
            if (!confirm('Voulez-vous ajouter une nouvelle colonne d\'évaluation pour ce cours ?\n\nTous les étudiants auront une nouvelle colonne vide à remplir.')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('course_id', courseId);
                formData.append('evaluation_type_id', evalTypeId);
                formData.append('class_id', classId);

                const response = await fetch('?action=add_evaluation_column&year=' + encodeURIComponent(currentMatrixYear), {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showAlert(`✅ ${data.message}`, 'success');
                    loadGradesTable();
                } else {
                    showAlert('❌ Erreur: ' + data.message, 'error');
                }
            } catch (error) {
                console.error('Erreur:', error);
                showAlert('❌ Erreur lors de l\'ajout de la colonne', 'error');
            }
        }

        function openCommentModal(button, currentComment) {
            const gradeInput = button.previousElementSibling;
            currentCommentInput = gradeInput;
            const modal = document.getElementById('commentModal');
            const textarea = document.getElementById('commentTextarea');
            
            const decodedComment = currentComment.replace(/&apos;/g, "'").replace(/&quot;/g, '"');
            textarea.value = decodedComment || '';
            modal.classList.add('active');
            textarea.focus();
        }

        function closeCommentModal() {
            const modal = document.getElementById('commentModal');
            modal.classList.remove('active');
            currentCommentInput = null;
        }

        async function saveComment() {
            if (!currentCommentInput) return;

            const textarea = document.getElementById('commentTextarea');
            const comment = textarea.value;
            const gradeId = currentCommentInput.dataset.gradeId;

            if (gradeId) {
                try {
                    const formData = new FormData();
                    formData.append('grade_id', gradeId);
                    formData.append('comment', comment);

                    const response = await fetch('?action=update_comment&year=' + encodeURIComponent(currentMatrixYear), {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        const commentBtn = currentCommentInput.nextElementSibling;
                        commentBtn.setAttribute('title', comment || 'Ajouter un commentaire');
                        commentBtn.setAttribute('onclick', `openCommentModal(this, '${comment.replace(/'/g, "&apos;")}')`);
                        if (comment) {
                            commentBtn.classList.add('has-comment');
                        } else {
                            commentBtn.classList.remove('has-comment');
                        }
                        closeCommentModal();
                        showAlert('Commentaire enregistré', 'success');
                    } else {
                        showAlert('Erreur: ' + data.message, 'error');
                    }
                } catch (error) {
                    console.error('Erreur:', error);
                    showAlert('Erreur lors de la sauvegarde du commentaire', 'error');
                }
            } else {
                currentCommentInput.dataset.pendingComment = comment;
                await saveGrade(currentCommentInput);
                closeCommentModal();
            }
        }

        document.getElementById('commentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCommentModal();
            }
        });

        async function saveGrade(input) {
            const value = parseFloat(input.value);
            
            if (input.value === '' || input.value === null) {
                input.classList.remove('has-value', 'error');
                return;
            }

            if (isNaN(value) || value < 0 || value > 20) {
                input.classList.add('error');
                input.classList.remove('has-value');
                showAlert('La note doit être comprise entre 0 et 20', 'error');
                return;
            }

            input.classList.remove('error');
            
            const indicator = input.nextElementSibling.nextElementSibling;
            indicator.className = 'save-indicator saving';
            indicator.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            const formData = new FormData();
            formData.append('student_id', input.dataset.studentId);
            formData.append('course_id', input.dataset.courseId);
            formData.append('evaluation_type_id', input.dataset.evalTypeId);
            formData.append('eval_index', input.dataset.evalIndex);
            formData.append('grade', value);
            formData.append('grade_id', input.dataset.gradeId || '');
            
            const pendingComment = input.dataset.pendingComment || '';
            formData.append('comment', pendingComment);

            try {
                const response = await fetch('?action=save_grade&year=' + encodeURIComponent(currentMatrixYear), {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    input.classList.add('has-value');
                    indicator.className = 'save-indicator saved';
                    indicator.innerHTML = '<i class="fas fa-check"></i>';
                    
                    if (data.grade_id) {
                        input.dataset.gradeId = data.grade_id;
                        const commentBtn = input.nextElementSibling;
                        commentBtn.dataset.gradeId = data.grade_id;
                    }

                    if (pendingComment) {
                        const commentBtn = input.nextElementSibling;
                        commentBtn.classList.add('has-comment');
                        commentBtn.setAttribute('title', pendingComment);
                        commentBtn.setAttribute('onclick', `openCommentModal(this, '${pendingComment.replace(/'/g, "&apos;")}')`);
                        delete input.dataset.pendingComment;
                    }

                    setTimeout(() => {
                        indicator.innerHTML = '';
                        indicator.className = 'save-indicator';
                    }, 2000);
                } else {
                    throw new Error(data.message || 'Erreur inconnue');
                }
            } catch (error) {
                console.error('Erreur:', error);
                input.classList.add('error');
                indicator.className = 'save-indicator';
                indicator.innerHTML = '<i class="fas fa-times" style="color: #e74c3c;"></i>';
                showAlert('Erreur lors de la sauvegarde: ' + error.message, 'error');
            }
        }

        function showAlert(message, type) {
            const alertContainer = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = `alert ${type}`;
            alert.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            alertContainer.appendChild(alert);
            
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-20px)';
                setTimeout(() => alert.remove(), 300);
            }, 4000);
        }
    </script>
</body>
</html>