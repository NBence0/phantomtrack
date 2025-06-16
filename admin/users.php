<?php
require_once __DIR__ . '/../includes/header.php';
requireAdmin(); // Csak adminoknak!

$pageTitle = "Felhasználók Kezelése";
$db = getDB();

// Új felhasználó létrehozása (admin által)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
         $_SESSION['flash_message'] = "Érvénytelen kérés."; $_SESSION['flash_message_type'] = "error";
    } else {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $is_admin = isset($_POST['is_admin']) ? 1 : 0;

        if(empty($username) || empty($email) || empty($password)){
             $_SESSION['flash_message'] = "Felhasználónév, email és jelszó kitöltése kötelező."; $_SESSION['flash_message_type'] = "warning";
        } else {
            // Ellenőrizzük, foglalt-e
            $stmt = $db->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
            $stmt->execute([':username' => $username, ':email' => $email]);
            if($stmt->fetch()){
                 $_SESSION['flash_message'] = "Felhasználónév vagy email már foglalt."; $_SESSION['flash_message_type'] = "error";
            } else {
                if (registerUser($username, $email, $password, $is_admin)) {
                     $_SESSION['flash_message'] = "Felhasználó sikeresen létrehozva."; $_SESSION['flash_message_type'] = "success";
                } else {
                     $_SESSION['flash_message'] = "Hiba történt a felhasználó létrehozásakor."; $_SESSION['flash_message_type'] = "error";
                }
            }
        }
        header('Location: ' . BASE_URL . 'admin/users.php'); exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Érvénytelen kérés."; $_SESSION['flash_message_type'] = "error";
    } else {
        $userIdToDelete = (int)($_POST['user_id_to_delete'] ?? 0);
        $tokenAction = $_POST['token_action'] ?? 'delete';
        $newUserId = (int)($_POST['new_user_id'] ?? 0);

        if ($userIdToDelete <= 0) {
            $_SESSION['flash_message'] = "Érvénytelen felhasználói azonosító a törléshez."; $_SESSION['flash_message_type'] = "error";
        } elseif ($userIdToDelete == getCurrentUserId()) {
            $_SESSION['flash_message'] = "Saját fiókodat nem törölheted."; $_SESSION['flash_message_type'] = "error";
        } elseif ($tokenAction === 'move' && $newUserId <= 0) {
            $_SESSION['flash_message'] = "Tokenek átruházásához ki kell választani egy célfelhasználót."; $_SESSION['flash_message_type'] = "error";
        } else {
            
            $db->beginTransaction(); // Fontos: Tranzakciót indítunk
            try {
                if ($tokenAction === 'move') {
                    // 1. opció: Tokenek átruházása
                    $updateTokensStmt = $db->prepare("UPDATE tokens SET user_id = :new_user_id WHERE user_id = :old_user_id");
                    $updateTokensStmt->execute([':new_user_id' => $newUserId, ':old_user_id' => $userIdToDelete]);
                    $_SESSION['flash_message'] = "Tokenek sikeresen átruházva. ";
                } else {
                    // 2. opció: Tokenek (és a hozzájuk tartozó logok) törlése
                    // Cascade delete miatt elég a tokent törölni, ha az FK úgy van beállítva.
                    // Biztonság kedvéért explicit töröljük a logokat is, ha az FK nincs beállítva ON DELETE CASCADE-ra.
                    $deleteLogsStmt = $db->prepare("DELETE al FROM activity_logs al JOIN tokens t ON al.token_id = t.id WHERE t.user_id = :user_id");
                    $deleteLogsStmt->execute([':user_id' => $userIdToDelete]);

                    $deleteTokensStmt = $db->prepare("DELETE FROM tokens WHERE user_id = :user_id");
                    $deleteTokensStmt->execute([':user_id' => $userIdToDelete]);
                    $_SESSION['flash_message'] = "A felhasználóhoz tartozó tokenek és naplók törölve. ";
                }

                // Végül a felhasználó törlése
                $deleteUserStmt = $db->prepare("DELETE FROM users WHERE id = :id");
                $deleteUserStmt->execute([':id' => $userIdToDelete]);
                
                $db->commit(); // Minden sikeres, véglegesítjük a változásokat
                
                $_SESSION['flash_message'] .= "A felhasználó sikeresen törölve.";
                $_SESSION['flash_message_type'] = "success";
                
            } catch (Exception $e) {
                $db->rollBack(); // Hiba történt, visszavonunk mindent
                $_SESSION['flash_message'] = "Hiba történt a törlés során: " . $e->getMessage();
                $_SESSION['flash_message_type'] = "error";
            }
        }
        header('Location: ' . BASE_URL . 'admin/users.php'); exit;
    }
}

