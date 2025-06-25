<?php
// === 1. LÉPÉS: PHP LOGIKA FELDOLGOZÁSA (HTML ELŐTT) ===
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin(); // Csak bejelentkezett felhasználók

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Fájlkezelő";

// === 2. LÉPÉS: ADATOK LEKÉRDEZÉSE A MEGJELENÍTÉSHEZ ===
// Jelenleg üres, de itt lesz a logika a fájlok listázásához a `files` táblából.
// Pl. szűrés, lapozás, stb.

// Példa a jövőre:
// $filesStmt = $db->prepare("SELECT * FROM files WHERE user_id = :user_id ORDER BY upload_timestamp DESC");
// $filesStmt->execute([':user_id' => $currentUserId]);
// $userFiles = $filesStmt->fetchAll();
$userFiles = []; // Egyelőre üresen hagyjuk.

// === 3. LÉPÉS: HTML MEGJELENÍTÉS KEZDETE ===
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-folder-open"></i> <?php echo escape($pageTitle); ?></h1>
    <!-- A jövőben itt lehet egy "Új fájl feltöltése" gomb, ami megnyitja az uploader modális ablakot -->
</div>

<!-- Fájlok szűrése és keresése -->
<div class="filter-form glass-effect" style="padding: var(--card-padding); margin-bottom: 20px;">
    <form method="GET" action="<?php echo BASE_URL . 'admin/files.php'; ?>" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
        <div class="form-group" style="flex: 2; min-width: 250px; margin-bottom:0;">
            <label for="search_file">Keresés a fájlokban:</label>
            <input type="text" id="search_file" name="search_file" placeholder="fajlnev.jpg, leírás..." class="form-control">
        </div>
        <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom:0;">
            <label for="filter_type">Fájltípus:</label>
            <input type="text" id="filter_type" name="filter_type" placeholder="pl. image/jpeg" class="form-control">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Szűrés</button>
        <a href="<?php echo BASE_URL . 'admin/files.php'; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Szűrők törlése</a>
    </form>
</div>

<!-- Fájlok táblázata -->
<div class="table-container glass-effect">
    <table>
        <thead>
            <tr>
                <th>Fájlnév</th>
                <th>Típus</th>
                <th>Méret</th>
                <th>Feltöltve</th>
                <th>Státusz/Biztonság</th>
                <th style="text-align:right;">Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($userFiles)): ?>
                <tr>
                    <td colspan="6" style="text-align:center; padding: 40px;">
                        Még nincsenek feltöltött fájlok. Hozz létre egy <a href="<?php echo BASE_URL; ?>admin/file_requests.php">fájlbekérő linket</a> és oszd meg!
                    </td>
                </tr>
            <?php else: ?>
                <?php // Ide kerül majd a ciklus, ami kiírja a fájlokat ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php 
// Lapozó logika, ha a fájlok száma meghaladja az egy oldalon megjeleníthetőt
// ...

// === 4. LÉPÉS: FOOTER BEHÍVÁSA ===
require_once __DIR__ . '/../includes/footer.php'; 
?>