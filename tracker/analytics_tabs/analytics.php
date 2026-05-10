<?php
$pf  = pw('created_at', $period);
$tf  = pTrend('created_at');
$pfa = pw('a.created_at', $period);

// ── Metrikák ──
$sessTotal   = qv($db, "SELECT COUNT(*) FROM log_analytics WHERE gallery_id = :gid $pf");
$avgTime     = round(qv($db, "SELECT AVG(time_on_page_sec) FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec>0 $pf") ?? 0);
$maxTime     = (int)qv($db, "SELECT MAX(time_on_page_sec) FROM log_analytics WHERE gallery_id = :gid $pf");
$avgScroll   = round(qv($db, "SELECT AVG(scroll_depth_percent) FROM log_analytics WHERE gallery_id = :gid $pf") ?? 0);
$bounce      = qv($db, "SELECT COUNT(*) FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec<15 $pf");
$superBounce = qv($db, "SELECT COUNT(*) FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec<5 $pf");
$deepRead    = qv($db, "SELECT COUNT(*) FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec>=300 AND scroll_depth_percent>=75 $pf");
$engaged     = qv($db, "SELECT COUNT(*) FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec>=60 AND scroll_depth_percent>=50 $pf");
$bounceRate  = $sessTotal > 0 ? round($bounce/$sessTotal*100,1) : 0;
$engagedPct  = $sessTotal > 0 ? round($engaged/$sessTotal*100,1) : 0;
$deepPct     = $sessTotal > 0 ? round($deepRead/$sessTotal*100,1) : 0;

$qualityScore = round(qv($db, "SELECT AVG(LEAST(time_on_page_sec/300,1)*50 + scroll_depth_percent/100*50) FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec>0 $pf") ?? 0);

$midPoint = qv($db, "SELECT created_at FROM log_analytics WHERE gallery_id = :gid $pf ORDER BY created_at LIMIT 1 OFFSET " . max(0, (int)($sessTotal/2)-1));
if ($midPoint) {
    $avgTimeFirst  = round(qv($db, "SELECT AVG(time_on_page_sec) FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec>0 $pf AND created_at <= :mp", [':mp'=>$midPoint]) ?? 0);
    $avgTimeSecond = round(qv($db, "SELECT AVG(time_on_page_sec) FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec>0 $pf AND created_at > :mp", [':mp'=>$midPoint]) ?? 0);
    $momentum = $avgTimeSecond - $avgTimeFirst;
} else {
    $avgTimeFirst = $avgTimeSecond = $avgTime; $momentum = 0;
}

