<?php
// facefinder/api/logs.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_check.php';

$action = $_GET['action'] ?? '';
$logFile = TEMP_DIR . "fastapi.log";

if ($action === 'get_logs') {
    if (!file_exists($logFile)) { 
        echo json_encode(['ok' => true, 'logs' => "Nincs log fájl."]); 
        exit; 
    }
    // Ezt shell_exec-el olvassuk a sebesség miatt (tail)
    $lines = shell_exec("tail -n 80 " . escapeshellarg($logFile));
    echo json_encode(['ok' => true, 'logs' => $lines]); 
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ismeretlen log művelet.']);
