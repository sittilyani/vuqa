<?php
// transitions/transition_dashboard.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$county_id = isset($_GET['county']) ? (int)$_GET['county'] : 0;
$period    = isset($_GET['period']) ? mysqli_real_escape_string($conn, $_GET['period']) : '';

$counties_list = [];
$cr = mysqli_query($conn, "SELECT county_id, county_name FROM counties ORDER BY county_name");
if ($cr) while ($r = mysqli_fetch_assoc($cr)) $counties_list[] = $r;

$periods_list = [];
$pr = mysqli_query($conn, "SELECT DISTINCT assessment_period FROM transition_section_submissions ORDER BY assessment_period DESC");
if ($pr) while ($r = mysqli_fetch_assoc($pr)) $periods_list[] = $r['assessment_period'];

$cdoh_only_sections = ['leadership', 'institutional_ownership'];

$section_labels = [
    'leadership'             => 'Leadership & Governance',
    'supervision'            => 'Supervision & Mentorship',
    'special_initiatives'    => 'Special Initiatives',
    'quality_improvement'    => 'Quality Improvement',
    'identification_linkage' => 'Patient Identification',
    'retention_suppression'  => 'Patient Retention',
    'prevention_kp'          => 'Prevention & KP',
    'finance'                => 'Finance Management',
    'sub_grants'             => 'Sub-Grants',
    'commodities'            => 'Commodities Mgmt',
    'equipment'              => 'Equipment',
    'laboratory'             => 'Laboratory',
    'inventory'              => 'Inventory Mgmt',
    'training'               => 'In-Service Training',
    'hr_management'          => 'HR Management',
    'data_management'        => 'Data Management',
    'patient_monitoring'     => 'Patient Monitoring',
    'institutional_ownership'=> 'Institutional Ownership',
];

$where_parts = ["1=1"];
if ($county_id) $where_parts[] = "tss.county_id = $county_id";
if ($period)    $where_parts[] = "tss.assessment_period = '$period'";
$where = "WHERE " . implode(" AND ", $where_parts);

$q = "
    SELECT
        tss.assessment_id,
        tss.county_id,
        c.county_name,
        tss.assessment_period,
        tss.section_key,
        tss.submitted_at,
        tss.sub_count,
        tss.avg_cdoh,
        tss.avg_ip,
        COALESCE(tss.cdoh_percent, 0)    AS cdoh_pct,
        COALESCE(tss.ip_percent,   0)    AS ip_pct,
        COALESCE(tss.cdoh_ip_overlap, 0) AS overlap_pct,
        CASE WHEN tss.ip_percent > tss.cdoh_percent
             THEN ROUND(tss.ip_percent - tss.cdoh_percent, 2) ELSE 0 END AS gap_pct,
        (SELECT COUNT(*) FROM transition_raw_scores r WHERE r.assessment_id=tss.assessment_id AND r.section_key=tss.section_key AND r.cdoh_score=4) AS s4,
        (SELECT COUNT(*) FROM transition_raw_scores r WHERE r.assessment_id=tss.assessment_id AND r.section_key=tss.section_key AND r.cdoh_score=3) AS s3,
        (SELECT COUNT(*) FROM transition_raw_scores r WHERE r.assessment_id=tss.assessment_id AND r.section_key=tss.section_key AND r.cdoh_score=2) AS s2,
        (SELECT COUNT(*) FROM transition_raw_scores r WHERE r.assessment_id=tss.assessment_id AND r.section_key=tss.section_key AND r.cdoh_score=1) AS s1,
        (SELECT COUNT(*) FROM transition_raw_scores r WHERE r.assessment_id=tss.assessment_id AND r.section_key=tss.section_key AND r.cdoh_score=0) AS s0
    FROM transition_section_submissions tss
    JOIN counties c ON c.county_id = tss.county_id
    $where
    ORDER BY tss.county_id, tss.assessment_period DESC, tss.section_key
";
$result = mysqli_query($conn, $q);

