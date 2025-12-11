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
    $name = $googleInfo['name'] ?? explode('@', $email)[0]; // Ha nincs név, az email eleje

    // 1. Megnézzük, van-e már user ezzel a Google ID-val
    $stmt = $db->prepare("SELECT id, username FROM users WHERE google_id = :google_id LIMIT 1");
    $stmt->execute([':google_id' => $googleId]);
    $user = $stmt->fetch();

    if ($user) {
        // Van ilyen user -> Beléptetés
        setSession($user['id'], $user['username']);
        return true;
    }

    // 2. Ha nincs Google ID, megnézzük, van-e ilyen EMAIL cím
    $stmt = $db->prepare("SELECT id, username FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        // Van user ezzel az emaillel -> Összekötjük a fiókot a Google ID-val
        $updateStmt = $db->prepare("UPDATE users SET google_id = :google_id WHERE id = :id");
        $updateStmt->execute([':google_id' => $googleId, ':id' => $existingUser['id']]);
        
        setSession($existingUser['id'], $existingUser['username']);
        return true;
    }

    // 3. Teljesen új felhasználó -> Regisztráció
    // Generálunk egy egyedi felhasználónevet (mert a Google név lehet foglalt)
    $baseUsername = createSlug($name); // A functions.php-ban lévő slug generálót használjuk
    $finalUsername = $baseUsername;
    $counter = 1;

    while (true) {
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
        $checkStmt->execute([':username' => $finalUsername]);
        if ($checkStmt->fetchColumn() == 0) {
            break;
        }
        $finalUsername = $baseUsername . $counter;
        $counter++;
    }

    // Beszúrás (Jelszó nélkül, Google ID-val)
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