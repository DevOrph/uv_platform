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
    <link rel="stylesheet" href="/assets/css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <title>UV Platform</title>
    <style>
        body, html {
    overflow-x: hidden;
    width: 100%;
}

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
        }

        header {
            background: var(--secondary-bg);
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid var(--border-color);
            position: relative;
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

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
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

        /* Style spécial pour le bouton de déconnexion */
        nav a[href*="logout"] {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        nav a[href*="logout"]:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        /* Style pour la page active */
        nav a.active {
            background: var(--accent-color);
            color: white;
        }

        nav a.active::before {
            transform: scaleX(1);
        }

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
    </style>
</head>
<body>
    <header>
        <div class="header-content">
            <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
            <nav>
                <ul>
                    <?php
                    $current_page = basename($_SERVER['PHP_SELF']);
                    if (!isset($_SESSION['user_id'])): ?>
                        <li><a href="/index.php" class="<?php echo $current_page == 'index.php' ? 'active' : ''; ?>">
                            <i class="fas fa-home"></i> Accueil
                        </a></li>
                        <li><a href="/pages/login.php" class="<?php echo $current_page == 'login.php' ? 'active' : ''; ?>">
                            <i class="fas fa-sign-in-alt"></i> Connexion
                        </a></li>
                    <?php else: ?>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <li><a href="../admin/admin_dashboard.php" class="<?php echo $current_page == 'admin_dashboard.php' ? 'active' : ''; ?>">
                                <i class="fas fa-tachometer-alt"></i> Tableau de bord
                            </a></li>
                            <li><a href="../admin/user_management.php" class="<?php echo $current_page == 'user_management.php' ? 'active' : ''; ?>">
                                <i class="fas fa-users"></i> Utilisateurs
                            </a></li>
                            <li><a href="../grades/grades_management.php" class="<?php echo $current_page == 'grades_management.php' ? 'active' : ''; ?>">
                                <i class="fas fa-chart-bar"></i> Notes
                            </a></li>
                        <?php elseif ($_SESSION['role'] === 'teacher'): ?>
                            <li><a href="../professor/teacher_dashboard.php" class="<?php echo $current_page == 'teacher_dashboard.php' ? 'active' : ''; ?>">
                                <i class="fas fa-chalkboard-teacher"></i> Tableau de bord
                            </a></li>
                            <li><a href="../professor/courses.php" class="<?php echo $current_page == 'courses.php' ? 'active' : ''; ?>">
                                <i class="fas fa-book"></i> Mes cours
                            </a></li>
                            <li><a href="../admin/schedule_management.php"><i class="fas fa-calendar-alt"></i> Emploi du temps</a></li>
                            <li><a href="../grades/grades_management.php" class="<?php echo $current_page == 'grades_management.php' ? 'active' : ''; ?>">
                                <i class="fas fa-chart-line"></i> Gestion des notes
                            </a></li>
                        <?php elseif ($_SESSION['role'] === 'student'): ?>
                            <li><a href="../student/student_dashboard.php" class="<?php echo $current_page == 'student_dashboard.php' ? 'active' : ''; ?>">
                                <i class="fas fa-user-graduate"></i> Tableau de bord
                            </a></li>
                            <li><a href="../student/student_grades.php" class="<?php echo $current_page == 'student_grades.php' ? 'active' : ''; ?>">
                                <i class="fas fa-chart-bar"></i> Mes notes
                            </a></li>
                            <li><a href="student_profile.php" class="<?php echo $current_page == 'student_profile.php' ? 'active' : ''; ?>">
                                <i class="fas fa-user"></i> Profil
                            </a></li>
                        <?php endif; ?>
                        <li><a href="../pages/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Déconnexion
                        </a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
</body>
</html>