$raw_data = [];
if ($result) while ($row = mysqli_fetch_assoc($result)) {
    $cn = $row['county_name'];
    $p  = $row['assessment_period'];
    $sk = $row['section_key'];
    if (!isset($raw_data[$cn])) $raw_data[$cn] = ['county_id'=>$row['county_id'],'periods'=>[]];
    if (!isset($raw_data[$cn]['periods'][$p])) $raw_data[$cn]['periods'][$p] = [];
    $raw_data[$cn]['periods'][$p][$sk] = $row;
}

$overall_data = [];
foreach ($raw_data as $cn => $cd) {
    $lp = array_key_first($cd['periods']);
    $secs = $cd['periods'][$lp];
    $cdoh_vals = array_filter(array_column($secs,'cdoh_pct'), fn($v)=>$v>0);
    $ip_vals   = array_filter(array_column($secs,'ip_pct'),   fn($v)=>$v>0);
    $avg_c = count($cdoh_vals)>0 ? round(array_sum($cdoh_vals)/count($cdoh_vals),1) : 0;
    $avg_i = count($ip_vals)>0   ? round(array_sum($ip_vals)/count($ip_vals),1)     : 0;
    $overall_data[$cn] = ['county_id'=>$cd['county_id'],'cdoh_pct'=>$avg_c,'ip_pct'=>$avg_i,'period'=>$lp,
        'readiness'=>$avg_c>=70?'Transition':($avg_c>=50?'Support and Monitor':'Not Ready'),'sections'=>count($secs)];
}

$total_counties   = count($overall_data);
$transition_count = count(array_filter($overall_data,fn($d)=>$d['readiness']==='Transition'));
$support_count    = count(array_filter($overall_data,fn($d)=>$d['readiness']==='Support and Monitor'));
$not_ready_count  = count(array_filter($overall_data,fn($d)=>$d['readiness']==='Not Ready'));
$all_cdoh_vals    = array_column($overall_data,'cdoh_pct');
$avg_overall      = count($all_cdoh_vals)>0 ? round(array_sum($all_cdoh_vals)/count($all_cdoh_vals),1) : 0;

$all_county_chart = [];
foreach ($raw_data as $cn => $cd) {
    $lp = array_key_first($cd['periods']);
    $secs = $cd['periods'][$lp];
    $entry = ['sections'=>[],'period'=>$lp,'cdoh_overall'=>$overall_data[$cn]['cdoh_pct'],
              'ip_overall'=>$overall_data[$cn]['ip_pct'],'readiness'=>$overall_data[$cn]['readiness']];
    foreach ($secs as $sk => $row) {
        $tot = max(1,(int)$row['sub_count']);
        $is_co = in_array($sk,$cdoh_only_sections);
        $entry['sections'][] = [
            'key'=>$sk,'label'=>$section_labels[$sk]??$sk,
            'cdoh_pct'=>(float)$row['cdoh_pct'],'ip_pct'=>$is_co?null:(float)$row['ip_pct'],
            'overlap'=>(float)$row['overlap_pct'],'gap'=>(float)$row['gap_pct'],'cdoh_only'=>$is_co,
            's4'=>round($row['s4']/$tot*100,1),'s3'=>round($row['s3']/$tot*100,1),
            's2'=>round($row['s2']/$tot*100,1),'s1'=>round($row['s1']/$tot*100,1),
            's0'=>round($row['s0']/$tot*100,1),'total'=>(int)$row['sub_count'],
        ];
    }
    $all_county_chart[$cn] = $entry;
}

