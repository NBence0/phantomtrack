<?php
/**
 * PhantomTrack - API végpont URL-ek naplózásához
 *
 * Ez a végpont a következőképpen működik:
 * 1. Fogadja a `api_token`, `url` és opcionálisan a `redirect` paramétereket a GET kérésben.
 * 2. Érvényesíti a bemenetet.
 * 3. Megkeresi a felhasználóhoz és a `url`-hez tartozó meglévő `pixel_token`-t.
 * 4. Ha nincs ilyen token, de a felhasználó engedélyezte, létrehoz egy újat.
 * 5. Ha van érvényes token (meglévő vagy új), naplózza a látogatást a részletes adatokkal.
 * 6. A `redirect` paramétertől függően vagy átirányít az `url`-re, vagy JSON választ ad.
 */

// --- 1. Alapvető beállítások és függőségek ---
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';


// --- 2. HTTP Headerek beállítása ---
// A Content-Type fejlécet csak akkor állítjuk be, ha JSON választ adunk.
// Az Access-Control-Allow-Origin maradhat, mert minden válaszra érvényes.
header("Access-Control-Allow-Origin: *");


// --- 3. Bemeneti adatok fogadása és validálása ---
$userApiToken = $_GET['api_token'] ?? null;
$logUrl = $_GET['url'] ?? null;
// --- ÚJ ---: Az átirányítási paraméter beolvasása.
$redirectParam = $_GET['redirect'] ?? 'false'; // Alapértelmezetten 'false', ha nincs megadva.

if (!$userApiToken || !$logUrl) {
    header("Content-Type: application/json; charset=UTF-8");
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Hiányzó api_token vagy url paraméter.']);
    exit;
}

try {
    // --- 4. Adatbázis-műveletek ---
    $db = getDB();
    
    // Felhasználó azonosítása az API token alapján
    $userStmt = $db->prepare("SELECT id, allow_api_token_creation FROM users WHERE api_token = :api_token");
    $userStmt->execute([':api_token' => $userApiToken]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(403); // Forbidden
        echo json_encode(['status' => 'error', 'message' => 'Érvénytelen API token.']);
        exit;
    }

    $currentUserId = $user['id'];
    $allowCreation = (bool)$user['allow_api_token_creation'];

    $pixelTokenValue = null;
    $pixelTokenId = null;

    // Meglévő `pixel_token` keresése
    $tokenExistsStmt = $db->prepare("SELECT id, token_value FROM tokens WHERE user_id = :user_id AND name = :name AND is_active = 1");
    $tokenExistsStmt->execute([':user_id' => $currentUserId, ':name' => $logUrl]);
    $existingToken = $tokenExistsStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingToken) {
        $pixelTokenValue = $existingToken['token_value'];
        $pixelTokenId = $existingToken['id'];
    } elseif ($allowCreation) {
        // Új token létrehozása, ha engedélyezve van
        $newPixelTokenValue = generateUniqueToken();
        $createStmt = $db->prepare(
            "INSERT INTO tokens (user_id, token_value, name, description, is_active, created_at) 
             VALUES (:user_id, :token_value, :name, 'Automatikusan létrehozva API-n keresztül.', 1, NOW())"
        );
        
        $params = [
            ':user_id'      => $currentUserId,
            ':token_value'  => $newPixelTokenValue,
            ':name'         => $logUrl,
        ];
        
        if ($createStmt->execute($params)) {
            $pixelTokenValue = $newPixelTokenValue;
            $pixelTokenId = $db->lastInsertId();
        }
    }

    // --- 5. Látogatás naplózása, ha van érvényes token ---
    if ($pixelTokenId) {
        $ipAddress = getIpAddress();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
        $referrer = $logUrl;

        $uaDetails = getDetailedUserAgentInfo($userAgent);
        $geoDetails = getGeolocationFromIp($ipAddress);

        $logStmt = $db->prepare("
            INSERT INTO activity_logs 
            (token_id, ip_address, user_agent, referrer, browser_name, browser_version, os_name, os_version, device_type, device_brand, device_model, country_code, city_name, isp, timestamp) 
            VALUES (:token_id, :ip, :ua, :ref, :b_name, :b_ver, :os_name, :os_ver, :d_type, :d_brand, :d_model, :c_code, :c_name, :isp, NOW())
        ");
        
        $logParams = [
            ':token_id' => $pixelTokenId, ':ip' => $ipAddress, ':ua' => $userAgent, ':ref' => $referrer,
            ':b_name' => $uaDetails['client_name'], ':b_ver' => $uaDetails['client_version'],
            ':os_name' => $uaDetails['os_name'], ':os_ver' => $uaDetails['os_version'],
            ':d_type' => $uaDetails['device_type'], ':d_brand' => $uaDetails['device_brand'],
            ':d_model' => $uaDetails['device_model'], ':c_code' => $geoDetails['country_code'],
            ':c_name' => $geoDetails['city_name'], ':isp' => $geoDetails['isp']
        ];
        $logStmt->execute($logParams);
    }
    
    // --- 6. VÁLTOZTATÁS: Válasz küldése VAGY átirányítás ---
    
    // Ellenőrizzük, hogy a `redirect` paraméter értéke 'true' és az URL érvényes-e.
    if ($redirectParam === 'true' && filter_var($logUrl, FILTER_VALIDATE_URL)) {
        // Ha igen, elküldjük az átirányítási fejlécet, és leállítjuk a szkript futását.
        // A 302-es státuszkód ideiglenes átirányítást jelez.
        http_response_code(302);
        header('Location: ' . $logUrl);
        exit;
    } else {
        // Minden más esetben a normál JSON választ küldjük.
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(200); // OK
        echo json_encode([
            'status'        => 'success',
            'message'       => 'URL successfully tracked.',
            'tracked_data'  => [
                'user_token'    => $userApiToken,
                'pixel_token'   => $pixelTokenValue, // A tényleges pixel token, ami a naplózáshoz tartozik
                'logged_url'    => $logUrl
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

} catch (PDOException $e) {
    // Adatbázis hiba esetén naplózzuk a hibát, és egy általános hibaüzenetet küldünk vissza
    error_log("PhantomTrack API (PDO) hiba: " . $e->getMessage());
    header("Content-Type: application/json; charset=UTF-8");
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Szerveroldali hiba történt.']);
}
?>