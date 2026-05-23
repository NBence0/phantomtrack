<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

$facefinderDir = __DIR__;
$rootDir    = dirname(__DIR__);
$uploadsDir = $rootDir . '/uploads/';   // B1 fix: visszaállítva
$tempDir    = $facefinderDir . '/temp/';
$uploadedDir = $rootDir . '/uploaded/'; // F5 fix: archív mappa
$isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$fastApiPort = $isWin ? 8005 : 8000;
$fastApiUrl = "http://127.0.0.1:$fastApiPort";

if (!is_dir($uploadsDir))  @mkdir($uploadsDir,  0755, true);
if (!is_dir($tempDir))     @mkdir($tempDir,     0755, true);
if (!is_dir($uploadedDir)) @mkdir($uploadedDir, 0750, true);

// uploaded/ mappa .htaccess védelme
$htaccess = $uploadedDir . '.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
}

// ── E5: Rate limiting (search endpoint, IP alapon) ──────────────────
function check_rate_limit(string $action, int $max, int $window_sec): bool {
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key     = md5($ip . '_' . $action);
    $file    = sys_get_temp_dir() . '/rl_' . $key . '.json';
    $now     = time();
    $data    = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
    }
    // Régi bejegyzések törlése
    $data = array_filter($data, fn($t) => $t > $now - $window_sec);
    if (count($data) >= $max) {
        return false; // Limit elérve
    }
    $data[] = $now;
    file_put_contents($file, json_encode(array_values($data)), LOCK_EX);
    return true;
}

// ── Daemon indítás / ellenőrzés ──────────────────────────────────────
function ensure_daemon_running(): bool {
    $host     = '127.0.0.1';
    $isWin    = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    $port     = $isWin ? 8005 : 8000;
    $lockFile = '/var/www/nbence.hu/face/facefinder/temp/daemon_start.lock';

    $connection = @fsockopen($host, $port, $errno, $errstr, 1);
    if (is_resource($connection)) { fclose($connection); return true; }

    $fp = fopen($lockFile, 'c');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if (is_resource($connection)) {
            fclose($connection);
            flock($fp, LOCK_UN);
            fclose($fp);
            return true;
        }

        $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        if ($isWin) {
            $cmd = 'start /B "" python "' . __DIR__ . '/facefinder/backend/windows.py" > "' . __DIR__ . '/facefinder/temp/fastapi.log" 2>&1';
            pclose(popen($cmd, "r"));
        } else {
            $cmd = "nohup env PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin "
                 . "PYTHONPATH=/var/www/nbence.hu/face/facefinder/pylib "
                 . "cd /var/www/nbence.hu/face/facefinder && "
                 . "/usr/bin/python3 -m uvicorn backend.main:app "
                 . "--host 127.0.0.1 --port 8000 "
                 . "--app-dir /var/www/nbence.hu/face/facefinder "
                 . "> /var/www/nbence.hu/face/facefinder/temp/fastapi.log 2>&1 &";
            exec($cmd);
        }

        flock($fp, LOCK_UN);
    }
    fclose($fp);

    for ($i = 0; $i < 60; $i++) {
        usleep(500000);
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if (is_resource($connection)) { fclose($connection); return true; }
    }
    return false;
}

// ── Proxy helper ─────────────────────────────────────────────────────
function proxy_request(string $endpoint, array $data, string $method = 'POST') {
    global $fastApiUrl;
    $url     = $fastApiUrl . $endpoint;
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => $method,
            'content' => json_encode($data),
            'timeout' => 30,
            'ignore_errors' => true,
        ]
    ];
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

// ── Csak olvasó endpointok – Daemon NÉLKÜL, közvetlen SQLite ──────────
// (Ezek a faces.php-nak kellenek; daemon leállásakor is működnek)
$action = $_GET['action'] ?? '';
$dbPath = $facefinderDir . '/data/pipeline_data.db';

/**
 * Biztosítja a szükséges oszlopok meglétét a régi DB-n.
 */
