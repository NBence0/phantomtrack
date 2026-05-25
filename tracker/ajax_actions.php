<?php
// Hely: tracker/ajax_actions.php

// Alapvető beállítások és biztonsági ellenőrzések
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Minden válasz JSON formátumú lesz
header('Content-Type: application/json');

// Csak bejelentkezett felhasználók használhatják
requireLogin();

// Alapértelmezett válasz-objektum
$response = ['success' => false, 'message' => 'Ismeretlen hiba vagy érvénytelen művelet.'];

// Ellenőrizzük, hogy POST kérés érkezett-e és van-e érvényes CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
    $response['message'] = 'Érvénytelen vagy jogosulatlan kérés.';
    http_response_code(403); // Forbidden
    echo json_encode($response);
    exit;
}

$action = $_POST['action'] ?? null;
$currentUserId = getCurrentUserId();
$db = getDB();

// A kérések szétválasztása az 'action' paraméter alapján
switch ($action) {
    
    case 'create_category_ajax':
        $name = trim($_POST['category_name'] ?? '');
        
        if (empty($name)) {
            $response['message'] = 'A kategória neve nem lehet üres.';
        } else {
            // Ellenőrizzük, hogy a felhasználónak létezik-e már ilyen nevű kategóriája
            $checkStmt = $db->prepare("SELECT id FROM token_categories WHERE user_id = :user_id AND name = :name");
            $checkStmt->execute([':user_id' => $currentUserId, ':name' => $name]);
            
            if ($checkStmt->fetch()) {
                 $response['message'] = 'Már létezik ilyen nevű kategóriád.';
            } else {
                // Ha nem létezik, hozzuk létre
                $stmt = $db->prepare("INSERT INTO token_categories (user_id, name) VALUES (:user_id, :name)");
                if ($stmt->execute([':user_id' => $currentUserId, ':name' => $name])) {
                    $response['success'] = true;
                    $response['message'] = 'Kategória sikeresen létrehozva.';
                    // Visszaküldjük az új kategória adatait a JS-nek, hogy frissíthesse a UI-t
                    $response['category'] = [
                        'id' => $db->lastInsertId(),
                        'name' => $name
                    ];
                } else {
                    $response['message'] = 'Adatbázis hiba történt a mentés során.';
                    http_response_code(500); // Internal Server Error
                }
            }
        }
        break;
        
    case 'get_token_details':
        $tokenId = (int)($_POST['token_id'] ?? 0);
        if ($tokenId > 0) {
            $stmt = $db->prepare("SELECT id, name, description, webhook_url, category_id, is_active FROM tokens WHERE id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $tokenId, ':user_id' => $currentUserId]);
            $token = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($token) {
                $response['success'] = true;
                $response['token'] = $token;
            } else {
                $response['message'] = 'Token nem található vagy nincs jogosultságod hozzá.';
            }
        } else {
            $response['message'] = 'Érvénytelen token ID.';
        }
        break;

    case 'update_token':
            $tokenId = (int)($_POST['token_id'] ?? 0);
            $name = trim($_POST['token_name'] ?? '');
            $description = trim($_POST['token_description'] ?? '');

            $webhookUrl = trim($_POST['webhook_url'] ?? '');
            if (empty($webhookUrl)) $webhookUrl = null;
        
            $categoryId = !empty($_POST['token_category_id']) ? (int)$_POST['token_category_id'] : null;
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($tokenId > 0 && !empty($name)) {
                $stmt = $db->prepare(
                    "UPDATE tokens SET name = :name, description = :description, webhook_url = :webhook_url, category_id = :category_id, is_active = :is_active 
                    WHERE id = :id AND user_id = :user_id"
                );
                $success = $stmt->execute([
                    ':name' => $name,
                    ':description' => $description,
                    ':webhook_url' => $webhookUrl,
                    ':category_id' => $categoryId,
                    ':is_active' => $isActive,
                    ':id' => $tokenId,
                    ':user_id' => $currentUserId
                ]);
            if ($success) {
                $response['success'] = true;
                $response['message'] = 'Token sikeresen frissítve.';
            } else {
                $response['message'] = 'Hiba a token frissítésekor.';
            }
        } else {
            $response['message'] = 'Hiányzó vagy érvénytelen adatok.';
        }
        break;
    case 'get_category_details':
        $categoryId = (int)($_POST['category_id'] ?? 0);
        if ($categoryId > 0) {
            $stmt = $db->prepare("SELECT id, name FROM token_categories WHERE id = :id AND user_id = :user_id");
            $stmt->execute([':id' => $categoryId, ':user_id' => $currentUserId]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($category) {
                $response['success'] = true;
                $response['category'] = $category;
            } else {
                $response['message'] = 'Kategória nem található vagy nincs jogosultságod hozzá.';
            }
        } else {
            $response['message'] = 'Érvénytelen kategória ID.';
        }
        break;

    case 'update_category':
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['category_name'] ?? '');

        if ($categoryId > 0 && !empty($name)) {
            // Ellenőrizzük, hogy az új név nem ütközik-e egy MÁSIK kategória nevével
            $checkStmt = $db->prepare("SELECT id FROM token_categories WHERE name = :name AND user_id = :user_id AND id != :id");
            $checkStmt->execute([':name' => $name, ':user_id' => $currentUserId, ':id' => $categoryId]);
            
            if ($checkStmt->fetch()) {
                $response['message'] = 'Már létezik ilyen nevű kategóriád.';
            } else {
                $stmt = $db->prepare("UPDATE token_categories SET name = :name WHERE id = :id AND user_id = :user_id");
                if ($stmt->execute([':name' => $name, ':id' => $categoryId, ':user_id' => $currentUserId])) {
                    $response['success'] = true;
                    $response['message'] = 'Kategória sikeresen frissítve.';
                } else {
                    $response['message'] = 'Hiba a kategória frissítésekor.';
                }
            }
        } else {
            $response['message'] = 'Hiányzó vagy érvénytelen adatok.';
        }
        break;
    case 'create_user_ajax':
        if (!isAdmin()) { // Biztonsági ellenőrzés, csak admin hozhasson létre usert
            $response['message'] = 'Nincs jogosultságod a művelethez.';
            break;
        }
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $is_admin = isset($_POST['is_admin']) ? 1 : 0; // <-- JAVÍTVA
        if(empty($username) || empty($email) || empty($password)){
             $response['message'] = 'Minden mező kitöltése kötelező.';
        } else {
            $checkStmt = $db->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
            $checkStmt->execute([':username' => $username, ':email' => $email]);
            if($checkStmt->fetch()){
                 $response['message'] = "Felhasználónév vagy email már foglalt.";
            } else {
                // A registerUser függvény az is_admin paramétert várja
                if (registerUser($username, $email, $password, $is_admin)) { // <-- JAVÍTVA
                     $response['success'] = true;
                     $response['message'] = "Felhasználó sikeresen létrehozva.";
                } else {
                     $response['message'] = "Hiba történt a felhasználó létrehozásakor.";
                }
            }
        }
        break;

    case 'get_user_details':
        if (!isAdmin()) {
            $response['message'] = 'Nincs jogosultságod a művelethez.';
            break;
        }
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            $stmt = $db->prepare("SELECT id, username, email, is_admin, google_id, facebook_id, github_id FROM users WHERE id = :id");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $response['success'] = true;
                $response['user'] = $user;
            } else {
                $response['message'] = 'Felhasználó nem található.';
            }
        } else {
            $response['message'] = 'Érvénytelen felhasználó ID.';
        }
        break;

    case 'update_user':
        if (!isAdmin()) {
            $response['message'] = 'Nincs jogosultságod a művelethez.';
            break;
        }
        $userId = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $newPassword = $_POST['password'] ?? '';
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
        
        // MÓDOSÍTVA: Social ID-k beolvasása (üres string -> NULL)
        $googleId = !empty($_POST['google_id']) ? trim($_POST['google_id']) : null;
        $facebookId = !empty($_POST['facebook_id']) ? trim($_POST['facebook_id']) : null;
        $githubId = !empty($_POST['github_id']) ? trim($_POST['github_id']) : null;

        if ($userId > 0 && !empty($username) && !empty($email)) {
            // Egyediség-ellenőrzés
            $checkStmt = $db->prepare("SELECT id FROM users WHERE (username = :username OR email = :email) AND id != :id");
            $checkStmt->execute([':username' => $username, ':email' => $email, ':id' => $userId]);
            
            if ($checkStmt->fetch()) {
                 $response['message'] = 'A megadott felhasználónév vagy email már foglalt.';
            } else {
                // MÓDOSÍTVA: SQL update kibővítve a social mezőkkel
                $sql = "UPDATE users SET username = :username, email = :email, is_admin = :is_admin, google_id = :google_id, facebook_id = :facebook_id, github_id = :github_id";
                $params = [
                    ':username' => $username, 
                    ':email' => $email, 
                    ':is_admin' => $isAdmin, 
                    ':google_id' => $googleId,
                    ':facebook_id' => $facebookId,
                    ':github_id' => $githubId,
                    ':id' => $userId
                ];
                
                if (!empty($newPassword)) {
                    $sql .= ", password_hash = :password_hash";
                    $params[':password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                }
                
                $sql .= " WHERE id = :id";
                $updateStmt = $db->prepare($sql);
                
                if ($updateStmt->execute($params)) {
                    $response['success'] = true;
                    $response['message'] = 'Felhasználó adatai frissítve.';
                    if ($userId == $currentUserId) { $_SESSION['username'] = $username; }
                } else {
                    $response['message'] = 'Hiba a mentés során.';
                }
            }
        } else { 
            $response['message'] = 'Hiányzó vagy érvénytelen adatok.'; 
        }
        break;

    case 'delete_user':
        if (!isAdmin()) {
            $response['message'] = 'Nincs jogosultságod a művelethez.';
            break;
        }

        $userIdToDelete = (int)($_POST['user_id_to_delete'] ?? 0);
        $tokenAction = $_POST['token_action'] ?? 'delete';
        $newUserId = ($_POST['new_user_id'] === 'none' || empty($_POST['new_user_id'])) ? null : (int)$_POST['new_user_id'];

        if ($userIdToDelete <= 0) {
            $response['message'] = 'Érvénytelen felhasználó ID.';
        } elseif ($userIdToDelete == $currentUserId) {
            $response['message'] = 'Saját fiókodat nem törölheted.';
        } elseif ($tokenAction === 'move' && ($newUserId === 0 || is_null($newUserId))) {
            $response['message'] = 'Válassz egy célfelhasználót az áthelyezéshez.';
        } else {
            $db->beginTransaction();
            try {
                if ($tokenAction === 'move') {
                    // Tokenek áthelyezése
                    $updateTokensStmt = $db->prepare("UPDATE tokens SET user_id = :new_user_id WHERE user_id = :old_user_id");
                    $updateTokensStmt->execute([':new_user_id' => $newUserId, ':old_user_id' => $userIdToDelete]);
                    
                    // Galériák áthelyezése (ha van)
                    $db->prepare("UPDATE galleries SET user_id = :new WHERE user_id = :old")->execute([':new' => $newUserId, ':old' => $userIdToDelete]);
                    
                    // Fájlok áthelyezése
                    $db->prepare("UPDATE files SET user_id = :new WHERE user_id = :old")->execute([':new' => $newUserId, ':old' => $userIdToDelete]);
                    
                    $msgPart = "Tokenek és adatok átruházva. ";
                } else {
                    // MINDEN TÖRLÉSE (Cascade gondoskodik a legtöbbről, de a fizikai fájlokat illene törölni)
                    // Itt most egyszerűsítünk, csak a DB rekordokat töröljük, a fájlok maradnak "árvák" (vagy a cron takarítja őket)
                    // A DB cascade beállításoktól függően a tokens, galleries, files törlődhet.
                    
                    // Biztonság kedvéért explicit törlés, ha nincs cascade
                    $db->prepare("DELETE FROM activity_logs WHERE token_id IN (SELECT id FROM tokens WHERE user_id = :uid)")->execute([':uid' => $userIdToDelete]);
                    $db->prepare("DELETE FROM tokens WHERE user_id = :uid")->execute([':uid' => $userIdToDelete]);
                    $db->prepare("DELETE FROM galleries WHERE user_id = :uid")->execute([':uid' => $userIdToDelete]);
                    // A fájlokat itt nem töröljük fizikailag a bonyolultság miatt, de a DB-ből eltűnnek
                    $db->prepare("DELETE FROM files WHERE user_id = :uid")->execute([':uid' => $userIdToDelete]);
                    
                    $msgPart = "Adatok törölve. ";
                }

                // Felhasználó törlése
                $deleteUserStmt = $db->prepare("DELETE FROM users WHERE id = :id");
                $deleteUserStmt->execute([':id' => $userIdToDelete]);

                $db->commit();
                $response['success'] = true;
                $response['message'] = $msgPart . 'Felhasználó sikeresen törölve.';
                
            } catch (Exception $e) {
                $db->rollBack();
                $response['message'] = 'Hiba történt: ' . $e->getMessage();
            }
        }
        break;

    case 'delete_category_ajax':
        $categoryId = (int)($_POST['category_id_to_delete'] ?? 0);
        $tokenAction = $_POST['token_action'] ?? 'delete';
        $moveToCategoryId = ($_POST['move_to_category_id'] === 'none' || empty($_POST['move_to_category_id'])) ? null : (int)$_POST['move_to_category_id'];
        
        // Ellenőrzések
        $catCheck = $db->prepare("SELECT id FROM token_categories WHERE id = :id AND user_id = :user_id");
        $catCheck->execute([':id' => $categoryId, ':user_id' => $currentUserId]);
        if (!$catCheck->fetch()) {
            $response['message'] = 'Kategória nem található vagy nincs jogosultságod törölni.';
            break; // Kilép a switch-ből
        }
        if ($tokenAction === 'move' && !is_null($moveToCategoryId) && $moveToCategoryId === 0) {
            $response['message'] = 'Érvénytelen célkategória lett kiválasztva.';
            break;
        }

        // Adatbázis-műveletek tranzakcióban
        $db->beginTransaction();
        try {
            if ($tokenAction === 'move') {
                $updateStmt = $db->prepare("UPDATE tokens SET category_id = :new_cat_id WHERE category_id = :old_cat_id AND user_id = :user_id");
                $updateStmt->execute([':new_cat_id' => $moveToCategoryId, ':old_cat_id' => $categoryId, ':user_id' => $currentUserId]);
            } else { // 'delete' action
                $getTokensStmt = $db->prepare("SELECT id FROM tokens WHERE category_id = :cat_id AND user_id = :user_id");
                $getTokensStmt->execute([':cat_id' => $categoryId, ':user_id' => $currentUserId]);
                $tokenIdsToDelete = $getTokensStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($tokenIdsToDelete)) {
                    $placeholders = implode(',', array_fill(0, count($tokenIdsToDelete), '?'));
                    $db->prepare("DELETE FROM activity_logs WHERE token_id IN ($placeholders)")->execute($tokenIdsToDelete);
                    $db->prepare("DELETE FROM tokens WHERE id IN ($placeholders)")->execute($tokenIdsToDelete);
                }
            }

            // Maga a kategória törlése
            $deleteCatStmt = $db->prepare("DELETE FROM token_categories WHERE id = :id AND user_id = :user_id");
            $deleteCatStmt->execute([':id' => $categoryId, ':user_id' => $currentUserId]);
            
            $db->commit();
            $response['success'] = true;
            $response['message'] = "A kategória és a kapcsolódó elemek sikeresen törölve.";

        } catch (Exception $e) {
            $db->rollBack();
            $response['message'] = "Hiba történt a törlés során: " . $e->getMessage();
            http_response_code(500);
        }
        break;


    case 'create_permanent_request':
        if (!isLoggedIn()) { $response['message'] = 'Nincs bejelentkezve.'; break; }
        
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            $response['message'] = 'A link neve nem lehet üres.';
            break;
        }

        $tokenValue = generateUniqueToken();
        $stmt = $db->prepare(
            "INSERT INTO tokens (user_id, token_type, token_value, name, is_active, max_uploads) 
             VALUES (:user_id, 'file_request_permanent', :token_value, :name, 1, NULL)"
        );
        if ($stmt->execute([
            ':user_id' => $currentUserId,
            ':token_value' => $tokenValue,
            ':name' => $name
        ])) {
            $response['success'] = true;
            $response['message'] = 'Állandó feltöltő link sikeresen létrehozva.';
        } else {
            $response['message'] = 'Adatbázis hiba történt a link létrehozásakor.';
            http_response_code(500);
        }
        break;

