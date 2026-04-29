<?php
// transitions/transition_comparison_dashboard.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

// -- Multi-select filters ------------------------------------------------------
$sel_counties = isset($_GET['counties']) ? array_map('intval', (array)$_GET['counties']) : [];
$sel_periods  = isset($_GET['periods'])  ? array_map(fn($v)=>mysqli_real_escape_string($conn,$v),(array)$_GET['periods']) : [];

// Filter options
$counties_list=[];
$cr=mysqli_query($conn,"SELECT county_id,county_name FROM counties ORDER BY county_name");
if($cr) while($r=mysqli_fetch_assoc($cr)) $counties_list[]=$r;

$periods_list=[];
$pr=mysqli_query($conn,"SELECT DISTINCT assessment_period FROM transition_section_submissions ORDER BY assessment_period DESC");
if($pr) while($r=mysqli_fetch_assoc($pr)) $periods_list[]=$r['assessment_period'];

// -- Section labels ------------------------------------------------------------
$section_labels=[
    'leadership'=>'Leadership','supervision'=>'Supervision','special_initiatives'=>'Special Init.',
    'quality_improvement'=>'Quality Impr.','identification_linkage'=>'Patient ID',
    'retention_suppression'=>'Retention','prevention_kp'=>'Prevention/KP',
    'finance'=>'Finance','sub_grants'=>'Sub-Grants','commodities'=>'Commodities',
    'equipment'=>'Equipment','laboratory'=>'Laboratory','inventory'=>'Inventory',
    'training'=>'Training','hr'=>'HR Mgmt','hr_management'=>'HR Mgmt',
    'data_management'=>'Data Mgmt',
    'patient_monitoring'=>'Patient Monitoring','institutional_ownership'=>'Institutional',
];
$cdoh_only_sections=['leadership','institutional_ownership'];

// -- Determine comparison mode -------------------------------------------------
// Mode A: 2+ counties selected (compare counties, across selected periods)
// Mode B: 2+ periods selected  (compare periods, across selected counties)
// Mode C: both selected        (compare county+period combos)
$groups=[];
$compare_by='none';

if(count($sel_counties)>=2 && count($sel_periods)<=1){
    $compare_by='county';
    $p_filter = count($sel_periods)===1 ? "AND tss.assessment_period='{$sel_periods[0]}'" : '';
    foreach($sel_counties as $cid){
        $cn_row=mysqli_fetch_assoc(mysqli_query($conn,"SELECT county_name FROM counties WHERE county_id=$cid"));
        $cn=$cn_row?$cn_row['county_name']:"County $cid";
        $groups[]=["label"=>$cn,"where"=>"tss.county_id=$cid $p_filter"];
    }
} elseif(count($sel_periods)>=2 && count($sel_counties)<=1){
    $compare_by='period';
    $c_filter = count($sel_counties)===1 ? "AND tss.county_id={$sel_counties[0]}" : '';
    // Sort periods chronologically so the most recent (current) is LAST → shows improvement left-to-right
    $sorted_periods = $sel_periods;
    sort($sorted_periods); // natural sort: Q1 2026 before Q2 2026
    foreach($sorted_periods as $p){
        $groups[]=["label"=>$p,"where"=>"tss.assessment_period='$p' $c_filter"];
    }
} elseif(count($sel_counties)>=1 && count($sel_periods)>=1){
    $compare_by='combo';
    // Sort periods chronologically within each county so current is last
    $sorted_periods = $sel_periods;
    sort($sorted_periods);
    foreach($sel_counties as $cid){
        $cn_row=mysqli_fetch_assoc(mysqli_query($conn,"SELECT county_name FROM counties WHERE county_id=$cid"));
        $cn=$cn_row?$cn_row['county_name']:"County $cid";
        foreach($sorted_periods as $p){
            // Label: "CDOH County / Q1 2026" vs final → use compact period label
            $groups[]=["label"=>"$cn $p","where"=>"tss.county_id=$cid AND tss.assessment_period='$p'"];
        }
    }
}

