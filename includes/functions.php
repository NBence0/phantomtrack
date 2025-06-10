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


use DeviceDetector\DeviceDetector; // Fontos a use statement!
use DeviceDetector\Parser\Device\AbstractDeviceParser; // Szintén fontos!

// ...

function getDetailedUserAgentInfo($userAgentString) {
    static $dd = null; // Statikus, hogy ne kelljen minden híváskor újra létrehozni
    if ($dd === null) {
        AbstractDeviceParser::setVersionTruncation(AbstractDeviceParser::VERSION_TRUNCATION_NONE);
        $dd = new DeviceDetector($userAgentString);
        // Éles környezetben érdemes lehet egy perzisztensebb cache-t használni, pl. Stash, Doctrine Cache, stb.
        // A Matomo\Cache\EAcceleratorCache, Matomo\Cache\ApcCache stb. helyett
        // a DeviceDetector saját cache interfészét vagy egy PSR-6/PSR-16 kompatibilis cache-t használj.
        // Példa a Matomo\Cache\ArrayCache-re (nem perzisztens, csak az adott kérésre él):
        // $dd->setCache(new \Matomo\Cache\ArrayCache());
        // Újabb DeviceDetector verziókhoz:
        // $cache = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
        // $dd->setCache($cache);
        // Ha nincs Symfony Cache, egyszerűen hagyd ki a setCache részt, vagy használj egy egyszerűbb implementációt,
        // de élesben a cache fontos a teljesítmény miatt. Kezdetnek a Matomo\Cache\ArrayCache is jó lehet,
        // ha a matomo/cache csomag is települ a device-detectorral (gyakran függőségként).
        // A "Matomo\ менееCache\ArrayCache()" furcsán néz ki, valószínűleg elírás és sima "Matomo\Cache\ArrayCache" lenne
        // Ellenőrizd a DeviceDetector dokumentációját a cache beállítására.
        // Ha a Matomo Cache nincs, és nem akarsz Symfony Cache-t, kezdetben hagyd ki a setCache hívást.
    } else {
        $dd->setUserAgent($userAgentString); // Újrahasznosítjuk az objektumot
    }

    $dd->parse();

    $clientInfo = $dd->getClient(); // Böngésző, feed reader, stb.
    $osInfo = $dd->getOs();
    // A DeviceDetector újabb verziói a getDeviceName() helyett getDevice() és abból a type-ot adják
    // vagy specifikusabb metódusokat (isSmartphone(), isTablet() etc.)
    $deviceType = $dd->getDeviceName(); // Eszköz típusa (desktop, smartphone, tablet, console, etc.)
    // Vagy $deviceType = $dd->getDevice(); // Ez egy számot adhat vissza, amit konstanssal kell összevetni
    // Pl. if ($dd->isSmartphone()) $deviceType = 'smartphone';

    $brandInfo = $dd->getBrandName();
    $modelInfo = $dd->getModel();

    return [
        'client_type' => $clientInfo['type'] ?? 'N/A',
        'client_name' => $clientInfo['name'] ?? 'N/A',
        'client_version' => $clientInfo['version'] ?? 'N/A',
        'os_name' => $osInfo['name'] ?? 'N/A',
        'os_version' => $osInfo['version'] ?? 'N/A',
        'os_platform' => $osInfo['platform'] ?? 'N/A',
        'device_type' => $deviceType ?? 'N/A',
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


use GeoIp2\Database\Reader; // Fontos a use statement!
use GeoIp2\Exception\AddressNotFoundException; // Szintén fontos!

// ...

function getGeolocationFromIp($ipAddress) {
    $enableGeolocation = (bool)getAppSetting('ip_geolocation_enabled', '0');
    // A GeoLite2-City.mmdb fájlt a projekt `data` mappájába kell helyezni.
    // Ezt le kell tölteni a MaxMind weboldaláról (regisztráció után ingyenesen elérhető).
    $geoLiteDbPath = __DIR__ . '/../data/GeoLite2-City.mmdb';

    $defaultGeoData = [
        'country_code' => null,
        'city_name' => null,
        'isp' => null,
    ];

    if (!$enableGeolocation || !file_exists($geoLiteDbPath) || !is_readable($geoLiteDbPath)) {
        if ($enableGeolocation && (!file_exists($geoLiteDbPath) || !is_readable($geoLiteDbPath))) {
            error_log("GeoLite2 database not found or not readable at: " . $geoLiteDbPath . " - Geolocation will be skipped.");
        }
        return $defaultGeoData;
    }

    if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
        return $defaultGeoData;
    }
    if ($ipAddress === '::1' || $ipAddress === '127.0.0.1') {
        return $defaultGeoData;
    }

    try {
        static $reader = null;
        if ($reader === null) {
            $reader = new Reader($geoLiteDbPath);
        }

        $record = $reader->city($ipAddress);

        return [
            'country_code' => $record->country->isoCode ?? null,
            'city_name' => $record->city->name ?? null,
            'isp' => $record->traits->isp ?? ($record->traits->organization ?? null),
        ];

    } catch (AddressNotFoundException $e) {
        error_log("IP address not found in GeoLite2 DB: " . $ipAddress . " - " . $e->getMessage());
        return $defaultGeoData;
    } catch (\Exception $e) { // Általános Exception minden egyéb GeoIp2 hibára
        error_log("GeoIP2 error: " . $e->getMessage() . " for IP: " . $ipAddress);
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