<?php
// facefinder/clusters.php
session_start();
require_once dirname(__DIR__) . '/config.php';

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

$pageTitle = "VisionAI Klaszterek - " . $gallery_name;
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="static/css/clusters.css">
<script>
const GALLERY_TOKEN = "<?= $view_token ?>";
const DATA_BASE_URL = "<?= BASE_URL ?>data.php?id=";
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
/* A belső VisionAI app-header el van rejtve, a PhantomTrack nav veszi át a szerepét */
.app-header { display: none !important; }
/* A clusters layout a main-content alja az oldalnak, nincs padding */
.main-content { padding: 0 !important; overflow: hidden; }
.app-layout {
    height: calc(100vh - 52px);
    margin-top: 0;
}
</style>



<!-- ════════════════════ MAIN APP ════════════════════════ -->
<header class="app-header">
  <div class="brand"><span>🧠</span> VisionAI Editor: <?= $gallery_name ?></div>
  <div class="header-sep"></div>
  <div class="header-stat" id="globalStat" title="Összes arc az adatbázisban">
    <span>Klaszterek:</span> <strong id="gStatClusters">–</strong>
    <span style="margin-left:6px">Arcok:</span> <strong id="gStatFaces">–</strong>
  </div>
  <div class="header-spacer"></div>
  <button class="btn" id="btnUndo" disabled onclick="undoLastMove()" title="Ctrl+Z">
    ↩ Visszavonás <span id="undoCount" style="opacity:.6">(0)</span>
  </button>
  <div class="header-sep"></div>
  <a href="../gallery_view.php?token=<?= $view_token ?>" class="btn btn-secondary">Vissza a Galériába</a>
</header>

