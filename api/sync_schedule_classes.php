<?php
session_start();
require_once '../includes/db_connect.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ============================================================================
// ACTION 1 : DÉTECTER LES CLASSES MANQUANTES ET LA CLASSE DE RÉFÉRENCE
// ============================================================================
if ($action === 'detect_missing') {
    $schedule_id = intval($_GET['schedule_id'] ?? 0);
    
    if ($schedule_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID invalide']);
        exit();
    }
    
    // Récupérer les infos du planning de référence
    $schedule_query = "SELECT s.*, c.class_id as course_class_ids, c.name as course_name,
                       cl.name as reference_class_name, s.class_id as reference_class_id
                       FROM schedule s
                       JOIN courses c ON s.course_id = c.id
                       JOIN classes cl ON s.class_id = cl.id
                       WHERE s.id = ?";
    $schedule_stmt = $conn->prepare($schedule_query);
    $schedule_stmt->bind_param("i", $schedule_id);
    $schedule_stmt->execute();
    $schedule = $schedule_stmt->get_result()->fetch_assoc();
    $schedule_stmt->close();
    
    if (!$schedule) {
        echo json_encode(['success' => false, 'message' => 'Planning non trouvé']);
        exit();
    }
    
    // Classes du cours
    $course_class_ids = json_decode($schedule['course_class_ids'], true);
    if (!is_array($course_class_ids)) {
        $course_class_ids = [];
    }
    
    // Classes déjà programmées pour ce cours AU MÊME CRÉNEAU (jour + heure)
    $scheduled_query = "SELECT DISTINCT class_id 
                        FROM schedule 
                        WHERE course_id = ? 
                        AND weekday_id = ? 
                        AND time_slot_id = ?";
    $scheduled_stmt = $conn->prepare($scheduled_query);
    $scheduled_stmt->bind_param("iii", $schedule['course_id'], $schedule['weekday_id'], $schedule['time_slot_id']);
    $scheduled_stmt->execute();
    $scheduled_result = $scheduled_stmt->get_result();
    
    $scheduled_class_ids = [];
    while ($row = $scheduled_result->fetch_assoc()) {
        $scheduled_class_ids[] = $row['class_id'];
    }
    $scheduled_stmt->close();
    
    // Classes manquantes = classes du cours - classes déjà programmées à ce créneau
    $missing_class_ids = array_diff($course_class_ids, $scheduled_class_ids);
    
    if (empty($missing_class_ids)) {
        echo json_encode([
            'success' => true,
            'has_missing' => false,
            'message' => 'Toutes les classes du cours sont déjà programmées'
        ]);
        exit();
    }
    
    // Récupérer les noms des classes manquantes
    $placeholders = implode(',', array_fill(0, count($missing_class_ids), '?'));
    $types = str_repeat('i', count($missing_class_ids));
    
    $missing_query = "SELECT id, name FROM classes WHERE id IN ($placeholders) ORDER BY name";
    $missing_stmt = $conn->prepare($missing_query);
    $missing_stmt->bind_param($types, ...$missing_class_ids);
    $missing_stmt->execute();
    $missing_result = $missing_stmt->get_result();
    
    $missing_classes = [];
    while ($class = $missing_result->fetch_assoc()) {
        $missing_classes[] = $class;
    }
    $missing_stmt->close();
    
    echo json_encode([
        'success' => true,
        'has_missing' => true,
        'missing_classes' => $missing_classes,
        'missing_count' => count($missing_classes),
        'reference_class_id' => $schedule['reference_class_id'],
        'reference_class_name' => $schedule['reference_class_name'],
        'schedule_info' => [
            'course_id' => $schedule['course_id'],
            'course_name' => $schedule['course_name'],
            'teacher_id' => $schedule['teacher_id'],
            'classroom_id' => $schedule['classroom_id'],
            'weekday_id' => $schedule['weekday_id'],
            'time_slot_id' => $schedule['time_slot_id'],
            'start_date' => $schedule['start_date'],
            'end_date' => $schedule['end_date'],
            'is_recurring' => $schedule['is_recurring']
        ]
    ]);
    exit();
}

