<?php
// ============================================================
// auth_check.php — Vérification session + SSO
// À inclure EN HAUT de chaque page protégée, à la place de :
//   session_start();
//   if (!isset($_SESSION['user_id'])) { header(...); exit(); }
//   $conn = new mysqli(...);
//
// Usage :
//   require_once '../includes/auth_check.php';
//   // $conn est déjà disponible après cet include
// ============================================================

session_start();

require_once __DIR__ . '/db_config.php';

// ── Config DB (une seule fois ici) ──────────────────────────
$cfg = get_db_config();
$servername = $cfg['host'];
$db_user    = $cfg['user'];
$db_pass    = $cfg['pass'];
$dbname     = $cfg['name'];

// ── URL de l'API SSO ─────────────────────────────────────────
// En local  : http://localhost/api
// En prod   : https://api.uvcoding.com
define('SSO_API_URL', 'http://localhost/api');

// ── URL de la page de login ──────────────────────────────────
// Ajuste le chemin selon la page qui inclut ce fichier
define('LOGIN_URL', '../pages/login.php');

// ============================================================
// 1. Déjà connecté via session PHP → OK, on continue
// ============================================================
if (isset($_SESSION['user_id'])) {
    // Connexion DB et on sort
    $conn = new mysqli($servername, $db_user, $db_pass, $dbname);
    $conn->set_charset("utf8mb4");
    $conn->query("SET collation_connection = 'utf8mb4_general_ci'");
    if ($conn->connect_error) {
        die("Connexion MySQL échouée : " . $conn->connect_error);
    }
    return; // ← la page continue normalement
}

// ============================================================
// 2. Pas de session → vérifier si un token SSO arrive en URL
//    ex: student_dashboard.php?sso_token=xxxxx
// ============================================================
$sso_token = $_GET['sso_token'] ?? $_COOKIE['sso_token'] ?? null;

if ($sso_token) {
    // Vérifier le token auprès de l'API centrale
    $ctx = stream_context_create([
        "http" => [
            "method" => "GET",
            "header" => "Authorization: Bearer " . $sso_token,
            "timeout" => 5
        ]
    ]);

    $response = @file_get_contents(SSO_API_URL . "/me", false, $ctx);

    if ($response !== false) {
        $sso_user = json_decode($response, true);

        if (!empty($sso_user['email'])) {
            // Token valide → chercher l'utilisateur dans la DB UV
            $conn = new mysqli($servername, $db_user, $db_pass, $dbname);
            $conn->set_charset("utf8mb4");
            $conn->query("SET collation_connection = 'utf8mb4_general_ci'");

            $email = strtolower(trim($sso_user['email']));
            $stmt  = $conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if (!$user) {
                // Utilisateur inconnu dans UV → création automatique
                $new_id   = uniqid('usr_', true);
                $new_name = $sso_user['name'] ?? $email;
                $new_role = 'student';
                $new_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);

                $ins = $conn->prepare(
                    "INSERT INTO users (id, name, email, password, role) VALUES (?,?,?,?,?)"
                );
                $ins->bind_param("sssss", $new_id, $new_name, $email, $new_pass, $new_role);
                $ins->execute();

                // Recharger l'utilisateur
                $stmt2 = $conn->prepare("SELECT * FROM users WHERE id = ?");
                $stmt2->bind_param("s", $new_id);
                $stmt2->execute();
                $user = $stmt2->get_result()->fetch_assoc();
            }

            if ($user) {
                // Ouvrir la session PHP normalement
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['name']      = $user['name'];
                $_SESSION['email']     = $user['email'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['avatar']    = $user['avatar'] ?? 'default_avatar.png';
                $_SESSION['sso_token'] = $sso_token;

                // Stocker le token dans un cookie (valide 1h, même domaine)
                setcookie('sso_token', $sso_token, [
                    'expires'  => time() + 3600,
                    'path'     => '/',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);

                // Nettoyer le token de l'URL par redirection propre
                $clean_url = strtok($_SERVER['REQUEST_URI'], '?');
                header("Location: " . $clean_url);
                exit();
            }
        }
    }
}

// ============================================================
// 3. Ni session ni token valide → redirection login
// ============================================================
header("Location: " . LOGIN_URL);
exit();
