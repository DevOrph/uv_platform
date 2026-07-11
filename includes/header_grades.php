<?php
// Vérification de session (si pas déjà fait)
if (!isset($_SESSION['user_id'])) {
    session_start();
}

require_once __DIR__ . '/super_admin.php';

$current_user_id = $_SESSION['user_id'] ?? 'N/A';
$current_user_role = $_SESSION['role'] ?? 'N/A';
$current_user_name = $_SESSION['name'] ?? 'Utilisateur';

// Fonction pour déterminer les permissions d'examen
function getCurrentUserExamPermission($conn, $user_id) {
    if (is_super_admin($conn, $user_id)) {
        return true;
    }
    
    $query = "SELECT * FROM exam_permissions WHERE user_id = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

$has_exam_permission = isset($conn) ? getCurrentUserExamPermission($conn, $current_user_id) : false;
?>

<meta name="csrf-token" content="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
<script>
(function() {
    var token = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!token) return;
    window.CSRF_TOKEN = token;
    var originalFetch = window.fetch;
    window.fetch = function(url, options) {
        options = options || {};
        var method = (options.method || 'GET').toUpperCase();
        if (method === 'GET') return originalFetch(url, options);
        options.headers = options.headers || {};
        if (options.headers instanceof Headers) {
            options.headers.set('X-CSRF-Token', token);
        } else {
            options.headers['X-CSRF-Token'] = token;
        }
        return originalFetch(url, options);
    };
})();
</script>
<style>
/* Styles du header amélioré */
:root {
    --drawer-width: 320px;
    --drawer-bg: #0a1929;
    --drawer-hover: rgba(3, 155, 229, 0.1);
}

/* Header principal */
.modern-header {
    background: linear-gradient(135deg, var(--secondary-bg) 0%, var(--primary-bg) 100%);
    padding: 15px 0;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    border-bottom: 1px solid var(--border-color);
    position: relative;
    width: 100%;
    z-index: 999;
    margin-bottom: 0;
}

.header-content {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    flex-direction: column;
}

/* Bouton hamburger */
.menu-toggle {
    position: absolute;
    left: 20px;
    top: 50%;
    transform: translateY(-50%);
    background: linear-gradient(135deg, var(--accent-color), #0277bd);
    border: none;
    color: white;
    font-size: 20px;
    cursor: pointer;
    padding: 12px;
    border-radius: 50%;
    transition: all 0.3s ease;
    z-index: 1001;
    box-shadow: 0 4px 15px rgba(3, 155, 229, 0.3);
}

.menu-toggle:hover {
    transform: translateY(-50%) scale(1.1);
    box-shadow: 0 6px 20px rgba(3, 155, 229, 0.5);
}

/* Icônes flottantes */
.floating-icons {
    position: absolute;
    width: 100%;
    height: 100%;
    top: 0;
    left: 0;
    pointer-events: none;
    z-index: 1;
}

.floating-icon {
    position: absolute;
    font-size: 18px;
    color: rgba(3, 155, 229, 0.3);
    opacity: 0;
    animation: floatIcon 4s ease-in-out infinite;
}

.floating-icon:nth-child(1) { left: 15%; top: 20%; animation-delay: 0s; }
.floating-icon:nth-child(2) { left: 35%; top: 70%; animation-delay: 1s; }
.floating-icon:nth-child(3) { left: 55%; top: 25%; animation-delay: 2s; }
.floating-icon:nth-child(4) { left: 75%; top: 60%; animation-delay: 3s; }
.floating-icon:nth-child(5) { left: 85%; top: 35%; animation-delay: 0.5s; }

@keyframes floatIcon {
    0% { transform: translateY(150%); opacity: 0; }
    20% { opacity: 0.6; }
    80% { opacity: 0.6; }
    100% { transform: translateY(-150%); opacity: 0; }
}

.header-title {
    font-size: 28px;
    color: var(--text-light);
    margin: 0 0 15px 0;
    text-align: center;
    font-weight: 600;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    z-index: 2;
    position: relative;
}

.header-title i {
    color: var(--accent-color);
    margin-right: 10px;
}

/* User info badge */
.user-badge {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255, 255, 255, 0.1);
    padding: 8px 15px;
    border-radius: 25px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    z-index: 2;
}

