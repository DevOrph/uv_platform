<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/super_admin.php';

// Vérification - Seul un super administrateur peut accéder à cette page
if (!isset($_SESSION['user_id']) || !is_super_admin($conn)) {
    header('Location: ../pages/login.php');
    exit();
}

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['grant_permission'])) {
        $user_id = $_POST['user_id'];
        $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $notes = $_POST['notes'] ?? '';
        $allow_rattrapage = isset($_POST['allow_rattrapage']) ? 1 : 0;

        // Vérifier si la permission existe déjà
        $check_query = "SELECT id FROM exam_permissions WHERE user_id = ?";
        $stmt = $conn->prepare($check_query);
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $existing = $stmt->get_result();

        if ($existing->num_rows > 0) {
            // Mettre à jour la permission existante
            $update_query = "UPDATE exam_permissions SET expires_at = ?, is_active = 1, notes = ?, granted_at = NOW(), allow_rattrapage = ? WHERE user_id = ?";
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param("ssis", $expires_at, $notes, $allow_rattrapage, $user_id);
            $stmt->execute();
            $success = "Permission mise à jour avec succès !";
        } else {
            // Créer une nouvelle permission
            $insert_query = "INSERT INTO exam_permissions (user_id, granted_by, expires_at, notes, allow_rattrapage) VALUES (?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insert_query);
            $stmt->bind_param("ssssi", $user_id, $_SESSION['user_id'], $expires_at, $notes, $allow_rattrapage);
            $stmt->execute();
            $success = "Permission accordée avec succès !";
        }
    }
    
    if (isset($_POST['revoke_permission'])) {
        $permission_id = $_POST['permission_id'];
        
        $update_query = "UPDATE exam_permissions SET is_active = 0 WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("i", $permission_id);
        $stmt->execute();
        $success = "Permission révoquée avec succès !";
    }
    
    if (isset($_POST['extend_permission'])) {
        $permission_id = $_POST['permission_id'];
        $new_expiry = $_POST['new_expiry'];

        $update_query = "UPDATE exam_permissions SET expires_at = ? WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("si", $new_expiry, $permission_id);
        $stmt->execute();
        $success = "Durée de permission prolongée avec succès !";
    }

    if (isset($_POST['toggle_rattrapage'])) {
        $permission_id = (int)$_POST['permission_id'];
        $new_value = (int)$_POST['rattrapage_value'];

        $update_query = "UPDATE exam_permissions SET allow_rattrapage = ? WHERE id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("ii", $new_value, $permission_id);
        $stmt->execute();
        $success = $new_value ? "Autorisation rattrapage accordée." : "Autorisation rattrapage révoquée.";
    }
}

// Récupération des utilisateurs éligibles (admins et enseignants, sauf ADMIN01)
$users_query = "SELECT id, name, role FROM users WHERE role IN ('admin', 'teacher') AND is_super_admin = 0 ORDER BY role, name";
$users_result = $conn->query($users_query);

// Récupération des permissions existantes
$permissions_query = "
    SELECT ep.*, u.name as user_name, u.role as user_role 
    FROM exam_permissions ep 
    JOIN users u ON ep.user_id = u.id 
    ORDER BY ep.granted_at DESC
