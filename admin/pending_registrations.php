<?php
// pending_registrations.php (version avec SendGrid)
session_start();
require_once '../includes/db_connect.php';
require_once '../vendor/autoload.php'; // SendGrid

use SendGrid\Mail\Mail;

// Activer l'affichage des erreurs pour le débogage
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Définir le fuseau horaire
date_default_timezone_set('Africa/Libreville');

// Vérifier si l'utilisateur est connecté et est un administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

// Forcer UTF-8
$conn->set_charset('utf8mb4');
$conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

$current_admin_id = $_SESSION['user_id'] ?? null;

// Configuration SendGrid
define('SENDGRID_FROM_EMAIL', 'contact@uvcoding.com');
define('SENDGRID_FROM_NAME', 'Université Virtuelle');

// IDs des templates SendGrid (à remplacer par vos vrais IDs)
define('TEMPLATE_ACCOUNT_APPROVED', 'd-cc24b02213a34194ae272fdc9cb87481'); // Template validation
define('TEMPLATE_ACCOUNT_DECLINED', 'd-d17e0164f4a24df1ad63e21f5d978152'); // Template refus

// Fonction pour envoyer un email via SendGrid
function send_email_with_sendgrid($to_email, $to_name, $template_id, $dynamic_data) {
    try {
        $email = new Mail();
        $email->setFrom(SENDGRID_FROM_EMAIL, SENDGRID_FROM_NAME);
        $email->addTo($to_email, $to_name);
        $email->setTemplateId($template_id);
        $email->addDynamicTemplateDatas($dynamic_data);
        $email->setReplyTo(SENDGRID_FROM_EMAIL, SENDGRID_FROM_NAME);
        $email->addCategory('student_registration');
        
        $sg_res = $GLOBALS['conn']->query("SELECT valeur FROM parametres WHERE cle='sendgrid_api_key' LIMIT 1");
        $sg_key = $sg_res ? trim($sg_res->fetch_assoc()['valeur'] ?? '') : '';
        if (empty($sg_key)) { return ['success' => false, 'message' => 'Clé API SendGrid non configurée']; }
        $sendgrid = new \SendGrid($sg_key);
        $response = $sendgrid->send($email);

        if ($response->statusCode() == 202) {
            return ['success' => true, 'message' => 'Email envoyé avec succès'];
        } else {
            return ['success' => false, 'message' => 'Code erreur: ' . $response->statusCode()];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Fonction de log admin (inchangée)
function log_admin_action($conn, $admin_id, $action_type, $description, $entity_id = null, $entity_type = null, $entity_name = null) {
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $conn->prepare("
            INSERT INTO admin_logs 
            (admin_id, action_type, description, ip_address, entity_id, entity_type, entity_name, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "ssssssss",
            $admin_id,
            $action_type,
            $description,
            $ip_address,
            $entity_id,
            $entity_type,
            $entity_name,
            $user_agent
        );
        $stmt->execute();
        $stmt->close();
    } catch (Exception $e) {
        // Ignorer les erreurs de log pour ne pas bloquer le flux
    }
}

// Fonction pour supprimer un utilisateur (décliner l'inscription)
function decline_student($conn, $temp_id, $admin_id, $decline_reason = '') {
    try {
        // Récupérer les informations de l'étudiant avant suppression
        $stmt = $conn->prepare("SELECT name, email, avatar FROM users WHERE id = ?");
        $stmt->bind_param("s", $temp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Étudiant introuvable'];
        }
        
        $student = $result->fetch_assoc();
        $stmt->close();
        
        // Supprimer l'avatar s'il existe
        if (!empty($student['avatar']) && $student['avatar'] !== 'default_avatar.png') {
            // La BDD stocke : uploads/avatars/fichier.jpg
            // pending_registrations.php est dans : public_html/pages/
            // Donc le chemin physique depuis pages/ = '../' + chemin BDD
            $avatar_path = '../' . $student['avatar'];
            if (file_exists($avatar_path)) {
                unlink($avatar_path);
            }
        }
        
        // Supprimer l'utilisateur de la base de données
        $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $delete_stmt->bind_param("s", $temp_id);
        $success = $delete_stmt->execute();
        $delete_stmt->close();
        
        if ($success) {
            // Log admin
            log_admin_action(
                $conn,
                $admin_id,
                'decline_student',
                "Refus de l'inscription : {$student['name']} ({$student['email']}) - Raison: $decline_reason",
                $temp_id,
                'student',
                $student['name']
            );
            
            // Déterminer l'URL du site actuel
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $contact_url = $protocol . "://" . $host . "/contact";
            
            // Envoyer un email de refus via SendGrid
            $email_result = send_email_with_sendgrid(
                $student['email'],
                $student['name'],
                TEMPLATE_ACCOUNT_DECLINED,
                [
                    'student_name' => $student['name'],
                    'decline_reason' => !empty($decline_reason) ? $decline_reason : 'Votre inscription ne répond pas aux critères requis pour l\'accès à la plateforme.',
                    'contact_url' => $contact_url,
                    'support_email' => SENDGRID_FROM_EMAIL,
                    'current_year' => date('Y')
                ]
            );
            
            return [
                'success' => true, 
                'message' => 'Inscription refusée',
                'email_sent' => $email_result['success']
            ];
        }
        
        return ['success' => false, 'message' => 'Erreur lors de la suppression'];
    } catch (Exception $e) {
        error_log("Erreur lors du refus de l'inscription: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Fonction pour générer un ID automatique (inchangée)
function generate_student_id($conn, $class_id) {
    if (empty($class_id)) {
        $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'student' AND blocked = 0");
        $count_stmt->execute();
        $result = $count_stmt->get_result();
        $row = $result->fetch_assoc();
        $next_number = $row['total'] + 1;
        $count_stmt->close();
        
        return "ISMM-STU-" . str_pad($next_number, 2, '0', STR_PAD_LEFT);
    }
    
    $class_stmt = $conn->prepare("SELECT code, name FROM classes WHERE id = ?");
    $class_stmt->bind_param("s", $class_id);
    $class_stmt->execute();
    $class_result = $class_stmt->get_result();
    
    if ($class_result->num_rows === 0) {
        $class_stmt->close();
        return "ISMM-STU-" . str_pad(rand(1, 99), 2, '0', STR_PAD_LEFT);
    }
    
    $class = $class_result->fetch_assoc();
    $class_code = strtoupper($class['code'] ?? 'GEN');
    $class_name = $class['name'];
    $class_stmt->close();
    
    $year = '';
    if (!preg_match('/\d+$/', $class_code)) {
        if (preg_match('/(\d)[èe]+ (?:année|Année|ANNÉE)/i', $class_name, $matches)) {
            $year = $matches[1];
        } elseif (preg_match('/^(\d)/', $class_name, $matches)) {
            $year = $matches[1];
        } elseif (preg_match('/(\d)[èe]/i', $class_name, $matches)) {
            $year = $matches[1];
        } else {
            $year = '1';
        }
    }
    
    $count_stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM users 
        WHERE role = 'student' 
        AND class_id = ? 
        AND blocked = 0
    ");
    $count_stmt->bind_param("s", $class_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $row = $count_result->fetch_assoc();
    $next_number = $row['total'] + 1;
    $count_stmt->close();
    
    return "ISMM-" . $class_code . $year . "-" . str_pad($next_number, 2, '0', STR_PAD_LEFT);
}

// Messages
$success_message = "";
$error_message = "";

// Action de validation
if (isset($_POST['approve_student']) && !empty($_POST['temp_id'])) {
    $temp_id = $_POST['temp_id'];
    $new_id = trim(strtoupper($_POST['new_id']));
    $new_class_id = !empty($_POST['new_class_id']) ? $_POST['new_class_id'] : null;
    
    if (!preg_match('/^[A-Z0-9\-]{3,20}$/', $new_id)) {
        $error_message = "❌ Format d'ID invalide. Utilisez uniquement des lettres majuscules, chiffres et tirets (3-20 caractères).";
    } else {
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $check_stmt->bind_param("s", $new_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_message = "❌ L'ID '$new_id' est déjà utilisé. Veuillez en choisir un autre.";
        } else {
            $stmt = $conn->prepare("SELECT name, email, class_id FROM users WHERE id = ? AND role = 'student' AND blocked = 1");
            $stmt->bind_param("s", $temp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                
                if ($new_class_id !== null) {
                    $update_stmt = $conn->prepare("UPDATE users SET id = ?, class_id = ?, blocked = 0 WHERE id = ?");
                    $update_stmt->bind_param("sss", $new_id, $new_class_id, $temp_id);
                } else {
                    $update_stmt = $conn->prepare("UPDATE users SET id = ?, blocked = 0 WHERE id = ?");
                    $update_stmt->bind_param("ss", $new_id, $temp_id);
                }
                
                if ($update_stmt->execute()) {
                    // Mettre à jour le user_id dans candidatures
                    $cand_stmt = $conn->prepare("UPDATE candidatures SET user_id = ? WHERE user_id = ?");
                    $cand_stmt->bind_param("ss", $new_id, $temp_id);
                    $cand_stmt->execute();
                    $cand_stmt->close();

                    // Récupérer le nom de la classe
                    $class_name = "Aucune classe";
                    if ($new_class_id) {
                        $class_stmt = $conn->prepare("SELECT name FROM classes WHERE id = ?");
                        $class_stmt->bind_param("s", $new_class_id);
                        $class_stmt->execute();
                        $class_result = $class_stmt->get_result();
                        if ($class_result->num_rows > 0) {
                            $class_row = $class_result->fetch_assoc();
                            $class_name = $class_row['name'];
                        }
                        $class_stmt->close();
                    }
                    
                    // Déterminer l'URL du site actuel
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                    $host = $_SERVER['HTTP_HOST'];
                    $login_url = $protocol . "://" . $host . "/pages/login.html";
                    
                    // Envoyer l'email via SendGrid
                    $email_result = send_email_with_sendgrid(
                        $user['email'],
                        $user['name'],
                        TEMPLATE_ACCOUNT_APPROVED,
                        [
                            'student_name' => $user['name'],
                            'student_id' => $new_id,
                            'student_email' => $user['email'],
                            'class_name' => $class_name,
                            'login_url' => $login_url,
                            'support_email' => SENDGRID_FROM_EMAIL,
                            'current_year' => date('Y'),
                            'prenom'       => explode(' ', $user['name'])[0],   // Premier mot du nom
                            'NOM'          => $user['name'],
                            'reference'    => $new_id, 
                        ]
                    );
                    
                    if ($email_result['success']) {
                        $success_message = "✅ Étudiant validé ! ID assigné : <strong>$new_id</strong>. Email de confirmation envoyé à {$user['email']}.";
                    } else {
                        $success_message = "✅ Étudiant validé ! ID assigné : <strong>$new_id</strong>. ⚠️ Erreur d'envoi de l'email : " . $email_result['message'];
                    }
                    
                    log_admin_action(
                        $conn,
                        $current_admin_id,
                        'approve_student',
                        "Validation de l'inscription : ID '$temp_id' → '$new_id' ({$user['name']}) - Classe: $class_name",
                        $new_id,
                        'student',
                        $user['name']
                    );
                } else {
                    $error_message = "❌ Erreur lors de la mise à jour : " . $conn->error;
                }
                $update_stmt->close();
            } else {
                $error_message = "⚠️ Étudiant introuvable ou déjà validé.";
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
}

// Action de refus
if (isset($_POST['decline_student']) && !empty($_POST['temp_id'])) {
    $temp_id = $_POST['temp_id'];
    $reason = trim($_POST['decline_reason'] ?? '');
    
    $result = decline_student($conn, $temp_id, $current_admin_id, $reason);
    
    if ($result['success']) {
        if ($result['email_sent']) {
            $success_message = "❌ Inscription refusée avec succès. L'étudiant a été notifié par email.";
        } else {
            $success_message = "❌ Inscription refusée. ⚠️ Erreur lors de l'envoi de l'email de notification.";
        }
    } else {
        $error_message = "⚠️ Erreur lors du refus de l'inscription : " . $result['message'];
    }
}

// Récupérer toutes les classes disponibles
$classes_sql = "SELECT id, name, code FROM classes ORDER BY name ASC";
$classes_result = $conn->query($classes_sql);
$classes = [];
while ($class = $classes_result->fetch_assoc()) {
    $classes[] = $class;
}

// Récupérer les inscriptions en attente (avec données candidature)
$pending_sql = "SELECT u.*, 
                    c.name AS class_name, c.code AS class_code,
                    cand.ref_dossier, cand.niveau, cand.specialite, cand.exp_pro,
                    cand.domaine_pro, cand.ville, cand.mode_paiement,
                    cand.preuve_paiement, cand.cv_path, cand.diplome_path,
                    cand.cni_path, cand.lettre_path
                FROM users u 
                LEFT JOIN classes c ON u.class_id = c.id 
                LEFT JOIN candidatures cand ON cand.user_id = u.id
                WHERE u.role = 'student' AND u.blocked = 1 
                ORDER BY u.created_at DESC";
$pending_result = $conn->query($pending_sql);

$pending_students = [];
while ($student = $pending_result->fetch_assoc()) {
    $generated_id = generate_student_id($conn, $student['class_id']);
    $student['generated_id'] = $generated_id;
    $pending_students[] = $student;
}

// Statistiques
$stats_sql = "SELECT 
    COUNT(CASE WHEN blocked = 1 THEN 1 END) as en_attente,
    COUNT(CASE WHEN blocked = 0 THEN 1 END) as valides,
    COUNT(*) as total
    FROM users WHERE role = 'student'";
$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscriptions en attente - Administration UV</title>
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
            --warning-color: #f39c12;
            --orange: #ff9500;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Google Sans', Arial, sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-light);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        main {
            padding: 20px;
            flex: 1;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        h2 {
            color: var(--orange);
            margin-bottom: 30px;
            font-size: 28px;
            text-align: center;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card .icon {
            font-size: 40px;
            margin-bottom: 15px;
        }

        .stat-card.pending .icon { color: var(--warning-color); }
        .stat-card.approved .icon { color: var(--success-color); }
        .stat-card.total .icon { color: var(--accent-color); }

        .stat-card .number {
            font-size: 48px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .stat-card.pending .number { color: var(--warning-color); }
        .stat-card.approved .number { color: var(--success-color); }
        .stat-card.total .number { color: var(--accent-color); }

        .stat-card .label {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
        }

        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateX(-20px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .success-message {
            background: rgba(46, 204, 113, 0.15);
            border-left: 4px solid var(--success-color);
            color: var(--success-color);
        }

        .error-message {
            background: rgba(231, 76, 60, 0.15);
            border-left: 4px solid var(--error-color);
            color: var(--error-color);
        }

        .pending-list {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 20px;
            border: 1px solid var(--border-color);
        }

        .pending-list > h3 {
            color: var(--orange);
            margin-bottom: 20px;
            font-size: 20px;
        }

        .pending-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 25px;
            align-items: start;
            transition: all 0.3s;
        }

        .pending-item:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--orange);
            box-shadow: 0 5px 15px rgba(255, 149, 0, 0.2);
        }

        .avatar-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            min-width: 180px;
        }

        .student-avatar {
            width: 150px;
            height: 150px;
            border-radius: 12px;
            object-fit: cover;
            border: 3px solid var(--accent-color);
            background: var(--secondary-bg);
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .student-avatar:hover {
            transform: scale(1.05);
            border-color: var(--orange);
            box-shadow: 0 6px 20px rgba(255, 149, 0, 0.4);
        }

        .avatar-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 12px;
            background: var(--secondary-bg);
            border: 3px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
            font-size: 50px;
            cursor: default;
        }

        .click-hint {
            font-size: 11px;
            color: var(--accent-color);
            text-align: center;
            margin-top: 5px;
            opacity: 0.8;
        }

        .click-hint i {
            margin-right: 3px;
        }

        .student-info {
            flex: 1;
        }

        .student-info h3 {
            color: var(--orange);
            margin: 0 0 15px 0;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .pending-badge {
            background: var(--warning-color);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        .detail {
            font-size: 14px;
            margin: 8px 0;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail i {
            color: var(--accent-color);
            width: 20px;
            text-align: center;
        }

        .detail strong {
            color: var(--text-light);
            min-width: 100px;
        }

        .temp-id-box {
            background: rgba(255, 149, 0, 0.1);
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            border-left: 3px solid var(--orange);
        }

        .temp-id-box code {
            color: var(--warning-color);
            font-weight: bold;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }

        .avatar-info {
            background: rgba(3, 155, 229, 0.1);
            padding: 8px 12px;
            border-radius: 5px;
            margin: 5px 0;
            border-left: 3px solid var(--accent-color);
            font-size: 12px;
            text-align: center;
        }

        .form-section {
            display: flex;
            flex-direction: column;
            gap: 15px;
            min-width: 320px;
        }

        .info-box {
            background: rgba(3, 155, 229, 0.1);
            border-left: 4px solid var(--accent-color);
            padding: 12px;
            border-radius: 5px;
            font-size: 12px;
            color: #aaa;
        }

        .info-box i {
            color: var(--accent-color);
            margin-right: 5px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group label {
            font-size: 13px;
            font-weight: bold;
            color: var(--orange);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .auto-badge {
            background: var(--accent-color);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: bold;
            margin-left: auto;
        }

        .class-select, .id-input, .reason-textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 14px;
            transition: all 0.3s;
            resize: vertical;
        }

        .id-input {
            font-family: 'Courier New', monospace;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .class-select:focus, .id-input:focus, .reason-textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 10px rgba(3, 155, 229, 0.3);
        }

        .format-hint {
            font-size: 11px;
            color: #999;
            font-style: italic;
        }

        .buttons-container {
            display: flex;
            gap: 10px;
            flex-direction: column;
        }

        .approve-btn {
            background: linear-gradient(135deg, var(--success-color), #27ae60);
            color: white;
            border: none;
            padding: 14px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex: 1;
        }

        .approve-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(46, 204, 113, 0.4);
        }

        .decline-btn {
            background: linear-gradient(135deg, var(--error-color), #c0392b);
            color: white;
            border: none;
            padding: 14px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex: 1;
        }

        .decline-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(231, 76, 60, 0.4);
        }

        .approve-btn:active, .decline-btn:active {
            transform: translateY(0);
        }

        /* ★ NOUVEAU : bouton impression fiche ISMM */
        .print-fiche-btn {
            background: linear-gradient(135deg, #0a1c2e, #1a3a5c);
            color: white;
            border: none;
            padding: 14px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            flex: 1;
        }

        .print-fiche-btn:hover {
            background: linear-gradient(135deg, #ff9500, #ff8c00);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 149, 0, 0.4);
            color: white;
        }

        .print-fiche-btn:active {
            transform: translateY(0);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .back-link {
            text-align: center;
            margin-top: 30px;
        }

        .back-link a {
            color: var(--accent-color);
            text-decoration: none;
            transition: color 0.3s;
        }

        .back-link a:hover {
            color: var(--orange);
        }

        /* Modal pour l'image agrandie */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            position: relative;
            margin: auto;
            display: block;
            width: auto;
            height: auto;
            max-width: 90%;
            max-height: 90%;
            top: 50%;
            transform: translateY(-50%);
            border-radius: 10px;
            box-shadow: 0 0 30px rgba(255, 149, 0, 0.3);
            animation: zoomIn 0.3s ease;
        }

        @keyframes zoomIn {
            from { transform: translateY(-50%) scale(0.8); opacity: 0; }
            to { transform: translateY(-50%) scale(1); opacity: 1; }
        }

        .modal-caption {
            position: absolute;
            bottom: -50px;
            left: 0;
            width: 100%;
            text-align: center;
            color: white;
            font-size: 16px;
            padding: 10px;
        }

        .close-modal {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 1001;
            transition: color 0.3s;
        }

        .close-modal:hover {
            color: var(--orange);
        }

        .modal-controls {
            position: absolute;
            bottom: 20px;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .modal-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .modal-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        /* Modal pour le refus */
        .decline-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            animation: fadeIn 0.3s ease;
        }

        .decline-modal-content {
            background: var(--secondary-bg);
            margin: 10% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            border: 2px solid var(--error-color);
            box-shadow: 0 0 30px rgba(231, 76, 60, 0.3);
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .decline-modal-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            color: var(--error-color);
        }

        .decline-modal-header h3 {
            font-size: 20px;
        }

        .decline-modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .confirm-decline-btn {
            background: var(--error-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            flex: 1;
            transition: all 0.3s;
        }

        .confirm-decline-btn:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }

        .cancel-decline-btn {
            background: var(--border-color);
            color: var(--text-light);
            border: none;
            padding: 12px 25px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            flex: 1;
            transition: all 0.3s;
        }

        .cancel-decline-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .pending-item {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .avatar-section {
                order: -1;
            }
            
            .student-avatar, .avatar-placeholder {
                margin: 0 auto;
            }
            
            .form-section {
                min-width: 100%;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .modal-content {
                max-width: 95%;
                max-height: 80%;
            }

            .buttons-container {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    
<?php include '../includes/header_admin.php'; ?>

<main>
    <h2><i class="fas fa-user-clock"></i> Inscriptions en attente de validation</h2>

    <?php if (!empty($success_message)): ?>
        <div class="message success-message">
            <i class="fas fa-check-circle"></i> 
            <span><?php echo $success_message; ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="message error-message">
            <i class="fas fa-exclamation-triangle"></i> 
            <span><?php echo $error_message; ?></span>
        </div>
    <?php endif; ?>

    <!-- Statistiques -->
    <div class="stats-container">
        <div class="stat-card pending">
            <div class="icon">⏳</div>
            <div class="number"><?php echo $stats['en_attente']; ?></div>
            <div class="label">En attente</div>
        </div>
        
        <div class="stat-card approved">
            <div class="icon">✅</div>
            <div class="number"><?php echo $stats['valides']; ?></div>
            <div class="label">Validés</div>
        </div>
        
        <div class="stat-card total">
            <div class="icon">👥</div>
            <div class="number"><?php echo $stats['total']; ?></div>
            <div class="label">Total étudiants</div>
        </div>
    </div>

    <!-- Liste des inscriptions en attente -->
    <div class="pending-list">
        <h3>
            <i class="fas fa-list"></i> Nouvelles inscriptions (<?php echo count($pending_students); ?>)
        </h3>

        <?php if (count($pending_students) > 0): ?>
            <?php foreach ($pending_students as $index => $student): ?>
                <div class="pending-item">
                    <!-- Avatar de l'étudiant -->
                    <div class="avatar-section">
                        <?php
                        // La BDD stocke : uploads/avatars/fichier.jpg  (ou 'default_avatar.png')
                        // Chemin physique depuis pages/  = '../' + valeur BDD
                        // Chemin URL depuis pages/       = '../' + valeur BDD
                        $avatarDb    = $student['avatar'] ?? '';
                        $isDefault   = (empty($avatarDb) || $avatarDb === 'default_avatar.png');
                        $avatarPhys  = $isDefault ? '' : ('../' . $avatarDb);
                        $avatarUrl   = $isDefault ? '' : ('../' . $avatarDb);
                        $avatarHasFile = !$isDefault && file_exists($avatarPhys);
                        ?>

                        <?php if ($avatarHasFile): ?>
                            <img src="<?php echo htmlspecialchars($avatarUrl); ?>"
                                 alt="Avatar de <?php echo htmlspecialchars($student['name']); ?>"
                                 class="student-avatar"
                                 onclick="openModal('<?php echo htmlspecialchars($avatarUrl); ?>', '<?php echo htmlspecialchars(addslashes($student['name'])); ?>')"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="avatar-placeholder" style="display:none;">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="click-hint">
                                <i class="fas fa-search-plus"></i> Cliquer pour agrandir
                            </div>
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <i class="fas fa-user"></i>
                            </div>
                        <?php endif; ?>

                        <?php if ($avatarHasFile): ?>
                        <div class="avatar-info">
                            <i class="fas fa-image"></i>
                            <strong>Avatar:</strong>
                            <?php echo htmlspecialchars(basename($avatarDb)); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="student-info">
                        <h3>
                            <i class="fas fa-user"></i>
                            <?php echo htmlspecialchars($student['name']); ?>
                            <span class="pending-badge">⏳ EN ATTENTE</span>
                        </h3>
                        
                        <div class="temp-id-box">
                            <div class="detail">
                                <i class="fas fa-id-card-clip"></i>
                                <strong>ID Temporaire:</strong>
                                <code><?php echo htmlspecialchars($student['id']); ?></code>
                            </div>
                            <small style="margin-left: 28px; color: #999; font-size: 11px;">
                                (sera remplacé lors de la validation)
                            </small>
                        </div>
                        
                        <div class="detail">
                            <i class="fas fa-envelope"></i>
                            <strong>Email:</strong>
                            <span><?php echo htmlspecialchars($student['email']); ?></span>
                        </div>
                        
                        <?php if (!empty($student['phone'])): ?>
                        <div class="detail">
                            <i class="fas fa-phone"></i>
                            <strong>Téléphone:</strong>
                            <span><?php echo htmlspecialchars($student['phone']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['birth_date'])): ?>
                        <div class="detail">
                            <i class="fas fa-birthday-cake"></i>
                            <strong>Naissance:</strong>
                            <span><?php echo date('d/m/Y', strtotime($student['birth_date'])); ?>
                                <?php if (!empty($student['birth_place'])): ?>
                                    — <?php echo htmlspecialchars($student['birth_place']); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['sexe']) || !empty($student['nationalite'])): ?>
                        <div class="detail">
                            <i class="fas fa-id-card"></i>
                            <strong>Identité:</strong>
                            <span>
                                <?php if (!empty($student['sexe'])): ?><?php echo $student['sexe'] === 'M' ? 'Masculin' : 'Féminin'; ?><?php endif; ?>
                                <?php if (!empty($student['nationalite'])): ?> — <?php echo htmlspecialchars($student['nationalite']); ?><?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['address'])): ?>
                        <div class="detail">
                            <i class="fas fa-map-marker-alt"></i>
                            <strong>Adresse:</strong>
                            <span><?php echo htmlspecialchars($student['address']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['tuteur_nom'])): ?>
                        <div class="detail">
                            <i class="fas fa-user-tie"></i>
                            <strong>Tuteur légal:</strong>
                            <span><?php echo htmlspecialchars($student['tuteur_nom']); ?>
                                <?php if (!empty($student['tuteur_lien'])): ?>(<?php echo htmlspecialchars($student['tuteur_lien']); ?>)<?php endif; ?>
                                <?php if (!empty($student['tuteur_telephone'])): ?> — <?php echo htmlspecialchars($student['tuteur_telephone']); ?><?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['urgence_nom'])): ?>
                        <div class="detail">
                            <i class="fas fa-ambulance"></i>
                            <strong>Urgence:</strong>
                            <span><?php echo htmlspecialchars($student['urgence_nom']); ?>
                                <?php if (!empty($student['urgence_lien'])): ?>(<?php echo htmlspecialchars($student['urgence_lien']); ?>)<?php endif; ?>
                                <?php if (!empty($student['urgence_telephone'])): ?> — <?php echo htmlspecialchars($student['urgence_telephone']); ?><?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['dernier_diplome'])): ?>
                        <div class="detail">
                            <i class="fas fa-certificate"></i>
                            <strong>Dernier diplôme:</strong>
                            <span><?php echo htmlspecialchars($student['dernier_diplome']); ?>
                                <?php if (!empty($student['diplome_serie'])): ?> — <?php echo htmlspecialchars($student['diplome_serie']); ?><?php endif; ?>
                                <?php if (!empty($student['diplome_annee'])): ?> (<?php echo htmlspecialchars($student['diplome_annee']); ?>)<?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['etablissement_origine'])): ?>
                        <div class="detail">
                            <i class="fas fa-university"></i>
                            <strong>Établissement:</strong>
                            <span><?php echo htmlspecialchars($student['etablissement_origine']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['ref_dossier'])): ?>
                        <div class="detail">
                            <i class="fas fa-file-alt"></i>
                            <strong>N° dossier:</strong>
                            <code style="color:var(--orange);font-family:monospace;"><?php echo htmlspecialchars($student['ref_dossier']); ?></code>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['niveau'])): ?>
                        <div class="detail">
                            <i class="fas fa-layer-group"></i>
                            <strong>Niveau:</strong>
                            <span><?php echo htmlspecialchars($student['niveau']); ?>
                                <?php if (!empty($student['regime'])): ?> — <?php echo htmlspecialchars($student['regime']); ?><?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['specialite'])): ?>
                        <div class="detail">
                            <i class="fas fa-book"></i>
                            <strong>Spécialité:</strong>
                            <span style="color:var(--accent-color);font-weight:bold;"><?php echo htmlspecialchars($student['specialite']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['exp_pro'])): ?>
                        <div class="detail">
                            <i class="fas fa-briefcase"></i>
                            <strong>Expérience:</strong>
                            <span><?php echo htmlspecialchars($student['exp_pro']); ?>
                                <?php if (!empty($student['domaine_pro'])): ?> — <?php echo htmlspecialchars($student['domaine_pro']); ?><?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['ville'])): ?>
                        <div class="detail">
                            <i class="fas fa-city"></i>
                            <strong>Ville:</strong>
                            <span><?php echo htmlspecialchars($student['ville']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($student['mode_paiement'])): ?>
                        <div class="detail">
                            <i class="fas fa-credit-card"></i>
                            <strong>Paiement:</strong>
                            <?php
                            $payIcons = ['airtel'=>'📱 Airtel Money','moov'=>'📱 Moov Money','card'=>'💳 Carte bancaire','virement'=>'🏦 Virement'];
                            $pm = $student['mode_paiement'];
                            echo '<span style="color:var(--success-color);font-weight:bold;">' . htmlspecialchars($payIcons[$pm] ?? ucfirst($pm)) . '</span>';
                            ?>
                        </div>
                        <?php endif; ?>

                        <?php
                        // ── Documents ─────────────────────────────────────────
                        // La BDD stocke : uploads/documents/fichier.pdf
                        // Depuis pages/ le lien = '../' + chemin BDD
                        $docs = [
                            'cv_path'        => ['CV',               'fa-file-pdf'],
                            'diplome_path'   => ['Diplôme',          'fa-award'],
                            'cni_path'       => ['CNI / Passeport',  'fa-id-card'],
                            'lettre_path'    => ['Lettre de motiv.', 'fa-pen-fancy'],
                            'preuve_paiement'=> ['Preuve paiement',  'fa-receipt'],
                        ];
                        $hasDoc = false;
                        foreach ($docs as $key => $info) {
                            if (!empty($student[$key])) { $hasDoc = true; break; }
                        }
                        if ($hasDoc): ?>
                        <div style="margin-top:10px;padding:10px;background:rgba(3,155,229,0.08);border-radius:6px;border-left:3px solid var(--accent-color);">
                            <div style="font-size:12px;font-weight:bold;color:var(--accent-color);margin-bottom:8px;">
                                <i class="fas fa-folder-open"></i> Documents soumis
                            </div>
                            <?php foreach ($docs as $key => $info): ?>
                                <?php if (!empty($student[$key])): ?>
                                <div style="font-size:12px;margin:4px 0;display:flex;align-items:center;gap:6px;">
                                    <i class="fas <?php echo $info[1]; ?>" style="color:var(--accent-color);width:14px;"></i>
                                    <strong style="min-width:120px;"><?php echo $info[0]; ?> :</strong>
                                    <a href="../<?php echo htmlspecialchars($student[$key]); ?>"
                                       target="_blank"
                                       style="color:var(--orange);text-decoration:none;font-size:11px;"
                                       title="Ouvrir le document">
                                        <i class="fas fa-external-link-alt"></i>
                                        <?php echo basename($student[$key]); ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="detail">
                            <i class="fas fa-graduation-cap"></i>
                            <strong>Classe choisie:</strong>
                            <span>
                                <?php if (!empty($student['class_name'])): ?>
                                    <?php echo htmlspecialchars($student['class_name']); ?>
                                <?php else: ?>
                                    <span style="color: #999;">Aucune classe</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <div class="detail">
                            <i class="fas fa-calendar"></i>
                            <strong>Inscrit le:</strong>
                            <span><?php echo date('d/m/Y à H:i', strtotime($student['created_at'])); ?></span>
                        </div>
                    </div>
                    
                    <form method="POST" class="form-section" data-student-index="<?php echo $index; ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                        <input type="hidden" name="temp_id" value="<?php echo htmlspecialchars($student['id']); ?>">
                        
                        <div class="info-box">
                            <i class="fas fa-magic"></i>
                            <strong>ID auto-généré</strong> - Modifiable si nécessaire
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-graduation-cap"></i> 
                                Classe / Filière
                                <span class="auto-badge">MODIFIABLE</span>
                            </label>
                            <select name="new_class_id" 
                                    class="class-select class-selector" 
                                    data-original-class="<?php echo htmlspecialchars($student['class_id'] ?? ''); ?>"
                                    data-student-index="<?php echo $index; ?>">
                                <option value="">Aucune classe</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['id']); ?>"
                                            data-class-code="<?php echo htmlspecialchars($class['code']); ?>"
                                            data-class-name="<?php echo htmlspecialchars($class['name']); ?>"
                                            <?php echo ($class['id'] == $student['class_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="format-hint">
                                Changez la classe pour mettre à jour l'ID automatiquement
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-id-badge"></i> 
                                ID Étudiant
                                <span class="auto-badge">AUTO</span>
                            </label>
                            <input type="text" 
                                   name="new_id" 
                                   class="id-input id-field"
                                   value="<?php echo htmlspecialchars($student['generated_id']); ?>"
                                   required
                                   pattern="[A-Z0-9\-]{3,20}"
                                   title="Lettres majuscules, chiffres et tirets uniquement"
                                   data-student-index="<?php echo $index; ?>"
                                   oninput="this.value = this.value.toUpperCase()">
                            <div class="format-hint">
                                Format: ISMM-[CODE][ANNÉE]-[NUMÉRO] (Ex: ISMM-GI1-01, ISMM-MC3-01)
                            </div>
                        </div>
                        
                        <div class="buttons-container">
                            <button type="submit" 
                                    name="approve_student" 
                                    class="approve-btn"
                                    onclick="return confirm('⚠️ CONFIRMATION\n\nValidation de : <?php echo addslashes($student['name']); ?>\nID : ' + this.form.querySelector('.id-field').value + '\n\nL\'étudiant recevra un email avec ses identifiants.\n\nConfirmer ?')">
                                <i class="fas fa-check-circle"></i>
                                Valider et envoyer les identifiants
                            </button>
                            
                            <button type="button" 
                                    class="decline-btn"
                                    onclick="openDeclineModal('<?php echo htmlspecialchars($student['id']); ?>', '<?php echo addslashes($student['name']); ?>')">
                                <i class="fas fa-times-circle"></i>
                                Refuser l'inscription
                            </button>

                            <!-- ★ NOUVEAU : Bouton impression fiche ISMM -->
                            <a href="print_fiche.php?student_id=<?php echo urlencode($student['id']); ?>"
                               target="_blank"
                               class="print-fiche-btn"
                               title="Ouvrir et imprimer la fiche d'inscription ISMM">
                                <i class="fas fa-print"></i>
                                Imprimer la fiche
                            </a>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>Aucune inscription en attente</h3>
                <p>Toutes les inscriptions ont été traitées !</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="back-link">
        <a href="user_management.php">
            <i class="fas fa-arrow-left"></i> Retour à la gestion des utilisateurs
        </a>
    </div>
</main>

<!-- Modal pour l'image agrandie -->
<div id="imageModal" class="modal">
    <span class="close-modal" onclick="closeModal()">&times;</span>
    <img class="modal-content" id="modalImage">
    <div class="modal-caption" id="modalCaption"></div>
    <div class="modal-controls">
        <button class="modal-btn" onclick="rotateImage(-90)">
            <i class="fas fa-undo"></i> Rotation gauche
        </button>
        <button class="modal-btn" onclick="rotateImage(90)">
            <i class="fas fa-redo"></i> Rotation droite
        </button>
        <button class="modal-btn" onclick="resetImage()">
            <i class="fas fa-sync"></i> Réinitialiser
        </button>
    </div>
</div>

<!-- Modal pour le refus -->
<div id="declineModal" class="decline-modal">
    <div class="decline-modal-content">
        <div class="decline-modal-header">
            <i class="fas fa-exclamation-triangle fa-2x"></i>
            <h3>Refuser l'inscription</h3>
        </div>
        
        <form id="declineForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
            <input type="hidden" name="temp_id" id="declineStudentId">
            
            <div class="form-group">
                <label>
                    <i class="fas fa-comment"></i> 
                    Raison du refus (optionnel)
                </label>
                <textarea name="decline_reason" 
                          class="reason-textarea" 
                          placeholder="Expliquez brièvement la raison du refus (sera envoyé par email à l'étudiant)..."
                          rows="4"></textarea>
                <div class="format-hint">
                    Cette raison sera incluse dans l'email de refus envoyé à l'étudiant.
                </div>
            </div>
            
            <div class="decline-modal-buttons">
                <button type="button" class="cancel-decline-btn" onclick="closeDeclineModal()">
                    <i class="fas fa-arrow-left"></i> Annuler
                </button>
                <button type="submit" name="decline_student" class="confirm-decline-btn" onclick="return confirmDecline()">
                    <i class="fas fa-times-circle"></i> Confirmer le refus
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Données des classes pour JavaScript
const classesData = <?php echo json_encode($classes); ?>;
let currentRotation = 0;
let currentDeclineStudent = null;

// Fonction pour ouvrir le modal de refus
function openDeclineModal(studentId, studentName) {
    const modal = document.getElementById('declineModal');
    const studentIdField = document.getElementById('declineStudentId');
    
    studentIdField.value = studentId;
    currentDeclineStudent = studentName;
    
    modal.style.display = 'block';
}

// Fonction pour fermer le modal de refus
function closeDeclineModal() {
    document.getElementById('declineModal').style.display = 'none';
    document.getElementById('declineForm').reset();
    currentDeclineStudent = null;
}

// Fonction de confirmation pour le refus
function confirmDecline() {
    return confirm(`⚠️ CONFIRMATION DU REFUS\n\nÊtes-vous sûr de vouloir refuser l'inscription de : ${currentDeclineStudent} ?\n\nCette action est irréversible :\n• Le compte sera supprimé\n• L'avatar sera supprimé\n• Un email de refus sera envoyé\n\nConfirmer le refus ?`);
}

// Fonction pour ouvrir le modal d'image
function openModal(imageSrc, studentName) {
    const modal = document.getElementById('imageModal');
    const modalImg = document.getElementById('modalImage');
    const caption = document.getElementById('modalCaption');
    
    modal.style.display = 'block';
    modalImg.src = imageSrc;
    caption.innerHTML = `Photo de profil de <strong>${studentName}</strong>`;
    currentRotation = 0;
    modalImg.style.transform = 'translateY(-50%) rotate(0deg)';
}

// Fonction pour fermer le modal d'image
function closeModal() {
    document.getElementById('imageModal').style.display = 'none';
}

// Fonction pour faire pivoter l'image
function rotateImage(degrees) {
    const modalImg = document.getElementById('modalImage');
    currentRotation += degrees;
    modalImg.style.transform = `translateY(-50%) rotate(${currentRotation}deg)`;
}

// Fonction pour réinitialiser l'image
function resetImage() {
    const modalImg = document.getElementById('modalImage');
    currentRotation = 0;
    modalImg.style.transform = 'translateY(-50%) rotate(0deg)';
}

// Fermer les modals en cliquant en dehors
document.getElementById('imageModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

document.getElementById('declineModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeclineModal();
    }
});

// Fermer les modals avec la touche Échap
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
        closeDeclineModal();
    }
});