$has_comparison = count($groups)>=2;

// -- Fetch section data per group ----------------------------------------------
$group_colors=['#0D1A63','#0ABFBC','#27AE60','#F5A623','#8B5CF6','#E74C3C','#3B82F6','#F97316'];
$group_data=[];
foreach($groups as $gi=>$g){
    $q="SELECT tss.section_key,
            COALESCE(AVG(tss.cdoh_percent),0) AS cdoh_pct,
            COALESCE(AVG(tss.ip_percent),0)   AS ip_pct,
            COALESCE(AVG(tss.cdoh_ip_overlap),0) AS overlap_pct,
            CASE WHEN AVG(tss.ip_percent)>AVG(tss.cdoh_percent) THEN ROUND(AVG(tss.ip_percent)-AVG(tss.cdoh_percent),2) ELSE 0 END AS gap_pct,
            SUM(tss.sub_count) AS sub_count
        FROM transition_section_submissions tss
        WHERE {$g['where']}
        GROUP BY tss.section_key ORDER BY tss.section_key";
    $res=mysqli_query($conn,$q);
    $secs=[];
    if($res) while($row=mysqli_fetch_assoc($res)){
        $sk=$row['section_key'];
        if($sk==='hr') $sk='hr_management'; // normalise legacy key
        $is_co=in_array($sk,$cdoh_only_sections);
        $secs[$sk]=['label'=>$section_labels[$sk]??$sk,
            'cdoh_pct'=>round((float)$row['cdoh_pct'],2),
            'ip_pct'=>$is_co?null:round((float)$row['ip_pct'],2),
            'overlap'=>round((float)$row['overlap_pct'],2),
            'gap'=>round((float)$row['gap_pct'],2),
            'cdoh_only'=>$is_co,'sub_count'=>(int)$row['sub_count']];
    }
    $cdoh_vals=array_filter(array_column($secs,'cdoh_pct'),fn($v)=>$v>0);
    $ip_vals  =array_filter(array_map(fn($s)=>$s['ip_pct'],$secs),fn($v)=>$v!==null&&$v>0);
    $overall_cdoh=count($cdoh_vals)>0?round(array_sum($cdoh_vals)/count($cdoh_vals),2):0;
    $overall_ip  =count($ip_vals)>0?round(array_sum($ip_vals)/count($ip_vals),2):0;
    $group_data[]=["label"=>$g['label'],"sections"=>$secs,"color"=>$group_colors[$gi%count($group_colors)],
        "overall_cdoh"=>$overall_cdoh,"overall_ip"=>$overall_ip,
        "readiness"=>$overall_cdoh>=70?'Transition':($overall_cdoh>=50?'Support and Monitor':'Not Ready')];
}

// -- Common section list across all groups -------------------------------------
$all_keys=[];
foreach($group_data as $gd) foreach(array_keys($gd['sections']) as $sk) $all_keys[$sk]=true;
ksort($all_keys);
$common_labels=[];
foreach(array_keys($all_keys) as $sk) $common_labels[]=($section_labels[$sk]??$sk);

