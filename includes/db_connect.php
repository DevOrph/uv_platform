<?php
// ── Session (DOIT être la toute première chose, avant tout output) ──
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db_config.php';

// Connexion à la base de données
$cfg = get_db_config();
$servername = $cfg['host'];
$username   = $cfg['user'];
$password   = $cfg['pass'];
$dbname     = $cfg['name'];

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("La connexion à MySQL a échoué : " . $conn->connect_error);
}

$conn->set_charset($cfg['charset']);
$conn->query("SET collation_connection = '{$cfg['charset']}_general_ci'");

// ── Année académique courante ──────────────────────────────────────────────
// Calcul automatique : mois >= 9 → YYYY-(YYYY+1), sinon (YYYY-1)-YYYY
// La valeur dans `parametres` (cle='annee_academique_courante') est un
// forçage manuel optionnel : si non vide, elle est prioritaire.
if (!defined('ANNEE_ACADEMIQUE_COURANTE')) {
    $__m    = (int) date('n');
    $__y    = (int) date('Y');
    $__annee = $__m >= 9 ? "$__y-" . ($__y + 1) : ($__y - 1) . "-$__y";

    $__r = $conn->query("SELECT valeur FROM parametres WHERE cle = 'annee_academique_courante' LIMIT 1");
    if ($__r && $__r->num_rows > 0) {
        $__forced = trim($__r->fetch_assoc()['valeur']);
        if ($__forced !== '') {
            $__annee = $__forced;
        }
        unset($__forced);
    }
    define('ANNEE_ACADEMIQUE_COURANTE', $__annee);
    unset($__m, $__y, $__annee, $__r);
}

// ── Institution courante ───────────────────────────────────────────────────
// Lue depuis `parametres` ; fallback sur 'UAS' (valeur active en BDD).
if (!defined('INSTITUTION_ID')) {
    $__inst = 'UAS';
    $__r2 = $conn->query("SELECT valeur FROM parametres WHERE cle = 'institution_id' LIMIT 1");
    if ($__r2 && $__r2->num_rows > 0) {
        $__v = trim($__r2->fetch_assoc()['valeur']);
        if ($__v !== '') {
            $__inst = $__v;
        }
        unset($__v);
    }
    define('INSTITUTION_ID', $__inst);
    unset($__inst, $__r2);
}

// ── Protection CSRF ───────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!defined('CSRF_TOKEN')) {
    define('CSRF_TOKEN', $_SESSION['csrf_token']);
}

function verify_csrf() {
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod === 'GET') return;
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($requestUri, '/uv-api-mobile/') !== false) return;
    if (strpos($requestUri, '/api/') !== false) return;

    $session_token = $_SESSION['csrf_token'] ?? '';

    $header_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $post_token   = $_POST['csrf_token'] ?? '';
    $json_token   = '';
    if (empty($post_token) && empty($header_token)) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $data = json_decode($raw, true);
            $json_token = $data['csrf_token'] ?? '';
        }
    }

    $received = $header_token ?: $post_token ?: $json_token;

    if (empty($session_token) || !hash_equals($session_token, $received)) {
        http_response_code(403);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($header_token)) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Token CSRF invalide', 'code' => 403]);
        } else {
            echo "Accès refusé — token CSRF invalide.";
        }
        exit;
    }
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST') {
    $uri      = $_SERVER['REQUEST_URI'] ?? '';
    $uri_path = parse_url($uri, PHP_URL_PATH) ?? $uri;

    // Pages de login/register : exemption flexible (check by filename or full path)
    $exempt_files = [
        'login.php',
        'login1.php',
        'register.php',
        'register_handler.php',
    ];
    $is_exempt = false;
    
    // Check if the URI ends with any exempt file
    foreach ($exempt_files as $file) {
        if (strpos($uri_path, $file) !== false) {
            $is_exempt = true;
            break;
        }
    }

    // APIs mobiles et internes : préfixe suffisant (dossiers entiers)
    if (!$is_exempt) {
        foreach (['/uv-api-mobile/', '/api/'] as $path) {
            if (strpos($uri, $path) !== false) { $is_exempt = true; break; }
        }
    }

    if (!$is_exempt) {
        verify_csrf();
    }
}