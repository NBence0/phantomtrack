<?php
/**
 * QR Kód Generáló - Fájl linkek QR kódjainak létrehozása
 * Támogatja a phpqrcode könyvtárat, online API-t és informatív SVG fallbacket
 */

// Inicializáció és hibakezelés
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Biztonsági fejlécek
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

/**
 * Informatív SVG fallback QR kód generálási hiba esetére
 */
function generateErrorQRPlaceholder($data, $size = 200) {
    $escaped_data = htmlspecialchars($data);
    // Próbáljuk meg az adatot több sorba tördelni, ha túl hosszú
    $max_chars_per_line = floor(($size - 40) / 6); // Hozzávetőleges karakterszám egy sorban (6px/char)
    if ($max_chars_per_line <=0) $max_chars_per_line = 10;
    
    $wrapped_data = wordwrap($escaped_data, $max_chars_per_line, "\n", true);
    $data_lines = explode("\n", $wrapped_data);
    
    $text_elements = '';
    $line_height = 12;
    $max_lines_to_display = 5; // Maximum ennyi sort jelenítünk meg, hogy ne legyen túl zsúfolt
    $lines_to_render = array_slice($data_lines, 0, $max_lines_to_display);

    $current_y = $size / 2 - (count($lines_to_render) * $line_height / 2) + $line_height; // Középre igazítás + eltolás

    foreach ($lines_to_render as $index => $line) {
        $text_elements .= '<text x="' . ($size / 2) . '" y="' . ($current_y + $index * $line_height) . '" text-anchor="middle" font-family="Arial, Sans-serif" font-size="10" fill="#333">' . htmlspecialchars($line) . '</text>';
    }
    if(count($data_lines) > $max_lines_to_display){
         $text_elements .= '<text x="' . ($size / 2) . '" y="' . ($current_y + count($lines_to_render) * $line_height) . '" text-anchor="middle" font-family="Arial, Sans-serif" font-size="9" fill="#555">...</text>';
    }

    $svg = '<?xml version="1.0" encoding="UTF-8"?>
    <svg width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" xmlns="http://www.w3.org/2000/svg">
        <rect width="100%" height="100%" fill="#f0f0f0"/>
        <rect x="5" y="5" width="' . ($size - 10) . '" height="' . ($size - 10) . '" fill="none" stroke="#ccc" stroke-width="1"/>
        <text x="' . ($size / 2) . '" y="30" text-anchor="middle" font-family="Arial, Sans-serif" font-size="16" fill="#d9534f">QR Hiba</text>
        <text x="' . ($size / 2) . '" y="50" text-anchor="middle" font-family="Arial, Sans-serif" font-size="10" fill="#555">A kód nem generálható.</text>
        <text x="' . ($size / 2) . '" y="' . ($current_y - $line_height). '" text-anchor="middle" font-family="Arial, Sans-serif" font-size="10" fill="#555" style="font-weight:bold;">Adat:</text>
        ' . $text_elements . '
    </svg>';
    
    return $svg;
}

/**
 * Phpqrcode könyvtár használata (ha elérhető)
 */
function generateQRCodeWithPhpqrcode($data, $size = 200) { // Renamed for clarity
    $qrlib_path = __DIR__ . '/phpqrcode/qrlib.php';
    
    if (file_exists($qrlib_path)) {
        try {
            include_once $qrlib_path; // Használj require_once-t, ha kritikus a megléte
            
            if (class_exists('QRcode')) {
                ob_start();
                // A QRcode::png() alapból kimenetre ír.
                // A második paraméter (outfile) false, ami jó, mert a kimeneti pufferbe ír.
                // Méret (4. paraméter): 1-10 javasolt. $size itt nem közvetlenül adható át.
                // A 8-as érték jó minőséget ad. A margó (5. paraméter) 2.
                QRcode::png($data, false, QR_ECLEVEL_M, 6, 2); // Kisebb pixel méret, hogy a $size jobban érvényesüljön az img tagnél
                $imageString = ob_get_contents();
                ob_end_clean();
                
                if ($imageString) {
                    return ['content_type' => 'image/png', 'data' => $imageString];
                }
            }
        } catch (Exception $e) {
            error_log("QR Code generation error (phpqrcode): " . $e->getMessage());
        }
    }
    return false;
}

/**
 * Online QR API használata (backup)
 */
