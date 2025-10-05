<?php
// Hely: /View.php

// === FÜGGŐSÉGEK ===
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// === ADATBÁZIS ÉS VALIDÁCIÓ ===
$db = getDB();
$viewToken = $_GET['id'] ?? '';
$isDownloadRequest = isset($_GET['download']);
$fileData = null;
$errorReason = null;
$validation = ['valid' => false, 'reason' => 'Érvénytelen hozzáférés.'];

if (empty($viewToken)) {
    http_response_code(400);
    die('Hiányzó fájl azonosító (view token).');
}

// 1. Fájl adatainak lekérdezése
$stmt = $db->prepare(
    "SELECT id, user_id, original_filename, stored_filename, file_size, mime_type, upload_timestamp, download_count, 
            password_hash, expiry_time, max_downloads, one_time_download, 
            ip_whitelist, ip_blacklist 
     FROM files WHERE view_token = :token"
);
$stmt->execute([':token' => $viewToken]);
$fileData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fileData) {
    http_response_code(404);
    die('Fájl nem található.');
}

// HELPER FÜGGVÉNY A VALIDÁCIÓHOZ (a PHP blokkon belül)
function validateFileAccess($fileData) {
    $fileId = $fileData['id'];
    $ip = getIpAddress();
    $now = time();
    $expiryTimestamp = $fileData['expiry_time'] ? strtotime($fileData['expiry_time']) : null;
    
    if ($expiryTimestamp && $now > $expiryTimestamp) {
        logActivity('file_view_expired', null, $fileId);
        return ['valid' => false, 'reason' => 'A fájl lejárt.'];
    }
    
    $ipBlacklist = $fileData['ip_blacklist'] ? json_decode($fileData['ip_blacklist'], true) : [];
    if (!empty($ipBlacklist) && in_array($ip, $ipBlacklist)) {
        logActivity('file_view_denied', null, $fileId);
        return ['valid' => false, 'reason' => 'Hozzáférés megtagadva (IP Blacklist).'];
    }
    
    $ipWhitelist = $fileData['ip_whitelist'] ? json_decode($fileData['ip_whitelist'], true) : [];
    if (!empty($ipWhitelist) && !in_array($ip, $ipWhitelist)) {
        logActivity('file_view_denied', null, $fileId);
        return ['valid' => false, 'reason' => 'Hozzáférés megtagadva (IP Whitelist).'];
    }
    
    if ($fileData['password_hash']) {
        session_start();
        if (!isset($_SESSION['authenticated_files'][$fileId]) || (time() - $_SESSION['authenticated_files'][$fileId] > 3600)) {
            if (isset($_SESSION['authenticated_files'][$fileId])) unset($_SESSION['authenticated_files'][$fileId]);
            return ['valid' => false, 'reason' => 'password_required'];
        }
    }
    
    return ['valid' => true];
}

$validation = validateFileAccess($fileData);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    session_start();
    if (password_verify($_POST['password'], $fileData['password_hash'])) {
        $_SESSION['authenticated_files'][$fileData['id']] = time();
        header("Location: View.php?id=" . urlencode($viewToken));
        exit;
    } else {
        $errorReason = 'Hibás jelszó. Próbálja újra!';
        $validation = ['valid' => false, 'reason' => 'password_required'];
    }
}

if ($isDownloadRequest) {
    header('Location: FileDownloader.php?token=' . urlencode($viewToken));
    exit;
}

