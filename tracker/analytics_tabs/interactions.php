<?php
// analytics_tabs/interactions.php
$pf = pw('created_at', $period);

// Pagination
$page    = max(1, (int)($_GET['page'] ?? 1));
$pp      = (int)($_GET['per_page'] ?? 25);
$perPage = in_array($pp, [10,25,50,100]) ? $pp : 25;

// Filter params — whitelist validated
$allTypes    = q($db, "SELECT DISTINCT action_type FROM log_user_actions WHERE gallery_id = :gid ORDER BY action_type");
$allSections = q($db, "SELECT DISTINCT page_section FROM log_user_actions WHERE gallery_id = :gid AND page_section IS NOT NULL ORDER BY page_section");

$filterType    = $_GET['f_type']    ?? '';
$filterSection = $_GET['f_section'] ?? '';
$filterIp      = trim($_GET['f_ip'] ?? '');

// Validate whitelist for action_type
$validTypes = array_column($allTypes, 'action_type');
if ($filterType && !in_array($filterType, $validTypes)) $filterType = '';

$filterSql   = '';
$filterParams = [];
if ($filterType)    { $filterSql .= " AND a.action_type = :f_type";    $filterParams[':f_type'] = $filterType; }
if ($filterSection) { $filterSql .= " AND a.page_section = :f_sec";   $filterParams[':f_sec'] = $filterSection; }
if ($filterIp)      { $filterSql .= " AND v.ip_address LIKE :f_ip";    $filterParams[':f_ip'] = "%$filterIp%"; }

$hasFilter = ($filterType || $filterSection || $filterIp);

// Stats (unfiltered for overview)
$totalActions  = qv($db,"SELECT COUNT(*) FROM log_user_actions WHERE gallery_id = :gid $pf");
$copyCount     = qv($db,"SELECT COUNT(*) FROM log_user_actions WHERE gallery_id = :gid AND action_type='copy_text' $pf");
$rageCount     = qv($db,"SELECT COUNT(*) FROM log_user_actions WHERE gallery_id = :gid AND action_type='rage_click' $pf");
$deadCount     = qv($db,"SELECT COUNT(*) FROM log_user_actions WHERE gallery_id = :gid AND action_type='dead_click' $pf");
$secCount      = qv($db,"SELECT COUNT(*) FROM log_user_actions WHERE gallery_id = :gid AND action_type IN ('image_context_menu','image_drag_start','save_page_attempt') $pf");

