<?php
// === Fájl: tracker/galleries.php ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Galériák Kezelése";

// Galériák lekérdezése képek számával
$stmt = $db->prepare("
    SELECT g.*, u.username, COUNT(f.id) as image_count, 
    (SELECT view_token FROM files WHERE gallery_id = g.id AND mime_type LIKE 'image/%' ORDER BY upload_timestamp DESC LIMIT 1) as cover_token
    FROM galleries g
    JOIN users u ON g.user_id = u.id  -- JOIN hozzáadva a username-hez
    LEFT JOIN files f ON g.id = f.gallery_id
    WHERE g.user_id = :user_id
    GROUP BY g.id
    ORDER BY g.created_at DESC
");
$stmt->execute([':user_id' => $currentUserId]);
$galleries = $stmt->fetchAll();

// Kategóriák a modalhoz
$catsStmt = $db->prepare("SELECT id, name FROM token_categories WHERE user_id = :uid ORDER BY name ASC");
$catsStmt->execute([':uid' => $currentUserId]);
$userCategories = $catsStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- Külső CSS az uploaderhez -->
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/uploader.css">
<style>
    /* Helyi felülírások, hogy illeszkedjen az admin designba */
    .upload-area { background: rgba(0,0,0,0.2); border: 2px dashed var(--glass-border); }
    .gallery-card { position: relative; overflow: hidden; transition: transform 0.2s; }
    .gallery-card:hover { transform: translateY(-5px); }
    .gallery-cover { height: 150px; background-size: cover; background-position: center; background-color: rgba(0,0,0,0.3); position: relative; }
    .gallery-badges { position: absolute; top: 10px; right: 10px; display: flex; gap: 5px; }
    .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.75em; font-weight: bold; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.5); }
    .badge-public { background: var(--color-success); }
    .badge-private { background: var(--color-warning); color: #333; }
    .badge-password { background: var(--color-error); }
    .gallery-actions { display: flex; justify-content: space-between; padding: 10px; border-top: 1px solid var(--glass-border); }
</style>

<div class="content-header">
    <h1><i class="fas fa-images"></i> <?php echo escape($pageTitle); ?></h1>
    <button class="btn btn-primary" onclick="openCreateGalleryModal()">
        <i class="fas fa-plus"></i> Új Galéria
    </button>
</div>

<!-- GALÉRIA LISTA -->
<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));">
    <?php if (empty($galleries)): ?>
        <div class="stat-card glass-effect" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
            <i class="fas fa-images" style="font-size: 3em; color: var(--text-secondary); margin-bottom: 20px;"></i>
            <h3>Nincs még galéria létrehozva.</h3>
            <p>Hozz létre egyet a fenti gombbal!</p>
        </div>
    <?php else: foreach ($galleries as $gallery): ?>
        <?php 
            $coverUrl = $gallery['cover_token'] 
                ? BASE_URL . 'thumbnails/' . $gallery['cover_token'] . '.webp' 
                : BASE_URL . 'assets/img/no-image.png';
        ?>
        <div class="gallery-card glass-effect">
            <div class="gallery-cover" style="background-image: url('<?php echo $coverUrl; ?>');">
                <div class="gallery-badges">
                    <?php if($gallery['visibility'] == 'public'): ?>
                        <span class="badge badge-public"><i class="fas fa-globe"></i> Publikus</span>
                    <?php elseif($gallery['visibility'] == 'password'): ?>
                        <span class="badge badge-password"><i class="fas fa-lock"></i> Jelszavas</span>
                    <?php else: ?>
                        <span class="badge badge-private"><i class="fas fa-eye-slash"></i> Privát</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-card" style="padding: 15px;">
                <h3 style="margin-bottom: 5px;"><?php echo escape($gallery['name']); ?></h3>
                <p style="font-size: 0.9em; color: var(--text-secondary); height: 40px; overflow: hidden;">
                    <?php echo escape($gallery['description'] ?: 'Nincs leírás'); ?>
                </p>
                <div style="font-size: 0.85em; color: var(--accent-secondary); margin-top: 10px;">
                    <i class="fas fa-camera"></i> <?php echo $gallery['image_count']; ?> kép
                </div>
            </div>
            <div class="gallery-actions">
                <a href="gallery_analytics.php?id=<?php echo $gallery['id']; ?>" class="btn btn-small" style="background:var(--accent);color:#000;" title="Analitika"><i class="fas fa-chart-pie"></i></a>
                <button onclick="openShareModal('<?php echo escape($gallery['username']); ?>', '<?php echo escape($gallery['slug']); ?>', '<?php echo $gallery['view_token']; ?>')" class="btn btn-small btn-info" title="Megosztás / Megtekintés"><i class="fas fa-eye"></i></button>
                <button onclick="openUploadModal(<?php echo $gallery['id']; ?>, '<?php echo escape($gallery['name']); ?>')" class="btn btn-small btn-primary" title="Feltöltés"><i class="fas fa-cloud-upload-alt"></i></button>
                <button onclick="editGallery(<?php echo $gallery['id']; ?>)" class="btn btn-small btn-secondary" title="Szerkesztés"><i class="fas fa-edit"></i></button>
                <button onclick="deleteGallery(<?php echo $gallery['id']; ?>, '<?php echo escape($gallery['name']); ?>')" class="btn btn-small btn-danger" title="Törlés"><i class="fas fa-trash-alt"></i></button>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<!-- ÚJ GALÉRIA MODAL -->
