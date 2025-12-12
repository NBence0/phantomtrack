<?php
// === Fájl: tracker/file_requests.php (EGYESÍTETT: Korlátozott + Állandó) ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/TableHelper.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Fájlbekérő Linkek";

// === RENDEZÉSI LOGIKA ===
$allowedSortColumns = ['name', 'created_at', 'upload_count', 'max_uploads', 'expiry_time', 'token_type'];
$sort = $_GET['sort'] ?? 'created_at';
$dir = $_GET['dir'] ?? 'desc';

if (!in_array($sort, $allowedSortColumns)) $sort = 'created_at';
if (!in_array($dir, ['asc', 'desc'])) $dir = 'desc';

$sqlSortDir = strtoupper($dir);

// === ADATOK LEKÉRDEZÉSE (MINDEN TÍPUS) ===
// A token_type alapján szűrünk mindkét típusra
$stmt = $db->prepare(
    "SELECT id, token_value, name, created_at, is_active, max_uploads, upload_count, expiry_time, token_type 
     FROM tokens 
     WHERE user_id = :user_id 
       AND token_type IN ('file_request_limited', 'file_request_permanent')
     ORDER BY $sort $sqlSortDir"
);
$stmt->execute([':user_id' => $currentUserId]);
$requests = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-file-import"></i> <?php echo escape($pageTitle); ?></h1>
    <button class="btn btn-primary" onclick="openAddModal()">
        <i class="fas fa-plus"></i> Új Fájlbekérő
    </button>
</div>

<!-- ÚJ BEKÉRŐ LÉTREHOZÁSA (Kombinált Modális Ablak) -->
<div id="addRequestModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('addRequestModal').style.display='none'">×</span>
        <h2>Új Fájlbekérő Létrehozása</h2>
        <form id="addRequestForm">
            <?php echo csrfInput(); ?>
            <!-- Az action dinamikusan lesz beállítva JS-el (create_limited_request vagy create_permanent_request) -->
            <input type="hidden" name="action" id="add_form_action" value="create_limited_request">
            
            <div class="form-group">
                <label>Típus:</label>
                <div class="radio-group" style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <label style="cursor: pointer;">
                        <input type="radio" name="req_type" value="limited" checked onchange="toggleRequestType('limited')"> 
                        ⏳ Korlátozott
                    </label>
                    <label style="cursor: pointer;">
                        <input type="radio" name="req_type" value="permanent" onchange="toggleRequestType('permanent')"> 
                        ♾️ Állandó
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label for="request_name">Bekérő Neve:</label>
                <input type="text" id="request_name" name="name" required placeholder="pl. Projekt Dokumentáció">
            </div>

            <!-- Ez a blokk csak akkor látszik, ha Korlátozott a típus -->
            <div id="limited_options">
                <div class="form-group">
                    <label for="max_uploads">Maximális feltöltések száma:</label>
                    <input type="number" id="max_uploads" name="max_uploads" value="1" min="1">
                </div>
                <div class="form-group">
                    <label for="add_expiry_time">Lejárati dátum (opcionális):</label>
                    <input type="text" id="add_expiry_time" name="expiry_time" class="flatpickr-datetime" placeholder="Nincs lejárat">
                </div>
                <p class="text-muted" style="font-size: 0.9em;">
                    A link inaktívvá válik a feltöltési limit vagy a dátum elérésekor.
                </p>
            </div>
            
            <!-- Ez a blokk csak akkor látszik, ha Állandó a típus -->
            <div id="permanent_info" style="display: none;">
                <p class="text-info" style="font-size: 0.9em; color: var(--color-info);">
                    <i class="fas fa-info-circle"></i> Az állandó linkek sosem járnak le, és korlátlan számú fájlt fogadhatnak.
                </p>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Létrehozás</button>
        </form>
    </div>
</div>

<!-- SZERKESZTÉS MODÁLIS ABLAK -->
<div id="editRequestModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="document.getElementById('editRequestModal').style.display='none'">×</span>
        <h2>Fájlbekérő Szerkesztése</h2>
        <form id="editRequestForm">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="update_limited_request"> <!-- Alapértelmezett, JS felülírhatja -->
            <input type="hidden" id="edit_token_id" name="token_id">
            
            <div class="form-group">
                <label for="edit_request_name">Bekérő Neve:</label>
                <input type="text" id="edit_request_name" name="name" required>
            </div>

            <!-- Korlátozott opciók szerkesztése -->
            <div id="edit_limited_options">
                <div class="form-group">
                    <label for="edit_max_uploads">Maximális feltöltések száma:</label>
                    <input type="number" id="edit_max_uploads" name="max_uploads" min="1">
                </div>
                <div class="form-group">
                    <label for="edit_expiry_time">Lejárati dátum (opcionális):</label>
                    <input type="text" id="edit_expiry_time" name="expiry_time" class="flatpickr-datetime" placeholder="Nincs lejárat">
                </div>
            </div>
            
            <p id="edit_permanent_msg" style="display:none; color: var(--text-muted);">
                Állandó linkeknél csak a név szerkeszthető.
            </p>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Mentés</button>
        </form>
    </div>
