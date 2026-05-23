<?php
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

$facefinderDir = __DIR__;
$rootDir       = dirname(__DIR__);
$tempDir       = $facefinderDir . '/temp/';
$uploadedDir   = $rootDir . '/uploaded/'; 

require_once $rootDir . '/config.php';

if (!is_dir($tempDir))     @mkdir($tempDir,     0755, true);
if (!is_dir($uploadedDir)) @mkdir($uploadedDir, 0750, true);

$htaccess = $uploadedDir . '.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Nincs bejelentkezve.']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Adatbázis csatlakozási hiba.']);
    exit;
}

$gallery_id = isset($_REQUEST['gallery_id']) ? (int)$_REQUEST['gallery_id'] : 0;
if ($gallery_id <= 0 && $_GET['action'] !== 'get_thumb') {
    // get_thumb might not have gallery_id, but it's better if it does. We will secure it too.
    echo json_encode(['success' => false, 'error' => 'Hiányzó gallery_id paraméter.']);
    exit;
}

if ($gallery_id > 0) {
    $stmt = $pdo->prepare("SELECT user_id, folder_name FROM galleries WHERE id = ?");
    $stmt->execute([$gallery_id]);
    $gallery = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$gallery) {
        echo json_encode(['success' => false, 'error' => 'A galéria nem található.']);
        exit;
    }

    $is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    if (!$is_admin && $gallery['user_id'] != $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'error' => 'Hozzáférés megtagadva.']);
        exit;
    }
}

$isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
$fastApiPort = $isWin ? 8005 : 8000;
$fastApiUrl = "http://127.0.0.1:$fastApiPort";

function check_rate_limit(string $action, int $max, int $window_sec): bool {
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key     = md5($ip . '_' . $action);
    $file    = sys_get_temp_dir() . '/rl_' . $key . '.json';
    $now     = time();
    $data    = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true) ?: [];
    }
    $data = array_filter($data, fn($t) => $t > $now - $window_sec);
    if (count($data) >= $max) {
        return false; 
    }
    $data[] = $now;
    file_put_contents($file, json_encode(array_values($data)), LOCK_EX);
    return true;
}

