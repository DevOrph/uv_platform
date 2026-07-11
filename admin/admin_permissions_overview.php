<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/super_admin.php';

// Vérification - Seul un super administrateur peut accéder
if (!isset($_SESSION['user_id']) || !is_super_admin($conn)) {
    header('Location: ../pages/login.php');
    exit();
}

// Récupération des statistiques
$stats_queries = [
    'total_users' => "SELECT COUNT(*) as count FROM users WHERE role IN ('admin', 'teacher') AND is_super_admin = 0",
    'active_permissions' => "SELECT COUNT(*) as count FROM exam_permissions WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())",
    'expired_permissions' => "SELECT COUNT(*) as count FROM exam_permissions WHERE expires_at IS NOT NULL AND expires_at <= NOW()",
    'total_exam_grades' => "SELECT COUNT(*) as count FROM grades WHERE evaluation_type_id = 2",
    'recent_exam_grades' => "SELECT COUNT(*) as count FROM grades WHERE evaluation_type_id = 2 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
];

$stats = [];
foreach ($stats_queries as $key => $query) {
    $result = $conn->query($query);
    $stats[$key] = $result->fetch_assoc()['count'];
}

// Récupération des permissions récentes
$recent_permissions_query = "
    SELECT ep.*, u.name as user_name, u.role as user_role
    FROM exam_permissions ep
    JOIN users u ON ep.user_id = u.id
    ORDER BY ep.granted_at DESC
    LIMIT 10
";
$recent_permissions = $conn->query($recent_permissions_query);

// Récupération des notes d'examen récentes
$recent_exam_grades_query = "
    SELECT g.*, u.name as student_name, c.name as course_name, 
           creator.name as created_by_name
    FROM grades g
    JOIN users u ON g.student_id = u.id
    JOIN courses c ON g.course_id = c.id
    JOIN users creator ON g.created_by = creator.id
    WHERE g.evaluation_type_id = 2
    ORDER BY g.created_at DESC
    LIMIT 15
";
$recent_exam_grades = $conn->query($recent_exam_grades_query);

// Récupération des utilisateurs sans permission
$users_without_permission_query = "
    SELECT u.id, u.name, u.role
    FROM users u
    LEFT JOIN exam_permissions ep ON u.id = ep.user_id AND ep.is_active = 1
    WHERE u.role IN ('admin', 'teacher') 
    AND u.is_super_admin = 0
    AND (ep.id IS NULL OR (ep.expires_at IS NOT NULL AND ep.expires_at <= NOW()))
    ORDER BY u.role, u.name
