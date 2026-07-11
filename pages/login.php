<?php
require_once '../includes/db_connect.php';
require_once '../includes/utils/admin_logger.php';

// Si l'utilisateur est déjà connecté, redirection vers son tableau de bord
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin') {
        header("Location: ../admin/admin_dashboard.php");
    } else if ($_SESSION['role'] === 'teacher') {
        header("Location: ../professor/teacher_dashboard.php");
    } else {
        header("Location: ../student/student_dashboard.php");
    }
    exit();
}

$error_message = "";

// Traitement du formulaire de connexion
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Vérification des identifiants
    $sql = "SELECT * FROM users WHERE id = ? OR email = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Vérifier si le compte est bloqué
        if ($user['blocked'] == 1) {
            logFailedLogin($conn, $username, "Compte bloqué");
            $error_message = "Ce compte a été désactivé. Veuillez contacter l'administrateur.";
        }
        // Vérifier le mot de passe
        else if (password_verify($password, $user['password'])) {
            // Connexion réussie
            logSuccessfulLogin($conn, $user['id']);
            
            // Initialiser la session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['avatar'] = $user['avatar'] ?? 'default_avatar.png';
            
            // Rediriger vers la page appropriée
            if ($user['role'] === 'admin' || $user['role'] === 'super_admin') {
                header("Location: ../admin/admin_dashboard.php");
            } else if ($user['role'] === 'teacher') {
                header("Location: ../professor/teacher_dashboard.php");
            } else {
                header("Location: ../student/student_dashboard.php");
            }
            exit();
        } else {
            // Mot de passe incorrect
            logFailedLogin($conn, $username, "Mot de passe incorrect");
            $error_message = "Identifiants incorrects. Veuillez réessayer.";
        }
    } else {
        // Utilisateur non trouvé
        logFailedLogin($conn, $username, "Utilisateur non trouvé");
        $error_message = "Identifiants incorrects. Veuillez réessayer.";
    }
}

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Université Virtuelle</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles1.css">
    <style>
        .login-container {
            max-width: 400px;
            margin: 50px auto;
            background: rgba(12, 45, 72, 0.8);
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .login-logo {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .login-logo img {
            max-width: 200px;
            height: auto;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: #039be5;
            margin-top: 15px;
            font-size: 24px;
        }
        
        .login-form .form-group {
            margin-bottom: 20px;
        }
        
        .login-form label {
            display: block;
            margin-bottom: 8px;
            color: #fff;
        }
        
        .login-form input {
            width: 100%;
            padding: 12px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-size: 16px;
            box-sizing: border-box;
        }
        
        .login-form input:focus {
            outline: none;
            border-color: #039be5;
            box-shadow: 0 0 0 2px rgba(3, 155, 229, 0.3);
        }
        
        .login-form button {
            width: 100%;
            padding: 12px;
            background: #039be5;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .login-form button:hover {
            background: #0288d1;
        }
        
        .error-message {
            color: #f44336;
            background: rgba(244, 67, 54, 0.1);
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .forgot-password {
            text-align: center;
            margin-top: 15px;
        }
        
        .forgot-password a {
            color: #039be5;
            text-decoration: none;
            font-size: 14px;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 30px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <img src="../assets/images/UV.png" alt="Logo Université Virtuelle">
        </div>
        
        <div class="login-header">
            <h1>Connexion à l'Université Virtuelle</h1>
            <p>Entrez vos identifiants pour accéder à votre espace</p>
        </div>
        
        <?php if (!empty($error_message)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <form class="login-form" method="POST" action="">
            <div class="form-group">
                <label for="username">Identifiant ou Email</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit">
                <i class="fas fa-sign-in-alt"></i> Se connecter
            </button>
        </form>
        
        <div class="forgot-password">
            <a href="#">Mot de passe oublié ?</a>
        </div>
        
        <div class="login-footer">
            <p>© <?php echo date('Y'); ?> Université Virtuelle - Tous droits réservés</p>
        </div>
    </div>
</body>
</html>