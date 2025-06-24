<?php
// Hely: admin/export_handler.php
// Ez a fájl kezeli az összes adat exportálási kérelmet (JSON, CSV, XLSX, PDF).

// 1. Alapvető beállítások és biztonsági ellenőrzések
// -----------------------------------------------------------------------------
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Composer autoloader a külső könyvtárakhoz (PhpSpreadsheet, mPDF)
require_once __DIR__ . '/../vendor/autoload.php';

// Minden export művelethez bejelentkezés szükséges
requireLogin();

// 2. Bemeneti adatok feldolgozása és közös változók
// -----------------------------------------------------------------------------
$db = getDB();
$currentUserId = getCurrentUserId();

// Az export típusa (GET vagy POST kérésből is jöhet)
$action = $_REQUEST['action'] ?? null;
// A token ID (GET vagy POST kérésből is jöhet)
$tokenId = (int)($_REQUEST['id'] ?? 0);
// A letöltési token a cookie-alapú visszajelzéshez
$downloadToken = $_REQUEST['download_token'] ?? null;


// 3. Jogosultság ellenőrzés és alapvető adatok lekérdezése
// -----------------------------------------------------------------------------

// Token alapadatok lekérdezése (jogosultság ellenőrzéshez és a fájlnévhez)
$tokenStmt = $db->prepare("SELECT id, token_value, name FROM tokens WHERE id = :id AND user_id = :user_id");
$tokenStmt->execute([':id' => $tokenId, ':user_id' => $currentUserId]);
$token = $tokenStmt->fetch();

if (!$token) {
    // Ha a token nem létezik vagy nincs jogosultság, leállunk.
    http_response_code(403);
    die('Hozzáférés megtagadva vagy a token nem található.');
}

// 4. Szűrési paraméterek és alap SQL lekérdezés összeállítása (táblázatos exportokhoz)
// -----------------------------------------------------------------------------

$baseSql = "SELECT id, token_id, timestamp, ip_address, user_agent, referrer, browser_name, browser_version, os_name, os_version, device_type, device_brand, device_model, country_code, city_name, isp FROM activity_logs WHERE token_id = :token_id";
$baseParams = [':token_id' => $tokenId];

// Szűrők alkalmazása, ha vannak
$filterIp = trim($_GET['filter_ip'] ?? '');
$urlDateFrom = trim($_GET['filter_date_from'] ?? '');
$urlDateTo = trim($_GET['filter_date_to'] ?? '');

if (!empty($filterIp)) { $baseSql .= " AND ip_address LIKE :ip_address"; $baseParams[':ip_address'] = "%" . $filterIp . "%"; }
if (!empty($urlDateFrom) && DateTime::createFromFormat('Y-m-d', $urlDateFrom)) { $baseSql .= " AND DATE(timestamp) >= :date_from"; $baseParams[':date_from'] = $urlDateFrom; }
if (!empty($urlDateTo) && DateTime::createFromFormat('Y-m-d', $urlDateTo)) { $baseSql .= " AND DATE(timestamp) <= :date_to"; $baseParams[':date_to'] = $urlDateTo; }

$baseSql .= " ORDER BY timestamp DESC";

// A generált fájlnév közös része
$baseFilename = 'token_'.preg_replace('/[^a-zA-Z0-9-]/', '_', $token['token_value']).'_logs_'.date('YmdHis');


