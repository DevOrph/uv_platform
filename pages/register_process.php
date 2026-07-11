<?php
// register_process.php - Version avec notification SendGrid aux admins
session_start();
require_once '../includes/db_connect_public.php';
require_once '../vendor/autoload.php'; // SendGrid

use SendGrid\Mail\Mail;

// Activer l'affichage des erreurs
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Définir le fuseau horaire
date_default_timezone_set('Africa/Libreville');

// Forcer UTF-8
$conn->set_charset('utf8mb4');
$conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

// Configuration SendGrid
define('SENDGRID_FROM_EMAIL', 'contact@uvcoding.com');
define('SENDGRID_FROM_NAME', 'Université Virtuelle');

// ID du template SendGrid pour notification admin (à créer dans SendGrid)
define('New_registration_admin', 'd-b6b105f7263645b6ab437513d0cc1e4e'); // REMPLACER PAR VOTRE TEMPLATE ID

// ✅ CORRECTION #1: Vérification CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: register.php");
    exit();
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['registration_errors'] = ['Token de sécurité invalide ou expiré. Veuillez réessayer.'];
    header("Location: register.php");
    exit();
}

// Tableau pour stocker les erreurs
$errors = [];

// ✅ CORRECTION #2: Validation et nettoyage des données
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Récupération et validation des données du formulaire
$name = sanitize_input($_POST['name'] ?? '');
$email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$phone = sanitize_input($_POST['phone'] ?? '');
$birth_date = sanitize_input($_POST['birth_date'] ?? '');
$address = sanitize_input($_POST['address'] ?? '');
$class_id = sanitize_input($_POST['class_id'] ?? '');
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

// Validation du nom
if (empty($name)) {
    $errors[] = "Le nom complet est obligatoire.";
} elseif (strlen($name) < 2 || strlen($name) > 100) {
    $errors[] = "Le nom doit contenir entre 2 et 100 caractères.";
} elseif (!preg_match("/^[a-zA-Z0-9À-ÿ\s\-'\.]{2,100}$/u", $name)) {
    $errors[] = "Le nom contient des caractères non autorisés.";
}

// Validation de l'email
if (empty($email)) {
    $errors[] = "L'adresse email est obligatoire.";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Format d'email invalide.";
} else {
    // Vérifier si l'email existe déjà
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $errors[] = "Cet email est déjà utilisé.";
    }
    $stmt->close();
}

// Validation du téléphone
if (empty($phone)) {
    $errors[] = "Le numéro de téléphone est obligatoire.";
} elseif (!preg_match("/^[\+]?[0-9\s\-\(\)]{8,20}$/", $phone)) {
    $errors[] = "Format de téléphone invalide.";
}

// Validation de la classe
if (empty($class_id)) {
    $errors[] = "Vous devez sélectionner une classe.";
} else {
    // Vérifier que la classe existe
    $stmt = $conn->prepare("SELECT id FROM classes WHERE id = ?");
    $stmt->bind_param("s", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $errors[] = "La classe sélectionnée n'existe pas.";
    }
    $stmt->close();
}

// Validation du mot de passe
if (empty($password)) {
    $errors[] = "Le mot de passe est obligatoire.";
} elseif (strlen($password) < 6) {
    $errors[] = "Le mot de passe doit contenir au moins 6 caractères.";
}

// Validation de la confirmation du mot de passe
if ($password !== $confirm_password) {
    $errors[] = "Les mots de passe ne correspondent pas.";
}

// Validation de la date de naissance (optionnelle)
if (!empty($birth_date)) {
    $date = DateTime::createFromFormat('Y-m-d', $birth_date);
    if (!$date || $date->format('Y-m-d') !== $birth_date) {
        $errors[] = "Format de date de naissance invalide.";
    }
}

// ✅ CORRECTION #5: Traitement sécurisé de l'avatar
$avatar_filename = null;
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['avatar'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    // Vérifier le type MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime_type, $allowed_types)) {
        $errors[] = "Format d'image non autorisé. Utilisez JPG, PNG, GIF ou WEBP.";
    } elseif ($file['size'] > $max_size) {
        $errors[] = "La taille de l'image ne doit pas dépasser 5MB.";
    } else {
        // Générer un nom de fichier sécurisé
        $extension = match($mime_type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg'
        };
        
        $avatar_filename = uniqid('avatar_', true) . '.' . $extension;
        $upload_dir = '../uploads/avatars/';
        
        // Créer le dossier s'il n'existe pas
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $upload_path = $upload_dir . $avatar_filename;
        
        if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
            $errors[] = "Erreur lors du téléchargement de la photo.";
            $avatar_filename = null;
        }
    }
}

// S'il y a des erreurs, retourner à la page d'inscription
if (!empty($errors)) {
    $_SESSION['registration_errors'] = $errors;
    $_SESSION['form_data'] = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'birth_date' => $birth_date,
        'address' => $address,
        'class_id' => $class_id
    ];
    
    // Supprimer l'avatar si upload mais erreurs ailleurs
    if ($avatar_filename && file_exists($upload_dir . $avatar_filename)) {
        unlink($upload_dir . $avatar_filename);
    }
    
    header("Location: register.php");
    exit();
}

// ✅ CORRECTION #6: Hachage sécurisé du mot de passe
$hashed_password = password_hash($password, PASSWORD_ARGON2ID);

// Générer un ID temporaire unique
$temp_id = 'TEMP-' . strtoupper(uniqid());