// Fonction pour extraire l'année depuis le nom de la classe
function extractYearFromClassName(className) {
    const match = className.match(/(\d)[èe]+ (?:année|Année|ANNÉE)/i);
    if (match) {
        return match[1];
    }
    
    const matchShort = className.match(/(\d)[èe]/i);
    if (matchShort) {
        return matchShort[1];
    }
    
    const matchStart = className.match(/^(\d)/);
    if (matchStart) {
        return matchStart[1];
    }
    
    return '1';
}

// Fonction pour compter les étudiants dans une classe
async function getStudentCountInClass(classId) {
    if (!classId) return 0;
    
    try {
        const response = await fetch(`get_student_count.php?class_id=${encodeURIComponent(classId)}`);
        const data = await response.json();
        return data.count || 0;
    } catch (error) {
        console.error('Erreur lors du comptage:', error);
        return 0;
    }
}

// Fonction pour générer un ID basé sur la classe
async function generateStudentId(classId, className) {
    if (!classId || classId === '') {
        const count = await getStudentCountInClass(null);
        return `ISMM-STU-${String(count + 1).padStart(2, '0')}`;
    }
    
    const classInfo = classesData.find(c => c.id === classId);
    if (!classInfo) {
        return `ISMM-STU-${String(Math.floor(Math.random() * 99) + 1).padStart(2, '0')}`;
    }
    
    const classCode = classInfo.code.toUpperCase();
    
    let year = '';
    if (!/\d+$/.test(classCode)) {
        year = extractYearFromClassName(className || classInfo.name);
    }
    
    const count = await getStudentCountInClass(classId);
    
    return `EISMM-${classCode}${year}-${String(count + 1).padStart(2, '0')}`;
}

