<?php
// facefinder/api/maintenance.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_check.php';

$action = $_GET['action'] ?? '';

if ($action === 'fix_paths') {
    $ch = curl_init(FASTAPI_URL . "/api/maintenance/fix_paths");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $res = curl_exec($ch); curl_close($ch);
    echo $res ?: json_encode(['ok' => false, 'msg' => 'Backend nem elérhető.']); exit;
}

if ($action === 'db_supplement') {
    $ch = curl_init(FASTAPI_URL . "/api/maintenance/db_supplement");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch); curl_close($ch);
    echo $res ?: json_encode(['ok' => false, 'msg' => 'Backend nem elérhető.']); exit;
}

if ($action === 'fix_perms') {
    $dirs = [DATA_DIR, TEMP_DIR, UPLOADS_DIR, IMAGES_DIR];
    $fixed = [];
    foreach ($dirs as $d) {
        if (is_dir($d)) {
            chmod($d, 0777);
            $fixed[] = basename($d) . '/';
        }
    }
    if (file_exists(DB_PATH)) {
        chmod(DB_PATH, 0666);
        $fixed[] = 'pipeline_data.db';
    }
    foreach (glob(DATA_DIR . '*.db*') as $f) {
        @chmod($f, 0666);
    }
    echo json_encode(['ok' => true, 'msg' => 'Jogosultságok javítva: ' . implode(', ', $fixed)]); exit;
}

if ($action === 'index_all') {
    $ch = curl_init(FASTAPI_URL . "/api/queue/scan_images");
    curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = curl_exec($ch); curl_close($ch);
    echo $res ?: json_encode(['ok' => false, 'msg' => 'Backend nem elérhető.']); exit;
}

if ($action === 'run_cluster') {
    $ch = curl_init(FASTAPI_URL . "/api/cluster");
    curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = curl_exec($ch); curl_close($ch);
    echo $res ?: json_encode(['ok' => false, 'msg' => 'Hiba vagy időtúllépés.']); exit;
}

if ($action === 'clean_clusters') {
    if (file_exists(DB_PATH)) {
        try {
            require_once __DIR__ . '/db.php';
            $pdo = Database::getInstance()->getConnection();
            $pdo->exec("UPDATE faces SET cluster_id = -1");
            $pdo->exec("DELETE FROM persons");
            echo json_encode(['ok' => true, 'msg' => 'Klaszterek és személyek sikeresen törölve.']); exit;
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'Adatbázis hiba: ' . $e->getMessage()]); exit;
        }
    }
    echo json_encode(['ok' => false, 'msg' => 'Adatbázis nem található.']); exit;
}

if ($action === 'upload_db') {
    if (!isset($_FILES['db_file']) || $_FILES['db_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'Fájlfeltöltési hiba.']); exit;
    }
    $mode = $_POST['mode'] ?? 'append';
    $uploadedFile = $_FILES['db_file']['tmp_name'];
    
    if ($mode === 'replace') {
        if (move_uploaded_file($uploadedFile, DB_PATH)) {
            @chmod(DB_PATH, 0666);
            echo json_encode(['ok' => true, 'msg' => 'Adatbázis sikeresen lecserélve!']); exit;
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Fájl mozgatása sikertelen.']); exit;
        }
    } else {
        if (!file_exists(DB_PATH)) {
            echo json_encode(['ok' => false, 'msg' => 'Alap adatbázis nem létezik, append nem lehetséges.']); exit;
        }
        try {
            require_once __DIR__ . '/db.php';
            $pdo = Database::getInstance()->getConnection();
            $pdo->exec("ATTACH DATABASE '$uploadedFile' AS upload_db");
            
            $get_cols = function($table) use ($pdo) {
                $up_cols = $pdo->query("PRAGMA upload_db.table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
                $main_cols = $pdo->query("PRAGMA main.table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
                $common = array_intersect(array_column($up_cols, 'name'), array_column($main_cols, 'name'));
                return implode(',', $common);
            };
            
            $c_faces = $get_cols('faces');
            $n_faces = $pdo->exec("INSERT OR IGNORE INTO main.faces ($c_faces) SELECT $c_faces FROM upload_db.faces");
            
            $c_jobs = $get_cols('jobs');
            $n_jobs = $pdo->exec("INSERT OR IGNORE INTO main.jobs ($c_jobs) SELECT $c_jobs FROM upload_db.jobs");
            
            $c_persons = $get_cols('persons');
            $n_persons = $pdo->exec("INSERT OR IGNORE INTO main.persons ($c_persons) SELECT $c_persons FROM upload_db.persons");
            
            $pdo->exec("DETACH DATABASE upload_db");
            
            echo json_encode(['ok' => true, 'msg' => "Kiegészítés kész: +$n_faces arc, +$n_jobs fájl, +$n_persons személy."]); exit;
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'msg' => 'SQLite hiba: ' . $e->getMessage()]); exit;
        }
    }
}

if ($action === 'cleanup') {
    $c = 0;
    foreach (glob(TEMP_DIR . '/*') as $f) {
        if (is_file($f)) { @unlink($f) && $c++; }
    }
    echo json_encode(['ok' => true, 'msg' => "Tisztítás kész ($c fájl törölve a temp mappából)."]); exit;
}

// GET: db_export
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'db_export') {
    if (!file_exists(DB_PATH)) { echo "DB nem található."; exit; }
    $backupPath = DATA_DIR . 'pipeline_data_backup.db';
    try {
        $src = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);
        $src->exec("PRAGMA wal_checkpoint(TRUNCATE)");
        $src->close();
    } catch (Exception $e) { }
    copy(DB_PATH, $backupPath);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="pipeline_data_backup.db"');
    header('Content-Length: ' . filesize($backupPath));
    readfile($backupPath);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ismeretlen maintenance művelet.']);