// 5. Központi Switch az exportálási műveletekhez
// -----------------------------------------------------------------------------
switch ($action) {

    case 'export_json':
        $stmt = $db->prepare($baseSql);
        $stmt->execute($baseParams);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($downloadToken) {
            setcookie($downloadToken, "true", ['expires' => time() + 30, 'path' => '/', 'samesite' => 'Lax']);
        }
        
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$baseFilename.'.json"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    case 'export_csv':
        $stmt = $db->prepare($baseSql);
        $stmt->execute($baseParams);

        if ($downloadToken) {
            setcookie($downloadToken, "true", ['expires' => time() + 30, 'path' => '/', 'samesite' => 'Lax']);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$baseFilename.'.csv"');
        
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // BOM az Excel helyes UTF-8 kezeléséhez
        
        $header = ['ID', 'Token ID', 'Időbélyeg', 'IP Cím', 'User Agent', 'Referrer', 'Böngésző', 'B. Verzió', 'OS', 'OS Verzió', 'Eszköz', 'Márka', 'Modell', 'Ország', 'Város', 'ISP'];
        fputcsv($output, $header);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        break;

    case 'export_xlsx':
        $stmt = $db->prepare($baseSql);
        $stmt->execute($baseParams);
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Napló Adatok');

        $header = ['ID', 'Token ID', 'Időbélyeg', 'IP Cím', 'User Agent', 'Referrer', 'Böngésző', 'B. Verzió', 'OS', 'OS Verzió', 'Eszköz', 'Márka', 'Modell', 'Ország', 'Város', 'ISP'];
        $sheet->fromArray($header, NULL, 'A1');
        $sheet->getStyle('A1:P1')->getFont()->setBold(true);

        $rowNum = 2;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sheet->fromArray(array_values($row), NULL, 'A'.$rowNum);
            $rowNum++;
        }
        
        foreach(range('A','P') as $columnID) { $sheet->getColumnDimension($columnID)->setAutoSize(true); }

        if ($downloadToken) {
            setcookie($downloadToken, "true", ['expires' => time() + 30, 'path' => '/', 'samesite' => 'Lax']);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="'.$baseFilename.'.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        break;

    case 'export_pdf':
        // Ellenőrizzük, hogy POST kérés érkezett-e
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
            http_response_code(405); // Method Not Allowed
            die("A PDF export csak POST kéréssel érhető el."); 
        }
        
        // --- Adatok előkészítése a template számára ---
        $tokenName = $_POST['token_name'] ?? $token['name'];
        $charts = json_decode($_POST['charts_data'] ?? '[]', true);
        $currentUsername = getCurrentUsername(); // Felhasználónév a lábléchez

        // A letöltés-jelző cookie beállítása, mielőtt bármi kimenet lenne
        if ($downloadToken) {
            setcookie($downloadToken, "true", ['expires' => time() + 30, 'path' => '/', 'samesite' => 'Lax']);
            // Ha a szerver támogatja, a böngésző azonnal folytathatja a munkát
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
        }
        
        // --- mPDF és a template feldolgozása ---
        try {
            // mPDF objektum konfigurálása
            $mpdf = new \Mpdf\Mpdf([
                'tempDir' => __DIR__ . '/../temp',
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_left' => 15, 'margin_right' => 15,
                'margin_top' => 25,  'margin_bottom' => 25,
                'margin_header' => 10, 'margin_footer' => 10
            ]);

            // Fejléc és Lábléc beállítása
            $header = '<div style="border-bottom: 1px solid #00D4FF; font-size: 9pt; text-align: center; padding-bottom: 3px; color: #444;">PhantomTrack Riport: ' . escape($tokenName) . '</div>';
            $footer = '<div style="font-size: 9pt; text-align: center; padding-top: 3px;">Generálta: ' . escape($currentUsername) . ' | {DATE j-m-Y H:i} | {PAGENO}/{nbpg} oldal</div>';
            $mpdf->SetHeader($header);
            $mpdf->SetFooter($footer);

            // A template fájl tartalmának "elfogása" egy változóba
            ob_start();
            require __DIR__ . '/../includes/pdf_template.php';
            $html = ob_get_clean();

            // A HTML tartalom beírása a PDF-be
            $mpdf->WriteHTML($html);

            // A PDF kimenetre küldése letöltésként
            $pdfFilename = 'riport_'.preg_replace('/[^a-zA-Z0-9-]/', '_', $tokenName).'_'.date('YmdHis').'.pdf';
            $mpdf->Output($pdfFilename, 'D');

        } catch (\Mpdf\MpdfException $e) { // mPDF specifikus kivételkezelés
            error_log("mPDF hiba: " . $e->getMessage());
            die("Hiba történt a PDF generálása közben.");
        }
        break;
    default:
        http_response_code(400); // Bad Request
        echo 'Érvénytelen exportálási művelet lett megadva.';
        break;
}

// A szkript futása itt véget ér minden esetben
exit; 