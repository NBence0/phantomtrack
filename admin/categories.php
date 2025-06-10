<?php
require_once __DIR__ . '/../includes/header.php';
requireLogin();
$pageTitle = "Token Kategóriák";
$db = getDB();
$currentUserId = getCurrentUserId();

// Műveletek
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Érvénytelen kérés."; $_SESSION['flash_message_type'] = "error";
        header('Location: ' . BASE_URL . 'admin/categories.php'); exit;
    }

    if ($_POST['action'] === 'create_category') {
        $name = trim($_POST['category_name'] ?? '');
        if (!empty($name)) {
            $stmt = $db->prepare("INSERT INTO token_categories (user_id, name) VALUES (:user_id, :name)");
            if ($stmt->execute([':user_id' => $currentUserId, ':name' => $name])) {
                $_SESSION['flash_message'] = "Kategória létrehozva."; $_SESSION['flash_message_type'] = "success";
            } else {
                $_SESSION['flash_message'] = "Hiba a kategória létrehozásakor."; $_SESSION['flash_message_type'] = "error";
            }
        } else {
            $_SESSION['flash_message'] = "A kategória neve nem lehet üres."; $_SESSION['flash_message_type'] = "warning";
        }
        header('Location: ' . BASE_URL . 'admin/categories.php'); exit;
    }
    // TODO: Később edit és delete action
}

// Kategóriák listázása
$categoriesStmt = $db->prepare("SELECT id, name FROM token_categories WHERE user_id = :user_id ORDER BY name ASC");
$categoriesStmt->execute([':user_id' => $currentUserId]);
$categories = $categoriesStmt->fetchAll();
?>
<div class="content-header">
    <h1><i class="fas fa-folder-open"></i> <?php echo escape($pageTitle); ?></h1>
</div>

<div class="settings-sections" style="grid-template-columns: 1fr 2fr; gap: 30px;">
    <section class="settings-section glass-effect">
        <h2><i class="fas fa-plus-circle"></i> Új Kategória</h2>
        <form method="POST" action="">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="create_category">
            <div class="form-group">
                <label for="category_name">Kategória Neve:</label>
                <input type="text" id="category_name" name="category_name" required>
            </div>
            <button type="submit" class="btn btn-primary">Létrehozás</button>
        </form>
    </section>

    <section class="settings-section glass-effect">
        <h2><i class="fas fa-list-ul"></i> Meglévő Kategóriák</h2>
        <?php if (empty($categories)): ?>
            <p>Nincsenek még kategóriák.</p>
        <?php else: ?>
            <ul style="list-style: none; padding-left:0;">
                <?php foreach ($categories as $category): ?>
                    <li style="padding: 8px 0; border-bottom: 1px solid var(--glass-border);">
                        <?php echo escape($category['name']); ?>
                        <!-- TODO: Edit/Delete gombok később -->
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>