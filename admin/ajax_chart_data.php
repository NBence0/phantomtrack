<?php
ini_set('display_errors', 1); // Hibák megjelenítése
ini_set('display_startup_errors', 1); // Indítási hibák megjelenítése
error_reporting(E_ALL); // Minden hiba jelentése
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php'; // Ha szükségesek a formázó függvények

requireLogin(); // Csak bejelentkezett felhasználók kérhetnek adatot

header('Content-Type: application/json');

$action = $_GET['action'] ?? null;
$currentUserId = getCurrentUserId();
$db = getDB();

$output = ['error' => 'Ismeretlen művelet vagy hiányzó adatok.']; // Alapértelmezett hiba

if (!$currentUserId) {
    echo json_encode(['error' => 'Nincs bejelentkezve felhasználó.']);
    exit;
}

// Segédfüggvény a token jogosultság és létezés ellenőrzéséhez
function verifyTokenAccess($db, $tokenId, $currentUserId) {
    if ($tokenId <= 0) {
        return ['error' => 'Érvénytelen token ID.', 'valid' => false];
    }
    $tokenCheckStmt = $db->prepare("SELECT id FROM tokens WHERE id = :token_id AND user_id = :user_id");
    $tokenCheckStmt->execute([':token_id' => $tokenId, ':user_id' => $currentUserId]);
    if(!$tokenCheckStmt->fetch()) {
         return ['error' => 'Nincs jogosultság a token adataihoz vagy a token nem létezik.', 'valid' => false];
    }
    return ['valid' => true];
}