// Charts
$byType   = q($db,"SELECT action_type, COUNT(*) as cnt FROM log_user_actions WHERE gallery_id = :gid $pf GROUP BY action_type ORDER BY cnt DESC");
$dailyAct = q($db,"SELECT DATE(created_at) as d,
    SUM(CASE WHEN action_type='copy_text' THEN 1 ELSE 0 END) as copies,
    SUM(CASE WHEN action_type='rage_click' THEN 1 ELSE 0 END) as rage,
    SUM(CASE WHEN action_type='dead_click' THEN 1 ELSE 0 END) as dead,
    SUM(CASE WHEN action_type IN ('image_context_menu','image_drag_start','save_page_attempt') THEN 1 ELSE 0 END) as security
    FROM log_user_actions WHERE gallery_id = :gid " . pTrend('created_at') . "
    GROUP BY DATE(created_at) ORDER BY d");
$bySection = q($db,"SELECT page_section, COUNT(*) as cnt FROM log_user_actions WHERE gallery_id = :gid AND page_section IS NOT NULL $pf GROUP BY page_section ORDER BY cnt DESC");
$rageSel   = q($db,"SELECT target_selector, COUNT(*) as cnt FROM log_user_actions WHERE gallery_id = :gid AND action_type='rage_click' AND target_selector IS NOT NULL $pf GROUP BY target_selector ORDER BY cnt DESC LIMIT 8");

// Hourly heatmap for interactions
$hourly = q($db,"SELECT HOUR(created_at) as h, COUNT(*) as cnt FROM log_user_actions WHERE gallery_id = :gid $pf GROUP BY HOUR(created_at) ORDER BY h");

// Filtered main table
$joinSql = "FROM log_user_actions a JOIN visitors v ON a.visitor_id=v.id WHERE a.gallery_id = :gid $pf";
$totalFiltered = (int)qv($db, "SELECT COUNT(*) $joinSql $filterSql", $filterParams);
$offset = ($page - 1) * $perPage;
$rows   = q($db, "SELECT a.action_type, a.target_selector, a.target_text,
        a.page_section, a.metadata, a.created_at, v.ip_address
        $joinSql $filterSql
        ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset", $filterParams);

// Base params
$bpArr = bp($tab, $period, $date_from, $date_to, array_filter([
    'f_type'    => $filterType,
    'f_section' => $filterSection,
    'f_ip'      => $filterIp,
    'per_page'  => $perPage !== 25 ? $perPage : null,
]));

$typeColors = [
    'copy_text'           => 'badge-teal',
    'rage_click'          => 'badge-orange',
    'dead_click'          => 'badge-blue',
    'image_context_menu'  => 'badge-red',
    'image_drag_start'    => 'badge-red',
    'save_page_attempt'   => 'badge-red',
    'battery_status'      => 'badge-green',
    'network_info'        => 'badge-cyan',
];
$typeIcons = [
    'copy_text'           => '📋',
    'rage_click'          => '😡',
    'dead_click'          => '💀',
    'image_context_menu'  => '🔒',
    'image_drag_start'    => '🔒',
    'save_page_attempt'   => '🔒',
    'battery_status'      => '🔋',
    'network_info'        => '📶',
];
?>

<div class="stat-grid">
    <div class="stat-card cyan"><div class="stat-label">Összes akció</div><div class="stat-value"><?= number_format($totalActions) ?></div></div>
    <div class="stat-card teal"><div class="stat-label">Másolás</div><div class="stat-value"><?= number_format($copyCount) ?></div></div>
    <div class="stat-card orange"><div class="stat-label">Rage click</div><div class="stat-value"><?= number_format($rageCount) ?></div></div>
    <div class="stat-card blue"><div class="stat-label">Dead click</div><div class="stat-value"><?= number_format($deadCount) ?></div></div>
    <div class="stat-card danger"><div class="stat-label">Biztonsági esemény</div><div class="stat-value"><?= number_format($secCount) ?></div></div>
</div>

<div class="chart-grid">
    <div class="chart-card tall"><h3>Napi interakciók (stacked)</h3><canvas id="cActTrend"></canvas></div>
    <div class="chart-card"><h3>Akció típusok</h3><canvas id="cActType"></canvas></div>
</div>
<div class="chart-grid">
    <div class="chart-card"><h3>Szekció aktivitás</h3><canvas id="cSection"></canvas></div>
    <div class="chart-card"><h3>Rage click célpontok</h3><canvas id="cRage"></canvas></div>
</div>

<!-- ═══ SZŰRŐ PANEL ═══ -->
<div class="table-card section-gap">
    <div class="table-card-header">
        <h3>🔍 Szűrők</h3>
        <?php if ($hasFilter): ?>
        <span class="badge badge-cyan">Aktív szűrő</span>
        <?php endif; ?>
    </div>
    <form method="GET" style="padding:16px 20px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="id" value="<?= $galleryId ?>">
        <input type="hidden" name="tab" value="interactions">
        <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
        <?php if ($date_from): ?>
        <input type="hidden" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
        <input type="hidden" name="date_to"   value="<?= htmlspecialchars($date_to) ?>">
        <?php endif; ?>

        <!-- Akció típus -->
        <div style="flex:0 0 auto">
            <label style="display:block;font-size:11px;color:var(--text-secondary);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px">Akció típus</label>
            <select name="f_type" class="form-control" style="min-width:180px;height:34px;padding:4px;">
                <option value="">— Összes típus —</option>
                <?php foreach ($allTypes as $at): ?>
                <option value="<?= htmlspecialchars($at['action_type']) ?>" <?= $filterType===$at['action_type']?'selected':'' ?>>
                    <?= ($typeIcons[$at['action_type']] ?? '•') . ' ' . htmlspecialchars($at['action_type']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Szekció -->
        <div style="flex:0 0 auto">
            <label style="display:block;font-size:11px;color:var(--text-secondary);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px">Oldal szekció</label>
            <select name="f_section" class="form-control" style="min-width:150px;height:34px;padding:4px;">
                <option value="">— Összes szekció —</option>
                <?php foreach ($allSections as $s): ?>
                <option value="<?= htmlspecialchars($s['page_section']) ?>" <?= $filterSection===$s['page_section']?'selected':'' ?>>
                    <?= htmlspecialchars($s['page_section']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- IP szűrő -->
        <div style="flex:1;min-width:160px">
            <label style="display:block;font-size:11px;color:var(--text-secondary);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px">IP cím (részleges)</label>
            <input type="text" name="f_ip" value="<?= htmlspecialchars($filterIp) ?>"
                   class="form-control" placeholder="pl. 192.168" style="height:34px;padding:4px;">
        </div>

        <!-- Per-page -->
        <div style="flex:0 0 auto">
            <label style="display:block;font-size:11px;color:var(--text-secondary);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px">Sorok</label>
            <select name="per_page" class="form-control" style="height:34px;padding:4px;">
                <?php foreach ([10,25,50,100] as $pp2): ?>
                <option value="<?= $pp2 ?>" <?= $perPage===$pp2?'selected':'' ?>><?= $pp2 ?>/oldal</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;gap:8px;padding-bottom:1px">
            <button type="submit" class="btn btn-primary" style="height:34px;padding:4px 12px;">Szűrés</button>
            <?php if ($hasFilter): ?>
            <a href="?<?= http_build_query(array_filter(['id'=>$galleryId,'tab'=>$tab,'period'=>$period,'date_from'=>$date_from,'date_to'=>$date_to])) ?>" class="btn btn-danger" style="height:34px;padding:4px 12px;text-decoration:none;display:inline-block;line-height:24px;">✕ Törlés</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($hasFilter): ?>
    <div style="padding:8px 20px 12px;display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($filterType): ?>
        <span class="badge badge-teal"><?= ($typeIcons[$filterType]??'') ?> <?= htmlspecialchars($filterType) ?></span>
        <?php endif; ?>
        <?php if ($filterSection): ?>
        <span class="badge badge-blue">📍 <?= htmlspecialchars($filterSection) ?></span>
        <?php endif; ?>
        <?php if ($filterIp): ?>
        <span class="badge badge-ip">IP: <?= htmlspecialchars($filterIp) ?></span>
        <?php endif; ?>
        <span style="font-size:11px;color:var(--text-secondary);align-self:center"><?= number_format($totalFiltered) ?> találat</span>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ FŐ TÁBLÁZAT ═══ -->
<div class="table-card">
    <div class="table-card-header">
        <h3>Interakciók (<?= number_format($totalFiltered) ?>)</h3>
        <div style="display:flex;gap:6px;align-items:center">
            <!-- Quick filter gombcsoport -->
            <?php
            $qFilters = [
                ''                   => 'Összes',
                'copy_text'          => '📋 Másolás',
                'rage_click'         => '😡 Rage',
                'dead_click'         => '💀 Dead',
                'image_context_menu' => '🔒 Jobb klikk',
                'battery_status'     => '🔋 Akku',
                'network_info'       => '📶 Net',
            ];
            foreach ($qFilters as $qf => $ql):
                $isActive = $filterType === $qf && !$filterSection && !$filterIp;
                $href = '?' . http_build_query(array_filter(['id'=>$galleryId,'tab'=>$tab,'period'=>$period,'date_from'=>$date_from,'date_to'=>$date_to,'f_type'=>$qf,'page'=>1]));
            ?>
            <a href="<?= $href ?>" class="period-btn <?= $isActive?'active':'' ?>" style="font-size:11px;padding:3px 10px">
                <?= $ql ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="tbl-wrap"><table>
        <thead><tr>
            <th>Időpont</th>
            <th>IP</th>
            <th>Akció típus</th>
            <th>Szekció</th>
            <th>Célpont / Szöveg</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $bc = $typeColors[$r['action_type']] ?? 'badge-cyan';
            $ic = $typeIcons[$r['action_type']]  ?? '•';
        ?>
        <tr>
            <td style="white-space:nowrap;font-size:12px;color:var(--text-secondary)"><?= $r['created_at'] ?></td>
            <td><span class="badge badge-ip"><?= htmlspecialchars($r['ip_address']) ?></span></td>
            <td><span class="badge <?= $bc ?>"><?= $ic ?> <?= htmlspecialchars($r['action_type']) ?></span></td>
            <td style="font-size:12px;color:var(--text-secondary)"><?= $r['page_section'] ? htmlspecialchars($r['page_section']) : '—' ?></td>
            <td style="font-size:12px;max-width:280px;overflow:hidden;text-overflow:ellipsis">
                <?php if ($r['action_type'] === 'battery_status'): 
                    $meta = json_decode($r['metadata'], true) ?: [];
                ?>
                    <span style="color:var(--color-success)">Szint: <?= $meta['level'] ?? '?' ?> (<?= !empty($meta['charging']) ? 'Töltésen' : 'Akku' ?>)</span>
                <?php elseif ($r['action_type'] === 'network_info'): 
                    $meta = json_decode($r['metadata'], true) ?: [];
                ?>
                    <span style="color:var(--accent-primary)">Típus: <?= $meta['effectiveType'] ?? '?' ?> (<?= $meta['downlink'] ?? '?' ?>Mbps, <?= $meta['rtt'] ?? '?' ?>ms RTT)</span>
                <?php elseif ($r['target_text']): ?>
                <span style="color:var(--accent-primary);font-style:italic">"<?= htmlspecialchars(mb_strimwidth($r['target_text'],0,80,'…')) ?>"</span>
                <?php elseif ($r['target_selector']): ?>
                <code style="font-size:11px;color:var(--text-secondary)"><?= htmlspecialchars(mb_strimwidth($r['target_selector'],0,80,'…')) ?></code>
                <?php else: ?>
                <span style="color:var(--text-secondary)">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-secondary);padding:24px">Nincs találat a megadott szűrőkre.</td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>
    <?= pagHtml($totalFiltered, $perPage, $page, $bpArr) ?>
</div>

<script>
const C={cyan:'rgba(0,212,255,.8)',teal:'rgba(78,205,196,.8)',blue:'rgba(58,123,213,.8)',orange:'rgba(255,202,40,.8)',red:'rgba(255,71,87,.8)'};
const MULTI=[C.teal,C.cyan,C.blue,C.orange,C.red,'rgba(46,213,115,.8)','rgba(168,85,247,.8)','rgba(240,98,146,.8)'];

const da=<?= json_encode($dailyAct) ?>;
new Chart(document.getElementById('cActTrend'),{type:'bar',data:{
    labels:da.map(r=>r.d.slice(5)),
    datasets:[
        {label:'Másolás',data:da.map(r=>+r.copies),backgroundColor:'rgba(78,205,196,.55)',stack:'s'},
        {label:'Rage',data:da.map(r=>+r.rage),backgroundColor:'rgba(255,202,40,.55)',stack:'s'},
        {label:'Dead',data:da.map(r=>+r.dead),backgroundColor:'rgba(58,123,213,.55)',stack:'s'},
        {label:'Biztonsági',data:da.map(r=>+r.security),backgroundColor:'rgba(255,71,87,.55)',stack:'s'}
    ]
},options:{responsive:true,plugins:{legend:{labels:{boxWidth:10,font:{size:11}}}},scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true}}}});

const bt=<?= json_encode($byType) ?>;
new Chart(document.getElementById('cActType'),{type:'doughnut',data:{
    labels:bt.map(r=>r.action_type),
    datasets:[{data:bt.map(r=>+r.cnt),backgroundColor:MULTI.slice(0,bt.length),borderWidth:0}]
},options:{responsive:true,plugins:{legend:{position:'right',labels:{boxWidth:10,font:{size:11}}}}}});

const bs=<?= json_encode($bySection) ?>;
new Chart(document.getElementById('cSection'),{type:'bar',data:{
    labels:bs.map(r=>r.page_section||'?'),
    datasets:[{label:'Akciók',data:bs.map(r=>+r.cnt),backgroundColor:'rgba(0,212,255,.55)',borderColor:C.cyan,borderWidth:1}]
},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});

const rs=<?= json_encode($rageSel) ?>;
new Chart(document.getElementById('cRage'),{type:'bar',data:{
    labels:rs.map(r=>r.target_selector||'?'),
    datasets:[{label:'Rage clicks',data:rs.map(r=>+r.cnt),backgroundColor:'rgba(255,202,40,.6)',borderColor:C.orange,borderWidth:1}]
},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});
</script>
