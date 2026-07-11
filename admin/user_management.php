<?php
// Démarrer la session
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/super_admin.php';

// Forcer UTF-8 et collation pour éviter les problèmes
$conn->set_charset('utf8mb4');
$conn->query("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");

$current_admin_id = $_SESSION['user_id'] ?? null;

// Vérifier si l'utilisateur est connecté et est un administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../pages/login.html");
    exit();
}

function log_admin_action($conn, $admin_id, $action_type, $description, $entity_id = null, $entity_type = null, $entity_name = null, $old_value = null, $new_value = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("
        INSERT INTO admin_logs 
        (admin_id, action_type, description, ip_address, entity_id, entity_type, entity_name, old_value, new_value, user_agent) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "ssssssssss",
        $admin_id,
        $action_type,
        $description,
        $ip_address,
        $entity_id,
        $entity_type,
        $entity_name,
        $old_value,
        $new_value,
        $user_agent
    );
    $stmt->execute();
    $stmt->close();
}

// ===== FONCTION DE VALIDATION AMÉLIORÉE =====
function validate_name($name) {
    $name = trim($name);
    
    if (empty($name)) {
        return ['valid' => false, 'error' => 'Le nom ne peut pas être vide.'];
    }
    
    if (strlen($name) < 2) {
        return ['valid' => false, 'error' => 'Le nom doit contenir au moins 2 caractères.'];
    }
    
    if (strlen($name) > 100) {
        return ['valid' => false, 'error' => 'Le nom ne peut pas dépasser 100 caractères.'];
    }
    
    if (!preg_match("/^[a-zA-Z0-9À-ÿ\s\-'\.]+$/u", $name)) {
        return ['valid' => false, 'error' => 'Le nom contient des caractères non autorisés. Seuls les lettres, chiffres, espaces, tirets, apostrophes et points sont acceptés.'];
    }
    
    if ($name !== strip_tags($name)) {
        return ['valid' => false, 'error' => 'Le nom contient des balises HTML non autorisées.'];
    }
    
    return ['valid' => true, 'name' => $name];
}

function validate_phone($phone) {
    if (empty($phone)) {
        return ['valid' => true, 'phone' => null];
    }
    
    $phone = trim($phone);
    
    if (!preg_match('/^[\+]?[0-9\s\-\(\)]{8,20}$/i', $phone)) {
        return ['valid' => false, 'error' => 'Format de téléphone invalide. Exemple : +241 01 23 45 67'];
    }
    
    return ['valid' => true, 'phone' => $phone];
}

function validate_user_id($user_id, $conn, $is_edit = false, $old_id = null) {
    $user_id = trim($user_id);
    
    if (empty($user_id)) {
        return ['valid' => false, 'error' => 'L\'ID utilisateur est obligatoire.'];
    }
    
    if (!preg_match('/^[A-Z0-9\-]{3,20}$/i', $user_id)) {
        return ['valid' => false, 'error' => 'L\'ID doit contenir entre 3 et 20 caractères (lettres, chiffres, tirets).'];
    }
    
    if (!$is_edit || ($is_edit && $user_id !== $old_id)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $stmt->close();
            return ['valid' => false, 'error' => 'Cet ID utilisateur existe déjà.'];
        }
        $stmt->close();
    }
    
    return ['valid' => true, 'id' => strtoupper($user_id)];
}

function validate_email($email, $conn, $is_edit = false, $old_email = null) {
    $email = trim(strtolower($email));
    
    if (empty($email)) {
        return ['valid' => false, 'error' => 'L\'email est obligatoire.'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['valid' => false, 'error' => 'Format d\'email invalide.'];
    }
    
    if (!$is_edit || ($is_edit && $email !== $old_email)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $stmt->close();
            return ['valid' => false, 'error' => 'Cet email est déjà utilisé.'];
        }
        $stmt->close();
    }
    
    return ['valid' => true, 'email' => $email];
}

function validate_password($password) {
    if (empty($password)) {
        return ['valid' => false, 'error' => 'Le mot de passe est obligatoire.'];
    }
    
    if (strlen($password) < 6) {
        return ['valid' => false, 'error' => 'Le mot de passe doit contenir au moins 6 caractères.'];
    }
    
    if (strlen($password) > 100) {
        return ['valid' => false, 'error' => 'Le mot de passe ne peut pas dépasser 100 caractères.'];
    }
    
    return ['valid' => true];
}

$error_message = "";
$error_user_id = null;
$success_message = "";
$success_user_id = null;

// Récupérer les messages flash de la session
if (isset($_SESSION['flash_success'])) {
    $success_message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (isset($_SESSION['flash_error'])) {
    $error_message = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ===== UPLOAD D'AVATAR =====
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['avatar']) && isset($_POST['avatar_user_id'])) {
    $user_id = $_POST['avatar_user_id'];
    $target_dir = "../uploads/avatars/";
    
    // Créer le dossier s'il n'existe pas
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file = $_FILES['avatar'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['error'] === 0) {
        if ($file['size'] <= $maxSize) {
            if (in_array($file['type'], $allowedTypes)) {
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $fileName = $user_id . '_' . time() . '.' . $extension;
                $uploadPath = $target_dir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    $stmt = $conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                    $stmt->bind_param("ss", $fileName, $user_id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['flash_success'] = "✅ Photo de profil mise à jour avec succès !";
                        
                        log_admin_action(
                            $conn,
                            $current_admin_id,
                            'update_avatar',
                            "Mise à jour de l'avatar de l'utilisateur $user_id",
                            $user_id,
                            'user',
                            null,
                            null,
                            $fileName
                        );
                    } else {
                        $_SESSION['flash_error'] = "⛔ Erreur lors de la mise à jour de l'avatar.";
                    }
                    $stmt->close();
                } else {
                    $_SESSION['flash_error'] = "⛔ Erreur lors de l'upload du fichier.";
                }
            } else {
                $_SESSION['flash_error'] = "⛔ Type de fichier non autorisé. Utilisez JPG, PNG, GIF ou WEBP.";
            }
        } else {
            $_SESSION['flash_error'] = "⛔ Le fichier est trop volumineux. Maximum 5MB.";
        }
    }
    
    header("Location: user_management.php");
    exit();
}

// ===== SUPPRIMER UN UTILISATEUR =====
if (isset($_GET['delete'])) {
    $user_id = $_GET['delete'];

    $stmt = $conn->prepare("SELECT role, name FROM users WHERE id = ?");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_role = $row['role'];
        $user_name = $row['name'];

        // Protection: seul un super admin peut supprimer un admin
        if ($user_role === 'admin' && !is_super_admin($conn, $current_admin_id)) {
            $_SESSION['flash_error'] = "⛔ Seul un super administrateur peut supprimer un administrateur.";
        } 
        // Protection: impossible de se supprimer soi-même
        elseif ($user_id === $current_admin_id) {
            $_SESSION['flash_error'] = "⛔ Vous ne pouvez pas supprimer votre propre compte.";
        } else {
            // Supprimer l'utilisateur
            $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $delete_stmt->bind_param("s", $user_id);

            if ($delete_stmt->execute()) {
                $_SESSION['flash_success'] = "✅ Utilisateur supprimé avec succès.";

                log_admin_action(
                    $conn,
                    $current_admin_id,
                    'delete_user',
                    "Suppression de l'utilisateur $user_id ($user_name)",
                    $user_id,
                    $user_role,
                    $user_name,
                    json_encode($row),
                    null
                );
            } else {
                $_SESSION['flash_error'] = "⛔ Erreur lors de la suppression : " . $conn->error;
            }
            $delete_stmt->close();
        }
    } else {
        $_SESSION['flash_error'] = "⚠️ Utilisateur introuvable.";
    }
    $stmt->close();
    
    header("Location: user_management.php");
    exit();
}

