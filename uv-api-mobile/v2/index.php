<?php
/**
 * Routeur principal — UV API Mobile v2
 */

// ─── 1. Capture toutes les erreurs PHP dès le départ ─────────────────────────
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'Erreur PHP',
        'data'    => ['error' => $errstr, 'file' => basename($errfile), 'line' => $errline],
    ]);
    exit;
});

set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'Exception non capturée',
        'data'    => ['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()],
    ]);
    exit;
});

// ─── 2. Autoload Composer ─────────────────────────────────────────────────────
define('API_ROOT',    __DIR__);
define('VENDOR_PATH', dirname(dirname(API_ROOT)) . '/vendor/autoload.php');

if (!file_exists(VENDOR_PATH)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'vendor/autoload.php introuvable. Lancez "composer install" sur le serveur.',
        'data'    => ['expected_path' => VENDOR_PATH],
    ]);
    exit;
}

require_once VENDOR_PATH;

// Vérifier que firebase/php-jwt est bien chargé
if (!class_exists('Firebase\JWT\JWT')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => false,
        'message' => 'firebase/php-jwt absent. Lancez : composer require firebase/php-jwt sur le serveur.',
        'data'    => null,
    ]);
    exit;
}

// ─── 3. Fichiers internes ─────────────────────────────────────────────────────
require_once API_ROOT . '/config/constants.php';
require_once API_ROOT . '/config/database.php';
require_once API_ROOT . '/helpers/Response.php';
require_once API_ROOT . '/helpers/JWT.php';
require_once API_ROOT . '/middleware/Auth.php';
require_once API_ROOT . '/controllers/AuthController.php';
require_once API_ROOT . '/controllers/StudentController.php';
require_once API_ROOT . '/controllers/ProfessorController.php';
require_once API_ROOT . '/controllers/DocumentController.php';
require_once API_ROOT . '/controllers/NotificationController.php';

