<?php
// === Fájl: /Upload.php ===

// 1. Alapvető beállítások és validáció
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$uploadTokenValue = $_GET['token'] ?? null;
$isValidToken = false;
$errorMessage = 'A megadott feltöltési link érvénytelen vagy lejárt.';
$tokenData = null;

if ($uploadTokenValue) {
    $db = getDB();
    $tokenStmt = $db->prepare("
        SELECT id, user_id, name, token_type, max_uploads, upload_count, is_active, expiry_time
        FROM tokens
        WHERE token_value = :token_value
    ");
    $tokenStmt->execute([':token_value' => $uploadTokenValue]);
    $tokenData = $tokenStmt->fetch();

    if ($tokenData && $tokenData['is_active'] && in_array($tokenData['token_type'], ['file_request_permanent', 'file_request_limited'])) {
        $now = time();
        $expiryTimestamp = $tokenData['expiry_time'] ? strtotime($tokenData['expiry_time']) : null;

        if ($tokenData['token_type'] === 'file_request_limited'
            && $tokenData['max_uploads'] !== null
            && $tokenData['upload_count'] >= $tokenData['max_uploads']) {
            $errorMessage = 'Ezen a linken már elérték a maximális feltöltési számot.';
        } elseif ($expiryTimestamp !== null && $now > $expiryTimestamp) {
            $errorMessage = 'Ez a feltöltési link lejárt.'; // ÚJ FELTÉTEL
        } else {
            $isValidToken = true;
        }
    }
}

// Publikus fejléc
require_once __DIR__ . '/includes/header_public.php';
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/uploader.css">

<!-- A HTML struktúra most már a teljes eredeti funkcionalitást tartalmazza -->
<div class="container">
    
    <?php if ($isValidToken): ?>
        <!-- Ha a token érvényes, megjelenítjük a feltöltő felületet -->
        <div class="header">
            <h1>🚀 Fájl Feltöltése</h1>
            <p>A(z) "<?php echo escape($tokenData['name']); ?>" nevű bekérőhöz</p>
        </div>

        <div class="upload-area" id="uploadArea">
            <div class="upload-icon">📁</div>
            <div class="upload-text">Húzd ide a fájlokat vagy kattints a feltöltéshez</div>
            <div class="upload-subtext">Támogatott: minden fájltípus, mappák, több fájl egyszerre</div>
            
            <div class="upload-buttons">
                <button class="btn" onclick="document.getElementById('fileInput').click()">
                    📄 Fájl választás
                </button>
                <button class="btn btn-secondary" onclick="document.getElementById('folderInput').click()">
                    📁 Mappa feltöltés
                </button>
                <!-- VISSZAÁLLÍTOTT GOMBOK -->
                <button class="btn btn-secondary" onclick="startVoiceRecording()">
                    🎤 Hangjegyzet
                </button>
                <button class="btn btn-secondary" onclick="pasteFromClipboard()">
                    📋 Beillesztés (Ctrl+V)
                </button>
            </div>

            <!-- A progress bar-ok és a feltöltési sor konténerei -->
            <div id="uploadQueueOverallProgress" style="display: none; margin-top: 20px;">
                <h4 style="color:#4ecdc4; margin-bottom:5px;">Teljes feltöltési folyamat:</h4>
                <div class="progress-bar"><div class="progress-fill" id="overallProgressFill"></div></div>
                <div class="progress-info">
                    <span id="overallProgressText">0%</span>
                    <span id="overallFilesProcessedText">0/0 fájl</span>
                </div>
            </div>
            <div id="uploadQueueItemsContainer" style="margin-top: 15px; max-height: 250px; overflow-y: auto; padding-right:10px;"></div>
            <div class="progress-container" id="currentFileProgressContainer" style="display: none; margin-top:10px; border-top: 1px solid rgba(255,255,255,0.1); padding-top:10px;">
                <p id="currentFileNameText" style="color:#bdc3c7; font-size:0.9em; margin-bottom:5px; text-align:left;"></p>
                <div class="progress-bar"><div class="progress-fill" id="currentFileProgressFill"></div></div>
                <div class="progress-info">
                    <span id="currentFileProgressText">0%</span>
                    <span id="currentFileSpeedText">0 KB/s</span>
                    <span id="currentFileChunkInfoText"></span>
                </div>
            </div>

            <!-- VISSZAÁLLÍTOTT HANGJEGYZET UI -->
            <div class="voice-controls" id="voiceControls">
                <p>🎤 Hangjegyzet rögzítése...</p>
                <button class="btn" onclick="stopVoiceRecording()">⏹️ Befejezés</button>
                <button class="btn btn-secondary" onclick="cancelVoiceRecording()">❌ Mégsem</button>
            </div>
        </div>

        <!-- A sikeres feltöltés utáni üzenetek helye (JS tölti fel) -->
        <div id="success-messages-container"></div>

        <!-- Rejtett inputok a fájl- és mappaválasztáshoz -->
        <input type="file" id="fileInput" multiple style="display: none;">
        <input type="file" id="folderInput" webkitdirectory multiple style="display: none;">

    <?php else: ?>
        <!-- Ha a token érvénytelen, hibaüzenetet jelenítünk meg -->
        <div class="header">
            <h1 style="color: #ff4757;">❌ Hozzáférés Megtagadva</h1>
            <p><?php echo escape($errorMessage); ?></p>
            <a href="<?php echo BASE_URL; ?>" class="btn btn-secondary" style="margin-top: 20px;">Vissza a főoldalra</a>
        </div>
    <?php endif; ?>
</div>

<!-- Kiegészített showSuccess függvény a JS számára -->
<script>
    // Felülírjuk az alapértelmezett showSuccess függvényt, hogy a QR kódot mindig megjelenítse,
    // és helyesen kezelje a fájl ID-t.
    function showSuccess(response) {
        const successContainer = document.getElementById('success-messages-container');
        const successDiv = document.createElement('div');
        successDiv.style.cssText = "background: rgba(46, 204, 113, 0.1); border: 1px solid #2ecc71; border-radius: 10px; padding: 20px; margin-top: 20px; text-align: center; position: relative;";

        // A QR kód URL-jét a qr.php-ra irányítjuk a kapott view_url-lel.
        const qrCodeUrlForGenerator = `<?php echo BASE_URL; ?>qr.php?data=${encodeURIComponent(response.view_url)}`;

        // A QR kód HTML-jét mindig legeneráljuk.
        const qrCodeHTML = `
            <div style="margin-bottom: 15px;">
                <img src="${qrCodeUrlForGenerator}" alt="QR Kód" title="QR Kód a fájlhoz" style="width: 150px; height: 150px; border: 1px solid #ccc; border-radius: 5px; background: white; padding: 5px;">
            </div>`;

        successDiv.innerHTML = `
            <button onclick="this.parentElement.remove();" style="position: absolute; top: 10px; right: 10px; background: transparent; border: none; font-size: 1.5rem; color: #aaa; cursor: pointer; line-height:1;">×</button>
            <h3 style="color: #2ecc71; margin-bottom: 15px;">✅ Feltöltés sikeres!</h3>
            <p style="margin-bottom: 10px;">Fájl ID: <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 3px;">${response.file_id}</code></p>
            ${qrCodeHTML}
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <a href="${response.view_url}" target="_blank" class="btn">👁️ Megtekintés</a>
                <button class="btn btn-secondary" onclick="copyToClipboard('${response.view_url}')">📋 Link másolás</button>
            </div>
        `;
        
        successContainer.prepend(successDiv);
    }
</script>
<!-- A végén betöltjük az uploader scriptet -->
<script src="<?php echo BASE_URL; ?>assets/js/uploader.js"></script>


<?php
require_once __DIR__ . '/includes/footer_public.php';
?>