// Felhasználók listázása
$usersStmt = $db->query("
    SELECT u.id, u.username, u.email, u.is_admin, u.created_at, COUNT(t.id) as token_count
    FROM users u
    LEFT JOIN tokens t ON u.id = t.user_id
    GROUP BY u.id, u.username, u.email, u.is_admin, u.created_at
    ORDER BY u.created_at DESC
");
$users = $usersStmt->fetchAll();
?>
<div class="content-header">
    <h1><i class="fas fa-users-cog"></i> <?php echo escape($pageTitle); ?></h1>
</div>

<div class="settings-sections" style="grid-template-columns: 1fr 2fr; gap: 30px;">
    <section class="settings-section glass-effect">
        <h2><i class="fas fa-user-plus"></i> Új Felhasználó Létrehozása</h2>
        <form method="POST" action="">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="create_user" value="1">
            <div class="form-group">
                <label for="username">Felhasználónév:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="email">E-mail cím:</label>
                <input type="email" id="email" name="email" required>
            </div>
             <div class="form-group">
                <label for="password">Jelszó:</label>
                <input type="password" id="password" name="password" required>
            </div>
             <div class="form-group">
                <input type="checkbox" id="is_admin" name="is_admin" value="1" style="vertical-align: middle;">
                <label for="is_admin" style="display: inline-block; font-weight:normal;">Adminisztrátori jogosultság</label>
            </div>
            <button type="submit" class="btn btn-primary">Létrehozás</button>
        </form>
    </section>

    <section class="settings-section glass-effect">
        <h2><i class="fas fa-list-ul"></i> Regisztrált Felhasználók</h2>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Felhasználónév</th>
                        <th>Email</th>
                        <th>Szerepkör</th>
                        <th>Regisztrált</th>
                        <th>Műveletek</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo escape($user['username']); ?></td>
                        <td><?php echo escape($user['email']); ?></td>
                        <td><?php echo $user['is_admin'] ? '<b>Admin</b>' : 'User'; ?></td>
                        <td><?php echo escape(formatTimestamp($user['created_at'])); ?></td>
                        <td>
                            <a href="<?php echo BASE_URL . 'admin/edit_user.php?id=' . $user['id']; ?>" class="btn btn-small btn-secondary" title="Szerkesztés"><i class="fas fa-edit"></i></a>
                            <?php if ($user['id'] != getCurrentUserId()): ?>
                                <a href="javascript:void(0)" onclick="openDeleteModal(<?php echo $user['id']; ?>, '<?php echo escape($user['username']); ?>', <?php echo $user['token_count']; ?>)" class="btn btn-small btn-danger" title="Törlés">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<!-- Felhasználó Törlése Modális Ablak -->
<div id="deleteUserModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="closeModal('deleteUserModal')">×</span>
        <h2>Felhasználó Törlése</h2>
        <p>A(z) <strong id="userToDeleteName"></strong> nevű felhasználó törlésre kerül.</p>
        <p>Mi történjen a felhasználóhoz tartozó <strong id="userTokenCount"></strong> darab tokennel és a hozzájuk kapcsolódó összes aktivitási naplóval?</p>
        
        <form method="POST" action="" id="deleteUserForm">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" id="userToDeleteId" name="user_id_to_delete">

            <div class="form-group">
                <input type="radio" id="option_delete" name="token_action" value="delete" checked>
                <label for="option_delete">Minden token és a hozzájuk tartozó napló végleges törlése.</label>
            </div>
            
            <div class="form-group">
                <input type="radio" id="option_move" name="token_action" value="move">
                <label for="option_move">Tokenek átruházása egy másik felhasználóra:</label>
                <select name="new_user_id" id="newUserSelect" disabled style="margin-top: 10px; width: 100%;">
                    <!-- Az opciókat JS tölti fel -->
                </select>
            </div>

            <p style="color: var(--color-warning); margin-top:20px; font-weight: bold;"><i class="fas fa-exclamation-triangle"></i> Figyelem! A művelet nem vonható vissza!</p>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('deleteUserModal')">Mégse</button>
                <button type="submit" class="btn btn-danger">Felhasználó Törlése</button>
            </div>
        </form>
    </div>
</div>

<script>
// A felhasználók listája a JS számára (kivéve a törlendőt)
const allUsersForMove = <?php echo json_encode(array_map(function($u) { return ['id' => $u['id'], 'username' => $u['username']]; }, $users)); ?>;

function openDeleteModal(userId, userName, tokenCount) {
    document.getElementById('userToDeleteId').value = userId;
    document.getElementById('userToDeleteName').innerText = userName;
    document.getElementById('userTokenCount').innerText = tokenCount;
    document.getElementById('deleteUserForm').action = "<?php echo BASE_URL; ?>admin/users.php";

    const newUserSelect = document.getElementById('newUserSelect');
    newUserSelect.innerHTML = '<option value="">Válassz egy felhasználót...</option>'; // Üres opció

    // Szűrjük a listát, hogy a törlendő user ne jelenjen meg benne
    const availableUsers = allUsersForMove.filter(user => user.id != userId);
    
    availableUsers.forEach(user => {
        const option = document.createElement('option');
        option.value = user.id;
        option.innerText = user.username;
        newUserSelect.appendChild(option);
    });

    // Ha nincsenek más felhasználók, az átruházás opciót letiltjuk
    if (availableUsers.length === 0) {
        document.getElementById('option_move').disabled = true;
        document.getElementById('option_delete').checked = true;
        newUserSelect.disabled = true;
    } else {
        document.getElementById('option_move').disabled = false;
    }
    
    document.getElementById('deleteUserModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Kezeljük a radio gombok állapotát
document.getElementById('option_delete').addEventListener('change', function() {
    document.getElementById('newUserSelect').disabled = true;
});
document.getElementById('option_move').addEventListener('change', function() {
    // Csak akkor engedélyezzük, ha van kire átruházni
    if(document.getElementById('newUserSelect').options.length > 1){
         document.getElementById('newUserSelect').disabled = false;
    }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>