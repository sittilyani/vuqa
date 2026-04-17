<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit(); }

// Handle DELETE
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $did = (int)$_GET['delete'];
    mysqli_query($conn, "DELETE FROM integration_assessment_emr_systems WHERE assessment_id=$did");
    if (mysqli_query($conn, "DELETE FROM integration_assessments WHERE assessment_id=$did"))
        $_SESSION['success_msg'] = 'Assessment deleted.';
    else
        $_SESSION['error_msg'] = 'Error deleting: ' . mysqli_error($conn);
    header('Location: integration_assessment_list.php'); exit();
}

$period=$_GET['period']??''; $county=$_GET['county']??''; $agency=$_GET['agency']??'';
$level_of_care=$_GET['level_of_care']??''; $date_from=$_GET['date_from']??''; $date_to=$_GET['date_to']??'';
$art_site=$_GET['art_site']??''; $uses_emr=$_GET['uses_emr']??''; $status_f=$_GET['status']??'';
$leadership=$_GET['leadership']??''; $data_integration=$_GET['data_integration']??'';

$esc = fn($v) => mysqli_real_escape_string($conn, $v);
$where="WHERE 1=1";
if($period)         $where.=" AND assessment_period='{$esc($period)}'";
if($county)         $where.=" AND county_name='{$esc($county)}'";
if($agency)         $where.=" AND agency='{$esc($agency)}'";
if($level_of_care)  $where.=" AND level_of_care_name='{$esc($level_of_care)}'";
if($date_from)      $where.=" AND collection_date>='{$esc($date_from)}'";
if($date_to)        $where.=" AND collection_date<='{$esc($date_to)}'";
if($art_site)       $where.=" AND is_art_site='{$esc($art_site)}'";
if($uses_emr)       $where.=" AND uses_emr='{$esc($uses_emr)}'";
if($status_f)       $where.=" AND assessment_status='{$esc($status_f)}'";
if($leadership)     $where.=" AND leadership_commitment='{$esc($leadership)}'";
if($data_integration) $where.=" AND data_integration_level='{$esc($data_integration)}'";

$page=max(1,(int)($_GET['page']??1)); $limit=20; $offset=($page-1)*$limit;
$total = (int)mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM integration_assessments $where"))['c'];
$total_pages = ceil($total/$limit);