if ($validation['valid']) {
    logActivity('file_view', null, $fileData['id']); 
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escape($fileData['original_filename']); ?> - PhantomTrack</title>
    <!-- Külső CSS fájl betöltése -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/view.css">
    <!-- CDN-ek a megtekintéshez (Prism.js, Marked.js) -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-okaidia.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js" defer></script> 
</head>
<body>

<?php if (!$validation['valid']): ?>
    <div class="password-form">
        <?php if ($validation['reason'] === 'password_required'): ?>
            <div class="lock-icon">🔒</div>
            <h2 class="form-title">Védett fájl</h2>
            <p class="form-desc">Ez a fájl jelszóval védett. Kérjük, adja meg a jelszót a hozzáféréshez.</p>
            <?php if ($errorReason): ?><div class="error-message"><?php echo escape($errorReason); ?></div><?php endif; ?>
            <form method="POST">
                <div class="form-group"><input type="password" name="password" class="form-input" placeholder="Jelszó" required autofocus></div>
                <button type="submit" class="btn">🔓 Hozzáférés</button>
            </form>
        <?php else: http_response_code(403); ?>
            <div class="lock-icon" style="font-size:3rem; color:#ff4757;">⛔</div>
            <h2 class="form-title" style="color: #ff4757;">Hozzáférés Megtagadva</h2>
            <p class="form-desc"><?php echo escape($validation['reason']); ?></p>
        <?php endif; ?>
    </div>
    <?php exit; // Itt a script véget ér, ha a validáció sikertelen ?>
<?php endif; ?>

<div class="container">
    <div class="header">
        <div class="file-info">
            <div class="file-icon"><?= getFileIcon($fileData['mime_type'], $fileData['original_filename']) ?></div>
            <div class="file-details">
                <h1><?= escape($fileData['original_filename']) ?></h1>
                <div class="file-meta">
                    <?= escape($fileData['mime_type']) ?> • <?= formatBytes($fileData['file_size']) ?> • 
                    Feltöltve: <?= escape(formatTimestamp($fileData['upload_timestamp'], 'Y-m-d H:i')) ?> •
                    Letöltések: <?= escape($fileData['download_count']) ?>
                    <?php if ($fileData['expiry_time']): ?>
                        • Lejár: <?= escape(formatTimestamp($fileData['expiry_time'], 'Y-m-d H:i')) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="actions">
            <a href="?id=<?= $viewToken ?>&download=1" class="btn">📥 Letöltés</a>
            <a href="qr.php?data=<?= urlencode(BASE_URL . 'View.php?id=' . $viewToken) ?>" target="_blank" class="btn btn-secondary">📱 QR kód</a>
            <button onclick="copyToClipboard('<?= BASE_URL . 'View.php?id=' . $viewToken ?>')" class="btn btn-secondary">📋 Link másolás</button>
            <a href="<?php echo BASE_URL; ?>tracker/dashboard.php" class="btn btn-secondary">🏠 Irányítópult</a>
        </div>
    </div>
    
    <div class="preview-container">
        <?php
        $fileIdForPreview = $fileData['id'];
        $dataUrl = 'data.php?id=' . urlencode($viewToken); // Itt a viewToken kell

        if (strpos($fileData['mime_type'], 'image/') === 0): ?>
            <img src="<?= $dataUrl ?>&type=raw" alt="<?= escape($fileData['original_filename']) ?>" class="preview-image">
        <?php elseif (strpos($fileData['mime_type'], 'video/') === 0): ?>
            <video controls class="preview-video"><source src="<?= $dataUrl ?>&type=raw" type="<?= $fileData['mime_type'] ?>">Böngészője nem támogatja a videót.</video>
        <?php elseif (strpos($fileData['mime_type'], 'audio/') === 0): ?>
            <audio controls class="preview-audio"><source src="<?= $dataUrl ?>&type=raw" type="<?= $fileData['mime_type'] ?>">Böngészője nem támogatja az audiót.</audio>
        <?php elseif ($fileData['mime_type'] === 'application/pdf'): ?>
            <iframe src="<?= $dataUrl ?>&type=raw" class="preview-pdf"></iframe>
        <?php elseif (strpos($fileData['mime_type'], 'text/') === 0 || in_array($fileData['mime_type'], ['application/json', 'application/xml', 'application/javascript', 'application/x-php'])):
            $isMarkdown = in_array($fileData['mime_type'], ['text/markdown', 'text/x-markdown']);
            $language = $isMarkdown ? 'markdown' : detectCodeLanguageFromMime($fileData['mime_type']);
            if ($isMarkdown): ?>
                 <div class="preview-markdown" id="markdownPreviewContainer">Betöltés...</div>
            <?php else: ?>
                 <div class="preview-text"><pre><code id="textPreviewCode" class="<?= $language ? 'language-' . escape($language) : '' ?>">Betöltés...</code></pre></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="file-icon-large"><?= getFileIcon($fileData['mime_type'], $fileData['original_filename']) ?></div>
            <div class="no-preview">Ez a fájltípus nem támogatja az előnézetet.</div>
        <?php endif; ?>
    </div>
    
        <div class="stats">
         <div class="stat-card">
            <div class="stat-value"><?= formatBytes($fileData['file_size']) ?></div>
            <div class="stat-label">Méret</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $fileData['download_count'] ?></div>
            <div class="stat-label">Letöltések</div>
        </div>
        <?php if ($fileData['one_time_download']): ?>
        <div class="stat-card" style="background: rgba(231, 76, 60, 0.2);">
            <div class="stat-value" style="color: #ff4757;">EGYSZERI</div>
            <div class="stat-label">Letöltés után törlődik</div>
        </div>
        <?php endif; ?>
        <?php if ($fileData['expiry_time']): ?>
        <div class="stat-card">
            <div class="stat-value" id="countdown">--:--:--</div>
            <div class="stat-label">Lejáratig hátra</div>
        </div>
        <script>
            function updateCountdown() {
                const expiryTime = <?= strtotime($fileData['expiry_time']) ?> * 1000;
                const now = new Date().getTime();
                const distance = expiryTime - now;
                if (distance < 0) {
                    document.getElementById('countdown').innerHTML = 'LEJÁRT'; return;
                }
                const days = Math.floor(distance / (1000 * 60 * 60 * 24));
                const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
                const seconds = Math.floor((distance % (1000 * 60)) / 1000);
                let timeString = '';
                if (days > 0) timeString += days + 'n ';
                timeString += String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
                document.getElementById('countdown').innerHTML = timeString;
            }
            updateCountdown();
            setInterval(updateCountdown, 1000);
        </script>
        <?php endif; ?>
    </div>
</div>
    
<script>
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            // A showDynamicMessage a PhantomTrack globális scriptjéből jön
            if(typeof showDynamicMessage === 'function') {
                showDynamicMessage('Link vágólapra másolva!', 'success');
            } else { alert('Link vágólapra másolva!'); }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const textPreviewCode = document.getElementById('textPreviewCode');
        const markdownPreviewContainer = document.getElementById('markdownPreviewContainer');

        if (textPreviewCode) {
            fetch('<?= $dataUrl ?>&type=text')
                .then(response => response.text())
                .then(text => {
                    textPreviewCode.textContent = text;
                    if (typeof Prism !== 'undefined') Prism.highlightElement(textPreviewCode);
                });
        }
        if (markdownPreviewContainer) {
            fetch('<?= $dataUrl ?>&type=text')
                .then(response => response.text())
                .then(text => {
                    if (typeof marked !== 'undefined') {
                        markdownPreviewContainer.innerHTML = marked.parse(text);
                        if (typeof Prism !== 'undefined') {
                             markdownPreviewContainer.querySelectorAll('pre code').forEach(Prism.highlightElement);
                        }
                    }
                });
        }
    });
</script>

</body>
</html>