<main class="app-layout" id="appLayout">

  <!-- ── BAL PANEL ── -->
  <section class="panel" id="panelLeft"
    ondragover="allowDrop(event)"
    ondrop="dropHandler(event,'left')"
    ondragenter="dragEnter(event,'left')"
    ondragleave="dragLeave(event,'left')">

    <div class="toolbar">
      <!-- Sor 1: Cluster választó + stats -->
      <!-- Sor 1: Klaszter választó + stats -->
      <div class="toolbar-row">
        <select class="ctrl ctrl-cluster" id="leftCluster" onchange="onClusterChange('left')">
          <option value="">— Válassz klasztert —</option>
        </select>
        <div class="stats-chip">
          <span>Összes:</span><span class="val" id="leftTotal">0</span>
          <span>|</span>
          <span>Kijel.:</span><span class="sel" id="leftSelCount">0</span>
          <span id="leftPageLabel" style="margin-left:4px;display:none"></span>
        </div>
        <button class="btn" style="padding:4px 8px; font-size:0.75rem;" onclick="editCluster('left')" title="Klaszter nevének/jegyzetének szerkesztése">✏️ Szerkeszt</button>
        <button class="btn" style="padding:4px 8px; font-size:0.75rem;" onclick="refreshClusters()" title="Klaszterek újratöltése">🔄 Frissít</button>
      </div>
      <!-- Sor 2: Kijelölés + műveletek -->
      <div class="toolbar-row">
        <button class="btn" onclick="selectAll('left')" title="Ctrl+A">☑ Mind</button>
        <button class="btn" onclick="deselectAll('left')" title="Esc">☐ Semmi</button>
        <button class="btn" onclick="invertSelection('left')" title="Kijelölés megfordítása">⇄ Invertál</button>
        <div style="width:1px;background:var(--border);height:20px;flex-shrink:0"></div>
        <button class="btn btn-warn" onclick="selectWorst('left', 20)" title="A leggyengébb arcok kijelölése">🪄 Rosszak</button>
        <button class="btn" onclick="hideSelected('left')" title="Kijelöltek elrejtése a nézetből">🙈 Bújtat</button>
        <div style="width:1px;background:var(--border);height:20px;flex-shrink:0"></div>
        <button class="btn btn-primary" onclick="quickMove('left')" title="Kijelöltek azonnali átküldése a jobb panelre">→ Átküld</button>
        <button class="btn btn-danger ml-auto" onclick="deleteSelected('left')" title="Delete – Kijelöltek végleges törlése">🗑 Törlés</button>
      </div>
      <!-- Sor 3: Szűrők + rendezés -->
      <div class="toolbar-row">
        <input type="text" class="ctrl" id="leftFilter" placeholder="🔍 Szűrés fájlnévre..."
          style="flex:1;min-width:80px" title="Szerver-oldali szűrés, 300ms debounce">
        <select class="ctrl" id="leftSort" onchange="onSortChange('left')">
          <option value="id_desc">⏬ Legújabb elöl</option>
          <option value="score_asc">🔴 Legrosszabb elöl</option>
          <option value="score_desc">🟢 Legjobb elöl</option>
          <option value="name_asc">🔤 Fájlnév A–Z</option>
        </select>
        <span class="label-sm">Min&nbsp;Q:</span>
        <input type="range" class="ctrl" id="leftMinScore" min="0" max="1" step="0.05" value="0"
          title="Minimum minőség szűrő – csak ennél jobb arcok látszanak" onchange="onMinScoreChange('left', this.value)">
        <span class="label-sm" id="leftMinScoreVal" style="min-width:34px;color:var(--accent);font-variant-numeric:tabular-nums">0.00</span>
      </div>
    </div>

    <!-- Grid + loading overlay -->
    <div style="position:relative;flex:1;display:flex;flex-direction:column;overflow:hidden;">
      <div class="panel-loading" id="leftLoading">
        <div class="spinner"></div>
        <div class="loading-text">Töltés...</div>
      </div>
      <div class="face-grid-wrap" id="leftWrap">
        <div class="face-grid" id="leftGrid"></div>
      </div>
      <div class="pagination" id="leftPagination"></div>
    </div>
  </section>

  <!-- ── DIVIDER ── -->
  <div class="panel-divider" id="panelDivider" title="Húzással átméretezhető"></div>

  <!-- ── JOBB PANEL ── -->
  <section class="panel" id="panelRight"
    ondragover="allowDrop(event)"
    ondrop="dropHandler(event,'right')"
    ondragenter="dragEnter(event,'right')"
    ondragleave="dragLeave(event,'right')">

    <div class="toolbar">
      <!-- Sor 1 -->
      <!-- Sor 1: Klaszter + stats -->
      <div class="toolbar-row">
        <select class="ctrl ctrl-cluster" id="rightCluster" onchange="onClusterChange('right')">
          <option value="">— Válassz klasztert —</option>
        </select>
        <div class="stats-chip">
          <span>Összes:</span><span class="val" id="rightTotal">0</span>
          <span>|</span>
          <span>Kijel.:</span><span class="sel" id="rightSelCount">0</span>
          <span id="rightPageLabel" style="margin-left:4px;display:none"></span>
        </div>
        <button class="btn" style="padding:4px 8px; font-size:0.75rem;" onclick="editCluster('right')" title="Klaszter nevének/jegyzetének szerkesztése">✏️ Szerkeszt</button>
        <button class="btn" style="padding:4px 8px; font-size:0.75rem;" onclick="refreshClusters()" title="Klaszterek újratöltése">🔄 Frissít</button>
      </div>
      <!-- Sor 2: Kijelölés + műveletek -->
      <div class="toolbar-row">
        <button class="btn" onclick="selectAll('right')" title="Ctrl+A">☑ Mind</button>
        <button class="btn" onclick="deselectAll('right')" title="Esc">☐ Semmi</button>
        <button class="btn" onclick="invertSelection('right')" title="Kijelölés megfordítása">⇄ Invertál</button>
        <div style="width:1px;background:var(--border);height:20px;flex-shrink:0"></div>
        <button class="btn btn-warn" onclick="selectWorst('right', 20)">🪄 Rosszak</button>
        <button class="btn" onclick="hideSelected('right')" title="Kijelöltek elrejtése a nézetből">🙈 Bújtat</button>
        <div style="width:1px;background:var(--border);height:20px;flex-shrink:0"></div>
        <button class="btn btn-primary" onclick="quickMove('right')" title="Kijelöltek azonnali átküldése a bal panelre">← Átküld</button>
        <button class="btn btn-success" onclick="mergeLeftToRight()" title="Bal panel ÖSSZES arca → Jobb klaszter">⚡ Merge L→R</button>
        <button class="btn btn-danger ml-auto" onclick="deleteSelected('right')" title="Delete">🗑 Törlés</button>
      </div>
      <!-- Sor 3: Szűrők + rendezés -->
      <div class="toolbar-row">
        <input type="text" class="ctrl" id="rightFilter" placeholder="🔍 Szűrés fájlnévre..."
          style="flex:1;min-width:80px">
        <select class="ctrl" id="rightSort" onchange="onSortChange('right')">
          <option value="id_desc">⏬ Legújabb elöl</option>
          <option value="score_asc">🔴 Legrosszabb elöl</option>
          <option value="score_desc">🟢 Legjobb elöl</option>
          <option value="name_asc">🔤 Fájlnév A–Z</option>
        </select>
        <span class="label-sm">Min&nbsp;Q:</span>
        <input type="range" class="ctrl" id="rightMinScore" min="0" max="1" step="0.05" value="0"
          onchange="onMinScoreChange('right', this.value)">
        <span class="label-sm" id="rightMinScoreVal" style="min-width:34px;color:var(--accent);font-variant-numeric:tabular-nums">0.00</span>
      </div>
    </div>

    <div style="position:relative;flex:1;display:flex;flex-direction:column;overflow:hidden;">
      <div class="panel-loading" id="rightLoading">
        <div class="spinner"></div>
        <div class="loading-text">Töltés...</div>
      </div>
      <div class="face-grid-wrap" id="rightWrap">
        <div class="face-grid" id="rightGrid"></div>
      </div>
      <div class="pagination" id="rightPagination"></div>
    </div>
  </section>
