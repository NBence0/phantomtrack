<?php
// === Fájl: tracker/category_view.php (TableHelper + Lapozás) ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
// TableHelper-t nem hívjuk be közvetlenül, mert egyedi prefixelt logikát használunk

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$categoryId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Kategória adatainak lekérése
$catStmt = $db->prepare("SELECT * FROM token_categories WHERE id = :id AND user_id = :uid");
$catStmt->execute([':id' => $categoryId, ':uid' => $currentUserId]);
$category = $catStmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    header('Location: ' . BASE_URL . 'tracker/categories.php');
    exit;
}

$itemsPerPage = (int)getAppSetting('items_per_page', 20);

// === 1. TOKENEK ===
$pageTokens = isset($_GET['page_tokens']) ? max(1, (int)$_GET['page_tokens']) : 1;
$offsetTokens = ($pageTokens - 1) * $itemsPerPage;
$sortTokens = $_GET['sort_tokens'] ?? 'created_at';
$dirTokens = $_GET['dir_tokens'] ?? 'desc';
$allowedSortTokens = ['name', 'created_at', 'is_active'];
if (!in_array($sortTokens, $allowedSortTokens)) $sortTokens = 'created_at';
if (!in_array($dirTokens, ['asc', 'desc'])) $dirTokens = 'desc';

$totalTokens = $db->prepare("SELECT COUNT(*) FROM tokens WHERE category_id = :id");
$totalTokens->execute([':id' => $categoryId]);
$totalTokensCount = $totalTokens->fetchColumn();
$totalPagesTokens = ceil($totalTokensCount / $itemsPerPage);

$tokensSql = "SELECT * FROM tokens WHERE category_id = :id ORDER BY $sortTokens " . strtoupper($dirTokens) . " LIMIT :limit OFFSET :offset";
$tokens = $db->prepare($tokensSql);
$tokens->bindValue(':id', $categoryId, PDO::PARAM_INT);
$tokens->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$tokens->bindValue(':offset', $offsetTokens, PDO::PARAM_INT);
$tokens->execute();
$tokensList = $tokens->fetchAll();


// === 2. GALÉRIÁK ===
$pageGalleries = isset($_GET['page_galleries']) ? max(1, (int)$_GET['page_galleries']) : 1;
$offsetGalleries = ($pageGalleries - 1) * $itemsPerPage;
$sortGalleries = $_GET['sort_galleries'] ?? 'created_at';
$dirGalleries = $_GET['dir_galleries'] ?? 'desc';
$allowedSortGalleries = ['name', 'visibility', 'created_at'];
if (!in_array($sortGalleries, $allowedSortGalleries)) $sortGalleries = 'created_at';
if (!in_array($dirGalleries, ['asc', 'desc'])) $dirGalleries = 'desc';

$totalGalleries = $db->prepare("SELECT COUNT(*) FROM galleries WHERE category_id = :id");
$totalGalleries->execute([':id' => $categoryId]);
$totalGalleriesCount = $totalGalleries->fetchColumn();
$totalPagesGalleries = ceil($totalGalleriesCount / $itemsPerPage);

$galleriesSql = "SELECT * FROM galleries WHERE category_id = :id ORDER BY $sortGalleries " . strtoupper($dirGalleries) . " LIMIT :limit OFFSET :offset";
$galleries = $db->prepare($galleriesSql);
$galleries->bindValue(':id', $categoryId, PDO::PARAM_INT);
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

$totalFiles = $db->prepare("SELECT COUNT(*) FROM files WHERE category_id = :id");
$totalFiles->execute([':id' => $categoryId]);
$totalFilesCount = $totalFiles->fetchColumn();
$totalPagesFiles = ceil($totalFilesCount / $itemsPerPage);

$filesSql = "SELECT * FROM files WHERE category_id = :id ORDER BY $sortFiles " . strtoupper($dirFiles) . " LIMIT :limit OFFSET :offset";
$files = $db->prepare($filesSql);
$files->bindValue(':id', $categoryId, PDO::PARAM_INT);
$files->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$files->bindValue(':offset', $offsetFiles, PDO::PARAM_INT);
$files->execute();
$filesList = $files->fetchAll();

