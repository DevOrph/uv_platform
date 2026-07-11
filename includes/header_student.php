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
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            position: relative;
            overflow: hidden;
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
        .user-settings {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
}

.drawer-toggle {
    background: transparent;
    border: none;
    color: var(--text-light);
    font-size: 20px;
    cursor: pointer;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

    .drawer-toggle:hover {
        background: rgba(255, 255, 255, 0.1);
        transform: rotate(90deg);
    }

    /* Modification du header-content pour accueillir le bouton */
    .header-content {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    body, html {
    overflow-x: hidden;
    width: 100%;
}

    </style>
</head>
<body>
<header>






<script>


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
    <div class="header-content">
        <div class="logo">
            <h1><i class="fas fa-graduation-cap"></i> Université Virtuelle</h1>
            </br>
        </div>
        <nav>
           <ul>
                <li><a href="student_dashboard.php"><i class="fas fa-home"></i> Accueil</a></li>
                <li><a href="student_grades.php"><i class="fas fa-chart-line"></i> Notes</a></li>
                <li><a href="../student/quiz_list.php"><i class="fas fa-question-circle"></i> Mes Quiz</a></li>
                <li><a href="student_dashboard.php"><i class="fas fa-book"></i> Mes cours</a></li>
                <li><a href="../student/schedule.php"><i class="fas fa-calendar-alt"></i> Emploi du temps</a></li>
                <li><a href="../student/my_payments.php"><i class="fas fa-credit-card"></i> Paiements</a></li>
                <li><a href="student_profile.php"><i class="fas fa-user"></i> Profil</a></li>
                <li><a href="../pages/logout.php" style="color: #dc3545;"><i class="fas fa-sign-out-alt"></i> Déconnexion</a></li>
            </ul>
        </nav>
        <br>
        <div class="user-settings">
        <button class="drawer-toggle" id="drawer-toggle-right">
            <i class="fas fa-sliders-h"></i>
        </button>
        </div>
        <div class="floating-icons">
            <i class="floating-icon fas fa-graduation-cap"></i>
            <i class="floating-icon fas fa-book"></i>
            <i class="floating-icon fas fa-chart-line"></i>
            <i class="floating-icon fas fa-user-graduate"></i>
            <i class="floating-icon fas fa-chalkboard-teacher"></i>
        </div>
    </div>
    
</header>