.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-color), #0277bd);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
}

/* Navigation rapide dans le header */
.quick-nav {
    display: flex;
    justify-content: center;
    gap: 15px;
    flex-wrap: wrap;
    z-index: 2;
    position: relative;
}

.quick-nav-item {
    color: rgba(255, 255, 255, 0.8);
    text-decoration: none;
    padding: 8px 16px;
    border-radius: 20px;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(5px);
    font-size: 14px;
}

.quick-nav-item:hover {
    background: rgba(3, 155, 229, 0.2);
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(3, 155, 229, 0.3);
    border-color: var(--accent-color);
}

.quick-nav-item.logout {
    background: rgba(244, 67, 54, 0.1);
    border-color: rgba(244, 67, 54, 0.3);
    color: #ff6b6b;
}

.quick-nav-item.logout:hover {
    background: rgba(244, 67, 54, 0.2);
    color: #ff5252;
}

/* Drawer styles */
.grades-drawer {
    position: fixed;
    top: 0;
    left: -var(--drawer-width);
    width: var(--drawer-width);
    height: 100vh;
    background: var(--drawer-bg);
    box-shadow: 2px 0 20px rgba(0, 0, 0, 0.3);
    transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 1000;
    overflow-y: auto;
    border-right: 1px solid var(--border-color);
}

.grades-drawer.open {
    left: 0;
}

.drawer-header {
    padding: 25px 20px;
    border-bottom: 1px solid var(--border-color);
    background: linear-gradient(135deg, var(--secondary-bg), var(--primary-bg));
    position: sticky;
    top: 0;
    z-index: 10;
}

.drawer-title {
    color: var(--accent-color);
    font-size: 20px;
    font-weight: bold;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.drawer-content {
    padding: 0;
}

.user-info {
    padding: 20px;
    background: rgba(3, 155, 229, 0.1);
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 10px;
}

.user-name {
    font-weight: bold;
    color: var(--accent-color);
    font-size: 16px;
}

.user-role {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase;
    margin-top: 4px;
}

.menu-section {
    padding: 15px 20px 8px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
    color: var(--accent-color);
    letter-spacing: 1px;
    border-top: 1px solid var(--border-color);
    margin-top: 10px;
}

.menu-section:first-child {
    border-top: none;
    margin-top: 0;
}

.menu-item {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    color: var(--text-light);
    text-decoration: none;
    transition: all 0.3s ease;
    border-left: 3px solid transparent;
    position: relative;
    gap: 12px;
}

.menu-item:hover {
    background: var(--drawer-hover);
    border-left-color: var(--accent-color);
    padding-left: 25px;
}

.menu-item.active {
    background: var(--drawer-hover);
    border-left-color: var(--accent-color);
    color: var(--accent-color);
}

.menu-item i {
    width: 20px;
    text-align: center;
    font-size: 16px;
}

.permission-indicator {
    margin-left: auto;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
}

.permission-granted {
    background: var(--success-color);
    color: white;
}

.permission-limited {
    background: var(--warning-color);
    color: white;
}

/* Overlay */
.drawer-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    z-index: 999;
}

.drawer-overlay.active {
    opacity: 1;
    visibility: visible;
}

