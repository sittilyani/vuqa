<?php
// transition.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Get counties for dropdown
$counties = $conn->query("SELECT id, county_name FROM counties ORDER BY county_name");

// Get assessment periods (last 6 quarters)
$quarters = [];
for ($i = 0; $i < 6; $i++) {
    $year = date('Y') - floor($i/4);
    $q_num = 4 - ($i % 4);
    $quarters[] = "Q$q_num $year";
}

// Handle form submission
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_transition'])) {

    $county_id = (int)$_POST['county_id'];
    $assessment_period = mysqli_real_escape_string($conn, $_POST['assessment_period']);
    $assessed_by = mysqli_real_escape_string($conn, $_SESSION['full_name'] ?? '');
    $assessment_date = mysqli_real_escape_string($conn, $_POST['assessment_date']);
    $comments = mysqli_real_escape_string($conn, $_POST['comments'] ?? '');

    // Calculate total score
    $total_score = 0;
    for ($i = 1; $i <= 11; $i++) {
        $score = isset($_POST["t1_$i"]) ? (int)$_POST["t1_$i"] : 0;
        $total_score += $score;
    }

    // Determine level
    if ($total_score <= 13) $level = 'Low';
    elseif ($total_score <= 28) $level = 'Medium';
    elseif ($total_score <= 43) $level = 'High';
    else $level = 'Full Performance';

    // Build SQL
    $sql = "INSERT INTO transition_benchmarking (
        county_id, assessment_period, assessed_by, assessment_date,
        t1_1, t1_2, t1_3, t1_4, t1_5, t1_6, t1_7, t1_8, t1_9, t1_10, t1_11,
        total_score_t1, level_t1, comments, created_at
    ) VALUES (
        $county_id, '$assessment_period', '$assessed_by', '$assessment_date',
        " . (int)$_POST['t1_1'] . ", " . (int)$_POST['t1_2'] . ", " . (int)$_POST['t1_3'] . ",
        " . (int)$_POST['t1_4'] . ", " . (int)$_POST['t1_5'] . ", " . (int)$_POST['t1_6'] . ",
        " . (int)$_POST['t1_7'] . ", " . (int)$_POST['t1_8'] . ", " . (int)$_POST['t1_9'] . ",
        " . (int)$_POST['t1_10'] . ", " . (int)$_POST['t1_11'] . ",
        $total_score, '$level', '$comments', NOW()
    )";

    if ($conn->query($sql)) {
        $_SESSION['success_msg'] = 'Transition benchmarking assessment saved successfully!';
        header('Location: transition_list.php');
        exit();
    } else {
        $error = 'Error saving: ' . $conn->error;
    }
}

