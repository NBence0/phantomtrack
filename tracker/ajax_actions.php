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
            $stmt = $db->prepare("SELECT id, name, description, category_id, is_active FROM tokens WHERE id = :id AND user_id = :user_id");
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
        $categoryId = !empty($_POST['token_category_id']) ? (int)$_POST['token_category_id'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($tokenId > 0 && !empty($name)) {
            $stmt = $db->prepare(
                "UPDATE tokens SET name = :name, description = :description, category_id = :category_id, is_active = :is_active 
                 WHERE id = :id AND user_id = :user_id"
            );
            $success = $stmt->execute([
                ':name' => $name,
                ':description' => $description,
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
            $stmt = $db->prepare("SELECT id, username, email, is_admin FROM users WHERE id = :id"); // <-- JAVÍTVA
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $response['success'] = true;
                $response['user'] = $user;
            } else { $response['message'] = 'Felhasználó nem található.'; }
        } else { $response['message'] = 'Érvénytelen felhasználó ID.'; }
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

        if ($userId > 0 && !empty($username) && !empty($email)) {
            // Egyediség-ellenőrzés
            $checkStmt = $db->prepare("SELECT id FROM users WHERE (username = :username OR email = :email) AND id != :id");
            $checkStmt->execute([':username' => $username, ':email' => $email, ':id' => $userId]);
            if ($checkStmt->fetch()) {
                 $response['message'] = 'A megadott felhasználónév vagy email már foglalt.';
            } else {
                $sql = "UPDATE users SET username = :username, email = :email, is_admin = :is_admin";
                $params = [':username' => $username, ':email' => $email, ':is_admin' => $isAdmin, ':id' => $userId];
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
                } else { $response['message'] = 'Hiba a mentés során.'; }
            }
        } 
        else { 
            $response['message'] = 'Hiányzó vagy érvénytelen adatok.'; 
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


    default:
        // Ha az 'action' ismeretlen, hibát adunk vissza
        $response['message'] = 'Ismeretlen művelet lett megadva.';
        http_response_code(400); // Bad Request
        break;
}

// A JSON válasz elküldése
echo json_encode($response);
exit;