</div>

<div class="table-container glass-effect">
    <table>
        <?php
        $table = new TableHelper($sort, $dir);
        $table->addColumn('name', 'Név', true);
        $table->addColumn('token_type', 'Típus', true, '80px'); // Rendezhető típus szerint
        $table->addColumn('token_link', 'Bekérő Link', false);
        $table->addColumn('expiry_time', 'Státusz / Lejárat', true);
        $table->addColumn('upload_count', 'Feltöltések', true);
        $table->addColumn('created_at', 'Létrehozva', true);
        $table->addColumn('actions', 'Műveletek', false, '100px');
        $table->render();
        ?>
        <tbody>
            <?php if (empty($requests)): ?>
                <tr><td colspan="7" style="text-align: center;">Nincsenek létrehozott fájlbekérők.</td></tr>
            <?php else: foreach ($requests as $link): 
                $isPermanent = ($link['token_type'] === 'file_request_permanent');
                $isExpired = !$isPermanent && $link['expiry_time'] && strtotime($link['expiry_time']) < time();
            ?>
            <tr>
                <td data-label="Név"><strong><?php echo escape($link['name']); ?></strong></td>
                
                <td data-label="Típus" style="text-align: center;">
                    <?php if ($isPermanent): ?>
                        <span title="Állandó Link" style="font-size: 1.2em;">♾️</span>
                    <?php else: ?>
                        <span title="Korlátozott Link" style="font-size: 1.2em;">⏳</span>
                    <?php endif; ?>
                </td>

                <td data-label="Bekérő Link" class="token-value-cell">
                    <input type="text" value="<?php echo BASE_URL . 'Upload.php?token=' . escape($link['token_value']); ?>" readonly class="pixel-url-input">
                    <button class="btn btn-small btn-copy" onclick="copyToClipboard(this.previousElementSibling)"><i class="far fa-copy"></i></button>
                </td>
                
                <td data-label="Státusz / Lejárat">
                    <?php if ($isPermanent): ?>
                         <span class="status-active"><i class="fas fa-infinity"></i> Mindig aktív</span>
                    <?php else: ?>
                        <?php if ($link['is_active'] && !$isExpired): ?>
                            <span class="status-active"><i class="fas fa-check-circle"></i> Aktív</span>
                            <?php if($link['expiry_time']): ?><br><small class="text-muted">Lejár: <?php echo formatTimestamp($link['expiry_time'], 'Y-m-d H:i'); ?></small><?php endif; ?>
                        <?php else: ?>
                            <span class="status-inactive"><i class="fas fa-times-circle"></i> Lejárt</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                
                <td data-label="Feltöltések">
                    <?php if ($isPermanent): ?>
                        <?php echo escape($link['upload_count']); ?> db
                    <?php else: ?>
                        <?php echo escape($link['upload_count']); ?> / <?php echo escape($link['max_uploads']); ?>
                    <?php endif; ?>
                </td>
                
                <td data-label="Létrehozva"><?php echo escape(formatTimestamp($link['created_at'])); ?></td>
                
                <td data-label="Műveletek">
                    <div class="action-buttons">
                        <?php if (!$isPermanent): // Csak akkor jelenjen meg, ha NEM állandó ?>
                            <button onclick="openEditModal(<?php echo $link['id']; ?>, 'limited')" class="btn btn-small btn-secondary" title="Szerkesztés"><i class="fas fa-edit"></i></button>
                        <?php endif; ?>
                        
                        <button onclick="deleteFileRequest(<?php echo $link['id']; ?>, '<?php echo escape(addslashes($link['name'])); ?>')" class="btn btn-small btn-danger" title="Törlés"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
// --- Flatpickr Inicializálás ---
let addFlatpickrInstance = null;
let editFlatpickrInstance = null;

const flatpickrOptions = {
    enableTime: true,
    dateFormat: "Y-m-d H:i",
    time_24hr: true,
    minDate: "today",
    locale: "hu"
};

