<?php
// transitions/transition_ai_advisor.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// -- Parameters ----------------------------------------------------------------
$county_id = isset($_GET['county']) ? (int)$_GET['county'] : 0;
$period    = isset($_GET['period']) ? mysqli_real_escape_string($conn, $_GET['period']) : '';
$action    = isset($_GET['action']) ? $_GET['action'] : '';  // analyse | workplan

// -- Filter options -------------------------------------------------------------
$counties_list = [];
$cr = mysqli_query($conn, "SELECT county_id, county_name FROM counties ORDER BY county_name");
if ($cr) while ($r = mysqli_fetch_assoc($cr)) $counties_list[] = $r;

$periods_list = [];
$pr = mysqli_query($conn,
    "SELECT DISTINCT assessment_period FROM transition_section_submissions ORDER BY assessment_period DESC");
if ($pr) while ($r = mysqli_fetch_assoc($pr)) $periods_list[] = $r['assessment_period'];

// -- Section & sub-indicator labels --------------------------------------------
$section_labels = [
    'leadership'             => 'County Level Leadership & Governance',
    'supervision'            => 'Routine Supervision & Mentorship',
    'special_initiatives'    => 'HIV/TB Special Initiatives (RRI, LEAP, Surge, SIMS)',
    'quality_improvement'    => 'Quality Improvement',
    'identification_linkage' => 'Patient Identification & Linkage',
    'retention_suppression'  => 'Patient Retention, Adherence & Viral Suppression',
    'prevention_kp'          => 'HIV Prevention & Key Populations',
    'finance'                => 'Finance Management',
    'sub_grants'             => 'Managing Sub-Grants',
    'commodities'            => 'Commodities Management',
    'equipment'              => 'Equipment Procurement & Use',
    'laboratory'             => 'Laboratory Services',
    'inventory'              => 'Inventory Management',
    'training'               => 'In-Service Training',
    'hr_management'          => 'Human Resource Management',
    'data_management'        => 'HIV/TB Program Data Management',
    'patient_monitoring'     => 'Patient Monitoring System',
    'institutional_ownership'=> 'Institutional Ownership',
];

// Sections that are CDOH-scored only (no IP)
$cdoh_only = ['leadership', 'institutional_ownership'];

// -- Load data from DB ---------------------------------------------------------
$county_name = '';
$assessment_data = [];   // section_key => row from transition_section_submissions
$raw_scores      = [];   // [section_key][sub_indicator_code] => [cdoh, ip, comments]

