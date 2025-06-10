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

?>
<div class="content-header">
    <h1><i class="fas fa-tag"></i> <?php echo escape($token['name']); ?> <small class="secondary-text">(<?php echo escape($token['token_value']); ?>)</small></h1>
    <div>
        <a href="<?php echo $exportUrl; ?>" class="btn btn-secondary"><i class="fas fa-download"></i> Logok exportálása (JSON)</a>
        <a href="<?php echo BASE_URL; ?>admin/tokens.php" class="btn btn-info"><i class="fas fa-arrow-left"></i> Vissza a Tokenekhez</a>
    </div>
</div>

<div class="token-details-grid">
    <div class="info-card glass-effect">
        <h3>Token Információk</h3>
        <p><strong>Név:</strong> <?php echo escape($token['name']); ?></p>
        <p><strong>Leírás:</strong> <?php echo nl2br(escape($token['description'])); ?></p>
        <p><strong>Státusz:</strong> <?php echo $token['is_active'] ? '<span class="status-active">Aktív</span>' : '<span class="status-inactive">Inaktív</span>'; ?></p>
        <p><strong>Létrehozva:</strong> <?php echo escape(formatTimestamp($token['created_at'])); ?></p>
        <p><strong>Pixel URL:</strong></p>
        <div class="token-value-cell" style="margin-top: 5px;">
             <input type="text" value="<?php echo BASE_URL . 'pixel.php?token=' . escape($token['token_value']); ?>" readonly class="pixel-url-input" style="width: calc(100% - 45px);">
             <button class="btn btn-small btn-copy" onclick="copyToClipboard(this.previousElementSibling)"><i class="far fa-copy"></i></button>
        </div>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="far fa-eye"></i> Összes Megnyitás</h3>
        <p class="stat-value"><?php echo escape($tokenTotalOpens); ?></p>
    </div>
    <div class="stat-card glass-effect">
        <h3><i class="fas fa-map-marker-alt"></i> Egyedi IP Címek</h3>
        <p class="stat-value"><?php echo escape($tokenUniqueIps); ?></p>
    </div>
</div>

<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-filter"></i> Napló Szűrése</h2>
    <div class="filter-form glass-effect" style="padding: var(--card-padding); margin-bottom: 20px;">
        <form method="GET" action="">
            <input type="hidden" name="id" value="<?php echo $tokenId; ?>">
            <div class="form-row" style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom:10px;">
                <div class="form-group" style="flex: 1; min-width: 150px;">
                    <label for="filter_ip">IP cím tartalmazza:</label>
                    <input type="text" id="filter_ip" name="filter_ip" value="<?php echo escape($filterIp); ?>" placeholder="Pl. 192.168">
                </div>
                <div class="form-group" style="flex: 1; min-width: 150px;">
                    <label for="filter_date_from">Dátum -tól:</label>
                    <input type="date" id="filter_date_from" name="filter_date_from" value="<?php echo escape($filterDateFrom); ?>">
                </div>
                <div class="form-group" style="flex: 1; min-width: 150px;">
                    <label for="filter_date_to">Dátum -ig:</label>
                    <input type="date" id="filter_date_to" name="filter_date_to" value="<?php echo escape($filterDateTo); ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Szűrés</button>
            <a href="<?php echo BASE_URL . 'admin/token_details.php?id=' . $tokenId; ?>" class="btn btn-secondary"><i class="fas fa-times"></i> Szűrők törlése</a>
        </form>
    </div>
</div>


<div class="dashboard-section">
    <h2 class="section-title"><i class="fas fa-list-alt"></i> Részletes Aktivitási Napló (<?php echo count($logs); ?> találat)</h2>
    <div class="table-container glass-effect">
        <?php if (empty($logs)): ?>
            <p style="text-align:center; padding: 20px;">Nincs a szűrési feltételeknek megfelelő naplóbejegyzés ehhez a tokenhez.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Időbélyeg</th>
                    <th>IP Cím</th>
                    <th>Elemzett User-Agent</th> <!-- Módosítva -->
                    <th>Referrer</th>
                    <th>Helyzet/Eszköz (Részletes)</th> <!-- Módosítva -->
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                        // A 9. pontban definiált formázott User-Agent
                        $formattedUa = formatUserAgentDetailed($log); // Ezt a függvényt még létre kell hozni
                        $fullUa = $log['user_agent'];

                        // A 2. és 3. pont eredményeit itt jelenítjük meg
                        $locationDeviceDetails = "";
                        if (!empty($log['country_code'])) {
                            $locationDeviceDetails .= "Ország: " . escape($log['country_code']);
                            if (!empty($log['city_name'])) $locationDeviceDetails .= ", " . escape($log['city_name']);
                            $locationDeviceDetails .= "<br>";
                        }
                        if (!empty($log['isp'])) $locationDeviceDetails .= "ISP: " . escape($log['isp']) . "<br>";
                        if (!empty($log['device_brand'])) {
                            $locationDeviceDetails .= "Eszköz: " . escape($log['device_brand']);
                            if (!empty($log['device_model'])) $locationDeviceDetails .= " " . escape($log['device_model']);
                            $locationDeviceDetails .= " (" . escape($log['device_type']) . ")";
                        } elseif (!empty($log['device_type'])) {
                            $locationDeviceDetails .= "Eszköz típusa: " . escape($log['device_type']);
                        }
                    ?>
                    <tr>
                        <td><?php echo escape(formatTimestamp($log['timestamp'])); ?></td>
                        <td><?php echo escape($log['ip_address']); ?></td>
                        <td title="<?php echo escape($fullUa); ?>"><?php echo escape($formattedUa); ?></td>
                        <td title="<?php echo escape($log['referrer']); ?>"><?php echo escape(substr($log['referrer'], 0, 50) . (strlen($log['referrer']) > 50 ? '...' : '')); ?></td>
                        <td><?php echo $locationDeviceDetails; // Már escape-elt részeket tartalmazhat ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
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
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>