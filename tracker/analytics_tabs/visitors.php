<?php
// analytics_tabs/visitors.php
$pf = pw('created_at', $period);
$vf = pw('first_seen', $period);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = in_array((int)($_GET['per_page'] ?? 25), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 25) : 25;
$sort    = in_array($_GET['sort'] ?? '', ['action_cnt','first_seen','last_seen']) ? $_GET['sort'] : 'action_cnt';
$dir     = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

$totalV    = qv($db, "SELECT COUNT(*) FROM visitors WHERE gallery_id = :gid $vf");
$newToday  = qv($db, "SELECT COUNT(*) FROM visitors WHERE gallery_id = :gid AND DATE(first_seen)=CURDATE()");
$returning = qv($db, "SELECT COUNT(*) FROM visitors WHERE gallery_id = :gid AND first_seen < last_seen $vf");
$retPct    = $totalV > 0 ? round($returning/$totalV*100, 1) : 0;

$langs = q($db, "SELECT COALESCE(language,'ismeretlen') as lang, COUNT(*) as cnt
    FROM visitors WHERE gallery_id = :gid $vf GROUP BY language ORDER BY cnt DESC LIMIT 10");
$viewports = q($db, "SELECT viewport_width, viewport_height, COUNT(*) as cnt
    FROM visitors WHERE gallery_id = :gid AND viewport_width IS NOT NULL $vf
    GROUP BY viewport_width, viewport_height ORDER BY cnt DESC LIMIT 10");
$deviceCats = q($db, "SELECT
    CASE WHEN viewport_width IS NULL THEN 'ismeretlen'
         WHEN viewport_width < 768   THEN 'Mobil'
         WHEN viewport_width < 1024  THEN 'Tablet'
         ELSE 'Desktop' END as cat, COUNT(*) as cnt
    FROM visitors WHERE gallery_id = :gid $vf GROUP BY cat ORDER BY cnt DESC");
$newPerDay = q($db, "SELECT DATE(first_seen) as d, COUNT(*) as cnt FROM visitors
    WHERE gallery_id = :gid " . pTrend('first_seen') . " GROUP BY DATE(first_seen) ORDER BY d");
$retTrend = q($db, "SELECT DATE(first_seen) as d,
    SUM(CASE WHEN first_seen=last_seen THEN 1 ELSE 0 END) as new_only,
    SUM(CASE WHEN first_seen<last_seen THEN 1 ELSE 0 END) as ret_users
    FROM visitors WHERE gallery_id = :gid " . pTrend('first_seen') . " GROUP BY DATE(first_seen) ORDER BY d");

$totalActive = (int)qv($db, "SELECT COUNT(DISTINCT v.id) FROM visitors v WHERE gallery_id = :gid $vf");
$offset      = ($page - 1) * $perPage;
$topActive   = q($db, "SELECT v.ip_address, v.first_seen, v.last_seen, v.language,
    COUNT(DISTINCT a.id) as action_cnt
    FROM visitors v
    LEFT JOIN log_user_actions a ON v.id=a.visitor_id
    WHERE v.gallery_id = :gid $vf
    GROUP BY v.id ORDER BY $sort $dir LIMIT $perPage OFFSET $offset");

$totalVp = $viewports ? array_sum(array_column($viewports,'cnt')) : 1;
$bpArr = bp($tab, $period, $date_from, $date_to, array_filter([
    'sort'=>$sort,'dir'=>$dir,'per_page'=>$perPage!==25?$perPage:null]));
?>

<div class="stat-grid">
    <div class="stat-card cyan"><div class="stat-label">Összes látogató</div><div class="stat-value"><?= number_format($totalV) ?></div></div>
    <div class="stat-card teal"><div class="stat-label">Új ma</div><div class="stat-value"><?= number_format($newToday) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Visszatérő</div><div class="stat-value"><?= number_format($returning) ?></div><div class="stat-sub"><?= $retPct ?>% visszatérési arány</div></div>
    <div class="stat-card orange"><div class="stat-label">Csak egyszer járt</div><div class="stat-value"><?= number_format($totalV-$returning) ?></div></div>
</div>

<div class="chart-grid">
    <div class="chart-card tall"><h3>Napi új látogatók</h3><canvas id="cVNew"></canvas></div>
    <div class="chart-card"><h3>Eszköz típus</h3><canvas id="cDevice"></canvas></div>
</div>
<div class="chart-grid">
    <div class="chart-card"><h3>Visszatérő vs. Új trend</h3><canvas id="cRet"></canvas></div>
    <div class="chart-card"><h3>Nyelv megoszlás</h3><canvas id="cLang"></canvas></div>
</div>

<!-- Per-page selector -->
<form method="GET" style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
    <?php foreach ($bpArr as $k=>$v): if($k==='per_page') continue; ?>
    <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
    <label style="font-size:12px;color:var(--text-secondary)">Látogatók / oldal:</label>
    <select name="per_page" class="form-control" style="width:80px; padding:4px;" onchange="this.form.submit()">
        <?php foreach ([10,25,50,100] as $pp): ?>
        <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
        <?php endforeach; ?>
    </select>
</form>

<div class="table-card section-gap">
    <div class="table-card-header"><h3>Legaktívabb látogatók</h3></div>
    <div class="tbl-wrap"><table>
        <thead><tr>
            <th>IP</th><th>Nyelv</th>
            <th><?= sortTh('first_seen','Első látogatás',$bpArr) ?></th>
            <th><?= sortTh('last_seen','Utoljára',$bpArr) ?></th>
            <th><?= sortTh('action_cnt','Akciók',$bpArr) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($topActive as $v): ?>
        <tr>
            <td><span class="badge badge-ip"><?= htmlspecialchars($v['ip_address']) ?></span></td>
            <td style="font-size:12px;color:var(--text-secondary)"><?= htmlspecialchars($v['language']??'—') ?></td>
            <td style="font-size:12px"><?= $v['first_seen'] ?></td>
            <td style="font-size:12px"><?= $v['last_seen'] ?></td>
            <td><span class="badge badge-cyan"><?= number_format($v['action_cnt']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= pagHtml($totalActive, $perPage, $page, $bpArr) ?>
</div>

<div class="table-card">
    <div class="table-card-header"><h3>Leggyakoribb képernyőméretek</h3></div>
    <div class="tbl-wrap"><table>
        <thead><tr><th>Viewport</th><th>Látogatók</th><th>Arány</th></tr></thead>
        <tbody>
        <?php $maxVp = $viewports[0]['cnt'] ?? 1; ?>
        <?php foreach ($viewports as $r): ?>
        <tr>
            <td style="font-family:monospace"><?= $r['viewport_width'] ?>×<?= $r['viewport_height'] ?></td>
            <td><?= $r['cnt'] ?></td>
            <td><div class="progress-wrap">
                <div class="progress-bar"><div class="progress-fill" style="width:<?= round($r['cnt']/$maxVp*100) ?>%"></div></div>
                <span class="progress-pct"><?= round($r['cnt']/$totalVp*100) ?>%</span>
            </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<script>
var C={cyan:'rgba(0,212,255,.8)',teal:'rgba(78,205,196,.8)',blue:'rgba(58,123,213,.8)',orange:'rgba(255,202,40,.8)',red:'rgba(255,71,87,.8)'};
var MULTI=[C.teal,C.cyan,C.blue,C.orange,'rgba(255,71,87,.8)','rgba(46,213,115,.8)'];
const npd=<?= json_encode($newPerDay) ?>;
new Chart(document.getElementById('cVNew'),{type:'line',data:{
    labels:npd.map(r=>r.d.slice(5)),
    datasets:[{label:'Új látogató',data:npd.map(r=>+r.cnt),fill:true,backgroundColor:'rgba(0,212,255,.07)',borderColor:C.cyan,tension:.4,pointRadius:3}]
},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});

const dc=<?= json_encode($deviceCats) ?>;
new Chart(document.getElementById('cDevice'),{type:'doughnut',data:{
    labels:dc.map(r=>r.cat),
    datasets:[{data:dc.map(r=>+r.cnt),backgroundColor:[C.teal,C.blue,C.cyan,C.orange].slice(0,dc.length),borderWidth:0}]
},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{boxWidth:12}}}}});

const rt=<?= json_encode($retTrend) ?>;
new Chart(document.getElementById('cRet'),{type:'bar',data:{
    labels:rt.map(r=>r.d.slice(5)),
    datasets:[
        {label:'Csak egyszer',data:rt.map(r=>+r.new_only),backgroundColor:'rgba(58,123,213,.6)',stack:'s'},
        {label:'Visszatérő',data:rt.map(r=>+r.ret_users),backgroundColor:'rgba(78,205,196,.6)',stack:'s'}
    ]
},options:{responsive:true,plugins:{legend:{labels:{boxWidth:10,font:{size:11}}}},scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true}}}});

const lg=<?= json_encode($langs) ?>;
new Chart(document.getElementById('cLang'),{type:'doughnut',data:{
    labels:lg.map(r=>r.lang),
    datasets:[{data:lg.map(r=>+r.cnt),backgroundColor:MULTI.slice(0,lg.length),borderWidth:0}]
},options:{responsive:true,plugins:{legend:{position:'right',labels:{boxWidth:10,font:{size:11}}}}}});
</script>
