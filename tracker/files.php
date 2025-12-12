<?php
// === Fájl: tracker/files.php (Végleges - TableHelperrel) ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/TableHelper.php'; // ÚJ: TableHelper behívása

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Fájlkezelő";

// Aktuális nézet
$view_mode = $_GET['view'] ?? ($_SESSION['file_view_mode'] ?? 'grid');
if (!in_array($view_mode, ['grid', 'list'])) $view_mode = 'grid';
$_SESSION['file_view_mode'] = $view_mode;

// === ÚJ: RENDEZÉSI LOGIKA ===
// Engedélyezett rendezési oszlopok
$allowedSortColumns = ['original_filename', 'gallery_name', 'file_size', 'upload_timestamp'];

// Paraméterek beolvasása (alapértelmezés: feltöltés ideje csökkenő)
$sort = $_GET['sort'] ?? 'upload_timestamp';
$dir = $_GET['dir'] ?? 'desc';

// Biztonsági ellenőrzés
if (!in_array($sort, $allowedSortColumns)) {
    $sort = 'upload_timestamp';
}
if (!in_array($dir, ['asc', 'desc'])) {
    $dir = 'desc';
}

// SQL oszlopnév leképezés (mert a gallery_name a 'g' táblában van, a többi az 'f'-ben)
$sqlSortColumn = ($sort === 'gallery_name') ? 'g.name' : "f.$sort";
$sqlSortDir = strtoupper($dir);
// =============================

// --- SZŰRÉSI LOGIKA ---
$sqlWhereParts = ["f.user_id = :user_id"];
$queryParams = [':user_id' => $currentUserId];

$search = trim($_GET['search_file'] ?? '');
if (!empty($search)) {
    $sqlWhereParts[] = "(f.original_filename LIKE :search OR f.stored_filename LIKE :search)";
    $queryParams[':search'] = "%" . $search . "%";
}

$filterType = trim($_GET['filter_type'] ?? '');
if (!empty($filterType)) {
    $sqlWhereParts[] = "f.mime_type LIKE :type";
    $queryParams[':type'] = $filterType . "%";
}

$filterGallery = $_GET['filter_gallery'] ?? '';
if ($filterGallery === 'none') {
    $sqlWhereParts[] = "f.gallery_id IS NULL";
} elseif ($filterGallery === 'any') {
    $sqlWhereParts[] = "f.gallery_id IS NOT NULL";
} elseif (is_numeric($filterGallery) && $filterGallery > 0) {
    $sqlWhereParts[] = "f.gallery_id = :gallery_id";
    $queryParams[':gallery_id'] = $filterGallery;
}

$sqlWhere = "WHERE " . implode(" AND ", $sqlWhereParts);

// Összes fájl számolása
$totalFilesStmt = $db->prepare("SELECT COUNT(*) FROM files f " . $sqlWhere);
$totalFilesStmt->execute($queryParams);
$totalFiles = $totalFilesStmt->fetchColumn();

// Lapozás (Eredeti logika)
$itemsPerPage = ($view_mode === 'grid') ? 24 : 15;
$totalPages = $totalFiles > 0 ? ceil($totalFiles / $itemsPerPage) : 1;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
if ($currentPage > $totalPages) $currentPage = $totalPages;
$offset = ($currentPage - 1) * $itemsPerPage;

// Fájlok lekérése (MÓDOSÍTOTT ORDER BY)
$sql = "SELECT f.*, g.name as gallery_name 
        FROM files f 
        LEFT JOIN galleries g ON f.gallery_id = g.id 
        " . $sqlWhere . " 
        ORDER BY $sqlSortColumn $sqlSortDir 
        LIMIT :limit OFFSET :offset";

$filesStmt = $db->prepare($sql);
foreach ($queryParams as $key => &$value) {
    $filesStmt->bindParam($key, $value);
}
$filesStmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$filesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$filesStmt->execute();
$userFiles = $filesStmt->fetchAll();

