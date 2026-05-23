<?php
// facefinder/index.php
session_start();
if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VisionAI Vezérlőpult</title>
<link rel="stylesheet" href="static/css/index.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>

<?php if (!isset($_SESSION['ai_ok'])): ?>
<!-- ════════════════════ AUTH SCREEN ════════════════════ -->
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo">🧠</div>
    <h1>VisionAI Platform</h1>
    <p>Kérlek, azonosítsd magad</p>
    <input type="password" id="authPass" placeholder="Jelszó..." autocomplete="current-password">
    <button class="btn btn-primary btn-full" id="authBtn" onclick="doLogin()">Belépés</button>
    <div id="authErr" class="auth-error"></div>
  </div>
</div>
<script>
function doLogin() {
  const p = document.getElementById('authPass').value;
  const btn = document.getElementById('authBtn');
  const err = document.getElementById('authErr');
  btn.disabled = true; btn.textContent = 'Belépés...';
  fetch('api/auth.php?action=login', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: 'password=' + encodeURIComponent(p)
  }).then(r => r.json()).then(res => {
    if (res.ok) location.reload();
    else { err.textContent = res.error || 'Hibás jelszó!'; btn.disabled = false; btn.textContent = 'Belépés'; }
  }).catch(() => { err.textContent = 'Hálózati hiba.'; btn.disabled = false; btn.textContent = 'Belépés'; });
}
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('authPass').addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
});
</script>
</body></html>
<?php exit; endif; ?>

<!-- ════════════════════ MAIN APP ════════════════════════ -->
<div class="dashboard-wrap">
    <header class="app-header">
      <div class="brand"><span>🧠</span> VisionAI</div>
      <div class="header-spacer"></div>
      <a href="?logout=1" class="btn btn-danger">Kijelentkezés</a>
    </header>

    <main class="dashboard-grid">
        <a href="search.php" class="dash-card card-search">
            <div class="icon">🔍</div>
            <h3>Arc Kereső & Galéria</h3>
            <p>Személyek keresése szelfi alapján és arcklaszterek böngészése a teljes adatbázisban.</p>
        </a>
        <a href="clusters.php" class="dash-card card-clusters">
            <div class="icon">👥</div>
            <h3>Klaszter Kezelő</h3>
            <p>Arcklaszterek egyesítése, hibás találatok törlése és minőségellenőrzés.</p>
        </a>
        <a href="images.php" class="dash-card card-images">
            <div class="icon">🖼️</div>
            <h3>Nyers Képek Galéria</h3>
            <p>Az indexelt képek gyors böngészése és törlése ömlesztve, vagy szűréssel.</p>
        </a>
        <a href="system.php" class="dash-card card-system">
            <div class="icon">⚙️</div>
            <h3>Rendszer és AI Démon</h3>
            <p>FastAPI worker irányítása, adatbázis karbantartás, indexelés indítása.</p>
        </a>
    </main>

    <!-- STATISZTIKÁK -->
    <section class="stats-section" id="statsSection" style="display:none;">
        <h2 class="stats-title">📊 Rendszer Állapot</h2>
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-val" id="s-faces">-</div><div class="stat-lbl">👁 Felismert Arc</div></div>
            <div class="stat-card"><div class="stat-val" id="s-images">-</div><div class="stat-lbl">🖼️ Indexelt Kép</div></div>
            <div class="stat-card"><div class="stat-val" id="s-clusters">-</div><div class="stat-lbl">👥 Klaszter</div></div>
            <div class="stat-card"><div class="stat-val" id="s-persons">-</div><div class="stat-lbl">✅ Nevesített Személy</div></div>
            <div class="stat-card"><div class="stat-val" id="s-unclust">-</div><div class="stat-lbl">❓ Nem Klaszterezett</div></div>
            <div class="stat-card"><div class="stat-val" id="s-age">-</div><div class="stat-lbl">🎂 Átlagéletkor</div></div>
            <div class="stat-card"><div class="stat-val" id="s-pending">-</div><div class="stat-lbl">⏳ Várakozó Feladat</div></div>
            <div class="stat-card"><div class="stat-val" id="s-failed">-</div><div class="stat-lbl">❌ Hibás Feladat</div></div>
        </div>
    </section>
</div>

<script>
fetch('api/editor_api.php?action=get_stats', {method:'GET'})
  .then(r => r.json()).then(res => {
    if (!res.success) return;
    document.getElementById('s-faces').textContent   = res.faces.toLocaleString('hu-HU');
    document.getElementById('s-images').textContent  = res.images.toLocaleString('hu-HU');
    document.getElementById('s-clusters').textContent= res.clusters.toLocaleString('hu-HU');
    document.getElementById('s-persons').textContent = res.persons.toLocaleString('hu-HU');
    document.getElementById('s-unclust').textContent = res.unclustered.toLocaleString('hu-HU');
    document.getElementById('s-age').textContent     = res.avg_age ? res.avg_age + ' év' : '-';
    document.getElementById('s-pending').textContent = res.pending_jobs.toLocaleString('hu-HU');
    const failEl = document.getElementById('s-failed');
    failEl.textContent = res.failed_jobs.toLocaleString('hu-HU');
    if (res.failed_jobs > 0) failEl.style.color = '#ff3366';
    document.getElementById('statsSection').style.display = 'block';
  }).catch(() => {});
</script>

</body>
</html>
