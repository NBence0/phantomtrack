<?php
require_once __DIR__ . '/../config.php';

function generateUniqueToken() {
    return bin2hex(random_bytes(16)); // 32 karakteres hexadecimális token
}

function formatTimestamp($timestamp, $format = 'Y-m-d H:i:s') {
    if (is_numeric($timestamp)) { // Ha Unix timestamp
        return date($format, $timestamp);
    }
    $date = new DateTime($timestamp);
    return $date->format($format);
}

function escape($html) {
    return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Egyszerű User-Agent elemzés (nagyon alap)
function parseUserAgent($userAgentString) {
    // Ez egy nagyon kezdetleges elemző.
    // Éles rendszerben a Matomo Device Detector vagy ua-parser/uap-php lenne ajánlott.
    $browser = "Ismeretlen Böngésző";
    $os = "Ismeretlen OS";
    $deviceType = "Ismeretlen Eszköz";

    // Böngésző detektálás (nagyon egyszerűsített)
    if (preg_match('/MSIE|Trident/i', $userAgentString)) $browser = 'Internet Explorer';
    elseif (preg_match('/Firefox/i', $userAgentString)) $browser = 'Firefox';
    elseif (preg_match('/Chrome/i', $userAgentString)) $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $userAgentString) && !preg_match('/Chrome/i', $userAgentString)) $browser = 'Safari';
    elseif (preg_match('/Opera|OPR/i', $userAgentString)) $browser = 'Opera';
    elseif (preg_match('/Edge/i', $userAgentString)) $browser = 'Edge';

    // OS detektálás (nagyon egyszerűsített)
    if (preg_match('/windows nt 10/i', $userAgentString)) $os = 'Windows 10/11';
    elseif (preg_match('/windows nt 6.3/i', $userAgentString)) $os = 'Windows 8.1';
    elseif (preg_match('/windows nt 6.2/i', $userAgentString)) $os = 'Windows 8';
    elseif (preg_match('/windows nt 6.1/i', $userAgentString)) $os = 'Windows 7';
    elseif (preg_match('/windows phone/i', $userAgentString)) $os = 'Windows Phone';
    elseif (preg_match('/android/i', $userAgentString)) $os = 'Android';
    elseif (preg_match('/iphone|ipad|ipod/i', $userAgentString)) $os = 'iOS';
    elseif (preg_match('/mac os x/i', $userAgentString)) $os = 'Mac OS X';
    elseif (preg_match('/linux/i', $userAgentString)) $os = 'Linux';

    // Eszköz típus (nagyon egyszerűsített)
    if (preg_match('/mobile|android|iphone|ipad|ipod|windows phone/i', $userAgentString)) {
        $deviceType = 'Mobil/Tablet';
    } elseif (preg_match('/bot|crawler|spider/i', $userAgentString)) {
        $deviceType = 'Bot';
    } else {
        $deviceType = 'Desktop';
    }
    
    return [
        'browser_name' => $browser,
        'browser_version' => 'N/A', // Ezt egy jobb parser adná meg
        'os_name' => $os,
        'os_version' => 'N/A',    // Ezt egy jobb parser adná meg
        'device_type' => $deviceType,
        'device_brand' => 'N/A',
        'device_model' => 'N/A'
    ];
}

function getIpAddress() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

function generateCsrfToken() {
    // Biztosítjuk, hogy a session el van indítva
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token_to_check) {
    // Biztosítjuk, hogy a session el van indítva
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token_to_check)) {
        // Az egyszer használatos tokenhez törölhetnénk itt:
        // unset($_SESSION['csrf_token']);
        // De általánosabb, ha a session végéig, vagy új generálásig érvényes.
        return true;
    }
    return false;
}

function csrfInput() {
    return '<input type="hidden" name="csrf_token" value="' . escape(generateCsrfToken()) . '">';
}


// Az egyszerű parseUserAgent funkciót lecseréljük/kiegészítjük.
// Ez a függvény most a Matomo Device Detector-t fogja használni.
function getDetailedUserAgentInfo($userAgentString) {
    static $dd = null; // Statikus, hogy ne kelljen minden híváskor újra létrehozni
    if ($dd === null) {
        AbstractDeviceParser::setVersionTruncation(AbstractDeviceParser::VERSION_TRUNCATION_NONE);
        $dd = new DeviceDetector($userAgentString);
        $dd->setCache(new \Matomo\ менееCache\ArrayCache()); // Vagy egy perzisztens cache éles környezetben
    } else {
        $dd->setUserAgent($userAgentString); // Újrahasznosítjuk az objektumot
    }

    $dd->parse();

    $clientInfo = $dd->getClient(); // Böngésző, feed reader, stb.
    $osInfo = $dd->getOs();
    $deviceInfo = $dd->getDeviceName(); // Eszköz típusa (desktop, smartphone, tablet, console, etc.)
    $brandInfo = $dd->getBrandName();
    $modelInfo = $dd->getModel();

    return [
        'client_type' => $clientInfo['type'] ?? 'N/A',
        'client_name' => $clientInfo['name'] ?? 'N/A',
        'client_version' => $clientInfo['version'] ?? 'N/A',
        'os_name' => $osInfo['name'] ?? 'N/A',
        'os_version' => $osInfo['version'] ?? 'N/A',
        'os_platform' => $osInfo['platform'] ?? 'N/A',
        'device_type' => $deviceInfo ?? 'N/A', // Ez a DeviceDetector-ban a 'desktop', 'smartphone', stb.
        'device_brand' => $brandInfo ?? 'N/A',
        'device_model' => $modelInfo ?? 'N/A',
        'is_bot' => $dd->isBot(),
        'bot_info' => $dd->isBot() ? $dd->getBot() : null,
    ];
}