function ensure_daemon_running(): bool {
    global $fastApiPort;
    $host = '127.0.0.1';
    $connection = @fsockopen($host, $fastApiPort, $errno, $errstr, 1);
    if (is_resource($connection)) { fclose($connection); return true; }
    // Daemon indítása csak admin által (biztonság miatt, de most hagyjuk auto-startolni ha kell)
    // De biztonságosabb, ha a web UI nem indítja, csak a cron. Maradjon a kód.
    $lockFile = __DIR__ . '/temp/daemon_start.lock';
    $fp = fopen($lockFile, 'c');
    if (!$fp) return false;
    if (flock($fp, LOCK_EX)) {
        $connection = @fsockopen($host, $fastApiPort, $errno, $errstr, 1);
        if (is_resource($connection)) {
            fclose($connection); flock($fp, LOCK_UN); fclose($fp); return true;
        }
        $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        if ($isWin) {
            $cmd = 'start /B "" python "' . __DIR__ . '/backend/windows.py" > "' . __DIR__ . '/temp/fastapi.log" 2>&1';
            pclose(popen($cmd, "r"));
        } else {
            $cmd = "nohup env PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin PYTHONPATH=".__DIR__."/pylib cd ".__DIR__." && /usr/bin/python3 -m uvicorn backend.main:app --host 127.0.0.1 --port 8000 --app-dir ".__DIR__." > ".__DIR__."/temp/fastapi.log 2>&1 &";
            exec($cmd);
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    for ($i = 0; $i < 60; $i++) {
        usleep(500000);
        $connection = @fsockopen($host, $fastApiPort, $errno, $errstr, 1);
        if (is_resource($connection)) { fclose($connection); return true; }
    }
    return false;
}

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

$action = $_GET['action'] ?? '';

if ($action === 'get_clusters') {
    try {
        $stmt = $pdo->prepare("
            SELECT f.cluster_id, f.face_id, MAX(f.det_score) as max_score, p.name, p.notes,
                   COUNT(f.face_id) as face_count
            FROM ff_faces f
            LEFT JOIN ff_persons p ON f.cluster_id = p.cluster_id AND f.gallery_id = p.gallery_id
            WHERE f.cluster_id != -1 AND f.gallery_id = ?
            GROUP BY f.cluster_id
            ORDER BY face_count DESC
        ");
        $stmt->execute([$gallery_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $clusterList = [];
        foreach ($rows as $row) {
            $clusterList[] = [
                'cluster_id' => (int)$row['cluster_id'],
                'name'       => $row['name'],
                'notes'      => $row['notes'],
                'count'      => (int)$row['face_count'],
                'cover_face_id' => (int)$row['face_id']
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

    $sortParam = $_GET['sort'] ?? 'id_desc';
    // MySQL handles NULLS LAST implicitly for DESC or we can use IS NULL
    $orderBy = 'face_id DESC';
    if ($sortParam === 'score_asc') $orderBy = 'det_score IS NULL, det_score ASC';
    if ($sortParam === 'score_desc') $orderBy = 'det_score IS NULL, det_score DESC';
    if ($sortParam === 'name_asc') $orderBy = 'video_path ASC';

    $minScore  = isset($_GET['min_score']) ? (float)$_GET['min_score'] : 0.0;
    $filterStr = trim($_GET['filter'] ?? '');

    try {
        $whereParts  = ['gallery_id = ?'];
        $whereParams = [$gallery_id];

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

        $whereSQL = 'WHERE ' . implode(' AND ', $whereParts);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ff_faces $whereSQL");
        $countStmt->execute($whereParams);
        $totalCount = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalCount / $limit));

        $queryParams   = array_merge($whereParams, [$limit, $offset]);
        $stmt = $pdo->prepare("
            SELECT face_id, video_path, bbox, face_thumb, age, gender, det_score, pitch, yaw, roll
            FROM ff_faces
            $whereSQL
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        ");
        // PDO bindParam for limits since MySQL gets angry at string limits
        foreach($whereParams as $key => $val) {
            $stmt->bindValue($key+1, $val);
        }
        $stmt->bindValue(count($whereParams)+1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(count($whereParams)+2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $faces = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bbox     = $row['bbox'] ? array_map('floatval', explode(',', $row['bbox'])) : [];
            $thumbUrl = $row['face_thumb']
                ? ('api.php?action=get_thumb&face_id=' . $row['face_id'] . '&gallery_id=' . $gallery_id)
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
    if ($face_id > 0) {
        $stmt = $pdo->prepare("SELECT face_thumb, gallery_id FROM ff_faces WHERE face_id = ?");
        $stmt->execute([$face_id]);
        $row = $stmt->fetch();
        if ($row) {
            // Verify access
            $is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
            $can_access = false;
            
            if ($is_admin) {
                $can_access = true;
            } else {
                $chk = $pdo->prepare("SELECT user_id FROM galleries WHERE id = ?");
                $chk->execute([$row['gallery_id']]);
                $g = $chk->fetch();
                if ($g && $g['user_id'] == $_SESSION['user_id']) {
                    $can_access = true;
                }
            }

            if ($can_access && $row['face_thumb']) {
                $data = $row['face_thumb'];
                if (strpos($data, 'data:image/webp;base64,') === 0) {
                    $data = base64_decode(substr($data, 23));
                }
                header('Content-Type: image/webp');
                header('Cache-Control: public, max-age=86400');
                echo $data;
                exit;
            }
        }
    }
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}

if ($action === 'get_persons') {
    try {
        $stmt = $pdo->prepare("SELECT cluster_id, name, notes, updated_at FROM ff_persons WHERE gallery_id = ? ORDER BY cluster_id");
        $stmt->execute([$gallery_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'persons' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'faces_in_image') {
    $filename = basename($_GET['filename'] ?? '');
    if (!$filename) {
        echo json_encode(['success' => false, 'faces' => []]);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT f.face_id, f.bbox, f.cluster_id, p.name, f.face_thumb, f.age, f.gender, f.det_score,
                   f.pitch, f.yaw, f.roll, f.kps
            FROM ff_faces f
            LEFT JOIN ff_persons p ON f.cluster_id = p.cluster_id AND f.gallery_id = p.gallery_id
            WHERE f.video_path LIKE ? AND f.gallery_id = ?
        ");
        $stmt->execute(['%' . $filename, $gallery_id]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bbox = $row['bbox'] ? array_map('floatval', explode(',', $row['bbox'])) : [];
            $thumb = $row['face_thumb'];
            $result[] = [
                'face_id'    => (int)$row['face_id'],
                'bbox'       => $bbox,
                'cluster_id' => (int)$row['cluster_id'],
                'name'       => $row['name'],
                'thumb'      => $thumb,
                'age'        => $row['age'],
                'gender'     => $row['gender'],
                'score'      => $row['det_score'],
                'pose'       => ['pitch' => $row['pitch'], 'yaw' => $row['yaw'], 'roll' => $row['roll']],
                'kps'        => $row['kps']
            ];
        }
        echo json_encode(['success' => true, 'faces' => $result]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'faces' => [], 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'queue_status') {
    $result = @file_get_contents($fastApiUrl . '/api/queue/status');
    echo $result ?: json_encode(['success' => false, 'error' => 'Nem érhető el az API.']);
    exit;
}

if (!ensure_daemon_running()) {
    echo json_encode(['success' => false, 'error' => 'A Python ML Démon nem indul el.']);
    exit;
}

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
    
    // Fájlok mennek a temp dir-be manual upload esetén.
    $destPath = $tempDir . $filename; 
    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $result = proxy_request('/api/queue', ['path' => $destPath, 'gallery_id' => $gallery_id]);
        echo $result ?: json_encode(['success' => false, 'error' => 'Nem érkezett válasz az API-tól.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Nem sikerült mozgatni a fájlt.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'search') {
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
        $result = proxy_request('/api/search', ['path' => $destPath, 'gallery_id' => $gallery_id]);

        $archivePath = $uploadedDir . basename($destPath);
        @rename($destPath, $archivePath);

        echo $result ?: json_encode(['success' => false, 'error' => 'Nem érkezett válasz az API-tól.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Hiba a fájl mozgatásakor.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'index_all') {
    // Scan physical files for this gallery
    $folder = $gallery['folder_name'];
    $dir = $rootDir . '/uploads/galleries/' . $folder . '/';
    if (!is_dir($dir)) {
        echo json_encode(['success' => false, 'error' => 'A galéria mappája nem található.']);
        exit;
    }

    $files = glob($dir . '*.{jpg,jpeg,png,gif,JPG,JPEG,PNG,GIF,webp,WEBP}', GLOB_BRACE);
    $count = 0;

    $doneFiles = [];
    try {
        $stmt = $pdo->prepare("SELECT file_path FROM ff_jobs WHERE gallery_id = ? AND status = 'done'");
        $stmt->execute([$gallery_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fp) {
            $doneFiles[realpath($fp) ?: $fp] = true;
        }
    } catch (Exception $e) { /* ignore */ }

    foreach ($files as $file) {
        $realFile = realpath($file);
        if ($realFile && isset($doneFiles[$realFile])) continue; 

        $result = proxy_request('/api/queue', ['path' => $realFile ?: $file, 'gallery_id' => $gallery_id]);
        if ($result) {
            $resData = json_decode($result, true);
            if (!empty($resData['success'])) $count++;
        }
    }
    echo json_encode(['success' => true, 'count' => $count]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cluster') {
    // A query parameters will pass gallery_id in proxy_request, wait proxy_request is POST body.
    $result = proxy_request('/api/cluster?gallery_id=' . $gallery_id, []);
    echo $result ?: json_encode(['success' => false, 'error' => 'Nem érkezett válasz az API-tól.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'rename_person') {
    $body       = json_decode(file_get_contents('php://input'), true);
    $cluster_id = intval($body['cluster_id'] ?? -1);
    $name       = trim($body['name'] ?? '');
    $notes      = trim($body['notes'] ?? '');
    if ($cluster_id < 0 || $name === '') {
        echo json_encode(['success' => false, 'error' => 'Hiányzó cluster_id vagy name.']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO ff_persons (cluster_id, gallery_id, name, notes, updated_at)
            VALUES (:cid, :gid, :name, :notes, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                name       = VALUES(name),
                notes      = VALUES(notes),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            ':cid'   => $cluster_id,
            ':gid'   => $gallery_id,
            ':name'  => $name,
            ':notes' => $notes ?: null
        ]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Érvénytelen kérés.']);
