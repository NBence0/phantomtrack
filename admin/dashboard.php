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


// Legfrissebb X token
$latestTokensLimit = 5;
$latestTokensStmt = $db->prepare(
    "SELECT name, token_value, is_active, created_at 
     FROM tokens 
     WHERE user_id = :user_id 
     ORDER BY created_at DESC 
     LIMIT :limit"
);
$latestTokensStmt->bindValue(':user_id', getCurrentUserId(), PDO::PARAM_INT);
$latestTokensStmt->bindValue(':limit', $latestTokensLimit, PDO::PARAM_INT);
$latestTokensStmt->execute();
$latestTokens = $latestTokensStmt->fetchAll();
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
</div>

<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-chart-line"></i> Napi Megnyitások (Összesített)</h2>
    <div class="chart-container glass-effect" style="height: 350px; padding: 15px;">
        <canvas id="dailyOpensChartOverall"></canvas>
    </div>
</div>

<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-fire-alt"></i> Legaktívabb Tokenek</h2>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="topTokensChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-globe-americas"></i> Országok Szerinti Megnyitások</h2>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="countryDistributionChart"></canvas>
        </div>
    </div>
</div>


<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fab fa-firefox-browser"></i> Böngészők</h2>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px; display: flex; justify-content: center; align-items: center;">
            <canvas id="browserDistributionChart" style="max-width: 250px; max-height: 250px;"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-desktop"></i> Operációs Rendszerek</h2>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px; display: flex; justify-content: center; align-items: center;">
            <canvas id="osDistributionChart" style="max-width: 250px; max-height: 250px;"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-mobile-alt"></i> Eszköztípusok</h2>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px; display: flex; justify-content: center; align-items: center;">
            <canvas id="deviceTypeDistributionChart" style="max-width: 250px; max-height: 250px;"></canvas>
        </div>
    </div>
</div>

<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-link"></i> Top Referrer Domainek</h2>
    <div class="chart-container glass-effect" style="height: 400px; padding: 15px;">
        <canvas id="topReferrersChart"></canvas>
    </div>
</div>

<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-calendar-week"></i> Heti Megnyitások</h2>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="weeklyOpensChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-hourglass-half"></i> Napszakok Szerinti Aktivitás</h2>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="hourlyActivityOverallChart"></canvas>
        </div>
    </div>
</div>

<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-network-wired"></i> ISP Eloszlás</h2>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px; display: flex; justify-content: center; align-items: center;">
            <canvas id="ispDistributionChart" style="max-width: 250px; max-height: 250px;"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-toggle-on"></i> Token Státusz Arány</h2>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px; display: flex; justify-content: center; align-items: center;">
            <canvas id="tokenStatusRatioChart" style="max-width: 250px; max-height: 250px;"></canvas>
        </div>
    </div>
     <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-calendar-plus"></i> Új Tokenek Havi Bontásban</h2>
        <div class="chart-container glass-effect" style="height: 280px; padding: 15px;">
            <canvas id="newTokensMonthlyChart"></canvas>
        </div>
    </div>
</div>
<div class="dashboard-grid">
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-percent"></i> Megnyitások Változása</h3>
        <p class="stat-value" id="opensChangePercentage">Betöltés...</p>
        <small id="opensChangePeriodInfo"></small>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-globe"></i> Geolokáció Telítettség</h3>
        <p class="stat-value" id="geoCompletenessCountry">Ország: Betöltés...</p>
        <p class="stat-value" id="geoCompletenessCity" style="font-size: 1.8em; margin-top: 5px;">Város: Betöltés...</p>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-calculator"></i> Átl. Megnyitás/Token</h3>
        <p class="stat-value" id="avgOpensPerToken">Betöltés...</p>
        <small id="avgOpensTotalInfo"></small>
    </div>
</div>

<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-city"></i> Top Városok</h2>
        <div class="chart-container glass-effect" style="height: 350px; padding: 15px;">
            <canvas id="topCitiesChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-robot"></i> Bot Aktivitás Aránya</h2>
        <div class="chart-container glass-effect" style="height: 350px; padding: 15px; display: flex; justify-content: center; align-items: center;">
            <canvas id="botActivityRatioChart" style="max-width: 300px; max-height: 300px;"></canvas>
        </div>
    </div>
</div>

<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-stream"></i> Tokenek Aktivitás Szerinti Eloszlása</h2>
    <div class="chart-container glass-effect" style="height: 350px; padding: 15px;">
        <canvas id="tokenActivityDistributionChart"></canvas>
    </div>
