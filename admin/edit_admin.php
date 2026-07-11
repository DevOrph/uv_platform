<?php
require_once '../includes/db_connect.php';

// Activer l'affichage des erreurs pour le débogage
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$user_id = $_SESSION['user_id'];

// Vérifier si un ID d'administrateur est spécifié
if (!isset($_GET['id'])) {
    header("Location: manage_admins.php");
    exit();
}

$admin_id = $_GET['id'];

// Inclure la bibliothèque de journalisation
require_once '../includes/utils/admin_logger.php';

// Récupérer les informations de l'administrateur à éditer
$sql = "SELECT * FROM users WHERE id = ? AND role = 'admin'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $admin_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: manage_admins.php");
    exit();
}

$admin = $result->fetch_assoc();

// Traitement du formulaire d'édition
$success_message = "";
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_admin'])) {
    $new_name = trim($_POST['name']);
    $new_email = trim($_POST['email']);
    $new_phone = trim($_POST['phone']);
    $new_password = trim($_POST['new_password'] ?? '');
    $reset_password = isset($_POST['reset_password']) ? true : false;
    
    // Vérifier si l'email est déjà utilisé par un autre utilisateur
    $check_email = "SELECT id FROM users WHERE email = ? AND id != ?";
    $stmt = $conn->prepare($check_email);
    $stmt->bind_param("ss", $new_email, $admin_id);
    $stmt->execute();
    $email_result = $stmt->get_result();
    
    if ($email_result->num_rows > 0) {
        $error_message = "Cette adresse email est déjà utilisée par un autre utilisateur.";
    } else {
        // Préparation de la requête de mise à jour
        $update_sql = "UPDATE users SET name = ?, email = ?, phone = ?";
        $params = [$new_name, $new_email, $new_phone];
        $types = "sss";
        
        // Si un nouveau mot de passe est fourni ou si une réinitialisation est demandée
        if (!empty($new_password) || $reset_password) {
            $password_to_set = $reset_password ? "Admin123!" : $new_password;
            $hashed_password = password_hash($password_to_set, PASSWORD_DEFAULT);
            
            $update_sql .= ", password = ?";
            $params[] = $hashed_password;
            $types .= "s";
        }
        
        $update_sql .= " WHERE id = ?";
        $params[] = $admin_id;
        $types .= "s";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            logAdminAction($conn, $user_id, 'UPDATE_ADMIN', "Modification de l'administrateur $admin_id", 'USER', $admin_id);
            
            // Si le mot de passe a été réinitialisé
            if ($reset_password) {
                logAdminAction($conn, $user_id, 'RESET_PASSWORD', "Réinitialisation du mot de passe de l'administrateur $admin_id", 'USER', $admin_id);
                $success_message = "Administrateur mis à jour avec succès. Le mot de passe a été réinitialisé à 'Admin123!'.";
            } else if (!empty($new_password)) {
                logAdminAction($conn, $user_id, 'UPDATE_PASSWORD', "Modification du mot de passe de l'administrateur $admin_id", 'USER', $admin_id);
                $success_message = "Administrateur mis à jour avec succès. Le mot de passe a été modifié.";
            } else {
                $success_message = "Administrateur mis à jour avec succès.";
            }
            
            // Rafraîchir les informations de l'admin
            $sql = "SELECT * FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $admin_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();
        } else {
            $error_message = "Erreur lors de la mise à jour: " . $conn->error;
        }
    }
}

// Récupération des dernières connexions de l'administrateur
$logins_result = getUserLogins($conn, $admin_id, 5);

// Récupération des dernières actions de l'administrateur
$actions_sql = "SELECT * FROM admin_logs WHERE admin_id = ? ORDER BY created_at DESC LIMIT 10";
$actions_stmt = $conn->prepare($actions_sql);
$actions_stmt->bind_param("s", $admin_id);
$actions_stmt->execute();
$actions_result = $actions_stmt->get_result();

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Éditer Administrateur - Université Virtuelle</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<style>/* =====================
   STYLES GLOBAUX
===================== */
body {
  font-family: "Poppins", sans-serif;
  background-color: #f5f7fa;
  margin: 0;
  padding: 0;
  color: #333;
}

