<?php
// === Fájl: tracker/gallery_analytics.php ===
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

requireLogin();
$db = getDB();
$currentUserId = getCurrentUserId();

$galleryId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$galleryId) {
    header("Location: galleries.php");
    exit;
}

// Check ownership
$stmt = $db->prepare("SELECT * FROM galleries WHERE id = ?");
$stmt->execute([$galleryId]);
$gallery = $stmt->fetch();

if (!$gallery || ($gallery['user_id'] != $currentUserId && !isAdmin())) {
    header("Location: galleries.php?error=not_authorized");
    exit;
}

$tab    = $_GET['tab']    ?? 'dashboard';
$period = $_GET['period'] ?? '7days';

// Custom date range
$date_from = '';
$date_to   = '';
if (!empty($_GET['date_from']) && !empty($_GET['date_to'])) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to'])) {
        $date_from = $_GET['date_from'];
        $date_to   = $_GET['date_to'];
        $period    = 'custom';
    }
}

if ($period === 'custom' && $date_from && $date_to) {
    $trendFrom = $date_from;
    $trendTo   = $date_to;
    $trendDays = max(1, (int)((strtotime($date_to) - strtotime($date_from)) / 86400) + 1);
} else {
    $trendDays = match($period) { 'today'=>1, '7days'=>7, '30days'=>30, default=>90 };
    $trendFrom = date('Y-m-d', strtotime("-{$trendDays} days"));
    $trendTo   = date('Y-m-d');
}

$GLOBALS['pw_from'] = $date_from;
$GLOBALS['pw_to']   = $date_to;
$GLOBALS['trendFrom'] = $trendFrom;
$GLOBALS['trendTo']   = $trendTo;

function pw(string $col, string $p): string {
    $f = $GLOBALS['pw_from'] ?? '';
    $t = $GLOBALS['pw_to']   ?? '';
    if ($f && $t) return "AND DATE($col) BETWEEN '$f' AND '$t'";
    return match($p) {
        'today'  => "AND DATE($col) = CURDATE()",
        '7days'  => "AND $col >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
        '30days' => "AND $col >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
        default  => ''
    };
}
function pTrend(string $col): string {
    $f = $GLOBALS['trendFrom'];
    $t = $GLOBALS['trendTo'];
    return "AND DATE($col) BETWEEN '$f' AND '$t'";
}
function q(PDO $pdo, string $sql, array $p = []): array {
    global $galleryId;
    $p[':gid'] = $galleryId;
    $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll();
}
function qv(PDO $pdo, string $sql, array $p = []): mixed {
    global $galleryId;
    $p[':gid'] = $galleryId;
    $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn() ?: 0;
}
function fmtSec(int|float $s): string {
    $s = max(0,(int)$s);
    if ($s < 60) return "{$s}mp";
    return floor($s/60)."p ".($s%60)."mp";
}
function displayUrl(string $url): string {
    $p = parse_url($url);
    $path = $p['path'] ?? '/';
    $qs = !empty($p['query']) ? '?'.$p['query'] : '';
    return $path . $qs;
}
function pagHtml(int $total, int $perPage, int $page, array $bp): string {
    if ($perPage <= 0) $perPage = 25;
    if ($total <= 0) return '';
    $pages = max(1, (int)ceil($total / $perPage));
    if ($pages <= 1 && $total <= $perPage) return '';
    $o  = '<div class="pagination">';
    $o .= '<span class="pag-info">'.number_format($total).' sor &bull; '.$pages.' oldal</span>';
    if ($page > 1) $o .= '<a href="?'.http_build_query(array_merge($bp,['page'=>$page-1])).'" class="pag-btn">‹</a>';
    $s = max(1, $page-2); $e = min($pages, $page+2);
    if ($s > 1) { $o .= '<a href="?'.http_build_query(array_merge($bp,['page'=>1])).'" class="pag-btn">1</a>'; if ($s>2) $o .= '<span class="pag-dots">…</span>'; }
    for ($i=$s; $i<=$e; $i++) $o .= '<a href="?'.http_build_query(array_merge($bp,['page'=>$i])).'" class="pag-btn'.($i===$page?' active':'').'">'.$i.'</a>';
    if ($e < $pages) { if ($e < $pages-1) $o .= '<span class="pag-dots">…</span>'; $o .= '<a href="?'.http_build_query(array_merge($bp,['page'=>$pages])).'" class="pag-btn">'.$pages.'</a>'; }
    if ($page < $pages) $o .= '<a href="?'.http_build_query(array_merge($bp,['page'=>$page+1])).'" class="pag-btn">›</a>';
    $o .= '</div>';
    return $o;
}
function sortTh(string $col, string $label, array $bp): string {
    $cur = $bp['sort'] ?? '';
    $dir = $bp['dir'] ?? 'desc';
    $nd  = ($cur === $col && $dir === 'asc') ? 'desc' : 'asc';
    $icon = $cur === $col ? ($dir === 'asc' ? '↑' : '↓') : '↕';
    $active = $cur === $col ? ' sort-active' : '';
    return '<a href="?'.http_build_query(array_merge($bp,['sort'=>$col,'dir'=>$nd,'page'=>1])).'" class="'.$active.'">'.$label.'<span class="sort-icon">'.$icon.'</span></a>';
}
function bp(string $tab, string $period, string $df, string $dt, array $extra=[]): array {
    global $galleryId;
    return array_filter(array_merge(['id'=>$galleryId,'tab'=>$tab,'period'=>$period,'date_from'=>$df,'date_to'=>$dt], $extra));
}

