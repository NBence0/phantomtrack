<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
// --- EREDETI FÜGGVÉNYEK (Kicsit módosítva a NULL jelszóhoz) ---

function registerUser($username, $email, $password, $is_admin = false) {
    $db = getDB();
    // Ha van jelszó, hasheljük, ha nincs (Google), akkor NULL
    $passwordHash = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
    
    try {
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, is_admin, created_at) VALUES (:username, :email, :password_hash, :is_admin, NOW())");
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        // Fontos: Ha null, akkor PDO::PARAM_NULL-ként kezelődik automatikusan vagy explicit megadva
        $stmt->bindParam(':password_hash', $passwordHash);
        $stmt->bindParam(':is_admin', $is_admin, PDO::PARAM_INT);
        $stmt->execute();
        return $db->lastInsertId();
    } catch (PDOException $e) {
        error_log("User registration error: " . $e->getMessage());
        return false;
    }
}

function attemptLogin($usernameOrEmail, $password) {
    $db = getDB();
    // Csak olyan usert engedünk be jelszóval, akinek VAN jelszava (password_hash IS NOT NULL)
    $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE (username = :login OR email = :login) AND password_hash IS NOT NULL LIMIT 1");
    $stmt->bindParam(':login', $usernameOrEmail);
    $stmt->execute();
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Session beállítás
        setSession($user['id'], $user['username']);
        return true;
    }
    return false;
}

// --- ÚJ GOOGLE LOGIKA ---

function loginOrRegisterWithGoogle($googleInfo) {
    $db = getDB();
    $email = $googleInfo['email'];
    $googleId = $googleInfo['id'];
    $name = $googleInfo['name'] ?? explode('@', $email)[0];

    // 1. ESET: Van-e már user ezzel a konkrét Google ID-val? (Ez a legbiztonságosabb)
    $stmt = $db->prepare("SELECT id, username FROM users WHERE google_id = :google_id LIMIT 1");
    $stmt->execute([':google_id' => $googleId]);
    $user = $stmt->fetch();

    if ($user) {
        // Már össze van kötve -> Beléptetés
        setSession($user['id'], $user['username']);
        return true;
    }

    // 2. ESET: Nincs Google ID, de van-e ilyen EMAIL cím?
    $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        // --- BIZTONSÁGI JAVÍTÁS ITT ---
        
        // Ha a felhasználónak van jelszava, NEM kötjük össze automatikusan!
        // Ez védi ki, hogy valaki beregisztrálja a te emailedet, te meg belépsz rá Google-lel.
        if (!empty($existingUser['password_hash'])) {
            // Nem engedjük a Google belépést, amíg nem igazolta magát jelszóval
            $_SESSION['flash_message'] = "Ez az e-mail cím már regisztrálva van jelszóval. Kérjük, jelentkezz be a felhasználónév/jelszó párossal, majd a Beállításokban kötheted össze a Google fiókodat.";
            $_SESSION['flash_message_type'] = "error";
            return false; 
        }

        // Ha nincs jelszava (valami hiba miatt, vagy korábbi Google regisztrációból maradt jelszó nélkül),
        // akkor kivételesen összeköthetjük.
        $updateStmt = $db->prepare("UPDATE users SET google_id = :google_id WHERE id = :id");
        $updateStmt->execute([':google_id' => $googleId, ':id' => $existingUser['id']]);
        
        setSession($existingUser['id'], $existingUser['username']);
        return true;
    }

    // 3. ESET: Teljesen új felhasználó -> Regisztráció
    // Itt kezeljük a felhasználónév ütközést is (az 'almaalma' problémát)
    
    // Kezdő név generálása
    $baseUsername = createSlug($name);
    if (empty($baseUsername)) $baseUsername = 'user';
    
    $finalUsername = $baseUsername;
    $counter = 1;

    // Ciklus: addig próbálgatjuk, amíg nem találunk szabad felhasználónevet
    // Így ha van 'almaalma', akkor a Google user 'almaalma1' lesz.
    while (true) {
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
        $checkStmt->execute([':username' => $finalUsername]);
        if ($checkStmt->fetchColumn() == 0) {
            break; // Találtunk szabad nevet
        }
        $finalUsername = $baseUsername . $counter;
        $counter++;
    }

    // Beszúrás
    try {
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, is_admin, google_id, created_at) VALUES (:username, :email, NULL, 0, :google_id, NOW())");
        $stmt->execute([
            ':username' => $finalUsername,
            ':email' => $email,
            ':google_id' => $googleId
        ]);
        $newUserId = $db->lastInsertId();

        setSession($newUserId, $finalUsername);
        return true;

    } catch (PDOException $e) {
        error_log("Google registration error: " . $e->getMessage());
        return false;
    }
}