// Écouter les changements de classe et régénérer l'ID automatiquement
document.querySelectorAll('.class-selector').forEach(function(select) {
    select.addEventListener('change', async function() {
        const studentIndex = this.getAttribute('data-student-index');
        const idField = document.querySelector(`.id-field[data-student-index="${studentIndex}"]`);
        const selectedOption = this.options[this.selectedIndex];
        const newClassId = this.value;
        const className = selectedOption.getAttribute('data-class-name');
        
        const originalValue = idField.value;
        idField.value = 'Génération...';
        idField.disabled = true;
        
        try {
            const newId = await generateStudentId(newClassId || null, className);
            idField.value = newId;
            
            idField.style.borderColor = '#2ecc71';
            idField.style.background = 'rgba(46, 204, 113, 0.1)';
            
            setTimeout(() => {
                idField.style.borderColor = 'rgba(255, 255, 255, 0.1)';
                idField.style.background = 'rgba(255, 255, 255, 0.1)';
            }, 1000);
        } catch (error) {
            console.error('Erreur génération ID:', error);
            idField.value = originalValue;
            alert('⚠️ Erreur lors de la génération de l\'ID. Veuillez réessayer.');
        } finally {
            idField.disabled = false;
        }
    });
});

// Validation en temps réel de l'ID
document.querySelectorAll('.id-input').forEach(function(input) {
    input.addEventListener('input', function() {
        const value = this.value;
        const pattern = /^[A-Z0-9\-]{3,20}$/;
        
        if (value.length === 0) {
            this.style.borderColor = 'var(--border-color)';
        } else if (pattern.test(value)) {
            this.style.borderColor = 'var(--success-color)';
        } else {
            this.style.borderColor = 'var(--error-color)';
        }
    });
});

// Auto-fermeture des messages après 5 secondes
setTimeout(function() {
    const messages = document.querySelectorAll('.message');
    messages.forEach(function(message) {
        message.style.transition = 'opacity 0.5s ease';
        message.style.opacity = '0';
        setTimeout(function() {
            message.style.display = 'none';
        }, 500);
    });
}, 5000);

// Log pour debug
console.log('📊 Données des classes chargées:', classesData);
console.log('✅ Système de génération automatique des IDs activé');
console.log('🖼️ Modal d\'affichage des avatars intégré');
console.log('❌ Fonction de refus des inscriptions intégrée');
console.log('🖨️ Bouton impression fiche ISMM intégré');
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>

<?php
$conn->close();
?>