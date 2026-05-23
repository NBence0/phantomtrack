<?php
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

$rootDir = dirname(__DIR__, 2);
require_once $rootDir . '/config.php';

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

// Ellenőrizzük a jogosultságot, és szerezzük meg a galériát
// Itt GET-ből vagy JSON body-ból is jöhet a gallery_id
$gallery_id = isset($_GET['gallery_id']) ? (int)$_GET['gallery_id'] : 0;
if ($gallery_id <= 0) {
    $inputData = json_decode(file_get_contents('php://input'), true);
    if (isset($inputData['gallery_id'])) {
        $gallery_id = (int)$inputData['gallery_id'];
    }
}

if ($gallery_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Hiányzó gallery_id.']);
    exit;
}

$stmt = $pdo->prepare("SELECT user_id FROM galleries WHERE id = ?");
$stmt->execute([$gallery_id]);
$gallery = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gallery) {
    echo json_encode(['success' => false, 'error' => 'Galéria nem található.']);
    exit;
}

require_once $rootDir . '/includes/auth.php';
$is_admin = isAdmin();
if (!$is_admin && $gallery['user_id'] != $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'error' => 'Nincs hozzáférés ehhez a galériához.']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'delete_faces') {
    $data = json_decode(file_get_contents('php://input'), true);
    $face_ids = $data['face_ids'] ?? [];
    if (empty($face_ids)) { 
        echo json_encode(['success' => false, 'error' => 'Nincs adat.']); 
        exit; 
    }
    try {
        $in  = str_repeat('?,', count($face_ids) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM ff_faces WHERE gallery_id = ? AND face_id IN ($in)");
        $params = array_merge([$gallery_id], $face_ids);
        $stmt->execute($params);
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
    if (empty($face_ids) || $target_cluster === -1) {
        echo json_encode(['success' => false, 'error' => 'Hibás adatok.']); exit;
    }
    try {
        $in     = str_repeat('?,', count($face_ids) - 1) . '?';
        $stmt   = $pdo->prepare("UPDATE ff_faces SET cluster_id = ? WHERE gallery_id = ? AND face_id IN ($in)");
        $params = array_merge([$target_cluster, $gallery_id], $face_ids);
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
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT video_path) FROM ff_faces WHERE gallery_id = ?");
        $stmt->execute([$gallery_id]);
        $total = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT video_path 
            FROM ff_faces 
            WHERE gallery_id = ?
            ORDER BY video_path ASC 
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $gallery_id);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $video_paths = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $images = [];
        if (!empty($video_paths)) {
            $inQuery = str_repeat('?,', count($video_paths) - 1) . '?';
            $faceStmt = $pdo->prepare("
                SELECT face_id, bbox, cluster_id, video_path 
                FROM ff_faces 
                WHERE gallery_id = ? AND video_path IN ($inQuery)
            ");
            $faceParams = array_merge([$gallery_id], $video_paths);
            $faceStmt->execute($faceParams);
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

if ($action === 'export_cluster') {
    $data       = json_decode(file_get_contents('php://input'), true);
    $cluster_id = (int)($data['cluster_id'] ?? -1);
    if ($cluster_id < 0) {
        echo json_encode(['success' => false, 'error' => 'Érvénytelen klaszter ID.']); exit;
    }
    try {
        $nameStmt = $pdo->prepare("SELECT name FROM ff_persons WHERE cluster_id = ? AND gallery_id = ?");
        $nameStmt->execute([$cluster_id, $gallery_id]);
        $name = $nameStmt->fetchColumn() ?: 'cluster_' . $cluster_id;
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);

        $stmt = $pdo->prepare("
            SELECT face_id, face_thumb
            FROM ff_faces
            WHERE cluster_id = ? AND gallery_id = ? AND face_thumb IS NOT NULL
        ");
        $stmt->execute([$cluster_id, $gallery_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            echo json_encode(['success' => false, 'error' => 'Nincsenek arcképek ebben a klaszterben.']); exit;
        }

        $TEMP_DIR = dirname(__DIR__) . '/temp/';
        if (!is_dir($TEMP_DIR)) mkdir($TEMP_DIR, 0777, true);
        $zipName  = 'export_' . $safeName . '_' . date('Ymd_His') . '.zip';
        $zipPath  = $TEMP_DIR . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            echo json_encode(['success' => false, 'error' => 'ZIP fájl létrehozása sikertelen.']); exit;
        }

        foreach ($rows as $row) {
            $thumb = $row['face_thumb'];
            if (!$thumb) continue;
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

        $downloadUrl = '../facefinder/temp/' . $zipName;
        echo json_encode(['success' => true, 'url' => $downloadUrl, 'filename' => $zipName, 'count' => count($rows)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'add_manual_face') {
    $data       = json_decode(file_get_contents('php://input'), true);
    $video_path = $data['video_path'] ?? '';
    $bbox       = $data['bbox'] ?? [];
    $cluster_id = (int)($data['cluster_id'] ?? -1);
    if (!$video_path || count($bbox) !== 4) {
        echo json_encode(['success' => false, 'error' => 'Hiányzó adatok.']); exit;
    }
    try {
        $bboxStr = implode(',', array_map('floatval', $bbox));
        $stmt = $pdo->prepare("
            INSERT INTO ff_faces (gallery_id, video_path, media_type, bbox, cluster_id, det_score, quality_score)
            VALUES (?, ?, 'image', ?, ?, 0.99, 1.0)
        ");
        $stmt->execute([$gallery_id, $video_path, $bboxStr, $cluster_id]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'face_id' => $newId]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_manual_face') {
    $data    = json_decode(file_get_contents('php://input'), true);
    $face_id = (int)($data['face_id'] ?? 0);
    if (!$face_id) {
        echo json_encode(['success' => false, 'error' => 'Hiányzó face_id.']); exit;
    }
    try {
        $pdo->prepare("DELETE FROM ff_faces WHERE face_id = ? AND gallery_id = ?")->execute([$face_id, $gallery_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_stats') {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ff_faces WHERE gallery_id = ?");
        $stmt->execute([$gallery_id]);
        $faces = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT video_path) FROM ff_faces WHERE gallery_id = ?");
        $stmt->execute([$gallery_id]);
        $images = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT cluster_id) FROM ff_faces WHERE gallery_id = ? AND cluster_id != -1");
        $stmt->execute([$gallery_id]);
        $clusters = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ff_persons WHERE gallery_id = ? AND name IS NOT NULL");
        $stmt->execute([$gallery_id]);
        $persons = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ff_faces WHERE gallery_id = ? AND cluster_id = -1");
        $stmt->execute([$gallery_id]);
        $unclust = $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT gender, COUNT(*) as cnt
            FROM ff_faces WHERE gallery_id = ? AND gender IS NOT NULL
            GROUP BY gender
        ");
        $stmt->execute([$gallery_id]);
        $genderRow = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $pdo->prepare("SELECT ROUND(AVG(age)) FROM ff_faces WHERE gallery_id = ? AND age > 0");
        $stmt->execute([$gallery_id]);
        $avgAge = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ff_jobs WHERE gallery_id = ? AND status = 'pending'");
        $stmt->execute([$gallery_id]);
        $pendingJobs = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ff_jobs WHERE gallery_id = ? AND status = 'failed'");
        $stmt->execute([$gallery_id]);
        $failedJobs = $stmt->fetchColumn();

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

if ($action === 'scan_gallery') {
    $uploadsDir = $rootDir . '/uploads/';

    try {
        $stmt = $pdo->prepare(
            "SELECT stored_filename FROM files WHERE gallery_id = ? AND mime_type LIKE 'image/%' AND is_deleted = 0"
        );
        $stmt->execute([$gallery_id]);
        $storedFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($storedFiles)) {
            echo json_encode(['success' => false, 'error' => 'Nincsenek képek ebben a galériában.']);
            exit;
        }

        $doneFiles = [];
        $doneStmt = $pdo->prepare("SELECT file_path FROM ff_jobs WHERE gallery_id = ?");
        $doneStmt->execute([$gallery_id]);
        foreach ($doneStmt->fetchAll(PDO::FETCH_COLUMN) as $fp) {
            $doneFiles[basename($fp)] = true;
        }

        $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        $fastApiPort = $isWin ? 8005 : 8000;
        $fastApiUrl = "http://127.0.0.1:$fastApiPort";

        $count = 0;
        $skipped = 0;

        $insertStmt = $pdo->prepare("INSERT IGNORE INTO ff_jobs (gallery_id, file_path, status) VALUES (?, ?, 'pending')");

        foreach ($storedFiles as $storedName) {
            if (isset($doneFiles[(string)$storedName])) {
                $skipped++;
                continue;
            }

            $filePath = $uploadsDir . $storedName;
            if (!file_exists($filePath)) {
                $skipped++;
                continue;
            }

            $realPath = realpath($filePath) ?: $filePath;
            $insertStmt->execute([$gallery_id, $realPath]);
            $count++;

            $data = ['path' => $realPath, 'gallery_id' => $gallery_id];
            $options = [
                'http' => [
                    'header'  => "Content-type: application/json\r\n",
                    'method'  => 'POST',
                    'content' => json_encode($data),
                    'timeout' => 1,
                    'ignore_errors' => true,
                ]
            ];
            $context = stream_context_create($options);
            @file_get_contents($fastApiUrl . '/api/queue', false, $context);
        }

        echo json_encode([
            'success' => true,
            'count'   => $count,
            'skipped' => $skipped,
            'message' => "$count kép beolvasva a sorba, $skipped kép kihagyva (már feldolgozott vagy nem létező)."
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}


if ($action === 'export_pending_jobs') {
    // Lekérdezzük az összes függőben lévő feladatot a galériából
    try {
        $stmt = $pdo->prepare("SELECT file_path FROM ff_jobs WHERE gallery_id = ? AND status = 'pending'");
        $stmt->execute([$gallery_id]);
        $pendingFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($pendingFiles)) {
            echo json_encode(['success' => false, 'error' => 'Nincsenek függőben lévő képek.']);
            exit;
        }

        // Állítsuk le a JSON headert és indítsunk ZIP letöltést
        // Ehhez a header-t MOST kell megváltoztatni, mielőtt bármi output megy
        header('Content-Type: application/zip');
        $zipFilename = 'pending_export_gallery' . $gallery_id . '_' . date('Ymd_His') . '.zip';
        header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
        header('Pragma: public');

        // Ideiglenes ZIP fájl létrehozása
        $TEMP_DIR = dirname(__DIR__) . '/temp/';
        if (!is_dir($TEMP_DIR)) mkdir($TEMP_DIR, 0777, true);
        $tmpZipPath = $TEMP_DIR . 'export_' . uniqid() . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($tmpZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'ZIP fájl létrehozása sikertelen.']);
            exit;
        }

        $addedCount = 0;
        foreach ($pendingFiles as $filePath) {
            // Ellenőrizzük, hogy a fájl létezik-e
            $realPath = realpath($filePath);
            if (!$realPath || !file_exists($realPath)) continue;

            // Belső ZIP struktúra: gallery_ID/fájlnév
            $entryName = 'gallery_' . $gallery_id . '/' . basename($realPath);
            $zip->addFile($realPath, $entryName);
            $addedCount++;
        }
        $zip->close();

        if ($addedCount === 0) {
            @unlink($tmpZipPath);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'A függőben lévő képfájlok egyike sem található a szerveren.']);
            exit;
        }

        // ZIP letöltés streamelése
        header('Content-Length: ' . filesize($tmpZipPath));
        readfile($tmpZipPath);
        @unlink($tmpZipPath); // Töröljük az ideiglenes fájlt a letöltés után
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'import_offline_results') {
    // A php://input-ból kiolvassuk a JSON payloadot
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'error' => 'Érvénytelen JSON formátum.']);
        exit;
    }

    $imported = 0;
    $errors = [];

    // Fájlok listája a státusz frissítéséhez
    $processedFiles = [];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO ff_faces (
                gallery_id, video_path, media_type, bbox, face_thumb, 
                age, gender, det_score, quality_score, pitch, yaw, roll, 
                kps, emb_antelope, emb_adaface, emb_vit
            ) VALUES (
                ?, ?, 'image', ?, ?, 
                ?, ?, ?, ?, ?, ?, ?, 
                ?, ?, ?, ?
            )
        ");

        foreach ($data as $face) {
            $filename = $face['filename'] ?? '';
            if (!$filename) continue;

            $videoPath = dirname(__DIR__, 2) . '/uploads/' . $filename;
            
            // Konvertáljuk a base64 mezőket binárissá, ha vannak
            $emb_antelope = !empty($face['emb_antelope']) ? base64_decode($face['emb_antelope']) : null;
            $emb_adaface  = !empty($face['emb_adaface'])  ? base64_decode($face['emb_adaface'])  : null;
            $emb_vit      = !empty($face['emb_vit'])      ? base64_decode($face['emb_vit'])      : null;

            $stmt->execute([
                $gallery_id,
                $videoPath,
                $face['bbox'] ?? null,
                $face['face_thumb'] ?? null,
                $face['age'] ?? null,
                $face['gender'] ?? null,
                $face['det_score'] ?? null,
                $face['quality_score'] ?? null,
                $face['pitch'] ?? null,
                $face['yaw'] ?? null,
                $face['roll'] ?? null,
                $face['kps'] ?? null,
                $emb_antelope,
                $emb_adaface,
                $emb_vit
            ]);
            
            $imported++;
            $processedFiles[$videoPath] = true;
        }

        // Frissítjük a jobokat "done" státuszra
        if (!empty($processedFiles)) {
            $paths = array_keys($processedFiles);
            $inQuery = str_repeat('?,', count($paths) - 1) . '?';
            $updateStmt = $pdo->prepare("UPDATE ff_jobs SET status = 'done' WHERE gallery_id = ? AND file_path IN ($inQuery)");
            $params = array_merge([$gallery_id], $paths);
            $updateStmt->execute($params);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'imported' => $imported]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ismeretlen editor művelet.']);
