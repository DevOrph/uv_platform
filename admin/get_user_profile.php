<?php
// Démarrer la session
session_start();
require_once '../includes/db_connect.php';

// Forcer UTF-8
$conn->set_charset('utf8mb4');
header('Content-Type: application/json; charset=utf-8');

// Vérifier si l'utilisateur est connecté et est un administrateur
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit();
}

// Vérifier si l'ID utilisateur est fourni
if (!isset($_GET['user_id']) || empty($_GET['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'ID utilisateur manquant']);
    exit();
}

$user_id = $_GET['user_id'];

// Récupérer les informations complètes de l'utilisateur
$stmt = $conn->prepare("
    SELECT u.*, c.name AS class_name 
    FROM users u 
    LEFT JOIN classes c ON u.class_id = c.id 
    WHERE u.id = ?
");
$stmt->bind_param("s", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Si l'avatar n'est pas défini, utiliser l'avatar par défaut
    if (empty($user['avatar'])) {
        $user['avatar'] = 'default-avatar.png';
    }
    
    // Récupérer la dernière connexion depuis la table user_logins
    $login_stmt = $conn->prepare("
        SELECT login_time 
        FROM user_logins 
        WHERE user_id = ? AND success = 1
        ORDER BY login_time DESC 
        LIMIT 1
    ");
    $login_stmt->bind_param("s", $user_id);
    $login_stmt->execute();
    $login_result = $login_stmt->get_result();
    
    if ($login_result->num_rows > 0) {
        $login_data = $login_result->fetch_assoc();
        $user['last_login'] = $login_data['login_time'];
    } else {
        $user['last_login'] = null;
    }
    $login_stmt->close();

    // Récupérer la candidature liée (documents, dossier, paiement)
    $user['candidature'] = null;
    $cand_stmt = $conn->prepare("
        SELECT * FROM candidatures
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $cand_stmt->bind_param("s", $user_id);
    $cand_stmt->execute();
    $cand_result = $cand_stmt->get_result();
    if ($cand_result->num_rows > 0) {
        $cand = $cand_result->fetch_assoc();
        $paiementLabels = [
            'airtel'   => 'Airtel Money',
            'moov'     => 'Moov Money',
            'card'     => 'Carte bancaire',
            'virement' => 'Virement bancaire',
            'especes'  => 'Espèces (sur place)',
        ];
        $cand['mode_paiement_label'] = $paiementLabels[$cand['mode_paiement'] ?? ''] ?? $cand['mode_paiement'];
        $cand['specialite_label']    = $cand['specialite'];
        $user['candidature'] = $cand;
    }
    $cand_stmt->close();

    // Compatibilité birth_place / place_of_birth
    if (empty($user['birth_place']) && !empty($user['place_of_birth'])) {
        $user['birth_place'] = $user['place_of_birth'];
    }

    // Retirer les champs sensibles
    unset($user['password'], $user['reset_token']);

    // Formater la date de naissance si elle existe
    if (!empty($user['birth_date'])) {
        $birth_date = new DateTime($user['birth_date']);
        $today = new DateTime();
        $age = $today->diff($birth_date)->y;
        $user['age'] = $age;
        $user['birth_date_formatted'] = $birth_date->format('d/m/Y');
    } else {
        $user['age'] = null;
        $user['birth_date_formatted'] = null;
    }
    
    // Formater la dernière connexion
    if (!empty($user['last_login'])) {
        $last_login = new DateTime($user['last_login']);
        $user['last_login_formatted'] = $last_login->format('d/m/Y à H:i');
        
        // Calculer le temps écoulé depuis la dernière connexion
        $now = new DateTime();
        $interval = $now->diff($last_login);
        
        if ($interval->days == 0) {
            if ($interval->h == 0) {
                if ($interval->i == 0) {
                    $user['last_login_relative'] = "Il y a quelques secondes";
                } else {
                    $user['last_login_relative'] = "Il y a " . $interval->i . " minute" . ($interval->i > 1 ? "s" : "");
                }
            } else {
                $user['last_login_relative'] = "Il y a " . $interval->h . " heure" . ($interval->h > 1 ? "s" : "");
            }
        } elseif ($interval->days == 1) {
            $user['last_login_relative'] = "Hier";
        } elseif ($interval->days < 7) {
            $user['last_login_relative'] = "Il y a " . $interval->days . " jours";
        } elseif ($interval->days < 30) {
            $weeks = floor($interval->days / 7);
            $user['last_login_relative'] = "Il y a " . $weeks . " semaine" . ($weeks > 1 ? "s" : "");
        } elseif ($interval->days < 365) {
            $months = floor($interval->days / 30);
            $user['last_login_relative'] = "Il y a " . $months . " mois";
        } else {
            $years = floor($interval->days / 365);
            $user['last_login_relative'] = "Il y a " . $years . " an" . ($years > 1 ? "s" : "");
        }
        
        // Déterminer la classe CSS pour le badge
        if ($interval->days < 7) {
            $user['last_login_badge_class'] = 'last-login-recent';
        } else {
            $user['last_login_badge_class'] = 'last-login-old';
        }
    } else {
        $user['last_login_formatted'] = null;
        $user['last_login_relative'] = "Jamais connecté";
        $user['last_login_badge_class'] = 'last-login-never';
    }
    
    // Formater les dates de création et modification
    if (!empty($user['created_at'])) {
        $created_at = new DateTime($user['created_at']);
        $user['created_at_formatted'] = $created_at->format('d/m/Y à H:i');
    }
    
    echo json_encode(['success' => true, 'user' => $user]);
} else {
    echo json_encode(['success' => false, 'message' => 'Utilisateur introuvable']);
}

$stmt->close();
$conn->close();
?>