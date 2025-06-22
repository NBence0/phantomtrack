<?php
// === 1. LÉPÉS: MINDEN PHP LOGIKA ÉS FELDOLGOZÁS (HTML ELŐTT) ===

// Alapvető fájlok és beállítások
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin(); // Csak bejelentkezett felhasználók

$db = getDB();
$currentUserId = getCurrentUserId();

// --- ALKALMAZÁS BEÁLLÍTÁSOK MENTÉSE (ADMIN) ---
// Ez a blokk feldolgozza a POST kérést, és ha sikeres, átirányít és leállítja a szkript futását.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_app_settings']) && isAdmin()) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Érvénytelen kérés.";
        $_SESSION['flash_message_type'] = "error";
    } else {
        $updated = 0;
        if (setAppSetting('ip_geolocation_enabled', isset($_POST['ip_geolocation_enabled']) ? '1' : '0')) $updated++;
        
        $itemsPerPage = (int)($_POST['items_per_page'] ?? DEFAULT_ITEMS_PER_PAGE);
        if ($itemsPerPage < 5) $itemsPerPage = 5;
        if ($itemsPerPage > 100) $itemsPerPage = 100;
        if (setAppSetting('items_per_page', $itemsPerPage)) $updated++;

        if ($updated > 0) {
            $_SESSION['flash_message'] = "Alkalmazás beállítások sikeresen mentve.";
            $_SESSION['flash_message_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Nem történt változás vagy hiba a mentéskor.";
            $_SESSION['flash_message_type'] = "warning";
        }
    }
    // Az átirányítás a logika végén történik, mielőtt bármi HTML kimenet lenne
    header('Location: ' . BASE_URL . 'admin/settings.php');
    exit;
}

// --- FELHASZNÁLÓI BEÁLLÍTÁSOK MENTÉSE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user_settings'])) {
    // A CSRF ellenőrzést ide is betehetjük, ha akarjuk
    // if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) ...

    $message = '';
    $message_type = 'info';

    // Email módosítás
    if (isset($_POST['email'])) {
        $newEmail = $_POST['email'];
        $userCheckStmt = $db->prepare("SELECT email FROM users WHERE id = :user_id");
        $userCheckStmt->execute([':user_id' => $currentUserId]);
        $currentEmailForCheck = $userCheckStmt->fetchColumn();

        if ($newEmail !== $currentEmailForCheck) {
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                 $message .= "Érvénytelen email formátum.<br>";
                 $message_type = 'error';
            } else {
                $updateEmailStmt = $db->prepare("UPDATE users SET email = :email WHERE id = :user_id");
                if ($updateEmailStmt->execute([':email' => $newEmail, ':user_id' => $currentUserId])) {
                    $message .= "Email cím sikeresen frissítve.<br>";
                    $message_type = 'success';
                } else {
                    $message .= "Hiba az email cím frissítésekor (lehet, hogy már foglalt).<br>";
                    $message_type = 'error';
                }
            }
        }
    }

    // Jelszócsere
    if (!empty($_POST['current_password']) || !empty($_POST['new_password']) || !empty($_POST['confirm_new_password'])) {
        if (!empty($_POST['current_password']) && !empty($_POST['new_password']) && !empty($_POST['confirm_new_password'])) {
            if ($_POST['new_password'] === $_POST['confirm_new_password']) {
                $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :user_id");
                $stmt->execute([':user_id' => $currentUserId]);
                $userData = $stmt->fetch();

                if ($userData && password_verify($_POST['current_password'], $userData['password_hash'])) {
                    $newPasswordHash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                    $updatePassStmt = $db->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :user_id");
                    if ($updatePassStmt->execute([':password_hash' => $newPasswordHash, ':user_id' => $currentUserId])) {
                        $message .= "Jelszó sikeresen megváltoztatva.<br>";
                        if(empty($message_type) || $message_type != 'error') $message_type = 'success';
                    } else {
                        $message .= "Hiba a jelszó megváltoztatása közben.<br>";
                        $message_type = 'error';
                    }
                } else {
                    $message .= "A jelenlegi jelszó helytelen.<br>";
                    $message_type = 'error';
                }
            } else {
                $message .= "Az új jelszavak nem egyeznek.<br>";
                $message_type = 'error';
            }
        } else {
            $message .= "Jelszóváltoztatáshoz minden jelszómező kitöltése szükséges.<br>";
            $message_type = 'warning';
        }
    }
    
    if(!empty($message)){
        $_SESSION['flash_message'] = rtrim($message, "<br>");
        $_SESSION['flash_message_type'] = $message_type;
        // Az átirányítás itt is a logika végén van
        header('Location: ' . BASE_URL . 'admin/settings.php');
        exit;
    }
}


