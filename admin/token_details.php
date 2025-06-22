<?php
// === 1. LÉPÉS: MINDEN FELDOLGOZÓ LOGIKA (HTML ELŐTT) ===
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$tokenId = (int)($_GET['id'] ?? 0);

if ($tokenId <= 0) {
    $_SESSION['flash_message'] = "Érvénytelen token ID.";
    $_SESSION['flash_message_type'] = "error";
    header('Location: ' . BASE_URL . 'admin/tokens.php');
    exit;
}

// Token alapadatok lekérdezése (ellenőrzéshez és exporthoz)
$tokenStmt = $db->prepare("SELECT id, token_value, name, description, is_active, created_at FROM tokens WHERE id = :id AND user_id = :user_id");
$tokenStmt->execute([':id' => $tokenId, ':user_id' => $currentUserId]);
$token = $tokenStmt->fetch();

if (!$token) {
    $_SESSION['flash_message'] = "Token nem található vagy nincs jogosultságod megtekinteni.";
    $_SESSION['flash_message_type'] = "error";
    header('Location: ' . BASE_URL . 'admin/tokens.php');
    exit;
}

// --- Szűrési paraméterek összegyűjtése (export és napló is használja) ---
$filterIp = trim($_GET['filter_ip'] ?? '');
$urlDateFrom = trim($_GET['filter_date_from'] ?? '');
$urlDateTo = trim($_GET['filter_date_to'] ?? '');

