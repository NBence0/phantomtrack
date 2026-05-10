<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

header("Access-Control-Allow-Origin: *");

$userApiToken = $_GET['api_token'] ?? null;
$logUrl = $_GET['url'] ?? null;
$redirectParam = $_GET['redirect'] ?? 'false';

// 1. Gyors ellenőrzés: API token és URL megléte
if (!$userApiToken || !$logUrl) {
    header("Content-Type: application/json; charset=UTF-8");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Hiányzó paraméterek.']);
    exit;
}

try {
    $db = getDB();
    
    // Felhasználó azonosítása (gyors lekérdezés)
    $userStmt = $db->prepare("SELECT id, allow_api_token_creation FROM users WHERE api_token = :api_token");
    $userStmt->execute([':api_token' => $userApiToken]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Érvénytelen API token.']);
        exit;
    }

    // --- VÁLASZ ELKÜLDÉSE A FELHASZNÁLÓNAK ---

    if ($redirectParam === 'true' && filter_var($logUrl, FILTER_VALIDATE_URL)) {
        http_response_code(302);
        header('Location: ' . $logUrl);
    } else {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Request received and being processed.'
        ]);
    }

    // Itt "vágjuk el" a vonalat: a szerver elküldi az adatokat, a böngésző bezárja a kapcsolatot
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request(); 
    } else {
        // Ha nem FastCGI-t használsz (ritka), trükközni kell a pufferekkel:
        ignore_user_abort(true);
        ob_start();
        // (itt már kiírtuk amit kell)
        header("Content-Length: " . ob_get_length());
        header("Connection: close");
        ob_end_flush();
        flush();
    }

    // --- INNENTŐL A FELHASZNÁLÓ MÁR SEMMIT NEM LÁT, DE A SCRIPT FUT ---
    // Ide jönnek a lassú folyamatok:

    $currentUserId = $user['id'];
    $allowCreation = (bool)$user['allow_api_token_creation'];

    // 1. Token kezelés
    $tokenExistsStmt = $db->prepare("SELECT id, token_value FROM tokens WHERE user_id = :user_id AND name = :name AND is_active = 1");
    $tokenExistsStmt->execute([':user_id' => $currentUserId, ':name' => $logUrl]);
    $existingToken = $tokenExistsStmt->fetch(PDO::FETCH_ASSOC);

    $pixelTokenId = null;
    if ($existingToken) {
        $pixelTokenId = $existingToken['id'];
    } elseif ($allowCreation) {
        $newPixelTokenValue = generateUniqueToken();
        $createStmt = $db->prepare("INSERT INTO tokens (user_id, token_value, name, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
        if ($createStmt->execute([$currentUserId, $newPixelTokenValue, $logUrl])) {
            $pixelTokenId = $db->lastInsertId();
        }
    }

    // 2. Lassú külső lekérdezések (GeoIP, UserAgent elemzés)
    if ($pixelTokenId) {
        $ipAddress = getIpAddress();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';
        
        $uaDetails = getDetailedUserAgentInfo($userAgent); // Ez lehet lassú
        $geoDetails = getGeolocationFromIp($ipAddress);    // Ez a leglassabb (20+ mp)

        $logStmt = $db->prepare("
            INSERT INTO activity_logs 
            (token_id, ip_address, user_agent, referrer, browser_name, browser_version, os_name, os_version, device_type, country_code, city_name, isp, timestamp) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $logStmt->execute([
            $pixelTokenId, $ipAddress, $userAgent, $logUrl,
            $uaDetails['client_name'], $uaDetails['client_version'],
            $uaDetails['os_name'], $uaDetails['os_version'],
            $uaDetails['device_type'], $geoDetails['country_code'],
            $geoDetails['city_name'], $geoDetails['isp']
        ]);
    }

} catch (Exception $e) {
    error_log("Hiba: " . $e->getMessage());
}