<?php
// === Fájl: tracker/user_manager.php (Lapozással) ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

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

// Célpontok listája
$targetsStmt = $db->prepare("SELECT id, username FROM users WHERE id != :id ORDER BY username ASC");
$targetsStmt->execute([':id' => $userId]);
$targetUsers = $targetsStmt->fetchAll(PDO::FETCH_ASSOC);

// --- LAPOZÁS KONFIGURÁCIÓ ---
$itemsPerPage = (int)getAppSetting('items_per_page', 20);
if ($itemsPerPage < 5) $itemsPerPage = 20; // Biztonsági minimum

// Tokenek
$pageTokens = isset($_GET['page_tokens']) ? max(1, (int)$_GET['page_tokens']) : 1;
$offsetTokens = ($pageTokens - 1) * $itemsPerPage;
$totalTokens = $db->prepare("SELECT COUNT(*) FROM tokens WHERE user_id = :id");
$totalTokens->execute([':id' => $userId]);
$totalTokensCount = $totalTokens->fetchColumn();
$totalPagesTokens = ceil($totalTokensCount / $itemsPerPage);

$tokens = $db->prepare("SELECT t.*, (SELECT COUNT(*) FROM activity_logs WHERE token_id = t.id) as log_count FROM tokens t WHERE t.user_id = :id ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset");
$tokens->bindValue(':id', $userId, PDO::PARAM_INT);
$tokens->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$tokens->bindValue(':offset', $offsetTokens, PDO::PARAM_INT);
$tokens->execute();
$tokensList = $tokens->fetchAll();

// Galériák
$pageGalleries = isset($_GET['page_galleries']) ? max(1, (int)$_GET['page_galleries']) : 1;
$offsetGalleries = ($pageGalleries - 1) * $itemsPerPage;
$totalGalleries = $db->prepare("SELECT COUNT(*) FROM galleries WHERE user_id = :id");
$totalGalleries->execute([':id' => $userId]);
$totalGalleriesCount = $totalGalleries->fetchColumn();
$totalPagesGalleries = ceil($totalGalleriesCount / $itemsPerPage);

$galleries = $db->prepare("SELECT g.*, COUNT(f.id) as image_count, COALESCE(SUM(f.file_size), 0) as total_size FROM galleries g LEFT JOIN files f ON g.id = f.gallery_id WHERE g.user_id = :id GROUP BY g.id ORDER BY g.created_at DESC LIMIT :limit OFFSET :offset");
$galleries->bindValue(':id', $userId, PDO::PARAM_INT);
$galleries->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$galleries->bindValue(':offset', $offsetGalleries, PDO::PARAM_INT);
$galleries->execute();
$galleriesList = $galleries->fetchAll();

// Fájlok
$pageFiles = isset($_GET['page_files']) ? max(1, (int)$_GET['page_files']) : 1;
$offsetFiles = ($pageFiles - 1) * $itemsPerPage;
$totalFiles = $db->prepare("SELECT COUNT(*) FROM files WHERE user_id = :id");
$totalFiles->execute([':id' => $userId]);
$totalFilesCount = $totalFiles->fetchColumn();
$totalPagesFiles = ceil($totalFilesCount / $itemsPerPage);

// Fájlok összmérete (ez marad globális, nem lapozott)
$totalSizeStmt = $db->prepare("SELECT SUM(file_size) FROM files WHERE user_id = :id");
$totalSizeStmt->execute([':id' => $userId]);
$totalFileSize = $totalSizeStmt->fetchColumn() ?: 0;

$files = $db->prepare("SELECT * FROM files WHERE user_id = :id ORDER BY upload_timestamp DESC LIMIT :limit OFFSET :offset");
$files->bindValue(':id', $userId, PDO::PARAM_INT);
$files->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$files->bindValue(':offset', $offsetFiles, PDO::PARAM_INT);
$files->execute();
$filesList = $files->fetchAll();

// Kategóriák
$pageCats = isset($_GET['page_cats']) ? max(1, (int)$_GET['page_cats']) : 1;
$offsetCats = ($pageCats - 1) * $itemsPerPage;
$totalCats = $db->prepare("SELECT COUNT(*) FROM token_categories WHERE user_id = :id");
$totalCats->execute([':id' => $userId]);
$totalCatsCount = $totalCats->fetchColumn();
$totalPagesCats = ceil($totalCatsCount / $itemsPerPage);

$cats = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM tokens WHERE category_id = c.id) as token_count FROM token_categories c WHERE c.user_id = :id ORDER BY c.name ASC LIMIT :limit OFFSET :offset");
$cats->bindValue(':id', $userId, PDO::PARAM_INT);
$cats->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$cats->bindValue(':offset', $offsetCats, PDO::PARAM_INT);
$cats->execute();
$categoriesList = $cats->fetchAll();

