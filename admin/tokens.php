<?php
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$pageTitle = "Token Menedzsment"; // Ezt a header.php használja
$db = getDB();
$currentUserId = getCurrentUserId();

$filterCategoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

// Kategóriák lekérése a szűrőhöz
$categoriesStmt = $db->prepare("SELECT id, name FROM token_categories WHERE user_id = :user_id ORDER BY name ASC");
$categoriesStmt->execute([':user_id' => $currentUserId]);
$availableCategories = $categoriesStmt->fetchAll();

// admin/tokens.php (modál előtt) és admin/edit_token.php (fájl elején)
$categoriesStmt = $db->prepare("SELECT id, name FROM token_categories WHERE user_id = :user_id ORDER BY name ASC");
$categoriesStmt->execute([':user_id' => $currentUserId]);
$categoriesForSelect = $categoriesStmt->fetchAll();


// Tokenek listázása kategória szűréssel
$sqlBase = "SELECT t.id, t.token_value, t.name, t.description, t.category_id, tc.name as category_name, t.is_active, t.created_at 
            FROM tokens t
            LEFT JOIN token_categories tc ON t.category_id = tc.id AND tc.user_id = t.user_id
            WHERE t.user_id = :user_id";
$params = [':user_id' => $currentUserId];

if ($filterCategoryId) {
    $sqlBase .= " AND t.category_id = :category_id";
    $params[':category_id'] = $filterCategoryId;
}

// Összes token számának lekérdezése a lapozáshoz (szűréssel)
$countSql = "SELECT COUNT(t.id) FROM tokens t WHERE t.user_id = :user_id";
if ($filterCategoryId) {
    $countSql .= " AND t.category_id = :category_id_count"; // Másik paraméternév az ütközés elkerülése végett
    $paramsForCount = [':user_id' => $currentUserId, ':category_id_count' => $filterCategoryId];
} else {
    $paramsForCount = [':user_id' => $currentUserId];
}
$totalTokensStmt = $db->prepare($countSql);
$totalTokensStmt->execute($paramsForCount);
$totalTokens = $totalTokensStmt->fetchColumn();
$itemsPerPage = (int)getAppSetting('items_per_page', DEFAULT_ITEMS_PER_PAGE);
$totalPages = ceil($totalTokens / $itemsPerPage);
if ($totalPages == 0) $totalPages = 1; // Hogy ne legyen 0, ha nincs token

// Tokenek listázása lapozással
$stmt = $db->prepare("SELECT id, token_value, name, description, is_active, created_at 
                      FROM tokens 
                      WHERE user_id = :user_id 
                      ORDER BY created_at DESC 
                      LIMIT :limit OFFSET :offset");
$stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
$stmt->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$tokens = $stmt->fetchAll();

$sqlBase .= " ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";
$params[':limit'] = $itemsPerPage; // PDO::PARAM_INT implicit
$params[':offset'] = $offset;       // PDO::PARAM_INT implicit

$stmt = $db->prepare($sqlBase);
// A bindParam itt már nem jó, mert a paraméterek száma változhat (category_id)
$stmt->execute($params);
$tokens = $stmt->fetchAll();



// Műveletek kezelése (létrehozás, törlés, státusz váltás)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // CSRF védelem
        if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
            $_SESSION['flash_message'] = "Érvénytelen kérés (CSRF token hiba).";
            $_SESSION['flash_message_type'] = "error";
            header('Location: ' . BASE_URL . 'admin/tokens.php');
            exit;
        }

        if ($_POST['action'] === 'create_token') {
            $name = trim($_POST['token_name'] ?? '');
            $description = trim($_POST['token_description'] ?? '');
            if (!empty($name)) {
                $newTokenValue = generateUniqueToken();
                $stmt = $db->prepare("INSERT INTO tokens (user_id, token_value, name, description, is_active, created_at) VALUES (:user_id, :token_value, :name, :description, 1, NOW())");
                $stmt->bindParam(':user_id', $currentUserId);
                $stmt->bindParam(':token_value', $newTokenValue);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                if ($stmt->execute()) {
                    $_SESSION['flash_message'] = "Token sikeresen létrehozva.";
                    $_SESSION['flash_message_type'] = "success";
                } else {
                    $_SESSION['flash_message'] = "Hiba a token létrehozásakor.";
                    $_SESSION['flash_message_type'] = "error";
                }
            } else {
                $_SESSION['flash_message'] = "A token neve nem lehet üres.";
                $_SESSION['flash_message_type'] = "warning";
            }
            header('Location: ' . BASE_URL . 'admin/tokens.php');
            exit;
        }
    }
}

