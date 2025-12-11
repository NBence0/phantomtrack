<?php
require_once __DIR__ . '/vendor/autoload.php'; // Composer autoloader
// Adatbázis beállítások
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'phantomtrack_db');
define('DB_USER', 'phantomtrack'); // Változtasd meg éles környezetben!
define('DB_PASS', 'Aa123456');     // Változtasd meg éles környezetben!

// Alkalmazás URL (fontos a helyes működéshez, / jellel a végén)
define('BASE_URL', 'http://nbtmp.hu/phantomtrack/'); // Változtasd meg!

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


// Google OAuth 2.0 beállítások
define('GOOGLE_CLIENT_ID', '983694138482-junh75btidv3tjpe4vgjuhio47pau657.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');
define('GOOGLE_REDIRECT_URI', BASE_URL . 'login/google_auth.php');

// Facebook OAuth beállítások
define('FB_APP_ID', '1294821296010804');
define('FB_APP_SECRET', '3be405e73b4ded7f3d70b1859f45fd54');
define('FB_REDIRECT_URI', BASE_URL . 'login/facebook_auth.php');

// GitHub OAuth beállítások
define('GITHUB_CLIENT_ID', 'Ov23lie7vxWxcVIjSQWL');
define('GITHUB_CLIENT_SECRET', '9a5bae7ad0fdf32dbc295ddca6b7e9297087b498');
define('GITHUB_REDIRECT_URI', BASE_URL . 'login/github_auth.php'); // Figyelj az új login mappára!

// Hibajelentés (fejlesztéshez)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>