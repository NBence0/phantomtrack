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
$entityType = $_POST['entity_type'] ?? ''; // tokens, galleries, files, categories
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
            $stmt = $db->prepare("DELETE FROM tokens WHERE id IN ($ph)");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
        } 
        elseif ($entityType === 'galleries') {
            // Galériák törlésekor a benne lévő fájlokat is törölni kellene fizikailag
            $filesStmt = $db->prepare("SELECT id, stored_filename, view_token FROM files WHERE gallery_id IN ($ph)");
            $filesStmt->execute($ids);
            $filesToDelete = $filesStmt->fetchAll();
            
            foreach ($filesToDelete as $f) {
                @unlink(__DIR__ . '/../uploads/' . $f['stored_filename']);
                @unlink(__DIR__ . '/../thumbnails/' . $f['view_token'] . '.webp');
                $db->prepare("DELETE FROM files WHERE id = ?")->execute([$f['id']]);
            }
            
            $stmt = $db->prepare("DELETE FROM galleries WHERE id IN ($ph)");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
        }
        elseif ($entityType === 'files') {
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
        elseif ($entityType === 'categories') {
            $recursive = !empty($_POST['recursive']) && $_POST['recursive'] == '1';
            
            if ($recursive) {
                // Rekurzív törlés: MINDENT törlünk, ami ebben a kategóriában van
                
                // 1. Tokenek törlése
                $db->prepare("DELETE FROM tokens WHERE category_id IN ($ph)")->execute($ids);
                
                // 2. Fájlok törlése (fizikailag is!)
                $filesInCatStmt = $db->prepare("SELECT id, stored_filename, view_token FROM files WHERE category_id IN ($ph)");
                $filesInCatStmt->execute($ids);
                $catFiles = $filesInCatStmt->fetchAll();
                foreach ($catFiles as $cf) {
                    @unlink(__DIR__ . '/../uploads/' . $cf['stored_filename']);
                    @unlink(__DIR__ . '/../thumbnails/' . $cf['view_token'] . '.webp');
                }
                $db->prepare("DELETE FROM files WHERE category_id IN ($ph)")->execute($ids);
                
                // 3. Galériák törlése (és a bennük lévő fájlok, ha még maradtak)
                // A fenti fájl törlés csak azokat vitte, amik közvetlenül a kategóriához tartoztak.
                // Ha egy fájl galériához tartozik, és a galéria ehhez a kategóriához, azt is vinni kell?
                // Igen, ha a galériát töröljük.
                $galleriesInCatStmt = $db->prepare("SELECT id FROM galleries WHERE category_id IN ($ph)");
                $galleriesInCatStmt->execute($ids);
                $galIds = $galleriesInCatStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($galIds)) {
                    $gPh = implode(',', array_fill(0, count($galIds), '?'));
                    // Galéria fájljainak törlése (fizikai)
                    $galFilesStmt = $db->prepare("SELECT id, stored_filename, view_token FROM files WHERE gallery_id IN ($gPh)");
                    $galFilesStmt->execute($galIds);
                    $galFiles = $galFilesStmt->fetchAll();
                    foreach ($galFiles as $gf) {
                        @unlink(__DIR__ . '/../uploads/' . $gf['stored_filename']);
                        @unlink(__DIR__ . '/../thumbnails/' . $gf['view_token'] . '.webp');
                        $db->prepare("DELETE FROM files WHERE id = ?")->execute([$gf['id']]);
                    }
                    // Maguk a galériák
                    $db->prepare("DELETE FROM galleries WHERE id IN ($gPh)")->execute($galIds);
                }
            }
            
            // Végül maga a kategória
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
        
        if ($table) {
            $ph = str_repeat('?,', count($ids) - 1) . '?';
            $params = array_merge([$targetUserId], $ids);
            $stmt = $db->prepare("UPDATE $table SET user_id = ? WHERE id IN ($ph)");
            $stmt->execute($params);
            $count = $stmt->rowCount();
        }
        elseif ($entityType === 'categories') {
            // Kategória mozgatása
            $ph = str_repeat('?,', count($ids) - 1) . '?';
            $params = array_merge([$targetUserId], $ids);
            
            // 1. Magát a kategóriát átrakjuk
            $db->prepare("UPDATE token_categories SET user_id = ? WHERE id IN ($ph)")->execute($params);
            
            // 2. A benne lévő dolgokat is átrakjuk az új userhez!
            $db->prepare("UPDATE tokens SET user_id = ? WHERE category_id IN ($ph)")->execute($params);
            $db->prepare("UPDATE galleries SET user_id = ? WHERE category_id IN ($ph)")->execute($params);
            $db->prepare("UPDATE files SET user_id = ? WHERE category_id IN ($ph)")->execute($params);
            
            $count = count($ids);
        }
    }

    // --- 3. MÁSOLÁS (COPY) ---
    elseif ($operation === 'copy') {
        if (!$targetUserId) throw new Exception("Nincs célfelhasználó.");
        $ph = str_repeat('?,', count($ids) - 1) . '?';

        // Segédfüggvény fájl másoláshoz (lokális)
        $copyFileInternal = function($srcFileRow, $newOwnerId, $newGalleryId = null, $newCategoryId = null) use ($db) {
            $srcPath = __DIR__ . '/../uploads/' . $srcFileRow['stored_filename'];
            if (!file_exists($srcPath)) return false;

            $newViewToken = bin2hex(random_bytes(16));
            
            $ins = $db->prepare("INSERT INTO files (user_id, gallery_id, category_id, view_token, stored_filename, original_filename, file_size, mime_type, upload_ip) VALUES (:uid, :gid, :cid, :token, :stored, :orig, :size, :mime, 'SYSTEM_COPY')");
            $ins->execute([
                ':uid' => $newOwnerId,
                ':gid' => $newGalleryId,
                ':cid' => $newCategoryId,
                ':token' => $newViewToken,
                ':stored' => 'temp',
                ':orig' => $srcFileRow['original_filename'],
                ':size' => $srcFileRow['file_size'],
                ':mime' => $srcFileRow['mime_type']
            ]);
            $newId = $db->lastInsertId();
            
            $newStoredName = (string)$newId;
            if (copy($srcPath, __DIR__ . '/../uploads/' . $newStoredName)) {
                $db->prepare("UPDATE files SET stored_filename = ? WHERE id = ?")->execute([$newStoredName, $newId]);
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
                $ins->execute([
                    ':uid' => $targetUserId, ':type' => $item['token_type'], ':val' => bin2hex(random_bytes(16)),
                    ':name' => $item['name'] . ' (Másolat)', ':desc' => $item['description']
                ]);
                $count++;
            }
        }
        elseif ($entityType === 'files') {
            $stmt = $db->prepare("SELECT * FROM files WHERE id IN ($ph)");
            $stmt->execute($ids);
            $items = $stmt->fetchAll();
            foreach ($items as $item) {
                if ($copyFileInternal($item, $targetUserId)) $count++;
            }
        }
        elseif ($entityType === 'galleries') {
             $stmt = $db->prepare("SELECT * FROM galleries WHERE id IN ($ph)");
             $stmt->execute($ids);
             $galleries = $stmt->fetchAll();
             $insGal = $db->prepare("INSERT INTO galleries (user_id, name, slug, description, visibility, view_token) VALUES (:uid, :name, :slug, :desc, :vis, :token)");
             foreach ($galleries as $g) {
                 $insGal->execute([
                     ':uid' => $targetUserId, ':name' => $g['name'] . ' (Másolat)', ':slug' => $g['slug'] . '-' . uniqid(),
                     ':desc' => $g['description'], ':vis' => $g['visibility'], ':token' => bin2hex(random_bytes(16))
                 ]);
                 $newGalleryId = $db->lastInsertId();
                 $count++;
                 // Galéria fájljainak másolása
                 $filesInGalleryStmt = $db->prepare("SELECT * FROM files WHERE gallery_id = :gid");
                 $filesInGalleryStmt->execute([':gid' => $g['id']]);
                 $galleryFiles = $filesInGalleryStmt->fetchAll();
                 foreach ($galleryFiles as $gf) $copyFileInternal($gf, $targetUserId, $newGalleryId);
             }
        }
        elseif ($entityType === 'categories') {
             $stmt = $db->prepare("SELECT * FROM token_categories WHERE id IN ($ph)");
             $stmt->execute($ids);
             $items = $stmt->fetchAll();
             
             // Kategória beszúrás
             $insCat = $db->prepare("INSERT INTO token_categories (user_id, name) VALUES (:uid, :name)");
             
             foreach ($items as $cat) {
                 // 1. Új kategória létrehozása
                 $insCat->execute([':uid' => $targetUserId, ':name' => $cat['name'] . ' (Másolat)']);
                 $newCatId = $db->lastInsertId();
                 $count++;
                 
                 // 2. Tokenek másolása ebbe a kategóriába
                 $tokensInCat = $db->prepare("SELECT * FROM tokens WHERE category_id = :cid");
                 $tokensInCat->execute([':cid' => $cat['id']]);
                 $tokensToCopy = $tokensInCat->fetchAll();
                 
                 $insToken = $db->prepare("INSERT INTO tokens (user_id, category_id, token_type, token_value, name, description, is_active) VALUES (:uid, :cat_id, :type, :val, :name, :desc, 1)");
                 foreach ($tokensToCopy as $t) {
                     $insToken->execute([
                         ':uid' => $targetUserId, ':cat_id' => $newCatId, ':type' => $t['token_type'], ':val' => bin2hex(random_bytes(16)),
                         ':name' => $t['name'], ':desc' => $t['description']
                     ]);
                 }

                 // 3. Galériák másolása ebbe a kategóriába
                 $galsInCat = $db->prepare("SELECT * FROM galleries WHERE category_id = :cid");
                 $galsInCat->execute([':cid' => $cat['id']]);
                 $galsToCopy = $galsInCat->fetchAll();
                 
                 $insGal = $db->prepare("INSERT INTO galleries (user_id, category_id, name, slug, description, visibility, view_token) VALUES (:uid, :cat_id, :name, :slug, :desc, :vis, :token)");
                 foreach ($galsToCopy as $g) {
                     $insGal->execute([
                         ':uid' => $targetUserId, ':cat_id' => $newCatId, ':name' => $g['name'], 
                         ':slug' => $g['slug'] . '-' . uniqid(), ':desc' => $g['description'], 
                         ':vis' => $g['visibility'], ':token' => bin2hex(random_bytes(16))
                     ]);
                     $newGalId = $db->lastInsertId();
                     // Galéria fájljainak másolása
                     $filesInGal = $db->prepare("SELECT * FROM files WHERE gallery_id = :gid");
                     $filesInGal->execute([':gid' => $g['id']]);
                     foreach ($filesInGal->fetchAll() as $gf) $copyFileInternal($gf, $targetUserId, $newGalId);
                 }

                 // 4. Fájlok másolása ebbe a kategóriába (amik közvetlenül itt vannak, nem galériában)
                 $filesInCat = $db->prepare("SELECT * FROM files WHERE category_id = :cid AND gallery_id IS NULL");
                 $filesInCat->execute([':cid' => $cat['id']]);
                 foreach ($filesInCat->fetchAll() as $cf) {
                     $copyFileInternal($cf, $targetUserId, null, $newCatId);
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