// All Counties aggregate
$all_secs_agg = [];
foreach ($all_county_chart as $cn => $entry) {
    foreach ($entry['sections'] as $sec) {
        $sk = $sec['key'];
        if (!isset($all_secs_agg[$sk])) $all_secs_agg[$sk]=['cdoh'=>[],'ip'=>[],'s0'=>0,'s1'=>0,'s2'=>0,'s3'=>0,'s4'=>0,'n'=>0,'label'=>$sec['label'],'cdoh_only'=>$sec['cdoh_only']];
        $all_secs_agg[$sk]['cdoh'][]=$sec['cdoh_pct'];
        if ($sec['ip_pct']!==null) $all_secs_agg[$sk]['ip'][]=$sec['ip_pct'];
        foreach(['s0','s1','s2','s3','s4'] as $sn) $all_secs_agg[$sk][$sn]+=$sec[$sn];
        $all_secs_agg[$sk]['n']++;
    }
}
$all_agg_entry=['sections'=>[],'period'=>'All Periods','cdoh_overall'=>$avg_overall,
    'ip_overall'=>count($overall_data)>0?round(array_sum(array_column($overall_data,'ip_pct'))/count($overall_data),1):0,
    'readiness'=>$avg_overall>=70?'Transition':($avg_overall>=50?'Support and Monitor':'Not Ready')];
foreach ($all_secs_agg as $sk => $agg) {
    $ca = count($agg['cdoh'])>0?round(array_sum($agg['cdoh'])/count($agg['cdoh']),1):0;
    $ia = count($agg['ip'])>0?round(array_sum($agg['ip'])/count($agg['ip']),1):null;
    $n  = max(1,$agg['n']);
    $all_agg_entry['sections'][]=['key'=>$sk,'label'=>$agg['label'],'cdoh_pct'=>$ca,'ip_pct'=>$ia,
        'overlap'=>$ia!==null?round(min($ca,$ia),1):$ca,'gap'=>$ia!==null?round(max(0,$ia-$ca),1):0,'cdoh_only'=>$agg['cdoh_only'],
        's4'=>round($agg['s4']/$n,1),'s3'=>round($agg['s3']/$n,1),'s2'=>round($agg['s2']/$n,1),
        's1'=>round($agg['s1']/$n,1),'s0'=>round($agg['s0']/$n,1),'total'=>$n];
}
$all_county_chart['All Counties'] = $all_agg_entry;

$default_county = $county_id
    ? (array_key_first(array_filter($overall_data,fn($d)=>$d['county_id']==$county_id)) ?? 'All Counties')
    : 'All Counties';

