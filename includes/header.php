<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    <title>Vue Globale des Notes - UV Platform</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #051e34;
            --secondary-bg: #0c2d48;
            --accent-color: #039be5;
            --text-light: #ffffff;
            --border-color: rgba(255, 255, 255, 0.1);
        }

        body {
            margin: 0;
            font-family: 'Google Sans', Arial, sans-serif;
            background-color: var(--primary-bg);
            color: var(--text-light);
            min-height: 100vh;
            display: block; /* Changé de flex à block */
        }

        /* Style du header */
        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid var(--border-color);
            position: relative;
            width: 100%;
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            position: relative;
            overflow: hidden;
        }

        /* Styles des icônes flottantes */
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
            font-size: 20px;
            color: var(--accent-color);
            opacity: 0;
            animation: floatIcon 3s ease-in-out infinite;
        }

        /* Position des icônes flottantes */
        .floating-icon:nth-child(1) { left: 10%; top: 20%; animation-delay: 0s; }
        .floating-icon:nth-child(2) { left: 30%; top: 60%; animation-delay: 0.5s; }
        .floating-icon:nth-child(3) { left: 50%; top: 30%; animation-delay: 1s; }
        .floating-icon:nth-child(4) { left: 70%; top: 50%; animation-delay: 1.5s; }
        .floating-icon:nth-child(5) { left: 90%; top: 40%; animation-delay: 2s; }

        /* Animation des icônes */
        @keyframes floatIcon {
            0% { transform: translateY(100%); opacity: 0; }
            50% { opacity: 0.3; }
            100% { transform: translateY(-100%); opacity: 0; }
        }

        h1 {
            font-size: 24px;
            color: var(--accent-color);
            margin: 0 0 20px 0;
            text-align: center;
        }

        /* Style de la navigation */
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
        }

        nav a:hover {
            background: rgba(3, 155, 229, 0.1);
        }

        /* Style spécial pour le bouton de déconnexion */
        nav a[href*="logout"] {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        nav a[href*="logout"]:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        /* Le contenu principal ne sera pas centré */
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            text-align: left; /* Assure que le texte n'est pas centré */
        }

        /* Responsive */
        @media (max-width: 768px) {
            nav ul {
                flex-direction: column;
                align-items: center;
            }

            nav a {
                width: 100%;
                justify-content: center;
            }
        }
        body, html {
            overflow-x: hidden;
            width: 100%;
        }

       

      
        

       
    </style>