// ============================================================================
// ACTION 2 : VÉRIFIER LES CONFLITS AVANT SYNCHRONISATION
// ============================================================================
if ($action === 'check_conflicts') {
    $schedule_id = intval($_POST['schedule_id'] ?? 0);
    $class_ids_to_check = json_decode($_POST['class_ids'] ?? '[]', true);
    $new_classroom_id = intval($_POST['classroom_id'] ?? 0);
    $new_weekday_id = intval($_POST['weekday_id'] ?? 0);
    $new_timeslot_id = intval($_POST['timeslot_id'] ?? 0);
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    
    if (empty($class_ids_to_check)) {
        echo json_encode(['success' => false, 'message' => 'Aucune classe à vérifier']);
        exit();
    }
    
    // Récupérer les infos du planning de référence
    $ref_query = "SELECT classroom_id, weekday_id, time_slot_id, course_id FROM schedule WHERE id = ?";
    $ref_stmt = $conn->prepare($ref_query);
    $ref_stmt->bind_param("i", $schedule_id);
    $ref_stmt->execute();
    $ref_schedule = $ref_stmt->get_result()->fetch_assoc();
    $ref_stmt->close();
    
    // Déterminer si c'est le même créneau ET la même salle
    $same_slot = ($ref_schedule['weekday_id'] == $new_weekday_id && 
                  $ref_schedule['time_slot_id'] == $new_timeslot_id);
    $same_classroom = ($ref_schedule['classroom_id'] == $new_classroom_id);
    
    // VALIDATION 1 : Vérifier le conflit de salle (sauf si c'est la même salle ET le même créneau pour ce cours)
    $classroom_conflict_sql = "SELECT s.id, c.name as course_name, s.course_id FROM schedule s
                               JOIN courses c ON s.course_id = c.id
                               WHERE s.classroom_id = ? 
                               AND s.weekday_id = ? 
                               AND s.time_slot_id = ?";
    
    // Si c'est le même créneau et la même salle, exclure les cours du même ID
    if ($same_slot && $same_classroom) {
        $classroom_conflict_sql .= " AND s.course_id != ?";
    }
    
    if ($start_date && $end_date) {
        $classroom_conflict_sql .= " AND ((s.start_date <= ? AND s.end_date >= ?) OR (s.start_date IS NULL AND s.end_date IS NULL))";
    } else {
        $classroom_conflict_sql .= " AND (s.start_date IS NULL AND s.end_date IS NULL)";
    }
    
    $classroom_stmt = $conn->prepare($classroom_conflict_sql);
    
    if ($same_slot && $same_classroom) {
        if ($start_date && $end_date) {
            $classroom_stmt->bind_param("iiiiss", $new_classroom_id, $new_weekday_id, $new_timeslot_id, $ref_schedule['course_id'], $end_date, $start_date);
        } else {
            $classroom_stmt->bind_param("iiii", $new_classroom_id, $new_weekday_id, $new_timeslot_id, $ref_schedule['course_id']);
        }
    } else {
        if ($start_date && $end_date) {
            $classroom_stmt->bind_param("iiiss", $new_classroom_id, $new_weekday_id, $new_timeslot_id, $end_date, $start_date);
        } else {
            $classroom_stmt->bind_param("iii", $new_classroom_id, $new_weekday_id, $new_timeslot_id);
        }
    }
    
    $classroom_stmt->execute();
    $classroom_result = $classroom_stmt->get_result();
    
    if ($classroom_result->num_rows > 0) {
        $classroom_conflict = $classroom_result->fetch_assoc();
        echo json_encode([
            'success' => false,
            'message' => "❌ Cette salle est déjà occupée par le cours : {$classroom_conflict['course_name']}"
        ]);
        $classroom_stmt->close();
        exit();
    }
    $classroom_stmt->close();
    
    // VALIDATION 2 : Vérifier les conflits par classe (uniquement pour les nouvelles classes à ajouter)
    $conflicts = [];
    
    // Si même salle et même créneau, pas besoin de vérifier les conflits de classes
    // Car on ajoute juste des classes à un cours existant
    if (!($same_slot && $same_classroom)) {
        foreach ($class_ids_to_check as $class_id) {
            $conflict_sql = "SELECT s.id, c.name as course_name, r.name as classroom_name, u.name as teacher_name
                             FROM schedule s
                             JOIN courses c ON s.course_id = c.id
                             JOIN classrooms r ON s.classroom_id = r.id
                             JOIN users u ON s.teacher_id = u.id
                             WHERE s.class_id = ? 
                             AND s.weekday_id = ? 
                             AND s.time_slot_id = ?";
            
            if ($start_date && $end_date) {
                $conflict_sql .= " AND ((s.start_date <= ? AND s.end_date >= ?) OR (s.start_date IS NULL AND s.end_date IS NULL))";
            } else {
                $conflict_sql .= " AND (s.start_date IS NULL AND s.end_date IS NULL)";
            }
            
            $conflict_stmt = $conn->prepare($conflict_sql);
            
            if ($start_date && $end_date) {
                $conflict_stmt->bind_param("iiiss", $class_id, $new_weekday_id, $new_timeslot_id, $end_date, $start_date);
            } else {
                $conflict_stmt->bind_param("iii", $class_id, $new_weekday_id, $new_timeslot_id);
            }
            
            $conflict_stmt->execute();
            $conflict_result = $conflict_stmt->get_result();
            
            if ($conflict_result->num_rows > 0) {
                $conflict_info = $conflict_result->fetch_assoc();
                
                // Récupérer le nom de la classe
                $class_name_sql = "SELECT name FROM classes WHERE id = ?";
                $class_name_stmt = $conn->prepare($class_name_sql);
                $class_name_stmt->bind_param("i", $class_id);
                $class_name_stmt->execute();
                $class_name_result = $class_name_stmt->get_result()->fetch_assoc();
                $class_name_stmt->close();
                
                $conflicts[] = [
                    'class_id' => $class_id,
                    'class_name' => $class_name_result['name'],
                    'schedule_id' => $conflict_info['id'],
                    'conflicting_course' => $conflict_info['course_name'],
                    'conflicting_classroom' => $conflict_info['classroom_name'],
                    'conflicting_teacher' => $conflict_info['teacher_name']
                ];
            }
            
            $conflict_stmt->close();
        }
    }
    
    echo json_encode([
        'success' => true,
        'has_conflicts' => !empty($conflicts),
        'conflicts' => $conflicts,
        'same_slot_and_classroom' => ($same_slot && $same_classroom)
    ]);
    exit();
}

