<?php
// Hely: /pixel.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$tokenValue = $_GET['token'] ?? null;

// Gyors kilépés, ha nincs token, vagy érvénytelen
if (!$tokenValue) {
    http_response_code(400);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, is_active, name, webhook_url FROM tokens WHERE token_value = :token_value AND user_id IS NOT NULL");
$stmt->execute([':token_value' => $tokenValue]);
$token = $stmt->fetch();

// --- 1. AZONNALI VÁLASZ ---
// Még mielőtt bármi lassú műveletbe kezdenénk, kiadjuk a pixelt.

// HTTP fejlécek beállítása
header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// A 1x1 átlátszó GIF kiadása
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

// --- 2. HÁTTÉRBEN FUTÓ FELDOLGOZÁS ---

// Ha létezik a fastcgi_finish_request() függvény (PHP-FPM használata esetén),
// akkor elküldjük a választ, és csak utána folytatjuk a futást.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// Ha a token inaktív vagy nem létezik, itt álljunk meg.
if (!$token || !$token['is_active']) {
    exit;
}

// Most, hogy a felhasználó már megkapta a választ, jöhet a lassú feldolgozás.
// A futási idő limitjét megnöveljük, hogy a lassú műveleteknek legyen idejük lefutni.
set_time_limit(60); 

try {
    // Adatok összegyűjtése (a logika nem változik)
    $ipAddress = getIpAddress();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
    $referrer = $_SERVER['HTTP_REFERER'] ?? 'N/A';

    $uaDetails = getDetailedUserAgentInfo($userAgent);
    $geoDetails = getGeolocationFromIp($ipAddress);

    // Adatbázisba írás (a logika nem változik)
    $logStmt = $db->prepare("
        INSERT INTO activity_logs 
        (token_id, ip_address, user_agent, referrer, 
         browser_name, browser_version, os_name, os_version, 
         device_type, device_brand, device_model,
         country_code, city_name, isp, timestamp) 
        VALUES (:token_id, :ip_address, :user_agent, :referrer, 
         :browser_name, :browser_version, :os_name, :os_version,
         :device_type, :device_brand, :device_model,
         :country_code, :city_name, :isp, NOW())
    ");

    $logStmt->execute([
        ':token_id'         => $token['id'],
        ':ip_address'       => $ipAddress,
        ':user_agent'       => $userAgent,
        ':referrer'         => $referrer,
        ':browser_name'     => $uaDetails['client_name'],
        ':browser_version'  => $uaDetails['client_version'],
        ':os_name'          => $uaDetails['os_name'],
        ':os_version'       => $uaDetails['os_version'],
        ':device_type'      => $uaDetails['device_type'],
        ':device_brand'     => $uaDetails['device_brand'],
        ':device_model'     => $uaDetails['device_model'],
        ':country_code'     => $geoDetails['country_code'],
        ':city_name'        => $geoDetails['city_name'],
        ':isp'              => $geoDetails['isp']
    ]);
    
    if (!empty($token['webhook_url'])) {
    $msg = "**IP:** {$ipAddress}\n**Eszköz:** " . ($uaDetails['device_type'] ?? 'N/A') . "\n**Hely:** " . ($geoDetails['city_name'] ?? 'Unknown') . ", " . ($geoDetails['country_code'] ?? 'N/A');
    sendWebhookNotification($token['webhook_url'], "pixel" . $token['name'], $msg, 3066993); // Zöld
    }

} catch (Exception $e) {
    // Hiba esetén naplózzuk a háttérben. A felhasználó erről már nem szerez tudomást.
    error_log("PhantomTrack pixel.php error: " . $e->getMessage());
}

exit; // Biztos, ami biztos.
?>