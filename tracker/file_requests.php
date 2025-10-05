<?php
// === Fájl: tracker/file_requests.php (bővített verzió) ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Korlátozott Fájlbekérők";

// Adatok lekérdezése a listázáshoz
$stmt = $db->prepare(
    "SELECT id, token_value, name, created_at, is_active, max_uploads, upload_count, expiry_time 
     FROM tokens 
     WHERE user_id = :user_id AND token_type = 'file_request_limited' 
     ORDER BY created_at DESC"
);
$stmt->execute([':user_id' => $currentUserId]);
$limited_requests = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-file-import"></i> <?php echo escape($pageTitle); ?></h1>
    <button class="btn btn-primary" onclick="document.getElementById('addLimitedRequestModal').style.display='block'">
        <i class="fas fa-plus"></i> Új Fájlbekérő
    </button>
</div>

<!-- Új Korlátozott Bekérő Modális Ablak -->
<div id="addLimitedRequestModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('addLimitedRequestModal').style.display='none'">×</span>
        <h2>Új Korlátozott Fájlbekérő</h2>
        <form id="addLimitedRequestForm">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="create_limited_request">
            <div class="form-group">
                <label for="limited_request_name">Bekérő Neve:</label>
                <input type="text" id="limited_request_name" name="name" required>
            </div>
            <div class="form-group">
                <label for="max_uploads">Maximális feltöltések száma:</label>
                <input type="number" id="max_uploads" name="max_uploads" value="1" min="1" required>
            </div>
            <div class="form-group">
                <label for="expiry_time">Lejárati dátum (opcionális):</label>
                <input type="text" id="add_expiry_time" name="expiry_time" class="flatpickr-datetime" placeholder="Nincs lejárat">
            </div>
            <button type="submit" class="btn btn-primary">Létrehozás</button>
        </form>
    </div>
</div>

<!-- Korlátozott Bekérő Szerkesztése Modális Ablak (ÚJ) -->
<div id="editLimitedRequestModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('editLimitedRequestModal').style.display='none'">×</span>
        <h2>Fájlbekérő Szerkesztése</h2>
        <form id="editLimitedRequestForm">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="update_limited_request">
            <input type="hidden" id="edit_token_id" name="token_id">
            <div class="form-group">
                <label for="edit_limited_request_name">Bekérő Neve:</label>
                <input type="text" id="edit_limited_request_name" name="name" required>
            </div>
            <div class="form-group">
                <label for="edit_max_uploads">Maximális feltöltések száma:</label>
                <input type="number" id="edit_max_uploads" name="max_uploads" min="1" required>
            </div>
            <div class="form-group">
                <label for="edit_expiry_time">Lejárati dátum (opcionális):</label>
                <input type="text" id="edit_expiry_time" name="expiry_time" class="flatpickr-datetime" placeholder="Nincs lejárat">
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mentés</button>
        </form>
    </div>
</div>

<p class="text-secondary" style="margin-bottom: 20px;">
    Ezek a linkek a megadott számú feltöltés után vagy a lejárati dátum elérésekor automatikusan inaktívvá válnak.
</p>