/* Responsive */
@media (max-width: 768px) {
    .header-content {
        padding: 0 15px;
    }
    
    .user-badge {
        right: 15px;
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .user-avatar {
        width: 28px;
        height: 28px;
    }
    
    .header-title {
        font-size: 24px;
        margin-bottom: 10px;
    }
    
    .quick-nav {
        gap: 8px;
    }
    
    .quick-nav-item {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .grades-drawer {
        width: 280px;
        left: -280px;
    }
    
    :root {
        --drawer-width: 280px;
    }
}

@media (max-width: 480px) {
    .grades-drawer {
        width: 100%;
        left: -100%;
    }
    
    .user-badge {
        position: relative;
        right: auto;
        top: auto;
        transform: none;
        margin: 10px auto 0;
        width: fit-content;
    }
    
    .header-content {
        padding-top: 60px;
    }
    
    .menu-toggle {
        top: 20px;
        left: 15px;
    }
}

/* Smooth scrollbar */
.grades-drawer::-webkit-scrollbar {
    width: 6px;
}

.grades-drawer::-webkit-scrollbar-track {
    background: var(--drawer-bg);
}

.grades-drawer::-webkit-scrollbar-thumb {
    background: var(--accent-color);
    border-radius: 3px;
}

.grades-drawer::-webkit-scrollbar-thumb:hover {
    background: #0288d1;
}
</style>

<header class="modern-header">
    <div class="header-content">
        <!-- Bouton hamburger -->
        <button class="menu-toggle" onclick="toggleGradesDrawer()" aria-label="Menu Navigation">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Badge utilisateur -->
        <div class="user-badge">
            <div class="user-avatar">
                <?= strtoupper(substr($current_user_name, 0, 1)) ?>
            </div>
            <div>
                <div style="font-weight: 600;"><?= htmlspecialchars($current_user_name) ?></div>
                <div style="font-size: 11px; opacity: 0.7;"><?= ucfirst($current_user_role) ?></div>
            </div>
        </div>

        <!-- Icônes flottantes -->
        <div class="floating-icons">
            <i class="floating-icon fas fa-star"></i>
            <i class="floating-icon fas fa-chart-line"></i>
            <i class="floating-icon fas fa-graduation-cap"></i>
            <i class="floating-icon fas fa-pencil-alt"></i>
            <i class="floating-icon fas fa-clipboard-check"></i>
        </div>

        <h1 class="header-title">
            <i class="fas fa-chart-bar"></i>
            Gestion des Notes
        </h1>
        
        <!-- Navigation rapide -->
        <nav class="quick-nav">
            <?php if ($current_user_role === 'admin'): ?>
                <a href="../admin/admin_dashboard.php" class="quick-nav-item">
                    <i class="fas fa-tachometer-alt"></i> Tableau de bord
                </a>
                <a href="../admin/user_management.php" class="quick-nav-item">
                    <i class="fas fa-users"></i> Utilisateurs
                </a>
            <?php endif; ?>
            
            <a href="../grades/global_grades.php" class="quick-nav-item">
                <i class="fas fa-list"></i> Toutes les notes
            </a>
            
            <a href="../grades/grade_statistics.php" class="quick-nav-item">
                <i class="fas fa-chart-pie"></i> Statistiques
            </a>
            
            <a href="../pages/logout.php" class="quick-nav-item logout">
                <i class="fas fa-sign-out-alt"></i> Déconnexion
            </a>
        </nav>
    </div>
</header>

<!-- Drawer de navigation -->
<nav class="grades-drawer" id="gradesDrawer">
    <div class="drawer-header">
        <h3 class="drawer-title">
            <i class="fas fa-chart-bar"></i>
            Navigation Notes
        </h3>
    </div>
    
    <div class="drawer-content">
        <!-- Info utilisateur -->
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($current_user_name) ?></div>
            <div class="user-role"><?= ucfirst($current_user_role) ?> - ID: <?= htmlspecialchars($current_user_id) ?></div>
        </div>

        <!-- Menu de gestion des notes -->
        <div class="menu-section">GESTION DES NOTES</div>
        <a href="../grades/grades_management.php" class="menu-item <?= basename($_SERVER['PHP_SELF']) == 'grades_management.php' ? 'active' : '' ?>">
            <i class="fas fa-plus"></i>
            <span>Saisir des Notes</span>
            <?php if ($has_exam_permission): ?>
                <span class="permission-indicator permission-granted">EXAM</span>
            <?php else: ?>
                <span class="permission-indicator permission-limited">CC</span>
            <?php endif; ?>
        </a>
        <a href="../grades/global_grades.php" class="menu-item <?= basename($_SERVER['PHP_SELF']) == 'global_grades.php' ? 'active' : '' ?>">
            <i class="fas fa-list"></i>
            <span>Toutes les Notes</span>
        </a>
        <a href="../grades/grade_statistics.php" class="menu-item <?= basename($_SERVER['PHP_SELF']) == 'grade_statistics.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-bar"></i>
            <span>Statistiques des Notes</span>
        </a>
        <a href="../grades/evaluation_periods.php" class="menu-item <?= basename($_SERVER['PHP_SELF']) == 'evaluation_periods.php' ? 'active' : '' ?>">
            <i class="fas fa-calendar"></i>
            <span>Périodes d'Évaluation</span>
        </a>

        <?php if ($current_user_role === 'admin' || is_super_admin($conn, $current_user_id)): ?>
            <div class="menu-section">PERMISSIONS D'EXAMEN</div>
            <a href="../grades/exam_permissions.php" class="menu-item <?= basename($_SERVER['PHP_SELF']) == 'exam_permissions.php' ? 'active' : '' ?>">
                <i class="fas fa-shield-alt"></i>
                <span>Gérer les Permissions</span>
            </a>
            <a href="../admin/admin_permissions_overview.php" class="menu-item">
                <i class="fas fa-eye"></i>
                <span>Vue d'ensemble</span>
            </a>
        <?php endif; ?>

        <?php if ($current_user_role === 'admin'): ?>
            <div class="menu-section">ADMINISTRATION</div>
            <a href="../admin/admin_dashboard.php" class="menu-item">
                <i class="fas fa-tachometer-alt"></i>
                <span>Tableau de bord</span>
            </a>
            <a href="../admin/user_management.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>Gestion Utilisateurs</span>
            </a>
            <a href="../admin/course_management.php" class="menu-item">
                <i class="fas fa-book"></i>
                <span>Gestion Cours</span>
            </a>
            <a href="../admin/class_management.php" class="menu-item">
                <i class="fas fa-graduation-cap"></i>
                <span>Gestion Classes</span>
            </a>
        <?php endif; ?>

        <div class="menu-section">OUTILS</div>
        <a href="../grades/grade_export.php" class="menu-item">
            <i class="fas fa-download"></i>
            <span>Export des Données</span>
        </a>
        
        <?php if ($current_user_role === 'teacher'): ?>
            <a href="../teacher/teacher_dashboard.php" class="menu-item">
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Mon Espace Enseignant</span>
            </a>
        <?php endif; ?>

        <div class="menu-section">GÉNÉRAL</div>
        <a href="../notifications.php" class="menu-item">
            <i class="fas fa-bell"></i>
            <span>Notifications</span>
        </a>
        <a href="../support/help.php" class="menu-item">
            <i class="fas fa-question-circle"></i>
            <span>Aide</span>
        </a>
        <a href="../pages/logout.php" class="menu-item" style="color: #dc3545;">
            <i class="fas fa-sign-out-alt"></i>
            <span>Déconnexion</span>
        </a>
    </div>
</nav>

<!-- Overlay -->
<div class="drawer-overlay" id="gradesDrawerOverlay" onclick="closeGradesDrawer()"></div>

<script>
function toggleGradesDrawer() {
    const drawer = document.getElementById('gradesDrawer');
    const overlay = document.getElementById('gradesDrawerOverlay');
    const menuToggle = document.querySelector('.menu-toggle');
    
    if (drawer.classList.contains('open')) {
        closeGradesDrawer();
    } else {
        openGradesDrawer();
    }
}

function openGradesDrawer() {
    const drawer = document.getElementById('gradesDrawer');
    const overlay = document.getElementById('gradesDrawerOverlay');
    const menuToggle = document.querySelector('.menu-toggle i');
    
    drawer.classList.add('open');
    overlay.classList.add('active');
    menuToggle.className = 'fas fa-times';
    document.body.style.overflow = 'hidden';
}

function closeGradesDrawer() {
    const drawer = document.getElementById('gradesDrawer');
    const overlay = document.getElementById('gradesDrawerOverlay');
    const menuToggle = document.querySelector('.menu-toggle i');
    
    drawer.classList.remove('open');
    overlay.classList.remove('active');
    menuToggle.className = 'fas fa-bars';
    document.body.style.overflow = 'auto';
}

// Fermer le drawer avec Escape
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeGradesDrawer();
    }
});

// Gestion responsive
window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        document.body.style.overflow = 'auto';
    }
});
</script>