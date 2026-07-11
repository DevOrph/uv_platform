<?php
session_start();

// Désactiver l'affichage des warnings pour une interface propre
error_reporting(E_ERROR | E_PARSE);

// SÉCURITÉ : Vérifier si l'utilisateur est un administrateur connecté
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

// Vérification de la connexion à la base de données
if (!isset($conn) || $conn->connect_error) {
    die("Erreur de connexion à la base de données: " . (isset($conn) ? $conn->connect_error : "Connexion non établie"));
}

require_once '../includes/super_admin.php';

// SÉCURITÉ : page réservée aux super administrateurs
if (!is_super_admin($conn)) {
    header("Location: admin_dashboard.php");
    exit();
}

// Messages
$success_message = "";
$error_message = "";

// Fonction améliorée pour enregistrer un log détaillé avec plus d'informations
function logAdminActionDetailed($conn, $admin_id, $action_type, $entity_id = null, $entity_type = 'USER', $old_values = null, $new_values = null, $entity_name = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Créer une description plus détaillée
    $description = generateDetailedDescription($action_type, $entity_name, $entity_id, $old_values, $new_values);
    
    $log_sql = "INSERT INTO admin_logs (admin_id, action_type, description, ip_address, user_agent, entity_id, entity_type, old_value, new_value, entity_name, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    if ($log_stmt) {
        $old_json = $old_values ? json_encode($old_values) : null;
        $new_json = $new_values ? json_encode($new_values) : null;
        $log_stmt->bind_param("ssssssssss", $admin_id, $action_type, $description, $ip_address, $user_agent, $entity_id, $entity_type, $old_json, $new_json, $entity_name);
        $log_stmt->execute();
    }
}

// Fonction pour générer des descriptions détaillées
function generateDetailedDescription($action_type, $entity_name, $entity_id, $old_values, $new_values) {
    switch ($action_type) {
        case 'UPDATE_USER':
            return "Modification des informations de l'étudiant {$entity_name} ({$entity_id})";
        case 'CREATE_USER':
            return "Création du compte étudiant {$entity_name} ({$entity_id})";
        case 'BLOCK_USER':
            return "Blocage du compte étudiant {$entity_name} ({$entity_id})";
        case 'UNBLOCK_USER':
            return "Déblocage du compte étudiant {$entity_name} ({$entity_id})";
        case 'DELETE_USER':
            return "Suppression du compte étudiant {$entity_name} ({$entity_id})";
        case 'CREATE_COURSE':
            return "Création du cours {$entity_name}";
        case 'UPDATE_COURSE':
            return "Modification du cours {$entity_name}";
        case 'DELETE_COURSE':
            return "Suppression du cours {$entity_name}";
        case 'CREATE_ADMIN':
            return "Création de l'administrateur {$entity_name} ({$entity_id})";
        case 'BLOCK_ADMIN':
            return "Blocage de l'administrateur {$entity_name} ({$entity_id})";
        case 'UNBLOCK_ADMIN':
            return "Déblocage de l'administrateur {$entity_name} ({$entity_id})";
        case 'DELETE_ADMIN':
            return "Suppression de l'administrateur {$entity_name} ({$entity_id})";
        case 'LOGIN':
            return "Connexion à la plateforme par {$entity_name}";
        default:
            return "Action {$action_type} sur {$entity_name}";
    }
}