// Galériák a szűrőhöz
$galleriesStmt = $db->prepare("SELECT id, name FROM galleries WHERE user_id = :uid ORDER BY name ASC");
$galleriesStmt->execute([':uid' => $currentUserId]);
$userGalleries = $galleriesStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    /* Z-Index javítás a szűrőnek */
    .filter-form { position: relative; z-index: 100; }
    .custom-options { 
        z-index: 1001 !important;
        background: linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.5)),var(--glass-bg); 
    }
    /* Gomb elrejtése, ha JS működik */
    .js-active .filter-submit-btn { display: none; }

    /* Műveleti gombok (Táblázat) - Sorkizárás */
    .action-buttons {
        display: flex;
        gap: 5px;
        white-space: nowrap;
        justify-content: flex-end;
    }

    /* KÁRTYA NÉZET GOMBOK */
    .file-actions {
        display: flex;
        justify-content: space-around;
        align-items: center;
        padding: 10px;
        border-top: 1px solid var(--glass-border);
        gap: 10px;
    }
    
    .file-actions a, .file-actions button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 40px;
        height: 35px;
    }

    /* MOBIL OPTIMALIZÁLÁS */
    @media (max-width: 768px) {
        /* FEJLÉC GRID ELRENDEZÉS - EZ JAVÍTJA A GOMBOKAT */
        .content-header {
            display: grid;
            grid-template-columns: 1fr auto; /* Balra cím, jobbra ikonok */
            grid-template-rows: auto auto;   /* Fent cím/ikon, lent nagy gomb */
            gap: 15px;
            align-items: center;
        }
        
        /* 1. Bal felső: Cím */
        .content-header h1 {
            grid-column: 1 / 2;
            grid-row: 1;
            font-size: 1.5em;
            margin: 0;
        }
        
        /* 2. Jobb felső: Nézetváltó gombok (EGYMÁS MELLETT!) */
        .view-switcher {
            grid-column: 2 / 3;
            grid-row: 1;
            display: flex !important; /* Kényszerített flex */
            flex-direction: row !important; /* Egymás mellé! */
            gap: 5px;
        }
        
        .view-switcher .btn {
            width: auto !important; /* Ne legyen teljes szélességű */
            padding: 8px 12px;
            margin: 0;
        }
        
        /* 3. Alsó sor: Új Fájlbekérő gomb */
        .header-actions {
            grid-column: 1 / -1; /* Teljes szélesség */
            grid-row: 2;
            width: 100%;
        }
        
        .header-actions .btn {
            width: 100%;
            display: block;
            text-align: center;
        }

        /* SZŰRŐ */
        .filter-form form {
            flex-direction: column;
            align-items: stretch !important;
        }
        
        .filter-form .form-group {
            width: 100% !important;
            margin-bottom: 10px !important;
        }

        /* TÁBLÁZAT MOBILON */
        .table-container td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            text-align: right;
        }
        .table-container td:before {
            content: attr(data-label);
            text-align: left;
            font-weight: bold;
            margin-right: 10px;
        }
    }
</style>
<div class="content-header">
    <h1><i class="fas fa-folder-open"></i> <?php echo escape($pageTitle); ?></h1>
    <div class="view-switcher">
        <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'grid', 'page' => null])); ?>" class="btn btn-small <?php echo $view_mode === 'grid' ? 'btn-primary' : 'btn-secondary'; ?>"><i class="fas fa-th-large"></i></a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'list', 'page' => null])); ?>" class="btn btn-small <?php echo $view_mode === 'list' ? 'btn-primary' : 'btn-secondary'; ?>"><i class="fas fa-bars"></i></a>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>tracker/file_stats.php" class="btn btn-primary"><i class="fas fa-info"></i> Statisztika</a>
        <a href="<?php echo BASE_URL; ?>tracker/file_requests.php" class="btn btn-primary"><i class="fas fa-plus"></i> Új Fájlbekérő</a>
    </div>