<div class="table-container glass-effect">
    <table>
        <thead>
            <tr>
                <th>Név</th>
                <th>Bekérő Link</th>
                <th>Státusz / Lejárat</th>
                <th>Feltöltések</th>
                <th>Létrehozva</th>
                <th style="text-align:right;">Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($limited_requests as $link): ?>
            <tr>
                <td data-label="Név"><?php echo escape($link['name']); ?></td>
                <td data-label="Bekérő Link" class="token-value-cell">
                    <input type="text" value="<?php echo BASE_URL . 'Upload.php?token=' . escape($link['token_value']); ?>" readonly class="pixel-url-input">
                    <button class="btn btn-small btn-copy" onclick="copyToClipboard(this.previousElementSibling)"><i class="far fa-copy"></i></button>
                </td>
                <td data-label="Státusz / Lejárat">
                    <?php 
                    $isExpired = $link['expiry_time'] && strtotime($link['expiry_time']) < time();
                    if ($link['is_active'] && !$isExpired): ?>
                        <span class="status-active"><i class="fas fa-check-circle"></i> Aktív</span>
                        <?php if($link['expiry_time']): ?><br><small>Lejár: <?php echo formatTimestamp($link['expiry_time'], 'Y-m-d H:i'); ?></small><?php endif; ?>
                    <?php else: ?>
                        <span class="status-inactive"><i class="fas fa-times-circle"></i> Lejárt/Felhasznált</span>
                    <?php endif; ?>
                </td>
                <td data-label="Feltöltések"><?php echo escape($link['upload_count']); ?> / <?php echo escape($link['max_uploads']); ?></td>
                <td data-label="Létrehozva"><?php echo escape(formatTimestamp($link['created_at'])); ?></td>
                <td data-label="Műveletek">
                    <div class="action-buttons">
                        <button onclick="openEditModal(<?php echo $link['id']; ?>)" class="btn btn-small btn-secondary" title="Szerkesztés"><i class="fas fa-edit"></i></button>
                        <button onclick="deleteFileRequest(<?php echo $link['id']; ?>, '<?php echo escape(addslashes($link['name'])); ?>')" class="btn btn-small btn-danger" title="Törlés"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // Ellenőrizzük, hogy a flatpickr betöltődött-e. Ha nem, nem csinálunk semmit, hogy ne okozzunk hibát.
    if (typeof flatpickr === 'undefined') {
        console.error('Flatpickr library is not loaded. Date pickers will not work.');
        return;
    }

    // --- Flatpickr Inicializálás ---

    let addFlatpickrInstance = null;
    let editFlatpickrInstance = null;

    const flatpickrOptions = {
        enableTime: true,
        dateFormat: "Y-m-d H:i",
        time_24hr: true,
        minDate: "today",
        locale: "hu" // Magyar nyelv beállítása
    };

    // "Új létrehozó" modál flatpickr-je
    const addModal = document.getElementById('addLimitedRequestModal');
    if (addModal) {
        // A modál megnyitásakor inicializáljuk.
        // A gomb onclick eseményére kötjük, ami egyszerűbb, mint a MutationObserver.
        const openAddModalButton = document.querySelector('button[onclick*="addLimitedRequestModal"]');
        if (openAddModalButton) {
            openAddModalButton.addEventListener('click', function() {
                if (!addFlatpickrInstance) {
                    addFlatpickrInstance = flatpickr("#add_expiry_time", flatpickrOptions);
                }
            });
        }
    }
    
    // "Szerkesztő" modál flatpickr-je (ezt előre inicializáljuk)
    const editExpiryInput = document.getElementById('edit_expiry_time');
    if (editExpiryInput) {
        editFlatpickrInstance = flatpickr(editExpiryInput, flatpickrOptions);
    }
    

    // --- AJAX Hívások és Eseménykezelők ---

    // Új bekérő űrlap elküldése
    const addForm = document.getElementById('addLimitedRequestForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
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
    }

    // Szerkesztő űrlap elküldése
    const editForm = document.getElementById('editLimitedRequestForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', { method: 'POST', body: formData })
                .then(res => res.json()).then(data => {
                    if (data.success) {
                        showDynamicMessage(data.message, 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else { showDynamicMessage('Hiba: ' + data.message, 'error'); }
                });
        });
    }

}); // DOMContentLoaded vége

// A globális névtérben kell lenniük, mert a HTML onclick hívja őket
function openEditModal(tokenId) {
    const modal = document.getElementById('editLimitedRequestModal');
    if (!modal) return;
    
    const form = document.getElementById('editLimitedRequestForm');
    form.reset();
    modal.style.display = 'block';

    const formData = new FormData();
    formData.append('action', 'get_limited_request_details');
    formData.append('token_id', tokenId);
    formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

    fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                form.querySelector('#edit_token_id').value = data.token.id;
                form.querySelector('#edit_limited_request_name').value = data.token.name;
                form.querySelector('#edit_max_uploads').value = data.token.max_uploads;
                
                // A flatpickr példányt az ablakon kívül, a DOMContentLoaded-ben már létrehoztuk.
                // Itt már csak a `setDate`-et kell hívni.
                const editExpiryInput = document.getElementById('edit_expiry_time');
                if (editExpiryInput && editExpiryInput._flatpickr) {
                    editExpiryInput._flatpickr.setDate(data.token.expiry_time || null, false);
                }
            } else {
                showDynamicMessage(data.message, 'error');
                modal.style.display = 'none';
            }
        });
}

function deleteFileRequest(tokenId, tokenName) {
    if (confirm(`Biztosan törölni szeretnéd a(z) "${tokenName}" nevű fájlbekérőt? A már feltöltött fájlok megmaradnak.`)) {
        const formData = new FormData();
        formData.append('action', 'delete_file_request');
        formData.append('token_id', tokenId);
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

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