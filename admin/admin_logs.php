<?php
session_start();
require_once '../includes/db_connect.php';

// Activer l'affichage des erreurs pour le débogage
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Vérifier si l'utilisateur est connecté et est un super administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    // Rediriger vers la page d'accueil ou d'erreur
    header("Location: admin_dashboard.php");
    exit();
}

$user_id = $_SESSION['user_id'];


// Inclure la bibliothèque de journalisation
require_once '../includes/utils/admin_logger.php';

// Paramètres de pagination
$items_per_page = 20;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $items_per_page;

// Filtres
$admin_filter = isset($_GET['admin_id']) ? $_GET['admin_id'] : '';
$action_filter = isset($_GET['action_type']) ? $_GET['action_type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Construction de la requête SQL avec filtres
$sql_count = "SELECT COUNT(*) as total FROM admin_logs a";
$sql_logs = "SELECT a.*, u.name as admin_name, u.avatar as admin_avatar 
             FROM admin_logs a
             JOIN users u ON a.admin_id = u.id";

// Conditions de filtrage
$where_conditions = [];
$params = [];
$types = "";

if (!empty($admin_filter)) {
    $where_conditions[] = "a.admin_id = ?";
    $params[] = $admin_filter;
    $types .= "s";
}

if (!empty($action_filter)) {
    $where_conditions[] = "a.action_type = ?";
    $params[] = $action_filter;
    $types .= "s";
}

if (!empty($date_from)) {
    $where_conditions[] = "a.created_at >= ?";
    $params[] = $date_from . " 00:00:00";
    $types .= "s";
}

if (!empty($date_to)) {
    $where_conditions[] = "a.created_at <= ?";
    $params[] = $date_to . " 23:59:59";
    $types .= "s";
}

// Ajouter les conditions à la requête
if (!empty($where_conditions)) {
    $sql_count .= " WHERE " . implode(" AND ", $where_conditions);
    $sql_logs .= " WHERE " . implode(" AND ", $where_conditions);
}

// Ajouter l'ordre et la pagination
$sql_logs .= " ORDER BY a.created_at DESC LIMIT ?, ?";
$params[] = $offset;
$params[] = $items_per_page;
$types .= "ii";

// Exécuter la requête pour le compte total
$stmt_count = $conn->prepare($sql_count);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$count_result = $stmt_count->get_result();
$total_items = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);

// Exécuter la requête pour les journaux
$stmt_logs = $conn->prepare($sql_logs);
if (!empty($params)) {
    $stmt_logs->bind_param($types, ...$params);
}
$stmt_logs->execute();
$logs_result = $stmt_logs->get_result();

// Récupérer les administrateurs pour le filtre
$admins_sql = "SELECT id, name FROM users WHERE role = 'admin' OR role = 'super_admin' ORDER BY name";
$admins_result = $conn->query($admins_sql);

// Récupérer les types d'actions uniques pour le filtre
$action_types_sql = "SELECT DISTINCT action_type FROM admin_logs ORDER BY action_type";
$action_types_result = $conn->query($action_types_sql);

