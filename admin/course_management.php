<?php
require_once '../includes/functions.php';

error_reporting(E_ERROR | E_PARSE);

session_start();

if (!file_exists('../includes/db_connect.php')) {
    die("Erreur: Fichier de connexion à la base de données introuvable.");
}

require_once '../includes/db_connect.php';

// Importer SendGrid
require_once '../vendor/autoload.php';
use SendGrid\Mail\Mail;

$current_admin_id = $_SESSION['user_id'];

if (!isset($conn) || $conn->connect_error) {
    die("Erreur de connexion à la base de données: " . (isset($conn) ? $conn->connect_error : "Connexion non établie"));
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

// Configuration SendGrid
define('SENDGRID_FROM_EMAIL', 'contact@uvcoding.com');
define('SENDGRID_FROM_NAME', 'Université Virtuelle');
define('SENDGRID_DISCUSSION_TEMPLATE', 'd-6125ebdeb75043a9a4ade8426530a0f1');

// ============================================================
// FONCTION POUR CRÉER UNE NOUVELLE CONNEXION MYSQL
// ============================================================
function createNewConnection() {
    // Config .env via includes/db_config.php (déjà chargé par db_connect.php)
    try {
        return get_db_connection();
    } catch (RuntimeException $e) {
        error_log("❌ Échec de connexion: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// FONCTION SENDGRID POUR ENVOYER LES EMAILS
// ============================================================
function sendEmailWithSendGrid($to_email, $to_name, $template_id, $dynamic_data) {
    try {
        $email = new Mail();
        $email->setFrom(SENDGRID_FROM_EMAIL, SENDGRID_FROM_NAME);
        $email->addTo($to_email, $to_name);
        $email->setTemplateId($template_id);
        $email->addDynamicTemplateDatas($dynamic_data);
        $email->setReplyTo(SENDGRID_FROM_EMAIL, SENDGRID_FROM_NAME);
        $email->addCategory('course_discussion');
        
        $sg_res = $GLOBALS['conn']->query("SELECT valeur FROM parametres WHERE cle='sendgrid_api_key' LIMIT 1");
        $sg_key = $sg_res ? trim($sg_res->fetch_assoc()['valeur'] ?? '') : '';
        if (empty($sg_key)) { return ['success' => false, 'message' => 'Clé API SendGrid non configurée']; }
        $sendgrid = new \SendGrid($sg_key);
        $response = $sendgrid->send($email);

        if ($response->statusCode() == 202) {
            return ['success' => true, 'message' => 'Email envoyé avec succès'];
        } else {
            error_log("SendGrid Error: " . $response->statusCode());
            return ['success' => false, 'message' => 'Erreur SendGrid: ' . $response->statusCode()];
        }
    } catch (Exception $e) {
        error_log("SendGrid Exception: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// ============================================================
// FONCTION OPTIMISÉE POUR ENVOYER DES EMAILS PAR LOTS VIA SENDGRID
// ============================================================
function sendEmailToStudents($conn, $course_id, $course_name, $sender_name, $action_type, $message_preview = '') {
    // Créer une nouvelle connexion dédiée pour les emails
    $email_conn = createNewConnection();
    if (!$email_conn) {
        error_log("❌ Impossible de créer une connexion pour les emails");
        return false;
    }
    
    // Récupérer les IDs de classes du cours
    $sql_classes = "SELECT class_id FROM courses WHERE id = ?";
    $stmt_classes = $email_conn->prepare($sql_classes);
    if (!$stmt_classes) {
        error_log("❌ Erreur préparation requête: " . $email_conn->error);
        $email_conn->close();
        return false;
    }
    
    $stmt_classes->bind_param("i", $course_id);
    $stmt_classes->execute();
    $result_classes = $stmt_classes->get_result();

    if ($result_classes && $row_class = $result_classes->fetch_assoc()) {
        $class_ids_json = $row_class['class_id'];
        
        error_log("=== ENVOI EMAIL VIA SENDGRID (ADMIN) ===");
        error_log("Cours ID: $course_id - Nom: $course_name");
        error_log("Type d'action: $action_type");
        error_log("Expéditeur: $sender_name");
        
        // Nettoyage robuste du JSON
        $clean_json = $class_ids_json;
        $clean_json = preg_replace('/"\s*([^"]*?)\s*"/', '"$1"', $clean_json);
        $clean_json = preg_replace('/\s*,\s*/', ',', $clean_json);
        $clean_json = preg_replace('/\[\s*/', '[', $clean_json);
        $clean_json = preg_replace('/\s*\]/', ']', $clean_json);
        
        $class_ids = json_decode($clean_json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            preg_match_all('/["\']?\s*(\d+)\s*["\']?/', $class_ids_json, $matches);
            if (!empty($matches[1])) {
                $class_ids = $matches[1];
            } else {
                error_log("Impossible d'extraire les IDs de classes");
                $stmt_classes->close();
                $email_conn->close();
                return false;
            }
        }

        if (is_array($class_ids) && count($class_ids) > 0) {
            // Nettoyer et convertir les IDs en entiers
            $clean_class_ids = array();
            foreach ($class_ids as $id) {
                $clean_id = intval(preg_replace('/[^0-9]/', '', $id));
                if ($clean_id > 0) {
                    $clean_class_ids[] = $clean_id;
                }
            }
            
            error_log("IDs de classes finaux: " . implode(', ', $clean_class_ids));
            
            if (count($clean_class_ids) > 0) {
                // Récupérer tous les emails
                $placeholders = implode(',', array_fill(0, count($clean_class_ids), '?'));
                $sql_emails = "
                    SELECT email, name, class_id
                    FROM users 
                    WHERE role = 'student' 
                    AND class_id IN ($placeholders)
                    AND email IS NOT NULL 
                    AND email != ''
                    ORDER BY class_id, name
                ";

                $stmt_emails = $email_conn->prepare($sql_emails);
                if ($stmt_emails) {
                    $stmt_emails->bind_param(str_repeat('i', count($clean_class_ids)), ...$clean_class_ids);
                    $stmt_emails->execute();
                    $result_emails = $stmt_emails->get_result();

                    error_log("Nombre total d'étudiants trouvés: " . $result_emails->num_rows);

                    if ($result_emails && $result_emails->num_rows > 0) {
                        // Collecter tous les étudiants
                        $all_students = [];
                        while ($row_email = $result_emails->fetch_assoc()) {
                            $all_students[] = $row_email;
                        }
                        
                        // Déterminer l'URL de connexion
                        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                        $host = $_SERVER['HTTP_HOST'];
                        $course_url = $protocol . "://" . $host . "/pages/manage_discussions.php?course_id=" . $course_id;
                        
                        // Configuration optimisée pour SendGrid
                        $batch_size = 50;
                        $pause_between_batches = 100000;
                        
                        $total_students = count($all_students);
                        $total_batches = ceil($total_students / $batch_size);
                        
                        error_log("📊 CONFIGURATION ENVOI:");
                        error_log("- Total étudiants: $total_students");
                        error_log("- Taille de lot: $batch_size");
                        error_log("- Nombre de lots: $total_batches");
                        
                        // Préparer le message d'aperçu
                        $message_preview = substr($message_preview, 0, 100);
                        if (strlen($message_preview) == 100) {
                            $message_preview .= "...";
                        }
                        
                        $emails_sent = 0;
                        $emails_failed = 0;
                        
                        // Traiter par lots
                        for ($batch = 0; $batch < $total_batches; $batch++) {
                            $start_index = $batch * $batch_size;
                            $batch_students = array_slice($all_students, $start_index, $batch_size);
                            
                            error_log("📦 LOT " . ($batch + 1) . "/$total_batches - " . count($batch_students) . " étudiants");
                            
                            foreach ($batch_students as $student) {
                                $to = $student['email'];
                                $student_name = $student['name'];
                                
                                // Préparer les données dynamiques pour le template
                                $dynamic_data = [
                                    'student_name' => $student_name,
                                    'sender_name' => $sender_name,
                                    'course_name' => $course_name,
                                    'action_type' => $action_type,
                                    'message_preview' => $message_preview,
                                    'course_url' => $course_url,
                                    'support_email' => SENDGRID_FROM_EMAIL,
                                    'current_year' => date('Y')
                                ];
                                
                                // Envoyer via SendGrid
                                $result = sendEmailWithSendGrid(
                                    $to,
                                    $student_name,
                                    SENDGRID_DISCUSSION_TEMPLATE,
                                    $dynamic_data
                                );
                                
                                if ($result['success']) {
                                    $emails_sent++;
                                    error_log("✅ Email envoyé: $student_name ($to)");
                                } else {
                                    $emails_failed++;
                                    error_log("❌ Échec: $student_name ($to) - " . $result['message']);
                                }
                                
                                // Micro-pause
                                usleep(50000);
                            }
                            
                            // Pause entre lots
                            if ($batch < $total_batches - 1) {
                                usleep($pause_between_batches);
                            }
                        }
                        
                        // Résumé final
                        error_log("🏁 RÉSUMÉ FINAL:");
                        error_log("- Emails envoyés: $emails_sent/$total_students");
                        error_log("- Emails échoués: $emails_failed");
                        error_log("- Taux de succès: " . round(($emails_sent / $total_students) * 100, 1) . "%");
                        
                        $stmt_emails->close();
                        $stmt_classes->close();
                        $email_conn->close();
                        return $emails_sent > 0;
                    } else {
                        error_log("PROBLÈME: Aucun étudiant trouvé");
                        $stmt_emails->close();
                        $stmt_classes->close();
                        $email_conn->close();
                        return false;
                    }
                } else {
                    error_log("ERREUR: Impossible de préparer la requête emails: " . $email_conn->error);
                    $stmt_classes->close();
                    $email_conn->close();
                    return false;
                }
            }
        }
        
        $stmt_classes->close();
    }
    
    $email_conn->close();
    return false;
}

// Gestion des requêtes AJAX pour récupérer les données d'un cours
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_course' && isset($_GET['id'])) {
    $course_id = intval($_GET['id']);
    $sql = "SELECT c.*, u.name as teacher_name FROM courses c 
            LEFT JOIN users u ON c.teacher_id = u.id 
            WHERE c.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $course = $result->fetch_assoc();
        echo json_encode(['success' => true, 'course' => $course]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Cours non trouvé']);
    }
    exit();
}

// AJAX pour récupérer les discussions d'un cours
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_discussions' && isset($_GET['course_id'])) {
    $course_id   = intval($_GET['course_id']);
    $admin_year  = (isset($_GET['year']) && preg_match('/^\d{4}-\d{4}$/', $_GET['year']))
                   ? $_GET['year'] : ANNEE_ACADEMIQUE_COURANTE;

    // Années disponibles pour ce cours (l'admin peut tout voir)
    $stmt_yrs = $conn->prepare(
        "SELECT DISTINCT academic_year AS yr FROM discussions
         WHERE course_id = ? AND academic_year IS NOT NULL
         ORDER BY yr DESC"
    );
    $stmt_yrs->bind_param("i", $course_id);
    $stmt_yrs->execute();
    $yrs_rows = $stmt_yrs->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_yrs->close();
    $available_years = array_column($yrs_rows, 'yr');
    if (!in_array(ANNEE_ACADEMIQUE_COURANTE, $available_years)) {
        array_unshift($available_years, ANNEE_ACADEMIQUE_COURANTE);
    }

    $sql_messages = "
        SELECT d.id AS discussion_id, d.sender_id, d.message, d.created_at,
               u.name, u.avatar, u.role,
               doc.id AS document_id, doc.file_path
        FROM discussions d
        JOIN users u ON d.sender_id = u.id
        LEFT JOIN documents doc ON d.id = doc.discussion_id
        WHERE d.course_id = ? AND d.academic_year = ?
        ORDER BY d.created_at DESC
        LIMIT 50
    ";

    $stmt = $conn->prepare($sql_messages);
    $stmt->bind_param("is", $course_id, $admin_year);
    $stmt->execute();
    $result   = $stmt->get_result();
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    $stmt->close();

    // Statistiques pour l'année sélectionnée
    $sql_stats = "
        SELECT
            COUNT(DISTINCT d.id)        AS total_messages,
            COUNT(DISTINCT doc.id)      AS total_documents,
            COUNT(DISTINCT d.sender_id) AS total_participants
        FROM discussions d
        LEFT JOIN documents doc ON d.id = doc.discussion_id
        WHERE d.course_id = ? AND d.academic_year = ?
    ";
    $stmt_stats = $conn->prepare($sql_stats);
    $stmt_stats->bind_param("is", $course_id, $admin_year);
    $stmt_stats->execute();
    $stats = $stmt_stats->get_result()->fetch_assoc();
    $stmt_stats->close();

    echo json_encode([
        'success'         => true,
        'messages'        => $messages,
        'stats'           => $stats,
        'available_years' => $available_years,
        'current_year'    => $admin_year,
    ]);
    exit();
}

// ============================================================
// AJAX PROGRESSION — NOUVEAUX HANDLERS
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_progress' && isset($_GET['course_id'])) {
    header('Content-Type: application/json');
    $course_id = intval($_GET['course_id']);
    $chapters_res = $conn->query("SELECT * FROM course_chapters WHERE course_id = $course_id ORDER BY order_num ASC");
    $chapters = [];
    while ($ch = $chapters_res->fetch_assoc()) {
        $sess_res = $conn->query("SELECT * FROM course_sessions WHERE chapter_id = {$ch['id']} ORDER BY session_number ASC");
        $sessions = [];
        while ($s = $sess_res->fetch_assoc()) $sessions[] = $s;
        $ch['sessions'] = $sessions;
        $chapters[] = $ch;
    }
    $total_res = $conn->query("SELECT COALESCE(SUM(hours),0) AS total FROM course_sessions WHERE course_id = $course_id");
    $total_hours = $total_res->fetch_assoc()['total'];
    echo json_encode(['success' => true, 'chapters' => $chapters, 'total_hours' => $total_hours]);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'add_chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data      = json_decode(file_get_contents('php://input'), true);
    $course_id = intval($data['course_id'] ?? 0);
    $title     = trim($data['title'] ?? '');
    if (!$course_id || !$title) { echo json_encode(['success' => false, 'message' => 'Données invalides']); exit(); }
    $ord_res   = $conn->query("SELECT COALESCE(MAX(order_num),0)+1 AS next FROM course_chapters WHERE course_id=$course_id");
    $order_num = $ord_res->fetch_assoc()['next'];
    $stmt = $conn->prepare("INSERT INTO course_chapters (course_id, title, order_num, created_by) VALUES (?,?,?,?)");
    $stmt->bind_param("isis", $course_id, $title, $order_num, $current_admin_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id' => $conn->insert_id, 'order_num' => $order_num]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'edit_chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data  = json_decode(file_get_contents('php://input'), true);
    $id    = intval($data['id'] ?? 0);
    $title = trim($data['title'] ?? '');
    if (!$id || !$title) { echo json_encode(['success' => false]); exit(); }
    $stmt = $conn->prepare("UPDATE course_chapters SET title=? WHERE id=?");
    $stmt->bind_param("si", $title, $id);
    echo json_encode(['success' => $stmt->execute()]);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_chapter' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id   = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM course_chapters WHERE id=?");
    $stmt->bind_param("i", $id);
    echo json_encode(['success' => $stmt->execute()]);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'add_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data        = json_decode(file_get_contents('php://input'), true);
    $chapter_id  = intval($data['chapter_id']  ?? 0);
    $course_id   = intval($data['course_id']   ?? 0);
    $title       = trim($data['title']         ?? '');
    $description = trim($data['description']   ?? '');
    $hours       = min(3.0, max(0.5, floatval($data['hours'] ?? 1.5)));
    $sess_date   = !empty($data['session_date']) ? $data['session_date'] : null;
    if (!$chapter_id || !$course_id || !$title) { echo json_encode(['success' => false, 'message' => 'Données invalides']); exit(); }
    $num_res  = $conn->query("SELECT COALESCE(MAX(session_number),0)+1 AS next FROM course_sessions WHERE course_id=$course_id");
    $sess_num = $num_res->fetch_assoc()['next'];
    $stmt = $conn->prepare("INSERT INTO course_sessions (chapter_id, course_id, session_number, title, description, hours, session_date, created_by) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param("iiissdss", $chapter_id, $course_id, $sess_num, $title, $description, $hours, $sess_date, $current_admin_id);
    if ($stmt->execute()) {
        $tot = $conn->query("SELECT COALESCE(SUM(hours),0) AS t FROM course_sessions WHERE course_id=$course_id")->fetch_assoc()['t'];
        echo json_encode(['success' => true, 'id' => $conn->insert_id, 'session_number' => $sess_num, 'total_hours' => $tot]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'edit_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data        = json_decode(file_get_contents('php://input'), true);
    $id          = intval($data['id']          ?? 0);
    $title       = trim($data['title']         ?? '');
    $description = trim($data['description']   ?? '');
    $hours       = min(3.0, max(0.5, floatval($data['hours'] ?? 1.0)));
    $sess_date   = !empty($data['session_date']) ? $data['session_date'] : null;
    $course_id   = intval($data['course_id']   ?? 0);
    if (!$id || !$title) { echo json_encode(['success' => false]); exit(); }
    $stmt = $conn->prepare("UPDATE course_sessions SET title=?, description=?, hours=?, session_date=? WHERE id=?");
    $stmt->bind_param("ssdsi", $title, $description, $hours, $sess_date, $id);
    if ($stmt->execute()) {
        $tot = $conn->query("SELECT COALESCE(SUM(hours),0) AS t FROM course_sessions WHERE course_id=$course_id")->fetch_assoc()['t'];
        echo json_encode(['success' => true, 'total_hours' => $tot]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_session' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id        = intval($_GET['id']);
    $course_id = intval($_GET['course_id'] ?? 0);
    $stmt      = $conn->prepare("DELETE FROM course_sessions WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $tot = $conn->query("SELECT COALESCE(SUM(hours),0) AS t FROM course_sessions WHERE course_id=$course_id")->fetch_assoc()['t'];
        echo json_encode(['success' => true, 'total_hours' => $tot]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}

// ============================================================
// AJAX PROGRESSION — HANDLERS
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_progress' && isset($_GET['course_id'])) {
    header('Content-Type: application/json');
    $course_id = intval($_GET['course_id']);
    $chapters_res = $conn->query("SELECT * FROM course_chapters WHERE course_id = $course_id ORDER BY order_num ASC");
    $chapters = [];
    while ($ch = $chapters_res->fetch_assoc()) {
        $sess_res = $conn->query("SELECT * FROM course_sessions WHERE chapter_id = {$ch['id']} ORDER BY session_number ASC");
        $sessions = [];
        while ($s = $sess_res->fetch_assoc()) $sessions[] = $s;
        $ch['sessions'] = $sessions;
        $chapters[] = $ch;
    }
    $total_res = $conn->query("SELECT COALESCE(SUM(hours),0) AS total FROM course_sessions WHERE course_id = $course_id");
    $total_hours = $total_res->fetch_assoc()['total'];
    echo json_encode(['success' => true, 'chapters' => $chapters, 'total_hours' => $total_hours]);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'add_chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data      = json_decode(file_get_contents('php://input'), true);
    $course_id = intval($data['course_id'] ?? 0);
    $title     = trim($data['title'] ?? '');
    if (!$course_id || !$title) { echo json_encode(['success' => false, 'message' => 'Données invalides']); exit(); }
    $ord_res   = $conn->query("SELECT COALESCE(MAX(order_num),0)+1 AS next FROM course_chapters WHERE course_id=$course_id");
    $order_num = $ord_res->fetch_assoc()['next'];
    $stmt = $conn->prepare("INSERT INTO course_chapters (course_id, title, order_num, created_by) VALUES (?,?,?,?)");
    $stmt->bind_param("isis", $course_id, $title, $order_num, $current_admin_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'id' => $conn->insert_id, 'order_num' => $order_num]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'edit_chapter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data  = json_decode(file_get_contents('php://input'), true);
    $id    = intval($data['id'] ?? 0);
    $title = trim($data['title'] ?? '');
    if (!$id || !$title) { echo json_encode(['success' => false]); exit(); }
    $stmt = $conn->prepare("UPDATE course_chapters SET title=? WHERE id=?");
    $stmt->bind_param("si", $title, $id);
    echo json_encode(['success' => $stmt->execute()]);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_chapter' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id   = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM course_chapters WHERE id=?");
    $stmt->bind_param("i", $id);
    echo json_encode(['success' => $stmt->execute()]);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'add_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data        = json_decode(file_get_contents('php://input'), true);
    $chapter_id  = intval($data['chapter_id']  ?? 0);
    $course_id   = intval($data['course_id']   ?? 0);
    $title       = trim($data['title']         ?? '');
    $description = trim($data['description']   ?? '');
    $hours       = min(3.0, max(0.5, floatval($data['hours'] ?? 1.5)));
    $sess_date   = !empty($data['session_date']) ? $data['session_date'] : null;
    if (!$chapter_id || !$course_id || !$title) { echo json_encode(['success' => false, 'message' => 'Données invalides']); exit(); }
    $num_res  = $conn->query("SELECT COALESCE(MAX(session_number),0)+1 AS next FROM course_sessions WHERE course_id=$course_id");
    $sess_num = $num_res->fetch_assoc()['next'];
    $stmt = $conn->prepare("INSERT INTO course_sessions (chapter_id, course_id, session_number, title, description, hours, session_date, created_by) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param("iiissdss", $chapter_id, $course_id, $sess_num, $title, $description, $hours, $sess_date, $current_admin_id);
    if ($stmt->execute()) {
        $tot = $conn->query("SELECT COALESCE(SUM(hours),0) AS t FROM course_sessions WHERE course_id=$course_id")->fetch_assoc()['t'];
        echo json_encode(['success' => true, 'id' => $conn->insert_id, 'session_number' => $sess_num, 'total_hours' => $tot]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'edit_session' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $data        = json_decode(file_get_contents('php://input'), true);
    $id          = intval($data['id']          ?? 0);
    $title       = trim($data['title']         ?? '');
    $description = trim($data['description']   ?? '');
    $hours       = min(3.0, max(0.5, floatval($data['hours'] ?? 1.0)));
    $sess_date   = !empty($data['session_date']) ? $data['session_date'] : null;
    $course_id   = intval($data['course_id']   ?? 0);
    if (!$id || !$title) { echo json_encode(['success' => false]); exit(); }
    $stmt = $conn->prepare("UPDATE course_sessions SET title=?, description=?, hours=?, session_date=? WHERE id=?");
    $stmt->bind_param("ssdsi", $title, $description, $hours, $sess_date, $id);
    if ($stmt->execute()) {
        $tot = $conn->query("SELECT COALESCE(SUM(hours),0) AS t FROM course_sessions WHERE course_id=$course_id")->fetch_assoc()['t'];
        echo json_encode(['success' => true, 'total_hours' => $tot]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_session' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id        = intval($_GET['id']);
    $course_id = intval($_GET['course_id'] ?? 0);
    $stmt      = $conn->prepare("DELETE FROM course_sessions WHERE id=?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $tot = $conn->query("SELECT COALESCE(SUM(hours),0) AS t FROM course_sessions WHERE course_id=$course_id")->fetch_assoc()['t'];
        echo json_encode(['success' => true, 'total_hours' => $tot]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit();
}

// AJAX pour envoyer un message (nouvelle fonctionnalité pour admin)
if (isset($_POST['ajax']) && $_POST['ajax'] === 'send_message') {
    header('Content-Type: application/json');
    
    try {
        $course_id = intval($_POST['course_id']);
        $message = isset($_POST['message']) ? trim($_POST['message']) : '';
        $sender_id = $current_admin_id;
        
        // Validation
        if (empty($message) && (empty($_FILES['document']['name']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK)) {
            echo json_encode(['success' => false, 'message' => 'Le message ou le document est requis']);
            exit();
        }
        
        // Insérer le message dans la table discussions
        $admin_msg_year = ANNEE_ACADEMIQUE_COURANTE;
        $sql = "INSERT INTO discussions (course_id, sender_id, message, academic_year, semester, created_at) VALUES (?, ?, ?, ?, 1, NOW())";
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception("Erreur préparation requête: " . $conn->error);
        }

        $stmt->bind_param("isss", $course_id, $sender_id, $message, $admin_msg_year);
        
        if (!$stmt->execute()) {
            throw new Exception("Erreur insertion message: " . $stmt->error);
        }
        
        $discussion_id = $conn->insert_id;
        $stmt->close();
        
        // Gérer l'upload du document si présent
        $document_uploaded = false;
        $file_path = null;
        
        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            // Créer le dossier s'il n'existe pas
            $target_dir = "../uploads/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION);
            $file_name = basename($_FILES['document']['name']);
            $target_file = $target_dir . $file_name;
            
            // Vérifier la taille du fichier (40 Mo max)
            if ($_FILES['document']['size'] > 40000000) {
                throw new Exception("Le fichier est trop volumineux (max 40 Mo)");
            }
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $target_file)) {
                // Insérer le document dans la table documents
                $is_teacher = 1; // L'admin est considéré comme enseignant pour les documents
                
                $sql_doc = "INSERT INTO documents (discussion_id, file_path, uploaded_by, is_teacher) VALUES (?, ?, ?, ?)";
                $stmt_doc = $conn->prepare($sql_doc);
                
                if (!$stmt_doc) {
                    throw new Exception("Erreur préparation requête document: " . $conn->error);
                }
                
                $stmt_doc->bind_param("issi", $discussion_id, $file_name, $sender_id, $is_teacher);
                
                if ($stmt_doc->execute()) {
                    $document_uploaded = true;
                    $file_path = $file_name;
                } else {
                    error_log("Erreur insertion document: " . $stmt_doc->error);
                }
                $stmt_doc->close();
            } else {
                error_log("Erreur déplacement fichier: " . $_FILES['document']['error']);
            }
        }
        
        // Récupérer le nom de l'admin et du cours pour l'email
        $sql_admin = "SELECT name FROM users WHERE id = ?";
        $stmt_admin = $conn->prepare($sql_admin);
        $stmt_admin->bind_param("s", $current_admin_id);
        $stmt_admin->execute();
        $admin_result = $stmt_admin->get_result();
        $admin_name = $admin_result->num_rows > 0 ? $admin_result->fetch_assoc()['name'] : 'Administrateur';
        $stmt_admin->close();
        
        $sql_course = "SELECT name FROM courses WHERE id = ?";
        $stmt_course = $conn->prepare($sql_course);
        $stmt_course->bind_param("i", $course_id);
        $stmt_course->execute();
        $course_result = $stmt_course->get_result();
        $course_name = $course_result->num_rows > 0 ? $course_result->fetch_assoc()['name'] : 'Cours';
        $stmt_course->close();
        
        // Envoyer l'email aux étudiants
        $email_sent = false;
        $action_type = $document_uploaded ? 'document(s)' : 'message';
        $message_preview = $document_uploaded ? 'Un nouveau document a été ajouté au cours.' : substr($message, 0, 100);
        
        error_log("🔥 DÉBUT ENVOI EMAIL ADMIN - Cours: $course_name (ID: $course_id) - Admin: $admin_name");
        $email_sent = sendEmailToStudents($conn, $course_id, $course_name, $admin_name, $action_type, $message_preview);
        error_log("🔥 RÉSULTAT ENVOI EMAIL ADMIN: " . ($email_sent ? 'SUCCÈS' : 'ÉCHEC'));
        
        // Log de l'action
        log_admin_action(
            $conn,
            $current_admin_id,
            'send_message',
            "Envoi d'un message dans le cours ID: $course_id",
            $discussion_id,
            'discussion',
            substr($message, 0, 50),
            null,
            json_encode([
                'course_id' => $course_id,
                'message' => $message,
                'has_document' => $document_uploaded,
                'file_path' => $file_path,
                'email_sent' => $email_sent
            ])
        );
        
        echo json_encode([
            'success' => true, 
            'message' => 'Message envoyé avec succès',
            'discussion_id' => $discussion_id,
            'email_sent' => $email_sent,
            'document_uploaded' => $document_uploaded
        ]);
        
    } catch (Exception $e) {
        error_log("❌ ERREUR ENVOI MESSAGE: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Erreur: ' . $e->getMessage()
        ]);
    }
    
    exit();
}

// ============================================================
// AJAX DEVOIRS — ADMIN
// ============================================================
if (isset($_GET['action'])) {
    if ($_SESSION['role'] !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Accès refusé']);
        exit;
    }
    header('Content-Type: application/json');
    $action = $_GET['action'];

    if ($action === 'get_course_assignments_admin' && isset($_GET['course_id'])) {
        $course_id = intval($_GET['course_id']);
        $annee = (isset($_GET['annee']) && preg_match('/^\d{4}-\d{4}$/', $_GET['annee']))
                 ? $_GET['annee'] : ANNEE_ACADEMIQUE_COURANTE;
        $sql = "SELECT ca.*,
                    u.name as teacher_name,
                    COUNT(asub.id) as nb_rendus,
                    (SELECT COUNT(DISTINCT u2.id) FROM users u2
                     JOIN classes cl ON cl.id = u2.class_id
                     WHERE JSON_CONTAINS(
                         (SELECT class_id FROM courses WHERE id = ca.course_id),
                         JSON_QUOTE(CAST(cl.id AS CHAR))
                     ) AND u2.role = 'student') as nb_etudiants
                FROM course_assignments ca
                JOIN users u ON u.id = ca.teacher_id
                LEFT JOIN assignment_submissions asub ON asub.assignment_id = ca.id
                WHERE ca.course_id = ? AND ca.annee_academique = ?
                GROUP BY ca.id
                ORDER BY ca.due_date DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $course_id, $annee);
        $stmt->execute();
        $devoirs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'devoirs' => $devoirs]);
        exit;
    }

    if ($action === 'get_all_assignments_admin') {
        $annee = (isset($_GET['annee']) && preg_match('/^\d{4}-\d{4}$/', $_GET['annee']))
                 ? $_GET['annee'] : ANNEE_ACADEMIQUE_COURANTE;
        $sql = "SELECT ca.*,
                    c.name as course_name,
                    u.name as teacher_name,
                    COUNT(asub.id) as nb_rendus,
                    COUNT(DISTINCT asub.student_id) as nb_uniq_rendus
                FROM course_assignments ca
                JOIN courses c ON c.id = ca.course_id
                JOIN users u ON u.id = ca.teacher_id
                LEFT JOIN assignment_submissions asub ON asub.assignment_id = ca.id
                WHERE ca.annee_academique = ?
                GROUP BY ca.id
                ORDER BY ca.due_date DESC
                LIMIT 100";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $annee);
        $stmt->execute();
        $devoirs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'devoirs' => $devoirs]);
        exit;
    }

    if ($action === 'get_assignment_submissions' && isset($_GET['assignment_id'])) {
        $assignment_id = intval($_GET['assignment_id']);
        $sql = "SELECT asub.*, u.name as student_name, ca.due_date
                FROM assignment_submissions asub
                JOIN users u ON u.id = asub.student_id
                JOIN course_assignments ca ON ca.id = asub.assignment_id
                WHERE asub.assignment_id = ?
                ORDER BY asub.submitted_at ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        $rendus = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['success' => true, 'rendus' => $rendus]);
        exit;
    }

    echo json_encode(['error' => 'Action inconnue']);
    exit;
}

$error_message = "";
$success_message = "";

// Ajouter un cours
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_course'])) {
    $name = trim($_POST['name']);
    $class_id_array = $_POST['class_id'];
    $class_id_json = json_encode($class_id_array);
    $teacher_id = trim($_POST['teacher_id']);
    $major = trim($_POST['major']);
    $semester = trim($_POST['semester']);
    $coefficient = trim($_POST['coefficient']);
    $image_path = null;

    if (empty($name) || empty($class_id_array) || empty($teacher_id) || empty($major) || empty($semester) || empty($coefficient) || empty($_FILES['course_image']['name'])) {
        $error_message = "⚠️ Tous les champs sont requis.";
    } else {
        $target_dir = "../uploads/";
        $target_file = $target_dir . basename($_FILES["course_image"]["name"]);
        $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        $uploadOk = 1;

        $check = getimagesize($_FILES["course_image"]["tmp_name"]);
        if ($check === false) {
            $error_message = "❌ Le fichier n'est pas une image.";
            $uploadOk = 0;
        }

        if ($uploadOk == 1) {
            if (move_uploaded_file($_FILES["course_image"]["tmp_name"], $target_file)) {
                $image_path = $target_file;

                $total_hours = !empty($_POST['total_hours']) ? intval($_POST['total_hours']) : null;

                $sql = "INSERT INTO courses (name, class_id, teacher_id, major, semester, coefficient, image_path, total_hours) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssssi", $name, $class_id_json, $teacher_id, $major, $semester, $coefficient, $image_path, $total_hours);

                if ($stmt->execute()) {
                    $success_message = "✅ Cours ajouté avec succès.";

                    log_admin_action(
                        $conn,
                        $current_admin_id,
                        'add_course',
                        "Ajout du cours $name",
                        $conn->insert_id,
                        'course',
                        $name,
                        null,
                        json_encode([
                            'name' => $name,
                            'class_id' => $class_id_array,
                            'teacher_id' => $teacher_id,
                            'major' => $major,
                            'semester' => $semester,
                            'coefficient' => $coefficient,
                            'image_path' => $image_path
                        ])
                    );

                } else {
                    $error_message = "❌ Erreur lors de l'ajout du cours : " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "❌ Une erreur s'est produite lors du téléchargement de votre fichier.";
            }
        }
    }
}

// Mise à jour d'un cours
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_course'])) {
    $course_id = $_POST['course_id'];
    $name = trim($_POST['name'] ?? '');
    $class_id_array = isset($_POST['class_id']) ? $_POST['class_id'] : [];
    $class_id_json = json_encode($class_id_array);
    $teacher_id = trim($_POST['teacher_id'] ?? '');
    $major = trim($_POST['major'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    $coefficient = trim($_POST['coefficient'] ?? '');
    $total_hours = !empty($_POST['total_hours']) ? intval($_POST['total_hours']) : null;
    $image_path = $_POST['existing_image'] ?? '';

    if (empty($name) || empty($class_id_array) || empty($teacher_id) || empty($major) || empty($semester) || empty($coefficient)) {
        $error_message = "⚠️ Veuillez remplir tous les champs pour modifier le cours.";
    } else {

        if (!empty($_FILES['course_image']['name'])) {
            $target_dir = "../uploads/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

            $target_file = $target_dir . basename($_FILES["course_image"]["name"]);
            $uploadOk = 1;

            if (isset($_FILES["course_image"]["tmp_name"]) && $_FILES["course_image"]["tmp_name"] != "") {
                $check = getimagesize($_FILES["course_image"]["tmp_name"]);
                if ($check === false) {
                    $error_message = "❌ Le fichier n'est pas une image.";
                    $uploadOk = 0;
                }
            }

            if ($uploadOk == 1 && move_uploaded_file($_FILES["course_image"]["tmp_name"], $target_file)) {
                $image_path = $target_file;
            }
        }

        $old_stmt = $conn->prepare("SELECT * FROM courses WHERE id = ?");
        $old_stmt->bind_param("i", $course_id);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();

        $sql = "UPDATE courses SET name = ?, class_id = ?, teacher_id = ?, major = ?, semester = ?, coefficient = ?, image_path = ?, total_hours = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssssii", $name, $class_id_json, $teacher_id, $major, $semester, $coefficient, $image_path, $total_hours, $course_id);

        if ($stmt->execute()) {
            $success_message = "✅ Cours modifié avec succès.";

            log_admin_action(
                $conn,
                $current_admin_id,
                'edit_course',
                "Modification du cours $course_id ($name)",
                $course_id,
                'course',
                $name,
                json_encode($old_result),
                json_encode([
                    'name' => $name,
                    'class_id' => $class_id_array,
                    'teacher_id' => $teacher_id,
                    'major' => $major,
                    'semester' => $semester,
                    'coefficient' => $coefficient,
                    'image_path' => $image_path
                ])
            );

        } else {
            $error_message = "❌ Erreur lors de la modification : " . $stmt->error;
        }
        $stmt->close();
    }
}

// Suppression d'un cours
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_course'])) {
    $course_id = $_POST['course_id'] ?? 0;

    if ($course_id > 0) {
        $old_stmt = $conn->prepare("SELECT * FROM courses WHERE id = ?");
        $old_stmt->bind_param("i", $course_id);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result()->fetch_assoc();
        $old_stmt->close();

        if (!empty($old_result['image_path']) && file_exists($old_result['image_path'])) {
            unlink($old_result['image_path']);
        }

        $sql = "DELETE FROM documents WHERE discussion_id IN (SELECT id FROM discussions WHERE course_id=?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $stmt->close();

        $sql = "DELETE FROM discussions WHERE course_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $stmt->close();

        $sql = "DELETE FROM courses WHERE id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $course_id);

        if ($stmt->execute()) {
            $success_message = "✅ Cours supprimé avec succès.";

            log_admin_action(
                $conn,
                $current_admin_id,
                'delete_course',
                "Suppression du cours {$old_result['name']} (ID: $course_id)",
                $course_id,
                'course',
                $old_result['name'],
                json_encode($old_result),
                null
            );

        } else {
            $error_message = "❌ Erreur lors de la suppression du cours : " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "⚠️ ID de cours invalide.";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Cours - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --error-color: #e74c3c;
            --success-color: #2ecc71;
            --card-bg: rgba(255, 255, 255, 0.05);
            --hover-color: rgba(3, 155, 229, 0.1);
        }

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
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        .page-header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            background: linear-gradient(45deg, var(--accent-color), #4fc3f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .page-header p {
            opacity: 0.8;
            font-size: 1.1rem;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease-out;
        }

        .alert.error {
            background: linear-gradient(135deg, var(--error-color), #c0392b);
            border-left: 4px solid #e74c3c;
        }

        .alert.success {
            background: linear-gradient(135deg, var(--success-color), #27ae60);
            border-left: 4px solid #2ecc71;
        }

        .form-card {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .form-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .form-card h3 {
            font-size: 1.5rem;
            margin-bottom: 25px;
            color: var(--accent-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-light);
            opacity: 0.9;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            padding: 12px 15px;
            color: var(--text-light);
            font-size: 1rem;
            transition: border-color 0.3s ease, background 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            background: rgba(255, 255, 255, 0.15);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .checkbox-container {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
            border: 1px solid var(--border-color);
        }

        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            transition: background 0.3s ease;
        }

        .checkbox-item:hover {
            background: var(--hover-color);
        }

        .checkbox-item input[type="checkbox"] {
            width: auto;
            margin: 0;
        }

        .btn {
            background: linear-gradient(135deg, var(--accent-color), #0288d1);
            color: var(--text-light);
            border: none;
            border-radius: 10px;
            padding: 12px 25px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(3, 155, 229, 0.4);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--error-color), #c0392b);
        }

        .btn-danger:hover {
            box-shadow: 0 5px 15px rgba(231, 76, 60, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
        }

        .btn-secondary:hover {
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.4);
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #138496);
        }

        .btn-info:hover {
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.4);
        }

        /* NOUVEAU — bouton progression vert */
        .btn-progress {
            background: linear-gradient(135deg, #1a6b3c, #22a05a);
        }

        .btn-progress:hover {
            box-shadow: 0 5px 15px rgba(34, 160, 90, 0.4);
        }

        .controls-section {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        .controls-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            align-items: end;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-light);
            font-size: 1rem;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-color);
        }

        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }

        .course-card {
            background: var(--card-bg);
            border-radius: 15px;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
        }

        .course-header {
            position: relative;
            height: 200px;
            overflow: hidden;
        }

        .course-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .course-card:hover .course-image {
            transform: scale(1.05);
        }

        .course-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to bottom, transparent, rgba(0, 0, 0, 0.7));
            display: flex;
            align-items: flex-end;
            padding: 20px;
        }

        .course-title {
            color: white;
            font-size: 1.3rem;
            font-weight: 600;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
        }

        .course-body {
            padding: 25px;
        }

        .course-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
        }

        .info-item i {
            color: var(--accent-color);
            width: 16px;
        }

        .course-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .course-actions .btn {
            flex: 1;
            min-width: 100px;
            justify-content: center;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: var(--secondary-bg);
            margin: 2% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 900px;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--border-color);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .close {
            color: var(--text-light);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close:hover {
            color: var(--accent-color);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--accent-color);
            margin-bottom: 10px;
        }

        .stat-label {
            opacity: 0.8;
            font-size: 0.9rem;
        }

        /* Styles pour le modal des discussions */
        .discussion-modal .modal-content {
            max-width: 1000px;
        }

        .discussion-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .discussion-stats .stat-box {
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }

        .discussion-stats .stat-box .number {
            font-size: 2rem;
            color: var(--accent-color);
            font-weight: bold;
        }

        .discussion-stats .stat-box .label {
            font-size: 0.9rem;
            opacity: 0.7;
        }

        .messages-container {
            max-height: 500px;
            overflow-y: auto;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 10px;
            padding: 20px;
        }

        .message-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 3px solid var(--accent-color);
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .message-author {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message-author img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 2px solid var(--accent-color);
        }

        .author-info .name {
            font-weight: 600;
            color: var(--accent-color);
        }

        .author-info .role {
            font-size: 0.8rem;
            opacity: 0.7;
        }

        .message-date {
            font-size: 0.85rem;
            opacity: 0.6;
        }

        .message-content {
            margin-top: 10px;
            line-height: 1.6;
        }

        .message-document {
            margin-top: 10px;
            padding: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .message-document a {
            color: var(--accent-color);
            text-decoration: none;
        }

        .message-document a:hover {
            text-decoration: underline;
        }

        .message-document i {
            color: var(--accent-color);
        }

        .no-messages {
            text-align: center;
            padding: 50px;
            opacity: 0.7;
        }

        /* BOUTON PROGRESSION */
        .btn-progress {
            background: linear-gradient(135deg, #1a6b3c, #22a05a);
        }
        .btn-progress:hover {
            box-shadow: 0 5px 15px rgba(34, 160, 90, 0.4);
        }

        /* ============================================================
           STYLES MODULE PROGRESSION
        ============================================================ */
        .progress-modal .modal-content { max-width: 780px; }

        .prog-total-bar {
            display: flex; align-items: center; gap: 16px;
            background: rgba(34, 160, 90, 0.1);
            border: 1px solid rgba(34, 160, 90, 0.3);
            border-radius: 10px; padding: 14px 20px; margin-bottom: 24px;
        }
        .prog-total-bar i { font-size: 1.6rem; color: #22a05a; }
        .prog-total-bar .label { font-size: 0.85rem; opacity: 0.6; }
        .prog-total-bar .value { font-size: 1.8rem; font-weight: 700; color: #22a05a; }

        .btn-add-chapter {
            width: 100%; padding: 12px;
            border: 2px dashed rgba(3, 155, 229, 0.4);
            background: transparent; border-radius: 10px;
            color: var(--accent-color); cursor: pointer;
            font-size: 0.95rem; font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all 0.2s; margin-bottom: 16px;
        }
        .btn-add-chapter:hover { background: rgba(3,155,229,0.08); border-color: var(--accent-color); }

        .chapter-block {
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border-color);
            border-radius: 12px; margin-bottom: 16px; overflow: hidden;
        }
        .chapter-header {
            display: flex; align-items: center; gap: 10px;
            padding: 14px 18px; background: rgba(255,255,255,0.06);
            cursor: pointer; border-bottom: 1px solid var(--border-color); user-select: none;
        }
        .chapter-header:hover { background: rgba(255,255,255,0.1); }
        .chapter-num {
            background: var(--accent-color); color: #fff;
            font-size: 0.75rem; font-weight: 700;
            padding: 3px 9px; border-radius: 20px; white-space: nowrap;
        }
        .chapter-title-text { flex: 1; font-weight: 600; font-size: 1rem; }
        .chapter-hours-badge {
            background: rgba(34,160,90,0.15); border: 1px solid rgba(34,160,90,0.3);
            color: #22a05a; font-size: 0.8rem; padding: 3px 10px; border-radius: 20px; white-space: nowrap;
        }
        .chapter-toggle { color: rgba(255,255,255,0.4); transition: transform 0.25s; }
        .chapter-toggle.open { transform: rotate(180deg); }
        .chapter-actions { display: flex; gap: 6px; }
        .icon-btn {
            background: transparent; border: none;
            color: rgba(255,255,255,0.45); cursor: pointer;
            padding: 4px 6px; border-radius: 6px; transition: all 0.2s; font-size: 0.9rem;
        }
        .icon-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .icon-btn.del:hover { background: rgba(231,76,60,0.15); color: #e74c3c; }

        .chapter-body { padding: 16px 18px; display: none; }
        .chapter-body.open { display: block; }

        .session-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 12px 14px; border-radius: 10px; margin-bottom: 10px;
            background: rgba(255,255,255,0.04); border: 1px solid var(--border-color);
            transition: background 0.2s;
        }
        .session-item:hover { background: rgba(255,255,255,0.07); }
        .session-num {
            background: rgba(3,155,229,0.15); border: 1px solid rgba(3,155,229,0.3);
            color: var(--accent-color); font-size: 0.72rem; font-weight: 700;
            padding: 4px 8px; border-radius: 8px; white-space: nowrap;
            margin-top: 2px; min-width: 36px; text-align: center;
        }
        .session-main { flex: 1; min-width: 0; }
        .session-title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; }
        .session-title { font-weight: 600; font-size: 0.95rem; }
        .session-hours-pill {
            background: rgba(34,160,90,0.15); border: 1px solid rgba(34,160,90,0.3);
            color: #22a05a; font-size: 0.75rem; font-weight: 600;
            padding: 2px 8px; border-radius: 20px; white-space: nowrap;
        }
        .session-date-pill {
            background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.45);
            font-size: 0.72rem; padding: 2px 8px; border-radius: 20px;
        }
        .session-desc-toggle {
            background: none; border: none; color: var(--accent-color);
            font-size: 0.78rem; cursor: pointer; padding: 0; font-family: inherit;
            display: flex; align-items: center; gap: 4px; margin-top: 4px;
        }
        .session-desc-toggle i { transition: transform 0.2s; font-size: 0.7rem; }
        .session-desc-toggle.open i { transform: rotate(180deg); }
        .session-desc-body {
            margin-top: 8px; padding: 10px 12px;
            background: rgba(255,255,255,0.04);
            border-left: 3px solid rgba(3,155,229,0.3);
            border-radius: 0 8px 8px 0;
            font-size: 0.88rem; color: rgba(255,255,255,0.7);
            line-height: 1.6; display: none;
        }
        .session-desc-body.open { display: block; }
        .session-actions { display: flex; gap: 4px; margin-top: 2px; }

        .btn-add-session {
            width: 100%; padding: 9px;
            border: 1px dashed rgba(255,255,255,0.15);
            background: transparent; border-radius: 8px;
            color: rgba(255,255,255,0.4); cursor: pointer;
            font-size: 0.85rem; font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 6px;
            transition: all 0.2s; margin-top: 6px;
        }
        .btn-add-session:hover { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.3); color: #fff; }

        .inline-form {
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--border-color);
            border-radius: 10px; padding: 16px; margin-bottom: 12px; display: none;
        }
        .inline-form.open { display: block; }
        .inline-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .inline-form input, .inline-form textarea, .inline-form select {
            width: 100%; padding: 9px 12px;
            background: rgba(255,255,255,0.1);
            border: 1px solid var(--border-color);
            border-radius: 8px; color: #fff;
            font-family: inherit; font-size: 0.9rem; outline: none; transition: border-color 0.2s;
        }
        .inline-form input:focus, .inline-form textarea:focus { border-color: var(--accent-color); }
        .inline-form textarea { resize: vertical; min-height: 60px; }
        .inline-form label { display: block; font-size: 0.8rem; opacity: 0.6; margin-bottom: 4px; }
        .inline-form-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
        .btn-sm { padding: 7px 16px; font-size: 0.85rem; border-radius: 8px; }

        .prog-empty { text-align: center; padding: 40px 20px; color: rgba(255,255,255,0.35); }
        .prog-empty i { font-size: 2.5rem; margin-bottom: 12px; display: block; }
        .prog-loading { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 40px; color: rgba(255,255,255,0.4); }
        .spinner {
            width: 24px; height: 24px;
            border: 2px solid rgba(3,155,229,0.2); border-top-color: var(--accent-color);
            border-radius: 50%; animation: spin 0.7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Notification d'envoi d'email */
        .email-sent-notification {
            background: rgba(76, 175, 80, 0.1);
            border: 1px solid #4CAF50;
            color: #4CAF50;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }


        @keyframes slideIn {
            from { transform: translateX(-100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .course-card {
            animation: fadeIn 0.5s ease-out;
        }

        /* ============================================================
           MODULE DEVOIRS ADMIN
        ============================================================ */
        .devoir-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 14px;
        }
        .devoirs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .87rem;
        }
        .devoirs-table th {
            text-align: left;
            padding: 10px 8px;
            opacity: .7;
            border-bottom: 1px solid rgba(255,255,255,.15);
            background: rgba(255,255,255,.04);
        }
        .devoirs-table td {
            padding: 8px;
            border-bottom: 1px solid rgba(255,255,255,.06);
            vertical-align: top;
        }
        .rendus-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .85rem;
        }
        .rendus-table th {
            text-align: left;
            padding: 8px 6px;
            opacity: .6;
            border-bottom: 1px solid rgba(255,255,255,.15);
        }
        .rendus-table td {
            padding: 8px 6px;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }
        .badge-cloture  { background:#e74c3c;color:#fff;border-radius:8px;padding:2px 10px;font-size:11px;white-space:nowrap; }
        .badge-en-cours { background:#22a05a;color:#fff;border-radius:8px;padding:2px 10px;font-size:11px;white-space:nowrap; }
        .badge-retard   { background:#e74c3c;color:#fff;border-radius:8px;padding:1px 6px;font-size:10px;margin-left:4px; }
        .filtre-select {
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.2);
            color: #fff;
            padding: 7px 12px;
            border-radius: 8px;
            font-size: .88rem;
            cursor: pointer;
            outline: none;
        }
        .filtre-select option { background: #0c2d48; }

        @media (max-width: 768px) {
            .container { padding: 15px; }
            .page-header h1 { font-size: 2rem; }
            .form-grid { grid-template-columns: 1fr; }
            .controls-grid { grid-template-columns: 1fr; }
            .courses-grid { grid-template-columns: 1fr; }
            .course-actions { flex-direction: column; }
            .course-actions .btn { flex: none; }
            .modal-content { width: 95%; margin: 10% auto; padding: 20px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .discussion-stats { grid-template-columns: 1fr; }
            .inline-form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php 
    if (file_exists('../includes/header.php')) {
        include '../includes/header.php'; 
    }
    ?>

    <div class="container">
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;">
            <div>
                <h1><i class="fas fa-book-open"></i> Gestion des Cours</h1>
                <p>Administrez les cours et consultez les discussions</p>
            </div>
            <button onclick="voirTousDevoirs()" class="btn" style="white-space:nowrap;align-self:center;">
                <i class="fas fa-tasks"></i> Tous les devoirs
            </button>
        </div>

        <?php if ($error_message): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <?php
            $stats_queries = [
                'Cours Total' => "SELECT COUNT(*) as count FROM courses",
                'Professeurs' => "SELECT COUNT(DISTINCT teacher_id) as count FROM courses",
                'Semestres' => "SELECT COUNT(DISTINCT semester) as count FROM courses",
                'Spécialités' => "SELECT COUNT(DISTINCT major) as count FROM courses"
            ];

            foreach ($stats_queries as $label => $query) {
                $result = $conn->query($query);
                $count = $result->fetch_assoc()['count'];
                echo "<div class='stat-card'>
                        <div class='stat-number'>$count</div>
                        <div class='stat-label'>$label</div>
                      </div>";
            }
            ?>
        </div>

        <div class="form-card">
            <h3><i class="fas fa-plus-circle"></i> Ajouter un nouveau cours</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-book"></i> Nom du cours</label>
                        <input type="text" name="name" placeholder="Nom du cours" required>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-user-tie"></i> Professeur</label>
                        <select name="teacher_id" required>
                            <option value="">Sélectionnez un professeur</option>
                            <?php
                            $sql = "SELECT id, name FROM users WHERE role='teacher'";
                            $result = $conn->query($sql);
                            while ($row = $result->fetch_assoc()) {
                                echo "<option value='" . $row['id'] . "'>" . htmlspecialchars($row['name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-info-circle"></i> Description</label>
                        <input type="text" name="major" placeholder="Description du cours" required>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Semestre</label>
                        <input type="number" name="semester" placeholder="Semestre" required min="1" max="10">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-weight"></i> Coefficient</label>
                        <input type="number" name="coefficient" placeholder="Coefficient" required min="0" step="0.1">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-clock"></i> Heures prévues</label>
                        <input type="number" name="total_hours" placeholder="ex: 30" min="1" step="1">
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-image"></i> Image du cours</label>
                        <input type="file" name="course_image" accept="image/*" required>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-users"></i> Classes concernées</label>
                    <div class="checkbox-container">
                        <div class="checkbox-grid">
                            <?php
                            $all_classes = $conn->query("SELECT id, name FROM classes");
                            if ($all_classes->num_rows > 0) {
                                while ($class = $all_classes->fetch_assoc()) {
                                    echo "<div class='checkbox-item'>
                                            <input type='checkbox' name='class_id[]' value='" . htmlspecialchars($class['id'], ENT_QUOTES) . "' id='class_" . $class['id'] . "'>
                                            <label for='class_" . $class['id'] . "'>" . htmlspecialchars($class['name'], ENT_QUOTES) . "</label>
                                          </div>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <button type="submit" name="add_course" class="btn">
                    <i class="fas fa-plus"></i> Ajouter le Cours
                </button>
            </form>
        </div>

        <div class="controls-section">
            <div class="controls-grid">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Rechercher un cours...">
                </div>
                
                <div class="form-group">
                    <label>Trier par</label>
                    <select id="sortSelect">
                        <option value="name">Nom</option>
                        <option value="semester">Semestre</option>
                        <option value="coefficient">Coefficient</option>
                        <option value="teacher">Professeur</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Filtrer par semestre</label>
                    <select id="semesterFilter">
                        <option value="">Tous les semestres</option>
                        <?php
                        $semesters = $conn->query("SELECT DISTINCT semester FROM courses ORDER BY semester");
                        while ($sem = $semesters->fetch_assoc()) {
                            echo "<option value='" . $sem['semester'] . "'>Semestre " . $sem['semester'] . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <button class="btn" onclick="resetFilters()">
                    <i class="fas fa-refresh"></i> Réinitialiser
                </button>
            </div>
        </div>

        <div id="coursesContainer">
            <div class="courses-grid" id="coursesGrid">
                <?php
                $annee_filtre = (isset($_GET['annee_filtre']) && preg_match('/^\d{4}-\d{4}$/', $_GET['annee_filtre']))
                    ? $_GET['annee_filtre'] : ANNEE_ACADEMIQUE_COURANTE;

                $sql = "SELECT c.*, u.name as teacher_name,
                               COALESCE(dv.nb_devoirs, 0) as nb_devoirs
                        FROM courses c
                        LEFT JOIN users u ON c.teacher_id = u.id
                        LEFT JOIN (
                            SELECT course_id, COUNT(*) as nb_devoirs
                            FROM course_assignments
                            WHERE annee_academique = '{$annee_filtre}'
                            GROUP BY course_id
                        ) dv ON dv.course_id = c.id
                        ORDER BY c.name";
                $result = $conn->query($sql);
                
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $class_names = [];
                        $class_ids = json_decode($row['class_id'], true);
                        
                        if (is_array($class_ids)) {
                            foreach ($class_ids as $class_id) {
                                $class_result = $conn->query("SELECT name FROM classes WHERE id=$class_id");
                                if ($class_result->num_rows > 0) {
                                    $class_row = $class_result->fetch_assoc();
                                    $class_names[] = $class_row['name'];
                                }
                            }
                        }
                        
                        $image_src = file_exists($row['image_path']) ? $row['image_path'] : '../assets/images/default-course.jpg';

                        // Total heures du cours
                        $hours_res = $conn->query("SELECT COALESCE(SUM(hours),0) AS t FROM course_sessions WHERE course_id={$row['id']}");
                        $total_hours_course = floatval($hours_res->fetch_assoc()['t']);
                        $hours_label = $total_hours_course > 0 ? number_format($total_hours_course, 1).'h' : '0h';
                        $nb_devoirs_val = intval($row['nb_devoirs'] ?? 0);
                        $badge_devoirs_html = $nb_devoirs_val > 0
                            ? "<span style=\"background:#00B0F0;color:#fff;border-radius:10px;padding:1px 6px;font-size:11px;margin-left:4px;\">$nb_devoirs_val</span>"
                            : '';

                        echo "<div class='course-card' data-name='" . strtolower($row['name']) . "' 
                                   data-semester='" . $row['semester'] . "' 
                                   data-coefficient='" . $row['coefficient'] . "' 
                                   data-teacher='" . strtolower($row['teacher_name']) . "'>
                                <div class='course-header'>
                                    <img src='" . htmlspecialchars($image_src) . "' alt='" . htmlspecialchars($row['name']) . "' class='course-image' onerror=\"this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjIwMCIgdmlld0JveD0iMCAwIDIwMCAyMDAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxyZWN0IHdpZHRoPSIyMDAiIGhlaWdodD0iMjAwIiBmaWxsPSIjMGMyZDQ4Ii8+Cjx0ZXh0IHg9IjEwMCIgeT0iMTAwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBkeT0iLjNlbSIgZmlsbD0iI2ZmZmZmZiIgZm9udC1mYW1pbHk9IkFyaWFsLCBzYW5zLXNlcmlmIiBmb250LXNpemU9IjE0Ij5Db3Vyc288L3RleHQ+Cjwvc3ZnPg=='\">
                                    <div class='course-overlay'>
                                        <div class='course-title'>" . htmlspecialchars($row['name']) . "</div>
                                    </div>
                                </div>
                                <div class='course-body'>
                                    <div class='course-info'>
                                        <div class='info-item'>
                                            <i class='fas fa-user-tie'></i>
                                            <span>" . htmlspecialchars($row['teacher_name']) . "</span>
                                        </div>
                                        <div class='info-item'>
                                            <i class='fas fa-calendar-alt'></i>
                                            <span>Semestre " . $row['semester'] . "</span>
                                        </div>
                                        <div class='info-item'>
                                            <i class='fas fa-weight'></i>
                                            <span>Coeff. " . $row['coefficient'] . "</span>
                                        </div>
                                        <div class='info-item'>
                                            <i class='fas fa-users'></i>
                                            <span>" . implode(', ', $class_names) . "</span>
                                        </div>
                                        <div class='info-item'>
                                            <i class='fas fa-clock' style='color:#22a05a'></i>
                                            <span style='color:#22a05a;font-weight:600;'>$hours_label effectuées</span>
                                        </div>
                                    </div>
                                    <div class='course-actions'>
                                        <button class='btn btn-progress' onclick='openProgress(" . $row['id'] . ", \"" . htmlspecialchars($row['name'], ENT_QUOTES) . "\")'>
                                            <i class='fas fa-chart-line'></i> Progression
                                        </button>
                                        <button class='btn btn-info' onclick='viewDiscussions(" . $row['id'] . ", \"" . htmlspecialchars($row['name']) . "\")'>
                                            <i class='fas fa-comments'></i> Discussions
                                        </button>
                                        <button class='btn btn-sm' onclick='voirDevoirsCours(" . $row['id'] . ", \"" . htmlspecialchars($row['name'], ENT_QUOTES) . "\")' style='background:#E6F7FE;color:#00B0F0;border:1px solid #00B0F0;flex:1;min-width:100px;justify-content:center;'>
                                            <i class='fas fa-tasks'></i> Devoirs $badge_devoirs_html
                                        </button>
                                        <button class='btn' onclick='editCourse(" . $row['id'] . ")'>
                                            <i class='fas fa-edit'></i> Modifier
                                        </button>
                                        <button class='btn btn-danger' onclick='deleteCourse(" . $row['id'] . ", \"" . htmlspecialchars($row['name']) . "\")'>
                                            <i class='fas fa-trash'></i> Supprimer
                                        </button>
                                    </div>
                                </div>
                              </div>";
                    }
                } else {
                    echo "<div style='text-align: center; padding: 50px; opacity: 0.7;'>
                            <i class='fas fa-book-open' style='font-size: 4rem; margin-bottom: 20px;'></i>
                            <h3>Aucun cours trouvé</h3>
                            <p>Commencez par ajouter votre premier cours.</p>
                          </div>";
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Modal de modification -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Modifier le cours</h3>
                <span class="close" onclick="closeModal('editModal')">&times;</span>
            </div>
            <div id="editForm"></div>
        </div>
    </div>

    <!-- Modal des discussions -->
    <div id="discussionsModal" class="modal discussion-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-comments"></i> Discussions - <span id="discussionCourseName"></span></h3>
                <span class="close" onclick="closeModal('discussionsModal')">&times;</span>
            </div>
            
            <div class="discussion-stats">
                <div class="stat-box">
                    <div class="number" id="totalMessages">0</div>
                    <div class="label">Messages</div>
                </div>
                <div class="stat-box">
                    <div class="number" id="totalDocuments">0</div>
                    <div class="label">Documents</div>
                </div>
                <div class="stat-box">
                    <div class="number" id="totalParticipants">0</div>
                    <div class="label">Participants</div>
                </div>
            </div>

            <div class="form-card" style="margin-bottom: 20px;">
                <h3><i class="fas fa-paper-plane"></i> Envoyer un message aux étudiants</h3>
                <form id="sendMessageForm" enctype="multipart/form-data">
                    <input type="hidden" id="messageCourseId" name="course_id">
                    
                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Message (optionnel si document)</label>
                        <textarea name="message" id="messageText" rows="3" placeholder="Écrivez votre message..." style="width: 100%; resize: vertical;"></textarea>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-file"></i> Document (optionnel si message)</label>
                        <input type="file" name="document" id="messageDocument">
                        <small style="opacity: 0.7;">Taille maximale: 40 Mo</small>
                    </div>

                    <button type="submit" class="btn" id="sendMessageBtn">
                        <i class="fas fa-paper-plane"></i> Envoyer
                    </button>
                    
                    <div id="emailNotification" class="email-sent-notification" style="display: none;">
                        <i class="fas fa-envelope-circle-check"></i>
                        <span>Les étudiants recevront une notification par email</span>
                    </div>
                </form>
            </div>

            <!-- Sélecteur d'année académique (admin voit toutes les années) -->
            <div style="margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;" id="yearSelectorWrap">
                <span style="font-size:13px;opacity:.7;">
                    <i class="fas fa-calendar-alt"></i> Année :
                </span>
                <select id="discussionYearFilter" onchange="reloadDiscussions()"
                        style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);
                               color:#fff;padding:5px 10px;border-radius:5px;font-size:13px;cursor:pointer;outline:none;">
                </select>
            </div>

            <div class="messages-container" id="messagesContainer">
                <div class="no-messages">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem;"></i>
                    <p>Chargement des discussions...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Progression -->
    <div id="progressModal" class="modal progress-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-chart-line" style="color:#22a05a;"></i>&nbsp; Progression — <span id="progCourseName"></span></h3>
                <span class="close" onclick="closeModal('progressModal')">&times;</span>
            </div>

            <div class="prog-total-bar">
                <i class="fas fa-clock"></i>
                <div>
                    <div class="label">Heures totales effectuées</div>
                    <div class="value" id="progTotalHours">0h</div>
                </div>
            </div>

            <div id="progContent">
                <div class="prog-loading"><div class="spinner"></div> Chargement…</div>
            </div>

            <button class="btn-add-chapter" id="btnAddChapter" onclick="showAddChapterForm()" style="display:none;">
                <i class="fas fa-plus"></i> Ajouter un chapitre
            </button>

            <div class="inline-form" id="addChapterForm">
                <label>Titre du chapitre</label>
                <input type="text" id="newChapterTitle" placeholder="ex: Chapitre 1 — Introduction" maxlength="255">
                <div class="inline-form-actions">
                    <button class="btn btn-secondary btn-sm" onclick="hideAddChapterForm()"><i class="fas fa-times"></i> Annuler</button>
                    <button class="btn btn-sm" onclick="saveNewChapter()"><i class="fas fa-check"></i> Ajouter</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Devoirs par cours -->
    <div id="mDevoirsAdmin" class="modal">
        <div class="modal-content" style="max-width:900px;">
            <div class="modal-header">
                <h3 id="mDevoirsAdminTitre">📋 Devoirs</h3>
                <span class="close" onclick="closeModal('mDevoirsAdmin')">&times;</span>
            </div>
            <div id="mDevoirsAdminBody">
                <div class="prog-loading"><div class="spinner"></div> Chargement…</div>
            </div>
        </div>
    </div>

    <!-- Modal Tous les devoirs (vue globale) -->
    <div id="mDevoirsGlobal" class="modal">
        <div class="modal-content" style="max-width:1100px;">
            <div class="modal-header">
                <h3><i class="fas fa-tasks"></i> Tous les devoirs</h3>
                <span class="close" onclick="closeModal('mDevoirsGlobal')">&times;</span>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
                <select id="filtGlobalCours"  class="filtre-select" onchange="filtrerDevoirsGlobal()">
                    <option value="">Tous les cours</option>
                </select>
                <select id="filtGlobalStatut" class="filtre-select" onchange="filtrerDevoirsGlobal()">
                    <option value="">Tous les statuts</option>
                    <option value="en_cours">En cours</option>
                    <option value="cloture">Clôturé</option>
                </select>
                <select id="filtGlobalProf"   class="filtre-select" onchange="filtrerDevoirsGlobal()">
                    <option value="">Tous les profs</option>
                </select>
            </div>
            <div id="mDevoirsGlobalBody">
                <div class="prog-loading"><div class="spinner"></div> Chargement…</div>
            </div>
        </div>
    </div>

    <?php
    if (file_exists('../includes/footer.php')) {
        include '../includes/footer.php';
    }
    ?>

    <script>
        const _csrfToken   = '<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>';
        const ANNEE_FILTRE = <?= json_encode($annee_filtre ?? ANNEE_ACADEMIQUE_COURANTE) ?>;
        // ── FILTRES EXISTANTS (inchangés) ────────────────────────
        function filterCourses() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const semesterFilter = document.getElementById('semesterFilter').value;
            const sortBy = document.getElementById('sortSelect').value;
            const cards = Array.from(document.querySelectorAll('.course-card'));

            cards.forEach(card => {
                const name = card.dataset.name;
                const semester = card.dataset.semester;
                const teacher = card.dataset.teacher;
                const matchesSearch = name.includes(searchTerm) || teacher.includes(searchTerm);
                const matchesSemester = !semesterFilter || semester === semesterFilter;
                card.style.display = (matchesSearch && matchesSemester) ? 'block' : 'none';
            });

            const visibleCards = cards.filter(card => card.style.display !== 'none');
            const container = document.getElementById('coursesGrid');
            visibleCards.sort((a, b) => {
                let aValue, bValue;
                switch(sortBy) {
                    case 'name':        aValue = a.dataset.name;                  bValue = b.dataset.name; break;
                    case 'semester':    aValue = parseInt(a.dataset.semester);    bValue = parseInt(b.dataset.semester); break;
                    case 'coefficient': aValue = parseFloat(a.dataset.coefficient); bValue = parseFloat(b.dataset.coefficient); break;
                    case 'teacher':     aValue = a.dataset.teacher;               bValue = b.dataset.teacher; break;
                }
                if (typeof aValue === 'string') return aValue.localeCompare(bValue);
                return aValue - bValue;
            });
            visibleCards.forEach(card => container.appendChild(card));
        }

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('semesterFilter').value = '';
            document.getElementById('sortSelect').value = 'name';
            filterCourses();
        }

        document.getElementById('searchInput').addEventListener('input', filterCourses);
        document.getElementById('sortSelect').addEventListener('change', filterCourses);
        document.getElementById('semesterFilter').addEventListener('change', filterCourses);

        // ── DISCUSSIONS ─────────────────────────────────────────
        let _currentDiscussionCourseId = null;

        function viewDiscussions(courseId, courseName) {
            _currentDiscussionCourseId = courseId;
            document.getElementById('discussionCourseName').textContent = courseName;
            document.getElementById('messageCourseId').value = courseId;
            document.getElementById('discussionsModal').style.display = 'block';
            document.getElementById('sendMessageForm').reset();
            document.getElementById('messageCourseId').value = courseId;
            document.getElementById('emailNotification').style.display = 'none';
            // Réinitialiser le sélecteur d'années
            const sel = document.getElementById('discussionYearFilter');
            sel.innerHTML = '<option value="">Chargement…</option>';
            loadDiscussions(courseId);
        }

        function reloadDiscussions() {
            if (_currentDiscussionCourseId) {
                loadDiscussions(_currentDiscussionCourseId);
            }
        }

        function loadDiscussions(courseId) {
            const sel  = document.getElementById('discussionYearFilter');
            const year = sel.value || '';
            const url  = `?ajax=get_discussions&course_id=${courseId}` + (year ? `&year=${encodeURIComponent(year)}` : '');

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) return;

                    // Mettre à jour les stats
                    document.getElementById('totalMessages').textContent    = data.stats.total_messages    ?? 0;
                    document.getElementById('totalDocuments').textContent   = data.stats.total_documents   ?? 0;
                    document.getElementById('totalParticipants').textContent = data.stats.total_participants ?? 0;

                    // Mettre à jour le sélecteur d'années (une seule fois au chargement initial)
                    if (sel.options.length <= 1) {
                        sel.innerHTML = '';
                        (data.available_years || []).forEach(yr => {
                            const opt = document.createElement('option');
                            opt.value = yr;
                            opt.textContent = yr;
                            if (yr === data.current_year) opt.selected = true;
                            sel.appendChild(opt);
                        });
                    }

                    // Afficher les messages
                    const container = document.getElementById('messagesContainer');
                    if (data.messages.length === 0) {
                        container.innerHTML = `<div class="no-messages"><i class="fas fa-comments" style="font-size:3rem;opacity:.5;margin-bottom:15px;"></i><p>Aucune discussion pour cette année</p></div>`;
                    } else {
                        const roleLabels = { teacher: 'Enseignant', student: 'Étudiant', admin: 'Administrateur' };
                        container.innerHTML = data.messages.map(msg => {
                            const roleLabel  = roleLabels[msg.role] || msg.role;
                            const docHTML    = msg.document_id
                                ? `<div class="message-document"><i class="fas fa-file"></i><a href="../uploads/${msg.file_path}" download>${msg.file_path}</a></div>`
                                : '';
                            return `<div class="message-item">
                                <div class="message-header">
                                    <div class="message-author">
                                        <img src="../uploads/avatars/${msg.avatar}" alt="${msg.name}" onerror="this.src='../assets/images/default-avatar.png'">
                                        <div class="author-info"><div class="name">${msg.name}</div><div class="role">${roleLabel}</div></div>
                                    </div>
                                    <div class="message-date">${msg.created_at}</div>
                                </div>
                                <div class="message-content">${msg.message || '<em>Document sans message</em>'}</div>
                                ${docHTML}
                            </div>`;
                        }).join('');
                    }
                })
                .catch(() => {
                    document.getElementById('messagesContainer').innerHTML =
                        `<div class="no-messages"><i class="fas fa-exclamation-triangle" style="font-size:3rem;color:var(--error-color);margin-bottom:15px;"></i><p>Erreur lors du chargement</p></div>`;
                });
        }

        document.getElementById('sendMessageForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('ajax', 'send_message');
            const sendBtn = document.getElementById('sendMessageBtn');
            const originalBtnText = sendBtn.innerHTML;
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Envoi en cours...';
            fetch('', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('messageText').value = '';
                        document.getElementById('messageDocument').value = '';
                        const courseId = document.getElementById('messageCourseId').value;
                        loadDiscussions(courseId);
                        const emailNotif = document.getElementById('emailNotification');
                        if (data.email_sent) {
                            emailNotif.style.display = 'flex';
                            emailNotif.style.borderColor = '#4CAF50';
                            emailNotif.style.color = '#4CAF50';
                            emailNotif.innerHTML = '<i class="fas fa-envelope-circle-check"></i><span>✅ Message envoyé et notifications email envoyées aux étudiants</span>';
                        } else {
                            emailNotif.style.display = 'flex';
                            emailNotif.style.borderColor = '#ff9800';
                            emailNotif.style.color = '#ff9800';
                            emailNotif.innerHTML = '<i class="fas fa-exclamation-triangle"></i><span>⚠️ Message envoyé mais l\'envoi d\'emails a échoué</span>';
                        }
                        setTimeout(() => { emailNotif.style.display = 'none'; }, 5000);
                        const tempAlert = document.createElement('div');
                        tempAlert.className = 'alert success';
                        tempAlert.innerHTML = '<i class="fas fa-check-circle"></i> Message envoyé avec succès';
                        tempAlert.style.cssText = 'position:fixed;top:20px;right:20px;z-index:10000;';
                        document.body.appendChild(tempAlert);
                        setTimeout(() => { tempAlert.remove(); }, 3000);
                    } else {
                        alert('Erreur: ' + data.message);
                    }
                })
                .catch(error => { alert('Une erreur est survenue lors de l\'envoi du message'); })
                .finally(() => { sendBtn.disabled = false; sendBtn.innerHTML = originalBtnText; });
        });

        // ── EDIT/DELETE COURS (inchangés) ────────────────────────
        function editCourse(courseId) {
            document.getElementById('editModal').style.display = 'block';
            fetch(`?ajax=get_course&id=${courseId}`)
                .then(response => response.json())
                .then(data => { if (data.success) loadEditFormWithData(data.course); })
                .catch(error => { console.error('Erreur:', error); });
        }

        function loadEditFormWithData(course) {
            const originalTeacherSelect = document.querySelector('select[name="teacher_id"]');
            let teacherOptions = '';
            if (originalTeacherSelect) {
                Array.from(originalTeacherSelect.options).forEach(option => {
                    const selected = option.value === course.teacher_id ? 'selected' : '';
                    teacherOptions += `<option value="${option.value}" ${selected}>${option.textContent}</option>`;
                });
            }
            const formHtml = `
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="${_csrfToken}">
                    <input type="hidden" name="course_id" value="${course.id}">
                    <input type="hidden" name="existing_image" value="${course.image_path}">
                    <div class="form-grid">
                        <div class="form-group"><label><i class="fas fa-book"></i> Nom du cours</label><input type="text" name="name" value="${course.name}" required></div>
                        <div class="form-group"><label><i class="fas fa-user-tie"></i> Professeur</label><select name="teacher_id" required>${teacherOptions}</select></div>
                        <div class="form-group"><label><i class="fas fa-info-circle"></i> Description</label><input type="text" name="major" value="${course.major || ''}" required></div>
                        <div class="form-group"><label><i class="fas fa-calendar-alt"></i> Semestre</label><input type="number" name="semester" value="${course.semester}" required min="1" max="10"></div>
                        <div class="form-group"><label><i class="fas fa-weight"></i> Coefficient</label><input type="number" name="coefficient" value="${course.coefficient}" required min="0" step="0.1"></div>
                        <div class="form-group"><label><i class="fas fa-clock"></i> Heures prévues</label><input type="number" name="total_hours" value="${course.total_hours || ''}" min="1" step="1" placeholder="ex: 30"></div>
                        <div class="form-group"><label><i class="fas fa-image"></i> Nouvelle image (optionnel)</label><input type="file" name="course_image" accept="image/*"><div style="margin-top: 10px;"><img src="${course.image_path}" style="width: 100px; height: auto; border-radius: 8px;" alt="Image actuelle"><p style="font-size: 0.9rem; opacity: 0.7;">Image actuelle</p></div></div>
                    </div>
                    <div class="form-group"><label><i class="fas fa-users"></i> Classes concernées</label><div class="checkbox-container"><div class="checkbox-grid" id="editClassesContainer"></div></div></div>
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" name="edit_course" class="btn"><i class="fas fa-save"></i> Sauvegarder</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')"><i class="fas fa-times"></i> Annuler</button>
                    </div>
                </form>`;
            document.getElementById('editForm').innerHTML = formHtml;
            loadClassesForEdit(course.id, course.class_id);
        }

        function loadClassesForEdit(courseId, classIdsJson) {
            const classIds = JSON.parse(classIdsJson || '[]');
            const allClassesContainer = document.querySelector('.checkbox-grid');
            const allClasses = allClassesContainer.querySelectorAll('.checkbox-item');
            let classesHtml = '';
            allClasses.forEach(classItem => {
                const checkbox = classItem.querySelector('input[type="checkbox"]');
                const label = classItem.querySelector('label');
                const classId = checkbox.value;
                const className = label.textContent.trim();
                const isChecked = classIds.includes(parseInt(classId)) || classIds.includes(classId.toString()) ? 'checked' : '';
                classesHtml += `<div class='checkbox-item'><input type='checkbox' name='class_id[]' value='${classId}' id='edit_class_${classId}' ${isChecked}><label for='edit_class_${classId}'>${className}</label></div>`;
            });
            document.getElementById('editClassesContainer').innerHTML = classesHtml;
        }

        function deleteCourse(courseId, courseName) {
            if (confirm(`Êtes-vous sûr de vouloir supprimer le cours "${courseName}" ?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="course_id" value="${courseId}"><input type="hidden" name="delete_course" value="1">`;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        window.onclick = function(event) {
            ['editModal', 'discussionsModal', 'progressModal', 'mDevoirsAdmin', 'mDevoirsGlobal'].forEach(id => {
                const modal = document.getElementById(id);
                if (event.target === modal) modal.style.display = 'none';
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.course-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });

        // ════════════════════════════════════════════════════════
        // MODULE PROGRESSION — JAVASCRIPT
        // ════════════════════════════════════════════════════════
        let progCourseId = null;

        function openProgress(courseId, courseName) {
            progCourseId = courseId;
            document.getElementById('progCourseName').textContent = courseName;
            document.getElementById('progressModal').style.display = 'block';
            document.getElementById('progContent').innerHTML = '<div class="prog-loading"><div class="spinner"></div> Chargement…</div>';
            document.getElementById('btnAddChapter').style.display = 'none';
            document.getElementById('addChapterForm').classList.remove('open');
            loadProgress();
        }

        function loadProgress() {
            fetch(`?ajax=get_progress&course_id=${progCourseId}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        document.getElementById('progContent').innerHTML = '<p style="color:#e74c3c;padding:20px;">Erreur de chargement</p>';
                        return;
                    }
                    updateTotalHours(data.total_hours);
                    renderChapters(data.chapters);
                    document.getElementById('btnAddChapter').style.display = 'flex';
                });
        }

        function updateTotalHours(h) {
            const v = parseFloat(h) || 0;
            document.getElementById('progTotalHours').textContent = (v % 1 === 0 ? v : v.toFixed(1)) + 'h';
        }

        function renderChapters(chapters) {
            const container = document.getElementById('progContent');
            if (!chapters.length) {
                container.innerHTML = '<div class="prog-empty"><i class="fas fa-layer-group"></i><p>Aucun chapitre. Commencez par en ajouter un.</p></div>';
                return;
            }
            container.innerHTML = chapters.map(ch => renderChapterBlock(ch)).join('');
            container.querySelectorAll('.chapter-header').forEach(h => {
                h.addEventListener('click', function(e) {
                    if (e.target.closest('.chapter-actions')) return;
                    toggleChapter(this.dataset.chapterId);
                });
            });
        }

        function renderChapterBlock(ch) {
            const totalChHours = ch.sessions.reduce((s, x) => s + parseFloat(x.hours || 0), 0);
            const hoursLabel = totalChHours > 0 ? (totalChHours % 1 === 0 ? totalChHours : totalChHours.toFixed(1)) + 'h' : '0h';
            const sessHtml = ch.sessions.length
                ? ch.sessions.map(s => renderSessionItem(s, ch.id)).join('')
                : '<div style="font-size:.85rem;color:rgba(255,255,255,.3);padding:8px 0;">Aucune séance</div>';

            return `
            <div class="chapter-block" id="chapter-block-${ch.id}">
                <div class="chapter-header" data-chapter-id="${ch.id}">
                    <span class="chapter-num">Ch. ${ch.order_num}</span>
                    <span class="chapter-title-text" id="ch-title-${ch.id}">${escHtml(ch.title)}</span>
                    <span class="chapter-hours-badge"><i class="fas fa-clock" style="font-size:.7rem;margin-right:4px;"></i>${hoursLabel}</span>
                    <div class="chapter-actions">
                        <button class="icon-btn" title="Modifier" onclick="editChapter(${ch.id})"><i class="fas fa-pen"></i></button>
                        <button class="icon-btn del" title="Supprimer" onclick="deleteChapter(${ch.id})"><i class="fas fa-trash"></i></button>
                    </div>
                    <i class="fas fa-chevron-down chapter-toggle" id="ch-toggle-${ch.id}"></i>
                </div>
                <div class="chapter-body" id="ch-body-${ch.id}">
                    <div class="inline-form" id="edit-ch-form-${ch.id}">
                        <label>Titre du chapitre</label>
                        <input type="text" id="edit-ch-input-${ch.id}" value="${escHtml(ch.title)}" maxlength="255">
                        <div class="inline-form-actions">
                            <button class="btn btn-secondary btn-sm" onclick="cancelEditChapter(${ch.id})"><i class="fas fa-times"></i> Annuler</button>
                            <button class="btn btn-sm" onclick="saveEditChapter(${ch.id})"><i class="fas fa-check"></i> Sauvegarder</button>
                        </div>
                    </div>
                    <div id="sessions-list-${ch.id}">${sessHtml}</div>
                    <div class="inline-form" id="add-sess-form-${ch.id}">
                        <div class="inline-form-grid">
                            <div><label>Titre de la séance *</label><input type="text" id="ns-title-${ch.id}" placeholder="ex: Introduction aux variables" maxlength="255"></div>
                            <div><label>Heures (0.5 – 3h) *</label><input type="number" id="ns-hours-${ch.id}" value="1.5" min="0.5" max="3" step="0.5"></div>
                            <div><label>Date (optionnel)</label><input type="date" id="ns-date-${ch.id}"></div>
                        </div>
                        <div><label>Description / contenu (optionnel)</label><textarea id="ns-desc-${ch.id}" placeholder="Décrivez les notions abordées lors de cette séance…" rows="3"></textarea></div>
                        <div class="inline-form-actions">
                            <button class="btn btn-secondary btn-sm" onclick="cancelAddSession(${ch.id})"><i class="fas fa-times"></i> Annuler</button>
                            <button class="btn btn-sm" onclick="saveNewSession(${ch.id})"><i class="fas fa-check"></i> Ajouter</button>
                        </div>
                    </div>
                    <button class="btn-add-session" onclick="showAddSessionForm(${ch.id})">
                        <i class="fas fa-plus"></i> Ajouter une séance
                    </button>
                </div>
            </div>`;
        }

        function renderSessionItem(s, chapterId) {
            const hours = parseFloat(s.hours || 0);
            const hoursLabel = (hours % 1 === 0 ? hours : hours.toFixed(1)) + 'h';
            const dateHtml = s.session_date
                ? `<span class="session-date-pill"><i class="fas fa-calendar" style="font-size:.65rem;margin-right:3px;"></i>${fmtDate(s.session_date)}</span>` : '';
            const descToggle = s.description
                ? `<button class="session-desc-toggle" id="desc-toggle-${s.id}" onclick="toggleDesc(${s.id})">
                       <i class="fas fa-chevron-down"></i> Voir le contenu
                   </button>
                   <div class="session-desc-body" id="desc-body-${s.id}">${escHtml(s.description)}</div>` : '';

            return `
            <div class="session-item" id="session-item-${s.id}">
                <span class="session-num">S${s.session_number}</span>
                <div class="session-main">
                    <div class="inline-form" id="edit-sess-form-${s.id}">
                        <div class="inline-form-grid">
                            <div><label>Titre *</label><input type="text" id="es-title-${s.id}" value="${escHtml(s.title)}" maxlength="255"></div>
                            <div><label>Heures (0.5 – 3h)</label><input type="number" id="es-hours-${s.id}" value="${hours}" min="0.5" max="3" step="0.5"></div>
                            <div><label>Date</label><input type="date" id="es-date-${s.id}" value="${s.session_date || ''}"></div>
                        </div>
                        <div><label>Description</label><textarea id="es-desc-${s.id}" rows="3">${escHtml(s.description || '')}</textarea></div>
                        <div class="inline-form-actions">
                            <button class="btn btn-secondary btn-sm" onclick="cancelEditSession(${s.id})"><i class="fas fa-times"></i> Annuler</button>
                            <button class="btn btn-sm" onclick="saveEditSession(${s.id})"><i class="fas fa-check"></i> Sauvegarder</button>
                        </div>
                    </div>
                    <div id="sess-display-${s.id}">
                        <div class="session-title-row">
                            <span class="session-title">${escHtml(s.title)}</span>
                            <span class="session-hours-pill"><i class="fas fa-clock" style="font-size:.65rem;margin-right:3px;"></i>${hoursLabel}</span>
                            ${dateHtml}
                        </div>
                        ${descToggle}
                    </div>
                </div>
                <div class="session-actions">
                    <button class="icon-btn" title="Modifier" onclick="editSession(${s.id})"><i class="fas fa-pen"></i></button>
                    <button class="icon-btn del" title="Supprimer" onclick="deleteSession(${s.id}, ${chapterId})"><i class="fas fa-trash"></i></button>
                </div>
            </div>`;
        }

        function toggleChapter(chapterId) {
            document.getElementById('ch-body-' + chapterId).classList.toggle('open');
            document.getElementById('ch-toggle-' + chapterId).classList.toggle('open');
        }

        function toggleDesc(sessionId) {
            const body   = document.getElementById('desc-body-' + sessionId);
            const toggle = document.getElementById('desc-toggle-' + sessionId);
            body.classList.toggle('open');
            toggle.classList.toggle('open');
            toggle.innerHTML = body.classList.contains('open')
                ? '<i class="fas fa-chevron-down"></i> Masquer le contenu'
                : '<i class="fas fa-chevron-down"></i> Voir le contenu';
        }

        function showAddChapterForm() {
            document.getElementById('btnAddChapter').style.display = 'none';
            document.getElementById('addChapterForm').classList.add('open');
            document.getElementById('newChapterTitle').focus();
        }

        function hideAddChapterForm() {
            document.getElementById('addChapterForm').classList.remove('open');
            document.getElementById('btnAddChapter').style.display = 'flex';
            document.getElementById('newChapterTitle').value = '';
        }

        function saveNewChapter() {
            const title = document.getElementById('newChapterTitle').value.trim();
            if (!title) { alert('Le titre est requis'); return; }
            fetch('?ajax=add_chapter', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ course_id: progCourseId, title })
            }).then(r => r.json()).then(data => {
                if (data.success) { hideAddChapterForm(); loadProgress(); }
                else alert('Erreur: ' + (data.message || 'inconnue'));
            });
        }

        function editChapter(chapterId) {
            const body = document.getElementById('ch-body-' + chapterId);
            if (!body.classList.contains('open')) toggleChapter(chapterId);
            document.getElementById('edit-ch-form-' + chapterId).classList.add('open');
            document.getElementById('edit-ch-input-' + chapterId).focus();
        }

        function cancelEditChapter(chapterId) {
            document.getElementById('edit-ch-form-' + chapterId).classList.remove('open');
        }

        function saveEditChapter(chapterId) {
            const title = document.getElementById('edit-ch-input-' + chapterId).value.trim();
            if (!title) { alert('Le titre est requis'); return; }
            fetch('?ajax=edit_chapter', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: chapterId, title })
            }).then(r => r.json()).then(data => {
                if (data.success) {
                    document.getElementById('ch-title-' + chapterId).textContent = title;
                    cancelEditChapter(chapterId);
                } else alert('Erreur lors de la modification');
            });
        }

        function deleteChapter(chapterId) {
            if (!confirm('Supprimer ce chapitre et toutes ses séances ?')) return;
            fetch(`?ajax=delete_chapter&id=${chapterId}`)
                .then(r => r.json()).then(data => {
                    if (data.success) { document.getElementById('chapter-block-' + chapterId).remove(); loadProgress(); }
                    else alert('Erreur lors de la suppression');
                });
        }

        function showAddSessionForm(chapterId) {
            const body = document.getElementById('ch-body-' + chapterId);
            if (!body.classList.contains('open')) toggleChapter(chapterId);
            document.getElementById('add-sess-form-' + chapterId).classList.add('open');
            document.getElementById('ns-title-' + chapterId).focus();
        }

        function cancelAddSession(chapterId) {
            document.getElementById('add-sess-form-' + chapterId).classList.remove('open');
            document.getElementById('ns-title-' + chapterId).value = '';
            document.getElementById('ns-desc-' + chapterId).value = '';
            document.getElementById('ns-hours-' + chapterId).value = '1.5';
            document.getElementById('ns-date-' + chapterId).value = '';
        }

        function saveNewSession(chapterId) {
            const title = document.getElementById('ns-title-' + chapterId).value.trim();
            const hours = parseFloat(document.getElementById('ns-hours-' + chapterId).value) || 1.5;
            const desc  = document.getElementById('ns-desc-' + chapterId).value.trim();
            const date  = document.getElementById('ns-date-' + chapterId).value;
            if (!title) { alert('Le titre de la séance est requis'); return; }
            if (hours < 0.5 || hours > 3) { alert('Les heures doivent être entre 0.5 et 3'); return; }
            fetch('?ajax=add_session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ chapter_id: chapterId, course_id: progCourseId, title, description: desc, hours, session_date: date })
            }).then(r => r.json()).then(data => {
                if (data.success) { cancelAddSession(chapterId); updateTotalHours(data.total_hours); loadProgress(); }
                else alert('Erreur: ' + (data.message || 'inconnue'));
            });
        }

        function editSession(sessionId) {
            document.getElementById('edit-sess-form-' + sessionId).classList.add('open');
            document.getElementById('sess-display-' + sessionId).style.display = 'none';
        }

        function cancelEditSession(sessionId) {
            document.getElementById('edit-sess-form-' + sessionId).classList.remove('open');
            document.getElementById('sess-display-' + sessionId).style.display = 'block';
        }

        function saveEditSession(sessionId) {
            const title = document.getElementById('es-title-' + sessionId).value.trim();
            const hours = parseFloat(document.getElementById('es-hours-' + sessionId).value) || 1.0;
            const desc  = document.getElementById('es-desc-' + sessionId).value.trim();
            const date  = document.getElementById('es-date-' + sessionId).value;
            if (!title) { alert('Le titre est requis'); return; }
            if (hours < 0.5 || hours > 3) { alert('Les heures doivent être entre 0.5 et 3'); return; }
            fetch('?ajax=edit_session', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: sessionId, course_id: progCourseId, title, description: desc, hours, session_date: date })
            }).then(r => r.json()).then(data => {
                if (data.success) { updateTotalHours(data.total_hours); loadProgress(); }
                else alert('Erreur lors de la modification');
            });
        }

        function deleteSession(sessionId, chapterId) {
            if (!confirm('Supprimer cette séance ?')) return;
            fetch(`?ajax=delete_session&id=${sessionId}&course_id=${progCourseId}`)
                .then(r => r.json()).then(data => {
                    if (data.success) { updateTotalHours(data.total_hours); loadProgress(); }
                    else alert('Erreur lors de la suppression');
                });
        }

        function escHtml(str) {
            if (!str) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function fmtDate(d) {
            if (!d) return '';
            return new Date(d).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
        }

        function fmtDatetime(dt) {
            if (!dt) return '—';
            return new Date(dt).toLocaleDateString('fr-FR', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
        }

        function openModal(id) { document.getElementById(id).style.display = 'block'; }

        // ════════════════════════════════════════════════════════
        // MODULE DEVOIRS ADMIN
        // ════════════════════════════════════════════════════════

        function voirDevoirsCours(courseId, courseName) {
            document.getElementById('mDevoirsAdminTitre').textContent = '📋 Devoirs — ' + courseName;
            openModal('mDevoirsAdmin');
            document.getElementById('mDevoirsAdminBody').innerHTML =
                '<div class="prog-loading"><div class="spinner"></div> Chargement…</div>';
            fetch('?action=get_course_assignments_admin&course_id=' + courseId + '&annee=' + encodeURIComponent(ANNEE_FILTRE))
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('mDevoirsAdminBody').innerHTML =
                            '<p style="color:#e74c3c;padding:20px;">' + escHtml(data.error) + '</p>';
                        return;
                    }
                    renderDevoirsAdmin(data.devoirs || []);
                })
                .catch(() => {
                    document.getElementById('mDevoirsAdminBody').innerHTML =
                        '<p style="color:#e74c3c;padding:20px;">Erreur de chargement</p>';
                });
        }

        function renderDevoirsAdmin(devoirs) {
            const container = document.getElementById('mDevoirsAdminBody');
            if (!devoirs.length) {
                container.innerHTML = '<div class="prog-empty"><i class="fas fa-tasks"></i><p>Aucun devoir pour cette année</p></div>';
                return;
            }
            const now = new Date();
            container.innerHTML = devoirs.map(d => {
                const deadline    = new Date(d.due_date);
                const cloture     = deadline < now;
                const badge       = cloture
                    ? '<span class="badge-cloture">Clôturé</span>'
                    : '<span class="badge-en-cours">En cours</span>';
                const nb_rendus   = parseInt(d.nb_rendus)    || 0;
                const nb_etud     = parseInt(d.nb_etudiants) || 0;
                const enonce      = d.file_path
                    ? `<a href="../uploads/${escHtml(d.file_path)}" target="_blank" class="btn btn-sm"
                          style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2);
                                 text-decoration:none;color:#fff;">
                           <i class="fas fa-paperclip"></i> Énoncé
                       </a>`
                    : '';
                return `
                <div class="devoir-card">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;
                                flex-wrap:wrap;gap:8px;margin-bottom:8px;">
                        <div style="font-weight:600;font-size:1rem;">📝 ${escHtml(d.title)}</div>
                        ${badge}
                    </div>
                    <div style="font-size:.87rem;opacity:.7;margin-bottom:4px;">
                        ⏰ Deadline : ${fmtDate(d.due_date)}
                        &nbsp;·&nbsp; <i class="fas fa-user-tie" style="opacity:.6;"></i> ${escHtml(d.teacher_name)}
                    </div>
                    <div style="font-size:.87rem;margin-bottom:10px;">
                        👥 <strong>${nb_rendus}/${nb_etud}</strong> rendus
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        ${enonce}
                        <button class="btn btn-sm"
                                onclick="voirRendusAdmin(${d.id})"
                                style="background:rgba(3,155,229,.15);border:1px solid rgba(3,155,229,.3);color:#039be5;">
                            <i class="fas fa-eye"></i> Voir rendus
                        </button>
                    </div>
                    <div id="rendus-${d.id}" style="display:none;margin-top:12px;"></div>
                </div>`;
            }).join('');
        }

        function voirRendusAdmin(assignmentId) {
            const container = document.getElementById('rendus-' + assignmentId);
            if (!container) return;
            if (container.style.display !== 'none') { container.style.display = 'none'; return; }
            container.style.display = 'block';
            container.innerHTML = '<div class="prog-loading" style="padding:10px;"><div class="spinner"></div> Chargement…</div>';
            fetch('?action=get_assignment_submissions&assignment_id=' + assignmentId)
                .then(r => r.json())
                .then(data => renderRendusAdmin(data.rendus || [], container));
        }

        function renderRendusAdmin(rendus, container) {
            if (!rendus.length) {
                container.innerHTML = '<p style="font-size:.85rem;opacity:.6;padding:8px;">Aucun rendu pour ce devoir</p>';
                return;
            }
            container.innerHTML = `
            <div style="overflow-x:auto;">
            <table class="rendus-table">
                <thead>
                    <tr>
                        <th>Étudiant</th>
                        <th>Date rendu</th>
                        <th>Fichier</th>
                        <th>Commentaire</th>
                    </tr>
                </thead>
                <tbody>
                    ${rendus.map(r => {
                        const isLate  = r.due_date && new Date(r.submitted_at) > new Date(r.due_date);
                        const lateBadge = isLate ? '<span class="badge-retard">En retard</span>' : '';
                        const fileBtn   = r.file_path
                            ? `<a href="../uploads/${escHtml(r.file_path)}" target="_blank" download
                                  style="color:#039be5;text-decoration:none;">
                                   <i class="fas fa-download"></i> Télécharger
                               </a>`
                            : '<span style="opacity:.4;">—</span>';
                        return `<tr>
                            <td>${escHtml(r.student_name)}${lateBadge}</td>
                            <td style="white-space:nowrap;">${fmtDatetime(r.submitted_at)}</td>
                            <td>${fileBtn}</td>
                            <td>${r.comment ? escHtml(r.comment) : '<span style="opacity:.4;">—</span>'}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
            </div>`;
        }

        let _allDevoirsGlobal = [];

        function voirTousDevoirs() {
            openModal('mDevoirsGlobal');
            document.getElementById('mDevoirsGlobalBody').innerHTML =
                '<div class="prog-loading"><div class="spinner"></div> Chargement…</div>';
            fetch('?action=get_all_assignments_admin&annee=' + encodeURIComponent(ANNEE_FILTRE))
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('mDevoirsGlobalBody').innerHTML =
                            '<p style="color:#e74c3c;padding:20px;">' + escHtml(data.error) + '</p>';
                        return;
                    }
                    _allDevoirsGlobal = data.devoirs || [];
                    remplirFiltresGlobal(_allDevoirsGlobal);
                    renderDevoirsGlobal(_allDevoirsGlobal);
                })
                .catch(() => {
                    document.getElementById('mDevoirsGlobalBody').innerHTML =
                        '<p style="color:#e74c3c;padding:20px;">Erreur de chargement</p>';
                });
        }

        function remplirFiltresGlobal(devoirs) {
            const cours = [...new Set(devoirs.map(d => d.course_name))].sort();
            const profs = [...new Set(devoirs.map(d => d.teacher_name))].sort();

            const selCours = document.getElementById('filtGlobalCours');
            const curCours = selCours.value;
            selCours.innerHTML = '<option value="">Tous les cours</option>'
                + cours.map(c => `<option value="${escHtml(c)}">${escHtml(c)}</option>`).join('');
            selCours.value = curCours;

            const selProf = document.getElementById('filtGlobalProf');
            const curProf = selProf.value;
            selProf.innerHTML = '<option value="">Tous les profs</option>'
                + profs.map(p => `<option value="${escHtml(p)}">${escHtml(p)}</option>`).join('');
            selProf.value = curProf;
        }

        function filtrerDevoirsGlobal() {
            const cours  = document.getElementById('filtGlobalCours').value;
            const statut = document.getElementById('filtGlobalStatut').value;
            const prof   = document.getElementById('filtGlobalProf').value;
            const now    = new Date();
            const filtered = _allDevoirsGlobal.filter(d => {
                if (cours  && d.course_name  !== cours)  return false;
                if (prof   && d.teacher_name !== prof)   return false;
                if (statut) {
                    const cloture = new Date(d.due_date) < now;
                    if (statut === 'en_cours' && cloture)  return false;
                    if (statut === 'cloture'  && !cloture) return false;
                }
                return true;
            });
            renderDevoirsGlobal(filtered);
        }

        function renderDevoirsGlobal(devoirs) {
            const container = document.getElementById('mDevoirsGlobalBody');
            if (!devoirs.length) {
                container.innerHTML = '<div class="prog-empty"><i class="fas fa-tasks"></i><p>Aucun devoir trouvé</p></div>';
                return;
            }
            const now = new Date();
            container.innerHTML = `
            <div style="overflow-x:auto;">
            <table class="devoirs-table">
                <thead>
                    <tr>
                        <th>Cours</th>
                        <th>Prof</th>
                        <th>Titre</th>
                        <th>Deadline</th>
                        <th>Rendus</th>
                        <th>Statut</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${devoirs.map(d => {
                        const cloture = new Date(d.due_date) < now;
                        const badge   = cloture
                            ? '<span class="badge-cloture">Clôturé</span>'
                            : '<span class="badge-en-cours">En cours</span>';
                        return `
                        <tr>
                            <td>${escHtml(d.course_name)}</td>
                            <td style="white-space:nowrap;">${escHtml(d.teacher_name)}</td>
                            <td style="font-weight:600;">${escHtml(d.title)}</td>
                            <td style="white-space:nowrap;">${fmtDate(d.due_date)}</td>
                            <td>${d.nb_uniq_rendus ?? 0}</td>
                            <td>${badge}</td>
                            <td>
                                <button class="btn btn-sm"
                                        onclick="voirRendusGlobal(${d.id})"
                                        style="background:rgba(3,155,229,.15);border:1px solid rgba(3,155,229,.3);
                                               color:#039be5;white-space:nowrap;">
                                    <i class="fas fa-eye"></i> Rendus
                                </button>
                            </td>
                        </tr>
                        <tr id="rendus-global-row-${d.id}" style="display:none;">
                            <td colspan="7" style="padding:0 8px 12px;">
                                <div id="rendus-global-${d.id}"></div>
                            </td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>
            </div>`;
        }

        function voirRendusGlobal(assignmentId) {
            const row       = document.getElementById('rendus-global-row-' + assignmentId);
            const container = document.getElementById('rendus-global-' + assignmentId);
            if (!row) return;
            if (row.style.display !== 'none') { row.style.display = 'none'; return; }
            row.style.display = '';
            container.innerHTML = '<div class="prog-loading" style="padding:10px;"><div class="spinner"></div> Chargement…</div>';
            fetch('?action=get_assignment_submissions&assignment_id=' + assignmentId)
                .then(r => r.json())
                .then(data => renderRendusAdmin(data.rendus || [], container));
        }
    </script>
</body>
</html>

<?php
$conn->close();
?>