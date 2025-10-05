<?php
// === Fájl: tracker/file_stats.php (Teljesen Frissítve) ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$pageTitle = "Fájlkezelő Összefoglaló";

// === calculateStats rész: Alapvető összesítő statisztikák ===
$statsStmt = $db->prepare("
    SELECT
        COUNT(id) as total_files,
        SUM(file_size) as total_size,
        SUM(download_count) as total_downloads
    FROM files
    WHERE user_id = :user_id
");
$statsStmt->execute([':user_id' => $currentUserId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// A teljes lemezhasználat becslése (uploads mappa mérete)
// Figyelem: Ez lassú lehet sok fájl esetén. Később érdemes lehet cache-elni.
$totalDiskUsage = 0;
$uploadsDir = __DIR__ . '/../uploads/';
$filesInDir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
foreach ($filesInDir as $file) {
    $totalDiskUsage += $file->getSize();
}


require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-chart-pie"></i> <?php echo escape($pageTitle); ?></h1>
    <a href="<?php echo BASE_URL . 'tracker/files.php'; ?>" class="btn btn-info"><i class="fas fa-arrow-left"></i> Vissza a Fájlkezelőhöz</a>
</div>

<!-- STATISZTIKAI KÁRTYÁK (FRISSÍTVE) -->
<div class="dashboard-grid">
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-file"></i> Összes Fájl</h3>
        <p class="stat-value"><?php echo number_format($stats['total_files'] ?? 0, 0, ',', ' '); ?></p>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-database"></i> Fájlok Mérete</h3>
        <p class="stat-value"><?php echo formatBytes($stats['total_size'] ?? 0); ?></p>
        <small>Az adatbázisban rögzített méret</small>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-download"></i> Összes Letöltés</h3>
        <p class="stat-value"><?php echo number_format($stats['total_downloads'] ?? 0, 0, ',', ' '); ?></p>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-hdd"></i> Teljes Lemezhasználat</h3>
        <p class="stat-value"><?php echo formatBytes($totalDiskUsage); ?></p>
        <small>A teljes `uploads` mappa mérete</small>
    </div>
</div>

<!-- GRAFIKONOK -->
<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-chart-line"></i> Napi Aktivitási Trendek (Elmúlt 30 nap)</h2>
    <div class="chart-container glass-effect" style="height: 350px; padding: 15px;">
        <canvas id="fileActivityTrendsChart"></canvas>
    </div>
</div>

<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-folder-open"></i> Fájltípusok Eloszlása</h2>
        <div class="chart-container glass-effect" style="height: 320px; padding: 15px; display: flex; align-items: center; justify-content: center;">
            <canvas id="fileTypeDistributionChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-clock"></i> Összesített Aktivitás Óránként</h2>
        <div class="chart-container glass-effect" style="height: 320px; padding: 15px;">
            <canvas id="fileHourlyActivityChart"></canvas>
        </div>
    </div>
</div>

<script>
    const ajaxBaseUrl = '<?php echo BASE_URL; ?>tracker/ajax_chart_data.php';
</script>
<script src="<?php echo BASE_URL . 'assets/js/file_stats.js'; ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>