// ===== AJOUTER UN UTILISATEUR =====
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    
    $user_id = trim($_POST['user_id']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    $class_id = $_POST['class_id'];
    
    if ($role !== 'student' || empty($class_id)) {
        $class_id = null;
    }
    
    if ($role === 'admin' && !is_super_admin($conn, $current_admin_id)) {
        $error_message = "⛔ Seul un super administrateur peut ajouter un autre administrateur.";
    } else {
        
        $id_check = validate_user_id($user_id, $conn);
        if (!$id_check['valid']) {
            $error_message = "⚠️ " . $id_check['error'];
        } else {
            $user_id = $id_check['id'];
            
            $name_check = validate_name($name);
            if (!$name_check['valid']) {
                $error_message = "⚠️ " . $name_check['error'];
            } else {
                $name = $name_check['name'];
                
                $email_check = validate_email($email, $conn);
                if (!$email_check['valid']) {
                    $error_message = "⚠️ " . $email_check['error'];
                } else {
                    $email = $email_check['email'];
                    
                    $phone_check = validate_phone($phone);
                    if (!$phone_check['valid']) {
                        $error_message = "⚠️ " . $phone_check['error'];
                    } else {
                        $phone = $phone_check['phone'];
                        
                        $password_check = validate_password($password);
                        if (!$password_check['valid']) {
                            $error_message = "⚠️ " . $password_check['error'];
                        } else {
                            
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            
                            if ($role === 'student' && !empty($class_id)) {
                                $check_class = $conn->prepare("SELECT id FROM classes WHERE id = ?");
                                $check_class->bind_param("i", $class_id);
                                $check_class->execute();
                                $class_result = $check_class->get_result();
                                
                                if ($class_result->num_rows === 0) {
                                    $error_message = "⚠️ La classe sélectionnée n'existe pas. Veuillez choisir une classe valide.";
                                } else {
                                    $stmt = $conn->prepare("INSERT INTO users (id, name, email, phone, password, role, class_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->bind_param("ssssssi", $user_id, $name, $email, $phone, $hashed_password, $role, $class_id);
                                    
                                    if ($stmt->execute()) {
                                        $success_message = "✅ Nouvel utilisateur ajouté avec succès.";
                                        
                                        log_admin_action(
                                            $conn,
                                            $current_admin_id,
                                            'add_user',
                                            "Ajout de l'utilisateur $user_id ($name)",
                                            $user_id,
                                            'user',
                                            $name,
                                            null,
                                            json_encode([
                                                'id' => $user_id,
                                                'name' => $name,
                                                'email' => $email,
                                                'phone' => $phone,
                                                'role' => $role,
                                                'class_id' => $class_id
                                            ])
                                        );
                                        
                                        $_SESSION['flash_success'] = $success_message;
                                        header("Location: user_management.php");
                                        exit();
                                    } else {
                                        $error_message = "⛔ Erreur lors de l'ajout : " . $conn->error;
                                    }
                                    $stmt->close();
                                }
                                $check_class->close();
                            } else {
                                $stmt = $conn->prepare("INSERT INTO users (id, name, email, phone, password, role, class_id) VALUES (?, ?, ?, ?, ?, ?, NULL)");
                                $stmt->bind_param("ssssss", $user_id, $name, $email, $phone, $hashed_password, $role);
                                
                                if ($stmt->execute()) {
                                    $success_message = "✅ Nouvel utilisateur ajouté avec succès.";
                                    
                                    log_admin_action(
                                        $conn,
                                        $current_admin_id,
                                        'add_user',
                                        "Ajout de l'utilisateur $user_id ($name)",
                                        $user_id,
                                        'user',
                                        $name,
                                        null,
                                        json_encode([
                                            'id' => $user_id,
                                            'name' => $name,
                                            'email' => $email,
                                            'phone' => $phone,
                                            'role' => $role,
                                            'class_id' => null
                                        ])
                                    );
                                    
                                    $_SESSION['flash_success'] = $success_message;
                                    header("Location: user_management.php");
                                    exit();
                                } else {
                                    $error_message = "⛔ Erreur lors de l'ajout : " . $conn->error;
                                }
                                $stmt->close();
                            }
                        }
                    }
                }
            }
        }
    }
}

// BLOQUER un utilisateur
if (isset($_GET['block'])) {
    $user_id = $_GET['block'];

    $stmt = $conn->prepare("SELECT role, name FROM users WHERE id = ?");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_role = $row['role'];
        $user_name = $row['name'];

        if ($user_role === 'admin' && !is_super_admin($conn, $current_admin_id)) {
            $error_message = "⛔ Seul un super administrateur peut bloquer un administrateur.";
            $error_user_id = $user_id;
        } else {
            $update_stmt = $conn->prepare("UPDATE users SET blocked = 1 WHERE id = ?");
            $update_stmt->bind_param("s", $user_id);

            if ($update_stmt->execute()) {
                $success_message = "✅ Utilisateur bloqué avec succès.";
                $success_user_id = $user_id;

                log_admin_action(
                    $conn,
                    $current_admin_id,
                    'block_user',
                    "Blocage de l'utilisateur $user_id ($user_name)",
                    $user_id,
                    $user_role,
                    $user_name,
                    null,
                    json_encode(['blocked' => 1])
                );
            } else {
                $error_message = "⛔ Erreur lors du blocage : " . $conn->error;
                $error_user_id = $user_id;
            }
            $update_stmt->close();
        }
    } else {
        $error_message = "⚠️ Utilisateur introuvable.";
        $error_user_id = $user_id;
    }
    $stmt->close();
}

// DEBLOQUER un utilisateur
if (isset($_GET['unblock'])) {
    $user_id = $_GET['unblock'];

    $stmt = $conn->prepare("SELECT role, name FROM users WHERE id = ?");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_role = $row['role'];
        $user_name = $row['name'];

        if ($user_role === 'admin' && !is_super_admin($conn, $current_admin_id)) {
            $error_message = "⛔ Seul un super administrateur peut débloquer un administrateur.";
            $error_user_id = $user_id;
        } else {
            $update_stmt = $conn->prepare("UPDATE users SET blocked = 0 WHERE id = ?");
            $update_stmt->bind_param("s", $user_id);

            if ($update_stmt->execute()) {
                $success_message = "✅ Utilisateur débloqué avec succès.";
                $success_user_id = $user_id;

                log_admin_action(
                    $conn,
                    $current_admin_id,
                    'unblock_user',
                    "Déblocage de l'utilisateur $user_id ($user_name)",
                    $user_id,
                    $user_role,
                    $user_name,
                    null,
                    json_encode(['blocked' => 0])
                );
            } else {
                $error_message = "⛔ Erreur lors du déblocage : " . $conn->error;
                $error_user_id = $user_id;
            }
            $update_stmt->close();
        }
    } else {
        $error_message = "⚠️ Utilisateur introuvable.";
        $error_user_id = $user_id;
    }
    $stmt->close();
}