document.addEventListener('DOMContentLoaded', function() {
    if (typeof flatpickr !== 'undefined') {
        editFlatpickrInstance = flatpickr("#edit_expiry_time", flatpickrOptions);
    }
});

// --- UI Logika: Új Létrehozása ---

function openAddModal() {
    document.getElementById('addRequestForm').reset();
    document.getElementById('addRequestModal').style.display = 'block';
    
    // Alaphelyzetbe állítás (Limited)
    document.querySelector('input[name="req_type"][value="limited"]').checked = true;
    toggleRequestType('limited');

    // Flatpickr inicializálása ha még nincs
    if (typeof flatpickr !== 'undefined' && !addFlatpickrInstance) {
        addFlatpickrInstance = flatpickr("#add_expiry_time", flatpickrOptions);
    }
}

function toggleRequestType(type) {
    const actionInput = document.getElementById('add_form_action');
    const limitedOpts = document.getElementById('limited_options');
    const permanentInfo = document.getElementById('permanent_info');
    
    // A inputok required attribútumát kezelni kell, hogy ne akadályozza a submitet
    const maxUploadsInput = document.getElementById('max_uploads');

    if (type === 'permanent') {
        actionInput.value = 'create_permanent_request';
        limitedOpts.style.display = 'none';
        permanentInfo.style.display = 'block';
        if(maxUploadsInput) maxUploadsInput.removeAttribute('required');
    } else {
        actionInput.value = 'create_limited_request';
        limitedOpts.style.display = 'block';
        permanentInfo.style.display = 'none';
        if(maxUploadsInput) maxUploadsInput.setAttribute('required', 'required');
    }
}

// Új bekérő AJAX
document.getElementById('addRequestForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    submitAjax(formData, 'addRequestModal');
});

// --- UI Logika: Szerkesztés ---

function openEditModal(tokenId, type) {
    const modal = document.getElementById('editRequestModal');
    const form = document.getElementById('editRequestForm');
    
    form.reset();
    modal.style.display = 'block';

    const limitOpts = document.getElementById('edit_limited_options');
    const permMsg = document.getElementById('edit_permanent_msg');
    
    // UI beállítása típus alapján
    if (type === 'permanent') {
        limitOpts.style.display = 'none';
        permMsg.style.display = 'block';
        // Állandó linkeknél nincs külön update endpoint a névre, de használhatjuk a limitedet is, 
        // ha a backend okos, vagy csinálhatunk egy generic update-et.
        // A jelenlegi ajax_actions.php struktúrában valószínűleg nincs 'update_permanent_request'.
        // TRÜKK: Ha csak a nevet akarjuk módosítani, a 'create_permanent_request' logika nem jó update-re.
        // Feltételezve, hogy az ajax_actions.php-ban a 'update_limited_request' csak a 'tokens' táblát frissíti,
        // és nem dob hibát, ha a max_uploads hiányzik, működhet.
        // BIZTONSÁGOS MEGOLDÁS: A form action marad update_limited_request, de a mezőket elrejtjük.
        // Ha a backend validálja a max_uploads-ot, akkor hidden mezőként beküldjük a jelenlegi értéket.
    } else {
        limitOpts.style.display = 'block';
        permMsg.style.display = 'none';
    }

    // Adatok betöltése
    const formData = new FormData();
    formData.append('action', 'get_limited_request_details'); // Ez valószínűleg jó állandóra is, ha csak token ID alapján keres
    formData.append('token_id', tokenId);
    formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

    fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                form.querySelector('#edit_token_id').value = data.token.id;
                form.querySelector('#edit_request_name').value = data.token.name;
                
                if (type !== 'permanent') {
                    form.querySelector('#edit_max_uploads').value = data.token.max_uploads;
                    if (editFlatpickrInstance) {
                        editFlatpickrInstance.setDate(data.token.expiry_time || null, false);
                    }
                }
            } else {
                showDynamicMessage(data.message, 'error');
                modal.style.display = 'none';
            }
        });
}

// Szerkesztés AJAX
document.getElementById('editRequestForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    submitAjax(formData, 'editRequestModal');
});

// --- Közös segédfüggvények ---

function submitAjax(formData, modalId) {
    fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showDynamicMessage(data.message, 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            showDynamicMessage('Hiba: ' + (data.message || 'Ismeretlen hiba'), 'error');
        }
    });
}

function deleteFileRequest(tokenId, tokenName) {
    if (confirm(`Biztosan törölni szeretnéd a(z) "${tokenName}" nevű fájlbekérőt?`)) {
        const formData = new FormData();
        formData.append('action', 'delete_file_request');
        formData.append('token_id', tokenId);
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        submitAjax(formData, null);
    }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>