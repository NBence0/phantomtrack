<?php
// === Fájl: tracker/user_manager.php ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
// TableHelper-t itt NEM hívjuk be közvetlenül, mert egyedi logikát írunk a prefixek miatt

requireAdmin();

$db = getDB();
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// User adatainak lekérése
$userStmt = $db->prepare("SELECT * FROM users WHERE id = :id");
$userStmt->execute([':id' => $userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: ' . BASE_URL . 'tracker/users.php');
    exit;
}

$targetsStmt = $db->prepare("SELECT id, username FROM users WHERE id != :id ORDER BY username ASC");
$targetsStmt->execute([':id' => $userId]);
$targetUsers = $targetsStmt->fetchAll(PDO::FETCH_ASSOC);

$itemsPerPage = (int)getAppSetting('items_per_page', 20);
if ($itemsPerPage < 5) $itemsPerPage = 20;

// === 1. TOKENEK ===
$pageTokens = isset($_GET['page_tokens']) ? max(1, (int)$_GET['page_tokens']) : 1;
$offsetTokens = ($pageTokens - 1) * $itemsPerPage;
$sortTokens = $_GET['sort_tokens'] ?? 'created_at';
$dirTokens = $_GET['dir_tokens'] ?? 'desc'; // Alap: DESC
$allowedSortTokens = ['name', 'is_active', 'log_count', 'created_at'];
if (!in_array($sortTokens, $allowedSortTokens)) $sortTokens = 'created_at';
if (!in_array($dirTokens, ['asc', 'desc'])) $dirTokens = 'desc';
$sqlSortTokens = ($sortTokens === 'log_count') ? 'log_count' : "t.$sortTokens";

$totalTokens = $db->prepare("SELECT COUNT(*) FROM tokens WHERE user_id = :id");
$totalTokens->execute([':id' => $userId]);
$totalTokensCount = $totalTokens->fetchColumn();
$totalPagesTokens = ceil($totalTokensCount / $itemsPerPage);

$tokensSql = "SELECT t.*, (SELECT COUNT(*) FROM activity_logs WHERE token_id = t.id) as log_count 
              FROM tokens t 
              WHERE t.user_id = :id 
              ORDER BY $sqlSortTokens " . strtoupper($dirTokens) . " 
              LIMIT :limit OFFSET :offset";
$tokens = $db->prepare($tokensSql);
$tokens->bindValue(':id', $userId, PDO::PARAM_INT);
$tokens->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$tokens->bindValue(':offset', $offsetTokens, PDO::PARAM_INT);
$tokens->execute();
$tokensList = $tokens->fetchAll();

// === 2. GALÉRIÁK ===
$pageGalleries = isset($_GET['page_galleries']) ? max(1, (int)$_GET['page_galleries']) : 1;
$offsetGalleries = ($pageGalleries - 1) * $itemsPerPage;
$sortGalleries = $_GET['sort_galleries'] ?? 'created_at';
$dirGalleries = $_GET['dir_galleries'] ?? 'desc';
$allowedSortGalleries = ['name', 'visibility', 'image_count', 'total_size', 'created_at'];
if (!in_array($sortGalleries, $allowedSortGalleries)) $sortGalleries = 'created_at';
if (!in_array($dirGalleries, ['asc', 'desc'])) $dirGalleries = 'desc';
$sqlSortGalleries = ($sortGalleries === 'image_count' || $sortGalleries === 'total_size') ? $sortGalleries : "g.$sortGalleries";

$totalGalleries = $db->prepare("SELECT COUNT(*) FROM galleries WHERE user_id = :id");
$totalGalleries->execute([':id' => $userId]);
$totalGalleriesCount = $totalGalleries->fetchColumn();
$totalPagesGalleries = ceil($totalGalleriesCount / $itemsPerPage);

$galleriesSql = "SELECT g.*, COUNT(f.id) as image_count, COALESCE(SUM(f.file_size), 0) as total_size 
                 FROM galleries g 
                 LEFT JOIN files f ON g.id = f.gallery_id 
                 WHERE g.user_id = :id 
                 GROUP BY g.id 
                 ORDER BY $sqlSortGalleries " . strtoupper($dirGalleries) . " 
                 LIMIT :limit OFFSET :offset";
$galleries = $db->prepare($galleriesSql);
$galleries->bindValue(':id', $userId, PDO::PARAM_INT);
$galleries->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$galleries->bindValue(':offset', $offsetGalleries, PDO::PARAM_INT);
$galleries->execute();
$galleriesList = $galleries->fetchAll();

// === 3. FÁJLOK ===
$pageFiles = isset($_GET['page_files']) ? max(1, (int)$_GET['page_files']) : 1;
$offsetFiles = ($pageFiles - 1) * $itemsPerPage;
$sortFiles = $_GET['sort_files'] ?? 'upload_timestamp';
$dirFiles = $_GET['dir_files'] ?? 'desc';
$allowedSortFiles = ['original_filename', 'file_size', 'upload_timestamp'];
if (!in_array($sortFiles, $allowedSortFiles)) $sortFiles = 'upload_timestamp';
if (!in_array($dirFiles, ['asc', 'desc'])) $dirFiles = 'desc';

$totalFiles = $db->prepare("SELECT COUNT(*) FROM files WHERE user_id = :id");
$totalFiles->execute([':id' => $userId]);
$totalFilesCount = $totalFiles->fetchColumn();
$totalPagesFiles = ceil($totalFilesCount / $itemsPerPage);
$totalSizeStmt = $db->prepare("SELECT SUM(file_size) FROM files WHERE user_id = :id");
$totalSizeStmt->execute([':id' => $userId]);
$totalFileSize = $totalSizeStmt->fetchColumn() ?: 0;

$filesSql = "SELECT * FROM files WHERE user_id = :id ORDER BY $sortFiles " . strtoupper($dirFiles) . " LIMIT :limit OFFSET :offset";
$files = $db->prepare($filesSql);
$files->bindValue(':id', $userId, PDO::PARAM_INT);
$files->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$files->bindValue(':offset', $offsetFiles, PDO::PARAM_INT);
$files->execute();
$filesList = $files->fetchAll();

// === 4. KATEGÓRIÁK ===
$pageCats = isset($_GET['page_cats']) ? max(1, (int)$_GET['page_cats']) : 1;
$offsetCats = ($pageCats - 1) * $itemsPerPage;
$sortCats = $_GET['sort_cats'] ?? 'name';
$dirCats = $_GET['dir_cats'] ?? 'asc';
$allowedSortCats = ['name', 'token_count'];
if (!in_array($sortCats, $allowedSortCats)) $sortCats = 'name';
if (!in_array($dirCats, ['asc', 'desc'])) $dirCats = 'asc';
$sqlSortCats = ($sortCats === 'token_count') ? 'token_count' : "c.$sortCats";

$totalCats = $db->prepare("SELECT COUNT(*) FROM token_categories WHERE user_id = :id");
$totalCats->execute([':id' => $userId]);
$totalCatsCount = $totalCats->fetchColumn();
$totalPagesCats = ceil($totalCatsCount / $itemsPerPage);

$catsSql = "SELECT c.*, (SELECT COUNT(*) FROM tokens WHERE category_id = c.id) as token_count 
            FROM token_categories c 
            WHERE c.user_id = :id 
            ORDER BY $sqlSortCats " . strtoupper($dirCats) . " 
            LIMIT :limit OFFSET :offset";
$cats = $db->prepare($catsSql);
$cats->bindValue(':id', $userId, PDO::PARAM_INT);
$cats->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$cats->bindValue(':offset', $offsetCats, PDO::PARAM_INT);
$cats->execute();
$categoriesList = $cats->fetchAll();


// === AKTÍV TAB MEGHATÁROZÁSA ===
$activeTab = $_GET['active_tab'] ?? 'tokens';
if (!isset($_GET['active_tab'])) {
    if (isset($_GET['page_galleries']) || isset($_GET['sort_galleries'])) $activeTab = 'galleries';
    elseif (isset($_GET['page_files']) || isset($_GET['sort_files'])) $activeTab = 'files';
    elseif (isset($_GET['page_cats']) || isset($_GET['sort_cats'])) $activeTab = 'categories';
}

$pageTitle = "Erőforrás Menedzser: " . $user['username'];
require_once __DIR__ . '/../includes/header.php';

// === FÜGGVÉNYEK ===

function renderPagination($current, $total, $pageParam, $activeTabName) {
    if ($total <= 1) return '';
    $html = '<div class="pagination glass-effect" style="margin-top:15px; justify-content:center;">';
    
    // Prefix kinyerése (pl. "page_tokens" -> "tokens")
    $prefix = str_replace('page_', '', $pageParam);

    // Tiszta paraméterlista összeállítása: Csak ID, Active Tab, és az aktuális fül rendezése
    $params = [
        'id' => $_GET['id'] ?? 0,
        'active_tab' => $activeTabName
    ];

    // Ha van rendezés beállítva ehhez a fülhöz, azt megőrizzük
    if (isset($_GET['sort_' . $prefix])) $params['sort_' . $prefix] = $_GET['sort_' . $prefix];
    if (isset($_GET['dir_' . $prefix])) $params['dir_' . $prefix] = $_GET['dir_' . $prefix];
    
    // Base URL generálása
    $baseUrl = '?' . http_build_query($params) . '&' . $pageParam . '=';
    
    if ($current > 1) $html .= '<a href="' . $baseUrl . ($current - 1) . '" class="btn btn-secondary btn-small">«</a>';
    
    $start = max(1, $current - 2);
    $end = min($total, $current + 2);
    
    if ($start > 1) {
        $html .= '<a href="' . $baseUrl . '1" class="btn btn-secondary btn-small">1</a>';
        if ($start > 2) $html .= '<span class="pagination-dots">...</span>';
    }
    
    for ($i = $start; $i <= $end; $i++) {
        $active = ($i == $current) ? 'btn-primary' : 'btn-secondary';
        $html .= '<a href="' . $baseUrl . $i . '" class="btn ' . $active . ' btn-small">' . $i . '</a>';
    }
    
    if ($end < $total) {
        if ($end < $total - 1) $html .= '<span class="pagination-dots">...</span>';
        $html .= '<a href="' . $baseUrl . $total . '" class="btn btn-secondary btn-small">' . $total . '</a>';
    }
    
    if ($current < $total) $html .= '<a href="' . $baseUrl . ($current + 1) . '" class="btn btn-secondary btn-small">»</a>';
    
    $html .= '</div>';
    return $html;
}

/**
 * Fontos: NEM használjuk a 'sortable' osztályt, hogy ne akadjon össze a globális JS-el!
 * Helyette 'custom-sort' osztályt használunk.
 */
function renderCustomTh($label, $columnKey, $prefix, $currentSort, $currentDir, $width = '', $align = 'left') {
    $isActive = ($currentSort === $columnKey);
    // Beállítjuk az active-asc/desc osztályt, hogy a JS tudja, mi a jelenlegi irány
    $activeClass = $isActive ? 'active-' . $currentDir : '';
    
    $icon = '<i class="fas fa-sort text-muted" style="opacity:0.3;"></i>';
    if ($isActive) {
        $icon = ($currentDir === 'asc') 
            ? '<i class="fas fa-sort-up"></i>' 
            : '<i class="fas fa-sort-down"></i>';
    }

    $style = 'cursor:pointer; user-select:none;'; // Kézzel adjuk meg a cursort
    if ($width) $style .= "width:{$width}px;";
    if ($align) $style .= "text-align:{$align};";

    echo '<th class="smart-th custom-sort ' . $activeClass . '" 
              data-column="' . $columnKey . '" 
              data-prefix="' . $prefix . '" 
              onclick="handleSort(this)" 
              style="' . $style . '">';
    echo '<div class="th-content" style="display:flex; align-items:center; justify-content:'. ($align == 'right' ? 'flex-end' : ($align == 'center' ? 'center' : 'flex-start')) .'; gap:5px;">';
    echo '<span>' . htmlspecialchars($label) . '</span>';
    echo '<span class="sort-icon">' . $icon . '</span>';
    echo '</div>';
    echo '</th>';
}
?>

<style>
    .manager-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--glass-border); padding-bottom: 10px; overflow-x: auto; }
    .tab-btn { background: transparent; border: none; color: var(--text-secondary); padding: 10px 20px; cursor: pointer; font-size: 1.1em; border-bottom: 2px solid transparent; transition: all 0.3s; white-space: nowrap; }
    .tab-btn.active { color: var(--accent-primary); border-bottom-color: var(--accent-primary); font-weight: bold; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    .bulk-actions { background: rgba(0,0,0,0.3); padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; position: relative; z-index: 100; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
    .badge-active { background: rgba(46, 213, 115, 0.2); color: #2ed573; border: 1px solid #2ed573; }
    .badge-inactive { background: rgba(255, 71, 87, 0.2); color: #ff4757; border: 1px solid #ff4757; }
    .badge-public { background: rgba(46, 213, 115, 0.2); color: #2ed573; }
    .badge-private { background: rgba(255, 202, 40, 0.2); color: #ffca28; }
    .badge-password { background: rgba(255, 71, 87, 0.2); color: #ff4757; }
    
    .item-check { transform: scale(1.2); cursor: pointer; }
    /* Hover effektus a custom headerre */
    .smart-th.custom-sort:hover { background: rgba(255,255,255,0.05); }
</style>

<div class="content-header">
    <h1><i class="fas fa-tools"></i> <?php echo escape($pageTitle); ?></h1>
    <a href="<?php echo BASE_URL; ?>tracker/users.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Vissza</a>
</div>

<!-- MŰVELETI ZÓNA -->
<div class="bulk-actions glass-effect">
    <strong style="color:var(--text-secondary);">Műveletek:</strong>
    <div class="custom-select-wrapper" style="width: 250px; display:inline-block; vertical-align:middle;">
        <select id="targetUserId" style="display:none;">
            <option value="">-- Célfelhasználó --</option>
            <?php foreach ($targetUsers as $target): ?>
                <option value="<?php echo $target['id']; ?>"><?php echo escape($target['username']); ?></option>
            <?php endforeach; ?>
        </select>
        <div class="select-trigger" style="background:rgba(0,0,0,0.3); border:1px solid var(--glass-border);">-- Célfelhasználó --</div>
        <div class="custom-options">
            <?php foreach ($targetUsers as $target): ?>
                <span class="custom-option" data-value="<?php echo $target['id']; ?>">
                    <i class="fas fa-user"></i> <?php echo escape($target['username']); ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <button class="btn btn-info" onclick="executeAction('move')"><i class="fas fa-share"></i> Áthelyezés</button>
    <button class="btn btn-primary" onclick="executeAction('copy')"><i class="fas fa-copy"></i> Másolás</button>
    <button class="btn btn-danger" onclick="executeAction('delete')"><i class="fas fa-trash"></i> Törlés</button>
    <div style="margin-left: auto; font-size: 0.9em; color: #888;" id="selectionCount">0 elem kijelölve</div>
</div>

<!-- TARTALOM -->
<div class="glass-effect" style="padding: 20px;">
    
    <!-- TABOK -->
    <div class="manager-tabs">
        <button class="tab-btn <?php echo $activeTab == 'tokens' ? 'active' : ''; ?>" onclick="switchTab('tokens')">Tokenek (<?php echo $totalTokensCount; ?>)</button>
        <button class="tab-btn <?php echo $activeTab == 'galleries' ? 'active' : ''; ?>" onclick="switchTab('galleries')">Galériák (<?php echo $totalGalleriesCount; ?>)</button>
        <button class="tab-btn <?php echo $activeTab == 'files' ? 'active' : ''; ?>" onclick="switchTab('files')">Fájlok (<?php echo $totalFilesCount; ?>)</button>
        <button class="tab-btn <?php echo $activeTab == 'categories' ? 'active' : ''; ?>" onclick="switchTab('categories')">Kategóriák (<?php echo $totalCatsCount; ?>)</button>
    </div>

    <!-- 1. TOKENEK TÁBLÁZAT -->
    <div id="tab-tokens" class="tab-content <?php echo $activeTab == 'tokens' ? 'active' : ''; ?>">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" class="item-check" onchange="toggleSelectAll(this, 'token')"></th>
                        <?php 
                        renderCustomTh('Név / Token', 'name', 'tokens', $sortTokens, $dirTokens);
                        renderCustomTh('Státusz', 'is_active', 'tokens', $sortTokens, $dirTokens, '120', 'center');
                        renderCustomTh('Logok', 'log_count', 'tokens', $sortTokens, $dirTokens, '120', 'center');
                        renderCustomTh('Létrehozva', 'created_at', 'tokens', $sortTokens, $dirTokens, '150', 'right');
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokensList as $t): ?>
                        <tr>
                            <td><input type="checkbox" class="item-check select-item select-token" value="<?php echo $t['id']; ?>"></td>
                            <td>
                                <strong><?php echo escape($t['name']); ?></strong><br>
                                <small class="text-muted" style="font-family:monospace;"><?php echo escape($t['token_value']); ?></small>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge <?php echo $t['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo $t['is_active'] ? 'Aktív' : 'Inaktív'; ?>
                                </span>
                            </td>
                            <td style="text-align:center; font-weight:bold;"><?php echo $t['log_count']; ?></td>
                            <td style="text-align:right;"><?php echo formatTimestamp($t['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php echo renderPagination($pageTokens, $totalPagesTokens, 'page_tokens', 'tokens'); ?>
    </div>

    <!-- 2. GALÉRIÁK TÁBLÁZAT -->
    <div id="tab-galleries" class="tab-content <?php echo $activeTab == 'galleries' ? 'active' : ''; ?>">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" class="item-check" onchange="toggleSelectAll(this, 'gallery')"></th>
                        <?php 
                        renderCustomTh('Név / Leírás', 'name', 'galleries', $sortGalleries, $dirGalleries);
                        renderCustomTh('Láthatóság', 'visibility', 'galleries', $sortGalleries, $dirGalleries, '120', 'center');
                        renderCustomTh('Tartalom', 'image_count', 'galleries', $sortGalleries, $dirGalleries, '150', 'center');
                        renderCustomTh('Létrehozva', 'created_at', 'galleries', $sortGalleries, $dirGalleries, '150', 'right');
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($galleriesList as $g): ?>
                        <tr>
                            <td><input type="checkbox" class="item-check select-item select-gallery" value="<?php echo $g['id']; ?>"></td>
                            <td>
                                <strong><?php echo escape($g['name']); ?></strong><br>
                                <small class="text-muted"><?php echo escape(mb_strimwidth($g['description'], 0, 50, '...')); ?></small>
                            </td>
                            <td style="text-align:center;">
                                <?php 
                                    $vis = $g['visibility'];
                                    $class = $vis == 'public' ? 'badge-public' : ($vis == 'password' ? 'badge-password' : 'badge-private');
                                    $icon = $vis == 'public' ? 'globe' : ($vis == 'password' ? 'lock' : 'eye-slash');
                                ?>
                                <span class="badge <?php echo $class; ?>"><i class="fas fa-<?php echo $icon; ?>"></i> <?php echo ucfirst($vis); ?></span>
                            </td>
                            <td style="text-align:center;">
                                <?php echo $g['image_count']; ?> kép<br>
                                <small class="text-muted"><?php echo formatBytes($g['total_size']); ?></small>
                            </td>
                            <td style="text-align:right;"><?php echo formatTimestamp($g['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php echo renderPagination($pageGalleries, $totalPagesGalleries, 'page_galleries', 'galleries'); ?>
    </div>

    <!-- 3. FÁJLOK TÁBLÁZAT -->
    <div id="tab-files" class="tab-content <?php echo $activeTab == 'files' ? 'active' : ''; ?>">
        <div style="padding:10px; margin-bottom:10px; background:rgba(255,255,255,0.05); border-radius:5px; text-align:right;">
            Összes méret: <strong><?php echo formatBytes($totalFileSize); ?></strong>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" class="item-check" onchange="toggleSelectAll(this, 'file')"></th>
                        <?php 
                        renderCustomTh('Fájlnév', 'original_filename', 'files', $sortFiles, $dirFiles);
                        renderCustomTh('Méret', 'file_size', 'files', $sortFiles, $dirFiles, '120', 'right');
                        renderCustomTh('Feltöltve', 'upload_timestamp', 'files', $sortFiles, $dirFiles, '150', 'right');
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filesList as $f): ?>
                        <tr>
                            <td><input type="checkbox" class="item-check select-item select-file" value="<?php echo $f['id']; ?>"></td>
                            <td>
                                <a href="<?php echo BASE_URL . 'tracker/file_details.php?id=' . $f['id']; ?>" target="_blank" style="display:flex; align-items:center; gap:10px; text-decoration:none; color:var(--text-primary);">
                                    <span style="font-size:1.2em;"><?php echo getFileIcon($f['mime_type'], $f['original_filename']); ?></span>
                                    <span title="<?php echo escape($f['original_filename']); ?>">
                                        <?php echo escape(mb_strimwidth($f['original_filename'], 0, 50, "...")); ?>
                                    </span>
                                </a>
                            </td>
                            <td style="text-align:right; font-family:monospace;"><?php echo formatBytes($f['file_size']); ?></td>
                            <td style="text-align:right;"><?php echo formatTimestamp($f['upload_timestamp']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php echo renderPagination($pageFiles, $totalPagesFiles, 'page_files', 'files'); ?>
    </div>

    <!-- 4. KATEGÓRIÁK TÁBLÁZAT -->
    <div id="tab-categories" class="tab-content <?php echo $activeTab == 'categories' ? 'active' : ''; ?>">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th width="40"><input type="checkbox" class="item-check" onchange="toggleSelectAll(this, 'category')"></th>
                        <?php 
                        renderCustomTh('Név', 'name', 'cats', $sortCats, $dirCats);
                        renderCustomTh('Elemek száma', 'token_count', 'cats', $sortCats, $dirCats, '150', 'right');
                        ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categoriesList as $c): ?>
                        <tr>
                            <td><input type="checkbox" class="item-check select-item select-category" value="<?php echo $c['id']; ?>"></td>
                            <td><strong><?php echo escape($c['name']); ?></strong></td>
                            <td style="text-align:right;"><?php echo $c['token_count']; ?> db</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php echo renderPagination($pageCats, $totalPagesCats, 'page_cats', 'categories'); ?>
    </div>

</div>

<script>
    const AJAX_MANAGER_URL = '<?php echo BASE_URL; ?>tracker/ajax_manager.php';
    const CSRF_TOKEN = '<?php echo generateCsrfToken(); ?>';
    const SOURCE_USER_ID = <?php echo $userId; ?>;

function handleSort(th) {
        const column = th.getAttribute('data-column');
        const prefix = th.getAttribute('data-prefix'); // tokens, galleries, files, cats
        
        let newDir = 'desc'; 
        if (th.classList.contains('active-desc')) {
            newDir = 'asc';
        } else if (th.classList.contains('active-asc')) {
            newDir = 'desc';
        }

        // Tab név meghatározása
        let tabName = prefix;
        if(prefix === 'cats') tabName = 'categories';

        // URL felépítése a nulláról (hogy töröljük a szemetet)
        const currentUrl = new URL(window.location.href);
        const userId = currentUrl.searchParams.get('id'); // ID-t megőrizzük

        // Új URL objektum tiszta lappal
        const newUrl = new URL(window.location.protocol + '//' + window.location.host + window.location.pathname);
        
        // Csak a szükséges paraméterek hozzáadása
        newUrl.searchParams.set('id', userId);
        newUrl.searchParams.set('active_tab', tabName);
        newUrl.searchParams.set('sort_' + prefix, column);
        newUrl.searchParams.set('dir_' + prefix, newDir);
        newUrl.searchParams.set('page_' + prefix, 1); // Rendezéskor vissza az 1. oldalra

        window.location.href = newUrl.toString();
    }
</script>
<script src="<?php echo BASE_URL; ?>assets/js/user_manager.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>