// Fonction pour formater les noms de champs
function formatFieldLabel($key) {
    $labels = [
        'name' => 'Nom complet',
        'email' => 'Adresse email',
        'phone' => 'Téléphone',
        'status' => 'Statut du compte',
        'blocked' => 'État de blocage',
        'class_id' => 'Classe assignée',
        'address' => 'Adresse',
        'birth_date' => 'Date de naissance',
        'gender' => 'Genre',
        'course_name' => 'Nom du cours',
        'course_description' => 'Description du cours',
        'credits' => 'Nombre de crédits',
        'role' => 'Rôle utilisateur'
    ];
    
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

// Fonction pour extraire les informations du navigateur
function extractBrowserInfo($user_agent) {
    if (strpos($user_agent, 'Chrome') !== false) return '🌐 Google Chrome';
    if (strpos($user_agent, 'Firefox') !== false) return '🦊 Mozilla Firefox';
    if (strpos($user_agent, 'Safari') !== false) return '🧭 Safari';
    if (strpos($user_agent, 'Edge') !== false) return '📘 Microsoft Edge';
    return '🌐 Navigateur inconnu';
}

// Traitement des actions sur les administrateurs
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Blocage/déblocage d'un administrateur
    if (isset($_POST['toggle_status'])) {
        $admin_id = $_POST['admin_id'];
        $current_status = $_POST['current_status'];
        $new_status = ($current_status == 1) ? 0 : 1;
        
        // Récupérer les informations de l'admin avant modification
        $admin_info_sql = "SELECT name, blocked FROM users WHERE id = ? AND role = 'admin'";
        $admin_info_stmt = $conn->prepare($admin_info_sql);
        $admin_info_stmt->bind_param("s", $admin_id);
        $admin_info_stmt->execute();
        $admin_info = $admin_info_stmt->get_result()->fetch_assoc();
        
        $status_sql = "UPDATE users SET blocked = ? WHERE id = ? AND role = 'admin'";
        $status_stmt = $conn->prepare($status_sql);
        if ($status_stmt) {
            $status_stmt->bind_param("is", $new_status, $admin_id);
            
            if ($status_stmt->execute()) {
                $action_type = ($new_status == 1) ? 'BLOCK_ADMIN' : 'UNBLOCK_ADMIN';
                
                $old_values = ['blocked' => ($current_status == 1) ? 'Bloqué' : 'Actif'];
                $new_values = ['blocked' => ($new_status == 1) ? 'Bloqué' : 'Actif'];
                
                logAdminActionDetailed($conn, $user_id, $action_type, $admin_id, 'USER', $old_values, $new_values, $admin_info['name']);
                
                $success_message = "Statut de l'administrateur modifié avec succès.";
            } else {
                $error_message = "Erreur lors de la modification du statut: " . $conn->error;
            }
        }
    }
    
    // Création d'un nouvel administrateur
    if (isset($_POST['create_admin'])) {
        $new_admin_id = trim($_POST['new_admin_id']);
        $new_admin_name = trim($_POST['new_admin_name']);
        $new_admin_email = trim($_POST['new_admin_email']);
        $new_admin_password = trim($_POST['new_admin_password']);
        $new_admin_phone = trim($_POST['new_admin_phone'] ?? '');
        
        // Validation des données
        $validation_errors = [];
        
        if (empty($new_admin_id)) {
            $validation_errors[] = "L'identifiant est requis.";
        } elseif (!preg_match('/^[A-Z0-9_-]+$/', $new_admin_id)) {
            $validation_errors[] = "L'identifiant doit contenir uniquement des lettres majuscules, chiffres, tirets et underscores.";
        }
        
        if (empty($new_admin_name)) {
            $validation_errors[] = "Le nom est requis.";
        }
        
        if (empty($new_admin_email)) {
            $validation_errors[] = "L'email est requis.";
        } elseif (!filter_var($new_admin_email, FILTER_VALIDATE_EMAIL)) {
            $validation_errors[] = "Format d'email invalide.";
        }
        
        if (empty($new_admin_password)) {
            $validation_errors[] = "Le mot de passe est requis.";
        } elseif (strlen($new_admin_password) < 8) {
            $validation_errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
        }
        
        // Vérifier si l'ID ou l'email existe déjà
        if (empty($validation_errors)) {
            $check_sql = "SELECT COUNT(*) as count FROM users WHERE id = ? OR email = ?";
            $check_stmt = $conn->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("ss", $new_admin_id, $new_admin_email);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $check_data = $check_result->fetch_assoc();
                
                if ($check_data['count'] > 0) {
                    $validation_errors[] = "Cet identifiant ou cette adresse email est déjà utilisé(e).";
                }
            }
        }
        
        if (empty($validation_errors)) {
            // Hachage du mot de passe
            $hashed_password = password_hash($new_admin_password, PASSWORD_DEFAULT);
            
            // Insertion du nouvel administrateur
            $insert_sql = "INSERT INTO users (id, name, email, phone, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, 'admin', 'active', NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            if ($insert_stmt) {
                $insert_stmt->bind_param("sssss", $new_admin_id, $new_admin_name, $new_admin_email, $new_admin_phone, $hashed_password);
                
                if ($insert_stmt->execute()) {
                    $action_type = 'CREATE_ADMIN';
                    $new_values = [
                        'id' => $new_admin_id,
                        'name' => $new_admin_name,
                        'email' => $new_admin_email,
                        'phone' => $new_admin_phone,
                        'role' => 'admin'
                    ];
                    
                    logAdminActionDetailed($conn, $user_id, $action_type, $new_admin_id, 'USER', null, $new_values, $new_admin_name);
                    
                    $success_message = "Nouvel administrateur créé avec succès. Identifiants: $new_admin_id";
                } else {
                    $error_message = "Erreur lors de la création de l'administrateur: " . $conn->error;
                }
            }
        } else {
            $error_message = implode("<br>", $validation_errors);
        }
    }
    
    // Promotion / rétrogradation super administrateur
    if (isset($_POST['toggle_super_admin'])) {
        $target_id = $_POST['admin_id'] ?? '';
        $stmt = $conn->prepare("SELECT id, name, is_super_admin FROM users WHERE id = ? AND role = 'admin'");
        $stmt->bind_param("s", $target_id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();

        if (!$target) {
            $error_message = "Administrateur introuvable.";
        } elseif ($target['is_super_admin'] && super_admin_count($conn) <= 1) {
            $error_message = "Impossible : il doit toujours rester au moins un super administrateur.";
        } else {
            $new_flag = $target['is_super_admin'] ? 0 : 1;
            $stmt = $conn->prepare("UPDATE users SET is_super_admin = ? WHERE id = ? AND role = 'admin'");
            $stmt->bind_param("is", $new_flag, $target_id);
            if ($stmt->execute()) {
                logAdminActionDetailed($conn, $user_id, $new_flag ? 'PROMOTE_SUPER_ADMIN' : 'DEMOTE_SUPER_ADMIN', $target_id, 'USER',
                    ['is_super_admin' => (int) $target['is_super_admin']], ['is_super_admin' => $new_flag], $target['name']);
                $success_message = $new_flag
                    ? htmlspecialchars($target['name']) . " est désormais super administrateur."
                    : htmlspecialchars($target['name']) . " n'est plus super administrateur.";
            } else {
                $error_message = "Erreur lors de la mise à jour : " . $conn->error;
            }
        }
    }

    // Suppression d'un administrateur
    if (isset($_POST['delete_admin'])) {
        $admin_id_to_delete = $_POST['admin_id'];
        
        // Un super administrateur ne peut pas être supprimé (le rétrograder d'abord)
        if (!is_super_admin($conn, $admin_id_to_delete)) {
            // Récupérer les informations de l'admin avant suppression
            $admin_info_sql = "SELECT name, email, phone, created_at FROM users WHERE id = ? AND role = 'admin'";
            $admin_info_stmt = $conn->prepare($admin_info_sql);
            $admin_info_stmt->bind_param("s", $admin_id_to_delete);
            $admin_info_stmt->execute();
            $admin_info = $admin_info_stmt->get_result()->fetch_assoc();
            
            $delete_sql = "DELETE FROM users WHERE id = ? AND role = 'admin'";
            $delete_stmt = $conn->prepare($delete_sql);
            if ($delete_stmt) {
                $delete_stmt->bind_param("s", $admin_id_to_delete);
                
                if ($delete_stmt->execute()) {
                    $action_type = 'DELETE_ADMIN';
                    $old_values = [
                        'id' => $admin_id_to_delete,
                        'name' => $admin_info['name'],
                        'email' => $admin_info['email'],
                        'phone' => $admin_info['phone'],
                        'created_at' => $admin_info['created_at']
                    ];
                    
                    logAdminActionDetailed($conn, $user_id, $action_type, $admin_id_to_delete, 'USER', $old_values, null, $admin_info['name']);
                    
                    $success_message = "Administrateur supprimé avec succès.";
                } else {
                    $error_message = "Erreur lors de la suppression: " . $conn->error;
                }
            }
        } else {
            $error_message = "Impossible de supprimer un super administrateur : rétrogradez-le d'abord.";
        }
    }
}