// ===== MISE À JOUR D'UN UTILISATEUR =====
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_user'])) {
    $user_id = $_POST['user_id'];
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $role = $_POST['role'];
    $class_id = ($role === 'student') ? $_POST['class_id'] : null;

    $old_stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $old_stmt->bind_param("s", $user_id);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result()->fetch_assoc();
    $old_stmt->close();

    $stmt_check = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt_check->bind_param("s", $user_id);
    $stmt_check->execute();
    $stmt_check->bind_result($existing_role);
    $stmt_check->fetch();
    $stmt_check->close();

    if (($existing_role === 'admin' || $role === 'admin') && !is_super_admin($conn, $current_admin_id)) {
        $error_message = "⛔ Vous n'êtes pas autorisé à modifier un administrateur. Seul un super administrateur a ce droit.";
        $error_user_id = $user_id;
    } else {
        
        $name_check = validate_name($name);
        if (!$name_check['valid']) {
            $error_message = "⚠️ " . $name_check['error'];
            $error_user_id = $user_id;
        } else {
            $name = $name_check['name'];
            
            $email_check = validate_email($email, $conn, true, $old_result['email']);
            if (!$email_check['valid']) {
                $error_message = "⚠️ " . $email_check['error'];
                $error_user_id = $user_id;
            } else {
                $email = $email_check['email'];
                
                $phone_check = validate_phone($phone);
                if (!$phone_check['valid']) {
                    $error_message = "⚠️ " . $phone_check['error'];
                    $error_user_id = $user_id;
                } else {
                    $phone = $phone_check['phone'];
                    
                    if ($role === 'student' && empty($class_id)) {
                        $error_message = "⚠️ Veuillez spécifier une classe pour l'étudiant.";
                        $error_user_id = $user_id;
                    } else {
                        
                        if ($role !== 'student') {
                            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, class_id=NULL WHERE id=?");
                            $stmt->bind_param("sssss", $name, $email, $phone, $role, $user_id);
                        } else {
                            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, role=?, class_id=? WHERE id=?");
                            $stmt->bind_param("ssssss", $name, $email, $phone, $role, $class_id, $user_id);
                        }

                        if ($stmt->execute()) {
                            $success_message = "✅ Utilisateur modifié avec succès.";
                            $success_user_id = $user_id;
                            
                            log_admin_action(
                                $conn,
                                $current_admin_id,
                                'edit_user',
                                "Modification de l'utilisateur $user_id",
                                $user_id,
                                'user',
                                $name,
                                json_encode($old_result),
                                json_encode([
                                    'id' => $user_id,
                                    'name' => $name,
                                    'email' => $email,
                                    'phone' => $phone,
                                    'role' => $role,
                                    'class_id' => $class_id
                                ])
                            );
                            
                            $_SESSION['flash_success'] = $success_message;
                            header("Location: user_management.php");
                            exit();
                        } else {
                            $error_message = "⛔ Erreur lors de la modification : " . $conn->error;
                            $error_user_id = $user_id;
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }
}

// ===== PAGINATION ET RECHERCHE =====
$order_by = 'name';
if (isset($_GET['order_by'])) {
    $allowed_columns = ['id', 'name', 'email', 'phone', 'role', 'class_name', 'blocked'];
    if (in_array($_GET['order_by'], $allowed_columns)) {
        $order_by = $_GET['order_by'];
        $_SESSION['order_by'] = $order_by;
    }
} elseif (isset($_SESSION['order_by'])) {
    $order_by = $_SESSION['order_by'];
}

$search = isset($_GET['search']) ? $conn->real_escape_string(trim($_GET['search'])) : '';

// Compter le nombre total d'utilisateurs (pour la pagination)
$count_sql = "SELECT COUNT(*) as total FROM users u";
if (!empty($search)) {
    $count_sql .= " WHERE u.name LIKE '%$search%' OR u.email LIKE '%$search%' OR u.phone LIKE '%$search%' OR u.id LIKE '%$search%'";
}
$count_result = $conn->query($count_sql);
$total_users = $count_result->fetch_assoc()['total'];

// Configuration de la pagination
$users_per_page = 100;
$total_pages = ceil($total_users / $users_per_page);
$current_page = isset($_GET['page']) ? max(1, min((int)$_GET['page'], $total_pages)) : 1;
$offset = ($current_page - 1) * $users_per_page;

// Récupérer les utilisateurs avec pagination + toutes les colonnes issues de l'inscription
$sql = "
    SELECT
        u.id, u.name, u.nom, u.prenom, u.email, u.phone, u.address,
        u.birth_date, u.birth_place, u.place_of_birth,
        u.sexe, u.nationalite,
        u.bac_serie, u.bac_annee,
        u.tuteur_nom, u.tuteur_lien, u.tuteur_adresse, u.tuteur_telephone,
        u.urgence_nom, u.urgence_lien, u.urgence_adresse, u.urgence_telephone,
        u.dernier_diplome, u.diplome_serie, u.diplome_annee, u.etablissement_origine,
        u.niveau, u.regime, u.specialite, u.exp_pro, u.domaine_pro, u.mode_paiement,
        u.role, u.status, u.blocked, u.avatar, u.class_id,
        u.created_at, u.last_login,
        c.name AS class_name
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
";

if (!empty($search)) {
    $sql .= " WHERE u.name LIKE '%$search%' OR u.email LIKE '%$search%' OR u.phone LIKE '%$search%' OR u.id LIKE '%$search%'";
}

$sql .= " ORDER BY $order_by LIMIT $users_per_page OFFSET $offset";
$result = $conn->query($sql);

// Récupérer les classes
$class_sql = "SELECT * FROM classes ORDER BY name";
$class_result = $conn->query($class_sql);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Utilisateurs - Tableau de Bord Administrateur</title>
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
        }

        body {
            margin: 0;
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
            width: 100%;
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

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        h1 {
            font-size: 24px;
            color: var(--accent-color);
            margin: 0 0 20px 0;
            text-align: center;
        }

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
            position: relative;
        }

        nav a::before {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--accent-color);
            transform: scaleX(0);
            transition: transform 0.3s ease;
            transform-origin: right;
        }

        nav a:hover::before {
            transform: scaleX(1);
            transform-origin: left;
        }

        nav a:hover {
            background: rgba(3, 155, 229, 0.1);
        }

        main {
            padding: 20px;
            flex: 1;
            max-width: 1600px;
            margin: 0 auto;
            width: 100%;
            box-sizing: border-box;
        }

        main h2 {
            margin-bottom: 20px;
            color: var(--accent-color);
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
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .error-message {
            background: rgba(231, 76, 60, 0.15);
            border-left: 4px solid var(--error-color);
            color: var(--error-color);
        }

        .success-message {
            background: rgba(46, 204, 113, 0.15);
            border-left: 4px solid var(--success-color);
            color: var(--success-color);
        }

        form {
            margin-bottom: 30px;
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }

        form h3 {
            margin-bottom: 15px;
            color: var(--accent-color);
        }

        .form-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: var(--text-light);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border-radius: 5px;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            box-sizing: border-box;
            transition: border 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 5px rgba(3, 155, 229, 0.3);
        }

        .form-group input.invalid {
            border-color: var(--error-color);
            background: rgba(231, 76, 60, 0.1);
        }

        .form-group input.valid {
            border-color: var(--success-color);
        }

        .form-group .error-hint {
            font-size: 12px;
            color: var(--error-color);
            margin-top: 5px;
            display: none;
        }

        .form-group .success-hint {
            font-size: 12px;
            color: var(--success-color);
            margin-top: 5px;
            display: none;
        }

        .btn {
            background: var(--accent-color);
            color: var(--text-light);
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
            font-weight: bold;
        }

        .btn:hover {
            background: #0288d1;
        }

        .btn-danger {
            background: var(--error-color);
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-success {
            background: var(--success-color);
        }

        .btn-success:hover {
            background: #27ae60;
        }

        .search-container {
            display: flex;
            gap: 10px;
            align-items: end;
            margin-bottom: 20px;
        }

        .search-container .form-group {
            flex: 1;
            max-width: 400px;
        }

        .search-spinner {
            display: none;
            margin-left: 10px;
            color: var(--accent-color);
        }

        .sort-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .sort-container label {
            font-weight: bold;
        }

        .table-container {
            overflow-x: auto;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background: var(--secondary-bg);
            padding: 12px;
            text-align: left;
            color: var(--text-light);
            font-weight: bold;
            white-space: nowrap;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-light);
            vertical-align: middle;
        }

        td input[type="text"],
        td input[type="email"],
        td select {
            width: 100%;
            padding: 5px;
            border-radius: 3px;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            margin: 0;
            box-sizing: border-box;
        }

        td button {
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            transition: background 0.3s;
            font-size: 12px;
            margin-right: 5px;
        }

        .status-active {
            color: var(--success-color);
            font-weight: bold;
        }

        .status-blocked {
            color: var(--error-color);
            font-weight: bold;
        }

        .actions {
            white-space: nowrap;
        }

        .actions a {
            text-decoration: none;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
            margin-left: 5px;
            transition: background 0.3s;
        }

        .actions .block-link {
            background: var(--error-color);
            color: white;
        }

        .actions .unblock-link {
            background: var(--success-color);
            color: white;
        }

        .actions .delete-link {
            background: #c0392b;
            color: white;
        }

        .actions a:hover {
            opacity: 0.8;
        }

        /* Avatar dans le tableau */
        .avatar-cell {
            text-align: center;
        }

        .avatar-mini {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent-color);
            cursor: pointer;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .avatar-mini:hover {
            transform: scale(1.3);
            box-shadow: 0 0 20px rgba(3, 155, 229, 0.7);
            z-index: 10;
        }

        /* Modal pour voir le profil */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.8);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: linear-gradient(145deg, var(--secondary-bg), var(--primary-bg));
            margin: 3% auto;
            padding: 40px;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            width: 90%;
            max-width: 1000px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.7);
            animation: slideDown 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
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
            color: var(--text-light);
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close:hover,
        .close:focus {
            color: var(--accent-color);
        }

        .profile-header {
            text-align: center;
            padding-bottom: 30px;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 30px;
        }

        .profile-avatar-large {
            width: 300px;
            height: 300px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid var(--accent-color);
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(3, 155, 229, 0.5);
            transition: transform 0.3s ease;
        }

        .profile-avatar-large:hover {
            transform: scale(1.05);
        }

        .profile-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            transition: transform 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.2);
        }

        .info-card-label {
            color: var(--accent-color);
            font-weight: bold;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-card-value {
            color: var(--text-light);
            font-size: 1.1rem;
        }

        .avatar-upload-form {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            text-align: center;
        }

        .avatar-upload-btn {
            display: inline-block;
            padding: 10px 20px;
            background: var(--accent-color);
            color: white;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .avatar-upload-btn:hover {
            background: #0288d1;
            transform: translateY(-2px);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 5px;
            color: var(--text-light);
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            background: var(--accent-color);
            border-color: var(--accent-color);
        }

        .pagination .current-page {
            background: var(--accent-color);
            border-color: var(--accent-color);
            font-weight: bold;
        }

        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-info {
            text-align: center;
            margin-top: 10px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }
            
            .search-container {
                flex-direction: column;
                align-items: stretch;
            }
            
            table {
                font-size: 14px;
            }
            
            th, td {
                padding: 8px;
            }

            .modal-content {
                width: 95%;
                margin: 10% auto;
            }

            .profile-info-grid {
                grid-template-columns: 1fr;
            }

            .avatar-mini {
                width: 60px;
                height: 60px;
            }

            .profile-avatar-large {
                width: 250px;
                height: 250px;
            }
        }

        option {
            background: var(--secondary-bg);
            color: var(--text-light);
        }

        .table-message {
            background: rgba(231, 76, 60, 0.1);
            color: var(--error-color);
            font-style: italic;
            text-align: center;
        }

        .table-success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success-color);
            font-style: italic;
            text-align: center;
        }

        .password-strength {
            margin-top: 5px;
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            transition: width 0.3s ease, background 0.3s ease;
            width: 0%;
        }

        .strength-weak { background: var(--error-color); width: 33%; }
        .strength-medium { background: var(--warning-color); width: 66%; }
        .strength-strong { background: var(--success-color); width: 100%; }

        /* ★ Bouton impression fiche ISMM */
        .print-fiche-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0a1c2e, #1a3a5c);
            color: white;
            border: none;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            transition: all 0.2s;
            title: "Imprimer la fiche ISMM";
        }
        .print-fiche-btn:hover {
            background: linear-gradient(135deg, #ff9500, #ff8c00);
            transform: scale(1.1);
            box-shadow: 0 3px 10px rgba(255,149,0,0.4);
            color: white;
        }

        .view-profile-btn {
            background: var(--accent-color);
            color: white;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-left: 5px;
        }

        .view-profile-btn:hover {
            background: #0288d1;
            transform: translateY(-2px);
        }

        .profile-section-title {
            color: var(--accent-color);
            font-size: 1.3rem;
            margin: 30px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
        }

        .last-login-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.9rem;
            margin-top: 5px;
        }

        .last-login-recent {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
        }

        .last-login-old {
            background: rgba(243, 156, 18, 0.2);
            color: var(--warning-color);
        }

        .last-login-never {
            background: rgba(231, 76, 60, 0.2);
            color: var(--error-color);
        }
    </style>
