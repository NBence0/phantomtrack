<?php
// Hely: /UploadHandler.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php'; // Szükséges a session ellenőrzéshez

// Hibakezelés és JSON válasz
header('Content-Type: application/json');
$response = ['success' => false];

function exitWithError($message, $httpCode = 400) {
    global $response;
    http_response_code($httpCode);
    $response['error'] = $message;
    echo json_encode($response);
    exit;
}

$db = getDB();

// --- BEMENETI ADATOK ELEMZÉSE (Token VAGY Galéria ID) ---
$uploadTokenValue = $_POST['upload_token'] ?? null;
$galleryId = $_POST['gallery_id'] ?? null;

$targetUserId = null;
$targetTokenId = null; // Csak ha public link
$targetGalleryId = null; // Csak ha galéria feltöltés
$isGalleryUpload = false;

// 1. Eset: Publikus feltöltési link (eredeti működés)
if ($uploadTokenValue && $uploadTokenValue !== 'undefined') {
    $tokenStmt = $db->prepare("
        SELECT id, user_id, token_type, max_uploads, name, upload_count, is_active, expiry_time, webhook_url
        FROM tokens
        WHERE token_value = :token_value
    ");
    $tokenStmt->execute([':token_value' => $uploadTokenValue]);
    $token = $tokenStmt->fetch();

    if (!$token) exitWithError('Érvénytelen feltöltési token.', 404);
    if (!$token['is_active']) exitWithError('Ez a feltöltési link már nem aktív.', 403);
    
    // Validációk (lejárat, max upload)...
    $now = time();
    $expiryTimestamp = $token['expiry_time'] ? strtotime($token['expiry_time']) : null;
    if ($expiryTimestamp !== null && $now > $expiryTimestamp) exitWithError('Ez a feltöltési link lejárt.', 403);
    if ($token['token_type'] === 'file_request_limited' && $token['max_uploads'] !== null && $token['upload_count'] >= $token['max_uploads']) {
        exitWithError('Limit elérve.', 403);
    }

    $targetUserId = $token['user_id'];
    $targetTokenId = $token['id'];

// 2. Eset: Galéria feltöltés (Admin/User session alapú)
} elseif ($galleryId) {
    if (!isLoggedIn()) {
        exitWithError('Nincs bejelentkezve.', 401);
    }
    
    $currentUserId = getCurrentUserId();
    
    // Ellenőrizzük, hogy a galéria az övé-e
    $galStmt = $db->prepare("SELECT id, user_id FROM galleries WHERE id = :id AND user_id = :uid");
    $galStmt->execute([':id' => $galleryId, ':uid' => $currentUserId]);
    $gallery = $galStmt->fetch();
    
    if (!$gallery) {
        exitWithError('Galéria nem található vagy nincs jogosultságod.', 403);
    }
    
    $targetUserId = $currentUserId;
    $targetGalleryId = $gallery['id'];
    $isGalleryUpload = true;

} else {
    exitWithError('Hiányzó hitelesítési adatok (Token vagy Galéria ID).');
}


// === Fájl megléte és hibák ellenőrzése ===
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    exitWithError('Fájlfeltöltési hiba: ' . ($_FILES['file']['error'] ?? 'Nincs fájl.'));
}

// 2. Fájl adatainak feldolgozása
$file = $_FILES['file'];
$originalName = basename($_POST['name'] ?? $file['name']);
$uploadPath = __DIR__ . '/uploads/';

