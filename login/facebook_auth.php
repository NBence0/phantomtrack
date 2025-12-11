<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php'; // Fontos a createSlug miatt!
require_once __DIR__ . '/../vendor/autoload.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'tracker/dashboard.php');
    exit;
}

if (!session_id()) {
    session_start();
}

$fb = new \Facebook\Facebook([
  'app_id' => FB_APP_ID,
  'app_secret' => FB_APP_SECRET,
  'default_graph_version' => 'v12.0', // Vagy újabb
]);

$helper = $fb->getRedirectLoginHelper();

try {
    if (isset($_GET['code'])) {
        // Visszatérés a Facebookról
        $accessToken = $helper->getAccessToken();
        
        if (!isset($accessToken)) {
             if ($helper->getError()) {
                throw new Exception("Hiba: " . $helper->getError() . " - " . $helper->getErrorReason());
             }
             throw new Exception("Érvénytelen válasz a Facebooktól.");
        }

        // Hosszú élettartamú token kérése (opcionális, de ajánlott)
        $oAuth2Client = $fb->getOAuth2Client();
        if (!$accessToken->isLongLived()) {
            $accessToken = $oAuth2Client->getLongLivedAccessToken($accessToken);
        }

        // Adatok lekérése
        $response = $fb->get('/me?fields=id,name,email', $accessToken);
        $userNode = $response->getGraphUser();

        $fbInfo = [
            'id' => $userNode->getId(),
            'name' => $userNode->getName(),
            'email' => $userNode->getEmail()
        ];

        if (empty($fbInfo['email'])) {
            throw new Exception("Nem kaptunk e-mail címet a Facebooktól. Kérjük, engedélyezd az e-mail megosztást.");
        }

        if (loginOrRegisterWithFacebook($fbInfo)) {
            header('Location: ' . BASE_URL . 'tracker/dashboard.php');
            exit;
        } else {
             // Hibaüzenet már a sessionben van (auth.php)
             header('Location: ' . BASE_URL . 'tracker/login.php');
             exit;
        }
    } else {
        // Indítás
        $permissions = ['email']; // Kötelező engedély
        $loginUrl = $helper->getLoginUrl(FB_REDIRECT_URI, $permissions);
        header('Location: ' . $loginUrl);
        exit;
    }
} catch(Exception $e) {
    die('Facebook SDK Error: ' . $e->getMessage());
}
?>