</head>
<body>
    
<?php include '../includes/header.php'; ?>

<main>
    <h2><i class="fas fa-users"></i> Gestion des Utilisateurs</h2>

    <!-- Messages globaux -->
    <?php if (!empty($success_message) && $success_user_id === null): ?>
        <div class="message success-message">
            <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message) && $error_user_id === null): ?>
        <div class="message error-message">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <!-- Formulaire d'ajout -->
    <form method="POST" id="addUserForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
        <h3><i class="fas fa-user-plus"></i> Ajouter un nouvel utilisateur</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label for="user_id">ID Utilisateur * <small>(Lettres, chiffres, tirets uniquement)</small></label>
                <input type="text" id="user_id" name="user_id" placeholder="Ex: UAS-STU-001" required pattern="[A-Za-z0-9\-]{3,20}">
                <div class="error-hint" id="user_id_error"></div>
                <div class="success-hint" id="user_id_success">✓ Format valide</div>
            </div>
            <div class="form-group">
                <label for="name">Nom complet * <small>(Lettres, chiffres, espaces, tirets, apostrophes)</small></label>
                <input type="text" id="name" name="name" placeholder="Ex: Jean-Pierre 4, Marie O'Connor" required>
                <div class="error-hint" id="name_error"></div>
                <div class="success-hint" id="name_success">✓ Nom valide</div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" placeholder="email@exemple.com" required>
                <div class="error-hint" id="email_error"></div>
                <div class="success-hint" id="email_success">✓ Email valide</div>
            </div>
            <div class="form-group">
                <label for="phone">Téléphone <small>(Format: +241 XX XX XX XX)</small></label>
                <input type="text" id="phone" name="phone" placeholder="+241 01 23 45 67" pattern="[\+]?[0-9\s\-\(\)]{8,20}">
                <div class="error-hint" id="phone_error"></div>
                <div class="success-hint" id="phone_success">✓ Format valide</div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="password">Mot de passe * <small>(Min. 6 caractères)</small></label>
                <div class="password-input-wrapper">
                    <input type="password" 
                           id="password" 
                           name="password" 
                           placeholder="Mot de passe" 
                           required 
                           minlength="6">
                    <button type="button" 
                            class="password-toggle" 
                            onclick="togglePasswordVisibility('password')"
                            title="Afficher/Masquer le mot de passe">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="password-strength">
                    <div class="password-strength-bar" id="password_strength_bar"></div>
                </div>
                <div class="error-hint" id="password_error"></div>
                <div class="success-hint" id="password_success">✓ Mot de passe acceptable</div>
            </div>
            <div class="form-group">
                <label for="role">Rôle *</label>
                <select id="role" name="role" required>
                    <option value="student">Étudiant</option>
                    <option value="teacher">Enseignant</option>
                    <option value="admin">Administrateur</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="class_id">Classe <small>(Obligatoire pour étudiants)</small></label>
                <select id="class_id" name="class_id">
                    <option value="">Sélectionner une classe</option>
                    <?php
                    if ($class_result) {
                        $class_result->data_seek(0);
                        while ($class = $class_result->fetch_assoc()) {
                            echo "<option value='{$class['id']}'>{$class['name']}</option>";
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" name="add_user" class="btn">
                    <i class="fas fa-plus"></i> Ajouter Utilisateur
                </button>
            </div>
        </div>
    </form>

    <!-- Barre de recherche intelligente améliorée -->
    <div class="search-container">
        <div class="form-group" style="flex: 1;">
            <label for="search">
                Rechercher un utilisateur 
                <small id="searchHint" style="font-weight: normal;">(Tapez au moins 2 caractères)</small>
            </label>
            <div style="position: relative;">
                <input type="text" id="search" name="search" 
                       placeholder="Rechercher par nom, email, téléphone ou ID..." 
                       value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>"
                       autocomplete="off"
                       style="padding-right: 40px;">
                <i class="fas fa-search" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: var(--accent-color); opacity: 0.5; pointer-events: none;"></i>
            </div>
        </div>
        <div class="search-status">
            <i class="fas fa-spinner fa-spin search-spinner" id="searchSpinner" style="display: none; color: var(--accent-color);"></i>
            <span id="searchStatus" style="font-size: 12px; color: rgba(255,255,255,0.7);"></span>
        </div>
        <?php if (!empty($_GET['search'])): ?>
            <a href="user_management.php" class="btn btn-danger" title="Effacer la recherche et afficher tous les utilisateurs">
                <i class="fas fa-times"></i> Effacer
            </a>
        <?php endif; ?>
        <button type="button" id="searchBtn" class="btn" title="Lancer la recherche manuellement" style="display: none;">
            <i class="fas fa-search"></i> Rechercher
        </button>
    </div>

    <!-- Tri -->
    <div class="sort-container">
        <label for="sort">Trier par :</label>
        <form method="GET" style="display: inline;">
            <?php if (!empty($_GET['search'])): ?>
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($_GET['search']); ?>">
            <?php endif; ?>
            <?php if (isset($_GET['page'])): ?>
                <input type="hidden" name="page" value="<?php echo htmlspecialchars($_GET['page']); ?>">
            <?php endif; ?>
            <select name="order_by" onchange="this.form.submit()">
                <option value="id" <?php echo $order_by == 'id' ? 'selected' : ''; ?>>ID</option>
                <option value="name" <?php echo $order_by == 'name' ? 'selected' : ''; ?>>Nom</option>
                <option value="email" <?php echo $order_by == 'email' ? 'selected' : ''; ?>>Email</option>
                <option value="phone" <?php echo $order_by == 'phone' ? 'selected' : ''; ?>>Téléphone</option>
                <option value="role" <?php echo $order_by == 'role' ? 'selected' : ''; ?>>Rôle</option>
                <option value="class_name" <?php echo $order_by == 'class_name' ? 'selected' : ''; ?>>Classe</option>
                <option value="blocked" <?php echo $order_by == 'blocked' ? 'selected' : ''; ?>>Statut</option>
            </select>
        </form>
    </div>

    <!-- Informations de pagination -->
    <div class="pagination-info">
        Affichage des utilisateurs <?php echo $offset + 1; ?> à <?php echo min($offset + $users_per_page, $total_users); ?> sur <?php echo $total_users; ?> au total
    </div>

    <!-- Liste des utilisateurs -->
    <div class="table-container">
        <h3><i class="fas fa-list"></i> Liste des Utilisateurs (<?php echo $result->num_rows; ?> sur cette page)</h3>
        
        <table>
            <thead>
                <tr>
                    <th>Avatar</th>
                    <th>ID</th>
                    <th>Nom</th>
                    <th>Email</th>
                    <th>Téléphone</th>
                    <th>Rôle</th>
                    <th>Classe</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="userTableBody">
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()) : ?>
                        <tr>
                            <form method="POST" style="display:contents;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                <td class="avatar-cell">
                                    <?php
                                    $av = $row['avatar'] ?? '';
                                    if (empty($av)) {
                                        $av_src = '../uploads/avatars/default_avatar.png';
                                    } elseif (strpos($av, 'uploads/') === 0) {
                                        $av_src = '../' . $av;
                                    } else {
                                        $av_src = '../uploads/avatars/' . $av;
                                    }
                                    ?>
                                    <img src="<?php echo htmlspecialchars($av_src); ?>" 
                                         alt="Avatar" 
                                         class="avatar-mini"
                                         onclick="openProfileModal('<?php echo htmlspecialchars($row['id']); ?>')">
                                </td>
                                <td>
                                    <input type="text" name="user_id" value="<?php echo htmlspecialchars($row['id']); ?>" readonly style="background: rgba(255,255,255,0.05); cursor: not-allowed;">
                                </td>
                                <td>
                                    <input type="text" name="name" value="<?php echo htmlspecialchars($row['name']); ?>" required 
                                           pattern="[a-zA-Z0-9À-ÿ\s\-'\.]{2,100}" title="Lettres, chiffres, espaces, tirets, apostrophes uniquement">
                                </td>
                                <td>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($row['email']); ?>" required>
                                </td>
                                <td>
                                    <input type="text" name="phone" value="<?php echo htmlspecialchars($row['phone'] ?? ''); ?>" 
                                           placeholder="Téléphone" pattern="[\+]?[0-9\s\-\(\)]{8,20}">
                                </td>
                                <td>
                                    <select name="role" required>
                                        <option value="student" <?php echo $row['role'] == 'student' ? 'selected' : ''; ?>>Étudiant</option>
                                        <option value="teacher" <?php echo $row['role'] == 'teacher' ? 'selected' : ''; ?>>Enseignant</option>
                                        <option value="admin" <?php echo $row['role'] == 'admin' ? 'selected' : ''; ?>>Administrateur</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="class_id">
                                        <option value="">Aucune classe</option>
                                        <?php
                                        if ($class_result) {
                                            $class_result->data_seek(0);
                                            while ($class = $class_result->fetch_assoc()) {
                                                $selected = ($class['id'] == $row['class_id']) ? 'selected' : '';
                                                echo "<option value='{$class['id']}' $selected>{$class['name']}</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </td>
                                <td>
                                    <?php if ($row['blocked']): ?>
                                        <span class="status-blocked"><i class="fas fa-ban"></i> Bloqué</span>
                                    <?php else: ?>
                                        <span class="status-active"><i class="fas fa-check-circle"></i> Actif</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <!-- Bouton Voir Profil -->
                                    <button type="button" class="view-profile-btn" 
                                            onclick="openProfileModal('<?php echo htmlspecialchars($row['id']); ?>')" 
                                            title="Voir le profil complet">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <!-- ★ Bouton Imprimer la fiche ISMM (étudiants seulement) -->
                                    <?php if ($row['role'] === 'student'): ?>
                                    <a href="print_fiche.php?student_id=<?php echo urlencode($row['id']); ?>"
                                       target="_blank"
                                       class="print-fiche-btn"
                                       title="Imprimer / Télécharger la fiche d'inscription ISMM">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <?php endif; ?>

                                    <!-- Bouton Enregistrer -->
                                    <button type="submit" name="edit_user" class="btn" title="Enregistrer les modifications">
                                        <i class="fas fa-save"></i>
                                    </button>
                                    
                                    <!-- Bouton Bloquer/Débloquer -->
                                    <?php if ($row['blocked']): ?>
                                        <a href="?unblock=<?php echo urlencode($row['id']); ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['page']) ? '&page=' . intval($_GET['page']) : ''; ?>" 
                                           class="unblock-link" title="Débloquer"
                                           onclick="return confirm('✅ Êtes-vous sûr de vouloir débloquer cet utilisateur ?')">
                                            <i class="fas fa-unlock"></i>
                                        </a>
                                    <?php else: ?>
                                        <a href="?block=<?php echo urlencode($row['id']); ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['page']) ? '&page=' . intval($_GET['page']) : ''; ?>" 
                                           class="block-link" title="Bloquer"
                                           onclick="return confirm('⚠️ Êtes-vous sûr de vouloir bloquer cet utilisateur ?\n\nCette action empêchera l\'utilisateur de se connecter.')">
                                            <i class="fas fa-lock"></i>
                                        </a>
                                    <?php endif; ?>
                                    
                                    <!-- Bouton Supprimer -->
                                    <?php if ($row['id'] !== $current_admin_id): ?>
                                        <a href="?delete=<?php echo urlencode($row['id']); ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['page']) ? '&page=' . intval($_GET['page']) : ''; ?>" 
                                           class="delete-link" title="Supprimer"
                                           onclick="return confirm('🗑️ ATTENTION : Cette action est IRRÉVERSIBLE !\n\nÊtes-vous ABSOLUMENT SÛR de vouloir supprimer l\'utilisateur :\n\n👤 <?php echo htmlspecialchars($row['name']); ?>\n📧 <?php echo htmlspecialchars($row['email']); ?>\n\nToutes les données associées seront perdues définitivement.')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </form>
                        </tr>
                        
                        <!-- Messages spécifiques à l'utilisateur -->
                        <?php if ($error_user_id == $row['id']): ?>
                            <tr>
                                <td colspan="9" class="table-message">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error_message; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        
                        <?php if ($success_user_id == $row['id']): ?>
                            <tr>
                                <td colspan="9" class="table-success">
                                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; font-style: italic; color: #ccc; padding: 40px;">
                            <i class="fas fa-info-circle" style="font-size: 48px; display: block; margin-bottom: 15px;"></i>
                            <?php if (!empty($_GET['search'])): ?>
                                Aucun utilisateur trouvé pour la recherche "<?php echo htmlspecialchars($_GET['search']); ?>".
                            <?php else: ?>
                                Aucun utilisateur trouvé.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <!-- Bouton Première page -->
            <?php if ($current_page > 1): ?>
                <a href="?page=1<?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo !empty($_GET['order_by']) ? '&order_by=' . $_GET['order_by'] : ''; ?>" title="Première page">
                    <i class="fas fa-angle-double-left"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="fas fa-angle-double-left"></i></span>
            <?php endif; ?>

            <!-- Bouton Page précédente -->
            <?php if ($current_page > 1): ?>
                <a href="?page=<?php echo $current_page - 1; ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo !empty($_GET['order_by']) ? '&order_by=' . $_GET['order_by'] : ''; ?>" title="Page précédente">
                    <i class="fas fa-angle-left"></i> Précédent
                </a>
            <?php else: ?>
                <span class="disabled"><i class="fas fa-angle-left"></i> Précédent</span>
            <?php endif; ?>

            <!-- Numéros de pages -->
            <?php
            $start_page = max(1, $current_page - 2);
            $end_page = min($total_pages, $current_page + 2);
            
            // Afficher "..." au début si nécessaire
            if ($start_page > 1) {
                echo '<a href="?page=1' . (!empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '') . (!empty($_GET['order_by']) ? '&order_by=' . $_GET['order_by'] : '') . '">1</a>';
                if ($start_page > 2) {
                    echo '<span>...</span>';
                }
            }
            
            // Afficher les numéros de pages
            for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i == $current_page): ?>
                    <span class="current-page"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?page=<?php echo $i; ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo !empty($_GET['order_by']) ? '&order_by=' . $_GET['order_by'] : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endif; ?>
            <?php endfor;
            
            // Afficher "..." à la fin si nécessaire
            if ($end_page < $total_pages) {
                if ($end_page < $total_pages - 1) {
                    echo '<span>...</span>';
                }
                echo '<a href="?page=' . $total_pages . (!empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '') . (!empty($_GET['order_by']) ? '&order_by=' . $_GET['order_by'] : '') . '">' . $total_pages . '</a>';
            }
            ?>

            <!-- Bouton Page suivante -->
            <?php if ($current_page < $total_pages): ?>
                <a href="?page=<?php echo $current_page + 1; ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo !empty($_GET['order_by']) ? '&order_by=' . $_GET['order_by'] : ''; ?>" title="Page suivante">
                    Suivant <i class="fas fa-angle-right"></i>
                </a>
            <?php else: ?>
                <span class="disabled">Suivant <i class="fas fa-angle-right"></i></span>
            <?php endif; ?>

            <!-- Bouton Dernière page -->
            <?php if ($current_page < $total_pages): ?>
                <a href="?page=<?php echo $total_pages; ?><?php echo !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo !empty($_GET['order_by']) ? '&order_by=' . $_GET['order_by'] : ''; ?>" title="Dernière page">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php else: ?>
                <span class="disabled"><i class="fas fa-angle-double-right"></i></span>
            <?php endif; ?>
        </div>
        
        <!-- Info pagination détaillée -->
        <div class="pagination-info">
            Page <?php echo $current_page; ?> sur <?php echo $total_pages; ?> 
            (<?php echo $users_per_page; ?> utilisateurs par page)
        </div>
    <?php endif; ?>

</main>

<!-- Modal pour voir le profil -->
<div id="profileModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeProfileModal()">&times;</span>
        <div id="profileContent">
            <!-- Contenu chargé dynamiquement -->
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>';
// ===== TOGGLE MOT DE PASSE =====
function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    const icon  = input ? input.parentElement.querySelector('.fa-eye, .fa-eye-slash') : null;
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
    } else {
        input.type = 'password';
        if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
    }
}

