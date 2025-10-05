<?php
// === Fájl: tracker/files.php ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Fájlkezelő";

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

$itemsPerPage = 15;
$totalPages = ceil($totalFiles / $itemsPerPage);
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

$filesStmt = $db->prepare("SELECT * FROM files " . $sqlWhere . " ORDER BY upload_timestamp DESC LIMIT :limit OFFSET :offset");
foreach ($queryParams as $key => $value) $filesStmt->bindValue($key, $value);
$filesStmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$filesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$filesStmt->execute();
$userFiles = $filesStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-folder-open"></i> <?php echo escape($pageTitle); ?></h1>
    <a href="<?php echo BASE_URL; ?>tracker/file_requests.php" class="btn btn-primary"><i class="fas fa-plus"></i> Új Fájlbekérő</a>
</div>

<div class="filter-form glass-effect" style="padding: var(--card-padding); margin-bottom: 20px;">
    <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
        <div class="form-group" style="flex: 2; min-width: 250px; margin-bottom:0;">
            <label for="search_file">Keresés a fájlokban:</label>
            <input type="text" id="search_file" name="search_file" value="<?php echo escape($search); ?>" placeholder="fajlnev.jpg, leírás..." class="form-control">
        </div>
        <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom:0;">
            <label for="filter_type">Fájltípus:</label>
            <input type="text" id="filter_type" name="filter_type" value="<?php echo escape($filterType); ?>" placeholder="pl. image/jpeg" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Szűrés</button>
        <a href="<?php echo BASE_URL . 'tracker/files.php'; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Szűrők törlése</a>
    </form>
</div>

<div class="table-container glass-effect">
    <table>
        <thead>
            <tr>
                <th>Fájlnév</th>
                <th>Típus</th>
                <th>Méret</th>
                <th>Feltöltve</th>
                <th>Letöltve</th>
                <th style="text-align:right;">Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($userFiles)): ?>
                <tr><td colspan="6" style="text-align:center; padding: 40px;">Nincsenek feltöltött fájlok.</td></tr>
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
                            <button onclick="copyToClipboard('<?php echo BASE_URL . 'View.php?id=' . $file['view_token']; ?>')" class="btn btn-small btn-secondary" title="Link másolása"><i class="fas fa-copy"></i></button>
                            <!-- Törlés gomb (AJAX-osra kell majd csinálni) -->
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- Lapozó -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>