<?php
// === 1. LÉPÉS: PHP FÁJLOK BEHÍVÁSA ÉS MINDEN FELDOLGOZÓ LOGIKA (HTML ELŐTT) ===
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// Jogosultság ellenőrzése az oldal elején
requireAdmin();

$db = getDB();
$currentUserId = getCurrentUserId();

// --- HAGYOMÁNYOS (NEM-AJAX) FORM FELDOLGOZÁS ---
// Ez a blokk lefut és átirányít, mielőtt bármi HTML kimenet lenne.
// Bár átálltunk AJAX-ra, biztonsági tartaléknak, vagy ha a JS nem fut le, ez még hasznos lehet.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Ez a blokk most már csak a NEM-AJAX-os törlést kezeli, ami a modális ablakból jön.
    if ($_POST['action'] === 'delete_user') {
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            $_SESSION['flash_message'] = "Érvénytelen kérés."; $_SESSION['flash_message_type'] = "error";
        } else {
            $userIdToDelete = (int)($_POST['user_id_to_delete'] ?? 0);
            $tokenAction = $_POST['token_action'] ?? 'delete';
            $newUserId = ($_POST['new_user_id'] === 'none') ? null : (int)($_POST['new_user_id'] ?? 0);

            if ($userIdToDelete <= 0) {
                $_SESSION['flash_message'] = "Érvénytelen felhasználói azonosító a törléshez."; $_SESSION['flash_message_type'] = "error";
            } elseif ($userIdToDelete == $currentUserId) {
                $_SESSION['flash_message'] = "Saját fiókodat nem törölheted."; $_SESSION['flash_message_type'] = "error";
            } elseif ($tokenAction === 'move' && $newUserId === 0) {
                $_SESSION['flash_message'] = "Tokenek átruházásához ki kell választani egy célfelhasználót."; $_SESSION['flash_message_type'] = "error";
            } else {
                $db->beginTransaction();
                try {
                    if ($tokenAction === 'move') {
                        $updateTokensStmt = $db->prepare("UPDATE tokens SET user_id = :new_user_id WHERE user_id = :old_user_id");
                        $updateTokensStmt->execute([':new_user_id' => $newUserId, ':old_user_id' => $userIdToDelete]);
                        $_SESSION['flash_message'] = "Tokenek sikeresen átruházva. ";
                    } else {
                        $getTokensStmt = $db->prepare("SELECT id FROM tokens WHERE user_id = :user_id");
                        $getTokensStmt->execute([':user_id' => $userIdToDelete]);
                        $tokenIdsToDelete = $getTokensStmt->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($tokenIdsToDelete)) {
                            $placeholders = implode(',', array_fill(0, count($tokenIdsToDelete), '?'));
                            $db->prepare("DELETE FROM activity_logs WHERE token_id IN ($placeholders)")->execute($tokenIdsToDelete);
                            $db->prepare("DELETE FROM tokens WHERE id IN ($placeholders)")->execute($tokenIdsToDelete);
                        }
                        $_SESSION['flash_message'] = "A felhasználóhoz tartozó tokenek és naplók törölve. ";
                    }
                    $deleteUserStmt = $db->prepare("DELETE FROM users WHERE id = :id");
                    $deleteUserStmt->execute([':id' => $userIdToDelete]);
                    $db->commit();
                    $_SESSION['flash_message'] .= "A felhasználó sikeresen törölve.";
                    $_SESSION['flash_message_type'] = "success";
                } catch (Exception $e) {
                    $db->rollBack();
                    $_SESSION['flash_message'] = "Hiba történt a törlés során: " . $e->getMessage();
                    $_SESSION['flash_message_type'] = "error";
                }
            }
        }
        header('Location: ' . BASE_URL . 'admin/users.php');
        exit;
    }
}

// === 2. LÉPÉS: ADATOK LEKÉRDEZÉSE A MEGJELENÍTÉSHEZ ===
$pageTitle = "Felhasználók Kezelése";

// Felhasználók listázása a táblázathoz
$usersStmt = $db->prepare("
    SELECT u.id, u.username, u.email, u.is_admin, u.created_at, COUNT(t.id) as token_count
    FROM users u
    LEFT JOIN tokens t ON u.id = t.user_id
    WHERE u.id > 0 -- Csak egy dummy feltétel, hogy a GROUP BY működjön, ha nincs user
    GROUP BY u.id, u.username, u.email, u.is_admin, u.created_at
    ORDER BY u.created_at DESC
");
$usersStmt->execute();
$users = $usersStmt->fetchAll();


// === 3. LÉPÉS: HTML MEGJELENÍTÉS KEZDETE ===
// Csak most, minden logika után hívjuk be a header.php-t!
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-users-cog"></i> <?php echo escape($pageTitle); ?></h1>
    <button class="btn btn-primary" onclick="document.getElementById('addUserModal').style.display='block'"><i class="fas fa-user-plus"></i> Új Felhasználó</button>
</div>

<!-- MODÁLIS ABLAKOK -->
<?php include __DIR__ . '/../includes/user_modals.php'; // Kiszervezzük a modális ablakokat ?>

<!-- A felhasználók listája -->
<section class="settings-section glass-effect">
    <h2><i class="fas fa-list-ul"></i> Regisztrált Felhasználók</h2>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th data-label="Felhasználónév">Felhasználónév</th>
                    <th data-label="Email">Email</th>
                    <th data-label="Szerepkör">Szerepkör</th>
                    <th data-label="Regisztrált">Regisztrált</th>
                    <th data-label="Műveletek">Műveletek</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="5" style="text-align: center;">Nincsenek felhasználók a rendszerben.</td></tr>
                <?php else: foreach ($users as $user): ?>
                <tr>
                    <td data-label="Felhasználónév"><?php echo escape($user['username']); ?></td>
                    <td data-label="Email"><?php echo escape($user['email']); ?></td>
                    <td data-label="Szerepkör"><?php echo $user['is_admin'] ? '<b>Admin</b>' : 'User'; ?></td>
                    <td data-label="Regisztrált"><?php echo escape(formatTimestamp($user['created_at'])); ?></td>
                    <td data-label="Műveletek">
                        <div class="action-buttons">
                            <button onclick="openEditUserModal(<?php echo $user['id']; ?>)" class="btn btn-small btn-secondary" title="Szerkesztés"><i class="fas fa-edit"></i></button>
                            <?php if ($user['id'] != $currentUserId): ?>
                                <button onclick="openDeleteUserModal(<?php echo $user['id']; ?>, '<?php echo escape($user['username']); ?>', <?php echo $user['token_count']; ?>)" class="btn btn-small btn-danger" title="Törlés">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
    // Ez az objektum tartalmaz minden olyan értéket, amit a PHP generál,
    // és amire a külső JavaScript fájlnak szüksége van.
    const UserPageConfig = {
        // Az AJAX kérésekhez használt központi URL
        ajaxUrl: '<?php echo BASE_URL; ?>admin/ajax_actions.php',

        // A felhasználók listája a tokenek áthelyezéséhez
        // Csak az ID és a felhasználónév szükséges
        allUsersForMove: <?php echo json_encode(array_map(function($u) { return ['id' => $u['id'], 'username' => $u['username']]; }, $users)); ?>
    };
</script>

<script src="<?php echo BASE_URL . 'assets/js/users.js'; ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>