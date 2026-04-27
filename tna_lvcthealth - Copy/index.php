<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include '../includes/config.php';

// Generate unique staff ID if not exists
if (!isset($_SESSION['temp_staff_id'])) {
    $_SESSION['temp_staff_id'] = 'STAFF_' . time() . '_' . rand(1000, 9999);
}
$staff_id = $_SESSION['temp_staff_id'];

// Fetch dropdown data
$departments = mysqli_query($conn, "SELECT department_id, department_name FROM departments ORDER BY department_name");
$divisions = mysqli_query($conn, "SELECT id, division_name FROM divisions ORDER BY division_name");
$projects = mysqli_query($conn, "SELECT id, project_name FROM projects ORDER BY project_name");
$learning_methods = mysqli_query($conn, "SELECT id, method_name FROM learning_methods");
$barriers = mysqli_query($conn, "SELECT id, barrier_name FROM barriers");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Training Needs Assessment System</title>
    <link rel="stylesheet" href="../assets/css/style.css" type="text/css">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head>
<body>
<div class="container">
    <!-- Header -->
    <div class="form-header">
        <div class="header-content">
            <div class="title-section">
                <h1>Training Needs Assessment System</h1>
                <p>Staff Registration & Skills Gap Analysis</p>
                <small>Staff ID: <strong id="staffIdDisplay"><?php echo $staff_id; ?></strong></small>
            </div>
            <div class="qr-section">
                <button type="button" class="qr-button" onclick="generateQR()">
                    Generate QR Code
                </button>
            </div>
        </div>
    </div>

    <!-- Progress -->
    <div class="progress-container">
        <div class="progress-stats">
            <span>Overall Completion</span>
            <span id="completionPercent">0%</span>
        </div>
        <div class="progress-bar-wrapper">
            <div class="progress-fill" id="progressFill">0%</div>
        </div>
    </div>

    <!-- Alert Messages -->
    <div id="alertSuccess" class="alert alert-success"></div>
    <div id="alertError" class="alert alert-error"></div>

    <!-- Tab Navigation -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="tab1">1. Staff Profile</button>
        <button class="tab-btn" data-tab="tab2">2. Qualifications</button>
        <button class="tab-btn" data-tab="tab3">3. Roles & Responsibilities</button>
        <button class="tab-btn" data-tab="tab4" id="supervisorTab" style="display:none;">4. Supervisor Assessment</button>
        <button class="tab-btn" data-tab="tab5">5. Skills Assessment</button>
        <button class="tab-btn" data-tab="tab6">6. Training Preferences</button>
        <button class="tab-btn" data-tab="tab7">7. Career Development</button>
        <button class="tab-btn" data-tab="tab8">8. Organizational Alignment</button>
    </div>

    <!-- Section 1: Staff Profile -->
    <div id="tab1" class="form-section active-section">
        <h2 class="section-title">Staff Profile</h2>
        <form id="section1Form">
            <input type="hidden" name="staff_id" value="<?php echo $staff_id; ?>">
            <div class="form-grid">
                <div class="form-group"><label>First Name <i>*</i></label><input type="text" name="first_name" required></div>
                <div class="form-group"><label>Last Name <i>*</i></label><input type="text" name="last_name" required></div>
                <div class="form-group"><label>Other Name</label><input type="text" name="other_name"></div>
                <div class="form-group"><label>Sex <i>*</i></label>
                    <select name="sex" required><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option></select>
                </div>
                <div class="form-group"><label>Department <i>*</i></label>
                    <select name="department_name" required><?php while($row = mysqli_fetch_assoc($departments)) echo "<option value='{$row['department_name']}'>{$row['department_name']}</option>"; mysqli_data_seek($departments, 0); ?></select>
                </div>
                <div class="form-group"><label>Division <i>*</i></label>
                    <select name="division_name" required><?php while($row = mysqli_fetch_assoc($divisions)) echo "<option value='{$row['division_name']}'>{$row['division_name']}</option>"; mysqli_data_seek($divisions, 0); ?></select>
                </div>
                <div class="form-group"><label>Project <i>*</i></label>
                    <select name="project_name" required><?php while($row = mysqli_fetch_assoc($projects)) echo "<option value='{$row['project_name']}'>{$row['project_name']}</option>"; ?></select>
                </div>
                <div class="form-group"><label>Are you a Supervisor? <i>*</i></label>
                    <select name="is_supervisor" required><option value="">Select</option><option value="Yes">Yes</option><option value="No">No</option></select>
                </div>
                <div class="form-group"><label>Date of Birth</label><input type="date" name="date_of_birth"></div>
                <div class="form-group"><label>Date of Joining</label><input type="date" name="date_of_joining"></div>
                <div class="form-group"><label>Years of Experience</label><input type="number" name="experience_years"></div>
                <div class="form-group"><label>Years in Current Role</label><input type="number" name="years_in_current_role"></div>
                <div class="form-group"><label>Upload Resume/CV (PDF max 1MB)</label><input type="file" name="resume" accept="application/pdf"></div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-primary" onclick="saveSection('section1')">Save & Continue</button>
            </div>
        </form>
    </div>

    <!-- Section 2: Qualifications -->
    <div id="tab2" class="form-section">
        <h2 class="section-title">Academic & Professional Qualifications</h2>
        <form id="section2Form">
            <input type="hidden" name="staff_id" value="<?php echo $staff_id; ?>">

            <h3>Academic Qualifications</h3>
            <table class="qualification-table" id="academicTable">
                <thead><tr><th>Degree</th><th>Institution</th><th>Status</th><th>Expected Date</th><th>Certificate</th><th></th></tr></thead>
                <tbody>
                    <tr>
                        <td><input type="text" name="academic_degree[]" placeholder="e.g. Bachelor's Degree"></td>
                        <td><input type="text" name="academic_institution[]"></td>
                        <td><input type="checkbox" name="academic_ongoing[]" value="1"> Ongoing</td>
                        <td><input type="month" name="academic_date[]"></td>
                        <td><input type="file" name="academic_cert[]" accept="application/pdf"></td>
                        <td><button type="button" class="btn-remove" onclick="removeRow(this)">?</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="btn-add" onclick="addRow('academicTable')">+ Add Academic Qualification</button>

            <h3 style="margin-top:30px">Professional Memberships</h3>
            <table class="qualification-table" id="proTable">
                <thead><tr><th>Membership Body</th><th>Membership ID</th><th>Status</th><th>Attachment</th><th></th></tr></thead>
                <tbody>
                    <tr>
                        <td><input type="text" name="pro_body[]" placeholder="e.g. KMPDC"></td>
                        <td><input type="text" name="pro_id[]"></td>
                        <td><select name="pro_status[]"><option>Active</option><option>Inactive</option><option>Expired</option></select></td>
                        <td><input type="file" name="pro_cert[]" accept="application/pdf"></td>
                        <td><button type="button" class="btn-remove" onclick="removeRow(this)">?</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="btn-add" onclick="addRow('proTable')">+ Add Membership</button>

            <div class="form-actions">
                <button type="button" class="btn-primary" onclick="saveSection('section2')">Save Section</button>
            </div>
        </form>
    </div>

    <!-- Section 3: Roles & Responsibilities -->
    <div id="tab3" class="form-section">
        <h2 class="section-title">Roles & Responsibilities</h2>
        <form id="section3Form">
            <input type="hidden" name="staff_id" value="<?php echo $staff_id; ?>">
            <div id="roles-container">
                <div class="role-block">
                    <div class="form-group"><label>Key Roles & Responsibilities</label><textarea name="roles_responsibilities[]" rows="3"></textarea></div>
                    <div class="form-group"><label>Minimum Required Qualifications</label><textarea name="qualifications[]" rows="2"></textarea></div>
                    <div class="form-group"><label>Minimum Required Experience (years)</label><input type="text" name="years_experience[]"></div>
                    <div class="form-group"><label>Understanding of Role</label><select name="role_understanding[]"><option>Very Clear</option><option>Moderately Clear</option><option>Unclear</option></select></div>
                    <div class="form-group"><label>Confident Aspects</label><textarea name="confident_aspects[]" rows="2"></textarea></div>
                    <div class="form-group"><label>Challenging Tasks</label><textarea name="challenging_tasks[]" rows="2"></textarea></div>
                    <div class="form-group"><label>Upload JD (PDF)</label><input type="file" name="jd_attachment[]" accept="application/pdf"></div>
                    <hr style="margin:20px 0">
                </div>
            </div>
            <button type="button" class="btn-add" onclick="addRoleBlock()">+ Add Another Role</button>
            <div class="form-actions"><button type="button" class="btn-primary" onclick="saveSection('section3')">Save Section</button></div>
        </form>
    </div>

    <!-- Section 4: Supervisor Assessment (Hidden initially) -->
    <div id="tab4" class="form-section">
        <h2 class="section-title">Supervisor Assessment</h2>
        <p><em>This section is for staff in supervisory positions only</em></p>
        <form id="section4Form">
            <input type="hidden" name="staff_id" value="<?php echo $staff_id; ?>">
            <div id="supervisor-container">
                <div class="supervisor-block">
                    <div class="form-group"><label>Position Supervised</label><input type="text" name="position[]" placeholder="e.g. Data Clerk"></div>
                    <div class="form-group"><label>Skills to Strengthen</label><textarea name="skills_gap[]" rows="3"></textarea></div>
                    <hr>
                </div>
            </div>
            <button type="button" class="btn-add" onclick="addSupervisorBlock()">+ Add Another Position</button>
            <div class="form-actions"><button type="button" class="btn-primary" onclick="saveSection('section4')">Save Section</button></div>
        </form>
    </div>

    <!-- Section 5: Skills Assessment -->
    <div id="tab5" class="form-section">
        <h2 class="section-title">Skills Self-Assessment</h2>
        <form id="section5Form">
            <input type="hidden" name="staff_id" value="<?php echo $staff_id; ?>">
            <div class="form-grid">
                <div class="form-group"><label>Technical Skills (1-5)</label><input type="number" name="technical" min="1" max="5"></div>
                <div class="form-group"><label>Communication Skills (1-5)</label><input type="number" name="communication" min="1" max="5"></div>
                <div class="form-group"><label>Leadership Skills (1-5)</label><input type="number" name="leadership" min="1" max="5"></div>
                <div class="form-group"><label>Teamwork (1-5)</label><input type="number" name="teamwork" min="1" max="5"></div>
                <div class="form-group"><label>Problem Solving (1-5)</label><input type="number" name="problem_solving" min="1" max="5"></div>
                <div class="form-group full-width"><label>Skills needing improvement</label><textarea name="skills_gap" rows="3"></textarea></div>
                <div class="form-group full-width"><label>Future skills needed (1-2 years)</label><textarea name="future_skills" rows="3"></textarea></div>
            </div>
            <div class="form-actions"><button type="button" class="btn-primary" onclick="saveSection('section5')">Save Section</button></div>
        </form>
    </div>

    <!-- Section 6: Training Preferences -->
    <div id="tab6" class="form-section">
        <h2 class="section-title">Training & Learning Preferences</h2>
        <form id="section6Form">
            <input type="hidden" name="staff_id" value="<?php echo $staff_id; ?>">
            <div class="form-group"><label style="color: #2D008A; font-weight: bold;">Most effective training type</label><textarea name="effective_training" rows="3"></textarea></div>
            <div class="form-group"><label style="color: #2D008A; font-weight: bold;">Preferred Learning Methods</label><br>
                <?php while($row = mysqli_fetch_assoc($learning_methods)) echo "<label><input type='checkbox' name='learning_methods[]' value='{$row['id']}'> {$row['method_name']}</label><br>"; ?>
            </div>
            <div class="form-group"><label style="color: #2D008A; font-weight: bold;">Barriers to Training</label><br>
                <?php while($row = mysqli_fetch_assoc($barriers)) echo "<label><input type='checkbox' name='barriers[]' value='{$row['id']}'> {$row['barrier_name']}</label><br>"; ?>
            </div>
            <div class="form-group"><label>Support Needed</label><textarea name="support_needed" rows="3"></textarea></div>
            <div class="form-actions"><button type="button" class="btn-primary" onclick="saveSection('section6')">Save Section</button></div>
        </form>
    </div>

    <!-- Section 7: Career Development -->
    <div id="tab7" class="form-section">
        <h2 class="section-title">Career Development</h2>
        <form id="section7Form">
            <input type="hidden" name="staff_id" value="<?php echo $staff_id; ?>">
            <div class="form-group"><label>Short-term goals (12 months)</label><textarea name="short_term" rows="3"></textarea></div>
            <div class="form-group"><label>Long-term goals (3-5 years)</label><textarea name="long_term" rows="3"></textarea></div>
            <div class="form-group"><label>Development opportunities needed</label><textarea name="development_opportunities" rows="3"></textarea></div>
            <div class="form-group"><label>Leadership/Cross-functional roles interest</label><textarea name="leadership_roles" rows="3"></textarea></div>
            <div class="form-actions"><button type="button" class="btn-primary" onclick="saveSection('section7')">Save Section</button></div>
        </form>
    </div>

    <!-- Section 8: Organizational Alignment -->
    <div id="tab8" class="form-section">
        <h2 class="section-title">Organizational Alignment</h2>
        <form id="section8Form">
            <input type="hidden" name="staff_id" value="<?php echo $staff_id; ?>">
            <div class="form-group"><label>How does your role contribute to organizational mission?</label><textarea name="role_contribution" rows="3"></textarea></div>
            <div class="form-group"><label>Organizational changes requiring training</label><textarea name="org_changes" rows="3"></textarea></div>
            <div class="form-group"><label>Organizational skills to strengthen</label><textarea name="org_skills" rows="3"></textarea></div>
            <div class="form-actions"><button type="button" class="btn-primary" onclick="saveSection('section8')">Save Section</button></div>
        </form>
    </div>
