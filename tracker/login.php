<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = "Bejelentkezés - PhantomTrack";
$errorMessage = '';

// Alapértelmezett visszatérési cím
$redirectUrl = BASE_URL . 'tracker/dashboard.php';

// 1. VISSZAIRÁNYÍTÁSI CÍM DETEKTÁLÁSA (Prioritási sorrendben)
if (isset($_POST['redirect_url']) && !empty($_POST['redirect_url'])) {
    // Ha már volt egy sikertelen próbálkozás, a rejtett mezőből vesszük
    $redirectUrl = $_POST['redirect_url'];
} elseif (isset($_GET['redirect']) && !empty($_GET['redirect'])) {
    // Ha most érkeztünk a linkről
    $redirectUrl = $_GET['redirect'];
}

// Biztonsági tisztítás (Ne lehessen külső domainre irányítani)
$redirectUrl = filter_var($redirectUrl, FILTER_SANITIZE_URL);
if (strpos($redirectUrl, 'http') === 0 && strpos($redirectUrl, BASE_URL) !== 0) {
    // Ha teljes URL és nem a mi oldalunkra mutat, reset
    $redirectUrl = BASE_URL . 'tracker/dashboard.php';
}

// Ha már be van jelentkezve, irányítsuk át azonnal
if (isLoggedIn()) {
    header('Location: ' . $redirectUrl);
    exit;
}

// 2. IDEIGLENES TELEPÍTŐ (Meglévő kód)
$db = getDB();
$stmt = $db->query("SELECT COUNT(*) as count FROM users");
$userCount = $stmt->fetchColumn();
if ($userCount == 0) {
    registerUser('admin1', 'admin1@example.com', '1234');
    $errorMessage = "Nincs felhasználó a rendszerben. Demo user létrehozva.";
}

// 3. LOGIN FELDOLGOZÁS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF ellenőrzés
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $errorMessage = 'Biztonsági hiba (CSRF). Kérjük, frissítsd az oldalt és próbáld újra.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $errorMessage = "Kérjük, töltsd ki mindkét mezőt.";
        } elseif (attemptLogin($username, $password)) {
            // SIKER! Átirányítás a céloldalra
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            $errorMessage = "Hibás felhasználónév vagy jelszó.";
        }
    }
}    
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($pageTitle); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="public-layout login-page">
    <div class="login-container">
        <div class="login-box glass-effect">
            <h1>Phantom<span class="accent-text">Track</span></h1>
            <p class="tagline">Fejlett Pixel Tracking Rendszer</p>
            
            <?php if ($errorMessage): ?>
                <p class="message error-message"><?php echo escape($errorMessage); ?></p>
            <?php endif; ?>
            
            <!-- ACTION üresen hagyva, hogy önmagának küldje (megtartva a query paramétereket is akár) -->
            <form method="POST" action="">
                <?php echo csrfInput(); ?>
                
                <!-- REJTETT MEZŐ: Itt tároljuk a visszatérési címet próbálkozások között -->
                <input type="hidden" name="redirect_url" value="<?php echo escape($redirectUrl); ?>">
                
                <div class="form-group">
                    <label for="username"><i class="fas fa-user"></i> Felhasználónév vagy Email</label>
                    <input type="text" id="username" name="username" value="<?php echo isset($_POST['username']) ? escape($_POST['username']) : ''; ?>" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Jelszó</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Bejelentkezés</button>
            </form>
            <div style="margin-top: 20px; text-align: center;">
                <div style="display: flex; align-items: center; justify-content: center; margin-bottom: 15px;">
                    <span style="height: 1px; background: rgba(255,255,255,0.2); flex: 1;"></span>
                    <span style="padding: 0 10px; color: rgba(255,255,255,0.5); font-size: 0.9em;">VAGY</span>
                    <span style="height: 1px; background: rgba(255,255,255,0.2); flex: 1;"></span>
                </div>

                <a href="<?php echo BASE_URL; ?>tracker/google_auth.php"
                class="btn btn-google"
                style="
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background-color: #fff;
                    color: #757575;
                    border-radius: 4px;
                    padding: 10px;
                    text-decoration: none;
                    font-weight: 500;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.2);
                    transition: background-color .2s;
                ">
                    <!-- Google ikon SVG (az első kódból) -->
                    <div style="width: 18px; height: 18px; margin-right: 10px;">
                        <svg version="1.1" xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 48 48" style="display: block; width: 18px; height: 18px;">
                            <path fill="#EA4335"
                                d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                            <path fill="#4285F4"
                                d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                            <path fill="#FBBC05"
                                d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                            <path fill="#34A853"
                                d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                            <path fill="none" d="M0 0h48v48H0z"/>
                        </svg>
                    </div>

                    Bejelentkezés Google fiókkal
                </a>
            </div>
            <p style="margin-top: 20px;">
                Nincs még fiókod? <a href="<?php echo BASE_URL; ?>tracker/register.php" style="color:var(--accent-primary);">Regisztrálj most!</a>
            </p>
        </div>
    </div>
</body>
</html>