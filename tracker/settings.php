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

// Lekérjük a felhasználó adatait, hogy tudjuk, van-e jelszava
// (Ezt felhoztuk az elejére, mert a mentésnél is tudnunk kell)
$userStmt = $db->prepare("SELECT email, password_hash, api_token, allow_api_token_creation FROM users WHERE id = :user_id");
$userStmt->execute([':user_id' => $currentUserId]);
$userData = $userStmt->fetch();

$hasPassword = !empty($userData['password_hash']); // Van-e beállítva jelszó?

// --- POST KÉRÉSEK KEZELÉSE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ellenőrizzük a CSRF tokent
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Érvénytelen vagy lejárt munkamenet. Kérjük, próbálja újra.";
        $_SESSION['flash_message_type'] = "error";
    } else {
        $action = $_POST['action'] ?? null;
        
        // --- FELHASZNÁLÓI BEÁLLÍTÁSOK MENTÉSE ---
        if ($action === 'save_user_settings') {
            $message = '';
            $message_type = 'info';

            // 1. Email módosítás
            if (isset($_POST['email'])) {
                $newEmail = trim($_POST['email']);
                if (filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $emailCheck = $db->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
                    $emailCheck->execute([':email' => $newEmail, ':id' => $currentUserId]);
                    if ($emailCheck->fetch()) {
                        $message .= "Ez az e-mail cím már foglalt.<br>";
                        $message_type = 'error';
                    } else {
                        $updateEmailStmt = $db->prepare("UPDATE users SET email = :email WHERE id = :id");
                        if ($updateEmailStmt->execute([':email' => $newEmail, ':id' => $currentUserId])) {
                            $message .= "Email cím sikeresen frissítve.<br>";
                            if($message_type != 'error') $message_type = 'success';
                        }
                    }
                } else {
                    $message .= "Érvénytelen e-mail formátum.<br>";
                    $message_type = 'error';
                }
            }

            // 2. Jelszó kezelés (JAVÍTOTT RÉSZ)
            // Csak akkor foglalkozunk vele, ha az új jelszó mező ki van töltve
            if (!empty($_POST['new_password'])) {
                
                $currentPassInput = $_POST['current_password'] ?? '';
                $newPassInput = $_POST['new_password'];
                $confirmPassInput = $_POST['confirm_new_password'] ?? '';

                // Validációk
                if ($hasPassword && empty($currentPassInput)) {
                    // Ha van jelszava, de nem adta meg a régit
                    $message .= "A jelszó módosításához meg kell adnod a jelenlegi jelszavadat.<br>";
                    $message_type = 'error';
                } elseif (empty($newPassInput) || empty($confirmPassInput)) {
                    $message .= "Kérjük, add meg az új jelszót mindkét mezőben.<br>";
                    $message_type = 'warning';
                } elseif ($newPassInput !== $confirmPassInput) {
                    $message .= "A két új jelszó nem egyezik.<br>";
                    $message_type = 'error';
                } else {
                    // Ellenőrzés és Csere
                    $verificationSuccess = true;

                    // Ha VAN jelszava, ellenőrizzük a régit
                    if ($hasPassword) {
                        if (!password_verify($currentPassInput, $userData['password_hash'])) {
                            $message .= "A jelenlegi jelszó helytelen.<br>";
                            $message_type = 'error';
                            $verificationSuccess = false;
                        }
                    }

                    // Ha minden ok (vagy nincs jelszava, vagy a régi jelszó jó volt)
                    if ($verificationSuccess) {
                        $newPasswordHash = password_hash($newPassInput, PASSWORD_DEFAULT);
                        $updatePassStmt = $db->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :user_id");
                        if($updatePassStmt->execute([':password_hash' => $newPasswordHash, ':user_id' => $currentUserId])){
                            $message .= "Jelszó sikeresen beállítva/módosítva.<br>";
                            if($message_type != 'error') $message_type = 'success';
                        }
                    }
                }
            }
            if(!empty($message)){ $_SESSION['flash_message'] = rtrim($message, "<br>"); $_SESSION['flash_message_type'] = $message_type; }
        }

        // --- ALKALMAZÁS BEÁLLÍTÁSOK MENTÉSE (ADMIN) ---
        elseif ($action === 'save_app_settings' && isAdmin()) {
            $updated = 0;
            if (setAppSetting('ip_geolocation_enabled', isset($_POST['ip_geolocation_enabled']) ? '1' : '0')) $updated++;
            
            $itemsPerPage = (int)($_POST['items_per_page'] ?? DEFAULT_ITEMS_PER_PAGE);
            if ($itemsPerPage >= 5 && $itemsPerPage <= 100) {
                if (setAppSetting('items_per_page', $itemsPerPage)) $updated++;
            }
            if ($updated > 0) {
                 $_SESSION['flash_message'] = "Alkalmazás beállítások mentve."; $_SESSION['flash_message_type'] = "success";
            }
        }
        
        // --- API BEÁLLÍTÁSOK MENTÉSE ---
        elseif ($action === 'save_api_settings') {
            $db->beginTransaction();
            try {
                // Token generálása
                if (isset($_POST['regenerate_token']) || is_null($userData['api_token'])) {
                    $newApiToken = bin2hex(random_bytes(32));
                    $updateTokenStmt = $db->prepare("UPDATE users SET api_token = :api_token WHERE id = :id");
                    $updateTokenStmt->execute([':api_token' => $newApiToken, ':id' => $currentUserId]);
                }
                
                // Automatikus létrehozás engedélyezése/tiltása
                $allowCreation = isset($_POST['allow_api_token_creation']) ? 1 : 0;
                $updateAllowStmt = $db->prepare("UPDATE users SET allow_api_token_creation = :allow WHERE id = :id");
                $updateAllowStmt->execute([':allow' => $allowCreation, ':id' => $currentUserId]);
                
                $db->commit();
                $_SESSION['flash_message'] = "API beállítások sikeresen mentve.";
                $_SESSION['flash_message_type'] = "success";
            } catch (Exception $e) {
                $db->rollBack();
                $_SESSION['flash_message'] = "Hiba történt az API beállítások mentésekor: " . $e->getMessage();
                $_SESSION['flash_message_type'] = "error";
            }
        }
    }
    header('Location: ' . BASE_URL . 'tracker/settings.php');
    exit;
}