// ─── 4. Headers CORS ─────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── 5. Debug temporaire ──────────────────────────────────────────────────────
// Accès : GET /uv-api-mobile/v2/index.php?debug=1  — à supprimer après validation
if (isset($_GET['debug'])) {
    http_response_code(200);
    echo json_encode([
        'REQUEST_URI'    => $_SERVER['REQUEST_URI']    ?? null,
        'PATH_INFO'      => $_SERVER['PATH_INFO']      ?? null,
        'SCRIPT_NAME'    => $_SERVER['SCRIPT_NAME']    ?? null,
        'PHP_SELF'       => $_SERVER['PHP_SELF']       ?? null,
        'QUERY_STRING'   => $_SERVER['QUERY_STRING']   ?? null,
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
        'firebase_jwt'   => class_exists('Firebase\JWT\JWT') ? 'OK' : 'ABSENT',
        'vendor_path'    => VENDOR_PATH,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── 6. Extraction du chemin ──────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];

if (!empty($_SERVER['PATH_INFO'])) {
    // Cas : accès direct /index.php/auth/login — PATH_INFO = /auth/login
    $path = '/' . trim($_SERVER['PATH_INFO'], '/');
} else {
    $rawUri   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $basePath = '/uv-api-mobile/v2';

    $relative = str_starts_with($rawUri, $basePath)
        ? substr($rawUri, strlen($basePath))
        : $rawUri;

    // Retirer /index.php en tête si présent
    $relative = preg_replace('#^/?index\.php#', '', $relative);

    $path = '/' . trim($relative, '/');
}

if ($path === '') {
    $path = '/';
}

// ─── 7. Table de routage ──────────────────────────────────────────────────────
// IMPORTANT : une seule espace entre méthode et chemin
$routes = [
    // ── Auth ──────────────────────────────────────────────────────────────
    'GET /ping'                  => [AuthController::class,    'ping',           false],
    'POST /auth/login'           => [AuthController::class,    'login',          false],
    'POST /auth/logout'          => [AuthController::class,    'logout',         true],
    'POST /auth/refresh'         => [AuthController::class,    'refresh',        false],
    'POST /auth/forgot-password' => [AuthController::class,    'forgotPassword', false],

    // ── Student — Phase 2 ─────────────────────────────────────────────────
    'GET /student/profile'          => [StudentController::class,   'profile',        true],
    'PUT /student/profile'          => [StudentController::class,   'updateProfile',  true],
    'POST /student/change-password' => [StudentController::class,   'changePassword', true],
    'GET /student/grades'           => [StudentController::class,   'grades',         true],
    'GET /student/schedule'         => [StudentController::class,   'schedule',       true],
    'GET /student/announcements'    => [StudentController::class,   'announcements',  true],

    // ── Professor — Phase 3 ───────────────────────────────────────────────
    'GET /professor/classes'        => [ProfessorController::class, 'classes',        true],
    'GET /professor/courses'        => [ProfessorController::class, 'courses',        true],
    'GET /professor/schedule'       => [ProfessorController::class, 'schedule',       true],
    'GET /professor/exam-permission'=> [ProfessorController::class, 'examPermission', true],
    'GET /professor/grades'         => [ProfessorController::class, 'grades',         true],
    'POST /professor/grades'        => [ProfessorController::class, 'addGrade',       true],
    'PUT /professor/grades'         => [ProfessorController::class, 'updateGrade',    true],
    'GET /professor/discussions'    => [ProfessorController::class, 'discussions',    true],
    'POST /professor/discussions'   => [ProfessorController::class, 'sendMessage',    true],

    // ── Student — Discussions ─────────────────────────────────────────────
    'GET /student/discussions'      => [StudentController::class,   'discussions',    true],
    'POST /student/discussions'     => [StudentController::class,   'sendMessage',    true],

    // ── Student — Documents ───────────────────────────────────────────────
    'GET /student/documents'        => [DocumentController::class,  'studentList',    true],
    'POST /student/documents'       => [DocumentController::class,  'studentUpload',  true],
    'DELETE /student/documents'     => [DocumentController::class,  'studentDelete',  true],

    // ── Professor — Documents ─────────────────────────────────────────────
    'GET /professor/documents'      => [DocumentController::class,  'professorList',       true],
    'POST /professor/documents'     => [DocumentController::class,  'professorUpload',     true],
    'DELETE /professor/documents'   => [DocumentController::class,  'professorDelete',     true],

    // ── Student — Phase 4 ─────────────────────────────────────────────────
    'GET /student/payments'          => [StudentController::class,   'payments',            true],
    'POST /student/payments/message' => [StudentController::class,   'sendFinanceMessage',  true],
    'GET /student/rattrapage'        => [StudentController::class,   'rattrapage',          true],

    // ── Shared — Notifications ────────────────────────────────────────────
    'POST /shared/device-token'       => [NotificationController::class, 'registerDeviceToken', true],

    // ── Professor — Phase 4 ───────────────────────────────────────────────
    'GET /professor/profile'          => [ProfessorController::class, 'profile',             true],
    'PUT /professor/profile'          => [ProfessorController::class, 'updateProfile',       true],
    'POST /professor/change-password' => [ProfessorController::class, 'changePassword',      true],
    'GET /professor/rattrapage'       => [ProfessorController::class, 'rattrapageList',      true],
    'POST /professor/rattrapage'      => [ProfessorController::class, 'saveRattrapageGrade', true],
];

// Clé normalisée : une seule espace, pas de trailing slash sur le path
$routeKey = $method . ' ' . rtrim($path, '/');
if ($routeKey === $method . ' ') {
    $routeKey = $method . ' /';
}

if (!isset($routes[$routeKey])) {
    Response::error("Route introuvable : [$routeKey]", 404, [
        'parsed_path' => $path,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
        'path_info'   => $_SERVER['PATH_INFO']   ?? null,
    ]);
}

// ─── 8. Dispatch ──────────────────────────────────────────────────────────────
try {
    [$controllerClass, $action, $requiresAuth] = $routes[$routeKey];

    $currentUser = null;
    if ($requiresAuth) {
        $currentUser = Auth::requireAuth();
    }

    $db         = Database::getInstance()->getConnection();
    $controller = new $controllerClass($db);
    $controller->$action($currentUser);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur interne',
        'data'    => [
            'error' => $e->getMessage(),
            'file'  => basename($e->getFile()),
            'line'  => $e->getLine(),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
