<?php
// facefinder/api/gallery.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Proxy helper for FastAPI
function proxy_request(string $endpoint, array $data, string $method = 'POST') {
    $url     = FASTAPI_URL . $endpoint;
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
    $result = @file_get_contents($url, false, $context);
    // Capture HTTP status code if available
    $status = null;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#HTTP/\d+\.\d+ (\d+)#', $h, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }
    if ($result === false) {
        // Return JSON error with status code (may be null)
        return json_encode(['success' => false, 'error' => 'FastAPI request failed', 'status' => $status]);
    }
    return $result;
}

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

$action = $_GET['action'] ?? '';
$token = $_GET['token'] ?? '';
$gallery_id = 0;

if ($token) {
    try {
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT id FROM galleries WHERE view_token = ?");
        $stmt->execute([$token]);
        $gallery_id = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        // ignore, gallery_id remains 0
    }
}
if (!$gallery_id && isset($_GET['gallery_id'])) {
    $gallery_id = (int)$_GET['gallery_id']; // fallback
}

if ($action === 'get_clusters') {
    try {
        $pdo = Database::getInstance()->getConnection();
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
    $orderBy   = match($sortParam) {
        'score_asc'  => 'f.det_score ASC',
        'score_desc' => 'f.det_score DESC',
        'name_asc'   => 'fi.original_filename ASC',
        default      => 'f.face_id DESC',
    };

    $minScore  = isset($_GET['min_score']) ? (float)$_GET['min_score'] : 0.0;
    $filterStr = trim($_GET['filter'] ?? '');

    try {
        $pdo = Database::getInstance()->getConnection();

        $whereParts  = ['f.gallery_id = ?'];
        $whereParams = [$gallery_id];

        if ($cid !== -2) {
            $whereParts[]  = 'f.cluster_id = ?';
            $whereParams[] = $cid;
        }
        if ($minScore > 0.0) {
            $whereParts[]  = '(f.det_score IS NOT NULL AND f.det_score >= ?)';
            $whereParams[] = $minScore;
        }
        if ($filterStr !== '') {
            $whereParts[]  = '(fi.original_filename LIKE ? OR f.video_path LIKE ?)';
            $whereParams[] = '%' . $filterStr . '%';
            $whereParams[] = '%' . $filterStr . '%';
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $whereParts);

        $countStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM ff_faces f
            LEFT JOIN files fi ON fi.stored_filename = SUBSTRING_INDEX(f.video_path, '/', -1) AND fi.gallery_id = f.gallery_id
            $whereSQL
        ");
        $countStmt->execute($whereParams);
        $totalCount = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalCount / $limit));

        $stmt = $pdo->prepare("
            SELECT f.face_id, f.video_path, f.bbox, f.face_thumb, f.age, f.gender, f.det_score, f.pitch, f.yaw, f.roll, fi.original_filename, fi.view_token
            FROM ff_faces f
            LEFT JOIN files fi ON fi.stored_filename = SUBSTRING_INDEX(f.video_path, '/', -1) AND fi.gallery_id = f.gallery_id
            $whereSQL
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        ");
        
        $paramIdx = 1;
        foreach ($whereParams as $val) {
            $type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($paramIdx++, $val, $type);
        }
        
        $stmt->bindValue($paramIdx++, (int)$limit, PDO::PARAM_INT);
        $stmt->bindValue($paramIdx, (int)$offset, PDO::PARAM_INT);
        $stmt->execute();

        $faces = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bbox     = $row['bbox'] ? array_map('floatval', explode(',', $row['bbox'])) : [];
            $thumbUrl = $row['face_thumb']
                ? ('api/gallery.php?action=get_thumb&face_id=' . $row['face_id'])
                : null;

            $faces[] = [
                'face_id'   => (int)$row['face_id'],
                'file_path' => $row['original_filename'] ?: basename(str_replace('\\', '/', $row['video_path'])),
                'img_token' => $row['view_token'] ?? null,
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
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT face_thumb FROM ff_faces WHERE face_id = ?");
        $stmt->execute([$face_id]);
        $data = $stmt->fetchColumn();
        if ($data) {
            if (strpos($data, 'data:image/webp;base64,') === 0) {
                $data = base64_decode(substr($data, 23));
            }
            header('Content-Type: image/webp');
            header('Cache-Control: public, max-age=86400');
            echo $data;
            exit;
        }
    }
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    exit;
}

if ($action === 'get_persons') {
    try {
        $pdo = Database::getInstance()->getConnection();
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
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("
            SELECT f.face_id, f.bbox, f.cluster_id, p.name, f.face_thumb, f.age, f.gender, f.det_score,
                   f.pitch, f.yaw, f.roll, f.kps
            FROM ff_faces f
            LEFT JOIN ff_persons p ON f.cluster_id = p.cluster_id AND f.gallery_id = p.gallery_id
            LEFT JOIN files fi ON fi.stored_filename = SUBSTRING_INDEX(f.video_path, '/', -1) AND fi.gallery_id = f.gallery_id
            WHERE (fi.original_filename LIKE ? OR f.video_path LIKE ?) AND f.gallery_id = ?
        ");
        $stmt->execute(['%' . $filename, '%' . $filename, $gallery_id]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
            $bbox = $row[1] ? array_map('floatval', explode(',', $row[1])) : [];
            $thumb = $row[4];
            if ($thumb && strpos($thumb, 'data:') !== 0 && !file_exists(BASE_DIR . '/face_thumbs/' . $thumb)) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cluster') {
    // Ellenőrizzük, fut-e a démon
    $fastPort = 8000;
    $isRunning = trim(shell_exec("ps aux | grep '[u]vicorn' | grep '$fastPort' | awk '{print \$2}'"));
    if (!$isRunning) {
        // Démon nem fut – próbáljuk automatikusan elindítani PYTHONPATH-tal
        $ffDir   = dirname(__DIR__);
        $logFile = $ffDir . '/temp/fastapi.log';
        $startCmd = "export PYTHONPATH=" . $ffDir . "/pylib;"
                  . " cd " . escapeshellarg($ffDir) . " &&"
                  . " nohup /usr/bin/python3 -m uvicorn backend.main:app"
                  . " --host 127.0.0.1 --port $fastPort"
                  . " > " . escapeshellarg($logFile) . " 2>&1 &";
        exec($startCmd);
        sleep(2);
        $isRunning = trim(shell_exec("ps aux | grep '[u]vicorn' | grep '$fastPort' | awk '{print \$2}'"));
        if (!$isRunning) {
            $lastLog = file_exists($logFile)
                ? trim(shell_exec("tail -n 5 " . escapeshellarg($logFile)))
                : 'Nincs log fájl.';
            echo json_encode([
                'success' => false,
                'error'   => 'A FastAPI démon nem fut és nem sikerült elindítani.',
                'log'     => $lastLog,
                'hint'    => 'Ellenőrizd, hogy a pylib/ mappában megvannak-e a szükséges csomagok: pip install fastapi uvicorn -t pylib/'
            ]);
            exit;
        }
    }
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
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO ff_persons (gallery_id, cluster_id, name, notes, updated_at)
            VALUES (:gid, :cid, :name, :notes, NOW())
            ON DUPLICATE KEY UPDATE
                name       = VALUES(name),
                notes      = VALUES(notes),
                updated_at = VALUES(updated_at)
        ");
        $stmt->execute([
            ':gid'   => $gallery_id,
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

echo json_encode(['success' => false, 'error' => 'Érvénytelen kérés.']);
