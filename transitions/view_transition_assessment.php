<?php
// transitions/view_transition_assessment.php
// Lists ALL assessments grouped by county, most recent first.
// View → opens transition_dashboard  |  Edit → opens transition_assessment
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$is_admin  = in_array($user_role, ['Admin', 'Super Admin']);

// All defined sections
$all_section_keys = [
    'leadership','supervision','special_initiatives','quality_improvement',
    'identification_linkage','retention_suppression','prevention_kp',
    'finance','sub_grants','commodities','equipment','laboratory',
    'inventory','training','hr_management','data_management',
    'patient_monitoring','institutional_ownership'
];
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

$search_county = isset($_GET['county']) ? (int)$_GET['county'] : 0;
$search_period = isset($_GET['period']) ? mysqli_real_escape_string($conn, $_GET['period']) : '';

$counties_list = [];
$cr = mysqli_query($conn, "SELECT county_id, county_name FROM counties ORDER BY county_name");
if ($cr) while ($r = mysqli_fetch_assoc($cr)) $counties_list[] = $r;

$periods_list = [];
$pr = mysqli_query($conn, "SELECT DISTINCT assessment_period FROM transition_assessments ORDER BY assessment_period DESC");
if ($pr) while ($r = mysqli_fetch_assoc($pr)) $periods_list[] = $r['assessment_period'];

// Fetch all assessments
$where_parts = ["1=1"];
if ($search_county) $where_parts[] = "ta.county_id = $search_county";
if ($search_period) $where_parts[] = "ta.assessment_period = '$search_period'";
$where = "WHERE " . implode(" AND ", $where_parts);

$q = "SELECT ta.assessment_id, ta.county_id, c.county_name,
             ta.assessment_period, ta.assessment_date, ta.assessed_by,
             ta.assessment_status, ta.readiness_level, ta.created_at
      FROM transition_assessments ta
      JOIN counties c ON c.county_id = ta.county_id
      $where
      ORDER BY c.county_name ASC, ta.assessment_date DESC, ta.assessment_period DESC";
$res = mysqli_query($conn, $q);

$grouped = [];
if ($res) while ($row = mysqli_fetch_assoc($res)) {
    $cn = $row['county_name'];
    if (!isset($grouped[$cn])) $grouped[$cn] = ['county_id' => $row['county_id'], 'assessments' => []];
    $grouped[$cn]['assessments'][] = $row;
}

// Fetch section submission statuses for all assessments
$assessment_ids = [];
foreach ($grouped as $cn => $cd)
    foreach ($cd['assessments'] as $a) $assessment_ids[] = (int)$a['assessment_id'];