$validTabs = ['dashboard','analytics','visitors','gallery','performance','interactions','errors'];
if (!in_array($tab, $validTabs)) $tab = 'dashboard';

$navItems = [
    'dashboard'    => ['📊', 'Vezérlőpult'],
    'analytics'    => ['📈', 'Analitika'],
    'visitors'     => ['👥', 'Látogatók'],
    'gallery'      => ['🖼️', 'Képek Stat.'],
    'performance'  => ['⚡', 'Teljesítmény'],
    'interactions' => ['🖱️', 'Interakciók'],
    'errors'       => ['🐛', 'Hibák'],
];

function navUrl(string $t, string $p, string $f, string $to): string {
    global $galleryId;
    return '?' . http_build_query(array_filter(['id'=>$galleryId,'tab'=>$t,'period'=>$p,'date_from'=>$f,'date_to'=>$to]));
}

$pageTitle = $navItems[$tab][1] . " - " . $gallery['name'];

require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/analytics.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4/dist/chart.umd.min.js"></script>

<style>
.analytics-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--glass-border);
    padding-bottom: 10px;
    overflow-x: auto;
}
.analytics-tab {
    padding: 8px 16px;
    border-radius: var(--border-radius);
    color: var(--text-secondary);
    text-decoration: none;
    font-weight: 500;
    white-space: nowrap;
    transition: all 0.2s;
}
.analytics-tab:hover { background: rgba(0,212,255,0.1); color: var(--accent-primary); }
.analytics-tab.active { background: var(--accent-primary); color: #000; font-weight: bold; }
select.form-control {
    background-color: rgba(0,0,0,0.2);
    color: var(--text-primary, #ffffff);
    border: 1px solid var(--glass-border, rgba(255,255,255,0.1));
    border-radius: 4px;
}
select.form-control option {
    background-color: var(--bg-primary, #1e1e2d);
    color: var(--text-primary, #ffffff);
}
</style>

<div class="content-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
    <div>
        <h1><i class="fas fa-chart-line"></i> Analitika: <?php echo escape($gallery['name']); ?></h1>
        <a href="galleries.php" style="color:var(--text-secondary); text-decoration:none;"><i class="fas fa-arrow-left"></i> Vissza a galériákhoz</a>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <div class="period-filter">
            <?php foreach (['today'=>'Ma','7days'=>'7 nap','30days'=>'30 nap','all'=>'Összes'] as $pk=>$pl): ?>
            <a href="<?= navUrl($tab, $pk, '', '') ?>" class="period-btn <?= ($period===$pk && !$date_from) ? 'active' : '' ?>"><?= $pl ?></a>
            <?php endforeach; ?>
        </div>
        <form method="GET" style="display:flex; gap:5px; align-items:center;">
            <input type="hidden" name="id" value="<?= $galleryId ?>">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="hidden" name="period" value="custom">
            <!-- PhantomTrack flatpickr fogja megformázni a type="date" inputokat -->
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from ?: $trendFrom) ?>" class="form-control" style="padding:4px; width:130px;" placeholder="Kezdés">
            <span style="color:var(--text-secondary);">→</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to ?: $trendTo) ?>" class="form-control" style="padding:4px; width:130px;" placeholder="Befejezés">
            <button type="submit" class="btn btn-primary" style="padding:4px 12px;">Szűrés</button>
        </form>
    </div>
</div>

<div class="analytics-tabs">
    <?php foreach ($navItems as $key => [$icon, $label]): ?>
    <a href="<?= navUrl($key, $period, $date_from, $date_to) ?>" class="analytics-tab <?= $tab === $key ? 'active' : '' ?>">
        <?= $icon ?> <?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($period === 'custom' && $date_from): ?>
<div style="padding:6px 24px;background:rgba(0,212,255,0.06);border-bottom:1px solid rgba(0,212,255,0.2);font-size:12px;color:var(--accent);margin-bottom:20px;border-radius:var(--border-radius);">
    📅 Egyedi intervallum: <strong><?= $date_from ?></strong> → <strong><?= $date_to ?></strong> (<?= $trendDays ?> nap)
</div>
<?php endif; ?>

<script>
Chart.defaults.color='#a0a7d3'; Chart.defaults.borderColor='rgba(255,255,255,0.08)';
// Removed global C and MULTI constants to avoid redeclaration SyntaxErrors in tabs
</script>

<div class="analytics-content">
    <?php
    $tabFile = __DIR__ . '/analytics_tabs/' . $tab . '.php';
    if (file_exists($tabFile)) {
        include $tabFile;
    } else {
        echo '<p style="color:var(--color-error)">Fül tartalma fejlesztés alatt...</p>';
    }
    ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
// Explicit initialization of flatpickr for this page, just in case script.js misses it
document.addEventListener('DOMContentLoaded', function() {
    if (typeof flatpickr !== "undefined") {
        flatpickr("input[type='date']", {
            dateFormat: "Y-m-d",
            locale: "hu"
        });
    }
});
</script>
