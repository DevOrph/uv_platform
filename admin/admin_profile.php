<?php
session_start();

// Désactiver l'affichage des warnings pour une interface propre
error_reporting(E_ERROR | E_PARSE);

// Vérifier si l'utilisateur est connecté et est un administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

$user_id = $_SESSION['user_id'];

// Vérification de l'existence du fichier de connexion
if (!file_exists('../includes/db_connect.php')) {
    die("Erreur: Fichier de connexion à la base de données introuvable.");
}

require_once '../includes/db_connect.php';
require_once '../includes/super_admin.php';

// Privilèges spéciaux pour les super administrateurs (sans changer leur rôle)
$is_privileged_admin = is_super_admin($conn, $user_id);

// Vérification de la connexion à la base de données
if (!isset($conn) || $conn->connect_error) {
    die("Erreur de connexion à la base de données: " . (isset($conn) ? $conn->connect_error : "Connexion non établie"));
}

// Récupérer les informations de l'administrateur
$sql = "SELECT * FROM users WHERE id = ? AND role = 'admin'";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Erreur de préparation de la requête: " . $conn->error);
}
$stmt->bind_param("s", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: ../pages/login.html");
    exit();
}

$admin = $result->fetch_assoc();

// Traitement de la mise à jour du profil
$success_message = "";
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $new_name = trim($_POST['name'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $new_phone = trim($_POST['phone'] ?? '');
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    // Gestion de l'upload de photo de profil
    $profile_image_path = $admin['avatar'] ?? null;
    
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $target_dir = "../uploads/profile_images/";
            
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $new_filename = uniqid('profile_') . '.' . $ext;
            $target_path = $target_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_path)) {
                $profile_image_path = 'uploads/profile_images/' . $new_filename;
                
                // Enregistrer l'action dans les logs
                $action_type = "UPDATE_PROFILE_PHOTO";
                $description = "Photo de profil mise à jour";
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
                
                $log_sql = "INSERT INTO admin_logs (admin_id, action_type, description, ip_address, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
                $log_stmt = $conn->prepare($log_sql);
                if ($log_stmt) {
                    $log_stmt->bind_param("ssss", $user_id, $action_type, $description, $ip_address);
                    $log_stmt->execute();
                }
            } else {
                $error_message = "Erreur lors du téléchargement de l'image.";
            }
        } else {
            $error_message = "Format d'image non pris en charge. Utilisez JPG, PNG ou GIF.";
        }
    }
    
    // Vérifier si l'email est déjà utilisé par un autre utilisateur
    $check_email = "SELECT id FROM users WHERE email = ? AND id != ?";
    $stmt = $conn->prepare($check_email);
    $stmt->bind_param("ss", $new_email, $user_id);
    $stmt->execute();
    $email_result = $stmt->get_result();
    
    if ($email_result->num_rows > 0) {
        $error_message = "Cette adresse email est déjà utilisée par un autre utilisateur.";
    } else {
        // Mise à jour du profil avec la photo
        $update_sql = "UPDATE users SET name = ?, email = ?, phone = ?";
        $params = [$new_name, $new_email, $new_phone];
        $types = "sss";
        
        if ($profile_image_path !== null) {
            $update_sql .= ", avatar = ?";
            $params[] = $profile_image_path;
            $types .= "s";
        }
        
        $update_sql .= " WHERE id = ?";
        $params[] = $user_id;
        $types .= "s";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $success_message = "Profil mis à jour avec succès.";
            
            $_SESSION['name'] = $new_name;
            $_SESSION['email'] = $new_email;
            if ($profile_image_path !== null) {
                $_SESSION['avatar'] = $profile_image_path;
            }
            
            // Rafraîchir les informations de l'admin
            $sql = "SELECT * FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();
            
            // Enregistrer l'action dans les logs
            $action_type = "UPDATE_PROFILE";
            $description = "Informations de profil mises à jour";
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            
            $log_sql = "INSERT INTO admin_logs (admin_id, action_type, description, ip_address, created_at) 
                        VALUES (?, ?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            if ($log_stmt) {
                $log_stmt->bind_param("ssss", $user_id, $action_type, $description, $ip_address);
                $log_stmt->execute();
            }
        } else {
            $error_message = "Erreur lors de la mise à jour du profil: " . ($conn->error ?? '');
        }
        
        // Mise à jour du mot de passe si demandé
        if (!empty($current_password) && !empty($new_password)) {
            if (strlen($new_password) < 8) {
                $error_message = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
            } else if ($new_password !== $confirm_password) {
                $error_message = "Les nouveaux mots de passe ne correspondent pas.";
            } else {
                $check_password = "SELECT password FROM users WHERE id = ?";
                $stmt = $conn->prepare($check_password);
                $stmt->bind_param("s", $user_id);
                $stmt->execute();
                $password_result = $stmt->get_result();
                $user_data = $password_result->fetch_assoc();
                
                if (password_verify($current_password, $user_data['password'])) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    $update_password = "UPDATE users SET password = ? WHERE id = ?";
                    $stmt = $conn->prepare($update_password);
                    $stmt->bind_param("ss", $hashed_password, $user_id);
                    
                    if ($stmt->execute()) {
                        $success_message = "Profil et mot de passe mis à jour avec succès.";
                        
                        // Enregistrer l'action dans les logs
                        $action_type = "UPDATE_PASSWORD";
                        $description = "Mot de passe modifié";
                        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
                        
                        $log_sql = "INSERT INTO admin_logs (admin_id, action_type, description, ip_address, created_at) 
                                    VALUES (?, ?, ?, ?, NOW())";
                        $log_stmt = $conn->prepare($log_sql);
                        if ($log_stmt) {
                            $log_stmt->bind_param("ssss", $user_id, $action_type, $description, $ip_address);
                            $log_stmt->execute();
                        }
                    } else {
                        $error_message = "Erreur lors de la mise à jour du mot de passe: " . ($conn->error ?? '');
                    }
                } else {
                    $error_message = "Le mot de passe actuel est incorrect.";
                }
            }
        }
    }
}