// ===== RECHERCHE AUTOMATIQUE =====
// ===== SYSTÈME DE RECHERCHE INTELLIGENT (4 SECONDES) =====
let searchTimeout = null;
let isSearching = false;
const searchInput = document.getElementById('search');
const searchSpinner = document.getElementById('searchSpinner');
const searchStatus = document.getElementById('searchStatus');
const searchHint = document.getElementById('searchHint');
const searchBtn = document.getElementById('searchBtn');

// Configuration
const SEARCH_DELAY = 4000; // Délai de base : 4 secondes
const MIN_SEARCH_LENGTH = 2;

// Fonction pour effectuer la recherche
function performSearch(searchValue) {
    if (isSearching) return;
    
    isSearching = true;
    searchSpinner.style.display = 'inline-block';
    searchStatus.textContent = 'Recherche en cours...';
    searchStatus.style.color = 'var(--accent-color)';
    searchInput.style.borderColor = 'var(--accent-color)';
    
    let url = new URL(window.location.href);
    url.searchParams.set('page', '1');
    
    if (searchValue.length > 0) {
        url.searchParams.set('search', searchValue);
    } else {
        url.searchParams.delete('search');
    }
    
    window.location.href = url.toString();
}

// Détecte les caractères spéciaux
function isTypingSpecialChar(value) {
    const lastChar = value.slice(-1);
    const specialChars = ['@', '-', '_', '.', '+', "'", '"', '(', ')', '[', ']', '/'];
    return specialChars.includes(lastChar);
}

