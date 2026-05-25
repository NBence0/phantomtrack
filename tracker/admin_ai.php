<?php
// tracker/admin_ai.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();
if (!isAdmin()) {
    die("Nincs hozzáférésed a VisionAI rendszer beállításokhoz.");
}

$pageTitle = "VisionAI Rendszer & Démon (Globális)";
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav.php';
?>

    <div class="content-header">
        <h1><i class="fas fa-brain"></i> VisionAI Adminisztráció</h1>
        <span class="secondary-text">Globális Arckereső Rendszer Kezelése</span>
    </div>

    <style>
    #toast {
        display: flex !important;
        opacity: 0;
        pointer-events: none;
        transition: opacity .3s;
        z-index: 9999;
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: var(--glass-bg);
        border: 1px solid var(--glass-border);
        color: #fff;
        padding: 15px 25px;
        border-radius: var(--border-radius);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }
    #toast.show { opacity: 1; pointer-events: auto; }
    #toast.err { border-color: rgba(255,71,87,.5); color: #ff4757; }
    #toast.ok { border-color: rgba(46,213,115,.5); color: #2ed573; }
    
    .console-wrap {
        height: 300px;
        overflow-y: auto;
        font-family: monospace;
        font-size: 0.85em;
        padding: 15px;
        color: #a0aec0;
        border-top: 1px solid var(--glass-border);
        margin-top: 10px;
    }
    .console-header {
        display: flex;
        justify-content: space-between;
        font-size: 0.9em;
        color: #cbd5e1;
        padding: 0 10px 10px 10px;
    }
    .log-entry { margin-bottom: 2px; }
    .log-err { color: #ff4757; }
    .log-warn { color: #ffa502; }
    .log-ok { color: #2ed573; }
    .log-cmd { color: #00d4ff; font-weight: bold; }
    
    .upload-area-custom {
        border: 2px dashed var(--accent);
        border-radius: var(--border-radius);
        padding: 30px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        background: rgba(255, 255, 255, 0.02);
        margin-top: 15px;
    }
    .upload-area-custom:hover {
        background: rgba(var(--accent-rgb), 0.1);
        border-color: #fff;
    }
    
    .action-group {
        margin-bottom: 15px;
        padding-bottom: 15px;
        border-bottom: 1px solid var(--glass-border);
    }
    .action-group:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }
    .action-group strong {
        display: block;
        margin-bottom: 10px;
        color: var(--text-color);
    }
    .btn-group {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    </style>

    <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <div class="stat-card glass-effect" id="stat-status">
            <h3><i class="fas fa-server"></i> Démon Státusz</h3>
            <p class="stat-value" id="status" style="font-size: 1.5em;">—</p>
        </div>
        <div class="stat-card glass-effect">
            <h3><i class="fas fa-layer-group"></i> Sorban (Queue)</h3>
            <p class="stat-value" id="queue" style="font-size: 1.5em;">0</p>
        </div>
        <div class="stat-card glass-effect">
            <h3><i class="fas fa-spinner fa-pulse"></i> Feldolgozás</h3>
            <p class="stat-value" id="tasks" style="font-size: 1.5em; color: var(--accent);">0</p>
        </div>
        <div class="stat-card glass-effect">
            <h3><i class="fas fa-check-circle"></i> Kész</h3>
            <p class="stat-value" id="done" style="font-size: 1.5em; color: #2ed573;">0</p>
        </div>
        <div class="stat-card glass-effect">
            <h3><i class="fas fa-times-circle"></i> Hiba</h3>
            <p class="stat-value" id="failed" style="font-size: 1.5em; color: #ff4757;">0</p>
        </div>
    </div>

    <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr;">
        <!-- Műveletek Panel -->
        <div class="dashboard-section glass-effect" style="padding: 20px;">
            <h2 class="section-title" style="margin-bottom: 20px;"><i class="fas fa-tools"></i> Rendszer Műveletek</h2>
            
            <div class="action-group">
                <strong>Démon Kezelés:</strong>
                <div class="btn-group">
                    <button class="btn btn-primary" style="background:#2ed573;border:none;" onclick="doAction('start_daemon')"><i class="fas fa-play"></i> Indítás</button>
                    <button class="btn btn-danger" onclick="doAction('kill_daemon')"><i class="fas fa-stop"></i> Leállítás</button>
                </div>
            </div>
            
            <div class="action-group">
                <strong>Feldolgozás (Sorba rakás):</strong>
                <div class="btn-group">
                    <button class="btn btn-secondary" onclick="doAction('index_all')"><i class="fas fa-search"></i> Teljes Indexelés</button>
                    <button class="btn btn-secondary" style="background:#ffa502;border:none;color:#fff;" onclick="doAction('run_cluster')"><i class="fas fa-project-diagram"></i> Klaszterezés</button>
                </div>
            </div>
            
            <div class="action-group">
                <strong>Karbantartás:</strong>
                <div class="btn-group">
                    <button class="btn btn-danger" onclick="cleanAllClusters()"><i class="fas fa-trash-alt"></i> Összes Klaszter Törlése</button>
                    <button class="btn btn-secondary" onclick="doAction('cleanup')"><i class="fas fa-broom"></i> Sor Tisztítása</button>
                </div>
            </div>
        </div>

        <!-- Offline Képfeldolgozás Panel -->
        <div class="dashboard-section glass-effect" style="padding: 20px;">
            <h2 class="section-title" style="margin-bottom: 20px;"><i class="fas fa-hdd"></i> Offline Képfeldolgozás</h2>
            
            <p style="margin-bottom: 15px;">
                Ha az AI feldolgozást egy erősebb, külső GPU-s gépen szeretnéd elvégezni, itt letöltheted a feldolgozásra váró feladatokat, majd visszatöltheted az eredményt.
            </p>
            
            <div style="text-align:center; margin-bottom:20px;">
                <button class="btn btn-primary" onclick="exportGlobalPendingJobs()" style="font-size:1.1em; padding:10px 20px;">
                    <i class="fas fa-file-download"></i> Függő Képek Letöltése (JSON)
                </button>
            </div>
            
            <hr style="border-color: var(--glass-border); margin: 20px 0;">
            
            <div class="upload-area-custom" id="dropZone" onclick="document.getElementById('resultsFile').click()">
                <i class="fas fa-file-upload" style="font-size:2em; margin-bottom:10px; color:var(--accent);"></i><br>
                Kattints vagy húzd ide az offline_indexer.py által generált <b>results.json</b> fájlt!
            </div>
            <input type="file" id="resultsFile" accept=".json" style="display:none" onchange="uploadOfflineResults(this)">
            
            <div id="uploadProgress" style="margin-top:10px; font-size:0.9em; color:var(--accent); display:none; text-align:center;">
                <i class="fas fa-circle-notch fa-spin"></i> Feltöltés és feldolgozás folyamatban...
            </div>
            <div id="uploadResult" style="margin-top:10px; font-size:0.9em; text-align:center; font-weight:bold;"></div>
        </div>
    </div>

    <!-- LOG CONSOLE -->
    <div class="dashboard-section glass-effect" style="padding: 20px; margin-top: 20px;">
        <div class="console-header">
            <span><i class="fas fa-terminal"></i> REAL-TIME FASTAPI LOG</span>
            <span id="last-update">—</span>
        </div>
        <div class="console-wrap" id="console">
            <div id="py-logs"></div>
            <div id="log-divider" style="margin:10px 0;border-top:1px dashed var(--glass-border);display:none;"></div>
            <div id="action-logs"></div>
        </div>
    </div>
    <div id="toast"></div>

<script>
// Prefix all API calls with the correct path relative to /tracker/
const API_BASE = '../facefinder/api/';

function exportGlobalPendingJobs() {
    if(!confirm('Letöltöd a feldolgozásra váró képek listáját (pending.json) az offline indexelőhöz?')) return;
    window.location.href = API_BASE + 'editor_api.php?action=export_global_pending';
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

        fetch(API_BASE + 'editor_api.php?action=import_offline_results', {
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

function cleanAllClusters() {
    if(!confirm('VIGYÁZAT! Minden galéria minden klasztere és az összes kézzel rajzolt tag törlődni fog! Folytatod?')) return;
    doAction('clean_all_clusters');
}

function doAction(act) {
    if (act === 'clean_all_clusters') {
        fetch(API_BASE + 'editor_api.php?action=clean_all_clusters', {method: 'POST'})
            .then(r => r.json())
            .then(data => {
                if (data.success) showToast('Minden klaszter sikeresen törölve.', 'ok');
                else showToast(data.error, 'err');
            }).catch(() => showToast('Hiba', 'err'));
        return;
    }

    if (act === 'cleanup') {
        fetch(API_BASE + 'editor_api.php?action=' + act, {method: 'POST'})
            .then(r => r.json())
            .then(d => {
                if(d.success) showToast('Rendszer tisztítva.', 'ok');
                else showToast(d.error, 'err');
            }).catch(()=>showToast('Hálózati hiba.', 'err'));
        return;
    }
    
    if (act === 'run_cluster' || act === 'index_all') {
        fetch(API_BASE + 'maintenance.php?action=' + act, {method: 'POST'})
            .then(r => r.json())
            .then(d => {
                if(d.ok) showToast(d.msg || 'Művelet elindítva.', 'ok');
                else showToast(d.msg || 'Hiba.', 'err');
            }).catch(()=>showToast('Hálózati hiba.', 'err'));
        return;
    }
    
    // Daemon actions
    fetch(API_BASE + 'daemon.php?action=' + act)
        .then(r => r.json())
        .then(d => {
            if(d.ok) showToast(d.msg, 'ok');
            else showToast(d.msg, 'err');
        }).catch(()=>showToast('Hálózati hiba.', 'err'));
}

function showToast(msg, type='info') {
    const t = document.getElementById('toast');
    if (!t) return;
    t.innerText = msg;
    t.className = type + ' show';
    setTimeout(() => { t.classList.remove('show'); }, 3000);
}

// Polling and Log functions for the UI
setInterval(checkDaemonStatus, 2000);
setInterval(fetchSystemStats, 2000);

function checkDaemonStatus() {
    fetch(API_BASE + 'daemon.php?action=status')
    .then(r => r.json())
    .then(data => {
        const dStat = document.getElementById('stat-status');
        const st = document.getElementById('status');
        if (data.running) {
            dStat.style.borderColor = '#00e676';
            st.innerHTML = '<span style="color:#00e676;">ON (' + data.pid + ')</span>';
        } else {
            dStat.style.borderColor = '#ff4757';
            st.innerHTML = '<span style="color:#ff4757;">OFF</span>';
        }
    }).catch(e => {
        document.getElementById('status').innerHTML = '<span style="color:#ff4757;">ERR</span>';
    });
}

function fetchSystemStats() {
    fetch(API_BASE + 'editor_api.php?action=get_system_stats')
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            document.getElementById('queue').textContent = data.stats.pending || 0;
            document.getElementById('tasks').textContent = data.stats.processing || 0;
            document.getElementById('done').textContent = data.stats.done || 0;
            document.getElementById('failed').textContent = data.stats.failed || 0;
            
            // PyLogs 
            const pyL = document.getElementById('py-logs');
            if (data.pylog && data.pylog.length > 0) {
                pyL.innerHTML = data.pylog.join('<br>');
            }
            document.getElementById('last-update').textContent = new Date().toLocaleTimeString();
        }
    }).catch(e => console.error(e));
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
