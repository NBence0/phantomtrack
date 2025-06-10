<?php
require_once __DIR__ . '/../includes/header.php';
requireLogin();

$pageTitle = "Token Részletek";
$db = getDB();
$currentUserId = getCurrentUserId();
$tokenId = (int)($_GET['id'] ?? 0);

if ($tokenId <= 0) {
    $_SESSION['flash_message'] = "Érvénytelen token ID.";
    $_SESSION['flash_message_type'] = "error";
    header('Location: ' . BASE_URL . 'admin/tokens.php');
    exit;
}

// Token adatok lekérdezése
$stmt = $db->prepare("SELECT id, token_value, name, description, is_active, created_at FROM tokens WHERE id = :id AND user_id = :user_id");
$stmt->bindParam(':id', $tokenId);
$stmt->bindParam(':user_id', $currentUserId);
$stmt->execute();
$token = $stmt->fetch();

if (!$token) {
    $_SESSION['flash_message'] = "Token nem található vagy nincs jogosultságod megtekinteni.";
    $_SESSION['flash_message_type'] = "error";
    header('Location: ' . BASE_URL . 'admin/tokens.php');
    exit;
}
$pageTitle = "Részletek: " . escape($token['name']);

// Token-specifikus statisztikák (kezdetleges)
$totalOpens = $db->prepare("SELECT COUNT(*) FROM activity_logs WHERE token_id = :token_id");
$totalOpens->bindParam(':token_id', $tokenId);
$totalOpens->execute();
$tokenTotalOpens = $totalOpens->fetchColumn();

$uniqueIps = $db->prepare("SELECT COUNT(DISTINCT ip_address) FROM activity_logs WHERE token_id = :token_id");
$uniqueIps->bindParam(':token_id', $tokenId);
$uniqueIps->execute();
$tokenUniqueIps = $uniqueIps->fetchColumn();

// Aktivitási logok lekérdezése (lapozás nélkül a v1-ben)
$logStmt = $db->prepare("SELECT * FROM activity_logs WHERE token_id = :token_id ORDER BY timestamp DESC LIMIT 100"); // Limit, hogy ne legyen túl sok
$logStmt->bindParam(':token_id', $tokenId);
$logStmt->execute();
$logs = $logStmt->fetchAll();


$pageTitle = "Részletek: " . escape($token['name']);

// Szűrési paraméterek
$filterIp = trim($_GET['filter_ip'] ?? '');
$filterDateFrom = trim($_GET['filter_date_from'] ?? '');
$filterDateTo = trim($_GET['filter_date_to'] ?? '');

// Aktivitási logok lekérdezése (lapozás nélkül a v1-ben, de szűréssel)
$logSql = "SELECT * FROM activity_logs WHERE token_id = :token_id";
$logParams = [':token_id' => $tokenId];

if (!empty($filterIp)) {
    $logSql .= " AND ip_address LIKE :ip_address";
    $logParams[':ip_address'] = "%" . $filterIp . "%";
}
if (!empty($filterDateFrom)) {
    // Ellenőrizzük a dátum formátumát (Y-m-d)
    if (DateTime::createFromFormat('Y-m-d', $filterDateFrom) !== false) {
        $logSql .= " AND DATE(timestamp) >= :date_from";
        $logParams[':date_from'] = $filterDateFrom;
    } else {
        // Opcionális: hibaüzenet vagy figyelmen kívül hagyás
        $_SESSION['flash_message'] = "Érvénytelen 'tól' dátum formátum. Használj ÉÉÉÉ-HH-NN formátumot.";
        $_SESSION['flash_message_type'] = "warning";
        $filterDateFrom = ''; // Töröljük, hogy ne okozzon SQL hibát
    }
}
if (!empty($filterDateTo)) {
    if (DateTime::createFromFormat('Y-m-d', $filterDateTo) !== false) {
        $logSql .= " AND DATE(timestamp) <= :date_to";
        $logParams[':date_to'] = $filterDateTo;
    } else {
        $_SESSION['flash_message'] = (isset($_SESSION['flash_message']) ? $_SESSION['flash_message'].'<br>' : '') . "Érvénytelen 'ig' dátum formátum. Használj ÉÉÉÉ-HH-NN formátumot.";
        $_SESSION['flash_message_type'] = "warning";
        $filterDateTo = '';
    }
}

$logSql .= " ORDER BY timestamp DESC LIMIT 200"; // Továbbra is limitáljuk a v1-ben a túlzott adatmennyiség elkerülése végett

