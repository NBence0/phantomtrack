<?php
require_once __DIR__ . '/vendor/autoload.php'; // Composer autoloader
// Adatbázis beállítások
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'phantomtrack_db');
define('DB_USER', 'root'); // Változtasd meg éles környezetben!
define('DB_PASS', '');     // Változtasd meg éles környezetben!

// Alkalmazás URL (fontos a helyes működéshez, / jellel a végén)
define('BASE_URL', 'http://localhost/phantomtrack/'); // Változtasd meg!

// Alapértelmezett értékek
define('DEFAULT_ITEMS_PER_PAGE', 10);

// Automatikus karbantartás beállításai
define('AUTO_CLEANUP_ENABLED', false);     // kikapcsolva
define('AUTO_CLEANUP_INTERVAL', 86400);   // Milyen gyakran fusson? (86400 mp = 24 óra)
define('CHUNK_CLEANUP_AGE', 86400);       // Mennyi idős legyen a chunk mappa, hogy töröljük? (24 óra)
// Munkamenet beállítások
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Hibajelentés (fejlesztéshez)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>