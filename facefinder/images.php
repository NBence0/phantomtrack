<?php
// facefinder/editor2.php
session_start();
if (!isset($_SESSION['ai_ok'])) {
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>VisionAI - Képek Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="static/css/images.css">
</head>
<body>
    <div class="auth-wrapper">
      <div class="auth-box">
        <div class="auth-icon">🧠</div>
        <div class="auth-title">Editor V2</div>
        <div class="auth-subtitle">Fejlett Duplikátum Kezelő Rendszer</div>
        <form id="loginForm" onsubmit="doLogin(event)">
          <input type="password" id="pw" class="auth-input" placeholder="Admin Jelszó..." autofocus required autocomplete="current-password">
          <button type="submit" class="btn-login" id="loginBtn">Hitelesítés</button>
        </form>
        <div id="loginErr" class="auth-error"></div>
      </div>
    </div>
    <script src="static/js/images.js"></script>
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>VisionAI - Képek Galéria</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="static/css/images.css">
</head>
<body>

<div class="app-container">
    
    <!-- STICKY CONTROL BAR -->
    <div class="control-bar">
        <div class="control-bar-left">
            <div class="control-brand">🧠 <span>Képek Galéria</span></div>
            <div class="control-stats" id="pageStats">Betöltés...</div>
        </div>
        <div class="control-bar-right">
            <a href="index.php" class="header-link">Vezérlőpult →</a>
        </div>
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

<!-- === FLOATING ACTION BAR === -->
<div class="floating-action-bar" id="actionBar">
    <div class="fab-stats">
        <div class="fab-label">Kijelölve:</div>
        <div class="fab-count" id="selCount">0</div>
    </div>
    <div class="fab-divider"></div>
    <div class="fab-actions">
        <button class="btn-fab-danger" onclick="deleteSelected()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
            Törlés Véglegesen
        </button>
        <button class="btn-fab-cancel" onclick="deselectAll()">Mégsem</button>
    </div>
</div>

<!-- === LIGHTBOX MODAL === -->
<div id="lightbox" class="lightbox-overlay" onclick="if(event.target===this)closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()" title="Bezárás (Esc)">✕</button>
    <button class="lightbox-nav prev" id="lbPrev" title="Előző (Bal nyíl)">‹</button>
    <div class="lightbox-content" id="lbContent">
        <div class="lightbox-img-wrapper" id="lbWrapper">
            <img id="lbImg" class="lightbox-img" src="" draggable="false">
            <div id="lbBboxContainer" class="bbox-layer"></div>
        </div>
        <!-- Manuális Tag gomb -->
        <div style="position:absolute;bottom:14px;left:50%;transform:translateX(-50%);z-index:200;">
            <button id="btnTagMode" onclick="toggleTagMode()" title="Rajzolj kézzel arckeretet a képre"
                style="background:#1a1a2e;color:#00e5ff;border:1px solid #00e5ff;padding:7px 16px;border-radius:8px;cursor:pointer;font-size:.85rem;transition:all .2s;">
                🏷️ Tag Mód
            </button>
        </div>
    </div>
    <button class="lightbox-nav next" id="lbNext" title="Következő (Jobb nyíl)">›</button>
</div>

<script src="static/js/images.js"></script>
</body>
</html>