// -- Chart JSON ---------------------------------------------------------------
// Grouped bar: CDOH% per section per group
// IP chart uses alternating Brown / DarkGoldenrod for clarity
$ip_colors = ['#8B4513','#B8860B','#6B3A2A','#9A7B0A','#7B2D00','#A08500'];
$cdoh_datasets=[];$ip_datasets=[];$overlap_datasets=[];$gap_datasets=[];
foreach($group_data as $gi=>$gd){
    $col    = $gd['color'];
    $ip_col = $ip_colors[$gi % count($ip_colors)];
    $cdoh_vals_arr=[];$ip_arr=[];$ov_arr=[];$gap_arr=[];
    foreach(array_keys($all_keys) as $sk){
        $s=$gd['sections'][$sk]??['cdoh_pct'=>0,'ip_pct'=>0,'overlap'=>0,'gap'=>0,'cdoh_only'=>false];
        $cdoh_vals_arr[]=$s['cdoh_pct'];
        $ip_arr[]=$s['cdoh_only']?null:$s['ip_pct'];
        $ov_arr[]=$s['overlap'];
        $gap_arr[]=$s['cdoh_only']?0:$s['gap'];
    }
    // Build period-range labels when comparing periods: "CDOH Q1 2026/Q2 2026"
    $cdoh_label = count($group_data)===2 && isset($group_data[0],$group_data[1])
        ? "CDOH ".$group_data[0]['label']."/".$group_data[1]['label']
        : $gd['label']." CDOH";
    $ip_label   = count($group_data)===2 && isset($group_data[0],$group_data[1])
        ? "IP ".$group_data[0]['label']."/".$group_data[1]['label']
        : $gd['label']." IP";
    $cdoh_datasets[]=["label"=>$gd['label']." CDOH","data"=>$cdoh_vals_arr,"backgroundColor"=>$col,"borderRadius"=>4,"borderSkipped"=>false];
    $ip_datasets[]  =["label"=>$gd['label']." IP",  "data"=>$ip_arr,"backgroundColor"=>$ip_col,"borderRadius"=>4,"borderSkipped"=>false,"borderColor"=>$ip_col,"borderWidth"=>1.5];
    $overlap_datasets[]=["label"=>$gd['label']." Overlap","data"=>$ov_arr,"backgroundColor"=>$col,"borderRadius"=>4,"borderSkipped"=>false];
    $gap_datasets[]    =["label"=>$gd['label']." Gap","data"=>$gap_arr,"backgroundColor"=>$col."99","borderRadius"=>4,"borderSkipped"=>false];
}
$labels_json=json_encode($common_labels);
$cdoh_ds_json=json_encode($cdoh_datasets);
$ip_ds_json=json_encode($ip_datasets);
$ov_ds_json=json_encode($overlap_datasets);
$gap_ds_json=json_encode($gap_datasets);
// Overall comparison (KPI bars)
$kpi_labels=json_encode(array_column($group_data,'label'));
$kpi_cdoh=json_encode(array_column($group_data,'overall_cdoh'));
$kpi_ip=json_encode(array_column($group_data,'overall_ip'));
$kpi_colors=json_encode(array_column($group_data,'color'));
$group_data_json=json_encode($group_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Transition Comparison Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f0f2f7;color:#333;line-height:1.6;}
.container{max-width:1700px;margin:0 auto;padding:20px;}
.page-header{background:linear-gradient(135deg,#0D1A63,#1a3a9e);color:#fff;padding:22px 30px;border-radius:14px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 6px 24px rgba(13,26,99,.25);}
.page-header h1{font-size:1.5rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.page-header .hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:7px 14px;border-radius:8px;font-size:13px;margin-left:8px;transition:background .2s;}
.page-header .hdr-links a:hover{background:rgba(255,255,255,.28);}
.filters-card{background:#fff;border-radius:14px;padding:20px 22px;margin-bottom:22px;box-shadow:0 2px 16px rgba(13,26,99,.08);}
.filters-title{font-size:12px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:.8px;margin-bottom:14px;}
.filters-grid{display:grid;grid-template-columns:1fr 1fr auto;gap:16px;align-items:end;}
.filter-group label{font-size:11px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:5px;}
.filter-hint{font-size:10px;color:#0ABFBC;margin-top:3px;font-weight:500;}
.filter-actions{display:flex;gap:10px;}
.btn-compare{background:#0D1A63;color:#fff;border:none;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;height:40px;display:flex;align-items:center;gap:7px;}
.btn-compare:hover{background:#1a3a9e;}
.btn-clear{background:#f3f4f6;color:#666;border:none;padding:9px 16px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;height:40px;display:flex;align-items:center;gap:6px;}
.ts-wrapper .ts-control{border:1.5px solid #e0e4f0!important;border-radius:8px!important;font-size:13px!important;min-height:40px!important;padding:4px 8px!important;}
.ts-wrapper.focus .ts-control{border-color:#0D1A63!important;box-shadow:none!important;}
.ts-wrapper .ts-control .item{background:#0D1A63!important;color:#fff!important;border-radius:5px!important;font-size:11px!important;padding:2px 7px!important;}
.compare-banner{display:flex;align-items:center;gap:12px;padding:12px 18px;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:600;flex-wrap:wrap;}
.compare-banner.active{background:rgba(10,191,188,.1);border:1.5px solid rgba(10,191,188,.3);color:#0ABFBC;}
.compare-banner.inactive{background:rgba(245,166,35,.08);border:1.5px solid rgba(245,166,35,.25);color:#92400E;}
.group-tag{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;color:#fff;}
.section-title{font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#888;margin:24px 0 14px;display:flex;align-items:center;gap:10px;}
.section-title::after{content:'';flex:1;height:1px;background:#e0e4f0;}
.card{background:#fff;border-radius:14px;padding:0;box-shadow:0 4px 20px rgba(0,0,0,.05);overflow:hidden;margin-bottom:22px;}
.card-head{padding:15px 20px 13px;border-bottom:1px solid #e8ecf5;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.card-head h3{font-size:14px;font-weight:700;color:#0D1A63;display:flex;align-items:center;gap:8px;}
.card-body{padding:20px;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:22px;}
.kpi-box{background:#fff;border-radius:14px;padding:18px 20px;box-shadow:0 4px 20px rgba(0,0,0,.05);border-left:5px solid var(--kc);}
.kpi-box-label{font-size:12px;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;}
.kpi-box-rows{display:flex;flex-direction:column;gap:6px;}
.kpi-box-row{display:flex;align-items:center;gap:8px;}
.kpi-group-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.kpi-group-label{font-size:11px;font-weight:600;flex:1;}
.kpi-bar-track{flex:2;height:9px;background:#f0f0f0;border-radius:99px;overflow:hidden;}
.kpi-bar-fill{height:100%;border-radius:99px;}
.kpi-val{font-size:12px;font-weight:800;min-width:40px;text-align:right;}
.legend{display:flex;gap:14px;flex-wrap:wrap;}
.legend-item{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;}
.legend-dot{width:12px;height:12px;border-radius:3px;}
.compare-table{width:100%;border-collapse:collapse;font-size:12px;}
.compare-table th{background:#f8fafc;padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;color:#888;border-bottom:1px solid #e8ecf5;}
.compare-table td{padding:8px 10px;border-bottom:1px solid #e8ecf5;vertical-align:middle;}
.compare-table tr:last-child td{border-bottom:none;}
.compare-table tr:hover td{background:#f8fafc;}
.delta-up{background:#d4edda;color:#155724;padding:2px 7px;border-radius:12px;font-size:11px;font-weight:700;}
.delta-down{background:#f8d7da;color:#721c24;padding:2px 7px;border-radius:12px;font-size:11px;font-weight:700;}
.delta-neutral{background:#f3f4f6;color:#666;padding:2px 7px;border-radius:12px;font-size:11px;font-weight:700;}
.readiness-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-transition{background:#d4edda;color:#155724;}
.badge-support{background:#fff3cd;color:#856404;}
.badge-not-ready{background:#f8d7da;color:#721c24;}
@media(max-width:1100px){.filters-grid{grid-template-columns:1fr 1fr;}.grid-2{grid-template-columns:1fr;}}
@media(max-width:700px){.filters-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="container">
<div class="page-header">
    <h1><i class="fas fa-code-branch"></i> Transition Assessment  Comparison Dashboard</h1>
    <div class="hdr-links">
        <a href="transition_dashboard.php"><i class="fas fa-tachometer-alt"></i> Main Dashboard</a>
        <a href="transition_index.php"><i class="fas fa-plus"></i> New Assessment</a>
    </div>
</div>

<!-- Filters -->
<div class="filters-card">
    <div class="filters-title"><i class="fas fa-sliders-h"></i> Comparison Filters  select 2+ counties or 2+ periods to compare side-by-side</div>
    <form method="GET">
        <div class="filters-grid">
            <div class="filter-group">
                <label>Counties <span style="color:#0ABFBC">(multiselect)</span></label>
                <select id="sel-counties" name="counties[]" multiple placeholder="Select counties">
                    <?php foreach($counties_list as $c): ?>
                    <option value="<?= $c['county_id'] ?>" <?= in_array($c['county_id'],$sel_counties)?'selected':'' ?>><?= htmlspecialchars($c['county_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="filter-hint"><i class="fas fa-info-circle"></i> Select 2+ counties to compare</div>
            </div>
            <div class="filter-group">
                <label>Assessment Periods <span style="color:#0ABFBC">(multiselect)</span></label>
                <select id="sel-periods" name="periods[]" multiple placeholder="Select periods">
                    <?php foreach($periods_list as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= in_array($p,$sel_periods)?'selected':'' ?>><?= htmlspecialchars($p) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="filter-hint"><i class="fas fa-info-circle"></i> Select 2+ periods to compare over time</div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-compare"><i class="fas fa-sync-alt"></i> Compare</button>
                <a href="transition_comparison_dashboard.php" class="btn-clear"><i class="fas fa-times"></i> Reset</a>
            </div>
        </div>
    </form>
</div>

<!-- Banner -->
<?php if($has_comparison): ?>
<div class="compare-banner active">
    <i class="fas fa-check-circle" style="font-size:18px"></i>
    <span>Comparing <strong><?= count($groups) ?> groups</strong> by <strong><?= $compare_by ?></strong></span>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-left:auto">
        <?php foreach($group_data as $gi=>$gd): ?>
        <span class="group-tag" style="background:<?= $gd['color'] ?>"><i class="fas fa-circle" style="font-size:7px"></i> <?= htmlspecialchars($gd['label']) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="compare-banner inactive">
    <i class="fas fa-exclamation-triangle"></i>
    <span>Select <strong>2 or more</strong> counties or periods above to enable comparison.</span>
</div>
<?php endif; ?>

<?php if($has_comparison): ?>

<!-- -- KPI overview bars --------------------------------------------------- -->
<div class="section-title"><i class="fas fa-tachometer-alt"></i> Overall CDOH &amp; IP Scores</div>
<div class="kpi-row">
    <?php foreach(['overall_cdoh'=>'Overall CDOH %','overall_ip'=>'Overall IP %'] as $key=>$lbl): ?>
    <div class="kpi-box" style="--kc:<?= $key==='overall_cdoh'?'#0D1A63':'#F5A623' ?>">
        <div class="kpi-box-label"><?= $lbl ?></div>
        <div class="kpi-box-rows">
            <?php $max_v=max(1,...array_column($group_data,$key)); ?>
            <?php foreach($group_data as $gi=>$gd): $val=$gd[$key]; ?>
            <div class="kpi-box-row">
                <div class="kpi-group-dot" style="background:<?= $gd['color'] ?>"></div>
                <div class="kpi-group-label"><?= htmlspecialchars($gd['label']) ?></div>
                <div class="kpi-bar-track"><div class="kpi-bar-fill" style="width:<?= $max_v>0?round($val/$max_v*100):0 ?>%;background:<?= $gd['color'] ?>"></div></div>
                <div class="kpi-val" style="color:<?= $gd['color'] ?>"><?= $val ?>%</div>
            </div>
            <?php endforeach; ?>
            <?php if(count($group_data)===2):
                $d=round($group_data[1][$key]-$group_data[0][$key],2);
                $cls=$d>0?'delta-up':($d<0?'delta-down':'delta-neutral');
                echo "<div style='margin-top:6px'><span class='$cls'>".($d>=0?'+':'').number_format($d,2)."pp vs prev.</span></div>";
            endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <!-- Readiness -->
    <div class="kpi-box" style="--kc:#8B5CF6">
        <div class="kpi-box-label">Readiness Level</div>
        <div class="kpi-box-rows">
            <?php foreach($group_data as $gd): ?>
            <div class="kpi-box-row">
                <div class="kpi-group-dot" style="background:<?= $gd['color'] ?>"></div>
                <div class="kpi-group-label"><?= htmlspecialchars($gd['label']) ?></div>
                <span class="readiness-badge <?= $gd['readiness']==='Transition'?'badge-transition':($gd['readiness']==='Support and Monitor'?'badge-support':'badge-not-ready') ?>"><?= $gd['readiness'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- -- CDOH % grouped bar -------------------------------------------------- -->
<div class="section-title"><i class="fas fa-chart-bar"></i> CDOH % per Section  Grouped</div>
<div class="card">
    <div class="card-head">
        <h3><i class="fas fa-building"></i> County Autonomy (CDOH) by Section</h3>
        <div class="legend">
            <?php foreach($group_data as $gd): ?>
            <div class="legend-item"><div class="legend-dot" style="background:<?= $gd['color'] ?>"></div><?= htmlspecialchars($gd['label']) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body"><div style="height:320px"><canvas id="cdohChart"></canvas></div></div>
</div>

<!-- -- IP % grouped bar ---------------------------------------------------- -->
<div class="section-title"><i class="fas fa-chart-bar"></i> IP % per Section  Grouped</div>
<div class="card">
    <div class="card-head">
        <h3><i class="fas fa-handshake"></i> IP Involvement by Section</h3>
        <div class="legend">
            <?php foreach($group_data as $gd): ?>
            <div class="legend-item"><div class="legend-dot" style="background:<?= $gd['color'] ?>22;border:2px solid <?= $gd['color'] ?>"></div><?= htmlspecialchars($gd['label']) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body"><div style="height:320px"><canvas id="ipChart"></canvas></div></div>
</div>

<!-- -- Overlap & Gap ------------------------------------------------------- -->
<div class="grid-2">
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-object-group"></i> Overlap per Section</h3></div>
        <div class="card-body"><div style="height:280px"><canvas id="overlapChart"></canvas></div></div>
    </div>
    <div class="card">
        <div class="card-head"><h3><i class="fas fa-arrows-alt-h"></i> Gap per Section (IP - CDOH)</h3></div>
        <div class="card-body"><div style="height:280px"><canvas id="gapChart"></canvas></div></div>
    </div>
</div>

<!-- -- Full comparison table ----------------------------------------------- -->
<div class="section-title"><i class="fas fa-table"></i> Full Section Comparison Table</div>
<div class="card">
    <div class="card-head">
        <h3><i class="fas fa-list"></i> All Sections  <?= count($groups) ?>-Way Comparison</h3>
    </div>
    <div style="overflow-x:auto">
        <table class="compare-table">
            <thead>
                <tr>
                    <th>Section</th>
                    <?php foreach($group_data as $gi=>$gd): ?>
                    <th style="color:<?= $gd['color'] ?>"><?= htmlspecialchars($gd['label']) ?><br><small>CDOH / IP</small></th>
                    <?php endforeach; ?>
                    <?php if(count($group_data)===2): ?><th>? CDOH</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach(array_keys($all_keys) as $sk):
                $lbl=$section_labels[$sk]??$sk;
                $is_co=in_array($sk,$cdoh_only_sections);
            ?>
            <tr>
                <td><strong><?= $lbl ?></strong><?= $is_co?'<span style="background:#e8edf8;color:#0D1A63;padding:1px 5px;border-radius:8px;font-size:10px;margin-left:4px">CDOH</span>':'' ?></td>
                <?php foreach($group_data as $gi=>$gd):
                    $s=$gd['sections'][$sk]??['cdoh_pct'=>0,'ip_pct'=>null,'cdoh_only'=>$is_co];
                    $col=$s['cdoh_pct']>=70?'#28a745':($s['cdoh_pct']>=50?'#ffc107':'#dc3545');
                ?>
                <td>
                    <span style="font-weight:700;color:<?= $col ?>"><?= $s['cdoh_pct'] ?>%</span>
                    <?php if(!$is_co && $s['ip_pct']!==null): ?>
                    <span style="color:#b8860b;font-weight:600;margin-left:4px">/ <?= $s['ip_pct'] ?>%</span>
                    <?php elseif(!$is_co): ?>
                    <span style="color:#aaa;margin-left:4px">/ </span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <?php if(count($group_data)===2):
                    $v0=$group_data[0]['sections'][$sk]['cdoh_pct']??0;
                    $v1=$group_data[1]['sections'][$sk]['cdoh_pct']??0;
                    $d=round($v1-$v0,2);$cls=$d>0?'delta-up':($d<0?'delta-down':'delta-neutral');
                ?><td><span class="<?= $cls ?>"><?= $d>=0?'+':'' ?><?= number_format($d,2) ?>pp</span></td><?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <!-- Overall row -->
            <tr style="background:#f8fafc;font-weight:700;border-top:2px solid #e0e4f0">
                <td><strong>Overall (avg)</strong></td>
                <?php foreach($group_data as $gi=>$gd):
                    $col=$gd['overall_cdoh']>=70?'#28a745':($gd['overall_cdoh']>=50?'#ffc107':'#dc3545'); ?>
                <td>
                    <span style="font-weight:800;color:<?= $col ?>"><?= $gd['overall_cdoh'] ?>%</span>
                    <?php if($gd['overall_ip']>0): ?>
                    <span style="color:#b8860b;font-weight:700;margin-left:4px">/ <?= $gd['overall_ip'] ?>%</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
                <?php if(count($group_data)===2):
                    $d=round($group_data[1]['overall_cdoh']-$group_data[0]['overall_cdoh'],2);
                    $cls=$d>0?'delta-up':($d<0?'delta-down':'delta-neutral');
                ?><td><span class="<?= $cls ?>"><?= $d>=0?'+':'' ?><?= number_format($d,2) ?>pp</span></td><?php endif; ?>
            </tr>
            </tbody>
        </table>
    </div>
</div>

<?php endif; // has_comparison ?>
</div>

<script>
Chart.defaults.font.family="'Segoe UI',Arial,sans-serif";
Chart.defaults.color='#6B7280';

const secLabels=<?= $labels_json ?>;

const chartOpts=(title)=>({responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:true,position:'top',labels:{boxWidth:12,font:{size:11}}},
             tooltip:{mode:'index',intersect:false,callbacks:{label:c=>c.raw!==null?` ${c.dataset.label}: ${c.raw}%`:''}}},
    scales:{x:{grid:{display:false},ticks:{font:{size:10},maxRotation:40}},
            y:{min:0,max:100,grid:{color:'#f0f0f0'},ticks:{callback:v=>v+'%'}}}});

new Chart(document.getElementById('cdohChart'),{type:'bar',data:{labels:secLabels,datasets:<?= $cdoh_ds_json ?>},options:chartOpts('CDOH')});
new Chart(document.getElementById('ipChart'),  {type:'bar',data:{labels:secLabels,datasets:<?= $ip_ds_json ?>},  options:chartOpts('IP')});
new Chart(document.getElementById('overlapChart'),{type:'bar',data:{labels:secLabels,datasets:<?= $ov_ds_json ?>}, options:chartOpts('Overlap')});
new Chart(document.getElementById('gapChart'),    {type:'bar',data:{labels:secLabels,datasets:<?= $gap_ds_json ?>},options:chartOpts('Gap')});

// Tom Select
['sel-counties','sel-periods'].forEach(id=>{
    const el=document.getElementById(id);
    if(el) new TomSelect('#'+id,{plugins:['remove_button'],maxItems:null});
});
</script>
</body>
</html>