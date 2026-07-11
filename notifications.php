<?php
// notifications.php
session_start();
require_once '../includes/db_connect.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: pages/login.html");
    exit();
}



// Marquer toutes les notifications comme lues
if (isset($_GET['mark_all_read'])) {
    $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $_SESSION['user_id']);
    $stmt->execute();
    
    header("Location: notifications.php");
    exit();
}

// Marquer une notification comme lue
if (isset($_GET['mark_read']) && isset($_GET['id'])) {
    $notification_id = $_GET['id'];
    $sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $notification_id, $_SESSION['user_id']);
    $stmt->execute();
    
    // Rediriger vers la page de notification ou vers la page liée
    if (isset($_GET['redirect'])) {
        header("Location: " . $_GET['redirect']);
        exit();
    } else {
        header("Location: notifications.php");
        exit();
    }
}

// Supprimer une notification
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $notification_id = $_GET['id'];
    $sql = "DELETE FROM notifications WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $notification_id, $_SESSION['user_id']);
    $stmt->execute();
    
    header("Location: notifications.php");
    exit();
}

// Récupérer les notifications
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($current_page - 1) * $per_page;

// Filtrer par type si spécifié
$filter = isset($_GET['filter']) ? $_GET['filter'] : '';
$filter_clause = '';
$filter_params = ["s"];
$filter_values = [$_SESSION['user_id']];

if (!empty($filter) && $filter !== 'all') {
    $filter_clause = " AND type = ?";
    $filter_params[] = "s";
    $filter_values[] = $filter;
}

// Filtrer par lu/non lu si spécifié
$read_filter = isset($_GET['read']) ? $_GET['read'] : 'all';
if ($read_filter !== 'all') {
    $is_read = ($read_filter === 'read') ? 1 : 0;
    $filter_clause .= " AND is_read = ?";
    $filter_params[] = "i";
    $filter_values[] = $is_read;
}

// Compter le nombre total de notifications
$sql = "SELECT COUNT(*) as total FROM notifications WHERE user_id = ?" . $filter_clause;
$stmt = $conn->prepare($sql);
$stmt->bind_param(...array_merge($filter_params, $filter_values));
$stmt->execute();
$total_result = $stmt->get_result();
$total_notifications = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_notifications / $per_page);

// Récupérer les notifications pour la page actuelle
$sql = "SELECT n.*, c.name as course_name, u.name as sender_name 
        FROM notifications n
        LEFT JOIN courses c ON n.course_id = c.id
        LEFT JOIN users u ON n.sender_id = u.id
        WHERE n.user_id = ?" . $filter_clause . "
        ORDER BY n.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$filter_params[] = "i";
$filter_params[] = "i";
$filter_values[] = $per_page;
$filter_values[] = $offset;

$stmt->bind_param(...array_merge($filter_params, $filter_values));
$stmt->execute();
$notifications = $stmt->get_result();

// Fonction pour obtenir l'icône en fonction du type de notification
function getNotificationIcon($type) {
    switch ($type) {
        case 'message':
            return 'fa-comment';
        case 'document':
            return 'fa-file';
        case 'visio':
            return 'fa-video';
        case 'announcement':
            return 'fa-bullhorn';
        case 'grade':
            return 'fa-chart-line';
        default:
            return 'fa-bell';
    }
}

// Fonction pour obtenir la classe CSS en fonction du type de notification
function getNotificationClass($type) {
    switch ($type) {
        case 'message':
            return 'notification-message';
        case 'document':
            return 'notification-document';
        case 'visio':
            return 'notification-visio';
        case 'announcement':
            return 'notification-announcement';
        case 'grade':
            return 'notification-grade';
        default:
            return '';
    }
}

