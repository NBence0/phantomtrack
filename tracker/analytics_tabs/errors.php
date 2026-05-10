<?php
// analytics_tabs/errors.php
$pf = pw('created_at', $period);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = in_array((int)($_GET['per_page'] ?? 25), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 25) : 25;
$sort    = in_array($_GET['sort'] ?? '', ['cnt','affected_users','last_seen']) ? $_GET['sort'] : 'cnt';
$dir     = (($_GET['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';
$view    = in_array($_GET['view'] ?? '', ['grouped','recent']) ? $_GET['view'] : 'grouped';

// Stats
$totalErrors   = qv($db, "SELECT COUNT(*) FROM log_client_errors WHERE gallery_id = :gid $pf");
$uniqueErrors  = qv($db, "SELECT COUNT(DISTINCT message) FROM log_client_errors WHERE gallery_id = :gid $pf");
$affectedUsers = qv($db, "SELECT COUNT(DISTINCT visitor_id) FROM log_client_errors WHERE gallery_id = :gid $pf");
$todayErrors   = qv($db, "SELECT COUNT(*) FROM log_client_errors WHERE gallery_id = :gid AND DATE(created_at)=CURDATE()");

// Charts
$errPerDay = q($db, "SELECT DATE(created_at) as d, COUNT(*) as cnt, COUNT(DISTINCT visitor_id) as users
    FROM log_client_errors WHERE gallery_id = :gid " . pTrend('created_at') . " GROUP BY DATE(created_at) ORDER BY d");
$byFile = q($db, "SELECT COALESCE(file,'ismeretlen') as file, COUNT(*) as cnt
    FROM log_client_errors WHERE gallery_id = :gid $pf GROUP BY file ORDER BY cnt DESC LIMIT 8");

$bpArr = bp($tab, $period, $date_from, $date_to, array_filter([
    'sort' => $sort, 'dir' => $dir, 'view' => $view,
    'per_page' => $perPage !== 25 ? $perPage : null,
]));

if ($view === 'grouped') {
    $total  = (int)$uniqueErrors;
    $offset = ($page - 1) * $perPage;
    $rows   = q($db, "SELECT message,
        COUNT(*) as cnt,
        COUNT(DISTINCT visitor_id) as affected_users,
        MAX(created_at) as last_seen,
        MIN(file) as sample_file,
        MIN(line_no) as sample_line
    FROM log_client_errors WHERE gallery_id = :gid $pf
    GROUP BY message ORDER BY $sort $dir LIMIT $perPage OFFSET $offset");
} else {
    $total  = (int)$totalErrors;
    $offset = ($page - 1) * $perPage;
    $rows   = q($db, "SELECT e.*, v.ip_address FROM log_client_errors e
        JOIN visitors v ON e.visitor_id=v.id WHERE e.gallery_id = :gid $pf
        ORDER BY e.created_at DESC LIMIT $perPage OFFSET $offset");
}
?>

<div class="stat-grid">
    <div class="stat-card danger"><div class="stat-label">Összes hiba</div><div class="stat-value"><?= number_format($totalErrors) ?></div><div class="stat-sub">Ma: <?= $todayErrors ?></div></div>
    <div class="stat-card orange"><div class="stat-label">Egyedi hiba típus</div><div class="stat-value"><?= number_format($uniqueErrors) ?></div></div>
    <div class="stat-card cyan"><div class="stat-label">Érintett látogatók</div><div class="stat-value"><?= number_format($affectedUsers) ?></div></div>
</div>

<div class="chart-grid">
    <div class="chart-card tall"><h3>Napi hibák (trend)</h3><canvas id="cErrTrend"></canvas></div>
    <div class="chart-card"><h3>Hibák fájl szerint</h3><canvas id="cErrFile"></canvas></div>
</div>

<!-- View toggle + per-page -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
    <div class="period-filter">
        <a href="?<?= http_build_query(array_merge($bpArr,['view'=>'grouped','page'=>1])) ?>"
           class="period-btn <?= $view==='grouped'?'active':'' ?>">📦 Csoportosítva</a>
        <a href="?<?= http_build_query(array_merge($bpArr,['view'=>'recent','page'=>1])) ?>"
           class="period-btn <?= $view==='recent'?'active':'' ?>">🕐 Legutóbbiak</a>
    </div>
    <form method="GET" style="display:flex;gap:6px;align-items:center">
        <?php foreach ($bpArr as $k=>$v): if($k==='per_page') continue; ?>
        <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
        <?php endforeach; ?>
        <select name="per_page" class="form-control" style="width:100px; padding:4px;" onchange="this.form.submit()">
            <?php foreach ([10,25,50,100] as $pp): ?>
            <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?>/oldal</option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if ($view === 'grouped'): ?>
<div class="table-card">
    <div class="table-card-header"><h3>Csoportosított hibák (<?= number_format($total) ?> egyedi)</h3></div>
    <div class="tbl-wrap"><table>
        <thead><tr>
            <th style="min-width:300px">Hiba üzenet</th>
            <th><?= sortTh('cnt','Előfordulás',$bpArr) ?></th>
            <th><?= sortTh('affected_users','Érintett látogatók',$bpArr) ?></th>
            <th>Fájl : sor</th>
            <th><?= sortTh('last_seen','Utoljára',$bpArr) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $e): ?>
        <tr>
            <td style="max-width:360px">
                <span style="color:var(--color-error);font-size:12px;font-weight:500">
                    <?= htmlspecialchars(mb_strimwidth($e['message'],0,120,'…')) ?>
                </span>
            </td>
            <td><span class="badge badge-red"><?= number_format($e['cnt']) ?></span></td>
            <td><span class="badge badge-orange"><?= $e['affected_users'] ?> látogató</span></td>
            <td style="font-family:monospace;font-size:11px;color:var(--text-secondary)">
                <?= $e['sample_file'] ? htmlspecialchars(basename($e['sample_file'])).':'.$e['sample_line'] : '—' ?>
            </td>
            <td style="font-size:12px;color:var(--text-secondary);white-space:nowrap"><?= $e['last_seen'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= pagHtml($total, $perPage, $page, $bpArr) ?>
</div>

<?php else: ?>
<div class="table-card">
    <div class="table-card-header"><h3>Legutóbbi hibák (<?= number_format($total) ?>)</h3></div>
    <div class="tbl-wrap"><table>
        <thead><tr>
            <th>Időpont</th><th>IP</th><th>Hiba üzenet</th><th>Fájl : sor</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $e): ?>
        <tr>
            <td style="white-space:nowrap;font-size:12px;color:var(--text-secondary)"><?= $e['created_at'] ?></td>
            <td><span class="badge badge-ip"><?= htmlspecialchars($e['ip_address']) ?></span></td>
            <td style="max-width:380px">
                <span style="color:var(--color-error);font-size:12px"><?= htmlspecialchars(mb_strimwidth($e['message'],0,130,'…')) ?></span>
                <?php if (!empty($e['stack_trace'])): ?>
                <div style="background:rgba(0,0,0,0.3); border-radius:4px; padding:6px; font-family:monospace; font-size:10px; color:var(--text-secondary); margin-top:4px;">
                    <?= htmlspecialchars(mb_strimwidth($e['stack_trace'],0,300,'…')) ?>
                </div>
                <?php endif; ?>
            </td>
            <td style="font-family:monospace;font-size:11px;color:var(--text-secondary)">
                <?= $e['file'] ? htmlspecialchars(basename($e['file'])).':'.$e['line_no'] : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= pagHtml($total, $perPage, $page, $bpArr) ?>
</div>
<?php endif; ?>

<script>
var C={cyan:'rgba(0,212,255,.8)',teal:'rgba(78,205,196,.8)',blue:'rgba(58,123,213,.8)',orange:'rgba(255,202,40,.8)',red:'rgba(255,71,87,.8)'};
const et=<?= json_encode($errPerDay) ?>;
new Chart(document.getElementById('cErrTrend'),{type:'bar',data:{
    labels:et.map(r=>r.d.slice(5)),
    datasets:[
        {label:'Hibák',data:et.map(r=>+r.cnt),backgroundColor:'rgba(255,71,87,.4)',borderColor:C.red,borderWidth:1,yAxisID:'y'},
        {label:'Érintett látogatók',data:et.map(r=>+r.users),type:'line',borderColor:C.orange,backgroundColor:'transparent',tension:.4,pointRadius:3,yAxisID:'y2'}
    ]
},options:{responsive:true,interaction:{mode:'index'},plugins:{legend:{labels:{boxWidth:10,font:{size:11}}}},
    scales:{y:{beginAtZero:true,position:'left'},y2:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false}}}}});

const ef=<?= json_encode($byFile) ?>;
const MULTI=['rgba(255,71,87,.7)','rgba(255,202,40,.7)','rgba(0,212,255,.7)','rgba(78,205,196,.7)','rgba(58,123,213,.7)','rgba(46,213,115,.7)','rgba(168,85,247,.7)','rgba(240,98,146,.7)'];
new Chart(document.getElementById('cErrFile'),{type:'doughnut',data:{
    labels:ef.map(r=>r.file.length>28?'…'+r.file.slice(-26):r.file),
    datasets:[{data:ef.map(r=>+r.cnt),backgroundColor:MULTI.slice(0,ef.length),borderWidth:0}]
},options:{responsive:true,plugins:{legend:{position:'right',labels:{boxWidth:10,font:{size:11}}}}}});
</script>
