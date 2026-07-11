<?php
/**
 * Bibliothèque de fonctions pour l'enregistrement des actions administratives
 * À placer dans le dossier includes/utils/
 */

/**
 * Enregistre une action administrative dans les logs
 * 
 * @param object $conn Connexion à la base de données
 * @param string $admin_id ID de l'administrateur
 * @param string $action_type Type d'action (CREATE, UPDATE, DELETE, etc.)
 * @param string $description Description de l'action
 * @param string $entity_type Type d'entité concernée (USER, COURSE, etc.) (optionnel)
 * @param string $entity_id ID de l'entité concernée (optionnel)
 * @return bool Succès ou échec de l'opération
 */
function logAdminAction($conn, $admin_id, $action_type, $description, $entity_type = null, $entity_id = null) {
    // Obtenir l'adresse IP et le user agent
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $sql = "INSERT INTO admin_logs (admin_id, action_type, description, entity_type, entity_id, ip_address, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssss", $admin_id, $action_type, $description, $entity_type, $entity_id, $ip_address);
    
    return $stmt->execute();
}

/**
 * Enregistre une connexion réussie
 * 
 * @param object $conn Connexion à la base de données
 * @param string $user_id ID de l'utilisateur
 * @return bool Succès ou échec de l'opération
 */
function logSuccessfulLogin($conn, $user_id) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $success = 1;
    
    $sql = "INSERT INTO user_logins (user_id, login_time, ip_address, user_agent, success) 
            VALUES (?, NOW(), ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $user_id, $ip_address, $user_agent, $success);
    
    // Mettre à jour la date de dernière connexion dans la table users
    if ($stmt->execute()) {
        $update_sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("s", $user_id);
        $update_stmt->execute();
        
        // Si c'est un admin, enregistrer également dans les logs d'admin
        $check_sql = "SELECT role FROM users WHERE id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $user_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $user_data = $result->fetch_assoc();
        
        if ($user_data && ($user_data['role'] === 'admin' || $user_data['role'] === 'super_admin')) {
            logAdminAction($conn, $user_id, 'LOGIN', 'Connexion au système', 'USER', $user_id);
        }
        
        return true;
    }
    
    return false;
}

/**
 * Enregistre une tentative de connexion échouée
 * 
 * @param object $conn Connexion à la base de données
 * @param string $username Nom d'utilisateur qui a tenté de se connecter
 * @param string $reason Raison de l'échec (mot de passe incorrect, compte inexistant, etc.)
 * @return bool Succès ou échec de l'opération
 */
function logFailedLogin($conn, $username, $reason = 'Identifiants incorrects') {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $success = 0;
    
    // Essayer de récupérer l'ID utilisateur s'il existe
    $user_id = null;
    $check_sql = "SELECT id FROM users WHERE email = ? OR id = ? LIMIT 1";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ss", $username, $username);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user_data = $result->fetch_assoc();
        $user_id = $user_data['id'];
    } else {
        $user_id = 'unknown';
    }
    
    $sql = "INSERT INTO user_logins (user_id, login_time, ip_address, user_agent, success) 
            VALUES (?, NOW(), ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $user_id, $ip_address, $user_agent, $success);
    
    return $stmt->execute();
}

/**
 * Récupère l'historique des actions d'un administrateur
 * 
 * @param object $conn Connexion à la base de données
 * @param string $admin_id ID de l'administrateur (optionnel, tous les administrateurs si null)
 * @param int $limit Nombre maximum d'enregistrements à récupérer
 * @param int $offset Décalage pour la pagination
 * @return array Tableau des actions
 */