// Get saved scores for summary preview (if editing)
$saved_scores = [];
for ($i = 1; $i <= 11; $i++) {
    $saved_scores["t1_$i"] = $_POST["t1_$i"] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transition Benchmarking Assessment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f7;
            color: #333;
            line-height: 1.6;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

        .page-header {
            background: linear-gradient(135deg, #0D1A63 0%, #1a3a9e 100%);
            color: #fff;
            padding: 22px 30px;
            border-radius: 14px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 6px 24px rgba(13,26,99,.25);
        }
        .page-header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-header .hdr-links a {
            color: #fff;
            text-decoration: none;
            background: rgba(255,255,255,.15);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-left: 8px;
            transition: background .2s;
        }
        .page-header .hdr-links a:hover {
            background: rgba(255,255,255,.28);
        }

        .alert {
            padding: 13px 18px;
            border-radius: 9px;
            margin-bottom: 18px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Form sections */
        .form-section {
            background: #fff;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
            overflow: hidden;
        }
        .section-head {
            background: linear-gradient(90deg, #0D1A63, #1a3a9e);
            color: #fff;
            padding: 14px 22px;
            font-size: 15px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .section-head h2 {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-head .badge {
            background: rgba(255,255,255,.2);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        .section-body {
            padding: 22px;
        }

        /* Summary card */
        .summary-card {
            background: #f8fafc;
            border: 2px solid #0D1A63;
            border-radius: 12px;
            padding: 16px 22px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .summary-item {
            text-align: center;
            min-width: 120px;
        }
        .summary-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .summary-value {
            font-size: 32px;
            font-weight: 800;
            color: #0D1A63;
            line-height: 1.2;
        }
        .summary-level {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 14px;
        }
        .level-low { background: #f8d7da; color: #721c24; }
        .level-medium { background: #fff3cd; color: #856404; }
        .level-high { background: #d1ecf1; color: #0c5460; }
        .level-full { background: #d4edda; color: #155724; }

        /* Form elements */
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #444;
            font-size: 13px;
        }
        .form-control, .form-select {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            font-size: 13px;
            transition: all .2s;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: #0D1A63;
            box-shadow: 0 0 0 3px rgba(13,26,99,.1);
        }

        /* Table styles */
        .table-container {
            overflow-x: auto;
            margin: 20px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            background: #fff;
        }
        th {
            background: #f0f3fb;
            color: #0D1A63;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            padding: 12px 8px;
            border: 1px solid #dce3f5;
            text-align: center;
        }
        td {
            padding: 10px 8px;
            border: 1px solid #e8ecf5;
            vertical-align: middle;
        }
        .indicator-cell {
            font-weight: 500;
            color: #333;
            max-width: 300px;
        }
        .indicator-cell small {
            color: #666;
            font-size: 11px;
            display: block;
            margin-top: 3px;
        }

        /* Radio grid */
        .radio-grid {
            display: flex;
            gap: 5px;
            justify-content: center;
        }
        .radio-option {
            text-align: center;
            min-width: 50px;
        }
        .radio-option input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: #0D1A63;
            cursor: pointer;
        }
        .radio-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #555;
            margin-top: 3px;
        }
        .score-value {
            font-weight: 700;
            color: #0D1A63;
            font-size: 12px;
        }

        .total-row {
            background: #f0f3fb;
            font-weight: 700;
        }
        .total-row td {
            border-top: 2px solid #0D1A63;
        }

        .level-selector {
            background: #e8edf8;
            padding: 15px 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .level-options {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        .level-option {
            flex: 1;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid transparent;
        }
        .level-option.low { background: #f8d7da; color: #721c24; }
        .level-option.medium { background: #fff3cd; color: #856404; }
        .level-option.high { background: #d1ecf1; color: #0c5460; }
        .level-option.full { background: #d4edda; color: #155724; }
        .level-option.selected {
            border-color: #0D1A63;
            transform: scale(1.02);
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
        }

        .comments-section {
            margin-top: 20px;
        }
        textarea {
            width: 100%;
            padding: 12px;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            min-height: 100px;
            font-family: inherit;
        }

        .btn-submit {
            background: #0D1A63;
            color: #fff;
            padding: 14px 40px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: block;
            width: 100%;
            max-width: 340px;
            margin: 28px auto;
            transition: all .2s;
        }
        .btn-submit:hover {
            background: #1a2a7a;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13,26,99,.3);
        }

        .score-badge {
            display: inline-block;
            background: #0D1A63;
            color: #fff;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 11px;
            margin-left: 5px;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1><i class="fas fa-chart-line"></i> Transition Benchmarking Assessment</h1>
        <div class="hdr-links">
            <a href="transition_list.php"><i class="fas fa-list"></i> View All</a>
            <a href="transition_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form id="transitionForm" method="POST">
        <!-- Basic Information -->
        <div class="form-section">
            <div class="section-head">
                <h2><i class="fas fa-info-circle"></i> Assessment Information</h2>
            </div>
            <div class="section-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>County <span style="color: #dc3545;">*</span></label>
                        <select name="county_id" class="form-select" required>
                            <option value="">-- Select County --</option>
                            <?php while ($county = $counties->fetch_assoc()): ?>
                            <option value="<?= $county['id'] ?>"><?= htmlspecialchars($county['county_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Assessment Period <span style="color: #dc3545;">*</span></label>
                        <select name="assessment_period" class="form-select" required>
                            <option value="">-- Select Period --</option>
                            <?php foreach ($quarters as $quarter): ?>
                            <option value="<?= $quarter ?>"><?= $quarter ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Assessment Date</label>
                        <input type="date" name="assessment_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Live Summary Card -->
        <div class="summary-card" id="summaryCard">
            <div class="summary-item">
                <div class="summary-label">Total Score</div>
                <div class="summary-value" id="totalScore">0</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Level</div>
                <div id="levelDisplay">
                    <span class="summary-level level-low" id="levelBadge">Not Assessed</span>
                </div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Maximum Possible</div>
                <div class="summary-value">44</div>
            </div>
            <div class="summary-item">
                <div class="summary-label">Progress</div>
                <div style="width: 150px; height: 10px; background: #e8ecf5; border-radius: 10px; overflow: hidden; margin-top: 8px;">
                    <div id="progressBar" style="width: 0%; height: 100%; background: #0D1A63; border-radius: 10px; transition: width 0.3s;"></div>
                </div>
            </div>
        </div>

        <!-- T1: County Legislature Health Leadership and Governance -->
        <div class="form-section">
            <div class="section-head">
                <h2><i class="fas fa-landmark"></i> T1: County Legislature Health Leadership and Governance</h2>
                <span class="badge">Section Score: <span id="sectionScore">0</span>/44</span>
            </div>
            <div class="section-body">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40%;">Indicator</th>
                                <th style="width: 8%;">Fully Adequate<br><span style="font-weight: 400;">(4)</span></th>
                                <th style="width: 8%;">Partially Adequate<br><span style="font-weight: 400;">(3)</span></th>
                                <th style="width: 8%;">Structures Defined<br><span style="font-weight: 400;">(2)</span></th>
                                <th style="width: 8%;">Structures Defined No Evidence<br><span style="font-weight: 400;">(1)</span></th>
                                <th style="width: 8%;">Inadequate<br><span style="font-weight: 400;">(0)</span></th>
                                <th style="width: 10%;">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $indicators = [
                                1 => ['T1.1. Does the county have a legally constituted mechanism that oversees the health department?', '<small>e.g. County assembly health committee</small>'],
                                2 => ['T1.2. Does the county have an overall vision for the County Department of Health (CDOH) that is overseen by the County assembly health committee?', '<small>Check if the vision statement is included in County integrated development plan (CIDP)</small>'],
                                3 => ['T1.3. Are the roles of the County assembly health committee well-defined in the county health system?', '<small>Review the county assembly standing orders in respect to the health committee</small>'],
                                4 => ['T1.4. Are County assembly health committee meetings held regularly as stipulated; decisions documented; and reflect accountability and resource stewardship?', '<small>Check County assembly health committee minutes</small>'],
                                5 => ['T1.5. Does the County assembly health committee composition include members who are recognized for leadership and/or area of expertise and are representative of stakeholders including PLHIV/TB patients?', '<small>e.g. PLHIV ex officio members - check County assembly health committee minutes</small>'],
                                6 => ['T1.6. Does the County assembly health committee ensure that public interest is considered in decision making?', '<small>e.g. transparency of public participation - check assembly public participation standing rules</small>'],
                                7 => ['T1.7. How committed and accountable is the County assembly health committee in following up on agreed action items?', '<small>Check minutes</small>'],
                                8 => ['T1.8. Does the County assembly health committee have a risk management policy/framework?', '<small>Check the committee\'s standing rules</small>'],
                                9 => ['T1.9. How much oversight is given to HIV/TB activities in the county by the health committee of the county assembly?', '<small>Check minutes of previous meetings</small>'],
                                10 => ['T1.10. Is the leadership arrangement/structure for the HIV/TB program adequate to increase coverage and quality of HIV/TB services?', '<small>Review and compare the HIV/TB services organogram with that of the IP to find areas requiring strengthening</small>'],
                                11 => ['T1.11. Does the HIV/TB program planning and funding allow for sustainability?', '<small>Review annual workplan (AWP) and CIDP</small>'],
                            ];

                            for ($i = 1; $i <= 11; $i++):
                                $score = $saved_scores["t1_$i"];
                            ?>
                            <tr>
                                <td class="indicator-cell">
                                    <?= $indicators[$i][0] ?>
                                    <?= $indicators[$i][1] ?>
                                </td>
                                <td style="text-align: center;">
                                    <div class="radio-option">
                                        <input type="radio" name="t1_<?= $i ?>" value="4" <?= $score == 4 ? 'checked' : '' ?> onchange="updateSummary()">
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <div class="radio-option">
                                        <input type="radio" name="t1_<?= $i ?>" value="3" <?= $score == 3 ? 'checked' : '' ?> onchange="updateSummary()">
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <div class="radio-option">
                                        <input type="radio" name="t1_<?= $i ?>" value="2" <?= $score == 2 ? 'checked' : '' ?> onchange="updateSummary()">
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <div class="radio-option">
                                        <input type="radio" name="t1_<?= $i ?>" value="1" <?= $score == 1 ? 'checked' : '' ?> onchange="updateSummary()">
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <div class="radio-option">
                                        <input type="radio" name="t1_<?= $i ?>" value="0" <?= $score == 0 ? 'checked' : '' ?> onchange="updateSummary()">
                                    </div>
                                </td>
                                <td style="text-align: center; font-weight: 700; color: #0D1A63;" id="score_<?= $i ?>"><?= $score ?></td>
                            </tr>
                            <?php endfor; ?>
                            <tr class="total-row">
                                <td colspan="6" style="text-align: right; font-weight: 700;">Total CDOH =</td>
                                <td style="text-align: center; font-weight: 700; font-size: 16px; color: #0D1A63;" id="totalT1">0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Level Selection (Auto-calculated) -->
                <div class="level-selector">
                    <div style="font-weight: 700; margin-bottom: 10px;">Level of County Legislature Leadership and Governance:</div>
                    <div class="level-options">
                        <div class="level-option low" id="levelLow" onclick="setLevel('Low')">0�13: Low</div>
                        <div class="level-option medium" id="levelMedium" onclick="setLevel('Medium')">14�28: Medium</div>
                        <div class="level-option high" id="levelHigh" onclick="setLevel('High')">29�43: High</div>
                        <div class="level-option full" id="levelFull" onclick="setLevel('Full Performance')">44: Full Performance</div>
                    </div>
                    <input type="hidden" name="level_t1" id="levelInput" value="">
                </div>

                <!-- Comments -->
                <div class="comments-section">
                    <label style="font-weight: 600; display: block; margin-bottom: 8px;">Comments</label>
                    <textarea name="comments" placeholder="Enter any additional comments, observations, or justifications for scores..."><?= htmlspecialchars($_POST['comments'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <button type="submit" name="submit_transition" class="btn-submit">
            <i class="fas fa-save"></i> Save Assessment
        </button>
    </form>
</div>

<script>
// Calculate total and update summary
function updateSummary() {
    let total = 0;

    for (let i = 1; i <= 11; i++) {
        const radios = document.getElementsByName(`t1_${i}`);
        let score = 0;
        for (let radio of radios) {
            if (radio.checked) {
                score = parseInt(radio.value);
                break;
            }
        }
        total += score;
        document.getElementById(`score_${i}`).textContent = score;
    }

    // Update displays
    document.getElementById('totalT1').textContent = total;
    document.getElementById('totalScore').textContent = total;
    document.getElementById('sectionScore').textContent = total;

    // Update progress bar
    const percentage = (total / 44) * 100;
    document.getElementById('progressBar').style.width = percentage + '%';

    // Determine level
    let level = '';
    let levelClass = '';

    if (total <= 13) {
        level = 'Low';
        levelClass = 'level-low';
    } else if (total <= 28) {
        level = 'Medium';
        levelClass = 'level-medium';
    } else if (total <= 43) {
        level = 'High';
        levelClass = 'level-high';
    } else if (total == 44) {
        level = 'Full Performance';
        levelClass = 'level-full';
    }

    // Update level display
    const levelBadge = document.getElementById('levelBadge');
    levelBadge.textContent = level;
    levelBadge.className = 'summary-level ' + levelClass;

    // Update hidden input
    document.getElementById('levelInput').value = level;

    // Highlight corresponding level option
    document.querySelectorAll('.level-option').forEach(opt => {
        opt.classList.remove('selected');
    });

    if (total <= 13) document.getElementById('levelLow').classList.add('selected');
    else if (total <= 28) document.getElementById('levelMedium').classList.add('selected');
    else if (total <= 43) document.getElementById('levelHigh').classList.add('selected');
    else if (total == 44) document.getElementById('levelFull').classList.add('selected');
}

// Manual level selection (optional override)
function setLevel(level) {
    document.getElementById('levelInput').value = level;
    document.querySelectorAll('.level-option').forEach(opt => {
        opt.classList.remove('selected');
    });

    if (level === 'Low') document.getElementById('levelLow').classList.add('selected');
    else if (level === 'Medium') document.getElementById('levelMedium').classList.add('selected');
    else if (level === 'High') document.getElementById('levelHigh').classList.add('selected');
    else if (level === 'Full Performance') document.getElementById('levelFull').classList.add('selected');
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateSummary();

    // Add event listeners to all radio buttons
    for (let i = 1; i <= 11; i++) {
        const radios = document.getElementsByName(`t1_${i}`);
        radios.forEach(radio => {
            radio.addEventListener('change', updateSummary);
        });
    }
});

// Form validation
document.getElementById('transitionForm').addEventListener('submit', function(e) {
    // Check if all indicators have a score
    let allSelected = true;
    for (let i = 1; i <= 11; i++) {
        const radios = document.getElementsByName(`t1_${i}`);
        let checked = false;
        for (let radio of radios) {
            if (radio.checked) {
                checked = true;
                break;
            }
        }
        if (!checked) {
            allSelected = false;
            break;
        }
    }

    if (!allSelected) {
        e.preventDefault();
        alert('Please select a score for all 11 indicators before submitting.');
    }
});
</script>
</body>
</html>