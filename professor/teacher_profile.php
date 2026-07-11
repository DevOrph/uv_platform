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

// Traitement du changement de mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['current_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Vérifier que les nouveaux mots de passe correspondent
    if ($new_password !== $confirm_password) {
        $error_message = "Les nouveaux mots de passe ne correspondent pas.";
    } else if (strlen($new_password) < 6) {
        $error_message = "Le nouveau mot de passe doit contenir au moins 6 caractères.";
    } else {
        // Récupérer le mot de passe actuel de l'utilisateur
        $query = "SELECT password FROM users WHERE id = :user_id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Vérifier le mot de passe actuel
        if (password_verify($current_password, $user['password'])) {
            // Hasher le nouveau mot de passe
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Mettre à jour le mot de passe
            $update_query = "UPDATE users SET password = :password WHERE id = :user_id";
            $update_stmt = $pdo->prepare($update_query);
            $update_stmt->execute([
                ':password' => $hashed_password,
                ':user_id' => $user_id
            ]);
            
            $success_message = "Mot de passe changé avec succès!";
        } else {
            $error_message = "Le mot de passe actuel est incorrect.";
        }
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
            
            $success_message = "Informations personnelles mises à jour avec succès!";
        }
    }
}

// Traitement de l'upload de l'avatar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    $file = $_FILES['avatar'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['error'] === 0) {
        if ($file['size'] <= $maxSize) {
            if (in_array($file['type'], $allowedTypes)) {
                $fileName = $user_id . '_' . time() . '_' . basename($file['name']);
                $uploadPath = '../uploads/avatars/' . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    $query = "UPDATE users SET avatar = :avatar WHERE id = :user_id";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([
                        ':avatar' => $fileName,
                        ':user_id' => $user_id
                    ]);
                    $success_message = "Photo de profil mise à jour avec succès!";
                    
                    // Rediriger pour éviter la resoumission du formulaire
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                } else {
                    $error_message = "Erreur lors de l'upload du fichier.";
                }
            } else {
                $error_message = "Type de fichier non autorisé. Utilisez JPG, PNG ou GIF.";
            }
        } else {
            $error_message = "Le fichier est trop volumineux. Maximum 5MB.";
        }
    }
}

// Récupérer les informations de l'enseignant avec ses cours
$query = "SELECT u.*, 
                 COUNT(DISTINCT c.id) as course_count, 
                 COUNT(DISTINCT cl.id) as class_count,
                 GROUP_CONCAT(DISTINCT c.name) as course_names,
                 GROUP_CONCAT(DISTINCT cl.name) as class_names
          FROM users u
          LEFT JOIN courses c ON u.id = c.teacher_id
          LEFT JOIN classes cl ON JSON_CONTAINS(c.class_id, JSON_QUOTE(CAST(cl.id AS CHAR)))
          WHERE u.id = :user_id AND u.role = 'teacher'
          GROUP BY u.id";

$stmt = $pdo->prepare($query);
$stmt->bindParam(':user_id', $user_id, PDO::PARAM_STR);
$stmt->execute();
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);

// Récupérer les statistiques des cours
$stats_query = "SELECT 
    COUNT(DISTINCT d.id) as total_discussions,
    COUNT(DISTINCT doc.id) as total_documents
    FROM courses c
    LEFT JOIN discussions d ON c.id = d.course_id
    LEFT JOIN documents doc ON d.id = doc.discussion_id
    WHERE c.teacher_id = :teacher_id";
