<?php
// facefinder/system.php
session_start();
require_once dirname(__DIR__) . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . '/../includes/auth.php';
$is_admin = isAdmin();
if (!$is_admin) {
    die("Nincs hozzáférésed a rendszer beállításokhoz.");
}

$gallery_id = isset($_GET['gallery_id']) ? (int)$_GET['gallery_id'] : 0;
if ($gallery_id <= 0) die("Hiányzó gallery_id.");
$pageTitle = "VisionAI Rendszer & Démon";
require_once __DIR__ . '/../includes/header.php';
?>
<style>
/* ── SYSTEM PAGE ── */
.sys-wrap { display:flex; flex-direction:column; gap:16px; padding-bottom:40px; }
.sys-actionbar {
    display:flex; gap:10px; flex-wrap:wrap; align-items:center;
    padding:14px 18px; background:rgba(0,0,0,0.3);
    border-radius:10px; border:1px solid rgba(255,255,255,0.08);
}
.sys-actionbar .sys-group { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.sys-actionbar .sys-group strong { font-size:.8em; color:#a0a7d3; white-space:nowrap; margin-right:4px; }
.sys-actionbar .divider-v { width:1px; height:28px; background:rgba(255,255,255,0.1); margin:0 6px; }

.sys-stats { display:flex; flex-wrap:wrap; gap:10px; }
.sys-stat {
    flex:1; min-width:110px; padding:12px 16px;
    background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.08);
    border-radius:8px; font-size:.8em; color:#a0a7d3;
}
.sys-stat b { display:block; font-size:1.3em; color:#00d4ff; margin-top:2px; }

.sys-console-wrap {
    display:flex; flex-direction:column;
    background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.08);
    border-radius:10px; overflow:hidden; min-height:300px;
}
.sys-console-header {
    display:flex; justify-content:space-between; align-items:center;
    padding:9px 16px; border-bottom:1px solid rgba(255,255,255,0.08);
    font-size:.75em; font-weight:600; color:#00d4ff; text-transform:uppercase;
    background:rgba(0,0,0,0.2);
}
#console {
    flex:1; overflow-y:auto; padding:14px 16px;
    font-family:'JetBrains Mono','Fira Code',monospace;
    font-size:.78em; line-height:1.8; color:#a0a7d3;
    min-height:250px;
}
.sys-offline-card {
    padding:18px; background:rgba(184,115,249,0.07);
    border:1px solid rgba(184,115,249,0.25); border-radius:10px;
}
.sys-offline-card h3 { color:#b873f9; margin-bottom:10px; font-size:.95em; }
.sys-offline-card p { font-size:.82em; color:#a0a7d3; margin-bottom:12px; }
.sys-upload-area {
    border:2px dashed rgba(184,115,249,0.4); border-radius:8px;
    padding:20px; text-align:center; cursor:pointer;
    transition: background .2s; color:#b873f9; font-size:.85em;
}
.sys-upload-area:hover { background:rgba(184,115,249,0.1); }
#toast {
    position:fixed; bottom:20px; right:20px; z-index:9999;
    background:rgba(30,30,50,.95); border:1px solid rgba(0,212,255,.3);
    border-radius:8px; padding:12px 18px; font-size:.88em;
    display:none; color:#fff; box-shadow:0 8px 30px rgba(0,0,0,.5);
}
</style>

<div class="sys-wrap">

    <!-- ACTION BAR -->
    <div class="sys-actionbar">
        <div class="sys-group">
            <strong>Démon:</strong>
            <button class="btn btn-small" style="background:#00e676;color:#000;" onclick="doAction('start_daemon')">&#9654; Indítás</button>
            <button class="btn btn-small" style="background:#ff3366;color:#fff;" onclick="doAction('kill_daemon')">&#9209; Leállítás</button>
        </div>
        <div class="divider-v"></div>
        <div class="sys-group">
            <strong>Indexelés:</strong>
            <button class="btn btn-small" style="background:#8a2be2;color:#fff;" onclick="doAction('scan_gallery')">&#128269; Teljes Indexelés</button>
            <button class="btn btn-small" style="background:#48d1cc;color:#000;" onclick="doAction('run_cluster')">&#129504; Klaszterezés</button>
        </div>
        <div class="divider-v"></div>
        <div class="sys-group">
            <strong>Karbantartás:</strong>
            <button class="btn btn-small btn-danger" onclick="cleanClusters()">&#129529; Klaszterek Törlése</button>
            <button class="btn btn-small btn-secondary" onclick="doAction('cleanup')">&#129529; Tisztítás</button>
        </div>
        <div class="divider-v"></div>
        <div class="sys-group">
            <strong>Offline:</strong>
            <button class="btn btn-small" style="background:#b873f9;color:#fff;" onclick="exportPendingJobs()">&#9203; Függő Képek (ZIP)</button>
        </div>
        <div class="divider-v" style="margin-left:auto;"></div>
        <a href="index.php?gallery_id=<?= $gallery_id ?>" class="btn btn-small btn-secondary">&#8592; Dashboard</a>
    </div>

    <!-- STATS -->
    <div class="sys-stats">
        <div class="sys-stat" id="stat-status">DÉMON<b id="status">—</b></div>
        <div class="sys-stat">QUEUE<b id="queue">0</b></div>
        <div class="sys-stat">FELDOLGOZÁS<b id="tasks">0</b></div>
        <div class="sys-stat">KÉSZ<b id="done">0</b></div>
        <div class="sys-stat">HIBA<b id="failed">0</b></div>
    </div>

    <!-- LOG CONSOLE -->
    <div class="sys-console-wrap">
        <div class="sys-console-header">
            <span>&#9654; REAL-TIME FASTAPI LOG</span>
            <span id="last-update">—</span>
        </div>
        <div id="console">
            <div id="py-logs"></div>
            <div id="log-divider" style="margin:10px 0;border-top:1px dashed #333;display:none;"></div>
            <div id="action-logs"></div>
        </div>
    </div>

    <!-- OFFLINE EREDMÉNYEK FELTÖLTÉSE -->
    <div class="sys-offline-card">
        <h3>&#128190; Offline Feldolgozás Eredményeinek Feltöltése</h3>
        <p>
            Töltsd le a függő képeket ZIP-ben, futtasd le helyi gépen az <code>offline_indexer.py</code>-t,
            majd töltsd fel ide az eredmény <code>results.json</code> fájlt.
        </p>
        <div class="sys-upload-area" id="dropZone" onclick="document.getElementById('resultsFile').click()">
            &#128228; Kattints vagy húzd ide a <b>results.json</b> fájlt
        </div>
        <input type="file" id="resultsFile" accept=".json" style="display:none" onchange="uploadOfflineResults(this)">
        <div id="uploadProgress" style="margin-top:10px; font-size:.82em; color:#00d4ff; display:none;">Feltöltés folyamatban...</div>
        <div id="uploadResult" style="margin-top:10px; font-size:.82em;"></div>
    </div>

</div>
<div id="toast"></div>

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

function exportPendingJobs() {
    if(!confirm('Letöltöd a feldolgozásra váró képeket ZIP formátumban?')) return;
    window.location.href = 'api/editor_api.php?action=export_pending_jobs&gallery_id=' + GALLERY_ID;
}

function uploadOfflineResults(input) {
    const file = input.files[0];
    if (!file) return;
    const progress = document.getElementById('uploadProgress');
    const result = document.getElementById('uploadResult');
    progress.style.display = 'block';
    result.textContent = '';

    const reader = new FileReader();
    reader.onload = function(e) {
        let parsed;
        try { parsed = JSON.parse(e.target.result); }
        catch(err) { result.textContent = 'Hibás JSON formátum!'; result.style.color='#ff4757'; progress.style.display='none'; return; }

        fetch('api/editor_api.php?action=import_offline_results&gallery_id=' + GALLERY_ID, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(parsed)
        }).then(r => r.json()).then(data => {
            progress.style.display = 'none';
            if (data.success) {
                result.textContent = '✔ Sikeresen feltöltve: ' + (data.imported || 0) + ' arc importálva.';
                result.style.color = '#00e676';
            } else {
                result.textContent = 'Hiba: ' + (data.error || 'Ismeretlen hiba.');
                result.style.color = '#ff4757';
            }
        }).catch(() => {
            progress.style.display = 'none';
            result.textContent = 'Hálózati hiba.';
            result.style.color = '#ff4757';
        });
    };
    reader.readAsText(file);
}

// Drag & drop
const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.background='rgba(184,115,249,0.15)'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.background=''; });
dropZone.addEventListener('drop', e => {
    e.preventDefault(); dropZone.style.background='';
    const file = e.dataTransfer.files[0];
    if (file) { document.getElementById('resultsFile').files = e.dataTransfer.files; uploadOfflineResults({files:[file]}); }
});
</script>
<script src="static/js/system.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
