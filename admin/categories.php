<?php
// === 1. LÉPÉS: MINDEN PHP LOGIKA (HTML ELŐTT) ===
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();

// --- POST KÉRÉSEK FELDOLGOZÁSA ---
// Ez a blokk már nem fut, mert minden művelet AJAX-szal történik,
// de biztonsági tartaléknak és a nem-AJAX fallbacknek itt hagyható.
// A lényeg, hogy a header() hívások az ajax_actions.php-be kerültek.
// A jövőben ezt a blokkot akár törölhetjük is, ha minden AJAX-os.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'create_category_ajax' && $_POST['action'] !== 'update_category' && $_POST['action'] !== 'delete_category') {
    // Ide jöhetnének a hagyományos, nem-AJAX-os formküldések kezelése, ha lennének.
}

// === 2. LÉPÉS: ADATOK LEKÉRDEZÉSE A MEGJELENÍTÉSHEZ ===
$pageTitle = "Token Kategóriák";

$categoriesStmt = $db->prepare("
    SELECT c.id, c.name, COUNT(t.id) as token_count
    FROM token_categories c
    LEFT JOIN tokens t ON c.id = t.category_id AND t.user_id = c.user_id
    WHERE c.user_id = :user_id
    GROUP BY c.id, c.name
    ORDER BY c.name ASC
");
$categoriesStmt->execute([':user_id' => $currentUserId]);
$categories = $categoriesStmt->fetchAll();

// === 3. LÉPÉS: HTML MEGJELENÍTÉS KEZDETE ===
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-folder-open"></i> <?php echo escape($pageTitle); ?></h1>
    <button class="btn btn-primary" onclick="document.getElementById('addCategoryModal').style.display='block'"><i class="fas fa-plus"></i> Új Kategória</button>
</div>

<!-- Új Kategória Létrehozása Modális Ablak -->
<div id="addCategoryModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('addCategoryModal').style.display='none'">×</span>
        <h2>Új Kategória Létrehozása</h2>
        <form id="addCategoryForm" method="POST" action="">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="create_category_ajax">
            <div class="form-group">
                <label for="add_category_name">Kategória Neve:</label>
                <input type="text" id="add_category_name" name="category_name" required>
            </div>
            <button type="submit" class="btn btn-primary">Létrehozás</button>
        </form>
    </div>
</div>

<!-- Kategória Szerkesztése Modális Ablak -->
<div id="editCategoryModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('editCategoryModal').style.display='none'">×</span>
        <h2>Kategória Szerkesztése</h2>
        <form id="editCategoryForm" method="POST">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" id="edit_category_id" name="category_id">
            <div class="form-group">
                <label for="edit_category_name">Kategória Új Neve:</label>
                <input type="text" id="edit_category_name" name="category_name" required>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mentés</button>
        </form>
    </div>
</div>

<!-- Kategória Törlése Modális Ablak -->
<div id="deleteCategoryModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('deleteCategoryModal').style.display='none'">×</span>
        <h2>Kategória Törlése</h2>
        <p>A(z) <strong id="categoryToDeleteName"></strong> nevű kategória (<span id="categoryTokenCount">0</span> tokennel) törlésre kerül.</p>
        <p style="margin-top:15px;">Mi történjen a kategóriában lévő tokenekkel?</p>
        <form id="deleteCategoryForm" method="POST" action="<?php echo BASE_URL . 'admin/categories.php'; ?>"> <!-- Az action itt még a régi, de nem számít, mert a JS felülírja -->
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="delete_category_ajax"> <!-- AJAX action-re változtatjuk -->
            <input type="hidden" id="categoryIdToDelete" name="category_id_to_delete">
            <div class="form-group">
                <input type="radio" id="cat_option_move" name="token_action" value="move" checked>
                <label for="cat_option_move">Tokenek áthelyezése másik kategóriába:</label>
                <div class="custom-select-wrapper" id="categoryMoveSelectWrapper" style="margin-top: 10px;">
                    <select name="move_to_category_id" id="moveToCategorySelect"></select>
                    <div class="select-trigger">Válassz...</div>
                    <div class="custom-options"></div>
                </div>
            </div>
            <div class="form-group">
                <input type="radio" id="cat_option_delete" name="token_action" value="delete">
                <label for="cat_option_delete">Kategória és a benne lévő összes token (és naplóik) végleges törlése.</label>
            </div>
            <hr><br>
            <div class="form-group">
                 <label for="confirm_delete_input">A folytatáshoz írd be a kategória nevét: <strong id="confirmCategoryName"></strong></label>
                 <input type="text" id="confirm_delete_input" autocomplete="off">
                 <small id="confirm_error_message" style="color: var(--color-error); display: none; margin-top: 5px;"></small>
            </div>
            <p style="color: var(--color-warning); margin-top:20px; font-weight: bold;"><i class="fas fa-exclamation-triangle"></i> A művelet nem vonható vissza!</p>
            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('deleteCategoryModal').style.display='none'">Mégse</button>
                <button type="submit" id="finalDeleteBtn" class="btn btn-danger" disabled>Törlés Megerősítése</button>
            </div>
        </form>
    </div>
</div>


<!-- A kategóriák listája most már egyetlen szekcióban, teljes szélességben -->
<section class="settings-section glass-effect">
    <h2><i class="fas fa-list-ul"></i> Meglévő Kategóriák</h2>
    <div class="table-container">
        <?php if (empty($categories)): ?>
            <p>Nincsenek még kategóriák létrehozva.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th data-label="Név">Név</th>
                        <th data-label="Tokenek száma">Tokenek száma</th>
                        <th data-label="Műveletek" style="text-align:right;">Műveletek</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td data-label="Név"><?php echo escape($category['name']); ?></td>
                            <td data-label="Tokenek száma"><?php echo $category['token_count']; ?></td>
                            <td data-label="Műveletek" style="text-align:right;">
                                <div class="action-buttons">
                                    <button onclick="openEditCategoryModal(<?php echo $category['id']; ?>)" class="btn btn-small btn-secondary" title="Szerkesztés"><i class="fas fa-edit"></i></button>
                                    <button onclick="openDeleteCategoryModal(<?php echo $category['id']; ?>, '<?php echo escape($category['name']); ?>', <?php echo $category['token_count']; ?>)" class="btn btn-small btn-danger" title="Törlés"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>


<script>
// A kategóriák listája a JS számára
const allCategoriesForMove = <?php echo json_encode(array_map(function($c) { return ['id' => $c['id'], 'name' => $c['name']]; }, $categories)); ?>;
const AJAX_PHP_URL = '<?php echo BASE_URL; ?>admin/ajax_actions.php';
</script>

<script src="<?php echo BASE_URL . 'assets/js/categories.js'; ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>