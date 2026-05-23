<?php
// facefinder/faces.php
session_start();
require_once dirname(__DIR__) . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$gallery_id = isset($_GET['gallery_id']) ? (int)$_GET['gallery_id'] : 0;
if ($gallery_id <= 0) die("Hiányzó gallery_id.");
$pageTitle = "VisionAI Szereplők Keresése";
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="static/css/search.css">
<script>
const GALLERY_ID = <?= $gallery_id ?>;
const originalFetch = window.fetch;
window.fetch = function() {
    let [resource, config] = arguments;
    if (typeof resource === 'string' && resource.includes('api/')) {
        let sep = resource.includes('?') ? '&' : '?';
        resource += sep + 'gallery_id=' + GALLERY_ID;
    }
    return originalFetch(resource, config);
};
</script>
<style>
.topbar { display: none; } /* Hide inner topbar since we have phantomtrack nav */
.app-container { height: calc(100vh - 100px); }
</style>

<div class="topbar">
  <div class="topbar-left">
    <a href="index.php?gallery_id=<?= $gallery_id ?>" class="back-btn">← Vezérlőpult</a>
    <span class="page-title">👥 Szereplők</span>
    <span class="badge" id="personCount">...</span>
  </div>
</div>

<div class="toolbar">
  <div class="search-box">
    <span class="icon">🔍</span>
    <input type="text" id="searchInput" placeholder="Keresés névben..." oninput="filterCards()">
  </div>
  <button class="btn btn-selfie" onclick="toggleSelfie()">📷 Keresés szelfi alapján</button>
  <span class="cluster-status" id="clusterStatus"></span>
</div>

<div id="selfieZone" onclick="document.getElementById('selfieInput').click()">
  <p>Húzz ide egy szelfit, vagy kattints a feltöltéshez!</p>
  <img id="selfiePreview">
  <input type="file" id="selfieInput" accept="image/*" onchange="handleSelfieFile(this.files[0])">
  <div id="selfieResult" style="margin-top:10px;color:#00e5ff;font-size:.88rem"></div>
</div>

<div id="mainGrid" class="grid">
  <div class="loader"><div class="spinner"></div> Betöltés...</div>
</div>

<!-- GDPR Modal -->
<div id="gdprModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:9999;justify-content:center;align-items:center;">
  <div style="background:#111;border:1px solid #00e5ff;padding:28px;border-radius:14px;max-width:450px;color:#fff;box-shadow:0 10px 30px rgba(0,229,255,0.2);">
    <h3 style="color:#00e5ff;margin-top:0;">📷 Adatvédelmi tájékoztató</h3>
    <p style="margin-bottom:14px;">A feltöltött képet kizárólag a galériában való kereséshez használjuk.</p>
    <ul style="color:#aaa;line-height:1.8;margin-bottom:20px;padding-left:18px;">
      <li>Harmadik félnek <strong>NEM</strong> adjuk ki</li>
      <li>24 órán belül automatikusan törlődik rendszerünkből</li>
      <li>Személyazonosítási adatot (név, stb.) nem tárolunk hozzá</li>
    </ul>
    <div style="display:flex;gap:15px;justify-content:flex-end;">
      <button onclick="closeFacesGdprModal(false)" style="background:#333;color:#fff;border:none;padding:9px 18px;border-radius:8px;cursor:pointer;">❌ Mégsem</button>
      <button onclick="closeFacesGdprModal(true)" style="background:#00e5ff;color:#000;border:none;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:bold;">✅ Elfogadom</button>
    </div>
  </div>
</div>

<!-- PERSON MODAL -->
<div id="modal">
  <div class="modal-inner">
    <button class="modal-close" onclick="closeModal()">✕</button>
    <div class="modal-header">
      <img class="modal-avatar" id="modalAvatar">
      <div class="modal-info">
        <div class="modal-name-row">
          <span class="modal-name" id="modalName"></span>
          <button class="modal-edit-btn" onclick="toggleNameEdit()">✏️ Névadás</button>
        </div>
        <div class="modal-count" id="modalCount"></div>
        <div class="name-edit-form" id="nameEditForm">
          <input class="name-input" id="nameInput" placeholder="Pl. Bence, Péter...">
          <input class="name-input" id="notesInput" placeholder="Megjegyzés (opcionális)">
          <button class="btn-save" onclick="saveName()">Mentés</button>
        </div>
      </div>
    </div>
    <div class="photo-grid" id="modalPhotos"></div>
  </div>
</div>

<!-- LIGHTBOX -->
<div id="lightbox" class="lightbox">
  <button id="lightbox-close" onclick="closeLightbox()">✕</button>
  <button class="lbox-nav" id="lbox-prev" onclick="event.stopPropagation();lboxNav(-1)">&#10094;</button>
  <button class="lbox-nav" id="lbox-next" onclick="event.stopPropagation();lboxNav(1)">&#10095;</button>
  <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); max-width:90vw; max-height:90vh; display:flex; justify-content:center; align-items:center;">
    <img id="lightbox-img" style="max-width:90vw; max-height:90vh; display:block; box-shadow:0 10px 40px rgba(0,0,0,.5);">
    <canvas id="lightbox-canvas" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none;"></canvas>
  </div>
</div>

<script src="static/js/search.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
