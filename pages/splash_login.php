<?php
session_start();
// Marquer que l'utilisateur vient de se connecter
$_SESSION['fresh_login'] = true;

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !isset($_SESSION['redirect_url'])) {
    // Rediriger vers la page de login si non connecté
    header("Location: pages/login.html");
    exit();
}

// Déterminer la destination en fonction du rôle
$user_name = $_SESSION['name'];
$role = $_SESSION['role'];
$redirect_url = $_SESSION['redirect_url'];

// Texte selon le rôle
$role_text = "";
switch ($role) {
    case 'admin':
        $role_text = "Administrateur";
        $welcome_message = "Bienvenue dans l'interface d'administration";
        break;
    case 'teacher':
        $role_text = "Enseignant";
        $welcome_message = "Bienvenue dans votre espace enseignant";
        break;
    case 'student':
        $role_text = "Étudiant";
        $welcome_message = "Bienvenue dans votre espace étudiant";
        break;
    default:
        $role_text = "Utilisateur";
        $welcome_message = "Bienvenue sur la plateforme UV";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion réussie - Université Virtuelle</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        :root {
            --primary-color: #051e34;
            --secondary-color: #039be5;
            --accent-color: #ff9500;
            --success-color: #4CAF50;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #051e34 0%, #0a2d4e 100%);
            color: #ffffff;
            overflow: hidden;
            position: relative;
        }

        /* Animations de fond */
        .bg-animation {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            z-index: 1;
        }

        .circle {
            position: absolute;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(3, 155, 229, 0.1) 0%, rgba(3, 155, 229, 0) 70%);
            animation: float 20s infinite ease-in-out;
        }

        .circle:nth-child(1) {
            width: 300px;
            height: 300px;
            top: -150px;
            left: -150px;
            animation-delay: 0s;
        }

        .circle:nth-child(2) {
            width: 200px;
            height: 200px;
            bottom: -100px;
            right: -100px;
            animation-delay: 5s;
        }

        .circle:nth-child(3) {
            width: 150px;
            height: 150px;
            top: 50%;
            left: 10%;
            animation-delay: 10s;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0) scale(1);
            }
            50% {
                transform: translateY(-20px) scale(1.05);
            }
        }

        /* Container principal */
        .splash-container {
            text-align: center;
            z-index: 10;
            position: relative;
            padding: 40px;
            max-width: 800px;
            width: 90%;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Logo */
        .logo-container {
            margin-bottom: 30px;
            animation: bounceIn 1s ease-out;
        }

        .logo {
            width: 120px;
            height: 120px;
            margin: 0 auto;
            background: linear-gradient(135deg, #ffa726 0%, #ffb74d 50%, #039be5 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(3, 155, 229, 0.4);
            position: relative;
            overflow: hidden;
        }

        .logo::before {
            content: '';
            position: absolute;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            border: 3px solid rgba(3, 155, 229, 0.2);
            animation: pulse 2s ease-out infinite;
        }

        .logo-text {
            font-size: 48px;
            font-weight: bold;
            color: #051e34;
            z-index: 2;
        }

        @keyframes bounceIn {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            60% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            100% {
                transform: scale(1.2);
                opacity: 0;
            }
        }

        /* Message de succès */
        .success-icon {
            font-size: 80px;
            color: #4CAF50;
            margin-bottom: 20px;
            animation: checkmark 0.5s ease-out 0.5s both;
        }

        @keyframes checkmark {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        h1 {
            font-size: 2.5rem;
            margin-bottom: 15px;
            color: #4CAF50;
            animation: slideInFromLeft 0.8s ease-out 0.3s both;
        }

        .user-name {
            color: #ff9500;
            font-weight: bold;
        }

        .role-badge {
            display: inline-block;
            background: rgba(255, 149, 0, 0.2);
            color: #ff9500;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 1rem;
            margin: 10px 0 30px;
            border: 1px solid rgba(255, 149, 0, 0.3);
            animation: fadeIn 0.8s ease-out 0.7s both;
        }

        .welcome-message {
            font-size: 1.3rem;
            margin-bottom: 40px;
            color: rgba(255, 255, 255, 0.9);
            animation: slideInFromRight 0.8s ease-out 0.5s both;
            line-height: 1.6;
        }

        @keyframes slideInFromLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInFromRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Loader et compteur */
        .loader-container {
            margin: 30px auto;
            animation: fadeIn 0.8s ease-out 0.9s both;
        }

        .loader {
            width: 60px;
            height: 60px;
            margin: 0 auto;
            position: relative;
        }

        .loader-circle {
            width: 100%;
            height: 100%;
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-top: 4px solid #4CAF50;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            position: absolute;
        }

        .loader-circle:nth-child(2) {
            border-top-color: #ff9500;
            animation-duration: 1.5s;
            animation-direction: reverse;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Barre de progression */
        .progress-container {
            width: 300px;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin: 20px auto;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #ff9500);
            border-radius: 10px;
            animation: progressAnimation 3s ease-out;
            animation-fill-mode: forwards;
        }

        @keyframes progressAnimation {
            from {
                width: 0%;
            }
            to {
                width: 100%;
            }
        }

        /* Compteur */
        .countdown {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.9);
            margin-top: 20px;
            animation: blink 1.5s ease-in-out infinite;
        }

        .countdown-number {
            color: #ff9500;
            font-weight: bold;
            font-size: 1.3rem;
        }

        @keyframes blink {
            0%, 100% {
                opacity: 0.9;
            }
            50% {
                opacity: 0.6;
            }
        }

        /* Points animés */
        .dots {
            display: inline-block;
        }

        .dots span {
            animation: dotBlink 1.4s infinite;
            opacity: 0;
        }

        .dots span:nth-child(1) {
            animation-delay: 0s;
        }

        .dots span:nth-child(2) {
            animation-delay: 0.2s;
        }

        .dots span:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes dotBlink {
            0%, 20% {
                opacity: 0;
            }
            50% {
                opacity: 1;
            }
            100% {
                opacity: 0;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .splash-container {
                padding: 20px;
                width: 95%;
            }

            h1 {
                font-size: 2rem;
            }

            .welcome-message {
                font-size: 1.1rem;
            }

            .logo {
                width: 100px;
                height: 100px;
            }

            .logo-text {
                font-size: 40px;
            }

            .success-icon {
                font-size: 60px;
            }

            .progress-container {
                width: 250px;
            }
        }

        @media (max-width: 480px) {
            h1 {
                font-size: 1.5rem;
            }

            .welcome-message {
                font-size: 1rem;
            }

            .logo {
                width: 80px;
                height: 80px;
            }

            .logo-text {
                font-size: 32px;
            }
        }
    </style>
</head>
<body>
    <!-- Animation de fond -->
    <div class="bg-animation">
        <div class="circle"></div>
        <div class="circle"></div>
        <div class="circle"></div>
    </div>

    <!-- Container principal -->
    <div class="splash-container">
        <!-- Logo -->
        <div class="logo-container">
            <div class="logo">
                <div class="logo-text">UV</div>
            </div>
        </div>

        <!-- Icône de succès -->
        <div class="success-icon">
            <i class="fas fa-check-circle"></i>
        </div>

        <!-- Message de bienvenue -->
        <h1>Connexion réussie !</h1>
        
        <div class="role-badge">
            <i class="fas fa-user-tag"></i> <?php echo $role_text; ?>
        </div>

        <p class="welcome-message">
            Bonjour <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span> !<br>
            <?php echo $welcome_message; ?><br>
            Redirection automatique vers votre tableau de bord...
        </p>

        <!-- Loader -->
        <div class="loader-container">
            <div class="loader">
                <div class="loader-circle"></div>
                <div class="loader-circle"></div>
            </div>
        </div>

        <!-- Barre de progression -->
        <div class="progress-container">
            <div class="progress-bar"></div>
        </div>

        <!-- Compteur -->
        <p class="countdown">
            Redirection dans <span class="countdown-number" id="countdown">3</span> seconde<span id="plural">s</span>
            <span class="dots"><span>.</span><span>.</span><span>.</span></span>
        </p>
    </div>

    <script>
        // Compteur de redirection
        let countdown = 3;
        const countdownElement = document.getElementById('countdown');
        const pluralElement = document.getElementById('plural');
        
        const countdownInterval = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            
            // Gérer le pluriel
            if (countdown <= 1) {
                pluralElement.textContent = '';
            } else {
                pluralElement.textContent = 's';
            }
            
            if (countdown <= 0) {
                clearInterval(countdownInterval);
                // Redirection vers le tableau de bord approprié
                window.location.href = '<?php echo $redirect_url; ?>';
            }
        }, 1000);

        // Redirection manuelle si l'utilisateur clique
        document.addEventListener('click', function() {
            window.location.href = '<?php echo $redirect_url; ?>';
        });

        // Redirection manuelle avec la touche Entrée
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                window.location.href = '<?php echo $redirect_url; ?>';
            }
        });
    </script>
</body>
</html>