</head>
<body>
    <header>
    <?php
    // Démarrer la session si elle n'est pas déjà démarrée
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
 
    ?>


        <div class="header-content">
            <!-- Icônes flottantes -->
            <div class="floating-icons">
                <i class="floating-icon fas fa-graduation-cap"></i>
                <i class="floating-icon fas fa-book"></i>
                <i class="floating-icon fas fa-user-graduate"></i>
                <i class="floating-icon fas fa-pencil-alt"></i>
                <i class="floating-icon fas fa-chart-line"></i>
            </div>

            <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
            
            <!-- Debug info (optionnel - à supprimer en production) -->
            <?php if (isset($_SESSION['user_id'])): ?>
            <div style="font-size: 10px; color: gray; text-align: center; margin-bottom: 10px;">
                User ID: <?php echo $_SESSION['user_id']; ?> | 
                Role: <?php echo isset($_SESSION['role']) ? $_SESSION['role'] : 'Non défini'; ?>
            </div>
            <?php endif; ?>
            
            <nav>
                <ul>
                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <!-- Navigation pour utilisateurs non connectés -->
                        <li><a href="/index.php"><i class="fas fa-home"></i> Accueil</a></li>
                        <li><a href="/pages/login.php"><i class="fas fa-sign-in-alt"></i> Connexion</a></li>
                    <?php else: ?>
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                            <!-- Navigation pour administrateurs -->
                            <li><a href="../admin/admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a></li>
                            <li><a href="../admin/user_management.php"><i class="fas fa-users"></i> Utilisateurs</a></li>
                            <li><a href="../grades/grades_management.php"><i class="fas fa-chart-bar"></i> Notes</a></li>
                            <li><a href="../admin/course_management.php"><i class="fas fa-book"></i> Cours</a></li>
                            <li><a href="../admin/class_management.php"><i class="fas fa-user-graduate"></i> Classes</a></li>
                            <li><a href="../admin/gestion_filieres.php"><i class="fas fa-sitemap"></i> Filières</a></li>
                            <li><a href="../grades/manage_teaching_units.php"><i class="fas fa-layer-group"></i> Unités d’enseignement</a></li>
                            <li><a href="../grades/assign_courses_to_units.php"><i class="fas fa-random"></i> Affecter cours aux UE</a></li>
                            <li><a href="../professor/quiz_manage.php"><i class="fas fa-question-circle"></i> Quiz</a></li>
                        <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'teacher'): ?>
                            <!-- Navigation pour enseignants -->
                            <li><a href="../professor/teacher_dashboard.php"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a></li>
                            <li><a href="../professor/courses.php"><i class="fas fa-book"></i> Mes cours</a></li>
                            <li><a href="../professor/grades_management.php"><i class="fas fa-chart-bar"></i> Gestion des notes</a></li>
                            <li><a href="../professor/quiz_manage.php"><i class="fas fa-question-circle"></i> Mes Quiz</a></li>
                            <li><a href="../professor/manage_discussions.php"><i class="fas fa-comments"></i> Discussions</a></li>
                            <li><a href="../professor/teacher_profile.php"><i class="fas fa-user"></i> Profil</a></li>
                            <li><a href="../professor/mes_honoraires.php"><i class="fas fa-money-bill-wave"></i> Mes Honoraires</a></li>
                        <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'student'): ?>
                            <!-- Navigation pour étudiants -->
                            <li><a href="../student/student_dashboard.php"><i class="fas fa-tachometer-alt"></i> Tableau de bord</a></li>
                            <li><a href="../student/student_grades.php"><i class="fas fa-chart-bar"></i> Mes notes</a></li>
                            <li><a href="../student/quiz_list.php"><i class="fas fa-question-circle"></i> Mes Quiz</a></li>
                            <li><a href="../student/schedule.php"><i class="fas fa-calendar-alt"></i> Emploi du temps</a></li>
                            <li><a href="../student/student_profile.php"><i class="fas fa-user"></i> Profil</a></li>
                        <?php else: ?>
                            <!-- Navigation par défaut (si le rôle n'est pas reconnu) -->
                            <li><a href="/index.php"><i class="fas fa-home"></i> Accueil</a></li>
                        <?php endif; ?>
                        <!-- Lien de déconnexion pour tous les utilisateurs connectés -->
                        <li><a href="../pages/logout.php" style="color: #dc3545;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <script>
    // Fonction pour afficher/masquer le dropdown des notifications
    function toggleNotifications() {
        const dropdown = document.getElementById('notificationDropdown');
        dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        
        // Cliquer en dehors pour fermer
        document.addEventListener('click', function closeNotif(e) {
            if (!e.target.closest('.notification-icon')) {
                dropdown.style.display = 'none';
                document.removeEventListener('click', closeNotif);
            }
        });
    }

    // Fonction pour marquer une notification comme lue via AJAX
    function markAsRead(notificationId, redirectUrl) {
        fetch(`mark_notification.php?notification_id=${notificationId}&ajax=1`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = redirectUrl;
                }
            });
        
        return false;
    }

    // Fonction pour calculer le temps écoulé
    function timeElapsed(datetime) {
        const now = new Date();
        const date = new Date(datetime);
        const diff = Math.floor((now - date) / 1000);
        
        if (diff < 60) {
            return 'À l\'instant';
        } else if (diff < 3600) {
            const minutes = Math.floor(diff / 60);
            return `Il y a ${minutes} minute${minutes > 1 ? 's' : ''}`;
        } else if (diff < 86400) {
            const hours = Math.floor(diff / 3600);
            return `Il y a ${hours} heure${hours > 1 ? 's' : ''}`;
        } else {
            const days = Math.floor(diff / 86400);
            return `Il y a ${days} jour${days > 1 ? 's' : ''}`;
        }
    }
    </script>
</body>
</html>