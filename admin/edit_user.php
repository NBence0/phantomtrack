<?php
require_once __DIR__ . '/../includes/header.php';
requireAdmin(); // Szigorúan csak adminoknak!

$pageTitle = "Felhasználó Szerkesztése";
$db = getDB();
$userId = (int)($_GET['id'] ?? 0);

if ($userId <= 0) {
    $_SESSION['flash_message'] = "Érvénytelen felhasználói azonosító.";
    $_SESSION['flash_message_type'] = "error";
    header('Location: ' . BASE_URL . 'admin/users.php');
    exit;
}

// 1. Felhasználó adatainak lekérdezése
$stmt = $db->prepare("SELECT id, username, email, is_admin FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    $_SESSION['flash_message'] = "A felhasználó nem található.";
    $_SESSION['flash_message_type'] = "error";
    header('Location: ' . BASE_URL . 'admin/users.php');
    exit;
}

$pageTitle = "Szerkesztés: " . escape($user['username']); // Oldalcím frissítése

// 2. Mentés feldolgozása (POST kérés esetén)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Érvénytelen kérés.";
        $_SESSION['flash_message_type'] = "error";
    } else {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $newPassword = $_POST['password']; // Lehet üres
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
        
        $error = false;
        
        // Ellenőrzés: Az új felhasználónév nem lehet üres
        if (empty($username)) {
            $_SESSION['flash_message'] = "A felhasználónév nem lehet üres.";
            $_SESSION['flash_message_type'] = "error";
            $error = true;
        }

        // Ellenőrzés: Egyediség vizsgálata, CSAK ha a név változott
        if (!$error && $username !== $user['username']) {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = :username");
            $stmt->execute([':username' => $username]);
            if ($stmt->fetch()) {
                $_SESSION['flash_message'] = "A megadott felhasználónév ('" . escape($username) . "') már foglalt.";
                $_SESSION['flash_message_type'] = "error";
                $error = true;
            }
        }
        
        // Ellenőrzés: Email egyediség, CSAK ha az email változott
        if (!$error && $email !== $user['email']) {
             $stmt = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :current_id");
             $stmt->execute([':email' => $email, ':current_id' => $userId]);
             if ($stmt->fetch()) {
                 $_SESSION['flash_message'] = "A megadott e-mail cím már egy másik fiókhoz tartozik.";
                 $_SESSION['flash_message_type'] = "error";
                 $error = true;
             }
        }

        if (!$error) {
            $sql = "UPDATE users SET username = :username, email = :email, is_admin = :is_admin";
            $params = [
                ':username' => $username,
                ':email' => $email,
                ':is_admin' => $isAdmin,
                ':id' => $userId
            ];
            
            // Jelszó frissítése, csak ha meg van adva új
            if (!empty($newPassword)) {
                $sql .= ", password_hash = :password_hash";
                $params[':password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
            
            $sql .= " WHERE id = :id";
            
            try {
                $updateStmt = $db->prepare($sql);
                if ($updateStmt->execute($params)) {
                    $_SESSION['flash_message'] = "Felhasználó adatai sikeresen frissítve.";
                    $_SESSION['flash_message_type'] = "success";

                    // Session frissítése, ha a bejelentkezett felhasználó adatait módosítottuk
                    if ($userId == getCurrentUserId()) {
                        $_SESSION['username'] = $username;
                    }
                    
                    header('Location: ' . BASE_URL . 'admin/users.php');
                    exit;
                } else {
                     $_SESSION['flash_message'] = "Hiba történt a mentés során.";
                     $_SESSION['flash_message_type'] = "error";
                }
            } catch (PDOException $e) {
                 $_SESSION['flash_message'] = "Adatbázis hiba történt. Győződj meg róla, hogy a megadott adatok egyediek.";
                 $_SESSION['flash_message_type'] = "error";
            }
        }
        
        // Hiba esetén frissítsük az űrlaphoz használt adatokat a beküldöttekkel, hogy a felhasználó ne veszítse el, amit beírt
        $user['username'] = $username;
        $user['email'] = $email;
        $user['is_admin'] = $isAdmin;
    }
}

?>

<!-- 3. HTML Űrlap -->
<div class="content-header">
    <h1><i class="fas fa-user-edit"></i> <?php echo escape($pageTitle); ?></h1>
    <a href="<?php echo BASE_URL; ?>admin/users.php" class="btn btn-info"><i class="fas fa-arrow-left"></i> Vissza a Felhasználókhoz</a>
</div>

<div class="form-container glass-effect" style="max-width: 700px; margin: 20px auto; padding: var(--card-padding);">
    <form method="POST" action="<?php echo BASE_URL . 'admin/edit_user.php?id=' . $userId; ?>">
        <?php echo csrfInput(); ?>

        <div class="form-group">
            <label for="username">Felhasználónév:</label>
            <input type="text" id="username" name="username" value="<?php echo escape($user['username']); ?>" required>
        </div>
                
        <div class="form-group">
            <label for="email">E-mail cím:</label>
            <input type="email" id="email" name="email" value="<?php echo escape($user['email']); ?>" required>
        </div>

        <div class="form-group">
            <label for="password">Új jelszó:</label>
            <input type="password" id="password" name="password">
            <small>Hagyd üresen, ha nem szeretnéd megváltoztatni a jelszót.</small>
        </div>

        <div class="form-group">
            <input type="checkbox" id="is_admin" name="is_admin" value="1" <?php if ($user['is_admin']) echo 'checked'; ?>>
            <label for="is_admin" style="display:inline-block; font-weight:normal;">Adminisztrátori jogosultság</label>
        </div>
        
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Változtatások Mentése</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>