// === AKTÍV TAB MEGHATÁROZÁSA ===
$activeTab = $_GET['active_tab'] ?? 'tokens';
if (!isset($_GET['active_tab'])) {
    if (isset($_GET['page_galleries']) || isset($_GET['sort_galleries'])) $activeTab = 'galleries';
    elseif (isset($_GET['page_files']) || isset($_GET['sort_files'])) $activeTab = 'files';
}

$pageTitle = "Kategória: " . $category['name'];
require_once __DIR__ . '/../includes/header.php';

// === HELPER FÜGGVÉNYEK ===

function renderPagination($current, $total, $pageParam, $activeTabName) {
    if ($total <= 1) return '';
    $html = '<div class="pagination glass-effect" style="margin-top:15px; justify-content:center;">';
    
    // Csak a releváns paramétereket tartjuk meg
    $prefix = str_replace('page_', '', $pageParam);
    $params = [
        'id' => $_GET['id'] ?? 0,
        'active_tab' => $activeTabName
    ];
    if (isset($_GET['sort_' . $prefix])) $params['sort_' . $prefix] = $_GET['sort_' . $prefix];
    if (isset($_GET['dir_' . $prefix])) $params['dir_' . $prefix] = $_GET['dir_' . $prefix];

    $baseUrl = '?' . http_build_query($params) . '&' . $pageParam . '=';
    
    if ($current > 1) $html .= '<a href="' . $baseUrl . ($current - 1) . '" class="btn btn-secondary btn-small">«</a>';
    $start = max(1, $current - 2); $end = min($total, $current + 2);
    if ($start > 1) { $html .= '<a href="' . $baseUrl . '1" class="btn btn-secondary btn-small">1</a>'; if ($start > 2) $html .= '<span class="pagination-dots">...</span>'; }
    for ($i = $start; $i <= $end; $i++) { $active = ($i == $current) ? 'btn-primary' : 'btn-secondary'; $html .= '<a href="' . $baseUrl . $i . '" class="btn ' . $active . ' btn-small">' . $i . '</a>'; }
    if ($end < $total) { if ($end < $total - 1) $html .= '<span class="pagination-dots">...</span>'; $html .= '<a href="' . $baseUrl . $total . '" class="btn btn-secondary btn-small">' . $total . '</a>'; }
    if ($current < $total) $html .= '<a href="' . $baseUrl . ($current + 1) . '" class="btn btn-secondary btn-small">»</a>';
    $html .= '</div>';
    return $html;
}

