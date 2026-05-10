<?php
// analytics_tabs/performance.php
$pf = pw('created_at', $period);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = in_array((int)($_GET['per_page'] ?? 25), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 25) : 25;
$sort    = in_array($_GET['sort'] ?? '', ['full_load_ms','ttfb_ms','dom_load_ms','resource_count']) ? $_GET['sort'] : 'full_load_ms';
$dir     = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

$avgTtfb   = round(qv($db,"SELECT AVG(ttfb_ms) FROM log_performance WHERE gallery_id = :gid $pf") ?? 0);
$avgDom    = round(qv($db,"SELECT AVG(dom_load_ms) FROM log_performance WHERE gallery_id = :gid $pf") ?? 0);
$avgFull   = round(qv($db,"SELECT AVG(full_load_ms) FROM log_performance WHERE gallery_id = :gid $pf") ?? 0);
$avgRes    = round(qv($db,"SELECT AVG(resource_count) FROM log_performance WHERE gallery_id = :gid $pf") ?? 0);
$totalMeas = (int)qv($db,"SELECT COUNT(*) FROM log_performance WHERE gallery_id = :gid $pf");
$slowCount = (int)qv($db,"SELECT COUNT(*) FROM log_performance WHERE gallery_id = :gid AND full_load_ms>3000 $pf");
$p75row    = q($db,"SELECT full_load_ms FROM log_performance WHERE gallery_id = :gid $pf ORDER BY full_load_ms LIMIT 1 OFFSET ".max(0,(int)($totalMeas*0.75)-1));
$p75Full   = $p75row[0]['full_load_ms'] ?? 0;