// Aktív tab meghatározása (hogy lapozás után ugyanott maradjunk)
$activeTab = 'tokens';
if (isset($_GET['page_galleries'])) $activeTab = 'galleries';
elseif (isset($_GET['page_files'])) $activeTab = 'files';
elseif (isset($_GET['page_cats'])) $activeTab = 'categories';

$pageTitle = "Erőforrás Menedzser: " . $user['username'];
require_once __DIR__ . '/../includes/header.php';

// Helper a lapozó linkekhez
function renderPagination($current, $total, $paramName) {
    if ($total <= 1) return '';
    $html = '<div class="pagination" style="margin-top:15px; justify-content:center;">';
    
    // URL paraméterek megőrzése
    $params = $_GET;
    unset($params[$paramName]); // Az épp aktuálisat kivesszük, hogy újat tegyünk be
    $baseUrl = '?' . http_build_query($params) . '&' . $paramName . '=';
    
    if ($current > 1) {
        $html .= '<a href="' . $baseUrl . ($current - 1) . '" class="btn btn-secondary btn-small">«</a>';
    }
    
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
    
    if ($current < $total) {
        $html .= '<a href="' . $baseUrl . ($current + 1) . '" class="btn btn-secondary btn-small">»</a>';
    }
    
    $html .= '</div>';
    return $html;
}
?>