// Insertion dans la base de données
$stmt = $conn->prepare("
    INSERT INTO users (id, name, email, phone, birth_date, address, class_id, password, role, blocked, avatar, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'student', 1, ?, NOW())
");

$stmt->bind_param(
    "sssssssss",
    $temp_id,
    $name,
    $email,
    $phone,
    $birth_date,
    $address,
    $class_id,
    $hashed_password,
    $avatar_filename
);

if ($stmt->execute()) {
    $stmt->close();
    
    // 🔔 NOUVEAU: Envoyer une notification aux administrateurs
    $notification_result = send_admin_notifications($conn, $name, $email, $phone, $class_id, $avatar_filename);
    
    // Message de succès
    $_SESSION['registration_success'] = true;
    $_SESSION['student_name'] = $name;
    $_SESSION['notification_sent'] = $notification_result['success'];
    $_SESSION['notification_count'] = $notification_result['count'];
    
    header("Location: registration_success.php");
    exit();
} else {
    // Supprimer l'avatar en cas d'erreur
    if ($avatar_filename && file_exists($upload_dir . $avatar_filename)) {
        unlink($upload_dir . $avatar_filename);
    }
    
    $errors[] = "Erreur lors de l'inscription : " . $conn->error;
    $_SESSION['registration_errors'] = $errors;
    $_SESSION['form_data'] = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'birth_date' => $birth_date,
        'address' => $address,
        'class_id' => $class_id
    ];
    
    $stmt->close();
    header("Location: register.php");
    exit();
}

// 🆕 FONCTION: Envoyer des notifications à tous les administrateurs
function send_admin_notifications($conn, $student_name, $student_email, $student_phone, $class_id, $avatar_filename) {
    try {
        // Récupérer tous les administrateurs actifs
        $stmt = $conn->prepare("SELECT id, name, email FROM users WHERE role = 'admin' AND blocked = 0");
        $stmt->execute();
        $result = $stmt->get_result();
        $admins = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($admins)) {
            error_log("⚠️ Aucun administrateur trouvé pour envoyer les notifications");
            return ['success' => false, 'count' => 0, 'message' => 'Aucun administrateur disponible'];
        }
        
        // Récupérer le nom de la classe
        $class_name = "Non spécifiée";
        if (!empty($class_id)) {
            $stmt = $conn->prepare("SELECT name FROM classes WHERE id = ?");
            $stmt->bind_param("s", $class_id);
            $stmt->execute();
            $class_result = $stmt->get_result();
            if ($class_row = $class_result->fetch_assoc()) {
                $class_name = $class_row['name'];
            }
            $stmt->close();
        }
        
        // URL de la page de validation
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $validation_url = $protocol . "://" . $host . "/admin/pending_registrations.php";
        
        // Informations sur l'avatar
        $has_avatar = !empty($avatar_filename);
        $avatar_status = $has_avatar 
            ? "✅ Photo de profil fournie (accélère la validation)" 
            : "⚠️ Aucune photo de profil";
        
        // Compteur de succès
        $success_count = 0;
        $total_admins = count($admins);
        
        // Envoyer un email à chaque administrateur
        foreach ($admins as $admin) {
            $email_result = send_email_with_sendgrid(
                $admin['email'],
                $admin['name'],
                New_registration_admin,
                [
                    'admin_name' => $admin['name'],
                    'student_name' => $student_name,
                    'student_email' => $student_email,
                    'student_phone' => $student_phone,
                    'class_name' => $class_name,
                    'avatar_status' => $avatar_status,
                    'has_avatar' => $has_avatar,
                    'registration_date' => date('d/m/Y à H:i'),
                    'validation_url' => $validation_url,
                    'current_year' => date('Y')
                ]
            );
            
            if ($email_result['success']) {
                $success_count++;
                error_log("✅ Notification envoyée à {$admin['name']} ({$admin['email']})");
            } else {
                error_log("❌ Échec notification à {$admin['name']}: {$email_result['message']}");
            }
        }
        
        return [
            'success' => $success_count > 0,
            'count' => $success_count,
            'total' => $total_admins,
            'message' => "$success_count/$total_admins notifications envoyées"
        ];
        
    } catch (Exception $e) {
        error_log("❌ Erreur lors de l'envoi des notifications admin: " . $e->getMessage());
        return ['success' => false, 'count' => 0, 'message' => $e->getMessage()];
    }
}

// 🆕 FONCTION: Envoyer un email via SendGrid
function send_email_with_sendgrid($to_email, $to_name, $template_id, $dynamic_data) {
    try {
        $email = new Mail();
        $email->setFrom(SENDGRID_FROM_EMAIL, SENDGRID_FROM_NAME);
        $email->addTo($to_email, $to_name);
        $email->setTemplateId($template_id);
        $email->addDynamicTemplateDatas($dynamic_data);
        $email->setReplyTo(SENDGRID_FROM_EMAIL, SENDGRID_FROM_NAME);
        $email->addCategory('admin_notification');
        $email->addCategory('new_registration');
        
        $sg_res = $GLOBALS['conn']->query("SELECT valeur FROM parametres WHERE cle='sendgrid_api_key' LIMIT 1");
        $sg_key = $sg_res ? trim($sg_res->fetch_assoc()['valeur'] ?? '') : '';
        if (empty($sg_key)) { return ['success' => false, 'message' => 'Clé API SendGrid non configurée']; }
        $sendgrid = new \SendGrid($sg_key);
        $response = $sendgrid->send($email);

        if ($response->statusCode() == 202) {
            return ['success' => true, 'message' => 'Email envoyé avec succès'];
        } else {
            return [
                'success' => false,
                'message' => 'Code erreur SendGrid: ' . $response->statusCode()
            ];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

$conn->close();
?>
