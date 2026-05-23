<?php
// facefinder/api/auth_check.php
session_start();
if (!isset($_SESSION['ai_ok'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Nincs jogosultság.']);
    exit;
}