switch ($action) {
    case 'daily_opens_overall':
        // Napi megnyitások az elmúlt 30 napra
        $days = (int)($_GET['days'] ?? 30);
        if ($days < 7) $days = 7;
        if ($days > 90) $days = 90; // Limit

        $stmt = $db->prepare("
            SELECT DATE(al.timestamp) as open_date, COUNT(al.id) as open_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY open_date
            ORDER BY open_date ASC
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $counts = [];
        // Hiányzó napok feltöltése 0 értékkel
        $current_date = new DateTime(date('Y-m-d', strtotime("-{$days} days")));
        $end_date = new DateTime(date('Y-m-d'));
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($current_date, $interval, $end_date->modify('+1 day')); // +1 day, hogy az utolsó nap is benne legyen

        $dataMap = [];
        foreach($data as $row) {
            $dataMap[$row['open_date']] = (int)$row['open_count'];
        }

        foreach ($period as $dt) {
            $date_str = $dt->format('Y-m-d');
            $labels[] = $date_str;
            $counts[] = $dataMap[$date_str] ?? 0;
        }

        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'top_tokens':
        $limit = (int)($_GET['limit'] ?? 5);
        if ($limit < 3) $limit = 3;
        if ($limit > 15) $limit = 15;

        $stmt = $db->prepare("
            SELECT t.name as token_name, COUNT(al.id) as open_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id
            GROUP BY al.token_id, t.name
            ORDER BY open_count DESC
            LIMIT :limit
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $labels = array_column($data, 'token_name');
        $counts = array_map('intval', array_column($data, 'open_count'));
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'browser_distribution':
    case 'os_distribution':
    case 'device_type_distribution':
        $field = '';
        if ($action === 'browser_distribution') $field = 'browser_name';
        elseif ($action === 'os_distribution') $field = 'os_name';
        elseif ($action === 'device_type_distribution') $field = 'device_type';
        
        // Összesítés "Ismeretlen" vagy "N/A" kategóriákba, ha sok kicsi van
        $stmt = $db->prepare("
            SELECT 
                CASE 
                    WHEN al.{$field} IS NULL OR al.{$field} = '' OR al.{$field} = 'N/A' THEN 'Ismeretlen'
                    ELSE al.{$field}
                END as item_name, 
                COUNT(al.id) as item_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id AND al.{$field} IS NOT NULL AND al.{$field} != '' 
            GROUP BY item_name
            ORDER BY item_count DESC
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $counts = [];
        $otherCount = 0;
        $maxItems = 6; // Maximum ennyi szeletet mutatunk külön, a többi "Egyéb"

        foreach ($data as $index => $row) {
            if ($index < $maxItems) {
                $labels[] = $row['item_name'];
                $counts[] = (int)$row['item_count'];
            } else {
                $otherCount += (int)$row['item_count'];
            }
        }
        if ($otherCount > 0) {
            $labels[] = 'Egyéb';
            $counts[] = $otherCount;
        }
        
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'top_referrers':
        $limit = (int)($_GET['limit'] ?? 7);
        if ($limit < 3) $limit = 3;
        if ($limit > 20) $limit = 20;

        // Domain kinyerése a referrerből (egyszerűsített)
        // MariaDB/MySQL 8+ esetén REGEXP_REPLACE lenne jobb
        // Ez egy alapvető megoldás, ami nem tökéletes minden URL formátumra
        $stmt = $db->prepare("
            SELECT 
                SUBSTRING_INDEX(REPLACE(REPLACE(al.referrer, 'http://', ''), 'https://', ''), '/', 1) as referrer_domain,
                COUNT(al.id) as open_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id AND al.referrer IS NOT NULL AND al.referrer != '' AND al.referrer != 'N/A'
            GROUP BY referrer_domain
            HAVING referrer_domain IS NOT NULL AND referrer_domain != ''
            ORDER BY open_count DESC
            LIMIT :limit
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = array_column($data, 'referrer_domain');
        $counts = array_map('intval', array_column($data, 'open_count'));
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'country_distribution':
        $limit = (int)($_GET['limit'] ?? 10);
         if ($limit < 3) $limit = 3;
        if ($limit > 20) $limit = 20;

        $stmt = $db->prepare("
            SELECT 
                CASE 
                    WHEN al.country_code IS NULL OR al.country_code = '' THEN 'Ismeretlen'
                    ELSE al.country_code -- Később ezt feloldhatnánk országnévre
                END as country, 
                COUNT(al.id) as open_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id
            GROUP BY country
            ORDER BY open_count DESC
            LIMIT :limit
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = array_column($data, 'country');
        $counts = array_map('intval', array_column($data, 'open_count'));
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    // Token Részletek Oldalhoz (opcionális)
    case 'daily_opens_token':
        $tokenId = (int)($_GET['token_id'] ?? 0);
        $days = (int)($_GET['days'] ?? 30);
        if ($days < 7) $days = 7;
        if ($days > 90) $days = 90;

        if ($tokenId <= 0) {
            $output = ['error' => 'Érvénytelen token ID.'];
            break;
        }
        // Jogosultság ellenőrzés (hogy a userhez tartozik-e a token)
        $tokenCheckStmt = $db->prepare("SELECT id FROM tokens WHERE id = :token_id AND user_id = :user_id");
        $tokenCheckStmt->execute([':token_id' => $tokenId, ':user_id' => $currentUserId]);
        if(!$tokenCheckStmt->fetch()) {
             $output = ['error' => 'Nincs jogosultság a token adataihoz.'];
            break;
        }


        $stmt = $db->prepare("
            SELECT DATE(al.timestamp) as open_date, COUNT(al.id) as open_count
            FROM activity_logs al
            WHERE al.token_id = :token_id AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY open_date
            ORDER BY open_date ASC
        ");
        $stmt->bindParam(':token_id', $tokenId, PDO::PARAM_INT);
        $stmt->bindParam(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $labels = [];
        $counts = [];
        $current_date = new DateTime(date('Y-m-d', strtotime("-{$days} days")));
        $end_date = new DateTime(date('Y-m-d'));
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($current_date, $interval, $end_date->modify('+1 day'));

        $dataMap = [];
        foreach($data as $row) {
            $dataMap[$row['open_date']] = (int)$row['open_count'];
        }

        foreach ($period as $dt) {
            $date_str = $dt->format('Y-m-d');
            $labels[] = $date_str;
            $counts[] = $dataMap[$date_str] ?? 0;
        }

        $output = ['labels' => $labels, 'data' => $counts];
        break;
        
    case 'hourly_activity_token':
        $tokenId = (int)($_GET['token_id'] ?? 0);
        $days_back = (int)($_GET['days_back'] ?? 7); // Milyen időszakra nézve

        if ($tokenId <= 0) {
            $output = ['error' => 'Érvénytelen token ID.'];
            break;
        }
        // Jogosultság ellenőrzés
        $tokenCheckStmt = $db->prepare("SELECT id FROM tokens WHERE id = :token_id AND user_id = :user_id");
        $tokenCheckStmt->execute([':token_id' => $tokenId, ':user_id' => $currentUserId]);
        if(!$tokenCheckStmt->fetch()) {
             $output = ['error' => 'Nincs jogosultság a token adataihoz.'];
            break;
        }

        $stmt = $db->prepare("
            SELECT HOUR(al.timestamp) as open_hour, COUNT(al.id) as open_count
            FROM activity_logs al
            WHERE al.token_id = :token_id AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL :days_back DAY)
            GROUP BY open_hour
            ORDER BY open_hour ASC
        ");
        $stmt->bindParam(':token_id', $tokenId, PDO::PARAM_INT);
        $stmt->bindParam(':days_back', $days_back, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hourlyData = array_fill(0, 24, 0); // 0-23 óráig tömb inicializálása 0-val
        foreach ($data as $row) {
            $hourlyData[(int)$row['open_hour']] = (int)$row['open_count'];
        }
        
        $labels = array_map(function($hour){ return str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00'; }, range(0,23));
        $counts = array_values($hourlyData);
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'weekly_opens_overall':
        $weeks = (int)($_GET['weeks'] ?? 12); // Elmúlt 12 hét
        if ($weeks < 4) $weeks = 4;
        if ($weeks > 52) $weeks = 52;

        $stmt = $db->prepare("
            SELECT YEARWEEK(al.timestamp, 1) as open_yearweek, COUNT(al.id) as open_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL :num_days DAY)
            GROUP BY open_yearweek
            ORDER BY open_yearweek ASC
        ");
        $num_days_for_weeks = $weeks * 7;
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':num_days', $num_days_for_weeks, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $counts = [];
        
        // Címkék generálása az elmúlt X hétre
        $current_week_start = new DateTime();
        $current_week_start->setISODate((int)$current_week_start->format('o'), (int)$current_week_start->format('W'), 1); // Aktuális hét hétfő
        $current_week_start->setTime(0,0,0);


        $dataMap = [];
        foreach($data as $row) {
            $dataMap[$row['open_yearweek']] = (int)$row['open_count'];
        }

        for ($i = $weeks -1; $i >= 0; $i--) {
            $loop_week_start = clone $current_week_start;
            if ($i > 0) { // Ne vonj le napot az aktuális héthez
                 $loop_week_start->modify("-{$i} weeks");
            }
            $year_week_label = $loop_week_start->format("oW"); // ÉvHét formátum, pl. 202324
            $labels[] = $loop_week_start->format("Y-m-d"); // Hétfői dátum a címkéhez
            $counts[] = $dataMap[$year_week_label] ?? 0;
        }

        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'hourly_activity_overall':
        $days_back = (int)($_GET['days_back'] ?? 30); // Elmúlt 30 nap adatai alapján
        if ($days_back < 1) $days_back = 1;
        if ($days_back > 90) $days_back = 90;

        $stmt = $db->prepare("
            SELECT HOUR(al.timestamp) as open_hour, COUNT(al.id) as open_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL :days_back DAY)
            GROUP BY open_hour
            ORDER BY open_hour ASC
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':days_back', $days_back, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hourlyData = array_fill(0, 24, 0); // 0-23 óráig tömb inicializálása 0-val
        foreach ($data as $row) {
            $hourlyData[(int)$row['open_hour']] = (int)$row['open_count'];
        }
        
        $labels = array_map(function($hour){ return str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00'; }, range(0,23));
        $counts = array_values($hourlyData);
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'isp_distribution':
        // Hasonló a browser/os/device_type eloszláshoz
        $stmt = $db->prepare("
            SELECT 
                CASE 
                    WHEN al.isp IS NULL OR al.isp = '' OR al.isp = 'N/A' THEN 'Ismeretlen'
                    ELSE al.isp
                END as item_name, 
                COUNT(al.id) as item_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id AND al.isp IS NOT NULL AND al.isp != ''
            GROUP BY item_name
            ORDER BY item_count DESC
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $counts = [];
        $otherCount = 0;
        $maxItems = 7; // Maximum ennyi szeletet mutatunk külön

        foreach ($data as $index => $row) {
            if ($index < $maxItems) {
                $labels[] = $row['item_name'];
                $counts[] = (int)$row['item_count'];
            } else {
                $otherCount += (int)$row['item_count'];
            }
        }
        if ($otherCount > 0) {
            $labels[] = 'Egyéb ISP';
            $counts[] = $otherCount;
        }
        
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'token_status_ratio':
        $stmt = $db->prepare("
            SELECT 
                CASE is_active WHEN 1 THEN 'Aktív' ELSE 'Inaktív' END as status_label,
                COUNT(id) as token_count
            FROM tokens
            WHERE user_id = :user_id
            GROUP BY is_active
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = array_column($data, 'status_label');
        $counts = array_map('intval', array_column($data, 'token_count'));
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'new_tokens_monthly':
        $months = (int)($_GET['months'] ?? 12); // Elmúlt 12 hónap
        if ($months < 3) $months = 3;
        if ($months > 24) $months = 24;

        $stmt = $db->prepare("
            SELECT DATE_FORMAT(created_at, '%Y-%m') as creation_month, COUNT(id) as token_count
            FROM tokens
            WHERE user_id = :user_id AND created_at >= DATE_SUB(CURDATE(), INTERVAL :num_months MONTH)
            GROUP BY creation_month
            ORDER BY creation_month ASC
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':num_months', $months, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $counts = [];
        
        $current_month_dt = new DateTime(date('Y-m-01')); // Aktuális hónap első napja
        
        $dataMap = [];
        foreach($data as $row) {
            $dataMap[$row['creation_month']] = (int)$row['token_count'];
        }

        for ($i = $months - 1; $i >= 0; $i--) {
            $loop_month_dt = clone $current_month_dt;
            if ($i > 0) {
                $loop_month_dt->modify("-{$i} months");
            }
            $month_label_str = $loop_month_dt->format('Y-m');
            $labels[] = $month_label_str;
            $counts[] = $dataMap[$month_label_str] ?? 0;
        }

    
    
    case 'top_cities':
        $limit = (int)($_GET['limit'] ?? 10);
        if ($limit < 3) $limit = 3;
        if ($limit > 20) $limit = 20;

        $stmt = $db->prepare("
            SELECT 
                CASE 
                    WHEN al.city_name IS NULL OR al.city_name = '' THEN 'Ismeretlen Város'
                    ELSE al.city_name
                END as city, 
                al.country_code, -- Esetleg a címkébe beletehetjük
                COUNT(al.id) as open_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id AND (al.city_name IS NOT NULL AND al.city_name != '')
            GROUP BY city, al.country_code 
            ORDER BY open_count DESC
            LIMIT :limit
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        foreach($data as $row){
            $labels[] = $row['city'] . ($row['country_code'] ? ' (' . $row['country_code'] . ')' : '');
        }
        $counts = array_map('intval', array_column($data, 'open_count'));
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'bot_activity_ratio':
        // Feltételezzük, hogy a device_type 'Bot' értéket kap, ha botról van szó
        // a getDetailedUserAgentInfo() alapján, amit a pixel.php ment.
        $stmt = $db->prepare("
            SELECT 
                SUM(CASE WHEN al.device_type = 'Bot' THEN 1 ELSE 0 END) as bot_opens,
                COUNT(al.id) as total_opens
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $bot_opens = (int)($result['bot_opens'] ?? 0);
        $total_opens = (int)($result['total_opens'] ?? 0);
        $human_opens = $total_opens - $bot_opens;

        if ($total_opens > 0) {
            $labels = ['Emberi', 'Bot'];
            $counts = [$human_opens, $bot_opens];
        } else {
            $labels = ['Nincs adat'];
            $counts = [1]; // Hogy a grafikon ne legyen üres
        }
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'average_opens_per_token':
        $stmt = $db->prepare("
            SELECT 
                (SELECT COUNT(*) FROM activity_logs al_inner JOIN tokens t_inner ON al_inner.token_id = t_inner.id WHERE t_inner.user_id = :user_id1) as total_opens,
                (SELECT COUNT(*) FROM tokens t_outer WHERE t_outer.user_id = :user_id2 AND t_outer.is_active = 1) as active_tokens_count
        ");
        $stmt->bindParam(':user_id1', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':user_id2', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $total_opens = (int)($result['total_opens'] ?? 0);
        $active_tokens_count = (int)($result['active_tokens_count'] ?? 0);
        
        $average = 0;
        if ($active_tokens_count > 0) {
            $average = round($total_opens / $active_tokens_count, 2);
        }
        // Ezt nem grafikonhoz adjuk vissza, hanem egy sima számként
        $output = ['average' => $average, 'total_opens' => $total_opens, 'active_tokens' => $active_tokens_count];
        break;

    case 'token_activity_distribution':
        // Hisztogram-szerű adatokhoz
        // Meghatározzuk a kategóriákat: 0, 1-10, 11-50, 51-100, 101-500, 500+
        $stmt = $db->prepare("
            SELECT 
                t.id as token_id, 
                COUNT(al.id) as open_count
            FROM tokens t
            LEFT JOIN activity_logs al ON t.id = al.token_id
            WHERE t.user_id = :user_id
            GROUP BY t.id
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $token_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bins = [
            '0 Megnyitás' => 0,
            '1-10 Megnyitás' => 0,
            '11-50 Megnyitás' => 0,
            '51-100 Megnyitás' => 0,
            '101-500 Megnyitás' => 0,
            '500+ Megnyitás' => 0
        ];

        foreach ($token_counts as $tc) {
            $count = (int)$tc['open_count'];
            if ($count == 0) $bins['0 Megnyitás']++;
            elseif ($count >= 1 && $count <= 10) $bins['1-10 Megnyitás']++;
            elseif ($count >= 11 && $count <= 50) $bins['11-50 Megnyitás']++;
            elseif ($count >= 51 && $count <= 100) $bins['51-100 Megnyitás']++;
            elseif ($count >= 101 && $count <= 500) $bins['101-500 Megnyitás']++;
            else $bins['500+ Megnyitás']++;
        }
        
        $output = ['labels' => array_keys($bins), 'data' => array_values($bins)];
        break;

    case 'geo_data_completeness':
        $stmt = $db->prepare("
            SELECT
                COUNT(id) as total_logs,
                SUM(CASE WHEN country_code IS NOT NULL AND country_code != '' THEN 1 ELSE 0 END) as with_country,
                SUM(CASE WHEN city_name IS NOT NULL AND city_name != '' THEN 1 ELSE 0 END) as with_city
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $total_logs = (int)($result['total_logs'] ?? 0);
        $with_country = (int)($result['with_country'] ?? 0);
        $with_city = (int)($result['with_city'] ?? 0);

        $country_completeness = $total_logs > 0 ? round(($with_country / $total_logs) * 100, 1) : 0;
        $city_completeness = $total_logs > 0 ? round(($with_city / $total_logs) * 100, 1) : 0;
        
        // Ezt is inkább kártyákhoz adjuk vissza
        $output = [
            'country_percentage' => $country_completeness, 
            'city_percentage' => $city_completeness,
            'total_logs_for_geo' => $total_logs
        ];
        break;    $output = ['error' => 'Ismeretlen művelet: ' . escape($action)];

    case 'least_active_tokens':
        $limit = (int)($_GET['limit'] ?? 5);
        if ($limit < 3) $limit = 3;
        if ($limit > 10) $limit = 10;

        // Azokat a tokeneket keressük, amelyek aktívak, de kevés vagy 0 megnyitásuk van,
        // vagy a legutóbbi megnyitásuk régen volt.
        // Ez a lekérdezés azokat listázza, amiknek van legalább 1 logja, és a legutolsó log a legrégebbi.
        // Kicsit komplexebb lekérdezés kellene a "0 megnyitásos aktív" tokenekhez.
        // Most azokat listázzuk, amiknek van logja, de a legutóbbi régen volt.
        $stmt = $db->prepare("
            SELECT t.name as token_name, t.token_value, MAX(al.timestamp) as last_open_time, COUNT(al.id) as total_opens
            FROM tokens t
            LEFT JOIN activity_logs al ON t.id = al.token_id
            WHERE t.user_id = :user_id AND t.is_active = 1
            GROUP BY t.id, t.name, t.token_value
            ORDER BY last_open_time ASC, total_opens ASC  -- Aminek a legrégebbi az utolsó megnyitása, vagy kevés van
            LIMIT :limit
        ");
        // Ha a NULL-okat (soha nem megnyitott) is előre akarjuk venni: ORDER BY last_open_time IS NULL DESC, last_open_time ASC
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Ezt a PHP oldalon fogjuk kiírni, nem JSON-ként egy grafikonhoz.
        // De ha AJAX-szal akarnánk frissíteni egy listát, akkor JSON lenne.
        // Most feltételezzük, hogy a dashboard.php PHP része dolgozza fel.
        // A példa kedvéért most JSON-t adunk vissza.
        $output = $tokens; // A JS majd formázza listává.
        break;

    case 'daily_average_opens':
        $days = (int)($_GET['days'] ?? 30);
        if ($days < 7) $days = 7;
        if ($days > 90) $days = 90;

        $stmt = $db->prepare("
            SELECT COUNT(al.id) as total_opens_in_period
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $total_opens_in_period = (int)($stmt->fetchColumn() ?? 0);
        
        $average = $days > 0 ? round($total_opens_in_period / $days, 1) : 0;
        $output = ['daily_average' => $average, 'period_days' => $days, 'total_in_period' => $total_opens_in_period];
        break;

    case 'most_active_hour_overall':
        // Hasonló az 'hourly_activity_overall'-hoz, de csak a csúcsot adja vissza
        $days_back = (int)($_GET['days_back'] ?? 30);
        $stmt = $db->prepare("
            SELECT HOUR(al.timestamp) as open_hour, COUNT(al.id) as open_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL :days_back DAY)
            GROUP BY open_hour
            ORDER BY open_count DESC
            LIMIT 1
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->bindParam(':days_back', $days_back, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result) {
            $output = ['hour' => str_pad($result['open_hour'], 2, '0', STR_PAD_LEFT) . ':00 - ' . str_pad($result['open_hour']+1, 2, '0', STR_PAD_LEFT) . ':00', 'count' => (int)$result['open_count']];
        } else {
            $output = ['hour' => 'N/A', 'count' => 0];
        }
        break;
            case 'category_opens_distribution':
        $stmt = $db->prepare("
            SELECT 
                COALESCE(tc.name, 'Nincs Kategória') as category_name, 
                COUNT(al.id) as open_count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            LEFT JOIN token_categories tc ON t.category_id = tc.id AND tc.user_id = t.user_id 
            WHERE t.user_id = :user_id
            GROUP BY category_name
            ORDER BY open_count DESC
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = [];
        $counts = [];
        $otherCount = 0;
        $maxItems = 7; 

        foreach ($data as $index => $row) {
            if ($index < $maxItems) {
                $labels[] = $row['category_name'];
                $counts[] = (int)$row['open_count'];
            } else {
                $otherCount += (int)$row['open_count'];
            }
        }
        if ($otherCount > 0) {
            $labels[] = 'Egyéb Kategóriák';
            $counts[] = $otherCount;
        }
        
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'opens_trend_comparison':
        $current_period_days = (int)($_GET['current_days'] ?? 7);
        $comparison_offset_days = (int)($_GET['offset_days'] ?? $current_period_days); // Hány nappal ezelőtt kezdődött az előző periódus
        $period_length = (int)($_GET['length_days'] ?? $current_period_days); // Milyen hosszú egy periódus

        // Aktuális periódus
        $stmt_current = $db->prepare("
            SELECT COUNT(al.id) as count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL :period_length_curr DAY)
        ");
        $stmt_current->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt_current->bindParam(':period_length_curr', $period_length, PDO::PARAM_INT);
        $stmt_current->execute();
        $current_opens = (int)($stmt_current->fetchColumn() ?? 0);

        // Előző periódus
        $previous_period_start_sql = "DATE_SUB(CURDATE(), INTERVAL ".($comparison_offset_days + $period_length -1)." DAY)"; // -1, mert a 0. nap is számít
        $previous_period_end_sql = "DATE_SUB(CURDATE(), INTERVAL ".$comparison_offset_days." DAY)";
        
        $stmt_previous = $db->prepare("
            SELECT COUNT(al.id) as count
            FROM activity_logs al
            JOIN tokens t ON al.token_id = t.id
            WHERE t.user_id = :user_id 
              AND al.timestamp >= {$previous_period_start_sql}
              AND al.timestamp < {$previous_period_end_sql} 
        "); // < a végén, hogy ne legyen átfedés, ha offset=period_length
        $stmt_previous->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        // A dátumok már be vannak építve, nem kell bindolni őket itt, de jobb lenne paraméterezni a napokat
        $stmt_previous->execute();
        $previous_opens = (int)($stmt_previous->fetchColumn() ?? 0);

        $percentage_change = 0;
        if ($previous_opens > 0) {
            $percentage_change = round((($current_opens - $previous_opens) / $previous_opens) * 100, 1);
        } elseif ($current_opens > 0) {
            $percentage_change = 100; // Ha előtte 0 volt, de most van, az +100% (vagy végtelen)
        }
        
        $output = [
            'current_opens' => $current_opens,
            'previous_opens' => $previous_opens,
            'percentage_change' => $percentage_change,
            'current_period_label' => "Elmúlt {$period_length} nap",
            'previous_period_label' => "Előző {$period_length} nap (".($comparison_offset_days)." nappal ezelőttől)"
        ];
        break;
    case 'data_quality_stats': // Ez a 19-es ponthoz
        $stmt = $db->prepare("
            SELECT
                COUNT(al.id) as total_logs, -- EGYÉRTELMŰSÍTVE: al.id
                SUM(CASE WHEN al.browser_name IS NULL OR al.browser_name = '' OR al.browser_name = 'N/A' OR al.browser_name = 'Ismeretlen Böngésző' THEN 1 ELSE 0 END) as unknown_browser,
                SUM(CASE WHEN al.os_name IS NULL OR al.os_name = '' OR al.os_name = 'N/A' OR os_name = 'Ismeretlen OS' THEN 1 ELSE 0 END) as unknown_os,
                SUM(CASE WHEN al.device_type IS NULL OR al.device_type = '' OR al.device_type = 'N/A' OR device_type = 'Ismeretlen Eszköz' THEN 1 ELSE 0 END) as unknown_device_type,
                SUM(CASE WHEN al.country_code IS NULL OR al.country_code = '' THEN 1 ELSE 0 END) as unknown_country
            FROM activity_logs al -- Itt adtunk aliast az activity_logs-nak: 'al'
            JOIN tokens t ON al.token_id = t.id -- Itt adtunk aliast a tokens-nek: 't'
            WHERE t.user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute(); // Ez a 654. sor környéke
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $total_logs = (int)($result['total_logs'] ?? 0);
        $output = [
            'total_logs' => $total_logs,
            'unknown_browser_abs' => (int)($result['unknown_browser'] ?? 0),
            'unknown_os_abs' => (int)($result['unknown_os'] ?? 0),
            'unknown_device_type_abs' => (int)($result['unknown_device_type'] ?? 0),
            'unknown_country_abs' => (int)($result['unknown_country'] ?? 0),
            'unknown_browser_perc' => $total_logs > 0 ? round(((int)($result['unknown_browser'] ?? 0) / $total_logs) * 100, 1) : 0,
            'unknown_os_perc' => $total_logs > 0 ? round(((int)($result['unknown_os'] ?? 0) / $total_logs) * 100, 1) : 0,
            'unknown_device_type_perc' => $total_logs > 0 ? round(((int)($result['unknown_device_type'] ?? 0) / $total_logs) * 100, 1) : 0,
            'unknown_country_perc' => $total_logs > 0 ? round(((int)($result['unknown_country'] ?? 0) / $total_logs) * 100, 1) : 0,
        ];
        break;
    case 'geo_data_completeness': // Ez a 17-es ponthoz
        $stmt = $db->prepare("
            SELECT
                COUNT(al.id) as total_logs, -- EGYÉRTELMŰSÍTVE: al.id
                SUM(CASE WHEN al.country_code IS NOT NULL AND al.country_code != '' THEN 1 ELSE 0 END) as with_country,
                SUM(CASE WHEN al.city_name IS NOT NULL AND al.city_name != '' THEN 1 ELSE 0 END) as with_city
            FROM activity_logs al -- Alias 'al'
            JOIN tokens t ON al.token_id = t.id -- Alias 't'
            WHERE t.user_id = :user_id
        ");
        $stmt->bindParam(':user_id', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $total_logs = (int)($result['total_logs'] ?? 0);
        $with_country = (int)($result['with_country'] ?? 0);
        $with_city = (int)($result['with_city'] ?? 0);

        $country_completeness = $total_logs > 0 ? round(($with_country / $total_logs) * 100, 1) : 0;
        $city_completeness = $total_logs > 0 ? round(($with_city / $total_logs) * 100, 1) : 0;
        
        $output = [
            'country_percentage' => $country_completeness, 
            'city_percentage' => $city_completeness,
            'total_logs_for_geo' => $total_logs
        ];
        break;




///////////         // TOKEN-SPECIFIKUS STATISZTIKÁK (dátumszűréssel is)          ///////////



        
    case 'daily_opens_token': // MÓDOSÍTVA: Dátumszűréssel
        $tokenId = (int)($_GET['token_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;

        $verification = verifyTokenAccess($db, $tokenId, $currentUserId);
        if (!$verification['valid']) { $output = $verification; break; }

        $sql = "SELECT DATE(al.timestamp) as open_date, COUNT(al.id) as open_count
                FROM activity_logs al
                WHERE al.token_id = :token_id";
        $params = [':token_id' => $tokenId];

        if ($startDate && $endDate && preg_match("/^\d{4}-\d{2}-\d{2}$/", $startDate) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $endDate)) {
            $sql .= " AND DATE(al.timestamp) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
            // Dátumok feltöltése a periódusra
            $period_start_dt = new DateTime($startDate);
            $period_end_dt = new DateTime($endDate);
        } else {
            // Alapértelmezett: elmúlt 30 nap
            $sql .= " AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
            $period_start_dt = new DateTime(date('Y-m-d', strtotime("-30 days")));
            $period_end_dt = new DateTime(date('Y-m-d'));
        }
        $sql .= " GROUP BY open_date ORDER BY open_date ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $labels = [];
        $counts = [];
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($period_start_dt, $interval, $period_end_dt->modify('+1 day'));

        $dataMap = [];
        foreach($data as $row) { $dataMap[$row['open_date']] = (int)$row['open_count']; }
        foreach ($period as $dt) {
            $date_str = $dt->format('Y-m-d');
            $labels[] = $date_str;
            $counts[] = $dataMap[$date_str] ?? 0;
        }
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'hourly_activity_token': // MÓDOSÍTVA: Dátumszűréssel
        $tokenId = (int)($_GET['token_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;

        $verification = verifyTokenAccess($db, $tokenId, $currentUserId);
        if (!$verification['valid']) { $output = $verification; break; }

        $sql = "SELECT HOUR(al.timestamp) as open_hour, COUNT(al.id) as open_count
                FROM activity_logs al
                WHERE al.token_id = :token_id";
        $params = [':token_id' => $tokenId];
        
        if ($startDate && $endDate && preg_match("/^\d{4}-\d{2}-\d{2}$/", $startDate) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $endDate)) {
            $sql .= " AND DATE(al.timestamp) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        } else {
            $sql .= " AND al.timestamp >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"; // Alapértelmezett: elmúlt 7 nap
        }
        $sql .= " GROUP BY open_hour ORDER BY open_hour ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hourlyData = array_fill(0, 24, 0);
        foreach ($data as $row) { $hourlyData[(int)$row['open_hour']] = (int)$row['open_count']; }
        
        $labels = array_map(function($hour){ return str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00'; }, range(0,23));
        $counts = array_values($hourlyData);
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    // ÚJ TOKEN-SPECIFIKUS ELOSZLÁSOK (dátumszűrővel)
    case 'browser_distribution_token':
    case 'os_distribution_token':
    case 'device_type_distribution_token':
    case 'country_distribution_token':
    case 'city_distribution_token': // ÚJ
    case 'isp_distribution_token':   // ÚJ
        $tokenId = (int)($_GET['token_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;

        $verification = verifyTokenAccess($db, $tokenId, $currentUserId);
        if (!$verification['valid']) { $output = $verification; break; }

        $field = ''; $unknownLabel = 'Ismeretlen';
        if ($action === 'browser_distribution_token') { $field = 'browser_name'; $unknownLabel = 'Ismeretlen Böngésző';}
        elseif ($action === 'os_distribution_token') { $field = 'os_name'; $unknownLabel = 'Ismeretlen OS';}
        elseif ($action === 'device_type_distribution_token') { $field = 'device_type'; $unknownLabel = 'Ismeretlen Eszköz';}
        elseif ($action === 'country_distribution_token') { $field = 'country_code'; $unknownLabel = 'Ismeretlen Ország';}
        elseif ($action === 'city_distribution_token') { $field = 'city_name'; $unknownLabel = 'Ismeretlen Város';}
        elseif ($action === 'isp_distribution_token') { $field = 'isp'; $unknownLabel = 'Ismeretlen ISP';}

        $sql = "SELECT 
                    CASE 
                        WHEN al.{$field} IS NULL OR al.{$field} = '' OR al.{$field} = 'N/A' THEN :unknown_label
                        ELSE al.{$field}
                    END as item_name, 
                    COUNT(al.id) as item_count
                FROM activity_logs al
                WHERE al.token_id = :token_id AND al.{$field} IS NOT NULL AND al.{$field} != ''";
        $params = [':token_id' => $tokenId, ':unknown_label' => $unknownLabel];

        if ($startDate && $endDate && preg_match("/^\d{4}-\d{2}-\d{2}$/", $startDate) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $endDate)) {
            $sql .= " AND DATE(al.timestamp) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        } // else nincs alapértelmezett dátumszűrés itt, az összes adatot veszi

        $sql .= " GROUP BY item_name ORDER BY item_count DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $labels = []; $counts = []; $otherCount = 0; $maxItems = 6;
        foreach ($data as $index => $row) {
            if ($index < $maxItems) {
                $labels[] = $row['item_name'];
                $counts[] = (int)$row['item_count'];
            } else { $otherCount += (int)$row['item_count']; }
        }
        if ($otherCount > 0) { $labels[] = 'Egyéb'; $counts[] = $otherCount; }
        
        $output = ['labels' => $labels, 'data' => $counts];
        break;

    case 'top_referrers_token':
        $tokenId = (int)($_GET['token_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        $limit = (int)($_GET['limit'] ?? 7);
        if ($limit < 3) $limit = 3;
        if ($limit > 20) $limit = 20;

        $verification = verifyTokenAccess($db, $tokenId, $currentUserId);
        if (!$verification['valid']) {
            $output = $verification;
            break;
        }

        // Domain kinyerése:
        // Először eltávolítjuk a protokollt (http://, https://)
        // Majd az első '/' karakterig vesszük a részt.
        // Ezután eltávolítjuk a 'www.' prefixet, ha van, a jobb csoportosítás érdekében.
        // Figyelem: Ez még mindig nem tökéletes minden edge case-re (pl. IP címek, portok),
        // de a legtöbb általános URL-re működnie kell.
        $sql = "SELECT 
                    LOWER(TRIM(LEADING 'www.' FROM SUBSTRING_INDEX(REPLACE(REPLACE(al.referrer, 'https://', ''), 'http://', ''), '/', 1))) as referrer_domain,
                    COUNT(al.id) as open_count
                FROM activity_logs al
                WHERE al.token_id = :token_id 
                  AND al.referrer IS NOT NULL 
                  AND al.referrer != '' 
                  AND al.referrer != 'N/A'
                  AND al.referrer NOT LIKE 'http://localhost%' -- Helyi referrerek kiszűrése (opcionális)
                  AND al.referrer NOT LIKE 'https://localhost%' 
                  AND LENGTH(SUBSTRING_INDEX(REPLACE(REPLACE(al.referrer, 'https://', ''), 'http://', ''), '/', 1)) > 0 -- Üres domain ne legyen
                  ";

        $params = [':token_id' => $tokenId];
        
        if ($startDate && $endDate && preg_match("/^\d{4}-\d{2}-\d{2}$/", $startDate) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $endDate)) {
            $sql .= " AND DATE(al.timestamp) BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }

        $sql .= " GROUP BY referrer_domain 
                  HAVING referrer_domain IS NOT NULL AND referrer_domain != '' -- Dupla ellenőrzés
                  ORDER BY open_count DESC 
                  LIMIT :limit";
        $params[':limit'] = $limit;

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $labels = [];
            $counts = [];
            if ($data) {
                foreach ($data as $row) {
                    // Ha a domain még mindig túl hosszú, levághatjuk a megjelenítéshez
                    $domainLabel = $row['referrer_domain'];
                    if (strlen($domainLabel) > 30) { // Példa hosszkorlát
                        // $domainLabel = substr($domainLabel, 0, 27) . '...';
                    }
                    $labels[] = $domainLabel;
                    $counts[] = (int)$row['open_count'];
                }
            }
            $output = ['labels' => $labels, 'data' => $counts];

        } catch (PDOException $e) {
            error_log("SQL Error in top_referrers_token: " . $e->getMessage());
            $output = ['error' => 'Adatbázis hiba történt a referrer adatok lekérdezésekor.', 'details' => $e->getMessage()];
        }
        break;
    case 'unique_vs_total_opens_token':
        $tokenId = (int)($_GET['token_id'] ?? 0);
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;

        $verification = verifyTokenAccess($db, $tokenId, $currentUserId);
        if (!$verification['valid']) { $output = $verification; break; }

        $sql_total = "SELECT COUNT(id) FROM activity_logs WHERE token_id = :token_id";
        $sql_unique = "SELECT COUNT(DISTINCT ip_address) FROM activity_logs WHERE token_id = :token_id";
        $params = [':token_id' => $tokenId];

        if ($startDate && $endDate && preg_match("/^\d{4}-\d{2}-\d{2}$/", $startDate) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $endDate)) {
            $date_filter_sql = " AND DATE(timestamp) BETWEEN :start_date AND :end_date";
            $sql_total .= $date_filter_sql;
            $sql_unique .= $date_filter_sql;
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate;
        }

        $stmt_total = $db->prepare($sql_total);
        $stmt_total->execute($params);
        $total_opens = (int)($stmt_total->fetchColumn() ?? 0);

        $stmt_unique = $db->prepare($sql_unique);
        $stmt_unique->execute($params);
        $unique_opens = (int)($stmt_unique->fetchColumn() ?? 0);
        
        $output = [
            'total_opens' => $total_opens, 
            'unique_opens' => $unique_opens,
            'labels' => ['Egyedi IP', 'Ismétlődő'],
            'data' => [$unique_opens, max(0, $total_opens - $unique_opens)] // Grafiknhoz
        ];
        break;
}

echo json_encode($output);
exit;
?>