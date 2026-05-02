<?php
// digitization/print_issuance.php  — Printable Issuance Certificate
session_start();

$base_path   = dirname(__DIR__);
$config_path = $base_path . '/includes/config.php';
$sess_check  = $base_path . '/includes/session_check.php';

if (!file_exists($config_path)) die('Configuration file not found.');
include $config_path;
include $sess_check;

if (!isset($conn) || !$conn)          die('Database connection failed.');
if (!isset($_SESSION['user_id']))     { header('Location: ../login.php'); exit(); }

$invest_id = isset($_GET['invest_id']) ? (int)$_GET['invest_id'] : 0;
if ($invest_id <= 0) die('Invalid issuance ID.');

// Detect table name (pre- or post-migration)
$_air_table = 'assets_issuance_register';
$_tc = mysqli_query($conn, "SHOW TABLES LIKE 'assets_issuance_register'");
if (!$_tc || mysqli_num_rows($_tc) === 0) $_air_table = 'digital_innovation_investments';

// Fetch issuance record
$res = mysqli_query($conn, "SELECT air.*, amr.description AS amr_description,
       amr.model, amr.serial_number, amr.asset_category, amr.lpo_number,
       amr.current_condition, amr.project_name,
       et.emr_type_name,
       df.dig_funder_name AS funder_name
FROM assets_issuance_register air
LEFT JOIN asset_master_register amr ON air.asset_id = amr.asset_id
LEFT JOIN emr_types et ON air.emr_type_id = et.emr_type_id
LEFT JOIN digital_funders df ON air.dig_funder_id = df.dig_funder_id
WHERE air.invest_id = $invest_id LIMIT 1");

$r = $res ? mysqli_fetch_assoc($res) : null;
if (!$r) die('Issuance record not found.');

$issue_date = $r['issue_date'] ? date('d F Y', strtotime($r['issue_date'])) : '—';
$print_date = date('d F Y');
$cert_no    = 'AI-' . str_pad($invest_id, 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Issuance Certificate <?= $cert_no ?> – LVCT Health</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;background:#f5f3fb;color:#1a1a2e;font-size:13px}
.page{background:#fff;max-width:820px;margin:30px auto;padding:0;box-shadow:0 4px 24px rgba(45,0,138,.12);border-radius:8px;overflow:hidden;position:relative}
/* HEADER */
.cert-header{background:linear-gradient(135deg,#2D008A,#AC80EE);color:#fff;padding:28px 36px 20px;display:flex;justify-content:space-between;align-items:flex-start}
.cert-header .org h1{font-size:1.3rem;font-weight:700;letter-spacing:.3px}
.cert-header .org p{font-size:.8rem;opacity:.85;margin-top:4px}
.cert-header .cert-info{text-align:right}
.cert-no{font-size:1rem;font-weight:700;background:rgba(255,255,255,.18);padding:6px 14px;border-radius:20px;display:inline-block}
.cert-date{font-size:.78rem;opacity:.8;margin-top:6px}
/* TITLE BAR */
.cert-title{background:#04B04B;color:#fff;text-align:center;padding:11px;font-size:.95rem;font-weight:700;letter-spacing:1px;text-transform:uppercase}
/* BODY */
.cert-body{padding:28px 36px}
/* SECTION */
.section{margin-bottom:22px}
.section-head{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#2D008A;border-bottom:2px solid #e0d9f0;padding-bottom:5px;margin-bottom:12px}
/* GRID */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 20px}
.info-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px 20px}
.field{display:flex;flex-direction:column}
.field label{font-size:.72rem;color:#6c757d;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.field span{font-size:.9rem;font-weight:600;color:#1a1a2e;margin-top:2px;padding-bottom:5px;border-bottom:1px dashed #e0d9f0}
/* VALUE HIGHLIGHT */
.val-highlight{color:#2D008A}
/* SIGNATURE */
.sig-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;margin-top:28px}
.sig-box{border-top:2px solid #2D008A;padding-top:8px}
.sig-box p{font-size:.75rem;color:#6c757d;font-weight:600}
.sig-box strong{font-size:.82rem;color:#1a1a2e;display:block;margin-top:3px}
/* STATUS */
.status-badge{display:inline-block;padding:4px 14px;border-radius:20px;font-size:.78rem;font-weight:700}
.status-active{background:#d4f7e6;color:#04B04B}
.status-expired{background:#fde8eb;color:#E41E39}
/* FOOTER */
.cert-footer{background:#f5f3fb;border-top:2px solid #e0d9f0;padding:12px 36px;display:flex;justify-content:space-between;align-items:center;font-size:.75rem;color:#6c757d}
/* WATERMARK */
.watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);font-size:5rem;font-weight:900;color:rgba(45,0,138,.04);pointer-events:none;white-space:nowrap;z-index:0}
/* PRINT CONTROLS */
.no-print{background:#fff;max-width:820px;margin:0 auto 16px;padding:14px 24px;display:flex;gap:12px;justify-content:flex-end;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:7px;border:none;font-size:.88rem;font-weight:600;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-primary{background:#2D008A;color:#fff}
.btn-primary:hover{background:#1e005e}
.btn-outline{background:transparent;border:1.5px solid #2D008A;color:#2D008A}
.btn-outline:hover{background:#2D008A;color:#fff}
@media print{
    .no-print{display:none!important}
    body{background:#fff}
    .page{box-shadow:none;border-radius:0;margin:0;max-width:100%}
    .watermark{color:rgba(45,0,138,.06)}
}
</style>
</head>
<body>

<div class="no-print">
    <a href="view_asset_issues.php" class="btn btn-outline"><i>←</i> Back to Register</a>
    <a href="assets_issuance.php?edit=<?= $invest_id ?>" class="btn btn-outline">Edit Record</a>
    <button class="btn btn-primary" onclick="window.print()">🖨 Print / Save as PDF</button>
</div>

<div class="page">
    <div class="watermark">LVCT HEALTH</div>

    <div class="cert-header">
        <div class="org">
            <h1><img src="../assets/images/lvctlogonew.png" width="109.6" height="22.7" alt=""></h1>
            <p>Digital Innovation & Asset Management Unit</p>
            <p>Nairobi, Kenya &nbsp;|&nbsp; www.lvcthealth.org</p>
        </div>
        <div class="cert-info">
            <div class="cert-no"><?= $cert_no ?></div>
            <div class="cert-date">Printed: <?= $print_date ?></div>
        </div>
    </div>

    <div class="cert-title">Asset Issuance Certificate</div>

    <div class="cert-body">

        <!-- STATUS + DATES -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
            <div>
                <span style="font-size:.75rem;color:#6c757d;font-weight:600">STATUS &nbsp;</span>
                <span class="status-badge <?= $r['invest_status']==='Active'?'status-active':'status-expired' ?>">
                    <?= htmlspecialchars($r['invest_status']) ?>
                </span>
            </div>
            <div style="font-size:.82rem;color:#6c757d">
                Issue Date: <strong style="color:#2D008A"><?= $issue_date ?></strong>
                <?php if ($r['end_date']): ?>
                &nbsp;→&nbsp; End: <strong><?= date('d F Y', strtotime($r['end_date'])) ?></strong>
                <?php elseif ($r['no_end_date']): ?>
                &nbsp;→&nbsp; <em>No planned end date</em>
                <?php endif; ?>
            </div>
        </div>

        <!-- FACILITY -->
        <div class="section">
            <div class="section-head">Receiving Facility</div>
            <div class="info-grid">
                <div class="field"><label>Facility Name</label><span class="val-highlight"><?= htmlspecialchars($r['facility_name']) ?></span></div>
                <div class="field"><label>MFL Code</label><span><?= htmlspecialchars($r['mflcode'] ?? '—') ?></span></div>
                <div class="field"><label>County</label><span><?= htmlspecialchars($r['county_name'] ?? '—') ?></span></div>
                <div class="field"><label>Sub-County</label><span><?= htmlspecialchars($r['subcounty_name'] ?? '—') ?></span></div>
            </div>
        </div>

        <!-- ASSET DETAILS -->
        <div class="section">
            <div class="section-head">Asset Details</div>
            <div class="info-grid-3">
                <div class="field"><label>Category</label><span><?= htmlspecialchars($r['asset_category'] ?? '—') ?></span></div>
                <div class="field"><label>Description</label><span class="val-highlight"><?= htmlspecialchars($r['amr_description'] ?: ($r['asset_name'] ?? '—')) ?></span></div>
                <div class="field"><label>Model</label><span><?= htmlspecialchars($r['model'] ?? '—') ?></span></div>
                <div class="field"><label>Serial Number</label><span><?= htmlspecialchars($r['serial_number'] ?? '—') ?></span></div>
                <div class="field"><label>Tag / Label</label><span class="val-highlight"><?= htmlspecialchars($r['tag_name'] ?? '—') ?></span></div>
                <div class="field"><label>Condition</label><span><?= htmlspecialchars($r['current_condition'] ?? '—') ?></span></div>
                <div class="field"><label>Purchase Value (KES)</label><span><?= number_format((float)$r['purchase_value'], 2) ?></span></div>
                <div class="field"><label>Current Value (KES)</label><span><?= number_format((float)$r['current_value'], 2) ?></span></div>
                <div class="field"><label>Depreciation Rate</label><span><?= number_format((float)$r['depreciation_percentage'], 2) ?>% p.a.</span></div>
                <div class="field"><label>LPO Number</label><span><?= htmlspecialchars($r['lpo_number'] ?? '—') ?></span></div>
                <div class="field"><label>Funder</label><span><?= htmlspecialchars($r['funder_name'] ?? '—') ?></span></div>
                <div class="field"><label>Lot Number</label><span><?= htmlspecialchars($r['lot_number'] ?? '—') ?></span></div>
            </div>
        </div>

        <!-- DEPLOYMENT -->
        <div class="section">
            <div class="section-head">Deployment & Assignment</div>
            <div class="info-grid">
                <div class="field"><label>Assigned To</label><span class="val-highlight"><?= htmlspecialchars($r['name_of_user'] ?? '—') ?></span></div>
                <div class="field"><label>Department</label><span class="val-highlight"><?= htmlspecialchars($r['department_name'] ?? '—') ?></span></div>
                <div class="field"><label>Service Level</label><span><?= htmlspecialchars($r['service_level'] ?? '—') ?></span></div>
                <div class="field"><label>EMR Type</label><span><?= htmlspecialchars($r['emr_type_name'] ?? '—') ?></span></div>
                <?php if ($r['date_of_verification']): ?>
                <div class="field"><label>Last Verification</label><span><?= date('d F Y', strtotime($r['date_of_verification'])) ?></span></div>
                <?php endif; ?>
                <?php if ($r['date_of_disposal']): ?>
                <div class="field"><label>Date of Disposal</label><span><?= date('d F Y', strtotime($r['date_of_disposal'])) ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- SIGNATURES -->
        <div class="sig-row">
            <div class="sig-box">
                <p>Issued By</p>
                <strong><?= htmlspecialchars($r['created_by'] ?? '') ?></strong>
                <p style="margin-top:24px;font-size:.72rem;color:#aaa">Signature &amp; Date</p>
            </div>
            <div class="sig-box">
                <p>Received By</p>
                <strong><?= htmlspecialchars($r['name_of_user'] ?? '') ?></strong>
                <p style="margin-top:24px;font-size:.72rem;color:#aaa">Signature &amp; Date</p>
            </div>
            <div class="sig-box">
                <p>Authorised By</p>
                <strong>&nbsp;</strong>
                <p style="margin-top:24px;font-size:.72rem;color:#aaa">Signature &amp; Date</p>
            </div>
        </div>

    </div><!-- /cert-body -->

    <div class="cert-footer">
        <span>Ref: <?= $cert_no ?> &nbsp;|&nbsp; LVCT Health Asset Management System</span>
        <span>Generated: <?= date('d M Y H:i') ?></span>
    </div>
</div>

</body>
</html>
