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
    return @file_get_contents($url, false, $context);
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
$dbPath = DB_PATH;

if ($action === 'get_clusters') {
    if (!file_exists($dbPath)) {
        echo json_encode(['success' => false, 'error' => 'Az adatbázis nem létezik.']);
        exit;
    }
    try {
        $pdo = Database::getInstance()->getConnection();
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
        'score_asc'  => 'det_score ASC NULLS LAST',
        'score_desc' => 'det_score DESC NULLS LAST',
        'name_asc'   => 'video_path ASC',
        default      => 'face_id DESC',
    };

    $minScore  = isset($_GET['min_score']) ? (float)$_GET['min_score'] : 0.0;
    $filterStr = trim($_GET['filter'] ?? '');

    if ($cid == -1 && !file_exists($dbPath)) {
        echo json_encode(['success' => false, 'error' => 'Érvénytelen azonosító.']);
        exit;
    }

    try {
        $pdo = Database::getInstance()->getConnection();

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

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM faces $whereSQL");
        $countStmt->execute($whereParams);
        $totalCount = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalCount / $limit));

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
                ? ('api/gallery.php?action=get_thumb&face_id=' . $row['face_id'])
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
        $pdo = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare("SELECT face_thumb FROM faces WHERE face_id = ?");
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
    if (!file_exists($dbPath)) {
        echo json_encode(['success' => true, 'persons' => []]);
        exit;
    }
    try {
        $pdo = Database::getInstance()->getConnection();
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
        $pdo = Database::getInstance()->getConnection();
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
    $result = proxy_request('/api/cluster', []);
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
    if (!file_exists($dbPath)) {
        echo json_encode(['success' => false, 'error' => 'Adatbázis nem létezik.']);
        exit;
    }
    try {
        $pdo = Database::getInstance()->getConnection();
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
    $destPath = TEMP_DIR . $filename;

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $result = proxy_request('/api/search', ['path' => $destPath]);
        
        $uploadedDir = BASE_DIR . '/uploaded/';
        if (!is_dir($uploadedDir)) @mkdir($uploadedDir, 0750, true);
        $archivePath = $uploadedDir . basename($destPath);
        @rename($destPath, $archivePath);

        echo $result ?: json_encode(['success' => false, 'error' => 'Nem érkezett válasz az API-tól.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Hiba a fájl mozgatásakor.']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Érvénytelen kérés.']);
