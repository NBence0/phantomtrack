<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

function registerUser($username, $email, $password) {
    $db = getDB();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, created_at) VALUES (:username, :email, :password_hash, NOW())");
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password_hash', $passwordHash);
        $stmt->execute();
        return $db->lastInsertId();
    } catch (PDOException $e) {
        // Kezeld a duplikált felhasználónév/email hibát
        // if ($e->errorInfo[1] == 1062) { return false; }
        error_log("User registration error: " . $e->getMessage());
        return false;
    }
}

function attemptLogin($usernameOrEmail, $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, password_hash FROM users WHERE username = :login OR email = :login LIMIT 1");
    $stmt->bindParam(':login', $usernameOrEmail);
    $stmt->execute();
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        return true;
    }
    return false;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI']; // Elmentjük, hova akart menni
        header('Location: ' . BASE_URL . 'admin/login.php');
        exit;
    }
}

function logoutUser() {
    $_SESSION = array(); // Töröljük az összes session változót
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: ' . BASE_URL . 'admin/login.php');
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
    return $role == 1 ? 'admin' : 'user'; // Vagy csak a 0/1 értéket visszaadni
}

function isAdmin() {
    return getCurrentUserRole() === 'admin';
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        $_SESSION['flash_message'] = "Nincs jogosultságod az oldal megtekintéséhez.";
        $_SESSION['flash_message_type'] = "error";
        header('Location: ' . BASE_URL . 'admin/dashboard.php'); // Vagy egy "access denied" oldalra
        exit;
    }
}
?>