// PRIVILÈGES SPÉCIAUX POUR ADMIN01 : Récupérer les actions des autres admins
$other_admins_logs_result = null;
if ($is_privileged_admin) {
    $sql_other_admins_logs = "
        SELECT l.*, u.name AS admin_name, u.avatar AS admin_avatar, u.role
        FROM admin_logs l
        LEFT JOIN users u ON l.admin_id = u.id
        WHERE l.admin_id != ? 
          AND u.role = 'admin'
        ORDER BY l.created_at DESC
        LIMIT 50
    ";
    $stmt_logs = $conn->prepare($sql_other_admins_logs);
    if ($stmt_logs) {
        $stmt_logs->bind_param("s", $user_id); // exclut ADMIN01 lui-même
        $stmt_logs->execute();
        $other_admins_logs_result = $stmt_logs->get_result();
    } else {
        error_log("Erreur préparation logs autres admins: " . $conn->error);
    }
}


// Récupérer les statistiques des activités de l'admin connecté
$sql_actions = "SELECT action_type, COUNT(*) as count 
                FROM admin_logs 
                WHERE admin_id = ? 
                GROUP BY action_type 
                ORDER BY count DESC 
                LIMIT 5";
$stmt = $conn->prepare($sql_actions);
$actions_result = null;
if ($stmt) {
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $actions_result = $stmt->get_result();
}

// Récupérer le nombre de connexions
$sql_logins_count = "SELECT COUNT(*) as login_count FROM user_logins WHERE user_id = ?";
$stmt = $conn->prepare($sql_logins_count);
$logins_count = 0;
if ($stmt) {
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $logins_count_result = $stmt->get_result();
    $logins_count = $logins_count_result->fetch_assoc()['login_count'] ?? 0;
}

// Récupérer les dernières connexions selon les privilèges
if ($is_privileged_admin) {
    // ADMIN01 voit toutes les connexions avec noms et rôles
    $sql_logins = "SELECT ul.login_time, ul.ip_address, ul.user_agent, u.name, u.role, u.avatar
                  FROM user_logins ul 
                  LEFT JOIN users u ON ul.user_id = u.id 
                  WHERE ul.success = 1
                  ORDER BY ul.login_time DESC 
                  LIMIT 20";
    $logins_result = $conn->query($sql_logins);
} else {
    // Admin normal voit seulement ses propres connexions
    $sql_logins = "SELECT login_time, ip_address, user_agent
                  FROM user_logins 
                  WHERE user_id = ? AND success = 1
                  ORDER BY login_time DESC 
                  LIMIT 5";
    $stmt = $conn->prepare($sql_logins);
    $logins_result = null;
    if ($stmt) {
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $logins_result = $stmt->get_result();
    }
}