// --- EXPORTÁLÁSI LOGIKA ---
if (isset($_GET['action']) && $_GET['action'] === 'export_json') {
    
    $exportSql = "SELECT * FROM activity_logs WHERE token_id = :token_id";
    $exportParams = [':token_id' => $tokenId];

    if (!empty($filterIp)) { $exportSql .= " AND ip_address LIKE :ip_address"; $exportParams[':ip_address'] = "%" . $filterIp . "%"; }
    if (!empty($urlDateFrom) && DateTime::createFromFormat('Y-m-d', $urlDateFrom)) { $exportSql .= " AND DATE(timestamp) >= :date_from"; $exportParams[':date_from'] = $urlDateFrom; }
    if (!empty($urlDateTo) && DateTime::createFromFormat('Y-m-d', $urlDateTo)) { $exportSql .= " AND DATE(timestamp) <= :date_to"; $exportParams[':date_to'] = $urlDateTo; }
    
    $exportSql .= " ORDER BY timestamp DESC";

    $exportStmt = $db->prepare($exportSql);
    $exportStmt->execute($exportParams);
    $exportLogsData = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fejlécek beállítása a JSON letöltéshez
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="token_'.preg_replace('/[^a-zA-Z0-9-]/', '_', $token['token_value']).'_logs_'.date('YmdHis').'.json"');
    
    echo json_encode($exportLogsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit; // A szkript futása itt leáll, nincs HTML kimenet
}


// === 2. LÉPÉS: ADATOK LEKÉRDEZÉSE A MEGJELENÍTÉSHEZ ===
// Ha a kód idáig eljut, az azt jelenti, hogy a normál oldalt kell megjeleníteni.

$pageTitle = "Részletek: " . escape($token['name']);
$tokenIdForJs = $token['id'];

// --- Aktivitási Napló Szűrési és Lapozási Logika ---
$defaultStartDateForForm = $urlDateFrom ?: date('Y-m-d', strtotime('-29 days'));
$defaultEndDateForForm = $urlDateTo ?: date('Y-m-d');

$logSql = "SELECT * FROM activity_logs WHERE token_id = :token_id";
$logParams = [':token_id' => $tokenId];

if (!empty($filterIp)) { $logSql .= " AND ip_address LIKE :ip_address"; $logParams[':ip_address'] = "%" . $filterIp . "%"; }
if (!empty($urlDateFrom) && DateTime::createFromFormat('Y-m-d', $urlDateFrom)) { $logSql .= " AND DATE(timestamp) >= :date_from"; $logParams[':date_from'] = $urlDateFrom; }
if (!empty($urlDateTo) && DateTime::createFromFormat('Y-m-d', $urlDateTo)) { $logSql .= " AND DATE(timestamp) <= :date_to"; $logParams[':date_to'] = $urlDateTo; }

// Lapozás (count)
$countSql = "SELECT COUNT(*) FROM activity_logs " . str_replace('SELECT * FROM activity_logs', '', $logSql);
$countParams = $logParams;
$totalLogsStmt = $db->prepare($countSql);
$totalLogsStmt->execute($countParams);
$totalLogsCount = $totalLogsStmt->fetchColumn();

// Lapozás (számítások)
$itemsPerPageLogs = (int)getAppSetting('items_per_page_logs', 50);
$totalPagesLogs = $totalLogsCount > 0 ? ceil($totalLogsCount / $itemsPerPageLogs) : 1;
$currentPageLogs = isset($_GET['log_page']) ? (int)$_GET['log_page'] : 1;
if ($currentPageLogs < 1) $currentPageLogs = 1;
if ($currentPageLogs > $totalPagesLogs) $currentPageLogs = $totalPagesLogs;
$offsetLogs = ($currentPageLogs - 1) * $itemsPerPageLogs;

// Végleges lekérdezés lapozással
$logSql .= " ORDER BY timestamp DESC LIMIT :limit OFFSET :offset";
$logParams[':limit'] = $itemsPerPageLogs;
$logParams[':offset'] = $offsetLogs;

$logStmt = $db->prepare($logSql);
foreach ($logParams as $paramKey => &$paramValue) {
    $logStmt->bindParam($paramKey, $paramValue, is_int($paramValue) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$logStmt->execute();
$logs = $logStmt->fetchAll();


$exportUrl = BASE_URL . 'admin/token_details.php?id=' . $token['id'] . '&action=export_json'
            . (!empty($filterIp) ? '&filter_ip=' . urlencode($filterIp) : '')
            . (!empty($urlDateFrom) ? '&filter_date_from=' . $urlDateFrom : '')
            . (!empty($urlDateTo) ? '&filter_date_to=' . $urlDateTo : '');

// === 3. LÉPÉS: HTML MEGJELENÍTÉS KEZDETE ===
require_once __DIR__ . '/../includes/header.php';
?>
<div class="content-header">
    <h1><i class="fas fa-tag"></i> <?php echo escape($pageTitle); ?> <small class="secondary-text">(<?php echo escape($token['token_value']); ?>)</small></h1>
    <div>
        <a href="<?php echo $exportUrl; // PHP-ban definiálva ?>" class="btn btn-secondary"><i class="fas fa-download"></i> Napló Exportálása (JSON)</a>
        <a href="<?php echo BASE_URL . 'admin/tokens.php'; ?>" class="btn btn-info"><i class="fas fa-arrow-left"></i> Vissza a Tokenekhez</a>
    </div>
</div>

<div class="token-details-main-grid"> <?php // Fő grid az oldal tetején ?>
    <div class="token-info-and-actions glass-effect"> <?php // Bal oldali sáv ?>
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
        <form id="dateRangeFormTokenDetails" class="form-inline" style="display:flex; flex-direction:column; gap:10px;" method="GET" action="<?php echo BASE_URL . 'admin/token_details.php'; ?>">
            <input type="hidden" name="id" value="<?php echo $tokenId; ?>">
            <?php if (!empty($filterIp)): ?>
                <input type="hidden" name="filter_ip" value="<?php echo escape($filterIp); ?>">
            <?php endif; ?>
            <div class="form-group" style="width:100%;">
                <label for="td_start_date">Kezdődátum:</label>
                <input type="date" id="td_start_date" name="filter_date_from" class="form-control" value="<?php echo escape($defaultStartDateForForm); // PHP-ban definiálva ?>" style="width:100%;">
            </div>
            <div class="form-group" style="width:100%;">
                <label for="td_end_date">Végdátum:</label>
                <input type="date" id="td_end_date" name="filter_date_to" class="form-control" value="<?php echo escape($defaultEndDateForForm); // PHP-ban definiálva ?>" style="width:100%;">
            </div>
            <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter"></i> Szűrés & Frissítés</button>
        </form>
    </div>  

    <div class="token-main-charts-area"> <?php // Jobb oldali fő grafikonok területe ?>
        <div class="dashboard-section" style="margin-top:0;"> <?php // margin-top:0, ha ez az első elem itt ?>
            <h2 class="section-title"><i class="fas fa-chart-line"></i> Napi Megnyitások</h2>
            <div class="chart-container glass-effect" style="height: 360px; padding: 15px;"> <?php // Kicsit magasabb ?>
                <canvas id="tdDailyOpensChart"></canvas>
            </div>
        </div>
        <div class="dashboard-section" style="margin-top:20px;">
            <h2 class="section-title"><i class="fas fa-clock"></i> Óránkénti Aktivitás</h2>
            <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
                <canvas id="tdHourlyActivityChart"></canvas>
            </div>
        </div>
    </div>
</div>

<?php>require_once __DIR__ . '/../includes/token_details_passive_content.php';?>

<div class="dashboard-section" style="margin-top:30px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h2 class="section-title" style="margin-bottom:0;"><i class="fas fa-list-alt"></i> Részletes Aktivitási Napló</h2>
        <small id="activityLogCountAndPageInfo" style="font-weight:normal; font-size:0.9em; color: var(--text-secondary);">
            Összesen: <?php echo $totalLogsCount; ?> bejegyzés. 
            Jelenlegi oldal: <?php echo $currentPageLogs; ?> / <?php echo $totalPagesLogs; ?>.
        </small>
    </div>
    <div class="filter-form glass-effect" style="padding: var(--card-padding); margin-bottom: 20px;">
        <form method="GET" action="<?php echo BASE_URL . 'admin/token_details.php'; ?>" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
            <input type="hidden" name="id" value="<?php echo $tokenId; ?>">
            <?php // A dátumszűrők a bal oldali panelen vannak, itt csak az IP szűrő van. ?>
            <div class="form-group" style="flex: 1; min-width: 200px; margin-bottom:0;">
                <label for="filter_ip_log">IP cím tartalmazza (Naplóhoz):</label>
                <input type="text" id="filter_ip_log" name="filter_ip" value="<?php echo escape($filterIp); ?>" placeholder="Pl. 192.168" class="form-control">
            </div>
            <?php // A dátum inputokat a bal oldali form kezeli, az URL-ben fognak megjelenni ?>
            <input type="hidden" name="filter_date_from" value="<?php echo escape($defaultStartDateForForm); ?>">
            <input type="hidden" name="filter_date_to" value="<?php echo escape($defaultEndDateForForm); ?>">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Napló Szűrése</button>
            <?php if (!empty($filterIp) || !empty($urlDateFrom) || !empty($urlDateTo)): ?>
                 <a href="<?php echo BASE_URL . 'admin/token_details.php?id=' . $tokenId; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Szűrők törlése</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="table-container glass-effect" id="tokenDetailsActivityLogTableContainer">
        <?php if (empty($logs)): ?>
            <p style="text-align:center; padding: 20px;">Nincs a szűrési feltételeknek megfelelő naplóbejegyzés ehhez a tokenhez.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Időbélyeg</th>
                    <th>IP Cím</th>
                    <th>Elemzett User-Agent</th>
                    <th>Referrer</th>
                    <th>Helyzet/Eszköz (Részletes)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                        $formattedUa = formatUserAgentDetailed($log);
                        $fullUa = $log['user_agent'];
                        $locationDeviceDetails = "";
                        if (!empty($log['country_code'])) {
                            $locationDeviceDetails .= "Ország: " . escape($log['country_code']);
                            if (!empty($log['city_name'])) $locationDeviceDetails .= ", " . escape($log['city_name']);
                            $locationDeviceDetails .= "<br>";
                        }
                        if (!empty($log['isp'])) $locationDeviceDetails .= "ISP: " . escape($log['isp']) . "<br>";
                        if (!empty($log['device_brand']) || !empty($log['device_model']) || !empty($log['device_type'])) {
                             $deviceOutput = "Eszköz: ";
                             if(!empty($log['device_brand'])) $deviceOutput .= escape($log['device_brand']) . " ";
                             if(!empty($log['device_model'])) $deviceOutput .= escape($log['device_model']) . " ";
                             if(!empty($log['device_type'])) $deviceOutput .= "(" . escape($log['device_type']) . ")";
                             $locationDeviceDetails .= trim($deviceOutput);
                        }
                    ?>
                    <tr>
                        <td data-label="Időbélyeg"><?php echo escape(formatTimestamp($log['timestamp'])); ?></td>
                        <td data-label="IP Cím"><?php echo escape($log['ip_address']); ?></td>
                        <td data-label="User-Agent" title="<?php echo escape($fullUa); ?>"><?php echo escape($formattedUa); ?></td>
                        <td data-label="Referrer" title="<?php echo escape($log['referrer']); ?>"><?php echo escape(substr($log['referrer'], 0, 50) . (strlen($log['referrer']) > 50 ? '...' : '')); ?></td>
                        <td data-label="Helyzet/Eszköz"><?php echo $locationDeviceDetails ?: 'N/A'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <!-- Lapozás a naplóhoz -->
    <?php if ($totalPagesLogs > 1): ?>
    <div class="pagination glass-effect" style="margin-top:20px;">
        <?php
        $paginationUrlParams = "id=$tokenId"
            . (!empty($filterIp) ? '&filter_ip=' . urlencode($filterIp) : '')
            . (!empty($defaultStartDateForForm) ? '&filter_date_from=' . $defaultStartDateForForm : '') // Itt a form defaultjait használjuk, mert ezek az aktuális szűrés
            . (!empty($defaultEndDateForForm) ? '&filter_date_to=' . $defaultEndDateForForm : '');
        ?>
        <?php if ($currentPageLogs > 1): ?>
            <a href="?<?php echo $paginationUrlParams; ?>&log_page=<?php echo $currentPageLogs - 1; ?>" class="btn btn-secondary btn-small">« Előző</a>
        <?php else: ?>
            <span class="btn btn-secondary btn-small disabled">« Előző</span>
        <?php endif; ?>

        <?php 
        $startPage = max(1, $currentPageLogs - 2);
        $endPage = min($totalPagesLogs, $currentPageLogs + 2);
        if ($startPage > 1) {
            echo '<a href="?'.$paginationUrlParams.'&log_page=1" class="btn btn-secondary btn-small">1</a>';
            if ($startPage > 2) echo '<span class="pagination-dots">...</span>';
        }
        for ($i = $startPage; $i <= $endPage; $i++): ?>
            <?php if ($i == $currentPageLogs): ?>
                <span class="btn btn-primary btn-small current-page"><?php echo $i; ?></span>
            <?php else: ?>
                <a href="?<?php echo $paginationUrlParams; ?>&log_page=<?php echo $i; ?>" class="btn btn-secondary btn-small"><?php echo $i; ?></a>
            <?php endif; ?>
        <?php endfor;
        if ($endPage < $totalPagesLogs) {
            if ($endPage < $totalPagesLogs - 1) echo '<span class="pagination-dots">...</span>';
            echo '<a href="?'.$paginationUrlParams.'&log_page='.$totalPagesLogs.'" class="btn btn-secondary btn-small">'.$totalPagesLogs.'</a>';
        }
        ?>

        <?php if ($currentPageLogs < $totalPagesLogs): ?>
            <a href="?<?php echo $paginationUrlParams; ?>&log_page=<?php echo $currentPageLogs + 1; ?>" class="btn btn-secondary btn-small">Következő »</a>
        <?php else: ?>
            <span class="btn btn-secondary btn-small disabled">Következő »</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<script>
    const tokenCreatedAt = new Date('<?php echo $token['created_at']; ?>');
    const ajaxBaseUrl = '<?php echo BASE_URL; ?>admin/ajax_chart_data.php';
    const currentTokenId = <?php echo $tokenIdForJs; ?>;
</script>
<script src="<?php echo BASE_URL . 'assets/js/token_details.js'; ?>"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>  