$section_status = [];
if (!empty($assessment_ids)) {
    $ids_str = implode(',', $assessment_ids);
    $sr = mysqli_query($conn,
        "SELECT assessment_id, section_key, sub_count, avg_cdoh
         FROM transition_section_submissions WHERE assessment_id IN ($ids_str)");
    if ($sr) while ($row = mysqli_fetch_assoc($sr)) {
        $aid = (int)$row['assessment_id'];
        $sk  = ($row['section_key'] === 'hr') ? 'hr_management' : $row['section_key'];
        $cdoh_pct = ($row['avg_cdoh'] !== null) ? ((float)$row['avg_cdoh'] / 4 * 100) : 0;
        if (!isset($section_status[$aid][$sk]) || $cdoh_pct > 0) {
            $section_status[$aid][$sk] = $cdoh_pct >= 75 ? 'complete'
                : ($row['sub_count'] > 0 ? 'incomplete' : 'not_started');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Transition Assessments Registry</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f0f2f7;color:#333;line-height:1.6;}
.container{max-width:1500px;margin:0 auto;padding:20px;}
.page-header{background:linear-gradient(135deg,#2D008A,#1a3a9e);color:#fff;padding:22px 30px;border-radius:14px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 6px 24px rgba(45,0,138,.25);flex-wrap:wrap;gap:12px;}
.page-header h1{font-size:1.5rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.page-header .hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:7px 14px;border-radius:8px;font-size:13px;margin-left:8px;transition:background .2s;display:inline-flex;align-items:center;gap:6px;}
.page-header .hdr-links a:hover{background:rgba(255,255,255,.28);}
.filter-bar{background:#fff;border-radius:12px;padding:16px 20px;margin-bottom:20px;box-shadow:0 2px 12px rgba(0,0,0,.06);display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.filter-group{flex:1;min-width:180px;}
.filter-group label{display:block;font-size:11px;font-weight:700;color:#666;margin-bottom:4px;text-transform:uppercase;}
.filter-group select{width:100%;padding:9px 11px;border:2px solid #e0e4f0;border-radius:8px;font-size:13px;}
.btn-filter{background:#2D008A;color:#fff;border:none;padding:9px 22px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;}
.btn-clear{background:#e0e4f0;color:#333;border:none;padding:9px 16px;border-radius:8px;font-weight:600;cursor:pointer;text-decoration:none;font-size:13px;display:inline-flex;align-items:center;gap:5px;}
.county-group{background:#fff;border-radius:14px;margin-bottom:22px;box-shadow:0 4px 20px rgba(0,0,0,.06);overflow:hidden;}
.county-header{background:linear-gradient(90deg,#2D008A,#1a3a9e);color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;cursor:pointer;user-select:none;}
.county-header h2{font-size:1rem;font-weight:700;display:flex;align-items:center;gap:9px;}
.county-header .count-badge{background:rgba(255,255,255,.25);padding:3px 12px;border-radius:20px;font-size:12px;font-weight:700;}
.county-toggle{transition:transform .25s;display:inline-block;}
.county-group.collapsed .county-assessments{display:none;}
.county-group.collapsed .county-toggle{transform:rotate(-90deg);}
.assessment-row{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:start;padding:16px 22px;border-bottom:1px solid #f0f2f7;transition:background .15s;}
.assessment-row:last-child{border-bottom:none;}
.assessment-row:hover{background:#fafbff;}
.assessment-meta{display:flex;flex-direction:column;gap:6px;}
.assessment-period{font-size:15px;font-weight:700;color:#2D008A;display:flex;flex-wrap:wrap;align-items:center;gap:6px;}
.assessment-info{font-size:12px;color:#888;display:flex;flex-wrap:wrap;gap:12px;}
.assessment-info span{display:flex;align-items:center;gap:4px;}
.status-pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.status-submitted{background:#d4edda;color:#155724;}
.status-draft{background:#e0e7ff;color:#3730a3;}
.readiness-pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.r-transition{background:#d4edda;color:#155724;}
.r-support{background:#fff3cd;color:#856404;}
.r-not-ready{background:#f8d7da;color:#721c24;}
.r-unrated{background:#f3f4f6;color:#666;}
.section-status-panel{display:flex;flex-wrap:wrap;gap:4px;margin-top:8px;}
.sec-dot{width:26px;height:26px;border-radius:5px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;position:relative;cursor:default;}
.sec-dot::after{content:attr(title);position:absolute;bottom:110%;left:50%;transform:translateX(-50%);background:#222;color:#fff;padding:4px 8px;border-radius:5px;font-size:10px;white-space:nowrap;z-index:10;pointer-events:none;opacity:0;transition:opacity .2s;}
.sec-dot:hover::after{opacity:1;}
.sec-complete{background:#04B04B;}
.sec-incomplete{background:#FFC12E;}
.sec-not-started{background:#555;}
.action-btns{display:flex;flex-direction:column;gap:8px;align-items:stretch;min-width:100px;}
.btn-view{background:#2D008A;color:#fff;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:background .2s;}
.btn-view:hover{background:#1a3a9e;}
.btn-edit{background:#04B04B;color:#fff;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:background .2s;}
.btn-edit:hover{background:#039b40;}
.no-data{text-align:center;padding:60px 20px;color:#aaa;}
.no-data i{font-size:48px;margin-bottom:14px;display:block;}
@media(max-width:700px){.assessment-row{grid-template-columns:1fr;}.action-btns{flex-direction:row;}}
</style>
</head>
<body>
<div class="container">

<div class="page-header">
    <h1><i class="fas fa-clipboard-list"></i> Transition Assessments Registry</h1>
    <div class="hdr-links">
        <a href="transition_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        <a href="transition_comparison_dashboard.php"><i class="fas fa-code-branch"></i> Compare</a>
        <a href="transition_index.php"><i class="fas fa-plus"></i> New Assessment</a>
    </div>
</div>

<form method="GET" class="filter-bar">
    <div class="filter-group">
        <label>County</label>
        <select name="county">
            <option value="">All Counties</option>
            <?php foreach ($counties_list as $c): ?>
            <option value="<?= $c['county_id'] ?>" <?= $search_county==$c['county_id']?'selected':'' ?>><?= htmlspecialchars($c['county_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Period</label>
        <select name="period">
            <option value="">All Periods</option>
            <?php foreach ($periods_list as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>" <?= $search_period===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Filter</button>
    <a href="view_transition_assessment.php" class="btn-clear"><i class="fas fa-times"></i> Clear</a>
</form>

<!-- Legend -->
<div style="display:flex;gap:16px;margin-bottom:16px;font-size:12px;align-items:center;color:#555;flex-wrap:wrap;">
    <strong>Section Status:</strong>
    <span><span style="display:inline-block;width:13px;height:13px;background:#04B04B;border-radius:3px;vertical-align:middle;margin-right:4px;"></span>Complete (&ge;75%)</span>
    <span><span style="display:inline-block;width:13px;height:13px;background:#FFC12E;border-radius:3px;vertical-align:middle;margin-right:4px;"></span>Incomplete</span>
    <span><span style="display:inline-block;width:13px;height:13px;background:#555;border-radius:3px;vertical-align:middle;margin-right:4px;"></span>Not Started</span>
</div>

<?php if (empty($grouped)): ?>
<div class="no-data">
    <i class="fas fa-clipboard-list"></i>
    <p>No assessments found. <a href="transition_index.php" style="color:#2D008A;font-weight:600;">Start a new assessment</a>.</p>
</div>
<?php else: ?>

<?php foreach ($grouped as $county_name => $cd):
    $assessments = $cd['assessments'];
    $cid         = $cd['county_id'];
    $all_sections_param = implode(',', $all_section_keys);
?>
<div class="county-group" id="county_<?= $cid ?>">
    <div class="county-header" onclick="toggleCounty(<?= $cid ?>)">
        <h2>
            <i class="fas fa-map-marker-alt"></i>
            <?= htmlspecialchars($county_name) ?>
            <span class="count-badge"><?= count($assessments) ?> assessment<?= count($assessments)!==1?'s':'' ?></span>
        </h2>
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:12px;opacity:.8">Latest: <?= htmlspecialchars($assessments[0]['assessment_period'] ?? '') ?></span>
            <span class="county-toggle"><i class="fas fa-chevron-down"></i></span>
        </div>
    </div>
    <div class="county-assessments">
        <?php foreach ($assessments as $a):
            $aid       = (int)$a['assessment_id'];
            $status    = $a['assessment_status'] ?? 'draft';
            $readiness = $a['readiness_level']   ?? '';
            $rdClass   = $readiness === 'Transition'         ? 'r-transition'
                       : ($readiness === 'Support and Monitor' ? 'r-support'
                       : ($readiness === 'Not Ready'           ? 'r-not-ready' : 'r-unrated'));
            $sec_map   = $section_status[$aid] ?? [];

            // Section dots
            $dots = '';
            foreach ($all_section_keys as $sk) {
                $state = $sec_map[$sk] ?? 'not_started';
                $cls   = $state === 'complete'   ? 'sec-complete'
                       : ($state === 'incomplete' ? 'sec-incomplete' : 'sec-not-started');
                $icon  = $state === 'complete'   ? '&#10003;'
                       : ($state === 'incomplete' ? '&#126;'        : '&bull;');
                $tip   = htmlspecialchars($section_labels[$sk] ?? $sk);
                $dots .= "<span class='sec-dot $cls' title='$tip'>$icon</span>";
            }

            $edit_url = "transition_assessment.php?county={$cid}&period=" . urlencode($a['assessment_period'])
                      . "&sections=$all_sections_param&assessment_id=$aid";
            $view_url = "transition_dashboard.php?county={$cid}&period=" . urlencode($a['assessment_period']);
        ?>
        <div class="assessment-row">
            <div class="assessment-meta">
                <div class="assessment-period">
                    <i class="fas fa-calendar-alt" style="color:#AC80EE;font-size:13px;"></i>
                    <?= htmlspecialchars($a['assessment_period']) ?>
                    <span class="status-pill status-<?= htmlspecialchars($status) ?>"><?= ucfirst($status) ?></span>
                    <?php if ($readiness): ?>
                    <span class="readiness-pill <?= $rdClass ?>"><?= htmlspecialchars($readiness) ?></span>
                    <?php endif; ?>
                </div>
                <div class="assessment-info">
                    <?php if ($a['assessment_date']): ?>
                    <span><i class="fas fa-calendar-check"></i> <?= date('d M Y', strtotime($a['assessment_date'])) ?></span>
                    <?php endif; ?>
                    <?php if ($a['assessed_by']): ?>
                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($a['assessed_by']) ?></span>
                    <?php endif; ?>
                    <span><i class="fas fa-hashtag"></i> ID <?= $aid ?></span>
                </div>
                <div class="section-status-panel"><?= $dots ?></div>
            </div>
            <div class="action-btns">
                <a href="<?= $view_url ?>" class="btn-view" title="View dashboard for this assessment">
                    <i class="fas fa-chart-bar"></i> View
                </a>
                <a href="<?= $edit_url ?>" class="btn-edit" title="Open assessment form">
                    <i class="fas fa-edit"></i> Edit
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

</div>
<script>
function toggleCounty(cid) {
    const el = document.getElementById('county_' + cid);
    if (el) el.classList.toggle('collapsed');
}
</script>
</body>
</html>