// Récupération des statistiques
$stats = [
    'total_admins' => 0,
    'active_admins' => 0,
    'blocked_admins' => 0,
    'recent_logins' => 0,
    'admin_actions_today' => 0
];

$stats_queries = [
    'total_admins' => "SELECT COUNT(*) as count FROM users WHERE role = 'admin'",
    'active_admins' => "SELECT COUNT(*) as count FROM users WHERE role = 'admin' AND blocked = 0",
    'blocked_admins' => "SELECT COUNT(*) as count FROM users WHERE role = 'admin' AND blocked = 1",
    'recent_logins' => "SELECT COUNT(DISTINCT user_id) as count FROM user_logins ul 
                       JOIN users u ON ul.user_id = u.id 
                       WHERE u.role = 'admin' AND ul.login_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
    'admin_actions_today' => "SELECT COUNT(*) as count FROM admin_logs 
                              WHERE DATE(created_at) = CURDATE() 
                              AND admin_id IN (SELECT id FROM users WHERE role = 'admin')"
];

foreach ($stats_queries as $key => $query) {
    $result = $conn->query($query);
    if ($result) {
        $stats[$key] = $result->fetch_assoc()['count'] ?? 0;
    }
}

// Récupération des administrateurs
$admins_sql = "SELECT u.*, 
               (SELECT MAX(login_time) FROM user_logins WHERE user_id = u.id) as last_login,
               (SELECT COUNT(*) FROM admin_logs WHERE admin_id = u.id AND DATE(created_at) = CURDATE()) as actions_today
               FROM users u 
               WHERE role = 'admin' 
               ORDER BY 
                 CASE WHEN u.is_super_admin = 1 THEN 0 ELSE 1 END,
                 created_at DESC";
$admins_result = $conn->query($admins_sql);

// Récupération des dernières actions administratives avec détails
$admin_actions_sql = "SELECT al.*, u.name as admin_name, u.avatar as admin_avatar, u.is_super_admin as admin_is_super 
                     FROM admin_logs al
                     LEFT JOIN users u ON al.admin_id = u.id
                     ORDER BY al.created_at DESC 
                     LIMIT 20";
$admin_actions_result = $conn->query($admin_actions_sql);

// Vérifier si les colonnes existent dans la table admin_logs
$columns_check_sql = "SHOW COLUMNS FROM admin_logs LIKE 'old_value'";
$columns_exist = $conn->query($columns_check_sql)->num_rows > 0;

