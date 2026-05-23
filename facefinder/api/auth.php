<?php
// facefinder/api/auth.php
session_start();
require_once __DIR__ . '/config.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../ai.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'login') {
        if (($_POST['password'] ?? '') === AI_PASSWORD) {
            $_SESSION['ai_ok'] = true;
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Hibás jelszó!']);
        }
        exit;
    }
}
