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

// MODIFICATION 1: Fonction pour récupérer les notes avec eval_number
function getGradesByClass($conn, $class_id, $evaluation_type_id = null, $period_ids = null) {
    $grades = [];

    try {
        // Vérifier si la colonne eval_number existe
        $check_column = $conn->query("SHOW COLUMNS FROM grades LIKE 'eval_number'");
        $has_eval_number = $check_column->num_rows > 0;

        if ($has_eval_number) {
            $query = "SELECT g.*, u.id as student_id, c.id as course_id,
                             COALESCE(g.eval_number, 1) as eval_number
                      FROM grades g
                      JOIN users u ON g.student_id = u.id
                      JOIN courses c ON g.course_id = c.id
                      WHERE u.class_id = ?";
        } else {
            $query = "SELECT g.*, u.id as student_id, c.id as course_id
                      FROM grades g
                      JOIN users u ON g.student_id = u.id
                      JOIN courses c ON g.course_id = c.id
                      WHERE u.class_id = ?";
        }

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
        
        if ($has_eval_number) {
            $query .= " ORDER BY g.eval_number ASC, g.id ASC";
        } else {
            $query .= " ORDER BY g.id ASC";
        }
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $key = $row['student_id'] . '_' . $row['course_id'];
            if (!isset($grades[$key])) {
                $grades[$key] = [];
            }
            
            if ($has_eval_number) {
                // Utiliser eval_number comme index pour préserver les trous
                $eval_index = intval($row['eval_number']) - 1; // Convertir en index 0-based
                $grades[$key][$eval_index] = [
                    'id' => $row['id'],
                    'grade' => $row['grade'],
                    'comment' => $row['comment'],
                    'created_at' => $row['created_at'],
                    'eval_number' => $row['eval_number']
                ];
            } else {
                // Ancien système sans eval_number
                $grades[$key][] = [
                    'id' => $row['id'],
                    'grade' => $row['grade'],
                    'comment' => $row['comment'],
                    'created_at' => $row['created_at']
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Erreur getGradesByClass: " . $e->getMessage());
    }
    
    return $grades;
}

// MODIFICATION 2: Fonction pour obtenir le nombre maximum d'évaluations par cours
function getMaxEvaluationsPerCourse($grades, $courses, $students) {
    $maxEvals = [];
    
    foreach ($courses as $course) {
        $maxCount = 0;
        foreach ($students as $student) {
            $key = $student['id'] . '_' . $course['id'];
            if (isset($grades[$key])) {
                // Trouver le plus grand numéro d'évaluation
                $evalNumbers = array_keys($grades[$key]);
                if (!empty($evalNumbers)) {
                    $maxEval = max($evalNumbers) + 1; // +1 car c'est un index 0-based
                    if ($maxEval > $maxCount) {
                        $maxCount = $maxEval;
                    }
                }
            }
        }
        $maxEvals[$course['id']] = $maxCount;
    }
    
    return $maxEvals;
}

// Traitement AJAX
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // Année archivée = lecture seule : aucune écriture (ajout, modification,
    // suppression, colonnes) n'est autorisée hors année courante.
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
            
        // MODIFICATION 3: case 'get_class_matrix'
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

                // Vérifier si eval_number existe
                $check_column = $conn->query("SHOW COLUMNS FROM grades LIKE 'eval_number'");
                $has_eval_number = $check_column->num_rows > 0;

                echo json_encode([
                    'students' => $students,
                    'courses' => $courses,
                    'grades' => $grades,
                    'maxEvaluations' => $maxEvals,
                    'has_eval_number' => $has_eval_number,
                    'year' => $selected_year,
                    'is_archive' => $is_archive_year
                ]);
            } else {
                echo json_encode(['error' => 'Paramètres manquants']);
            }
            exit();
            
        // MODIFICATION 4: case 'save_grade'
        case 'save_grade':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $student_id = $_POST['student_id'];
                $course_id = intval($_POST['course_id']);
                $evaluation_type_id = intval($_POST['evaluation_type_id']);
                $grade = floatval($_POST['grade']);
                $comment = $_POST['comment'] ?? '';
                $eval_number = intval($_POST['eval_number']); // IMPORTANT: Numéro d'évaluation spécifique
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

                // Période réelle de l'année courante pour ce semestre
                // (avant : le n° de semestre servait d'id de période → notes
                // rattachées à la mauvaise année académique)
                $target_period_id = get_period_id_for($conn, (int) $semester, $current_year)
                    ?? (int) $semester;
                
                // Vérifier si eval_number existe
                $check_column = $conn->query("SHOW COLUMNS FROM grades LIKE 'eval_number'");
                $has_eval_number = $check_column->num_rows > 0;
                
                if ($grade_id) {
                    // Verrou : un enseignant ne modifie plus une note trop ancienne
                    if ($user_role === 'teacher' && grade_is_locked($conn, $grade_id, $user_role)) {
                        echo json_encode(['success' => false, 'message' => grade_lock_message($conn)]);
                        exit();
                    }
                    // Mise à jour d'une note existante
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
                    if ($has_eval_number) {
                        // Vérifier si une note existe déjà pour cet étudiant, cours, type d'évaluation ET numéro d'évaluation
                        // — uniquement dans l'année courante, pour ne jamais écraser une note archivée
                        $year_scope = empty($current_year_period_ids) ? ''
                            : ' AND evaluation_period_id IN (' . implode(',', $current_year_period_ids) . ')';
                        $check_query = "SELECT id FROM grades
                                       WHERE student_id = ? AND course_id = ?
                                       AND evaluation_type_id = ? AND eval_number = ?" . $year_scope;
                        $check_stmt = $conn->prepare($check_query);
                        $check_stmt->bind_param("siii", $student_id, $course_id, $evaluation_type_id, $eval_number);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows > 0) {
                            // Mettre à jour la note existante
                            $existing = $check_result->fetch_assoc();
                            // Verrou : un enseignant ne modifie plus une note trop ancienne
                            if ($user_role === 'teacher' && grade_is_locked($conn, (int) $existing['id'], $user_role)) {
                                echo json_encode(['success' => false, 'message' => grade_lock_message($conn)]);
                                exit();
                            }
                            $update_query = "UPDATE grades SET grade = ?, comment = ?, updated_at = NOW(), updated_by = ?
                                           WHERE id = ?";
                            $stmt = $conn->prepare($update_query);
                            $stmt->bind_param("dssi", $grade, $comment, $user_id, $existing['id']);
                            
                            if ($stmt->execute()) {
                                echo json_encode(['success' => true, 'message' => 'Note mise à jour', 'grade_id' => $existing['id']]);
                            } else {
                                echo json_encode(['success' => false, 'message' => 'Erreur mise à jour']);
                            }
                        } else {
                            // Insérer une nouvelle note avec le numéro d'évaluation spécifique
                            $insert_query = "INSERT INTO grades 
                                            (student_id, course_id, evaluation_type_id, grade, comment, evaluation_period_id, created_by, eval_number) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt = $conn->prepare($insert_query);
                            $stmt->bind_param("siidsisi", $student_id, $course_id, $evaluation_type_id, $grade, $comment, $target_period_id, $user_id, $eval_number);

                            if ($stmt->execute()) {
                                echo json_encode(['success' => true, 'message' => 'Note ajoutée', 'grade_id' => $conn->insert_id]);
                            } else {
                                echo json_encode(['success' => false, 'message' => 'Erreur ajout: ' . $stmt->error]);
                            }
                        }
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Veuillez exécuter: ALTER TABLE grades ADD COLUMN eval_number INT DEFAULT 1;']);
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
            
        // MODIFICATION 5: case 'delete_evaluation_column'
        case 'delete_evaluation_column':
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['course_id']) && isset($_POST['evaluation_type_id']) && isset($_POST['eval_number'])) {
                $course_id = intval($_POST['course_id']);
                $evaluation_type_id = intval($_POST['evaluation_type_id']);
                $eval_number = intval($_POST['eval_number']);
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
                
                // Verrou : refuser la suppression de colonne si une note verrouillée s'y trouve
                if ($user_role === 'teacher') {
                    $nb_locked = grade_count_locked_in_column($conn, $course_id, $evaluation_type_id, $eval_number, $class_id, $user_role);
                    if ($nb_locked > 0) {
                        echo json_encode(['success' => false, 'message' => "$nb_locked note(s) de cette colonne sont verrouillées (saisies il y a plus de " . grade_lock_days($conn) . " jours). Contactez l'administration."]);
                        exit();
                    }
                }

                // Supprimer toutes les notes pour ce cours, ce type d'évaluation et ce numéro d'évaluation
                $delete_query = "DELETE FROM grades
                                WHERE course_id = ?
                                AND evaluation_type_id = ?
                                AND eval_number = ?
                                AND student_id IN (SELECT id FROM users WHERE class_id = ?)";
                $stmt = $conn->prepare($delete_query);
                $stmt->bind_param("iiii", $course_id, $evaluation_type_id, $eval_number, $class_id);
                
                if ($stmt->execute()) {
                    $deleted_count = $stmt->affected_rows;
                    echo json_encode([
                        'success' => true, 
                        'message' => "Colonne supprimée avec succès. $deleted_count note(s) supprimée(s)."
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Erreur lors de la suppression']);
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
                $max_eval = 0;
                
                // Numérotation limitée à l'année courante : les évaluations
                // repartent de 1 à chaque année académique
                $year_scope = empty($current_year_period_ids) ? ''
                    : ' AND evaluation_period_id IN (' . implode(',', $current_year_period_ids) . ')';
                foreach ($students as $student) {
                    $query = "SELECT MAX(eval_number) as max_eval FROM grades
                             WHERE student_id = ? AND course_id = ? AND evaluation_type_id = ?" . $year_scope;
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("sii", $student['id'], $course_id, $evaluation_type_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    
                    if ($row['max_eval'] > $max_eval) {
                        $max_eval = intval($row['max_eval']);
                    }
                }
                
                $new_eval_number = $max_eval + 1;
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Nouvelle colonne d'évaluation #{$new_eval_number} prête. Vous pouvez maintenant saisir les notes.",
                    'eval_number' => $new_eval_number
                ]);
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

// Paramètres de pagination
$perPage = 25;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

// Récupération des grades avec pagination
$grades_query = "
    SELECT g.*, 
           u.name AS student_name, 
           et.name AS evaluation_type,
           et.id AS evaluation_type_id,
           cl.name AS class_name,
           c.name AS course_name
    FROM grades g
    JOIN users u ON g.student_id = u.id
    JOIN evaluation_types et ON g.evaluation_type_id = et.id
    JOIN courses c ON g.course_id = c.id
    LEFT JOIN classes cl ON cl.id = u.class_id
    WHERE g.created_by = ?
    ORDER BY g.created_at DESC
    LIMIT ?, ?
";
$stmt = $conn->prepare($grades_query);
$stmt->bind_param("sii", $user_id, $offset, $perPage);
$stmt->execute();
$grades = $stmt->get_result();

// MODIFICATION 7: Gestion des soumissions du formulaire traditionnel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_grade'])) {
    $student_id = $_POST['student_id'];
    $course_id = $_POST['course_id'];
    $evaluation_type = $_POST['evaluation_type'];
    $grade = $_POST['grade'];
    $comment = $_POST['comment'] ?? '';

    if ($grade < 0 || $grade > 20) {
        $_SESSION['error_message'] = "La note doit être comprise entre 0 et 20.";
        header("Location: grades_management.php");
        exit();
    }

    if ($evaluation_type == 2 && !$can_add_exam_grades) {
        $_SESSION['error_message'] = "Vous n'avez pas l'autorisation d'ajouter des notes d'examen.";
        header("Location: grades_management.php");
        exit();
    }

    if ($user_role === 'teacher') {
        $course_check = "SELECT id, semester FROM courses WHERE id = ? AND teacher_id = ?";
        $stmt = $conn->prepare($course_check);
        $stmt->bind_param("is", $course_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            $_SESSION['error_message'] = "Vous ne pouvez ajouter des notes que pour vos propres cours.";
            header("Location: grades_management.php");
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

    // Période réelle de l'année courante pour ce semestre
    // (avant : le n° de semestre servait d'id de période → notes
    // rattachées à la mauvaise année académique)
    $target_period_id = get_period_id_for($conn, (int) $semester, $current_year)
        ?? (int) $semester;

    if ($evaluation_type == 2) {
        // Unicité de l'examen limitée à l'année courante : un examen
        // d'une année archivée ne doit pas bloquer la saisie
        $year_scope = empty($current_year_period_ids) ? ''
            : ' AND g.evaluation_period_id IN (' . implode(',', $current_year_period_ids) . ')';
        $check_query = "
            SELECT g.id
            FROM grades g
            JOIN courses c ON g.course_id = c.id
            WHERE g.student_id = ?
            AND g.course_id = ?
            AND g.evaluation_type_id = 2
            AND c.semester = ?" . $year_scope;
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("sii", $student_id, $course_id, $semester);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $_SESSION['error_message'] = "Une note d'examen existe déjà pour ce cours au semestre " . $semester . ".";
            header("Location: grades_management.php");
            exit();
        }
    }

    // Trouver le prochain numéro d'évaluation (année courante uniquement)
    $max_query = "SELECT MAX(eval_number) as max_eval FROM grades
                  WHERE student_id = ? AND course_id = ? AND evaluation_type_id = ?"
        . (empty($current_year_period_ids) ? ''
            : ' AND evaluation_period_id IN (' . implode(',', $current_year_period_ids) . ')');
    $max_stmt = $conn->prepare($max_query);
    $max_stmt->bind_param("sii", $student_id, $course_id, $evaluation_type);
    $max_stmt->execute();
    $max_result = $max_stmt->get_result();
    $max_row = $max_result->fetch_assoc();
    $eval_number = ($max_row['max_eval'] ?? 0) + 1;

    // MODIFIÉ: INSERT avec eval_number
    $insert_query = "INSERT INTO grades 
                    (student_id, course_id, evaluation_type_id, grade, comment, evaluation_period_id, created_by, eval_number) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($insert_query);
    $stmt->bind_param("siidsisi", $student_id, $course_id, $evaluation_type, $grade, $comment, $target_period_id, $user_id, $eval_number);

    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Note ajoutée avec succès.";
    } else {
        $_SESSION['error_message'] = "Erreur lors de l'ajout de la note.";
    }

    header("Location: grades_management.php");
    exit();
}

// Récupération du nombre total de notes
$total_grades_query = "
    SELECT COUNT(*) AS total
    FROM grades g
    WHERE g.created_by = ?
";
$stmt = $conn->prepare($total_grades_query);
$stmt->bind_param("s", $user_id);
$stmt->execute();
$total_grades_result = $stmt->get_result();
$total_grades = $total_grades_result->fetch_assoc()['total'];

$total_pages = ceil($total_grades / $perPage);

$pagination = '';
for ($i = 1; $i <= $total_pages; $i++) {
    $active_class = ($i == $page) ? 'active' : '';
    $pagination .= "<a href='grades_management.php?page=$i' class='pagination-link $active_class'>$i</a> ";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Notes - UV Platform</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #4CAF50;
            --warning-color: #ff9800;
            --error-color: #f44336;
        }

        body {
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            color: #ffffff;
            font-family: 'Google Sans', Arial, sans-serif;
            min-height: 100vh;
        }

        .container {
            /* Pleine largeur : la matrice de saisie a besoin de tout l'écran */
            max-width: none;
            margin: 0 auto;
            padding: 20px 25px;
        }

        /* Boutons de basculement de vue */
        .view-switcher {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }

        .view-btn {
            flex: 1;
            padding: 15px 25px;
            border: 2px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.05);
            color: white;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 600;
        }

        .view-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            border-color: var(--accent-color);
            transform: translateY(-2px);
        }

        .view-btn.active {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            border-color: var(--accent-color);
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.4);
        }

        /* Vue traditionnelle */
        .traditional-view {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 25px;
        }

        .traditional-view.hidden {
            display: none;
        }

        /* Vue tableau Excel */
        .excel-view {
            display: none;
        }

        .excel-view.active {
            display: block;
        }

        .excel-controls {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .excel-table-container {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            overflow-x: auto;
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
        }

        .excel-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.08);
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
            background: rgba(46, 204, 113, 0.2);
            border-color: #2ecc71;
        }

        .grade-input.error {
            background: rgba(231, 76, 60, 0.2);
            border-color: #e74c3c;
        }

        .comment-btn {
            padding: 4px 8px;
            background: rgba(52, 152, 219, 0.3);
            border: 1px solid #3498db;
            border-radius: 4px;
            cursor: pointer;
            color: #3498db;
            font-size: 11px;
            margin-left: 3px;
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

        /* Modal pour les commentaires */
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

        .grade-cell {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
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

        /* Bouton d'ajout d'évaluation */
        .btn-add-eval {
            transition: all 0.3s ease;
        }

        .btn-add-eval:hover {
            background: rgba(46, 204, 113, 0.5) !important;
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(46, 204, 113, 0.4);
        }

        .btn-add-eval:active {
            transform: scale(0.98);
        }

        /* Statistiques */
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

        /* Styles traditionnels conservés */
        .sidebar {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            height: fit-content;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .main-content {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .permission-banner {
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
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

        .grade-form h2 {
            color: var(--accent-color);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #039be5;
            box-shadow: 0 0 0 3px rgba(3, 155, 229, 0.2);
            outline: none;
        }

        .form-group select option {
            background: var(--secondary-bg);
            color: white;
        }

        .btn-submit {
            background: linear-gradient(135deg, #039be5, #0277bd);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            width: 100%;
            justify-content: center;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.4);
        }

        .grades-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .grades-table th,
        .grades-table td {
            padding: 15px 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: left;
        }

        .grades-table th {
            background: rgba(255, 255, 255, 0.1);
            cursor: pointer;
            position: relative;
            transition: background 0.3s ease;
            font-weight: 600;
            color: var(--accent-color);
        }

        .grade-value {
            font-weight: bold;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 14px;
        }

        .grade-good { background: rgba(46, 204, 113, 0.2); color: #2ecc71; }
        .grade-average { background: rgba(241, 196, 15, 0.2); color: #f1c40f; }
        .grade-poor { background: rgba(231, 76, 60, 0.2); color: #e74c3c; }

        .notification {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .notification.success {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
            border-left: 4px solid #2ecc71;
        }

        .notification.error {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }

        .pagination {
            margin-top: 25px;
            text-align: center;
        }

        .pagination-link {
            margin: 0 5px;
            padding: 10px 15px;
            text-decoration: none;
            background-color: #039be5;
            color: white;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .pagination-link:hover {
            background-color: #0277bd;
            transform: translateY(-1px);
        }

        .pagination-link.active {
            background-color: #4CAF50;
        }

        .btn-delete, .btn-edit {
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 0 2px;
        }

        .btn-delete {
            background: #e74c3c;
            color: white;
        }

        .btn-delete:hover {
            background: #c0392b;
        }

        .btn-edit {
            background: #f39c12;
            color: white;
        }

        .btn-edit:hover {
            background: #e67e22;
        }

        .exam-restriction-notice {
            background: linear-gradient(135deg, #ff9800, #f57c00);
            color: white;
            padding: 12px 15px;
            border-radius: 8px;
            font-size: 14px;
            margin-top: 10px;
            display: none;
            align-items: center;
            gap: 8px;
        }

        .exam-restriction-notice.show {
            display: flex;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: rgba(255, 255, 255, 0.7);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 20px;
            color: var(--accent-color);
        }

        @media (max-width: 768px) {
            .traditional-view {
                grid-template-columns: 1fr;
            }
            
            .view-switcher {
                flex-direction: column;
            }

            .excel-controls {
                grid-template-columns: 1fr;
            }
        }
        /* AJOUTEZ CES MEDIA QUERIES À LA FIN DE VOTRE SECTION <style> EXISTANTE */

/* ========== RESPONSIVE DESIGN ========== */

/* Tablettes et grands mobiles (portrait) */
@media (max-width: 1024px) {
    .container {
        padding: 15px;
    }

    .traditional-view {
        grid-template-columns: 1fr;
        gap: 20px;
    }

    .sidebar {
        order: 1;
    }

    .main-content {
        order: 2;
    }

    .excel-controls {
        grid-template-columns: 1fr;
    }

    .view-switcher {
        gap: 10px;
    }

    .view-btn {
        font-size: 14px;
        padding: 12px 20px;
    }

    .permission-banner {
        flex-direction: column;
        text-align: center;
        padding: 15px;
    }

    .quick-stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Tablettes et mobiles */
@media (max-width: 768px) {
    body {
        font-size: 14px;
    }

    .container {
        padding: 10px;
    }

    /* View Switcher */
    .view-switcher {
        flex-direction: column;
        gap: 10px;
    }

    .view-btn {
        width: 100%;
        padding: 15px;
        font-size: 15px;
    }

    /* Permission Banner */
    .permission-banner {
        padding: 12px;
        font-size: 13px;
    }

    .permission-banner i {
        font-size: 20px;
    }

    /* Sidebar & Main Content */
    .sidebar,
    .main-content {
        padding: 15px;
        border-radius: 10px;
    }

    .sidebar h2,
    .main-content h2 {
        font-size: 18px;
        margin-bottom: 15px;
    }

    /* Form Groups */
    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        font-size: 14px;
        margin-bottom: 6px;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        padding: 10px;
        font-size: 14px;
    }

    .btn-submit {
        padding: 12px 20px;
        font-size: 15px;
    }

    /* Excel Controls */
    .excel-controls {
        padding: 15px;
        gap: 15px;
    }

    /* Excel Table Container */
    .excel-table-container {
        padding: 15px;
    }

    .excel-table {
        font-size: 12px;
    }

    .excel-table th,
    .excel-table td {
        padding: 8px 6px;
    }

    .excel-table th:first-child,
    .excel-table td:first-child {
        min-width: 150px;
        font-size: 12px;
        padding-left: 10px;
    }

    .grade-input {
        width: 60px;
        padding: 5px;
        font-size: 12px;
    }

    .comment-btn {
        padding: 3px 6px;
        font-size: 10px;
    }

    /* Buttons */
    .btn-add-eval {
        padding: 4px 8px !important;
        font-size: 10px !important;
    }

    .btn-delete-column {
        padding: 3px 6px;
        font-size: 10px;
    }

    /* Quick Stats */
    .quick-stats {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        padding: 10px;
    }

    .stat-value {
        font-size: 20px;
    }

    .stat-label {
        font-size: 11px;
    }

    /* Grades Table (Traditional View) */
    .grades-table {
        font-size: 12px;
    }

    .grades-table th,
    .grades-table td {
        padding: 10px 8px;
    }

    .grade-value {
        font-size: 12px;
        padding: 4px 8px;
    }

    .btn-delete,
    .btn-edit {
        padding: 6px 10px;
        font-size: 12px;
        margin: 2px 0;
    }

    /* Modal */
    .modal-content {
        width: 95%;
        padding: 20px;
    }

    .modal-header h3 {
        font-size: 18px;
    }

    .modal-body textarea {
        min-height: 100px;
        font-size: 14px;
    }

    .modal-btn {
        padding: 10px 15px;
        font-size: 14px;
    }

    /* Notifications */
    .notification {
        padding: 12px;
        font-size: 13px;
    }

    /* Pagination */
    .pagination-link {
        padding: 8px 12px;
        font-size: 13px;
        margin: 3px;
    }

    /* Empty State */
    .empty-state {
        padding: 30px 15px;
    }

    .empty-state i {
        font-size: 36px;
    }

    .empty-state h3 {
        font-size: 18px;
    }

    .empty-state p {
        font-size: 14px;
    }

    /* Exam Restriction Notice */
    .exam-restriction-notice {
        font-size: 12px;
        padding: 10px 12px;
    }
}

/* Petits mobiles */
@media (max-width: 480px) {
    .container {
        padding: 8px;
    }

    /* View Switcher */
    .view-btn {
        padding: 12px;
        font-size: 14px;
        flex-direction: column;
        gap: 5px;
    }

    .view-btn i {
        font-size: 18px;
    }

    /* Permission Banner */
    .permission-banner {
        padding: 10px;
        font-size: 12px;
    }

    .permission-banner strong {
        font-size: 14px;
    }

    .permission-banner p {
        font-size: 11px;
        margin-top: 5px;
    }

    /* Sidebar & Main Content */
    .sidebar,
    .main-content {
        padding: 12px;
    }

    .sidebar h2,
    .main-content h2 {
        font-size: 16px;
        flex-wrap: wrap;
    }

    /* Form */
    .form-group label {
        font-size: 13px;
        flex-wrap: wrap;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        padding: 8px;
        font-size: 13px;
    }

    .btn-submit {
        padding: 10px 15px;
        font-size: 14px;
    }

    /* Excel Controls */
    .excel-controls {
        padding: 12px;
    }

    .excel-controls h2 {
        font-size: 16px;
    }

    /* Excel Table */
    .excel-table-container {
        padding: 10px;
    }

    .excel-table {
        font-size: 11px;
        min-width: 600px;
    }

    .excel-table th,
    .excel-table td {
        padding: 6px 4px;
    }

    .excel-table th:first-child,
    .excel-table td:first-child {
        min-width: 120px;
        font-size: 11px;
        padding-left: 8px;
    }

    .excel-table th {
        font-size: 11px;
    }

    .grade-input {
        width: 50px;
        padding: 4px;
        font-size: 11px;
    }

    .comment-btn {
        padding: 2px 5px;
        font-size: 9px;
    }

    .save-indicator {
        font-size: 10px;
    }

    /* Buttons */
    .btn-add-eval {
        display: none; /* Masquer le texte sur très petit écran */
    }

    .btn-add-eval i {
        margin: 0 !important;
    }

    /* Stats */
    .quick-stats {
        grid-template-columns: 2fr 2fr;
        gap: 8px;
        padding: 8px;
        font-size: 11px;
    }

    .stat-value {
        font-size: 18px;
    }

    .stat-label {
        font-size: 10px;
    }

    /* Grades Table */
    .grades-table {
        font-size: 11px;
        display: block;
        overflow-x: auto;
    }

    .grades-table thead,
    .grades-table tbody,
    .grades-table th,
    .grades-table td,
    .grades-table tr {
        display: block;
    }

    .grades-table thead tr {
        display: none; /* Masquer les en-têtes sur mobile */
    }

    .grades-table tr {
        margin-bottom: 15px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 8px;
        padding: 10px;
        background: rgba(255, 255, 255, 0.05);
    }

    .grades-table td {
        border: none;
        position: relative;
        padding-left: 50%;
        text-align: right;
        padding-top: 8px;
        padding-bottom: 8px;
    }

    .grades-table td::before {
        content: attr(data-label);
        position: absolute;
        left: 10px;
        width: 45%;
        padding-right: 10px;
        white-space: nowrap;
        text-align: left;
        font-weight: bold;
        color: var(--accent-color);
    }

    /* Ajouter des labels via JavaScript ou modifier le HTML */
    .grades-table td:nth-of-type(1)::before { content: "Étudiant"; }
    .grades-table td:nth-of-type(2)::before { content: "Type"; }
    .grades-table td:nth-of-type(3)::before { content: "Note"; }
    .grades-table td:nth-of-type(4)::before { content: "Classe"; }
    .grades-table td:nth-of-type(5)::before { content: "Cours"; }
    .grades-table td:nth-of-type(6)::before { content: "Date"; }
    .grades-table td:nth-of-type(7)::before { content: "Commentaire"; }
    .grades-table td:nth-of-type(8)::before { content: "Actions"; }

    .grade-value {
        font-size: 11px;
        padding: 3px 6px;
    }

    .btn-delete,
    .btn-edit {
        padding: 5px 8px;
        font-size: 11px;
        display: inline-block;
        margin: 2px;
    }

    /* Modal */
    .modal-content {
        width: 98%;
        padding: 15px;
    }

    .modal-header h3 {
        font-size: 16px;
    }

    .modal-close {
        width: 25px;
        height: 25px;
        font-size: 20px;
    }

    .modal-body textarea {
        min-height: 80px;
        font-size: 13px;
        padding: 10px;
    }

    .modal-footer {
        flex-direction: column;
        gap: 8px;
    }

    .modal-btn {
        width: 100%;
        padding: 10px;
        font-size: 13px;
    }

    /* Notifications */
    .notification {
        padding: 10px;
        font-size: 12px;
        flex-direction: column;
        text-align: center;
    }

    /* Pagination */
    .pagination {
        margin-top: 15px;
    }

    .pagination-link {
        padding: 6px 10px;
        font-size: 12px;
        margin: 2px;
        display: inline-block;
    }

    /* Empty State */
    .empty-state {
        padding: 25px 10px;
    }

    .empty-state i {
        font-size: 32px;
    }

    .empty-state h3 {
        font-size: 16px;
    }

    .empty-state p {
        font-size: 13px;
    }

    /* Info box dans excel-table-container */
    .excel-table-container div[style*="margin-top: 20px"] {
        font-size: 12px !important;
        padding: 10px !important;
    }

    .excel-table-container ul {
        font-size: 11px !important;
        margin: 8px 0 0 15px !important;
    }

    .excel-table-container ul li {
        margin-bottom: 5px;
    }

    /* Exam restriction notice */
    .exam-restriction-notice {
        font-size: 11px;
        padding: 8px 10px;
    }
}

/* Très petits écrans (≤ 360px) */
@media (max-width: 360px) {
    .container {
        padding: 5px;
    }

    .view-btn {
        font-size: 13px;
        padding: 10px;
    }

    .sidebar,
    .main-content,
    .excel-controls,
    .excel-table-container {
        padding: 10px;
    }

    .excel-table {
        min-width: 500px;
    }

    .excel-table th:first-child,
    .excel-table td:first-child {
        min-width: 100px;
        font-size: 10px;
    }

    .grade-input {
        width: 45px;
        font-size: 10px;
    }

    .quick-stats {
        grid-template-columns: 1fr 1fr;
    }

    .stat-value {
        font-size: 16px;
    }

    .stat-label {
        font-size: 9px;
    }
}

/* Mode paysage mobile */
@media (max-width: 768px) and (orientation: landscape) {
    .permission-banner {
        padding: 10px;
    }

    .view-switcher {
        flex-direction: row;
    }

    .quick-stats {
        grid-template-columns: repeat(4, 1fr);
    }

    .modal-content {
        max-height: 90vh;
        overflow-y: auto;
    }
}

/* Optimisations pour les écrans tactiles */
@media (hover: none) and (pointer: coarse) {
    .grade-input,
    .comment-btn,
    .btn-add-eval,
    .btn-delete,
    .btn-edit,
    .view-btn,
    .btn-submit,
    .modal-btn,
    .pagination-link {
        min-height: 44px; /* Taille minimale recommandée pour les boutons tactiles */
        min-width: 44px;
    }

    .grade-input {
        font-size: 16px; /* Empêche le zoom sur iOS */
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        font-size: 16px; /* Empêche le zoom sur iOS */
    }
}

/* Print styles */
@media print {
    .sidebar,
    .view-switcher,
    .permission-banner,
    .btn-add-eval,
    .btn-delete,
    .btn-edit,
    .comment-btn,
    .modal-overlay,
    .pagination,
    .notification {
        display: none !important;
    }

    body {
        background: white;
        color: black;
    }

    .excel-table,
    .grades-table {
        border: 1px solid black;
    }

    .excel-table th,
    .excel-table td,
    .grades-table th,
    .grades-table td {
        border: 1px solid black;
        color: black;
    }
}
    </style>

</head>
<body>
<?php include '../includes/header_discussion.php'; ?>

    <div class="container">
        <!-- Bannière des permissions -->
        <div class="permission-banner <?php echo $can_add_exam_grades ? 'has-exam-permission' : 'no-exam-permission'; ?>">
            <i class="fas <?php echo $can_add_exam_grades ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
            <div>
                <strong>
                    <?php if ($can_add_exam_grades): ?>
                        Permissions d'examen accordées
                    <?php else: ?>
                        Permissions limitées
                    <?php endif; ?>
                </strong>
                <p>
                    <?php if ($can_add_exam_grades): ?>
                        Vous avez l'autorisation d'ajouter des notes d'examen
                    <?php else: ?>
                        Vous ne pouvez ajouter que des notes de devoirs, contrôles continus et TP
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Vue Traditionnelle (désactivée : la vue tableau est la seule utilisée) -->
        <div class="traditional-view" id="traditionalView" style="display: none;">
            <div class="sidebar">
                <h2><i class="fas fa-plus-circle"></i> Ajouter une note</h2>
                <form method="post" class="grade-form" id="gradeForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                    <div class="form-group">
                        <label><i class="fas fa-users"></i> Classe</label>
                        <select name="class_id" id="classSelect" onchange="loadStudentsAndCourses(this.value)" required>
                            <option value="">Sélectionner une classe</option>
                            <?php
                                if (empty($classes)) {
                                    echo "<option disabled>Aucune classe disponible</option>";
                                } else {
                                    foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            <?php echo htmlspecialchars($class['name']); ?>
                                        </option>
                                    <?php endforeach;
                                }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-user-graduate"></i> Étudiant</label>
                        <select name="student_id" id="student_select" required disabled>
                            <option value="">Sélectionner une classe d'abord</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-book"></i> Cours</label>
                        <select name="course_id" id="course_select" required disabled>
                            <option value="">Sélectionner une classe d'abord</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-clipboard-list"></i> Type d'évaluation</label>
                        <select name="evaluation_type" id="evaluation_type" required onchange="checkExamPermission(this.value)">
                            <option value="">Sélectionner un type</option>
                            <?php
                            $types = $conn->query("SELECT * FROM evaluation_types ORDER BY name");
                            while ($type = $types->fetch_assoc()):
                            ?>
                                <option value="<?php echo $type['id']; ?>"
                                        <?php echo ($type['id'] == 2 && !$can_add_exam_grades) ? 'disabled' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?> 
                                    (Coef. <?php echo $type['coefficient']; ?>)
                                    <?php echo ($type['id'] == 2 && !$can_add_exam_grades) ? ' - Non autorisé' : ''; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <div id="examRestrictionNotice" class="exam-restriction-notice">
                            <i class="fas fa-lock"></i>
                            <span>Seul l'administration peut autoriser l'ajout de notes d'examen</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-star"></i> Note (/20)</label>
                        <input type="number" name="grade" min="0" max="20" step="0.25" required 
                               placeholder="Exemple: 15.5">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Commentaire</label>
                        <textarea name="comment" rows="3" placeholder="Commentaire optionnel..."></textarea>
                    </div>

                    <button type="submit" name="add_grade" class="btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i>
                        Ajouter la note
                    </button>
                </form>
            </div>

            <div class="main-content">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="notification success">
                        <i class="fas fa-check-circle"></i>
                        <?php 
                            echo $_SESSION['success_message'];
                            unset($_SESSION['success_message']);
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="notification error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php 
                            echo $_SESSION['error_message'];
                            unset($_SESSION['error_message']);
                        ?>
                    </div>
                <?php endif; ?>

                <div class="grades-list">
                    <h2><i class="fas fa-history"></i> Notes récentes (<?php echo $total_grades; ?> au total)</h2>
                    
                    <?php if ($total_grades > 0): ?>
                    <div style="overflow-x: auto;">
                        <table class="grades-table">
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th>Type</th>
                                    <th>Note</th>
                                    <th>Classe</th>
                                    <th>Cours</th>
                                    <th>Date</th>
                                    <th>Commentaire</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($grade = $grades->fetch_assoc()): ?>
                                <?php
                                    if (!$grade['student_name'] || !$grade['evaluation_type'] || !$grade['course_name']) {
                                        continue;
                                    }

                                    $grade_class = '';
                                    if ($grade['grade'] >= 14) {
                                        $grade_class = 'grade-good';
                                    } elseif ($grade['grade'] >= 10) {
                                        $grade_class = 'grade-average';
                                    } else {
                                        $grade_class = 'grade-poor';
                                    }

                                    $is_exam = $grade['evaluation_type_id'] == 2;
                                    $can_modify = $can_add_exam_grades || !$is_exam;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($grade['student_name']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($grade['evaluation_type']); ?>
                                        <?php if ($is_exam): ?>
                                            <i class="fas fa-graduation-cap" style="color: #f39c12; margin-left: 5px;"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="grade-value <?php echo $grade_class; ?>">
                                            <?php echo number_format($grade['grade'], 2); ?>/20
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($grade['class_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($grade['course_name']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($grade['created_at'])); ?></td>
                                    <td style="max-width: 200px;"><?php echo htmlspecialchars($grade['comment']); ?></td>
                                    <td>
                                        <?php if ($can_modify): ?>
                                            <button onclick="editGrade(<?php echo $grade['id']; ?>)" class="btn-edit" title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="deleteGrade(<?php echo $grade['id']; ?>)" class="btn-delete" title="Supprimer">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <span style="color: rgba(255,255,255,0.5); font-size: 12px;">
                                                <i class="fas fa-lock"></i>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php echo $pagination; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <h3>Aucune note ajoutée</h3>
                            <p>Commencez par ajouter votre première note.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Vue Tableau Excel (vue unique) -->
        <div class="excel-view active" id="excelView">
            <div class="excel-controls">
                <div class="form-group">
                    <label><i class="fas fa-users"></i> Classe</label>
                    <select id="excelClassSelect" onchange="loadExcelView()">
                        <option value="">Sélectionner une classe</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>">
                                <?php echo htmlspecialchars($class['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-clipboard-list"></i> Type d'évaluation</label>
                    <select id="excelEvalTypeSelect" onchange="loadExcelView()">
                        <option value="">Sélectionner un type</option>
                        <?php
                        $types = $conn->query("SELECT * FROM evaluation_types ORDER BY name");
                        while ($type = $types->fetch_assoc()):
                        ?>
                            <option value="<?php echo $type['id']; ?>"
                                    <?php echo ($type['id'] == 2 && !$can_add_exam_grades) ? 'disabled' : ''; ?>>
                                <?php echo htmlspecialchars($type['name']); ?>
                                <?php echo ($type['id'] == 2 && !$can_add_exam_grades) ? ' - Non autorisé' : ''; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Année académique</label>
                    <select id="excelYearSelect" onchange="loadExcelView()">
                        <?php foreach ($available_years as $yr): ?>
                            <option value="<?php echo htmlspecialchars($yr); ?>"
                                    <?php echo $yr === $selected_year ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($yr); ?><?php echo $yr === $current_year ? ' (courante)' : ' (archive)'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="excel-table-container" id="excelTableContainer">
                <div class="empty-state">
                    <i class="fas fa-table"></i>
                    <h3>Sélectionnez une classe et un type d'évaluation</h3>
                    <p>Le tableau de saisie s'affichera ici</p>
                </div>
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
        let currentMatrixYear = document.getElementById('excelYearSelect')
            ? document.getElementById('excelYearSelect').value : CURRENT_YEAR;
        let currentCommentInput = null;

        // ── Mémorisation du dernier contexte de travail (classe / type / année) ──
        const GM_STORE_KEY = 'uv_gm_last_context';

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

            const classSel = document.getElementById('excelClassSelect');
            const typeSel  = document.getElementById('excelEvalTypeSelect');
            const yearSel  = document.getElementById('excelYearSelect');

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

            if (classSel.value && typeSel.value) {
                loadExcelView();
            }
        }

        document.addEventListener('DOMContentLoaded', restoreWorkContext);

        // Charger la vue Excel
        async function loadExcelView() {
            const classId = document.getElementById('excelClassSelect').value;
            const evalTypeId = document.getElementById('excelEvalTypeSelect').value;
            currentMatrixYear = document.getElementById('excelYearSelect').value || CURRENT_YEAR;
            const container = document.getElementById('excelTableContainer');

            if (classId && evalTypeId) {
                saveWorkContext(classId, evalTypeId, currentMatrixYear);
            }

            if (!classId || !evalTypeId) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-table"></i>
                        <h3>Sélectionnez une classe et un type d'évaluation</h3>
                        <p>Le tableau de saisie s'affichera ici</p>
                    </div>
                `;
                return;
            }

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

            container.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><h3>Chargement...</h3></div>';

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

        // MODIFICATION 8: Afficher le tableau Excel
        function renderExcelTable(data, classId, evalTypeId) {
            const container = document.getElementById('excelTableContainer');
            const { students, courses, grades, maxEvaluations, has_eval_number } = data;
            const isArchive = !!data.is_archive;
            const matrixYear = data.year || CURRENT_YEAR;

            if (!has_eval_number) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Configuration requise</h3>
                        <p>Veuillez exécuter cette commande SQL :</p>
                        <code style="background: rgba(255,255,255,0.1); padding: 10px; display: block; margin: 10px 0;">
                            ALTER TABLE grades ADD COLUMN eval_number INT DEFAULT 1;
                        </code>
                    </div>
                `;
                return;
            }

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
            Object.values(grades).forEach(gradeObj => {
                totalGrades += Object.keys(gradeObj).length;
            });

            let totalExpected = 0;
            courses.forEach(course => {
                totalExpected += students.length * (maxEvaluations[course.id] + 1);
            });

            let html = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 style="margin: 0; color: var(--accent-color);">
                        <i class="fas fa-table"></i>
                        ${isArchive ? 'Consultation des notes' : 'Saisie des notes'} - ${students.length} étudiants — ${matrixYear}
                    </h2>
                    <button class="modal-btn modal-btn-primary" onclick="loadExcelView()" style="padding: 10px 20px;">
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
                                        title="Créer une nouvelle colonne d'évaluation"
                                        style="margin-left: 10px; padding: 5px 10px; background: rgba(46, 204, 113, 0.3); border: 1px solid #2ecc71; color: #2ecc71; border-radius: 5px; cursor: pointer; font-size: 11px; font-weight: 600; transition: all 0.3s ease;">
                                    <i class="fas fa-plus"></i> Nouvelle
                                </button>`}
                            </div>
                        </th>`;
            });

            html += `</tr><tr><th>Nom</th>`;

            courses.forEach(course => {
                const numEvals = maxEvaluations[course.id];
                
                // Colonnes existantes
                for (let evalNum = 1; evalNum <= numEvals; evalNum++) {
                    html += `
                        <th style="min-width: 120px; background: linear-gradient(135deg, var(--accent-color), #0277bd); position: relative;">
                            <div style="display: flex; align-items: center; justify-content: center; gap: 5px;">
                                <span>Éval ${evalNum}</span>
                                ${isArchive ? '' : `
                                <button onclick="deleteEvaluationColumn(${course.id}, ${evalTypeId}, ${classId}, ${evalNum})"
                                        class="btn-delete-column"
                                        title="Supprimer cette colonne">
                                    <i class="fas fa-trash"></i>
                                </button>`}
                            </div>
                        </th>
                    `;
                }
                
                // Colonne "Nouvelle" (masquée en consultation d'archive)
                if (!isArchive) {
                    html += `
                        <th style="min-width: 120px; background: linear-gradient(135deg, rgba(46, 204, 113, 0.3), rgba(39, 174, 96, 0.3)); border: 2px dashed #2ecc71;">
                            <span style="color: #2ecc71; font-size: 12px; font-weight: 600;">Nouvelle Éval ${numEvals + 1}</span>
                        </th>
                    `;
                }
            });

            html += `</tr></thead><tbody>`;

            students.forEach(student => {
                html += `<tr><td>${student.name}</td>`;
                
                courses.forEach(course => {
                    const key = `${student.id}_${course.id}`;
                    const studentGrades = grades[key] || {};
                    const numEvals = maxEvaluations[course.id];
                    
                    // Colonnes existantes (eval_number commence à 1)
                    for (let evalNum = 1; evalNum <= numEvals; evalNum++) {
                        const gradeData = studentGrades[evalNum - 1]; // grades utilise un index 0-based
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
                                           data-eval-number="${evalNum}"
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
                    
                    // Colonne "Nouvelle" avec eval_number = numEvals + 1 (masquée en archive)
                    if (!isArchive) {
                        html += `
                            <td style="background: rgba(46, 204, 113, 0.05);">
                                <div class="grade-cell">
                                    <input type="number"
                                           class="grade-input new-column"
                                           min="0"
                                           max="20"
                                           step="0.25"
                                           value=""
                                           data-student-id="${student.id}"
                                           data-course-id="${course.id}"
                                           data-eval-type-id="${evalTypeId}"
                                           data-eval-number="${numEvals + 1}"
                                           data-grade-id=""
                                           onchange="saveGrade(this)"
                                           onblur="saveGrade(this)"
                                           placeholder="--"
                                           title="Nouvelle évaluation ${numEvals + 1}">
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
                            <strong><i class="fas fa-lightbulb"></i> Comment ça marche :</strong>
                        </p>
                        <ul style="margin: 10px 0 0 20px; color: rgba(255,255,255,0.8); font-size: 13px;">
                            <li>La colonne <strong style="color: #2ecc71;">"Nouvelle Éval"</strong> est toujours disponible pour ajouter une note</li>
                            <li>Lorsque vous saisissez une note dans cette colonne, elle devient automatiquement une colonne permanente</li>
                            <li>Cliquez sur <strong style="color: #2ecc71;">➕ Nouvelle</strong> pour recharger et voir la nouvelle colonne créée</li>
                            <li>Cliquez sur <strong style="color: #e74c3c;">🗑️</strong> pour supprimer toute une colonne d'évaluation</li>
                            <li>Les notes se sauvegardent automatiquement ✅</li>
                            <li>Si vous laissez Éval 1 vide et remplissez Éval 2, elles resteront indépendantes</li>
                        </ul>
                    </div>`;
            container.innerHTML = html;
        }

        // MODIFICATION 9: Supprimer une colonne d'évaluation
        async function deleteEvaluationColumn(courseId, evalTypeId, classId, evalNumber) {
            if (!confirm(`⚠️ ATTENTION ⚠️\n\nVoulez-vous vraiment supprimer la colonne "Éval ${evalNumber}" ?\n\nCette action supprimera les notes de TOUS les étudiants pour cette évaluation et ne peut pas être annulée !`)) {
                return;
            }

            if (!confirm('Êtes-vous ABSOLUMENT SÛR(E) ?\n\nCette suppression est définitive et irréversible !')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('course_id', courseId);
                formData.append('evaluation_type_id', evalTypeId);
                formData.append('class_id', classId);
                formData.append('eval_number', evalNumber);

                const response = await fetch('?action=delete_evaluation_column&year=' + encodeURIComponent(currentMatrixYear), {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    alert(`✅ ${data.message}\n\nLe tableau va se recharger.`);
                    loadExcelView();
                } else {
                    alert('❌ Erreur: ' + data.message);
                }
            } catch (error) {
                console.error('Erreur:', error);
                alert('❌ Erreur lors de la suppression de la colonne');
            }
        }

        // Ajouter une colonne d'évaluation
        async function addEvaluationColumn(courseId, evalTypeId, classId) {
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
                    alert(`✅ ${data.message}\n\nLe tableau va se recharger avec la nouvelle colonne.`);
                    loadExcelView();
                } else {
                    alert('❌ Erreur: ' + data.message);
                }
            } catch (error) {
                console.error('Erreur:', error);
                alert('❌ Erreur lors de l\'ajout de la colonne');
            }
        }

        // Ouvrir le modal de commentaire
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

        // Fermer le modal de commentaire
        function closeCommentModal() {
            const modal = document.getElementById('commentModal');
            modal.classList.remove('active');
            currentCommentInput = null;
        }

        // Sauvegarder le commentaire
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
                    } else {
                        alert('Erreur: ' + data.message);
                    }
                } catch (error) {
                    console.error('Erreur:', error);
                    alert('Erreur lors de la sauvegarde du commentaire');
                }
            } else {
                currentCommentInput.dataset.pendingComment = comment;
                await saveGrade(currentCommentInput);
                closeCommentModal();
            }
        }

        // Fermer le modal en cliquant en dehors
        document.getElementById('commentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCommentModal();
            }
        });

        // MODIFICATION 10: Sauvegarder une note avec eval_number
        async function saveGrade(input) {
            const value = parseFloat(input.value);
            
            if (input.value === '' || input.value === null) {
                input.classList.remove('has-value', 'error');
                return;
            }

            if (isNaN(value) || value < 0 || value > 20) {
                input.classList.add('error');
                input.classList.remove('has-value');
                alert('La note doit être comprise entre 0 et 20');
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
            formData.append('eval_number', input.dataset.evalNumber); // IMPORTANT
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
                    input.classList.remove('new-column');
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
                alert('Erreur lors de la sauvegarde: ' + error.message);
            }
        }

        // Vue traditionnelle - fonctions existantes
        async function loadStudentsAndCourses(classId) {
            const studentSelect = document.querySelector('#student_select');
            const courseSelect = document.querySelector('#course_select');
            
            if (!classId) {
                studentSelect.innerHTML = '<option value="">Sélectionner une classe d\'abord</option>';
                courseSelect.innerHTML = '<option value="">Sélectionner une classe d\'abord</option>';
                studentSelect.disabled = true;
                courseSelect.disabled = true;
                return;
            }

            try {
                studentSelect.innerHTML = '<option value="">Chargement...</option>';
                studentSelect.disabled = true;
                
                const studentsResponse = await fetch(`?action=get_students&class_id=${classId}`);
                const students = await studentsResponse.json();

                if (students.error) {
                    studentSelect.innerHTML = `<option value="">Erreur: ${students.error}</option>`;
                } else {
                    studentSelect.innerHTML = '<option value="">Sélectionner un étudiant</option>';
                    students.forEach(student => {
                        studentSelect.innerHTML += `<option value="${student.id}">${student.name}</option>`;
                    });
                    studentSelect.disabled = false;
                }

                courseSelect.innerHTML = '<option value="">Chargement...</option>';
                courseSelect.disabled = true;
                
                const coursesResponse = await fetch(`?action=get_courses&class_id=${classId}`);
                const courses = await coursesResponse.json();

                if (courses.error) {
                    courseSelect.innerHTML = `<option value="">Erreur: ${courses.error}</option>`;
                } else {
                    courseSelect.innerHTML = '<option value="">Sélectionner un cours</option>';
                    courses.forEach(course => {
                        courseSelect.innerHTML += `<option value="${course.id}">${course.name}</option>`;
                    });
                    courseSelect.disabled = false;
                }

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
                notice.classList.add('show');
                submitBtn.disabled = true;
            } else {
                notice.classList.remove('show');
                submitBtn.disabled = false;
            }
        }

        function editGrade(id) {
            window.location.href = `edit_grade.php?id=${id}`;
        }

        async function deleteGrade(id) {
            if (!confirm('Voulez-vous vraiment supprimer cette note ?')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('grade_id', id);

                const response = await fetch('?action=delete_grade&year=' + encodeURIComponent(currentMatrixYear), {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    location.reload();
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
</html>