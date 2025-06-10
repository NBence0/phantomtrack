<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$tokenValue = $_GET['token'] ?? null;

if (!$tokenValue) {
    // Nincs token, ne csináljunk semmit, vagy adjunk 400-as hibát
    http_response_code(400);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id, is_active FROM tokens WHERE token_value = :token_value AND user_id IS NOT NULL"); // user_id IS NOT NULL, hogy csak admin által létrehozott legyen
$stmt->bindParam(':token_value', $tokenValue);
$stmt->execute();
$token = $stmt->fetch();


if ($token && $token['is_active']) {
    $ipAddress = getIpAddress();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
    $referrer = $_SERVER['HTTP_REFERER'] ?? 'N/A';

    // 2. Matomo Device Detector használata
    $uaDetails = getDetailedUserAgentInfo($userAgent);

    // 3. IP Geolokáció (ezt a következő pontban implementáljuk)
    $geoDetails = getGeolocationFromIp($ipAddress); // Ezt a függvényt még létre kell hozni

    try {
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
        $logStmt->bindParam(':token_id', $token['id']);
        $logStmt->bindParam(':ip_address', $ipAddress);
        $logStmt->bindParam(':user_agent', $userAgent); // Eredeti UA string
        $logStmt->bindParam(':referrer', $referrer);
        
        // Matomo Device Detector adatok
        $logStmt->bindParam(':browser_name', $uaDetails['client_name']);
        $logStmt->bindParam(':browser_version', $uaDetails['client_version']);
        $logStmt->bindParam(':os_name', $uaDetails['os_name']);
        $logStmt->bindParam(':os_version', $uaDetails['os_version']);
        $logStmt->bindParam(':device_type', $uaDetails['device_type']);
        $logStmt->bindParam(':device_brand', $uaDetails['device_brand']);
        $logStmt->bindParam(':device_model', $uaDetails['device_model']);

        // Geolokációs adatok
        $logStmt->bindParam(':country_code', $geoDetails['country_code']);
        $logStmt->bindParam(':city_name', $geoDetails['city_name']);
        $logStmt->bindParam(':isp', $geoDetails['isp']);
        
        $logStmt->execute();
    } catch (PDOException $e) {
        error_log("Pixel logging error: " . $e->getMessage());
    }
} elseif ($token && !$token['is_active']) {
    // Token létezik, de inaktív. Logolhatnánk ezt is külön, ha kell.
    // Most nem csinálunk semmit, csak kiadjuk a pixelt.
} else {
    // Ismeretlen token. Logolhatnánk ezt is.
    // Most nem csináljunk semmit, csak kiadjuk a pixelt, hogy ne tűnjön fel a hiba.
}

// 1x1 átlátszó GIF pixel küldése
header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
// R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7 (base64 kódolt 1x1 átlátszó GIF)
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;
?>