<?php
require_once '../includes/db_connect.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../pages/login.html");
    exit();
}

try {
    $pdo = get_pdo_connection();
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Récupérer les informations de l'utilisateur
$query = "SELECT u.*, c.name as class_name FROM users u 
          LEFT JOIN classes c ON u.class_id = c.id 
          WHERE u.id = :user_id";
$stmt = $pdo->prepare($query);
$stmt->bindParam(':user_id', $user_id, PDO::PARAM_STR);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Traitement de la mise à jour du mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['current_password'], $_POST['new_password'], $_POST['confirm_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Vérification du mot de passe actuel
    if (!password_verify($current_password, $user['password'])) {
        $error_message = "Le mot de passe actuel est incorrect.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "Les nouveaux mots de passe ne correspondent pas.";
    } else {
        // Mise à jour du mot de passe
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $query = "UPDATE users SET password = :password WHERE id = :user_id";
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            ':password' => $hashed_password,
            ':user_id' => $user_id
        ]);
        $success_message = "Mot de passe modifié avec succès!";
    }
}

// Traitement de la mise à jour des informations personnelles
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_info'])) {
    $new_email = trim($_POST['email']);
    $new_phone = trim($_POST['phone']);
    
    // Validation de l'email
    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "L'adresse email n'est pas valide.";
    } else {
        // Vérifier si l'email n'est pas déjà utilisé par un autre utilisateur
        $query = "SELECT id FROM users WHERE email = :email AND id != :user_id";
        $stmt = $pdo->prepare($query);
        $stmt->execute([':email' => $new_email, ':user_id' => $user_id]);
        
        if ($stmt->rowCount() > 0) {
            $error_message = "Cette adresse email est déjà utilisée par un autre utilisateur.";
        } else {
            // Mise à jour des informations
            $query = "UPDATE users SET email = :email, phone = :phone WHERE id = :user_id";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                ':email' => $new_email,
                ':phone' => $new_phone,
                ':user_id' => $user_id
            ]);
            
            // Recharger les informations utilisateur
            $query = "SELECT u.*, c.name as class_name FROM users u 
                      LEFT JOIN classes c ON u.class_id = c.id 
                      WHERE u.id = :user_id";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $success_message = "Informations personnelles mises à jour avec succès!";
        }
    }
}