</div>

<!-- SZŰRŐ ŰRLAP -->
<div class="filter-form glass-effect" style="padding: var(--card-padding); margin-bottom: 20px;">
    <form method="GET" action="" id="filterForm" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
        <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
        <!-- A rendezési paramétereket is megtartjuk szűréskor, bár általában új keresésnél resetelődik, itt hagyjuk -->
        <?php if(isset($_GET['sort'])): ?><input type="hidden" name="sort" value="<?php echo escape($_GET['sort']); ?>"><?php endif; ?>
        <?php if(isset($_GET['dir'])): ?><input type="hidden" name="dir" value="<?php echo escape($_GET['dir']); ?>"><?php endif; ?>
        
        <div class="form-group" style="flex: 2; min-width: 200px; margin-bottom:0;">
            <label>Keresés (Enter):</label>
            <input type="text" name="search_file" value="<?php echo escape($search); ?>" placeholder="Név..." class="form-control">
        </div>
        
        <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom:0;">
            <label>Típus:</label>
            <input type="text" name="filter_type" value="<?php echo escape($filterType); ?>" placeholder="pl. image" class="form-control">
        </div>

        <!-- Galéria Szűrő -->
        <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom:0;">
            <label>Galéria:</label>
            <div class="custom-select-wrapper">
                <select name="filter_gallery" id="filter_gallery_select" style="display:none;" onchange="this.form.submit()">
                    <option value="">Összes fájl</option>
                    <option value="none" <?php echo $filterGallery === 'none' ? 'selected' : ''; ?>>Nincs galériában</option>
                    <option value="any" <?php echo $filterGallery === 'any' ? 'selected' : ''; ?>>Bármely galériában</option>
                    <?php foreach ($userGalleries as $gal): ?>
                        <option value="<?php echo $gal['id']; ?>" <?php echo $filterGallery == $gal['id'] ? 'selected' : ''; ?>><?php echo escape($gal['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- Custom UI -->
                <div class="select-trigger">
                    <?php 
                        if($filterGallery === 'none') echo '📁 Nincs galériában';
                        elseif($filterGallery === 'any') echo '🖼️ Bármely galériában';
                        elseif($filterGallery > 0) {
                            $found = false;
                            foreach($userGalleries as $gal) if($gal['id'] == $filterGallery) { echo escape($gal['name']); $found=true; break; }
                            if(!$found) echo 'Összes fájl';
                        } else echo 'Összes fájl';
                    ?>
                </div>
                <div class="custom-options">
                    <span class="custom-option" data-value="">Összes fájl</span>
                    <span class="custom-option" data-value="none" style="color:var(--accent-secondary);">📁 Nincs galériában</span>
                    <span class="custom-option" data-value="any">🖼️ Bármely galériában</span>
                    <?php foreach ($userGalleries as $gal): ?>
                        <span class="custom-option" data-value="<?php echo $gal['id']; ?>"><?php echo escape($gal['name']); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary filter-submit-btn"><i class="fas fa-filter"></i> Szűrés</button>
        <!-- Szűrők törlése gomb (Margin 0 !important) -->
        <a href="?view=<?php echo $view_mode; ?>" class="btn btn-secondary"><i class="fas fa-times" style="margin: 0 !important;"></i></a>
    </form>
</div>

<?php if ($view_mode === 'grid'): ?>
    <!-- RÁCS NÉZET -->
    <div class="file-grid">
        <?php if (empty($userFiles)): ?>
            <p class="no-files-message">Nincs találat.</p>
        <?php else: foreach ($userFiles as $file): ?>
            <div class="file-card glass-effect" id="file-card-<?php echo $file['id']; ?>">
                <a href="<?php echo BASE_URL . 'tracker/file_details.php?id=' . $file['id']; ?>" class="file-preview-link">
                    <?php 
                    $thumbnailUrl = BASE_URL . 'thumbnails/' . $file['view_token'] . '.webp';
                    if (strpos($file['mime_type'], 'image/') === 0): ?>
                        <img src="<?php echo $thumbnailUrl; ?>" alt="img" class="file-thumbnail" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="file-icon-fallback" style="display:none;">🖼️</div>
                    <?php else: ?>
                        <div class="file-icon-fallback"><?php echo getFileIcon($file['mime_type'], $file['original_filename']); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($file['gallery_name']): ?>
                        <span class="category-tag" style="position:absolute; bottom:5px; right:5px; background:rgba(0,0,0,0.7);">
                            <i class="fas fa-images"></i> <?php echo escape($file['gallery_name']); ?>
                        </span>
                    <?php endif; ?>
                </a>
                <div class="file-info">
                    <p class="file-name"><?php echo escape(mb_strimwidth($file['original_filename'], 0, 20, "...")); ?></p>
                    <small class="file-meta"><?php echo formatBytes($file['file_size']); ?></small>
                </div>
                <div class="file-actions">
                    <a href="<?php echo BASE_URL . 'View.php?id=' . $file['view_token']; ?>" target="_blank" title="Megtekintés"><i class="fas fa-eye"></i></a>
                    <a href="#" onclick="openSetGalleryModal(<?php echo $file['id']; ?>, <?php echo $file['gallery_id'] ?: 'null'; ?>); return false;" title="Galéria Módosítása"><i class="fas fa-folder"></i></a>
                    <a href="#" onclick="deleteFile(<?php echo $file['id']; ?>, '<?php echo escape(addslashes($file['original_filename'])); ?>', this); return false;" title="Törlés" style="color:var(--color-error);"><i class="fas fa-trash-alt"></i></a>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
<?php else: ?>
    <!-- LISTA NÉZET -->
    <div class="table-container glass-effect">
        <table>
            <?php
            // === TABLE HELPER HASZNÁLATA ===
            $table = new TableHelper($sort, $dir);
            $table->addColumn('original_filename', 'Fájlnév', true);
            $table->addColumn('gallery_name', 'Galéria', true);
            $table->addColumn('file_size', 'Méret', true);
            $table->addColumn('upload_timestamp', 'Feltöltve', true);
            $table->addColumn('actions', 'Műveletek', false, '150px');
            $table->render();
            ?>
            <tbody>
                <?php if (empty($userFiles)): ?>
                    <tr><td colspan="5" style="text-align:center;">Nincs találat.</td></tr>
                <?php else: foreach ($userFiles as $file): ?>
                    <tr>
                        <td data-label="Fájlnév"><?php echo escape(mb_strimwidth($file['original_filename'], 0, 40, "...")); ?></td>
                        <td data-label="Galéria">
                            <?php if ($file['gallery_name']): ?>
                                <span class="category-tag"><i class="fas fa-images"></i> <?php echo escape($file['gallery_name']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Méret"><?php echo formatBytes($file['file_size']); ?></td>
                        <td data-label="Feltöltve"><?php echo formatTimestamp($file['upload_timestamp']); ?></td>
                        <td data-label="Műveletek">
                            <div class="action-buttons">
                                <a href="<?php echo BASE_URL . 'View.php?id=' . $file['view_token']; ?>" target="_blank" title="Megtekintés"  class="btn btn-small btn-info"><i class="fas fa-eye"></i></a>
                                <a href="#" onclick="openSetGalleryModal(<?php echo $file['id']; ?>, <?php echo $file['gallery_id'] ?: 'null'; ?>); return false;" class="btn btn-small btn-secondary"><i class="fas fa-folder"></i></a>
                                <button onclick="deleteFile(<?php echo $file['id']; ?>, 'x', this)" class="btn btn-small btn-danger"><i class="fas fa-trash-alt"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- LAPOZÓ (Eredeti logika) -->
<?php if ($totalPages > 1): ?>
<div class="pagination glass-effect" style="margin-top:20px;">
    <?php
    // Itt a $queryParamsForPagination a $_GET-ből indul, ami már tartalmazza a sort/dir paramétereket!
    $queryParamsForPagination = $_GET;
    unset($queryParamsForPagination['page']);
    $paginationUrlParams = http_build_query($queryParamsForPagination);
    ?>
    <?php if ($currentPage > 1): ?>
        <a href="?<?php echo $paginationUrlParams; ?>&page=<?php echo $currentPage - 1; ?>" class="btn btn-secondary btn-small">« Előző</a>
    <?php else: ?>
        <span class="btn btn-secondary btn-small disabled">« Előző</span>
    <?php endif; ?>

    <?php 
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    if ($startPage > 1) {
        echo '<a href="?'.$paginationUrlParams.'&page=1" class="btn btn-secondary btn-small">1</a>';
        if ($startPage > 2) echo '<span class="pagination-dots">...</span>';
    }
    for ($i = $startPage; $i <= $endPage; $i++): ?>
        <?php if ($i == $currentPage): ?>
            <span class="btn btn-primary btn-small current-page"><?php echo $i; ?></span>
        <?php else: ?>
            <a href="?<?php echo $paginationUrlParams; ?>&page=<?php echo $i; ?>" class="btn btn-secondary btn-small"><?php echo $i; ?></a>
        <?php endif; ?>
    <?php endfor;
    if ($endPage < $totalPages) {
        if ($endPage < $totalPages - 1) echo '<span class="pagination-dots">...</span>';
        echo '<a href="?'.$paginationUrlParams.'&page='.$totalPages.'" class="btn btn-secondary btn-small">'.$totalPages.'</a>';
    }
    ?>

    <?php if ($currentPage < $totalPages): ?>
        <a href="?<?php echo $paginationUrlParams; ?>&page=<?php echo $currentPage + 1; ?>" class="btn btn-secondary btn-small">Következő »</a>
    <?php else: ?>
        <span class="btn btn-secondary btn-small disabled">Következő »</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- MODALOK és JS -->
<div id="setGalleryModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('setGalleryModal').style.display='none'">×</span>
        <h2><i class="fas fa-images"></i> Galéria Hozzárendelése</h2>
        <form id="setGalleryForm">
            <input type="hidden" name="action" value="assign_file_gallery">
            <input type="hidden" name="file_id" id="assign_file_id">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
            <div class="form-group">
                <select name="gallery_id" id="assign_gallery_select" class="form-control" style="background:#222; color:white; padding:10px; display:block;">
                    <option value="null">🚫 Nincs galéria (Leválasztás)</option>
                    <?php foreach ($userGalleries as $gal): ?>
                        <option value="<?php echo $gal['id']; ?>"><?php echo escape($gal['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Mentés</button>
        </form>
    </div>
</div>

<script>
    const CSRF_TOKEN = '<?php echo generateCsrfToken(); ?>';

    document.addEventListener('DOMContentLoaded', function() {
        document.body.classList.add('js-active');
        const filterSelect = document.getElementById('filter_gallery_select');
        if(filterSelect) {
            filterSelect.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        }
    });

    function openSetGalleryModal(fileId, currentGalleryId) {
        document.getElementById('assign_file_id').value = fileId;
        document.getElementById('assign_gallery_select').value = currentGalleryId || 'null';
        document.getElementById('setGalleryModal').style.display = 'block';
    }

    document.getElementById('setGalleryForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) { showDynamicMessage(data.message, 'success'); setTimeout(() => location.reload(), 500); }
            else alert(data.message);
        });
    });

    function deleteFile(fileId, fileName, btn) {
        if (!confirm('Biztosan törlöd?')) return;
        const fd = new FormData(); fd.append('action','delete_file'); fd.append('file_id',fileId); fd.append('csrf_token', CSRF_TOKEN);
        fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{ if(d.success) { btn.closest('.file-card, tr').remove(); } });
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>