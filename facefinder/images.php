<?php
// facefinder/editor2.php
require_once dirname(__DIR__) . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$token = isset($_GET['token']) ? $_GET['token'] : '';
if (empty($token)) die("Hiányzó token.");

require_once dirname(__DIR__) . '/facefinder/api/db.php';
$pdo = Database::getInstance()->getConnection();
$stmt = $pdo->prepare("SELECT id, name, view_token FROM galleries WHERE view_token = ?");
$stmt->execute([$token]);
$gallery = $stmt->fetch();
if (!$gallery) die("Érvénytelen token.");

$gallery_id = $gallery['id'];
$gallery_name = htmlspecialchars($gallery['name']);
$view_token = $gallery['view_token'];

$pageTitle = "VisionAI Képek - " . $gallery_name;
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="static/css/images.css">
<script>
const GALLERY_TOKEN = "<?= $view_token ?>";
const originalFetch = window.fetch;
window.fetch = function() {
    let [resource, config] = arguments;
    if (typeof resource === 'string' && resource.includes('api/')) {
        if (!resource.includes('token=')) {
            let sep = resource.includes('?') ? '&' : '?';
            resource += sep + 'token=' + GALLERY_TOKEN;
        }
    }
    return originalFetch(resource, config);
};
</script>
<style>
/* PhantomTrack layout override */
.main-content { padding: 0 !important; }
.control-bar { top: 0; position: sticky; }
.app-container { min-height: calc(100vh - 52px); padding-bottom: 80px; }
</style>

<div class="app-container">
    
    <!-- STICKY CONTROL BAR -->
    <div class="control-bar">
        <div class="control-bar-left">
            <div class="control-brand">🧠 <span>Képek: <?= $gallery_name ?></span></div>
            <div class="control-stats" id="pageStats">Betöltés...</div>
        </div>
        <div class="control-bar-right">
            <input type="range" id="gridSizeSlider" min="150" max="400" value="200" oninput="changeGridSize(this.value)" style="width:120px;" title="Képméret">
        </div>
    </div>
    
    <!-- ACTION BAR (Több-elemes törléshez) -->
    <div id="actionBar" class="action-bar" style="display:none; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:rgba(20,20,30,0.9); border:1px solid #00e5ff; padding:10px 20px; border-radius:30px; z-index:1000; box-shadow:0 10px 30px rgba(0,0,0,0.5); backdrop-filter:blur(10px); align-items:center;">
        <span style="color:#fff; font-weight:bold; margin-right:15px; font-size:1.1rem;">Kijelölve: <span id="selCount" style="color:#00e5ff;">0</span></span>
        <button onclick="deleteSelected()" style="background:#ff3366; color:#fff; border:none; padding:8px 15px; border-radius:15px; cursor:pointer; font-weight:bold; margin-right:10px; transition:0.2s;"><i class="fas fa-trash"></i> Törlés</button>
        <button onclick="deselectAll()" style="background:#333; color:#fff; border:1px solid #555; padding:8px 15px; border-radius:15px; cursor:pointer; transition:0.2s;">Mégsem</button>
    </div>
    
    <!-- GALLERY GRID -->
    <div class="gallery-grid" id="galleryGrid">
        <div class="loading-state"><div class="spinner"></div>Képek betöltése...</div>
    </div>

    <!-- INFINITE SCROLL SENTINEL & LOADER -->
    <div id="scrollLoader" style="display:none;justify-content:center;padding:24px;color:#aaa;gap:12px;align-items:center;">
        <div class="spinner"></div> Következő 30 kép betöltése...
    </div>
    <div id="scrollEndMsg" style="display:none;text-align:center;padding:24px;color:#00e676;font-size:.9rem;"></div>
    <div id="scrollSentinel" style="height:1px;"></div>

</div>

<!-- === LIGHTBOX MODAL === -->
<div id="lightbox" class="lightbox-overlay" onclick="if(event.target===this)closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()" title="Bezárás (Esc)">✕</button>
    <button class="lightbox-nav prev" id="lbPrev" title="Előző (Bal nyíl)">‹</button>
    <div class="lightbox-content" id="lbContent">
        <div class="lightbox-img-wrapper" id="lbWrapper">
            <img id="lbImg" class="lightbox-img" src="" draggable="false" style="display:none;">
            <video id="lbVid" class="lightbox-img" controls style="display:none;"></video>
            <div id="lbBboxContainer" class="bbox-layer"></div>
        </div>
        
        <!-- === ÚJ CONTROL PANEL (Lightbox Belsejében) === -->
        <div class="lb-controls" id="lbControls">
            <span id="lbSelInfo" style="display:none; color:#00e5ff; font-weight:bold; margin-right:15px; font-size:1.1rem;">Kijelölve: <span id="lbSelCount">0</span></span>
            <button id="btnLbDelete" style="display:none; background:#ff3366; color:#fff; border:none; padding:8px 15px; border-radius:15px; cursor:pointer; font-weight:bold; margin-right:10px; transition:0.2s;" onclick="deleteSelected()"><i class="fas fa-trash"></i> Törlés</button>
            <button id="btnLbDeselect" style="display:none; background:#333; color:#fff; border:1px solid #555; padding:8px 15px; border-radius:15px; cursor:pointer; margin-right:20px; transition:0.2s;" onclick="deselectAll()">Mégsem</button>

            <button id="btnTagMode" class="btn-lb-control btn-lb-tag" onclick="toggleTagMode()" title="Rajzolj kézzel arckeretet a képre">
                <i class="fas fa-crop-alt"></i> Új Arc (Tag)
            </button>
        </div>
    </div>
    <button class="lightbox-nav next" id="lbNext" title="Következő (Jobb nyíl)">›</button>
</div>

<script>
    // Move lightbox to body to escape .main-content stacking context
    document.body.appendChild(document.getElementById('lightbox'));
</script>
<script src="static/js/images.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
