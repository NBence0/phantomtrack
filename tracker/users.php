<?php
// === Fájl: tracker/users.php ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// Jogosultság ellenőrzése
requireAdmin();

$db = getDB();
$currentUserId = getCurrentUserId();

// === ADATOK LEKÉRDEZÉSE ===
$pageTitle = "Felhasználók Kezelése";

// Lekérdezés bővítése a social ID-kkal
$usersStmt = $db->prepare("
    SELECT u.id, u.username, u.email, u.is_admin, u.created_at, 
           u.google_id, u.facebook_id, u.github_id, 
           COUNT(t.id) as token_count
    FROM users u
    LEFT JOIN tokens t ON u.id = t.user_id
    GROUP BY u.id
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
    <button class="btn btn-primary" onclick="document.getElementById('addUserModal').style.display='block'">
        <i class="fas fa-user-plus"></i> Új Felhasználó
    </button>
</div>

<!-- MODÁLIS ABLAKOK -->
<?php include __DIR__ . '/../includes/user_modals.php'; ?>

<!-- FELHASZNÁLÓK LISTÁJA -->
<section class="settings-section glass-effect">
    <h2><i class="fas fa-list-ul"></i> Regisztrált Felhasználók</h2>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Felhasználónév</th>
                    <th>Email</th>
                    <th>Fiókok</th> <!-- ÚJ OSZLOP -->
                    <th>Szerepkör</th>
                    <th>Regisztrált</th>
                    <th style="text-align:right;">Műveletek</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6" style="text-align: center;">Nincsenek felhasználók.</td></tr>
                <?php else: foreach ($users as $user): ?>
                <tr>
                    <td data-label="Felhasználónév">
                        <strong><?php echo escape($user['username']); ?></strong>
                    </td>
                    <td data-label="Email">
                        <?php echo escape($user['email']); ?>
                    </td>
                    
                    <!-- ÚJ: Social Ikonok Megjelenítése -->
                    <td data-label="Fiókok" style="white-space: nowrap;">
                        <?php 
                        $hasSocial = false;
                        if (!empty($user['google_id'])) {
                            echo '<i class="fab fa-google" style="color:#DB4437; margin-right:8px; font-size:1.1em;" title="Google Fiók csatolva"></i>';
                            $hasSocial = true;
                        }
                        if (!empty($user['facebook_id'])) {
                            echo '<i class="fab fa-facebook" style="color:#4267B2; margin-right:8px; font-size:1.1em;" title="Facebook Fiók csatolva"></i>';
                            $hasSocial = true;
                        }
                        if (!empty($user['github_id'])) {
                            echo '<i class="fab fa-github" style="color:#ffffff; margin-right:8px; font-size:1.1em;" title="GitHub Fiók csatolva"></i>';
                            $hasSocial = true;
                        }
                        if (!$hasSocial) {
                            echo '<span class="text-muted" style="font-size:0.8em;">-</span>';
                        }
                        ?>
                    </td>

                    <td data-label="Szerepkör">
                        <?php if ($user['is_admin']): ?>
                            <span style="color:var(--color-warning); font-weight:bold;"><i class="fas fa-crown"></i> Admin</span>
                        <?php else: ?>
                            User
                        <?php endif; ?>
                    </td>
                    
                    <td data-label="Regisztrált">
                        <?php echo escape(formatTimestamp($user['created_at'])); ?>
                    </td>
                    
                    <td data-label="Műveletek">
                        <div class="action-buttons">
                            <button onclick="openEditUserModal(<?php echo $user['id']; ?>)" class="btn btn-small btn-secondary" title="Szerkesztés">
                                <i class="fas fa-edit"></i>
                            </button>
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
        ajaxUrl: '<?php echo BASE_URL; ?>tracker/ajax_actions.php',

        // A felhasználók listája a tokenek áthelyezéséhez
        // Csak az ID és a felhasználónév szükséges
        allUsersForMove: <?php echo json_encode(array_map(function($u) { return ['id' => $u['id'], 'username' => $u['username']]; }, $users)); ?>
    };
</script>

<script src="<?php echo BASE_URL . 'assets/js/users.js'; ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>