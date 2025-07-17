<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'tracker/dashboard.php');
} else {
    header('Location: ' . BASE_URL . 'tracker/login.php');
}
exit;
?>