<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'teacher' && $_SESSION['role'] !== 'admin')) {
    header("Location: ../pages/login.php?error=access_denied");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Paramètres de filtrage
$filter_student = isset($_GET['student']) ? $_GET['student'] : '';
$filter_action = isset($_GET['action']) ? $_GET['action'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Pagination
$perPage = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $perPage;

// Construction de la requête
$query = "SELECT 
            gh.id,
            gh.grade_id,
            gh.action_type,
            gh.old_grade,
            gh.new_grade,
            gh.old_comment,
            gh.new_comment,
            u_student.name AS student_name,
            c.name AS course_name,
            et.name AS evaluation_type,
            u_changed.name AS changed_by_name,
            gh.changed_at,
            gh.ip_address
          FROM grades_history gh
          LEFT JOIN users u_student ON gh.student_id = u_student.id
          LEFT JOIN users u_changed ON gh.changed_by = u_changed.id
          LEFT JOIN courses c ON gh.course_id = c.id
          LEFT JOIN evaluation_types et ON gh.new_evaluation_type_id = et.id
          WHERE 1=1";

$params = [];
$types = "";

// Filtres
if ($filter_student) {
    $query .= " AND u_student.name LIKE ?";
    $params[] = "%$filter_student%";
    $types .= "s";
}

if ($filter_action) {
    $query .= " AND gh.action_type = ?";
    $params[] = $filter_action;
    $types .= "s";
}

if ($filter_date_from) {
    $query .= " AND DATE(gh.changed_at) >= ?";
    $params[] = $filter_date_from;
    $types .= "s";
}

if ($filter_date_to) {
    $query .= " AND DATE(gh.changed_at) <= ?";
    $params[] = $filter_date_to;
    $types .= "s";
}

// Restriction pour les enseignants
if ($user_role === 'teacher') {
    $query .= " AND gh.changed_by = ?";
    $params[] = $user_id;
    $types .= "s";
}

$query .= " ORDER BY gh.changed_at DESC LIMIT ?, ?";
$params[] = $offset;
$params[] = $perPage;
$types .= "ii";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$history = $stmt->get_result();

// Compter le total
$count_query = str_replace("SELECT gh.id,", "SELECT COUNT(*) as total", $query);
$count_query = preg_replace('/ORDER BY.*LIMIT.*/', '', $count_query);
$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_params = array_slice($params, 0, -2); // Enlever offset et limit
    $count_types = substr($types, 0, -2);
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
}
$count_stmt->execute();
$total_result = $count_stmt->get_result();
$total = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total / $perPage);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des Modifications - UV Platform</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --success-color: #4CAF50;
            --warning-color: #ff9800;
            --error-color: #f44336;
        }

        body {
            background: linear-gradient(135deg, var(--primary-bg) 0%, var(--secondary-bg) 100%);
            color: #ffffff;
            font-family: 'Google Sans', Arial, sans-serif;
            min-height: 100vh;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            backdrop-filter: blur(10px);
        }

        .page-header h1 {
            margin: 0;
            color: var(--accent-color);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .filters {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            backdrop-filter: blur(10px);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 14px;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 14px;
        }

        .btn-filter {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.4);
        }

        .history-table {
            background: rgba(255, 255, 255, 0.1);
            padding: 25px;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        th {
            background: rgba(255, 255, 255, 0.1);
            font-weight: 600;
            color: var(--accent-color);
        }

        .action-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .action-insert {
            background: rgba(76, 175, 80, 0.2);
            color: #4CAF50;
        }

        .action-update {
            background: rgba(255, 152, 0, 0.2);
            color: #ff9800;
        }

        .action-delete {
            background: rgba(244, 67, 54, 0.2);
            color: #f44336;
        }

        .grade-change {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .grade-old {
            color: #e74c3c;
            text-decoration: line-through;
        }

        .grade-new {
            color: #2ecc71;
            font-weight: 600;
        }

        .pagination {
            margin-top: 25px;
            text-align: center;
        }

        .pagination a {
            margin: 0 5px;
            padding: 8px 12px;
            text-decoration: none;
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            background-color: var(--accent-color);
        }

        .pagination a.active {
            background-color: var(--accent-color);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
            text-align: center;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--accent-color);
        }

        .stat-label {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
            margin-top: 5px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: rgba(255, 255, 255, 0.7);
        }

        .empty-state i {
            font-size: 64px;
            color: var(--accent-color);
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header_discussion.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-history"></i>
                Historique des Modifications
            </h1>
            <p style="margin: 10px 0 0 0; color: rgba(255,255,255,0.8);">
                Traçabilité complète de toutes les actions sur les notes
            </p>
        </div>

        <!-- Statistiques -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total; ?></div>
                <div class="stat-label">Modifications totales</div>
            </div>
            <?php
            $stats_query = "SELECT 
                              SUM(CASE WHEN action_type = 'INSERT' THEN 1 ELSE 0 END) as insertions,
                              SUM(CASE WHEN action_type = 'UPDATE' THEN 1 ELSE 0 END) as updates,
                              SUM(CASE WHEN action_type = 'DELETE' THEN 1 ELSE 0 END) as deletions
                            FROM grades_history";
            if ($user_role === 'teacher') {
                $stats_query .= " WHERE changed_by = '$user_id'";
            }
            $stats_result = $conn->query($stats_query);
            $stats = $stats_result->fetch_assoc();
            ?>
            <div class="stat-card">
                <div class="stat-value" style="color: #4CAF50;"><?php echo $stats['insertions']; ?></div>
                <div class="stat-label">Ajouts</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #ff9800;"><?php echo $stats['updates']; ?></div>
                <div class="stat-label">Modifications</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #f44336;"><?php echo $stats['deletions']; ?></div>
                <div class="stat-label">Suppressions</div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="filters">
            <form method="get" action="">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label><i class="fas fa-user"></i> Étudiant</label>
                        <input type="text" name="student" value="<?php echo htmlspecialchars($filter_student); ?>" placeholder="Nom de l'étudiant">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-tasks"></i> Action</label>
                        <select name="action">
                            <option value="">Toutes</option>
                            <option value="INSERT" <?php echo $filter_action === 'INSERT' ? 'selected' : ''; ?>>Ajout</option>
                            <option value="UPDATE" <?php echo $filter_action === 'UPDATE' ? 'selected' : ''; ?>>Modification</option>
                            <option value="DELETE" <?php echo $filter_action === 'DELETE' ? 'selected' : ''; ?>>Suppression</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Du</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                    </div>
                    <div class="filter-group">
                        <label><i class="fas fa-calendar"></i> Au</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                    </div>
                </div>
                <button type="submit" class="btn-filter">
                    <i class="fas fa-filter"></i> Filtrer
                </button>
                <a href="?" class="btn-filter" style="background: rgba(255,255,255,0.1); text-decoration: none; display: inline-block; margin-left: 10px;">
                    <i class="fas fa-times"></i> Réinitialiser
                </a>
            </form>
        </div>

        <!-- Tableau d'historique -->
        <div class="history-table">
            <?php if ($history->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date/Heure</th>
                        <th>Action</th>
                        <th>Étudiant</th>
                        <th>Cours</th>
                        <th>Type</th>
                        <th>Changement</th>
                        <th>Modifié par</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $history->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i:s', strtotime($row['changed_at'])); ?></td>
                        <td>
                            <span class="action-badge action-<?php echo strtolower($row['action_type']); ?>">
                                <?php 
                                    echo $row['action_type'] === 'INSERT' ? 'Ajout' : 
                                         ($row['action_type'] === 'UPDATE' ? 'Modification' : 'Suppression'); 
                                ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($row['student_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['course_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['evaluation_type']); ?></td>
                        <td>
                            <?php if ($row['action_type'] === 'INSERT'): ?>
                                <span class="grade-new"><?php echo $row['new_grade']; ?>/20</span>
                            <?php elseif ($row['action_type'] === 'UPDATE'): ?>
                                <div class="grade-change">
                                    <?php if ($row['old_grade'] != $row['new_grade']): ?>
                                        <span class="grade-old"><?php echo $row['old_grade']; ?></span>
                                        <i class="fas fa-arrow-right"></i>
                                        <span class="grade-new"><?php echo $row['new_grade']; ?></span>
                                    <?php else: ?>
                                        <span>Commentaire modifié</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="grade-old"><?php echo $row['old_grade']; ?>/20</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['changed_by_name']); ?></td>
                        <td><small><?php echo htmlspecialchars($row['ip_address'] ?? 'N/A'); ?></small></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&student=<?php echo urlencode($filter_student); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>" 
                       class="<?php echo $i == $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <h3>Aucune modification trouvée</h3>
                <p>Ajustez les filtres pour voir l'historique</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>