.dashboard-container {
  max-width: 1200px;
  margin: 40px auto;
  padding: 0 20px;
}

a {
  text-decoration: none;
  color: inherit;
}

/* =====================
   LIEN RETOUR
===================== */
.back-link {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: #2b6cb0;
  font-weight: 600;
  margin-bottom: 20px;
  transition: color 0.3s;
}

.back-link:hover {
  color: #1e4f91;
}

/* =====================
   TITRES ET EN-TÊTES
===================== */
.page-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 25px;
}

.page-header h1 {
  font-size: 1.8rem;
  color: #2d3748;
}

.page-header i {
  font-size: 1.8rem;
  color: #3182ce;
}

/* =====================
   ALERTES (succès / erreur)
===================== */
.alert {
  padding: 15px 20px;
  border-radius: 10px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 500;
}

.alert i {
  font-size: 1.2rem;
}

.alert-success {
  background-color: #e6fffa;
  border: 1px solid #38b2ac;
  color: #2c7a7b;
}

.alert-error {
  background-color: #fff5f5;
  border: 1px solid #fc8181;
  color: #c53030;
}

/* =====================
   CARTES
===================== */
.card {
  background: inherit; /* ou background: transparent; */
  border-radius: 15px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
  padding: 25px;
  margin-bottom: 30px;
  transition: transform 0.2s ease;
}


.card:hover {
  transform: translateY(-3px);
}

/* =====================
   INFO ADMIN
===================== */
.admin-header {
  display: flex;
  align-items: center;
  gap: 20px;
  margin-bottom: 25px;
}

.admin-avatar {
  width: 100px;
  height: 100px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid #3182ce;
}

.admin-info h2 {
  font-size: 1.5rem;
  margin-bottom: 5px;
}

.admin-status {
  padding: 4px 10px;
  border-radius: 8px;
  font-size: 0.9rem;
  font-weight: 600;
}

.admin-status.active {
  background-color: #e6fffa;
  color: #2c7a7b;
}

.admin-status.blocked {
  background-color: #fff5f5;
  color: #c53030;
}

