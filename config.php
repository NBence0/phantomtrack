<?php
require_once __DIR__ . '/vendor/autoload.php'; // Composer autoloader
// Adatbázis beállítások
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'phantomtrack_db');
define('DB_USER', 'root'); // Változtasd meg éles környezetben!
define('DB_PASS', '');     // Változtasd meg éles környezetben!

// Alkalmazás URL (fontos a helyes működéshez, / jellel a végén)
define('BASE_URL', 'http://localhost:81/phantomtrack/'); // Változtasd meg!

// Alapértelmezett értékek
define('DEFAULT_ITEMS_PER_PAGE', 10);

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