function loginOrRegisterWithFacebook($fbInfo) {
    $db = getDB();
    $email = $fbInfo['email'];
    $fbId = $fbInfo['id'];
    $name = $fbInfo['name'];

    // 1. ESET: Van-e már user ezzel a Facebook ID-val?
    $stmt = $db->prepare("SELECT id, username FROM users WHERE facebook_id = :fb_id LIMIT 1");
    $stmt->execute([':fb_id' => $fbId]);
    $user = $stmt->fetch();

    if ($user) {
        setSession($user['id'], $user['username']);
        return true;
    }

    // 2. ESET: Nincs Facebook ID, de van-e ilyen EMAIL cím?
    $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        // BIZTONSÁGI ELLENŐRZÉS: Ha van jelszava, TILOS automatikusan beléptetni!
        if (!empty($existingUser['password_hash'])) {
            $_SESSION['flash_message'] = "Ez az e-mail cím már regisztrálva van jelszóval. Kérjük, lépj be hagyományosan!";
            $_SESSION['flash_message_type'] = "error";
            return false;
        }

        // Ha nincs jelszava (pl. Google user volt), összekötjük
        $updateStmt = $db->prepare("UPDATE users SET facebook_id = :fb_id WHERE id = :id");
        $updateStmt->execute([':fb_id' => $fbId, ':id' => $existingUser['id']]);
        
        setSession($existingUser['id'], $existingUser['username']);
        return true;
    }

    // 3. ESET: Teljesen új regisztráció
    $baseUsername = createSlug($name);
    if (empty($baseUsername)) $baseUsername = 'user';
    $finalUsername = $baseUsername;
    $counter = 1;

    while (true) {
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
        $checkStmt->execute([':username' => $finalUsername]);
        if ($checkStmt->fetchColumn() == 0) break;
        $finalUsername = $baseUsername . $counter;
        $counter++;
    }

    try {
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, is_admin, facebook_id, created_at) VALUES (:username, :email, NULL, 0, :fb_id, NOW())");
        $stmt->execute([
            ':username' => $finalUsername,
            ':email' => $email,
            ':fb_id' => $fbId
        ]);
        setSession($db->lastInsertId(), $finalUsername);
        return true;
    } catch (PDOException $e) {
        error_log("Facebook registration error: " . $e->getMessage());
        return false;
    }
}

function loginOrRegisterWithGithub($ghInfo) {
    $db = getDB();
    $email = $ghInfo['email'];
    $ghId = $ghInfo['id'];
    $name = $ghInfo['name'] ?? $ghInfo['login']; // GitHubnál a 'login' a username, a 'name' opcionális

    // 1. ESET: Van-e már user ezzel a GitHub ID-val?
    $stmt = $db->prepare("SELECT id, username FROM users WHERE github_id = :gh_id LIMIT 1");
    $stmt->execute([':gh_id' => $ghId]);
    $user = $stmt->fetch();

    if ($user) {
        setSession($user['id'], $user['username']);
        return true;
    }

    // 2. ESET: Nincs GitHub ID, de van-e ilyen EMAIL cím?
    // GitHubnál előfordulhat, hogy az email privát, ezért ezt csak akkor tudjuk, ha kaptunk emailt.
    if (!empty($email)) {
        $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $existingUser = $stmt->fetch();

        if ($existingUser) {
            // BIZTONSÁGI ELLENŐRZÉS: Ha van jelszava, TILOS automatikusan beléptetni!
            if (!empty($existingUser['password_hash'])) {
                $_SESSION['flash_message'] = "Ez az e-mail cím már regisztrálva van jelszóval. Kérjük, lépj be hagyományosan!";
                $_SESSION['flash_message_type'] = "error";
                return false;
            }

            // Összekötés
            $updateStmt = $db->prepare("UPDATE users SET github_id = :gh_id WHERE id = :id");
            $updateStmt->execute([':gh_id' => $ghId, ':id' => $existingUser['id']]);
            
            setSession($existingUser['id'], $existingUser['username']);
            return true;
        }
    }

    // 3. ESET: Új regisztráció
    // Ha a GitHub nem adott emailt (mert privát), akkor generálnunk kell egy placeholder-t vagy kérni a usertől.
    // De a lenti kódban lekérjük a privát emailt is, szóval elvileg mindig lesz.
    
    $baseUsername = createSlug($ghInfo['login']); // GitHub login név jó alap
    if (empty($baseUsername)) $baseUsername = 'gh_user';
    $finalUsername = $baseUsername;
    $counter = 1;

    while (true) {
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
        $checkStmt->execute([':username' => $finalUsername]);
        if ($checkStmt->fetchColumn() == 0) break;
        $finalUsername = $baseUsername . $counter;
        $counter++;
    }

    try {
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, is_admin, github_id, created_at) VALUES (:username, :email, NULL, 0, :gh_id, NOW())");
        $stmt->execute([
            ':username' => $finalUsername,
            ':email' => $email, // Ha üres, itt hiba lehet az adatbázis 'NOT NULL' miatt. A lenti kód kezeli.
            ':gh_id' => $ghId
        ]);
        setSession($db->lastInsertId(), $finalUsername);
        return true;
    } catch (PDOException $e) {
        error_log("GitHub registration error: " . $e->getMessage());
        return false;
    }
}
// Segédfüggvény a session beállításhoz (DRY elv)
function setSession($userId, $username) {
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
}

// --- MARADÉK FÜGGVÉNYEK VÁLTOZATLANUL (vagy minimális igazítással) ---

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . BASE_URL . 'tracker/login.php');
        exit;
    }
}

function logoutUser() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: ' . BASE_URL . 'tracker/login.php');
    exit;
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUsername() {
    return $_SESSION['username'] ?? null;
}

function getCurrentUserRole() {
    if (!isLoggedIn()) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT is_admin FROM users WHERE id = :user_id");
    $stmt->execute([':user_id' => getCurrentUserId()]);
    $role = $stmt->fetchColumn();
    if ($role === false) return 'user';
    return $role == 1 ? 'admin' : 'user';
}

function isAdmin() {
    return getCurrentUserRole() === 'admin';
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        $_SESSION['flash_message'] = "Nincs jogosultságod az oldal megtekintéséhez.";
        $_SESSION['flash_message_type'] = "error";
        header('Location: ' . BASE_URL . 'tracker/dashboard.php');
        exit;
    }
}
?>