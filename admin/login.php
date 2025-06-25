<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = "Bejelentkezés - PhantomTrack";
$errorMessage = '';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'admin/dashboard.php');
    exit;
}
//registerUser('admin1', 'admin1@example.com', '1234'); // NE HASZNÁLD ÉLESBEN!
// Ideiglenes: Első felhasználó létrehozása, ha még nincs
$db = getDB();
$stmt = $db->query("SELECT COUNT(*) as count FROM users");
$userCount = $stmt->fetchColumn();
if ($userCount == 0) {
    // Itt érdemes lenne egy rendes telepítőt csinálni, de demohoz jó
    // Vagy parancssorból létrehozni az első usert.
    registerUser('admin1', 'admin1@example.com', '1234'); // NE HASZNÁLD ÉLESBEN!
    $errorMessage = "Nincs felhasználó a rendszerben. Hozz létre egyet manuálisan az adatbázisban, vagy futtass egy telepítő szkriptet.";
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $errorMessage = 'Érvénytelen kérés. Kérjük, próbálja újra.';
        // Itt nem irányítunk át, hogy a hibaüzenet megjelenhessen az űrlap alatt
    } else {
        // ... (meglévő login logika) ...
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        if (attemptLogin($username, $password)) {
            $redirectUrl = $_SESSION['redirect_url'] ?? BASE_URL . 'admin/dashboard.php';
            unset($_SESSION['redirect_url']);
            header('Location: ' . $redirectUrl);
        exit;
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
    <!-- Ide jöhet a FontAwesome CSS link, ha CDN-ről használod -->
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
                <form method="POST" action="<?php echo BASE_URL; ?>admin/login.php">
                <?php echo csrfInput(); ?>
                <div class="form-group">
                    <label for="username"><i class="fas fa-user"></i> Felhasználónév vagy Email</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Jelszó</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary">Bejelentkezés</button>
            </form>
            </form>
            <p style="margin-top: 20px;">
                Nincs még fiókod? <a href="<?php echo BASE_URL; ?>admin/register.php" style="color:var(--accent-primary);">Regisztrálj most!</a>
            </p>
        </div>
    </div>
</body>
</html>