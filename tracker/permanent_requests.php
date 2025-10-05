<?php
// === Fájl: tracker/permanent_requests.php ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Állandó Feltöltő Linkek";

// Adatok lekérdezése a listázáshoz
$stmt = $db->prepare(
    "SELECT id, token_value, name, created_at, upload_count 
     FROM tokens 
     WHERE user_id = :user_id AND token_type = 'file_request_permanent' 
     ORDER BY created_at DESC"
);
$stmt->execute([':user_id' => $currentUserId]);
$permanent_links = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-link"></i> <?php echo escape($pageTitle); ?></h1>
    <button class="btn btn-primary" onclick="document.getElementById('addPermanentLinkModal').style.display='block'">
        <i class="fas fa-plus"></i> Új Állandó Link
    </button>
</div>

<!-- Új Állandó Link Modális Ablak -->
<div id="addPermanentLinkModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('addPermanentLinkModal').style.display='none'">×</span>
        <h2>Új Állandó Link Létrehozása</h2>
        <form id="addPermanentLinkForm">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="create_permanent_request">
            <div class="form-group">
                <label for="permanent_link_name">Link Neve (pl. "Munkahelyi Projektek"):</label>
                <input type="text" id="permanent_link_name" name="name" required>
            </div>
            <button type="submit" class="btn btn-primary">Létrehozás</button>
        </form>
    </div>
</div>

<p class="text-secondary" style="margin-bottom: 20px;">
    Ezek a linkek állandóan aktívak, és korlátlan számú feltöltést tesznek lehetővé. Bárkinek elküldheted őket, aki fájlt szeretne küldeni neked.
</p>

<div class="table-container glass-effect">
    <table>
        <thead>
            <tr>
                <th>Név</th>
                <th>Feltöltő Link</th>
                <th>Feltöltések Száma</th>
                <th>Létrehozva</th>
                <th style="text-align:right;">Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($permanent_links)): ?>
                <tr><td colspan="5" style="text-align:center; padding: 40px;">Még nem hoztál létre állandó feltöltő linket.</td></tr>
            <?php else: ?>
                <?php foreach ($permanent_links as $link): ?>
                <tr>
                    <td data-label="Név"><?php echo escape($link['name']); ?></td>
                    <td data-label="Feltöltő Link" class="token-value-cell">
                        <input type="text" value="<?php echo BASE_URL . 'Upload.php?token=' . escape($link['token_value']); ?>" readonly class="pixel-url-input">
                        <button class="btn btn-small btn-copy" onclick="copyToClipboard(this.previousElementSibling)"><i class="far fa-copy"></i></button>
                    </td>
                    <td data-label="Feltöltések"><?php echo escape($link['upload_count']); ?> db</td>
                    <td data-label="Létrehozva"><?php echo escape(formatTimestamp($link['created_at'])); ?></td>
                    <td data-label="Műveletek">
                        <div class="action-buttons">
                            <button onclick="deleteFileRequest(<?php echo $link['id']; ?>, '<?php echo escape(addslashes($link['name'])); ?>')" class="btn btn-small btn-danger" title="Törlés">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
    // AJAX-os űrlapküldés az új link létrehozásához
    document.getElementById('addPermanentLinkForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showDynamicMessage(data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showDynamicMessage('Hiba: ' + (data.message || 'Ismeretlen hiba.'), 'error');
            }
        });
    });

    // AJAX-os törlés funkció
    function deleteFileRequest(tokenId, tokenName) {
        if (confirm(`Biztosan törölni szeretnéd a(z) "${tokenName}" nevű feltöltő linket? A már feltöltött fájlok megmaradnak.`)) {
            const formData = new FormData();
            formData.append('action', 'delete_file_request');
            formData.append('token_id', tokenId);
            formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>'); // Friss token

            fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showDynamicMessage(data.message, 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showDynamicMessage('Hiba: ' + (data.message || 'Ismeretlen hiba.'), 'error');
                }
            });
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>