if (isset($_GET['action'])) {
    $tokenId = (int)($_GET['id'] ?? 0);
    // CSRF GET kéréseknél GET token-t is lehetne használni, vagy megerősítést kérni JS-sel.

    if ($_GET['action'] === 'toggle_status' && $tokenId > 0) {
        $stmt = $db->prepare("UPDATE tokens SET is_active = NOT is_active WHERE id = :id AND user_id = :user_id");
        $stmt->bindParam(':id', $tokenId);
        $stmt->bindParam(':user_id', $currentUserId);
        if ($stmt->execute()) {
             $_SESSION['flash_message'] = "Token státusza frissítve.";
             $_SESSION['flash_message_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Hiba a státusz frissítésekor.";
            $_SESSION['flash_message_type'] = "error";
        }
        header('Location: ' . BASE_URL . 'admin/tokens.php');
        exit;
    }

    if ($_GET['action'] === 'delete_token' && $tokenId > 0) {
        // Először a kapcsolódó logokat töröljük
        $stmtLogs = $db->prepare("DELETE FROM activity_logs WHERE token_id = :token_id AND token_id IN (SELECT id FROM tokens WHERE user_id = :user_id)");
        $stmtLogs->bindParam(':token_id', $tokenId);
        $stmtLogs->bindParam(':user_id', $currentUserId);
        $stmtLogs->execute(); // Nem baj, ha nincs log, a token törlése a fontos

        // Majd a tokent
        $stmtToken = $db->prepare("DELETE FROM tokens WHERE id = :id AND user_id = :user_id");
        $stmtToken->bindParam(':id', $tokenId);
        $stmtToken->bindParam(':user_id', $currentUserId);
        if ($stmtToken->execute() && $stmtToken->rowCount() > 0) {
            $_SESSION['flash_message'] = "Token és a hozzá tartozó naplóbejegyzések törölve.";
            $_SESSION['flash_message_type'] = "success";
        } else {
            $_SESSION['flash_message'] = "Hiba a token törlésekor, vagy nincs ilyen tokened.";
            $_SESSION['flash_message_type'] = "error";
        }
        header('Location: ' . BASE_URL . 'admin/tokens.php');
        exit;
    }
}


// Tokenek listázása
$stmt = $db->prepare("SELECT id, token_value, name, description, is_active, created_at FROM tokens WHERE user_id = :user_id ORDER BY created_at DESC");
$stmt->bindParam(':user_id', $currentUserId);
$stmt->execute();
$tokens = $stmt->fetchAll();

?>
<div class="content-header">
    <h1><i class="fas fa-tags"></i> <?php echo escape($pageTitle); ?></h1>
    <button class="btn btn-primary" onclick="document.getElementById('addTokenModal').style.display='block'"><i class="fas fa-plus"></i> Új Token</button>
</div>

<!-- Új Token Modális Ablak -->
<div id="addTokenModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('addTokenModal').style.display='none'">×</span>
        <h2>Új Követő Token Létrehozása</h2>
        <form method="POST" action="<?php echo BASE_URL; ?>admin/tokens.php">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="create_token">
            <div class="form-group">
                <label for="token_name">Token Neve:</label>
                <input type="text" id="token_name" name="token_name" required>
            </div>
            <div class="form-group">
                <label for="token_description">Leírás (opcionális):</label>
                <textarea id="token_description" name="token_description" rows="3"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Létrehozás</button>
        </form>
    </div>
</div>


<div class="table-container glass-effect">
    <div class="filter-form glass-effect" style="padding: 15px; margin-bottom: 20px;">
        <form method="GET" action="" style="display: flex; align-items: center; gap: 15px;">
            <div class="form-group" style="margin-bottom:0; flex-grow:1;">
                <label for="category_filter_select" style="margin-right:10px;">Szűrés kategóriára:</label>
                <select id="category_filter_select" name="category_id" onchange="this.form.submit()">
                    <option value="">Összes kategória</option>
                    <?php foreach ($availableCategories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php if ($filterCategoryId == $cat['id']) echo 'selected'; ?>>
                            <?php echo escape($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filterCategoryId): ?>
                <a href="<?php echo BASE_URL; ?>admin/tokens.php" class="btn btn-secondary btn-small">Szűrő törlése</a>
            <?php endif; ?>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>Név</th>
                <th>Token Érték</th>
                <th>Leírás</th>
                <th>Státusz</th>
                <th>Létrehozva</th>
                <th>Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tokens)): ?>
                <tr><td colspan="6" style="text-align:center;">Nincsenek tokenek. Hozz létre egyet!</td></tr>
            <?php else: ?>
                <?php foreach ($tokens as $token): ?>
                <tr>
                    <td><a href="<?php echo BASE_URL . 'admin/token_details.php?id=' . $token['id']; ?>"><?php echo escape($token['name']); ?></a></td>
                    <td class="token-value-cell">
                        <input type="text" value="<?php echo BASE_URL . 'pixel.php?token=' . escape($token['token_value']); ?>" readonly class="pixel-url-input">
                        <button class="btn btn-small btn-copy" onclick="copyToClipboard(this.previousElementSibling)"><i class="far fa-copy"></i></button>
                    </td>
                    <td><?php echo nl2br(escape(substr($token['description'], 0, 50) . (strlen($token['description']) > 50 ? '...' : ''))); ?></td>
                    <td>
                        <?php if ($token['is_active']): ?>
                            <span class="status-active"><i class="fas fa-check-circle"></i> Aktív</span>
                        <?php else: ?>
                            <span class="status-inactive"><i class="fas fa-times-circle"></i> Inaktív</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo escape(formatTimestamp($token['created_at'])); ?></td>
                    <td>
                        <a href="<?php echo BASE_URL . 'admin/token_details.php?id=' . $token['id']; ?>" class="btn btn-small btn-info" title="Részletek"><i class="fas fa-eye"></i></a>
                        <a href="<?php echo BASE_URL . 'admin/tokens.php?action=toggle_status&id=' . $token['id']; ?>" class="btn btn-small <?php echo $token['is_active'] ? 'btn-warning' : 'btn-success'; ?>" title="<?php echo $token['is_active'] ? 'Deaktiválás' : 'Aktiválás'; ?>">
                            <i class="fas <?php echo $token['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i>
                        </a>
                        <a href="<?php echo BASE_URL . 'admin/tokens.php?action=delete_token&id=' . $token['id']; ?>" class="btn btn-small btn-danger" onclick="return confirm('Biztosan törölni szeretnéd ezt a tokent és minden kapcsolódó adatot?');" title="Törlés">
                            <i class="fas fa-trash-alt"></i>
                        </a>
                        <!-- ... Részletek, Státusz váltás, Törlés gombok ... -->
                        <a href="<?php echo BASE_URL . 'admin/edit_token.php?id=' . $token['id']; ?>" class="btn btn-small btn-secondary" title="Szerkesztés">
                            <i class="fas fa-edit"></i>
                        </a>
                    </td>
                    <td><?php echo $token['category_name'] ? escape($token['category_name']) : '<em style="color: var(--text-secondary);">Nincs</em>'; ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <!-- ... (A <table> után) ... -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination glass-effect">
        <?php if ($currentPage > 1): ?>
            <a href="?page=<?php echo $currentPage - 1; ?>" class="btn btn-secondary btn-small">« Előző</a>
        <?php else: ?>
            <span class="btn btn-secondary btn-small disabled">« Előző</span>
        <?php endif; ?>

        <?php 
        // Egyszerűsített lapozó linkek generálása (pl. max 5 linket mutatunk)
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $currentPage + 2);

        if ($startPage > 1) {
            echo '<a href="?page=1" class="btn btn-secondary btn-small">1</a>';
            if ($startPage > 2) {
                echo '<span class="pagination-dots">...</span>';
            }
        }

        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <?php if ($i == $currentPage): ?>
                <span class="btn btn-primary btn-small current-page"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="?page=<?php echo $i; ?>" class="btn btn-secondary btn-small"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php
        if ($endPage < $totalPages) {
            if ($endPage < $totalPages - 1) {
                echo '<span class="pagination-dots">...</span>';
            }
            echo '<a href="?page='.$totalPages.'" class="btn btn-secondary btn-small">'.$totalPages.'</a>';
        }
        ?>

        <?php if ($currentPage < $totalPages): ?>
            <a href="?page=<?php echo $currentPage + 1; ?>" class="btn btn-secondary btn-small">Következő »</a>
        <?php else: ?>
            <span class="btn btn-secondary btn-small disabled">Következő »</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<script>
    function copyToClipboard(inputElement) {
        inputElement.select();
        inputElement.setSelectionRange(0, 99999); // Mobil eszközökhöz
        try {
            document.execCommand('copy');
            // Opcionális: visszajelzés a felhasználónak
            alert('Pixel URL vágólapra másolva!');
        } catch (err) {
            alert('Hiba a másolás során. Kérlek, másold manuálisan.');
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>