// Détecte si la valeur semble complète
function seemsComplete(value) {
    if (value.endsWith(' ')) return true;
    if (value.includes('@') && value.indexOf('.', value.indexOf('@')) > value.indexOf('@')) return true;
    if (/^[A-Z0-9]+-[A-Z0-9]+-[A-Z0-9]+$/i.test(value.trim())) return true;
    const digitCount = (value.match(/\d/g) || []).length;
    if (digitCount >= 10 && digitCount <= 15) return true;
    return false;
}

// Événement principal sur le champ de recherche
searchInput.addEventListener('input', function(e) {
    clearTimeout(searchTimeout);
    
    const searchValue = this.value.trim();
    const rawValue = this.value;
    
    searchSpinner.style.display = 'none';
    searchInput.style.borderColor = 'var(--border-color)';
    searchStatus.textContent = '';
    
    // Champ vide
    if (searchValue.length === 0) {
        searchHint.textContent = '(Tapez au moins 2 caractères)';
        searchHint.style.color = 'rgba(255,255,255,0.5)';
        searchBtn.style.display = 'none';
        
        if (rawValue.length === 0 && window.location.search.includes('search=')) {
            searchStatus.textContent = 'Effacement...';
            searchTimeout = setTimeout(() => performSearch(''), 400);
        }
        return;
    }
    
    // Pas assez de caractères
    if (searchValue.length < MIN_SEARCH_LENGTH) {
        const remaining = MIN_SEARCH_LENGTH - searchValue.length;
        searchHint.textContent = `(${remaining} caractère${remaining > 1 ? 's' : ''} restant${remaining > 1 ? 's' : ''})`;
        searchHint.style.color = 'var(--warning-color)';
        searchBtn.style.display = 'none';
        searchStatus.textContent = '';
        return;
    }
    
    // Afficher le bouton de recherche manuelle
    searchBtn.style.display = 'inline-block';
    searchHint.textContent = '(Recherche automatique dans quelques instants...)';
    searchHint.style.color = 'var(--accent-color)';
    searchInput.style.borderColor = 'var(--warning-color)';
    
    // Déterminer le délai
    let delay = SEARCH_DELAY;
    let statusMessage = 'Recherche dans 4 secondes...';
    
    // Caractère spécial : +1 seconde
    if (isTypingSpecialChar(rawValue)) {
        delay = 5000;
        statusMessage = 'Caractère spécial détecté, recherche dans 5s...';
        searchStatus.style.color = 'var(--warning-color)';
    }
    
    // Valeur complète : réduction à 1.5s
    if (seemsComplete(searchValue)) {
        delay = 1500;
        statusMessage = 'Valeur complète détectée, recherche dans 1.5s...';
        searchStatus.style.color = 'var(--success-color)';
        searchInput.style.borderColor = 'var(--success-color)';
    }
    
    searchStatus.textContent = statusMessage;
    
    searchTimeout = setTimeout(() => performSearch(searchValue), delay);
});

// Bouton de recherche manuelle
if (searchBtn) {
    searchBtn.addEventListener('click', function() {
        clearTimeout(searchTimeout);
        const searchValue = searchInput.value.trim();
        if (searchValue.length >= MIN_SEARCH_LENGTH || searchValue.length === 0) {
            performSearch(searchValue);
        } else {
            searchStatus.textContent = 'Minimum 2 caractères requis';
            searchStatus.style.color = 'var(--error-color)';
        }
    });
}

// Recherche sur Enter
searchInput.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(searchTimeout);
        const searchValue = this.value.trim();
        if (searchValue.length >= MIN_SEARCH_LENGTH || searchValue.length === 0) {
            searchStatus.textContent = 'Recherche via Enter...';
            performSearch(searchValue);
        } else {
            searchStatus.textContent = 'Minimum 2 caractères requis';
            searchStatus.style.color = 'var(--error-color)';
        }
    }
});

// Empêcher la soumission multiple
searchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && isSearching) {
        e.preventDefault();
    }
});

// Feedback visuel au focus
searchInput.addEventListener('focus', function() {
    if (this.value.trim().length >= MIN_SEARCH_LENGTH) {
        searchBtn.style.display = 'inline-block';
    }
});

console.log('✅ Système de recherche activé (délai : 4 secondes)');
console.log('⚙️ Config : Base=4s, Spécial=5s, Complet=1.5s');

// ===== VALIDATION EN TEMPS RÉEL =====

// Validation de l'ID utilisateur
const userIdInput = document.getElementById('user_id');
const userIdError = document.getElementById('user_id_error');
const userIdSuccess = document.getElementById('user_id_success');

userIdInput.addEventListener('input', function() {
    const value = this.value.trim();
    const pattern = /^[A-Za-z0-9\-]{3,20}$/;
    
    if (value.length === 0) {
        this.classList.remove('valid', 'invalid');
        userIdError.style.display = 'none';
        userIdSuccess.style.display = 'none';
    } else if (!pattern.test(value)) {
        this.classList.add('invalid');
        this.classList.remove('valid');
        userIdError.textContent = '⚠ 3-20 caractères (lettres, chiffres, tirets uniquement)';
        userIdError.style.display = 'block';
        userIdSuccess.style.display = 'none';
    } else {
        this.classList.add('valid');
        this.classList.remove('invalid');
        userIdError.style.display = 'none';
        userIdSuccess.style.display = 'block';
    }
});

// Validation du nom
const nameInput = document.getElementById('name');
const nameError = document.getElementById('name_error');
const nameSuccess = document.getElementById('name_success');

nameInput.addEventListener('input', function() {
    const value = this.value.trim();
    const pattern = /^[a-zA-Z0-9À-ÿ\s\-'\.]{2,100}$/;
    
    if (value.length === 0) {
        this.classList.remove('valid', 'invalid');
        nameError.style.display = 'none';
        nameSuccess.style.display = 'none';
    } else if (value.length < 2) {
        this.classList.add('invalid');
        this.classList.remove('valid');
        nameError.textContent = '⚠ Le nom doit contenir au moins 2 caractères';
        nameError.style.display = 'block';
        nameSuccess.style.display = 'none';
    } else if (!pattern.test(value)) {
        this.classList.add('invalid');
        this.classList.remove('valid');
        nameError.textContent = '⚠ Seuls les lettres, chiffres, espaces, tirets et apostrophes sont acceptés';
        nameError.style.display = 'block';
        nameSuccess.style.display = 'none';
    } else {
        this.classList.add('valid');
        this.classList.remove('invalid');
        nameError.style.display = 'none';
        nameSuccess.style.display = 'block';
    }
});

// Validation de l'email
const emailInput = document.getElementById('email');
const emailError = document.getElementById('email_error');
const emailSuccess = document.getElementById('email_success');

emailInput.addEventListener('input', function() {
    const value = this.value.trim();
    const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    
    if (value.length === 0) {
        this.classList.remove('valid', 'invalid');
        emailError.style.display = 'none';
        emailSuccess.style.display = 'none';
    } else if (!pattern.test(value)) {
        this.classList.add('invalid');
        this.classList.remove('valid');
        emailError.textContent = '⚠ Format d\'email invalide';
        emailError.style.display = 'block';
        emailSuccess.style.display = 'none';
    } else {
        this.classList.add('valid');
        this.classList.remove('invalid');
        emailError.style.display = 'none';
        emailSuccess.style.display = 'block';
    }
});

// Validation du téléphone
const phoneInput = document.getElementById('phone');
const phoneError = document.getElementById('phone_error');
const phoneSuccess = document.getElementById('phone_success');

phoneInput.addEventListener('input', function() {
    const value = this.value.trim();
    const pattern = /^[\+]?[0-9\s\-\(\)]{8,20}$/;
    
    if (value.length === 0) {
        this.classList.remove('valid', 'invalid');
        phoneError.style.display = 'none';
        phoneSuccess.style.display = 'none';
    } else if (!pattern.test(value)) {
        this.classList.add('invalid');
        this.classList.remove('valid');
        phoneError.textContent = '⚠ Format invalide (Ex: +241 01 23 45 67)';
        phoneError.style.display = 'block';
        phoneSuccess.style.display = 'none';
    } else {
        this.classList.add('valid');
        this.classList.remove('invalid');
        phoneError.style.display = 'none';
        phoneSuccess.style.display = 'block';
    }
});

// Validation du mot de passe avec force
const passwordInput = document.getElementById('password');
const passwordError = document.getElementById('password_error');
const passwordSuccess = document.getElementById('password_success');
const passwordStrengthBar = document.getElementById('password_strength_bar');

passwordInput.addEventListener('input', function() {
    const value = this.value;
    
    if (value.length === 0) {
        this.classList.remove('valid', 'invalid');
        passwordError.style.display = 'none';
        passwordSuccess.style.display = 'none';
        passwordStrengthBar.className = 'password-strength-bar';
    } else if (value.length < 6) {
        this.classList.add('invalid');
        this.classList.remove('valid');
        passwordError.textContent = '⚠ Minimum 6 caractères requis';
        passwordError.style.display = 'block';
        passwordSuccess.style.display = 'none';
        passwordStrengthBar.className = 'password-strength-bar strength-weak';
    } else {
        let strength = 0;
        if (value.length >= 8) strength++;
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) strength++;
        if (/[0-9]/.test(value)) strength++;
        if (/[^a-zA-Z0-9]/.test(value)) strength++;
        
        this.classList.add('valid');
        this.classList.remove('invalid');
        passwordError.style.display = 'none';
        passwordSuccess.style.display = 'block';
        
        if (strength <= 1) {
            passwordStrengthBar.className = 'password-strength-bar strength-weak';
            passwordSuccess.textContent = '✓ Faible - Ajoutez majuscules, chiffres ou symboles';
        } else if (strength <= 2) {
            passwordStrengthBar.className = 'password-strength-bar strength-medium';
            passwordSuccess.textContent = '✓ Moyen - Bon mot de passe';
        } else {
            passwordStrengthBar.className = 'password-strength-bar strength-strong';
            passwordSuccess.textContent = '✓ Fort - Excellent mot de passe !';
        }
    }
});

