<?php
// Hely: /UploadHandler.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

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

// 1. Bemeneti adatok és token validálása
// ----------------------------------------------------
$uploadTokenValue = $_POST['upload_token'] ?? null;
if (!$uploadTokenValue) {
    exitWithError('Hiányzó feltöltési token.');
}

$db = getDB();
$tokenStmt = $db->prepare("
    SELECT id, user_id, token_type, max_uploads, name, upload_count, is_active, expiry_time, webhook_url
    FROM tokens
    WHERE token_value = :token_value
");
$tokenStmt->execute([':token_value' => $uploadTokenValue]);
$token = $tokenStmt->fetch();

if (!$token) {
    exitWithError('Érvénytelen feltöltési token.', 404);
}

if (!$token['is_active']) {
    exitWithError('Ez a feltöltési link már nem aktív.', 403);
}

if (!in_array($token['token_type'], ['file_request_permanent', 'file_request_limited'])) {
    exitWithError('Ez a link nem fájlfeltöltésre szolgál.', 403);
}

// === ÚJ: Lejárati idő ellenőrzés ===
$now = time();
$expiryTimestamp = $token['expiry_time'] ? strtotime($token['expiry_time']) : null;
if ($expiryTimestamp !== null && $now > $expiryTimestamp) {
    exitWithError('Ez a feltöltési link lejárt.', 403);
}

if (
    $token['token_type'] === 'file_request_limited'
    && $token['max_uploads'] !== null
    && $token['upload_count'] >= $token['max_uploads']
) {
    exitWithError('Ezen a linken már elérték a maximális feltöltési számot.', 403);
}

// === Fájl megléte és hibák ellenőrzése ===
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    exitWithError('Fájlfeltöltési hiba: ' . ($_FILES['file']['error'] ?? 'Nincs fájl.'));
}


// 2. Fájl adatainak feldolgozása
// ----------------------------------------------------
$file = $_FILES['file'];
$originalName = basename($_POST['name'] ?? $file['name']);
$uploadPath = __DIR__ . '/uploads/'; // A configból is jöhetne

// 3. Darabolt (Chunked) feltöltés kezelése
// ----------------------------------------------------
if (isset($_POST['chunk'])) {
    $chunkIndex = intval($_POST['chunk']);
    $totalChunks = intval($_POST['chunks']);
    $fileIdForChunks = preg_replace('/[^a-zA-Z0-9-]/', '', $_POST['file_id']); // Biztonsági tisztítás
    $chunkDir = $uploadPath . 'chunks/' . $fileIdForChunks;

    if (!is_dir($chunkDir)) @mkdir($chunkDir, 0777, true);
    $chunkFile = $chunkDir . '/chunk_' . $chunkIndex;

    if (!move_uploaded_file($file['tmp_name'], $chunkFile)) {
        exitWithError('Nem sikerült a darab mentése.', 500);
    }

    // Ha az utolsó darab érkezett, fűzzük össze a fájlt
    if ($chunkIndex === ($totalChunks - 1)) {
        $finalFileId = 0; // Az adatbázis ID-t fogjuk itt tárolni
        try {
            $db->beginTransaction();

            // 1. Új bejegyzés a 'files' táblában, hogy megkapjuk az ID-t
            $viewToken = generateFileViewToken();
            $fileInsertStmt = $db->prepare(
                "INSERT INTO files (user_id, upload_token_id, stored_filename, original_filename, file_size, mime_type, upload_ip, view_token)
                 VALUES (:user_id, :token_id, :stored, :original, 0, 'application/octet-stream', :ip, :view_token)"
            );
            $fileInsertStmt->execute([
                ':user_id' => $token['user_id'],
                ':token_id' => $token['id'],
                ':stored' => 'placeholder', // Ezt később frissítjük
                ':original' => $originalName,
                ':ip' => getIpAddress(),
                ':view_token' => $viewToken,
            ]);
            $finalFileId = $db->lastInsertId();
            $storedFileName = (string)$finalFileId;
            $finalPath = $uploadPath . $storedFileName;

            // 2. Fájlok összefűzése
            $finalHandle = fopen($finalPath, 'wb');
            $totalSize = 0;
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $chunkDir . '/chunk_' . $i;
                $chunkHandle = fopen($chunkPath, 'rb');
                $chunkContent = fread($chunkHandle, filesize($chunkPath));
                fclose($chunkHandle);
                fwrite($finalHandle, $chunkContent);
                $totalSize += filesize($chunkPath);
                unlink($chunkPath);
            }
            fclose($finalHandle);
            rmdir($chunkDir);

            // 3. Metaadatok frissítése a 'files' táblában
            $mimeType = mime_content_type($finalPath) ?: 'application/octet-stream';
            $fileUpdateStmt = $db->prepare("UPDATE files SET stored_filename = :stored, file_size = :size, mime_type = :mime WHERE id = :id");
            $fileUpdateStmt->execute([
                ':stored' => $storedFileName,
                ':size' => $totalSize,
                ':mime' => $mimeType,
                ':id' => $finalFileId
            ]);

            // 4. Token számláló frissítése
            $newUploadCount = $token['upload_count'] + 1;
            $tokenUpdateSql = "UPDATE tokens SET upload_count = :count";
            if ($token['token_type'] === 'file_request_limited' && $newUploadCount >= $token['max_uploads']) {
                $tokenUpdateSql .= ", is_active = 0";
            }
            $tokenUpdateSql .= " WHERE id = :id";
            $tokenUpdateStmt = $db->prepare($tokenUpdateSql);
            $tokenUpdateStmt->execute([':count' => $newUploadCount, ':id' => $token['id']]);
            
            logActivity('file_upload', $token['id'], $finalFileId);
            
            // ÚJ: INDEXKÉP GENERÁLÁSA
            if (strpos($mimeType, 'image/') === 0) {
                $thumbnailPath = __DIR__ . '/thumbnails/' . $finalFileId . '.webp';
                createThumbnail($finalPath, $thumbnailPath);
            }

            $db->commit();
            
            // Sikeres válasz
            $response['success'] = true;
            $response['file_id'] = $viewToken; // <-- EZ AZ ÚJ SOR
            $response['message'] = 'Fájl sikeresen feltöltve.';
            $response['view_url'] = BASE_URL . 'View.php?id=' . $viewToken;
            echo json_encode($response);

        } catch (Exception $e) {
            $db->rollBack();
            if ($finalFileId > 0) @unlink($uploadPath . $finalFileId); // Takarítás
            exitWithError('Fájl összeállítási hiba: ' . $e->getMessage(), 500);
        }
        exit;
    } else {
        // Köztes darab sikeresen feltöltve
        $response['success'] = true;
        $response['chunk_uploaded'] = $chunkIndex;
        echo json_encode($response);
        exit;
    }
}