$ttfbBuckets = q($db,"SELECT
    CASE WHEN ttfb_ms<100  THEN '< 100ms (kiváló)'
         WHEN ttfb_ms<300  THEN '100-300ms (jó)'
         WHEN ttfb_ms<1000 THEN '300ms-1s (közepes)'
         ELSE '> 1s (lassú)' END as bucket, COUNT(*) as cnt
    FROM log_performance WHERE gallery_id = :gid $pf GROUP BY bucket ORDER BY MIN(ttfb_ms)");
$fullBuckets = q($db,"SELECT
    CASE WHEN full_load_ms<1000 THEN '< 1s'
         WHEN full_load_ms<2000 THEN '1-2s'
         WHEN full_load_ms<3000 THEN '2-3s'
         WHEN full_load_ms<5000 THEN '3-5s'
         ELSE '> 5s' END as bucket, COUNT(*) as cnt
    FROM log_performance WHERE gallery_id = :gid $pf GROUP BY bucket ORDER BY MIN(full_load_ms)");
$dailyPerf = q($db,"SELECT DATE(created_at) as d,
    ROUND(AVG(ttfb_ms)) as avg_ttfb, ROUND(AVG(dom_load_ms)) as avg_dom,
    ROUND(AVG(full_load_ms)) as avg_full, COUNT(*) as cnt
    FROM log_performance WHERE gallery_id = :gid " . pTrend('created_at') . "
    GROUP BY DATE(created_at) ORDER BY d");

$offset  = ($page - 1) * $perPage;
$slowest = q($db,"SELECT p.*, v.ip_address FROM log_performance p
    JOIN visitors v ON p.visitor_id=v.id WHERE p.gallery_id = :gid $pf
    ORDER BY p.$sort $dir LIMIT $perPage OFFSET $offset");

$ttfbClass = $avgTtfb<100?'val-good':($avgTtfb<300?'val-warn':'val-bad');
$fullClass = $avgFull<2000?'val-good':($avgFull<4000?'val-warn':'val-bad');
$bpArr = bp($tab, $period, $date_from, $date_to, array_filter([
    'sort'=>$sort,'dir'=>$dir,'per_page'=>$perPage!==25?$perPage:null]));
?>

<div class="stat-grid">
    <div class="stat-card teal"><div class="stat-label">Átl. TTFB</div><div class="stat-value <?= $ttfbClass ?>"><?= $avgTtfb ?>ms</div><div class="stat-sub">Time to First Byte</div></div>
    <div class="stat-card blue"><div class="stat-label">Átl. DOM betöltés</div><div class="stat-value"><?= $avgDom ?>ms</div></div>
    <div class="stat-card cyan"><div class="stat-label">Átl. teljes betöltés</div><div class="stat-value <?= $fullClass ?>"><?= $avgFull ?>ms</div><div class="stat-sub">P75: <?= $p75Full ?>ms</div></div>
    <div class="stat-card orange"><div class="stat-label">Átl. erőforrás szám</div><div class="stat-value"><?= $avgRes ?></div></div>
    <div class="stat-card danger"><div class="stat-label">Lassú betöltés (&gt;3s)</div><div class="stat-value"><?= $slowCount ?></div><div class="stat-sub"><?= $totalMeas ?> mérésből</div></div>
</div>

<div class="chart-grid">
    <div class="chart-card tall"><h3>Napi teljesítmény trend</h3><canvas id="cPerfTrend"></canvas></div>
    <div class="chart-card"><h3>TTFB eloszlás</h3><canvas id="cTtfb"></canvas></div>
</div>
<div class="chart-grid">
    <div class="chart-card"><h3>Teljes betöltési idő eloszlás</h3><canvas id="cFull"></canvas></div>
    <div class="chart-card" style="display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;padding:24px">
        <div style="text-align:center">
            <div style="font-size:11px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Teljesítmény összefoglaló</div>
            <div style="font-size:42px;font-weight:700;color:<?= $avgFull<2000?'var(--color-success)':($avgFull<4000?'var(--color-warning)':'var(--color-error)') ?>"><?= $avgFull ?>ms</div>
            <div style="font-size:12px;color:var(--text-secondary);margin-top:4px">átlagos oldalbetöltés</div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%">
            <div style="background:rgba(0,0,0,.2);border-radius:6px;padding:10px;text-align:center">
                <div style="font-size:11px;color:var(--text-secondary)">TTFB</div>
                <div style="font-size:18px;font-weight:600;color:var(--accent-secondary,#4ecdc4)"><?= $avgTtfb ?>ms</div>
            </div>
            <div style="background:rgba(0,0,0,.2);border-radius:6px;padding:10px;text-align:center">
                <div style="font-size:11px;color:var(--text-secondary)">DOM</div>
                <div style="font-size:18px;font-weight:600;color:var(--highlight,#3a7bd5)"><?= $avgDom ?>ms</div>
            </div>
        </div>
    </div>
</div>

<!-- Per-page -->
<form method="GET" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
    <?php foreach ($bpArr as $k=>$v): if($k==='per_page') continue; ?>
    <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
    <label style="font-size:12px;color:var(--text-secondary)">Sorok / oldal:</label>
    <select name="per_page" class="form-control" style="width:80px; padding:4px;" onchange="this.form.submit()">
        <?php foreach ([10,25,50,100] as $pp): ?>
        <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
        <?php endforeach; ?>
    </select>
</form>

<div class="table-card">
    <div class="table-card-header"><h3>Betöltési mérések (<?= number_format($totalMeas) ?>)</h3></div>
    <div class="tbl-wrap"><table>
        <thead><tr>
            <th>Időpont</th><th>IP</th>
            <th><?= sortTh('ttfb_ms','TTFB',$bpArr) ?></th>
            <th><?= sortTh('dom_load_ms','DOM',$bpArr) ?></th>
            <th><?= sortTh('full_load_ms','Teljes',$bpArr) ?></th>
            <th><?= sortTh('resource_count','Erőforrás',$bpArr) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($slowest as $r): ?>
        <tr>
            <td style="white-space:nowrap;font-size:12px;color:var(--text-secondary)"><?= $r['created_at'] ?></td>
            <td><span class="badge badge-ip"><?= htmlspecialchars($r['ip_address']) ?></span></td>
            <td class="<?= $r['ttfb_ms']<300?'val-good':'val-bad' ?>"><?= $r['ttfb_ms'] ?>ms</td>
            <td><?= $r['dom_load_ms'] ?>ms</td>
            <td class="<?= $r['full_load_ms']<2000?'val-good':($r['full_load_ms']<4000?'val-warn':'val-bad') ?>"><strong><?= $r['full_load_ms'] ?>ms</strong></td>
            <td><?= $r['resource_count'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= pagHtml($totalMeas, $perPage, $page, $bpArr) ?>
</div>

<script>
var C={cyan:'rgba(0,212,255,.8)',teal:'rgba(78,205,196,.8)',blue:'rgba(58,123,213,.8)',orange:'rgba(255,202,40,.8)',red:'rgba(255,71,87,.8)'};
const pd=<?= json_encode($dailyPerf) ?>;
new Chart(document.getElementById('cPerfTrend'),{type:'line',data:{
    labels:pd.map(r=>r.d.slice(5)),
    datasets:[
        {label:'TTFB',data:pd.map(r=>+r.avg_ttfb),borderColor:C.teal,backgroundColor:'transparent',tension:.4,pointRadius:2},
        {label:'DOM',data:pd.map(r=>+r.avg_dom),borderColor:C.blue,backgroundColor:'transparent',tension:.4,pointRadius:2},
        {label:'Teljes',data:pd.map(r=>+r.avg_full),borderColor:C.cyan,backgroundColor:'transparent',tension:.4,pointRadius:2}
    ]
},options:{responsive:true,plugins:{legend:{labels:{boxWidth:10}}},scales:{y:{beginAtZero:true}}}});

const tb=<?= json_encode($ttfbBuckets) ?>;
new Chart(document.getElementById('cTtfb'),{type:'bar',data:{
    labels:tb.map(r=>r.bucket),
    datasets:[{label:'Mérések',data:tb.map(r=>+r.cnt),
        backgroundColor:['rgba(46,213,115,.7)','rgba(58,123,213,.7)','rgba(255,202,40,.7)','rgba(255,71,87,.7)'],borderWidth:0}]
},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});

const fb=<?= json_encode($fullBuckets) ?>;
new Chart(document.getElementById('cFull'),{type:'bar',data:{
    labels:fb.map(r=>r.bucket),
    datasets:[{label:'Mérések',data:fb.map(r=>+r.cnt),
        backgroundColor:['rgba(46,213,115,.7)','rgba(58,123,213,.7)','rgba(255,202,40,.7)','rgba(255,71,87,.7)','rgba(255,71,87,.5)'],borderWidth:0}]
},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});
</script>
