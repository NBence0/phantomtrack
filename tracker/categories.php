<?php
// === Fájl: tracker/categories.php ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Kategóriák Kezelése";

// Kategóriák lekérdezése kibővített statisztikákkal
$categoriesStmt = $db->prepare("
    SELECT c.id, c.name, 
           (SELECT COUNT(*) FROM tokens WHERE category_id = c.id) as token_count,
           (SELECT COUNT(*) FROM galleries WHERE category_id = c.id) as gallery_count,
           (SELECT COUNT(*) FROM files WHERE category_id = c.id) as file_count
    FROM token_categories c
    WHERE c.user_id = :user_id
    ORDER BY c.name ASC
");
$categoriesStmt->execute([':user_id' => $currentUserId]);
$categories = $categoriesStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-tags"></i> <?php echo escape($pageTitle); ?></h1>
    <button class="btn btn-primary" onclick="document.getElementById('addCategoryModal').style.display='block'">
        <i class="fas fa-plus"></i> Új Kategória
    </button>
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


<!-- TÖRLÉS MODAL (Bővített logikával) -->
<div id="deleteCategoryModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('deleteCategoryModal').style.display='none'">×</span>
        <h2>Kategória Törlése</h2>
        <p>A(z) <strong id="categoryToDeleteName"></strong> kategória törlésre kerül.</p>
        
        <div class="info-card" style="margin:15px 0; font-size:0.9em;">
            Tartalom: 
            <span id="catTokenCount">0</span> token, 
            <span id="catGalleryCount">0</span> galéria, 
            <span id="catFileCount">0</span> fájl.
        </div>

        <form id="deleteCategoryForm">
            <input type="hidden" name="action" value="delete_category_ajax">
            <input type="hidden" id="categoryIdToDelete" name="category_id_to_delete">
            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">

            <div class="form-group">
                <input type="radio" id="cat_option_move" name="token_action" value="move" checked>
                <label for="cat_option_move">Tartalom áthelyezése másik kategóriába:</label>
                <div class="custom-select-wrapper" style="margin-top: 10px;">
                    <select name="move_to_category_id" id="moveToCategorySelect" class="form-control" style="background:#222; color:white; padding:8px;">
                        <option value="">Válassz...</option>
                        <!-- JS tölti fel -->
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <input type="radio" id="cat_option_unlink" name="token_action" value="unlink">
                <label for="cat_option_unlink">Kategória törlése (elemek megtartása kategória nélkül).</label>
            </div>

            <div class="form-group">
                <input type="radio" id="cat_option_delete" name="token_action" value="delete">
                <label for="cat_option_delete" style="color:#ff4757;">MINDEN elem végleges törlése (Veszélyes!).</label>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top:20px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('deleteCategoryModal').style.display='none'">Mégse</button>
                <button type="submit" class="btn btn-danger">Végrehajtás</button>
            </div>
        </form>
    </div>
</div>

<!-- LISTA -->
<section class="settings-section glass-effect">
    <div class="table-container">
        <?php if (empty($categories)): ?>
            <p style="text-align:center; padding:20px;">Nincsenek kategóriák.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Név</th>
                        <th>Tartalom</th>
                        <th style="text-align:right;">Műveletek</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $cat): ?>
                        <tr>
                            <td><strong><?php echo escape($cat['name']); ?></strong></td>
                            <td>
                                <span title="Tokenek"><i class="fas fa-crosshairs"></i> <?php echo $cat['token_count']; ?></span> &nbsp;
                                <span title="Galériák"><i class="fas fa-images"></i> <?php echo $cat['gallery_count']; ?></span> &nbsp;
                                <span title="Fájlok"><i class="fas fa-file"></i> <?php echo $cat['file_count']; ?></span>
                            </td>
                            <td style="text-align:right;">
                                <button onclick="openEditCategoryModal(<?php echo $cat['id']; ?>, '<?php echo escape($cat['name']); ?>')" class="btn btn-small btn-secondary"><i class="fas fa-edit"></i></button>
                                <button onclick="openDeleteCategoryModal(<?php echo $cat['id']; ?>, '<?php echo escape($cat['name']); ?>', <?php echo $cat['token_count']; ?>, <?php echo $cat['gallery_count']; ?>, <?php echo $cat['file_count']; ?>)" class="btn btn-small btn-danger"><i class="fas fa-trash-alt"></i></button>
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
const allCategories = <?php echo json_encode($categories); ?>;
const allCategoriesForMove = <?php echo json_encode(array_map(function($c) { return ['id' => $c['id'], 'name' => $c['name']]; }, $categories)); ?>;
const AJAX_PHP_URL = '<?php echo BASE_URL; ?>tracker/ajax_actions.php';
</script>

<script src="<?php echo BASE_URL . 'assets/js/categories.js'; ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>