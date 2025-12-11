<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php'; // Composer autoload

// Ha már be van jelentkezve, irány a dashboard
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'tracker/dashboard.php');
    exit;
}

// Google Client inicializálás
$client = new Google\Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URI);
$client->addScope("email");
$client->addScope("profile");

// 1. ESET: Visszatérés a Google-től (van 'code' paraméter)
if (isset($_GET['code'])) {
    // --- DIAGNOSZTIKA KEZDETE (Csak a hiba elhárításáig hagyd bent!) ---
    echo "<h3>Diagnosztika adatok:</h3>";
    echo "<b>Configban lévő Redirect URI:</b> " . GOOGLE_REDIRECT_URI . "<br>";
    echo "<b>Google-től kapott code:</b> " . substr($_GET['code'], 0, 10) . "...<br>";
    echo "<b>Client ID (eleje):</b> " . substr(GOOGLE_CLIENT_ID, 0, 10) . "...<br>";
    echo "<b>Client Secret hossza:</b> " . strlen(GOOGLE_CLIENT_SECRET) . " karakter (Ellenőrizd: Nem 0?)<br>";
    
    // Ellenőrizzük a Google Client beállításait is
    echo "<b>Google Client obj Redirect URI:</b> " . $client->getRedirectUri() . "<br>";
    
    // Ha ezt látod, a kód eddig lefutott. Ha itt megállunk, össze tudod vetni a Console-lal.
    // die("Ellenőrizd a fenti adatokat a Google Console-ban!"); 
    // --- DIAGNOSZTIKA VÉGE ---

    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        
        if (isset($token['error'])) {
            throw new Exception("Hiba a token lekérésekor: " . $token['error']);
        }

        $client->setAccessToken($token['access_token']);

        // Felhasználói adatok lekérése
        $google_oauth = new Google\Service\Oauth2($client);
        $google_account_info = $google_oauth->userinfo->get();

        $googleInfo = [
            'id' => $google_account_info->id,
            'email' => $google_account_info->email,
            'name' => $google_account_info->name,
            'picture' => $google_account_info->picture
        ];

        // Beléptetés vagy Regisztráció
        if (loginOrRegisterWithGoogle($googleInfo)) {
            // Siker!
            // Ellenőrizzük, volt-e mentett redirect URL
            $redirect = $_SESSION['redirect_url'] ?? BASE_URL . 'tracker/dashboard.php';
            unset($_SESSION['redirect_url']);
            
            header('Location: ' . $redirect);
            exit;
        } else {
            throw new Exception("Adatbázis hiba a bejelentkezés során.");
        }

    } catch (Exception $e) {
        // Hiba esetén visszaküldjük a login oldalra hibaüzenettel
        // Mivel session még nem biztos, hogy él, URL paraméterben küldhetnénk, 
        // de biztonságosabb, ha kiírjuk és linket adunk.
        error_log("Google Auth Error: " . $e->getMessage());
        die("RÉSZLETES HIBA: " . $e->getMessage()); 
    }
} 
// 2. ESET: Indítás (nincs code), átirányítás a Google-höz
else {
    $authUrl = $client->createAuthUrl();
    header('Location: ' . $authUrl);
    exit;
}
?>