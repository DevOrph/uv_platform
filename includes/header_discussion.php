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


    <title>UV Platform</title>
    
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

        /* Icônes pour les liens */
        nav a[href*="dashboard"]::before { content: '\f0c9'; font-family: 'Font Awesome 5 Free'; font-weight: 900; }
        nav a[href*="user"]::before { content: '\f007'; font-family: 'Font Awesome 5 Free'; font-weight: 900; }
        nav a[href*="grades"]::before { content: '\f518'; font-family: 'Font Awesome 5 Free'; font-weight: 900; }
        nav a[href*="courses"]::before { content: '\f51c'; font-family: 'Font Awesome 5 Free'; font-weight: 900; }
        nav a[href*="logout"]::before { content: '\f2f5'; font-family: 'Font Awesome 5 Free'; font-weight: 900; }

        /* Style spécial pour le bouton de déconnexion */
        nav a[href*="logout"] {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        nav a[href*="logout"]:hover {
            background: rgba(220, 53, 69, 0.2);
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
        body, html {
    overflow-x: hidden;
    width: 100%;
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

    </style>
</head>
<body>
<header>

        <div class="header-content">
            <div class="logo">
                <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
                
            </div>
            <nav>
                <ul>
                    <li><a href="teacher_dashboard.php"><i class="fas fa-home"></i> Accueil</a></li>
                    <li><a href="grades_management.php"><i class="fas fa-chart-line"></i> Notes</a></li>
                    <li><a href="../professor/teacher_schedule.php"><i class="fas fa-calendar-alt"></i> Mon emploi du temps</a></li>
                    <li><a href="teacher_profile.php"><i class="fas fa-user"></i> Profil</a></li>
                    <li><a href="mes_honoraires.php"><i class="fas fa-money-bill-wave"></i> Mes Honoraires</a></li>
                    <li><a href="../pages/logout.php" style="color: #dc3545;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
                    
                </ul>
                
                
            </nav>
            <div class="user-settings">
<br>
        </div>
           
            <div class="floating-icons">
                <i class="floating-icon fas fa-graduation-cap"></i>
                <i class="floating-icon fas fa-book"></i>
                <i class="floating-icon fas fa-chart-line"></i>
                <i class="floating-icon fas fa-user-graduate"></i>
                <i class="floating-icon fas fa-chalkboard-teacher"></i>
            </div>
        </div>
        <!-- Supprimer cette partie -->
        
    </header>
<script>
    // Animation des liens au survol
    const navLinks = document.querySelectorAll('nav a');
    const header = document.querySelector('header');
    const floatingIcons = document.querySelectorAll('.floating-icon');

    navLinks.forEach(link => {
        link.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        link.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });

    // Animation des icônes flottantes
    header.addEventListener('mouseenter', () => {
        floatingIcons.forEach(icon => {
            icon.style.opacity = '1';
        });
    });

    header.addEventListener('mouseleave', () => {
        floatingIcons.forEach(icon => {
            icon.style.opacity = '0';
        });
    });

    // Réinitialisation des animations
    function resetAnimation(element) {
        element.style.animation = 'none';
        element.offsetHeight; // Déclenche un reflow
        element.style.animation = null;
    }

    header.addEventListener('mouseenter', () => {
        floatingIcons.forEach(icon => {
            resetAnimation(icon);
        });
    });
</script>