<?php
// Hely: /FileDownloader.php
// Cél: Fájlok biztonságos letöltésének kezelése, számlálás és naplózás.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$viewToken = $_GET['token'] ?? '';

if (empty($viewToken)) {
    http_response_code(400); die('Hiányzó letöltési token.');
}

$db = getDB();
$stmt = $db->prepare(
    "SELECT id, original_filename, stored_filename, mime_type, download_count, 
            one_time_download, max_downloads, password_hash, expiry_time, 
            ip_whitelist, ip_blacklist 
     FROM files WHERE view_token = :token"
);
$stmt->execute([':token' => $viewToken]);
$fileData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fileData) {
    http_response_code(404); die('A kért fájl nem található.');
}

// A validateFileAccess itt is használható az alap ellenőrzésekre
// (A függvény definíciója a View.php-ből átvehető vagy egy közös helyre tehető)
// Most feltételezzük, hogy a functions.php-ben van.

// Itt egy külön validáció kell, ami a letöltési limiteket is ellenőrzi
function validateDownloadAccess($fileData) {
    // ... (ugyanazok az ellenőrzések, mint a data.php-ben: lejárat, jelszó, IP)
    // PLUSZ:
    if ($fileData['one_time_download'] && $fileData['download_count'] > 0) {
        return ['valid' => false, 'reason' => 'Ezt a fájlt már letöltötték (egyszeri link).'];
    }
    if ($fileData['max_downloads'] !== null && $fileData['download_count'] >= $fileData['max_downloads']) {
        return ['valid' => false, 'reason' => 'Elérte a maximális letöltési számot.'];
    }
    return ['valid' => true];
}
// Az egyszerűség kedvéért most a View.php-ből átvett, kibővített logikát használjuk
$validation = validateFileAccess($fileData); // A View.php-ben definiáltból
if (!$validation['valid']) {
    http_response_code(403); die($validation['reason']);
}

// További, letöltés-specifikus ellenőrzések
if ($fileData['one_time_download'] && $fileData['download_count'] > 0) {
    http_response_code(410); // Gone
    die('Ez a fájl már letöltésre került és törölve lett.');
}
if ($fileData['max_downloads'] !== null && $fileData['download_count'] >= $fileData['max_downloads']) {
    http_response_code(403);
    die('A fájl elérte a maximális letöltési limitet.');
}


// Ha minden rendben, növeljük a számlálót és naplózunk
try {
    $db->beginTransaction();
    
    // 1. Számláló növelése
    $updateStmt = $db->prepare("UPDATE files SET download_count = download_count + 1 WHERE id = :id");
    $updateStmt->execute([':id' => $fileData['id']]);

    // 2. Naplózás
    logActivity('file_download', null, $fileData['id']);

    // 3. Egyszeri link törlése, HA a letöltés sikeresen elindul
    // Ezt a letöltés utánra helyezzük, hogy a megszakadt letöltés ne törölje a fájlt.
    
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    error_log("Download counter/log error: " . $e->getMessage());
    http_response_code(500);
    die("Szerverhiba a letöltés előkészítésekor.");
}

// Fájl kiszolgálása
$filePath = __DIR__ . '/uploads/' . $fileData['stored_filename'];
if (!file_exists($filePath)) {
    http_response_code(404); die('Fizikai fájl nem található.');
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $fileData['mime_type']);
header('Content-Disposition: attachment; filename="' . basename($fileData['original_filename']) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filePath));
flush(); // Kiürítjük a puffert a fájl küldése előtt
readfile($filePath);

// A letöltés befejezése után ellenőrizzük, hogy törölni kell-e
if ($fileData['one_time_download']) {
    try {
        // Töröljük a fizikai fájlokat
        @unlink(__DIR__ . '/uploads/' . $fileData['stored_filename']);
        @unlink(__DIR__ . '/backup/' . $fileData['stored_filename']); // Ha van backup logika
        @unlink(__DIR__ . '/thumbnails/' . $fileData['id'] . '.jpg'); // Thumbnail is
        
        // Töröljük az adatbázisból a bejegyzést
        $deleteStmt = $db->prepare("DELETE FROM files WHERE id = :id");
        $deleteStmt->execute([':id' => $fileData['id']]);
    } catch (Exception $e) {
        error_log("Error deleting one-time download file: " . $e->getMessage());
    }
}

exit;