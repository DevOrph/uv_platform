<?php
// Intégrer ce code dans votre script de connexion existant
// Après avoir vérifié les identifiants de l'utilisateur et avant de définir les variables de session

// Inclure la bibliothèque de journalisation
require_once '../includes/utils/admin_logger.php';

// Connexion à la base de données déjà établie ($conn)

// Si la connexion est réussie
if ($identifiants_valides) {
    // Enregistrer la connexion réussie
    logSuccessfulLogin($conn, $user_id);
    
    // Définir les variables de session comme d'habitude
    $_SESSION['user_id'] = $user_id;
    $_SESSION['name'] = $user_data['name'];
    $_SESSION['email'] = $user_data['email'];
    $_SESSION['role'] = $user_data['role'];
    $_SESSION['avatar'] = $user_data['avatar'];
    
    // Redirection en fonction du rôle
    if ($user_data['role'] === 'admin' || $user_data['role'] === 'super_admin') {
        header("Location: ../admin/admin_dashboard.php");
    } else if ($user_data['role'] === 'teacher') {
        header("Location: ../professor/teacher_dashboard.php");
    } else {
        header("Location: ../student/student_dashboard.php");
    }
    exit();
} else {
    // Enregistrer la tentative de connexion échouée
    logFailedLogin($conn, $username_or_email, 'Identifiants incorrects');
    
    // Afficher un message d'erreur comme d'habitude
    $error_message = "Identifiants incorrects. Veuillez réessayer.";
}

// Exemple d'implémentation complète pour une page de connexion

/*
session_start();

// Si l'utilisateur est déjà connecté, redirection
if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// Connexion à la base de données
require_once '../load_env.php';
loadEnv();

$servername = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost';
$username = $_ENV['DB_USER'] ?? getenv('DB_USER') ?? 'uvcoding';
$password = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?? '';
$dbname = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?? 'uvcoding';

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connexion échouée : " . $conn->connect_error);
}

// Inclure la bibliothèque de journalisation
require_once '../includes/utils/admin_logger.php';

$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username_or_email = trim($_POST['username_or_email']);
    $password = trim($_POST['password']);
    
    // Vérifier si l'utilisateur existe
    $sql = "SELECT * FROM users WHERE (email = ? OR id = ?) AND blocked = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $username_or_email, $username_or_email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user_data = $result->fetch_assoc();
        
        // Vérifier le mot de passe
        if (password_verify($password, $user_data['password'])) {
            // Connexion réussie
            
            // Enregistrer la connexion
            logSuccessfulLogin($conn, $user_data['id']);
            
            // Définir les variables de session
            $_SESSION['user_id'] = $user_data['id'];
            $_SESSION['name'] = $user_data['name'];
            $_SESSION['email'] = $user_data['email'];
            $_SESSION['role'] = $user_data['role'];
            $_SESSION['avatar'] = $user_data['avatar'];
            
            // Redirection en fonction du rôle
            if ($user_data['role'] === 'admin' || $user_data['role'] === 'super_admin') {
                header("Location: ../admin/admin_dashboard.php");
            } else if ($user_data['role'] === 'teacher') {
                header("Location: ../professor/teacher_dashboard.php");
            } else {
                header("Location: ../student/student_dashboard.php");
            }
            exit();
        } else {
            // Mot de passe incorrect
            logFailedLogin($conn, $username_or_email, 'Mot de passe incorrect');
            $error_message = "Identifiants incorrects. Veuillez réessayer.";
        }
    } else {
        // Utilisateur non trouvé ou bloqué
        logFailedLogin($conn, $username_or_email, 'Utilisateur non trouvé ou bloqué');
        $error_message = "Identifiants incorrects. Veuillez réessayer.";
    }
}

$conn->close();
*/