// 4. Direkt (nem darabolt) feltöltés kezelése
// ----------------------------------------------------
try {
    $db->beginTransaction();

    // 1. Új bejegyzés a files táblában
    $viewToken = generateFileViewToken();
    $fileInsertStmt = $db->prepare(
        "INSERT INTO files (user_id, upload_token_id, stored_filename, original_filename, file_size, mime_type, upload_ip, view_token)
         VALUES (:user_id, :token_id, :stored, :original, :size, :mime, :ip, :view_token)"
    );
    $mimeType = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
    $fileInsertStmt->execute([
        ':user_id' => $token['user_id'],
        ':token_id' => $token['id'],
        ':stored' => 'placeholder',
        ':original' => $originalName,
        ':size' => $file['size'],
        ':mime' => $mimeType,
        ':ip' => getIpAddress(),
        ':view_token' => $viewToken
    ]);
    $newFileId = $db->lastInsertId();
    $storedFileName = (string)$newFileId;

    // 2. Fájl mozgatása és átnevezése
    if (!move_uploaded_file($file['tmp_name'], $uploadPath . $storedFileName)) {
        throw new Exception('A fájl mozgatása sikertelen.');
    }
    
    // 3. Stored filename frissítése
    $db->prepare("UPDATE files SET stored_filename = :stored WHERE id = :id")->execute([':stored' => $storedFileName, ':id' => $newFileId]);
    
    // 4. Token számláló frissítése
    $newUploadCount = $token['upload_count'] + 1;
    $tokenUpdateSql = "UPDATE tokens SET upload_count = :count";
    if ($token['token_type'] === 'file_request_limited' && $newUploadCount >= $token['max_uploads']) {
        $tokenUpdateSql .= ", is_active = 0";
    }
    $tokenUpdateSql .= " WHERE id = :id";
    $tokenUpdateStmt = $db->prepare($tokenUpdateSql);
    $tokenUpdateStmt->execute([':count' => $newUploadCount, ':id' => $token['id']]);

    // 5. Naplózás
// ...
    logActivity('file_upload', $token['id'], $newFileId);
    
    // ÚJ: INDEXKÉP GENERÁLÁSA
    if (strpos($mimeType, 'image/') === 0) {
        $thumbnailPath = __DIR__ . '/thumbnails/' . $newFileId . '.webp';
        $sourcePath = $uploadPath . $storedFileName;
        createThumbnail($sourcePath, $thumbnailPath);
    }

    // Webhook értesítés feltöltésről
    if (!empty($token['webhook_url'])) {
        $msg = "**Fájl:** {$originalName}\n**Méret:** " . formatBytes($totalSize ?? $file['size']) . "\n**Feltöltő IP:** " . getIpAddress();
        sendWebhookNotification($token['webhook_url'], "file" . $token['name'], $msg, 15844367); // Gold
    }

    $db->commit();
    
    $response['success'] = true;
    $response['file_id'] = $viewToken;
    $response['message'] = 'Fájl sikeresen feltöltve.';
    $response['view_url'] = BASE_URL . 'View.php?id=' . $viewToken;
    echo json_encode($response);

} catch (Exception $e) {
    $db->rollBack();
    exitWithError('Adatbázis hiba a direkt feltöltés során: ' . $e->getMessage(), 500);
}