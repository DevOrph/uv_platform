<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

// ============================================================================
// FONCTION DE LOGGING : Enregistrer les actions dans admin_logs
// ============================================================================
function logAdminAction($conn, $admin_id, $action_type, $description, $entity_id = null, $entity_type = null, $entity_name = null, $old_value = null, $new_value = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $old_value_json = $old_value ? json_encode($old_value, JSON_UNESCAPED_UNICODE) : null;
    $new_value_json = $new_value ? json_encode($new_value, JSON_UNESCAPED_UNICODE) : null;
    
    $log_sql = "INSERT INTO admin_logs (admin_id, action_type, description, ip_address, entity_id, entity_type, entity_name, old_value, new_value, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    
    if ($log_stmt) {
        $log_stmt->bind_param("ssssssssss", 
            $admin_id, 
            $action_type, 
            $description, 
            $ip_address, 
            $entity_id, 
            $entity_type, 
            $entity_name,
            $old_value_json,
            $new_value_json,
            $user_agent
        );
        $log_stmt->execute();
        $log_stmt->close();
    }
}

$admin_id = $_SESSION['user_id'];

// Traitement des formulaires
$error_message = "";
$success_message = "";

// Ajouter une salle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_classroom'])) {
    $name = $_POST['name'];
    $capacity = $_POST['capacity'];
    $building = $_POST['building'];
    $description = $_POST['description'];

    $sql = "INSERT INTO classrooms (name, capacity, building, description) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("siss", $name, $capacity, $building, $description);
    
    if ($stmt->execute()) {
        $classroom_id = $conn->insert_id;
        $success_message = "Salle ajoutée avec succès.";
        
        logAdminAction(
            $conn,
            $admin_id,
            'ADD_CLASSROOM',
            "Ajout de la salle $name (Bâtiment: $building, Capacité: $capacity)",
            $classroom_id,
            'classroom',
            $name,
            null,
            ['name' => $name, 'capacity' => $capacity, 'building' => $building, 'description' => $description]
        );
    } else {
        $error_message = "Erreur lors de l'ajout de la salle : " . $stmt->error;
    }
    $stmt->close();
}

// Modifier une salle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_classroom'])) {
    $id = $_POST['classroom_id'];
    $name = $_POST['name'];
    $capacity = $_POST['capacity'];
    $building = $_POST['building'];
    $description = $_POST['description'];
    
    $old_sql = "SELECT * FROM classrooms WHERE id = ?";
    $old_stmt = $conn->prepare($old_sql);
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_data = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    $sql = "UPDATE classrooms SET name = ?, capacity = ?, building = ?, description = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sissi", $name, $capacity, $building, $description, $id);
    
    if ($stmt->execute()) {
        $success_message = "Salle modifiée avec succès.";
        
        logAdminAction(
            $conn,
            $admin_id,
            'EDIT_CLASSROOM',
            "Modification de la salle $name (ID: $id)",
            $id,
            'classroom',
            $name,
            $old_data,
            ['name' => $name, 'capacity' => $capacity, 'building' => $building, 'description' => $description]
        );
    } else {
        $error_message = "Erreur lors de la modification de la salle : " . $stmt->error;
    }
    $stmt->close();
}

// Supprimer une salle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_classroom'])) {
    $id = $_POST['classroom_id'];
    
    $old_sql = "SELECT * FROM classrooms WHERE id = ?";
    $old_stmt = $conn->prepare($old_sql);
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_data = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();
    
    $sql = "DELETE FROM classrooms WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $success_message = "Salle supprimée avec succès.";
        
        logAdminAction(
            $conn,
            $admin_id,
            'DELETE_CLASSROOM',
            "Suppression de la salle {$old_data['name']} (ID: $id)",
            $id,
            'classroom',
            $old_data['name'],
            $old_data,
            null
        );
    } else {
        $error_message = "Erreur lors de la suppression de la salle : " . $stmt->error;
    }
    $stmt->close();
}

// Ajouter un créneau horaire
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_timeslot'])) {
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $name = $_POST['slot_name'];

    $sql = "INSERT INTO time_slots (start_time, end_time, name) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $start_time, $end_time, $name);
    
    if ($stmt->execute()) {
        $timeslot_id = $conn->insert_id;
        $success_message = "Créneau horaire ajouté avec succès.";
        
        logAdminAction(
            $conn,
            $admin_id,
            'ADD_TIMESLOT',
            "Ajout du créneau $name ($start_time - $end_time)",
            $timeslot_id,
            'timeslot',
            $name,
            null,
            ['name' => $name, 'start_time' => $start_time, 'end_time' => $end_time]
        );
    } else {
        $error_message = "Erreur lors de l'ajout du créneau horaire : " . $stmt->error;
    }
    $stmt->close();
}

// Modifier un créneau horaire
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_timeslot'])) {
    $id = $_POST['timeslot_id'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $name = $_POST['slot_name'];
    
    $old_sql = "SELECT * FROM time_slots WHERE id = ?";
    $old_stmt = $conn->prepare($old_sql);
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_data = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    $sql = "UPDATE time_slots SET start_time = ?, end_time = ?, name = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $start_time, $end_time, $name, $id);
    
    if ($stmt->execute()) {
        $success_message = "Créneau horaire modifié avec succès.";
        
        logAdminAction(
            $conn,
            $admin_id,
            'EDIT_TIMESLOT',
            "Modification du créneau $name (ID: $id)",
            $id,
            'timeslot',
            $name,
            $old_data,
            ['name' => $name, 'start_time' => $start_time, 'end_time' => $end_time]
        );
    } else {
        $error_message = "Erreur lors de la modification du créneau horaire : " . $stmt->error;
    }
    $stmt->close();
}

// Supprimer un créneau horaire
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_timeslot'])) {
    $id = $_POST['timeslot_id'];
    
    $old_sql = "SELECT * FROM time_slots WHERE id = ?";
    $old_stmt = $conn->prepare($old_sql);
    $old_stmt->bind_param("i", $id);
    $old_stmt->execute();
    $old_data = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();
    
    $sql = "DELETE FROM time_slots WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $success_message = "Créneau horaire supprimé avec succès.";
        
        logAdminAction(
            $conn,
            $admin_id,
            'DELETE_TIMESLOT',
            "Suppression du créneau {$old_data['name']} (ID: $id)",
            $id,
            'timeslot',
            $old_data['name'],
            $old_data,
            null
        );
    } else {
        $error_message = "Erreur lors de la suppression du créneau horaire : " . $stmt->error;
    }
    $stmt->close();
}


// ============================================================================
// FONCTION : Vérifier si une classe a déjà un cours à un créneau donné
// ============================================================================
function hasClassConflict($conn, $class_id, $weekday_id, $time_slot_id, $start_date, $end_date, $exclude_schedule_id = null) {
    $conflict_sql = "SELECT s.id, c.name as course_name, u.name as teacher_name, r.name as classroom_name
                     FROM schedule s
                     JOIN courses c ON s.course_id = c.id
                     JOIN users u ON s.teacher_id = u.id
                     JOIN classrooms r ON s.classroom_id = r.id
                     WHERE s.class_id = ? 
                     AND s.weekday_id = ? 
                     AND s.time_slot_id = ?";
    
    // Si on modifie un cours existant, exclure cet ID
    if ($exclude_schedule_id !== null) {
        $conflict_sql .= " AND s.id != ?";
    }
    
    // Gestion des dates
    if ($start_date && $end_date) {
        $conflict_sql .= " AND ((s.start_date <= ? AND s.end_date >= ?) OR (s.start_date IS NULL AND s.end_date IS NULL))";
    } else {
        $conflict_sql .= " AND (s.start_date IS NULL AND s.end_date IS NULL)";
    }
    
    $conflict_stmt = $conn->prepare($conflict_sql);
    
    if ($exclude_schedule_id !== null) {
        if ($start_date && $end_date) {
            $conflict_stmt->bind_param("iiiiss", $class_id, $weekday_id, $time_slot_id, $exclude_schedule_id, $end_date, $start_date);
        } else {
            $conflict_stmt->bind_param("iiii", $class_id, $weekday_id, $time_slot_id, $exclude_schedule_id);
        }
    } else {
        if ($start_date && $end_date) {
            $conflict_stmt->bind_param("iiiss", $class_id, $weekday_id, $time_slot_id, $end_date, $start_date);
        } else {
            $conflict_stmt->bind_param("iii", $class_id, $weekday_id, $time_slot_id);
        }
    }
    
    $conflict_stmt->execute();
    $conflict_result = $conflict_stmt->get_result();
    
    if ($conflict_result->num_rows > 0) {
        $conflict_data = $conflict_result->fetch_assoc();
        $conflict_stmt->close();
        return $conflict_data;
    }
    
    $conflict_stmt->close();
    return false;
}