// ============================================================================
// ACTION 3 : SYNCHRONISER AVEC LOGIQUE INTELLIGENTE
// ============================================================================
if ($action === 'sync_classes') {
    $schedule_id = intval($_POST['schedule_id'] ?? 0);
    $class_ids_to_add = json_decode($_POST['class_ids'] ?? '[]', true);
    $new_classroom_id = intval($_POST['classroom_id'] ?? 0);
    $new_weekday_id = intval($_POST['weekday_id'] ?? 0);
    $new_timeslot_id = intval($_POST['timeslot_id'] ?? 0);
    $replace_conflicts = intval($_POST['replace_conflicts'] ?? 0);
    
    if ($schedule_id <= 0 || empty($class_ids_to_add) || $new_classroom_id <= 0 || $new_weekday_id <= 0 || $new_timeslot_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Données invalides']);
        exit();
    }
    
    // Récupérer les infos du planning de référence
    $schedule_query = "SELECT s.*, c.name as course_name FROM schedule s 
                       JOIN courses c ON s.course_id = c.id 
                       WHERE s.id = ?";
    $schedule_stmt = $conn->prepare($schedule_query);
    $schedule_stmt->bind_param("i", $schedule_id);
    $schedule_stmt->execute();
    $schedule = $schedule_stmt->get_result()->fetch_assoc();
    $schedule_stmt->close();
    
    if (!$schedule) {
        echo json_encode(['success' => false, 'message' => 'Planning non trouvé']);
        exit();
    }
    
    $start_date = $_POST['start_date'] ?? $schedule['start_date'];
    $end_date = $_POST['end_date'] ?? $schedule['end_date'];
    $is_recurring = intval($_POST['is_recurring'] ?? $schedule['is_recurring']);
    
    // Déterminer si c'est le même créneau ET la même salle
    $same_slot = ($schedule['weekday_id'] == $new_weekday_id && 
                  $schedule['time_slot_id'] == $new_timeslot_id);
    $same_classroom = ($schedule['classroom_id'] == $new_classroom_id);
    
    $conn->begin_transaction();
    
    try {
        $added_count = 0;
        $replaced_count = 0;
        $skipped_count = 0;
        $moved_count = 0;
        $added_classes = [];
        $replaced_classes = [];
        $skipped_classes = [];
        $moved_classes = [];
        
        // ====================================================================
        // CAS 1 : MÊME SALLE ET MÊME CRÉNEAU
        // On ajoute simplement les classes manquantes
        // ====================================================================
        if ($same_slot && $same_classroom) {
            $insert_sql = "INSERT INTO schedule (course_id, teacher_id, classroom_id, class_id, weekday_id, time_slot_id, start_date, end_date, is_recurring) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            
            foreach ($class_ids_to_add as $class_id) {
                // Ne pas ré-ajouter la classe de référence
                if ($class_id == $schedule['class_id']) {
                    continue;
                }
                
                // Vérifier si cette classe n'a pas déjà ce cours à ce créneau
                $check_sql = "SELECT id FROM schedule 
                             WHERE course_id = ? AND class_id = ? AND weekday_id = ? AND time_slot_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("iiii", $schedule['course_id'], $class_id, $new_weekday_id, $new_timeslot_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    // Classe déjà programmée, passer
                    $check_stmt->close();
                    continue;
                }
                $check_stmt->close();
                
                // Ajouter la classe
                $insert_stmt->bind_param("isiiiissi",
                    $schedule['course_id'],
                    $schedule['teacher_id'],
                    $new_classroom_id,
                    $class_id,
                    $new_weekday_id,
                    $new_timeslot_id,
                    $start_date,
                    $end_date,
                    $is_recurring
                );
                
                if ($insert_stmt->execute()) {
                    $added_count++;
                    
                    $class_name_sql = "SELECT name FROM classes WHERE id = ?";
                    $class_name_stmt = $conn->prepare($class_name_sql);
                    $class_name_stmt->bind_param("i", $class_id);
                    $class_name_stmt->execute();
                    $class_name_result = $class_name_stmt->get_result()->fetch_assoc();
                    $added_classes[] = $class_name_result['name'];
                    $class_name_stmt->close();
                }
            }
            
            $insert_stmt->close();
            
        // ====================================================================
        // CAS 2 : SALLE DIFFÉRENTE OU CRÉNEAU DIFFÉRENT
        // On déplace TOUTES les classes (référence incluse)
        // ====================================================================
        } else {
            // Récupérer toutes les classes déjà programmées pour ce cours
            $existing_query = "SELECT DISTINCT class_id FROM schedule WHERE course_id = ?";
            $existing_stmt = $conn->prepare($existing_query);
            $existing_stmt->bind_param("i", $schedule['course_id']);
            $existing_stmt->execute();
            $existing_result = $existing_stmt->get_result();
            
            $existing_classes = [];
            while ($row = $existing_result->fetch_assoc()) {
                $existing_classes[] = $row['class_id'];
            }
            $existing_stmt->close();
            
            // Fusionner avec les nouvelles classes
            $all_classes = array_unique(array_merge($existing_classes, $class_ids_to_add));
            
            // Supprimer tous les anciens cours de ce cours-ci
            $delete_sql = "DELETE FROM schedule WHERE course_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $schedule['course_id']);
            $delete_stmt->execute();
            $delete_stmt->close();
            
            // Réinsérer pour toutes les classes au nouveau créneau/salle
            $insert_sql = "INSERT INTO schedule (course_id, teacher_id, classroom_id, class_id, weekday_id, time_slot_id, start_date, end_date, is_recurring) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            
            foreach ($all_classes as $class_id) {
                // Vérifier les conflits pour cette classe
                $conflict_check = "SELECT s.id FROM schedule s
                                  WHERE s.class_id = ? 
                                  AND s.weekday_id = ? 
                                  AND s.time_slot_id = ?";
                
                if ($start_date && $end_date) {
                    $conflict_check .= " AND ((s.start_date <= ? AND s.end_date >= ?) OR (s.start_date IS NULL AND s.end_date IS NULL))";
                }
                
                $conflict_stmt = $conn->prepare($conflict_check);
                
                if ($start_date && $end_date) {
                    $conflict_stmt->bind_param("iiiss", $class_id, $new_weekday_id, $new_timeslot_id, $end_date, $start_date);
                } else {
                    $conflict_stmt->bind_param("iii", $class_id, $new_weekday_id, $new_timeslot_id);
                }
                
                $conflict_stmt->execute();
                $conflict_result = $conflict_stmt->get_result();
                
                if ($conflict_result->num_rows > 0 && $replace_conflicts == 0) {
                    // Conflit et on ne remplace pas
                    $conflict_stmt->close();
                    
                    $class_name_sql = "SELECT name FROM classes WHERE id = ?";
                    $class_name_stmt = $conn->prepare($class_name_sql);
                    $class_name_stmt->bind_param("i", $class_id);
                    $class_name_stmt->execute();
                    $class_name_result = $class_name_stmt->get_result()->fetch_assoc();
                    $skipped_classes[] = $class_name_result['name'];
                    $class_name_stmt->close();
                    
                    $skipped_count++;
                    continue;
                } elseif ($conflict_result->num_rows > 0 && $replace_conflicts == 1) {
                    // Conflit et on remplace
                    $conflict_id = $conflict_result->fetch_row()[0];
                    $conflict_stmt->close();
                    
                    $delete_conflict_sql = "DELETE FROM schedule WHERE id = ?";
                    $delete_conflict_stmt = $conn->prepare($delete_conflict_sql);
                    $delete_conflict_stmt->bind_param("i", $conflict_id);
                    $delete_conflict_stmt->execute();
                    $delete_conflict_stmt->close();
                } else {
                    $conflict_stmt->close();
                }
                
                // Insérer la classe au nouveau créneau
                $insert_stmt->bind_param("isiiiissi",
                    $schedule['course_id'],
                    $schedule['teacher_id'],
                    $new_classroom_id,
                    $class_id,
                    $new_weekday_id,
                    $new_timeslot_id,
                    $start_date,
                    $end_date,
                    $is_recurring
                );
                
                if ($insert_stmt->execute()) {
                    $moved_count++;
                    
                    $class_name_sql = "SELECT name FROM classes WHERE id = ?";
                    $class_name_stmt = $conn->prepare($class_name_sql);
                    $class_name_stmt->bind_param("i", $class_id);
                    $class_name_stmt->execute();
                    $class_name_result = $class_name_stmt->get_result()->fetch_assoc();
                    $moved_classes[] = $class_name_result['name'];
                    $class_name_stmt->close();
                }
            }
            
            $insert_stmt->close();
        }
        
        $total_synced = $added_count + $moved_count + $replaced_count;
        
        if ($total_synced > 0) {
            $conn->commit();
            
            // Récupérer les infos du créneau
            $info_sql = "SELECT w.name as weekday, ts.name as timeslot, r.name as classroom_name
                         FROM weekdays w, time_slots ts, classrooms r
                         WHERE w.id = ? AND ts.id = ? AND r.id = ?";
            $info_stmt = $conn->prepare($info_sql);
            $info_stmt->bind_param("iii", $new_weekday_id, $new_timeslot_id, $new_classroom_id);
            $info_stmt->execute();
            $info = $info_stmt->get_result()->fetch_assoc();
            $info_stmt->close();
            
            // Message de succès
            $message = "Synchronisation réussie !\n\n";
            if ($added_count > 0) $message .= "✅ $added_count ajoutée(s) : " . implode(', ', $added_classes) . "\n\n";
            if ($moved_count > 0) $message .= "🔄 $moved_count déplacée(s) : " . implode(', ', $moved_classes) . "\n\n";
            if ($replaced_count > 0) $message .= "🔄 $replaced_count remplacée(s) : " . implode(', ', $replaced_classes) . "\n\n";
            if ($skipped_count > 0) $message .= "⚠️ $skipped_count ignorée(s) : " . implode(', ', $skipped_classes);
            
            echo json_encode([
                'success' => true,
                'added_count' => $added_count,
                'moved_count' => $moved_count,
                'replaced_count' => $replaced_count,
                'skipped_count' => $skipped_count,
                'added_classes' => $added_classes,
                'moved_classes' => $moved_classes,
                'replaced_classes' => $replaced_classes,
                'skipped_classes' => $skipped_classes,
                'schedule_info' => $info,
                'message' => trim($message)
            ]);
        } else {
            throw new Exception("Aucune classe n'a pu être synchronisée");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Erreur lors de la synchronisation : ' . $e->getMessage()
        ]);
    }
    
    exit();
}

echo json_encode(['success' => false, 'message' => 'Action non reconnue']);
?>