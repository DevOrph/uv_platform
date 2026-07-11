<?php
/**
 * Configuration de la base de données pour l'API Mobile UV
 * 
 * @package UV-API-Mobile
 * @author Orphé MYENE & Filbert KASSA - Coding Enterprise
 */

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        // Identifiants chargés depuis .env (racine du projet), jamais en dur
        require_once __DIR__ . '/../../load_env.php';
        loadEnv(__DIR__ . '/../../.env');
        $this->host     = getenv('DB_HOST') ?: 'localhost';
        $this->db_name  = getenv('DB_NAME') ?: 'uvcoding';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
    }
    
    /**
     * Obtenir la connexion à la base de données
     * 
     * @return PDO|null
     */
    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password,
                array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                )
            );
        } catch(PDOException $exception) {
            error_log("Erreur de connexion DB: " . $exception->getMessage());
            return null;
        }
        
        return $this->conn;
    }
    
    /**
     * Fermer la connexion
     */
    public function closeConnection() {
        $this->conn = null;
    }
}
?>