// === 2. LÉPÉS: ADATOK ELŐKÉSZÍTÉSE MEGJELENÍTÉSHEZ ===
$pageTitle = "Beállítások";
$currentEmail = $userData['email'] ?? '';
$apiToken = $userData['api_token'] ?? null;
$allowApiCreation = (bool)($userData['allow_api_token_creation'] ?? false);

// Alkalmazás beállítások
$appSettings = [
    'ip_geolocation_enabled' => getAppSetting('ip_geolocation_enabled', '0'),
    'items_per_page' => getAppSetting('items_per_page', DEFAULT_ITEMS_PER_PAGE),
];

// === 3. LÉPÉS: HTML MEGJELENÍTÉS ===
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-cog"></i> <?php echo escape($pageTitle); ?></h1>
</div>

<div class="settings-sections">
    <!-- Felhasználói beállítások űrlap -->
    <section class="settings-section glass-effect">
        <h2><i class="fas fa-user-edit"></i> Fiók Beállítások</h2>
        <form method="POST" action="">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="save_user_settings">
            
            <div class="form-group">
                <label for="email">Email cím:</label>
                <input type="email" id="email" name="email" value="<?php echo escape($currentEmail); ?>" required>
            </div>

            <hr><br>
            
            <h3>
                <?php echo $hasPassword ? 'Jelszócsere' : 'Jelszó beállítása'; ?>
            </h3>
            
            <?php if (!$hasPassword): ?>
                <div style="background: rgba(var(--accent-primary-rgb), 0.1); border-left: 4px solid var(--accent-primary); padding: 10px; margin-bottom: 15px; border-radius: 4px;">
                    Jelenleg Google vagy Github fiókkal léptél be. Itt beállíthatsz egy jelszót, ha szeretnél hagyományos módon (email/jelszó) is belépni.
                </div>
            <?php endif; ?>

            <!-- Csak akkor mutatjuk a jelenlegi jelszó mezőt, ha VAN jelszava -->
            <?php if ($hasPassword): ?>
                <div class="form-group">
                    <label for="current_password">Jelenlegi jelszó:</label>
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password">
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="new_password">Új jelszó:</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password" placeholder="<?php echo $hasPassword ? '' : 'Add meg a kívánt jelszót'; ?>">
            </div>
            <div class="form-group">
                <label for="confirm_new_password">Új jelszó megerősítése:</label>
                <input type="password" id="confirm_new_password" name="confirm_new_password" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary">Felhasználói Beállítások Mentése</button>
        </form>
    </section>

    <!-- API Hozzáférés Beállítások -->
    <section class="settings-section glass-effect">
        <h2><i class="fas fa-key"></i> API Hozzáférés</h2>
        <p class="text-secondary" style="margin-bottom:20px;">
            Ezzel a linkkel külső scriptekből vagy eszközökből automatikusan hozhatsz létre új pixelkövetőket. A megadott URL lesz a token neve.
        </p>

        <form method="POST" action="">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="save_api_settings">

            <div class="form-group">
                <label for="api_token_link">A Te API Végpontod:</label>
                <?php if ($apiToken): ?>
                    <input type="text" id="api_token_link" value="<?php echo BASE_URL . 'api.php?api_token=' . escape($apiToken) . '&url=CEL_URL'; ?>" readonly class="pixel-url-input">
                    <small style="display:block; color:var(--text-secondary); margin-top:5px;">Cseréld a `CEL_URL` részt a követni kívánt URL-re.</small>
                <?php else: ?>
                    <p>Még nincs generált API kulcsod. Kattints az "Első Kulcs Generálása" gombra egy létrehozásához.</p>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <input type="checkbox" id="allow_api_token_creation" name="allow_api_token_creation" value="1" <?php if ($allowApiCreation) echo 'checked'; ?>>
                <label for="allow_api_token_creation" style="display:inline-block; font-weight:normal;">Automatikus token-létrehozás engedélyezése</label>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 15px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> API Beállítások Mentése</button>
                
                <?php if ($apiToken): ?>
                    <button type="submit" name="regenerate_token" value="1" class="btn btn-warning" onclick="return confirm('Biztosan új API kulcsot szeretnél generálni? A régi érvénytelenné válik!');">
                        <i class="fas fa-sync-alt"></i> Kulcs Újragenerálása
                    </button>
                <?php else: ?>
                    <button type="submit" name="regenerate_token" value="1" class="btn btn-success">
                        <i class="fas fa-plus-circle"></i> Első Kulcs Generálása
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <!-- Alkalmazás beállítások űrlap (csak adminoknak) -->
    <?php if (isAdmin()): ?>
    <section class="settings-section glass-effect">
        <h2><i class="fas fa-cogs"></i> Alkalmazás Beállítások (Admin)</h2>
        <form method="POST" action="">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="save_app_settings">
            
            <div class="form-group">
                <input type="checkbox" id="ip_geolocation_enabled" name="ip_geolocation_enabled" value="1" <?php if ($appSettings['ip_geolocation_enabled'] == '1') echo 'checked'; ?>>
                <label for="ip_geolocation_enabled" style="display:inline-block; font-weight:normal;">IP Geolokáció Engedélyezése</label>
                <small style="display:block; color:var(--text-secondary);">Szükséges a `GeoLite2-City.mmdb` fájl a `data/` mappában.</small>
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