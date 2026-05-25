// facefinder/static/js/ai.js
const cons = document.getElementById('console');
let lastLogContent = "";

function toast(msg, isErr = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.toggle('err', isErr);
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 3500);
}

function logLine(msg, type = 'res') {
    const div = document.createElement('div');
    div.className = 'log-entry';
    const time = new Date().toLocaleTimeString('hu-HU');
    div.innerHTML = `<span style="color:#333">[${time}]</span> <span class="log-${type}">${msg}</span>`;
    document.getElementById('action-logs').appendChild(div);
    document.getElementById('log-divider').style.display = 'block';
    if(cons) cons.scrollTop = cons.scrollHeight;
}

function getEndpoint(action) {
    if (['start_daemon', 'kill_daemon'].includes(action)) return 'api/daemon.php';
    if (['get_logs'].includes(action)) return 'api/logs.php';
    if (['scan_gallery'].includes(action)) return 'api/editor_api.php';
    return 'api/maintenance.php';
}

function doAction(action) {
    logLine(`▶ ${action.toUpperCase()}`, 'cmd');
    const statEl = document.getElementById('stat-status');
    statEl.className = 'stat-item busy';
    document.getElementById('status').textContent = 'BUSY';

    const endpoint = getEndpoint(action);
    fetch(`${endpoint}?action=${action}`, {method: 'POST'})
        .then(r => r.json())
        .then(r => {
            const ok = r.ok ?? r.success;
            const msg = r.msg || r.message || (ok ? 'Kész.' : 'Hiba.');
            const detail = r.detail ? '\n' + r.detail : '';
            logLine(`${ok ? '✓' : '✗'} ${msg}${detail}`, ok ? 'ok' : 'err');
            toast(msg, !ok);
            statEl.className = 'stat-item' + (ok ? ' ok' : '');
            document.getElementById('status').textContent = 'IDLE';
        })
        .catch(e => {
            logLine(`!! ${e.message}`, 'err');
            toast(e.message, true);
            document.getElementById('status').textContent = 'IDLE';
        });
}

function dbExport() {
    logLine('▶ DB_EXPORT — letöltés indul...', 'cmd');
    window.location.href = 'api/maintenance.php?action=db_export';
}

function cleanClusters() {
    if (confirm("Biztosan törlöd a klasztereket? A beállított személyek elvesznek.")) {
        if (confirm("Ezt nem lehet visszavonni! Tényleg törlöd?")) {
            doAction('clean_clusters');
        }
    }
}

function uploadDb(file) {
    if (!file) return;
    const mode = confirm("Kattints az OK-ra (Igen), ha a jelenlegi adatbázist szeretnéd KIEGÉSZÍTENI az új ID-kkel.\n\nKattints a Mégse (Nem) gombra, ha teljesen LE SZERETNÉD CSERÉLNI az adatbázist az újra!") ? "append" : "replace";
    
    if (mode === 'replace' && !confirm("VIGYÁZAT!\n\nBIZTOSAN lecseréled a teljes adatbázist?\nMinden korábbi adatod és beállításod felülíródik!")) {
        document.getElementById('db-upload').value = '';
        return;
    }
    
    const fd = new FormData();
    fd.append('db_file', file);
    fd.append('mode', mode);
    
    logLine(`▶ DB_UPLOAD — feltöltés indul (${mode})...`, 'cmd');
    document.getElementById('status').textContent = 'BUSY';
    fetch('api/maintenance.php?action=upload_db', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(r => {
            logLine(`${r.ok ? '✓' : '✗'} ${r.msg}`, r.ok ? 'ok' : 'err');
            toast(r.msg, !r.ok);
            document.getElementById('status').textContent = 'IDLE';
        })
        .catch(e => {
            logLine(`!! Hiba a feltöltésnél: ${e.message}`, 'err');
            toast('Feltöltési hiba', true);
            document.getElementById('status').textContent = 'IDLE';
        });
    document.getElementById('db-upload').value = '';
}

function updateLogs() {
    fetch('api/logs.php?action=get_logs', {method: 'POST'})
        .then(r => r.json())
        .then(r => {
            if (r.logs && r.logs !== lastLogContent) {
                const pyCons = document.getElementById('py-logs');
                pyCons.innerHTML = r.logs.split("\n")
                    .filter(l => l.trim() && !l.includes('/api/queue/status'))
                    .map(l => {
                        let cls = '';
                        if (/ERROR|error|Traceback|Exception/.test(l)) cls = 'log-err';
                        else if (/WARNING|warn/i.test(l)) cls = 'log-warn';
                        else if (/startup|running|Application/i.test(l)) cls = 'log-ok';
                        return `<div class="log-entry ${cls}">${escHtml(l)}</div>`;
                    }).join('');
                if(cons) cons.scrollTop = cons.scrollHeight;
                lastLogContent = r.logs;
                document.getElementById('last-update').textContent = new Date().toLocaleTimeString('hu-HU');
            }
        }).catch(() => {});
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function updateStats() {
    fetch('api/logs.php?action=get_logs', {method: 'POST'})
        .then(r => r.json())
        .then(r => {
            const el = document.getElementById('status');
            const stat = document.getElementById('stat-status');
            if (r.ok) {
                el.textContent = 'ONLINE';
                stat.className = 'stat-item ok';
            }
        }).catch(() => {
            document.getElementById('status').textContent = 'OFFLINE';
            document.getElementById('stat-status').className = 'stat-item';
        });

    // TODO: move queue status to api/maintenance.php or implement a proxy.
    // Assuming we proxy it in maintenance.php:
    fetch('api/maintenance.php?action=queue_status')
        .then(r => r.json())
        .then(r => {
            if (r.success || r.ok) {
                const s = r.status || {};
                document.getElementById('queue').textContent   = s.pending    || 0;
                document.getElementById('tasks').textContent   = s.processing || 0;
                document.getElementById('done').textContent    = s.done       || 0;
                document.getElementById('failed').textContent  = s.failed     || 0;
            }
        }).catch(() => {});
}

setInterval(updateLogs, 2000);
setInterval(updateStats, 5000);
updateStats();
updateLogs();

function login() {
    const fd = new FormData();
    fd.append('password', document.getElementById('pw').value);
    fetch('api/auth.php?action=login', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(r => r.ok ? location.reload() : alert('Hibás jelszó!'))
        .catch(() => alert('Hálózati hiba!'));
}
