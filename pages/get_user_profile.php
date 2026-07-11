<?php
// ============================================================
//  ISMM — get_user_profile.php
//  Retourne le profil complet d'un utilisateur en JSON
//  Inclut toutes les données de candidature + champs "Autre"
// ============================================================
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Non autorisé']);
    exit;
}

$user_id = $_GET['user_id'] ?? '';
if (empty($user_id)) {
    echo json_encode(['success' => false, 'message' => 'ID manquant']);
    exit;
}

$conn->set_charset('utf8mb4');

// — Récupérer l'utilisateur —
$stmt = $conn->prepare("
    SELECT u.*, c.name AS class_name
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE u.id = ?
");
$stmt->bind_param("s", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Utilisateur introuvable']);
    exit;
}

// — Récupérer la candidature (tous les champs dont les "Autre") —
$stmt2 = $conn->prepare("
    SELECT *
    FROM candidatures
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt2->bind_param("s", $user_id);
$stmt2->execute();
$candidature = $stmt2->get_result()->fetch_assoc();
$stmt2->close();

// — Formater les dates —
$user['created_at_formatted'] = $user['created_at']
    ? (new DateTime($user['created_at']))->format('d/m/Y à H:i')
    : null;

$user['birth_date_formatted'] = $user['birth_date']
    ? (new DateTime($user['birth_date']))->format('d/m/Y')
    : null;

if ($user['birth_date']) {
    $user['age'] = (new DateTime($user['birth_date']))->diff(new DateTime())->y;
}

// — Dernière connexion —
if ($user['last_login']) {
    $lastLogin = new DateTime($user['last_login']);
    $now       = new DateTime();
    $diff      = $now->diff($lastLogin);
    $user['last_login_formatted'] = $lastLogin->format('d/m/Y à H:i');

    if ($diff->days === 0) {
        $user['last_login_relative']    = "Aujourd'hui";
        $user['last_login_badge_class'] = 'last-login-recent';
    } elseif ($diff->days <= 7) {
        $user['last_login_relative']    = "Il y a {$diff->days} jour(s)";
        $user['last_login_badge_class'] = 'last-login-recent';
    } elseif ($diff->days <= 30) {
        $user['last_login_relative']    = "Il y a {$diff->days} jours";
        $user['last_login_badge_class'] = 'last-login-old';
    } else {
        $user['last_login_relative']    = "Il y a plus d'un mois";
        $user['last_login_badge_class'] = 'last-login-old';
    }
} else {
    $user['last_login_formatted']   = null;
    $user['last_login_relative']    = 'Jamais connecté';
    $user['last_login_badge_class'] = 'last-login-never';
}

// — Associer la candidature —
if ($candidature) {
    // Libellés lisibles pour mode de paiement
    $modesLabels = [
        'airtel'   => 'Airtel Money',
        'moov'     => 'Moov Money',
        'card'     => 'Carte bancaire',
        'virement' => 'Virement bancaire',
    ];
    $candidature['mode_paiement_label'] = $modesLabels[$candidature['mode_paiement']] ?? $candidature['mode_paiement'];

    // Libellés spécialités
    $specLabels = [
        'info'       => 'Informatique & Réseaux',
        'gestion'    => 'Gestion & Finance',
        'droit'      => 'Droit & Juridique',
        'marketing'  => 'Marketing & Communication',
        'logistique' => 'Logistique & Supply Chain',
        'rh'         => 'Ressources Humaines',
        'sante'      => 'Santé & Social',
    ];
    // Afficher le libellé propre ou la valeur brute
    $rawSpec = $candidature['specialite'] ?? '';
    if (isset($specLabels[$rawSpec])) {
        $candidature['specialite_label'] = $specLabels[$rawSpec];
    } elseif (strpos($rawSpec, 'Autre:') === 0) {
        $candidature['specialite_label'] = $rawSpec; // "Autre: Architecture"
    } else {
        $candidature['specialite_label'] = $rawSpec;
    }

    // Date de candidature formatée
    if (!empty($candidature['created_at'])) {
        $candidature['created_at_formatted'] = (new DateTime($candidature['created_at']))->format('d/m/Y à H:i');
    }

    $user['candidature'] = $candidature;
} else {
    $user['candidature'] = null;
}

// Retirer le mot de passe de la réponse
unset($user['password'], $user['reset_token'], $user['token_expiration']);

echo json_encode(['success' => true, 'user' => $user]);
$conn->close();
