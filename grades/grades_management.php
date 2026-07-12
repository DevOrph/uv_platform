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

// Fonction pour récupérer TOUTES les notes d'une classe (matrice de saisie)
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

                if ($evaluation_type_id == 2 && !canAddExamGrade($conn, $user_id, $user_role)) {
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

                if ($evaluation_type_id == 2 && !canAddExamGrade($conn, $user_id, $user_role)) {
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
                    // Numérotation limitée à l'année courante
                    $year_scope = empty($current_year_period_ids) ? ''
                        : ' AND evaluation_period_id IN (' . implode(',', $current_year_period_ids) . ')';
                    $first_student = $students[0]['id'];
                    $count_query = "SELECT COUNT(*) as count FROM grades
                                   WHERE student_id = ? AND course_id = ? AND evaluation_type_id = ?" . $year_scope;
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
        /* ── Styles matrice de saisie (transplantés de grades_table_view.php) ── */
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

        <div id="alertContainer"></div>

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

        <!-- Filtres de la matrice de saisie -->
        <div class="grade-form">
            <h2><i class="fas fa-filter"></i> Sélection</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label for="class">
                        <i class="fas fa-users"></i>
                        Classe
                    </label>
                    <select id="class" onchange="checkLoadButton()">
                        <option value="">Sélectionner une classe</option>
                        <?php while ($class = $classes_result->fetch_assoc()): ?>
                            <option value="<?php echo $class['id']; ?>">
                                <?php echo htmlspecialchars($class['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="evalType">
                        <i class="fas fa-clipboard-list"></i>
                        Type d'évaluation
                    </label>
                    <select id="evalType" onchange="checkLoadButton()">
                        <option value="">Sélectionner un type</option>
                        <?php while ($type = $evaluation_types_result->fetch_assoc()): ?>
                            <option value="<?php echo $type['id']; ?>"
                                    <?php echo ($type['id'] == 2 && !$can_add_exam_grades) ? 'disabled' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                                <?php echo ($type['id'] == 2 && !$can_add_exam_grades) ? ' (Non autorisé)' : ''; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
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
            <p style="margin: 10px 0 0; color: rgba(255,255,255,0.6); font-size: 13px;">
                <i class="fas fa-info-circle"></i>
                Le tableau se charge automatiquement dès que classe et type sont sélectionnés.
            </p>
        </div>

        <!-- Matrice de saisie des notes -->
        <div class="excel-table-container" id="tableContainer" style="display: none;"></div>

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
                loadRecentGrades(classId);
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
                    // Recharger la matrice (qui recharge aussi les notes récentes)
                    const classId = document.getElementById('class').value;
                    const evalTypeId = document.getElementById('evalType').value;
                    if (classId && evalTypeId) {
                        loadGradesTable();
                    } else if (classId) {
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

    </script>
</body>
    <?php include '../includes/footer.php'; ?>

</html>