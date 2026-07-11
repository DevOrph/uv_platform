<?php
require_once __DIR__ . '/../load_env.php';

if (!function_exists('get_db_config')) {
    function get_db_config(array $overrides = []): array {
        $envPath = dirname(__DIR__) . '/.env';
        if (file_exists($envPath)) {
            loadEnv($envPath);
        }

        $config = [
            'host'    => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1',
            'name'    => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'uvcoding',
            'user'    => $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root',
            'pass'    => $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '',
            'charset' => $_ENV['DB_CHARSET'] ?? getenv('DB_CHARSET') ?: 'utf8mb4',
        ];

        foreach (['host', 'name', 'user', 'pass', 'charset'] as $key) {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
                $config[$key] = $overrides[$key];
            }
        }

        return $config;
    }
}

if (!function_exists('get_db_connection')) {
    function get_db_connection(array $overrides = []): mysqli {
        $config = get_db_config($overrides);
        $conn = new mysqli($config['host'], $config['user'], $config['pass'], $config['name']);

        if ($conn->connect_error) {
            throw new RuntimeException('Connexion MySQL échouée : ' . $conn->connect_error);
        }

        $conn->set_charset($config['charset']);
        $conn->query("SET collation_connection = '{$config['charset']}_general_ci'");

        return $conn;
    }
}

if (!function_exists('get_pdo_connection')) {
    function get_pdo_connection(array $overrides = []): PDO {
        $config = get_db_config($overrides);
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['name'], $config['charset']);

        return new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
}
