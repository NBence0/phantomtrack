<?php
require_once __DIR__ . '/../includes/header.php';
requireLogin();
$pageTitle = "Beállítások";
$db = getDB();
$currentUserId = getCurrentUserId();

$userStmt = $db->prepare("SELECT email FROM users WHERE id = :user_id");
$userStmt->bindParam(':user_id', $currentUserId);
$userStmt->execute();
$user = $userStmt->fetch();
$currentEmail = $user['email'] ?? '';

$message = '';
$message_type = '';


// Alkalmazás beállítások betöltése
$appSettings = [
    'ip_geolocation_enabled' => getAppSetting('ip_geolocation_enabled', '0'), // Alapból kikapcsolva
    'items_per_page' => getAppSetting('items_per_page', DEFAULT_ITEMS_PER_PAGE),
    // További beállítások itt...
];
// Alapértelmezett értékek, ha még nincsenek beállítva
// Alkalmazás beállítások mentése (csak adminnak)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_app_settings']) && isAdmin()) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Érvénytelen kérés."; $_SESSION['flash_message_type'] = "error";
    } else {
        $updated = 0;
        if (setAppSetting('ip_geolocation_enabled', isset($_POST['ip_geolocation_enabled']) ? '1' : '0')) $updated++;
        
        $itemsPerPage = (int)($_POST['items_per_page'] ?? DEFAULT_ITEMS_PER_PAGE);
        if ($itemsPerPage < 5) $itemsPerPage = 5; // Minimum érték
        if ($itemsPerPage > 100) $itemsPerPage = 100; // Maximum érték
        if (setAppSetting('items_per_page', $itemsPerPage)) $updated++;

        if ($updated > 0) {
            $_SESSION['flash_message'] = "Alkalmazás beállítások mentve.";
            $_SESSION['flash_message_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Nem történt változás vagy hiba a mentéskor.";
            $_SESSION['flash_message_type'] = "warning";
        }
        header('Location: ' . BASE_URL . 'admin/settings.php'); exit;
    }
}



