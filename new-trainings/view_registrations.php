<?php
session_start();
include '../includes/config.php';
include '../includes/session_check.php';

if (!isset($_SESSION['full_name'])) {
    header("Location: login.php");
    exit();
}

$training_id = $_GET['training_id'] ?? 0;

if (!$training_id) {
    die("Invalid training ID");
}

// Fetch training details
$stmt = $conn->prepare("
    SELECT pt.*, c.course_name, cnt.county_name
    FROM planned_trainings pt
    LEFT JOIN courses c ON pt.course_id = c.course_id
    LEFT JOIN counties cnt ON pt.county_id = cnt.county_id
    WHERE pt.training_id = ?
");
$stmt->bind_param("i", $training_id);
$stmt->execute();
$training = $stmt->get_result()->fetch_assoc();

if (!$training) {
    die("Training not found");
}

// Fetch participants
$stmt = $conn->prepare("
    SELECT * FROM training_registrations
    WHERE training_id = ?
    ORDER BY registration_id DESC
");
$stmt->bind_param("i", $training_id);
$stmt->execute();
$participants = $stmt->get_result();

// Export to CSV
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="participants_' . $training['training_code'] . '_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Registration ID', 'Name', 'Gender', 'ID Number', 'Phone', 'Email', 'Facility', 'Department', 'Cadre', 'County', 'Subcounty', 'Disability', 'Submitted At']);

    while ($row = $participants->fetch_assoc()) {
        fputcsv($output, [
            $row['registration_id'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['gender'],
            $row['id_number'],
            $row['phone'],
            $row['email'],
            $row['facility_name'],
            $row['department'],
            $row['cadre'],
            $row['county'],
            $row['subcounty'],
            $row['disability_status'] . ($row['disability_type'] ? ': ' . $row['disability_type'] : ''),
            $row['submitted_at']
        ]);
    }
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Participants - <?php echo htmlspecialchars($training['course_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy: #0D1A63;
            --surface: #f4f7fc;
            --card: #ffffff;
            --border: #e2e8f0;
            --text: #1e293b;
            --radius: 12px;
        }

        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:var(--surface); font-family:'Segoe UI',system-ui,sans-serif; color:var(--text); }

        .page-header {
            background: linear-gradient(135deg, var(--navy) 0%, #162180 100%);
            color:#fff;
            padding:24px 32px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            flex-wrap:wrap;
            gap:16px;
        }
        .page-header h1 { font-size:1.4rem; font-weight:700; display:flex; align-items:center; gap:10px; }

        .btn {
            display:inline-flex; align-items:center; gap:7px;
            padding:9px 18px; border:none; border-radius:8px;
            font-size:.875rem; font-weight:600; cursor:pointer;
            text-decoration:none; transition:all .2s;
        }
        .btn-light   { background:#fff; color:var(--navy); }
        .btn-success { background:#10b981; color:#fff; }
        .btn-primary { background:#3b82f6; color:#fff; }

        .page-body { max-width:1400px; margin:0 auto; padding:28px 20px; }

        .card {
            background:var(--card);
            border-radius:var(--radius);
            box-shadow:0 2px 12px rgba(0,0,0,.07);
            overflow:hidden;
            margin-bottom:24px;
        }
        .card-header {
            background:var(--navy);
            color:#fff;
            padding:16px 24px;
            font-weight:600;
            font-size:1rem;
        }
        .card-body { padding:24px; }

        .stats {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
            gap:16px;
            margin-bottom:24px;
        }
        .stat-card {
            background:var(--card);
            border-radius:var(--radius);
            padding:20px;
            text-align:center;
            box-shadow:0 2px 8px rgba(0,0,0,.05);
        }
        .stat-number { font-size:2rem; font-weight:800; color:var(--navy); }
        .stat-label { color:#64748b; font-size:.85rem; margin-top:5px; }

        .table-scroll { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:.875rem; }
        th {
            background:#f1f5f9;
            padding:12px 16px;
            text-align:left;
            font-weight:600;
            color:var(--navy);
            border-bottom:2px solid var(--border);
        }
        td {
            padding:12px 16px;
            border-bottom:1px solid var(--border);
        }
        tr:hover td { background:#f8faff; }

        .empty-state {
            text-align:center;
            padding:60px 20px;
            color:#64748b;
        }
        .empty-state i { font-size:3rem; opacity:.3; margin-bottom:16px; }

        @media(max-width:600px){
            .page-header { padding:16px; }
            .card-body { padding:16px; }
            th, td { padding:8px 10px; font-size:.75rem; }
        }
    </style>
</head>
<body>

<header class="page-header">
    <h1><i class="fas fa-users"></i> Training Participants</h1>
    <div>
        <a href="planned_trainings.php" class="btn btn-light">
            <i class="fas fa-arrow-left"></i> Back to Trainings
        </a>
    </div>
</header>

<div class="page-body">

<!-- This calculates the budgets dynamically --> 
<div class="mb-3">
    <button type="button" class="btn btn-primary" onclick="openPaymentModal()">
        <i class="fas fa-money-bill-wave"></i> Process Payments
    </button>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="modal-overlay" style="display:none;">
    <div class="modal-box" style="max-width:600px;">
        <div class="modal-icon"><i class="fas fa-coins"></i></div>
        <div class="modal-title">Process Participant Payments</div>

        <form id="paymentForm">
            <input type="hidden" name="training_id" value="<?php echo $training_id; ?>">

            <div class="form-group">
                <label>Apply to:</label>
                <select name="apply_to" id="apply_to" class="form-control">
                    <option value="all">All Participants</option>
                    <option value="selected">Selected Participants Only</option>
                </select>
            </div>

            <div class="form-group">
                <label>Fare Amount (KES)</label>
                <input type="number" name="fare_amount" id="fare_amount" class="form-control" step="0.01" value="0">
            </div>

            <div class="form-group">
                <label>Airtime Amount (KES)</label>
                <input type="number" name="airtime_amount" id="airtime_amount" class="form-control" step="0.01" value="0">
            </div>

            <div class="form-group">
                <label>Perdiem Amount (KES)</label>
                <input type="number" name="perdiem_amount" id="perdiem_amount" class="form-control" step="0.01" value="0">
            </div>

            <div class="form-group">
                <label>Lunch Amount (KES)</label>
                <input type="number" name="lunch_amount" id="lunch_amount" class="form-control" step="0.01" value="0">
            </div>

            <div class="form-group">
                <label>Dinner Amount (KES)</label>
                <input type="number" name="dinner_amount" id="dinner_amount" class="form-control" step="0.01" value="0">
            </div>

            <div class="form-group">
                <label>Others Amount (KES)</label>
                <input type="number" name="others_amount" id="others_amount" class="form-control" step="0.01" value="0">
            </div>

            <div class="form-group">
                <label>Total Amount (KES)</label>
                <input type="number" name="total_amount" id="total_amount" class="form-control" readonly style="background:#f0f0f0;">
            </div>

            <div id="selectedParticipantsDiv" style="display:none;">
                <div class="form-group">
                    <label>Selected Participants:</label>
                    <div id="selectedCount"></div>
                </div>
            </div>

            <div class="modal-actions" style="margin-top:20px;">
                <button type="button" class="btn btn-outline" onclick="closePaymentModal()">Cancel</button>
                <button type="button" class="btn btn-success" onclick="processPayments()">
                    <i class="fas fa-check"></i> Process Payments
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Calculate total dynamically
$('#fare_amount, #airtime_amount, #perdiem_amount, #lunch_amount, #dinner_amount, #others_amount').on('input', function() {
    let fare = parseFloat($('#fare_amount').val()) || 0;
    let airtime = parseFloat($('#airtime_amount').val()) || 0;
    let perdiem = parseFloat($('#perdiem_amount').val()) || 0;
    let lunch = parseFloat($('#lunch_amount').val()) || 0;
    let dinner = parseFloat($('#dinner_amount').val()) || 0;
    let others = parseFloat($('#others_amount').val()) || 0;
    let total = fare + airtime + perdiem + lunch + dinner + others;
    $('#total_amount').val(total.toFixed(2));
});

// Quick fill dropdown for lunch
$('#lunch_amount').on('dblclick', function() {
    let val = prompt("Set lunch amount for all participants:", $('#lunch_amount').val());
    if(val !== null) $('#lunch_amount').val(val).trigger('input');
});

// Quick fill dropdown for dinner
$('#dinner_amount').on('dblclick', function() {
    let val = prompt("Set dinner amount for all participants:", $('#dinner_amount').val());
    if(val !== null) $('#dinner_amount').val(val).trigger('input');
});

function openPaymentModal() {
    // Load existing amounts from training settings via AJAX
    $.get('get_training_allowances.php', { training_id: <?php echo $training_id; ?> }, function(data) {
        if(data.success) {
            $('#fare_amount').val(data.fare);
            $('#airtime_amount').val(data.airtime);
            $('#perdiem_amount').val(data.perdiem);
            $('#lunch_amount').val(data.lunch);
            $('#dinner_amount').val(data.dinner);
            $('#fare_amount, #airtime_amount, #perdiem_amount, #lunch_amount, #dinner_amount, #others_amount').trigger('input');
        }
    }, 'json');

    $('#paymentModal').fadeIn();
}

function closePaymentModal() {
    $('#paymentModal').fadeOut();
}

function processPayments() {
    let applyTo = $('#apply_to').val();
    let selectedIds = [];

    if(applyTo === 'selected') {
        // Get checkboxes from registration table
        $('input[name="participant_checkbox"]:checked').each(function() {
            selectedIds.push($(this).val());
        });

        if(selectedIds.length === 0) {
            alert('Please select at least one participant');
            return;
        }

        $('#selectedCount').text(selectedIds.length + ' participants selected');
        $('#selectedParticipantsDiv').show();
    }

    let formData = {
        training_id: <?php echo $training_id; ?>,
        action: applyTo === 'all' ? 'apply_to_all' : 'process_selected',
        fare_amount: $('#fare_amount').val(),
        airtime_amount: $('#airtime_amount').val(),
        perdiem_amount: $('#perdiem_amount').val(),
        lunch_amount: $('#lunch_amount').val(),
        dinner_amount: $('#dinner_amount').val(),
        others_amount: $('#others_amount').val()
    };

    if(applyTo === 'selected') {
        formData.selected_ids = selectedIds;
    }

    $.post('process_payment.php', formData, function(response) {
        if(response.success) {
            alert(response.message);
            location.reload();
        } else {
            alert('Error: ' + response.message);
        }
    }, 'json');
}
</script>
    <div class="card">
        <div class="card-header">
            <i class="fas fa-info-circle"></i> Training Details
        </div>
        <div class="card-body">
            <h2 style="margin-bottom:10px;"><?php echo htmlspecialchars($training['course_name']); ?></h2>
            <p><strong>Code:</strong> <?php echo htmlspecialchars($training['training_code']); ?></p>
            <p><strong>Dates:</strong> <?php echo date('d M Y', strtotime($training['start_date'])); ?> - <?php echo date('d M Y', strtotime($training['end_date'])); ?></p>
            <p><strong>County:</strong> <?php echo htmlspecialchars($training['county_name'] ?? '—'); ?></p>
            <p><strong>Venue:</strong> <?php echo htmlspecialchars($training['venue_details'] ?? $training['location_name'] ?? '—'); ?></p>
        </div>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?php echo $participants->num_rows; ?></div>
            <div class="stat-label">Total Registered</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $training['max_participants']; ?></div>
            <div class="stat-label">Max Capacity</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo round(($participants->num_rows / max($training['max_participants'], 1)) * 100); ?>%</div>
            <div class="stat-label">Fill Rate</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fas fa-list"></i> Registered Participants</span>
            <a href="?training_id=<?php echo $training_id; ?>&export=1" class="btn btn-success btn-sm" style="padding:6px 12px; font-size:.75rem;">
                <i class="fas fa-download"></i> Export CSV
            </a>
        </div>
        <div class="card-body">
            <?php if ($participants->num_rows === 0): ?>
                <div class="empty-state">
                    <i class="fas fa-user-friends"></i>
                    <p>No participants registered yet.</p>
                    <p style="margin-top:10px;">Share the QR code to start receiving registrations.</p>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Gender</th>
                                <th>ID Number</th>
                                <th>Phone</th>
                                <th>Facility</th>
                                <th>Cadre</th>
                                <th>County</th>
                                <th>Registered On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $i = 1;
                            while($row = $participants->fetch_assoc()):
                            ?>
                            <tr>
                                <td><?php echo $i++; ?></td>
                                <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['gender']); ?></td>
                                <td><?php echo htmlspecialchars($row['id_number']); ?></td>
                                <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                <td><?php echo htmlspecialchars($row['facility_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['cadre']); ?></td>
                                <td><?php echo htmlspecialchars($row['county']); ?></td>
                                <td><?php echo date('d M Y H:i', strtotime($row['submitted_at'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

</body>
</html>