<style>
    /* ... (CSS változatlan) ... */
    .manager-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--glass-border); padding-bottom: 10px; overflow-x: auto; }
    .tab-btn { background: transparent; border: none; color: var(--text-secondary); padding: 10px 20px; cursor: pointer; font-size: 1.1em; border-bottom: 2px solid transparent; transition: all 0.3s; white-space: nowrap; }
    .tab-btn.active { color: var(--accent-primary); border-bottom-color: var(--accent-primary); font-weight: bold; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    .item-row { display: flex; align-items: center; padding: 12px; border-bottom: 1px solid var(--glass-border); transition: background 0.2s; gap: 15px; }
    .item-row:hover { background: rgba(255,255,255,0.05); }
    .item-check { transform: scale(1.2); cursor: pointer; }
    
    .bulk-actions { background: rgba(0,0,0,0.3); padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; position: relative; z-index: 100; }
    
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
    .badge-active { background: rgba(46, 213, 115, 0.2); color: #2ed573; border: 1px solid #2ed573; }
    .badge-inactive { background: rgba(255, 71, 87, 0.2); color: #ff4757; border: 1px solid #ff4757; }
    .badge-public { background: rgba(46, 213, 115, 0.2); color: #2ed573; }
    .badge-private { background: rgba(255, 202, 40, 0.2); color: #ffca28; }
    .badge-password { background: rgba(255, 71, 87, 0.2); color: #ff4757; }

    .custom-select-wrapper { z-index: 1001; }
    .custom-options { z-index: 1002; }
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

    <!-- TOKENEK LISTA -->
    <div id="tab-tokens" class="tab-content <?php echo $activeTab == 'tokens' ? 'active' : ''; ?>">
        <div class="item-row header" style="font-weight:bold; color:var(--accent-secondary); border-bottom:2px solid var(--glass-border);">
            <input type="checkbox" class="item-check" onchange="toggleSelectAll(this, 'token')">
            <div style="flex:2;">Név / Token</div>
            <div style="width:120px; text-align:center;">Státusz</div>
            <div style="width:120px; text-align:center;">Megnyitás</div>
            <div style="width:150px; text-align:right;">Létrehozva</div>
        </div>
        <?php foreach ($tokensList as $t): ?>
            <div class="item-row">
                <input type="checkbox" class="item-check select-item select-token" value="<?php echo $t['id']; ?>">
                <div style="flex:2;">
                    <strong><?php echo escape($t['name']); ?></strong><br>
                    <small class="text-muted" style="font-family:monospace;"><?php echo escape($t['token_value']); ?></small>
                </div>
                <div style="width:120px; text-align:center;">
                    <?php if ($t['is_active']): ?>
                        <span class="badge badge-active">Aktív</span>
                    <?php else: ?>
                        <span class="badge badge-inactive">Inaktív</span>
                    <?php endif; ?>
                </div>
                <div style="width:120px; text-align:center; font-weight:bold;">
                    <?php echo $t['log_count']; ?>
                </div>
                <div style="width:150px; text-align:right;">
                    <?php echo formatTimestamp($t['created_at']); ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php echo renderPagination($pageTokens, $totalPagesTokens, 'page_tokens'); ?>
    </div>

    <!-- GALÉRIÁK LISTA -->
    <div id="tab-galleries" class="tab-content <?php echo $activeTab == 'galleries' ? 'active' : ''; ?>">
        <div class="item-row header" style="font-weight:bold; color:var(--accent-secondary); border-bottom:2px solid var(--glass-border);">
            <input type="checkbox" class="item-check" onchange="toggleSelectAll(this, 'gallery')">
            <div style="flex:2;">Név / Leírás</div>
            <div style="width:120px; text-align:center;">Láthatóság</div>
            <div style="width:150px; text-align:center;">Tartalom</div>
            <div style="width:150px; text-align:right;">Létrehozva</div>
        </div>
        <?php foreach ($galleriesList as $g): ?>
            <div class="item-row">
                <input type="checkbox" class="item-check select-item select-gallery" value="<?php echo $g['id']; ?>">
                <div style="flex:2;">
                    <strong><?php echo escape($g['name']); ?></strong><br>
                    <small class="text-muted"><?php echo escape(mb_strimwidth($g['description'], 0, 50, '...')); ?></small>
                </div>
                <div style="width:120px; text-align:center;">
                    <?php 
                        $vis = $g['visibility'];
                        $class = $vis == 'public' ? 'badge-public' : ($vis == 'password' ? 'badge-password' : 'badge-private');
                        $icon = $vis == 'public' ? 'globe' : ($vis == 'password' ? 'lock' : 'eye-slash');
                    ?>
                    <span class="badge <?php echo $class; ?>"><i class="fas fa-<?php echo $icon; ?>"></i> <?php echo ucfirst($vis); ?></span>
                </div>
                <div style="width:150px; text-align:center;">
                    <?php echo $g['image_count']; ?> kép<br>
                    <small class="text-muted"><?php echo formatBytes($g['total_size']); ?></small>
                </div>
                <div style="width:150px; text-align:right;">
                    <?php echo formatTimestamp($g['created_at']); ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php echo renderPagination($pageGalleries, $totalPagesGalleries, 'page_galleries'); ?>
    </div>

    <!-- FÁJLOK LISTA -->
    <div id="tab-files" class="tab-content <?php echo $activeTab == 'files' ? 'active' : ''; ?>">
        <div style="padding:10px; margin-bottom:10px; background:rgba(255,255,255,0.05); border-radius:5px; text-align:right;">
            Összes méret: <strong><?php echo formatBytes($totalFileSize); ?></strong>
        </div>
        <div class="item-row header" style="font-weight:bold; color:var(--accent-secondary); border-bottom:2px solid var(--glass-border);">
            <input type="checkbox" class="item-check" onchange="toggleSelectAll(this, 'file')">
            <div style="flex:2;">Fájlnév</div>
            <div style="width:120px; text-align:right;">Méret</div>
            <div style="width:150px; text-align:right;">Feltöltve</div>
        </div>
        <?php foreach ($filesList as $f): ?>
            <div class="item-row">
                <input type="checkbox" class="item-check select-item select-file" value="<?php echo $f['id']; ?>">
                <div style="flex:2;">
                    <a href="<?php echo BASE_URL . 'tracker/file_details.php?id=' . $f['id']; ?>" target="_blank" style="display:flex; align-items:center; gap:10px; text-decoration:none; color:var(--text-primary);">
                        <span style="font-size:1.2em;"><?php echo getFileIcon($f['mime_type'], $f['original_filename']); ?></span>
                        <span title="<?php echo escape($f['original_filename']); ?>">
                            <?php echo escape(mb_strimwidth($f['original_filename'], 0, 50, "...")); ?>
                        </span>
                    </a>
                </div>
                <div style="width:120px; text-align:right; font-family:monospace;">
                    <?php echo formatBytes($f['file_size']); ?>
                </div>
                <div style="width:150px; text-align:right;">
                    <?php echo formatTimestamp($f['upload_timestamp']); ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php echo renderPagination($pageFiles, $totalPagesFiles, 'page_files'); ?>
    </div>

    <!-- KATEGÓRIÁK LISTA -->
    <div id="tab-categories" class="tab-content <?php echo $activeTab == 'categories' ? 'active' : ''; ?>">
        <div class="item-row header" style="font-weight:bold; color:var(--accent-secondary); border-bottom:2px solid var(--glass-border);">
            <input type="checkbox" class="item-check" onchange="toggleSelectAll(this, 'category')">
            <div style="flex:2;">Név</div>
            <div style="width:150px; text-align:right;">Elemek száma</div>
        </div>
        <?php foreach ($categoriesList as $c): ?>
            <div class="item-row">
                <input type="checkbox" class="item-check select-item select-category" value="<?php echo $c['id']; ?>">
                <div style="flex:2;">
                    <strong><?php echo escape($c['name']); ?></strong>
                </div>
                <div style="width:150px; text-align:right;">
                    <?php echo $c['token_count']; ?> db
                </div>
            </div>
        <?php endforeach; ?>
        <?php echo renderPagination($pageCats, $totalPagesCats, 'page_cats'); ?>
    </div>

</div>

<script>
    const AJAX_MANAGER_URL = '<?php echo BASE_URL; ?>tracker/ajax_manager.php';
    const CSRF_TOKEN = '<?php echo generateCsrfToken(); ?>';
    const SOURCE_USER_ID = <?php echo $userId; ?>;
</script>
<script src="<?php echo BASE_URL; ?>assets/js/user_manager.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>