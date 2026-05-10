<?php
// analytics_tabs/dashboard.php
$pf = pw('created_at', $period);
$vf = pw('first_seen', $period);
$pfa = pw('a.created_at', $period);

$totalVisitors = qv($db, "SELECT COUNT(*) FROM visitors WHERE gallery_id = :gid $vf");
$todayVisitors = qv($db, "SELECT COUNT(*) FROM visitors WHERE gallery_id = :gid AND DATE(first_seen)=CURDATE()");
$totalActions  = qv($db, "SELECT COUNT(*) FROM log_user_actions WHERE gallery_id = :gid $pf");
$totalCopies   = qv($db, "SELECT COUNT(*) FROM log_user_actions WHERE gallery_id = :gid AND action_type='copy_text' $pf");
$totalErrors   = qv($db, "SELECT COUNT(*) FROM log_client_errors WHERE gallery_id = :gid $pf");
$avgTime       = qv($db, "SELECT AVG(time_on_page_sec) FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec>0 $pf");
$sessTotal     = qv($db, "SELECT COUNT(*) FROM log_analytics WHERE gallery_id = :gid $pf");
$bounceCount   = qv($db, "SELECT COUNT(*) FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec<15 $pf");
$bounceRate    = $sessTotal > 0 ? round($bounceCount/$sessTotal*100) : 0;

$visitorDays = q($db, "SELECT DATE(first_seen) as d, COUNT(*) as cnt FROM visitors
    WHERE gallery_id = :gid " . pTrend('first_seen') . "
    GROUP BY DATE(first_seen) ORDER BY d");

$hourly = q($db, "SELECT HOUR(created_at) as h, COUNT(*) as cnt FROM log_user_actions
    WHERE gallery_id = :gid AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY HOUR(created_at) ORDER BY h");

$actionDist = q($db, "SELECT action_type, COUNT(*) as cnt FROM log_user_actions WHERE gallery_id = :gid $pf GROUP BY action_type ORDER BY cnt DESC LIMIT 8");

$topImages  = q($db, "SELECT image_filename, COUNT(*) as cnt FROM log_gallery WHERE gallery_id = :gid AND action_type='view' $pf GROUP BY image_filename ORDER BY cnt DESC LIMIT 6");

$recentV    = q($db, "SELECT ip_address, user_agent, first_seen, last_seen FROM visitors WHERE gallery_id = :gid ORDER BY last_seen DESC LIMIT 6");
?>
<div class="stat-grid">
    <div class="stat-card cyan"><div class="stat-label">Látogatók</div><div class="stat-value"><?= number_format($totalVisitors) ?></div><div class="stat-sub">Ma: <?= $todayVisitors ?></div></div>
    <div class="stat-card teal"><div class="stat-label">Akciók</div><div class="stat-value"><?= number_format($totalActions) ?></div><div class="stat-sub">Másolás: <?= $totalCopies ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Átl. oldalon töltött idő</div><div class="stat-value"><?= fmtSec((int)$avgTime) ?></div><div class="stat-sub">Bounce: <?= $bounceRate ?>%</div></div>
    <div class="stat-card danger"><div class="stat-label">JS Hibák</div><div class="stat-value"><?= number_format($totalErrors) ?></div></div>
</div>

<div class="chart-grid">
    <div class="chart-card tall"><h3>Új látogatók — intervallum</h3><canvas id="cVisitorDays"></canvas></div>
    <div class="chart-card"><h3>Óránkénti aktivitás (7 nap)</h3><canvas id="cHourly"></canvas></div>
</div>
<div class="chart-grid">
    <div class="chart-card"><h3>Akció típusok</h3><canvas id="cActionDist"></canvas></div>
    <div class="chart-card"><h3>Top megtekintett képek</h3><canvas id="cTopImages"></canvas></div>
</div>

<div class="table-card">
    <div class="table-card-header"><h3>Legutóbbi látogatók</h3></div>
    <div class="tbl-wrap"><table>
        <thead><tr><th>IP</th><th>Böngésző</th><th>Első látogatás</th><th>Utoljára</th></tr></thead>
        <tbody>
        <?php foreach ($recentV as $v): ?>
        <tr>
            <td><span class="badge badge-ip"><?= htmlspecialchars($v['ip_address']) ?></span></td>
            <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-secondary);font-size:12px"><?= htmlspecialchars($v['user_agent']) ?></td>
            <td><?= $v['first_seen'] ?></td><td><?= $v['last_seen'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<script>
var C={cyan:'rgba(0,212,255,.8)',teal:'rgba(78,205,196,.8)',blue:'rgba(58,123,213,.8)',orange:'rgba(255,202,40,.8)',red:'rgba(255,71,87,.8)',purple:'rgba(187,134,252,.8)'};
const vDays=<?= json_encode($visitorDays) ?>;
new Chart(document.getElementById('cVisitorDays'),{type:'line',data:{
    labels:vDays.map(r=>r.d.slice(5)),
    datasets:[{label:'Látogatók',data:vDays.map(r=>+r.cnt),fill:true,backgroundColor:'rgba(187,134,252,.1)',borderColor:C.purple,tension:.4,pointRadius:3}]
},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});

const hr=<?= json_encode($hourly) ?>;
const hCounts=Array(24).fill(0); hr.forEach(r=>hCounts[+r.h]=+r.cnt);
new Chart(document.getElementById('cHourly'),{type:'bar',data:{
    labels:Array.from({length:24},(_,i)=>i+':00'),
    datasets:[{label:'Akciók',data:hCounts,backgroundColor:'rgba(100,181,246,.55)',borderColor:C.blue,borderWidth:1}]
},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});

const ad=<?= json_encode($actionDist) ?>;
new Chart(document.getElementById('cActionDist'),{type:'doughnut',data:{
    labels:ad.map(r=>r.action_type),
    datasets:[{data:ad.map(r=>+r.cnt),backgroundColor:MULTI.slice(0,ad.length),borderWidth:0}]
},options:{responsive:true,plugins:{legend:{position:'right',labels:{boxWidth:12,font:{size:11}}}}}});

const ti=<?= json_encode($topImages) ?>;
new Chart(document.getElementById('cTopImages'),{type:'bar',data:{
    labels:ti.map(r=>r.image_filename.length>20?r.image_filename.substring(0,18)+'…':r.image_filename),
    datasets:[{label:'Megtekintés',data:ti.map(r=>+r.cnt),backgroundColor:'rgba(3,218,198,.65)',borderColor:C.teal,borderWidth:1}]
},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});
</script>
