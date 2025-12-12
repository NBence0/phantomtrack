<?php
// === Fájl: tracker/ajax_manager.php ===

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// JSON válasz és hibakezelés előkészítése
header('Content-Type: application/json');
$response = ['success' => false, 'message' => 'Ismeretlen hiba'];

// Csak admin és POST kérés
if (!isAdmin() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Jogosulatlan hozzáférés.']);
    exit;
}

$db = getDB();
$operation = $_POST['operation'] ?? ''; // move, copy, delete
$entityType = $_POST['entity_type'] ?? ''; // tokens, galleries, files
$ids = json_decode($_POST['ids'] ?? '[]', true);
$targetUserId = (int)($_POST['target_user_id'] ?? 0);
$sourceUserId = (int)($_POST['source_user_id'] ?? 0);


if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'Nincs elem kiválasztva.']);
    exit;
}

try {
    $db->beginTransaction();
    $count = 0;

    // --- 1. TÖRLÉS (DELETE) ---
    if ($operation === 'delete') {
        $ph = str_repeat('?,', count($ids) - 1) . '?';
        
        if ($entityType === 'tokens') {
            // Activity logokat viszi a cascade
            $stmt = $db->prepare("DELETE FROM tokens WHERE id IN ($ph)");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
        } 
        elseif ($entityType === 'galleries') {
            // Galériák törlése (Cascade viszi a kommenteket, de a fájlokat nem feltétlenül!)
            // Ha a fájlokhoz beállítottunk ON DELETE SET NULL-t, akkor árvák lesznek.
            // Ha törölni akarjuk a fájlokat is:
            $filesStmt = $db->prepare("SELECT id, stored_filename FROM files WHERE gallery_id IN ($ph)");
            $filesStmt->execute($ids);
            $filesToDelete = $filesStmt->fetchAll();
            
            foreach ($filesToDelete as $f) {
                @unlink(__DIR__ . '/../uploads/' . $f['stored_filename']);
                // Thumbnail törlés (feltételezve a view_token alapú nevet, de itt ID alapút is próbálunk)
                @unlink(__DIR__ . '/../thumbnails/' . $f['id'] . '.webp');
                
                // DB rekord törlése
                $db->prepare("DELETE FROM files WHERE id = ?")->execute([$f['id']]);
            }
            
            $stmt = $db->prepare("DELETE FROM galleries WHERE id IN ($ph)");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
        }
        elseif ($entityType === 'files') {
            // Fizikai törlés
            $sel = $db->prepare("SELECT id, stored_filename, view_token FROM files WHERE id IN ($ph)");
            $sel->execute($ids);
            $filesToDelete = $sel->fetchAll();
            
            foreach ($filesToDelete as $f) {
                @unlink(__DIR__ . '/../uploads/' . $f['stored_filename']);
                @unlink(__DIR__ . '/../thumbnails/' . $f['view_token'] . '.webp');
            }
            
            $del = $db->prepare("DELETE FROM files WHERE id IN ($ph)");
            $del->execute($ids);
            $count = $del->rowCount();
        }
        // A delete ágban:
        elseif ($entityType === 'categories') {
            $stmt = $db->prepare("DELETE FROM token_categories WHERE id IN ($ph)");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
        }
    }

    // --- 2. ÁTHELYEZÉS (MOVE) ---
    elseif ($operation === 'move') {
        if (!$targetUserId) throw new Exception("Nincs célfelhasználó.");
        
        $table = '';
        if ($entityType === 'tokens') $table = 'tokens';
        elseif ($entityType === 'galleries') $table = 'galleries';
        elseif ($entityType === 'files') $table = 'files';
        elseif ($entityType === 'categories') $table = 'token_categories';
        
        if ($table) {
            $ph = str_repeat('?,', count($ids) - 1) . '?';
            $params = array_merge([$targetUserId], $ids); // [target, id1, id2...]
            $stmt = $db->prepare("UPDATE $table SET user_id = ? WHERE id IN ($ph)");
            $stmt->execute($params);
            $count = $stmt->rowCount();
        }
    }

    // --- 3. MÁSOLÁS (COPY) ---
    elseif ($operation === 'copy') {
        if (!$targetUserId) throw new Exception("Nincs célfelhasználó.");
        $ph = str_repeat('?,', count($ids) - 1) . '?';

        // Segédfüggvény fájl másoláshoz (lokális)
        $copyFileInternal = function($srcFileRow, $newOwnerId, $newGalleryId = null) use ($db) {
            $srcPath = __DIR__ . '/../uploads/' . $srcFileRow['stored_filename'];
            if (!file_exists($srcPath)) return false;

            $newViewToken = bin2hex(random_bytes(16));
            
            // DB beszúrás ID szerzéshez
            $ins = $db->prepare("INSERT INTO files (user_id, gallery_id, view_token, stored_filename, original_filename, file_size, mime_type, upload_ip) VALUES (:uid, :gid, :token, :stored, :orig, :size, :mime, 'SYSTEM_COPY')");
            $ins->execute([
                ':uid' => $newOwnerId,
                ':gid' => $newGalleryId,
                ':token' => $newViewToken,
                ':stored' => 'temp',
                ':orig' => $srcFileRow['original_filename'],
                ':size' => $srcFileRow['file_size'],
                ':mime' => $srcFileRow['mime_type']
            ]);
            $newId = $db->lastInsertId();
            
            // Fizikai másolás
            $newStoredName = (string)$newId;
            if (copy($srcPath, __DIR__ . '/../uploads/' . $newStoredName)) {
                $db->prepare("UPDATE files SET stored_filename = ? WHERE id = ?")->execute([$newStoredName, $newId]);
                
                // Thumbnail másolás
                $srcThumb = __DIR__ . '/../thumbnails/' . $srcFileRow['view_token'] . '.webp';
                if (file_exists($srcThumb)) {
                    copy($srcThumb, __DIR__ . '/../thumbnails/' . $newViewToken . '.webp');
                }
                return true;
            }
            return false;
        };

        if ($entityType === 'tokens') {
            $stmt = $db->prepare("SELECT * FROM tokens WHERE id IN ($ph)");
            $stmt->execute($ids);
            $items = $stmt->fetchAll();
            
            $ins = $db->prepare("INSERT INTO tokens (user_id, token_type, token_value, name, description, is_active) VALUES (:uid, :type, :val, :name, :desc, 1)");
            
            foreach ($items as $item) {
                $newToken = bin2hex(random_bytes(16));
                $ins->execute([
                    ':uid' => $targetUserId,
                    ':type' => $item['token_type'],
                    ':val' => $newToken,
                    ':name' => $item['name'] . ' (Másolat)',
                    ':desc' => $item['description']
                ]);
                $count++;
            }
        }
        
        elseif ($entityType === 'files') {
            $stmt = $db->prepare("SELECT * FROM files WHERE id IN ($ph)");
            $stmt->execute($ids);
            $items = $stmt->fetchAll();
            
            foreach ($items as $item) {
                if ($copyFileInternal($item, $targetUserId)) {
                    $count++;
                }
            }
        }
        
        elseif ($entityType === 'galleries') {
             $stmt = $db->prepare("SELECT * FROM galleries WHERE id IN ($ph)");
             $stmt->execute($ids);
             $galleries = $stmt->fetchAll();
             
             $insGal = $db->prepare("INSERT INTO galleries (user_id, name, slug, description, visibility, view_token) VALUES (:uid, :name, :slug, :desc, :vis, :token)");
             
             foreach ($galleries as $g) {
                 // Új galéria létrehozása
                 $newSlug = $g['slug'] . '-' . uniqid();
                 $newToken = bin2hex(random_bytes(16));
                 
                 $insGal->execute([
                     ':uid' => $targetUserId,
                     ':name' => $g['name'] . ' (Másolat)',
                     ':slug' => $newSlug,
                     ':desc' => $g['description'],
                     ':vis' => $g['visibility'],
                     ':token' => $newToken
                 ]);
                 $newGalleryId = $db->lastInsertId();
                 $count++; // Galéria sikeresen másolva
                 
                 // Képek másolása ebbe a galériába
                 $filesInGalleryStmt = $db->prepare("SELECT * FROM files WHERE gallery_id = :gid");
                 $filesInGalleryStmt->execute([':gid' => $g['id']]);
                 $galleryFiles = $filesInGalleryStmt->fetchAll();
                 
                 foreach ($galleryFiles as $gf) {
                     $copyFileInternal($gf, $targetUserId, $newGalleryId);
                 }
             }
        }

        elseif ($entityType === 'categories') {
             $stmt = $db->prepare("SELECT * FROM token_categories WHERE id IN ($ph)");
             $stmt->execute($ids);
             $items = $stmt->fetchAll();
             
             $ins = $db->prepare("INSERT INTO token_categories (user_id, name) VALUES (:uid, :name)");
             
             foreach ($items as $item) {
                 // Ellenőrzés: Van-e már ilyen nevű kategóriája a célnak?
                 $check = $db->prepare("SELECT id FROM token_categories WHERE user_id = :uid AND name = :name");
                 $check->execute([':uid' => $targetUserId, ':name' => $item['name']]);
                 
                 if (!$check->fetch()) {
                     $ins->execute([':uid' => $targetUserId, ':name' => $item['name']]);
                     $count++;
                     
                     // Bónusz: Ha akarod, a tokeneket is átmásolhatnánk, amik ebben vannak...
                     // De az bonyolult, mert a tokenek ID-ja változik.
                     // Egyszerűbb, ha a User először átmásolja a kategóriákat, aztán a tokeneket.
                 }
             }
        }
    }

    $db->commit();
    $response['success'] = true;
    $response['message'] = "$count elem sikeresen feldolgozva ($operation).";

} catch (Exception $e) {
    $db->rollBack();
    $response['message'] = 'Hiba: ' . $e->getMessage();
}

echo json_encode($response);