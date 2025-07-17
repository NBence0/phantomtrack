<?php
// === 1. LÉPÉS: PHP LOGIKA FELDOLGOZÁSA (HTML ELŐTT) ===
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Állandó Feltöltő Link";

// === 2. LÉPÉS: ADATOK LEKÉRDEZÉSE A MEGJELENÍTÉSHEZ ===
// Itt fogjuk lekérdezni a felhasználó állandó (permanent) feltöltő linkjének tokenjét.
// Ha még nincs neki, akkor itt akár létre is hozhatnánk automatikusan.

// Példa:
$stmt = $db->prepare("SELECT * FROM tokens WHERE user_id = :user_id AND token_type = 'file_request_permanent' LIMIT 1");
$stmt->execute([':user_id' => $currentUserId]);
$permanent_link_token = $stmt->fetch();
// Ha nincs, generálhatnánk egyet.

// === 3. LÉPÉS: HTML MEGJELENÍTÉS KEZDETE ===
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-link"></i> <?php echo escape($pageTitle); ?></h1>
</div>

<div class="settings-section glass-effect">
    <h2>A Te Személyes Feltöltési Címed</h2>
    <p class="text-secondary" style="margin-bottom: 20px;">
        Ez a link állandóan a tiéd. Bárkinek elküldheted, aki fájlt szeretne küldeni neked. Az ezen a linken keresztül feltöltött összes fájl a te <a href="<?php echo BASE_URL; ?>tracker/files.php">Fájlkezelődbe</a> kerül.
    </p>

    <?php if ($permanent_link_token): ?>
        <div class="form-group">
            <label for="permanent-link-input">A Te Linked:</label>
            <input type="text" id="permanent-link-input" readonly value="<?php echo BASE_URL . 'file_uploader.php?token=' . escape($permanent_link_token['token_value']); ?>" class="pixel-url-input">
        </div>
        <button class="btn btn-primary" onclick="copyToClipboard(document.getElementById('permanent-link-input'))"><i class="far fa-copy"></i> Link Másolása</button>
    <?php else: ?>
        <p>Még nincs állandó feltöltési linked. A rendszer automatikusan létre fogja hozni az első használatkor vagy egy adminisztrátor által.</p>
        <!-- Itt lehet egy "Generálás most" gomb is -->
    <?php endif; ?>

</div>

<?php 
// === 4. LÉPÉS: FOOTER BEHÍVÁSA ===
require_once __DIR__ . '/../includes/footer.php'; 
?>