// Récupérer des statistiques supplémentaires
$stats = [
    'total_users' => 0,
    'total_students' => 0,
    'total_teachers' => 0,
    'total_admins' => 0,
    'total_courses' => 0,
    'total_announcements' => 0,
    'active_users_today' => 0,
    'recent_logins' => 0,
];

// Statistiques de base (visibles pour tous les admins)
$queries = [
    'total_users' => "SELECT COUNT(*) as count FROM users",
    'total_students' => "SELECT COUNT(*) as count FROM users WHERE role = 'student'",
    'total_teachers' => "SELECT COUNT(*) as count FROM users WHERE role = 'teacher'",
    'total_admins' => "SELECT COUNT(*) as count FROM users WHERE role = 'admin'",
    'total_courses' => "SELECT COUNT(*) as count FROM courses",
    'total_announcements' => "SELECT COUNT(*) as count FROM announcements"
];

foreach ($queries as $key => $query) {
    $result = $conn->query($query);
    if ($result) {
        $stats[$key] = $result->fetch_assoc()['count'] ?? 0;
    }
}

// Statistiques avancées pour ADMIN01
if ($is_privileged_admin) {
    $advanced_queries = [
        'active_users_today' => "SELECT COUNT(DISTINCT user_id) as count FROM user_logins WHERE DATE(login_time) = CURDATE()",
        'recent_logins' => "SELECT COUNT(*) as count FROM user_logins WHERE login_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    ];
    
    foreach ($advanced_queries as $key => $query) {
        $result = $conn->query($query);
        if ($result) {
            $stats[$key] = $result->fetch_assoc()['count'] ?? 0;
        }
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <title>Profil Administrateur - Université Virtuelle</title>
    <style>
        /* Variables globales */
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #4CAF50;
            --error-color: #f44336;
            --warning-color: #ff9800;
            --info-color: #2196F3;
            --card-bg: rgba(255, 255, 255, 0.05);
            --hover-color: rgba(3, 155, 229, 0.1);
            --admin01-color: #e91e63; /* Couleur spéciale pour ADMIN01 */
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

        /* Header Styles */
        .page-header {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
            position: relative;
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

        /* Styles spéciaux pour ADMIN01 */
        .admin01-special {
            border-left: 4px solid var(--admin01-color);
            background: linear-gradient(135deg, rgba(233, 30, 99, 0.1), rgba(233, 30, 99, 0.05));
        }

        .admin01-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background: linear-gradient(135deg, var(--admin01-color), #c2185b);
            color: white;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            box-shadow: 0 3px 10px rgba(233, 30, 99, 0.3);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .access-level {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 20px;
            background: linear-gradient(135deg, rgba(233, 30, 99, 0.2), rgba(233, 30, 99, 0.1));
            color: var(--admin01-color);
            border: 2px solid rgba(233, 30, 99, 0.3);
        }

        /* Messages de feedback */
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

        /* Cartes */
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .card h2 {
            font-size: 1.5rem;
            margin-bottom: 20px;
            color: var(--accent-color);
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }

        /* Section Actions des Autres Admins */
        .other-admins-section {
            margin-top: 30px;
        }

        .admin-action-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            background: rgba(255, 255, 255, 0.03);
            border-left: 3px solid var(--accent-color);
            transition: all 0.3s ease;
        }

        .admin-action-item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateX(5px);
        }

        .admin-avatar-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent-color);
            margin-right: 15px;
        }

        .action-details {
            flex: 1;
        }

        .action-admin-name {
            font-weight: 600;
            color: var(--accent-color);
            margin-bottom: 5px;
        }

        .action-description {
            opacity: 0.8;
            font-size: 0.9rem;
            margin-bottom: 3px;
        }

        .action-timestamp {
            font-size: 0.8rem;
            opacity: 0.6;
        }

        .action-type-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 10px;
        }

        .action-create { background: rgba(76, 175, 80, 0.2); color: #4CAF50; }
        .action-update { background: rgba(33, 150, 243, 0.2); color: #2196F3; }
        .action-delete { background: rgba(244, 67, 54, 0.2); color: #F44336; }
        .action-login { background: rgba(255, 152, 0, 0.2); color: #FF9800; }

        /* Profile Section */
        .profile-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .profile-image-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 20px;
        }
        
        .profile-image {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent-color);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            background-color: rgba(255, 255, 255, 0.1);
            cursor: pointer;
        }
        
        .profile-image-overlay {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--accent-color);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid white;
        }
        
        .profile-image-overlay:hover {
            background: #0288d1;
            transform: scale(1.1);
        }
        
        .profile-image-upload {
            display: none;
        }

        /* Formulaire de profil */
        .profile-form .form-group {
            margin-bottom: 15px;
        }

        .profile-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .profile-form input[type="text"],
        .profile-form input[type="email"],
        .profile-form input[type="password"],
        .profile-form input[type="tel"],
        .profile-form input[type="file"] {
            width: 100%;
            padding: 12px 15px;
            border-radius: 10px;
            border: 2px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .profile-form input:focus {
            outline: none;
            border-color: var(--accent-color);
            background: rgba(255, 255, 255, 0.15);
        }

        .profile-form input::placeholder {
            color: rgba(255, 255, 255, 0.6);
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
            margin-top: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(3, 155, 229, 0.4);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
        }

        .btn-secondary:hover {
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.4);
        }

        /* Stats */
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
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--accent-color);
            margin-bottom: 10px;
        }

        .stat-label {
            opacity: 0.8;
            font-size: 1rem;
        }

        /* Admin info */
        .admin-info p {
            margin: 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
        }

        .admin-info i {
            color: var(--accent-color);
            width: 20px;
            text-align: center;
        }

        /* Login Table */
        .login-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 10px;
            overflow: hidden;
        }

        .login-table th,
        .login-table td {
            text-align: left;
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .login-table th {
            background: rgba(0, 0, 0, 0.3);
            color: var(--accent-color);
            font-weight: 600;
        }

        .login-table tr:hover {
            background: var(--hover-color);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent-color);
        }

        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-student {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
            border: 1px solid #4CAF50;
        }

        .role-teacher {
            background: rgba(33, 150, 243, 0.2);
            color: #2196F3;
            border: 1px solid #2196F3;
        }

        .role-admin {
            background: rgba(255, 152, 0, 0.2);
            color: #FF9800;
            border: 1px solid #FF9800;
        }

        /* Sections cachées */
        .password-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px dashed var(--border-color);
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .feature-highlight {
            background: linear-gradient(135deg, rgba(3, 155, 229, 0.1), rgba(3, 155, 229, 0.05));
            border: 1px solid rgba(3, 155, 229, 0.2);
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
        }

        .feature-highlight h4 {
            color: var(--accent-color);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .privilege-list {
            list-style: none;
            padding: 0;
        }

        .privilege-list li {
            padding: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .privilege-list li:last-child {
            border-bottom: none;
        }

        .privilege-list i {
            color: var(--accent-color);
            width: 20px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideIn {
            from {
                transform: translateX(-100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
                /* Filtre par rôle */
        .role-filter-container {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }

        .role-filter-container label {
            font-weight: 600;
            color: var(--accent-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .role-filter-select {
            padding: 8px 15px;
            border-radius: 8px;
            border: 2px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .role-filter-select:focus {
            outline: none;
            border-color: var(--accent-color);
            background: rgba(255, 255, 255, 0.15);
        }

        .role-filter-select option {
            background: var(--primary-bg);
            color: var(--text-light);
        }

        .filter-results-count {
            margin-left: auto;
            font-size: 0.9rem;
            opacity: 0.8;
            color: var(--accent-color);
        }

        @media (max-width: 768px) {
            .profile-section {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .page-header h1 {
                font-size: 2rem;
            }

            .container {
                padding: 15px;
            }

            .admin01-badge {
                position: relative;
                top: auto;
                right: auto;
                margin-bottom: 15px;
                display: inline-block;
            }
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
        <div class="page-header <?php echo $is_privileged_admin ? 'admin01-special' : 'admin-only'; ?>">
            <?php if ($is_privileged_admin): ?>
                <div class="admin01-badge">
                    <i class="fas fa-star"></i> ADMIN Principal
                </div>
            <?php endif; ?>
            
            <h1>
                <i class="fas <?php echo $is_privileged_admin ? 'fa-star' : 'fa-user-shield'; ?>"></i> 
                <?php echo $is_privileged_admin ? 'Administrateur Principal' : 'Administrateur'; ?>
            </h1>
            <p><?php echo $is_privileged_admin ? 'Surveillance et gestion privilégiée de la plateforme' : 'Gestion des ressources pédagogiques'; ?></p>
            
            <?php if ($is_privileged_admin): ?>
                <div class="access-level">
                    <i class="fas fa-star"></i> Accès Administrateur Principal + Surveillance
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <?php if ($is_privileged_admin): ?>
            <!-- Section privilèges pour ADMIN01 -->
            <div class="feature-highlight">
                <h4><i class="fas fa-eye"></i> Privilèges Administrateur Principal (ADMIN01)</h4>
                <ul class="privilege-list">
                    <li><i class="fas fa-eye"></i> Surveillance des actions des autres administrateurs</li>
                    <li><i class="fas fa-chart-line"></i> Accès aux statistiques avancées</li>
                    <li><i class="fas fa-users"></i> Vue d'ensemble de toutes les connexions</li>
                    <li><i class="fas fa-graduation-cap"></i> Gestion des cours et programmes</li>
                    <li><i class="fas fa-shield-alt"></i> Supervision de l'activité administrative</li>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Statistiques adaptées selon le rôle -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_users']); ?></div>
                <div class="stat-label"><i class="fas fa-users"></i> Utilisateurs Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_students']); ?></div>
                <div class="stat-label"><i class="fas fa-user-graduate"></i> Étudiants</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_teachers']); ?></div>
                <div class="stat-label"><i class="fas fa-chalkboard-teacher"></i> Enseignants</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_courses']); ?></div>
                <div class="stat-label"><i class="fas fa-book"></i> Cours</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_admins']); ?></div>
                <div class="stat-label"><i class="fas fa-user-shield"></i> Administrateurs</div>
            </div>
            <?php if ($is_privileged_admin): ?>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['active_users_today']); ?></div>
                <div class="stat-label"><i class="fas fa-wifi"></i> Actifs Aujourd'hui</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['recent_logins']); ?></div>
                <div class="stat-label"><i class="fas fa-clock"></i> Connexions 24h</div>
            </div>
            <?php else: ?>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_announcements']); ?></div>
                <div class="stat-label"><i class="fas fa-bullhorn"></i> Annonces</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($logins_count); ?></div>
                <div class="stat-label"><i class="fas fa-sign-in-alt"></i> Mes Connexions</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- SECTION : Actions des Autres Admins (pour ADMIN01 uniquement) -->
        <?php if ($is_privileged_admin && $other_admins_logs_result): ?>
            <div class="card admin01-special other-admins-section">
                <h2>
                    <i class="fas fa-users-cog"></i>
                    Surveillance des Actions Administratives
                </h2>
                
                <div class="feature-highlight">
                    <h4><i class="fas fa-shield-alt"></i> Activité des Autres Administrateurs</h4>
                    <p>Surveillance en temps réel des actions effectuées par les autres administrateurs pour maintenir la sécurité et la conformité.</p>
                </div>
                
                <div style="max-height: 500px; overflow-y: auto;">
                    <?php if ($other_admins_logs_result->num_rows > 0): ?>
                        <?php while ($log = $other_admins_logs_result->fetch_assoc()): ?>
                            <div class="admin-action-item">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if (!empty($log['admin_avatar']) && $log['admin_avatar'] !== 'default_avatar.png'): ?>
                                        <img src="../<?php echo htmlspecialchars($log['admin_avatar']); ?>" alt="Avatar" class="admin-avatar-small">
                                    <?php else: ?>
                                        <div class="admin-avatar-small" style="background: var(--accent-color); display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-user" style="color: white;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="action-details">
                                    <div class="action-admin-name">
                                        <?php echo htmlspecialchars($log['admin_name'] ?? 'Admin Inconnu'); ?>
                                        <span class="role-badge role-admin">
                                            <i class="fas fa-user-shield"></i> Admin
                                        </span>
                                    </div>
                                    <div class="action-description">
                                        <?php echo htmlspecialchars($log['description']); ?>
                                    </div>
                                    <div class="action-timestamp">
                                        <i class="fas fa-clock"></i> <?php echo htmlspecialchars($log['created_at']); ?>
                                        <?php if (!empty($log['ip_address'])): ?>
                                            <i class="fas fa-map-marker-alt" style="margin-left: 15px;"></i> 
                                            <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px;">
                                                <?php echo htmlspecialchars($log['ip_address']); ?>
                                            </code>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="action-type-badge <?php 
                                    $action_type = strtolower($log['action_type']);
                                    if (strpos($action_type, 'create') !== false) echo 'action-create';
                                    elseif (strpos($action_type, 'update') !== false) echo 'action-update';
                                    elseif (strpos($action_type, 'delete') !== false) echo 'action-delete';
                                    elseif (strpos($action_type, 'login') !== false) echo 'action-login';
                                    else echo 'action-update';
                                ?>">
                                    <?php echo htmlspecialchars($log['action_type']); ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; opacity: 0.6;">
                            <i class="fas fa-info-circle" style="font-size: 3rem; margin-bottom: 15px;"></i><br>
                            Aucune action administrative détectée récemment
                        </div>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: rgba(233, 30, 99, 0.1); border-radius: 8px; border-left: 4px solid var(--admin01-color);">
                    <h5 style="color: var(--admin01-color); margin-bottom: 8px;"><i class="fas fa-exclamation-triangle"></i> Note de Surveillance</h5>
                    <p style="margin: 0; font-size: 0.9rem; opacity: 0.9;">
                        En tant qu'Administrateur Principal, vous avez accès à toutes les actions administratives. 
                        Signalez toute activité suspecte à l'équipe technique.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Section Profil -->
        <section class="profile-section">
            <div class="card">
                <h2><i class="fas fa-id-card"></i> Informations Personnelles</h2>
                
                <form class="profile-form" method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                    <div class="profile-image-container">
                        <?php if (!empty($admin['avatar']) && $admin['avatar'] !== 'default_avatar.png'): ?>
                            <img src="../<?php echo htmlspecialchars($admin['avatar']); ?>" alt="Photo de profil" class="profile-image" id="profileImage">
                        <?php else: ?>
                            <img src="../assets/images/profil.png" alt="Photo de profil par défaut" class="profile-image" id="profileImage">
                        <?php endif; ?>
                        
                        <div class="profile-image-overlay" id="profileImageOverlay">
                            <i class="fas fa-camera"></i>
                        </div>
                        
                        <input type="file" id="profile_image" name="profile_image" accept="image/*" class="profile-image-upload">
                    </div>
                    
                    <div class="form-group">
                        <label for="name"><i class="fas fa-user"></i> Nom complet</label>
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($admin['name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email"><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="phone"><i class="fas fa-phone"></i> Téléphone</label>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>">
                    </div>
                    
                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <button type="button" id="show-password-btn" class="btn btn-secondary">
                            <i class="fas fa-key"></i> Modifier le mot de passe
                        </button>
                        
                        <button type="submit" name="update_profile" class="btn">
                            <i class="fas fa-save"></i> Mettre à jour le profil
                        </button>
                    </div>
                    
                    <div class="password-section" id="password-section">
                        <div class="form-group">
                            <label for="current_password"><i class="fas fa-lock"></i> Mot de passe actuel</label>
                            <input type="password" id="current_password" name="current_password">
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password"><i class="fas fa-key"></i> Nouveau mot de passe</label>
                            <input type="password" id="new_password" name="new_password">
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password"><i class="fas fa-check"></i> Confirmer le nouveau mot de passe</label>
                            <input type="password" id="confirm_password" name="confirm_password">
                        </div>
                    </div>
                </form>
            </div>
            
            <div class="card">
                <h2><i class="fas fa-info-circle"></i> Informations Compte</h2>
                
                <div class="admin-info">
                    <p><i class="fas fa-user-shield"></i> <strong>Rôle :</strong> 
                        <?php if ($is_privileged_admin): ?>
                            <span style="color: var(--admin01-color);">Administrateur Principal</span>
                        <?php else: ?>
                            Administrateur
                        <?php endif; ?>
                    </p>
                    <p><i class="fas fa-id-badge"></i> <strong>Identifiant :</strong> <?php echo htmlspecialchars($admin['id'] ?? ''); ?></p>
                    <p><i class="fas fa-calendar-alt"></i> <strong>Date de création :</strong> <?php echo htmlspecialchars($admin['created_at'] ?? 'Non disponible'); ?></p>
                    <p><i class="fas fa-clock"></i> <strong>Dernière connexion :</strong> <?php echo htmlspecialchars($admin['last_login'] ?? 'Non disponible'); ?></p>
                    <p><i class="fas fa-sign-in-alt"></i> <strong>Mes connexions :</strong> <?php echo number_format($logins_count); ?></p>
                </div>
            </div>
        </section>
        
        <!-- Section Connexions -->
        <div class="card <?php echo $is_privileged_admin ? 'admin01-special' : 'admin-only'; ?>">
            <h2>
                <i class="fas fa-history"></i>
                <?php if ($is_privileged_admin): ?>
                    Surveillance des Connexions (Tous les utilisateurs)
                <?php else: ?>
                    Mon Historique de Connexion
                <?php endif; ?>
            </h2>
            
            <?php if ($is_privileged_admin): ?>
                <div class="feature-highlight">
                    <h4><i class="fas fa-shield-alt"></i> Monitoring de Sécurité</h4>
                    <p>Surveillez toutes les connexions à la plateforme pour détecter les activités suspectes et maintenir la sécurité du système.</p>
                </div>
            <?php endif; ?>
            
            <?php if ($is_privileged_admin): ?>
    <div class="role-filter-container">
        <label for="roleFilter">
            <i class="fas fa-filter"></i> Filtrer par rôle :
        </label>
        <select id="roleFilter" class="role-filter-select">
            <option value="all">Tous les rôles</option>
            <option value="admin">Administrateurs</option>
            <option value="teacher">Enseignants</option>
            <option value="student">Étudiants</option>
        </select>
        <div class="filter-results-count" id="filterResultsCount">
            Affichage de toutes les connexions
        </div>
    </div>
<?php endif; ?>

<div style="overflow-x: auto;">
                <table class="login-table">
                    <thead>
                        <tr>
                            <?php if ($is_privileged_admin): ?>
                                <th><i class="fas fa-user"></i> Utilisateur</th>
                                <th><i class="fas fa-id-badge"></i> Rôle</th>
                            <?php endif; ?>
                            <th><i class="fas fa-calendar-alt"></i> Date et Heure</th>
                            <th><i class="fas fa-map-marker-alt"></i> Adresse IP</th>
                            <?php if ($is_privileged_admin): ?>
                                <th><i class="fas fa-browser"></i> Navigateur</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($logins_result && $logins_result->num_rows > 0): ?>
                            <?php while ($login = $logins_result->fetch_assoc()): ?>
                                <tr>
                                    <?php if ($is_privileged_admin): ?>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <?php if (!empty($login['avatar']) && $login['avatar'] !== 'default_avatar.png'): ?>
                                                    <img src="../<?php echo htmlspecialchars($login['avatar']); ?>" alt="Avatar" class="user-avatar">
                                                <?php else: ?>
                                                    <div class="user-avatar" style="background: var(--accent-color); display: flex; align-items: center; justify-content: center;">
                                                        <i class="fas fa-user" style="color: white;"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <strong><?php echo htmlspecialchars($login['name'] ?? 'Utilisateur inconnu'); ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="role-badge role-<?php echo $login['role'] ?? 'student'; ?>">
                                                <?php 
                                                switch($login['role'] ?? 'student') {
                                                    case 'admin': echo '<i class="fas fa-user-shield"></i> Admin'; break;
                                                    case 'teacher': echo '<i class="fas fa-chalkboard-teacher"></i> Enseignant'; break;
                                                    case 'student': echo '<i class="fas fa-user-graduate"></i> Étudiant'; break;
                                                    default: echo '<i class="fas fa-user"></i> Utilisateur'; break;
                                                }
                                                ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <i class="fas fa-calendar-alt" style="color: var(--accent-color); margin-right: 5px;"></i>
                                        <?php echo htmlspecialchars($login['login_time']); ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-map-marker-alt" style="color: var(--accent-color); margin-right: 5px;"></i>
                                        <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 4px;">
                                            <?php echo htmlspecialchars($login['ip_address']); ?>
                                        </code>
                                    </td>
                                    <?php if ($is_privileged_admin): ?>
                                        <td style="font-size: 0.9rem;">
                                            <?php 
                                            $user_agent = $login['user_agent'] ?? '';
                                            if (strpos($user_agent, 'Chrome') !== false) {
                                                echo '<i class="fab fa-chrome" style="color: #4285f4;"></i> Chrome';
                                            } elseif (strpos($user_agent, 'Firefox') !== false) {
                                                echo '<i class="fab fa-firefox" style="color: #ff7139;"></i> Firefox';
                                            } elseif (strpos($user_agent, 'Safari') !== false) {
                                                echo '<i class="fab fa-safari" style="color: #1b88ca;"></i> Safari';
                                            } elseif (strpos($user_agent, 'Edge') !== false) {
                                                echo '<i class="fab fa-edge" style="color: #0078d4;"></i> Edge';
                                            } else {
                                                echo '<i class="fas fa-globe"></i> Autre';
                                            }
                                            ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo $is_privileged_admin ? '5' : '2'; ?>" style="text-align: center; padding: 30px; opacity: 0.6;">
                                    <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                                    Aucune connexion enregistrée
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($is_privileged_admin): ?>
                <div style="margin-top: 20px; padding: 15px; background: rgba(233, 30, 99, 0.1); border-radius: 8px; border-left: 4px solid var(--admin01-color);">
                    <h5 style="color: var(--admin01-color); margin-bottom: 8px;"><i class="fas fa-exclamation-triangle"></i> Note de Sécurité</h5>
                    <p style="margin: 0; font-size: 0.9rem; opacity: 0.9;">
                        En tant qu'Administrateur Principal, surveillez les connexions inhabituelles ou suspectes. 
                        Contactez l'équipe technique si vous détectez des activités anormales.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php 
    if (file_exists('../includes/footer.php')) {
        include '../includes/footer.php'; 
    }
    ?>

    <script>
        // Gestion du téléchargement de la photo de profil
        const profileImage = document.getElementById('profileImage');
        const profileImageOverlay = document.getElementById('profileImageOverlay');
        const profileImageInput = document.getElementById('profile_image');
        
        if (profileImageOverlay) {
            profileImageOverlay.addEventListener('click', function() {
                profileImageInput.click();
            });
        }
        
        if (profileImage) {
            profileImage.addEventListener('click', function() {
                profileImageInput.click();
            });
        }
        
        if (profileImageInput) {
            profileImageInput.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        profileImage.src = event.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
        
        // Gestion de la section mot de passe
        const passwordSection = document.getElementById('password-section');
        const showPasswordBtn = document.getElementById('show-password-btn');
        
        if (showPasswordBtn) {
            showPasswordBtn.addEventListener('click', function() {
                if (passwordSection.style.display === 'block') {
                    passwordSection.style.display = 'none';
                    showPasswordBtn.innerHTML = '<i class="fas fa-key"></i> Modifier le mot de passe';
                } else {
                    passwordSection.style.display = 'block';
                    showPasswordBtn.innerHTML = '<i class="fas fa-eye-slash"></i> Masquer les options de mot de passe';
                }
            });
        }

        // Animation des cartes au chargement
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card, .stat-card');
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
        // Filtrage par rôle des connexions
        document.addEventListener('DOMContentLoaded', function() {
            const roleFilter = document.getElementById('roleFilter');
            const loginTable = document.querySelector('.login-table tbody');
            const filterResultsCount = document.getElementById('filterResultsCount');
            
            if (roleFilter && loginTable) {
                const allRows = Array.from(loginTable.querySelectorAll('tr'));
                
                roleFilter.addEventListener('change', function() {
                    const selectedRole = this.value;
                    let visibleCount = 0;
                    
                    allRows.forEach(row => {
                        // Vérifier si la ligne contient des données (pas le message "Aucune connexion")
                        if (row.cells.length > 2) {
                            if (selectedRole === 'all') {
                                row.style.display = '';
                                visibleCount++;
                            } else {
                                // Trouver la cellule du rôle (2ème colonne pour admin privilégié)
                                const roleCell = row.cells[1];
                                if (roleCell) {
                                    const roleSpan = roleCell.querySelector('.role-badge');
                                    if (roleSpan && roleSpan.classList.contains('role-' + selectedRole)) {
                                        row.style.display = '';
                                        visibleCount++;
                                    } else {
                                        row.style.display = 'none';
                                    }
                                }
                            }
                        } else {
                            // Masquer/afficher le message "Aucune connexion" selon le contexte
                            if (selectedRole === 'all' || visibleCount === 0) {
                                row.style.display = visibleCount === 0 ? '' : 'none';
                            } else {
                                row.style.display = 'none';
                            }
                        }
                    });
                    
                    // Mettre à jour le compteur
                    if (filterResultsCount) {
                        if (selectedRole === 'all') {
                            filterResultsCount.textContent = `Affichage de toutes les connexions (${visibleCount})`;
                        } else {
                            const roleNames = {
                                'admin': 'Administrateurs',
                                'teacher': 'Enseignants', 
                                'student': 'Étudiants'
                            };
                            filterResultsCount.textContent = `${visibleCount} connexion(s) - ${roleNames[selectedRole]}`;
                        }
                    }
                });
                
                // Initialiser le compteur au chargement
                const initialCount = allRows.filter(row => row.cells.length > 2).length;
                if (filterResultsCount) {
                    filterResultsCount.textContent = `Affichage de toutes les connexions (${initialCount})`;
                }
            }
        });
    </script>
</body>
</html>