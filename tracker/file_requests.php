<?php
// === 1. LÉPÉS: PHP LOGIKA FELDOLGOZÁSA (HTML ELŐTT) ===
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin(); // Csak bejelentkezett felhasználók

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Egyszeri Fájlbekérők";

// === 2. LÉPÉS: ADATOK LEKÉRDEZÉSE A MEGJELENÍTÉSHEZ ===
// Jelenleg üres, de itt lesz a logika a `tokens` táblából való listázáshoz,
// ahol a token_type = 'file_request_single_use'

// Példa a jövőre:
// $requestsStmt = $db->prepare("SELECT * FROM tokens WHERE user_id = :user_id AND token_type = 'file_request_single_use' ORDER BY created_at DESC");
// $requestsStmt->execute([':user_id' => $currentUserId]);
// $fileRequests = $requestsStmt->fetchAll();
$fileRequests = []; // Egyelőre üresen hagyjuk.

// === 3. LÉPÉS: HTML MEGJELENÍTÉS KEZDETE ===
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-file-import"></i> <?php echo escape($pageTitle); ?></h1>
    <button class="btn btn-primary"><i class="fas fa-plus"></i> Új Fájlbekérő Létrehozása</button>
</div>

<p class="text-secondary" style="margin-bottom: 20px;">
    Ezek a linkek egyszeri használatra készültek. Miután valaki feltöltött egy fájlt a linken keresztül, az érvénytelenné válik.
</p>

<!-- Fájlbekérő linkek táblázata -->
<div class="table-container glass-effect">
    <table>
        <thead>
            <tr>
                <th>Név</th>
                <th>Bekérő Link</th>
                <th>Státusz</th>
                <th>Létrehozva</th>
                <th>Feltöltött fájl</th>
                <th style="text-align:right;">Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($fileRequests)): ?>
                <tr>
                    <td colspan="6" style="text-align:center; padding: 40px;">
                        Még nem hoztál létre egyszeri fájlbekérő linket. Kattints az "Új Fájlbekérő" gombra!
                    </td>
                </tr>
            <?php else: ?>
                <?php // Ide kerül majd a ciklus, ami kiírja a linkeket ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php 
// Lapozó logika, ha a linkek száma sok lenne
// ...

// === 4. LÉPÉS: FOOTER BEHÍVÁSA ===
require_once __DIR__ . '/../includes/footer.php'; 
?>