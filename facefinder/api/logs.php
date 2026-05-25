<?php
// facefinder/api/logs.php
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json');

$logFile = dirname(__DIR__) . '/temp/fastapi.log';
$action  = $_GET['action'] ?? '';

if ($action === 'get_logs') {
    if (!file_exists($logFile)) {
        echo json_encode(['ok' => true, 'logs' => 'Nincs log fájl.']);
        exit;
    }
    $lines = shell_exec("tail -n 100 " . escapeshellarg($logFile));
    echo json_encode(['ok' => true, 'logs' => $lines ?: '']);
    exit;
}

if ($action === 'clear_logs') {
    file_put_contents($logFile, '');
    echo json_encode(['ok' => true, 'msg' => 'Log törölve.']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ismeretlen log művelet.']);
