<?php
/**
 * PhantomTrack - Automatikus Token Létrehozó és Átirányító API
 *
 * Ez a végpont a következőképpen működik:
 * 1. Fogadja a `api_token` és `url` paramétereket.
 * 2. Érvényesíti a bemenetet. Ha a cél `url` érvénytelen, hibát ad. Ha az `api_token` érvénytelen,
 *    csendben, hiba nélkül átirányítja a felhasználót a cél `url`-re.
 * 3. Megkeresi a felhasználóhoz és a cél `url`-hez tartozó meglévő tokent.
 * 4. Ha nincs ilyen token, és a felhasználó engedélyezte az automatikus létrehozást,
 *    létrehoz egy új tokent az adatbázisban.
 * 5. Ha van érvényes token (meglévő vagy frissen létrehozott), a szerver a háttérben
 *    meghívja a `pixel.php` végpontot cURL segítségével, továbbítva a felhasználó
 *    valódi adatait (IP, User-Agent). Ez a hívás rögzíti a megnyitást.
 * 6. Végül, a szkript minden esetben (a cél URL hibáján kívül) egy 302-es HTTP
 *    átirányítással a felhasználót a megadott `url`-re küldi.
 *
 * Ez a megközelítés biztosítja, hogy a felhasználói élmény zökkenőmentes legyen,
 * és a statisztika rögzítése a háttérben, a felhasználó számára láthatatlanul történjen.
 */

// --- 1. Alapvető beállítások és függőségek ---
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// --- 2. Bemeneti adatok és hibakezelés ---
$apiToken = $_GET['api_token'] ?? null;
$targetUrl = $_GET['url'] ?? null;

// Ha a cél URL hiányzik vagy érvénytelen, nem tudunk hova irányítani, ez az egyetlen végzetes hiba.
if (!$targetUrl || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400); // Bad Request
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Érvénytelen vagy hiányzó cél URL.']);
    exit;
}

// Ha az API token hiányzik, ne végezzünk műveletet, csak irányítsuk át a felhasználót.
if (!$apiToken) {
    header('Location: ' . $targetUrl, true, 302);
    exit;
}

// --- 3. Felhasználó azonosítása ---
$db = getDB();
$userStmt = $db->prepare("SELECT id, allow_api_token_creation FROM users WHERE api_token = :api_token");
$userStmt->execute([':api_token' => $apiToken]);
$user = $userStmt->fetch();

// Ha a felhasználó az API token alapján nem található, csendben átirányítunk.
if (!$user) {
    header('Location: ' . $targetUrl, true, 302);
    exit;
}

$currentUserId = $user['id'];
$allowCreation = (bool)$user['allow_api_token_creation'];

// --- 4. Token keresése vagy létrehozása ---
$tokenValue = null;

try {
    // Meglévő token keresése
    $tokenExistsStmt = $db->prepare("SELECT token_value FROM tokens WHERE user_id = :user_id AND name = :name");
    $tokenExistsStmt->execute([':user_id' => $currentUserId, ':name' => $targetUrl]);
    $existingTokenValue = $tokenExistsStmt->fetchColumn();

    if ($existingTokenValue) {
        $tokenValue = $existingTokenValue;
    } elseif ($allowCreation) {
        // Új token létrehozása, ha engedélyezve van
        $newTokenValue = generateUniqueToken();
        $createStmt = $db->prepare(
            "INSERT INTO tokens (user_id, token_value, name, description, is_active, created_at) 
             VALUES (:user_id, :token_value, :name, :description, 1, NOW())"
        );
        
        $params = [
            ':user_id' => $currentUserId,
            ':token_value' => $newTokenValue,
            ':name' => $targetUrl,
            ':description' => 'Automatikusan létrehozva API-n keresztül: ' . date('Y-m-d H:i:s')
        ];
        
        if ($createStmt->execute($params)) {
            $tokenValue = $newTokenValue;
        }
    }
} catch (PDOException $e) {
    // Adatbázis hiba esetén is naplózzuk, de a felhasználót átirányítjuk,
    // így a működés számára folytonos marad.
    error_log("PhantomTrack API DB error: " . $e->getMessage());
}

// --- 5. Szerveroldali Pixel Hívás cURL-lal ---
if ($tokenValue) {
    $pixelUrl = BASE_URL . 'pixel.php?token=' . $tokenValue;
    
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $pixelUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);    // Választ stringként adja vissza, ne írja ki.
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);   // Ne kövesse az átirányításokat.
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);              // Max 3 másodperc futási idő.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);       // Max 2 másodperc kapcsolódási idő.
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);             // Kritikus a timeout megbízható működéséhez.

    // Fontos: Átadjuk a felhasználó eredeti adatait a `pixel.php`-nek.
    $headers = [
        'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'API Pixel Call'),
        'Referer: ' . ($_SERVER['HTTP_REFERER'] ?? ''), // Az eredeti referer
        'X-Forwarded-For: ' . getIpAddress()             // A felhasználó valódi IP címe
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // Elindítjuk a hívást, de nem foglalkozunk a válasszal vagy a hibákkal,
    // a cél az, hogy a kérés elinduljon.
    curl_exec($ch);
    curl_close($ch);
}

// --- 6. Végső Átirányítás a Cél URL-re ---
header('Location: ' . $targetUrl, true, 302);
exit;