</div>
<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-calendar-day"></i> Napi Átl. Megnyitás</h3>
        <p class="stat-value" id="dailyAvgOpensVal">Betöltés...</p>
        <small id="dailyAvgOpensPeriod"></small>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-clock"></i> Legforgalmasabb Óra</h3>
        <p class="stat-value" id="busiestHourVal" style="font-size: 1.7em;">Betöltés...</p> <?php // Kisebb betűméret, ha hosszú az időintervallum ?>
        <small id="busiestHourCount"></small>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-bed"></i> Legkevésbé Aktív Tokenek</h3>
        <div id="leastActiveTokensList" class="simple-list-condensed" style="font-size:0.9em; max-height: 150px; overflow-y: auto;">
            <p>Betöltés...</p>
        </div>
    </div>
</div>
<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
    <div class="stat-card glass-effect" id="dataQualityCardUnknownBrowser">
        <h3><i class="fab fa-firefox-browser"></i> Ismeretlen Böngészők</h3>
        <p class="stat-value"><span>Betöltés...</span>%</p> <!-- Span a százalékjel mellé -->
        <small><span>0</span> abszolút (<span class="total-logs-dq">0</span> logból)</small>
    </div>
    <div class="stat-card glass-effect" id="dataQualityCardUnknownOs">
        <h3><i class="fas fa-desktop"></i> Ismeretlen OS</h3>
        <p class="stat-value"><span>Betöltés...</span>%</p>
        <small><span>0</span> abszolút (<span class="total-logs-dq">0</span> logból)</small>
    </div>
    <div class="stat-card glass-effect" id="dataQualityCardUnknownDevice">
        <h3><i class="fas fa-mobile-alt"></i> Ismeretlen Eszköz</h3>
        <p class="stat-value"><span>Betöltés...</span>%</p>
        <small><span>0</span> abszolút (<span class="total-logs-dq">0</span> logból)</small>
    </div>
    <div class="stat-card glass-effect" id="dataQualityCardUnknownCountry">
        <h3><i class="fas fa-flag"></i> Ismeretlen Ország</h3>
        <p class="stat-value"><span>Betöltés...</span>%</p>
        <small><span>0</span> abszolút (<span class="total-logs-dq">0</span> logból)</small>
    </div>
</div>

<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-folder-open"></i> Megnyitások Kategóriánként</h2>
    <div class="chart-container glass-effect" style="height: 300px; padding: 15px; display: flex; justify-content: center; align-items: center;">
        <canvas id="categoryOpensDistributionChart" style="max-width: 280px; max-height: 280px;"></canvas>
    </div>
</div>
<div class="dashboard-section">
    <h2 class="section-title"><i class="far fa-list-alt"></i> Legfrissebb Tokenek (Top <?php echo $latestTokensLimit; ?>)</h2>
    <div class="table-container glass-effect" id="latestTokensListContainer" style="padding:15px;">
        <?php if (empty($latestTokens)): ?>
            <p>Nincsenek tokenek létrehozva.</p>
        <?php else: ?>
            <ul class="simple-list"> <?php // Adjunk neki egy osztályt a stílusozáshoz ?>
                <?php foreach ($latestTokens as $token): ?>
                    <li>
                        <strong><?php echo escape($token['name']); ?></strong>
                        <small class="text-muted">(<?php echo escape($token['token_value']); ?>)</small><br>
                        Státusz: <?php echo $token['is_active'] ? '<span class="status-active">Aktív</span>' : '<span class="status-inactive">Inaktív</span>'; ?><br>
                        Létrehozva: <?php echo escape(formatTimestamp($token['created_at'], 'Y-m-d H:i')); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-wave-square"></i> Aktivitási Folyam ("Pulse")</h2>
    <div class="pulse-feed glass-effect">
        <?php if (empty($recentActivities)): ?>
            <p>Nincs megjeleníthető aktivitás.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($recentActivities as $activity): ?>
                    <?php 
                        // Itt már a Composerrel telepített getDetailedUserAgentInfo-t használjuk
                        $uaInfo = getDetailedUserAgentInfo($activity['user_agent']); 
                    ?>
                    <li>
                        <span class="timestamp"><?php echo escape(formatTimestamp($activity['timestamp'], 'Y-m-d H:i')); ?></span> - 
                        Pixel <strong class="accent-text"><?php echo escape($activity['token_name']); ?></strong> megnyitva.
                        <span class="details">
                            IP: <?php echo escape($activity['ip_address']); ?>, 
                            Eszköz: <?php echo escape($uaInfo['device_type'] . ($uaInfo['device_brand'] !== 'N/A' ? ' - '.$uaInfo['device_brand'] : '')); ?>,
                            Böngésző: <?php echo escape($uaInfo['client_name'] . ($uaInfo['client_version'] !== 'N/A' ? ' '.$uaInfo['client_version'] : '')); ?>,
                            OS: <?php echo escape($uaInfo['os_name'] . ($uaInfo['os_version'] !== 'N/A' ? ' '.$uaInfo['os_version'] : '')); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<script>
    const ajaxBaseUrl = '<?php echo BASE_URL; ?>admin/ajax_chart_data.php';
</script>
<script src="<?php echo BASE_URL . 'assets/js/dashboard.js'; ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>