// Fonction pour formater la date relative
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    // Calculer les semaines manuellement
    $weeks = floor($diff->d / 7);
    $diff->d -= $weeks * 7;

    $string = array(
        'y' => 'an',
        'm' => 'mois',
        'w' => $weeks, // Utiliser la variable $weeks au lieu de $diff->w
        'd' => 'jour',
        'h' => 'heure',
        'i' => 'minute',
        's' => 'seconde',
    );
    
    foreach ($string as $k => &$v) {
        if ($k === 'w') {
            if ($weeks) {
                $v = $weeks . ' semaine' . ($weeks > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        } else if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 && $k != 'm' ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? 'Il y a ' . implode(', ', $string) : 'À l\'instant';
}

// Inclure le header
include 'pages/includes/header.php';
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centre de notifications - Université Virtuelle</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .notifications-container {
            max-width: 1000px;
            margin: 30px auto;
            background: var(--secondary-bg);
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .notifications-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .notifications-title {
            font-size: 1.5rem;
            color: var(--accent-color);
            margin: 0;
        }

        .notifications-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--accent-color);
            color: white;
        }

        .btn-primary:hover {
            background: #0288d1;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--accent-color);
            color: var(--accent-color);
        }

        .btn-outline:hover {
            background: rgba(3, 155, 229, 0.1);
        }

        .notifications-filters {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            width: 100%;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .filter-label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.7);
        }

        .filter-select {
            width: 100%;
            padding: 8px 12px;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-light);
            font-size: 14px;
        }

        .notification-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .notification-item {
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s;
            position: relative;
        }

        .notification-item:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-link {
            padding: 20px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
            text-decoration: none;
            color: var(--text-light);
            position: relative;
        }

        .notification-unread {
            background-color: rgba(3, 155, 229, 0.05);
        }

        .notification-unread::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--accent-color);
        }

        .notification-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            color: var(--accent-color);
            font-size: 20px;
        }

        .notification-content {
            flex: 1;
        }

        .notification-message {
            font-size: 16px;
            margin-bottom: 5px;
            font-weight: 400;
        }

        .notification-info {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.6);
            margin-top: 8px;
        }

        .notification-course, .notification-sender, .notification-time {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notification-actions {
            display: flex;
            gap: 10px;
            margin-left: auto;
        }

        .notification-btn {
            background: transparent;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            font-size: 16px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
        }

        .notification-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.8);
        }

        .notification-btn.delete:hover {
            color: #dc3545;
        }

        .notification-btn.mark-read:hover {
            color: #28a745;
        }

        .notification-pagination {
            display: flex;
            justify-content: center;
            padding: 20px;
            border-top: 1px solid var(--border-color);
            gap: 5px;
        }

        .page-link {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-light);
            text-decoration: none;
            transition: all 0.3s;
        }

        .page-link:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .page-link.active {
            background: var(--accent-color);
            color: white;
            pointer-events: none;
        }

        .notification-empty {
            padding: 40px 20px;
            text-align: center;
            color: rgba(255, 255, 255, 0.6);
        }

        .notification-empty i {
            font-size: 48px;
            margin-bottom: 15px;
            color: rgba(255, 255, 255, 0.2);
        }

        .notification-empty p {
            font-size: 16px;
            margin: 0;
        }

        /* Couleurs spécifiques pour les types de notifications */
        .notification-message .notification-icon {
            color: #0d6efd;
        }

        .notification-document .notification-icon {
            color: #198754;
        }

        .notification-visio .notification-icon {
            color: #dc3545;
        }

        .notification-announcement .notification-icon {
            color: #ffc107;
        }

        .notification-grade .notification-icon {
            color: #0dcaf0;
        }
    </style>
