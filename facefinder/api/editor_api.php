<?php
// facefinder/api/editor_api.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_check.php';

$action = $_GET['action'] ?? '';

if ($action === 'delete_faces') {
    $data = json_decode(file_get_contents('php://input'), true);
    $face_ids = $data['face_ids'] ?? [];
    if (empty($face_ids) || !file_exists(DB_PATH)) { 
        echo json_encode(['success' => false, 'error' => 'Nincs adat.']); 
        exit; 
    }
    
    try {
        require_once __DIR__ . '/db.php';
        $pdo = Database::getInstance()->getConnection();
        
        $in  = str_repeat('?,', count($face_ids) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM faces WHERE face_id IN ($in)");
        $stmt->execute($face_ids);
        
        echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
    } catch (Exception $e) { 
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); 
    }
    exit;
}

if ($action === 'move_faces') {
    $data           = json_decode(file_get_contents('php://input'), true);
    $face_ids       = $data['face_ids'] ?? [];
    $target_cluster = $data['target_cluster'] ?? -1;
    if (empty($face_ids) || $target_cluster === -1 || !file_exists(DB_PATH)) {
        echo json_encode(['success' => false, 'error' => 'Hibás adatok.']); exit;
    }
    try {
        require_once __DIR__ . '/db.php';
        $pdo = Database::getInstance()->getConnection();
        $in     = str_repeat('?,', count($face_ids) - 1) . '?';
        $stmt   = $pdo->prepare("UPDATE faces SET cluster_id = ? WHERE face_id IN ($in)");
        $params = array_merge([$target_cluster], $face_ids);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'moved' => $stmt->rowCount()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_images') {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 100;
    $offset = ($page - 1) * $limit;
    
    if (!file_exists(DB_PATH)) { 
        echo json_encode(['success' => false, 'error' => 'Adatbázis nem található']); 
        exit; 
    }
    
    try {
        require_once __DIR__ . '/db.php';
        $pdo = Database::getInstance()->getConnection();
        
        $stmt = $pdo->query("SELECT COUNT(DISTINCT video_path) FROM faces");
        $total = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT video_path 
            FROM faces 
            ORDER BY video_path ASC 
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $video_paths = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $images = [];
        if (!empty($video_paths)) {
            $inQuery = str_repeat('?,', count($video_paths) - 1) . '?';
            $faceStmt = $pdo->prepare("
                SELECT face_id, bbox, cluster_id, video_path 
                FROM faces 
                WHERE video_path IN ($inQuery)
            ");
            $faceStmt->execute($video_paths);
            $all_faces = $faceStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $grouped = [];
            foreach ($all_faces as $f) {
                $vp = basename(str_replace('\\', '/', $f['video_path']));
                $bbox = $f['bbox'] ? array_map('floatval', explode(',', $f['bbox'])) : [];
                $grouped[$vp][] = [
                    'face_id' => (int)$f['face_id'],
                    'bbox' => $bbox,
                    'cluster_id' => (int)$f['cluster_id']
                ];
            }
            foreach ($video_paths as $vpFull) {
                $vp = basename(str_replace('\\', '/', $vpFull));
                $images[] = [
                    'file' => $vp,
                    'faces' => $grouped[$vp] ?? []
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'images' => $images,
            'total' => $total,
            'totalPages' => max(1, ceil($total / $limit))
        ]);
    } catch (Exception $e) { 
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); 
    }
    exit;
}


// ── EXPORT CLUSTER FACES -> ZIP a temp/ mappába ─────────────────────────────
if ($action === 'export_cluster') {
    $data       = json_decode(file_get_contents('php://input'), true);
    $cluster_id = (int)($data['cluster_id'] ?? -1);
    if ($cluster_id < 0 || !file_exists(DB_PATH)) {
        echo json_encode(['success' => false, 'error' => 'Érvénytelen klaszter ID.']); exit;
    }
    try {
        require_once __DIR__ . '/db.php';
        $pdo = Database::getInstance()->getConnection();
        // Klaszter neve
        $nameStmt = $pdo->prepare("SELECT name FROM persons WHERE cluster_id = ?");
        $nameStmt->execute([$cluster_id]);
        $name = $nameStmt->fetchColumn() ?: 'cluster_' . $cluster_id;
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);

        // Miniatúrák lekérése
        $stmt = $pdo->prepare("
            SELECT face_id, face_thumb
            FROM faces
            WHERE cluster_id = ? AND face_thumb IS NOT NULL
        ");
        $stmt->execute([$cluster_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo json_encode(['success' => false, 'error' => 'Nincsenek arcképek ebben a klaszterben.']); exit;
        }

        if (!is_dir(TEMP_DIR)) mkdir(TEMP_DIR, 0777, true);
        $zipName  = 'export_' . $safeName . '_' . date('Ymd_His') . '.zip';
        $zipPath  = TEMP_DIR . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            echo json_encode(['success' => false, 'error' => 'ZIP fájl létrehozása sikertelen.']); exit;
        }

        foreach ($rows as $row) {
            $thumb = $row['face_thumb'];
            if (!$thumb) continue;
            // Base64 -> bináris
            if (strpos($thumb, 'data:') === 0) {
                $b64 = preg_replace('/^data:image\/\w+;base64,/', '', $thumb);
            } else {
                $b64 = base64_encode($thumb);
            }
            $binary = base64_decode($b64);
            if ($binary === false) continue;
            $zip->addFromString('face_' . $row['face_id'] . '.webp', $binary);
        }
        $zip->close();

        // Relatív URL-t adunk vissza, hogy a böngésző le tudja tölteni
        $downloadUrl = '../facefinder/temp/' . $zipName;
        echo json_encode(['success' => true, 'url' => $downloadUrl, 'filename' => $zipName, 'count' => count($rows)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── MANUAL TAG: kézzel rajzolt bbox mentése ──────────────────────────────────
if ($action === 'add_manual_face') {
    $data       = json_decode(file_get_contents('php://input'), true);
    $video_path = $data['video_path'] ?? '';
    $bbox       = $data['bbox'] ?? [];
    $cluster_id = (int)($data['cluster_id'] ?? -1);
    if (!$video_path || count($bbox) !== 4 || !file_exists(DB_PATH)) {
        echo json_encode(['success' => false, 'error' => 'Hiányzó adatok.']); exit;
    }
    try {
        require_once __DIR__ . '/db.php';
        $pdo = Database::getInstance()->getConnection();
        $bboxStr = implode(',', array_map('floatval', $bbox));
        $stmt = $pdo->prepare("
            INSERT INTO faces (video_path, media_type, bbox, cluster_id, det_score, quality_score)
            VALUES (?, 'image', ?, ?, 0.99, 1.0)
        ");
        $stmt->execute([$video_path, $bboxStr, $cluster_id]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'face_id' => $newId]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── DELETE MANUAL TAG ─────────────────────────────────────────────────────────
if ($action === 'delete_manual_face') {
    $data    = json_decode(file_get_contents('php://input'), true);
    $face_id = (int)($data['face_id'] ?? 0);
    if (!$face_id || !file_exists(DB_PATH)) {
        echo json_encode(['success' => false, 'error' => 'Hiányzó face_id.']); exit;
    }
    try {
        require_once __DIR__ . '/db.php';
        $pdo = Database::getInstance()->getConnection();
        $pdo->prepare("DELETE FROM faces WHERE face_id = ?")->execute([$face_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── STATS (dashboard statisztikák) ────────────────────────────────────────────
if ($action === 'get_stats') {
    if (!file_exists(DB_PATH)) {
        echo json_encode(['success' => false, 'error' => 'Adatbázis nem található']); exit;
    }
    try {
        require_once __DIR__ . '/db.php';
        $pdo = Database::getInstance()->getConnection();

        $faces    = $pdo->query("SELECT COUNT(*) FROM faces")->fetchColumn();
        $images   = $pdo->query("SELECT COUNT(DISTINCT video_path) FROM faces")->fetchColumn();
        $clusters = $pdo->query("SELECT COUNT(DISTINCT cluster_id) FROM faces WHERE cluster_id != -1")->fetchColumn();
        $persons  = $pdo->query("SELECT COUNT(*) FROM persons WHERE name IS NOT NULL")->fetchColumn();
        $unclust  = $pdo->query("SELECT COUNT(*) FROM faces WHERE cluster_id = -1")->fetchColumn();

        $genderRow = $pdo->query("
            SELECT gender, COUNT(*) as cnt
            FROM faces WHERE gender IS NOT NULL
            GROUP BY gender
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        $avgAge = $pdo->query("SELECT ROUND(AVG(age)) FROM faces WHERE age > 0")->fetchColumn();

        $pendingJobs = file_exists(DB_PATH)
            ? $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'pending'")->fetchColumn()
            : 0;
        $failedJobs = $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'failed'")->fetchColumn();

        echo json_encode([
            'success'      => true,
            'faces'        => (int)$faces,
            'images'       => (int)$images,
            'clusters'     => (int)$clusters,
            'persons'      => (int)$persons,
            'unclustered'  => (int)$unclust,
            'gender'       => $genderRow,
            'avg_age'      => (int)$avgAge,
            'pending_jobs' => (int)$pendingJobs,
            'failed_jobs'  => (int)$failedJobs,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ismeretlen editor művelet.']);
