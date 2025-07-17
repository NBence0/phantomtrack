<?php
// === 1. LÉPÉS: PHP LOGIKA FELDOLGOZÁSA (MINDEN HTML ELŐTT) ===
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();

// --- POST/GET MŰVELETEK KEZELÉSE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Érvénytelen kérés (CSRF token hiba).";
        $_SESSION['flash_message_type'] = "error";
    } else {
        if ($_POST['action'] === 'create_token') {
            $name = trim($_POST['token_name'] ?? '');
            $description = trim($_POST['token_description'] ?? '');
            $categoryId = !empty($_POST['token_category_id']) ? (int)$_POST['token_category_id'] : null;

            if (empty($name)) {
                $_SESSION['flash_message'] = "A token neve nem lehet üres.";
                $_SESSION['flash_message_type'] = "warning";
            } else {
                $newTokenValue = generateUniqueToken();
                $stmt = $db->prepare("INSERT INTO tokens (user_id, token_value, name, description, category_id, is_active, created_at) VALUES (:user_id, :token_value, :name, :description, :category_id, 1, NOW())");
                $params = [
                    ':user_id' => $currentUserId,
                    ':token_value' => $newTokenValue,
                    ':name' => $name,
                    ':description' => $description,
                    ':category_id' => $categoryId
                ];
                if ($stmt->execute($params)) {
                    $_SESSION['flash_message'] = "Token sikeresen létrehozva.";
                    $_SESSION['flash_message_type'] = "success";
                } else {
                    $_SESSION['flash_message'] = "Hiba a token létrehozásakor.";
                    $_SESSION['flash_message_type'] = "error";
                }
            }
        }
    }
    $redirectUrl = BASE_URL . 'tracker/tokens.php';
    if (isset($_GET['category_id'])) {
        $redirectUrl .= '?category_id=' . (int)$_GET['category_id'];
    }
    header('Location: ' . $redirectUrl);
    exit;
}

