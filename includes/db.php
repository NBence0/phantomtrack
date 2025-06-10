<?php
// Ez a fájl már a config.php-ban van kezelve, de ha külön akarod:
/*
if (!defined('DB_HOST')) { // Hogy a config.php-t ne kelljen újra includolni
    require_once __DIR__ . '/../config.php';
}
*/

function getDB() {
    static $db = null;
    if ($db === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $db = new PDO($dsn, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Éles környezetben itt egy szebb hibaoldalt vagy logolást kellene implementálni
            die('Adatbázis kapcsolódási hiba: ' . $e->getMessage());
        }
    }
    return $db;
}
?>