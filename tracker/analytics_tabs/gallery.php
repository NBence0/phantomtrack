<?php
// analytics_tabs/gallery.php
$pf = pw('created_at', $period);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = in_array((int)($_GET['per_page'] ?? 25), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 25) : 25;
$sort    = in_array($_GET['sort'] ?? '', ['total','lightbox','tagged']) ? $_GET['sort'] : 'total';
$dir     = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

$totalViews    = qv($db, "SELECT COUNT(*) FROM log_gallery WHERE gallery_id = :gid $pf");
$lightboxViews = qv($db, "SELECT COUNT(*) FROM log_gallery WHERE gallery_id = :gid AND action_type='lightbox_open' $pf");
$tagInserts    = qv($db, "SELECT COUNT(*) FROM log_gallery WHERE gallery_id = :gid AND action_type='image_tag_insert' $pf");
$uniqueImages  = qv($db, "SELECT COUNT(DISTINCT image_filename) FROM log_gallery WHERE gallery_id = :gid $pf");

$dailyViews = q($db, "SELECT DATE(created_at) as d,
    SUM(CASE WHEN action_type='lightbox_open' THEN 1 ELSE 0 END) as lightbox,
    SUM(CASE WHEN action_type='image_tag_insert' THEN 1 ELSE 0 END) as tagged
    FROM log_gallery WHERE gallery_id = :gid " . pTrend('created_at') . "
    GROUP BY DATE(created_at) ORDER BY d");
$hourly = q($db, "SELECT HOUR(created_at) as h, COUNT(*) as cnt
    FROM log_gallery WHERE gallery_id = :gid $pf GROUP BY HOUR(created_at) ORDER BY h");

// Chart top 10 (fixed, no pagination)
$topImagesChart = q($db, "SELECT image_filename, COUNT(*) as cnt
    FROM log_gallery WHERE gallery_id = :gid $pf GROUP BY image_filename ORDER BY cnt DESC LIMIT 10");

// Paginated detail table
$totalImgs = (int)qv($db, "SELECT COUNT(DISTINCT image_filename) FROM log_gallery WHERE gallery_id = :gid $pf");
$offset    = ($page - 1) * $perPage;
$topImages = q($db, "SELECT image_filename,
    COUNT(*) as total,
    SUM(CASE WHEN action_type='lightbox_open' THEN 1 ELSE 0 END) as lightbox,
    SUM(CASE WHEN action_type='image_tag_insert' THEN 1 ELSE 0 END) as tagged
    FROM log_gallery WHERE gallery_id = :gid $pf
    GROUP BY image_filename ORDER BY $sort $dir LIMIT $perPage OFFSET $offset");

$activeVisitors = q($db, "SELECT v.ip_address, COUNT(*) as views
    FROM log_gallery g JOIN visitors v ON g.visitor_id=v.id
    WHERE g.gallery_id = :gid $pf GROUP BY g.visitor_id ORDER BY views DESC LIMIT 8");

$maxTotal = !empty($topImages) ? max(array_column($topImages,'total')) : 1;
$bpArr = bp($tab, $period, $date_from, $date_to, array_filter([
    'sort'=>$sort,'dir'=>$dir,'per_page'=>$perPage!==25?$perPage:null]));
?>

<div class="stat-grid">
    <div class="stat-card cyan"><div class="stat-label">Összes galéria esemény</div><div class="stat-value"><?= number_format($totalViews) ?></div></div>
    <div class="stat-card teal"><div class="stat-label">Lightbox megnyitás</div><div class="stat-value"><?= number_format($lightboxViews) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Képcímkézés</div><div class="stat-value"><?= number_format($tagInserts) ?></div></div>
    <div class="stat-card orange"><div class="stat-label">Egyedi képek</div><div class="stat-value"><?= number_format($uniqueImages) ?></div></div>
</div>

<div class="chart-grid">
    <div class="chart-card tall"><h3>Napi galéria nézetek</h3><canvas id="cGDays"></canvas></div>
    <div class="chart-card"><h3>Óránkénti aktivitás</h3><canvas id="cGHour"></canvas></div>
</div>
<div class="chart-grid">
    <div class="chart-card"><h3>Top 10 kép (megtekintések)</h3><canvas id="cTopImg"></canvas></div>
    <div class="chart-card"><h3>Legaktívabb látogatók</h3><canvas id="cActiveV"></canvas></div>
</div>

<!-- Per-page + sort -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
    <form method="GET" style="display:flex;gap:6px;align-items:center">
        <?php foreach ($bpArr as $k=>$v): if($k==='per_page') continue; ?>
        <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
        <label style="font-size:12px;color:var(--text-secondary)">Képek / oldal:</label>
        <select name="per_page" class="form-control" style="width:80px; padding:4px;" onchange="this.form.submit()">
            <?php foreach ([10,25,50,100] as $pp): ?>
            <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="table-card">
    <div class="table-card-header"><h3>Képenkénti statisztika</h3></div>
    <div class="tbl-wrap"><table>
        <thead><tr>
            <th>#</th>
            <th>Fájlnév</th>
            <th><?= sortTh('total','Összes nézet',$bpArr) ?></th>
            <th><?= sortTh('lightbox','Lightbox',$bpArr) ?></th>
            <th><?= sortTh('tagged','Tag',$bpArr) ?></th>
            <th>Arány</th>
        </tr></thead>
        <tbody>
        <?php foreach ($topImages as $i => $r): ?>
        <tr>
            <td style="color:var(--text-secondary)"><?= $offset+$i+1 ?></td>
            <td style="font-family:monospace;font-size:12px;color:var(--accent)"><?= htmlspecialchars($r['image_filename']) ?></td>
            <td><strong><?= number_format($r['total']) ?></strong></td>
            <td><?= number_format($r['lightbox']) ?></td>
            <td><?= number_format($r['tagged']) ?></td>
            <td><div class="progress-wrap">
                <div class="progress-bar"><div class="progress-fill" style="width:<?= $maxTotal>0?round($r['total']/$maxTotal*100):0 ?>%"></div></div>
            </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= pagHtml($totalImgs, $perPage, $page, $bpArr) ?>
</div>

<script>
var C={cyan:'rgba(0,212,255,.8)',teal:'rgba(78,205,196,.8)',blue:'rgba(58,123,213,.8)',orange:'rgba(255,202,40,.8)',red:'rgba(255,71,87,.8)'};
const gd=<?= json_encode($dailyViews) ?>;
new Chart(document.getElementById('cGDays'),{type:'bar',data:{
    labels:gd.map(r=>r.d.slice(5)),
    datasets:[
        {label:'Lightbox',data:gd.map(r=>+r.lightbox),backgroundColor:'rgba(0,212,255,.55)',stack:'s'},
        {label:'Tag',data:gd.map(r=>+r.tagged),backgroundColor:'rgba(78,205,196,.55)',stack:'s'}
    ]
},options:{responsive:true,plugins:{legend:{labels:{boxWidth:10}}},scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true}}}});

const gh=<?= json_encode($hourly) ?>;
const hArr=Array(24).fill(0); gh.forEach(r=>hArr[+r.h]=+r.cnt);
new Chart(document.getElementById('cGHour'),{type:'bar',data:{
    labels:Array.from({length:24},(_,i)=>i+':00'),
    datasets:[{label:'Nézetek',data:hArr,backgroundColor:'rgba(255,202,40,.55)',borderColor:C.orange,borderWidth:1}]
},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});

const ti=<?= json_encode($topImagesChart) ?>;
new Chart(document.getElementById('cTopImg'),{type:'bar',data:{
    labels:ti.map(r=>r.image_filename.length>20?r.image_filename.substring(0,18)+'…':r.image_filename),
    datasets:[{label:'Nézetek',data:ti.map(r=>+r.cnt),backgroundColor:'rgba(58,123,213,.65)',borderColor:C.blue,borderWidth:1}]
},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});

const av=<?= json_encode($activeVisitors) ?>;
new Chart(document.getElementById('cActiveV'),{type:'bar',data:{
    labels:av.map(r=>r.ip_address),
    datasets:[{label:'Nézetek',data:av.map(r=>+r.views),backgroundColor:'rgba(78,205,196,.6)',borderColor:C.teal,borderWidth:1}]
},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});
</script>