</div>

<!-- Loading Spinner -->
<div id="loading" class="loading">Saving... Please wait</div>

<!-- QR Modal -->
<div id="qrModal" class="modal">
    <div class="modal-content">
        <h3>Share Form Link</h3>
        <div id="qrcode"></div>
        <p>Scan QR code to access this form</p>
        <button class="close-modal" onclick="closeQRModal()">Close</button>
    </div>
</div>

<script src="../assets/js/main.js"></script>
<script>
// Initialize page
$(document).ready(function() {
    loadProgress();
    checkSupervisorStatus();

    // Tab switching
    $('.tab-btn').click(function() {
        var tabId = $(this).data('tab');
        $('.tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.form-section').removeClass('active-section');
        $('#' + tabId).addClass('active-section');
    });

    $('#section1Form select[name="is_supervisor"]').change(checkSupervisorStatus);
});

function checkSupervisorStatus() {
    var isSupervisor = $('#section1Form select[name="is_supervisor"]').val();
    if (isSupervisor === 'Yes') {
        $('#supervisorTab').show();
    } else {
        $('#supervisorTab').hide();
    }
}

function addRow(tableId) {
    var table = document.getElementById(tableId);
    var tbody = table.getElementsByTagName('tbody')[0];
    var newRow = tbody.rows[0].cloneNode(true);
    var inputs = newRow.getElementsByTagName('input');
    for(var i = 0; i < inputs.length; i++) {
        if(inputs[i].type === 'checkbox') inputs[i].checked = false;
        else if(inputs[i].type === 'file') inputs[i].value = '';
        else inputs[i].value = '';
    }
    var selects = newRow.getElementsByTagName('select');
    for(var i = 0; i < selects.length; i++) selects[i].selectedIndex = 0;
    tbody.appendChild(newRow);
}

function addRoleBlock() {
    var container = document.getElementById('roles-container');
    var clone = container.children[0].cloneNode(true);
    var inputs = clone.querySelectorAll('input, textarea, select');
    inputs.forEach(input => { if(input.type !== 'file') input.value = ''; else input.value = ''; });
    container.appendChild(clone);
}

function addSupervisorBlock() {
    var container = document.getElementById('supervisor-container');
    var clone = container.children[0].cloneNode(true);
    var inputs = clone.querySelectorAll('input, textarea');
    inputs.forEach(input => input.value = '');
    container.appendChild(clone);
}

function removeRow(btn) {
    var row = btn.closest('tr');
    var tbody = row.parentNode;
    if(tbody.rows.length > 1) row.remove();
    else alert("At least one entry is required");
}

function generateQR() {
    var url = window.location.href;
    var staffId = $('#staffIdDisplay').text();
    var qrUrl = url + '?staff_id=' + staffId;
    $('#qrcode').empty();
    new QRCode(document.getElementById("qrcode"), {
        text: qrUrl,
        width: 200,
        height: 200
    });
    $('#qrModal').css('display', 'flex');
}

function closeQRModal() {
    $('#qrModal').css('display', 'none');
}
</script>
</body>
</html>