// Traitement de l'upload d'avatar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    $target_dir = "../uploads/avatars/";
    $target_file = $target_dir . basename($_FILES["avatar"]["name"]);
    $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    // Vérification si le fichier est une image
    $check = getimagesize($_FILES["avatar"]["tmp_name"]);
    if ($check === false) {
        $error_message = "Le fichier n'est pas une image.";
    } elseif (!in_array($imageFileType, ["jpg", "png", "jpeg", "gif"])) {
        $error_message = "Seuls les formats JPG, JPEG, PNG & GIF sont autorisés.";
    } else {
        // Déplacer le fichier dans le dossier uploads
        if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $target_file)) {
            // Mettre à jour l'avatar dans la base de données
            $query = "UPDATE users SET avatar = :avatar WHERE id = :user_id";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                ':avatar' => basename($_FILES["avatar"]["name"]),
                ':user_id' => $user_id
            ]);

            $success_message = "Photo de profil mise à jour avec succès.";
            // Recharger la page pour afficher l'image mise à jour
            header("Location: ".$_SERVER['PHP_SELF']);
            exit;
        } else {
            $error_message = "Erreur lors de l'upload du fichier.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Étudiant - Université Virtuelle</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #051e34;
            --primary-color-hover: #0c2d48;
            --background-color: #051e34;
            --text-color: #ffffff;
            --card-background: rgba(255, 255, 255, 0.1);
            --border-color: rgba(255, 255, 255, 0.2);
            --accent-color: #039be5;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--background-color);
            color: var(--text-color);
            transition: all 0.3s ease;
            min-height: 100vh;
        }

        body.dark-theme {
            --background-color: #1a1a1a;
            --text-color: #ffffff;
            --card-background: rgba(255, 255, 255, 0.05);
            --border-color: rgba(255, 255, 255, 0.1);
        }

        header {
            background-color: var(--primary-color);
            color: white;
            padding: 1rem;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        header h1 {
            margin: 0;
            background: linear-gradient(to bottom, #ffffff, #039be5, #051e34);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .profile-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 2rem;
        }

        .profile-sidebar {
            background: linear-gradient(145deg, var(--card-background), rgba(255, 255, 255, 0.05));
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            height: fit-content;
            transition: transform 0.3s ease;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        .profile-sidebar:hover {
            transform: translateY(-5px);
        }

        .avatar-container {
            position: relative;
            width: 200px;
            height: 200px;
            margin: 0 auto 1.5rem;
        }

        .avatar-image {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--accent-color);
            transition: transform 0.3s ease;
        }

        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            cursor: pointer;
        }

        .avatar-container:hover .avatar-overlay {
            opacity: 1;
        }

        .avatar-overlay i {
            color: white;
            font-size: 2rem;
        }

        .profile-main {
            background: linear-gradient(145deg, var(--card-background), rgba(255, 255, 255, 0.05));
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        .info-group {
            margin-bottom: 1.5rem;
            padding: 1rem;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            transition: transform 0.3s ease;
            border: 1px solid var(--border-color);
        }

        .info-group:hover {
            transform: translateX(10px);
            background: rgba(255, 255, 255, 0.15);
        }

        .info-label {
            font-weight: 600;
            color: var(--accent-color);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-value {
            font-size: 1.1rem;
            color: var(--text-color);
        }

        .nav-button {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            margin: 0.5rem;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(3, 155, 229, 0.3);
        }

        .nav-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(3, 155, 229, 0.4);
            background: linear-gradient(135deg, #0277bd, var(--accent-color));
        }

        .success-message {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            animation: slideIn 0.5s ease-out;
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        .error-message {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            animation: slideIn 0.5s ease-out;
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        #fileInput {
            display: none;
        }

        .theme-toggle {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: white;
            border: none;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(3, 155, 229, 0.3);
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .theme-toggle:hover {
            transform: rotate(180deg) scale(1.1);
            box-shadow: 0 6px 20px rgba(3, 155, 229, 0.4);
        }

        .section {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border-color);
        }

        .section-button {
            width: 100%;
            padding: 0.8rem;
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(3, 155, 229, 0.3);
            margin-bottom: 1rem;
        }

        .section-button:hover {
            background: linear-gradient(135deg, #0277bd, var(--accent-color));
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(3, 155, 229, 0.4);
        }

        .form-container {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
            display: none;
            animation: slideDown 0.3s ease-out;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-container .form-group {
            margin-bottom: 1.2rem;
        }

        .form-container label {
            display: block;
            color: var(--text-color);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-container input {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-color);
            font-size: 1rem;
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
        }

        .form-container input:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 2px rgba(3, 155, 229, 0.3);
            background: rgba(255, 255, 255, 0.15);
        }

        .form-container input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .submit-button {
            width: 100%;
            padding: 0.8rem;
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 2px 8px rgba(39, 174, 96, 0.3);
        }

        .submit-button:hover {
            background: linear-gradient(135deg, #229954, #27ae60);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.4);
        }

        .submit-button:active {
            transform: translateY(0);
        }

        .profile-sidebar h2 {
            text-align: center;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .profile-sidebar p {
            text-align: center;
            color: var(--accent-color);
            margin-bottom: 1rem;
        }

        footer {
            background-color: var(--primary-color);
            color: white;
            text-align: center;
            padding: 1rem;
            margin-top: 2rem;
            box-shadow: 0 -2px 4px rgba(0, 0, 0, 0.3);
        }

        @media (max-width: 768px) {
            .profile-container {
                grid-template-columns: 1fr;
                margin: 1rem auto;
                padding: 0 0.5rem;
            }

            .profile-sidebar {
                margin-bottom: 2rem;
            }

            .avatar-container {
                width: 150px;
                height: 150px;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Mon Profil</h1>
        <nav>
            <a href="student_dashboard.php" class="nav-button">
                <i class="fas fa-home"></i> Dashboard
            </a>
        </nav>
    </header>

    <div class="profile-container">
        <?php if ($success_message): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="profile-sidebar">
            <form id="avatarForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                <div class="avatar-container">
                    <img src="../uploads/avatars/<?php echo !empty($user['avatar']) ? htmlspecialchars($user['avatar']) : 'default.png'; ?>" 
                         alt="Photo de profil" 
                         class="avatar-image">
                    <label for="fileInput" class="avatar-overlay">
                        <i class="fas fa-camera"></i>
                    </label>
                    <input type="file" 
                           id="fileInput" 
                           name="avatar" 
                           accept="image/*" 
                           onchange="document.getElementById('avatarForm').submit();">
                </div>
            </form>
            <h2><?php echo htmlspecialchars($user['name']); ?></h2>
            <p><i class="fas fa-user-graduate"></i> Étudiant</p>

            <!-- Section pour modifier les informations personnelles -->
            <div class="section">
                <button class="section-button" onclick="toggleInfoForm()">
                    <i class="fas fa-edit"></i>
                    Modifier mes informations
                </button>

                <form method="POST" class="form-container" id="infoForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                    <input type="hidden" name="update_info" value="1">
                    
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope"></i>
                            Adresse email
                        </label>
                        <input type="email" 
                               name="email" 
                               id="email" 
                               required
                               value="<?php echo htmlspecialchars($user['email']); ?>"
                               placeholder="Votre adresse email">
                    </div>

                    <div class="form-group">
                        <label for="phone">
                            <i class="fas fa-phone"></i>
                            Numéro de téléphone
                        </label>
                        <input type="tel" 
                               name="phone" 
                               id="phone"
                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                               placeholder="Votre numéro de téléphone">
                    </div>

                    <button type="submit" class="submit-button">
                        <i class="fas fa-save"></i>
                        Mettre à jour les informations
                    </button>
                </form>
            </div>

            <!-- Section pour changer le mot de passe -->
            <div class="section">
                <button class="section-button" onclick="togglePasswordForm()">
                    <i class="fas fa-key"></i>
                    Changer le mot de passe
                </button>

                <form method="POST" class="form-container" id="passwordForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                    <div class="form-group">
                        <label for="current_password">
                            <i class="fas fa-lock"></i>
                            Mot de passe actuel
                        </label>
                        <input type="password" 
                               name="current_password" 
                               id="current_password" 
                               required
                               placeholder="Entrez votre mot de passe actuel">
                    </div>

                    <div class="form-group">
                        <label for="new_password">
                            <i class="fas fa-key"></i>
                            Nouveau mot de passe
                        </label>
                        <input type="password" 
                               name="new_password" 
                               id="new_password" 
                               required
                               placeholder="Entrez votre nouveau mot de passe">
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">
                            <i class="fas fa-check-circle"></i>
                            Confirmer le nouveau mot de passe
                        </label>
                        <input type="password" 
                               name="confirm_password" 
                               id="confirm_password" 
                               required
                               placeholder="Confirmez votre nouveau mot de passe">
                    </div>

                    <button type="submit" class="submit-button">
                        <i class="fas fa-save"></i>
                        Mettre à jour le mot de passe
                    </button>
                </form>
            </div>
        </div>

        <div class="profile-main">
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-id-card"></i>
                    Identifiant
                </div>
                <div class="info-value"><?php echo htmlspecialchars($user['id']); ?></div>
            </div>

            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-user"></i>
                    Nom complet
                </div>
                <div class="info-value"><?php echo htmlspecialchars($user['name']); ?></div>
            </div>

            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-envelope"></i>
                    Email
                </div>
                <div class="info-value"><?php echo htmlspecialchars($user['email']); ?></div>
            </div>

            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-phone"></i>
                    Téléphone
                </div>
                <div class="info-value">
                    <?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : '<em>Non renseigné</em>'; ?>
                </div>
            </div>

            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-graduation-cap"></i>
                    Classe
                </div>
                <div class="info-value"><?php echo htmlspecialchars($user['class_name']); ?></div>
            </div>

            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-calendar-alt"></i>
                    Date d'inscription
                </div>
                <div class="info-value"><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></div>
            </div>
        </div>
    </div>

    <button class="theme-toggle" onclick="toggleTheme()">
        <i class="fas fa-moon"></i>
    </button>

    <?php include '../includes/footer.php'; ?>

    <script>
        // ===== SYSTÈME DE THÈME SYNCHRONISÉ =====
        const DEFAULT_COLORS = {
            primaryColor: '#051e34',
            backgroundColor: '#051e34',
            textColor: '#ffffff',
            accentColor: '#039be5'
        };

        // Fonction pour charger les préférences de thème (mêmes clés que le dashboard)
        function loadThemePreferences() {
            const savedPrimaryColor = localStorage.getItem('themePrimaryColor') || DEFAULT_COLORS.primaryColor;
            const savedBackgroundColor = localStorage.getItem('themeBackgroundColor') || DEFAULT_COLORS.backgroundColor;
            const savedTextColor = localStorage.getItem('themeTextColor') || DEFAULT_COLORS.textColor;
            const savedAccentColor = localStorage.getItem('themeAccentColor') || DEFAULT_COLORS.accentColor;
            const isDarkTheme = localStorage.getItem('darkTheme') === 'true';

            // Appliquer les couleurs
            document.documentElement.style.setProperty('--primary-color', savedPrimaryColor);
            document.documentElement.style.setProperty('--background-color', savedBackgroundColor);
            document.documentElement.style.setProperty('--text-color', savedTextColor);
            document.documentElement.style.setProperty('--accent-color', savedAccentColor);

            document.body.style.background = savedBackgroundColor;
            document.body.style.color = savedTextColor;

            const header = document.querySelector('header');
            const footer = document.querySelector('footer');
            if (header) header.style.backgroundColor = savedPrimaryColor;
            if (footer) footer.style.backgroundColor = savedPrimaryColor;

            if (isDarkTheme) {
                document.body.classList.add('dark-theme');
                document.querySelector('.theme-toggle i').className = 'fas fa-sun';
            }
        }

        // Fonction pour afficher/masquer le formulaire d'informations personnelles
        function toggleInfoForm() {
            const form = document.getElementById('infoForm');
            form.style.display = form.style.display === 'none' || form.style.display === '' ? 'block' : 'none';
        }

        // Fonction pour afficher/masquer le formulaire de changement de mot de passe
        function togglePasswordForm() {
            const form = document.getElementById('passwordForm');
            form.style.display = form.style.display === 'none' || form.style.display === '' ? 'block' : 'none';
        }

        // Fonction pour basculer le thème clair/sombre
        function toggleTheme() {
            document.body.classList.toggle('dark-theme');
            const isDarkTheme = document.body.classList.contains('dark-theme');
            localStorage.setItem('darkTheme', isDarkTheme);
            
            const themeIcon = document.querySelector('.theme-toggle i');
            themeIcon.className = isDarkTheme ? 'fas fa-sun' : 'fas fa-moon';
            
            // Notification visuelle
            showNotification(isDarkTheme ? 'Mode sombre activé' : 'Mode clair activé', 'info');
        }

        // Fonction pour afficher des notifications
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `${type}-message`;
            notification.innerHTML = `<i class="fas fa-info-circle"></i> ${message}`;
            notification.style.position = 'fixed';
            notification.style.top = '20px';
            notification.style.right = '20px';
            notification.style.zIndex = '10000';
            notification.style.maxWidth = '300px';
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                notification.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 500);
            }, 3000);
        }

        // Charger les préférences au démarrage
        document.addEventListener('DOMContentLoaded', () => {
            loadThemePreferences();
            
            // Message de bienvenue
            setTimeout(() => {
                showNotification('Profil chargé avec le thème synchronisé', 'success');
            }, 500);
        });

        // Animation des messages existants
        const messages = document.querySelectorAll('.success-message, .error-message');
        messages.forEach(message => {
            setTimeout(() => {
                message.style.opacity = '0';
                message.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    if (message.parentNode) {
                        message.parentNode.removeChild(message);
                    }
                }, 500);
            }, 5000);
        });

        // Écouter les changements depuis d'autres onglets
        window.addEventListener('storage', function(e) {
            if (e.key && e.key.startsWith('theme')) {
                loadThemePreferences();
                showNotification('Thème mis à jour depuis un autre onglet', 'info');
            }
        });
    </script>
</body>
</html>