if (isset($_GET['action'])) {
    // ... a GET alapú műveletek (törlés, státuszváltás) logikája itt van ...
    // Példa: státuszváltás
    $tokenId = (int)($_GET['id'] ?? 0);
    if ($_GET['action'] === 'toggle_status' && $tokenId > 0) {
        $stmt = $db->prepare("UPDATE tokens SET is_active = NOT is_active WHERE id = :id AND user_id = :user_id");
        $stmt->execute([':id' => $tokenId, ':user_id' => $currentUserId]);
        $_SESSION['flash_message'] = "Token státusza frissítve.";
        $_SESSION['flash_message_type'] = "success";
        
        $redirectUrl = BASE_URL . 'tracker/tokens.php';
        if (isset($_GET['category_id'])) {
            $redirectUrl .= '?category_id=' . (int)$_GET['category_id'];
        } elseif (isset($_GET['page'])) {
            $redirectUrl .= '?page=' . (int)$_GET['page'];
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// ITT KELL KIEGÉSZÍTENI
if (isset($_GET['action'])) {
    // --- CSRF VÉDELEM GET ALAPÚ MŰVELETEKRE ---
    // Szigorúbb biztonságért a GET alapú állapotváltoztató műveleteket is védeni kellene.
    // Most az egyszerűség kedvéért elhagyjuk, de éles rendszerben ezt is meg kell oldani
    // pl. egy token hozzáadásával a linkhez és annak ellenőrzésével.

    $tokenId = (int)($_GET['id'] ?? 0);

    // Státuszváltás
    if ($_GET['action'] === 'toggle_status' && $tokenId > 0) {
        $stmt = $db->prepare("UPDATE tokens SET is_active = NOT is_active WHERE id = :id AND user_id = :user_id");
        if ($stmt->execute([':id' => $tokenId, ':user_id' => $currentUserId])) {
             $_SESSION['flash_message'] = "Token státusza frissítve.";
             $_SESSION['flash_message_type'] = "success";
        }
        $redirectUrl = BASE_URL . 'tracker/tokens.php' . (isset($_GET['category_id']) ? '?category_id='.(int)$_GET['category_id'] : '');
        header('Location: ' . $redirectUrl);
        exit;
    }


    // *** ÚJ BLOKK: TOKEN TÖRLÉSE ***
    if ($_GET['action'] === 'delete_token' && $tokenId > 0) {
        // 1. Ellenőrizzük, hogy a token a felhasználóé-e
        $checkStmt = $db->prepare("SELECT id FROM tokens WHERE id = :id AND user_id = :user_id");
        $checkStmt->execute([':id' => $tokenId, ':user_id' => $currentUserId]);
        
        if ($checkStmt->fetch()) {
            // 2. Töröljük a tokent. Az ON DELETE CASCADE miatt a kapcsolódó naplók is törlődni fognak.
            // (Ellenőrizzük a `database.sql`-t, hogy a foreign key tényleg CASCADE-ra van-e állítva!)
            // Igen, a beküldött `database.sql` alapján ez így van:
            // ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`token_id`) REFERENCES `tokens` (`id`) ON DELETE CASCADE;
            $deleteStmt = $db->prepare("DELETE FROM tokens WHERE id = :id AND user_id = :user_id");
            if ($deleteStmt->execute([':id' => $tokenId, ':user_id' => $currentUserId])) {
                $_SESSION['flash_message'] = "A token és a hozzá tartozó összes naplóbejegyzés sikeresen törölve.";
                $_SESSION['flash_message_type'] = "success";
            } else {
                 $_SESSION['flash_message'] = "Hiba történt a törlés során.";
                 $_SESSION['flash_message_type'] = "error";
            }
        } else {
            $_SESSION['flash_message'] = "Nincs jogosultságod a token törléséhez, vagy az nem létezik.";
            $_SESSION['flash_message_type'] = "error";
        }
        
        // 3. Átirányítás a token lista oldalra
        $redirectUrl = BASE_URL . 'tracker/tokens.php' . (isset($_GET['category_id']) ? '?category_id='.(int)$_GET['category_id'] : '');
        header('Location: ' . $redirectUrl);
        exit;
    }
}
// === 2. LÉPÉS: ADATOK LEKÉRDEZÉSE A MEGJELENÍTÉSHEZ ===
$pageTitle = "Token Menedzsment";

// Kategóriák lekérése a modális ablakhoz
$categoriesStmt = $db->prepare("SELECT id, name FROM token_categories WHERE user_id = :user_id ORDER BY name ASC");
$categoriesStmt->execute([':user_id' => $currentUserId]);
$availableCategories = $categoriesStmt->fetchAll();

// --- SZŰRÉSI ÉS LAPOZÁSI LOGIKA KIEGÉSZÍTÉSE ---
$filterCategoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

// Az SQL feltétel és a paraméterek dinamikus összeállítása
$sqlWhereParts = ["t.user_id = :user_id"];
$queryParams = [':user_id' => $currentUserId];

if ($filterCategoryId !== null) {
    $sqlWhereParts[] = "t.category_id = :category_id";
    $queryParams[':category_id'] = $filterCategoryId;
    // Címsor frissítése a kategória nevével
    // Ehhez le kell kérdeznünk a kategória nevét az ID alapján
    $catNameStmt = $db->prepare("SELECT name FROM token_categories WHERE id = :id AND user_id = :user_id");
    $catNameStmt->execute([':id' => $filterCategoryId, ':user_id' => $currentUserId]);
    $categoryName = $catNameStmt->fetchColumn();
    if ($categoryName) {
        $pageTitle .= " (" . escape($categoryName) . ")";
    }
}

$sqlWhere = "WHERE " . implode(" AND ", $sqlWhereParts);

// ... (A lapozás és a lekérdezés többi része ugyanígy használja az $sqlWhere-t és a $queryParams-ot, ahogy a régi kód is)
// FONTOS: a `totalTokensStmt` és a `tokens` lekérdező `stmt` is az új, dinamikus $queryParams tömbbel kell, hogy fusson.
$totalTokensStmt = $db->prepare("SELECT COUNT(t.id) FROM tokens t " . $sqlWhere);
$totalTokensStmt->execute($queryParams);
$totalTokens = $totalTokensStmt->fetchColumn();

// Lapozási változók
$itemsPerPage = (int)getAppSetting('items_per_page', 10);
$totalPages = $totalTokens > 0 ? ceil($totalTokens / $itemsPerPage) : 1;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
if ($currentPage > $totalPages) $currentPage = $totalPages;
$offset = ($currentPage - 1) * $itemsPerPage;

// Végleges lekérdezés lapozással
$sqlQuery = "SELECT 
                t.id, t.token_value, t.name, t.description, t.is_active, t.created_at, 
                t.category_id AS token_category_link_id,  -- Ezt használjuk a linkhez, a neve egyedi
                tc.name as category_name 
             FROM 
                tokens t 
             LEFT JOIN 
                token_categories tc ON t.category_id = tc.id 
             " . $sqlWhere . " 
             ORDER BY 
                t.created_at DESC 
             LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sqlQuery);

foreach ($queryParams as $paramName => &$paramValue) {
    $stmt->bindParam($paramName, $paramValue, is_int($paramValue) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$stmt->bindParam(':limit', $itemsPerPage, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$tokens = $stmt->fetchAll();


// === 3. LÉPÉS: HTML MEGJELENÍTÉS KEZDETE ===
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-tags"></i> <?php echo escape($pageTitle); ?></h1>
    <button class="btn btn-primary" onclick="document.getElementById('addTokenModal').style.display='block'"><i class="fas fa-plus"></i> Új Token</button>
</div>

<?php 
// A modális ablak behívása a külön fájlból
include __DIR__ . '/../includes/tokens_modals.php'; 
?>

<div class="table-container glass-effect">
    <table>
        <thead>
            <tr>
                <th>Név (Kategória)</th>
                <th>Pixel URL</th>
                <th>Leírás</th>
                <th>Státusz</th>
                <th>Létrehozva</th>
                <th style="text-align:right;">Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tokens)): ?>
                <tr><td colspan="6" style="text-align:center;">Nincsenek tokenek ebben a nézetben.</td></tr>
            <?php else: ?>
                <?php foreach ($tokens as $token): ?>
                <tr>
                    <td data-label="Név (Kategória)">
                        <a href="<?php echo BASE_URL . 'tracker/token_details.php?id=' . $token['id']; ?>">
                            <?php echo escape($token['name']); ?>
                        </a>

                        <?php 
                        // JAVÍTÁS:
                        // Csak azt ellenőrizzük, hogy a `category_name` nem üres-e.
                        // Mivel a `LEFT JOIN` NULL-t ad vissza, ha nincs egyezés, ez a tökéletes feltétel.
                        if (isset($token['category_name']) && $token['category_name'] !== null): 
                        ?>
                            <a href="?category_id=<?php echo (int)$token['token_category_link_id']; ?>" 
                            class="category-tag" 
                            title="Szűrés erre a kategóriára: <?php echo escape($token['category_name']); ?>">
                                <i class="fas fa-tag"></i> <?php echo escape($token['category_name']); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td data-label="Pixel URL" class="token-value-cell">
                        <input type="text" value="<?php echo BASE_URL . 'pixel.php?token=' . escape($token['token_value']); ?>" readonly class="pixel-url-input">
                        <button class="btn btn-small btn-copy" onclick="copyToClipboard(this.previousElementSibling)"><i class="far fa-copy"></i></button>
                    </td>
                    <td data-label="Leírás">
                        <?php echo nl2br(escape(substr($token['description'], 0, 50) . (strlen($token['description']) > 50 ? '...' : ''))); ?>
                    </td>
                    <td data-label="Státusz">
                        <?php if ($token['is_active']): ?>
                            <span class="status-active"><i class="fas fa-check-circle"></i> Aktív</span>
                        <?php else: ?>
                            <span class="status-inactive"><i class="fas fa-times-circle"></i> Inaktív</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Létrehozva">
                        <?php echo escape(formatTimestamp($token['created_at'])); ?>
                    </td>
                    <td data-label="Műveletek">
                        <div class="action-buttons">
                            <a href="<?php echo BASE_URL . 'tracker/token_details.php?id=' . $token['id']; ?>" class="btn btn-small btn-info" title="Részletek"><i class="fas fa-eye"></i></a>
                            <a href="<?php echo BASE_URL . 'tracker/tokens.php?action=toggle_status&id=' . $token['id']; ?>" class="btn btn-small <?php echo $token['is_active'] ? 'btn-warning' : 'btn-success'; ?>" title="<?php echo $token['is_active'] ? 'Deaktiválás' : 'Aktiválás'; ?>">
                                <i class="fas <?php echo $token['is_active'] ? 'fa-toggle-off' : 'fa-toggle-on'; ?>"></i>
                            </a>
                            <a href="<?php echo BASE_URL . 'tracker/tokens.php?action=delete_token&id=' . $token['id']; ?>" class="btn btn-small btn-danger" onclick="return confirm('Biztosan törölni szeretnéd ezt a tokent és minden kapcsolódó adatot?');" title="Törlés">
                                <i class="fas fa-trash-alt"></i>
                            </a>
                            <button onclick="openEditTokenModal(<?php echo $token['id']; ?>)" class="btn btn-small btn-secondary" title="Szerkesztés"><i class="fas fa-edit"></i></button>
                            <button onclick="openGetCodeModal('<?php echo escape($token['token_value']); ?>')" class="btn btn-small btn-info" title="Kód Snippetek"><i class="fas fa-code"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
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
</div>


<script>
    const PageConfig = { // <- Átnevezve!
        ajaxUrl: '<?php echo BASE_URL . "tracker/ajax_actions.php"; ?>',
        allUserCategories: <?php echo json_encode($availableCategories); ?>,
        baseUrl: '<?php echo BASE_URL; ?>',
    };
</script>
<script src="<?php echo BASE_URL . 'assets/js/tokens.js'; ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>