function generateQROnline($data, $size = 200) {
    $api_url = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($data);
    
    $context_options = [
        'http' => [
            'timeout' => 5, // 5 másodperc timeout
            'user_agent' => 'File Share App QR Generator/1.0',
            'ignore_errors' => true // Hogy a HTTP hibakódok esetén is kapjunk tartalmat
        ],
        'ssl' => [ // Néhány szerveren szükség lehet erre
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ];
    $context = stream_context_create($context_options);
    
    $qr_data = @file_get_contents($api_url, false, $context);
    
    // Ellenőrizzük, hogy a válasz sikeres kép-e
    if ($qr_data !== false) {
        $headers = $http_response_header ?? [];
        $is_image = false;
        foreach($headers as $header) {
            if (stripos($header, 'Content-Type: image/') === 0) {
                $is_image = true;
                break;
            }
        }
        if ($is_image) {
             return ['content_type' => 'image/png', 'data' => $qr_data]; // Feltételezzük, hogy PNG az API-tól
        } else {
            error_log("QR Code generation error (Online API did not return an image): " . ($headers[0] ?? 'Unknown HTTP error'));
        }
    } else {
        error_log("QR Code generation error (Online API request failed)");
    }
    
    return false;
}

// Fő logika
try {
    $data_param = $_GET['data'] ?? '';
    $size_param = (int)($_GET['size'] ?? 200); // Ezt a méretet az img tagnek kell kezelnie, itt az API-nak adjuk át
    $download = isset($_GET['download']);
    
    if (empty($data_param)) {
        throw new Exception('Hiányzó adat paraméter');
    }
    
    $size_param = max(100, min(800, $size_param)); // API-nak átadott méret korlátozása
    
    // URL validáció (biztonsági ellenőrzés) - enyhítve, hogy általánosabb adatokat is elfogadjon
    // A FILTER_VALIDATE_URL túl szigorú lehet, ha nem csak URL-t akarunk QR kódolni.
    // A regex is lehet túl szigorú. Ezt a frontendnek kellene jobban kezelnie, mit küld.
    // Maradjon az egyszerűség kedvéért, de éles rendszerben ezt felül kell vizsgálni.
    if (strlen($data_param) > 1024) { // Adathossz korlát
        throw new Exception('Túl hosszú adat a QR kódhoz');
    }
     if (!preg_match('/^[a-zA-Z0-9\-_\.\/\?\&\=\#\%\:\s\+]+$/u', $data_param) && !filter_var($data_param, FILTER_VALIDATE_URL)) {
         if (mb_detect_encoding($data_param, 'UTF-8', true) === false) { // Ha nem UTF-8, vagy gyanús karakterek
            throw new Exception('Érvénytelen karakterek az adatban');
         }
         // Ha UTF-8, és nem URL, akkor is engedjük tovább, de logoljuk, hogy nem validált URL
         // error_log("QR Data is not a validated URL but proceeding: " . substr($data_param, 0, 50));
     }


    $qr_result = null;
    
    // 1. Phpqrcode könyvtár próbálása
    $qr_result = generateQRCodeWithPhpqrcode($data_param, $size_param);
    
    // 2. Online API próbálása, ha az első nem sikerült
    if (!$qr_result) {
        $qr_result = generateQROnline($data_param, $size_param);
    }
    
    // 3. SVG fallback, ha egyik sem sikerült
    if (!$qr_result) {
        $qr_content_data = generateErrorQRPlaceholder($data_param, $size_param);
        $content_type = 'image/svg+xml';
    } else {
        $qr_content_data = $qr_result['data'];
        $content_type = $qr_result['content_type'];
    }
    
    if (!$qr_content_data) { // Dupla ellenőrzés, bár a fallback miatt ennek nem szabadna megtörténnie
        throw new Exception('QR kód generálás végleg sikertelen');
    }
    
    // Fejlécek beállítása
    if ($download) {
        $filename = 'qr_code_' . preg_replace('/[^a-z0-9_]/i', '_', substr($data_param, 0, 20)) . '_' .date('YmdHis');
        $filename .= ($content_type === 'image/svg+xml' ? '.svg' : '.png');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    } else {
        header('Content-Disposition: inline');
    }
    
    header('Content-Type: ' . $content_type);
    header('Content-Length: ' . strlen($qr_content_data));
    header('Cache-Control: public, max-age=3600'); // 1 óra cache
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
    
    echo $qr_content_data;
    
    // Naplózás
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
    
    $log_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'type' => 'qr_generation_request',
        'data_preview' => substr($data_param, 0, 100),
        'size_param' => $size_param,
        'generated_type' => $content_type,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent_preview' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 100),
        'success' => true,
        'method' => ($qr_result && $content_type !== 'image/svg+xml') ? ($qr_result['content_type'] === 'image/png' && function_exists('generateQRCodeWithPhpqrcode') && generateQRCodeWithPhpqrcode($data_param, $size_param) !== false ? 'phpqrcode' : 'online_api') : 'svg_fallback_or_error'
    ];
    
    $log_file = $log_dir . '/qr_access.log';
    file_put_contents($log_file, json_encode($log_data) . "\n", FILE_APPEND | LOCK_EX);
    
} catch (Exception $e) {
    error_log("QR Generation Main Error: " . $e->getMessage());
    
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);

    $error_log_data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'type' => 'qr_generation_error',
        'error_message' => $e->getMessage(),
        'data_param' => $_GET['data'] ?? 'N/A',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ];
    
    $log_file_errors = $log_dir . '/qr_errors.log';
    file_put_contents($log_file_errors, json_encode($error_log_data) . "\n", FILE_APPEND | LOCK_EX);
    
    // Hiba kép generálás (SVG)
    header('Content-Type: image/svg+xml');
    echo generateErrorQRPlaceholder($_GET['data'] ?? 'N/A (Hiba)', $size_param ?? 200);
}
?>