<?php
// db_connect_public.php
// ✅ Connexion pour les pages PUBLIQUES (inscription, récupération de mot de passe, etc.)
// PAS de vérification de session - accessible à tous

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db_config.php';

// Informations de connexion
$cfg = get_db_config();
$servername = $cfg['host'];
$username = $cfg['user'];
$password = $cfg['pass'];
$dbname = $cfg['name'];

// Connexion à la base de données
$conn = new mysqli($servername, $username, $password, $dbname);

// Définir le charset UTF-8 pour éviter les problèmes d'encodage
$conn->set_charset($cfg['charset']);
$conn->query("SET collation_connection = '{$cfg['charset']}_general_ci'");

// Vérifier la connexion
if ($conn->connect_error) {
    // En production, ne pas afficher les détails de l'erreur
    error_log("Erreur de connexion DB: " . $conn->connect_error);
    die("Erreur de connexion à la base de données. Veuillez réessayer plus tard.");
}

// Note: Pas de vérification de session ici - ce fichier est pour les pages publiques
?>