$dailyTrend = q($db, "SELECT DATE(created_at) as d,
    COUNT(*) as cnt,
    ROUND(AVG(time_on_page_sec)) as avg_t,
    ROUND(AVG(scroll_depth_percent)) as avg_s,
    SUM(CASE WHEN time_on_page_sec<15 THEN 1 ELSE 0 END) as bounces
FROM log_analytics WHERE gallery_id = :gid $tf GROUP BY DATE(created_at) ORDER BY d");

$timeBuckets = q($db, "SELECT
    CASE WHEN time_on_page_sec<5   THEN '0-5mp (azonnali)'
         WHEN time_on_page_sec<15  THEN '5-15mp (bounce)'
         WHEN time_on_page_sec<60  THEN '15-60mp'
         WHEN time_on_page_sec<300 THEN '1-5 perc'
         WHEN time_on_page_sec<900 THEN '5-15 perc'
         ELSE '15+ perc (deep)'
    END as b, COUNT(*) as cnt
FROM log_analytics WHERE gallery_id = :gid $pf GROUP BY b ORDER BY MIN(time_on_page_sec)");

$scrollBuckets = q($db, "SELECT
    CASE WHEN scroll_depth_percent<10  THEN '0-10% (szinte semmi)'
         WHEN scroll_depth_percent<25  THEN '10-25%'
         WHEN scroll_depth_percent<50  THEN '25-50%'
         WHEN scroll_depth_percent<75  THEN '50-75%'
         WHEN scroll_depth_percent<90  THEN '75-90%'
         ELSE '90-100% (végigolvasott)'
    END as b, COUNT(*) as cnt
FROM log_analytics WHERE gallery_id = :gid $pf GROUP BY b ORDER BY MIN(scroll_depth_percent)");

$correlation = q($db, "SELECT
    FLOOR(scroll_depth_percent/20)*20 as s_grp,
    ROUND(AVG(time_on_page_sec)) as avg_t, COUNT(*) as cnt
FROM log_analytics WHERE gallery_id = :gid AND time_on_page_sec>0 AND scroll_depth_percent>0 $pf
GROUP BY s_grp ORDER BY s_grp");

$heatmap = q($db, "SELECT DAYOFWEEK(created_at)-1 as dow, HOUR(created_at) as h, COUNT(*) as cnt
FROM log_analytics WHERE gallery_id = :gid AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
GROUP BY dow, h ORDER BY dow, h");
$heatData = array_fill(0, 7, array_fill(0, 24, 0));
$heatMax = 0;
foreach ($heatmap as $r) {
    $heatData[$r['dow']][$r['h']] = (int)$r['cnt'];
    $heatMax = max($heatMax, (int)$r['cnt']);
}

$retBehavior = q($db, "SELECT
    CASE WHEN v.first_seen < v.last_seen THEN 'Visszatérő' ELSE 'Új' END as type,
    ROUND(AVG(a.time_on_page_sec)) as avg_t,
    ROUND(AVG(a.scroll_depth_percent)) as avg_s,
    COUNT(*) as sessions
FROM log_analytics a JOIN visitors v ON a.visitor_id=v.id WHERE a.gallery_id = :gid $pfa
GROUP BY type");

$actionCorr = q($db, "SELECT
    CASE WHEN ac=0 THEN '0 akció'
         WHEN ac<=2 THEN '1-2 akció'
         WHEN ac<=5 THEN '3-5 akció'
         ELSE '6+ akció'
    END as grp,
    ROUND(AVG(avg_scroll)) as avg_scroll,
    ROUND(AVG(avg_time)) as avg_time,
    COUNT(*) as sessions
FROM (
    SELECT a.visitor_id,
        ROUND(AVG(a.scroll_depth_percent)) as avg_scroll,
        ROUND(AVG(a.time_on_page_sec)) as avg_time,
        COUNT(DISTINCT ua.id) as ac
    FROM log_analytics a
    LEFT JOIN log_user_actions ua ON a.visitor_id=ua.visitor_id
        AND DATE(ua.created_at)=DATE(a.created_at) AND ua.gallery_id = a.gallery_id
    WHERE a.gallery_id = :gid $pfa
    GROUP BY a.visitor_id
) sub GROUP BY grp ORDER BY MIN(ac)");

$recent = q($db, "SELECT a.*, v.ip_address FROM log_analytics a
JOIN visitors v ON a.visitor_id=v.id WHERE a.gallery_id = :gid $pfa ORDER BY a.created_at DESC LIMIT 15");
?>
<!-- FÓKUSZ KÁRTYÁK -->
<div class="stat-grid">
    <div class="stat-card cyan"><div class="stat-label">Összes session</div><div class="stat-value"><?= number_format($sessTotal) ?></div></div>
    <div class="stat-card teal"><div class="stat-label">Átl. oldalon töltött idő</div><div class="stat-value"><?= fmtSec($avgTime) ?></div><div class="stat-sub">Max: <?= fmtSec($maxTime) ?></div></div>
    <div class="stat-card <?= $bounceRate>60?'danger':($bounceRate>40?'orange':'green') ?>">
        <div class="stat-label">Bounce Rate</div><div class="stat-value"><?= $bounceRate ?>%</div>
        <div class="stat-sub">Azonnali kilépés (<5mp): <?= $sessTotal>0?round($superBounce/$sessTotal*100):0 ?>%</div>
    </div>
    <div class="stat-card blue"><div class="stat-label">Átl. görgetési mélység</div><div class="stat-value"><?= $avgScroll ?>%</div></div>
    <div class="stat-card green"><div class="stat-label">Engaged</div><div class="stat-value"><?= $engagedPct ?>%</div><div class="stat-sub">≥60mp + ≥50% scroll</div></div>
    <div class="stat-card <?= $deepPct>10?'teal':'blue' ?>"><div class="stat-label">Deep Reader</div><div class="stat-value"><?= $deepPct ?>%</div><div class="stat-sub">≥5 perc + ≥75% scroll</div></div>
</div>

<!-- MINŐSÉG + MOMENTUM -->
<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);max-width:700px;margin-bottom:24px">
    <div class="stat-card <?= $qualityScore>60?'green':($qualityScore>35?'orange':'danger') ?>">
        <div class="stat-label">Session Quality Score</div>
        <div class="stat-value"><?= $qualityScore ?><span style="font-size:14px">/100</span></div>
        <div class="stat-sub">Idő + görgetés kombó</div>
    </div>
    <div class="stat-card <?= $momentum>0?'green':($momentum==0?'blue':'orange') ?>">
        <div class="stat-label">Momentum</div>
        <div class="stat-value"><?= $momentum>0?'+':'' ?><?= $momentum ?>mp</div>
        <div class="stat-sub">2. fél vs 1. fél átl. idő</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Átl. idő: Új vs. Visszatérő</div>
        <?php foreach ($retBehavior as $rb): ?>
        <div style="font-size:12px;margin-top:4px"><span style="color:var(--text-secondary)"><?= $rb['type'] ?>:</span> <strong style="color:var(--text);"><?= fmtSec((int)$rb['avg_t']) ?></strong> / <?= $rb['avg_s'] ?>% scroll</div>
        <?php endforeach; ?>
    </div>
</div>

<!-- NAPI TREND -->
<div class="chart-grid">
    <div class="chart-card tall">
        <h3>Napi session + átl. idő + bounce</h3>
        <canvas id="cTrend"></canvas>
    </div>
    <div class="chart-card">
        <h3>Látogató szegmensek</h3>
        <canvas id="cSeg"></canvas>
    </div>
</div>

<!-- ELOSZLÁSOK -->
<div class="chart-grid">
    <div class="chart-card">
        <h3>Oldalon töltött idő eloszlása</h3>
        <canvas id="cTimeDist"></canvas>
    </div>
    <div class="chart-card">
        <h3>Görgetési mélység eloszlása</h3>
        <canvas id="cScrollDist"></canvas>
    </div>
</div>

<!-- KORRELÁCIÓ + INTERAKCIÓ HATÁS -->
<div class="chart-grid">
    <div class="chart-card">
        <h3>Görgetés → átl. oldalon töltött idő</h3>
        <canvas id="cCorr"></canvas>
    </div>
    <div class="chart-card">
        <h3>Interakció aktivitás → Engagement</h3>
        <canvas id="cActCorr"></canvas>
    </div>
</div>

<!-- NAP×ÓRA HEATMAP -->
<div class="table-card section-gap">
    <div class="table-card-header"><h3>🗓️ Nap × Óra aktivitási heatmap (utolsó 90 nap)</h3></div>
    <div class="tbl-wrap" style="padding:16px">
        <?php
        $days = ['V','H','K','Sze','Cs','P','Szo'];
        echo '<table style="border-collapse:separate;border-spacing:2px;font-size:11px;width:auto;margin:0 auto;">';
        echo '<tr><th style="padding:2px 6px;color:var(--text-secondary);background:none;"></th>';
        for ($h=0; $h<24; $h++) echo "<th style='padding:2px 4px;color:var(--text-secondary);text-align:center;background:none;'>$h</th>";
        echo '</tr>';
        for ($d=0; $d<7; $d++) {
            echo "<tr><td style='padding:2px 8px;color:var(--text-secondary);white-space:nowrap;border:none;'>{$days[$d]}</td>";
            for ($h=0; $h<24; $h++) {
                $val = $heatData[$d][$h];
                $pct = $heatMax > 0 ? $val/$heatMax : 0;
                $alpha = round($pct * 0.85 + ($pct>0 ? 0.08 : 0), 2);
                $bg = "rgba(0,212,255,$alpha)";
                $color = $pct > 0.5 ? '#000' : '#888';
                echo "<td title='{$days[$d]} {$h}:00 — $val session' style='width:26px;height:22px;background:$bg;border-radius:3px;text-align:center;color:$color;cursor:default;border:none;'>" . ($val>0?$val:'') . "</td>";
            }
            echo '</tr>';
        }
        echo '</table>';
        ?>
        <div style="margin-top:8px;font-size:11px;color:var(--text-secondary);text-align:center;">Sötétebb = több session. V=Vasárnap, H=Hétfő… Szo=Szombat</div>
    </div>
</div>

<!-- UTOLSÓ SESSIONÖK -->
<div class="table-card">
    <div class="table-card-header"><h3>Legutóbbi sessionök</h3></div>
    <div class="tbl-wrap"><table>
        <thead><tr><th>Időpont</th><th>IP</th><th>Oldalon töltött idő</th><th>Görgetés</th><th>Quality</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $s):
            $t=$s['time_on_page_sec']; $sc=$s['scroll_depth_percent'];
            $qs = min(100, round((min($t/300,1)*50) + ($sc/100*50)));
        ?>
        <tr>
            <td style="white-space:nowrap;font-size:12px"><?= $s['created_at'] ?></td>
            <td><span class="badge-ip"><?= htmlspecialchars($s['ip_address']) ?></span></td>
            <td class="<?= $t<15?'val-bad':($t<60?'val-warn':'val-good') ?>"><?= fmtSec($t) ?></td>
            <td>
                <div class="progress-wrap">
                    <div class="progress-bar"><div class="progress-fill" style="width:<?= $sc ?>%;background:<?= $sc>60?'var(--teal)':($sc>30?'var(--orange)':'var(--danger)') ?>"></div></div>
                    <span class="progress-pct"><?= $sc ?>%</span>
                </div>
            </td>
            <td>
                <div class="progress-wrap">
                    <div class="progress-bar" style="min-width:50px"><div class="progress-fill" style="width:<?= $qs ?>%;background:<?= $qs>60?'var(--color-success)':($qs>35?'var(--color-warning)':'var(--color-error)') ?>"></div></div>
                    <span class="progress-pct"><?= $qs ?></span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<script>
// Napi trend
var C={cyan:'rgba(0,212,255,.8)',teal:'rgba(78,205,196,.8)',blue:'rgba(58,123,213,.8)',orange:'rgba(255,202,40,.8)',red:'rgba(255,71,87,.8)'};
var MULTI=[C.teal,C.cyan,C.blue,C.orange,C.red,'rgba(46,213,115,.8)','rgba(168,85,247,.8)','rgba(240,98,146,.8)'];

const dt=<?= json_encode($dailyTrend) ?>;
const dp=<?= json_encode($dailyPages) ?>;
new Chart(document.getElementById('cTrend'),{type:'bar',data:{
    labels:dt.map(r=>r.d.slice(5)),
    datasets:[
        {label:'Sessionök',data:dt.map(r=>+r.cnt),backgroundColor:'rgba(0,212,255,.15)',borderColor:C.cyan,borderWidth:1,yAxisID:'y'},
        {label:'Átl. idő (mp)',data:dt.map(r=>+r.avg_t),type:'line',borderColor:C.teal,backgroundColor:'transparent',tension:.4,pointRadius:2,yAxisID:'y2'},
        {label:'Átl. scroll %',data:dt.map(r=>+r.avg_s),type:'line',borderColor:C.orange,backgroundColor:'transparent',tension:.4,pointRadius:2,yAxisID:'y2'},
        {label:'Bounce',data:dt.map(r=>+r.bounces),type:'line',borderColor:C.red,backgroundColor:'transparent',tension:.4,pointRadius:2,borderDash:[4,3],yAxisID:'y'}
    ]
},options:{responsive:true,interaction:{mode:'index'},plugins:{legend:{labels:{boxWidth:10,font:{size:11}}}},
    scales:{y:{beginAtZero:true,position:'left'},y2:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false}}}}});

// Szegmensek
const superB=<?= $superBounce ?>, normalB=<?= $bounce-$superBounce ?>, med=<?= $sessTotal-$bounce-$engaged ?>,
    eng=<?= $engaged-$deepRead ?>, deep=<?= $deepRead ?>;
new Chart(document.getElementById('cSeg'),{type:'doughnut',data:{
    labels:['Azonnali (<5mp)','Bounce (5-15mp)','Közepes','Engaged','Deep Reader'],
    datasets:[{data:[superB,normalB,Math.max(0,med),Math.max(0,eng),deep],
        backgroundColor:['rgba(207,102,121,.9)','rgba(255,100,80,.6)','rgba(255,183,77,.6)','rgba(100,181,246,.7)','rgba(3,218,198,.8)'],borderWidth:0}]
},options:{responsive:true,plugins:{legend:{position:'right',labels:{boxWidth:10,font:{size:11}}}}}});

// Idő eloszlás
const tb=<?= json_encode($timeBuckets) ?>;
new Chart(document.getElementById('cTimeDist'),{type:'bar',data:{
    labels:tb.map(r=>r.b),
    datasets:[{label:'Session',data:tb.map(r=>+r.cnt),backgroundColor:['rgba(207,102,121,.7)','rgba(255,150,80,.7)','rgba(255,183,77,.7)','rgba(100,181,246,.7)','rgba(3,218,198,.7)','rgba(129,199,132,.7)'],borderWidth:0}]
},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});

// Scroll eloszlás
const sb=<?= json_encode($scrollBuckets) ?>;
new Chart(document.getElementById('cScrollDist'),{type:'bar',data:{
    labels:sb.map(r=>r.b),
    datasets:[{label:'Session',data:sb.map(r=>+r.cnt),backgroundColor:['rgba(207,102,121,.7)','rgba(255,150,80,.7)','rgba(255,183,77,.7)','rgba(100,181,246,.7)','rgba(3,218,198,.7)','rgba(129,199,132,.7)'],borderWidth:0}]
},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});

// Scroll→idő korreláció
const cr=<?= json_encode($correlation) ?>;
new Chart(document.getElementById('cCorr'),{type:'bar',data:{
    labels:cr.map(r=>r.s_grp+'%+'),
    datasets:[
        {label:'Átl. idő (mp)',data:cr.map(r=>+r.avg_t),backgroundColor:'rgba(100,181,246,.7)',yAxisID:'y'},
        {label:'Session db',data:cr.map(r=>+r.cnt),type:'line',borderColor:C.orange,backgroundColor:'transparent',tension:.4,pointRadius:3,yAxisID:'y2'}
    ]
},options:{responsive:true,interaction:{mode:'index'},plugins:{legend:{labels:{boxWidth:10}}},
    scales:{y:{beginAtZero:true,position:'left'},y2:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false}}}}});

// Interakció → engagement
const ac=<?= json_encode($actionCorr) ?>;
new Chart(document.getElementById('cActCorr'),{type:'bar',data:{
    labels:ac.map(r=>r.grp),
    datasets:[
        {label:'Átl. scroll %',data:ac.map(r=>+r.avg_scroll),backgroundColor:'rgba(3,218,198,.6)',yAxisID:'y'},
        {label:'Átl. idő (mp)',data:ac.map(r=>+r.avg_time),backgroundColor:'rgba(187,134,252,.6)',yAxisID:'y2'}
    ]
},options:{responsive:true,interaction:{mode:'index'},plugins:{legend:{labels:{boxWidth:10}}},
    scales:{y:{beginAtZero:true,position:'left',title:{display:true,text:'Scroll %'}},
           y2:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},title:{display:true,text:'Idő (mp)'}}}}});
</script>