$conn->close();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journaux d'administration - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
    <style>
        .filters-form {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }
        
        .filters-form .form-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        
        .filters-form .form-group {
            flex: 1;
            min-width: 200px;
            margin-bottom: 0;
        }
        
        .filters-form .form-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
        }
        
        .log-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .log-table th,
        .log-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .log-table th {
            background: rgba(0, 0, 0, 0.2);
            color: var(--accent-color);
        }
        
        .log-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .log-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            vertical-align: middle;
            margin-right: 8px;
        }
        
        .log-admin {
            display: flex;
            align-items: center;
        }
        
        .log-type {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 30px;
            gap: 5px;
        }
        
        .pagination a,
        .pagination span {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-light);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .pagination a:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .pagination .current {
            background: var(--accent-color);
            color: white;
        }
        
        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .clear-filters {
            background: rgba(244, 67, 54, 0.1);
            color: #F44336;
            border: 1px solid rgba(244, 67, 54, 0.3);
        }
        
        .clear-filters:hover {
            background: rgba(244, 67, 54, 0.2);
        }
        
        .export-btn {
            background: rgba(76, 175, 80, 0.1);
            color: #4CAF50;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }
        
        .export-btn:hover {
            background: rgba(76, 175, 80, 0.2);
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
            <i class="fas fa-clipboard-list"></i>
            <h1>Journaux d'administration</h1>
        </div>

        <div class="filters-form">
            <h3><i class="fas fa-filter"></i> Filtres</h3>
            <form method="GET" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="admin_id">Administrateur</label>
                        <select id="admin_id" name="admin_id">
                            <option value="">Tous les administrateurs</option>
                            <?php while ($admin = $admins_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($admin['id']); ?>" <?php echo ($admin_filter === $admin['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($admin['name']); ?> (<?php echo htmlspecialchars($admin['id']); ?>)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="action_type">Type d'action</label>
                        <select id="action_type" name="action_type">
                            <option value="">Toutes les actions</option>
                            <?php while ($action = $action_types_result->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars($action['action_type']); ?>" <?php echo ($action_filter === $action['action_type']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action['action_type']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="date_from">Date de début</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="date_to">Date de fin</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                </div>
                
                <div class="form-actions">
                    <div>
                        <button type="submit" class="btn">
                            <i class="fas fa-search"></i> Filtrer
                        </button>
                        
                        <a href="admin_logs.php" class="btn-secondary clear-filters">
                            <i class="fas fa-times"></i> Effacer les filtres
                        </a>
                    </div>
                    
                    <a href="export_logs.php?<?php echo http_build_query($_GET); ?>" class="btn-secondary export-btn">
                        <i class="fas fa-file-export"></i> Exporter en CSV
                    </a>
                </div>
            </form>
        </div>

        <div class="card">
            <h2><i class="fas fa-history"></i> Historique des actions administratives</h2>
            <p>Total: <?php echo number_format($total_items); ?> actions</p>
            
            <?php if ($logs_result->num_rows > 0): ?>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Date et Heure</th>
                            <th>Administrateur</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>Entité</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($log = $logs_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['created_at']))); ?></td>
                                <td class="log-admin">
                                    <?php if (!empty($log['admin_avatar']) && $log['admin_avatar'] !== 'default_avatar.png'): ?>
                                        <img src="../<?php echo htmlspecialchars($log['admin_avatar']); ?>" alt="Avatar" class="log-avatar">
                                    <?php else: ?>
                                        <img src="../assets/images/profil.png" alt="Avatar par défaut" class="log-avatar">
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($log['admin_name']); ?>
                                </td>
                                <td>
                                    <?php 
                                    $log_data = formatActionType($log['action_type']);
                                    $log_class = $log_data['class'];
                                    $log_icon = $log_data['icon'];
                                    ?>
                                    <span class="log-type <?php echo $log_class; ?>">
                                        <i class="fas <?php echo $log_icon; ?>"></i> 
                                        <?php echo htmlspecialchars($log['action_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['description']); ?></td>
                                <td>
                                    <?php if (!empty($log['entity_type']) && !empty($log['entity_id'])): ?>
                                        <span class="badge info">
                                            <?php echo htmlspecialchars($log['entity_type']); ?>: <?php echo htmlspecialchars($log['entity_id']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=1<?php echo (!empty($_GET)) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                            <a href="?page=<?php echo $page - 1; ?><?php echo (!empty($_GET)) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="disabled">
                                <i class="fas fa-angle-double-left"></i>
                            </span>
                            <span class="disabled">
                                <i class="fas fa-angle-left"></i>
                            </span>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $start_page + 4);
                        if ($end_page - $start_page < 4 && $start_page > 1) {
                            $start_page = max(1, $end_page - 4);
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?><?php echo (!empty($_GET)) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo (!empty($_GET)) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                            <a href="?page=<?php echo $total_pages; ?><?php echo (!empty($_GET)) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : ''; ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="disabled">
                                <i class="fas fa-angle-right"></i>
                            </span>
                            <span class="disabled">
                                <i class="fas fa-angle-double-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard"></i>
                    <p>Aucun journal d'administration trouvé avec les filtres sélectionnés</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php include_once '../includes/footer.php'; ?>

    <script>
        // Validation des dates
        document.querySelector('form').addEventListener('submit', function(e) {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            
            if (dateFrom && dateTo && new Date(dateFrom) > new Date(dateTo)) {
                e.preventDefault();
                alert('La date de début doit être antérieure à la date de fin.');
            }
        });
    </script>
</body>
</html>