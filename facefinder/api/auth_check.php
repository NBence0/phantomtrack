<?php
// facefinder/api/auth_check.php
session_start();
$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/config.php';
require_once $rootDir . '/includes/auth.php';

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Nincs jogosultság.']);
    exit;
}
