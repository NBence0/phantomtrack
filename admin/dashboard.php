<?php
require_once __DIR__ . '/../includes/header.php';
requireLogin(); // Csak bejelentkezett felhasználók érhetik el

$pageTitle = "Irányítópult";
$db = getDB();

// Összesített statisztikák
$totalTokens = $db->query("SELECT COUNT(*) FROM tokens WHERE user_id = " . getCurrentUserId())->fetchColumn();
$activeTokens = $db->query("SELECT COUNT(*) FROM tokens WHERE is_active = 1 AND user_id = " . getCurrentUserId())->fetchColumn();
$totalOpens = $db->query(
    "SELECT COUNT(al.id) FROM activity_logs al JOIN tokens t ON al.token_id = t.id WHERE t.user_id = " . getCurrentUserId()
)->fetchColumn();

// Egyedi IP-k becslése (egyszerűsített)
$uniqueIps = $db->query(
    "SELECT COUNT(DISTINCT al.ip_address) FROM activity_logs al JOIN tokens t ON al.token_id = t.id WHERE t.user_id = " . getCurrentUserId()
)->fetchColumn();

// Pulse feed - legutóbbi X aktivitás
$pulseLimit = 5;
$pulseStmt = $db->prepare(
    "SELECT al.*, t.name as token_name 
     FROM activity_logs al 
     JOIN tokens t ON al.token_id = t.id 
     WHERE t.user_id = :user_id
     ORDER BY al.timestamp DESC 
     LIMIT :limit"
);
$pulseStmt->bindValue(':user_id', getCurrentUserId(), PDO::PARAM_INT);
$pulseStmt->bindValue(':limit', $pulseLimit, PDO::PARAM_INT);
$pulseStmt->execute();
$recentActivities = $pulseStmt->fetchAll();

?>

<div class="content-header">
    <h1><i class="fas fa-tachometer-alt"></i> <?php echo escape($pageTitle); ?></h1>
</div>

<div class="dashboard-grid">
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-tags"></i> Tokenek</h3>
        <p class="stat-value"><?php echo escape($activeTokens); ?> <span class="stat-label">aktív</span> / <?php echo escape($totalTokens); ?> <span class="stat-label">összes</span></p>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="far fa-eye"></i> Összes Megnyitás</h3>
        <p class="stat-value"><?php echo escape($totalOpens); ?></p>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-map-marker-alt"></i> Egyedi IP Címek (becsült)</h3>
        <p class="stat-value"><?php echo escape($uniqueIps); ?></p>
    </div>
    <!-- További statisztikai kártyák helye -->
</div>

<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-wave-square"></i> Aktivitási Folyam ("Pulse")</h2>
    <div class="pulse-feed glass-effect">
        <?php if (empty($recentActivities)): ?>
            <p>Nincs megjeleníthető aktivitás.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($recentActivities as $activity): ?>
                    <?php $uaInfo = parseUserAgent($activity['user_agent']); ?>
                    <li>
                        <span class="timestamp"><?php echo escape(formatTimestamp($activity['timestamp'], 'Y-m-d H:i')); ?></span> - 
                        Pixel <strong class="accent-text"><?php echo escape($activity['token_name']); ?></strong> megnyitva.
                        <span class="details">
                            IP: <?php echo escape($activity['ip_address']); ?>, 
                            Eszköz: <?php echo escape($uaInfo['device_type']); ?>,
                            Böngésző: <?php echo escape($uaInfo['browser_name']); ?>,
                            OS: <?php echo escape($uaInfo['os_name']); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-chart-line"></i> Grafikonok (helyőrző)</h2>
    <div class="charts-grid">
        <div class="chart-placeholder glass-effect">Napi megnyitások (idősoros)</div>
        <div class="chart-placeholder glass-effect">Legaktívabb tokenek (bar chart)</div>
        <div class="chart-placeholder glass-effect">Top Referrer domainek (bar chart)</div>
        <div class="chart-placeholder glass-effect">Böngésző, OS, Eszköz típus eloszlás (doughnut/pie)</div>
    </div>
</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>