<?php
// facefinder/api/maintenance.php
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json');

// MySQL kapcsolat a főkonfigból (auth_check.php már betöltötte a config.php-t)
$rootDir = dirname(__DIR__, 2);
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'msg' => 'Adatbázis hiba: ' . $e->getMessage()]);
    exit;
}

// FastAPI URL meghatározása
$isWin      = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$fastApiUrl = 'http://127.0.0.1:' . ($isWin ? 8005 : 8000);

$action = $_GET['action'] ?? '';

if ($action === 'fix_paths') {
    $ch = curl_init($fastApiUrl . "/api/maintenance/fix_paths");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $res = curl_exec($ch); curl_close($ch);
    echo $res ?: json_encode(['ok' => false, 'msg' => 'Backend nem elérhető.']); exit;
}

if ($action === 'db_supplement') {
    $ch = curl_init($fastApiUrl . "/api/maintenance/db_supplement");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch); curl_close($ch);
    echo $res ?: json_encode(['ok' => false, 'msg' => 'Backend nem elérhető.']); exit;
}

if ($action === 'fix_perms') {
    $ffDir = dirname(__DIR__);
    $dirs = [$ffDir . '/temp/', $ffDir . '/data/'];
    $fixed = [];
    foreach ($dirs as $d) {
        if (is_dir($d)) { chmod($d, 0777); $fixed[] = basename($d) . '/'; }
    }
    echo json_encode(['ok' => true, 'msg' => 'Jogosultságok javítva: ' . implode(', ', $fixed)]); exit;
}

if ($action === 'index_all') {
    $ch = curl_init($fastApiUrl . "/api/queue/scan_images");
    curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = curl_exec($ch); curl_close($ch);
    echo $res ?: json_encode(['ok' => false, 'msg' => 'Backend nem elérhető.']); exit;
}

if ($action === 'run_cluster') {
    $gallery_id_param = isset($_GET['gallery_id']) ? (int)$_GET['gallery_id'] : 0;
    $url = $fastApiUrl . "/api/cluster";
    if ($gallery_id_param > 0) {
        $url .= "?gallery_id=" . $gallery_id_param;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Klaszterezés hosszú folyamat
    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        echo json_encode(['ok' => false, 'msg' => 'Backend nem elérhető vagy időtúllépés: ' . $curlErr]);
    } else {
        echo $res;
    }
    exit;
}

if ($action === 'clean_clusters') {
    try {
        $pdo->exec("UPDATE ff_faces SET cluster_id = -1");
        $pdo->exec("DELETE FROM ff_persons");
        echo json_encode(['ok' => true, 'msg' => 'Klaszterek és személyek sikeresen törölve.']); exit;
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Adatbázis hiba: ' . $e->getMessage()]); exit;
    }
}

if ($action === 'cleanup') {
    $tempDir = dirname(__DIR__) . '/temp/';
    $c = 0;
    if (is_dir($tempDir)) {
        foreach (glob($tempDir . '*') as $f) {
            if (is_file($f)) { @unlink($f) && $c++; }
        }
    }
    echo json_encode(['ok' => true, 'msg' => "Tisztítás kész ($c fájl törölve a temp mappából)."]); exit;
}

if ($action === 'queue_status') {
    try {
        $stmt = $pdo->query("SELECT status, COUNT(*) as c FROM ff_jobs GROUP BY status");
        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']] = (int)$row['c'];
        }
        echo json_encode([
            'success' => true,
            'status' => [
                'pending'    => $counts['pending']    ?? 0,
                'processing' => $counts['processing'] ?? 0,
                'done'       => $counts['done']       ?? 0,
                'failed'     => $counts['failed']     ?? 0,
            ]
        ]); exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'status' => []]); exit;
    }
}

// db_export: MySQL dump letöltés egyszerűsítve
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'db_export') {
    // Exportáljuk a táblák tartalmát JSON-ba (egyszerű megoldás mysqldump nélkül)
    try {
        $tables = ['ff_faces', 'ff_jobs', 'ff_persons'];
        $export = [];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $export[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="visionai_export_' . date('Ymd_His') . '.json"');
        echo json_encode($export, JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ismeretlen maintenance művelet.']);