$all_county_json  = json_encode($all_county_chart);
$cmp_labels_json  = json_encode(array_keys($overall_data));
$cmp_cdoh_json    = json_encode(array_column($overall_data,'cdoh_pct'));
$cmp_ip_json      = json_encode(array_column($overall_data,'ip_pct'));
$cmp_colors_json  = json_encode(array_map(fn($d)=>$d['cdoh_pct']>=70?'#28a745':($d['cdoh_pct']>=50?'#ffc107':'#dc3545'),$overall_data));
$default_county_json = json_encode($default_county);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Transition Assessment Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f0f2f7;color:#333;line-height:1.6;}
.container{max-width:1600px;margin:0 auto;padding:20px;}
.page-header{background:linear-gradient(135deg,#0D1A63,#1a3a9e);color:#fff;padding:22px 30px;border-radius:14px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 6px 24px rgba(13,26,99,.25);}
.page-header h1{font-size:1.8rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.page-header .hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:8px 16px;border-radius:8px;font-size:13px;margin-left:8px;transition:background .2s;}
.page-header .hdr-links a:hover{background:rgba(255,255,255,.28);}
.filter-bar{background:#fff;border-radius:12px;padding:18px 22px;margin-bottom:24px;box-shadow:0 2px 14px rgba(0,0,0,.07);display:flex;flex-wrap:wrap;gap:15px;align-items:flex-end;}
.filter-group{flex:1;min-width:200px;}
.filter-group label{display:block;font-size:11px;font-weight:700;color:#666;margin-bottom:5px;text-transform:uppercase;}
.filter-group select{width:100%;padding:10px 12px;border:2px solid #e0e4f0;border-radius:8px;font-size:13px;}
.btn-filter{background:#0D1A63;color:#fff;border:none;padding:10px 24px;border-radius:8px;font-weight:600;cursor:pointer;}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px;margin-bottom:28px;}
.kpi-card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 20px rgba(0,0,0,.05);border-top:4px solid var(--kc);}
.kpi-val{font-size:38px;font-weight:900;color:var(--kc);line-height:1;}
.kpi-lbl{font-size:13px;color:#666;margin-top:6px;font-weight:500;}
.section-title{font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#888;margin:24px 0 14px;display:flex;align-items:center;gap:10px;}
.section-title::after{content:'';flex:1;height:1px;background:#e0e4f0;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.card{background:#fff;border-radius:14px;padding:0;box-shadow:0 4px 20px rgba(0,0,0,.05);overflow:hidden;}
.card-head{padding:16px 20px 14px;border-bottom:1px solid #e8ecf5;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.card-head h3{font-size:15px;font-weight:700;color:#0D1A63;display:flex;align-items:center;gap:8px;}
.card-body{padding:20px;}
.county-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;}
.county-tab{padding:7px 16px;background:#fff;border:2px solid #e0e4f0;border-radius:30px;font-size:13px;font-weight:600;color:#666;cursor:pointer;transition:all .2s;}
.county-tab.active{background:#0D1A63;color:#fff;border-color:#0D1A63;}
.readiness-badge{display:inline-block;padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700;}
.badge-transition{background:#d4edda;color:#155724;}
.badge-support{background:#fff3cd;color:#856404;}
.badge-not-ready{background:#f8d7da;color:#721c24;}
.legend{display:flex;gap:14px;flex-wrap:wrap;}
.legend-item{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;}
.legend-dot{width:12px;height:12px;border-radius:3px;}
.detail-table{width:100%;border-collapse:collapse;font-size:12px;}
.detail-table th{background:#f8fafc;padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;color:#888;border-bottom:1px solid #e8ecf5;}
.detail-table td{padding:8px 10px;border-bottom:1px solid #e8ecf5;vertical-align:middle;}
.detail-table tr:last-child td{border-bottom:none;}
.detail-table tr:hover td{background:#f8fafc;}
.no-data{text-align:center;padding:60px 20px;color:#aaa;}
.no-data i{font-size:48px;margin-bottom:16px;display:block;}
@media(max-width:900px){.grid-2{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="container">
<div class="page-header">
    <h1><i class="fas fa-chart-bar"></i> Transition Assessment Dashboard</h1>

    <div class="hdr-links">
        <a href="transition_ai_advisor.php?county=<?= $county_id ?>&period=<?= urlencode($period) ?>">
        <i class="fas fa-robot"></i> AI Advisor</a>
        <a href="transition_comparison_dashboard.php"><i class="fas fa-code-branch"></i> Compare</a>
        <a href="transition_index.php"><i class="fas fa-plus"></i> New Assessment</a>
        <a href="transition_index.php"><i class="fas fa-home"></i> Home</a>
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-group">
        <label>County</label>
        <select name="county">
            <option value="">All Counties</option>
            <?php foreach ($counties_list as $c): ?>
            <option value="<?= $c['county_id'] ?>" <?= $county_id==$c['county_id']?'selected':'' ?>><?= htmlspecialchars($c['county_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Assessment Period</label>
        <select name="period">
            <option value="">All Periods</option>
            <?php foreach ($periods_list as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>" <?= $period===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
    <a href="transition_dashboard.php" style="background:#e0e4f0;color:#333;padding:10px 18px;border-radius:8px;font-weight:600;text-decoration:none;font-size:13px;"><i class="fas fa-times"></i> Clear</a>
</form>

<div class="kpi-grid">
    <div class="kpi-card" style="--kc:#0D1A63"><div class="kpi-val"><?= $total_counties ?></div><div class="kpi-lbl"><i class="fas fa-map-marker-alt"></i> Counties Assessed</div></div>
    <div class="kpi-card" style="--kc:#28a745"><div class="kpi-val"><?= $transition_count ?></div><div class="kpi-lbl"><i class="fas fa-check-circle"></i> Ready to Transition</div></div>
    <div class="kpi-card" style="--kc:#ffc107"><div class="kpi-val"><?= $support_count ?></div><div class="kpi-lbl"><i class="fas fa-tools"></i> Support &amp; Monitor</div></div>
    <div class="kpi-card" style="--kc:#dc3545"><div class="kpi-val"><?= $not_ready_count ?></div><div class="kpi-lbl"><i class="fas fa-exclamation-triangle"></i> Not Ready</div></div>
    <div class="kpi-card" style="--kc:#0ABFBC"><div class="kpi-val"><?= $avg_overall ?>%</div><div class="kpi-lbl"><i class="fas fa-percentage"></i> Avg CDOH Score</div></div>
</div>

<?php if (empty($raw_data)): ?>
<div class="no-data"><i class="fas fa-chart-bar"></i><p>No assessment data found. <a href="transition_index.php">Start an assessment</a>.</p></div>
<?php else: ?>

<div class="section-title"><i class="fas fa-map-marker-alt"></i> Select County</div>
<div class="county-tabs" id="countyTabs">
    <div class="county-tab <?= !$county_id?'active':'' ?>" onclick="switchCounty('All Counties',this)">
        <i class="fas fa-globe-africa"></i> All Counties
        <span class="readiness-badge badge-<?= $avg_overall>=70?'transition':($avg_overall>=50?'support':'not-ready') ?>" style="margin-left:6px"><?= $avg_overall ?>%</span>
    </div>
    <?php foreach ($overall_data as $cn => $od): ?>
    <div class="county-tab <?= ($county_id&&$od['county_id']==$county_id)?'active':'' ?>" onclick="switchCounty('<?= htmlspecialchars(addslashes($cn)) ?>',this)">
        <?= htmlspecialchars($cn) ?>
        <span class="readiness-badge <?= $od['cdoh_pct']>=70?'badge-transition':($od['cdoh_pct']>=50?'badge-support':'badge-not-ready') ?>" style="margin-left:6px"><?= $od['cdoh_pct'] ?>%</span>
    </div>
    <?php endforeach; ?>
</div>

<div style="display:flex;align-items:center;gap:16px;margin-bottom:18px;flex-wrap:wrap">
    <div id="countyName" style="font-size:22px;font-weight:800;color:#0D1A63"></div>
    <span id="overallBadge" class="readiness-badge"></span>
    <span id="overallScores" style="font-size:14px;color:#666"></span>
</div>

<div class="section-title"><i class="fas fa-chart-bar"></i> CDOH Score Distribution per Section</div>
<div class="card" style="margin-bottom:22px">
    <div class="card-head">
        <h3><i class="fas fa-layer-group"></i> Score Level Distribution by Section (% of sub-indicators)</h3>
        <div class="legend">
            <div class="legend-item"><div class="legend-dot" style="background:#dc3545"></div>0</div>
            <div class="legend-item"><div class="legend-dot" style="background:#fd7e14"></div>1</div>
            <div class="legend-item"><div class="legend-dot" style="background:#ffc107"></div>2</div>
            <div class="legend-item"><div class="legend-dot" style="background:#17a2b8"></div>3</div>
            <div class="legend-item"><div class="legend-dot" style="background:#28a745"></div>4</div>
        </div>
    </div>
    <div class="card-body"><div style="height:380px"><canvas id="stackedChart"></canvas></div></div>
</div>

<div class="section-title"><i class="fas fa-chart-bar"></i> CDOH vs IP — Cluster Bars per Section</div>
<div class="card" style="margin-bottom:22px">
    <div class="card-head">
        <h3><i class="fas fa-exchange-alt"></i> CDOH · IP · Overlap · Gap per Section</h3>
        <div class="legend">
            <div class="legend-item"><div class="legend-dot" style="background:#0D1A63"></div>CDOH %</div>
            <div class="legend-item"><div class="legend-dot" style="background:#FFC107"></div>IP %</div>
            <div class="legend-item"><div class="legend-dot" style="background:#27AE60"></div>Overlap</div>
            <div class="legend-item"><div class="legend-dot" style="background:#dc3545"></div>Gap</div>
        </div>
    </div>
    <div class="card-body"><div style="height:340px"><canvas id="clusterChart"></canvas></div></div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-map"></i> County Overall CDOH Comparison</h3></div>
        <div class="card-body"><div style="height:300px"><canvas id="countyChart"></canvas></div></div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-table"></i> Section Detail — <span id="sectionDetailTitle">—</span></h3></div>
        <div class="card-body" style="overflow-x:auto;max-height:360px;overflow-y:auto">
            <table class="detail-table">
                <thead><tr><th>Section</th><th>CDOH%</th><th>IP%</th><th>Overlap</th><th>Gap</th><th>Distribution</th></tr></thead>
                <tbody id="sectionTableBody"><tr><td colspan="6" style="text-align:center;color:#aaa;padding:20px">Loading…</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>
</div>

<script>
Chart.defaults.font.family="'Segoe UI',Arial,sans-serif";
const allCountyData=<?= $all_county_json ?>;
let stackedChart,clusterChart,countyChart;

function buildStackedChart(county){
    const d=allCountyData[county];if(!d||!d.sections.length)return;
    const labels=d.sections.map(s=>s.label);
    if(stackedChart)stackedChart.destroy();
    stackedChart=new Chart(document.getElementById('stackedChart'),{type:'bar',data:{labels,datasets:[
        {label:'4 — Fully',data:d.sections.map(s=>s.s4),backgroundColor:'#28a745',stack:'s'},
        {label:'3 — Partial',data:d.sections.map(s=>s.s3),backgroundColor:'#17a2b8',stack:'s'},
        {label:'2 — Some',data:d.sections.map(s=>s.s2),backgroundColor:'#ffc107',stack:'s'},
        {label:'1 — Minimal',data:d.sections.map(s=>s.s1),backgroundColor:'#fd7e14',stack:'s'},
        {label:'0 — None',data:d.sections.map(s=>s.s0),backgroundColor:'#dc3545',stack:'s'},
    ]},options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:true,position:'top',labels:{boxWidth:12,font:{size:11}}},
                 tooltip:{mode:'index',intersect:false,callbacks:{label:c=>` ${c.dataset.label}: ${c.raw}%`}}},
        scales:{x:{stacked:true,grid:{display:false},ticks:{font:{size:10},maxRotation:40}},
                y:{stacked:true,max:100,grid:{color:'#f0f0f0'},ticks:{callback:v=>v+'%'}}}}});
}

function buildClusterChart(county){
    const d=allCountyData[county];if(!d||!d.sections.length)return;
    const labels=d.sections.map(s=>s.label);
    if(clusterChart)clusterChart.destroy();
    clusterChart=new Chart(document.getElementById('clusterChart'),{type:'bar',data:{labels,datasets:[
        {label:'CDOH %',data:d.sections.map(s=>s.cdoh_pct),backgroundColor:'rgba(13,26,99,.75)',borderRadius:4,borderSkipped:false},
        {label:'IP %',data:d.sections.map(s=>s.cdoh_only?null:s.ip_pct),backgroundColor:'rgba(255,193,7,.75)',borderRadius:4,borderSkipped:false},
        {label:'Overlap',data:d.sections.map(s=>s.overlap),backgroundColor:'rgba(39,174,96,.75)',borderRadius:4,borderSkipped:false},
        {label:'Gap',data:d.sections.map(s=>s.cdoh_only?0:s.gap),backgroundColor:'rgba(220,53,69,.75)',borderRadius:4,borderSkipped:false},
    ]},options:{responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:true,position:'top',labels:{boxWidth:12,font:{size:11}}},
                 tooltip:{mode:'index',intersect:false,callbacks:{label:c=>c.raw!==null?` ${c.dataset.label}: ${c.raw}%`:''}}},
        scales:{x:{grid:{display:false},ticks:{font:{size:10},maxRotation:40}},
                y:{min:0,max:100,grid:{color:'#f0f0f0'},ticks:{callback:v=>v+'%'}}}}});
}

function buildCountyChart(){
    if(countyChart)countyChart.destroy();
    countyChart=new Chart(document.getElementById('countyChart'),{type:'bar',data:{
        labels:<?= $cmp_labels_json ?>,
        datasets:[{label:'CDOH %',data:<?= $cmp_cdoh_json ?>,backgroundColor:<?= $cmp_colors_json ?>,borderRadius:6},
                  {label:'IP %',data:<?= $cmp_ip_json ?>,backgroundColor:'rgba(255,193,7,.4)',borderRadius:6}]},
        options:{responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:true,position:'top',labels:{boxWidth:12,font:{size:11}}},
                     tooltip:{mode:'index',intersect:false,callbacks:{label:c=>` ${c.dataset.label}: ${c.raw}%`}}},
            scales:{x:{grid:{display:false},ticks:{font:{size:10},maxRotation:40}},
                    y:{min:0,max:100,grid:{color:'#f0f0f0'},ticks:{callback:v=>v+'%'}}}}});
}

function updateSectionTable(county){
    const d=allCountyData[county];
    document.getElementById('sectionDetailTitle').textContent=county;
    if(!d||!d.sections.length)return;
    document.getElementById('sectionTableBody').innerHTML=d.sections.map(s=>{
        const col=s.cdoh_pct>=70?'#28a745':s.cdoh_pct>=50?'#ffc107':'#dc3545';
        const stack=`<div style="display:flex;height:11px;border-radius:5px;overflow:hidden;min-width:90px">
            <div style="width:${s.s4}%;background:#28a745"></div><div style="width:${s.s3}%;background:#17a2b8"></div>
            <div style="width:${s.s2}%;background:#ffc107"></div><div style="width:${s.s1}%;background:#fd7e14"></div>
            <div style="width:${s.s0}%;background:#dc3545"></div></div>`;
        return `<tr><td><strong>${s.label}</strong>${s.cdoh_only?'<span style="background:#e8edf8;color:#0D1A63;padding:1px 5px;border-radius:8px;font-size:10px;margin-left:4px">CDOH</span>':''}</td>
            <td style="font-weight:700;color:${col}">${s.cdoh_pct}%</td>
            <td style="font-weight:700;color:#b8860b">${s.cdoh_only?'—':(s.ip_pct!==null?s.ip_pct+'%':'—')}</td>
            <td style="font-weight:700;color:#27AE60">${s.overlap}%</td>
            <td style="font-weight:700;color:#dc3545">${s.cdoh_only?'—':s.gap+'%'}</td>
            <td>${stack}</td></tr>`;
    }).join('');
}

function switchCounty(county,tabEl){
    document.querySelectorAll('.county-tab').forEach(t=>t.classList.remove('active'));
    if(tabEl)tabEl.classList.add('active');
    const d=allCountyData[county];
    document.getElementById('countyName').textContent=county;
    if(d){
        const rdClass=d.cdoh_overall>=70?'badge-transition':d.cdoh_overall>=50?'badge-support':'badge-not-ready';
        const b=document.getElementById('overallBadge');b.textContent=d.readiness;b.className='readiness-badge '+rdClass;
        document.getElementById('overallScores').textContent=`CDOH: ${d.cdoh_overall}%${d.ip_overall?' · IP: '+d.ip_overall+'%':''} · Period: ${d.period}`;
    }
    buildStackedChart(county);buildClusterChart(county);updateSectionTable(county);
}

document.addEventListener('DOMContentLoaded',function(){
    buildCountyChart();
    const def=<?= $default_county_json ?>;
    const tab=document.querySelector('.county-tab.active')||document.querySelector('.county-tab');
    switchCounty(def,tab);
});
</script>
</body>
</html>