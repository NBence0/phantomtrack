<?php
// phantomtrack/log.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action'])) { 
    echo json_encode(['status'=>'error', 'message'=>'Invalid input']); exit; 
}

$action = $input['action'];
$data = $input['data'] ?? [];
$category = $input['category'] ?? 'general';

// Ha nincs kategória, kitaláljuk
if ($category === 'general') {
    if (strpos($action, 'copy') !== false) $category = 'interaction';
    elseif (strpos($action, 'page_exit') !== false) $category = 'analytics';
}

// Extract galleryId
$galleryId = $input['gallery_id'] ?? null;

if (!$galleryId) {
    echo json_encode(['status'=>'error', 'message'=>'No gallery ID provided']); exit; 
}

Logger::log($action, $data, $category, (int)$galleryId);
echo json_encode(['status' => 'success']);
?>
