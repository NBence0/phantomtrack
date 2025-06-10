<?php
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$tokenId = (int)($_GET['id'] ?? 0);

$pageTitle = "Token Szerkesztése"; // Alapértelmezett cím

if ($tokenId <= 0) {
    $_SESSION['flash_message'] = "Érvénytelen token ID.";
    $_SESSION['flash_message_type'] = "error";
    header('Location: ' . BASE_URL . 'admin/tokens.php');
    exit;
}

// admin/tokens.php (modál előtt) és admin/edit_token.php (fájl elején)
$categoriesStmt = $db->prepare("SELECT id, name FROM token_categories WHERE user_id = :user_id ORDER BY name ASC");
$categoriesStmt->execute([':user_id' => $currentUserId]);
$categoriesForSelect = $categoriesStmt->fetchAll();


// Token adatok lekérdezése szerkesztéshez
$stmt = $db->prepare("SELECT id, name, description, is_active FROM tokens WHERE id = :id AND user_id = :user_id");
$stmt->bindParam(':id', $tokenId);
$stmt->bindParam(':user_id', $currentUserId);

$stmt->execute();
$token = $stmt->fetch();

if (!$token) {
    $_SESSION['flash_message'] = "Token nem található vagy nincs jogosultságod szerkeszteni.";
    $_SESSION['flash_message_type'] = "error";
    header('Location: ' . BASE_URL . 'admin/tokens.php');
    exit;
}

$pageTitle = "Szerkesztés: " . escape($token['name']); // Cím frissítése a token nevével

// Változók inicializálása az űrlaphoz
$tokenName = $token['name'];
$tokenDescription = $token['description'];
$tokenIsActive = $token['is_active'];

// Mentés feldolgozása
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $_SESSION['flash_message'] = "Érvénytelen kérés (CSRF token hiba).";
        $_SESSION['flash_message_type'] = "error";
        // Hogy ne vesszenek el a beírt adatok, ne irányítsunk át azonnal, csak ha muszáj
    } else {
        $newName = trim($_POST['token_name'] ?? '');
        $newDescription = trim($_POST['token_description'] ?? '');
        $newIsActive = isset($_POST['is_active']) ? 1 : 0; // Checkbox-hoz
        // Ha select-et használnánk: $newIsActive = (int)($_POST['is_active'] ?? 0);

        if (empty($newName)) {
            $_SESSION['flash_message'] = "A token neve nem lehet üres.";
            $_SESSION['flash_message_type'] = "warning";
            // Frissítjük a változókat, hogy a felhasználó ne veszítse el, amit beírt
            $tokenName = $newName; 
            $tokenDescription = $newDescription;
            $tokenIsActive = $newIsActive;
        } else {
            $updateStmt = $db->prepare("UPDATE tokens SET name = :name, description = :description, is_active = :is_active WHERE id = :id AND user_id = :user_id");
            $updateStmt->bindParam(':name', $newName);
            $updateStmt->bindParam(':description', $newDescription);
            $updateStmt->bindParam(':is_active', $newIsActive, PDO::PARAM_INT);
            $updateStmt->bindParam(':id', $tokenId);
            $updateStmt->bindParam(':user_id', $currentUserId);

            if ($updateStmt->execute()) {
                $_SESSION['flash_message'] = "Token sikeresen frissítve.";
                $_SESSION['flash_message_type'] = "success";
                header('Location: ' . BASE_URL . 'admin/tokens.php'); // Vissza a listához
                exit;
            } else {
                $_SESSION['flash_message'] = "Hiba a token frissítésekor.";
                $_SESSION['flash_message_type'] = "error";
                $tokenName = $newName; 
                $tokenDescription = $newDescription;
                $tokenIsActive = $newIsActive;
            }
        }
    }
}
?>

<div class="content-header">
    <h1><i class="fas fa-edit"></i> <?php echo $pageTitle; ?></h1>
    <a href="<?php echo BASE_URL; ?>admin/tokens.php" class="btn btn-info"><i class="fas fa-arrow-left"></i> Vissza a Tokenekhez</a>
</div>

<div class="form-container glass-effect" style="max-width: 700px; margin: 20px auto; padding: var(--card-padding);">
    <form method="POST" action="<?php echo BASE_URL . 'admin/edit_token.php?id=' . $tokenId; ?>">
        <?php echo csrfInput(); ?>
        
        <div class="form-group">
            <label for="token_name">Token Neve:</label>
            <input type="text" id="token_name" name="token_name" value="<?php echo escape($tokenName); ?>" required>
        </div>
        
        <div class="form-group">
            <label for="token_description">Leírás:</label>
            <textarea id="token_description" name="token_description" rows="4"><?php echo escape($tokenDescription); ?></textarea>
        </div>
        <div class="form-group">
            <label for="token_category">Kategória:</label>
            <select id="token_category" name="token_category_id">
                <option value="">Nincs kategória</option>
                <?php foreach ($categoriesForSelect as $category): ?>
                    <option value="<?php echo $category['id']; ?>" <?php if (isset($token) && $token['category_id'] == $category['id']) echo 'selected'; ?>>
                        <?php echo escape($category['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <input type="checkbox" id="is_active" name="is_active" value="1" <?php if ($tokenIsActive) echo 'checked'; ?> style="vertical-align: middle;">
            <label for="is_active" style="display: inline-block; margin-left: 5px; font-weight:normal; color: var(--text-primary);">Aktív</label>
        </div>
        
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mentés</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>