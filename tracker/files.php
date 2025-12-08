<?php
// === Fájl: tracker/files.php (Teljes, Frissített Verzió) ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Fájlkezelő";

// Aktuális nézet (rács vagy lista) a session-ből vagy GET paraméterből
$view_mode = $_GET['view'] ?? ($_SESSION['file_view_mode'] ?? 'grid');
if (!in_array($view_mode, ['grid', 'list'])) $view_mode = 'grid';
$_SESSION['file_view_mode'] = $view_mode;

// Szűrési és lapozási logika
$sqlWhereParts = ["user_id = :user_id"];
$queryParams = [':user_id' => $currentUserId];

$search = trim($_GET['search_file'] ?? '');
if (!empty($search)) {
    $sqlWhereParts[] = "(original_filename LIKE :search OR stored_filename LIKE :search)";
    $queryParams[':search'] = "%" . $search . "%";
}

$filterType = trim($_GET['filter_type'] ?? '');
if (!empty($filterType)) {
    $sqlWhereParts[] = "mime_type LIKE :type";
    $queryParams[':type'] = $filterType . "%";
}

$sqlWhere = "WHERE " . implode(" AND ", $sqlWhereParts);

$totalFilesStmt = $db->prepare("SELECT COUNT(*) FROM files " . $sqlWhere);
$totalFilesStmt->execute($queryParams);
$totalFiles = $totalFilesStmt->fetchColumn();

// Rács nézetben több elem fér el egy oldalon
$itemsPerPage = ($view_mode === 'grid') ? 24 : 15;
$totalPages = $totalFiles > 0 ? ceil($totalFiles / $itemsPerPage) : 1;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;
if ($currentPage > $totalPages) $currentPage = $totalPages;
$offset = ($currentPage - 1) * $itemsPerPage;

$filesStmt = $db->prepare("SELECT * FROM files " . $sqlWhere . " ORDER BY upload_timestamp DESC LIMIT :limit OFFSET :offset");
foreach ($queryParams as $key => &$value) {
    $filesStmt->bindParam($key, $value);
}
$filesStmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$filesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$filesStmt->execute();
$userFiles = $filesStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-folder-open"></i> <?php echo escape($pageTitle); ?></h1>
    <div class="view-switcher">
        <a href="?view=grid<?php echo http_build_query(array_merge($_GET, ['view' => 'grid', 'page' => null])); ?>" class="btn btn-small <?php echo $view_mode === 'grid' ? 'btn-primary' : 'btn-secondary'; ?>" title="Rács nézet"><i class="fas fa-th-large"></i></a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['view' => 'list', 'page' => null])); ?>" class="btn btn-small <?php echo $view_mode === 'list' ? 'btn-primary' : 'btn-secondary'; ?>" title="Lista nézet"><i class="fas fa-bars"></i></a>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>tracker/file_stats.php" class="btn btn-secondary"><i class="fas fa-chart-pie"></i> Összefoglaló</a>
        <a href="<?php echo BASE_URL; ?>tracker/file_requests.php" class="btn btn-primary"><i class="fas fa-plus"></i> Új Fájlbekérő</a>
    </div>
</div>

<div class="filter-form glass-effect" style="padding: var(--card-padding); margin-bottom: 20px;">
    <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
        <input type="hidden" name="view" value="<?php echo $view_mode; ?>"> <!-- Megtartjuk a nézetet szűréskor -->
        <div class="form-group" style="flex: 2; min-width: 250px; margin-bottom:0;">
            <label for="search_file">Keresés a fájlokban:</label>
            <input type="text" id="search_file" name="search_file" value="<?php echo escape($search); ?>" placeholder="fajlnev.jpg, leírás..." class="form-control">
        </div>
        <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom:0;">
            <label for="filter_type">Fájltípus:</label>
            <input type="text" id="filter_type" name="filter_type" value="<?php echo escape($filterType); ?>" placeholder="pl. image/jpeg" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Szűrés</button>
        <a href="<?php echo BASE_URL . 'tracker/files.php?view=' . $view_mode; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Szűrők törlése</a>
    </form>
</div>

<?php if ($view_mode === 'grid'): ?>
    <!-- RÁCS NÉZET -->
    <div class="file-grid">
        <?php if (empty($userFiles)): ?>
            <p class="no-files-message">A szűrési feltételeknek megfelelő fájl nem található.</p>
        <?php else: foreach ($userFiles as $file): ?>
            <div class="file-card glass-effect">
                <a href="<?php echo BASE_URL . 'tracker/file_details.php?id=' . $file['id']; ?>" class="file-preview-link">
                    <?php 
                    $thumbnailUrl = BASE_URL . 'thumbnails/' . $file['view_token'] . '.webp';
                    if (strpos($file['mime_type'], 'image/') === 0): ?>
                        <img src="<?php echo $thumbnailUrl; ?>" alt="<?php echo escape($file['original_filename']); ?>" class="file-thumbnail" loading="lazy" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="file-icon-fallback" style="display:none;"><?php echo getFileIcon($file['mime_type'], $file['original_filename']); ?></div>
                    <?php else: ?>
                        <div class="file-icon-fallback"><?php echo getFileIcon($file['mime_type'], $file['original_filename']); ?></div>
                    <?php endif; ?>
                </a>
                <div class="file-info">
                    <p class="file-name" title="<?php echo escape($file['original_filename']); ?>"><?php echo escape(mb_strimwidth($file['original_filename'], 0, 25, "...")); ?></p>
                    <small class="file-meta"><?php echo formatBytes($file['file_size']); ?> &bull; <?php echo $file['download_count']; ?>x <i class="fas fa-download"></i></small>
                </div>
                <div class="file-actions">
                     <a href="<?php echo BASE_URL . 'View.php?id=' . $file['view_token']; ?>" target="_blank" title="Megtekintés"><i class="fas fa-eye"></i></a>
                     <a href="#" onclick="copyFileLink('<?php echo BASE_URL . 'View.php?id=' . $file['view_token']; ?>'); return false;" title="Link másolása"><i class="fas fa-copy"></i></a>
                     <a href="#" onclick="deleteFile(<?php echo $file['id']; ?>, '<?php echo escape(addslashes($file['original_filename'])); ?>', this); return false;" title="Törlés"><i class="fas fa-trash-alt"></i></a>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

