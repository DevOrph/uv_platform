<?php
/**
 * Constantes globales de l'API v2
 * ⚠ Changer JWT_SECRET par une valeur aléatoire forte en production
 *    Ex: bin2hex(random_bytes(32)) dans une console PHP
 */

// Secrets chargés depuis .env (racine du projet), jamais en dur
require_once __DIR__ . '/../../../load_env.php';
loadEnv(__DIR__ . '/../../../.env');

// ── JWT ────────────────────────────────────────────────────────────────────
define('JWT_SECRET',          getenv('JWT_SECRET') ?: 'dev-secret-a-changer');
define('JWT_ACCESS_EXPIRY',   15 * 60);          // 15 minutes (secondes)
define('JWT_REFRESH_EXPIRY',  30 * 24 * 3600);   // 30 jours (secondes)
define('JWT_ALGO',            'HS256');

// ── App ────────────────────────────────────────────────────────────────────
define('APP_NAME',    'UV Platform API');
define('APP_VERSION', '2.0.0');
define('APP_ENV',     'production');              // 'development' | 'production'

// ── Base de données ────────────────────────────────────────────────────────
define('DB_HOST',     getenv('DB_HOST') ?: 'localhost');
define('DB_NAME',     getenv('DB_NAME') ?: 'uvcoding');
define('DB_USER',     getenv('DB_USER') ?: 'root');
define('DB_PASS',     getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET',  getenv('DB_CHARSET') ?: 'utf8mb4');

// ── Année académique ───────────────────────────────────────────────────────
// Calcul automatique : mois >= 9 → YYYY-(YYYY+1), sinon (YYYY-1)-YYYY
// La valeur dans `parametres` (cle='annee_academique_courante') est un
// forçage manuel optionnel : si non vide, elle est prioritaire.
if (!defined('ANNEE_ACADEMIQUE_COURANTE')) {
    $__m     = (int) date('n');
    $__y     = (int) date('Y');
    $__annee = $__m >= 9 ? "$__y-" . ($__y + 1) : ($__y - 1) . "-$__y";

    try {
        $__dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $__pdo = new PDO($__dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $__stmt = $__pdo->query("SELECT valeur FROM parametres WHERE cle = 'annee_academique_courante' LIMIT 1");
        $__row  = $__stmt ? $__stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($__row && trim($__row['valeur']) !== '') {
            $__annee = trim($__row['valeur']);
        }
        unset($__dsn, $__pdo, $__stmt, $__row);
    } catch (PDOException $__e) {
        unset($__e);
    }

    define('ANNEE_ACADEMIQUE_COURANTE', $__annee);
    unset($__m, $__y, $__annee);
}