// Gestion conditionnelle de la classe selon le rôle
const roleSelect = document.getElementById('role');
const classSelect = document.getElementById('class_id');

roleSelect.addEventListener('change', function() {
    if (this.value === 'student') {
        classSelect.required = true;
        classSelect.parentElement.style.opacity = '1';
        classSelect.parentElement.querySelector('label small').style.color = 'var(--warning-color)';
    } else {
        classSelect.required = false;
        classSelect.value = '';
        classSelect.parentElement.style.opacity = '0.5';
        classSelect.parentElement.querySelector('label small').style.color = '#999';
    }
});

// Initialiser l'état du formulaire au chargement
roleSelect.dispatchEvent(new Event('change'));

// Auto-fermeture des messages après 10 secondes
setTimeout(function() {
    const messages = document.querySelectorAll('.message');
    messages.forEach(function(message) {
        message.style.transition = 'opacity 0.5s ease';
        message.style.opacity = '0';
        setTimeout(function() {
            message.style.display = 'none';
        }, 500);
    });
}, 10000);

// ===== GESTION DU MODAL DE PROFIL =====
function openProfileModal(userId) {
    const modal = document.getElementById('profileModal');
    const profileContent = document.getElementById('profileContent');
    
    // Afficher un loader
    profileContent.innerHTML = '<div style="text-align: center; padding: 50px;"><i class="fas fa-spinner fa-spin fa-3x"></i><p>Chargement du profil...</p></div>';
    modal.style.display = 'block';
    
    // Charger les données via AJAX
    fetch(`get_user_profile.php?user_id=${encodeURIComponent(userId)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayUserProfile(data.user);
            } else {
                profileContent.innerHTML = '<div style="text-align: center; padding: 50px; color: var(--error-color);"><i class="fas fa-exclamation-triangle fa-3x"></i><p>' + data.message + '</p></div>';
            }
        })
        .catch(error => {
            profileContent.innerHTML = '<div style="text-align: center; padding: 50px; color: var(--error-color);"><i class="fas fa-times-circle fa-3x"></i><p>Erreur lors du chargement du profil</p></div>';
        });
}

function avatarSrc(avatar) {
    if (!avatar || avatar === 'default_avatar.png') return '../uploads/avatars/default_avatar.png';
    if (avatar.startsWith('uploads/')) return '../' + avatar;
    return '../uploads/avatars/' + avatar;
}

function displayUserProfile(user) {
    const profileContent = document.getElementById('profileContent');
    
    const roleLabels = {
        'student': '<i class="fas fa-user-graduate"></i> Étudiant',
        'teacher': '<i class="fas fa-chalkboard-teacher"></i> Enseignant',
        'admin': '<i class="fas fa-user-shield"></i> Administrateur'
    };
    
    const statusLabel = user.blocked == 1 
        ? '<span class="status-blocked"><i class="fas fa-ban"></i> Bloqué</span>' 
        : '<span class="status-active"><i class="fas fa-check-circle"></i> Actif</span>';
    
    const lastLoginClass = user.last_login_badge_class || 'last-login-never';

    function infoCard(icon, label, value) {
        if (!value) return '';
        return `<div class="info-card">
            <div class="info-card-label"><i class="fas fa-${icon}"></i> ${label}</div>
            <div class="info-card-value">${value}</div>
        </div>`;
    }
    function fileCard(icon, label, path) {
        if (!path) return '';
        return `<div class="info-card">
            <div class="info-card-label"><i class="fas fa-${icon}"></i> ${label}</div>
            <div class="info-card-value">
                <a href="../${path}" target="_blank" style="color:var(--accent-color);text-decoration:none;">
                    <i class="fas fa-external-link-alt"></i> Voir le fichier
                </a>
            </div>
        </div>`;
    }

    const html = `
        <div class="profile-header">
            <img src="${avatarSrc(user.avatar)}" alt="Avatar" class="profile-avatar-large">
            <h2>${user.name}</h2>
            <p>${roleLabels[user.role] || user.role}</p>
            <p>${statusLabel}</p>
        </div>

        <!-- Boutons fiche ISMM -->
        ${user.role === 'student' ? `
        <div style="margin:12px 0;display:flex;gap:10px;flex-wrap:wrap;">
            <a href="print_fiche.php?student_id=${encodeURIComponent(user.id)}" target="_blank"
               style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;
                      background:linear-gradient(135deg,#ff9500,#ff8c00);color:white;
                      border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;">
                <i class="fas fa-print"></i> Imprimer la fiche ISMM
            </a>
            <a href="print_fiche.php?student_id=${encodeURIComponent(user.id)}&auto=1" target="_blank"
               style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;
                      background:rgba(255,255,255,0.1);color:white;border:1px solid rgba(255,255,255,0.2);
                      border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;">
                <i class="fas fa-file-pdf"></i> Télécharger (impression auto)
            </a>
        </div>` : ''}

        <!-- 1. IDENTIFICATION -->
        <h3 class="profile-section-title"><i class="fas fa-id-card"></i> 1. Identification</h3>
        <div class="profile-info-grid">
            ${infoCard('hashtag',       'ID Utilisateur',    user.id)}
            ${infoCard('user',          'Nom complet',       user.name)}
            ${infoCard('font',          'Nom de famille',    user.nom)}
            ${infoCard('font',          'Prénom(s)',         user.prenom)}
            ${infoCard('birthday-cake', 'Date de naissance', user.birth_date_formatted || '')}
            ${infoCard('map-pin',       'Lieu de naissance', user.birth_place)}
            ${infoCard('venus-mars',    'Sexe',              user.sexe === 'M' ? 'Masculin' : user.sexe === 'F' ? 'Féminin' : user.sexe)}
            ${infoCard('flag',          'Nationalité',       user.nationalite)}
        </div>

        <!-- 2. CONTACT -->
        <h3 class="profile-section-title"><i class="fas fa-phone"></i> 2. Contact</h3>
        <div class="profile-info-grid">
            ${infoCard('envelope',      'Email',             user.email)}
            ${infoCard('phone',         'Téléphone',         user.phone)}
            ${infoCard('map-marker-alt','Adresse',           user.address)}
            ${infoCard('graduation-cap','Bac série',         user.bac_serie)}
            ${infoCard('calendar',      'Année Bac',         user.bac_annee)}
            ${infoCard('user-tie',      'Tuteur légal',      user.tuteur_nom ? user.tuteur_nom + (user.tuteur_lien ? ' ('+user.tuteur_lien+')' : '') : '')}
            ${infoCard('map-pin',       'Adresse tuteur',    user.tuteur_adresse)}
            ${infoCard('phone',         'Tél. tuteur',       user.tuteur_telephone)}
        </div>

        <!-- 3. URGENCE -->
        <h3 class="profile-section-title"><i class="fas fa-ambulance"></i> 3. Urgence</h3>
        <div class="profile-info-grid">
            ${infoCard('user',          'Contact urgence',   user.urgence_nom ? user.urgence_nom + (user.urgence_lien ? ' ('+user.urgence_lien+')' : '') : '')}
            ${infoCard('map-pin',       'Adresse urgence',   user.urgence_adresse)}
            ${infoCard('phone',         'Tél. urgence',      user.urgence_telephone)}
        </div>

        <!-- 4. ACADÉMIQUE -->
        <h3 class="profile-section-title"><i class="fas fa-graduation-cap"></i> 4. Informations académiques</h3>
        <div class="profile-info-grid">
            ${infoCard('certificate',   'Dernier diplôme',   user.dernier_diplome)}
            ${infoCard('book',          'Série / Filière',   user.diplome_serie)}
            ${infoCard('calendar',      'Année diplôme',     user.diplome_annee)}
            ${infoCard('university',    'Établissement',     user.etablissement_origine)}
        </div>

        <!-- 5. FORMATION -->
        <h3 class="profile-section-title"><i class="fas fa-book-open"></i> 5. Formation choisie</h3>
        <div class="profile-info-grid">
            ${infoCard('graduation-cap','Classe',            user.class_name)}
            ${user.candidature && user.candidature.niveau ? infoCard('layer-group','Niveau', user.candidature.niveau) : ''}
            ${infoCard('sync-alt',      'Régime',            user.regime)}
            ${user.candidature && user.candidature.specialite ? infoCard('book','Spécialité', user.candidature.specialite_label || user.candidature.specialite) : ''}
            ${user.candidature && user.candidature.exp_pro ? infoCard('briefcase','Expérience pro', user.candidature.exp_pro) : ''}
            ${user.candidature && user.candidature.domaine_pro ? infoCard('industry','Domaine pro', user.candidature.domaine_pro) : ''}
            ${user.candidature && user.candidature.ville ? infoCard('city','Ville', user.candidature.ville) : ''}
        </div>

        <!-- 6. PIÈCES & PAIEMENT -->
        ${user.candidature ? `
        <h3 class="profile-section-title"><i class="fas fa-folder-open"></i> 6. Dossier &amp; Documents</h3>
        <div class="profile-info-grid">
            ${user.candidature.ref_dossier ? infoCard('hashtag','Réf. dossier', user.candidature.ref_dossier) : ''}
            ${user.candidature.mode_paiement ? infoCard('credit-card','Mode de paiement', user.candidature.mode_paiement_label || user.candidature.mode_paiement) : ''}
            ${fileCard('receipt',           'Preuve de paiement',           user.candidature.preuve_paiement)}
            ${fileCard('file-alt',          'Acte de naissance',            user.candidature.acte_naissance_path || user.candidature.cni_path)}
            ${fileCard('award',             'Diplôme',                      user.candidature.diplome_path)}
            ${fileCard('list-ol',           'Relevé de notes',              user.candidature.releve_notes_path || user.candidature.cv_path)}
            ${fileCard('id-badge',          "Photos d'identité",           user.candidature.photos_path || user.candidature.lettre_path)}
            ${fileCard('briefcase',         "Attestation d'emploi",        user.candidature.attestation_emploi_path)}
        </div>
        ` : ''}

        <!-- Connexion -->
        <h3 class="profile-section-title"><i class="fas fa-clock"></i> Connexion</h3>
        <div class="profile-info-grid">
            ${infoCard('calendar-plus', "Date d'inscription",  user.created_at_formatted || '')}
            ${infoCard('sign-in-alt',   'Dernière connexion',  user.last_login_formatted ? user.last_login_formatted + ' (' + user.last_login_relative + ')' : 'Jamais connecté')}
            ${infoCard('user-check',    'Statut',              user.status === 'active' ? 'Actif' : 'Inactif')}
        </div>

        <!-- Parcours académique (étudiants seulement) -->
        ${user.role === 'student' ? `
        <h3 class="profile-section-title" style="display:flex;align-items:center;justify-content:space-between">
            <span><i class="fas fa-route"></i> Parcours académique</span>
            <button onclick="loadParcours('${user.id}')" id="btn-parcours-${user.id}"
                    style="padding:4px 12px;border:none;border-radius:6px;background:rgba(3,155,229,.2);
                           color:#039be5;font-size:.78rem;font-weight:600;cursor:pointer">
                <i class="fas fa-history"></i> Charger
            </button>
        </h3>
        <div id="parcours-content-${user.id}"
             style="color:rgba(255,255,255,.45);font-style:italic;font-size:.88rem;padding:8px 0 16px">
            Cliquez sur « Charger » pour afficher le parcours.
        </div>` : ''}

        <div class="avatar-upload-form">
            <h3><i class="fas fa-camera"></i> Modifier la photo de profil</h3>
            <form method="POST" enctype="multipart/form-data" id="avatarUploadForm">
                <input type="hidden" name="csrf_token" value="${CSRF_TOKEN}">
                <input type="hidden" name="avatar_user_id" value="${user.id}">
                <label for="avatarInput_${user.id}" class="avatar-upload-btn">
                    <i class="fas fa-upload"></i> Choisir une nouvelle photo
                </label>
                <input type="file" 
                       id="avatarInput_${user.id}" 
                       name="avatar" 
                       accept="image/*" 
                       style="display: none;"
                       onchange="document.getElementById('avatarUploadForm').submit();">
            </form>
            <p style="margin-top: 10px; font-size: 0.9rem; color: rgba(255,255,255,0.7);">
                Formats acceptés: JPG, PNG, GIF, WEBP (Max. 5MB)
            </p>
        </div>
    `;
    
    profileContent.innerHTML = html;
}

function closeProfileModal() {
    const modal = document.getElementById('profileModal');
    modal.style.display = 'none';
}

/* ── Parcours académique ── */
async function loadParcours(userId) {
    const btn = document.getElementById('btn-parcours-' + userId);
    const box = document.getElementById('parcours-content-' + userId);
    if (!btn || !box) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner" style="animation:spin 1s linear infinite;display:inline-block"></i> Chargement…';
    box.innerHTML = '';

    try {
        const resp = await fetch('get_student_parcours.php?student_id=' + encodeURIComponent(userId));
        const data = await resp.json();

        if (!data.success || !data.history || !data.history.length) {
            box.innerHTML = '<p style="color:rgba(255,255,255,.4);font-style:italic;font-size:.85rem">Aucun historique de parcours disponible.</p>';
            btn.style.display = 'none';
            return;
        }

        const statusMeta = {
            en_cours:   { color: '#039be5', icon: 'circle',           label: 'En cours' },
            admis:      { color: '#2ecc71', icon: 'check-circle',     label: 'Admis' },
            redoublant: { color: '#f39c12', icon: 'redo',             label: 'Redoublant' },
            transfere:  { color: '#3498db', icon: 'exchange-alt',     label: 'Transféré' },
            abandonne:  { color: '#e74c3c', icon: 'times-circle',     label: 'Abandonné' },
        };

        let html = '<div style="padding:4px 0 8px;display:flex;flex-direction:column;gap:0">';
        data.history.forEach((h, i) => {
            const meta   = statusMeta[h.status] || { color: '#888', icon: 'question-circle', label: h.status };
            const isLast = i === data.history.length - 1;
            const period = h.end_date
                ? `${h.start_date} → ${h.end_date}`
                : `depuis ${h.start_date}`;

            html += `
            <div style="display:flex;gap:0;align-items:stretch">
                <div style="display:flex;flex-direction:column;align-items:center;width:28px;flex-shrink:0">
                    <div style="width:14px;height:14px;border-radius:50%;background:${meta.color};margin-top:4px;flex-shrink:0;box-shadow:0 0 0 3px rgba(0,0,0,.4)"></div>
                    ${!isLast ? `<div style="width:2px;background:rgba(255,255,255,.1);flex:1;margin:3px 0"></div>` : ''}
                </div>
                <div style="padding:2px 12px 14px;flex:1">
                    <div style="font-size:.78rem;color:rgba(255,255,255,.45);margin-bottom:2px">${h.academic_year}</div>
                    <div style="font-weight:600;font-size:.88rem">
                        ${h.filiere_code ? `<span style="background:${meta.color}22;color:${meta.color};padding:1px 6px;border-radius:4px;font-size:.72rem;margin-right:5px">${h.filiere_code}</span>` : ''}
                        ${h.class_name}
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:3px;font-size:.78rem">
                        <span style="color:${meta.color}">
                            <i class="fas fa-${meta.icon}"></i> ${meta.label}
                        </span>
                        <span style="color:rgba(255,255,255,.35)">${period}</span>
                    </div>
                    ${h.notes ? `<div style="font-size:.75rem;color:rgba(255,255,255,.45);margin-top:3px;font-style:italic">${h.notes}</div>` : ''}
                </div>
            </div>`;
        });
        html += '</div>';
        html += `<a href="passage_classe.php" style="display:inline-flex;align-items:center;gap:5px;
                    font-size:.78rem;color:#039be5;text-decoration:none;margin-top:4px">
                    <i class="fas fa-level-up-alt"></i> Gérer le passage en classe
                 </a>`;

        box.innerHTML = html;
        btn.style.display = 'none';
    } catch(e) {
        box.innerHTML = `<p style="color:#e74c3c;font-size:.85rem">Erreur : ${e.message}</p>`;
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-history"></i> Réessayer';
    }
}

/* ── Keyframe pour le spin ── */
if (!document.getElementById('spin-style')) {
    const st = document.createElement('style');
    st.id = 'spin-style';
    st.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(st);
}

// Fermer le modal en cliquant à l'extérieur
window.onclick = function(event) {
    const modal = document.getElementById('profileModal');
    if (event.target == modal) {
        closeProfileModal();
    }
}
</script>

<?php include '../includes/footer.php'; ?>

<?php
$conn->close();
?>
</body>
</html>