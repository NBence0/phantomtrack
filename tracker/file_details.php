<?php
// === Fájl: tracker/file_details.php (Frissítve) ===

// 1. Alapvető beállítások és biztonsági ellenőrzések
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();

$db = getDB();
$currentUserId = getCurrentUserId();
$fileId = (int)($_GET['id'] ?? 0);

if ($fileId <= 0) {
    $_SESSION['flash_message'] = "Érvénytelen fájl azonosító.";
    $_SESSION['flash_message_type'] = "error";
    header('Location: ' . BASE_URL . 'tracker/files.php');
    exit;
}

// 2. Fájl adatainak lekérdezése
$fileStmt = $db->prepare("
    SELECT f.*, t.name as upload_token_name, t.token_value as upload_token_value
    FROM files f
    LEFT JOIN tokens t ON f.upload_token_id = t.id
    WHERE f.id = :id AND f.user_id = :user_id
");
$fileStmt->execute([':id' => $fileId, ':user_id' => $currentUserId]);
$file = $fileStmt->fetch();

if (!$file) {
    $_SESSION['flash_message'] = "A fájl nem található vagy nincs jogosultságod megtekinteni.";
    $_SESSION['flash_message_type'] = "error";
    header('Location: ' . BASE_URL . 'tracker/files.php');
    exit;
}

$pageTitle = "Részletek: " . escape($file['original_filename']);

// 3. Kapcsolódó aktivitási naplók lekérdezése (lapozással)
$logSql = "SELECT * FROM activity_logs WHERE file_id = :file_id ORDER BY timestamp DESC";
$logSql .= " LIMIT 100"; // Első körben marad a 100-as limit
$logStmt = $db->prepare($logSql);
$logStmt->execute([':file_id' => $fileId]);
$logs = $logStmt->fetchAll();

// 4. HTML kimenet
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1><i class="fas fa-file-alt"></i> <?php echo $pageTitle; ?></h1>
    <a href="<?php echo BASE_URL . 'tracker/files.php'; ?>" class="btn btn-info"><i class="fas fa-arrow-left"></i> Vissza a Fájlokhoz</a>
</div>

<div class="token-details-main-grid">

    <!-- BAL OLDALI INFORMÁCIÓS SÁV (FRISSÍTVE) -->
    <div class="token-info-and-actions glass-effect">
        <h3><i class="fas fa-info-circle"></i> Fájl Információk</h3>
        <p><strong>Eredeti név:</strong> <?php echo escape($file['original_filename']); ?></p>
        <p><strong>Méret:</strong> <?php echo formatBytes($file['file_size']); ?></p>
        <p><strong>Feltöltve:</strong> <?php echo escape(formatTimestamp($file['upload_timestamp'])); ?></p>
        <p><strong>Feltöltő IP:</strong> <?php echo escape($file['upload_ip']); ?></p>
        <p><strong>Letöltések:</strong> <?php echo (int)$file['download_count']; ?>x</p>
        
        <hr style="margin: 20px 0;">
        
        <h3><i class="fas fa-link"></i> Linkek</h3>
        <p><strong>Megtekintő URL:</strong></p>
        <div class="token-value-cell">
             <input type="text" value="<?php echo BASE_URL . 'View.php?id=' . escape($file['view_token']); ?>" readonly class="pixel-url-input">
             <button class="btn btn-small btn-copy" onclick="copyToClipboard(this.previousElementSibling)"><i class="far fa-copy"></i></button>
        </div>
        
        <?php if ($file['upload_token_name']): ?>
            <p style="margin-top:15px;"><strong>Forrás (Feltöltő link):</strong></p>
            <small><?php echo escape($file['upload_token_name']); ?></small>
            <div class="token-value-cell">
                 <input type="text" value="<?php echo BASE_URL . 'Upload.php?token=' . escape($file['upload_token_value']); ?>" readonly class="pixel-url-input">
                 <button class="btn btn-small btn-copy" onclick="copyToClipboard(this.previousElementSibling)"><i class="far fa-copy"></i></button>
            </div>
        <?php endif; ?>
    </div>

    <!-- JOBB OLDALI TARTALOM (FRISSÍTVE GRAFIKONOKKAL) -->
    <div class="token-main-charts-area">
        <div class="dashboard-section" style="margin-top:0;">
            <h2 class="section-title"><i class="fas fa-chart-line"></i> Napi Aktivitás (Megtekintés vs Letöltés)</h2>
            <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
                <canvas id="fileDailyActivityChart"></canvas>
            </div>
        </div>
        <div class="dashboard-section" style="margin-top:30px;">
            <h2 class="section-title"><i class="fas fa-clock"></i> Aktivitás Óránkénti Eloszlása</h2>
             <div class="chart-container glass-effect" style="height: 300px; padding: 15px;">
                <canvas id="fileHourlyActivityChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- NAPLÓ (VÁLTOZATLAN) -->
<div class="dashboard-section" style="margin-top:30px;">
    <h2 class="section-title"><i class="fas fa-list-alt"></i> Kapcsolódó Aktivitási Napló (Legutóbbi 100)</h2>
    <div class="table-container glass-effect">
        <?php if (empty($logs)): ?>
            <p style="text-align:center; padding: 20px;">Nincs rögzített aktivitás ehhez a fájlhoz.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Időbélyeg</th><th>Esemény Típusa</th><th>IP Cím</th><th>Böngésző/OS</th><th>Helyzet</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td data-label="Időbélyeg"><?php echo escape(formatTimestamp($log['timestamp'])); ?></td>
                        <td data-label="Típus"><?php echo escape(str_replace('_', ' ', $log['log_type'])); ?></td>
                        <td data-label="IP Cím"><?php echo escape($log['ip_address']); ?></td>
                        <td data-label="Böngésző/OS" title="<?php echo escape($log['user_agent']); ?>">
                            <?php echo escape($log['browser_name'] . ' on ' . $log['os_name']); ?>
                        </td>
                        <td data-label="Helyzet">
                            <?php echo escape($log['city_name'] ? $log['city_name'] . ', ' : '') . escape($log['country_code']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- JS SZEKCIÓ (ÚJ) -->
<script>
    const ajaxBaseUrl = '<?php echo BASE_URL; ?>tracker/ajax_chart_data.php';
    const currentFileId = <?php echo $fileId; ?>;
</script>
<script src="<?php echo BASE_URL . 'assets/js/file_details.js'; ?>"></script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>