";
$permissions_result = $conn->query($permissions_query);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Permissions d'Examen - UV</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #4CAF50;
            --error-color: #f44336;
            --warning-color: #ff9800;
        }

        body {
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            min-height: 100vh;
            font-family: 'Google Sans', Arial, sans-serif;
            color: var(--text-light);
        }

        .dashboard-container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .page-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
            padding: 25px;
            background: linear-gradient(135deg, #e91e63, #ad1457);
            color: var(--text-light);
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(233, 30, 99, 0.3);
        }

        .admin-badge {
            background: linear-gradient(135deg, #ffd700, #ffb300);
            color: #000;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .permission-form {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 1px solid var(--border-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group select,
        .form-group input,
        .form-group textarea {
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group select:focus,
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(3, 155, 229, 0.2);
        }

        .form-group select option {
            background: var(--secondary-bg);
            color: var(--text-light);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: var(--text-light);
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            text-align: center;
            justify-content: center;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #45a049);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #f57c00);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--error-color), #d32f2f);
            color: white;
        }

        .btn-sm {
            padding: 8px 15px;
            font-size: 12px;
        }

        .permissions-table-container {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 20px;
            margin-top: 30px;
        }

        .permissions-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .permissions-table th {
            background: var(--secondary-bg);
            color: var(--text-light);
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid var(--accent-color);
            font-weight: 600;
        }

        .permissions-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-light);
            vertical-align: middle;
        }

        .permissions-table tr:nth-child(even) {
            background: rgba(255, 255, 255, 0.05);
        }

        .permissions-table tr:hover {
            background: rgba(3, 155, 229, 0.2);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-active {
            background: var(--success-color);
            color: white;
        }

        .status-inactive {
            background: var(--error-color);
            color: white;
        }

        .status-expired {
            background: var(--warning-color);
            color: white;
        }

        .role-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .role-admin {
            background: #9c27b0;
            color: white;
        }

        .role-teacher {
            background: #2196f3;
            color: white;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .alert.success {
            background: linear-gradient(135deg, var(--success-color), #45a049);
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .extend-form {
            display: none;
            margin-top: 10px;
            padding: 15px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
        }

        .extend-form.show {
            display: block;
        }

        .quick-actions {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid var(--border-color);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--accent-color);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.8);
            text-transform: uppercase;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-container {
                margin: 10px;
                padding: 15px;
            }
            
            .permissions-table {
                font-size: 12px;
            }
            
            .permissions-table th,
            .permissions-table td {
                padding: 10px 8px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php include '../includes/header_admin.php'; ?>

    <div class="dashboard-container">
        <div class="page-header">
            <i class="fas fa-shield-alt"></i>
            <div>
                <h1>Gestion des Permissions d'Examen</h1>
                <p>Contrôle d'accès aux notes d'examen et de rattrapage</p>
                <div class="admin-badge">ADMIN01 Exclusif</div>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="stats-grid">
            <?php
            // Calculer les statistiques
            $active_permissions = $conn->query("SELECT COUNT(*) as count FROM exam_permissions WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())")->fetch_assoc()['count'];
            $total_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role IN ('admin', 'teacher') AND is_super_admin = 0")->fetch_assoc()['count'];
            $expired_permissions = $conn->query("SELECT COUNT(*) as count FROM exam_permissions WHERE expires_at IS NOT NULL AND expires_at <= NOW()")->fetch_assoc()['count'];
            $rattrapage_authorized = $conn->query("SELECT COUNT(*) as count FROM exam_permissions WHERE is_active = 1 AND allow_rattrapage = 1 AND (expires_at IS NULL OR expires_at > NOW())")->fetch_assoc()['count'];
            ?>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_permissions; ?></div>
                <div class="stat-label">Permissions Actives</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $rattrapage_authorized; ?></div>
                <div class="stat-label">Rattrapage Autorisés</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_users; ?></div>
                <div class="stat-label">Utilisateurs Éligibles</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $expired_permissions; ?></div>
                <div class="stat-label">Permissions Expirées</div>
            </div>
        </div>

        <!-- Formulaire d'attribution de permission -->
        <div class="permission-form">
            <h2><i class="fas fa-user-plus"></i> Accorder une Permission d'Examen</h2>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="user_id">
                            <i class="fas fa-user"></i>
                            Utilisateur
                        </label>
                        <select id="user_id" name="user_id" required>
                            <option value="">Sélectionner un utilisateur</option>
                            <?php while ($user = $users_result->fetch_assoc()): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['name']); ?> 
                                    (<?php echo ucfirst($user['role']); ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="expires_at">
                            <i class="fas fa-calendar-times"></i>
                            Date d'expiration (optionnel)
                        </label>
                        <input type="datetime-local" id="expires_at" name="expires_at">
                        <small style="color: rgba(255,255,255,0.7); margin-top: 5px;">
                            Laisser vide pour une permission permanente
                        </small>
                    </div>
                </div>

                <div class="form-group">
                    <label for="notes">
                        <i class="fas fa-sticky-note"></i>
                        Notes / Raison (optionnel)
                    </label>
                    <textarea id="notes" name="notes" rows="3"
                              placeholder="Indiquer la raison de cette permission..."></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label style="flex-direction: row; align-items: center; gap: 12px; cursor: pointer;">
                        <input type="checkbox" name="allow_rattrapage" id="allow_rattrapage"
                               style="width: 18px; height: 18px; cursor: pointer; accent-color: var(--accent-color);">
                        <span>
                            <i class="fas fa-redo"></i>
                            Autoriser la saisie des notes de rattrapage
                        </span>
                        <small style="color: rgba(255,255,255,0.6); font-weight: normal;">
                            — permet à l'enseignant de noter les rattrapages de ses cours
                        </small>
                    </label>
                </div>

                <div class="quick-actions">
                    <button type="submit" name="grant_permission" class="btn-primary">
                        <i class="fas fa-key"></i>
                        Accorder la Permission
                    </button>
                    <button type="button" onclick="setQuickExpiry('1week')" class="btn-success btn-sm">
                        <i class="fas fa-clock"></i>
                        1 Semaine
                    </button>
                    <button type="button" onclick="setQuickExpiry('1month')" class="btn-warning btn-sm">
                        <i class="fas fa-calendar"></i>
                        1 Mois
                    </button>
                    <button type="button" onclick="setQuickExpiry('3months')" class="btn-danger btn-sm">
                        <i class="fas fa-calendar-alt"></i>
                        3 Mois
                    </button>
                </div>
            </form>
        </div>

        <!-- Tableau des permissions existantes -->
        <div class="permissions-table-container">
            <h2><i class="fas fa-list"></i> Permissions Existantes</h2>
            
            <?php if ($permissions_result->num_rows > 0): ?>
                <table class="permissions-table">
                    <thead>
                        <tr>
                            <th>Utilisateur</th>
                            <th>Rôle</th>
                            <th>Statut</th>
                            <th>Rattrapage</th>
                            <th>Accordée le</th>
                            <th>Expire le</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($perm = $permissions_result->fetch_assoc()): 
                            $is_expired = $perm['expires_at'] && strtotime($perm['expires_at']) <= time();
                            $is_active = $perm['is_active'] && !$is_expired;
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($perm['user_name']); ?></strong>
                                    <br><small style="color: rgba(255,255,255,0.6);"><?php echo $perm['user_id']; ?></small>
                                </td>
                                <td>
                                    <span class="role-badge role-<?php echo $perm['user_role']; ?>">
                                        <?php echo ucfirst($perm['user_role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php 
                                        if (!$perm['is_active']) echo 'status-inactive';
                                        elseif ($is_expired) echo 'status-expired';
                                        else echo 'status-active';
                                    ?>">
                                        <?php 
                                            if (!$perm['is_active']) echo 'Révoquée';
                                            elseif ($is_expired) echo 'Expirée';
                                            else echo 'Active';
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($perm['allow_rattrapage']): ?>
                                        <span class="status-badge status-active" style="font-size:11px;">Oui</span>
                                    <?php else: ?>
                                        <span class="status-badge" style="background:rgba(255,255,255,.2);font-size:11px;">Non</span>
                                    <?php endif; ?>
                                    <?php if ($is_active): ?>
                                        <form method="POST" style="display:inline; margin-left:5px;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                            <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                            <input type="hidden" name="rattrapage_value" value="<?php echo $perm['allow_rattrapage'] ? 0 : 1; ?>">
                                            <button type="submit" name="toggle_rattrapage"
                                                    class="btn-<?php echo $perm['allow_rattrapage'] ? 'danger' : 'success'; ?> btn-sm"
                                                    style="font-size:10px; padding:3px 8px; margin-top:4px;"
                                                    onclick="return confirm('<?php echo $perm['allow_rattrapage'] ? 'Retirer' : 'Accorder'; ?> l\'autorisation rattrapage ?')">
                                                <?php echo $perm['allow_rattrapage'] ? 'Retirer' : 'Activer'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($perm['granted_at'])); ?></td>
                                <td>
                                    <?php if ($perm['expires_at']): ?>
                                        <?php echo date('d/m/Y H:i', strtotime($perm['expires_at'])); ?>
                                        <?php if ($is_expired): ?>
                                            <br><small style="color: #f44336;">⚠️ Expirée</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #4CAF50;">Permanente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($perm['notes']): ?>
                                        <div title="<?php echo htmlspecialchars($perm['notes']); ?>" style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                            <?php echo htmlspecialchars($perm['notes']); ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: rgba(255,255,255,0.5);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($is_active): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                                <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                                <button type="submit" name="revoke_permission" 
                                                        class="btn-danger btn-sm"
                                                        onclick="return confirm('Révoquer cette permission ?')">
                                                    <i class="fas fa-ban"></i>
                                                    Révoquer
                                                </button>
                                            </form>
                                            
                                            <button type="button" 
                                                    class="btn-warning btn-sm" 
                                                    onclick="toggleExtendForm(<?php echo $perm['id']; ?>)">
                                                <i class="fas fa-clock"></i>
                                                Prolonger
                                            </button>
                                        <?php else: ?>
                                            <span style="color: rgba(255,255,255,0.5);">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Formulaire de prolongation -->
                                    <div id="extendForm<?php echo $perm['id']; ?>" class="extend-form">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                                            <input type="hidden" name="permission_id" value="<?php echo $perm['id']; ?>">
                                            <div style="display: flex; gap: 10px; align-items: center;">
                                                <input type="datetime-local" name="new_expiry" required>
                                                <button type="submit" name="extend_permission" class="btn-success btn-sm">
                                                    <i class="fas fa-check"></i>
                                                    OK
                                                </button>
                                                <button type="button" onclick="toggleExtendForm(<?php echo $perm['id']; ?>)" class="btn-danger btn-sm">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: rgba(255,255,255,0.6);">
                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px;"></i>
                    <p>Aucune permission accordée pour le moment</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function setQuickExpiry(duration) {
            const now = new Date();
            let expiry;
            
            switch(duration) {
                case '1week':
                    expiry = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
                    break;
                case '1month':
                    expiry = new Date(now.getTime() + 30 * 24 * 60 * 60 * 1000);
                    break;
                case '3months':
                    expiry = new Date(now.getTime() + 90 * 24 * 60 * 60 * 1000);
                    break;
            }
            
            const expiryInput = document.getElementById('expires_at');
            expiryInput.value = expiry.toISOString().slice(0, 16);
        }

        function toggleExtendForm(permissionId) {
            const form = document.getElementById('extendForm' + permissionId);
            form.classList.toggle('show');
            
            if (form.classList.contains('show')) {
                // Définir une date par défaut (1 mois à partir de maintenant)
                const defaultDate = new Date();
                defaultDate.setMonth(defaultDate.getMonth() + 1);
                const input = form.querySelector('input[name="new_expiry"]');
                input.value = defaultDate.toISOString().slice(0, 16);
            }
        }

        // Validation du formulaire
        document.querySelector('form').addEventListener('submit', function(e) {
            const userId = document.getElementById('user_id').value;
            if (!userId) {
                e.preventDefault();
                alert('Veuillez sélectionner un utilisateur');
                return;
            }
        });

        // Auto-complétion intelligente
        document.getElementById('user_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const userName = selectedOption.textContent;
            const notesField = document.getElementById('notes');
            
            if (!notesField.value && userName) {
                notesField.value = `Permission accordée à ${userName} pour la saisie des notes d'examen`;
            }
        });
    </script>

    <?php include '../includes/footer.php'; ?>
</body>
</html>