$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->bindParam(':teacher_id', $user_id, PDO::PARAM_STR);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

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
    <title>Profil Enseignant - Université Virtuelle</title>
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

        .stats-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: white;
            padding: 1rem;
            border-radius: 10px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(3, 155, 229, 0.3);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(3, 155, 229, 0.4);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
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

        .courses-list {
            margin-top: 2rem;
        }

        .courses-list h3 {
            color: var(--accent-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .course-item {
            padding: 1rem;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--accent-color), #0277bd);
            color: white;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(3, 155, 229, 0.3);
        }

        .course-item:hover {
            transform: translateX(10px);
            box-shadow: 0 4px 12px rgba(3, 155, 229, 0.4);
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .error-message {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            animation: slideIn 0.5s ease-out;
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.3);
            display: flex;
            align-items: center;
            gap: 0.5rem;
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
            box-sizing: border-box;
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

            .stats-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>Profil Enseignant</h1>
        <nav>
            <a href="teacher_dashboard.php" class="nav-button">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="javascript:history.back()" class="nav-button">
                <i class="fas fa-book"></i> Mes Cours
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
                    <img src="../uploads/avatars/<?php echo htmlspecialchars($teacher['avatar']); ?>" 
                         alt="Photo de profil" 
                         class="avatar-image"
                         onerror="this.src='../uploads/avatars/default_avatar.png'">
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
            
            <h2><?php echo htmlspecialchars($teacher['name']); ?></h2>
            <p><i class="fas fa-chalkboard-teacher"></i> Enseignant</p>

            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $teacher['course_count']; ?></div>
                    <div class="stat-label">Cours</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $teacher['class_count']; ?></div>
                    <div class="stat-label">Classes</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_discussions']; ?></div>
                    <div class="stat-label">Discussions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['total_documents']; ?></div>
                    <div class="stat-label">Documents</div>
                </div>
            </div>

            <!-- Section pour modifier les informations personnelles -->
            <div class="section">
                <button type="button" class="section-button" onclick="toggleInfoForm()">
                    <i class="fas fa-edit"></i>
                    Modifier mes informations
                </button>

                <form method="POST" class="form-container" id="infoForm">
                    <input type="hidden" name="update_info" value="1">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(CSRF_TOKEN, ENT_QUOTES) ?>">
                    
                    <div class="form-group">
                        <label for="email">
                            <i class="fas fa-envelope"></i>
                            Adresse email
                        </label>
                        <input type="email" 
                               name="email" 
                               id="email" 
                               required
                               value="<?php echo htmlspecialchars($teacher['email']); ?>"
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
                               value="<?php echo htmlspecialchars($teacher['phone'] ?? ''); ?>"
                               placeholder="Votre numéro de téléphone">
                    </div>

                    <button type="submit" class="submit-button">
                        <i class="fas fa-save"></i>
                        Mettre à jour les informations
                    </button>
                </form>
            </div>

            <!-- Section pour changer le mot de passe -->
            <!-- À ajouter dans votre formulaire de changement de mot de passe -->
<!-- Remplacez la section "Section pour changer le mot de passe" par ceci: -->

<div class="section">
    <button type="button" class="section-button" onclick="togglePasswordForm()">
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
            <div class="password-input-wrapper">
                <input type="password" 
                       name="current_password" 
                       id="current_password" 
                       required
                       minlength="6"
                       placeholder="Entrez votre mot de passe actuel">
                <button type="button" class="password-toggle" onclick="togglePasswordVisibility('current_password')">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label for="new_password">
                <i class="fas fa-key"></i>
                Nouveau mot de passe
            </label>
            <div class="password-input-wrapper">
                <input type="password" 
                       name="new_password" 
                       id="new_password" 
                       required
                       minlength="6"
                       placeholder="Entrez votre nouveau mot de passe">
                <button type="button" class="password-toggle" onclick="togglePasswordVisibility('new_password')">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>

        <div class="form-group">
            <label for="confirm_password">
                <i class="fas fa-check-circle"></i>
                Confirmer le nouveau mot de passe
            </label>
            <div class="password-input-wrapper">
                <input type="password" 
                       name="confirm_password" 
                       id="confirm_password" 
                       required
                       minlength="6"
                       placeholder="Confirmez votre nouveau mot de passe">
                <button type="button" class="password-toggle" onclick="togglePasswordVisibility('confirm_password')">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="submit-button">
            <i class="fas fa-save"></i>
            Mettre à jour le mot de passe
        </button>
    </form>
</div>

<style>
    /* Styles pour le wrapper du champ de mot de passe */
    .password-input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    .password-input-wrapper input {
        padding-right: 2.5rem;
    }

    .password-toggle {
        position: absolute;
        right: 0.8rem;
        background: none;
        border: none;
        color: var(--accent-color);
        cursor: pointer;
        font-size: 1.1rem;
        padding: 0.5rem;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .password-toggle:hover {
        color: #0277bd;
        transform: scale(1.2);
    }

    .password-toggle:active {
        transform: scale(0.95);
    }
</style>

<script>
    // Fonction pour afficher/masquer le mot de passe
    function togglePasswordVisibility(inputId) {
        const input = document.getElementById(inputId);
        const button = event.target.closest('.password-toggle');
        const icon = button.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
            button.title = 'Masquer le mot de passe';
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
            button.title = 'Afficher le mot de passe';
        }
    }
</script>
        </div>

        <div class="profile-main">
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-id-card"></i>
                    Identifiant
                </div>
                <div class="info-value"><?php echo htmlspecialchars($teacher['id']); ?></div>
            </div>

            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-user"></i>
                    Nom complet
                </div>
                <div class="info-value"><?php echo htmlspecialchars($teacher['name']); ?></div>
            </div>

            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-envelope"></i>
                    Email
                </div>
                <div class="info-value"><?php echo htmlspecialchars($teacher['email']); ?></div>
            </div>

            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-phone"></i>
                    Téléphone
                </div>
                <div class="info-value">
                    <?php echo !empty($teacher['phone']) ? htmlspecialchars($teacher['phone']) : '<em>Non renseigné</em>'; ?>
                </div>
            </div>

            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-calendar-alt"></i>
                    Date d'inscription
                </div>
                <div class="info-value"><?php echo date('d/m/Y', strtotime($teacher['created_at'])); ?></div>
            </div>

            <?php if ($teacher['course_names']): ?>
            <div class="courses-list">
                <h3><i class="fas fa-book"></i> Mes Cours</h3>
                <?php foreach(explode(',', $teacher['course_names']) as $course): ?>
                    <div class="course-item">
                        <i class="fas fa-book"></i> <?php echo htmlspecialchars($course); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($teacher['class_names']): ?>
            <div class="courses-list">
                <h3><i class="fas fa-users"></i> Classes Enseignées</h3>
                <?php 
                $classes = array_unique(explode(',', $teacher['class_names']));
                foreach($classes as $class): 
                    if (!empty(trim($class))):
                ?>
                    <div class="course-item">
                        <i class="fas fa-users"></i>
                        <?php echo htmlspecialchars($class); ?>
                    </div>
                <?php 
                    endif;
                endforeach; 
                ?>
            </div>
            <?php endif; ?>
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
            const isVisible = form.style.display === 'block';
            form.style.display = isVisible ? 'none' : 'block';
            
            if (isVisible) {
                form.reset();
            }
        }

        // Fonction pour afficher/masquer le formulaire de changement de mot de passe
        function togglePasswordForm() {
            const form = document.getElementById('passwordForm');
            const isVisible = form.style.display === 'block';
            form.style.display = isVisible ? 'none' : 'block';
            
            if (isVisible) {
                form.reset();
            }
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
                showNotification('Profil enseignant chargé avec le thème synchronisé', 'success');
            }, 500);

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

            // Validation du formulaire de mot de passe
            const passwordForm = document.getElementById('passwordForm');
            passwordForm.addEventListener('submit', function(e) {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    showNotification('Les nouveaux mots de passe ne correspondent pas.', 'error');
                    return false;
                }
                
                if (newPassword.length < 6) {
                    e.preventDefault();
                    showNotification('Le mot de passe doit contenir au moins 6 caractères.', 'error');
                    return false;
                }
            });
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