</head>
<body>
    <div class="notifications-container">
        <div class="notifications-header">
            <h1 class="notifications-title">Centre de notifications</h1>
            
            <div class="notifications-actions">
                <?php if ($total_notifications > 0): ?>
                <a href="notifications.php?mark_all_read=1" class="btn btn-outline">
                    <i class="fas fa-check-double"></i> Marquer tout comme lu
                </a>
                <?php endif; ?>
            </div>
            
            <form class="notifications-filters" method="GET" action="notifications.php">
                <div class="filter-group">
                    <label class="filter-label">Type de notification</label>
                    <select name="filter" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>Tous les types</option>
                        <option value="message" <?php echo $filter === 'message' ? 'selected' : ''; ?>>Messages</option>
                        <option value="document" <?php echo $filter === 'document' ? 'selected' : ''; ?>>Documents</option>
                        <option value="visio" <?php echo $filter === 'visio' ? 'selected' : ''; ?>>Visioconférences</option>
                        <option value="announcement" <?php echo $filter === 'announcement' ? 'selected' : ''; ?>>Annonces</option>
                        <option value="grade" <?php echo $filter === 'grade' ? 'selected' : ''; ?>>Notes</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label class="filter-label">Statut</label>
                    <select name="read" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $read_filter === 'all' ? 'selected' : ''; ?>>Tous</option>
                        <option value="unread" <?php echo $read_filter === 'unread' ? 'selected' : ''; ?>>Non lus</option>
                        <option value="read" <?php echo $read_filter === 'read' ? 'selected' : ''; ?>>Lus</option>
                    </select>
                </div>
            </form>
        </div>
        
        <?php if ($notifications->num_rows > 0): ?>
            <ul class="notification-list">
                <?php while ($notification = $notifications->fetch_assoc()): ?>
                    <li class="notification-item <?php echo !$notification['is_read'] ? 'notification-unread' : ''; ?> <?php echo getNotificationClass($notification['type']); ?>">
                        <div class="notification-link">
                            <div class="notification-icon">
                                <i class="fas <?php echo getNotificationIcon($notification['type']); ?>"></i>
                            </div>
                            <div class="notification-content">
                                <div class="notification-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                <div class="notification-info">
                                    <?php if (!empty($notification['course_name'])): ?>
                                        <div class="notification-course">
                                            <i class="fas fa-book"></i>
                                            <?php echo htmlspecialchars($notification['course_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($notification['sender_name'])): ?>
                                        <div class="notification-sender">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($notification['sender_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="notification-time">
                                        <i class="fas fa-clock"></i>
                                        <?php echo time_elapsed_string($notification['created_at']); ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="notification-actions">
                                <?php if (!$notification['is_read']): ?>
                                    <a href="notifications.php?mark_read=1&id=<?php echo $notification['id']; ?>&redirect=<?php echo urlencode($notification['link']); ?>" class="notification-btn mark-read" title="Marquer comme lu">
                                        <i class="fas fa-check"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <a href="<?php echo htmlspecialchars($notification['link']); ?>" class="notification-btn view" title="Voir">
                                    <i class="fas fa-eye"></i>
                                </a>
                                
                                <a href="notifications.php?delete=1&id=<?php echo $notification['id']; ?>" class="notification-btn delete" title="Supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette notification ?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    </li>
                <?php endwhile; ?>
            </ul>
            
            <?php if ($total_pages > 1): ?>
                <div class="notification-pagination">
                    <?php if ($current_page > 1): ?>
                        <a href="notifications.php?page=<?php echo $current_page - 1; ?>&filter=<?php echo $filter; ?>&read=<?php echo $read_filter; ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $start_page + 4);
                    
                    if ($end_page - $start_page < 4) {
                        $start_page = max(1, $end_page - 4);
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++): 
                    ?>
                        <a href="notifications.php?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>&read=<?php echo $read_filter; ?>" class="page-link <?php echo $i === $current_page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a href="notifications.php?page=<?php echo $current_page + 1; ?>&filter=<?php echo $filter; ?>&read=<?php echo $read_filter; ?>" class="page-link">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="notification-empty">
                <i class="fas fa-bell-slash"></i>
                <p>Aucune notification ne correspond à vos critères</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Fonction pour fermer automatiquement les alertes
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                }, 3000);
            });
        });
    </script>
</body>
</html>

<?php
// Fermer la connexion à la base de données
$conn->close();
?>