/* =====================
   FORMULAIRES
===================== */
.profile-form {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.section-title {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 1.2rem;
  color: #2b6cb0;
  margin-top: 10px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

label {
  font-weight: 600;
  color: #4a5568;
}

input[type="text"],
input[type="email"],
input[type="tel"],
input[type="password"] {
  padding: 10px 12px;
  border: 1px solid #cbd5e0;
  border-radius: 8px;
  font-size: 1rem;
  transition: border-color 0.3s;
}

input:focus {
  outline: none;
  border-color: #3182ce;
}

small {
  font-size: 0.85rem;
  color: #718096;
}

/* =====================
   BOUTONS
===================== */
.form-actions {
  display: flex;
  gap: 12px;
  margin-top: 10px;
}

.btn {
  background-color: #3182ce;
  color: #fff;
  padding: 10px 20px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  font-weight: 600;
  transition: background 0.3s;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.btn:hover {
  background-color: #2b6cb0;
}

.btn-secondary {
  background-color: #edf2f7;
  color: #2d3748;
  padding: 10px 20px;
  border-radius: 8px;
  font-weight: 600;
  transition: background 0.3s;
}

.btn-secondary:hover {
  background-color: #e2e8f0;
}

/* =====================
   ONGLET ACTIVITÉS
===================== */
.tabs {
  display: flex;
  border-bottom: 2px solid #e2e8f0;
  margin-bottom: 15px;
}

.tab {
  padding: 10px 20px;
  cursor: pointer;
  font-weight: 600;
  color: #4a5568;
  border-bottom: 3px solid transparent;
  transition: all 0.3s;
}

.tab.active {
  color: #3182ce;
  border-bottom-color: #3182ce;
}

.tab-content {
  display: none;
}

.tab-content.active {
  display: block;
}

/* =====================
   JOURNAUX D’ACTIONS
===================== */
.log-item {
  display: flex;
  align-items: flex-start;
  gap: 15px;
  border-bottom: 1px solid #edf2f7;
  padding: 12px 0;
}

.log-avatar {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  object-fit: cover;
}

.log-content {
  flex: 1;
}

.log-header {
  display: flex;
  justify-content: space-between;
  font-weight: 600;
}

.log-time {
  color: #718096;
  font-size: 0.9rem;
}

.log-type {
  display: inline-block;
  margin-top: 4px;
  font-size: 0.9rem;
  font-weight: 600;
}

.log-type i {
  margin-right: 6px;
}

.log-description {
  margin-top: 4px;
  color: #4a5568;
  font-size: 0.95rem;
}

/* =====================
   TABLE DES CONNEXIONS
===================== */
.login-table {
  width: 100%;
  border-collapse: collapse;
}

.login-table th, 
.login-table td {
  padding: 10px 12px;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  background-color: #0c2d48;

}

.login-table th {
  background-color: #0c2d48;
  color: #2d3748;
  font-weight: 600;
}

.badge {
  padding: 5px 10px;
  border-radius: 8px;
  font-size: 0.9rem;
  font-weight: 600;
}

.badge.success {
  background-color: #e6fffa;
  color: #2c7a7b;
}

.badge.error {
  background-color: #fff5f5;
  color: #c53030;
}
</style>
</head>
<body>
    <?php include_once '../includes/header_admin.php'; ?>

    <div class="dashboard-container">
        <a href="manage_admins.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Retour à la gestion des administrateurs
        </a>

        <div class="page-header">
            <i class="fas fa-user-edit"></i>
            <h1>Éditer l'administrateur</h1>
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

        <div class="card">
            <div class="admin-header">
                <?php if (!empty($admin['avatar']) && $admin['avatar'] !== 'default_avatar.png'): ?>
                    <img src="../<?php echo htmlspecialchars($admin['avatar']); ?>" alt="Avatar" class="admin-avatar">
                <?php else: ?>
                    <img src="../assets/images/profil.png" alt="Avatar par défaut" class="admin-avatar">
                <?php endif; ?>
                
                <div class="admin-info">
                    <h2><?php echo htmlspecialchars($admin['name']); ?></h2>
                    <p>ID: <?php echo htmlspecialchars($admin['id']); ?></p>
                    <p>
                        Statut: Admin 
                        <?php if ($admin['blocked'] == 1): ?>
                            <span class="admin-status blocked">Bloqué</span>
                        <?php else: ?>
                            <span class="admin-status active">Actif</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <form class="profile-form" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <h3 class="section-title"><i class="fas fa-user"></i> Informations personnelles</h3>
                
                <div class="form-group">
                    <label for="name">Nom complet</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($admin['name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="phone">Téléphone</label>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>">
                </div>
                
                <h3 class="section-title"><i class="fas fa-lock"></i> Gestion du mot de passe</h3>
                
                <div class="password-options">
                    <label class="checkbox-group" for="reset_password">
                        <input type="checkbox" id="reset_password" name="reset_password">
                        Réinitialiser le mot de passe à la valeur par défaut (Admin123!)
                    </label>
                    
                    <label class="checkbox-group" for="custom_password">
                        <input type="checkbox" id="custom_password">
                        Définir un mot de passe personnalisé
                    </label>
                    
                    <div class="password-fields" id="password_fields">
                        <div class="form-group">
                            <label for="new_password">Nouveau mot de passe</label>
                            <input type="password" id="new_password" name="new_password">
                            <small>8 caractères minimum, incluant majuscules, minuscules, chiffres et caractères spéciaux</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirmer le nouveau mot de passe</label>
                            <input type="password" id="confirm_password" name="confirm_password">
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_admin" class="btn">
                        <i class="fas fa-save"></i> Enregistrer les modifications
                    </button>
                    <a href="manage_admins.php" class="btn-secondary">Annuler</a>
                </div>
            </form>
        </div>

        <div class="card">
            <h2><i class="fas fa-history"></i> Activités récentes</h2>
            
            <div class="tabs">
                <div class="tab active" data-tab="tab-actions">Actions</div>
                <div class="tab" data-tab="tab-logins">Connexions</div>
            </div>
            
            <div class="tab-content active" id="tab-actions">
                <div class="activity-logs">
                    <?php if ($actions_result->num_rows > 0): ?>
                        <?php while ($log = $actions_result->fetch_assoc()): ?>
                            <div class="log-item">
                                <?php if (!empty($admin['avatar']) && $admin['avatar'] !== 'default_avatar.png'): ?>
                                    <img src="../<?php echo htmlspecialchars($admin['avatar']); ?>" alt="Avatar admin" class="log-avatar">
                                <?php else: ?>
                                    <img src="../assets/images/profil.png" alt="Avatar par défaut" class="log-avatar">
                                <?php endif; ?>
                                
                                <div class="log-content">
                                    <div class="log-header">
                                        <span class="log-admin"><?php echo htmlspecialchars($admin['name']); ?></span>
                                        <span class="log-time"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($log['created_at']))); ?></span>
                                    </div>
                                    
                                    <?php 
                                    $log_data = formatActionType($log['action_type']);
                                    $log_class = $log_data['class'];
                                    $log_icon = $log_data['icon'];
                                    ?>
                                    
                                    <span class="log-type <?php echo $log_class; ?>">
                                        <i class="fas <?php echo $log_icon; ?>"></i> 
                                        <?php echo htmlspecialchars($log['action_type']); ?>
                                    </span>
                                    <p class="log-description"><?php echo htmlspecialchars($log['description']); ?></p>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p>Aucune action enregistrée</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="tab-content" id="tab-logins">
                <table class="login-table">
                    <thead>
                        <tr>
                            <th>Date et Heure</th>
                            <th>Adresse IP</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($logins_result) > 0): ?>
                            <?php foreach ($logins_result as $login): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($login['login_time']))); ?></td>
                                    <td><?php echo htmlspecialchars($login['ip_address']); ?></td>
                                    <td>
                                        <?php if ($login['success'] == 1): ?>
                                            <span class="badge success">Réussie</span>
                                        <?php else: ?>
                                            <span class="badge error">Échouée</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3">Aucune connexion enregistrée</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php include_once '../includes/footer.php'; ?>

    <script>
        // Gestion des options de mot de passe
        const resetPasswordCheckbox = document.getElementById('reset_password');
        const customPasswordCheckbox = document.getElementById('custom_password');
        const passwordFields = document.getElementById('password_fields');
        const newPasswordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        
        resetPasswordCheckbox.addEventListener('change', function() {
            if (this.checked) {
                customPasswordCheckbox.checked = false;
                passwordFields.classList.remove('show');
                newPasswordInput.value = '';
                confirmPasswordInput.value = '';
            }
        });
        
        customPasswordCheckbox.addEventListener('change', function() {
            if (this.checked) {
                resetPasswordCheckbox.checked = false;
                passwordFields.classList.add('show');
                newPasswordInput.focus();
            } else {
                passwordFields.classList.remove('show');
                newPasswordInput.value = '';
                confirmPasswordInput.value = '';
            }
        });
        
        // Validation du formulaire
        document.querySelector('.profile-form').addEventListener('submit', function(e) {
            if (customPasswordCheckbox.checked) {
                if (newPasswordInput.value !== confirmPasswordInput.value) {
                    e.preventDefault();
                    alert('Les mots de passe ne correspondent pas.');
                    return false;
                }
                
                if (newPasswordInput.value.length < 8) {
                    e.preventDefault();
                    alert('Le mot de passe doit contenir au moins 8 caractères.');
                    return false;
                }
            }
        });
        
        // Gestion des onglets
        const tabs = document.querySelectorAll('.tab');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Retirer la classe active de tous les onglets
                tabs.forEach(tab => tab.classList.remove('active'));
                
                // Ajouter la classe active à l'onglet cliqué
                this.classList.add('active');
                
                // Afficher le contenu correspondant
                const tabId = this.getAttribute('data-tab');
                
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    if (content.id === tabId) {
                        content.classList.add('active');
                    }
                });
            });
        });
    </script>
</body>
</html>