// 9. pont: Rövidített, de informatív User-Agent formázás
// Az $logRow az activity_logs tábla egy sora, ami már tartalmazza a DeviceDetector által kinyert adatokat.
function formatUserAgentDetailed($logRow) {
    if (empty($logRow['client_name']) || $logRow['client_name'] === 'N/A') {
        // Ha nincs adat a DB-ben, megpróbáljuk újra elemezni (backup)
        if (!empty($logRow['user_agent'])) {
            $uaData = getDetailedUserAgentInfo($logRow['user_agent']);
        } else {
            return 'Ismeretlen User-Agent';
        }
    } else {
        // Használjuk a DB-ben tárolt, már elemzett adatokat
        $uaData = [
            'client_name' => $logRow['browser_name'], // Figyelj a DB oszlopnevekre!
            'client_version' => $logRow['browser_version'],
            'os_name' => $logRow['os_name'],
            'os_version' => $logRow['os_version'],
            // 'device_type' => $logRow['device_type'], // Ezt külön jelenítjük meg
        ];
    }

    $output = [];
    if (!empty($uaData['client_name']) && $uaData['client_name'] !== 'N/A') {
        $output[] = $uaData['client_name'] . (!empty($uaData['client_version']) && $uaData['client_version'] !== 'N/A' ? ' ' . $uaData['client_version'] : '');
    }
    if (!empty($uaData['os_name']) && $uaData['os_name'] !== 'N/A') {
        $output[] = 'on ' . $uaData['os_name'] . (!empty($uaData['os_version']) && $uaData['os_version'] !== 'N/A' ? ' ' . $uaData['os_version'] : '');
    }
    
    if (empty($output)) {
        return 'Ismeretlen (elemzési hiba)';
    }

    return implode(' ', $output);
}


function getGeolocationFromIp($ipAddress) {
    // Beállítások: GeoLite2 adatbázis elérési útja és geolokáció engedélyezése
    // Ezeket később az app_settings-ből kellene olvasni
    // functions.php - getGeolocationFromIp elején
    $enableGeolocation = (bool)getAppSetting('ip_geolocation_enabled', '0');
    $geoLiteDbPath = __DIR__ . '/../data/GeoLite2-City.mmdb'; // Módosítsd az elérési utat!

    $defaultGeoData = [
        'country_code' => null,
        'city_name' => null,
        'isp' => null,
    ];

    if (!$enableGeolocation || !file_exists($geoLiteDbPath) || !is_readable($geoLiteDbPath)) {
        if ($enableGeolocation && (!file_exists($geoLiteDbPath) || !is_readable($geoLiteDbPath))) {
            error_log("GeoLite2 database not found or not readable at: " . $geoLiteDbPath);
        }
        return $defaultGeoData;
    }

    // Validáljuk az IP címet, mielőtt átadjuk a Reader-nek
    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        return $defaultGeoData;
    }
    // A ::1 (IPv6 localhost) és 127.0.0.1 (IPv4 localhost) nem geolokálható
    if ($ipAddress === '::1' || $ipAddress === '127.0.0.1') {
        return $defaultGeoData;
    }


    try {
        static $reader = null;
        if ($reader === null) {
            $reader = new Reader($geoLiteDbPath);
        }
        
        $record = $reader->city($ipAddress); // Vagy $reader->country($ipAddress) ha csak ország kell

        return [
            'country_code' => $record->country->isoCode ?? null,
            'city_name' => $record->city->name ?? null,
            // Az ISP információt a GeoLite2 adatbázisok általában nem tartalmazzák olyan részletesen,
            // mint a dedikált ISP adatbázisok vagy egyes API-k.
            // A $record->traits->isp és $record->traits->organization adhat némi támpontot.
            'isp' => $record->traits->isp ?? ($record->traits->organization ?? null),
        ];

    } catch (\GeoIp2\Exception\AddressNotFoundException $e) {
        // IP nem található az adatbázisban
        error_log("IP address not found in GeoLite2 DB: " . $ipAddress . " - " . $e->getMessage());
        return $defaultGeoData;
    } catch (\Exception $e) {
        // Egyéb hiba
        error_log("GeoIP2 error: " . $e->getMessage());
        return $defaultGeoData;
    }
}
function getAppSetting($key, $default = null) {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :key");
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    return ($value !== false) ? $value : $default;
}

function setAppSetting($key, $value) {
    $db = getDB();
    // UPSERT logika: ha létezik, frissít, ha nem, beszúr
    $stmt = $db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
                          ON DUPLICATE KEY UPDATE setting_value = :value");
    return $stmt->execute([':key' => $key, ':value' => $value]);
}
?>