</main>

<!-- ════════ FACE DETAIL MODAL ════════ -->
<div id="faceModal" class="modal-overlay" onclick="if(event.target===this)closeFaceModal()">
  <div class="modal-box">
    <button class="modal-close" onclick="closeFaceModal()">✕</button>
    <div class="modal-title" id="fmTitle">Face Info</div>
    <div style="position:relative; width:100%; text-align:center; margin-bottom:16px;">
      <img id="fmImg" class="modal-img" src="" alt="Face" style="margin-bottom:0; display:inline-block; max-width:100%;">
      <div id="fmBboxDraw" style="position:absolute; border: 2px solid var(--accent); display:none; pointer-events:none; box-shadow: 0 0 0 9999px rgba(0,0,0,0.4);"></div>
    </div>
    <div class="modal-meta">
      <div class="modal-meta-row"><span class="mk">Face ID:</span><span class="mv" id="fmId"></span></div>
      <div class="modal-meta-row"><span class="mk">Fájl:</span><span class="mv" id="fmFile"></span></div>
      <div class="modal-meta-row"><span class="mk">Score:</span><span class="mv" id="fmScore"></span></div>
      <div class="modal-meta-row"><span class="mk">Kor / Nem:</span><span class="mv" id="fmDemo"></span></div>
      <div class="modal-meta-row"><span class="mk">Póz (P/Y/R):</span><span class="mv" id="fmPose"></span></div>
      <div class="modal-meta-row"><span class="mk">BBox:</span><span class="mv" id="fmBbox"></span></div>
      <div class="modal-meta-row" style="margin-top:10px;">
        <a id="fmDownloadSrc" href="#" download class="btn" style="text-decoration:none;font-size:0.75rem;">⬇️ Eredeti kép letöltése</a>
        <a id="fmDownloadThumb" href="#" download class="btn" style="text-decoration:none;font-size:0.75rem;">⬇️ Arc kivágat letöltése</a>
      </div>
    </div>
  </div>
</div>

<!-- ════════ CONFIRM MODAL ════════ -->
<div id="confirmModal" class="modal-overlay">
  <div class="modal-box" style="max-width:400px">
    <div class="modal-title" id="confirmTitle">Megerősítés</div>
    <div class="confirm-msg" id="confirmMsg"></div>
    <div class="confirm-actions">
      <button class="btn" id="confirmCancel">Mégsem</button>
      <button class="btn btn-danger" id="confirmOk">Igen, törlöm</button>
    </div>
  </div>
</div>

<!-- ════════ CONTEXT MENU ════════ -->
<div id="contextMenu">
  <div class="ctx-item" onclick="ctxAction('view')">👁 Részletek</div>
  <div class="ctx-item" onclick="ctxAction('copy')">📋 Fájlnév másolása</div>
  <div class="ctx-sep"></div>
  <div class="ctx-item ctx-danger" onclick="ctxAction('delete')">🗑 Törlés azonnal</div>
</div>

<!-- ════════ HOVER ZOOM ════════ -->
<div id="hoverZoom"><img id="hoverImg" src="" alt=""></div>

<!-- ════════ TOAST ════════ -->
<div id="toastContainer"></div>

<script>
  // Move modals to body to escape main-content stacking context
  document.body.appendChild(document.getElementById('faceModal'));
  document.body.appendChild(document.getElementById('confirmModal'));
  document.body.appendChild(document.getElementById('contextMenu'));
  document.body.appendChild(document.getElementById('hoverZoom'));
  document.body.appendChild(document.getElementById('toastContainer'));
</script>
<script src="static/js/clusters.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
