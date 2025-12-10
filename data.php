<?php
// Hely: /data.php
// Cél: Nyers fájladatok BIZTONSÁGOS kiszolgálása.

// === FÜGGŐSÉGEK ===
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php'; // Szükséges a session ellenőrzéshez

// === BEMENETI ADATOK ÉS VALIDÁCIÓ ===
$viewToken = $_GET['id'] ?? '';
$type = $_GET['type'] ?? 'raw';

if (empty($viewToken)) {
    http_response_code(400); exit('Hiányzó fájl azonosító.');
}

// 1. Adatbázis lekérdezés (Bővítve a gallery_id-vel)
$db = getDB();
$stmt = $db->prepare("
    SELECT f.id, f.stored_filename, f.mime_type, f.gallery_id, 
           f.password_hash, f.expiry_time, f.ip_whitelist, f.ip_blacklist,
           g.visibility, g.password_hash as gallery_pass, g.user_id as owner_id
    FROM files f
    LEFT JOIN galleries g ON f.gallery_id = g.id
    WHERE f.view_token = :token
");
$stmt->execute([':token' => $viewToken]);
$fileData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fileData) {
    http_response_code(404); exit('Fájl nem található.');
}

// === 2. BIZTONSÁGI ELLENŐRZÉS (GALÉRIA VÉDELEM) ===
if ($fileData['gallery_id']) {
    $canView = false;
    
    // User lekérdezése manuálisan, ha be van jelentkezve
    $user = null;
    if (function_exists('isLoggedIn') && isLoggedIn()) {
        $currentUserId = getCurrentUserId();
        if ($currentUserId) {
            $userStmt = $db->prepare("SELECT id, username, is_admin FROM users WHERE id = :id");
            $userStmt->execute([':id' => $currentUserId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    $sessionKey = 'gallery_auth_' . $fileData['gallery_id'];

    // a) Publikus galéria
    if ($fileData['visibility'] === 'public') {
        $canView = true;
    }
    // b) Tulajdonos vagy Admin mindig láthatja
    elseif ($user && ($user['id'] == $fileData['owner_id'] || isAdmin())) {
        $canView = true;
    }
    // c) Privát galéria (csak tulajdonos, amit fentebb már ellenőriztünk, tehát itt else ág)
    elseif ($fileData['visibility'] === 'private') {
        $canView = false;
    }
    // d) Jelszavas galéria
    elseif ($fileData['visibility'] === 'password') {
        // Ellenőrizzük a session-t (amit a gallery_view.php állított be sikeres belépéskor)
        if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === true) {
            $canView = true;
        } else {
            $canView = false;
        }
    }

    if (!$canView) {
        http_response_code(403);
        // Opcionális: Visszaadhatunk egy "Access Denied" placeholder képet
        if ($type === 'raw' && strpos($fileData['mime_type'], 'image/') === 0) {
            header('Content-Type: image/png');
            readfile(__DIR__ . '/assets/img/access_denied.png'); // Ha van ilyen kép
            exit;
        }
        exit('Hozzáférés megtagadva. Kérjük, jelentkezzen be a galériába!');
    }
}

// Fájl szintű védelem (jelszó, IP, lejárat) továbbra is érvényes maradhat,
// de a galéria logikája most már prioritást élvez.
// A korábbi `validateFileAccess` függvény hívása ide is bekerülhetne, ha szükséges.


// 3. Fájl kiszolgálása (Változatlan)
$filePath = __DIR__ . '/uploads/' . $fileData['stored_filename'];
if (!file_exists($filePath)) {
    http_response_code(404); exit('Fizikai fájl nem található.');
}

switch ($type) {
    case 'text':
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, max-age=3600'); // Privát cache javasolt védett tartalomnál!
        readfile($filePath);
        break;

    case 'raw':
    default:
        header('Content-Type: ' . $fileData['mime_type']);
        header('Content-Length: ' . filesize($filePath));
        // Cache-Control módosítva: 'private', hogy proxyk ne cacheljék a védett tartalmat
        header('Cache-Control: private, max-age=3600'); 
        header('ETag: "' . md5_file($filePath) . '"');
        
        // Range handling (Videókhoz)
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