function renderCustomTh($label, $columnKey, $prefix, $currentSort, $currentDir, $width = '', $align = 'left') {
    $isActive = ($currentSort === $columnKey);
    $activeClass = $isActive ? 'active-' . $currentDir : '';
    $icon = '<i class="fas fa-sort text-muted" style="opacity:0.3;"></i>';
    if ($isActive) {
        $icon = ($currentDir === 'asc') ? '<i class="fas fa-sort-up"></i>' : '<i class="fas fa-sort-down"></i>';
    }
    $style = 'cursor:pointer; user-select:none;';
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
    .manager-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 1px solid var(--glass-border); padding-bottom: 10px; }
    .tab-btn { background: transparent; border: none; color: var(--text-secondary); padding: 10px 20px; cursor: pointer; font-size: 1.1em; border-bottom: 2px solid transparent; transition: all 0.3s; }
    .tab-btn.active { color: var(--accent-primary); border-bottom-color: var(--accent-primary); font-weight: bold; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
    .badge-active { background: rgba(46, 213, 115, 0.2); color: #2ed573; border: 1px solid #2ed573; }
    .badge-inactive { background: rgba(255, 71, 87, 0.2); color: #ff4757; border: 1px solid #ff4757; }
    .badge-public { background: rgba(46, 213, 115, 0.2); color: #2ed573; }
    .badge-private { background: rgba(255, 202, 40, 0.2); color: #ffca28; }
    .badge-password { background: rgba(255, 71, 87, 0.2); color: #ff4757; }
    
    .smart-th.custom-sort:hover { background: rgba(255,255,255,0.05); }
</style>

<div class="content-header">
    <h1><i class="fas fa-tag"></i> <?php echo escape($pageTitle); ?></h1>
    <a href="<?php echo BASE_URL; ?>tracker/categories.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Vissza</a>
</div>

<div class="glass-effect" style="padding: 20px;">
    
    <div class="manager-tabs">
        <button class="tab-btn <?php echo $activeTab == 'tokens' ? 'active' : ''; ?>" onclick="openTab('tokens')">Tokenek (<?php echo $totalTokensCount; ?>)</button>
        <button class="tab-btn <?php echo $activeTab == 'galleries' ? 'active' : ''; ?>" onclick="openTab('galleries')">Galériák (<?php echo $totalGalleriesCount; ?>)</button>
        <button class="tab-btn <?php echo $activeTab == 'files' ? 'active' : ''; ?>" onclick="openTab('files')">Fájlok (<?php echo $totalFilesCount; ?>)</button>
    </div>

    <!-- 1. TOKENEK -->
    <div id="tab-tokens" class="tab-content <?php echo $activeTab == 'tokens' ? 'active' : ''; ?>">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <?php 
                        renderCustomTh('Név', 'name', 'tokens', $sortTokens, $dirTokens);
                        renderCustomTh('Token', 'token_value', 'tokens', $sortTokens, $dirTokens); // Opcionálisan rendezhető
                        renderCustomTh('Státusz', 'is_active', 'tokens', $sortTokens, $dirTokens, '100', 'center');
                        renderCustomTh('Létrehozva', 'created_at', 'tokens', $sortTokens, $dirTokens, '150', 'right');
                        ?>
                        <th style="width:100px; text-align:right;">Műveletek</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($tokensList)): ?>
                        <tr><td colspan="5" style="text-align:center;">Nincs token ebben a kategóriában.</td></tr>
                    <?php else: foreach ($tokensList as $t): ?>
                        <tr>
                            <td><strong><?php echo escape($t['name']); ?></strong></td>
                            <td style="font-family:monospace;"><?php echo escape($t['token_value']); ?></td>
                            <td style="text-align:center;">
                                <span class="badge <?php echo $t['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo $t['is_active'] ? 'Aktív' : 'Inaktív'; ?>
                                </span>
                            </td>
                            <td style="text-align:right;"><?php echo formatTimestamp($t['created_at']); ?></td>
                            <td style="text-align:right;">
                                <a href="<?php echo BASE_URL . 'tracker/token_details.php?id=' . $t['id']; ?>" class="btn btn-small btn-primary"><i class="fas fa-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo renderPagination($pageTokens, $totalPagesTokens, 'page_tokens', 'tokens'); ?>
    </div>

    <!-- 2. GALÉRIÁK -->
    <div id="tab-galleries" class="tab-content <?php echo $activeTab == 'galleries' ? 'active' : ''; ?>">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <?php 
                        renderCustomTh('Név', 'name', 'galleries', $sortGalleries, $dirGalleries);
                        renderCustomTh('Láthatóság', 'visibility', 'galleries', $sortGalleries, $dirGalleries, '120', 'center');
                        renderCustomTh('Létrehozva', 'created_at', 'galleries', $sortGalleries, $dirGalleries, '150', 'right');
                        ?>
                        <th style="width:120px; text-align:right;">Műveletek</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($galleriesList)): ?>
                        <tr><td colspan="4" style="text-align:center;">Nincs galéria ebben a kategóriában.</td></tr>
                    <?php else: foreach ($galleriesList as $g): ?>
                        <tr>
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
                            <td style="text-align:right;"><?php echo formatTimestamp($g['created_at']); ?></td>
                            <td style="text-align:right;">
                                <a href="<?php echo BASE_URL . 'gallery_view.php?token=' . $g['view_token']; ?>" target="_blank" class="btn btn-small btn-info"><i class="fas fa-external-link-alt"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo renderPagination($pageGalleries, $totalPagesGalleries, 'page_galleries', 'galleries'); ?>
    </div>

    <!-- 3. FÁJLOK -->
    <div id="tab-files" class="tab-content <?php echo $activeTab == 'files' ? 'active' : ''; ?>">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <?php 
                        renderCustomTh('Fájlnév', 'original_filename', 'files', $sortFiles, $dirFiles);
                        renderCustomTh('Méret', 'file_size', 'files', $sortFiles, $dirFiles, '120', 'right');
                        renderCustomTh('Feltöltve', 'upload_timestamp', 'files', $sortFiles, $dirFiles, '150', 'right');
                        ?>
                        <th style="width:100px; text-align:right;">Műveletek</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($filesList)): ?>
                        <tr><td colspan="4" style="text-align:center;">Nincs fájl ebben a kategóriában.</td></tr>
                    <?php else: foreach ($filesList as $f): ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <span style="font-size:1.2em;"><?php echo getFileIcon($f['mime_type'], $f['original_filename']); ?></span>
                                    <span><?php echo escape(mb_strimwidth($f['original_filename'], 0, 50, "...")); ?></span>
                                </div>
                            </td>
                            <td style="text-align:right; font-family:monospace;"><?php echo formatBytes($f['file_size']); ?></td>
                            <td style="text-align:right;"><?php echo formatTimestamp($f['upload_timestamp']); ?></td>
                            <td style="text-align:right;">
                                <a href="<?php echo BASE_URL . 'tracker/file_details.php?id=' . $f['id']; ?>" class="btn btn-small btn-primary"><i class="fas fa-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php echo renderPagination($pageFiles, $totalPagesFiles, 'page_files', 'files'); ?>
    </div>

</div>

<script>
    // Tab váltó (ez csak a megjelenítést kezeli, nem tölt újra)
    function openTab(name) {
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        
        document.getElementById('tab-' + name).classList.add('active');
        // Keresünk egy gombot, aminek az onclickjében ez van, és aktiváljuk (hack, de működik)
        const btns = document.querySelectorAll('.tab-btn');
        btns.forEach(btn => {
            if(btn.textContent.toLowerCase().includes(name.substring(0,4))) btn.classList.add('active'); // Egyszerűsített logika
        });
        
        // URL frissítése újratöltés nélkül, hogy a back gomb működjön
        const url = new URL(window.location.href);
        url.searchParams.set('active_tab', name);
        window.history.pushState({}, '', url);
    }
    
    // Explicit beállítjuk az aktív gombot betöltéskor
    document.addEventListener('DOMContentLoaded', function() {
        const activeTab = '<?php echo $activeTab; ?>';
        // A PHP már generálta a contentet active class-szal, de a gombot is be kell állítani
        const btns = document.querySelectorAll('.tab-btn');
        // Ez a logika feltételezi a sorrendet: 0:Tokens, 1:Galleries, 2:Files
        if(activeTab === 'tokens') btns[0].classList.add('active');
        else if(activeTab === 'galleries') btns[1].classList.add('active');
        else if(activeTab === 'files') btns[2].classList.add('active');
    });

    // Rendezés kezelő (tisztítja az URL-t)
    function handleSort(th) {
        const column = th.getAttribute('data-column');
        const prefix = th.getAttribute('data-prefix'); // tokens, galleries, files
        
        let newDir = 'desc'; 
        if (th.classList.contains('active-desc')) newDir = 'asc';
        else if (th.classList.contains('active-asc')) newDir = 'desc';

        const currentUrl = new URL(window.location.href);
        const catId = currentUrl.searchParams.get('id');

        // Tiszta URL építése
        const newUrl = new URL(window.location.protocol + '//' + window.location.host + window.location.pathname);
        newUrl.searchParams.set('id', catId);
        newUrl.searchParams.set('active_tab', prefix);
        newUrl.searchParams.set('sort_' + prefix, column);
        newUrl.searchParams.set('dir_' + prefix, newDir);
        newUrl.searchParams.set('page_' + prefix, 1);

        window.location.href = newUrl.toString();
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>