// ============================================================================
// AJOUTER UN COURS À L'EMPLOI DU TEMPS - AVEC VALIDATION STRICTE
// ============================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_schedule'])) {
    $course_id = intval($_POST['course_id'] ?? 0);
    $classroom_id = intval($_POST['classroom_id'] ?? 0);
    $weekday_id = intval($_POST['weekday_id'] ?? 0);
    $time_slot_id = intval($_POST['time_slot_id'] ?? 0);
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : NULL;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : NULL;
    $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;

    if (empty($course_id)) {
        $error_message = "Erreur: Vous devez sélectionner un cours.";
    } else {
        // Récupérer les informations du cours
        $course_sql = "SELECT teacher_id, class_id, name FROM courses WHERE id = ?";
        $course_stmt = $conn->prepare($course_sql);
        $course_stmt->bind_param("i", $course_id);
        $course_stmt->execute();
        $course_result = $course_stmt->get_result()->fetch_assoc();
        $course_stmt->close();
        
        if (!$course_result || empty($course_result['teacher_id'])) {
            $error_message = "Erreur: Le cours sélectionné n'a pas d'enseignant assigné.";
        } else {
            $teacher_id = $course_result['teacher_id'];
            $course_name = $course_result['name'];
            
            // Décoder les classes liées au cours
            $class_ids = json_decode($course_result['class_id'], true);
            if (!is_array($class_ids) || empty($class_ids)) {
                $error_message = "Erreur: Le cours n'a pas de classes assignées.";
            } elseif (empty($classroom_id) || empty($weekday_id) || empty($time_slot_id)) {
                $error_message = "Erreur: Tous les champs obligatoires doivent être remplis.";
            } else {
                // ============================================================
                // VALIDATION 1 : Vérifier conflit de salle
                // ============================================================
                $check_sql = "SELECT s.id, c.name as course_name FROM schedule s
                              JOIN courses c ON s.course_id = c.id
                              WHERE s.classroom_id = ? AND s.weekday_id = ? AND s.time_slot_id = ?";
                
                if ($start_date && $end_date) {
                    $check_sql .= " AND ((s.start_date <= ? AND s.end_date >= ?) OR (s.start_date IS NULL AND s.end_date IS NULL))";
                } else {
                    $check_sql .= " AND (s.start_date IS NULL AND s.end_date IS NULL)";
                }
                
                $check_stmt = $conn->prepare($check_sql);
                
                if ($start_date && $end_date) {
                    $check_stmt->bind_param("iiiss", $classroom_id, $weekday_id, $time_slot_id, $end_date, $start_date);
                } else {
                    $check_stmt->bind_param("iii", $classroom_id, $weekday_id, $time_slot_id);
                }
                
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $conflict = $check_result->fetch_assoc();
                    $error_message = "❌ Ce créneau est déjà occupé pour cette salle par le cours : {$conflict['course_name']}";
                    $check_stmt->close();
                } else {
                    $check_stmt->close();
                    
                    // ============================================================
                    // VALIDATION 2 : Détecter les conflits par classe
                    // ============================================================
                    $available_classes = [];
                    $conflicting_classes = [];
                    
                    foreach ($class_ids as $class_id) {
                        $conflict = hasClassConflict($conn, $class_id, $weekday_id, $time_slot_id, $start_date, $end_date);
                        
                        if ($conflict) {
                            // Récupérer le nom de la classe
                            $class_name_sql = "SELECT name FROM classes WHERE id = ?";
                            $class_name_stmt = $conn->prepare($class_name_sql);
                            $class_name_stmt->bind_param("i", $class_id);
                            $class_name_stmt->execute();
                            $class_name_result = $class_name_stmt->get_result()->fetch_assoc();
                            $class_name_stmt->close();
                            
                            $conflicting_classes[] = [
                                'id' => $class_id,
                                'name' => $class_name_result['name'],
                                'conflicting_course' => $conflict['course_name'],
                                'conflicting_teacher' => $conflict['teacher_name'],
                                'conflicting_classroom' => $conflict['classroom_name']
                            ];
                        } else {
                            $available_classes[] = $class_id;
                        }
                    }
                    
                    // ============================================================
                    // SI AUCUNE CLASSE DISPONIBLE
                    // ============================================================
                    if (empty($available_classes)) {
                        $error_message = "❌ Aucune classe n'est disponible pour ce créneau. Toutes les classes ont déjà un cours programmé.\n\n";
                        $error_message .= "Classes en conflit :\n";
                        foreach ($conflicting_classes as $conflict) {
                            $error_message .= "• {$conflict['name']} → {$conflict['conflicting_course']} (Prof: {$conflict['conflicting_teacher']}, Salle: {$conflict['conflicting_classroom']})\n";
                        }
                    } else {
                        // ============================================================
                        // AJOUTER LE COURS POUR LES CLASSES DISPONIBLES
                        // ============================================================
                        $conn->begin_transaction();
                        
                        try {
                            $inserted_count = 0;
                            $inserted_classes = [];
                            
                            $insert_sql = "INSERT INTO schedule (course_id, teacher_id, classroom_id, class_id, weekday_id, time_slot_id, start_date, end_date, is_recurring, academic_year)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $insert_stmt = $conn->prepare($insert_sql);
                            
                            $sched_year = ANNEE_ACADEMIQUE_COURANTE;
                            foreach ($available_classes as $class_id) {
                                $insert_stmt->bind_param("isiiiissis",
                                    $course_id,
                                    $teacher_id,
                                    $classroom_id,
                                    $class_id,
                                    $weekday_id,
                                    $time_slot_id,
                                    $start_date,
                                    $end_date,
                                    $is_recurring,
                                    $sched_year
                                );
                                
                                if ($insert_stmt->execute()) {
                                    $inserted_count++;
                                    
                                    $class_name_sql = "SELECT name FROM classes WHERE id = ?";
                                    $class_name_stmt = $conn->prepare($class_name_sql);
                                    $class_name_stmt->bind_param("i", $class_id);
                                    $class_name_stmt->execute();
                                    $class_name_result = $class_name_stmt->get_result()->fetch_assoc();
                                    $inserted_classes[] = $class_name_result['name'];
                                    $class_name_stmt->close();
                                }
                            }
                            
                            $insert_stmt->close();
                            
                            if ($inserted_count > 0) {
                                $conn->commit();
                                
                                $classes_list = implode(', ', $inserted_classes);
                                $success_message = "✅ Cours '$course_name' ajouté avec succès pour $inserted_count classe(s) : $classes_list";
                                
                                if (!empty($conflicting_classes)) {
                                    $success_message .= "\n\n⚠️ " . count($conflicting_classes) . " classe(s) ignorée(s) (conflit de créneau) :\n";
                                    foreach ($conflicting_classes as $conflict) {
                                        $success_message .= "• {$conflict['name']} → {$conflict['conflicting_course']}\n";
                                    }
                                }
                                
                                // Log
                                $info_sql = "SELECT w.name as weekday, ts.name as timeslot, r.name as classroom_name
                                             FROM weekdays w, time_slots ts, classrooms r
                                             WHERE w.id = ? AND ts.id = ? AND r.id = ?";
                                $info_stmt = $conn->prepare($info_sql);
                                $info_stmt->bind_param("iii", $weekday_id, $time_slot_id, $classroom_id);
                                $info_stmt->execute();
                                $info = $info_stmt->get_result()->fetch_assoc();
                                $info_stmt->close();
                                
                                logAdminAction(
                                    $conn,
                                    $admin_id,
                                    'ADD_SCHEDULE_SMART',
                                    "Ajout du cours '$course_name' pour $inserted_count classe(s) - {$info['weekday']} {$info['timeslot']} - Salle: {$info['classroom_name']}",
                                    $course_id,
                                    'schedule',
                                    $course_name,
                                    null,
                                    [
                                        'course_id' => $course_id,
                                        'teacher_id' => $teacher_id,
                                        'classroom_id' => $classroom_id,
                                        'available_classes' => $inserted_classes,
                                        'conflicting_classes' => array_column($conflicting_classes, 'name'),
                                        'weekday_id' => $weekday_id,
                                        'time_slot_id' => $time_slot_id,
                                        'start_date' => $start_date,
                                        'end_date' => $end_date,
                                        'is_recurring' => $is_recurring
                                    ]
                                );
                            } else {
                                throw new Exception("Aucune classe n'a pu être ajoutée.");
                            }
                            
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error_message = "Erreur lors de l'ajout du cours : " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

// ============================================================================
// MODIFIER UN COURS - AVEC VALIDATION STRICTE DES CONFLITS
// ============================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_schedule'])) {
    $schedule_id = intval($_POST['schedule_id']);
    $course_id = intval($_POST['course_id'] ?? 0);
    $classroom_id = intval($_POST['classroom_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $weekday_id = intval($_POST['weekday_id'] ?? 0);
    $time_slot_id = intval($_POST['time_slot_id'] ?? 0);
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : NULL;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : NULL;
    $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
    
    if (empty($course_id)) {
        $error_message = "Erreur: Vous devez sélectionner un cours.";
    } else {
        $teacher_sql = "SELECT teacher_id FROM courses WHERE id = ?";
        $teacher_stmt = $conn->prepare($teacher_sql);
        $teacher_stmt->bind_param("i", $course_id);
        $teacher_stmt->execute();
        $teacher_result = $teacher_stmt->get_result()->fetch_assoc();
        $teacher_stmt->close();
        
        if (!$teacher_result || empty($teacher_result['teacher_id'])) {
            $error_message = "Erreur: Le cours sélectionné n'a pas d'enseignant assigné.";
        } else {
            $teacher_id = $teacher_result['teacher_id'];
            
            $old_sql = "SELECT s.*, c.name as course_name, u.name as teacher_name, r.name as classroom_name, 
                        cl.name as class_name, w.name as weekday, ts.name as timeslot
                        FROM schedule s
                        JOIN courses c ON s.course_id = c.id
                        JOIN users u ON s.teacher_id = u.id
                        JOIN classrooms r ON s.classroom_id = r.id
                        JOIN classes cl ON s.class_id = cl.id
                        JOIN weekdays w ON s.weekday_id = w.id
                        JOIN time_slots ts ON s.time_slot_id = ts.id
                        WHERE s.id = ?";
            $old_stmt = $conn->prepare($old_sql);
            $old_stmt->bind_param("i", $schedule_id);
            $old_stmt->execute();
            $old_data = $old_stmt->get_result()->fetch_assoc();
            $old_stmt->close();
            
            if (empty($classroom_id) || empty($class_id) || empty($weekday_id) || empty($time_slot_id)) {
                $error_message = "Erreur: Tous les champs obligatoires doivent être remplis.";
            } else {
                // ============================================================
                // VALIDATION 1 : Vérifier conflit de salle (exclure le cours actuel)
                // ============================================================
                $check_sql = "SELECT s.id, c.name as course_name FROM schedule s
                              JOIN courses c ON s.course_id = c.id
                              WHERE s.id != ? AND s.classroom_id = ? AND s.weekday_id = ? AND s.time_slot_id = ?";
                
                if ($start_date && $end_date) {
                    $check_sql .= " AND ((s.start_date <= ? AND s.end_date >= ?) OR (s.start_date IS NULL AND s.end_date IS NULL))";
                } else {
                    $check_sql .= " AND (s.start_date IS NULL AND s.end_date IS NULL)";
                }
                
                $check_stmt = $conn->prepare($check_sql);
                
                if ($start_date && $end_date) {
                    $check_stmt->bind_param("iiiiss", $schedule_id, $classroom_id, $weekday_id, $time_slot_id, $end_date, $start_date);
                } else {
                    $check_stmt->bind_param("iiii", $schedule_id, $classroom_id, $weekday_id, $time_slot_id);
                }
                
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $conflict = $check_result->fetch_assoc();
                    $error_message = "❌ Ce créneau est déjà occupé pour cette salle par le cours : {$conflict['course_name']}";
                    $check_stmt->close();
                } else {
                    $check_stmt->close();
                    
                    // ============================================================
                    // VALIDATION 2 : Vérifier conflit de classe (exclure le cours actuel)
                    // ============================================================
                    $conflict = hasClassConflict($conn, $class_id, $weekday_id, $time_slot_id, $start_date, $end_date, $schedule_id);
                    
                    if ($conflict) {
                        $error_message = "❌ La classe a déjà un cours à ce créneau :\n";
                        $error_message .= "→ {$conflict['course_name']} (Prof: {$conflict['teacher_name']}, Salle: {$conflict['classroom_name']})";
                    } else {
                        // Pas de conflit, modifier le cours
                        $sql = "UPDATE schedule SET course_id = ?, teacher_id = ?, classroom_id = ?, class_id = ?, 
                                weekday_id = ?, time_slot_id = ?, start_date = ?, end_date = ?, is_recurring = ? 
                                WHERE id = ?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("isiiiissii", $course_id, $teacher_id, $classroom_id, $class_id, 
                                          $weekday_id, $time_slot_id, $start_date, $end_date, $is_recurring, $schedule_id);
                        
                        if ($stmt->execute()) {
                            $success_message = "✅ Cours modifié avec succès.";
                            
                            logAdminAction(
                                $conn,
                                $admin_id,
                                'EDIT_SCHEDULE',
                                "Modification du cours '{$old_data['course_name']}' (ID: $schedule_id)",
                                $schedule_id,
                                'schedule',
                                $old_data['course_name'],
                                $old_data,
                                [
                                    'course_id' => $course_id,
                                    'teacher_id' => $teacher_id,
                                    'classroom_id' => $classroom_id,
                                    'class_id' => $class_id,
                                    'weekday_id' => $weekday_id,
                                    'time_slot_id' => $time_slot_id,
                                    'start_date' => $start_date,
                                    'end_date' => $end_date,
                                    'is_recurring' => $is_recurring
                                ]
                            );
                        } else {
                            $error_message = "Erreur lors de la modification du cours : " . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }
}

// Supprimer un élément de l'emploi du temps
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_schedule'])) {
    $schedule_id = $_POST['schedule_id'];
    
    $old_sql = "SELECT s.*, c.name as course_name, u.name as teacher_name, r.name as classroom_name, 
                cl.name as class_name, w.name as weekday, ts.name as timeslot
                FROM schedule s
                JOIN courses c ON s.course_id = c.id
                JOIN users u ON s.teacher_id = u.id
                JOIN classrooms r ON s.classroom_id = r.id
                JOIN classes cl ON s.class_id = cl.id
                JOIN weekdays w ON s.weekday_id = w.id
                JOIN time_slots ts ON s.time_slot_id = ts.id
                WHERE s.id = ?";
    $old_stmt = $conn->prepare($old_sql);
    $old_stmt->bind_param("i", $schedule_id);
    $old_stmt->execute();
    $old_data = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();
    
    $sql = "DELETE FROM schedule WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $schedule_id);
    
    if ($stmt->execute()) {
        $success_message = "Cours supprimé de l'emploi du temps avec succès.";
        
        logAdminAction(
            $conn,
            $admin_id,
            'DELETE_SCHEDULE',
            "Suppression du cours '{$old_data['course_name']}' - {$old_data['weekday']} {$old_data['timeslot']} (ID: $schedule_id)",
            $schedule_id,
            'schedule',
            $old_data['course_name'],
            $old_data,
            null
        );
    } else {
        $error_message = "Erreur lors de la suppression du cours : " . $stmt->error;
    }
    $stmt->close();
}

// Récupérer les données pour les listes déroulantes
$courses_query = "SELECT id, name FROM courses ORDER BY name";
$courses_result = $conn->query($courses_query);

$teachers_query = "SELECT id, name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name";
$teachers_result = $conn->query($teachers_query);

$classrooms_query = "SELECT id, name, capacity, building FROM classrooms ORDER BY name";
$classrooms_result = $conn->query($classrooms_query);

$classes_query = "SELECT id, name FROM classes ORDER BY name";
$classes_result = $conn->query($classes_query);

$weekdays_query = "SELECT id, name FROM weekdays ORDER BY id";
$weekdays_result = $conn->query($weekdays_query);

$weekdays_count = $weekdays_result->num_rows;

$time_slots_query = "SELECT id, TIME_FORMAT(start_time, '%H:%i') as start_time, TIME_FORMAT(end_time, '%H:%i') as end_time, name FROM time_slots ORDER BY start_time ASC";
$time_slots_result = $conn->query($time_slots_query);

$schedule_query = "SELECT s.id, c.name as course_name, u.name as teacher_name, r.name as classroom_name, 
                  cl.name as class_name, w.name as weekday, w.id as weekday_id,
                  TIME_FORMAT(ts.start_time, '%H:%i') as start_time, 
                  TIME_FORMAT(ts.end_time, '%H:%i') as end_time, 
                  s.start_date, s.end_date, s.is_recurring, s.course_id, s.teacher_id, 
                  s.classroom_id, s.class_id, s.time_slot_id, ts.id as ts_id
                  FROM schedule s
                  JOIN courses c ON s.course_id = c.id
                  JOIN users u ON s.teacher_id = u.id
                  JOIN classrooms r ON s.classroom_id = r.id
                  JOIN classes cl ON s.class_id = cl.id
                  JOIN weekdays w ON s.weekday_id = w.id
                  JOIN time_slots ts ON s.time_slot_id = ts.id
                  ORDER BY w.id, ts.start_time";
$schedule_result = $conn->query($schedule_query);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion de l'emploi du temps - Université Virtuelle</title>
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
            --warning-color: #ffc107;
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
        }

        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid var(--border-color);
            position: relative;
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

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        h1, h2 {
            color: var(--accent-color);
            margin-bottom: 20px;
        }

        h3 {
            color: var(--text-light);
            margin-bottom: 15px;
            font-size: 1.2em;
        }

        .section {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 20px;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        .timetable-wrapper {
            background: rgba(255, 255, 255, 0.02);
            padding: 20px;
            border-radius: 10px;
            margin-top: 10px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        label {
            font-weight: bold;
            margin-bottom: 5px;
            display: block;
        }

        input, select, textarea {
            padding: 10px;
            border-radius: 5px;
            border: 1px solid var(--border-color);
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            width: 100%;
            transition: all 0.3s;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            background-color: rgba(255, 255, 255, 0.15);
        }

        input[type="checkbox"] {
            width: auto;
            margin-right: 10px;
        }

        button {
            background-color: var(--accent-color);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }

        button:hover {
            background-color: #0288d1;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(3, 155, 229, 0.3);
        }

        button:active {
            transform: translateY(0);
        }

        .btn-edit {
            background-color: var(--warning-color);
            padding: 5px 10px;
            font-size: 0.9em;
        }

        .btn-edit:hover {
            background-color: #e0a800;
        }

        .btn-delete {
            background-color: var(--error-color);
            padding: 5px 10px;
            font-size: 0.9em;
        }

        .btn-delete:hover {
            background-color: #c82333;
        }

        .error, .success {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error {
            background-color: var(--error-color);
        }

        .success {
            background-color: var(--success-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        table th, table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        table th {
            background-color: var(--secondary-bg);
            font-weight: 600;
        }

        table tr {
            transition: background-color 0.3s;
        }

        table tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .tab-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .tabs {
            display: flex;
            gap: 10px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
            flex-wrap: wrap;
        }

        .tab {
            padding: 10px 15px;
            cursor: pointer;
            border-radius: 5px 5px 0 0;
            transition: all 0.3s;
            font-weight: 500;
        }

        .tab.active {
            background-color: var(--accent-color);
            color: white;
        }

        .tab:hover:not(.active) {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .timetable {
            display: grid;
            grid-template-columns: 140px repeat(<?php echo $weekdays_count; ?>, 1fr);
            gap: 2px;
            background-color: var(--border-color);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .timetable-header {
            background: linear-gradient(135deg, var(--secondary-bg), #0a3a5c);
            padding: 15px 10px;
            text-align: center;
            font-weight: 600;
            font-size: 1em;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--accent-color);
        }

        .timetable-time {
            background: linear-gradient(135deg, var(--secondary-bg), #0a3a5c);
            padding: 15px 10px;
            text-align: center;
            font-weight: 600;
            font-size: 0.95em;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            gap: 5px;
            border-right: 2px solid var(--accent-color);
        }

        .timetable-time div:first-child {
            font-size: 1.1em;
            color: var(--accent-color);
        }

        .timetable-time div:last-child {
            font-size: 1.1em;
            color: #888;
        }

        .timetable-cell {
            background-color: rgba(255, 255, 255, 0.03);
            padding: 12px;
            min-height: 140px;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .timetable-cell.has-class {
            background-color: rgba(3, 155, 229, 0.15);
            cursor: pointer;
            transition: all 0.3s;
        }

        .timetable-cell.has-class:hover {
            background-color: rgba(3, 155, 229, 0.25);
            box-shadow: inset 0 0 10px rgba(3, 155, 229, 0.3);
        }

        .timetable-entry {
            font-size: 13px;
            padding: 12px;
            border-radius: 8px;
            background: linear-gradient(135deg, rgba(3, 155, 229, 0.7), rgba(3, 155, 229, 0.5));
            border-left: 4px solid var(--accent-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            transition: all 0.3s;
        }

        .timetable-entry:hover {
            background: linear-gradient(135deg, rgba(3, 155, 229, 0.9), rgba(3, 155, 229, 0.7));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(3, 155, 229, 0.4);
        }

        .timetable-entry strong {
            display: block;
            margin-bottom: 6px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.3;
        }

        .timetable-entry-info {
            font-size: 12px;
            line-height: 1.6;
            color: rgba(255, 255, 255, 0.95);
        }

        .timetable-entry-info i {
            margin-right: 4px;
            color: var(--accent-color);
            width: 14px;
            display: inline-block;
        }

        .timetable-entry-actions {
            margin-top: 10px;
            display: flex;
            gap: 6px;
            justify-content: flex-start;
        }

        .timetable-entry-actions button {
            padding: 5px 10px;
            font-size: 0.8em;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background-color: var(--secondary-bg);
            margin: 5% auto;
            padding: 30px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
            animation: slideDown 0.3s;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s;
        }

        .close:hover,
        .close:focus {
            color: var(--text-light);
        }

        option {
            background: #0c2d48;
            color: #ffffff;
        }

        .sync-checkbox {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid var(--warning-color);
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }

        .sync-checkbox label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            color: var(--warning-color);
            font-weight: 600;
        }

        .sync-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .sync-info {
            font-size: 0.85em;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 8px;
            padding-left: 30px;
        }

        .quick-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .quick-action-btn {
            background: linear-gradient(135deg, var(--accent-color), #0288d1);
            padding: 10px 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .quick-action-btn i {
            font-size: 1.2em;
        }

        .smart-suggestion {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid rgba(76, 175, 80, 0.3);
            border-radius: 8px;
            padding: 12px;
            margin: 15px 0;
            display: none;
        }

        .smart-suggestion.active {
            display: block;
            animation: slideDown 0.3s ease-out;
        }

        .smart-suggestion-title {
            color: #4CAF50;
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .smart-suggestion-content {
            font-size: 0.9em;
            color: rgba(255, 255, 255, 0.8);
        }

        .conflict-warning {
            background: rgba(255, 152, 0, 0.1);
            border: 1px solid rgba(255, 152, 0, 0.3);
            border-radius: 8px;
            padding: 12px;
            margin: 15px 0;
            display: none;
        }

        .conflict-warning.active {
            display: block;
            animation: slideDown 0.3s ease-out;
        }

        .conflict-warning-title {
            color: #FF9800;
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .timetable {
                grid-template-columns: 100px repeat(<?php echo $weekdays_count; ?>, 1fr);
                font-size: 0.85em;
            }

            .timetable-time {
                font-size: 0.8em;
                padding: 10px 5px;
            }

            .timetable-cell {
                min-height: 120px;
                padding: 8px;
            }

            .timetable-entry {
                font-size: 11px;
                padding: 8px;
            }

            .timetable-entry strong {
                font-size: 12px;
            }

            .modal-content {
                width: 95%;
                margin: 10% auto;
                padding: 20px;
            }
        }

        @media (max-width: 1200px) {
            .timetable {
                overflow-x: auto;
            }

            .timetable-header {
                font-size: 0.9em;
                padding: 12px 8px;
            }
        }

/* ============================================
   STYLES D'IMPRESSION - A4 PAYSAGE
   ============================================ */

@media print {
    /* Configuration de la page */
    @page {
        size: A4 landscape;
        margin: 10mm 8mm;
    }
    
    /* Masquer tous les éléments inutiles */
    header, 
    .quick-actions, 
    .tabs, 
    .action-buttons, 
    button, 
    .error, 
    .success,
    .tab-container > .tabs,
    .section:not(:has(.timetable-wrapper)),
    footer {
        display: none !important;
    }
    
    /* Réinitialiser le body */
    body {
        background: white !important;
        color: black !important;
        margin: 0;
        padding: 0;
        font-family: Arial, sans-serif;
        font-size: 9pt;
    }
    
    /* Container principal */
    .container {
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    
    /* Titre de la page */
    .tab-content.active h2 {
        display: block !important;
        text-align: center;
        font-size: 16pt;
        color: #051e34;
        margin-bottom: 8mm;
        page-break-after: avoid;
    }
    
    /* Section de l'emploi du temps */
    .section {
        background: white !important;
        border: none !important;
        padding: 0 !important;
        margin: 0 !important;
        page-break-inside: avoid;
    }
    
    .timetable-wrapper {
        background: white !important;
        padding: 0 !important;
        margin: 0 !important;
        page-break-inside: avoid;
    }
    
    /* Grille de l'emploi du temps */
    .timetable {
        display: grid !important;
        grid-template-columns: 60px repeat(<?php echo $weekdays_count; ?>, 1fr) !important;
        gap: 0 !important;
        background-color: transparent !important;
        border: 2px solid #000 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        page-break-inside: avoid;
        width: 100%;
        max-width: 100%;
    }
    
    /* En-têtes */
    .timetable-header {
        background: #e0e0e0 !important;
        padding: 4mm 2mm !important;
        text-align: center !important;
        font-weight: bold !important;
        font-size: 9pt !important;
        color: #000 !important;
        border: 1px solid #000 !important;
        border-bottom: 2px solid #000 !important;
        page-break-inside: avoid;
        page-break-after: avoid;
    }
    
    /* Colonne des heures */
    .timetable-time {
        background: #f5f5f5 !important;
        padding: 2mm 1mm !important;
        text-align: center !important;
        font-weight: bold !important;
        font-size: 7pt !important;
        color: #000 !important;
        border: 1px solid #000 !important;
        border-right: 2px solid #000 !important;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 1mm;
        page-break-inside: avoid;
    }
    
    .timetable-time div {
        color: #000 !important;
        font-size: 7pt !important;
    }
    
    /* Cellules */
    .timetable-cell {
        background-color: white !important;
        padding: 1.5mm !important;
        min-height: auto !important;
        height: auto !important;
        border: 1px solid #ccc !important;
        page-break-inside: avoid;
        overflow: hidden;
    }
    
    .timetable-cell.has-class {
        background-color: #f0f7ff !important;
    }
    
    /* Entrées de cours */
    .timetable-entry {
        font-size: 7pt !important;
        padding: 2mm !important;
        border-radius: 2mm !important;
        background: #e3f2fd !important;
        border-left: 2px solid #1976d2 !important;
        box-shadow: none !important;
        margin-bottom: 1.5mm;
        page-break-inside: avoid;
        line-height: 1.3;
    }
    
    .timetable-entry:last-child {
        margin-bottom: 0;
    }
    
    .timetable-entry strong {
        display: block;
        margin-bottom: 1mm;
        color: #000 !important;
        font-size: 8pt !important;
        font-weight: bold !important;
        line-height: 1.2;
    }
    
    .timetable-entry-info {
        font-size: 6.5pt !important;
        line-height: 1.4 !important;
        color: #333 !important;
    }
    
    .timetable-entry-info div {
        margin-bottom: 0.5mm;
    }
    
    .timetable-entry-info i {
        margin-right: 1mm;
        color: #555 !important;
        width: auto;
        font-size: 6pt !important;
    }
    
    /* Masquer les actions */
    .timetable-entry-actions {
        display: none !important;
    }
    
    /* Éviter les coupures */
    .timetable-header,
    .timetable-time,
    .timetable-cell,
    .timetable-entry {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
    }
    
    /* Assurer que tout tient sur une page */
    .tab-content.active {
        page-break-after: avoid;
        height: auto;
        max-height: none;
    }
    
    /* Ajuster la hauteur des cellules si trop de contenu */
    @supports (display: grid) {
        .timetable {
            grid-auto-rows: minmax(auto, 1fr);
        }
    }
    
    /* Si plusieurs cours dans une cellule */
    .timetable-cell:has(.timetable-entry + .timetable-entry) .timetable-entry {
        font-size: 6pt !important;
        padding: 1mm !important;
    }
    
    .timetable-cell:has(.timetable-entry + .timetable-entry) .timetable-entry strong {
        font-size: 7pt !important;
    }
    
    .timetable-cell:has(.timetable-entry + .timetable-entry) .timetable-entry-info {
        font-size: 6pt !important;
    }
    
    /* En-tête de la page imprimée */
    .tab-content.active::before {
        content: "Emploi du Temps - Université Virtuelle";
        display: block;
        text-align: center;
        font-size: 18pt;
        font-weight: bold;
        color: #051e34;
        margin-bottom: 5mm;
        padding-bottom: 3mm;
        border-bottom: 2px solid #039be5;
    }
    
    /* Date d'impression */
    .tab-content.active::after {
        content: "Imprimé le " counter(page);
        display: block;
        text-align: right;
        font-size: 8pt;
        color: #666;
        margin-top: 3mm;
    }
    
    /* Optimisation pour Chrome/Edge */
    html {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    
    /* Forcer l'affichage en paysage */
    body {
        width: 297mm;
        height: 210mm;
    }
}

</style>
</head>
<body>
    <?php include '../includes/header_admin.php'; ?>

    <div class="container">
        <?php if (!empty($error_message)): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?></div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="success"><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="tab-container">
            <div class="tabs">
                <div class="tab active" data-tab="schedule-view"><i class="fas fa-calendar-alt"></i> Voir l'emploi du temps</div>
                <div class="tab" data-tab="manage-schedule"><i class="fas fa-edit"></i> Gérer les cours</div>
                <div class="tab" data-tab="manage-classrooms"><i class="fas fa-door-open"></i> Gérer les salles</div>
                <div class="tab" data-tab="manage-timeslots"><i class="fas fa-clock"></i> Gérer les créneaux</div>
            </div>

            <!-- Onglet de visualisation de l'emploi du temps -->
            <div class="tab-content active" id="schedule-view">
                <h2><i class="fas fa-calendar-week"></i> Emploi du temps</h2>
                
                <div class="quick-actions">
                    <button class="quick-action-btn" onclick="openAddScheduleModal()">
                        <i class="fas fa-plus"></i> Ajouter un cours rapide
                    </button>
                    <button class="quick-action-btn" onclick="printScheduleInNewWindow()" style="background: linear-gradient(135deg, #9C27B0, #7B1FA2);">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                </div>

                <div class="section">
                    <form method="GET" action="">
                        <div class="grid">
                            <div>
                                <label for="filter_class"><i class="fas fa-users"></i> Filtrer par classe:</label>
                                <select name="filter_class" id="filter_class">
                                    <option value="">Toutes les classes</option>
                                    <?php
                                    $classes_result->data_seek(0);
                                    while ($class = $classes_result->fetch_assoc()) {
                                        $selected = (isset($_GET['filter_class']) && $_GET['filter_class'] == $class['id']) ? 'selected' : '';
                                        echo "<option value='{$class['id']}' $selected>{$class['name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label for="filter_teacher"><i class="fas fa-chalkboard-teacher"></i> Filtrer par enseignant:</label>
                                <select name="filter_teacher" id="filter_teacher">
                                    <option value="">Tous les enseignants</option>
                                    <?php
                                    $teachers_result->data_seek(0);
                                    while ($teacher = $teachers_result->fetch_assoc()) {
                                        $selected = (isset($_GET['filter_teacher']) && $_GET['filter_teacher'] == $teacher['id']) ? 'selected' : '';
                                        echo "<option value='{$teacher['id']}' $selected>{$teacher['name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label for="filter_classroom"><i class="fas fa-door-open"></i> Filtrer par salle:</label>
                                <select name="filter_classroom" id="filter_classroom">
                                    <option value="">Toutes les salles</option>
                                    <?php
                                    $classrooms_result->data_seek(0);
                                    while ($classroom = $classrooms_result->fetch_assoc()) {
                                        $selected = (isset($_GET['filter_classroom']) && $_GET['filter_classroom'] == $classroom['id']) ? 'selected' : '';
                                        echo "<option value='{$classroom['id']}' $selected>{$classroom['name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div style="display: flex; align-items: end;">
                                <button type="submit"><i class="fas fa-filter"></i> Filtrer</button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="section">
                    <div class="timetable-wrapper">
                        <div class="timetable">
                        <div class="timetable-header">Horaire</div>
                        <?php
                        $weekdays_result->data_seek(0);
                        while ($weekday = $weekdays_result->fetch_assoc()) {
                            echo "<div class='timetable-header'>{$weekday['name']}</div>";
                        }
                        
                        $time_slots_result->data_seek(0);
                        while ($time_slot = $time_slots_result->fetch_assoc()) {
                            echo "<div class='timetable-time'>";
                            echo "<div>{$time_slot['start_time']}</div>";
                            echo "<div style='margin: 3px 0;'>—</div>";
                            echo "<div>{$time_slot['end_time']}</div>";
                            echo "</div>";
                            
                            $weekdays_result->data_seek(0);
                            while ($weekday = $weekdays_result->fetch_assoc()) {
                                $schedule_query = "SELECT s.id, c.name as course_name, u.name as teacher_name, r.name as classroom_name, cl.name as class_name,
                                                 s.course_id, s.teacher_id, s.classroom_id, s.class_id, s.weekday_id, s.time_slot_id,
                                                 s.start_date, s.end_date, s.is_recurring
                                                 FROM schedule s
                                                 JOIN courses c ON s.course_id = c.id
                                                 JOIN users u ON s.teacher_id = u.id
                                                 JOIN classrooms r ON s.classroom_id = r.id
                                                 JOIN classes cl ON s.class_id = cl.id
                                                 WHERE s.weekday_id = {$weekday['id']} AND s.time_slot_id = {$time_slot['id']}";
                                
                                if (isset($_GET['filter_class']) && !empty($_GET['filter_class'])) {
                                    $schedule_query .= " AND s.class_id = " . intval($_GET['filter_class']);
                                }
                                if (isset($_GET['filter_teacher']) && !empty($_GET['filter_teacher'])) {
                                    $schedule_query .= " AND s.teacher_id = '" . $conn->real_escape_string($_GET['filter_teacher']) . "'";
                                }
                                if (isset($_GET['filter_classroom']) && !empty($_GET['filter_classroom'])) {
                                    $schedule_query .= " AND s.classroom_id = " . intval($_GET['filter_classroom']);
                                }
                                
                                $cell_result = $conn->query($schedule_query);
                                
                                echo "<div class='timetable-cell " . ($cell_result->num_rows > 0 ? "has-class" : "") . "'>";
                                while ($cell = $cell_result->fetch_assoc()) {
                                    echo "<div class='timetable-entry'>";
                                    echo "<strong>{$cell['course_name']}</strong>";
                                    echo "<div class='timetable-entry-info'>";
                                    echo "<div><i class='fas fa-chalkboard-teacher'></i> {$cell['teacher_name']}</div>";
                                    echo "<div><i class='fas fa-door-open'></i> {$cell['classroom_name']}</div>";
                                    echo "<div><i class='fas fa-users'></i> {$cell['class_name']}</div>";
                                    echo "</div>";
                                    echo "<div class='timetable-entry-actions'>";
                                    echo "<button class='btn-edit' onclick='openEditScheduleModal(" . json_encode($cell) . ")' title='Modifier'><i class='fas fa-edit'></i></button>";
                                    echo "<button class='btn-delete' onclick='deleteSchedule({$cell['id']})' title='Supprimer'><i class='fas fa-trash'></i></button>";
                                    echo "<button class='btn-edit' onclick='checkAndSyncClasses({$cell['id']})' style='background-color: #4CAF50;' title='Synchroniser'><i class='fas fa-sync'></i></button>";
                                    echo "</div>";
                                    echo "</div>";
                                }
                                echo "</div>";
                            }
                        }
                        ?>
                    </div>
                    </div>
                </div>
            </div>

            <!-- Onglet de gestion des cours dans l'emploi du temps -->
            <div class="tab-content" id="manage-schedule">
                <h2><i class="fas fa-tasks"></i> Gérer les cours dans l'emploi du temps</h2>
                
                <div class="section" style="margin-bottom: 20px;">
                    <label for="search-schedule"><i class="fas fa-search"></i> Recherche rapide :</label>
                    <input type="text" id="search-schedule" placeholder="Rechercher par cours, enseignant, classe, salle..." 
                           onkeyup="filterScheduleTable()" 
                           style="width: 100%; padding: 12px; margin-top: 10px;">
                </div>
                
                <div class="section">
                    <h3>Liste des cours programmés</h3>
                    <table id="schedule-table">
                        <thead>
                            <tr>
                                <th>Cours</th>
                                <th>Enseignant</th>
                                <th>Salle</th>
                                <th>Classe</th>
                                <th>Jour</th>
                                <th>Horaire</th>
                                <th>Période</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $schedule_result->data_seek(0);
                            if ($schedule_result->num_rows > 0) {
                                while ($row = $schedule_result->fetch_assoc()) {
                                    $period = "Permanent";
                                    if (!empty($row['start_date']) && !empty($row['end_date'])) {
                                        $period = "Du " . date('d/m/Y', strtotime($row['start_date'])) . " au " . date('d/m/Y', strtotime($row['end_date']));
                                    }
                                    $recurring = $row['is_recurring'] ? "Récurrent" : "Unique";
                                    
                                    echo "<tr>
                                            <td>{$row['course_name']}</td>
                                            <td>{$row['teacher_name']}</td>
                                            <td>{$row['classroom_name']}</td>
                                            <td>{$row['class_name']}</td>
                                            <td>{$row['weekday']}</td>
                                            <td>{$row['start_time']} - {$row['end_time']}</td>
                                            <td>{$period} ({$recurring})</td>
                                            <td>
                                                <div class='action-buttons'>
                                                    <button class='btn-edit' onclick='openEditScheduleModal(" . json_encode($row) . ")'><i class='fas fa-edit'></i> Modifier</button>
                                                    <button class='btn-delete' onclick='deleteSchedule({$row['id']})'><i class='fas fa-trash'></i> Supprimer</button>
                                                    <button class='btn-edit' onclick='checkAndSyncClasses({$row['id']})' style='background-color: #4CAF50;' title='Synchroniser les classes'><i class='fas fa-sync'></i> Sync</button>
                                                </div>
                                            </td>
                                          </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='8'>Aucun cours programmé pour le moment.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Onglet de gestion des salles -->
            <div class="tab-content" id="manage-classrooms">
                <h2><i class="fas fa-door-open"></i> Gérer les salles</h2>
                
                <div class="quick-actions">
                    <button class="quick-action-btn" onclick="openAddClassroomModal()">
                        <i class="fas fa-plus"></i> Ajouter une salle
                    </button>
                </div>

                <div class="section">
                    <h3>Liste des salles</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Capacité</th>
                                <th>Bâtiment</th>
                                <th>Description</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $classrooms_query = "SELECT * FROM classrooms ORDER BY name";
                            $classrooms_list = $conn->query($classrooms_query);
                            
                            if ($classrooms_list->num_rows > 0) {
                                while ($row = $classrooms_list->fetch_assoc()) {
                                    echo "<tr>
                                            <td>{$row['name']}</td>
                                            <td>{$row['capacity']} places</td>
                                            <td>{$row['building']}</td>
                                            <td>{$row['description']}</td>
                                            <td>
                                                <div class='action-buttons'>
                                                    <button class='btn-edit' onclick='openEditClassroomModal(" . json_encode($row) . ")'><i class='fas fa-edit'></i> Modifier</button>
                                                    <button class='btn-delete' onclick='deleteClassroom({$row['id']})'><i class='fas fa-trash'></i> Supprimer</button>
                                                </div>
                                            </td>
                                          </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5'>Aucune salle enregistrée pour le moment.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Onglet de gestion des créneaux horaires -->
            <div class="tab-content" id="manage-timeslots">
                <h2><i class="fas fa-clock"></i> Gérer les créneaux horaires</h2>
                
                <div class="quick-actions">
                    <button class="quick-action-btn" onclick="openAddTimeslotModal()">
                        <i class="fas fa-plus"></i> Ajouter un créneau
                    </button>
                </div>

                <div class="section">
                    <h3>Liste des créneaux horaires</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Heure de début</th>
                                <th>Heure de fin</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $time_slots_query = "SELECT id, name, TIME_FORMAT(start_time, '%H:%i') as start_time, TIME_FORMAT(end_time, '%H:%i') as end_time FROM time_slots ORDER BY start_time ASC";
                            $time_slots_list = $conn->query($time_slots_query);
                            
                            if ($time_slots_list->num_rows > 0) {
                                while ($row = $time_slots_list->fetch_assoc()) {
                                    echo "<tr>
                                            <td>{$row['name']}</td>
                                            <td>{$row['start_time']}</td>
                                            <td>{$row['end_time']}</td>
                                            <td>
                                                <div class='action-buttons'>
                                                    <button class='btn-edit' onclick='openEditTimeslotModal(" . json_encode($row) . ")'><i class='fas fa-edit'></i> Modifier</button>
                                                    <button class='btn-delete' onclick='deleteTimeslot({$row['id']})'><i class='fas fa-trash'></i> Supprimer</button>
                                                </div>
                                            </td>
                                          </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='4'>Aucun créneau horaire enregistré pour le moment.</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal pour ajouter/modifier un cours -->
    <div id="scheduleModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('scheduleModal')">&times;</span>
            <h2 id="scheduleModalTitle"><i class="fas fa-plus-circle"></i> Ajouter un cours</h2>
            <form method="POST" action="" id="scheduleForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <input type="hidden" name="schedule_id" id="schedule_id">
                <div class="grid" style="grid-template-columns: 1fr;">
                    <div>
                        <label for="modal_course_id"><i class="fas fa-book"></i> Cours:</label>
                        <select name="course_id" id="modal_course_id" required>
                            <option value="">Sélectionner un cours</option>
                            <?php
                            $courses_result->data_seek(0);
                            while ($course = $courses_result->fetch_assoc()) {
                                echo "<option value='{$course['id']}'>{$course['name']}</option>";
                            }
                            ?>
                        </select>
                        <small id="course-lock-info" style="color: rgba(255, 152, 0, 0.8); display: none; margin-top: 5px;">
                            <i class="fas fa-lock"></i> Le cours ne peut pas être modifié après création
                        </small>
                    </div>
                    
                    <!-- Suggestions intelligentes -->
                    <div id="smartSuggestion" class="smart-suggestion">
                        <div class="smart-suggestion-title">
                            <i class="fas fa-lightbulb"></i> Suggestion intelligente
                        </div>
                        <div class="smart-suggestion-content" id="suggestionContent"></div>
                    </div>
                    
                    <!-- Alerte de conflit -->
                    <div id="conflictWarning" class="conflict-warning">
                        <div class="conflict-warning-title">
                            <i class="fas fa-exclamation-triangle"></i> Attention : Conflit détecté
                        </div>
                        <div class="conflict-warning-content" id="conflictContent"></div>
                    </div>
                    
                    <div>
                        <label for="modal_teacher_id"><i class="fas fa-chalkboard-teacher"></i> Enseignant:</label>
                        <select name="teacher_id" id="modal_teacher_id" required>
                            <option value="">Sélectionner un enseignant</option>
                            <?php
                            $teachers_result->data_seek(0);
                            while ($teacher = $teachers_result->fetch_assoc()) {
                                echo "<option value='{$teacher['id']}'>{$teacher['name']}</option>";
                            }
                            ?>
                        </select>
                        <small id="teacher-auto-info" style="color: rgba(255, 255, 255, 0.6); display: block; margin-top: 5px;">
                            <i class="fas fa-info-circle"></i> Rempli automatiquement selon le cours sélectionné
                        </small>
                    </div>
                </div>
                
                <div class="grid" style="grid-template-columns: 1fr 1fr;">
    <div>
        <label for="modal_classroom_id"><i class="fas fa-door-open"></i> Salle:</label>
        <select name="classroom_id" id="modal_classroom_id" required>
            <option value="">Sélectionner une salle</option>
            <?php
            $classrooms_result->data_seek(0);
            while ($classroom = $classrooms_result->fetch_assoc()) {
                echo "<option value='{$classroom['id']}'>{$classroom['name']} ({$classroom['building']}, {$classroom['capacity']} places)</option>";
            }
            ?>
        </select>
    </div>
    <!-- Champ classe masqué pour l'ajout, visible pour la modification -->
    <div id="class-select-container" style="display: none;">
        <label for="modal_class_id"><i class="fas fa-users"></i> Classe:</label>
        <select name="class_id" id="modal_class_id">
            <option value="">Sélectionner une classe</option>
            <?php
            $classes_result->data_seek(0);
            while ($class = $classes_result->fetch_assoc()) {
                echo "<option value='{$class['id']}'>{$class['name']}</option>";
            }
            ?>
        </select>
    </div>
                    <div>
                        <label for="modal_weekday_id"><i class="fas fa-calendar-day"></i> Jour:</label>
                        <select name="weekday_id" id="modal_weekday_id" required>
                            <option value="">Sélectionner un jour</option>
                            <?php
                            $weekdays_result->data_seek(0);
                            while ($weekday = $weekdays_result->fetch_assoc()) {
                                echo "<option value='{$weekday['id']}'>{$weekday['name']}</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label for="modal_time_slot_id"><i class="fas fa-clock"></i> Créneau horaire:</label>
                        <select name="time_slot_id" id="modal_time_slot_id" required>
                            <option value="">Sélectionner un créneau</option>
                            <?php
                            $time_slots_result->data_seek(0);
                            while ($time_slot = $time_slots_result->fetch_assoc()) {
                                echo "<option value='{$time_slot['id']}'>{$time_slot['name']} ({$time_slot['start_time']} - {$time_slot['end_time']})</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label for="modal_start_date"><i class="fas fa-calendar-alt"></i> Date de début:</label>
                        <input type="date" name="start_date" id="modal_start_date">
                    </div>
                    <div>
                        <label for="modal_end_date"><i class="fas fa-calendar-check"></i> Date de fin:</label>
                        <input type="date" name="end_date" id="modal_end_date">
                    </div>
                </div>
                <div>
                    <label>
                        <input type="checkbox" name="is_recurring" id="modal_is_recurring" checked>
                        <i class="fas fa-redo"></i> Cours récurrent (toutes les semaines)
                    </label>
                </div>
                <button type="submit" name="add_schedule" id="scheduleSubmitBtn"><i class="fas fa-save"></i> Enregistrer</button>
            </form>
        </div>
    </div>

    <!-- Modal pour ajouter/modifier une salle -->
    <div id="classroomModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('classroomModal')">&times;</span>
            <h2 id="classroomModalTitle"><i class="fas fa-plus-circle"></i> Ajouter une salle</h2>
            <form method="POST" action="" id="classroomForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <input type="hidden" name="classroom_id" id="classroom_id">
                <div>
                    <label for="modal_classroom_name"><i class="fas fa-door-open"></i> Nom de la salle:</label>
                    <input type="text" name="name" id="modal_classroom_name" required>
                </div>
                <div>
                    <label for="modal_capacity"><i class="fas fa-users"></i> Capacité:</label>
                    <input type="number" name="capacity" id="modal_capacity" min="1" required>
                </div>
                <div>
                    <label for="modal_building"><i class="fas fa-building"></i> Bâtiment:</label>
                    <input type="text" name="building" id="modal_building">
                </div>
                <div>
                    <label for="modal_description"><i class="fas fa-info-circle"></i> Description:</label>
                    <textarea name="description" id="modal_description" rows="3"></textarea>
                </div>
                <button type="submit" name="add_classroom" id="classroomSubmitBtn"><i class="fas fa-save"></i> Enregistrer</button>
            </form>
        </div>
    </div>

    <!-- Modal pour ajouter/modifier un créneau -->
    <div id="timeslotModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('timeslotModal')">&times;</span>
            <h2 id="timeslotModalTitle"><i class="fas fa-plus-circle"></i> Ajouter un créneau</h2>
            <form method="POST" action="" id="timeslotForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <input type="hidden" name="timeslot_id" id="timeslot_id">
                <div>
                    <label for="modal_slot_name"><i class="fas fa-tag"></i> Nom du créneau:</label>
                    <input type="text" name="slot_name" id="modal_slot_name" placeholder="ex: Créneau 1" required>
                </div>
                <div class="grid" style="grid-template-columns: 1fr 1fr;">
                    <div>
                        <label for="modal_start_time"><i class="fas fa-clock"></i> Heure de début:</label>
                        <input type="time" name="start_time" id="modal_start_time" required>
                    </div>
                    <div>
                        <label for="modal_end_time"><i class="fas fa-clock"></i> Heure de fin:</label>
                        <input type="time" name="end_time" id="modal_end_time" required>
                    </div>
                </div>
                <button type="submit" name="add_timeslot" id="timeslotSubmitBtn"><i class="fas fa-save"></i> Enregistrer</button>
            </form>
        </div>
    </div>
    
<!-- Modal de synchronisation des classes -->
<div id="syncModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <span class="close" onclick="closeModal('syncModal')">&times;</span>
        <h2 style="color: #4CAF50;"><i class="fas fa-sync"></i> Synchroniser les classes</h2>
        
        <div id="syncScheduleInfo"></div>
        
        <div style="background: rgba(255, 152, 0, 0.1); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <div style="color: #FF9800; font-weight: 600; margin-bottom: 8px;">
                <i class="fas fa-info-circle"></i> Classes manquantes
            </div>
            <p style="font-size: 0.9em; color: rgba(255, 255, 255, 0.8); margin: 0;">
                Les classes suivantes sont liées à ce cours mais ne sont pas encore programmées. 
                Sélectionnez celles que vous souhaitez ajouter et choisissez le créneau horaire et la salle :
            </p>
        </div>
        
        <!-- Liste des classes à synchroniser -->
        <div style="margin-bottom: 20px;">
            <h3 style="color: var(--text-light); font-size: 1.1em; margin-bottom: 10px;">
                <i class="fas fa-users"></i> Classes à ajouter
            </h3>
            <div id="syncClassList"></div>
        </div>
        
        <!-- Sélection du créneau horaire -->
        <div style="background: rgba(255, 255, 255, 0.05); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="color: var(--text-light); font-size: 1.1em; margin-bottom: 15px;">
                <i class="fas fa-calendar-alt"></i> Choisir le créneau horaire
            </h3>
            
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
                <div>
                    <label for="sync_weekday_id"><i class="fas fa-calendar-day"></i> Jour :</label>
                    <select name="weekday_id" id="sync_weekday_id" required>
                        <option value="">Sélectionner un jour</option>
                        <?php
                        $weekdays_result->data_seek(0);
                        while ($weekday = $weekdays_result->fetch_assoc()) {
                            echo "<option value='{$weekday['id']}'>{$weekday['name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label for="sync_timeslot_id"><i class="fas fa-clock"></i> Créneau horaire :</label>
                    <select name="time_slot_id" id="sync_timeslot_id" required>
                        <option value="">Sélectionner un créneau</option>
                        <?php
                        $time_slots_result->data_seek(0);
                        while ($time_slot = $time_slots_result->fetch_assoc()) {
                            echo "<option value='{$time_slot['id']}'>{$time_slot['name']} ({$time_slot['start_time']} - {$time_slot['end_time']})</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Sélection de la salle -->
        <div style="background: rgba(255, 255, 255, 0.05); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="color: var(--text-light); font-size: 1.1em; margin-bottom: 15px;">
                <i class="fas fa-door-open"></i> Choisir la salle
            </h3>
            
            <div>
                <label for="sync_classroom_id"><i class="fas fa-building"></i> Salle :</label>
                <select name="classroom_id" id="sync_classroom_id" required>
                    <option value="">Sélectionner une salle</option>
                    <?php
                    $classrooms_result->data_seek(0);
                    while ($classroom = $classrooms_result->fetch_assoc()) {
                        echo "<option value='{$classroom['id']}'>{$classroom['name']} ({$classroom['building']}, {$classroom['capacity']} places)</option>";
                    }
                    ?>
                </select>
            </div>
        </div>
        
        <!-- Dates (optionnel) -->
        <div style="background: rgba(255, 255, 255, 0.05); padding: 20px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="color: var(--text-light); font-size: 1.1em; margin-bottom: 15px;">
                <i class="fas fa-calendar-check"></i> Période (optionnel)
            </h3>
            
            <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 15px;">
                <div>
                    <label for="sync_start_date"><i class="fas fa-calendar-alt"></i> Date de début :</label>
                    <input type="date" name="start_date" id="sync_start_date">
                </div>
                <div>
                    <label for="sync_end_date"><i class="fas fa-calendar-check"></i> Date de fin :</label>
                    <input type="date" name="end_date" id="sync_end_date">
                </div>
            </div>
            
            <div style="margin-top: 15px;">
                <label style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" name="is_recurring" id="sync_is_recurring" checked style="width: 20px; height: 20px;">
                    <span><i class="fas fa-redo"></i> Cours récurrent (toutes les semaines)</span>
                </label>
            </div>
        </div>
        
        <!-- Boutons d'action -->
        <div style="display: flex; gap: 10px;">
            <button id="confirmSyncBtn" style="flex: 1; background-color: #4CAF50;">
                <i class="fas fa-check"></i> Synchroniser
            </button>
            <button onclick="closeModal('syncModal')" style="flex: 1; background-color: #757575;">
                <i class="fas fa-times"></i> Annuler
            </button>
        </div>
    </div>
</div>
<!-- Ajouter cette modale dans votre HTML, juste avant la fermeture du </body> -->

<!-- Modal de confirmation de conflit avec 3 options -->
<div id="conflictChoiceModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <h2 style="color: #FF9800; margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i> Conflits détectés
        </h2>
        
        <div id="conflictChoiceMessage" style="background: rgba(255, 152, 0, 0.1); padding: 15px; border-radius: 8px; margin-bottom: 20px; white-space: pre-line; line-height: 1.6;">
        </div>
        
        <div style="background: rgba(255, 255, 255, 0.05); padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <h3 style="color: var(--text-light); font-size: 1.1em; margin-bottom: 15px;">
                <i class="fas fa-question-circle"></i> Que voulez-vous faire ?
            </h3>
            
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button id="replaceConflictsBtn" style="background: linear-gradient(135deg, #4CAF50, #45a049); padding: 15px; display: flex; align-items: center; gap: 10px; justify-content: flex-start;">
                    <i class="fas fa-check-circle" style="font-size: 1.3em;"></i>
                    <div style="text-align: left;">
                        <div style="font-weight: 600;">REMPLACER</div>
                        <div style="font-size: 0.85em; opacity: 0.9;">Supprimer les cours existants et synchroniser</div>
                    </div>
                </button>
                
                <button id="ignoreConflictsBtn" style="background: linear-gradient(135deg, #FF9800, #F57C00); padding: 15px; display: flex; align-items: center; gap: 10px; justify-content: flex-start;">
                    <i class="fas fa-forward" style="font-size: 1.3em;"></i>
                    <div style="text-align: left;">
                        <div style="font-weight: 600;">IGNORER</div>
                        <div style="font-size: 0.85em; opacity: 0.9;">Synchroniser uniquement les classes disponibles</div>
                    </div>
                </button>
                
                <button id="cancelConflictsBtn" style="background: linear-gradient(135deg, #757575, #616161); padding: 15px; display: flex; align-items: center; gap: 10px; justify-content: flex-start;">
                    <i class="fas fa-times-circle" style="font-size: 1.3em;"></i>
                    <div style="text-align: left;">
                        <div style="font-weight: 600;">ANNULER</div>
                        <div style="font-size: 0.85em; opacity: 0.9;">Ne rien faire</div>
                    </div>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Variables globales pour stocker les données de conflit
let conflictScheduleId = null;
let conflictClassIds = null;
let conflictScheduleParams = null;

// Afficher la modale de confirmation de remplacement avec 3 options
function showConflictModal(scheduleId, classIds, conflicts, scheduleParams) {
    // Stocker les données pour les utiliser dans les callbacks
    conflictScheduleId = scheduleId;
    conflictClassIds = classIds;
    conflictScheduleParams = scheduleParams;
    
    let conflictMessage = `⚠️ ${conflicts.length} classe(s) ont déjà un cours à ce créneau :\n\n`;
    
    conflicts.forEach(conflict => {
        conflictMessage += `• ${conflict.class_name}\n`;
        conflictMessage += `  → ${conflict.conflicting_course}\n`;
        conflictMessage += `  → Prof: ${conflict.conflicting_teacher}\n`;
        conflictMessage += `  → Salle: ${conflict.conflicting_classroom}\n\n`;
    });
    
    document.getElementById('conflictChoiceMessage').textContent = conflictMessage;
    
    // Ouvrir la modale
    document.getElementById('conflictChoiceModal').style.display = 'block';
}

// Attacher les événements aux boutons
document.addEventListener('DOMContentLoaded', function() {
    // Bouton REMPLACER
    document.getElementById('replaceConflictsBtn').addEventListener('click', function() {
        closeModal('conflictChoiceModal');
        performSync(conflictScheduleId, conflictClassIds, 1, conflictScheduleParams);
    });
    
    // Bouton IGNORER
    document.getElementById('ignoreConflictsBtn').addEventListener('click', function() {
        closeModal('conflictChoiceModal');
        performSync(conflictScheduleId, conflictClassIds, 0, conflictScheduleParams);
    });
    
    // Bouton ANNULER
    document.getElementById('cancelConflictsBtn').addEventListener('click', function() {
        closeModal('conflictChoiceModal');
        // Ne rien faire, juste fermer la modale
    });
});
</script>
    <?php include '../includes/footer.php'; ?>

    <script>
        // Gestion des onglets
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    const target = tab.getAttribute('data-tab');
                    
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    
                    tab.classList.add('active');
                    document.getElementById(target).classList.add('active');
                });
            });

            // Attacher les écouteurs pour la détection de conflits
            ['modal_classroom_id', 'modal_weekday_id', 'modal_time_slot_id'].forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.addEventListener('change', checkConflicts);
                }
            });
        });

        // Gestion des modales
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Modal Cours - Ajouter
function openAddScheduleModal() {
    document.getElementById('scheduleModalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Ajouter un cours';
    document.getElementById('scheduleForm').reset();
    document.getElementById('schedule_id').value = '';
    document.getElementById('scheduleSubmitBtn').name = 'add_schedule';
    document.getElementById('scheduleSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Ajouter';
    
    // Réactiver les champs
    document.getElementById('modal_course_id').disabled = false;
    document.getElementById('modal_course_id').style.opacity = '1';
    document.getElementById('modal_teacher_id').disabled = false;
    document.getElementById('modal_teacher_id').style.opacity = '1';
    
    // Masquer le champ classe unique
    const classField = document.getElementById('modal_class_id').closest('div');
    if (classField) {
        classField.style.display = 'none';
    }
    
    // Afficher/masquer les infos appropriées
    document.getElementById('course-lock-info').style.display = 'none';
    document.getElementById('teacher-auto-info').style.display = 'block';
    
    document.getElementById('smartSuggestion').classList.remove('active');
    document.getElementById('conflictWarning').classList.remove('active');
    
    // Activer le remplissage automatique
    document.getElementById('modal_course_id').addEventListener('change', autoFillCourseDataForAdd);
    
    openModal('scheduleModal');
}

// Nouvelle fonction pour l'ajout
async function autoFillCourseDataForAdd() {
    const courseId = document.getElementById('modal_course_id').value;
    if (!courseId) {
        document.getElementById('modal_teacher_id').disabled = false;
        document.getElementById('modal_teacher_id').style.opacity = '1';
        document.getElementById('smartSuggestion').classList.remove('active');
        return;
    }
    
    try {
        const response = await fetch(`../api/get_course_data.php?course_id=${courseId}`);
        const data = await response.json();
        
        if (data.success) {
            // Remplir l'enseignant
            document.getElementById('modal_teacher_id').value = data.teacher_id;
            
            // Désactiver le champ enseignant
            document.getElementById('modal_teacher_id').disabled = true;
            document.getElementById('modal_teacher_id').style.opacity = '0.6';
            
            // Afficher les informations des classes
            const suggestionDiv = document.getElementById('smartSuggestion');
            const suggestionContent = document.getElementById('suggestionContent');
            
            let html = `<strong>Ce cours sera ajouté automatiquement pour ${data.classes.length} classe(s) :</strong><br>`;
            html += '<div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px;">';
            data.classes.forEach(cls => {
                html += `<span style="background: rgba(3, 155, 229, 0.2); padding: 5px 12px; border-radius: 15px; font-size: 0.9em;">
                    <i class="fas fa-users"></i> ${cls.name}
                </span>`;
            });
            html += '</div>';
            html += `<div style="margin-top: 10px; font-size: 0.85em; color: rgba(255, 255, 255, 0.7);">
                <i class="fas fa-info-circle"></i> Tous ces groupes auront ce cours dans la même salle au même créneau horaire.
            </div>`;
            
            suggestionContent.innerHTML = html;
            suggestionDiv.classList.add('active');
        }
    } catch (error) {
        console.error('Erreur lors de la récupération des données du cours:', error);
    }
}
        // Fonction pour remplir automatiquement les données du cours
        async function autoFillCourseData() {
            const courseId = document.getElementById('modal_course_id').value;
            if (!courseId) {
                document.getElementById('modal_teacher_id').disabled = false;
                document.getElementById('modal_teacher_id').style.opacity = '1';
                document.getElementById('smartSuggestion').classList.remove('active');
                return;
            }
            
            try {
                const response = await fetch(`../api/get_course_data.php?course_id=${courseId}`);
                const data = await response.json();
                
                if (data.success) {
                    // Remplir l'enseignant
                    document.getElementById('modal_teacher_id').value = data.teacher_id;
                    
                    // Remplir les classes disponibles
                    const classSelect = document.getElementById('modal_class_id');
                    classSelect.innerHTML = '<option value="">Sélectionner une classe</option>';
                    data.classes.forEach(cls => {
                        const option = document.createElement('option');
                        option.value = cls.id;
                        option.textContent = cls.name;
                        classSelect.appendChild(option);
                    });
                    
                    // Désactiver le champ enseignant (il est lié au cours)
                    document.getElementById('modal_teacher_id').disabled = true;
                    document.getElementById('modal_teacher_id').style.opacity = '0.6';
                    
                    // Afficher les suggestions
                    if (data.suggestions.length > 0) {
                        const suggestionDiv = document.getElementById('smartSuggestion');
                        const suggestionContent = document.getElementById('suggestionContent');
                        
                        let html = `<strong>Créneaux recommandés pour "${data.course_name}" :</strong><br>`;
                        data.suggestions.slice(0, 3).forEach(sugg => {
                            html += `<div style="margin-top: 5px;">
                                <i class="fas fa-check-circle" style="color: #4CAF50;"></i> 
                                ${sugg.weekday} - ${sugg.timeslot}
                            </div>`;
                        });
                        
                        if (data.occupied_slots.length > 0) {
                            html += `<br><strong>Créneaux déjà programmés :</strong><br>`;
                            data.occupied_slots.forEach(slot => {
                                html += `<div style="margin-top: 5px; color: rgba(255, 255, 255, 0.6);">
                                    <i class="fas fa-calendar-check"></i> 
                                    ${slot.weekday} - ${slot.timeslot} (${slot.classroom})
                                </div>`;
                            });
                        }
                        
                        suggestionContent.innerHTML = html;
                        suggestionDiv.classList.add('active');
                    }
                }
            } catch (error) {
                console.error('Erreur lors de la récupération des données du cours:', error);
            }
        }

        // Vérifier les conflits en temps réel
        async function checkConflicts() {
            const classroomId = document.getElementById('modal_classroom_id').value;
            const weekdayId = document.getElementById('modal_weekday_id').value;
            const timeslotId = document.getElementById('modal_time_slot_id').value;
            const scheduleId = document.getElementById('schedule_id').value;
            
            if (!classroomId || !weekdayId || !timeslotId) {
                document.getElementById('conflictWarning').classList.remove('active');
                return;
            }
            
            try {
                const response = await fetch(`../api/check_schedule_conflict.php?classroom_id=${classroomId}&weekday_id=${weekdayId}&timeslot_id=${timeslotId}&schedule_id=${scheduleId}`);
                const data = await response.json();
                
                if (data.conflict) {
                    const conflictDiv = document.getElementById('conflictWarning');
                    const conflictContent = document.getElementById('conflictContent');
                    
                    conflictContent.innerHTML = `
                        <strong>Ce créneau est déjà occupé !</strong><br>
                        <div style="margin-top: 8px;">
                            <i class="fas fa-book"></i> Cours : ${data.conflict.course_name}<br>
                            <i class="fas fa-chalkboard-teacher"></i> Enseignant : ${data.conflict.teacher_name}<br>
                            <i class="fas fa-users"></i> Classe : ${data.conflict.class_name}
                        </div>
                    `;
                    conflictDiv.classList.add('active');
                } else {
                    document.getElementById('conflictWarning').classList.remove('active');
                }
            } catch (error) {
                console.error('Erreur lors de la vérification des conflits:', error);
            }
        }

// Vérifier et synchroniser les classes
async function checkAndSyncClasses(scheduleId) {
    try {
        const response = await fetch(`../api/sync_schedule_classes.php?action=detect_missing&schedule_id=${scheduleId}`);
        const data = await response.json();
        
        if (!data.success) {
            alert('Erreur : ' + data.message);
            return;
        }
        
        if (!data.has_missing) {
            alert('✅ Toutes les classes du cours sont déjà programmées.');
            return;
        }
        
        // Afficher la modale de synchronisation
        showSyncModal(scheduleId, data);
        
    } catch (error) {
        console.error('Erreur:', error);
        alert('Erreur lors de la vérification des classes.');
    }
}

// Afficher la modale de synchronisation
function showSyncModal(scheduleId, data) {
    const modal = document.getElementById('syncModal');
    const classListDiv = document.getElementById('syncClassList');
    const scheduleInfoDiv = document.getElementById('syncScheduleInfo');
    
    // Afficher les informations du cours
    scheduleInfoDiv.innerHTML = `
        <div style="background: rgba(3, 155, 229, 0.1); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
            <strong style="color: #039be5; font-size: 1.1em;">
                <i class="fas fa-book"></i> ${data.schedule_info.course_name}
            </strong>
            <div style="margin-top: 8px; font-size: 0.9em; color: rgba(255, 255, 255, 0.7);">
                <i class="fas fa-chalkboard-teacher"></i> Enseignant : ${data.schedule_info.teacher_id}
            </div>
        </div>
        <div style="background: rgba(76, 175, 80, 0.1); padding: 12px; border-radius: 8px; margin-bottom: 15px; border-left: 3px solid #4CAF50;">
            <strong style="color: #4CAF50;">
                <i class="fas fa-info-circle"></i> Classe de référence
            </strong>
            <div style="margin-top: 5px; color: rgba(255, 255, 255, 0.8);">
                ${data.reference_class_name}
            </div>
            <small style="font-size: 0.85em; color: rgba(255, 255, 255, 0.6); display: block; margin-top: 5px;">
                Cette classe sera automatiquement synchronisée avec les autres
            </small>
        </div>
    `;
    
    // Afficher la liste des classes manquantes (+ classe de référence)
    let classesHtml = '<div style="display: flex; flex-direction: column; gap: 10px;">';
    
    // Ajouter la classe de référence en premier (toujours cochée et désactivée)
    classesHtml += `
        <label style="display: flex; align-items: center; gap: 10px; padding: 10px; background: rgba(76, 175, 80, 0.1); border-radius: 5px; border: 1px solid rgba(76, 175, 80, 0.3);">
            <input type="checkbox" class="sync-class-checkbox" value="${data.reference_class_id}" checked disabled style="width: 20px; height: 20px;">
            <span style="flex: 1;"><i class="fas fa-users"></i> ${data.reference_class_name} <strong>(référence)</strong></span>
        </label>
    `;
    
    // Ajouter les classes manquantes
    data.missing_classes.forEach(cls => {
        classesHtml += `
            <label style="display: flex; align-items: center; gap: 10px; padding: 10px; background: rgba(255, 255, 255, 0.05); border-radius: 5px; cursor: pointer; transition: all 0.3s;">
                <input type="checkbox" class="sync-class-checkbox" value="${cls.id}" checked style="width: 20px; height: 20px;">
                <span style="flex: 1;"><i class="fas fa-users"></i> ${cls.name}</span>
            </label>
        `;
    });
    classesHtml += '</div>';
    
    classListDiv.innerHTML = classesHtml;
    
    // Pré-remplir les champs
    document.getElementById('sync_weekday_id').value = data.schedule_info.weekday_id || '';
    document.getElementById('sync_timeslot_id').value = data.schedule_info.time_slot_id || '';
    document.getElementById('sync_classroom_id').value = data.schedule_info.classroom_id || '';
    document.getElementById('sync_start_date').value = data.schedule_info.start_date || '';
    document.getElementById('sync_end_date').value = data.schedule_info.end_date || '';
    document.getElementById('sync_is_recurring').checked = data.schedule_info.is_recurring == 1;
    
    // Stocker l'ID du planning dans le bouton de confirmation
    document.getElementById('confirmSyncBtn').onclick = () => performSyncWithConflictCheck(scheduleId);
    
    modal.style.display = 'block';
}

// Vérifier les conflits avant de synchroniser
async function performSyncWithConflictCheck(scheduleId) {
    const checkboxes = document.querySelectorAll('.sync-class-checkbox:checked');
    const classIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
    
    const classroomId = document.getElementById('sync_classroom_id').value;
    const weekdayId = document.getElementById('sync_weekday_id').value;
    const timeslotId = document.getElementById('sync_timeslot_id').value;
    const startDate = document.getElementById('sync_start_date').value;
    const endDate = document.getElementById('sync_end_date').value;
    const isRecurring = document.getElementById('sync_is_recurring').checked ? 1 : 0;
    
    // Validation
    if (classIds.length === 0) {
        alert('❌ Veuillez sélectionner au moins une classe à synchroniser.');
        return;
    }
    
    if (!classroomId || !weekdayId || !timeslotId) {
        alert('❌ Veuillez remplir tous les champs obligatoires (jour, créneau, salle).');
        return;
    }
    
    if (startDate && !endDate) {
        alert('❌ Veuillez spécifier une date de fin si vous avez spécifié une date de début.');
        return;
    }
    
    if (!startDate && endDate) {
        alert('❌ Veuillez spécifier une date de début si vous avez spécifié une date de fin.');
        return;
    }
    
    if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
        alert('❌ La date de début doit être antérieure à la date de fin.');
        return;
    }
    
    try {
        // Vérifier les conflits
        const formData = new FormData();
        formData.append('action', 'check_conflicts');
        formData.append('schedule_id', scheduleId);
        formData.append('class_ids', JSON.stringify(classIds));
        formData.append('classroom_id', classroomId);
        formData.append('weekday_id', weekdayId);
        formData.append('timeslot_id', timeslotId);
        formData.append('start_date', startDate);
        formData.append('end_date', endDate);
        
        const response = await fetch('../api/sync_schedule_classes.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.has_conflicts) {
            // Afficher la modale de confirmation de remplacement
            showConflictModal(scheduleId, classIds, data.conflicts, {
                classroomId, weekdayId, timeslotId, startDate, endDate, isRecurring
            });
        } else {
            // Pas de conflit, synchroniser directement
            performSync(scheduleId, classIds, 0, {
                classroomId, weekdayId, timeslotId, startDate, endDate, isRecurring
            });
        }
        
    } catch (error) {
        console.error('Erreur:', error);
        alert('❌ Erreur lors de la vérification des conflits.');
    }
}


// Effectuer la synchronisation
async function performSync(scheduleId, classIds, replaceConflicts, scheduleParams) {
    const btn = document.getElementById('confirmSyncBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Synchronisation en cours...';
    
    try {
        const formData = new FormData();
        formData.append('action', 'sync_classes');
        formData.append('schedule_id', scheduleId);
        formData.append('class_ids', JSON.stringify(classIds));
        formData.append('classroom_id', scheduleParams.classroomId);
        formData.append('weekday_id', scheduleParams.weekdayId);
        formData.append('timeslot_id', scheduleParams.timeslotId);
        formData.append('start_date', scheduleParams.startDate);
        formData.append('end_date', scheduleParams.endDate);
        formData.append('is_recurring', scheduleParams.isRecurring);
        formData.append('replace_conflicts', replaceConflicts);
        
        const response = await fetch('../api/sync_schedule_classes.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            let message = data.message + '\n\n';
            message += '📅 Créneau : ' + data.schedule_info.weekday + ' - ' + data.schedule_info.timeslot + '\n';
            message += '🏫 Salle : ' + data.schedule_info.classroom;
            
            alert(message);
            closeModal('syncModal');
            location.reload();
        } else {
            alert('❌ ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Synchroniser';
        }
        
    } catch (error) {
        console.error('Erreur:', error);
        alert('❌ Erreur lors de la synchronisation.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Synchroniser';
    }
}

// Modal Cours - Modifier
async function openEditScheduleModal(data) {
    document.getElementById('scheduleModalTitle').innerHTML = '<i class="fas fa-edit"></i> Modifier le cours';
    document.getElementById('schedule_id').value = data.id;
    document.getElementById('modal_course_id').value = data.course_id;
    document.getElementById('modal_teacher_id').value = data.teacher_id;
    document.getElementById('modal_classroom_id').value = data.classroom_id;
    document.getElementById('modal_class_id').value = data.class_id;
    document.getElementById('modal_weekday_id').value = data.weekday_id;
    document.getElementById('modal_time_slot_id').value = data.time_slot_id;
    document.getElementById('modal_start_date').value = data.start_date || '';
    document.getElementById('modal_end_date').value = data.end_date || '';
    document.getElementById('modal_is_recurring').checked = data.is_recurring == 1;
    document.getElementById('scheduleSubmitBtn').name = 'edit_schedule';
    document.getElementById('scheduleSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Modifier';
    
    // ✅ IMPORTANT : Le cours EST maintenant modifiable !
    // Le professeur se synchronisera automatiquement avec le cours choisi
    document.getElementById('modal_course_id').disabled = false;
    document.getElementById('modal_course_id').style.opacity = '1';
    
    // Le professeur est lecture seule (il vient du cours)
    document.getElementById('modal_teacher_id').disabled = true;
    document.getElementById('modal_teacher_id').style.opacity = '0.6';
    
    // Afficher le message informatif
    document.getElementById('course-lock-info').style.display = 'none'; // Masquer le message de verrouillage du cours
    document.getElementById('teacher-auto-info').innerHTML = '<i class="fas fa-info-circle"></i> L\'enseignant se met à jour automatiquement selon le cours sélectionné';
    document.getElementById('teacher-auto-info').style.display = 'block';
    
    // Charger les classes disponibles pour ce cours
    try {
        const response = await fetch(`../api/get_course_data.php?course_id=${data.course_id}`);
        const courseData = await response.json();
        
        if (courseData.success) {
            const classSelect = document.getElementById('modal_class_id');
            classSelect.innerHTML = '<option value="">Sélectionner une classe</option>';
            courseData.classes.forEach(cls => {
                const option = document.createElement('option');
                option.value = cls.id;
                option.textContent = cls.name;
                if (cls.id == data.class_id) {
                    option.selected = true;
                }
                classSelect.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Erreur lors du chargement des classes:', error);
    }
    
    // Ajouter l'écouteur d'événement pour mettre à jour le professeur quand le cours change
    document.getElementById('modal_course_id').addEventListener('change', function() {
        autoFillCourseData();
    });
    
    // Afficher les informations de modification
    const suggestionDiv = document.getElementById('smartSuggestion');
    const suggestionContent = document.getElementById('suggestionContent');
    suggestionContent.innerHTML = `
        <strong>Mode modification :</strong><br>
        <div style="margin-top: 8px; line-height: 1.6;">
            ✅ Modifiables : <strong>Cours, Salle, Classe, Créneau horaire, Dates</strong><br>
            🔄 Auto-sync : <strong>Professeur</strong> (lié au cours choisi)
        </div>
    `;
    suggestionDiv.classList.add('active');
    
    // Masquer l'alerte de conflit au départ
    document.getElementById('conflictWarning').classList.remove('active');
    
    openModal('scheduleModal');
}

// Fonction pour remplir automatiquement les données du cours
async function autoFillCourseData() {
    const courseId = document.getElementById('modal_course_id').value;
    const scheduleId = document.getElementById('schedule_id').value;
    
    if (!courseId) {
        document.getElementById('modal_teacher_id').value = '';
        document.getElementById('modal_teacher_id').disabled = false;
        document.getElementById('modal_teacher_id').style.opacity = '1';
        document.getElementById('smartSuggestion').classList.remove('active');
        return;
    }
    
    try {
        const response = await fetch(`../api/get_course_data.php?course_id=${courseId}`);
        const data = await response.json();
        
        if (data.success) {
            // Remplir l'enseignant (depuis le cours)
            document.getElementById('modal_teacher_id').value = data.teacher_id;
            
            // Remplir les classes disponibles pour ce cours
            const classSelect = document.getElementById('modal_class_id');
            classSelect.innerHTML = '<option value="">Sélectionner une classe</option>';
            data.classes.forEach(cls => {
                const option = document.createElement('option');
                option.value = cls.id;
                option.textContent = cls.name;
                classSelect.appendChild(option);
            });
            
            // Le champ enseignant reste désactivé car il est lié au cours
            document.getElementById('modal_teacher_id').disabled = true;
            document.getElementById('modal_teacher_id').style.opacity = '0.6';
            
            // Afficher les suggestions
            if (data.suggestions && data.suggestions.length > 0) {
                const suggestionDiv = document.getElementById('smartSuggestion');
                const suggestionContent = document.getElementById('suggestionContent');
                
                let html = `<strong>Créneaux recommandés pour "${data.course_name}" :</strong><br>`;
                data.suggestions.slice(0, 3).forEach(sugg => {
                    html += `<div style="margin-top: 5px;">
                        <i class="fas fa-check-circle" style="color: #4CAF50;"></i> 
                        ${sugg.weekday} - ${sugg.timeslot}
                    </div>`;
                });
                
                if (data.occupied_slots && data.occupied_slots.length > 0) {
                    html += `<br><strong>Créneaux déjà programmés :</strong><br>`;
                    data.occupied_slots.forEach(slot => {
                        html += `<div style="margin-top: 5px; color: rgba(255, 255, 255, 0.6);">
                            <i class="fas fa-calendar-check"></i> 
                            ${slot.weekday} - ${slot.timeslot} (${slot.classroom})
                        </div>`;
                    });
                }
                
                suggestionContent.innerHTML = html;
                suggestionDiv.classList.add('active');
            }
        }
    } catch (error) {
        console.error('Erreur lors de la récupération des données du cours:', error);
    }
}
        // Supprimer un cours
        function deleteSchedule(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer ce cours de l\'emploi du temps ?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="schedule_id" value="${id}">
                    <input type="hidden" name="delete_schedule" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Modal Salle - Ajouter
        function openAddClassroomModal() {
            document.getElementById('classroomModalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Ajouter une salle';
            document.getElementById('classroomForm').reset();
            document.getElementById('classroom_id').value = '';
            document.getElementById('classroomSubmitBtn').name = 'add_classroom';
            document.getElementById('classroomSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Ajouter';
            openModal('classroomModal');
        }

        // Modal Salle - Modifier
        function openEditClassroomModal(data) {
            document.getElementById('classroomModalTitle').innerHTML = '<i class="fas fa-edit"></i> Modifier la salle';
            document.getElementById('classroom_id').value = data.id;
            document.getElementById('modal_classroom_name').value = data.name;
            document.getElementById('modal_capacity').value = data.capacity;
            document.getElementById('modal_building').value = data.building;
            document.getElementById('modal_description').value = data.description;
            document.getElementById('classroomSubmitBtn').name = 'edit_classroom';
            document.getElementById('classroomSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Modifier';
            openModal('classroomModal');
        }

        // Supprimer une salle
        function deleteClassroom(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette salle ?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="classroom_id" value="${id}">
                    <input type="hidden" name="delete_classroom" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Modal Créneau - Ajouter
        function openAddTimeslotModal() {
            document.getElementById('timeslotModalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Ajouter un créneau';
            document.getElementById('timeslotForm').reset();
            document.getElementById('timeslot_id').value = '';
            document.getElementById('timeslotSubmitBtn').name = 'add_timeslot';
            document.getElementById('timeslotSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Ajouter';
            openModal('timeslotModal');
        }

        // Modal Créneau - Modifier
        function openEditTimeslotModal(data) {
            document.getElementById('timeslotModalTitle').innerHTML = '<i class="fas fa-edit"></i> Modifier le créneau';
            document.getElementById('timeslot_id').value = data.id;
            document.getElementById('modal_slot_name').value = data.name;
            document.getElementById('modal_start_time').value = data.start_time;
            document.getElementById('modal_end_time').value = data.end_time;
            document.getElementById('timeslotSubmitBtn').name = 'edit_timeslot';
            document.getElementById('timeslotSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Modifier';
            openModal('timeslotModal');
        }

        // Supprimer un créneau
        function deleteTimeslot(id) {
            if (confirm('Êtes-vous sûr de vouloir supprimer ce créneau horaire ?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="timeslot_id" value="${id}">
                    <input type="hidden" name="delete_timeslot" value="1">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Validation des formulaires
        document.getElementById('scheduleForm').addEventListener('submit', function(e) {
            const startDate = document.getElementById('modal_start_date').value;
            const endDate = document.getElementById('modal_end_date').value;
            
            if (startDate && !endDate) {
                alert("Veuillez spécifier une date de fin si vous avez spécifié une date de début.");
                e.preventDefault();
                return false;
            }
            
            if (!startDate && endDate) {
                alert("Veuillez spécifier une date de début si vous avez spécifié une date de fin.");
                e.preventDefault();
                return false;
            }
            
            if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                alert("La date de début doit être antérieure à la date de fin.");
                e.preventDefault();
                return false;
            }
        });

        document.getElementById('timeslotForm').addEventListener('submit', function(e) {
            const startTime = document.getElementById('modal_start_time').value;
            const endTime = document.getElementById('modal_end_time').value;
            
            if (startTime && endTime && startTime >= endTime) {
                alert("L'heure de début doit être antérieure à l'heure de fin.");
                e.preventDefault();
                return false;
            }
        });

        // Fonction de recherche dans le tableau
        function filterScheduleTable() {
            const input = document.getElementById('search-schedule');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('schedule-table');
            const tr = table.getElementsByTagName('tr');
            
            for (let i = 1; i < tr.length; i++) {
                const row = tr[i];
                const cells = row.getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < cells.length - 1; j++) {
                    const cell = cells[j];
                    if (cell) {
                        const textValue = cell.textContent || cell.innerText;
                        if (textValue.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                row.style.display = found ? '' : 'none';
            }
        }

// Alternative : Ouvrir une fenêtre d'impression dédiée
function printScheduleInNewWindow() {
    const printWindow = window.open('', '_blank', 'width=1200,height=800');
    
    if (!printWindow) {
        alert('Veuillez autoriser les pop-ups pour imprimer');
        return;
    }
    
    // Récupérer le contenu de l'emploi du temps
    const scheduleContent = document.querySelector('.timetable-wrapper');
    
    if (!scheduleContent) {
        alert('Emploi du temps non trouvé');
        return;
    }
    
    // Compter le nombre de colonnes (jours de la semaine)
    const weekdayHeaders = document.querySelectorAll('.timetable-header');
    const weekdaysCount = weekdayHeaders.length - 1; // -1 pour exclure l'en-tête "Horaire"
    
    // Formater la date
    const currentDate = new Date().toLocaleDateString('fr-FR', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
    
    // Créer le document d'impression
    const printContent = '<!DOCTYPE html>' +
        '<html lang="fr">' +
        '<head>' +
        '<meta charset="UTF-8">' +
        '<title>Emploi du Temps - ' + new Date().toLocaleDateString('fr-FR') + '</title>' +
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">' +
        '<style>' +
        '@page { size: A4 landscape; margin: 10mm; }' +
        '* { margin: 0; padding: 0; box-sizing: border-box; }' +
        'body { font-family: Arial, sans-serif; background: white; color: black; padding: 10mm; }' +
        'h1 { text-align: center; color: #051e34; margin-bottom: 8mm; font-size: 18pt; border-bottom: 2px solid #039be5; padding-bottom: 3mm; }' +
        '.date { text-align: right; font-size: 9pt; color: #666; margin-bottom: 5mm; }' +
        '.timetable { display: grid; grid-template-columns: 60px repeat(' + weekdaysCount + ', 1fr); gap: 0; border: 2px solid #000; width: 100%; }' +
        '.timetable-header { background: #e0e0e0; padding: 4mm 2mm; text-align: center; font-weight: bold; font-size: 9pt; border: 1px solid #000; border-bottom: 2px solid #000; }' +
        '.timetable-time { background: #f5f5f5; padding: 2mm 1mm; text-align: center; font-weight: bold; font-size: 7pt; border: 1px solid #000; border-right: 2px solid #000; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 1mm; }' +
        '.timetable-cell { background: white; padding: 1.5mm; border: 1px solid #ccc; min-height: 50px; }' +
        '.timetable-cell.has-class { background: #f0f7ff; }' +
        '.timetable-entry { font-size: 7pt; padding: 2mm; border-radius: 2mm; background: #e3f2fd; border-left: 2px solid #1976d2; margin-bottom: 1.5mm; line-height: 1.3; }' +
        '.timetable-entry:last-child { margin-bottom: 0; }' +
        '.timetable-entry strong { display: block; margin-bottom: 1mm; font-size: 8pt; font-weight: bold; line-height: 1.2; }' +
        '.timetable-entry-info { font-size: 6.5pt; line-height: 1.4; color: #333; }' +
        '.timetable-entry-info div { margin-bottom: 0.5mm; }' +
        '.timetable-entry-info i { margin-right: 1mm; color: #555; font-size: 6pt; }' +
        '.timetable-entry-actions { display: none !important; }' +
        '@media print { body { padding: 0; } }' +
        '</style>' +
        '</head>' +
        '<body>' +
        '<h1>Emploi du Temps - Université Virtuelle</h1>' +
        '<div class="date">Imprimé le ' + currentDate + '</div>' +
        scheduleContent.innerHTML +
        '<script>' +
        'window.onload = function() {' +
        '  document.querySelectorAll(".timetable-entry-actions").forEach(el => el.remove());' +
        '  setTimeout(() => window.print(), 500);' +
        '};' +
        '<\/script>' +
        '</body>' +
        '</html>';
    
    printWindow.document.write(printContent);
    printWindow.document.close();
}

        // Auto-fermeture des messages après 5 secondes
        setTimeout(function() {
            const messages = document.querySelectorAll('.error, .success');
            messages.forEach(msg => {
                msg.style.transition = 'opacity 0.5s';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>