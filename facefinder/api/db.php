<?php
// facefinder/api/db.php
require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            // Jelenleg SQLite, később MySQL-re váltható (pl. mysql:host=localhost;dbname=visionai)
            $dsn = 'sqlite:' . DB_PATH;
            $this->pdo = new PDO($dsn);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // SQLite optimalizációk
            $this->pdo->exec("PRAGMA journal_mode=WAL;");
            $this->pdo->exec("PRAGMA synchronous=NORMAL;");
            
        } catch (PDOException $e) {
            die(json_encode(['success' => false, 'error' => "Adatbázis hiba: " . $e->getMessage()]));
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }
}