<?php else: ?>
    <!-- LISTA NÉZET -->
    <div class="table-container glass-effect">
        <table>
            <thead>
                <tr>
                    <th>Fájlnév</th><th>Típus</th><th>Méret</th><th>Feltöltve</th><th>Letöltve</th><th style="text-align:right;">Műveletek</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($userFiles)): ?>
                    <tr><td colspan="6" style="text-align:center; padding: 40px;">A szűrési feltételeknek megfelelő fájl nem található.</td></tr>
                <?php else: foreach ($userFiles as $file): ?>
                    <tr>
                        <td data-label="Fájlnév">
                            <a href="<?php echo BASE_URL . 'tracker/file_details.php?id=' . $file['id']; ?>" title="<?php echo escape($file['original_filename']); ?>">
                                <?php echo escape(mb_strimwidth($file['original_filename'], 0, 40, "...")); ?>
                            </a>
                        </td>
                        <td data-label="Típus"><?php echo escape($file['mime_type']); ?></td>
                        <td data-label="Méret"><?php echo formatBytes($file['file_size']); ?></td>
                        <td data-label="Feltöltve"><?php echo formatTimestamp($file['upload_timestamp']); ?></td>
                        <td data-label="Letöltve"><?php echo escape($file['download_count']); ?>x</td>
                        <td data-label="Műveletek">
                            <div class="action-buttons">
                                <a href="<?php echo BASE_URL . 'View.php?id=' . $file['view_token']; ?>" target="_blank" class="btn btn-small btn-info" title="Megtekintés"><i class="fas fa-eye"></i></a>
                                <a href="<?php echo BASE_URL . 'tracker/file_details.php?id=' . $file['id']; ?>" class="btn btn-small btn-secondary" title="Részletek/Statisztika"><i class="fas fa-chart-bar"></i></a>
                                <button onclick="copyFileLink('<?php echo BASE_URL . 'View.php?id=' . $file['view_token']; ?>')" class="btn btn-small btn-secondary" title="Link másolása"><i class="fas fa-copy"></i></button>
                                <button onclick="deleteFile(<?php echo $file['id']; ?>, '<?php echo escape(addslashes($file['original_filename'])); ?>', this)" class="btn btn-small btn-danger" title="Törlés">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- LAPOZÓ -->
<?php if ($totalPages > 1): ?>
<div class="pagination glass-effect" style="margin-top:20px;">
    <?php
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

<script>
    const CSRF_TOKEN = '<?php echo generateCsrfToken(); ?>';

    function copyFileLink(textToCopy) {
        navigator.clipboard.writeText(textToCopy).then(() => {
            showDynamicMessage('Megtekintési link vágólapra másolva!', 'success');
        }).catch(err => {
            showDynamicMessage('A másolás nem sikerült.', 'error');
        });
    }

    function deleteFile(fileId, fileName, buttonElement) {
        if (!confirm(`Biztosan véglegesen törölni szeretnéd a(z) "${fileName}" nevű fájlt? Ez a művelet nem vonható vissza!`)) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'delete_file');
        formData.append('file_id', fileId);
        formData.append('csrf_token', CSRF_TOKEN);

        const itemToRemove = buttonElement.closest('tr') || buttonElement.closest('.file-card');
        if (!itemToRemove) return;
        
        itemToRemove.style.opacity = '0.5';
        itemToRemove.style.pointerEvents = 'none';
        
        fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showDynamicMessage(data.message, 'success');
                itemToRemove.style.transition = 'opacity 0.5s ease, transform 0.5s ease, height 0.5s ease, padding 0.5s ease, margin 0.5s ease';
                itemToRemove.style.transform = 'scale(0.9)';
                itemToRemove.style.opacity = '0';
                // Rács nézetben a magasságot és a margót is animáljuk
                if(itemToRemove.classList.contains('file-card')) {
                    itemToRemove.style.height = '0';
                    itemToRemove.style.padding = '0';
                    itemToRemove.style.margin = '0';
                }
                setTimeout(() => {
                    itemToRemove.remove();
                    if (document.querySelector('table tbody tr') === null && document.querySelector('.file-card') === null) {
                        window.location.reload();
                    }
                }, 500);
            } else {
                showDynamicMessage('Hiba: ' + (data.message || 'Ismeretlen hiba történt.'), 'error');
                itemToRemove.style.opacity = '1';
                itemToRemove.style.pointerEvents = 'auto';
            }
        })
        .catch(error => {
            showDynamicMessage('Hálózati hiba történt a törlés során.', 'error');
            itemToRemove.style.opacity = '1';
            itemToRemove.style.pointerEvents = 'auto';
        });
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>