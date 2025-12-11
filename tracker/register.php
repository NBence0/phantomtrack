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
            <!--
            <a href="<?php echo BASE_URL; ?>login/facebook_auth.php" class="btn" style="
                display: flex;
                align-items: center;
                justify-content: center;
                background-color: #1877F2;
                color: #fff;
                border-radius: 4px;
                padding: 10px;
                text-decoration: none;
                font-weight: 500;
                margin-top: 10px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.2);
                transition: opacity .2s;
            ">
                <i class="fab fa-facebook-f" style="margin-right: 10px; font-size: 18px;"></i>
                Bejelentkezés Facebookkal
            </a>
            -->
            <a href="<?php echo BASE_URL; ?>login/github_auth.php" class="btn" style="
                display: flex;
                align-items: center;
                justify-content: center;
                background-color: #333; /* GitHub Fekete */
                color: #fff;
                border-radius: 4px;
                padding: 10px;
                text-decoration: none;
                font-weight: 500;
                margin-top: 10px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.2);
                transition: opacity .2s;
            ">
                <i class="fab fa-github" style="margin-right: 10px; font-size: 18px;"></i>
                Bejelentkezés GitHubbal
            </a>
            </div>
            <?php endif; ?>
             <p style="margin-top: 20px;">
                Van már fiókod? <a href="<?php echo BASE_URL; ?>tracker/login.php" style="color:var(--accent-primary);">Jelentkezz be!</a>
            </p>
        </div>
    </div>
</body>
</html>