$rows_r = mysqli_query($conn,"
    SELECT assessment_id, assessment_period, facility_name, mflcode, county_name,
           level_of_care_name, is_art_site, uses_emr, supported_by_usdos_ip,
           tx_curr, plhiv_enrolled_sha, assessment_status, sections_saved,
           collected_by, collection_date, last_saved_at, last_saved_by, created_at
    FROM integration_assessments $where ORDER BY created_at DESC LIMIT $offset,$limit");

$periods_r     = mysqli_query($conn,"SELECT DISTINCT assessment_period FROM integration_assessments WHERE assessment_period!='' ORDER BY assessment_period DESC");
$counties_r    = mysqli_query($conn,"SELECT DISTINCT county_name FROM integration_assessments WHERE county_name!='' ORDER BY county_name");
$agencies_r    = mysqli_query($conn,"SELECT DISTINCT agency FROM integration_assessments WHERE agency!='' ORDER BY agency");
$care_levels_r = mysqli_query($conn,"SELECT DISTINCT level_of_care_name FROM integration_assessments WHERE level_of_care_name!='' ORDER BY level_of_care_name");

$all_section_defs = ['s1','s2a','s2b','s2c','s3','s4','s5','s6','s7','s8_readiness','s8_lab','s9','s10','s11'];
$total_sections = count($all_section_defs);

$success_msg=$_SESSION['success_msg']??''; $error_msg=$_SESSION['error_msg']??'';
unset($_SESSION['success_msg'],$_SESSION['error_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Integration Assessments</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
:root{--navy:#0D1A63;--navy2:#1a3a9e;--teal:#0ABFBC;--green:#27AE60;--amber:#F5A623;--rose:#E74C3C;--bg:#f0f2f7;--card:#fff;--border:#e2e8f0;--muted:#6B7280;--shadow:0 2px 16px rgba(13,26,99,.07);}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:var(--bg);color:#1a1e2e;line-height:1.6;}
.container{max-width:1600px;margin:0 auto;padding:20px;}
.page-header{background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;padding:20px 28px;border-radius:14px;margin-bottom:22px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 6px 24px rgba(13,26,99,.22);}
.page-header h1{font-size:1.35rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:7px 14px;border-radius:8px;font-size:13px;margin-left:8px;transition:.2s;}
.hdr-links a:hover{background:rgba(255,255,255,.28);}
.hdr-links a.active{background:#fff;color:var(--navy);font-weight:700;}
.alert{padding:12px 18px;border-radius:9px;margin-bottom:16px;font-size:13.5px;display:flex;align-items:center;gap:10px;}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
.alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
/* Filters */
.filters{background:var(--card);border-radius:12px;padding:16px 20px;margin-bottom:20px;box-shadow:var(--shadow);display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
.fg{flex:1;min-width:130px;}
.fg label{display:block;font-size:10px;font-weight:700;color:var(--muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px;}
.fg input,.fg select{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;transition:.2s;}
.fg input:focus,.fg select:focus{outline:none;border-color:var(--navy);}
.btn-filter{background:var(--navy);color:#fff;border:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;height:36px;}
.btn-filter:hover{background:var(--navy2);}
.btn-reset{background:#f3f4f6;color:var(--muted);border:none;padding:9px 14px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:6px;height:36px;}
.btn-reset:hover{background:#e5e7eb;}
/* KPI row */
.kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px;}
.kpi{background:var(--card);border-radius:12px;padding:16px 18px;box-shadow:var(--shadow);border-top:3px solid var(--kc,var(--navy));}
.kpi-val{font-size:28px;font-weight:900;color:var(--kc,var(--navy));line-height:1;}
.kpi-lbl{font-size:11px;color:var(--muted);margin-top:3px;font-weight:500;}
/* Table */
.table-card{background:var(--card);border-radius:12px;box-shadow:var(--shadow);overflow:hidden;}
.table-top{padding:12px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;font-size:13px;}
.table-responsive{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:12.5px;}
th{text-align:left;padding:11px 12px;background:#f8fafc;color:var(--navy);font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);}
td{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#f8faff;}
/* Badges */
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;}
.b-success{background:#d4edda;color:#155724;}.b-warning{background:#fff3cd;color:#856404;}
.b-danger{background:#f8d7da;color:#721c24;}.b-info{background:#d1ecf1;color:#0c5460;}
.b-draft{background:#e0e7ff;color:#3730a3;}.b-complete{background:#d4edda;color:#155724;}
.b-submitted{background:#cff4fc;color:#0c5460;}
/* Progress bar */
.prog-bar{width:80px;height:7px;background:#e5e7eb;border-radius:99px;overflow:hidden;display:inline-block;vertical-align:middle;margin-left:6px;}
.prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--teal),var(--green));}
/* Actions */
.actions{display:flex;gap:6px;}
.btn-icon{padding:5px 9px;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;transition:.15s;display:inline-flex;align-items:center;gap:4px;border:none;cursor:pointer;}
.btn-view{background:#e8edf8;color:var(--navy);}
.btn-view:hover{background:#d6dff0;}
.btn-edit{background:#fff3cd;color:#856404;}
.btn-edit:hover{background:#ffe69c;}
.btn-delete{background:#f8d7da;color:#721c24;}
.btn-delete:hover{background:#f5c6cb;}
/* Pagination */
.pagination{display:flex;justify-content:center;gap:6px;margin:20px 0;}
.page-link{display:block;padding:7px 13px;background:var(--card);border:1px solid var(--border);border-radius:7px;color:var(--navy);text-decoration:none;font-size:13px;font-weight:600;transition:.15s;}
.page-link:hover{background:#e8edf8;border-color:var(--navy);}
.page-link.active{background:var(--navy);color:#fff;border-color:var(--navy);}
@media(max-width:700px){.filters{gap:8px;}.kpi-row{grid-template-columns:1fr 1fr;}}
</style>
</head>
<body>
<div class="container">

<div class="page-header">
    <h1><i class="fas fa-clipboard-list"></i> Integration Assessments</h1>
    <div class="hdr-links">
        <a href="integration_assessment.php"><i class="fas fa-plus"></i> New Assessment</a>
        <a href="integration_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        <a href="integration_assessment_list.php" class="active"><i class="fas fa-list"></i> List</a>
    </div>
</div>

<?php if($success_msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_msg) ?></div><?php endif; ?>
<?php if($error_msg): ?><div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_msg) ?></div><?php endif; ?>

<!-- Filters -->
<form method="GET" class="filters">
    <div class="fg"><label>Period</label>
        <select name="period"><option value="">All Periods</option>
        <?php while($p=mysqli_fetch_assoc($periods_r)): ?>
        <option value="<?= htmlspecialchars($p['assessment_period']) ?>" <?= $period===$p['assessment_period']?'selected':'' ?>><?= htmlspecialchars($p['assessment_period']) ?></option>
        <?php endwhile; ?></select></div>
    <div class="fg"><label>County</label>
        <select name="county"><option value="">All Counties</option>
        <?php while($c=mysqli_fetch_assoc($counties_r)): ?>
        <option value="<?= htmlspecialchars($c['county_name']) ?>" <?= $county===$c['county_name']?'selected':'' ?>><?= htmlspecialchars($c['county_name']) ?></option>
        <?php endwhile; ?></select></div>
    <div class="fg"><label>Agency</label>
        <select name="agency"><option value="">All</option>
        <?php while($a=mysqli_fetch_assoc($agencies_r)): ?>
        <option value="<?= htmlspecialchars($a['agency']) ?>" <?= $agency===$a['agency']?'selected':'' ?>><?= htmlspecialchars($a['agency']) ?></option>
        <?php endwhile; ?></select></div>
    <div class="fg"><label>Level of Care</label>
        <select name="level_of_care"><option value="">All</option>
        <?php while($l=mysqli_fetch_assoc($care_levels_r)): ?>
        <option value="<?= htmlspecialchars($l['level_of_care_name']) ?>" <?= $level_of_care===$l['level_of_care_name']?'selected':'' ?>><?= htmlspecialchars($l['level_of_care_name']) ?></option>
        <?php endwhile; ?></select></div>
    <div class="fg"><label>Status</label>
        <select name="status"><option value="">All</option>
        <?php foreach(['Draft','Complete','Submitted'] as $s): ?>
        <option value="<?= $s ?>" <?= $status_f===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?></select></div>
    <div class="fg"><label>ART Site</label>
        <select name="art_site"><option value="">All</option>
        <option value="Yes" <?= $art_site==='Yes'?'selected':'' ?>>Yes</option>
        <option value="No" <?= $art_site==='No'?'selected':'' ?>>No</option></select></div>
    <div class="fg"><label>Uses EMR</label>
        <select name="uses_emr"><option value="">All</option>
        <option value="Yes" <?= $uses_emr==='Yes'?'selected':'' ?>>Yes</option>
        <option value="No" <?= $uses_emr==='No'?'selected':'' ?>>No</option></select></div>
    <div class="fg"><label>From Date</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
    <div class="fg"><label>To Date</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
    <div style="display:flex;gap:7px;align-items:flex-end">
        <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
        <a href="integration_assessment_list.php" class="btn-reset"><i class="fas fa-undo"></i> Reset</a>
    </div>
</form>

<?php
// KPI counts
$kpi_all = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) total,
     SUM(assessment_status='Draft') drafts,
     SUM(assessment_status='Complete') complete,
     SUM(assessment_status='Submitted') submitted,
     COUNT(DISTINCT county_name) counties
     FROM integration_assessments $where"));
?>
<div class="kpi-row">
    <div class="kpi" style="--kc:var(--navy)"><div class="kpi-val"><?= $kpi_all['total'] ?></div><div class="kpi-lbl">Total Assessments</div></div>
    <div class="kpi" style="--kc:#3730a3"><div class="kpi-val"><?= $kpi_all['drafts'] ?></div><div class="kpi-lbl">Draft (In Progress)</div></div>
    <div class="kpi" style="--kc:var(--green)"><div class="kpi-val"><?= $kpi_all['complete'] ?></div><div class="kpi-lbl">Complete (Ready)</div></div>
    <div class="kpi" style="--kc:var(--teal)"><div class="kpi-val"><?= $kpi_all['submitted'] ?></div><div class="kpi-lbl">Submitted</div></div>
    <div class="kpi" style="--kc:var(--amber)"><div class="kpi-val"><?= $kpi_all['counties'] ?></div><div class="kpi-lbl">Counties</div></div>
</div>

<!-- Table -->
<div class="table-card">
    <div class="table-top">
        <span>Showing <strong><?= min($limit,$total) ?></strong> of <strong><?= number_format($total) ?></strong> assessments</span>
        <span style="font-size:12px;color:var(--muted)"><i class="fas fa-info-circle"></i> Progress shows sections saved out of <?= $total_sections ?></span>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>ID</th><th>Period</th><th>Facility</th><th>MFL</th><th>County</th>
                    <th>Level</th><th>ART</th><th>EMR</th><th>Status</th><th>Progress</th>
                    <th>TX_CURR</th><th>SHA Enrolled</th><th>Collected By</th><th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if(mysqli_num_rows($rows_r)>0): while($row=mysqli_fetch_assoc($rows_r)):
                $ss=json_decode($row['sections_saved']??'[]',true)?:[];
                $n=count($ss); $pct=round($n/$total_sections*100);
                $status=$row['assessment_status']??'Draft';
                $status_cls=['Draft'=>'b-draft','Complete'=>'b-complete','Submitted'=>'b-submitted'][$status]??'b-draft';
            ?>
            <tr>
                <td><strong style="color:var(--navy)">#<?= $row['assessment_id'] ?></strong></td>
                <td><?= htmlspecialchars($row['assessment_period']??'') ?></td>
                <td><strong><?= htmlspecialchars($row['facility_name']??'') ?></strong></td>
                <td style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($row['mflcode']??'') ?></td>
                <td><?= htmlspecialchars($row['county_name']??'') ?></td>
                <td><?= htmlspecialchars($row['level_of_care_name']??'') ?></td>
                <td><span class="badge <?= $row['is_art_site']==='Yes'?'b-success':'b-danger' ?>"><?= $row['is_art_site']??'—' ?></span></td>
                <td><span class="badge <?= $row['uses_emr']==='Yes'?'b-success':($row['uses_emr']==='No'?'b-warning':'b-info') ?>"><?= $row['uses_emr']??'—' ?></span></td>
                <td><span class="badge <?= $status_cls ?>"><?= $status ?></span></td>
                <td>
                    <span style="font-size:11px;font-weight:700;color:<?= $pct>=100?'#155724':($pct>=50?'#856404':'#721c24') ?>"><?= $n ?>/<?= $total_sections ?></span>
                    <span class="prog-bar"><span class="prog-fill" style="width:<?= $pct ?>%"></span></span>
                </td>
                <td><?= number_format($row['tx_curr']??0) ?></td>
                <td><?= number_format($row['plhiv_enrolled_sha']??0) ?></td>
                <td style="font-size:11px"><?= htmlspecialchars($row['collected_by']??'') ?></td>
                <td style="font-size:11px"><?= $row['collection_date']?date('d M Y',strtotime($row['collection_date'])):'—' ?></td>
                <td class="actions">
                    <a href="integration_assessment.php?id=<?= $row['assessment_id'] ?>" class="btn-icon btn-edit" title="Continue editing">
                        <i class="fas fa-edit"></i> <?= $status==='Draft'?'Continue':'Edit' ?>
                    </a>
                    <a href="?delete=<?= $row['assessment_id'] ?>" class="btn-icon btn-delete" title="Delete"
                       onclick="return confirm('Delete assessment #<?= $row['assessment_id'] ?> for <?= addslashes($row['facility_name']??'') ?>? This cannot be undone.')">
                        <i class="fas fa-trash"></i>
                    </a>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="15" style="text-align:center;padding:50px;color:var(--muted)">
                <i class="fas fa-folder-open" style="font-size:38px;display:block;margin-bottom:10px;opacity:.4"></i>
                No assessments found
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if($total_pages>1): ?>
<div class="pagination">
    <?php if($page>1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="page-link"><i class="fas fa-chevron-left"></i></a><?php endif; ?>
    <?php for($i=max(1,$page-2);$i<=min($total_pages,$page+2);$i++): ?>
    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>" class="page-link <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if($page<$total_pages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="page-link"><i class="fas fa-chevron-right"></i></a><?php endif; ?>
</div>
<?php endif; ?>
</div>
</body>
</html>