";
$users_without_permission = $conn->query($users_without_permission_query);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord - Permissions d'Examen</title>
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

        .dashboard {
            max-width: 1600px;
            margin: 20px auto;
            padding: 20px;
        }

        .dashboard-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, #e91e63, #ad1457);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(233, 30, 99, 0.3);
        }

        .dashboard-header h1 {
            margin: 0 0 10px 0;
            font-size: 2.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .admin-badge {
            background: linear-gradient(135deg, #ffd700, #ffb300);
            color: #000;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            display: block;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.8);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 40px;
        }

        .content-section {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            border: 1px solid var(--border-color);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--accent-color);
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .table-responsive {
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
        }

        .mini-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .mini-table th {
            background: var(--secondary-bg);
            color: var(--text-light);
            padding: 10px;
            text-align: left;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .mini-table td {
            padding: 10px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-light);
        }

        .mini-table tr:nth-child(even) {
            background: rgba(255, 255, 255, 0.05);
        }

        .mini-table tr:hover {
            background: rgba(3, 155, 229, 0.2);
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
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
            padding: 3px 6px;
            border-radius: 8px;
            font-size: 10px;
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

        .grade-badge {
            padding: 4px 8px;
            border-radius: 10px;
            font-weight: bold;
            font-size: 12px;
        }

        .grade-excellent {
            background: #4CAF50;
            color: white;
        }

        .grade-good {
            background: #2196F3;
            color: white;
        }

        .grade-average {
            background: #ff9800;
            color: white;
        }

        .grade-poor {
            background: #f44336;
            color: white;
        }

        .quick-actions {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: var(--text-light);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #45a049);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #f57c00);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: rgba(255, 255, 255, 0.6);
        }

        .empty-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .dashboard {
                margin: 10px;
                padding: 15px;
            }
            
            .quick-actions {
                flex-direction: column;
            }
        }

        .full-width-section {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header_admin.php'; ?>

    <div class="dashboard">
        <div class="dashboard-header">
            <h1>
                <i class="fas fa-shield-alt"></i>
                Tableau de Bord - Permissions d'Examen
            </h1>
            <p>Supervision et contrôle des accès aux notes d'examen</p>
            <div class="admin-badge">ADMIN01 - Super Administrateur</div>
        </div>

        <!-- Statistiques -->
        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-users stat-icon" style="color: var(--accent-color);"></i>
                <div class="stat-number" style="color: var(--accent-color);"><?php echo $stats['total_users']; ?></div>
                <div class="stat-label">Utilisateurs Éligibles</div>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-key stat-icon" style="color: var(--success-color);"></i>
                <div class="stat-number" style="color: var(--success-color);"><?php echo $stats['active_permissions']; ?></div>
                <div class="stat-label">Permissions Actives</div>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-clock stat-icon" style="color: var(--warning-color);"></i>
                <div class="stat-number" style="color: var(--warning-color);"><?php echo $stats['expired_permissions']; ?></div>
                <div class="stat-label">Permissions Expirées</div>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-graduation-cap stat-icon" style="color: #9c27b0;"></i>
                <div class="stat-number" style="color: #9c27b0;"><?php echo $stats['total_exam_grades']; ?></div>
                <div class="stat-label">Notes d'Examen Total</div>
            </div>
            
            <div class="stat-card">
                <i class="fas fa-calendar-day stat-icon" style="color: #ff5722;"></i>
                <div class="stat-number" style="color: #ff5722;"><?php echo $stats['recent_exam_grades']; ?></div>
                <div class="stat-label">Notes 30 Derniers Jours</div>
            </div>
        </div>

        <!-- Actions rapides -->
        <div class="quick-actions">
            <a href="exam_permissions.php" class="btn btn-primary">
                <i class="fas fa-user-plus"></i>
                Gérer les Permissions
            </a>
            <a href="../grades/grades_management.php" class="btn btn-success">
                <i class="fas fa-plus-circle"></i>
                Ajouter des Notes
            </a>
            <a href="../grades/grade_reports.php" class="btn btn-warning">
                <i class="fas fa-chart-bar"></i>
                Rapports de Notes
            </a>
        </div>

        <!-- Contenu principal -->
        <div class="content-grid">
            <!-- Permissions récentes -->
            <div class="content-section">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-history"></i>
                        Permissions Récentes
                    </div>
                </div>
                
                <div class="table-responsive">
                    <?php if ($recent_permissions->num_rows > 0): ?>
                        <table class="mini-table">
                            <thead>
                                <tr>
                                    <th>Utilisateur</th>
                                    <th>Statut</th>
                                    <th>Accordée le</th>
                                    <th>Expire</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($perm = $recent_permissions->fetch_assoc()): 
                                    $is_expired = $perm['expires_at'] && strtotime($perm['expires_at']) <= time();
                                    $is_active = $perm['is_active'] && !$is_expired;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($perm['user_name']); ?></strong>
                                            <br>
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
                                        <td><?php echo date('d/m/Y', strtotime($perm['granted_at'])); ?></td>
                                        <td>
                                            <?php if ($perm['expires_at']): ?>
                                                <?php echo date('d/m/Y', strtotime($perm['expires_at'])); ?>
                                            <?php else: ?>
                                                <span style="color: var(--success-color);">Jamais</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox empty-icon"></i>
                            <p>Aucune permission accordée</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Notes d'examen récentes -->
            <div class="content-section">
                <div class="section-header">
                    <div class="section-title">
                        <i class="fas fa-scroll"></i>
                        Notes d'Examen Récentes
                    </div>
                </div>
                
                <div class="table-responsive">
                    <?php if ($recent_exam_grades->num_rows > 0): ?>
                        <table class="mini-table">
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th>Cours</th>
                                    <th>Note</th>
                                    <th>Créée par</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($grade = $recent_exam_grades->fetch_assoc()): 
                                    $grade_value = floatval($grade['grade']);
                                    $grade_class = '';
                                    if ($grade_value >= 16) $grade_class = 'grade-excellent';
                                    elseif ($grade_value >= 14) $grade_class = 'grade-good';
                                    elseif ($grade_value >= 10) $grade_class = 'grade-average';
                                    else $grade_class = 'grade-poor';
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($grade['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($grade['course_name']); ?></td>
                                        <td>
                                            <span class="grade-badge <?php echo $grade_class; ?>">
                                                <?php echo $grade['grade']; ?>/20
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($grade['created_by_name']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($grade['created_at'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-graduation-cap empty-icon"></i>
                            <p>Aucune note d'examen récente</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Utilisateurs sans permission -->
        <div class="full-width-section">
            <div class="section-header">
                <div class="section-title">
                    <i class="fas fa-user-times"></i>
                    Utilisateurs Sans Permission d'Examen
                </div>
                <a href="exam_permissions.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i>
                    Accorder des Permissions
                </a>
            </div>
            
            <div class="table-responsive">
                <?php if ($users_without_permission->num_rows > 0): ?>
                    <table class="mini-table">
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>ID Utilisateur</th>
                                <th>Rôle</th>
                                <th>Statut</th>
                                <th>Action Rapide</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($user = $users_without_permission->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($user['name']); ?></strong></td>
                                    <td><code><?php echo htmlspecialchars($user['id']); ?></code></td>
                                    <td>
                                        <span class="role-badge role-<?php echo $user['role']; ?>">
                                            <?php echo ucfirst($user['role']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-inactive">
                                            Aucune Permission
                                        </span>
                                    </td>
                                    <td>
                                        <a href="exam_permissions.php?quick_grant=<?php echo urlencode($user['id']); ?>" 
                                           class="btn btn-success btn-sm">
                                            <i class="fas fa-key"></i>
                                            Accorder
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle empty-icon" style="color: var(--success-color);"></i>
                        <p>Tous les utilisateurs éligibles ont des permissions actives</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>