function getAdminActions($conn, $admin_id = null, $limit = 100, $offset = 0) {
    $params = [];
    $types = "";
    
    if ($admin_id) {
        $sql = "SELECT a.*, u.name as admin_name, u.avatar as admin_avatar 
                FROM admin_logs a
                JOIN users u ON a.admin_id = u.id
                WHERE a.admin_id = ? 
                ORDER BY a.created_at DESC 
                LIMIT ? OFFSET ?";
        $params = [$admin_id, $limit, $offset];
        $types = "sii";
    } else {
        $sql = "SELECT a.*, u.name as admin_name, u.avatar as admin_avatar 
                FROM admin_logs a
                JOIN users u ON a.admin_id = u.id
                ORDER BY a.created_at DESC 
                LIMIT ? OFFSET ?";
        $params = [$limit, $offset];
        $types = "ii";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $actions = [];
    while ($row = $result->fetch_assoc()) {
        $actions[] = $row;
    }
    
    return $actions;
}

/**
 * Récupère l'historique des connexions d'un utilisateur
 * 
 * @param object $conn Connexion à la base de données
 * @param string $user_id ID de l'utilisateur
 * @param int $limit Nombre maximum d'enregistrements à récupérer
 * @param int $offset Décalage pour la pagination
 * @return array Tableau des connexions
 */
function getUserLogins($conn, $user_id, $limit = 10, $offset = 0) {
    $sql = "SELECT * FROM user_logins 
            WHERE user_id = ? 
            ORDER BY login_time DESC 
            LIMIT ? OFFSET ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sii", $user_id, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $logins = [];
    while ($row = $result->fetch_assoc()) {
        $logins[] = $row;
    }
    
    return $logins;
}

/**
 * Récupère les statistiques des actions par type pour un administrateur
 * 
 * @param object $conn Connexion à la base de données
 * @param string $admin_id ID de l'administrateur (optionnel, tous les administrateurs si null)
 * @param int $limit Nombre maximum de types d'actions à récupérer
 * @return array Tableau des statistiques par type d'action
 */
function getActionStatsByType($conn, $admin_id = null, $limit = 5) {
    $params = [];
    $types = "";
    
    if ($admin_id) {
        $sql = "SELECT action_type, COUNT(*) as count 
                FROM admin_logs 
                WHERE admin_id = ? 
                GROUP BY action_type 
                ORDER BY count DESC 
                LIMIT ?";
        $params = [$admin_id, $limit];
        $types = "si";
    } else {
        $sql = "SELECT action_type, COUNT(*) as count 
                FROM admin_logs 
                GROUP BY action_type 
                ORDER BY count DESC 
                LIMIT ?";
        $params = [$limit];
        $types = "i";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $stats = [];
    while ($row = $result->fetch_assoc()) {
        $stats[$row['action_type']] = $row['count'];
    }
    
    return $stats;
}

/**
 * Récupère les dernières actions de l'ensemble des administrateurs
 * 
 * @param object $conn Connexion à la base de données
 * @param int $limit Nombre maximum d'enregistrements à récupérer
 * @return array Tableau des dernières actions
 */
function getRecentAdminActions($conn, $limit = 10) {
    $sql = "SELECT a.*, u.name as admin_name, u.avatar as admin_avatar 
            FROM admin_logs a
            JOIN users u ON a.admin_id = u.id
            ORDER BY a.created_at DESC 
            LIMIT ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $actions = [];
    while ($row = $result->fetch_assoc()) {
        $actions[] = $row;
    }
    
    return $actions;
}

/**
 * Formatte une action administrative pour l'affichage
 * 
 * @param string $action_type Type d'action
 * @return array Tableau avec la classe CSS et l'icône correspondant au type d'action
 */
function formatActionType($action_type) {
    $types = [
        'CREATE'  => ['class' => 'create',  'icon' => 'fa-plus-circle'],
        'UPDATE'  => ['class' => 'update',  'icon' => 'fa-edit'],
        'DELETE'  => ['class' => 'delete',  'icon' => 'fa-trash'],
        'CANCEL'  => ['class' => 'cancel',  'icon' => 'fa-ban'],
        'PAYMENT' => ['class' => 'payment', 'icon' => 'fa-money-bill-wave'],
        'ASSIGN'  => ['class' => 'assign',  'icon' => 'fa-user-check'],
        'LOGIN'   => ['class' => 'login',   'icon' => 'fa-sign-in-alt'],
        'LOGOUT'  => ['class' => 'logout',  'icon' => 'fa-sign-out-alt'],
        'VIEW'    => ['class' => 'view',    'icon' => 'fa-eye'],
        'EXPORT'  => ['class' => 'export',  'icon' => 'fa-file-export'],
        'IMPORT'  => ['class' => 'import',  'icon' => 'fa-file-import'],
        'SEND'    => ['class' => 'send',    'icon' => 'fa-paper-plane'],
        'APPROVE' => ['class' => 'approve', 'icon' => 'fa-check-circle'],
        'REJECT'  => ['class' => 'reject',  'icon' => 'fa-times-circle'],
        'BLOCK'   => ['class' => 'block',   'icon' => 'fa-ban'],
        'UNBLOCK' => ['class' => 'unblock', 'icon' => 'fa-unlock'],
    ];
    
    // Si le type d'action est reconnu, retourner les informations correspondantes
    if (isset($types[strtoupper($action_type)])) {
        return $types[strtoupper($action_type)];
    }
    
    // Pour les types contenant un mot-clé connu
    foreach ($types as $key => $value) {
        if (strpos(strtoupper($action_type), $key) !== false) {
            return $value;
        }
    }
    
    // Type d'action non reconnu, retourner une valeur par défaut
    return ['class' => 'default', 'icon' => 'fa-cog'];
}