function sqlite_migrate(PDO $pdo): void {
    $cols = [
        "face_thumb" => "TEXT DEFAULT NULL",
        "face_emb_idx" => "INTEGER DEFAULT NULL",
        "age" => "INTEGER DEFAULT NULL",
        "gender" => "TEXT DEFAULT NULL",
        "det_score" => "REAL DEFAULT NULL",
        "pitch" => "REAL DEFAULT NULL",
        "yaw" => "REAL DEFAULT NULL",
        "roll" => "REAL DEFAULT NULL",
        "kps" => "TEXT DEFAULT NULL"
    ];
    foreach ($cols as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE faces ADD COLUMN $col $def");
        } catch (Exception $e) {}
    }
    
    // face_emb_idx backfill régi sorokra
    try {
        $pdo->exec("UPDATE faces SET face_emb_idx = (face_id - 1) WHERE face_emb_idx IS NULL");
    } catch (Exception $e) {}
}

// Automatikus migráció KIKAPCSOLVA a normál folyamatok gyorsítása érdekében.
// Csak a karbantartás esetén, vagy kézzel hívható meg.

if ($action === 'get_clusters') {
    if (!file_exists($dbPath)) {
        echo json_encode(['success' => false, 'error' => 'Az adatbázis nem létezik.']);
        exit;
    }
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rows = $pdo->query("
            SELECT f.cluster_id, f.face_id, MAX(f.det_score) as max_score, p.name, p.notes,
                   COUNT(f.face_id) as face_count
            FROM faces f
            LEFT JOIN persons p ON f.cluster_id = p.cluster_id
            WHERE f.cluster_id != -1
            GROUP BY f.cluster_id
            ORDER BY face_count DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $clusterList = [];
        foreach ($rows as $row) {
            $clusterList[] = [
                'cluster_id' => (int)$row['cluster_id'],
                'name'       => $row['name'],
                'notes'      => $row['notes'],
                'count'      => (int)$row['face_count'],
                'cover_face_id' => (int)$row['face_id'] // Csak EGY arc az indexképet szolgálni
            ];
        }
        echo json_encode(['success' => true, 'clusters' => $clusterList]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_person_faces') {
    $cid       = isset($_GET['cluster_id']) ? (int)$_GET['cluster_id'] : -1;
    $page      = isset($_GET['page'])       ? max(1, (int)$_GET['page']) : 1;
    $limit     = isset($_GET['limit'])      ? min(500, max(50, (int)$_GET['limit'])) : 200;
    $offset    = ($page - 1) * $limit;

    // ── Szerver-oldali rendezés (lapozási bug fix) ──────────────────────
    $sortParam = $_GET['sort'] ?? 'id_desc';
    $orderBy   = match($sortParam) {
        'score_asc'  => 'det_score ASC NULLS LAST',
        'score_desc' => 'det_score DESC NULLS LAST',
        'name_asc'   => 'video_path ASC',
        default      => 'face_id DESC',
    };

    // ── Szerver-oldali szűrés ───────────────────────────────────────────
    $minScore  = isset($_GET['min_score']) ? (float)$_GET['min_score'] : 0.0;
    $filterStr = trim($_GET['filter'] ?? '');

    if ($cid == -1 && !file_exists($dbPath)) {
        echo json_encode(['success' => false, 'error' => 'Érvénytelen azonosító.']);
        exit;
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // WHERE feltételek összeállítása
        $whereParts  = [];
        $whereParams = [];

        if ($cid !== -2) {
            $whereParts[]  = 'cluster_id = ?';
            $whereParams[] = $cid;
        }
        if ($minScore > 0.0) {
            $whereParts[]  = '(det_score IS NOT NULL AND det_score >= ?)';
            $whereParams[] = $minScore;
        }
        if ($filterStr !== '') {
            $whereParts[]  = 'video_path LIKE ?';
            $whereParams[] = '%' . $filterStr . '%';
        }

        $whereSQL = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

        // Darabszám
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM faces $whereSQL");
        $countStmt->execute($whereParams);
        $totalCount = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalCount / $limit));

        // Lapozott eredmény
        $queryParams   = array_merge($whereParams, [$limit, $offset]);
        $stmt = $pdo->prepare("
            SELECT face_id, video_path, bbox, face_thumb, age, gender, det_score, pitch, yaw, roll
            FROM faces
            $whereSQL
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($queryParams);

        $faces = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bbox     = $row['bbox'] ? array_map('floatval', explode(',', $row['bbox'])) : [];
            $thumbUrl = $row['face_thumb']
                ? ('api.php?action=get_thumb&face_id=' . $row['face_id'])
                : null;

            $faces[] = [
                'face_id'   => (int)$row['face_id'],
                'file_path' => basename(str_replace('\\', '/', $row['video_path'])),
                'bbox'      => $bbox,
                'thumb'     => $thumbUrl,
                'age'       => $row['age'],
                'gender'    => $row['gender'],
                'score'     => $row['det_score'],
                'pose'      => [
                    'pitch' => $row['pitch'],
                    'yaw'   => $row['yaw'],
                    'roll'  => $row['roll']
                ]
            ];
        }

        echo json_encode([
            'success'     => true,
            'faces'       => $faces,
            'total_pages' => $totalPages,
            'current_page'=> $page,
            'total_faces' => $totalCount,
            'sort'        => $sortParam,
            'min_score'   => $minScore,
            'filter'      => $filterStr,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_thumb') {
    $face_id = isset($_GET['face_id']) ? (int)$_GET['face_id'] : 0;
    if ($face_id > 0 && file_exists($dbPath)) {
        $pdo = new PDO('sqlite:' . $dbPath);
        $stmt = $pdo->prepare("SELECT face_thumb FROM faces WHERE face_id = ?");
        $stmt->execute([$face_id]);
        $data = $stmt->fetchColumn();
        if ($data) {
            if (strpos($data, 'data:image/webp;base64,') === 0) {
                $data = base64_decode(substr($data, 23));
            }
            header('Content-Type: image/webp');
            // Cache-elés engedélyezése a natív böngésző gyorsításhoz
            header('Cache-Control: public, max-age=86400');
            echo $data;
            exit;
        }
    }
    // Fallback üres kép (1x1 transparent)
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}

if ($action === 'get_persons') {
    if (!file_exists($dbPath)) {
        echo json_encode(['success' => true, 'persons' => []]);
        exit;
    }
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $rows = $pdo->query(
            "SELECT cluster_id, name, notes, updated_at FROM persons ORDER BY cluster_id"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'persons' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'faces_in_image') {
    $filename = basename($_GET['filename'] ?? '');
    if (!$filename || !file_exists($dbPath)) {
        echo json_encode(['success' => false, 'faces' => []]);
        exit;
    }
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $stmt = $pdo->prepare("
            SELECT f.face_id, f.bbox, f.cluster_id, p.name, f.face_thumb, f.age, f.gender, f.det_score,
                   f.pitch, f.yaw, f.roll, f.kps
            FROM faces f
            LEFT JOIN persons p ON f.cluster_id = p.cluster_id
            WHERE f.video_path LIKE ?
        ");
        $stmt->execute(['%' . $filename]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $bbox = $row[1] ? array_map('floatval', explode(',', $row[1])) : [];
            $thumb = $row[4];
            if ($thumb && strpos($thumb, 'data:') !== 0 && !file_exists($rootDir . '/face_thumbs/' . $thumb)) {
                $thumb = 'data:image/webp;base64,' . base64_encode($thumb);
            }
            $result[] = [
                'face_id'    => (int)$row[0],
                'bbox'       => $bbox,
                'cluster_id' => (int)$row[2],
                'name'       => $row[3],
                'thumb'      => $thumb,
                'age'        => $row[5],
                'gender'     => $row[6],
                'score'      => $row[7],
                'pose'       => ['pitch' => $row[8], 'yaw' => $row[9], 'roll' => $row[10]],
                'kps'        => $row[11]
            ];
        }
        echo json_encode(['success' => true, 'faces' => $result]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'faces' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

// ── QUEUE STATUS ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'queue_status') {
    $result = @file_get_contents($fastApiUrl . '/api/queue/status');
    echo $result ?: json_encode(['success' => false, 'error' => 'Nem érhető el az API.']);
    exit;
}

// ── Daemon ellenőrzés az összes többi actionhöz ─────────────────────
if (!ensure_daemon_running()) {
    echo json_encode(['success' => false, 'error' => 'A Python ML Démon nem indul el.']);
    exit;
}

// ── UPLOAD ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Fájlfeltöltési hiba.']);
        exit;
    }
    $file     = $_FILES['file'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Nem támogatott képformátum.']);
        exit;
    }
    $filename = uniqid('media_') . '.' . $ext;
    $destPath = $uploadsDir . $filename; // B1 fix: $uploadsDir most elérhető
    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $result = proxy_request('/api/queue', ['path' => $destPath]);
        echo $result ?: json_encode(['success' => false, 'error' => 'Nem érkezett válasz az API-tól.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Nem sikerült mozgatni a fájlt.']);
    }
    exit;
}

// ── SEARCH (SZELFI) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'search') {
    // E5: Rate limiting – max 5 keresés / perc / IP
    if (!check_rate_limit('search', 5, 60)) {
        echo json_encode(['success' => false, 'error' => 'Túl sok keresés. Kérlek várj egy percet.']);
        exit;
    }

    if (!isset($_FILES['selfie']) || $_FILES['selfie']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Szelfi feltöltési hiba.']);
        exit;
    }

    $file     = $_FILES['selfie'];
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'error' => 'Nem támogatott képformátum.']);
        exit;
    }

    $filename = uniqid('search_') . '.' . $ext;
    $destPath = $tempDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $result = proxy_request('/api/search', ['path' => $destPath]);

        // F5 fix: ne töröljük, hanem archivájuk az uploaded/ mappába
        $archivePath = $uploadedDir . basename($destPath);
        @rename($destPath, $archivePath);

        echo $result ?: json_encode(['success' => false, 'error' => 'Nem érkezett válasz az API-tól.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Hiba a fájl mozgatásakor.']);
    }
    exit;
}



