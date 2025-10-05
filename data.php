<?php
// Hely: /data.php
// Cél: Nyers fájladatok biztonságos kiszolgálása a View.php előnézeteihez.

// === FÜGGŐSÉGEK ===
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// === BEMENETI ADATOK ÉS VALIDÁCIÓ ===
$viewToken = $_GET['id'] ?? ''; // Itt a 'view_token'-t kapja 'id' néven a View.php-től
$type = $_GET['type'] ?? 'raw'; // 'raw' vagy 'text'

if (empty($viewToken)) {
    http_response_code(400); exit('Hiányzó fájl azonosító.');
}

// 1. Adatbázis lekérdezés
$db = getDB();
$stmt = $db->prepare("SELECT id, stored_filename, mime_type, password_hash, expiry_time, ip_whitelist, ip_blacklist FROM files WHERE view_token = :token");
$stmt->execute([':token' => $viewToken]);
$fileData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fileData) {
    http_response_code(404); exit('Fájl nem található.');
}

// 2. Hozzáférés-ellenőrzés (ugyanaz a logika, mint a View.php-ben)
function validateDataAccess($fileData) {
    // Ez a függvény a View.php-ben definiált 'validateFileAccess' egyszerűsített másolata.
    // A jövőben érdemes lehet ezt egy közös helyre tenni.
    $ip = getIpAddress();
    $now = time();
    $expiryTimestamp = $fileData['expiry_time'] ? strtotime($fileData['expiry_time']) : null;
    
    if ($expiryTimestamp && $now > $expiryTimestamp) return false;
    
    $ipBlacklist = $fileData['ip_blacklist'] ? json_decode($fileData['ip_blacklist'], true) : [];
    if (!empty($ipBlacklist) && in_array($ip, $ipBlacklist)) return false;
    
    $ipWhitelist = $fileData['ip_whitelist'] ? json_decode($fileData['ip_whitelist'], true) : [];
    if (!empty($ipWhitelist) && !in_array($ip, $ipWhitelist)) return false;
    
    if ($fileData['password_hash']) {
        session_start();
        if (!isset($_SESSION['authenticated_files'][$fileData['id']]) || (time() - $_SESSION['authenticated_files'][$fileData['id']] > 3600)) {
            return false;
        }
    }
    return true;
}

if (!validateDataAccess($fileData)) {
    http_response_code(403); exit('Hozzáférés megtagadva.');
}

// 3. Fájl kiszolgálása
$filePath = __DIR__ . '/uploads/' . $fileData['stored_filename'];
if (!file_exists($filePath)) {
    http_response_code(404); exit('Fizikai fájl nem található a szerveren.');
}

switch ($type) {
    case 'text':
        // Szöveges tartalom kiírása (pl. kód vagy markdown előnézethez)
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=1800');
        readfile($filePath);
        break;

    case 'raw':
    default:
        // Nyers fájl kiszolgálása (képek, videók, PDF-ek böngészőben való megjelenítéséhez)
        header('Content-Type: ' . $fileData['mime_type']);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: public, max-age=3600'); // Cache-elhető 1 óráig
        header('ETag: "' . md5_file($filePath) . '"');
        
        // Range requests támogatása videókhoz
        $size = filesize($filePath);
        $start = 0;
        $end = $size - 1;
        
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
                $start = intval($matches[1]);
                if (!empty($matches[2])) $end = intval($matches[2]);
                http_response_code(206);
                header("Content-Range: bytes $start-$end/$size");
                header("Content-Length: " . ($end - $start + 1));
            }
        }

        $handle = fopen($filePath, 'rb');
        fseek($handle, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle)) {
            $chunkSize = min(8192, $remaining);
            echo fread($handle, $chunkSize);
            $remaining -= $chunkSize;
            flush();
        }
        fclose($handle);
        break;
}

exit;