// Fonction améliorée pour formater les types d'actions
function formatActionTypeDetailed($action_type, $old_value = null, $new_value = null) {
    $actions = [
        'CREATE_ADMIN' => [
            'class' => 'action-create', 
            'icon' => 'fa-user-plus', 
            'text' => 'Création Admin',
            'color' => '#4CAF50'
        ],
        'BLOCK_ADMIN' => [
            'class' => 'action-block', 
            'icon' => 'fa-ban', 
            'text' => 'Blocage Admin',
            'color' => '#F44336'
        ],
        'UNBLOCK_ADMIN' => [
            'class' => 'action-unblock', 
            'icon' => 'fa-unlock', 
            'text' => 'Déblocage Admin',
            'color' => '#4CAF50'
        ],
        'DELETE_ADMIN' => [
            'class' => 'action-delete', 
            'icon' => 'fa-trash', 
            'text' => 'Suppression Admin',
            'color' => '#F44336'
        ],
        'UPDATE_USER' => [
            'class' => 'action-update', 
            'icon' => 'fa-user-edit', 
            'text' => 'Modification Étudiant',
            'color' => '#2196F3'
        ],
        'CREATE_USER' => [
            'class' => 'action-create', 
            'icon' => 'fa-user-plus', 
            'text' => 'Création Étudiant',
            'color' => '#4CAF50'
        ],
        'BLOCK_USER' => [
            'class' => 'action-block', 
            'icon' => 'fa-user-slash', 
            'text' => 'Blocage Étudiant',
            'color' => '#F44336'
        ],
        'UNBLOCK_USER' => [
            'class' => 'action-unblock', 
            'icon' => 'fa-user-check', 
            'text' => 'Déblocage Étudiant',
            'color' => '#4CAF50'
        ],
        'CREATE_COURSE' => [
            'class' => 'action-create', 
            'icon' => 'fa-book-open', 
            'text' => 'Création Cours',
            'color' => '#4CAF50'
        ],
        'UPDATE_COURSE' => [
            'class' => 'action-update', 
            'icon' => 'fa-edit', 
            'text' => 'Modification Cours',
            'color' => '#2196F3'
        ],
        'LOGIN' => [
            'class' => 'action-login', 
            'icon' => 'fa-sign-in-alt', 
            'text' => 'Connexion',
            'color' => '#FF9800'
        ]
    ];
    
    return $actions[$action_type] ?? [
        'class' => 'action-update', 
        'icon' => 'fa-cog', 
        'text' => $action_type,
        'color' => '#2196F3'
    ];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Administrateurs - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            --admin01-color: #e91e63;
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

        /* Header */
        .page-header {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
            position: relative;
            border-left: 4px solid var(--admin01-color);
            background: linear-gradient(135deg, rgba(233, 30, 99, 0.1), rgba(233, 30, 99, 0.05));
        }

        .page-header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            background: linear-gradient(45deg, var(--accent-color), #4fc3f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-header p {
            opacity: 0.8;
            font-size: 1.1rem;
            margin-top: 10px;
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

        /* Messages d'alerte */
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

        .alert.alert-success {
            background: linear-gradient(135deg, var(--success-color), #27ae60);
            border-left: 4px solid #2ecc71;
        }

        .alert.alert-error {
            background: linear-gradient(135deg, var(--error-color), #c0392b);
            border-left: 4px solid #e74c3c;
        }

        .alert.alert-warning {
            background: linear-gradient(135deg, var(--warning-color), #d68910);
            border-left: 4px solid #f39c12;
        }

        /* Statistiques */
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

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }

        /* Grille d'administrateurs */
        .admins-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .admin-card {
            background: var(--card-bg);
            border-radius: 15px;
            padding: 25px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            position: relative;
        }

        .admin-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3);
        }

        .admin-card.admin01 {
            border-left: 4px solid var(--admin01-color);
            background: linear-gradient(135deg, rgba(233, 30, 99, 0.1), rgba(233, 30, 99, 0.05));
        }

        .admin-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .admin-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent-color);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .admin-info {
            flex: 1;
        }

        .admin-name {
            font-size: 1.3rem;
            font-weight: bold;
            margin: 0 0 5px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-id {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
            font-family: 'Courier New', monospace;
            background: rgba(255, 255, 255, 0.1);
            padding: 2px 8px;
            border-radius: 5px;
            display: inline-block;
        }

        .admin-details {
            margin-bottom: 20px;
        }

        .admin-details p {
            margin: 10px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
        }

        .admin-details i {
            color: var(--accent-color);
            width: 20px;
            text-align: center;
        }

        .admin-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 10px;
        }

        .admin-status.active {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
            border: 1px solid #4CAF50;
        }

        .admin-status.blocked {
            background: rgba(244, 67, 54, 0.2);
            color: #F44336;
            border: 1px solid #F44336;
        }

        .admin-status.principal {
            background: rgba(233, 30, 99, 0.2);
            color: var(--admin01-color);
            border: 1px solid var(--admin01-color);
        }

        .admin-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .admin-actions button,
        .admin-actions a {
            flex: 1;
            min-width: 120px;
            padding: 10px 15px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-edit {
            background: rgba(33, 150, 243, 0.2);
            color: #2196F3;
            border: 1px solid rgba(33, 150, 243, 0.3);
        }

        .btn-edit:hover {
            background: rgba(33, 150, 243, 0.3);
            transform: translateY(-2px);
        }

        .btn-block {
            background: rgba(244, 67, 54, 0.2);
            color: #F44336;
            border: 1px solid rgba(244, 67, 54, 0.3);
        }

        .btn-block:hover {
            background: rgba(244, 67, 54, 0.3);
            transform: translateY(-2px);
        }

        .btn-unblock {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .btn-unblock:hover {
            background: rgba(76, 175, 80, 0.3);
            transform: translateY(-2px);
        }

        .btn-delete {
            background: rgba(244, 67, 54, 0.3);
            color: #F44336;
            border: 1px solid #F44336;
        }

        .btn-delete:hover {
            background: rgba(244, 67, 54, 0.5);
            transform: translateY(-2px);
        }

        /* Formulaires */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--accent-color);
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border-radius: 10px;
            border: 2px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--accent-color);
            background: rgba(255, 255, 255, 0.15);
        }

        .form-group small {
            display: block;
            margin-top: 5px;
            color: rgba(255, 255, 255, 0.6);
            font-size: 0.85rem;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
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

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
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

        .btn-secondary:hover {
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.4);
            transform: translateY(-2px);
        }

        /* Logs d'activité améliorés */
        .activity-logs {
            max-height: 600px;
            overflow-y: auto;
            padding-right: 10px;
        }

        /* Styles pour les logs améliorés */
        .log-item {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            margin-bottom: 15px;
            border-left: 4px solid var(--accent-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .log-item:hover {
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
        }

        .log-header {
            padding: 20px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }

        .log-header:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .log-main-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex: 1;
        }

        .log-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent-color);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
        }

        .log-admin-info {
            flex: 1;
        }

        .log-admin-name {
            font-weight: 600;
            color: var(--accent-color);
            font-size: 1.1rem;
            margin-bottom: 5px;
        }

        .log-description-main {
            font-size: 1rem;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 5px;
            font-weight: 500;
        }

        .log-time {
            font-size: 0.85rem;
            opacity: 0.7;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .log-ip {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.8rem;
        }

        .log-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .log-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .log-toggle-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: var(--text-light);
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .log-toggle-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: var(--accent-color);
        }

        .log-toggle-btn.active {
            background: var(--accent-color);
            border-color: var(--accent-color);
        }

        .log-toggle-btn.active i {
            transform: rotate(180deg);
        }

        /* Panel des détails */
        .log-details-panel {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(0, 0, 0, 0.3);
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                max-height: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                max-height: 1000px;
                transform: translateY(0);
            }
        }

        .log-diff-container {
            padding: 20px;
        }

        .log-diff-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .log-diff-header h4 {
            color: var(--accent-color);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .log-diff-header small {
            color: rgba(255, 255, 255, 0.6);
        }

        /* Vue diff style GitHub */
        .diff-view {
            background: rgba(0, 0, 0, 0.4);
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .diff-field {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .diff-field:last-child {
            border-bottom: none;
        }

        .diff-field-header {
            background: rgba(255, 255, 255, 0.05);
            padding: 12px 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            color: var(--accent-color);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .diff-line {
            display: flex;
            align-items: center;
            padding: 8px 0;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }

        .diff-marker {
            width: 30px;
            text-align: center;
            font-weight: bold;
            margin-right: 15px;
        }

        .diff-content {
            flex: 1;
            padding: 5px 10px;
            border-radius: 4px;
        }

        .diff-removed {
            background: rgba(248, 81, 73, 0.15);
            border-left: 3px solid #f85149;
        }

        .diff-removed .diff-marker {
            color: #f85149;
        }

        .diff-removed .diff-content {
            background: rgba(248, 81, 73, 0.1);
        }

        .diff-added {
            background: rgba(46, 160, 67, 0.15);
            border-left: 3px solid #2ea043;
        }

        .diff-added .diff-marker {
            color: #2ea043;
        }

        .diff-added .diff-content {
            background: rgba(46, 160, 67, 0.1);
        }

        .diff-no-changes {
            text-align: center;
            padding: 30px;
            color: rgba(255, 255, 255, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* Informations techniques */
        .log-technical-info {
            background: rgba(255, 255, 255, 0.03);
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }

        .log-technical-info h5 {
            color: var(--accent-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .technical-details {
            display: grid;
            gap: 10px;
        }

        .tech-detail {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.8);
        }

        .tech-detail i {
            color: var(--accent-color);
            width: 16px;
            text-align: center;
        }

        /* Couleurs spécifiques pour les types d'actions */
        .log-item.action-create { border-left-color: #4CAF50; }
        .log-item.action-update { border-left-color: #2196F3; }
        .log-item.action-delete { border-left-color: #F44336; }
        .log-item.action-block { border-left-color: #F44336; }
        .log-item.action-unblock { border-left-color: #4CAF50; }
        .log-item.action-login { border-left-color: #FF9800; }

        .action-create { 
            background: rgba(76, 175, 80, 0.2); 
            color: #4CAF50; 
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .action-update, .action-unblock { 
            background: rgba(33, 150, 243, 0.2); 
            color: #2196F3; 
            border: 1px solid rgba(33, 150, 243, 0.3);
        }

        .action-delete, .action-block { 
            background: rgba(244, 67, 54, 0.2); 
            color: #F44336; 
            border: 1px solid rgba(244, 67, 54, 0.3);
        }

        .action-login { 
            background: rgba(255, 152, 0, 0.2); 
            color: #FF9800; 
            border: 1px solid rgba(255, 152, 0, 0.3);
        }

        /* Section cachée */
        .hidden-section {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: rgba(255, 255, 255, 0.6);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.4;
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

        /* Scrollbar personnalisé */
        .activity-logs::-webkit-scrollbar {
            width: 8px;
        }

        .activity-logs::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }

        .activity-logs::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 4px;
        }

        .activity-logs::-webkit-scrollbar-thumb:hover {
            background: #4fc3f7;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .admins-container {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .admin-actions {
                flex-direction: column;
            }

            .admin01-badge {
                position: relative;
                top: auto;
                right: auto;
                margin-bottom: 15px;
                display: inline-block;
            }

            .log-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .log-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .log-main-info {
                width: 100%;
            }
            
            .diff-line {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .diff-marker {
                width: auto;
                margin-right: 0;
                margin-bottom: 5px;
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
        <div class="page-header">
            <div class="admin01-badge">
                <i class="fas fa-star"></i> ADMIN Principal
            </div>
            <h1>
                <i class="fas fa-users-cog"></i>
                Gestion des Administrateurs
            </h1>
            <p>Contrôle et surveillance de tous les comptes administrateurs de la plateforme</p>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total_admins']; ?></div>
                <div class="stat-label"><i class="fas fa-users-cog"></i> Total Admins</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active_admins']; ?></div>
                <div class="stat-label"><i class="fas fa-user-check"></i> Admins Actifs</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['blocked_admins']; ?></div>
                <div class="stat-label"><i class="fas fa-user-slash"></i> Admins Bloqués</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['recent_logins']; ?></div>
                <div class="stat-label"><i class="fas fa-clock"></i> Connexions 24h</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['admin_actions_today']; ?></div>
                <div class="stat-label"><i class="fas fa-history"></i> Actions Aujourd'hui</div>
            </div>
        </div>

        <!-- Formulaire de création -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-user-plus"></i> Créer un nouvel administrateur</h2>
                <button id="show-create-form-btn" class="btn">
                    <i class="fas fa-plus"></i> Créer un administrateur
                </button>
            </div>
            
            <div id="create-admin-form" class="hidden-section">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_admin_id">Identifiant Admin *</label>
                            <input type="text" id="new_admin_id" name="new_admin_id" required placeholder="ex: ADMIN03" pattern="[A-Z0-9_-]+">
                            <small>Utilisez uniquement des majuscules, chiffres, tirets et underscores. Ne peut pas être modifié après création.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_admin_name">Nom complet *</label>
                            <input type="text" id="new_admin_name" name="new_admin_name" required placeholder="ex: Jean Dupont">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_admin_email">Adresse email *</label>
                            <input type="email" id="new_admin_email" name="new_admin_email" required placeholder="ex: admin@example.com">
                            <small>Cette adresse sera utilisée pour la récupération de mot de passe.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_admin_phone">Téléphone</label>
                            <input type="tel" id="new_admin_phone" name="new_admin_phone" placeholder="ex: +241 00 00 00 00">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_admin_password">Mot de passe temporaire *</label>
                        <input type="password" id="new_admin_password" name="new_admin_password" required>
                        <small>Minimum 8 caractères. L'administrateur devra le changer lors de sa première connexion.</small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="create_admin" class="btn">
                            <i class="fas fa-user-plus"></i> Créer l'administrateur
                        </button>
                        <button type="button" id="cancel-create-btn" class="btn-secondary">
                            <i class="fas fa-times"></i> Annuler
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Liste des administrateurs -->
        <div class="card">
            <h2><i class="fas fa-users-cog"></i> Administrateurs de la plateforme</h2>
            
            <div class="admins-container">
                <?php if ($admins_result && $admins_result->num_rows > 0): ?>
                    <?php while ($admin = $admins_result->fetch_assoc()): ?>
                        <div class="admin-card <?php echo !empty($admin['is_super_admin']) ? 'admin01' : ''; ?>">
                            <div class="admin-header">
                                <?php if (!empty($admin['avatar']) && $admin['avatar'] !== 'default_avatar.png'): ?>
                                    <img src="../<?php echo htmlspecialchars($admin['avatar']); ?>" alt="Avatar" class="admin-avatar">
                                <?php else: ?>
                                    <img src="../assets/images/profil.png" alt="Avatar par défaut" class="admin-avatar">
                                <?php endif; ?>
                                
                                <div class="admin-info">
                                    <h3 class="admin-name">
                                        <?php echo htmlspecialchars($admin['name']); ?>
                                        <?php if (!empty($admin['is_super_admin'])): ?>
                                            <span class="admin-status principal">Super Admin</span>
                                        <?php elseif ($admin['blocked'] == 1): ?>
                                            <span class="admin-status blocked">Bloqué</span>
                                        <?php else: ?>
                                            <span class="admin-status active">Actif</span>
                                        <?php endif; ?>
                                    </h3>
                                    <div class="admin-id"><?php echo htmlspecialchars($admin['id']); ?></div>
                                </div>
                            </div>
                            
                            <div class="admin-details">
                                <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($admin['email']); ?></p>
                                <?php if (!empty($admin['phone'])): ?>
                                    <p><i class="fas fa-phone"></i> <?php echo htmlspecialchars($admin['phone']); ?></p>
                                <?php endif; ?>
                                <p><i class="fas fa-calendar-alt"></i> Créé le: <?php echo htmlspecialchars(date('d/m/Y', strtotime($admin['created_at']))); ?></p>
                                <?php if (!empty($admin['last_login'])): ?>
                                    <p><i class="fas fa-clock"></i> Dernière connexion: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($admin['last_login']))); ?></p>
                                <?php else: ?>
                                    <p><i class="fas fa-clock"></i> Aucune connexion enregistrée</p>
                                <?php endif; ?>
                                <p><i class="fas fa-history"></i> Actions aujourd'hui: <?php echo $admin['actions_today'] ?? 0; ?></p>
                            </div>
                            
                            <div class="admin-actions">
                                <?php if ($admin['id'] === $user_id): ?>
                                    <a href="admin_profile.php" class="btn-edit">
                                        <i class="fas fa-user-edit"></i> Mon profil
                                    </a>
                                <?php else: ?>
                                    <form method="POST" action="" style="flex: 1;" onsubmit="return confirm('<?php echo !empty($admin['is_super_admin']) ? 'Retirer le rang de super administrateur à' : 'Promouvoir super administrateur'; ?> <?php echo htmlspecialchars($admin['name'], ENT_QUOTES); ?> ?')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                        <input type="hidden" name="admin_id" value="<?php echo htmlspecialchars($admin['id']); ?>">
                                        <button type="submit" name="toggle_super_admin" class="<?php echo !empty($admin['is_super_admin']) ? 'btn-block' : 'btn-edit'; ?>">
                                            <i class="fas <?php echo !empty($admin['is_super_admin']) ? 'fa-user-minus' : 'fa-user-shield'; ?>"></i>
                                            <?php echo !empty($admin['is_super_admin']) ? 'Rétrograder' : 'Promouvoir'; ?>
                                        </button>
                                    </form>
                                    <a href="edit_admin.php?id=<?php echo htmlspecialchars($admin['id']); ?>" class="btn-edit">
                                        <i class="fas fa-edit"></i> Modifier
                                    </a>
                                    
                                    <form method="POST" action="" style="flex: 1;" onsubmit="return confirmAction(event, '<?php echo $admin['blocked'] == 1 ? 'débloquer' : 'bloquer'; ?>', '<?php echo htmlspecialchars($admin['id']); ?>')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                        <input type="hidden" name="admin_id" value="<?php echo htmlspecialchars($admin['id']); ?>">
                                        <input type="hidden" name="current_status" value="<?php echo $admin['blocked']; ?>">
                                        
                                        <?php if ($admin['blocked'] == 1): ?>
                                            <button type="submit" name="toggle_status" class="btn-unblock">
                                                <i class="fas fa-unlock"></i> Débloquer
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="toggle_status" class="btn-block">
                                                <i class="fas fa-ban"></i> Bloquer
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                    
                                    <form method="POST" action="" style="flex: 1;" onsubmit="return confirmDelete(event, '<?php echo htmlspecialchars($admin['id']); ?>')">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                        <input type="hidden" name="admin_id" value="<?php echo htmlspecialchars($admin['id']); ?>">
                                        <button type="submit" name="delete_admin" class="btn-delete">
                                            <i class="fas fa-trash"></i> Supprimer
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users-slash"></i>
                        <p>Aucun administrateur trouvé</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Logs d'activité améliorés -->
        <div class="card">
            <h2><i class="fas fa-history"></i> Activité Administrative Récente</h2>
            
            <?php if (!$columns_exist): ?>
                <div class="alert alert-warning" style="background: linear-gradient(135deg, var(--warning-color), #d68910); border-left: 4px solid #f39c12;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Base de données à mettre à jour</strong><br>
                        Pour bénéficier des fonctionnalités avancées de logs (diff GitHub, détails des modifications), 
                        veuillez exécuter le script SQL de mise à jour fourni.
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="activity-logs">
                <?php if ($admin_actions_result && $admin_actions_result->num_rows > 0): ?>
                    <?php while ($log = $admin_actions_result->fetch_assoc()): ?>
                        <?php 
                        // Vérifier si les colonnes existent avant de les utiliser
                        $old_value = isset($log['old_value']) ? $log['old_value'] : null;
                        $new_value = isset($log['new_value']) ? $log['new_value'] : null;
                        $entity_name = isset($log['entity_name']) ? $log['entity_name'] : null;
                        $user_agent = isset($log['user_agent']) ? $log['user_agent'] : null;
                        
                        $action_info = formatActionTypeDetailed($log['action_type'], $old_value, $new_value);
                        $action_class = str_replace('action-', '', $action_info['class']);
                        $log_id = 'log_' . $log['id'];
                        $has_details = !empty($old_value) || !empty($new_value);
                        ?>
                        <div class="log-item action-<?php echo $action_class; ?>" data-log-id="<?php echo $log['id']; ?>">
                            <div class="log-header" <?php echo $has_details ? "onclick=\"toggleLogDetails('$log_id')\"" : ''; ?>>
                                <div class="log-main-info">
                                    <?php if (!empty($log['admin_avatar']) && $log['admin_avatar'] !== 'default_avatar.png'): ?>
                                        <img src="../<?php echo htmlspecialchars($log['admin_avatar']); ?>" alt="Avatar admin" class="log-avatar">
                                    <?php else: ?>
                                        <img src="../assets/images/profil.png" alt="Avatar par défaut" class="log-avatar">
                                    <?php endif; ?>
                                    
                                    <div class="log-admin-info">
                                        <div class="log-admin-name">
                                            <?php echo htmlspecialchars($log['admin_name'] ?? 'Administrateur'); ?>
                                            <?php if (!empty($log['admin_is_super'])): ?>
                                                <i class="fas fa-star" style="color: var(--admin01-color); margin-left: 5px;" title="Super Administrateur"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="log-description-main">
                                            <?php echo htmlspecialchars($log['description']); ?>
                                        </div>
                                        <div class="log-time">
                                            <i class="fas fa-clock"></i>
                                            <?php echo htmlspecialchars(date('d/m/Y à H:i:s', strtotime($log['created_at']))); ?>
                                            <?php if (!empty($log['ip_address'])): ?>
                                                <span class="log-ip">• IP: <?php echo htmlspecialchars($log['ip_address']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="log-actions">
                                    <span class="log-type-badge <?php echo $action_info['class']; ?>">
                                        <i class="fas <?php echo $action_info['icon']; ?>"></i> 
                                        <?php echo $action_info['text']; ?>
                                    </span>
                                    
                                    <?php if ($has_details): ?>
                                        <button class="log-toggle-btn" type="button">
                                            <i class="fas fa-chevron-down"></i>
                                            <span>Voir les détails</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
<script>
function toggleLogDetails(logId) {
    const panel = document.getElementById(logId);
    const header = panel.previousElementSibling;
    const button = header.querySelector('.log-toggle-btn');
    const icon = button?.querySelector('i');
    
    if (panel.style.display === 'none' || panel.style.display === '') {
        panel.style.display = 'block';
        if (button) {
            button.classList.add('active');
            button.querySelector('span').textContent = 'Masquer les détails';
        }
        if (icon) {
            icon.style.transform = 'rotate(180deg)';
        }
    } else {
        panel.style.display = 'none';
        if (button) {
            button.classList.remove('active');
            button.querySelector('span').textContent = 'Voir les détails';
        }
        if (icon) {
            icon.style.transform = 'rotate(0deg)';
        }
    }
}
</script>
                            <?php if ($has_details): ?>
                                <div class="log-details-panel" id="<?php echo $log_id; ?>" style="display: none;">
                                    <div class="log-diff-container">
                                        <div class="log-diff-header">
                                            <h4><i class="fas fa-code-branch"></i> Changements détaillés</h4>
                                            <small>Style GitHub - Rouge: supprimé, Vert: ajouté</small>
                                        </div>
                                        
                                        <?php 
                                        $old_data = $old_value ? json_decode($old_value, true) : null;
                                        $new_data = $new_value ? json_decode($new_value, true) : null;
                                        
                                        if ($old_data || $new_data):
                                            $all_keys = array_unique(array_merge(
                                                $old_data ? array_keys($old_data) : [],
                                                $new_data ? array_keys($new_data) : []
                                            ));
                                        ?>
                                            <div class="diff-view">
                                                <?php foreach ($all_keys as $key): ?>
                                                    <?php 
                                                    $old_value = $old_data[$key] ?? null;
                                                    $new_value = $new_data[$key] ?? null;
                                                    $field_label = formatFieldLabel($key);
                                                    
                                                    if ($old_value !== $new_value):
                                                    ?>
                                                        <div class="diff-field">
                                                            <div class="diff-field-header">
                                                                <i class="fas fa-edit"></i>
                                                                <strong><?php echo $field_label; ?></strong>
                                                            </div>
                                                            
                                                            <?php if ($old_value !== null): ?>
                                                                <div class="diff-line diff-removed">
                                                                    <span class="diff-marker">-</span>
                                                                    <span class="diff-content"><?php echo htmlspecialchars($old_value); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($new_value !== null): ?>
                                                                <div class="diff-line diff-added">
                                                                    <span class="diff-marker">+</span>
                                                                    <span class="diff-content"><?php echo htmlspecialchars($new_value); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="diff-no-changes">
                                                <i class="fas fa-info-circle"></i>
                                                <span>Aucun détail de changement disponible</span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Informations techniques -->
                                        <div class="log-technical-info">
                                            <h5><i class="fas fa-cog"></i> Informations techniques</h5>
                                            <div class="technical-details">
                                                <?php if (!empty($log['ip_address'])): ?>
                                                    <div class="tech-detail">
                                                        <i class="fas fa-map-marker-alt"></i>
                                                        <span><strong>Adresse IP:</strong> <?php echo htmlspecialchars($log['ip_address']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php if (!empty($user_agent)): ?>
                                                    <div class="tech-detail">
                                                        <i class="fas fa-desktop"></i>
                                                        <span><strong>Navigateur:</strong> <?php echo extractBrowserInfo($user_agent); ?></span>
                                                    </div>
                                                <?php elseif (!empty($log['ip_address'])): ?>
                                                    <div class="tech-detail">
                                                        <i class="fas fa-desktop"></i>
                                                        <span><strong>Navigateur:</strong> 🌐 Information non disponible</span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="tech-detail">
                                                    <i class="fas fa-fingerprint"></i>
                                                    <span><strong>ID de log:</strong> #<?php echo $log['id']; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <p>Aucune activité récente</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php 
    if (file_exists('../includes/footer.php')) {
        include '../includes/footer.php'; 
    }
    ?>

    <script>
        // Afficher/masquer le formulaire de création d'administrateur
        const showCreateFormBtn = document.getElementById('show-create-form-btn');
        const createAdminForm = document.getElementById('create-admin-form');
        const cancelCreateBtn = document.getElementById('cancel-create-btn');
        
        showCreateFormBtn.addEventListener('click', function() {
            createAdminForm.style.display = 'block';
            createAdminForm.classList.remove('hidden-section');
            showCreateFormBtn.style.display = 'none';
        });
        
        cancelCreateBtn.addEventListener('click', function() {
            createAdminForm.style.display = 'none';
            createAdminForm.classList.add('hidden-section');
            showCreateFormBtn.style.display = 'block';
        });
        
        // Fonction pour toggler les détails des logs
        function toggleLogDetails(logId) {
            const panel = document.getElementById(logId);
            const header = panel.previousElementSibling;
            const button = header.querySelector('.log-toggle-btn');
            const icon = button?.querySelector('i');
            
            if (panel.style.display === 'none' || panel.style.display === '') {
                panel.style.display = 'block';
                if (button) {
                    button.classList.add('active');
                    button.querySelector('span').textContent = 'Masquer les détails';
                }
                if (icon) {
                    icon.style.transform = 'rotate(180deg)';
                }
            } else {
                panel.style.display = 'none';
                if (button) {
                    button.classList.remove('active');
                    button.querySelector('span').textContent = 'Voir les détails';
                }
                if (icon) {
                    icon.style.transform = 'rotate(0deg)';
                }
            }
        }
        
        // Confirmation avant blocage/déblocage
        function confirmAction(event, action, adminId) {
            if (!confirm(`⚠️ Êtes-vous sûr de vouloir ${action} l'administrateur ${adminId} ?\n\nCette action affectera immédiatement l'accès à la plateforme.`)) {
                event.preventDefault();
                return false;
            }
            return true;
        }
        
        // Confirmation avant suppression
        function confirmDelete(event, adminId) {
            if (!confirm(`🚨 ATTENTION : Êtes-vous sûr de vouloir supprimer définitivement l'administrateur ${adminId} ?\n\n⚠️ Cette action est IRRÉVERSIBLE et supprimera :\n- Le compte administrateur\n- Toutes ses données associées\n- L'historique de ses actions\n\nTapez "SUPPRIMER" pour confirmer :`)) {
                event.preventDefault();
                return false;
            }
            
            const confirmation = prompt(`Pour confirmer la suppression de ${adminId}, tapez "SUPPRIMER" en majuscules :`);
            if (confirmation !== "SUPPRIMER") {
                event.preventDefault();
                alert("Suppression annulée. La confirmation n'était pas correcte.");
                return false;
            }
            
            return true;
        }
        
        // Validation du formulaire de création
        document.querySelector('form').addEventListener('submit', function(e) {
            const adminId = document.getElementById('new_admin_id').value;
            const password = document.getElementById('new_admin_password').value;
            
            // Validation de l'ID admin
            if (!/^[A-Z0-9_-]+$/.test(adminId)) {
                alert('⚠️ L\'identifiant doit contenir uniquement des lettres majuscules, chiffres, tirets et underscores.');
                e.preventDefault();
                return false;
            }
            
            // Validation du mot de passe
            if (password.length < 8) {
                alert('⚠️ Le mot de passe doit contenir au moins 8 caractères.');
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // Animation des cartes au chargement
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.admin-card, .stat-card, .card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Animation des logs
            const logItems = document.querySelectorAll('.log-item');
            logItems.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                
                setTimeout(() => {
                    item.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    item.style.opacity = '1';
                    item.style.transform = 'translateX(0)';
                }, (index * 50) + 500);
            });
        });
        
        // Auto-refresh des statistiques toutes les 30 secondes
        setInterval(function() {
            const now = new Date();
            const timestamp = now.getHours() + ':' + now.getMinutes().toString().padStart(2, '0');
            console.log(`📊 Statistiques mises à jour: ${timestamp}`);
        }, 30000);

        // Fonction pour marquer automatiquement les logs récents
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const logItems = document.querySelectorAll('.log-item');
            
            logItems.forEach(item => {
                const timeElement = item.querySelector('.log-time');
                if (timeElement) {
                    const timeText = timeElement.textContent;
                    // Ajouter un indicateur pour les actions très récentes (moins de 5 minutes)
                    const match = timeText.match(/(\d{2}):(\d{2}):(\d{2})/);
                    if (match) {
                        const logTime = new Date();
                        logTime.setHours(parseInt(match[1]), parseInt(match[2]), parseInt(match[3]));
                        
                        const timeDiff = (now - logTime) / 1000 / 60; // en minutes
                        if (timeDiff < 5 && timeDiff >= 0) {
                            const recentBadge = document.createElement('span');
                            recentBadge.innerHTML = ' <i class="fas fa-star" style="color: #FFD700; animation: pulse 1s infinite;"></i> <small>RÉCENT</small>';
                            timeElement.appendChild(recentBadge);
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>