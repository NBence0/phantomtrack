<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = "Regisztráció - PhantomTrack";
$errorMessage = '';
$successMessage = '';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'tracker/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $errorMessage = 'Érvénytelen kérés. Kérjük, próbálja újra.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validáció
        if (empty($username) || empty($email) || empty($password)) {
            $errorMessage = "Minden mező kitöltése kötelező.";
        } elseif ($password !== $confirmPassword) {
            $errorMessage = "A két jelszó nem egyezik.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = "Érvénytelen e-mail cím formátum.";
        } elseif (strlen($password) < 6) {
            $errorMessage = "A jelszónak legalább 6 karakter hosszúnak kell lennie.";
        } elseif (!isset($_POST['terms_agree']) || !isset($_POST['privacy_agree'])) { // <-- ÚJ ELLENŐRZÉS
            $errorMessage = "A regisztrációhoz el kell fogadnod a feltételeket és a nyilatkozatot.";
        } else {
            // Ellenőrizzük, hogy a felhasználónév vagy email foglalt-e már
            $db = getDB();
            $stmt = $db->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
            $stmt->execute([':username' => $username, ':email' => $email]);
            if ($stmt->fetch()) {
                $errorMessage = "A megadott felhasználónév vagy e-mail cím már foglalt.";
            } else {
                // Itt hívjuk meg az új, szerepkörrel bővített regisztrációs függvényt
                // Publikus regisztrációnál mindig `false` az `is_admin`
                $userId = registerUser($username, $email, $password, false);
                if ($userId) {
                    $successMessage = "Sikeres regisztráció! Most már bejelentkezhetsz.";
                    // Opcionális: Automatikus bejelentkeztetés
                    // attemptLogin($username, $password);
                    // header('Location: ' . BASE_URL . 'tracker/dashboard.php');
                    // exit;
                } else {
                    $errorMessage = "Hiba történt a regisztráció során. Kérjük, próbálja meg később.";
                }
            }
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
            <h1>Regisztráció</h1>
            <p class="tagline">Hozd létre saját PhantomTrack fiókodat</p>
            
            <?php if ($errorMessage): ?>
                <p class="message error-message"><?php echo escape($errorMessage); ?></p>
            <?php endif; ?>
            <?php if ($successMessage): ?>
                <p class="message success-message"><?php echo escape($successMessage); ?></p>
            <?php endif; ?>

            <?php if (!$successMessage): // Ha sikeres, a formot elrejtjük ?>
            <form method="POST" action="<?php echo BASE_URL; ?>tracker/register.php">
                <?php echo csrfInput(); ?>
                <div class="form-group">
                    <label for="username"><i class="fas fa-user"></i> Felhasználónév</label>
                    <input type="text" id="username" name="username" value="<?php echo escape($_POST['username'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> E-mail cím</label>
                    <input type="email" id="email" name="email" value="<?php echo escape($_POST['email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Jelszó</p>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password"><i class="fas fa-lock"></i> Jelszó megerősítése</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <!-- EZ AZ ÚJ BLOKK: -->
                <div class="form-group terms-group">
                    <div class="checkbox-container">
                        <input type="checkbox" id="terms_agree" name="terms_agree" value="1" required>
                        <label for="terms_agree">Elolvastam és elfogadom az <a href="<?php echo BASE_URL; ?>public/terms.php" target="_blank">Általános Szerződési Feltételeket</a>.</label>
                    </div>
                    <div class="checkbox-container">
                        <input type="checkbox" id="privacy_agree" name="privacy_agree" value="1" required>
                        <label for="privacy_agree">Elolvastam és elfogadom az <a href="<?php echo BASE_URL; ?>public/privacy.php" target="_blank">Adatkezelési Nyilatkozatot</a>.</label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Regisztráció</button>
            </form>
            <?php endif; ?>
             <p style="margin-top: 20px;">
                Van már fiókod? <a href="<?php echo BASE_URL; ?>tracker/login.php" style="color:var(--accent-primary);">Jelentkezz be!</a>
            </p>
        </div>
    </div>
</body>
</html>