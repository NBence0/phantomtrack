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
// Itt GET-ből vagy JSON body-ból is jöhet a token vagy gallery_id
$gallery_id = isset($_GET['gallery_id']) ? (int)$_GET['gallery_id'] : 0;
$token = isset($_GET['token']) ? $_GET['token'] : '';

$inputData = json_decode(file_get_contents('php://input'), true);
if ($inputData) {
    if (isset($inputData['gallery_id'])) $gallery_id = (int)$inputData['gallery_id'];
    if (isset($inputData['token'])) $token = $inputData['token'];
}

if ($token) {
    $stmtToken = $pdo->prepare("SELECT id FROM galleries WHERE view_token = ?");
    $stmtToken->execute([$token]);
    $resolved_id = $stmtToken->fetchColumn();
    if ($resolved_id) $gallery_id = (int)$resolved_id;
}

$action = $_GET['action'] ?? '';

// Globális műveletek, amikhez nem kell gallery_id
$globalActions = ['export_global_pending', 'import_offline_results', 'clean_all_clusters', 'get_system_stats', 'cleanup'];

if (!in_array($action, $globalActions)) {
    if ($gallery_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Hiányzó gallery_id vagy token.']);
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
} else {
    // Globális műveleteknél csak admin férhet hozzá!
    require_once $rootDir . '/includes/auth.php';
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'error' => 'Nincs globális hozzáférés.']);
        exit;
    }
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
        // Count distinct images using stored filename join
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT fi.id)
            FROM ff_faces f
            LEFT JOIN files fi ON fi.stored_filename = SUBSTRING_INDEX(REPLACE(f.video_path, '\\\\', '/'), '/', -1) AND fi.gallery_id = f.gallery_id
            WHERE f.gallery_id = ?
        ");
        $stmt->execute([$gallery_id]);
        $total = (int)$stmt->fetchColumn();

        // Get paginated distinct images with original filenames
        $stmt = $pdo->prepare("
            SELECT DISTINCT f.video_path, fi.original_filename
            FROM ff_faces f
            LEFT JOIN files fi ON fi.stored_filename = SUBSTRING_INDEX(REPLACE(f.video_path, '\\\\', '/'), '/', -1) AND fi.gallery_id = f.gallery_id
            WHERE f.gallery_id = ?
            ORDER BY COALESCE(fi.original_filename, f.video_path) ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $gallery_id);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $path_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $video_paths = array_column($path_rows, 'video_path');
        $filename_map = [];
        foreach ($path_rows as $r) {
            $stored = basename(str_replace('\\', '/', $r['video_path']));
            $filename_map[$stored] = $r['original_filename'] ?: $stored;
        }

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
                $stored = basename(str_replace('\\', '/', $f['video_path']));
                $bbox = $f['bbox'] ? array_map('floatval', explode(',', $f['bbox'])) : [];
                $grouped[$stored][] = [
                    'face_id'    => (int)$f['face_id'],
                    'bbox'       => $bbox,
                    'cluster_id' => (int)$f['cluster_id']
                ];
            }
            foreach ($video_paths as $vpFull) {
                $stored = basename(str_replace('\\', '/', $vpFull));
                $displayName = $filename_map[$stored] ?? $stored;
                $images[] = [
                    'file'        => $displayName,
                    'stored_file' => $stored,
                    'faces'       => $grouped[$stored] ?? []
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

if ($action === 'update_bbox') {
    $data    = json_decode(file_get_contents('php://input'), true);
    $face_id = (int)($data['face_id'] ?? 0);
    $bbox    = $data['bbox'] ?? [];
    if (!$face_id || count($bbox) !== 4) {
        echo json_encode(['success' => false, 'error' => 'Hiányzó face_id vagy érvénytelen bbox.']); exit;
    }
    try {
        $bboxStr = implode(',', array_map('floatval', $bbox));
        $stmt = $pdo->prepare("UPDATE ff_faces SET bbox = ? WHERE face_id = ? AND gallery_id = ?");
        $stmt->execute([$bboxStr, $face_id, $gallery_id]);
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
        // Minden képfájl lekérése a galériából
        $stmt = $pdo->prepare(
            "SELECT stored_filename FROM files WHERE gallery_id = ? AND mime_type LIKE 'image/%' AND is_deleted = 0"
        );
        $stmt->execute([$gallery_id]);
        $storedFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($storedFiles)) {
            echo json_encode(['success' => false, 'error' => 'Nincsenek képek ebben a galériában.']);
            exit;
        }

        // Már 'done' státuszú jobok — ezeket kihagyjuk
        $doneFiles = [];
        $doneStmt = $pdo->prepare("SELECT file_path FROM ff_jobs WHERE gallery_id = ? AND status = 'done'");
        $doneStmt->execute([$gallery_id]);
        foreach ($doneStmt->fetchAll(PDO::FETCH_COLUMN) as $fp) {
            $doneFiles[basename($fp)] = true;
        }

        $isWin       = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        $fastApiUrl  = "http://127.0.0.1:" . ($isWin ? 8005 : 8000);
        $count       = 0;
        $skipped     = 0;

        // INSERT IGNORE: ha már van ugyanolyan file_path a táblában, nem dob hibát
        $insertStmt = $pdo->prepare(
            "INSERT INTO ff_jobs (gallery_id, file_path, status) VALUES (?, ?, 'pending')
             ON DUPLICATE KEY UPDATE status = IF(status = 'done', 'done', 'pending'), retry_count = 0"
        );

        foreach ($storedFiles as $storedName) {
            // Kihagyjuk a már sikeresen feldolgozottakat
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

            // Opcionálisan értesítjük a FastAPI daemant (ha fut)
            $data = ['path' => $realPath, 'gallery_id' => $gallery_id];
            $ctx  = stream_context_create(['http' => [
                'header'       => "Content-type: application/json\r\n",
                'method'       => 'POST',
                'content'      => json_encode($data),
                'timeout'      => 1,
                'ignore_errors'=> true,
            ]]);
            @file_get_contents($fastApiUrl . '/api/queue', false, $ctx);
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

if ($action === 'export_global_pending') {
    require_once __DIR__ . '/auth_check.php';
    try {
        // Lekérdezzük az összes függőben lévő feladatot minden galériából
        $stmt = $pdo->query("
            SELECT j.gallery_id, j.file_path, f.original_filename
            FROM ff_jobs j
            LEFT JOIN files f ON f.stored_filename = SUBSTRING_INDEX(j.file_path, '/', -1) AND f.gallery_id = j.gallery_id
            WHERE j.status = 'pending'
        ");
        $pendingFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($pendingFiles)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Nincsenek függőben lévő képek a teljes rendszerben.']);
            exit;
        }

        $exportData = [];
        foreach ($pendingFiles as $row) {
            $exportData[] = [
                'gallery_id' => $row['gallery_id'],
                'stored_filename' => basename($row['file_path']),
                'original_filename' => $row['original_filename'] ?: basename($row['file_path'])
            ];
        }

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="pending.json"');
        echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
            
            // Ha a gallery_id 0 (globális import), megkeressük a fájl alapján a jobs táblából
            $current_gallery_id = $gallery_id;
            if ($current_gallery_id <= 0) {
                // A file_path végződik a filename-re
                $gStmt = $pdo->prepare("SELECT gallery_id FROM ff_jobs WHERE file_path LIKE ? LIMIT 1");
                $gStmt->execute(['%/' . $filename]);
                $found_id = $gStmt->fetchColumn();
                if ($found_id) {
                    $current_gallery_id = (int)$found_id;
                } else {
                    continue; // Nem találtuk meg a galériát ehhez a fájlhoz
                }
            }
            
            // Konvertáljuk a base64 mezőket binárissá, ha vannak
            $emb_antelope = !empty($face['emb_antelope']) ? base64_decode($face['emb_antelope']) : null;
            $emb_adaface  = !empty($face['emb_adaface'])  ? base64_decode($face['emb_adaface'])  : null;
            $emb_vit      = !empty($face['emb_vit'])      ? base64_decode($face['emb_vit'])      : null;

            $stmt->execute([
                $current_gallery_id,
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
            $processedFiles[$current_gallery_id][] = $videoPath;
        }

        // Frissítjük a jobokat "done" státuszra (galériánként csoportosítva)
        if (!empty($processedFiles)) {
            foreach ($processedFiles as $g_id => $paths) {
                $inQuery = str_repeat('?,', count($paths) - 1) . '?';
                $updateStmt = $pdo->prepare("UPDATE ff_jobs SET status = 'done' WHERE gallery_id = ? AND file_path IN ($inQuery)");
                $params = array_merge([$g_id], $paths);
                $updateStmt->execute($params);
            }
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

if ($action === 'get_system_stats') {
    // Globális statisztika a munkákról
    try {
        $stmt = $pdo->query("
            SELECT status, COUNT(*) as cnt 
            FROM ff_jobs 
            GROUP BY status
        ");
        $stats = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['status']] = (int)$row['cnt'];
        }
        
        $pylog = [];
        $logFile = dirname(__DIR__) . '/temp/fastapi.log';
        if (file_exists($logFile)) {
            $pylog = explode("\n", trim(shell_exec("tail -n 20 " . escapeshellarg($logFile))));
        }

        echo json_encode(['success' => true, 'stats' => $stats, 'pylog' => $pylog]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'clean_all_clusters') {
    require_once __DIR__ . '/auth_check.php';
    try {
        $pdo->exec("TRUNCATE TABLE ff_clusters");
        $pdo->exec("UPDATE ff_faces SET cluster_id = -1, is_manually_assigned = 0");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'cleanup') {
    require_once __DIR__ . '/auth_check.php';
    try {
        // Töröljük a halott jobokat vagy resetelünk
        $pdo->exec("UPDATE ff_jobs SET status = 'pending', retry_count = 0 WHERE status = 'failed'");
        $pdo->exec("UPDATE ff_jobs SET status = 'pending' WHERE status = 'processing'");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ismeretlen editor művelet.']);
