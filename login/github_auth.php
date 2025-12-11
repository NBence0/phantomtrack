<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'tracker/dashboard.php');
    exit;
}

// 1. Indítás: Átirányítás a GitHubra
if (!isset($_GET['code'])) {
    // Generálunk egy random state-t a biztonság kedvéért (CSRF ellen)
    $_SESSION['github_state'] = bin2hex(random_bytes(16));
    
    $params = [
        'client_id' => GITHUB_CLIENT_ID,
        'redirect_uri' => GITHUB_REDIRECT_URI,
        'scope' => 'user:email', // Fontos: kérjük el az email címet
        'state' => $_SESSION['github_state']
    ];
    
    header('Location: https://github.com/login/oauth/authorize?' . http_build_query($params));
    exit;
}

// 2. Visszatérés: Code beváltása Tokenre
if (isset($_GET['code'])) {
    
    // State ellenőrzés
    if (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['github_state'])) {
        die("Biztonsági hiba: Érvénytelen állapot (State mismatch).");
    }

    $tokenUrl = "https://github.com/login/oauth/access_token";
    $postData = [
        'client_id' => GITHUB_CLIENT_ID,
        'client_secret' => GITHUB_CLIENT_SECRET,
        'code' => $_GET['code'],
        'redirect_uri' => GITHUB_REDIRECT_URI
    ];

    // cURL hívás a tokenért
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    
    if (empty($data['access_token'])) {
        die("Hiba a GitHub bejelentkezés során (Token hiba).");
    }
    
    $accessToken = $data['access_token'];

    // 3. Felhasználói adatok lekérése a Tokennel
    $ch = curl_init("https://api.github.com/user");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . $accessToken,
        'User-Agent: PhantomTrack' // GitHub kötelezővé teszi a User-Agentet
    ]);
    $userResponse = curl_exec($ch);
    curl_close($ch);
    
    $userData = json_decode($userResponse, true);

    // 4. Email cím lekérése (külön API hívás kell hozzá, ha privát)
    $email = $userData['email'];
    
    if (empty($email)) {
        $ch = curl_init("https://api.github.com/user/emails");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: token ' . $accessToken,
            'User-Agent: PhantomTrack'
        ]);
        $emailsResponse = curl_exec($ch);
        curl_close($ch);
        
        $emails = json_decode($emailsResponse, true);
        if (is_array($emails)) {
            foreach ($emails as $emailObj) {
                if ($emailObj['primary'] && $emailObj['verified']) {
                    $email = $emailObj['email'];
                    break;
                }
            }
        }
    }

    if (empty($email)) {
        die("Nem sikerült lekérni az e-mail címet a GitHub fiókból. Kérjük, ellenőrizd a GitHub beállításaidat.");
    }

    // Adatok összeállítása
    $ghInfo = [
        'id' => $userData['id'],
        'email' => $email,
        'login' => $userData['login'], // Felhasználónév
        'name' => $userData['name'] // Teljes név (lehet üres)
    ];

    // 5. Beléptetés / Regisztráció
    if (loginOrRegisterWithGithub($ghInfo)) {
        header('Location: ' . BASE_URL . 'tracker/dashboard.php');
        exit;
    } else {
        header('Location: ' . BASE_URL . 'tracker/login.php');
        exit;
    }
}
?>