<div id="createGalleryModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="closeModal('createGalleryModal')">×</span>
        <h2><i class="fas fa-plus-circle"></i> Új Galéria</h2>
        <form id="createGalleryForm">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="create_gallery">
            
            <div class="form-group">
                <label for="gallery_name">Galéria neve:</label>
                <input type="text" id="gallery_name" name="name" required placeholder="pl. 24 Órás Vetélkedő 2025">
            </div>
            
            <div class="form-group">
                <label for="gallery_desc">Leírás (opcionális):</label>
                <textarea id="gallery_desc" name="description" placeholder="Rövid leírás az eseményről..."></textarea>
            </div>
            
            <div class="form-group">
                <label>Láthatóság:</label>
                <div class="custom-select-wrapper">
                    <select name="visibility" id="visibility" style="display:none;">
                        <option value="private">private</option>
                        <option value="public">public</option>
                        <option value="password">password</option>
                    </select>
                    <div class="select-trigger">🔒 Privát</div>
                    <div class="custom-options">
                        <span class="custom-option selected" data-value="private">🔒 Privát</span>
                        <span class="custom-option" data-value="public">🌍 Publikus</span>
                        <span class="custom-option" data-value="password">🔑 Jelszavas</span>
                    </div>
                </div>
            </div>
            <!-- KATEGÓRIA VÁLASZTÓ -->
            <div class="form-group">
                <label>Kategória:</label>
                <div class="custom-select-wrapper">
                    <select name="category_id" id="create_category_select" style="display:none;">
                        <option value="null">🚫 Nincs kategória</option>
                        <?php foreach ($userCategories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="select-trigger">🚫 Nincs kategória</div>
                    <div class="custom-options">
                        <span class="custom-option selected" data-value="null">🚫 Nincs kategória</span>
                        <?php foreach ($userCategories as $cat): ?>
                            <span class="custom-option" data-value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="form-group" id="passwordGroup" style="display:none;">
                <label for="gallery_password">Jelszó:</label>
                <input type="password" id="gallery_password" name="password" placeholder="Add meg a belépési jelszót">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Létrehozás</button>
        </form>
    </div>
</div>


<!-- SZERKESZTÉS MODAL -->
<div id="editGalleryModal" class="modal">
    <div class="modal-content glass-effect">
        <span class="close-btn" onclick="closeModal('editGalleryModal')">×</span>
        <h2><i class="fas fa-edit"></i> Galéria Szerkesztése</h2>
        <form id="editGalleryForm">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="update_gallery">
            <input type="hidden" id="edit_gallery_id" name="gallery_id">
            
            <div class="form-group">
                <label for="edit_name">Név:</label>
                <input type="text" id="edit_name" name="name" required>
            </div>
            
            <div class="form-group">
                <label for="edit_desc">Leírás:</label>
                <textarea id="edit_desc" name="description"></textarea>
            </div>
            
            <div class="form-group">
                <label>Láthatóság:</label>
                <div class="custom-select-wrapper" id="editVisibilityWrapper">
                    <select name="visibility" id="edit_visibility" style="display:none;">
                        <option value="private">private</option>
                        <option value="public">public</option>
                        <option value="password">password</option>
                    </select>
                    <div class="select-trigger">🔒 Privát</div> <!-- Alapértelmezett -->
                    <div class="custom-options">
                        <span class="custom-option" data-value="private">🔒 Privát</span>
                        <span class="custom-option" data-value="public">🌍 Publikus</span>
                        <span class="custom-option" data-value="password">🔑 Jelszavas</span>
                    </div>
                </div>
            </div>
            <!-- KATEGÓRIA VÁLASZTÓ (Szerkesztés) -->
            <div class="form-group">
                <label>Kategória:</label>
                <div class="custom-select-wrapper" id="editCategoryWrapper">
                    <select name="category_id" id="edit_category_select" style="display:none;">
                        <option value="null">🚫 Nincs kategória</option>
                        <?php foreach ($userCategories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="select-trigger">🚫 Nincs kategória</div>
                    <div class="custom-options">
                        <span class="custom-option" data-value="null">🚫 Nincs kategória</span>
                        <?php foreach ($userCategories as $cat): ?>
                            <span class="custom-option" data-value="<?php echo $cat['id']; ?>"><?php echo escape($cat['name']); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="form-group" id="editPasswordGroup" style="display:none;">
                <label for="edit_password">Új Jelszó (hagyd üresen, ha nem változik):</label>
                <input type="password" id="edit_password" name="password" placeholder="***">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Mentés</button>
        </form>
    </div>
</div>

<!-- FELTÖLTÉS MODAL (Integrálva a meglévő uploader.js-el) -->
<div id="uploadGalleryModal" class="modal" style="z-index: 1050;"> <!-- Magasabb z-index -->
    <div class="modal-content glass-effect" style="max-width: 800px;">
        <span class="close-btn" onclick="closeModal('uploadGalleryModal')">×</span>
        <h2><i class="fas fa-cloud-upload-alt"></i> Képek feltöltése: <span id="uploadTargetName" class="accent-text"></span></h2>
        
        <!-- Rejtett mező, amit a JS olvas ki a feltöltéshez -->
        <input type="hidden" id="currentGalleryId" value="">
        
        <!-- A te eredeti drag & drop zónád -->
        <div class="upload-area" id="uploadArea">
            <div class="upload-icon">📁</div>
            <div class="upload-text">Húzd ide a képeket vagy kattints</div>
            <div class="upload-buttons">
                <button class="btn btn-primary" onclick="document.getElementById('fileInput').click()">Fájlok</button>
                <button class="btn btn-secondary" onclick="document.getElementById('folderInput').click()">Mappa</button>
            </div>
            
            <!-- Progress Barok -->
            <div id="uploadQueueOverallProgress" style="display: none; margin-top: 20px;">
                <h4 style="color:var(--accent-secondary); margin-bottom:5px;">Összesített folyamat:</h4>
                <div class="progress-bar"><div class="progress-fill" id="overallProgressFill"></div></div>
                <div class="progress-info">
                    <span id="overallProgressText">0%</span>
                    <span id="overallFilesProcessedText">0/0 fájl</span>
                </div>
            </div>
            
            <div id="uploadQueueItemsContainer" style="margin-top: 15px; max-height: 200px; overflow-y: auto;"></div>
            
            <div class="progress-container" id="currentFileProgressContainer" style="display: none;">
                <p id="currentFileNameText" style="font-size:0.9em;"></p>
                <div class="progress-bar"><div class="progress-fill" id="currentFileProgressFill"></div></div>
                <div class="progress-info">
                    <span id="currentFileProgressText">0%</span>
                    <span id="currentFileSpeedText"></span>
                    <span id="currentFileChunkInfoText"></span>
                </div>
            </div>
        </div>
        
        <!-- Inputs -->
        <input type="file" id="fileInput" multiple style="display: none;" accept="image/*,video/*">
        <input type="file" id="folderInput" webkitdirectory multiple style="display: none;">
        
        <div id="success-messages-container"></div>
    </div>
</div>

<!-- LINK MEGOSZTÁS MODAL -->
<div id="shareGalleryModal" class="modal">
    <div class="modal-content glass-effect" style="max-width: 500px;">
        <span class="close-btn" onclick="closeModal('shareGalleryModal')">×</span>
        <h2><i class="fas fa-share-alt"></i> Galéria Megosztása</h2>
        <p style="margin-bottom: 20px; color: var(--text-secondary);">Válassz a linkek közül:</p>
        
        <!-- Szép URL -->
        <div class="form-group">
            <label>Szép Link (Ajánlott):</label>
            <div style="display:flex; gap:10px;">
                <input type="text" id="sharePrettyUrl" readonly class="form-control" style="background:rgba(0,0,0,0.3);">
                <button class="btn btn-secondary" onclick="copyInput('sharePrettyUrl')"><i class="fas fa-copy"></i></button>
                <a href="#" id="openPrettyUrl" target="_blank" class="btn btn-primary"><i class="fas fa-external-link-alt"></i></a>
            </div>
        </div>

        <!-- Tokenes URL -->
        <div class="form-group">
            <label>Technikai Link (Token):</label>
            <div style="display:flex; gap:10px;">
                <input type="text" id="shareTokenUrl" readonly class="form-control" style="background:rgba(0,0,0,0.3); color:#888;">
                <button class="btn btn-secondary" onclick="copyInput('shareTokenUrl')"><i class="fas fa-copy"></i></button>
            </div>
        </div>
    </div>
</div>

<script>
    // Globális változók a modalokhoz
    function openCreateGalleryModal() {
        document.getElementById('createGalleryModal').style.display = 'block';
    }
    
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
        if(modalId === 'uploadGalleryModal') {
            // Töröljük a listát bezáráskor, hogy tiszta legyen köv. alkalommal
            document.getElementById('uploadQueueItemsContainer').innerHTML = '';
            document.getElementById('success-messages-container').innerHTML = '';
            // Frissítsük az oldalt, hogy a borítókép és a darabszám frissüljön
            location.reload(); 
        }
    }

    function togglePasswordField() {
        const visibility = document.getElementById('visibility').value;
        document.getElementById('passwordGroup').style.display = (visibility === 'password') ? 'block' : 'none';
    }

    // --- FELTÖLTÉS LOGIKA HÍD ---
    function openUploadModal(galleryId, galleryName) {
        document.getElementById('currentGalleryId').value = galleryId;
        document.getElementById('uploadTargetName').textContent = galleryName;
        document.getElementById('uploadGalleryModal').style.display = 'block';
    }

    // A te uploader.js-ednek szüksége van egy success callback-re vagy felülírásra.
    // Mivel a `showSuccess` a HTML-ben volt definiálva az Upload.php-ban, itt definiálunk egy egyszerűbbet.
    function showSuccess(response) {
        // Csak csendben logolunk vagy kis pipát teszünk a sor végére, 
        // mert a queue kezelő már kiírja, hogy "Sikeres".
        // Itt opcionálisan triggerelhetünk UI frissítést.
        console.log("File uploaded:", response.file_id);
    }

    // --- AJAX FORM BEKÜLDÉS (Galéria létrehozás) ---
    document.getElementById('createGalleryForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const btn = this.querySelector('button[type="submit"]');
        const originalText = btn.innerHTML;
        btn.innerHTML = 'Mentés...';
        btn.disabled = true;

        fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showDynamicMessage(data.message, 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showDynamicMessage(data.message, 'error');
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        })
        .catch(err => {
            showDynamicMessage('Hálózati hiba.', 'error');
            btn.innerHTML = originalText;
            btn.disabled = false;
        });
    });

    function deleteGallery(id, name) {
        if(!confirm('Biztosan törlöd a(z) "' + name + '" galériát? Az összes benne lévő kép is törlődni fog!')) return;
        
        const formData = new FormData();
        formData.append('action', 'delete_gallery');
        formData.append('gallery_id', id);
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');
        
        fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) location.reload();
            else showDynamicMessage(data.message, 'error');
        });
    }

    function openShareModal(username, slug, token) {
        // URL-ek összeállítása
        const baseUrl = '<?php echo BASE_URL; ?>';
        
        // Szép URL: /gallery/username/slug
        // Figyelem: A .htaccess rewrite miatt a BASE_URL végét (pl. /phantomtrack/) figyelni kell
        // Ha a BASE_URL http://localhost/phantomtrack/, akkor a pretty: http://localhost/phantomtrack/gallery/...
        const prettyUrl = baseUrl + 'gallery/' + username + '/' + slug;
        
        // Token URL: gallery_view.php?token=...
        const tokenUrl = baseUrl + 'gallery_view.php?token=' + token;
        
        // Értékek beállítása
        document.getElementById('sharePrettyUrl').value = prettyUrl;
        document.getElementById('openPrettyUrl').href = prettyUrl;
        document.getElementById('shareTokenUrl').value = tokenUrl;
        
        // Modal nyitása
        document.getElementById('shareGalleryModal').style.display = 'block';
    }

    function copyInput(id) {
        const input = document.getElementById(id);
        
        // 1. Próbálkozás: Modern API (csak HTTPS/Localhost)
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value).then(() => {
                showDynamicMessage('Link másolva!', 'success');
            }).catch(err => {
                console.error('Modern copy failed:', err);
                fallbackCopy(input); // Ha mégis hiba van, fallback
            });
        } else {
            // 2. Próbálkozás: Fallback (HTTP)
            fallbackCopy(input);
        }
    }

    function fallbackCopy(inputElement) {
        inputElement.focus();
        inputElement.select();
        inputElement.setSelectionRange(0, 99999); // Mobilra

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                showDynamicMessage('Link másolva!', 'success');
            } else {
                showDynamicMessage('A másolás nem sikerült.', 'error');
            }
        } catch (err) {
            console.error('Fallback copy failed:', err);
            showDynamicMessage('A böngésző nem engedi a másolást.', 'error');
        }
    }

    
    // Adatok betöltése és modal nyitása
    function editGallery(id) {
        // 1. Adatok lekérése AJAX-szal
        const formData = new FormData();
        formData.append('action', 'get_gallery_details');
        formData.append('gallery_id', id);
        formData.append('csrf_token', '<?php echo generateCsrfToken(); ?>');

        fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                const g = data.gallery;
                document.getElementById('edit_gallery_id').value = g.id;
                document.getElementById('edit_name').value = g.name;
                document.getElementById('edit_desc').value = g.description;
                
                // Láthatóság UI frissítése
                updateCustomSelect('editVisibilityWrapper', 'edit_visibility', g.visibility);
                
                // Kategória UI frissítése
                updateCustomSelect('editCategoryWrapper', 'edit_category_select', g.category_id || 'null');
                
                toggleEditPassword();
                document.getElementById('editGalleryModal').style.display = 'block';
            } else {
                showDynamicMessage(data.message, 'error');
            }
        });
    }

    // Segédfüggvény a custom select frissítéséhez (ugyanaz, mint a files.php-ban)
    function updateCustomSelect(wrapperId, selectId, value) {
        const wrapper = document.getElementById(wrapperId);
        const select = document.getElementById(selectId);
        const trigger = wrapper.querySelector('.select-trigger');
        const options = wrapper.querySelectorAll('.custom-option');
        
        select.value = value;
        options.forEach(opt => {
            if (opt.dataset.value == value) {
                opt.classList.add('selected');
                trigger.textContent = opt.textContent;
            } else {
                opt.classList.remove('selected');
            }
        });
    }

    function toggleEditPassword() {
        const vis = document.getElementById('edit_visibility').value;
        document.getElementById('editPasswordGroup').style.display = (vis === 'password') ? 'block' : 'none';
    }


    // Figyeljük a rejtett select változását (amit a script.js vált ki)
    document.addEventListener('DOMContentLoaded', function() {
    // Létrehozásnál
    const visSelect = document.getElementById('visibility');
    if (visSelect) {
        visSelect.addEventListener('change', function() {
            document.getElementById('passwordGroup').style.display = (this.value === 'password') ? 'block' : 'none';
        });
    }

    // Szerkesztésnél
    const editVisSelect = document.getElementById('edit_visibility');
    if (editVisSelect) {
        editVisSelect.addEventListener('change', function() {
            document.getElementById('editPasswordGroup').style.display = (this.value === 'password') ? 'block' : 'none';
        });
    }
});
    // Form beküldése
    document.getElementById('editGalleryForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        fetch('<?php echo BASE_URL; ?>tracker/ajax_actions.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                showDynamicMessage(data.message, 'success');
                setTimeout(() => location.reload(), 500);
            } else {
                showDynamicMessage(data.message, 'error');
            }
        });
    });
</script>

<!-- Betöltjük a módosított uploader.js-t -->
<script src="<?php echo BASE_URL; ?>assets/js/uploader.js"></script>


<script>

// Globális konfiguráció az uploader.js számára
const UPLOAD_HANDLER_URL = '<?php echo BASE_URL; ?>UploadHandler.php';

</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>