<?php
// facefinder/ai.php
session_start();
if (!isset($_SESSION['ai_ok'])) {
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>VisionAI - Login</title>
    <link rel="stylesheet" href="static/css/system.css">
</head>
<body class="auth-body">
    <div class="auth-card">
        <h2>🤖 VisionAI</h2>
        <input type="password" id="pw" class="auth-input" placeholder="Jelszó" onkeydown="if(event.key==='Enter')login()">
        <button class="auth-btn" onclick="login()">ENTER</button>
    </div>
    <script src="static/js/system.js"></script>
</body>
</html>
<?php 
    exit; 
} 
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <title>VisionAI Rendszer</title>
    <link rel="stylesheet" href="static/css/system.css">
</head>
<body>
<div class="sidebar">
    <div class="brand">⚡ VisionAI</div>

    <div class="section-label">🖥 Démon</div>
    <button class="btn green" onclick="doAction('start_daemon')">▶ Démon Indítása</button>
    <button class="btn danger" onclick="doAction('kill_daemon')">⏹ Démon Leállítása</button>

    <hr class="divider">
    <div class="section-label">📦 Indexelés</div>
    <button class="btn" onclick="doAction('index_all')">🔍 Teljes Indexelés</button>
    <button class="btn warn" onclick="doAction('db_supplement')">➕ DB Kiegészítés</button>
    <button class="btn" onclick="doAction('run_cluster')">🧠 Klaszterezés</button>

    <hr class="divider">
    <div class="section-label">🛠 Karbantartás</div>
    <button class="btn accent" onclick="document.getElementById('db-upload').click()">📂 .db Feltöltés</button>
    <input type="file" id="db-upload" accept=".db" style="display:none" onchange="uploadDb(this.files[0])">
    <button class="btn danger" onclick="cleanClusters()">🧹 Klaszterek Törlése</button>
    <button class="btn accent" onclick="doAction('fix_paths')">🔧 Útvonalak Javítása</button>
    <button class="btn accent" onclick="doAction('fix_perms')">🔑 Írási Jogok Javítása</button>
    <button class="btn" onclick="doAction('cleanup')">🧹 Tisztítás (Cache/Lock)</button>
    <button class="btn warn" onclick="dbExport()">💾 DB Backup Letöltés</button>
</div>

<div class="main">
    <div class="stats">
        <div class="stat-item" id="stat-status">DÉMON: <b id="status">—</b></div>
        <div class="stat-item">QUEUE: <b id="queue">0</b></div>
        <div class="stat-item">FELDOLGOZÁS: <b id="tasks">0</b></div>
        <div class="stat-item">KÉSZ: <b id="done">0</b></div>
        <div class="stat-item">HIBA: <b id="failed">0</b></div>
    </div>
    <div class="console-container">
        <div class="console-header">
            <span>▸ REAL-TIME FASTAPI LOG</span>
            <span id="last-update">—</span>
        </div>
        <div id="console">
            <div id="py-logs"></div>
            <div id="log-divider" style="margin: 10px 0; border-top: 1px dashed #333; display:none;"></div>
            <div id="action-logs"></div>
        </div>
    </div>
</div>

<div id="toast"></div>

<script src="static/js/system.js"></script>
</body>
</html>