// === 2. LÉPÉS: ADATOK LEKÉRDEZÉSE A MEGJELENÍTÉSHEZ ===
// Miután minden feldolgozás megtörtént, lekérjük az adatokat a formokhoz.

$pageTitle = "Beállítások";

// Felhasználói adatok betöltése
$userStmt = $db->prepare("SELECT email FROM users WHERE id = :user_id");
$userStmt->execute([':user_id' => $currentUserId]);
$user = $userStmt->fetch();
$currentEmail = $user['email'] ?? '';

// Alkalmazás beállítások betöltése
$appSettings = [
    'ip_geolocation_enabled' => getAppSetting('ip_geolocation_enabled', '0'),
    'items_per_page' => getAppSetting('items_per_page', DEFAULT_ITEMS_PER_PAGE),
];


// === 3. LÉPÉS: HTML MEGJELENÍTÉS KEZDETE ===
// Csak most hívjuk be a header-t, miután minden lehetséges átirányítás megtörtént.
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-cog"></i> <?php echo escape($pageTitle); ?></h1>
</div>

<div class="settings-sections">
    <!-- Felhasználói beállítások űrlap -->
    <section class="settings-section glass-effect">
        <h2><i class="fas fa-user-edit"></i> Felhasználói Beállítások</h2>
        <form method="POST" action="">
            <?php echo csrfInput(); // CSRF token az űrlaphoz ?>
            <input type="hidden" name="save_user_settings" value="1">
            <div class="form-group">
                <label for="email">Email cím:</label>
                <input type="email" id="email" name="email" value="<?php echo escape($currentEmail); ?>" required>
            </div>
            <hr><br>
            <h3>Jelszócsere</h3>
            <div class="form-group">
                <label for="current_password">Jelenlegi jelszó:</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="new_password">Új jelszó:</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="confirm_new_password">Új jelszó megerősítése:</label>
                <input type="password" id="confirm_new_password" name="confirm_new_password" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary">Felhasználói Beállítások Mentése</button>
        </form>
    </section>

    <!-- Alkalmazás beállítások űrlap (csak adminoknak) -->
    <?php if (isAdmin()): ?>
    <section class="settings-section glass-effect">
        <h2><i class="fas fa-cogs"></i> Alkalmazás Beállítások (Admin)</h2>
        <form method="POST" action="">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="save_app_settings" value="1">
            
            <div class="form-group">
                <input type="checkbox" id="ip_geolocation_enabled" name="ip_geolocation_enabled" value="1" <?php if ($appSettings['ip_geolocation_enabled'] == '1') echo 'checked'; ?>>
                <label for="ip_geolocation_enabled" style="display:inline-block; font-weight:normal;">IP Geolokáció Engedélyezése</label>
                <small style="display:block; color:var(--text-secondary);">Szükséges a `GeoLite2-City.mmdb` fájl és a `geoip2/geoip2` csomag.</small>
            </div>

            <div class="form-group">
                <label for="items_per_page">Elemek oldalanként (lapozásnál):</label>
                <input type="number" id="items_per_page" name="items_per_page" value="<?php echo escape($appSettings['items_per_page']); ?>" min="5" max="100">
            </div>
            
            <button type="submit" class="btn btn-primary">Alkalmazás Beállítások Mentése</button>
        </form>
    </section>
    <?php endif; ?>
</div>

<?php 
// === 4. LÉPÉS: FOOTER BEHÍVÁSA ===
require_once __DIR__ . '/../includes/footer.php'; 
?>