// ── INDEX ALL ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'index_all') {
    $dir = $rootDir . '/images/';
    // B2 fix: .webp is szerepel a glob pattern-ben
    $files = glob($dir . '*.{jpg,jpeg,png,gif,JPG,JPEG,PNG,GIF,webp,WEBP}', GLOB_BRACE);
    $count = 0;

    // E1 fix: csak még nem 'done' státuszú fájlokat küldünk be
    $dbPath = $facefinderDir . '/data/pipeline_data.db';
    $doneFiles = [];
    if (file_exists($dbPath)) {
        try {
            $sqlite = new PDO('sqlite:' . $dbPath);
            $stmt = $sqlite->query("SELECT file_path FROM jobs WHERE status = 'done'");
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fp) {
                $doneFiles[realpath($fp) ?: $fp] = true;
            }
        } catch (Exception $e) { /* ignore */ }
    }

    foreach ($files as $file) {
        $realFile = realpath($file);
        if ($realFile && isset($doneFiles[$realFile])) continue; // E1: skip done

        $result = proxy_request('/api/queue', ['path' => $realFile ?: $file]);
        if ($result) {
            $resData = json_decode($result, true);
            if (!empty($resData['success'])) $count++;
        }
    }
    echo json_encode(['success' => true, 'count' => $count]);
    exit;
}