// Felhasználói beállítások mentése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user_settings'])) {
    // Email módosítás
    if (isset($_POST['email']) && filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $newEmail = $_POST['email'];
        if ($newEmail !== $currentEmail) {
            $updateEmailStmt = $db->prepare("UPDATE users SET email = :email WHERE id = :user_id");
            $updateEmailStmt->bindParam(':email', $newEmail);
            $updateEmailStmt->bindParam(':user_id', $currentUserId);
            if ($updateEmailStmt->execute()) {
                $currentEmail = $newEmail; // Frissítjük a megjelenített emailt
                $message .= "Email cím sikeresen frissítve.<br>";
                $message_type = 'success';
            } else {
                $message .= "Hiba az email cím frissítésekor.<br>";
                $message_type = 'error';
            }
        }
    } elseif (isset($_POST['email'])) {
         $message .= "Érvénytelen email formátum.<br>";
         $message_type = 'error';
    }


    // Jelszócsere
    if (!empty($_POST['current_password']) && !empty($_POST['new_password']) && !empty($_POST['confirm_new_password'])) {
        if ($_POST['new_password'] === $_POST['confirm_new_password']) {
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :user_id");
            $stmt->bindParam(':user_id', $currentUserId);
            $stmt->execute();
            $userData = $stmt->fetch();

            if ($userData && password_verify($_POST['current_password'], $userData['password_hash'])) {
                $newPasswordHash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $updatePassStmt = $db->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :user_id");
                $updatePassStmt->bindParam(':password_hash', $newPasswordHash);
                $updatePassStmt->bindParam(':user_id', $currentUserId);
                if ($updatePassStmt->execute()) {
                    $message .= "Jelszó sikeresen megváltoztatva.<br>";
                    if(empty($message_type) || $message_type == 'success') $message_type = 'success';
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
    } elseif (!empty($_POST['current_password']) || !empty($_POST['new_password']) || !empty($_POST['confirm_new_password'])) {
        // Ha valamelyik jelszó mező ki van töltve, de nem mind
        $message .= "Jelszóváltoztatáshoz minden jelszómező kitöltése szükséges.<br>";
        $message_type = 'warning';
    }
    
    if(!empty($message)){
        $_SESSION['flash_message'] = rtrim($message, "<br>");
        $_SESSION['flash_message_type'] = $message_type;
        header('Location: ' . BASE_URL . 'admin/settings.php'); // Újratöltés a flash üzenet megjelenítéséhez
        exit;
    }
}

// Alkalmazás beállítások (admin only - itt most minden felhasználó admin)
// TODO: Később jogosultságkezelés

?>
<div class="content-header">
    <h1><i class="fas fa-cog"></i> <?php echo escape($pageTitle); ?></h1>
</div>

<div class="settings-sections">
    <section class="settings-section glass-effect">
        <h2><i class="fas fa-user-edit"></i> Felhasználói Beállítások</h2>
        <form method="POST" action="">
            <input type="hidden" name="save_user_settings" value="1">
            <div class="form-group">
                <label for="email">Email cím:</label>
                <input type="email" id="email" name="email" value="<?php echo escape($currentEmail); ?>" required>
            </div>
            <hr>
            <h3>Jelszócsere</h3>
            <div class="form-group">
                <label for="current_password">Jelenlegi jelszó:</label>
                <input type="password" id="current_password" name="current_password">
            </div>
            <div class="form-group">
                <label for="new_password">Új jelszó:</label>
                <input type="password" id="new_password" name="new_password">
            </div>
            <div class="form-group">
                <label for="confirm_new_password">Új jelszó megerősítése:</label>
                <input type="password" id="confirm_new_password" name="confirm_new_password">
            </div>
            <button type="submit" class="btn btn-primary">Felhasználói Beállítások Mentése</button>
        </form>
    </section>

    <section class="settings-section glass-effect">
        <h2><i class="fas fa-cogs"></i> Alkalmazás Beállítások (Admin)</h2>
        <p class="placeholder-text">Helyőrző az alkalmazás szintű beállításoknak (pl. email értesítések, IP geolokáció ki/be, elemek száma oldalanként).</p>
        <p class="placeholder-text">Ezeket a v1 még nem kezeli, adatbázisban vagy config fájlban lennének tárolva.</p>
        <!-- Példa beállítási lehetőségek (nem működnek még):
        <form method="POST" action="">
            <input type="hidden" name="save_app_settings" value="1">
            <div class="form-group">
                <label for="email_notifications">Email értesítések:</label>
                <select id="email_notifications" name="email_notifications">
                    <option value="1">Bekapcsolva</option>
                    <option value="0">Kikapcsolva</option>
                </select>
            </div>
             <div class="form-group">
                <label for="items_per_page">Elemek oldalanként:</label>
                <input type="number" id="items_per_page" name="items_per_page" value="<?php echo DEFAULT_ITEMS_PER_PAGE; ?>">
            </div>
            <button type="submit" class="btn btn-primary">Alkalmazás Beállítások Mentése</button>
        </form>
        -->
    </section>
    <?php if (isAdmin()): ?>
    <section class="settings-section glass-effect">
        <h2><i class="fas fa-cogs"></i> Alkalmazás Beállítások (Admin)</h2>
        <form method="POST" action="">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="save_app_settings" value="1">
            
            <div class="form-group">
                <input type="checkbox" id="ip_geolocation_enabled" name="ip_geolocation_enabled" value="1" <?php if ($appSettings['ip_geolocation_enabled'] == '1') echo 'checked'; ?>>
                <label for="ip_geolocation_enabled" style="display:inline-block; font-weight:normal; color: var(--text-primary);">IP Geolokáció Engedélyezése (GeoLite2)</label>
                <small style="display:block; color:var(--text-secondary);">Szükséges a `GeoLite2-City.mmdb` fájl a `data` mappában és a `geoip2/geoip2` Composer csomag.</small>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>