// 3. Darabolt (Chunked) feltöltés kezelése
if (isset($_POST['chunk'])) {
    $chunkIndex = intval($_POST['chunk']);
    $totalChunks = intval($_POST['chunks']);
    $fileIdForChunks = preg_replace('/[^a-zA-Z0-9-]/', '', $_POST['file_id']);
    $chunkDir = $uploadPath . 'chunks/' . $fileIdForChunks;

    if (!is_dir($chunkDir)) @mkdir($chunkDir, 0777, true);
    $chunkFile = $chunkDir . '/chunk_' . $chunkIndex;

    if (!move_uploaded_file($file['tmp_name'], $chunkFile)) {
        exitWithError('Nem sikerült a darab mentése.', 500);
    }

    if ($chunkIndex === ($totalChunks - 1)) {
        // Összefűzés és mentés
        $finalFileId = 0;
        try {
            $db->beginTransaction();

            $viewToken = generateFileViewToken();
            $fileInsertStmt = $db->prepare(
                "INSERT INTO files (user_id, upload_token_id, gallery_id, stored_filename, original_filename, file_size, mime_type, upload_ip, view_token)
                 VALUES (:user_id, :token_id, :gallery_id, :stored, :original, 0, 'application/octet-stream', :ip, :view_token)"
            );
            $fileInsertStmt->execute([
                ':user_id' => $targetUserId,
                ':token_id' => $targetTokenId, // Lehet NULL
                ':gallery_id' => $targetGalleryId, // Lehet NULL
                ':stored' => 'placeholder',
                ':original' => $originalName,
                ':ip' => getIpAddress(),
                ':view_token' => $viewToken,
            ]);
            $finalFileId = $db->lastInsertId();
            $storedFileName = (string)$finalFileId;
            $finalPath = $uploadPath . $storedFileName;

            // Fájlok összefűzése
            $finalHandle = fopen($finalPath, 'wb');
            $totalSize = 0;
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $chunkDir . '/chunk_' . $i;
                $chunkHandle = fopen($chunkPath, 'rb');
                fwrite($finalHandle, fread($chunkHandle, filesize($chunkPath)));
                $totalSize += filesize($chunkPath);
                fclose($chunkHandle);
                unlink($chunkPath);
            }
            fclose($finalHandle);
            rmdir($chunkDir);

            // Metaadatok frissítése
            $mimeType = mime_content_type($finalPath) ?: 'application/octet-stream';
            $db->prepare("UPDATE files SET stored_filename = :stored, file_size = :size, mime_type = :mime WHERE id = :id")
               ->execute([':stored' => $storedFileName, ':size' => $totalSize, ':mime' => $mimeType, ':id' => $finalFileId]);

            // Token számláló frissítése (csak ha tokenes feltöltés)
            if (!$isGalleryUpload && $targetTokenId) {
                $newUploadCount = $token['upload_count'] + 1;
                $tokenUpdateSql = "UPDATE tokens SET upload_count = :count";
                if ($token['token_type'] === 'file_request_limited' && $newUploadCount >= $token['max_uploads']) {
                    $tokenUpdateSql .= ", is_active = 0";
                }
                $tokenUpdateSql .= " WHERE id = :id";
                $db->prepare($tokenUpdateSql)->execute([':count' => $newUploadCount, ':id' => $targetTokenId]);
            }
            
            // Naplózás
            logActivity('file_upload', $targetTokenId, $finalFileId);

            // Thumbnail
            if (strpos($mimeType, 'image/') === 0) {
                // Mappa ellenőrzés
                $thumbDir = __DIR__ . '/thumbnails/';
                if (!file_exists($thumbDir)) {
                    mkdir($thumbDir, 0755, true);
                }
                
                // Névképzés a view_token alapján (nem ID!)
                $thumbnailPath = $thumbDir . $viewToken . '.webp';
                
                // Windows útvonal javítás (opcionális, de segít)
                $sourcePath = str_replace('\\', '/', $uploadPath . $storedFileName);
                $thumbnailPath = str_replace('\\', '/', $thumbnailPath);

                createThumbnail($sourcePath, $thumbnailPath);
            }

            $db->commit();
            
            $response['success'] = true;
            $response['file_id'] = $viewToken;
            $response['message'] = 'Fájl sikeresen feltöltve.';
            $response['view_url'] = BASE_URL . 'View.php?id=' . $viewToken;
            echo json_encode($response);

        } catch (Exception $e) {
            $db->rollBack();
            if ($finalFileId > 0) @unlink($uploadPath . $finalFileId);
            exitWithError('Fájl összeállítási hiba: ' . $e->getMessage(), 500);
        }
        exit;
    } else {
        $response['success'] = true;
        $response['chunk_uploaded'] = $chunkIndex;
        echo json_encode($response);
        exit;
    }
}

// 4. Direkt feltöltés kezelése (Hasonló módosításokkal)
try {
    $db->beginTransaction();

    $viewToken = generateFileViewToken();
    $mimeType = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';

    $fileInsertStmt = $db->prepare(
        "INSERT INTO files (user_id, upload_token_id, gallery_id, stored_filename, original_filename, file_size, mime_type, upload_ip, view_token)
         VALUES (:user_id, :token_id, :gallery_id, :stored, :original, :size, :mime, :ip, :view_token)"
    );
    $fileInsertStmt->execute([
        ':user_id' => $targetUserId,
        ':token_id' => $targetTokenId,
        ':gallery_id' => $targetGalleryId,
        ':stored' => 'placeholder',
        ':original' => $originalName,
        ':size' => $file['size'],
        ':mime' => $mimeType,
        ':ip' => getIpAddress(),
        ':view_token' => $viewToken
    ]);
    $newFileId = $db->lastInsertId();
    $storedFileName = (string)$newFileId;

    if (!move_uploaded_file($file['tmp_name'], $uploadPath . $storedFileName)) {
        throw new Exception('Fájl mozgatása sikertelen.');
    }
    
    $db->prepare("UPDATE files SET stored_filename = :stored WHERE id = :id")->execute([':stored' => $storedFileName, ':id' => $newFileId]);
    
    // Token számláló (csak ha tokenes)
    if (!$isGalleryUpload && $targetTokenId) {
        $newUploadCount = $token['upload_count'] + 1;
        $db->prepare("UPDATE tokens SET upload_count = :count WHERE id = :id")
           ->execute([':count' => $newUploadCount, ':id' => $targetTokenId]);
    }

    logActivity('file_upload', $targetTokenId, $newFileId);
    
    if (strpos($mimeType, 'image/') === 0) {
        // Mappa ellenőrzés
        $thumbDir = __DIR__ . '/thumbnails/';
        if (!file_exists($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }
        
        // Névképzés a view_token alapján (nem ID!)
        $thumbnailPath = $thumbDir . $viewToken . '.webp';
        
        // Windows útvonal javítás (opcionális, de segít)
        $sourcePath = str_replace('\\', '/', $uploadPath . $storedFileName);
        $thumbnailPath = str_replace('\\', '/', $thumbnailPath);

        createThumbnail($sourcePath, $thumbnailPath);
    }

    // Webhook (csak ha van tokenhez rendelt)
    if (!$isGalleryUpload && !empty($token['webhook_url'])) {
        $msg = "**Fájl:** {$originalName}\n**Méret:** " . formatBytes($file['size']);
        sendWebhookNotification($token['webhook_url'], "file" . $token['name'], $msg, 15844367);
    }

    $db->commit();
    
    $response['success'] = true;
    $response['file_id'] = $viewToken;
    $response['message'] = 'Fájl sikeresen feltöltve.';
    $response['view_url'] = BASE_URL . 'View.php?id=' . $viewToken;
    echo json_encode($response);

} catch (Exception $e) {
    $db->rollBack();
    exitWithError('Adatbázis hiba: ' . $e->getMessage(), 500);
}