$logStmt = $db->prepare($logSql);
$logStmt->execute($logParams);
$logs = $logStmt->fetchAll();
// Exportálás gomb (JSON) - Funkcionalitás itt még nincs, csak a gomb
$exportUrl = BASE_URL . 'admin/token_details.php?id=' . $token['id'] . '&action=export_json';

if (isset($_GET['action']) && $_GET['action'] === 'export_json') {
    $exportStmt = $db->prepare("SELECT * FROM activity_logs WHERE token_id = :token_id ORDER BY timestamp DESC");
    $exportStmt->bindParam(':token_id', $tokenId);
    $exportStmt->execute();
    $exportLogs = $exportStmt->fetchAll();

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="token_'.escape($token['token_value']).'_logs.json"');
    echo json_encode($exportLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
$tokenIdForJs = $token['id']; // Átadjuk a JS-nek
?>
<div class="content-header">
    <h1><i class="fas fa-tag"></i> <?php echo escape($token['name']); ?> <small class="secondary-text">(<?php echo escape($token['token_value']); ?>)</small></h1>
    <div>
        <a href="<?php echo $exportUrl; ?>" class="btn btn-secondary"><i class="fas fa-download"></i> Logok exportálása (JSON)</a>
        <a href="<?php echo BASE_URL; ?>admin/tokens.php" class="btn btn-info"><i class="fas fa-arrow-left"></i> Vissza a Tokenekhez</a>
    </div>
</div>

<div class="token-details-main-grid"> <?php // Új fő grid ?>
    <div class="token-info-and-actions glass-effect">
        <h3><i class="fas fa-info-circle"></i> Token Információk</h3>
        <p><strong>Név:</strong> <?php echo escape($token['name']); ?></p>
        <p><strong>Leírás:</strong> <?php echo nl2br(escape($token['description'])); ?></p>
        <p><strong>Státusz:</strong> <?php echo $token['is_active'] ? '<span class="status-active">Aktív</span>' : '<span class="status-inactive">Inaktív</span>'; ?></p>
        <p><strong>Létrehozva:</strong> <?php echo escape(formatTimestamp($token['created_at'])); ?></p>
        <p style="margin-top:10px;"><strong>Pixel URL:</strong></p>
        <div class="token-value-cell" style="margin-top: 5px;">
             <input type="text" value="<?php echo BASE_URL . 'pixel.php?token=' . escape($token['token_value']); ?>" readonly class="pixel-url-input" style="width: calc(100% - 45px);">
             <button class="btn btn-small btn-copy" onclick="copyToClipboard(this.previousElementSibling)"><i class="far fa-copy"></i></button>
        </div>
        <hr style="margin: 20px 0;">
        <h3><i class="fas fa-filter"></i> Dátumtartomány Szűrő</h3>
        <form id="dateRangeFormTokenDetails" class="form-inline" style="display:flex; gap:10px; align-items:flex-end;">
            <div class="form-group" style="flex:1;">
                <label for="td_start_date">Kezdődátum:</label>
                <input type="date" id="td_start_date" name="start_date" class="form-control" value="<?php echo $defaultStartDate; ?>">
            </div>
            <div class="form-group" style="flex:1;">
                <label for="td_end_date">Végdátum:</label>
                <input type="date" id="td_end_date" name="end_date" class="form-control" value="<?php echo $defaultEndDate; ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-small"><i class="fas fa-sync-alt"></i> Frissítés</button>
        </form>
    </div>

    <div class="token-stats-cards-grid"> <?php // Kártyák gride ?>
        <div class="stat-card glass-effect">
            <h3><i class="far fa-eye"></i> Összes Megnyitás</h3>
            <p class="stat-value" id="tdTotalOpensVal">Betöltés...</p>
            <small id="tdTotalOpensPeriod">Kiválasztott időszak</small>
        </div>
        <div class="stat-card glass-effect">
            <h3><i class="fas fa-users"></i> Egyedi IP-k</h3>
            <p class="stat-value" id="tdUniqueIpsVal">Betöltés...</p>
            <small id="tdUniqueIpsPeriod">Kiválasztott időszak</small>
        </div>
         <div class="stat-card glass-effect">
            <h3><i class="fas fa-user-friends"></i> Egyedi / Összes</h3>
            <p class="stat-value" id="tdUniqueRatioVal">Betöltés...</p>
            <small id="tdUniqueRatioInfo">Arány</small>
        </div>
    </div>
</div>


<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-chart-line"></i> Napi Megnyitások (Tokenre)</h2>
    <div class="chart-container glass-effect" style="height: 350px; padding: 15px;">
        <canvas id="tdDailyOpensChart"></canvas>
    </div>
</div>

<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-clock"></i> Óránkénti Aktivitás (Tokenre)</h2>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="tdHourlyActivityChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h2 class="section-title"><i class="fas fa-link"></i> Top Referrerek (Tokenre)</h2>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="tdTopReferrersChart"></canvas>
        </div>
    </div>
</div>

<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-chalkboard-teacher"></i> Látogatói Profil (Tokenre)</h2>
<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fab fa-firefox-browser"></i> Böngészők</h3>
        <div class="chart-container-small glass-effect">
            <canvas id="tdBrowserDistributionChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-desktop"></i> Operációs Rendszerek</h3>
        <div class="chart-container-small glass-effect">
            <canvas id="tdOsDistributionChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-mobile-alt"></i> Eszköztípusok</h3>
        <div class="chart-container-small glass-effect">
            <canvas id="tdDeviceTypeDistributionChart"></canvas>
        </div>
    </div>
</div>

<h2 class="section-title" style="margin-top:30px;"><i class="fas fa-map-marked-alt"></i> Földrajzi Adatok (Tokenre)</h2>
<div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-globe-americas"></i> Top Országok</h3>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="tdCountryDistributionChart"></canvas>
        </div>
    </div>
    <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-city"></i> Top Városok</h3>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="tdCityDistributionChart"></canvas>
        </div>
    </div>
     <div class="dashboard-section">
        <h3 class="subsection-title"><i class="fas fa-network-wired"></i> Top ISP-k</h3>
        <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
            <canvas id="tdIspDistributionChart"></canvas>
        </div>
    </div>
</div>


<div class="dashboard-section" style="margin-top:30px;">
    <h2 class="section-title"><i class="fas fa-list-alt"></i> Részletes Aktivitási Napló
        <small id="activityLogCount" style="font-weight:normal; font-size:0.8em;"></small>
    </h2>
    <div class="filter-form glass-effect" style="padding: var(--card-padding); margin-bottom: 20px;">
        <?php // Meglévő szűrő form ... ?>
    </div>
    <div class="table-container glass-effect" id="tokenDetailsActivityLogTableContainer">
        <?php // Meglévő táblázat kiírása PHP-ból ... (ezt később AJAX-osíthatjuk) ?>
    </div>
    <?php // Lapozás, ha van ... ?>
</div>
<script>
function copyToClipboard(inputElement) {
    inputElement.select();
    inputElement.setSelectionRange(0, 99999); // Mobil eszközökhöz
    try {
        document.execCommand('copy');
        alert('Pixel URL vágólapra másolva!');
    } catch (err) {
        alert('Hiba a másolás során. Kérlek, másold manuálisan.');
    }
}

const CHART_INSTANCES_TD = {}; // Objektum a Chart instance-ok tárolására a frissítéshez

function destroyChartIfExists(chartId) {
    if (CHART_INSTANCES_TD[chartId]) {
        CHART_INSTANCES_TD[chartId].destroy();
        delete CHART_INSTANCES_TD[chartId];
    }
}


document.addEventListener('DOMContentLoaded', function() {
    const ajaxBaseUrl = '<?php echo BASE_URL; ?>admin/ajax_chart_data.php';
    const currentTokenId = <?php echo $tokenIdForJs; ?>;

    // 1. Napi Megnyitások (Tokenre)
    const dailyOpensTokenCtx = document.getElementById('dailyOpensTokenChart')?.getContext('2d');
    if (dailyOpensTokenCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=daily_opens_token&token_id=${currentTokenId}&days=30`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(dailyOpensTokenCtx, {
                    type: 'line',
                    data: {
                        labels: apiData.labels,
                        datasets: [{
                            label: 'Megnyitások száma',
                            data: apiData.data,
                            borderColor: ptColors.secondary,
                            backgroundColor: ptColors.secondaryTransparent,
                            tension: 0.3,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                type: 'time',
                                time: { unit: 'day', tooltipFormat: 'yyyy.MM.dd', displayFormats: {day: 'MM.dd'} }
                            },
                            y: { beginAtZero: true }
                        },
                        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }
                    }
                });
            }
        });
    }

    // 2. Óránkénti Aktivitás (Tokenre)
    const hourlyActivityTokenCtx = document.getElementById('hourlyActivityTokenChart')?.getContext('2d');
    if (hourlyActivityTokenCtx) {
        fetchChartData(`${ajaxBaseUrl}?action=hourly_activity_token&token_id=${currentTokenId}&days_back=7`).then(apiData => {
            if (apiData && apiData.labels && apiData.data) {
                new Chart(hourlyActivityTokenCtx, {
                    type: 'bar',
                    data: {
                        labels: apiData.labels, // 00:00, 01:00, ...
                        datasets: [{
                            label: 'Megnyitások (átlag elmúlt 7 nap)',
                            data: apiData.data,
                            backgroundColor: ptColors.green,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { title: {display: false, text: "Óra"} },
                            y: { beginAtZero: true, title: {display: false, text: "Megnyitások"} }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    }
    
    const dateRangeForm = document.getElementById('dateRangeFormTokenDetails');
    const startDateInput = document.getElementById('td_start_date');
    const endDateInput = document.getElementById('td_end_date');

    // Közös Doughnut/Pie chart opciók
    const doughnutPieOptionsTd = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { padding: 10, boxWidth: 10, font: {size: 10} } },
            tooltip: { /* ... tooltip config ... */ }
        }
    };
    const doughnutColorsTd = [ // Kicsit más paletta, hogy elkülönüljön a dashboardtól
        ptColors.secondary, ptColors.green, ptColors.purple, ptColors.orange, 
        ptColors.pink, ptColors.lightBlue, ptColors.teal, ptColors.yellow, ptColors.contrastHighlight, ptColors.grey
    ];
    
    // --- Adatbetöltő és grafikonrajzoló függvények ---
    function loadTokenSpecificCharts(startDate, endDate) {
        const dateParams = (startDate && endDate) ? `&start_date=${startDate}&end_date=${endDate}` : '';
        const selectedPeriodText = (startDate && endDate) ? `${startDate} - ${endDate}` : 'Összesített';

        // Stat kártyák frissítése
        const tdTotalOpensValEl = document.getElementById('tdTotalOpensVal');
        const tdUniqueIpsValEl = document.getElementById('tdUniqueIpsVal');
        const tdTotalOpensPeriodEl = document.getElementById('tdTotalOpensPeriod');
        const tdUniqueIpsPeriodEl = document.getElementById('tdUniqueIpsPeriod');
        const tdUniqueRatioValEl = document.getElementById('tdUniqueRatioVal');
        const tdUniqueRatioInfoEl = document.getElementById('tdUniqueRatioInfo');

        fetchChartData(`${ajaxBaseUrl}?action=unique_vs_total_opens_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
            if (apiData && typeof apiData.total_opens !== 'undefined') {
                if(tdTotalOpensValEl) tdTotalOpensValEl.textContent = apiData.total_opens;
                if(tdUniqueIpsValEl) tdUniqueIpsValEl.textContent = apiData.unique_opens;
                if(tdTotalOpensPeriodEl) tdTotalOpensPeriodEl.textContent = selectedPeriodText;
                if(tdUniqueIpsPeriodEl) tdUniqueIpsPeriodEl.textContent = selectedPeriodText;

                if (tdUniqueRatioValEl) {
                    const ratio = apiData.total_opens > 0 ? (apiData.unique_opens / apiData.total_opens * 100).toFixed(1) : 0;
                    tdUniqueRatioValEl.textContent = `${ratio}%`;
                }
                if(tdUniqueRatioInfoEl) tdUniqueRatioInfoEl.textContent = `${apiData.unique_opens} egyedi / ${apiData.total_opens} összes`;

                // Egyedi vs Összes (kis fánk diagram a kártyába, ha akarnánk, de most csak számok)
            } else {
                if(tdTotalOpensValEl) tdTotalOpensValEl.textContent = 'N/A';
                if(tdUniqueIpsValEl) tdUniqueIpsValEl.textContent = 'N/A';
                if(tdUniqueRatioValEl) tdUniqueRatioValEl.textContent = 'N/A';
            }
        });


        // Napi Megnyitások (Tokenre)
        destroyChartIfExists('tdDailyOpensChart');
        const tdDailyOpensCtx = document.getElementById('tdDailyOpensChart')?.getContext('2d');
        if (tdDailyOpensCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=daily_opens_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                    CHART_INSTANCES_TD['tdDailyOpensChart'] = new Chart(tdDailyOpensCtx, {
                        type: 'line',
                        data: { /* ... lásd dashboard ... */ labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, borderColor: ptColors.primary, backgroundColor: ptColors.primaryTransparent, tension: 0.3, fill:true }] },
                        options: { responsive: true, maintainAspectRatio: false, scales: { x: { type: 'time', time: { unit: 'day', tooltipFormat: 'yyyy.MM.dd', displayFormats: {day: 'MM.dd'} }}, y: { beginAtZero: true }}, plugins:{legend:{display:false}} }
                    });
                }
            });
        }

        // Óránkénti Aktivitás (Tokenre)
        destroyChartIfExists('tdHourlyActivityChart');
        const tdHourlyCtx = document.getElementById('tdHourlyActivityChart')?.getContext('2d');
        if (tdHourlyCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=hourly_activity_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                     CHART_INSTANCES_TD['tdHourlyActivityChart'] = new Chart(tdHourlyCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.green }] },
                        options: { responsive: true, maintainAspectRatio: false, scales: {y: { beginAtZero: true }}, plugins:{legend:{display:false}} }
                    });
                }
            });
        }
        
        // Böngésző Eloszlás (Tokenre)
        destroyChartIfExists('tdBrowserDistributionChart');
        const tdBrowsersCtx = document.getElementById('tdBrowserDistributionChart')?.getContext('2d');
        if (tdBrowsersCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=browser_distribution_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                    CHART_INSTANCES_TD['tdBrowserDistributionChart'] = new Chart(tdBrowsersCtx, {
                        type: 'doughnut',
                        data: { labels: apiData.labels, datasets: [{ data: apiData.data, backgroundColor: doughnutColorsTd, borderColor: ptColors.glassBg }] },
                        options: doughnutPieOptionsTd
                    });
                }
            });
        }

        // OS Eloszlás (Tokenre)
        destroyChartIfExists('tdOsDistributionChart');
        const tdOsCtx = document.getElementById('tdOsDistributionChart')?.getContext('2d');
        if (tdOsCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=os_distribution_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                     CHART_INSTANCES_TD['tdOsDistributionChart'] = new Chart(tdOsCtx, {
                        type: 'doughnut',
                        data: { labels: apiData.labels, datasets: [{ data: apiData.data, backgroundColor: doughnutColorsTd.slice().reverse(), borderColor: ptColors.glassBg }] },
                        options: doughnutPieOptionsTd
                    });
                }
            });
        }
        
        // Eszköztípus Eloszlás (Tokenre)
        destroyChartIfExists('tdDeviceTypeDistributionChart');
        const tdDeviceCtx = document.getElementById('tdDeviceTypeDistributionChart')?.getContext('2d');
        if (tdDeviceCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=device_type_distribution_token&token_id=${currentTokenId}${dateParams}`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                     CHART_INSTANCES_TD['tdDeviceTypeDistributionChart'] = new Chart(tdDeviceCtx, {
                        type: 'doughnut',
                        data: { labels: apiData.labels, datasets: [{ data: apiData.data, backgroundColor: [ptColors.teal, ptColors.orange, ptColors.red, ...doughnutColorsTd.slice(3)], borderColor: ptColors.glassBg }] },
                        options: doughnutPieOptionsTd
                    });
                }
            });
        }

        // Top Országok (Tokenre)
        destroyChartIfExists('tdCountryDistributionChart');
        const tdCountryCtx = document.getElementById('tdCountryDistributionChart')?.getContext('2d');
        if (tdCountryCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=country_distribution_token&token_id=${currentTokenId}${dateParams}&limit=7`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                     CHART_INSTANCES_TD['tdCountryDistributionChart'] = new Chart(tdCountryCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.secondary }] },
                        options: { responsive: true, maintainAspectRatio: false, scales: {y: { beginAtZero: true }}, plugins:{legend:{display:false}} }
                    });
                }
            });
        }
        
        // Top Városok (Tokenre)
        destroyChartIfExists('tdCityDistributionChart');
        const tdCityCtx = document.getElementById('tdCityDistributionChart')?.getContext('2d');
        if (tdCityCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=city_distribution_token&token_id=${currentTokenId}${dateParams}&limit=7`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                    CHART_INSTANCES_TD['tdCityDistributionChart'] = new Chart(tdCityCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.purple }] },
                        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: {x: { beginAtZero: true }}, plugins:{legend:{display:false}} }
                    });
                }
            });
        }
        
        // Top ISP-k (Tokenre)
        destroyChartIfExists('tdIspDistributionChart');
        const tdIspCtx = document.getElementById('tdIspDistributionChart')?.getContext('2d');
        if (tdIspCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=isp_distribution_token&token_id=${currentTokenId}${dateParams}&limit=7`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                     CHART_INSTANCES_TD['tdIspDistributionChart'] = new Chart(tdIspCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.contrastHighlight }] },
                        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: {x: { beginAtZero: true }}, plugins:{legend:{display:false}} }
                    });
                }
            });
        }
        
        // Top Referrerek (Tokenre)
        destroyChartIfExists('tdTopReferrersChart');
        const tdReferrersCtx = document.getElementById('tdTopReferrersChart')?.getContext('2d');
        if (tdReferrersCtx) {
            fetchChartData(`${ajaxBaseUrl}?action=top_referrers_token&token_id=${currentTokenId}${dateParams}&limit=7`).then(apiData => {
                if (apiData && apiData.labels && apiData.data) {
                     CHART_INSTANCES_TD['tdTopReferrersChart'] = new Chart(tdReferrersCtx, {
                        type: 'bar',
                        data: { labels: apiData.labels, datasets: [{ label: 'Megnyitások', data: apiData.data, backgroundColor: ptColors.greenTransparent, borderColor: ptColors.green, borderWidth: 1 }] },
                        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: {x: { beginAtZero: true }}, plugins:{legend:{display:false}} }
                    });
                }
            });
        }

        // TODO: Részletes aktivitási napló táblázat frissítése AJAX-szal (ha szükséges)
        // A jelenlegi PHP-ból kiírt táblázat a GET paraméterek alapján frissül az oldal újratöltésekor.
        // Ha ezt is AJAX-osítani akarjuk a dátumszűrővel, az egy újabb nagy lépés.
        // Most a GET paraméterek az URL-ben frissülnek, és az oldal újratölti a táblázatot.
        const logCountEl = document.getElementById('activityLogCount');
        if (logCountEl) {
            // A PHP által kiírt $logs változó hosszát használhatnánk itt,
            // de ha a táblázatot is AJAX-al töltenénk, akkor onnan jönne az adat.
            // Egyelőre hagyjuk üresen, vagy a PHP-ból írjuk ki a $logs hosszát.
            <?php if (isset($logs) && is_array($logs)): ?>
            logCountEl.textContent = ` (<?php echo count($logs); ?> megjelenített bejegyzés)`;
            <?php endif; ?>
        }

    } // loadTokenSpecificCharts vége

    // Kezdeti betöltés az alapértelmezett dátumokkal
    loadTokenSpecificCharts(startDateInput.value, endDateInput.value);

    // Eseménykezelő a dátumtartomány űrlaphoz
    if (dateRangeForm) {
        dateRangeForm.addEventListener('submit', function(event) {
            event.preventDefault(); // Megakadályozza a normál űrlapküldést
            const newStartDate = startDateInput.value;
            const newEndDate = endDateInput.value;

            // Frissítjük az URL-t a GET paraméterekkel az oldal újratöltéséhez,
            // hogy a PHP által generált aktivitási napló is frissüljön.
            // Vagy itt lehetne egy teljesen AJAX-os megoldás a naplóhoz is.
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('filter_date_from', newStartDate); // A PHP szűrő form paramétere
            currentUrl.searchParams.set('filter_date_to', newEndDate);   // A PHP szűrő form paramétere
            // Még meg kellene oldani a filter_ip és egyéb PHP oldali szűrők konzisztenciáját
            // Ha csak a grafikonokat akarjuk frissíteni AJAX-al, az URL-t nem kell bántani.
            
            // Grafikonok frissítése az új dátumokkal
            loadTokenSpecificCharts(newStartDate, newEndDate);
            
            // Ha a táblázatot is AJAX-szal frissítenénk, itt hívnánk meg azt a függvényt.
            // Jelenleg a PHP oldali táblázat csak oldalfrissítéssel változik a GET paraméterek alapján.
            // Ahhoz, hogy a táblázat is frissüljön a grafikonokkal együtt URL változás nélkül,
            // a táblázatot is AJAX-szal kellene betölteni.
            // Most egy kompromisszum: a grafikonok frissülnek, a táblázat az URL alapján.
            // Ha a felhasználó a grafikonok szűrőjét használja, az URL-t is frissíthetnénk,
            // hogy a táblázat is kövesse, de ez oldalfirssítést okozna.
            // A legtisztább egy teljesen AJAX-os táblázat lenne, de az plusz munka.
        });
    }

}); // DOMContentLoaded vége
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>