// tracker/ajax_actions.php

    case 'create_limited_request':
        if (!isLoggedIn()) { $response['message'] = 'Nincs bejelentkezve.'; break; }

        // 1. Változók definiálása a POST adatokból
        $name = trim($_POST['name'] ?? '');
        $maxUploads = (int)($_POST['max_uploads'] ?? 1);
        $expiryTime = !empty($_POST['expiry_time']) ? $_POST['expiry_time'] : null;

        // 2. Validáció a már definiált változókon
        if (empty($name)) {
            $response['message'] = 'A bekérő neve nem lehet üres.';
            break;
        }
        if ($maxUploads <= 0) {
            $response['message'] = 'A maximális feltöltések számának pozitívnak kell lennie.';
            break;
        }
        // Opcionális: szerver oldali validáció a dátumra
        if ($expiryTime !== null && (DateTime::createFromFormat('Y-m-d H:i', $expiryTime) === false || strtotime($expiryTime) < time())) {
            $response['message'] = 'Érvénytelen vagy múltbeli lejárati dátum.';
            break;
        }

        // 3. Adatbázis művelet
        $tokenValue = generateUniqueToken();
        $stmt = $db->prepare(
            "INSERT INTO tokens (user_id, token_type, token_value, name, is_active, max_uploads, expiry_time) 
             VALUES (:user_id, 'file_request_limited', :token_value, :name, 1, :max_uploads, :expiry_time)"
        );
        if ($stmt->execute([
            ':user_id' => $currentUserId,
            ':token_value' => $tokenValue,
            ':name' => $name,
            ':max_uploads' => $maxUploads,
            ':expiry_time' => $expiryTime
        ])) {
            $response['success'] = true;
            $response['message'] = 'Korlátozott fájlbekérő sikeresen létrehozva.';
        } else {
            $response['message'] = 'Adatbázis hiba történt a bekérő létrehozásakor.';
            http_response_code(500);
        }
        break;

    case 'delete_file_request': // Ez mindkét típusú link törlésére jó lesz
        if (!isLoggedIn()) { $response['message'] = 'Nincs bejelentkezve.'; break; }

        $tokenId = (int)($_POST['token_id'] ?? 0);
        if ($tokenId <= 0) {
            $response['message'] = 'Érvénytelen token ID.';
            break;
        }

        // Fontos a jogosultság-ellenőrzés!
        $stmt = $db->prepare("DELETE FROM tokens WHERE id = :id AND user_id = :user_id AND token_type LIKE 'file_request_%'");
        if ($stmt->execute([':id' => $tokenId, ':user_id' => $currentUserId])) {
            if ($stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = 'A bekérő link sikeresen törölve.';
            } else {
                $response['message'] = 'A link nem található, vagy nincs jogosultságod a törléséhez.';
            }
        } else {
            $response['message'] = 'Adatbázis hiba történt a törlés során.';
            http_response_code(500);
        }
        break;

    case 'get_limited_request_details':
        if (!isLoggedIn()) { $response['message'] = 'Nincs bejelentkezve.'; break; }
        $tokenId = (int)($_POST['token_id'] ?? 0);
        
        if ($tokenId <= 0) { $response['message'] = 'Érvénytelen ID.'; break; }
        
        $stmt = $db->prepare("SELECT id, name, max_uploads, expiry_time FROM tokens WHERE id = :id AND user_id = :user_id AND token_type = 'file_request_limited'");
        $stmt->execute([':id' => $tokenId, ':user_id' => $currentUserId]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($token) {
            $response['success'] = true;
            // A dátumot a flatpickr számára megfelelő formátumban adjuk vissza
            if ($token['expiry_time']) {
                $token['expiry_time'] = (new DateTime($token['expiry_time']))->format('Y-m-d H:i');
            }
            $response['token'] = $token;
        } else {
            $response['message'] = 'Bekérő nem található vagy nincs jogosultságod hozzá.';
        }
        break;

    case 'update_limited_request':
        if (!isLoggedIn()) { $response['message'] = 'Nincs bejelentkezve.'; break; }

        $tokenId = (int)($_POST['token_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $maxUploads = (int)($_POST['max_uploads'] ?? 1);
        $expiryTime = !empty($_POST['expiry_time']) ? $_POST['expiry_time'] : null;

        if ($tokenId <= 0 || empty($name) || $maxUploads <= 0) {
            $response['message'] = 'Hiányzó vagy érvénytelen adatok.';
            break;
        }

        if ($expiryTime !== null && DateTime::createFromFormat('Y-m-d H:i', $expiryTime) === false) {
            $response['message'] = 'Érvénytelen dátumformátum.';
            break;
        }

        $stmt = $db->prepare(
            "UPDATE tokens SET name = :name, max_uploads = :max_uploads, expiry_time = :expiry_time 
             WHERE id = :id AND user_id = :user_id AND token_type = 'file_request_limited'"
        );
        if ($stmt->execute([
            ':name' => $name,
            ':max_uploads' => $maxUploads,
            ':expiry_time' => $expiryTime,
            ':id' => $tokenId,
            ':user_id' => $currentUserId
        ])) {
            $response['success'] = true;
            $response['message'] = 'A bekérő adatai sikeresen frissítve.';
        } else {
            $response['message'] = 'Adatbázis hiba történt a frissítés során.';
            http_response_code(500);
        }
        break;

    case 'delete_file':
        $fileId = (int)($_POST['file_id'] ?? 0);

        if ($fileId <= 0) {
            $response['message'] = 'Érvénytelen fájl azonosító.';
            break;
        }

        // 1. Jogosultság és fájl adatainak lekérdezése
        $stmt = $db->prepare("SELECT id, stored_filename FROM files WHERE id = :id AND user_id = :user_id");
        $stmt->execute([':id' => $fileId, ':user_id' => $currentUserId]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$file) {
            $response['message'] = 'A fájl nem található, vagy nincs jogosultságod a törléséhez.';
            http_response_code(403); // Forbidden
            break;
        }

        // 2. Törlés végrehajtása tranzakcióban
        $db->beginTransaction();
        try {
            // A fizikai fájl törlése
            $filePath = __DIR__ . '/../uploads/' . $file['stored_filename'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // Thumbnail törlése, ha létezik (jövőbeli funkció)
            $thumbnailPath = __DIR__ . '/../thumbnails/' . $file['id'] . '.jpg';
            if (file_exists($thumbnailPath)) {
                unlink($thumbnailPath);
            }

            // Adatbázis bejegyzés törlése
            // Az ON DELETE CASCADE miatt a kapcsolódó activity_logs bejegyzések is törlődni fognak.
            $deleteStmt = $db->prepare("DELETE FROM files WHERE id = :id");
            $deleteStmt->execute([':id' => $fileId]);

            $db->commit();
            $response['success'] = true;
            $response['message'] = 'A fájl sikeresen törölve.';
            
        } catch (Exception $e) {
            $db->rollBack();
            $response['message'] = 'Hiba történt a törlés során: ' . $e->getMessage();
            http_response_code(500); // Internal Server Error
            error_log("File deletion error for file ID {$fileId}: " . $e->getMessage());
        }
        break;

    case 'file_activity_trends':
        // A felhasználó összes fájljára vonatkozó trendek (feltöltés, letöltés, megtekintés)
        $days = (int)($_GET['days'] ?? 30);
        $stmt = $db->prepare("
            SELECT 
                DATE(al.timestamp) as activity_date,
                SUM(CASE WHEN al.log_type = 'file_upload' THEN 1 ELSE 0 END) as upload_count,
                SUM(CASE WHEN al.log_type = 'file_view' THEN 1 ELSE 0 END) as view_count,
                SUM(CASE WHEN al.log_type = 'file_download' THEN 1 ELSE 0 END) as download_count
            FROM activity_logs al
            JOIN files f ON al.file_id = f.id
            WHERE f.user_id = :user_id 
            AND al.file_id IS NOT NULL
            AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY activity_date
            ORDER BY activity_date ASC
        ");
        $stmt->execute([':user_id' => $currentUserId, ':days' => $days]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Adatok előkészítése
        $labels = []; $uploads = []; $views = []; $downloads = [];
        $dataMap = [];
        foreach($data as $row) {
            $dataMap[$row['activity_date']] = [
                'u' => (int)$row['upload_count'],
                'v' => (int)$row['view_count'],
                'd' => (int)$row['download_count']
            ];
        }
        $period = new DatePeriod(new DateTime("-{$days} days"), new DateInterval('P1D'), new DateTime('+1 day'));
        foreach ($period as $dt) {
            $dateStr = $dt->format('Y-m-d');
            $labels[] = $dateStr;
            $uploads[] = $dataMap[$dateStr]['u'] ?? 0;
            $views[] = $dataMap[$dateStr]['v'] ?? 0;
            $downloads[] = $dataMap[$dateStr]['d'] ?? 0;
        }
        $output = ['labels' => $labels, 'uploads' => $uploads, 'views' => $views, 'downloads' => $downloads];
        break;

    case 'file_type_distribution':
        // Segédfüggvény a MIME típusok "lefordítására"
        function mapMimeToLabel($mime) {
            // Gyakori Office és archív formátumok
            if (strpos($mime, 'wordprocessingml') !== false) return 'Word Dokumentum';
            if (strpos($mime, 'spreadsheetml') !== false) return 'Excel Táblázat';
            if (strpos($mime, 'presentationml') !== false) return 'PowerPoint Prezentáció';
            if (strpos($mime, 'pdf') !== false) return 'PDF Dokumentum';
            if (strpos($mime, 'zip') !== false) return 'ZIP Archívum';
            if (strpos($mime, 'rar') !== false) return 'RAR Archívum';
            if (strpos($mime, '7z') !== false) return '7z Archívum';
            if (strpos($mime, 'heic') !== false) return 'HEIC Kép';

            // Fő kategóriák
            if (strpos($mime, 'image/') === 0) return 'Képfájl (Egyéb)';
            if (strpos($mime, 'video/') === 0) return 'Videó';
            if (strpos($mime, 'audio/') === 0) return 'Hangfájl';
            if (strpos($mime, 'text/') === 0) return 'Szöveges Fájl';
            if (strpos($mime, 'application/octet-stream') === 0) return 'Ismeretlen Bináris';
            if (strpos($mime, 'application/') === 0) return 'Alkalmazás/Dokumentum';
            
            return 'Egyéb';
        }

        $stmt = $db->prepare("SELECT mime_type FROM files WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $currentUserId]);
        $mimeTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $labelCounts = [];
        foreach ($mimeTypes as $mime) {
            $label = mapMimeToLabel($mime);
            if (!isset($labelCounts[$label])) {
                $labelCounts[$label] = 0;
            }
            $labelCounts[$label]++;
        }
        
        // Rendezés a darabszám szerint csökkenő sorrendben
        arsort($labelCounts);
        
        $output = [
            'labels' => array_keys($labelCounts),
            'data' => array_values($labelCounts)
        ];
        break;

    case 'hourly_activity_overall_files': // ÚJ BLOKK
        $stmt = $db->prepare("
            SELECT HOUR(al.timestamp) as hour, COUNT(al.id) as total_count
            FROM activity_logs al
            JOIN files f ON al.file_id = f.id
            WHERE f.user_id = :user_id AND al.file_id IS NOT NULL
            GROUP BY hour
            ORDER BY hour ASC
        ");
        $stmt->execute([':user_id' => $currentUserId]);
        $data = $stmt->fetchAll();
        
        $hourlyData = array_fill(0, 24, 0);
        foreach ($data as $row) {
            $hourlyData[(int)$row['hour']] = (int)$row['total_count'];
        }
        
        $output = [
            'labels' => array_map(function($h){ return str_pad($h, 2, '0', STR_PAD_LEFT) . ':00'; }, range(0,23)),
            'data' => array_values($hourlyData)
        ];
        break;
    // --- GALÉRIA KEZELÉS ---
    case 'create_gallery':
        $name = trim($_POST['name'] ?? '');
        $slug = createSlug($name);
        // Egyediség ellenőrzése (ha már van ilyen slugja a usernek, teszünk mögé számot)
        $originalSlug = $slug;
        $categoryId = (!empty($_POST['category_id']) && $_POST['category_id'] !== 'null') ? (int)$_POST['category_id'] : null;
        $counter = 1;
        while(true) {
            $check = $db->prepare("SELECT id FROM galleries WHERE user_id = :uid AND slug = :slug");
            $check->execute([':uid' => $currentUserId, ':slug' => $slug]);
            if(!$check->fetch()) break; // Nincs ilyen, mehet
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        $description = trim($_POST['description'] ?? '');
        $visibility = $_POST['visibility'] ?? 'private';
        $password = $_POST['password'] ?? '';
        
        if (empty($name)) {
            $response['message'] = 'A galéria neve kötelező.';
            break;
        }
        
        $passwordHash = null;
        if ($visibility === 'password') {
            if (empty($password)) {
                $response['message'] = 'Jelszavas védelemhez kötelező jelszót megadni.';
                break;
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }
        
        $viewToken = bin2hex(random_bytes(16)); // Egyedi linkhez
        
        $facefinderEnabled = isset($_POST['facefinder_enabled']) ? 1 : 0;
        
        $stmt = $db->prepare("INSERT INTO galleries (user_id, name, slug, description, visibility, password_hash, view_token, category_id, facefinder_enabled) VALUES (:uid, :name, :slug, :desc, :vis, :pass, :token, :cat_id, :facefinder_enabled)");
        if ($stmt->execute([
            ':uid' => $currentUserId,
            ':name' => $name,
            ':slug' => $slug,
            ':desc' => $description,
            ':vis' => $visibility,
            ':pass' => $passwordHash,
            ':token' => $viewToken,
            ':cat_id' => $categoryId,
            ':facefinder_enabled' => $facefinderEnabled
        ])) {
            $response['success'] = true;
            $response['message'] = 'Galéria sikeresen létrehozva.';
        } else {
            $response['message'] = 'Adatbázis hiba.';
            http_response_code(500);
        }
        break;

    case 'delete_gallery':
        $galleryId = (int)($_POST['gallery_id'] ?? 0);
        
        // Ellenőrzés: Saját galéria-e?
        $check = $db->prepare("SELECT id FROM galleries WHERE id = :id AND user_id = :uid");
        $check->execute([':id' => $galleryId, ':uid' => $currentUserId]);
        if (!$check->fetch()) {
            $response['message'] = 'Nincs jogosultságod vagy nem létezik.';
            break;
        }
        
        // 1. Fájlok fizikai törlése (opcionális, de ajánlott a szemetelés ellen)
        $filesStmt = $db->prepare("SELECT stored_filename, id FROM files WHERE gallery_id = :gid");
        $filesStmt->execute([':gid' => $galleryId]);
        $files = $filesStmt->fetchAll();
        
        foreach($files as $f) {
            $path = __DIR__ . '/../uploads/' . $f['stored_filename'];
            $thumb = __DIR__ . '/../thumbnails/' . $f['id'] . '.webp';
            if(file_exists($path)) @unlink($path);
            if(file_exists($thumb)) @unlink($thumb);
        }
        
        // 2. DB Törlés (Cascade miatt viszi a files, comments, logs rekordokat is)
        $del = $db->prepare("DELETE FROM galleries WHERE id = :id");
        if ($del->execute([':id' => $galleryId])) {
            $response['success'] = true;
            $response['message'] = 'Galéria törölve.';
        } else {
            $response['message'] = 'Hiba a törlésnél.';
        }
        break;
    // --- GALÉRIA LOGOLÁS (A Kistesó szelleme) ---
    case 'log_gallery_event':
        // Ez publikus is lehet, nem kell requireLogin()
        // DE a CSRF check az elején megfogja, ha nincs session.
        // A gallery_view.php indít sessiont, generál CSRF tokent, tehát működni fog.
        
        $galleryId = (int)($_POST['gallery_id'] ?? 0);
        $eventType = $_POST['event_type'] ?? 'unknown';
        $metaData = $_POST['meta_data'] ?? '{}'; // JSON string
        
        // Logolás a központi activity_logs táblába
        // logActivity függvényünk módosítása nélkül is működhetne, ha közvetlen INSERT-et írunk ide,
        // mert a logActivity nem fogad meta_data-t paraméterként jelenleg.
        // Így inkább közvetlen SQL-t használunk a meta_data mentéséhez.
        
        $ip = getIpAddress();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $db->prepare("INSERT INTO activity_logs (log_type, gallery_id, ip_address, user_agent, meta_data) VALUES (:type, :gid, :ip, :ua, :meta)");
        $stmt->execute([
            ':type' => 'gallery_' . $eventType, // pl. gallery_image_view
            ':gid' => $galleryId,
            ':ip' => $ip,
            ':ua' => $ua,
            ':meta' => $metaData
        ]);
        
        $response['success'] = true;
        break;
    // --- GALÉRIA LEKÉRDEZÉS (Szerkesztéshez) ---
    case 'get_gallery_details':
        $gid = (int)$_POST['gallery_id'];
        $stmt = $db->prepare("SELECT id, name, slug, description, visibility, category_id, facefinder_enabled FROM galleries WHERE id = :id AND user_id = :uid");
        $stmt->execute([':id' => $gid, ':uid' => $currentUserId]);
        $gal = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($gal) {
            $response['success'] = true;
            $response['gallery'] = $gal;
        } else {
            $response['message'] = 'Nem található.';
        }
        break;

    // --- GALÉRIA FRISSÍTÉS ---
    case 'update_gallery':
        $gid = (int)$_POST['gallery_id'];
        $name = trim($_POST['name']);
        $desc = trim($_POST['description']);
        $vis = $_POST['visibility'];
        $pass = $_POST['password'];

        $categoryId = (!empty($_POST['category_id']) && $_POST['category_id'] !== 'null') ? (int)$_POST['category_id'] : null;
        // Slug generálás
        $slug = createSlug($name);
        // Egyediség ellenőrzése (ha már van ilyen slugja a usernek, teszünk mögé számot)
        $originalSlug = $slug;
        $counter = 1;
        while(true) {
            $check = $db->prepare("SELECT id FROM galleries WHERE user_id = :uid AND slug = :slug AND id != :id");
            $check->execute([':uid' => $currentUserId, ':slug' => $slug, ':id' => $gid]);
            if(!$check->fetch()) break; // Nincs ilyen, mehet
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        
        // Jogosultság ellenőrzés
        $check = $db->prepare("SELECT id FROM galleries WHERE id = :id AND user_id = :uid");
        $check->execute([':id' => $gid, ':uid' => $currentUserId]);
        if (!$check->fetch()) {
            $response['message'] = 'Nincs jogosultságod.';
            break;
        }
        $facefinderEnabled = isset($_POST['facefinder_enabled']) ? 1 : 0;
        
        $sql = "UPDATE galleries SET name = :name, slug = :slug, description = :desc, visibility = :vis, category_id = :cat_id, facefinder_enabled = :facefinder_enabled";
        $params = [':name' => $name, ':desc' => $desc, ':vis' => $vis, ':id' => $gid, ':slug' => $slug, ':cat_id' => $categoryId, ':facefinder_enabled' => $facefinderEnabled];
        
        // Jelszó kezelés
        if ($vis === 'password' && !empty($pass)) {
            $sql .= ", password_hash = :pass";
            $params[':pass'] = password_hash($pass, PASSWORD_DEFAULT);
        }
        
        $sql .= " WHERE id = :id";
        
        if ($db->prepare($sql)->execute($params)) {
            $response['success'] = true;
            $response['message'] = 'Galéria frissítve.';
        } else {
            $response['message'] = 'Hiba a mentésnél.';
        }
        break;

    // --- GALÉRIA KOMMENTEK ---
    case 'submit_gallery_comment':
        if (!isLoggedIn()) {
            $response['message'] = 'A hozzászóláshoz be kell jelentkezned.';
            break;
        }
        
        $galleryId = (int)($_POST['gallery_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        $currentUserId = getCurrentUserId();
        
        if ($galleryId <= 0 || empty($message)) {
            $response['message'] = 'Hiányzó adatok.';
            break;
        }
        
        // 1. Usernév lekérése
        $userStmt = $db->prepare("SELECT username FROM users WHERE id = :uid");
        $userStmt->execute([':uid' => $currentUserId]);
        $username = $userStmt->fetchColumn() ?: 'Ismeretlen'; // Fallback
        
        // 2. Beszúrás
        $stmt = $db->prepare("INSERT INTO gallery_comments (gallery_id, user_id, user_name, message, created_at) VALUES (:gid, :uid, :uname, :msg, NOW())");
        
        if ($stmt->execute([
            ':gid' => $galleryId,
            ':uid' => $currentUserId,
            ':uname' => $username,
            ':msg' => $message
        ])) {
            // 3. VÁLASZ FELÜLÍRÁSA (Ez hiányzott!)
            $response['success'] = true;
            $response['message'] = 'Sikeres küldés!'; 
            
            // FONTOS: Vissza kell küldeni az adatokat a JS-nek!
            $response['comment'] = [
                'username' => htmlspecialchars($username),
                'message' => nl2br(htmlspecialchars($message)),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } else {
            $response['message'] = 'Adatbázis hiba.';
        }
        break;
    case 'delete_gallery_comment':
        if (!isLoggedIn()) {
            $response['message'] = 'Nincs jogosultságod.';
            break;
        }
        
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $galleryId = (int)($_POST['gallery_id'] ?? 0);
        
        // 1. Jogosultság ellenőrzés: A galéria tulajdonosa vagy?
        $checkStmt = $db->prepare("SELECT id FROM galleries WHERE id = :gid AND user_id = :uid");
        $checkStmt->execute([':gid' => $galleryId, ':uid' => $currentUserId]);
        $isOwner = $checkStmt->fetch();
        
        // VAGY maga a komment írója vagy? (Opcionális, de hasznos)
        // $isAuthor = ... 
        
        if ($isOwner || isAdmin()) {
            $del = $db->prepare("DELETE FROM gallery_comments WHERE id = :cid AND gallery_id = :gid");
            if ($del->execute([':cid' => $commentId, ':gid' => $galleryId])) {
                $response['success'] = true;
                $response['message'] = 'Komment törölve.';
            } else {
                $response['message'] = 'Hiba a törlésnél.';
            }
        } else {
            $response['message'] = 'Nincs jogosultságod törölni ezt a kommentet.';
        }
        break;
    // --- FÁJL GALÉRIA HOZZÁRENDELÉS ---
    case 'assign_file_properties':

        $fileId = (int)$_POST['file_id'];
        $galleryId = $_POST['gallery_id'] === 'null' ? null : (int)$_POST['gallery_id'];
        $categoryId = $_POST['category_id'] === 'null' ? null : (int)$_POST['category_id'];
        
        // Jogosultság ellenőrzés (Fájl)
        $checkFile = $db->prepare("SELECT id FROM files WHERE id = :fid AND user_id = :uid");
        $checkFile->execute([':fid' => $fileId, ':uid' => $currentUserId]);
        if (!$checkFile->fetch()) {
            $response['message'] = 'Fájl nem található vagy nincs jogod.';
            break;
        }
        
        // Érték előkészítése
        $targetGalleryId = null;
        if ($galleryId !== 'null' && is_numeric($galleryId)) {
            // Jogosultság ellenőrzés (Galéria)
            $checkGal = $db->prepare("SELECT id FROM galleries WHERE id = :gid AND user_id = :uid");
            $checkGal->execute([':gid' => $galleryId, ':uid' => $currentUserId]);
            if ($checkGal->fetch()) {
                $targetGalleryId = $galleryId;
            } else {
                $response['message'] = 'Érvénytelen galéria.';
                break;
            }
        }
        
        $upd = $db->prepare("UPDATE files SET gallery_id = :gid, category_id = :cid WHERE id = :fid AND user_id = :uid");
        if ($upd->execute([':gid' => $galleryId, ':cid' => $categoryId, ':fid' => $fileId, ':uid' => $currentUserId])) {
            $response['success'] = true;
            $response['message'] = 'Fájl tulajdonságai frissítve.';
        }
        break;
    default:
        // Ha az 'action' ismeretlen, hibát adunk vissza
        $response['message'] = 'Ismeretlen művelet lett megadva.';
        http_response_code(400); // Bad Request
        break;
}

// A JSON válasz elküldése
echo json_encode($response);
exit;