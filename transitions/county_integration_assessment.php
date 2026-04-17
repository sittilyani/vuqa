<?php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Get parameters
$county_id = isset($_GET['county_id']) ? (int)$_GET['county_id'] : 0;
$period = isset($_GET['period']) ? mysqli_real_escape_string($conn, $_GET['period']) : '';
$assessment_id = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;
$section_key = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : 'facility_profile';

// Get counties for dropdown
$counties = $conn->query("SELECT county_id, county_name, county_code, region FROM counties ORDER BY county_name");

// Get assessment periods
$quarters = [];
for ($i = 0; $i < 8; $i++) {
    $year = date('Y') - floor($i/4);
    $q_num = 4 - ($i % 4);
    $quarters[] = "Q$q_num $year";
}

// Define sections
$sections = [
    'facility_profile' => [
        'name' => 'Facility Profile & Leadership',
        'icon' => 'fa-hospital',
        'order' => 1,
        'questions' => [
            ['id' => 'supported_by_usdos_ip', 'label' => 'Q7. Is the health facility supported by US DoS IP?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Indicate Yes if the facility is supported by US DoS IP or No if not'],
            ['id' => 'is_art_site', 'label' => 'Q8. Is the health facility an ART site?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Indicate if the health facility is an ART site or No if not'],
            ['id' => 'hiv_tb_integrated', 'label' => 'Q9. Has the health facility integrated HIV/TB services within OPD or Chronic care model?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Please select YES, if the health facility has Integrated HIV/TB services within OPD or clinical care model, or NO if it has not.'],
            ['id' => 'hiv_tb_integration_model', 'label' => 'Q10. If yes, specify the type of integration model', 'type' => 'text', 'hint' => 'Please select the integration model types i.e., OPD, Chronic Care Center. The integration models are based on the national blue print and integration advisory memo. OPD - HIV and TB services integrated in OPD. Chronic Care Center - This model refers to HIV/TB services provided in the chronic care centers'],
            ['id' => 'tx_curr', 'label' => 'Q11. TX_CURR (last month of reporting)', 'type' => 'number', 'hint' => 'Indicate the Current on Treatment reported in the last month to the baseline'],
            ['id' => 'tx_curr_pmtct', 'label' => 'Q12. TX_CURR PMTCT (last month of reporting)', 'type' => 'number', 'hint' => 'Indicate the Current on Treatment for PMTCT reported in the last month to the baseline'],
            ['id' => 'plhiv_integrated_care', 'label' => 'Q13. Total PLHIVs receiving HIV/TB care through integrated models', 'type' => 'number', 'hint' => 'Indicate the total number of PLHIVs receiving HIV/TB care through integrated service models from the health facility that has integrated HIV/TB services.']
        ]
    ],
    'integration_pmtct_hts_prep' => [
        'name' => 'PMTCT, HTS & PrEP Integration',
        'icon' => 'fa-baby',
        'order' => 2,
        'questions' => [
            ['id' => 'pmtct_integrated_mnch', 'label' => 'Q14. Has the facility integrated PMTCT services in MNCH?', 'type' => 'radio', 'options' => ['Yes', 'No', 'NA'], 'hint' => 'Select YES if the health facility has integrated PMTCT services in MNCH, or NO if not, or Not Applicable'],
            ['id' => 'hts_integrated_opd', 'label' => 'Q15. Has the facility integrated HTS services in OPD?', 'type' => 'radio', 'options' => ['Yes', 'No', 'NA'], 'hint' => 'Select YES if the health facility has integrated HTS services in OPD, or NO if not, or Not Applicable'],
            ['id' => 'hts_integrated_ipd', 'label' => 'Q16. Has the facility integrated HTS services in IPD?', 'type' => 'radio', 'options' => ['Yes', 'No', 'NA'], 'hint' => 'Select YES if the health facility has integrated HTS services in IPD, or NO if not, or Not Applicable'],
            ['id' => 'hts_integrated_mnch', 'label' => 'Q17. Has the facility integrated HTS services in MNCH?', 'type' => 'radio', 'options' => ['Yes', 'No', 'NA'], 'hint' => 'Select YES if the health facility has integrated HTS services in MNCH, or NO if not, or Not Applicable'],
            ['id' => 'prep_integrated_opd', 'label' => 'Q18. Has the facility integrated PrEP services in OPD?', 'type' => 'radio', 'options' => ['Yes', 'No', 'NA'], 'hint' => 'Select YES if the health facility integrated HIV Prevention services (HTS & PrEP) in OPD, or NO if not, or Not Applicable'],
            ['id' => 'prep_integrated_ipd', 'label' => 'Q19. Has the facility integrated PrEP services in IPD?', 'type' => 'radio', 'options' => ['Yes', 'No', 'NA'], 'hint' => 'Select YES if the health facility integrated HIV Prevention services (HTS & PrEP) in IPD, or NO if not, or Not Applicable'],
            ['id' => 'prep_integrated_mnch', 'label' => 'Q20. Has the facility integrated PrEP services in MNCH?', 'type' => 'radio', 'options' => ['Yes', 'No', 'NA'], 'hint' => 'Select YES if the health facility integrated HIV Prevention services (HTS & PrEP) in MNCH, or NO if not, or Not Applicable']
        ]
    ],
    'emr_integration' => [
        'name' => 'EMR Integration',
        'icon' => 'fa-laptop-medical',
        'order' => 3,
        'questions' => [
            ['id' => 'uses_emr', 'label' => 'Q21. Does this facility use any EMR system?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Select Yes if the facility uses any Electronic Medical Record system'],
            ['id' => 'single_unified_emr', 'label' => 'Q24. Health facility has a single unified EMR system?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Indicate if the facility has a single unified EMR system across departments'],
            ['id' => 'lab_manifest_in_use', 'label' => 'Q37. Lab Manifest in use at the Health Facility?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Select Yes if Lab Manifest is in use'],
            ['id' => 'tibu_lite_lims_in_use', 'label' => 'Q38. Tibu Lite (LIMS) in use at the Health Facility?', 'type' => 'radio', 'options' => ['Yes', 'No', 'Partial'], 'hint' => 'Select Yes, No, or Partial if partially implemented'],
            ['id' => 'pharmacy_webadt_in_use', 'label' => 'Q41. Pharmacy WebADT in use at the Health Facility?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Select Yes if Pharmacy WebADT is in use'],
            ['id' => 'emr_interoperable_his', 'label' => 'Q42. Is the EMR interoperable with other HIS systems? (EID, WebADT, Lab)', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Indicate if the EMR can exchange data with other Health Information Systems']
        ]
    ],
    'hrh_transition' => [
        'name' => 'HRH Transition',
        'icon' => 'fa-users',
        'order' => 4,
        'questions' => [
            ['id' => 'hcw_total_pepfar', 'label' => 'Q43. Total HCWs supported by PEPFAR IP', 'type' => 'number', 'hint' => 'Enter total number of healthcare workers supported by PEPFAR'],
            ['id' => 'hcw_clinical_pepfar', 'label' => 'Q44. Clinical Staff', 'type' => 'number', 'hint' => 'Number of clinical staff supported'],
            ['id' => 'hcw_nonclinical_pepfar', 'label' => 'Q45. Non-Clinical Staff', 'type' => 'number', 'hint' => 'Number of non-clinical staff supported'],
            ['id' => 'hcw_data_pepfar', 'label' => 'Q46. Data Staff', 'type' => 'number', 'hint' => 'Number of data staff supported'],
            ['id' => 'hcw_community_pepfar', 'label' => 'Q47. Community-based Staff', 'type' => 'number', 'hint' => 'Number of community-based staff supported'],
            ['id' => 'hcw_other_pepfar', 'label' => 'Q48. Other', 'type' => 'number', 'hint' => 'Number of other staff supported'],
            ['id' => 'hcw_transitioned_clinical', 'label' => 'Q50. Clinical Staff Transitioned', 'type' => 'number', 'hint' => 'Number of clinical staff transitioned to county support'],
            ['id' => 'hcw_transitioned_nonclinical', 'label' => 'Q51. Non-Clinical Staff Transitioned', 'type' => 'number', 'hint' => 'Number of non-clinical staff transitioned'],
            ['id' => 'hcw_transitioned_data', 'label' => 'Q52. Data Staff Transitioned', 'type' => 'number', 'hint' => 'Number of data staff transitioned'],
            ['id' => 'hcw_transitioned_community', 'label' => 'Q53. Community-based Staff Transitioned', 'type' => 'number', 'hint' => 'Number of community-based staff transitioned'],
            ['id' => 'hcw_transitioned_other', 'label' => 'Q54. Other Transitioned', 'type' => 'number', 'hint' => 'Number of other staff transitioned']
        ]
    ],
    'sha_enrollment' => [
        'name' => 'SHA Enrollment',
        'icon' => 'fa-id-card',
        'order' => 5,
        'questions' => [
            ['id' => 'plhiv_enrolled_sha', 'label' => 'Q56. Total PLHIVs enrolled into SHA', 'type' => 'number', 'hint' => 'Total number of PLHIV enrolled in Social Health Authority'],
            ['id' => 'plhiv_sha_premium_paid', 'label' => 'Q57. PLHIVs enrolled with premium fully paid', 'type' => 'number', 'hint' => 'Number of PLHIV with fully paid premiums'],
            ['id' => 'pbfw_enrolled_sha', 'label' => 'Q58. Number of PBFW enrolled into SHA', 'type' => 'number', 'hint' => 'Number of Pregnant and Breastfeeding Women enrolled'],
            ['id' => 'pbfw_sha_premium_paid', 'label' => 'Q59. PBFW enrolled with premium fully paid', 'type' => 'number', 'hint' => 'Number of PBFW with fully paid premiums'],
            ['id' => 'sha_claims_submitted_ontime', 'label' => 'Q60. Has the facility been submitting SHA claims on time?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Indicate if SHA claims are submitted on time'],
            ['id' => 'sha_reimbursements_monthly', 'label' => 'Q61. In the last 3 months, has the facility consistently received SHA reimbursements monthly?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Indicate if SHA reimbursements are received monthly']
        ]
    ],
    'ta_mentorship' => [
        'name' => 'TA & Mentorship',
        'icon' => 'fa-chalkboard-teacher',
        'order' => 6,
        'questions' => [
            ['id' => 'ta_visits_total', 'label' => 'Q62. How many TA/Mentorship visits on HIV Prevention, HIV/TB and PMTCT were done in the last 3 months?', 'type' => 'number', 'hint' => 'Total number of Technical Assistance visits'],
            ['id' => 'ta_visits_moh_only', 'label' => 'Q63. Of the total TA visits, how many were done by MOH only (without IP staff)?', 'type' => 'number', 'hint' => 'Number of visits conducted by MOH staff only']
        ]
    ],
    'financing' => [
        'name' => 'Financing & Sustainability',
        'icon' => 'fa-coins',
        'order' => 7,
        'questions' => [
            ['id' => 'fif_collection_in_place', 'label' => 'Q64. Does the health facility have FIF collection mechanism in place?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Indicate if Facility Improvement Fund collection is in place'],
            ['id' => 'fif_includes_hiv_tb_pmtct', 'label' => 'Q65. Has FIF collection incorporated HIV Prevention, HIV/TB, PMTCT & MNCH services?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Indicate if FIF includes these services'],
            ['id' => 'sha_capitation_hiv_tb', 'label' => 'Q66. Is the facility receiving SHA capitation for HIV Prevention, HIV/TB, PMTCT & MNCH services?', 'type' => 'radio', 'options' => ['Yes', 'No'], 'hint' => 'Indicate if receiving SHA capitation for these services']
        ]
    ],
    'mortality' => [
        'name' => 'Mortality Outcomes',
        'icon' => 'fa-heartbeat',
        'order' => 8,
        'questions' => [
            ['id' => 'deaths_all_cause', 'label' => 'Q67. Total deaths from any cause (All-cause mortality)', 'type' => 'number', 'hint' => 'Total all-cause deaths'],
            ['id' => 'deaths_hiv_related', 'label' => 'Q68. HIV related deaths', 'type' => 'number', 'hint' => 'Number of HIV-related deaths'],
            ['id' => 'deaths_hiv_pre_art', 'label' => 'Q69. HIV deaths before ART linkage (late identification)', 'type' => 'number', 'hint' => 'Deaths before ART initiation'],
            ['id' => 'deaths_tb', 'label' => 'Q70. TB deaths', 'type' => 'number', 'hint' => 'Number of TB-related deaths'],
            ['id' => 'deaths_maternal', 'label' => 'Q71. Maternal deaths', 'type' => 'number', 'hint' => 'Number of maternal deaths'],
            ['id' => 'deaths_perinatal', 'label' => 'Q72. Perinatal deaths (stillbirths + early neonatal <7 days)', 'type' => 'number', 'hint' => 'Number of perinatal deaths']
        ]
    ],
    'integration_readiness' => [
        'name' => 'Integration Readiness & Sustainability',
        'icon' => 'fa-project-diagram',
        'order' => 9,
        'questions' => [
            ['id' => 'leadership_commitment', 'label' => 'Q86. Leadership commitment to HIV integration', 'type' => 'select', 'options' => ['High', 'Moderate', 'Low'], 'hint' => 'Rate leadership commitment level'],
            ['id' => 'transition_plan', 'label' => 'Q87. Is there a transition/integration plan?', 'type' => 'select', 'options' => ['Yes - Implemented', 'Yes - Not Implemented', 'No'], 'hint' => 'Indicate if a transition plan exists and its implementation status'],
            ['id' => 'hiv_in_awp', 'label' => 'Q88. HIV services included in AWP/Budget?', 'type' => 'select', 'options' => ['Fully', 'Partially', 'No'], 'hint' => 'Indicate if HIV services are in the Annual Work Plan'],
            ['id' => 'hrh_gap', 'label' => 'Q89. Estimated HRH gap (%)', 'type' => 'select', 'options' => ['0-10%', '10-30%', '>30%'], 'hint' => 'Estimated human resources for health gap'],
            ['id' => 'staff_multiskilled', 'label' => 'Q90. Are staff multi-skilled?', 'type' => 'select', 'options' => ['Yes', 'Partial', 'No'], 'hint' => 'Indicate if staff are trained in multiple areas'],
            ['id' => 'roving_staff', 'label' => 'Q91. Is there roving/visiting HIV/TB staff?', 'type' => 'select', 'options' => ['Yes - Regular', 'Yes - Irregular', 'No'], 'hint' => 'Indicate availability of roving staff'],
            ['id' => 'infrastructure_capacity', 'label' => 'Q92. Infrastructure capacity for integration', 'type' => 'select', 'options' => ['Adequate', 'Minor changes needed', 'Major redesign needed'], 'hint' => 'Assess infrastructure readiness'],
            ['id' => 'space_adequacy', 'label' => 'Q93. Space adequacy', 'type' => 'select', 'options' => ['Adequate', 'Congested', 'Severely Inadequate'], 'hint' => 'Assess space availability'],
            ['id' => 'service_delivery_without_ccc', 'label' => 'Q94. Can HIV services run without CCC?', 'type' => 'select', 'options' => ['Yes', 'Partially', 'No'], 'hint' => 'Indicate if services can operate without Chronic Care Center'],
            ['id' => 'avg_wait_time', 'label' => 'Q95. Average patient waiting time', 'type' => 'select', 'options' => ['<1 hour', '1-3 hours', '>3 hours'], 'hint' => 'Average patient waiting time'],
            ['id' => 'data_integration_level', 'label' => 'Q96. Data integration level', 'type' => 'select', 'options' => ['Fully Integrated', 'Partial', 'Fragmented'], 'hint' => 'Level of data integration across systems'],
            ['id' => 'financing_coverage', 'label' => 'Q97. Financing coverage for HIV services', 'type' => 'select', 'options' => ['High', 'Moderate', 'Low'], 'hint' => 'Rate financing coverage'],
            ['id' => 'disruption_risk', 'label' => 'Q98. Risk of service disruption', 'type' => 'select', 'options' => ['Low', 'Moderate', 'High'], 'hint' => 'Assess risk of service disruption'],
            ['id' => 'integration_barriers', 'label' => 'Q99. Key barriers to integration', 'type' => 'textarea', 'hint' => 'List the main barriers to successful integration']
        ]
    ]
];

// Handle AJAX save section
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_section') {
    header('Content-Type: application/json');

    $county_id = (int)$_POST['county_id'];
    $period = mysqli_real_escape_string($conn, $_POST['period']);
    $section_key = mysqli_real_escape_string($conn, $_POST['section_key']);
    $section_data = json_decode($_POST['section_data'], true);
    $completed_by = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System';

    // Check if assessment exists
    $check_assessment = $conn->prepare("SELECT assessment_id, is_completed FROM county_integration_assessments WHERE county_id = ? AND assessment_period = ?");
    $check_assessment->bind_param("is", $county_id, $period);
    $check_assessment->execute();
    $assessment_result = $check_assessment->get_result();

    if ($assessment_result->num_rows == 0) {
        // Create new assessment
        $insert_assessment = $conn->prepare("INSERT INTO county_integration_assessments (county_id, assessment_period, assessment_date) VALUES (?, ?, ?)");
        $assessment_date = date('Y-m-d');
        $insert_assessment->bind_param("iss", $county_id, $period, $assessment_date);
        $insert_assessment->execute();
        $assessment_id = $conn->insert_id;
    } else {
        $row = $assessment_result->fetch_assoc();
        $assessment_id = $row['assessment_id'];
        if ($row['is_completed'] == 1) {
            echo json_encode(['success' => false, 'message' => 'Assessment already completed for this period. Cannot modify.']);
            exit();
        }
    }

    // Save or update section
    $section_name = $sections[$section_key]['name'];
    $is_completed = isset($_POST['mark_completed']) ? 1 : 0;
    $completed_at = $is_completed ? date('Y-m-d H:i:s') : null;
    $completed_by_field = $is_completed ? $completed_by : null;

    $section_data_json = json_encode($section_data);

    $check_section = $conn->prepare("SELECT section_id FROM county_integration_sections WHERE assessment_id = ? AND section_key = ?");
    $check_section->bind_param("is", $assessment_id, $section_key);
    $check_section->execute();
    $section_result = $check_section->get_result();

    if ($section_result->num_rows > 0) {
        $update_section = $conn->prepare("UPDATE county_integration_sections SET data = ?, is_completed = ?, completed_by = ?, completed_at = ?, updated_at = NOW() WHERE assessment_id = ? AND section_key = ?");
        $update_section->bind_param("sissis", $section_data_json, $is_completed, $completed_by_field, $completed_at, $assessment_id, $section_key);
        $update_section->execute();
    } else {
        $insert_section = $conn->prepare("INSERT INTO county_integration_sections (assessment_id, section_key, section_name, data, is_completed, completed_by, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_section->bind_param("isssiss", $assessment_id, $section_key, $section_name, $section_data_json, $is_completed, $completed_by_field, $completed_at);
        $insert_section->execute();
    }

    // Check if all sections are completed
    $check_all = $conn->prepare("SELECT COUNT(*) as total, SUM(is_completed) as completed FROM county_integration_sections WHERE assessment_id = ?");
    $check_all->bind_param("i", $assessment_id);
    $check_all->execute();
    $all_result = $check_all->get_result();
    $stats = $all_result->fetch_assoc();

    $all_completed = ($stats['total'] == count($sections) && $stats['completed'] == count($sections));

    if ($all_completed) {
        $update_assessment = $conn->prepare("UPDATE county_integration_assessments SET is_completed = 1, completed_by = ?, completed_at = NOW() WHERE assessment_id = ?");
        $update_assessment->bind_param("si", $completed_by, $assessment_id);
        $update_assessment->execute();
    }

    echo json_encode(['success' => true, 'assessment_id' => $assessment_id, 'all_completed' => $all_completed]);
    exit();
}

// Load existing data if editing
$existing_data = [];
if ($assessment_id) {
    $load_query = $conn->prepare("SELECT section_key, data, is_completed, completed_by, completed_at FROM county_integration_sections WHERE assessment_id = ?");
    $load_query->bind_param("i", $assessment_id);
    $load_query->execute();
    $load_result = $load_query->get_result();
    while ($row = $load_result->fetch_assoc()) {
        $existing_data[$row['section_key']] = [
            'data' => json_decode($row['data'], true),
            'is_completed' => $row['is_completed'],
            'completed_by' => $row['completed_by'],
            'completed_at' => $row['completed_at']
        ];
    }
}

// Get completion status for all sections
$completion_status = [];
$current_assessment_id = 0;
if ($county_id && $period) {
    $status_query = $conn->prepare("SELECT assessment_id, is_completed FROM county_integration_assessments WHERE county_id = ? AND assessment_period = ?");
    $status_query->bind_param("is", $county_id, $period);
    $status_query->execute();
    $status_result = $status_query->get_result();
    if ($status_row = $status_result->fetch_assoc()) {
        $current_assessment_id = $status_row['assessment_id'];
        $is_fully_completed = $status_row['is_completed'];

        $section_status_query = $conn->prepare("SELECT section_key, is_completed FROM county_integration_sections WHERE assessment_id = ?");
        $section_status_query->bind_param("i", $current_assessment_id);
        $section_status_query->execute();
        $section_status_result = $section_status_query->get_result();
        while ($srow = $section_status_result->fetch_assoc()) {
            $completion_status[$srow['section_key']] = $srow['is_completed'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>County Integration Assessment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f7;
            color: #333;
            line-height: 1.6;
        }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }

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
        .page-header h1 { font-size: 1.4rem; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .page-header .hdr-links a {
            color: #fff;
            text-decoration: none;
            background: rgba(255,255,255,.15);
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-left: 8px;
            transition: background .2s;
        }
        .page-header .hdr-links a:hover { background: rgba(255,255,255,.28); }

        .alert {
            padding: 13px 18px;
            border-radius: 9px;
            margin-bottom: 18px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }

        /* Setup Card */
        .setup-card {
            background: #fff;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 24px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
        }
        .setup-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .setup-field label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .setup-field select, .setup-field input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e4f0;
            border-radius: 10px;
            font-size: 14px;
        }
        .btn-load {
            background: #0D1A63;
            color: #fff;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Section Tabs */
        .section-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
            background: #fff;
            padding: 15px;
            border-radius: 14px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
        }
        .section-tab {
            padding: 10px 20px;
            background: #f8fafc;
            border-radius: 30px;
            border: 2px solid #e0e4f0;
            font-size: 13px;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            transition: all .2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-tab:hover { background: #e8edf8; border-color: #0D1A63; }
        .section-tab.active { background: #0D1A63; color: #fff; border-color: #0D1A63; }
        .section-tab.completed { background: #d4edda; border-color: #28a745; color: #155724; }
        .section-tab.incomplete { background: #f8d7da; border-color: #dc3545; color: #721c24; }

        /* Form Card */
        .form-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
            overflow: hidden;
        }
        .form-header {
            background: linear-gradient(90deg, #f8fafc, #fff);
            padding: 18px 25px;
            border-bottom: 1px solid #e8ecf5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .form-header h2 {
            font-size: 16px;
            font-weight: 700;
            color: #0D1A63;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-status {
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-completed { background: #d4edda; color: #155724; }
        .status-incomplete { background: #f8d7da; color: #721c24; }
        .form-body { padding: 25px; }

        .question-group {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e8ecf5;
        }
        .question-group label {
            display: block;
            font-weight: 600;
            color: #0D1A63;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .question-hint {
            color: #0066cc;
            font-size: 12px;
            margin-bottom: 10px;
            font-style: italic;
        }
        .yn-group {
            display: flex;
            gap: 20px;
            margin-top: 5px;
        }
        .yn-opt {
            display: flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
        }
        .yn-opt input { width: 16px; height: 16px; accent-color: #0D1A63; }
        .form-control, .form-select {
            width: 100%;
            padding: 10px 13px;
            border: 2px solid #e0e4f0;
            border-radius: 8px;
            font-size: 14px;
        }
        textarea.form-control { min-height: 80px; resize: vertical; }

        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            justify-content: flex-end;
        }
        .btn-save {
            background: #0D1A63;
            color: #fff;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-save:hover { background: #1a2a7a; }
        .btn-complete {
            background: #28a745;
            color: #fff;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-complete:hover { background: #218838; }

        .save-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #28a745;
            color: #fff;
            padding: 10px 20px;
            border-radius: 30px;
            font-size: 13px;
            display: none;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,.2);
            z-index: 1000;
        }

        @media (max-width: 768px) {
            .section-tabs { overflow-x: auto; flex-wrap: nowrap; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1><i class="fas fa-clipboard-check"></i> County Integration Assessment</h1>
        <div class="hdr-links">
            <a href="county_integration_assessment_list.php"><i class="fas fa-list"></i> All Assessments</a>
        </div>
    </div>

    <!-- Assessment Setup -->
    <div class="setup-card">
        <div class="setup-grid">
            <div class="setup-field">
                <label>Select County <span class="req">*</span></label>
                <select id="countySelect">
                    <option value="">-- Select County --</option>
                    <?php while ($county = $counties->fetch_assoc()): ?>
                    <option value="<?= $county['county_id'] ?>" data-code="<?= $county['county_code'] ?>" data-region="<?= $county['region'] ?>" <?= $county_id == $county['county_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($county['county_name']) ?> (<?= $county['county_code'] ?>)
                    </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="setup-field">
                <label>Assessment Period <span class="req">*</span></label>
                <select id="periodSelect">
                    <option value="">-- Select Period --</option>
                    <?php foreach ($quarters as $q): ?>
                    <option value="<?= $q ?>" <?= $period == $q ? 'selected' : '' ?>><?= $q ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="setup-field">
                <button class="btn-load" onclick="loadAssessment()">
                    <i class="fas fa-arrow-right"></i> Load / Start Assessment
                </button>
            </div>
        </div>
    </div>

    <!-- Warning Modal -->
    <div id="warningModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: #fff; max-width: 500px; border-radius: 14px; padding: 25px; margin: 20px;">
            <h3 id="modalTitle" style="color: #856404; margin-bottom: 15px;">Warning</h3>
            <p id="modalMessage"></p>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button onclick="closeModalAndRedirect()" class="btn-save" style="background: #0D1A63;">View Assessments</button>
                <button onclick="closeModal()" class="btn-save" style="background: #6c757d;">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Section Tabs -->
    <div class="section-tabs" id="sectionTabs" style="display: none;">
        <?php foreach ($sections as $key => $section): ?>
        <div class="section-tab" data-section="<?= $key ?>" onclick="showSection('<?= $key ?>')">
            <i class="fas <?= $section['icon'] ?>"></i>
            <?= $section['name'] ?>
            <span class="section-status-badge"></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Section Forms Container -->
    <div id="formsContainer" style="display: none;">
        <?php foreach ($sections as $key => $section):
            $saved_data = isset($existing_data[$key]) ? $existing_data[$key]['data'] : [];
            $is_completed = isset($completion_status[$key]) ? $completion_status[$key] : (isset($existing_data[$key]) ? $existing_data[$key]['is_completed'] : 0);
        ?>
        <div class="form-card" id="form_<?= $key ?>" style="display: none;">
            <div class="form-header">
                <h2><i class="fas <?= $section['icon'] ?>"></i> <?= $section['name'] ?></h2>
                <div>
                    <span class="section-status <?= $is_completed ? 'status-completed' : 'status-incomplete' ?>">
                        <i class="fas <?= $is_completed ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                        <?= $is_completed ? 'Completed by ' . ($existing_data[$key]['completed_by'] ?? '') . ' on ' . date('d M Y', strtotime($existing_data[$key]['completed_at'] ?? '')) : 'Not Completed' ?>
                    </span>
                </div>
            </div>
            <div class="form-body">
                <form class="section-form" data-section="<?= $key ?>">
                    <?php foreach ($section['questions'] as $question): ?>
                    <div class="question-group">
                        <label><?= $question['label'] ?></label>
                        <div class="question-hint">
                            <i class="fas fa-info-circle"></i> <?= $question['hint'] ?>
                        </div>
                        <?php if ($question['type'] == 'radio'): ?>
                        <div class="yn-group">
                            <?php foreach ($question['options'] as $option): ?>
                            <label class="yn-opt">
                                <input type="radio" name="<?= $question['id'] ?>" value="<?= $option ?>" <?= (isset($saved_data[$question['id']]) && $saved_data[$question['id']] == $option) ? 'checked' : '' ?>>
                                <?= $option ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php elseif ($question['type'] == 'select'): ?>
                        <select name="<?= $question['id'] ?>" class="form-select">
                            <option value="">Select</option>
                            <?php foreach ($question['options'] as $option): ?>
                            <option value="<?= $option ?>" <?= (isset($saved_data[$question['id']]) && $saved_data[$question['id']] == $option) ? 'selected' : '' ?>><?= $option ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php elseif ($question['type'] == 'textarea'): ?>
                        <textarea name="<?= $question['id'] ?>" class="form-control" rows="3"><?= isset($saved_data[$question['id']]) ? htmlspecialchars($saved_data[$question['id']]) : '' ?></textarea>
                        <?php else: ?>
                        <input type="<?= $question['type'] ?>" name="<?= $question['id'] ?>" class="form-control" value="<?= isset($saved_data[$question['id']]) ? htmlspecialchars($saved_data[$question['id']]) : '' ?>">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="btn-group">
                        <button type="button" class="btn-save" onclick="saveSection('<?= $key ?>', false)">
                            <i class="fas fa-save"></i> Save Progress
                        </button>
                        <button type="button" class="btn-complete" onclick="saveSection('<?= $key ?>', true)">
                            <i class="fas fa-check-circle"></i> Mark as Complete
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Save Indicator -->
    <div class="save-indicator" id="saveIndicator">
        <i class="fas fa-check-circle"></i> <span id="saveMessage">Section saved!</span>
    </div>
</div>

<script>
let currentCountyId = <?= $county_id ?: 0 ?>;
let currentPeriod = '<?= htmlspecialchars($period) ?>';
let currentAssessmentId = <?= $current_assessment_id ?: 0 ?>;
let isFullyCompleted = <?= isset($is_fully_completed) && $is_fully_completed ? 'true' : 'false' ?>;

function loadAssessment() {
    const countyId = document.getElementById('countySelect').value;
    const period = document.getElementById('periodSelect').value;

    if (!countyId || !period) {
        alert('Please select both County and Assessment Period');
        return;
    }

    // Check if assessment exists
    fetch(`check_county_assessment.php?county_id=${countyId}&period=${encodeURIComponent(period)}`)
        .then(response => response.json())
        .then(data => {
            if (data.exists) {
                if (data.is_completed) {
                    showModal('Assessment Already Completed',
                        `An assessment for ${data.county_name} for period ${period} has already been completed on ${data.completed_at} by ${data.completed_by}. You cannot modify a completed assessment.`, true);
                } else {
                    showModal('Incomplete Assessment Found',
                        `An incomplete assessment for ${data.county_name} for period ${period} already exists. You will be redirected to continue where you left off.`, false);
                    setTimeout(() => {
                        window.location.href = `county_integration_assessment.php?county_id=${countyId}&period=${encodeURIComponent(period)}`;
                    }, 2000);
                }
            } else {
                // Start new assessment
                window.location.href = `county_integration_assessment.php?county_id=${countyId}&period=${encodeURIComponent(period)}`;
            }
        });
}

function showModal(title, message, isCompleted) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalMessage').innerHTML = message;
    document.getElementById('warningModal').style.display = 'flex';

    if (isCompleted) {
        document.getElementById('modalTitle').style.color = '#721c24';
    } else {
        document.getElementById('modalTitle').style.color = '#856404';
    }
}

function closeModal() {
    document.getElementById('warningModal').style.display = 'none';
}

function closeModalAndRedirect() {
    window.location.href = 'county_integration_assessment_list.php';
}

function showSection(sectionKey) {
    // Hide all forms
    document.querySelectorAll('.form-card').forEach(form => {
        form.style.display = 'none';
    });
    // Show selected form
    document.getElementById('form_' + sectionKey).style.display = 'block';

    // Update active tab
    document.querySelectorAll('.section-tab').forEach(tab => {
        tab.classList.remove('active');
        if (tab.dataset.section === sectionKey) {
            tab.classList.add('active');
        }
    });
}

function saveSection(sectionKey, markCompleted) {
    const form = document.querySelector(`#form_${sectionKey} .section-form`);
    const formData = new FormData(form);
    const sectionData = {};

    formData.forEach((value, key) => {
        sectionData[key] = value;
    });

    const countyId = document.getElementById('countySelect').value;
    const period = document.getElementById('periodSelect').value;

    if (!countyId || !period) {
        alert('Please select County and Period first');
        return;
    }

    fetch('county_integration_assessment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'save_section',
            county_id: countyId,
            period: period,
            section_key: sectionKey,
            section_data: JSON.stringify(sectionData),
            mark_completed: markCompleted ? 1 : 0
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            currentAssessmentId = data.assessment_id;
            showSaveMessage(markCompleted ? 'Section completed and saved!' : 'Section saved!');

            // Update tab status
            const tab = document.querySelector(`.section-tab[data-section="${sectionKey}"]`);
            if (markCompleted) {
                tab.classList.remove('incomplete');
                tab.classList.add('completed');
            } else {
                tab.classList.add('incomplete');
                tab.classList.remove('completed');
            }

            // Update section status badge
            const statusBadge = tab.querySelector('.section-status-badge');
            if (statusBadge) {
                if (markCompleted) {
                    statusBadge.innerHTML = ' ?';
                    statusBadge.style.color = '#28a745';
                }
            }

            // Update form header status
            const formHeader = document.querySelector(`#form_${sectionKey} .section-status`);
            if (formHeader) {
                if (markCompleted) {
                    formHeader.className = 'section-status status-completed';
                    formHeader.innerHTML = '<i class="fas fa-check-circle"></i> Completed';
                }
            }

            if (data.all_completed) {
                alert('Congratulations! All sections have been completed. The assessment is now fully submitted.');
                window.location.href = 'county_integration_assessment_list.php';
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while saving');
    });
}

function showSaveMessage(message) {
    const indicator = document.getElementById('saveIndicator');
    document.getElementById('saveMessage').textContent = message;
    indicator.style.display = 'flex';
    setTimeout(() => {
        indicator.style.display = 'none';
    }, 2000);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    if (currentCountyId && currentPeriod) {
        document.getElementById('sectionTabs').style.display = 'flex';
        document.getElementById('formsContainer').style.display = 'block';

        // Show first incomplete section or first section
        const firstSection = document.querySelector('.section-tab');
        if (firstSection) {
            const sectionKey = firstSection.dataset.section;
            showSection(sectionKey);
        }

        // Update tab statuses
        <?php foreach ($completion_status as $skey => $status): ?>
        const tab_<?= $skey ?> = document.querySelector(`.section-tab[data-section="<?= $skey ?>"]`);
        if (tab_<?= $skey ?>) {
            if (<?= $status ?>) {
                tab_<?= $skey ?>.classList.add('completed');
            } else {
                tab_<?= $skey ?>.classList.add('incomplete');
            }
        }
        <?php endforeach; ?>
    }
});
</script>
</body>
</html>