// ── CLUSTER ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cluster') {
    $result = proxy_request('/api/cluster', []);
    echo $result ?: json_encode(['success' => false, 'error' => 'Nem érkezett válasz az API-tól.']);
    exit;
}

// ── RENAME PERSON – közvetlen SQLite (daemon nem kell) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'rename_person') {
    $body       = json_decode(file_get_contents('php://input'), true);
    $cluster_id = intval($body['cluster_id'] ?? -1);
    $name       = trim($body['name'] ?? '');
    $notes      = trim($body['notes'] ?? '');
    if ($cluster_id < 0 || $name === '') {
        echo json_encode(['success' => false, 'error' => 'Hiányzó cluster_id vagy name.']);
        exit;
    }
    if (!file_exists($dbPath)) {
        echo json_encode(['success' => false, 'error' => 'Adatbázis nem létezik.']);
        exit;
    }
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("
            INSERT INTO persons (cluster_id, name, notes, updated_at)
            VALUES (:cid, :name, :notes, datetime('now'))
            ON CONFLICT(cluster_id) DO UPDATE SET
                name       = excluded.name,
                notes      = excluded.notes,
                updated_at = excluded.updated_at
        ");
        $stmt->execute([
            ':cid'   => $cluster_id,
            ':name'  => $name,
            ':notes' => $notes ?: null
        ]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── GET CLUSTERS – átirányítás (már fentebb közvetlen SQLite-ként fut) ────
// ── GET PERSONS – ugyanaz ───────────────────────────────────────────────────
// ── FACES IN IMAGE – ugyanaz ────────────────────────────────────────────────

echo json_encode(['success' => false, 'error' => 'Érvénytelen kérés.']);
