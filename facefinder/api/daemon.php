<?php
// facefinder/api/daemon.php — MySQL-re migrálva
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json');

// Helyi facefinder-specifikus konstansok (config.php-tól független)
$ffDir      = dirname(__DIR__);
$tempDir    = $ffDir . '/temp/';
$dataDir    = $ffDir . '/data/';
$pythonExec = '/usr/bin/python3';
$isWin      = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$fastPort   = $isWin ? 8005 : 8000;
$fastHost   = '127.0.0.1';
$logFile    = $tempDir . 'fastapi.log';

$action = $_GET['action'] ?? '';

if ($action === 'start_daemon') {
    $running = trim(shell_exec("ps aux | grep '[u]vicorn' | grep '$fastPort' | awk '{print $2}'"));
    if ($running) {
        echo json_encode(['ok' => false, 'msg' => "Démon már fut (PID: $running). Állítsd le először!"]); exit;
    }

    @chmod($dataDir, 0777);
    @chmod($tempDir, 0777);

    $pyLibPath = escapeshellarg($ffDir . '/pylib');

    $cmd = "export PATH=/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin:\$PATH;"
         . " export PYTHONPATH=" . $ffDir . "/pylib;"
         . " cd " . escapeshellarg($ffDir) . " &&"
         . " nohup $pythonExec -m uvicorn backend.main:app"
         . " --host $fastHost --port $fastPort"
         . " > " . escapeshellarg($logFile) . " 2>&1 &";
    exec($cmd);
    sleep(2);
    // Ellenőrizzük hogy tényleg elindult-e
    $running = trim(shell_exec("ps aux | grep '[u]vicorn' | grep '$fastPort' | awk '{print $2}'"));
    if ($running) {
        echo json_encode(['ok' => true, 'msg' => "Démon elindítva (PID: $running)."]); exit;
    } else {
        $lastLog = file_exists($logFile) ? trim(shell_exec("tail -n 5 " . escapeshellarg($logFile))) : 'Nincs log.';
        echo json_encode(['ok' => false, 'msg' => 'Démon indítása sikertelen. Log: ' . $lastLog]); exit;
    }
}

if ($action === 'kill_daemon') {
    exec("ps aux | grep '[u]vicorn' | awk '{print $2}' | xargs kill -9 2>/dev/null");

    // MySQL-ben visszaállítjuk a ragadt processing jobokat
    try {
        $rootDir = dirname($ffDir);
        require_once $rootDir . '/config.php';
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->exec("UPDATE ff_jobs SET status = 'pending', retry_count = retry_count + 1 WHERE status = 'processing'");
    } catch (Exception $e) { /* nem kritikus */ }

    @unlink($tempDir . 'daemon_start.lock');
    echo json_encode(['ok' => true, 'msg' => 'Démon leállítva. Ragadt jobok visszaállítva.']); exit;
}

if ($action === 'status') {
    $running = trim(shell_exec("ps aux | grep '[u]vicorn' | grep '$fastPort' | awk '{print $2}'"));
    echo json_encode(['ok' => true, 'running' => !empty($running), 'pid' => $running ?: null]); exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ismeretlen daemon művelet.']);
