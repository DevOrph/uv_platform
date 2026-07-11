<?php
require_once '../includes/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $identifiant = $_POST['identifiant'];
    $passwordInput = $_POST['password'];
    
    // Vérifie si l'utilisateur existe
    $stmt = $conn->prepare("SELECT id, name, email, password, role, blocked FROM users WHERE id = ? OR email = ?");
    $stmt->bind_param("ss", $identifiant, $identifiant);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Vérifie si l'utilisateur est bloqué
        if ($user['blocked'] == 1) {
            echo "<script>alert('Votre compte est bloqué. Veuillez contacter l\'administrateur.'); window.location.href='login.html';</script>";
        } else {
            // Vérifie le mot de passe
            if (password_verify($passwordInput, $user['password'])) {
                // Connexion réussie
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'] ?? '';
                $_SESSION['email'] = $user['email'] ?? '';
                $_SESSION['role'] = $user['role'];
                
                // Enregistrer la connexion
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                
                try {
                    // Mettre à jour la dernière connexion
                    $last_login_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $last_login_stmt->bind_param("s", $user['id']);
                    $last_login_stmt->execute();
                    
                    // Essayer d'utiliser la table user_logins si elle existe
                    $log_stmt = $conn->prepare("INSERT INTO user_logins (user_id, ip_address, user_agent, success) VALUES (?, ?, ?, 1)");
                    $log_stmt->bind_param("sss", $user['id'], $ip_address, $user_agent);
                    $log_stmt->execute();
                } catch (mysqli_sql_exception $e) {
                    // Ignorer les erreurs de journalisation pour ne pas bloquer la connexion
                    error_log("Erreur de journalisation: " . $e->getMessage());
                }
                
                // Stocker l'URL de redirection dans la session
                switch ($user['role']) {
                    case 'admin':
                        $_SESSION['redirect_url'] = '../admin/admin_dashboard.php';
                        break;
                    case 'teacher':
                        $_SESSION['redirect_url'] = '../professor/teacher_dashboard.php';
                        break;
                    case 'student':
                        $_SESSION['redirect_url'] = '../student/student_dashboard.php';
                        break;
                    default:
                        echo "<script>alert('Rôle inconnu'); window.location.href='login.php';</script>";
                        exit();
                }
                
                // Redirection vers la page splash
                header("Location: splash_login.php");
                exit();
            } else {
                echo "<script>alert('Mot de passe incorrect'); window.location.href='login.html';</script>";
            }
        }
    } else {
        echo "<script>alert('Identifiant non trouvé'); window.location.href='login.html';</script>";
    }
    
    $stmt->close();
}

$conn->close();
?>