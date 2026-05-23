<?php
// facefinder/api/daemon.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_check.php';

$action = $_GET['action'] ?? '';
$logFile = TEMP_DIR . "fastapi.log";

if ($action === 'start_daemon') {
    $running = trim(shell_exec("ps aux | grep '[u]vicorn' | grep '" . FASTAPI_PORT . "' | awk '{print $2}'"));
    if ($running) {
        echo json_encode(['ok' => false, 'msg' => "Démon már fut (PID: $running). Állítsd le először!"]); exit;
    }
    
    @chmod(DATA_DIR, 0777);
    @chmod(TEMP_DIR, 0777);
    if (file_exists(DB_PATH)) @chmod(DB_PATH, 0666);
    
    // BACKEND_DIR a script munkakönyvtára
    $cmd = "export PATH=/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin:\$PATH;"
         . " export PYTHONPATH=\$PYTHONPATH:" . PYLIB_DIR . ";"
         . " cd " . FACEFINDER_DIR . " &&"
         . " nohup " . PYTHON_EXEC . " -m uvicorn backend.main:app"
         . " --host " . FASTAPI_HOST . " --port " . FASTAPI_PORT
         . " > $logFile 2>&1 &";
    exec($cmd);
    sleep(1);
    echo json_encode(['ok' => true, 'msg' => 'Démon elindítva.']); exit;
}

if ($action === 'kill_daemon') {
    exec("ps aux | grep '[u]vicorn' | awk '{print $2}' | xargs kill -9 2>/dev/null");
    if (file_exists(DB_PATH)) {
        try {
            require_once __DIR__ . '/db.php';
            $pdo = Database::getInstance()->getConnection();
            $pdo->exec("UPDATE jobs SET status = 'failed' WHERE status = 'processing'");
        } catch (Exception $e) { }
    }
    @unlink(TEMP_DIR . 'daemon_start.lock');
    echo json_encode(['ok' => true, 'msg' => 'Démon leállítva. Ragadt jobok visszaállítva.']); exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ismeretlen daemon művelet.']);