if ($county_id && $period) {
    // County name
    $cnr = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT county_name FROM counties WHERE county_id=$county_id"));
    $county_name = $cnr ? $cnr['county_name'] : '';

    // Section-level summaries from transition_section_submissions
    $sq = mysqli_query($conn,
        "SELECT tss.*, tss.cdoh_percent, tss.ip_percent, tss.cdoh_gap, tss.cdoh_ip_overlap
         FROM transition_section_submissions tss
         WHERE tss.county_id=$county_id AND tss.assessment_period='$period'
         ORDER BY tss.section_key");
    if ($sq) while ($r = mysqli_fetch_assoc($sq)) {
        $assessment_data[$r['section_key']] = $r;
    }

    // Raw sub-indicator scores + comments
    if ($assessment_data) {
        $aid_list = implode(',', array_unique(array_column($assessment_data, 'assessment_id')));
        $rq = mysqli_query($conn,
            "SELECT section_key, sub_indicator_code, cdoh_score, ip_score, comments
             FROM transition_raw_scores
             WHERE assessment_id IN ($aid_list)
             ORDER BY section_key, sub_indicator_code");
        if ($rq) while ($r = mysqli_fetch_assoc($rq)) {
            $raw_scores[$r['section_key']][$r['sub_indicator_code']] = $r;
        }
    }
}

// -- Build structured data payload for AI -------------------------------------
// This is what we send to Claude: clean, structured summary of scores
function build_ai_payload(array $assessment_data, array $raw_scores,
                           array $section_labels, array $cdoh_only,
                           string $county_name, string $period): array {

    $sections = [];
    foreach ($assessment_data as $sk => $row) {
        $label      = $section_labels[$sk] ?? $sk;
        $is_co      = in_array($sk, $cdoh_only);
        $cdoh_pct   = (float)($row['cdoh_percent'] ?? 0);
        $ip_pct     = $is_co ? null : (float)($row['ip_percent'] ?? 0);
        $cdoh_gap   = (float)($row['cdoh_gap']     ?? 0);
        $overlap    = (float)($row['cdoh_ip_overlap'] ?? 0);
        $readiness  = $cdoh_pct >= 70 ? 'Transition Ready'
                    : ($cdoh_pct >= 50 ? 'Needs Support'
                    : ($cdoh_pct >= 25 ? 'Significant Gaps'
                    : 'Critical — Not Ready'));

        // Low-scoring sub-indicators (CDOH score 0-2)
        $weak = [];
        if (isset($raw_scores[$sk])) {
            foreach ($raw_scores[$sk] as $sub_code => $sr) {
                if ($sr['cdoh_score'] !== null && (int)$sr['cdoh_score'] <= 2) {
                    $weak[] = [
                        'code'      => $sub_code,
                        'cdoh'      => (int)$sr['cdoh_score'],
                        'ip'        => $sr['ip_score'] !== null ? (int)$sr['ip_score'] : null,
                        'comments'  => trim($sr['comments'] ?? ''),
                    ];
                }
            }
        }

        $sections[] = [
            'section'       => $label,
            'key'           => $sk,
            'cdoh_percent'  => $cdoh_pct,
            'ip_percent'    => $ip_pct,
            'cdoh_gap'      => $cdoh_gap,
            'overlap'       => $overlap,
            'readiness'     => $readiness,
            'cdoh_only'     => $is_co,
            'comments'      => trim($row['submitted_by'] ?? '') . (trim($row['comments'] ?? '') ? ' — '.$row['comments'] : ''),
            'weak_indicators' => $weak,
        ];
    }

    // Sort by cdoh_percent ascending (worst first for priority)
    usort($sections, fn($a,$b) => $a['cdoh_percent'] <=> $b['cdoh_percent']);

    $overall_cdoh = count($sections) > 0
        ? round(array_sum(array_column($sections,'cdoh_percent')) / count($sections), 1)
        : 0;
    $overall_ip   = count(array_filter($sections, fn($s)=>!$s['cdoh_only'])) > 0
        ? round(array_sum(array_filter(array_column($sections,'ip_percent'), fn($v)=>$v!==null))
                / count(array_filter($sections, fn($s)=>!$s['cdoh_only'])), 1)
        : 0;

    return [
        'county'       => $county_name,
        'period'       => $period,
        'overall_cdoh' => $overall_cdoh,
        'overall_ip'   => $overall_ip,
        'readiness'    => $overall_cdoh >= 70 ? 'Transition Ready'
                        : ($overall_cdoh >= 50 ? 'Needs Support' : 'Not Ready'),
        'sections'     => $sections,
    ];
}

$ai_payload = ($county_id && $period && $assessment_data)
    ? build_ai_payload($assessment_data, $raw_scores, $section_labels, $cdoh_only, $county_name, $period)
    : null;

// -- Overall score for display -------------------------------------------------
$overall_cdoh = $ai_payload['overall_cdoh'] ?? 0;
$overall_ip   = $ai_payload['overall_ip']   ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Transition Advisor<?= $county_name ? " — $county_name" : '' ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f0f2f7;color:#1a1e2e;line-height:1.6;}
.container{max-width:1400px;margin:0 auto;padding:20px;}

/* Header */
.page-header{background:linear-gradient(135deg,#0D1A63,#1a3a9e);color:#fff;padding:22px 30px;border-radius:14px;margin-bottom:24px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 6px 24px rgba(13,26,99,.25);}
.page-header h1{font-size:1.5rem;font-weight:700;display:flex;align-items:center;gap:10px;}
.page-header .hdr-links a{color:#fff;text-decoration:none;background:rgba(255,255,255,.15);padding:7px 14px;border-radius:8px;font-size:13px;margin-left:8px;transition:.2s;}
.page-header .hdr-links a:hover{background:rgba(255,255,255,.28);}

/* Filter bar */
.filter-bar{background:#fff;border-radius:12px;padding:18px 22px;margin-bottom:24px;box-shadow:0 2px 14px rgba(0,0,0,.07);display:flex;flex-wrap:wrap;gap:15px;align-items:flex-end;}
.filter-group{flex:1;min-width:200px;}
.filter-group label{display:block;font-size:11px;font-weight:700;color:#666;margin-bottom:5px;text-transform:uppercase;}
.filter-group select{width:100%;padding:10px 12px;border:2px solid #e0e4f0;border-radius:8px;font-size:13px;}
.btn-load{background:#0D1A63;color:#fff;border:none;padding:10px 22px;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;}
.btn-load:hover{background:#1a3a9e;}

/* Score overview banner */
.score-banner{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;}
.score-card{background:#fff;border-radius:14px;padding:18px 20px;box-shadow:0 4px 20px rgba(0,0,0,.05);border-top:4px solid var(--kc);text-align:center;}
.score-card .val{font-size:36px;font-weight:900;color:var(--kc);line-height:1;}
.score-card .lbl{font-size:12px;color:#666;margin-top:5px;font-weight:500;}

/* Action buttons */
.action-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:28px;}
.action-btn{background:#fff;border-radius:14px;padding:22px 20px;box-shadow:0 4px 20px rgba(0,0,0,.05);border:2px solid #e0e4f0;cursor:pointer;transition:all .2s;text-align:center;text-decoration:none;display:block;}
.action-btn:hover{border-color:#0D1A63;transform:translateY(-2px);box-shadow:0 8px 28px rgba(13,26,99,.12);}
.action-btn.active{border-color:#0D1A63;background:#f0f3fb;}
.action-btn .icon{font-size:32px;margin-bottom:10px;}
.action-btn .title{font-size:15px;font-weight:700;color:#0D1A63;margin-bottom:6px;}
.action-btn .desc{font-size:12px;color:#888;line-height:1.5;}

/* AI output area */
.ai-panel{background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,.05);margin-bottom:24px;min-height:200px;}
.ai-panel-header{display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #e8ecf5;}
.ai-panel-header .ai-icon{width:44px;height:44px;background:linear-gradient(135deg,#0D1A63,#1a3a9e);border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0;}
.ai-panel-header h2{font-size:18px;font-weight:700;color:#0D1A63;}
.ai-panel-header .sub{font-size:12px;color:#888;margin-top:2px;}

/* Loading state */
.loading-state{text-align:center;padding:60px 20px;color:#888;}
.loading-state .spinner{width:48px;height:48px;border:4px solid #e0e4f0;border-top-color:#0D1A63;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 16px;}
@keyframes spin{to{transform:rotate(360deg);}}
.loading-state p{font-size:15px;font-weight:500;}
.loading-state .sub-text{font-size:13px;color:#aaa;margin-top:6px;}

/* Idle state */
.idle-state{text-align:center;padding:60px 20px;color:#bbb;}
.idle-state i{font-size:56px;margin-bottom:16px;display:block;opacity:.35;}
.idle-state p{font-size:15px;font-weight:500;color:#999;}

/* AI content rendering */
#aiOutput{font-size:14px;line-height:1.8;color:#333;}
#aiOutput h1,#aiOutput h2{color:#0D1A63;font-size:17px;font-weight:700;margin:18px 0 8px;border-bottom:2px solid #e8ecf5;padding-bottom:6px;}
#aiOutput h1:first-child,#aiOutput h2:first-child{margin-top:0;}
#aiOutput h3{color:#1a3a9e;font-size:15px;font-weight:700;margin:14px 0 6px;}
#aiOutput h4{color:#374151;font-size:14px;font-weight:700;margin:10px 0 4px;}
#aiOutput p{margin-bottom:12px;}
#aiOutput ul,#aiOutput ol{margin:8px 0 12px 24px;}
#aiOutput li{margin-bottom:5px;}
#aiOutput strong{color:#0D1A63;}
#aiOutput em{color:#555;}
#aiOutput table{width:100%;border-collapse:collapse;margin:12px 0;font-size:13px;}
#aiOutput table th{background:#f0f3fb;padding:9px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;color:#0D1A63;border-bottom:2px solid #0D1A63;}
#aiOutput table td{padding:9px 12px;border-bottom:1px solid #e8ecf5;vertical-align:top;}
#aiOutput table tr:last-child td{border-bottom:none;}
#aiOutput table tr:hover td{background:#f8fafc;}
#aiOutput blockquote{border-left:4px solid #0D1A63;background:#f0f3fb;padding:10px 16px;border-radius:0 8px 8px 0;margin:12px 0;font-style:italic;color:#555;}

/* Priority badges */
#aiOutput .priority-critical{background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;}
#aiOutput .priority-high{background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;}
#aiOutput .priority-medium{background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;}

/* Workplan specific */
.wp-header{background:linear-gradient(135deg,#0D1A63,#1a3a9e);color:#fff;padding:20px 24px;border-radius:12px;margin-bottom:20px;}
.wp-header h3{font-size:18px;font-weight:700;}
.wp-header p{font-size:13px;opacity:.8;margin-top:4px;}

/* Section scores mini table */
.scores-summary{overflow-x:auto;margin-bottom:24px;}
.scores-table{width:100%;border-collapse:collapse;font-size:12px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 14px rgba(0,0,0,.06);}
.scores-table th{background:#0D1A63;color:#fff;padding:10px 14px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;}
.scores-table td{padding:9px 14px;border-bottom:1px solid #e8ecf5;vertical-align:middle;}
.scores-table tr:last-child td{border-bottom:none;}
.scores-table tr:hover td{background:#f8fafc;}
.pct-bar{display:flex;align-items:center;gap:8px;}
.pct-track{flex:1;height:8px;background:#f0f0f0;border-radius:99px;overflow:hidden;}
.pct-fill{height:100%;border-radius:99px;}

/* Copy / export button */
.btn-export{background:#0D1A63;color:#fff;border:none;padding:9px 20px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;margin-top:16px;}
.btn-export:hover{background:#1a3a9e;}
.btn-secondary{background:#f3f4f6;color:#374151;border:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;margin-top:16px;margin-left:10px;}
.btn-secondary:hover{background:#e5e7eb;}

.empty-state{text-align:center;padding:80px 20px;color:#aaa;}
.empty-state i{font-size:56px;margin-bottom:18px;display:block;}

@media(max-width:900px){.action-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="container">

<!-- Header -->
<div class="page-header">
    <h1><i class="fas fa-robot"></i> AI Transition Advisor</h1>
    <div class="hdr-links">
        <a href="transition_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        <a href="transition_comparison_dashboard.php"><i class="fas fa-code-branch"></i> Compare</a>
        <a href="transition_index.php"><i class="fas fa-home"></i> Home</a>
    </div>
</div>

<!-- County / Period selector -->
<form method="GET" class="filter-bar" id="filterForm">
    <div class="filter-group">
        <label>County</label>
        <select name="county" onchange="this.form.submit()">
            <option value="">— Select County —</option>
            <?php foreach ($counties_list as $c): ?>
            <option value="<?= $c['county_id'] ?>" <?= $county_id==$c['county_id']?'selected':'' ?>>
                <?= htmlspecialchars($c['county_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Assessment Period</label>
        <select name="period" onchange="this.form.submit()">
            <option value="">— Select Period —</option>
            <?php foreach ($periods_list as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>" <?= $period===$p?'selected':'' ?>>
                <?= htmlspecialchars($p) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>">
</form>

<?php if (!$county_id || !$period || !$ai_payload): ?>
<!-- Empty state -->
<div class="empty-state">
    <i class="fas fa-robot"></i>
    <p style="font-size:17px;font-weight:700;color:#666;margin-bottom:8px">Select a County and Assessment Period to begin</p>
    <p style="font-size:13px">The AI Advisor will analyse transition readiness scores, recommend transition models,<br>and generate a prioritised work plan based on low-scoring indicators.</p>
</div>

<?php else: ?>

<!-- Score overview -->
<?php
$readiness_col = $overall_cdoh>=70?'#28a745':($overall_cdoh>=50?'#ffc107':'#dc3545');
$readiness_lbl = $overall_cdoh>=70?'Transition Ready':($overall_cdoh>=50?'Needs Support':'Not Ready');
$sections_count = count($assessment_data);
$low_sections   = count(array_filter($assessment_data, fn($r)=>($r['cdoh_percent']??0)<50));
?>
<div class="score-banner">
    <div class="score-card" style="--kc:<?= $readiness_col ?>">
        <div class="val"><?= $overall_cdoh ?>%</div>
        <div class="lbl"><i class="fas fa-building"></i> Overall CDOH Score</div>
    </div>
    <div class="score-card" style="--kc:#F5A623">
        <div class="val"><?= $overall_ip ?>%</div>
        <div class="lbl"><i class="fas fa-handshake"></i> Overall IP Score</div>
    </div>
    <div class="score-card" style="--kc:<?= $readiness_col ?>">
        <div class="val"><?= $readiness_lbl ?></div>
        <div class="lbl"><i class="fas fa-flag"></i> Readiness Level</div>
    </div>
    <div class="score-card" style="--kc:#dc3545">
        <div class="val"><?= $low_sections ?></div>
        <div class="lbl"><i class="fas fa-exclamation-triangle"></i> Sections Needing Support (&lt;50%)</div>
    </div>
    <div class="score-card" style="--kc:#0D1A63">
        <div class="val"><?= $sections_count ?></div>
        <div class="lbl"><i class="fas fa-layer-group"></i> Sections Assessed</div>
    </div>
</div>

<!-- Section scores mini table -->
<div class="scores-summary">
<table class="scores-table">
    <thead>
        <tr>
            <th>Section</th>
            <th>CDOH %</th>
            <th>IP %</th>
            <th>CDOH Gap</th>
            <th>Overlap</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
    <?php
    // Sort sections by cdoh_percent ascending
    $sorted = $assessment_data;
    uasort($sorted, fn($a,$b) => ($a['cdoh_percent']??0) <=> ($b['cdoh_percent']??0));
    foreach ($sorted as $sk => $row):
        $cp = (float)($row['cdoh_percent'] ?? 0);
        $ip = (float)($row['ip_percent']   ?? 0);
        $gp = (float)($row['cdoh_gap']     ?? 0);
        $ov = (float)($row['cdoh_ip_overlap'] ?? 0);
        $col = $cp>=70?'#28a745':($cp>=50?'#ffc107':'#dc3545');
        $st  = $cp>=70?'? Transition Ready':($cp>=50?'Needs Support':'? Critical');
        $is_co = in_array($sk,$cdoh_only);
    ?>
    <tr>
        <td><strong><?= htmlspecialchars($section_labels[$sk]??$sk) ?></strong></td>
        <td>
            <div class="pct-bar">
                <div class="pct-track"><div class="pct-fill" style="width:<?= $cp ?>%;background:<?= $col ?>"></div></div>
                <span style="font-weight:700;color:<?= $col ?>;min-width:38px"><?= $cp ?>%</span>
            </div>
        </td>
        <td style="font-weight:600;color:#b8860b"><?= $is_co ? '—' : $ip.'%' ?></td>
        <td style="font-weight:600;color:#dc3545"><?= $gp ?>%</td>
        <td style="font-weight:600;color:#27AE60"><?= $is_co ? '—' : $ov.'%' ?></td>
        <td><span style="font-size:11px;font-weight:700;color:<?= $col ?>"><?= $st ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- AI Action buttons -->
<div class="action-grid">
    <a href="?county=<?= $county_id ?>&period=<?= urlencode($period) ?>&action=analyse"
       class="action-btn <?= $action==='analyse'?'active':'' ?>">
        <div class="icon">??</div>
        <div class="title">Transition Readiness Analysis</div>
        <div class="desc">AI-powered narrative assessment of transition readiness with recommended transition model (graduated, supervised, or direct) per section.</div>
    </a>
    <a href="?county=<?= $county_id ?>&period=<?= urlencode($period) ?>&action=workplan"
       class="action-btn <?= $action==='workplan'?'active':'' ?>">
        <div class="icon">??</div>
        <div class="title">Priority Work Plan</div>
        <div class="desc">Structured work plan for sections scoring 0–2 on CDOH. Includes activities, responsible parties, timelines and expected outcomes drawn from assessment comments.</div>
    </a>
    <a href="?county=<?= $county_id ?>&period=<?= urlencode($period) ?>&action=capacity"
       class="action-btn <?= $action==='capacity'?'active':'' ?>">
        <div class="icon">??</div>
        <div class="title">Capacity Building Roadmap</div>
        <div class="desc">Step-by-step capacity strengthening roadmap covering training, mentorship, resource mobilisation and IP exit strategy per domain.</div>
    </a>
</div>

<!-- AI Output Panel -->
<div class="ai-panel" id="aiPanel">
    <div class="ai-panel-header">
        <div class="ai-icon"><i class="fas fa-robot"></i></div>
        <div>
            <h2 id="aiPanelTitle">
                <?php if ($action==='analyse'): ?>Transition Readiness Analysis
                <?php elseif ($action==='workplan'): ?>Priority Work Plan
                <?php elseif ($action==='capacity'): ?>Capacity Building Roadmap
                <?php else: ?>AI Advisor Ready<?php endif; ?>
            </h2>
            <div class="sub" id="aiPanelSub">
                <?= $county_name ?> · <?= htmlspecialchars($period) ?>
            </div>
        </div>
        <?php if ($action): ?>
        <div style="margin-left:auto;display:flex;gap:8px;">
            <button class="btn-secondary" onclick="copyOutput()"><i class="fas fa-copy"></i> Copy</button>
            <button class="btn-export" onclick="printOutput()"><i class="fas fa-print"></i> Print / PDF</button>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$action): ?>
    <div class="idle-state">
        <i class="fas fa-lightbulb"></i>
        <p>Choose an analysis type above to generate AI-powered insights</p>
        <p style="font-size:13px;color:#bbb;margin-top:8px">The AI will analyse <?= $sections_count ?> sections of assessment data for <?= htmlspecialchars($county_name) ?></p>
    </div>

    <?php else: ?>
    <!-- Loading spinner — visible until JS replaces it -->
    <div class="loading-state" id="loadingState">
        <div class="spinner"></div>
        <p>Generating AI analysis…</p>
        <p class="sub-text">Claude is reading <?= $sections_count ?> sections of assessment data</p>
    </div>
    <div id="aiOutput" style="display:none"></div>
    <?php endif; ?>
</div>

<?php endif; // county + period selected ?>
</div><!-- /container -->

<?php if ($action && $ai_payload): ?>
<script>
// -- Build the prompt based on action -----------------------------------------
const payload = <?= json_encode($ai_payload, JSON_PRETTY_PRINT) ?>;
const action  = <?= json_encode($action) ?>;
const county  = <?= json_encode($county_name) ?>;
const period  = <?= json_encode($period) ?>;

function buildPrompt(action, payload) {
    const county = payload.county;
    const period = payload.period;
    const overall_cdoh = payload.overall_cdoh;
    const overall_ip   = payload.overall_ip;
    const readiness    = payload.readiness;
    const sections     = payload.sections;

    // Build a compact text summary of sections
    const sectionSummary = sections.map(s => {
        let line = `- **${s.section}** (${s.key}): CDOH ${s.cdoh_percent}%`;
        if (!s.cdoh_only && s.ip_percent !== null) line += `, IP ${s.ip_percent}%`;
        line += `, Gap ${s.cdoh_gap}%`;
        if (!s.cdoh_only && s.overlap !== null) line += `, Overlap ${s.overlap}%`;
        line += ` ? ${s.readiness}`;
        if (s.weak_indicators && s.weak_indicators.length > 0) {
            const weak = s.weak_indicators.map(w => {
                let wl = `  • ${w.code} (CDOH:${w.cdoh}${w.ip!==null?', IP:'+w.ip:''})`;
                if (w.comments) wl += ` — *"${w.comments}"*`;
                return wl;
            }).join('\n');
            line += '\n  Weak sub-indicators:\n' + weak;
        }
        return line;
    }).join('\n\n');

    if (action === 'analyse') {
        return `You are a PEPFAR HIV/TB transition specialist advising on the handover of program responsibilities from Implementing Partners (IPs) to County Departments of Health (CDOH) in Kenya.

## Assessment Context
**County:** ${county}
**Period:** ${period}
**Overall CDOH Score:** ${overall_cdoh}% (${readiness})
**Overall IP Score:** ${overall_ip}%

## Scoring Scale (0–4)
- 4 = Fully adequate / Implements independently
- 3 = Partially adequate / Mostly independent
- 2 = Some evidence / Involved but not independent
- 1 = Minimal / Minimally involved
- 0 = Inadequate / Not involved

## Section Scores (sorted by CDOH, lowest first)
${sectionSummary}

## Instructions
Provide a comprehensive **Transition Readiness Analysis** with the following sections:

### 1. Executive Summary
2–3 paragraph narrative summary of the county's overall transition readiness, highlighting strengths and critical gaps.

### 2. Transition Readiness by Domain
For each section (group thematically — Leadership, Service Delivery, Health Systems), describe the readiness status and key findings. Highlight where IP involvement is still high vs. where CDOH has taken ownership.

### 3. Recommended Transition Model
For each section, recommend one of three transition models:
- **Direct Transition** (CDOH = 70%): IP can exit; CDOH takes full ownership
- **Graduated Transition** (CDOH 50–69%): Phased handover over 2–3 quarters with milestone-based IP withdrawal
- **Supervised Transition** (CDOH < 50%): IP remains, CDOH co-leads with intensive mentorship and capacity building

Format as a table: | Section | CDOH% | IP% | Model | Rationale | Timeline |

### 4. Priority Focus Areas
List the top 5 most critical areas needing immediate attention to accelerate transition, with specific rationale based on the scores and any field comments.

### 5. Transition Risks
Identify 3–5 key risks if transition proceeds without addressing the identified gaps, and suggest mitigation measures.

Be specific, evidence-based, and reference the actual scores and comments from the assessment data.`;

    } else if (action === 'workplan') {
        // Only include sections with weak indicators (cdoh score 0-2)
        const weakSections = sections.filter(s => s.weak_indicators && s.weak_indicators.length > 0);
        const weakSummary  = weakSections.map(s => {
            const weak = s.weak_indicators.map(w => {
                let wl = `    • ${w.code} [Score: ${w.cdoh}/4]`;
                if (w.ip !== null) wl += ` | IP: ${w.ip}/4`;
                if (w.comments) wl += `\n      Field comment: "${w.comments}"`;
                return wl;
            }).join('\n');
            return `**${s.section}** — CDOH: ${s.cdoh_percent}%\n${weak}`;
        }).join('\n\n');

        return `You are a PEPFAR HIV/TB transition specialist creating a detailed work plan for ${county} County to address capacity gaps identified during the ${period} Transition Benchmarking Assessment.

## Assessment Summary
**County:** ${county} | **Period:** ${period}
**Overall CDOH Score:** ${overall_cdoh}% | **Readiness:** ${readiness}

## Sections with Low CDOH Scores (Score 0–2 sub-indicators requiring action)
${weakSummary}

## Instructions
Create a **Priority Capacity Strengthening Work Plan** that will move the county from IP-dependence to CDOH autonomy. The work plan must:

1. Focus ONLY on sub-indicators where CDOH score is 0, 1, or 2
2. Incorporate field comments from assessors as context for each activity
3. Be realistic and time-bound (use quarterly milestones: Q1, Q2, Q3, Q4)
4. Assign clear responsible parties (CDOH, IP, CHMT, Facility Teams, MOH)

### Work Plan Format

For each section with gaps, create a table with these columns:
| Activity | Responsible | Support | Timeline | Resources Needed | Expected Outcome | Success Indicator |

Group activities by section. Within each section, prioritise by score (0 first, then 1, then 2).

### Additional requirements:
- After the tables, include a **Monitoring & Accountability** section describing how progress will be tracked
- Include a **Quick Wins** section (activities achievable within 30 days at minimal cost)
- End with a **Summary Resource Requirements** table (financial, human resource, technical)

Use the field comments from assessors to make activities context-specific and actionable. If a comment indicates a specific barrier (e.g., "county depends on partner support for transmitting reports"), address that barrier directly in the activity.`;

    } else if (action === 'capacity') {
        return `You are a PEPFAR HIV/TB transition specialist designing a Capacity Building Roadmap for ${county} County based on the ${period} Transition Benchmarking Assessment.

## Assessment Summary
**County:** ${county} | **Period:** ${period}
**Overall CDOH Score:** ${overall_cdoh}% | **IP Score:** ${overall_ip}%
**Readiness:** ${readiness}

## Section-by-Section Scores
${sectionSummary}

## Instructions
Design a comprehensive **Capacity Building Roadmap** to accelerate the county's journey to full program ownership. Structure it as follows:

### 1. Capacity Gap Analysis
A prioritised table of capacity gaps, grouped by:
- **Human Resources** (skills, staffing, training needs)
- **Systems & Processes** (SOPs, tools, data systems)
- **Financing & Resources** (budget, domestic resource mobilisation)
- **Governance & Leadership** (oversight, accountability mechanisms)

### 2. Phased Capacity Building Plan

**Phase 1 — Foundation (Months 1–3):**
Address score-0 and score-1 gaps. Intensive IP mentorship, training, and process documentation.

**Phase 2 — Strengthening (Months 4–6):**
Address score-2 gaps. Co-implementation, joint supervision, and system strengthening.

**Phase 3 — Consolidation (Months 7–12):**
Address score-3 gaps to reach score-4. CDOH-led implementation, IP advisory only. Prepare for IP exit.

For each phase, provide a table: | Activity | Target Section | Current Score | Target Score | Lead | Support | Milestones |

### 3. IP Exit Strategy
Describe a phased IP exit schedule by section, specifying:
- Sections where IP can exit immediately (CDOH = 70%)
- Sections requiring 6-month exit timeline (CDOH 50–70%)
- Sections requiring 12-month exit timeline (CDOH < 50%)

### 4. Resource Mobilisation Strategy
How the county can mobilise domestic resources to fill financing gaps identified in the Finance and Sub-Grants sections.

### 5. Success Metrics
Define 5–7 measurable indicators to track transition progress over the next 12 months, with baseline values from the current assessment.

Be specific and reference actual scores. Where assessors left comments, incorporate them into your recommendations.`;
    }
}

// -- Stream from Claude API ----------------------------------------------------
async function generateAnalysis() {
    const prompt = buildPrompt(action, payload);
    const outputEl  = document.getElementById('aiOutput');
    const loadingEl = document.getElementById('loadingState');

    try {
        const response = await fetch('https://api.anthropic.com/v1/messages', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: 'claude-sonnet-4-20250514',
                max_tokens: 4096,
                messages: [{ role: 'user', content: prompt }]
            })
        });

        const data = await response.json();

        if (!response.ok || !data.content || !data.content[0]) {
            throw new Error(data.error?.message || 'API request failed');
        }

        const text = data.content[0].text;

        // Convert markdown to HTML
        const html = markdownToHtml(text);
        loadingEl.style.display = 'none';
        outputEl.innerHTML = html;
        outputEl.style.display = 'block';

    } catch (err) {
        loadingEl.style.display = 'none';
        outputEl.style.display = 'block';
        outputEl.innerHTML = `<div style="background:#fee2e2;border:1px solid #fca5a5;padding:16px;border-radius:10px;color:#991b1b;">
            <strong><i class="fas fa-exclamation-triangle"></i> Error generating analysis</strong><br>
            <span style="font-size:13px">${err.message}</span>
        </div>`;
    }
}

// -- Simple Markdown ? HTML converter -----------------------------------------
function markdownToHtml(md) {
    let html = md
        // Tables
        .replace(/^\|(.+)\|\s*$/gm, (match) => match)
        .replace(/((?:^\|.+\|\s*\n)+)/gm, (block) => {
            const rows = block.trim().split('\n');
            let table = '<table>';
            rows.forEach((row, i) => {
                if (row.match(/^\|[-| :]+\|$/)) return; // separator row
                const cells = row.split('|').filter((c,idx,arr) => idx>0 && idx<arr.length-1);
                const tag = (i === 0) ? 'th' : 'td';
                table += '<tr>' + cells.map(c => `<${tag}>${c.trim()}</${tag}>`).join('') + '</tr>';
            });
            return table + '</table>';
        })
        // Headings
        .replace(/^#### (.+)$/gm, '<h4>$1</h4>')
        .replace(/^### (.+)$/gm, '<h3>$1</h3>')
        .replace(/^## (.+)$/gm, '<h2>$1</h2>')
        .replace(/^# (.+)$/gm, '<h1>$1</h1>')
        // Bold/italic
        .replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        // Blockquote
        .replace(/^> (.+)$/gm, '<blockquote>$1</blockquote>')
        // Unordered list
        .replace(/^([ \t]*)[-*•] (.+)$/gm, (m, indent, text) => {
            const depth = indent.length > 0 ? ' style="margin-left:20px"' : '';
            return `<li${depth}>${text}</li>`;
        })
        // Ordered list
        .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
        // Wrap consecutive <li> in <ul>
        .replace(/(<li[^>]*>.*<\/li>\s*)+/gs, '<ul>$&</ul>')
        // Horizontal rule
        .replace(/^---+$/gm, '<hr style="border:none;border-top:2px solid #e8ecf5;margin:16px 0">')
        // Line breaks ? paragraphs
        .split('\n\n')
        .map(block => {
            block = block.trim();
            if (!block) return '';
            if (block.match(/^<(h[1-4]|ul|ol|table|blockquote|hr)/)) return block;
            if (block.match(/^<li/)) return block;
            return '<p>' + block.replace(/\n/g, '<br>') + '</p>';
        })
        .join('\n');

    return html;
}

// -- Print / export ------------------------------------------------------------
function printOutput() {
    const content = document.getElementById('aiOutput').innerHTML;
    const title   = document.getElementById('aiPanelTitle').textContent;
    const win = window.open('', '_blank');
    win.document.write(`<!DOCTYPE html><html><head>
        <title>${title} — <?= htmlspecialchars($county_name) ?></title>
        <style>
            body{font-family:'Segoe UI',Arial,sans-serif;max-width:900px;margin:40px auto;padding:20px;color:#333;line-height:1.7;}
            h1,h2{color:#0D1A63;border-bottom:2px solid #e0e4f0;padding-bottom:6px;margin-top:24px;}
            h3{color:#1a3a9e;margin-top:18px;}
            h4{color:#374151;margin-top:14px;}
            table{width:100%;border-collapse:collapse;margin:14px 0;font-size:13px;}
            th{background:#0D1A63;color:#fff;padding:9px 12px;text-align:left;}
            td{padding:8px 12px;border-bottom:1px solid #e0e4f0;}
            ul,ol{margin:8px 0 12px 24px;}
            blockquote{border-left:4px solid #0D1A63;background:#f0f3fb;padding:10px 16px;margin:12px 0;}
            .print-header{background:#0D1A63;color:#fff;padding:20px;margin:-20px -20px 30px;border-radius:8px;}
            .print-header h1{color:#fff;border:none;font-size:20px;}
            .print-header p{opacity:.8;font-size:13px;margin-top:4px;}
            @media print{.no-print{display:none}}
        </style>
    </head><body>
        <div class="print-header">
            <h1>${title}</h1>
            <p><?= htmlspecialchars($county_name) ?> &nbsp;·&nbsp; <?= htmlspecialchars($period) ?> &nbsp;·&nbsp; CDOH: <?= $overall_cdoh ?>% &nbsp;·&nbsp; Generated: ${new Date().toLocaleDateString()}</p>
        </div>
        ${content}
        <script>window.onload=()=>window.print()<\/script>
    </body></html>`);
    win.document.close();
}

function copyOutput() {
    const text = document.getElementById('aiOutput').innerText;
    navigator.clipboard.writeText(text).then(() => {
        const btn = event.target.closest('button');
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(() => btn.innerHTML = orig, 2000);
    });
}

// -- Kick off generation on page load -----------------------------------------
document.addEventListener('DOMContentLoaded', generateAnalysis);
</script>
<?php endif; ?>
</body>
</html>