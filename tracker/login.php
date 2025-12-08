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
            
            <p style="margin-top: 20px;">
                Nincs még fiókod? <a href="<?php echo BASE_URL; ?>tracker/register.php" style="color:var(--accent-primary);">Regisztrálj most!</a>
            </p>
        </div>
    </div>
</body>
</html>