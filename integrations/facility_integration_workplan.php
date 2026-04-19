<?php
// facility_integration_workplan.php
session_start();

// Fix include paths
$base_path = dirname(__DIR__);
$config_path = $base_path . '/includes/config.php';
$session_check_path = $base_path . '/includes/session_check.php';

if (!file_exists($config_path)) {
    die('Configuration file not found. Please check the path: ' . $config_path);
}

include($config_path);
include($session_check_path);

// Verify database connection
if (!isset($conn) || !$conn) {
    die('Database connection failed. Please check your config.php file.');
}

// Check if dompdf is available for PDF export
$dompdf_available = false;
$dompdf_autoload_paths = [
    $base_path . '/vendor/autoload.php',
    $base_path . '/vendor/dompdf/dompdf/autoload.inc.php',
    $base_path . '/dompdf/autoload.inc.php'
];

foreach ($dompdf_autoload_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $dompdf_available = true;
        break;
    }
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$export_format = isset($_GET['export']) ? $_GET['export'] : '';

if (!$id) {
    header('Location: facility_integration_assessment_list.php');
    exit();
}

// Get main assessment
$query = "SELECT * FROM integration_assessments WHERE assessment_id = $id";
$result = mysqli_query($conn, $query);
if (mysqli_num_rows($result) == 0) {
    header('Location: facility_integration_assessment_list.php');
    exit();
}
$assessment = mysqli_fetch_assoc($result);

// Get EMR systems
$emr_systems = mysqli_query($conn, "SELECT * FROM integration_assessment_emr_systems WHERE assessment_id = $id ORDER BY sort_order");

// Generate the workplan data
$workplan = generateIntegrationWorkplan($assessment, $emr_systems, $conn);
$workplan['detailed_recommendations'] = generateDetailedRecommendations($assessment);

// Handle exports
if ($export_format === 'pdf') {
    if (!$dompdf_available) {
        die('dompdf not found. Please install dompdf using: composer require dompdf/dompdf');
    }
    exportToPDF($workplan, $conn);
    exit();
} elseif ($export_format === 'word') {
    exportToWord($workplan, $conn);
    exit();
}

// Output the workplan as HTML
renderWorkplan($workplan, $assessment, $conn);
exit();

// ==================== FUNCTIONS ====================

function generateIntegrationWorkplan($assessment, $emr_systems, $conn) {
    $facility_name = $assessment['facility_name'];
    $mflcode = $assessment['mflcode'];
    $county = $assessment['county_name'];
    $level = $assessment['level_of_care_name'];

    // Calculate integration readiness score
    $readiness_score = calculateReadinessScore($assessment);

    // Determine integration model based on findings
    $integration_model = determineIntegrationModel($assessment);

    // Generate recommendations
    $recommendations = generateIntegrationRecommendations($assessment);

    // Identify gaps
    $gaps = identifyIntegrationGaps($assessment);

    // Create phased transition timeline
    $timeline = createPhasedTimeline($readiness_score);

    // Determine EMR status
    $emr_status = analyzeEMRStatus($assessment, $emr_systems);

    $workplan = [
        'assessment_id' => $assessment['assessment_id'],
        'facility_name' => $facility_name,
        'mflcode' => $mflcode,
        'county' => $county,
        'level_of_care' => $level,
        'assessment_period' => $assessment['assessment_period'],
        'collected_by' => $assessment['collected_by'],
        'collection_date' => $assessment['collection_date'],
        'readiness_score' => $readiness_score['score'],
        'readiness_level' => $readiness_score['level'],
        'readiness_color' => $readiness_score['color'],
        'integration_model' => $integration_model,
        'recommendations' => $recommendations,
        'gaps' => $gaps,
        'timeline' => $timeline,
        'emr_status' => $emr_status,
        'key_metrics' => [
            'tx_curr' => $assessment['tx_curr'] ?? 0,
            'plhiv_integrated' => $assessment['plhiv_integrated_care'] ?? 0,
            'plhiv_sha' => $assessment['plhiv_enrolled_sha'] ?? 0,
            'hcw_pepfar' => $assessment['hcw_total_pepfar'] ?? 0,
            'hcw_transitioned' => ($assessment['hcw_transitioned_clinical'] ?? 0) +
                                 ($assessment['hcw_transitioned_nonclinical'] ?? 0) +
                                 ($assessment['hcw_transitioned_data'] ?? 0) +
                                 ($assessment['hcw_transitioned_community'] ?? 0) +
                                 ($assessment['hcw_transitioned_other'] ?? 0),
            'ta_visits_total' => $assessment['ta_visits_total'] ?? 0,
            'ta_visits_moh' => $assessment['ta_visits_moh_only'] ?? 0,
            'deaths_hiv' => $assessment['deaths_hiv_related'] ?? 0,
            'deaths_tb' => $assessment['deaths_tb'] ?? 0
        ],
        'integration_status' => [
            'hiv_tb_integrated' => $assessment['hiv_tb_integrated'] ?? 'No',
            'integration_model' => $assessment['hiv_tb_integration_model'] ?? '',
            'pmtct_integrated' => $assessment['pmtct_integrated_mnch'] ?? 'No',
            'hts_opd' => $assessment['hts_integrated_opd'] ?? 'No',
            'hts_ipd' => $assessment['hts_integrated_ipd'] ?? 'No',
            'hts_mnch' => $assessment['hts_integrated_mnch'] ?? 'No',
            'prep_opd' => $assessment['prep_integrated_opd'] ?? 'No',
            'prep_ipd' => $assessment['prep_integrated_ipd'] ?? 'No',
            'prep_mnch' => $assessment['prep_integrated_mnch'] ?? 'No'
        ],
        'financial_status' => [
            'fif_collection' => $assessment['fif_collection_in_place'] ?? 'No',
            'fif_includes_hiv' => $assessment['fif_includes_hiv_tb_pmtct'] ?? 'No',
            'sha_capitation' => $assessment['sha_capitation_hiv_tb'] ?? 'No',
            'sha_claims_ontime' => $assessment['sha_claims_submitted_ontime'] ?? 'No',
            'sha_reimbursements' => $assessment['sha_reimbursements_monthly'] ?? 'No'
        ],
        'hrh_status' => [
            'leadership_commitment' => $assessment['leadership_commitment'] ?? 'Not Assessed',
            'transition_plan' => $assessment['transition_plan'] ?? 'Not Assessed',
            'hiv_in_awp' => $assessment['hiv_in_awp'] ?? 'Not Assessed',
            'hrh_gap' => $assessment['hrh_gap'] ?? 'Not Assessed',
            'staff_multiskilled' => $assessment['staff_multiskilled'] ?? 'Not Assessed',
            'roving_staff' => $assessment['roving_staff'] ?? 'Not Assessed',
            'infrastructure_capacity' => $assessment['infrastructure_capacity'] ?? 'Not Assessed',
            'space_adequacy' => $assessment['space_adequacy'] ?? 'Not Assessed',
            'service_without_ccc' => $assessment['service_delivery_without_ccc'] ?? 'Not Assessed',
            'data_integration' => $assessment['data_integration_level'] ?? 'Not Assessed',
            'financing_coverage' => $assessment['financing_coverage'] ?? 'Not Assessed',
            'disruption_risk' => $assessment['disruption_risk'] ?? 'Not Assessed'
        ],
        'barriers' => $assessment['integration_barriers'] ?? ''
    ];

    return $workplan;
}

function calculateReadinessScore($assessment) {
    $scores = [];

    // Section 1: Service Integration (30%)
    $integration_indicators = [
        'hiv_tb_integrated' => 10,
        'pmtct_integrated_mnch' => 5,
        'hts_integrated_opd' => 5,
        'hts_integrated_ipd' => 5,
        'hts_integrated_mnch' => 5,
        'prep_integrated_opd' => 5,
        'prep_integrated_ipd' => 5,
        'prep_integrated_mnch' => 5
    ];

    $service_score = 0;
    $service_max = 45;
    foreach ($integration_indicators as $indicator => $weight) {
        if (isset($assessment[$indicator]) && $assessment[$indicator] == 'Yes') {
            $service_score += $weight;
        }
    }
    $scores['service'] = ($service_score / $service_max) * 30;

    // Section 2: EMR Integration (20%)
    $emr_score = 0;
    if (isset($assessment['uses_emr']) && $assessment['uses_emr'] == 'Yes') {
        $emr_score += 10;
        $emr_depts = ['opd', 'ipd', 'mnch', 'ccc', 'pmtct', 'lab', 'pharmacy'];
        $dept_count = 0;
        foreach ($emr_depts as $dept) {
            $field = 'emr_at_' . $dept;
            if (isset($assessment[$field]) && $assessment[$field] == 'Yes') {
                $dept_count++;
            }
        }
        $emr_score += ($dept_count / 7) * 10;
    }
    $scores['emr'] = $emr_score;

    // Section 3: HRH Readiness (20%)
    $hrh_score = 0;
    $hrh_indicators = [
        'leadership_commitment' => ['High' => 5, 'Moderate' => 3, 'Low' => 1],
        'transition_plan' => ['Yes - Implemented' => 5, 'Yes - Not Implemented' => 3, 'No' => 1],
        'hiv_in_awp' => ['Fully' => 5, 'Partially' => 3, 'No' => 1],
        'staff_multiskilled' => ['Yes' => 5, 'Partial' => 3, 'No' => 1],
        'roving_staff' => ['Yes - Regular' => 5, 'Yes - Irregular' => 3, 'No' => 1]
    ];
    foreach ($hrh_indicators as $indicator => $values) {
        if (isset($assessment[$indicator]) && isset($values[$assessment[$indicator]])) {
            $hrh_score += $values[$assessment[$indicator]];
        }
    }
    $scores['hrh'] = ($hrh_score / 25) * 20;

    // Section 4: Financial Sustainability (15%)
    $finance_score = 0;
    $finance_indicators = [
        'fif_collection_in_place' => 4,
        'fif_includes_hiv_tb_pmtct' => 4,
        'sha_capitation_hiv_tb' => 4,
        'sha_claims_submitted_ontime' => 3
    ];
    foreach ($finance_indicators as $indicator => $weight) {
        if (isset($assessment[$indicator]) && $assessment[$indicator] == 'Yes') {
            $finance_score += $weight;
        }
    }
    $scores['finance'] = ($finance_score / 15) * 15;

    // Section 5: Infrastructure & Space (15%)
    $infra_score = 0;
    if (isset($assessment['infrastructure_capacity'])) {
        $infra_values = ['Adequate' => 8, 'Minor changes needed' => 5, 'Major redesign needed' => 2];
        $infra_score += $infra_values[$assessment['infrastructure_capacity']] ?? 0;
    }
    if (isset($assessment['space_adequacy'])) {
        $space_values = ['Adequate' => 7, 'Congested' => 4, 'Severely Inadequate' => 1];
        $infra_score += $space_values[$assessment['space_adequacy']] ?? 0;
    }
    $scores['infra'] = ($infra_score / 15) * 15;

    $total_score = $scores['service'] + $scores['emr'] + $scores['hrh'] + $scores['finance'] + $scores['infra'];

    if ($total_score >= 80) {
        $level = 'Fully Ready';
        $color = 'success';
    } elseif ($total_score >= 60) {
        $level = 'Moderately Ready';
        $color = 'warning';
    } elseif ($total_score >= 40) {
        $level = 'Low Readiness';
        $color = 'orange';
    } else {
        $level = 'Not Ready';
        $color = 'danger';
    }

    return ['score' => round($total_score, 1), 'level' => $level, 'color' => $color];
}

function determineIntegrationModel($assessment) {
    $hiv_tb_integrated = $assessment['hiv_tb_integrated'] ?? 'No';
    $current_model = $assessment['hiv_tb_integration_model'] ?? '';
    $has_ccc = ($assessment['emr_at_ccc'] ?? '') == 'Yes';
    $hts_opd = ($assessment['hts_integrated_opd'] ?? '') == 'Yes';
    $prep_opd = ($assessment['prep_integrated_opd'] ?? '') == 'Yes';

    $models = [];

    // Determine based on current status
    if ($hiv_tb_integrated == 'Yes') {
        if (strpos(strtolower($current_model), 'one-stop') !== false) {
            $models[] = ['name' => 'One-Stop Shop Model', 'description' => 'All HIV/TB services provided in a single location, integrated with general OPD services.', 'suitability' => 'High'];
        } elseif (strpos(strtolower($current_model), 'chronic care') !== false) {
            $models[] = ['name' => 'Chronic Care Model', 'description' => 'HIV/TB services integrated into chronic disease management clinics (NCDs, hypertension, diabetes).', 'suitability' => 'High'];
        } else {
            $models[] = ['name' => 'Differentiated Service Delivery (DSD)', 'description' => 'Risk-stratified approach where stable patients receive less frequent visits and multi-month dispensing.', 'suitability' => 'High'];
        }
    } else {
        // Recommend models based on facility profile
        if ($has_ccc) {
            $models[] = ['name' => 'Chronic Care Integration', 'description' => 'Integrate HIV/TB services into existing chronic disease clinics to reduce stigma and improve efficiency.', 'suitability' => 'Recommended'];
        }
        if ($hts_opd) {
            $models[] = ['name' => 'OPD-Based Integration', 'description' => 'Embed HTS and PrEP services within general OPD for routine screening of all patients.', 'suitability' => 'Recommended'];
        }
        $models[] = ['name' => 'Phased Integration Approach', 'description' => 'Gradually transition services: start with HTS/PrEP integration, then ART/PMTCT, then full integration.', 'suitability' => 'Alternative'];
    }

    return $models;
}

function generateIntegrationRecommendations($assessment) {
    $recommendations = [];

    // Service Integration Recommendations
    if (($assessment['hiv_tb_integrated'] ?? '') != 'Yes') {
        $recommendations[] = [
            'category' => 'Service Integration',
            'priority' => 'Critical',
            'title' => 'Integrate HIV/TB Services into General OPD',
            'description' => 'The facility currently operates vertical HIV/TB services. Integration into OPD will improve patient flow, reduce waiting times, and reduce stigma.',
            'actions' => [
                'Establish a joint HIV/TB and OPD clinic schedule',
                'Train OPD staff on basic HIV/TB management',
                'Develop patient flow maps to streamline services',
                'Create a shared registration system for all services'
            ],
            'timeline' => '1-3 months',
            'responsible' => 'Facility In-Charge, OPD Head, HIV Coordinator'
        ];
    }

    // PMTCT Integration
    if (($assessment['pmtct_integrated_mnch'] ?? '') != 'Yes') {
        $recommendations[] = [
            'category' => 'PMTCT Integration',
            'priority' => 'High',
            'title' => 'Integrate PMTCT Services into MNCH',
            'description' => 'PMTCT services should be fully integrated into MNCH to ensure all pregnant women receive HIV testing and treatment during antenatal care.',
            'actions' => [
                'Train MNCH staff on PMTCT guidelines',
                'Establish routine HIV testing for all ANC clients',
                'Integrate ART initiation into ANC services',
                'Ensure EID sample collection during routine visits'
            ],
            'timeline' => '2-4 months',
            'responsible' => 'MNCH In-Charge, PMTCT Coordinator'
        ];
    }

    // HTS Integration
    if (($assessment['hts_integrated_opd'] ?? '') != 'Yes') {
        $recommendations[] = [
            'category' => 'HTS Integration',
            'priority' => 'High',
            'title' => 'Expand HTS to OPD, IPD and MNCH',
            'description' => 'HIV testing should be offered to all patients presenting at OPD, IPD and MNCH to improve early case identification.',
            'actions' => [
                'Implement routine opt-out HIV testing in OPD',
                'Train clinical staff on provider-initiated testing and counseling (PITC)',
                'Establish rapid testing capacity in all departments',
                'Create referral pathways for positive clients to ART services'
            ],
            'timeline' => '1-3 months',
            'responsible' => 'Clinical Services Head, HTS Coordinator'
        ];
    }

    // PrEP Integration
    if (($assessment['prep_integrated_opd'] ?? '') != 'Yes') {
        $recommendations[] = [
            'category' => 'PrEP Integration',
            'priority' => 'Medium',
            'title' => 'Integrate PrEP Services into OPD/IPD/MNCH',
            'description' => 'PrEP should be available to all at-risk individuals presenting to OPD, IPD and MNCH.',
            'actions' => [
                'Train OPD staff on PrEP eligibility screening',
                'Establish PrEP initiation protocols',
                'Integrate PrEP dispensing into pharmacy services',
                'Develop referral systems for PrEP continuation'
            ],
            'timeline' => '3-6 months',
            'responsible' => 'Clinical Services, Pharmacy In-Charge'
        ];
    }

    // EMR Recommendations
    if (($assessment['uses_emr'] ?? '') != 'Yes') {
        $reasons = explode(',', $assessment['no_emr_reasons'] ?? '');
        $recommendations[] = [
            'category' => 'EMR Systems',
            'priority' => 'Critical',
            'title' => 'Implement Integrated EMR System',
            'description' => 'The facility lacks an EMR system. An integrated EMR is essential for seamless data sharing between departments and tracking patients across services.',
            'actions' => array_merge([
                'Conduct EMR needs assessment',
                'Identify suitable EMR system (KenyaEMR recommended)',
                'Procure necessary hardware and network infrastructure',
                'Train staff on EMR use'
            ], array_map(function($reason) {
                if (strpos($reason, 'hardware') !== false) return 'Procure hardware including computers, printers and UPS';
                if (strpos($reason, 'internet') !== false) return 'Install reliable internet connectivity';
                if (strpos($reason, 'electricity') !== false) return 'Install solar backup power system';
                if (strpos($reason, 'trained') !== false) return 'Hire and train IT support staff';
                return '';
            }, $reasons), []),
            'timeline' => '6-12 months',
            'responsible' => 'Health Records Officer, IT Department'
        ];
    } elseif (($assessment['emr_interoperable_his'] ?? '') != 'Yes') {
        $recommendations[] = [
            'category' => 'EMR Systems',
            'priority' => 'High',
            'title' => 'Ensure EMR Interoperability',
            'description' => 'Current EMR is not interoperable with other HIS systems, leading to data silos and duplication of effort.',
            'actions' => [
                'Assess current EMR system capabilities',
                'Develop integration interfaces with DHIS2, DATIM',
                'Implement HL7/FHIR standards for data exchange',
                'Test and validate data sharing across systems'
            ],
            'timeline' => '4-8 months',
            'responsible' => 'Health Records Officer, IT Support'
        ];
    }

    // HRH Recommendations
    $leadership = $assessment['leadership_commitment'] ?? '';
    if ($leadership == 'Low' || $leadership == 'Moderate') {
        $recommendations[] = [
            'category' => 'Leadership & Governance',
            'priority' => 'Critical',
            'title' => 'Strengthen Leadership Commitment to Integration',
            'description' => 'Leadership commitment to integration is currently ' . strtolower($leadership) . '. Strong leadership is essential for successful transition.',
            'actions' => [
                'Conduct leadership sensitization workshop on integration benefits',
                'Establish integration steering committee with clear terms of reference',
                'Set measurable integration targets in facility work plans',
                'Regularly review integration progress in management meetings'
            ],
            'timeline' => '1-2 months',
            'responsible' => 'Medical Superintendent, Facility Management'
        ];
    }

    $transition_plan = $assessment['transition_plan'] ?? '';
    if ($transition_plan != 'Yes - Implemented') {
        $recommendations[] = [
            'category' => 'Transition Planning',
            'priority' => 'High',
            'title' => 'Develop and Implement Integration Transition Plan',
            'description' => 'A formal transition plan is needed to guide the integration process with clear milestones and responsibilities.',
            'actions' => [
                'Develop comprehensive integration transition plan',
                'Include specific timelines, budgets, and responsible persons',
                'Align plan with county health department priorities',
                'Regularly review and update plan progress'
            ],
            'timeline' => '2-4 months',
            'responsible' => 'Facility Management, County Health Team'
        ];
    }

    // Staff Training
    $multiskilled = $assessment['staff_multiskilled'] ?? '';
    if ($multiskilled != 'Yes') {
        $recommendations[] = [
            'category' => 'Staff Training',
            'priority' => 'High',
            'title' => 'Build Multi-Skilled Workforce',
            'description' => 'Staff currently lack multi-skilling, limiting their ability to deliver integrated services across departments.',
            'actions' => [
                'Conduct training needs assessment',
                'Develop cross-training program for clinical staff',
                'Train OPD staff on HIV/TB management',
                'Train HIV staff on NCD management',
                'Implement job rotation programs'
            ],
            'timeline' => '3-9 months',
            'responsible' => 'HR Department, Clinical Services'
        ];
    }

    // Infrastructure
    $infrastructure = $assessment['infrastructure_capacity'] ?? '';
    $space = $assessment['space_adequacy'] ?? '';
    if ($infrastructure == 'Major redesign needed' || $space == 'Severely Inadequate') {
        $recommendations[] = [
            'category' => 'Infrastructure',
            'priority' => 'Critical',
            'title' => 'Upgrade Infrastructure to Support Integrated Services',
            'description' => 'Current infrastructure and space are inadequate for integrated service delivery.',
            'actions' => [
                'Conduct facility space assessment',
                'Develop infrastructure upgrade plan',
                'Advocate for county government funding for renovations',
                'Reorganize existing space to optimize patient flow',
                'Consider temporary solutions like mobile clinics during transition'
            ],
            'timeline' => '6-12 months',
            'responsible' => 'Facility Management, County Works Department'
        ];
    }

    // Financial Sustainability
    $fif = $assessment['fif_collection_in_place'] ?? '';
    if ($fif != 'Yes') {
        $recommendations[] = [
            'category' => 'Financial Sustainability',
            'priority' => 'High',
            'title' => 'Establish FIF Collection Mechanism',
            'description' => 'The facility lacks a FIF collection mechanism, limiting its ability to generate local revenue for service delivery.',
            'actions' => [
                'Establish or strengthen FIF collection system',
                'Ensure FIF includes HIV/TB/PMTCT services',
                'Train staff on proper FIF documentation',
                'Regularly audit FIF utilization'
            ],
            'timeline' => '2-4 months',
            'responsible' => 'Finance Department, Facility Management'
        ];
    }

    $sha_capitation = $assessment['sha_capitation_hiv_tb'] ?? '';
    if ($sha_capitation != 'Yes') {
        $recommendations[] = [
            'category' => 'SHA Enrollment',
            'priority' => 'Medium',
            'title' => 'Enroll PLHIVs into SHA and Maximize Capitation',
            'description' => 'The facility is not receiving SHA capitation for HIV/TB services, representing lost revenue.',
            'actions' => [
                'Establish SHA enrollment desk for PLHIVs',
                'Train staff on SHA claims submission',
                'Submit claims consistently and on time',
                'Advocate for inclusion of HIV/TB in SHA capitation package'
            ],
            'timeline' => '3-6 months',
            'responsible' => 'Finance, Health Records, Social Work'
        ];
    }

    // Data Integration
    $data_integration = $assessment['data_integration_level'] ?? '';
    if ($data_integration != 'Fully Integrated') {
        $recommendations[] = [
            'category' => 'Data Management',
            'priority' => 'High',
            'title' => 'Improve Data Integration',
            'description' => 'Data systems are ' . strtolower($data_integration) . ', limiting ability to track integrated services.',
            'actions' => [
                'Standardize data collection tools across departments',
                'Train staff on integrated reporting requirements',
                'Establish monthly data review meetings',
                'Implement data quality assurance protocols',
                'Link HIV/TB data with routine health information systems'
            ],
            'timeline' => '3-6 months',
            'responsible' => 'Health Records Officer, M&E Focal Person'
        ];
    }

    // Sort recommendations by priority
    $priority_order = ['Critical' => 1, 'High' => 2, 'Medium' => 3, 'Low' => 4];
    usort($recommendations, function($a, $b) use ($priority_order) {
        return $priority_order[$a['priority']] <=> $priority_order[$b['priority']];
    });

    return $recommendations;
}

function identifyIntegrationGaps($assessment) {
    $gaps = [];

    $gap_indicators = [
        ['Service Integration', 'HIV/TB Services', $assessment['hiv_tb_integrated'] ?? 'No', 'Yes'],
        ['Service Integration', 'PMTCT in MNCH', $assessment['pmtct_integrated_mnch'] ?? 'No', 'Yes'],
        ['Service Integration', 'HTS in OPD', $assessment['hts_integrated_opd'] ?? 'No', 'Yes'],
        ['Service Integration', 'HTS in IPD', $assessment['hts_integrated_ipd'] ?? 'No', 'Yes'],
        ['Service Integration', 'HTS in MNCH', $assessment['hts_integrated_mnch'] ?? 'No', 'Yes'],
        ['Service Integration', 'PrEP in OPD', $assessment['prep_integrated_opd'] ?? 'No', 'Yes'],
        ['Service Integration', 'PrEP in IPD', $assessment['prep_integrated_ipd'] ?? 'No', 'Yes'],
        ['Service Integration', 'PrEP in MNCH', $assessment['prep_integrated_mnch'] ?? 'No', 'Yes'],
        ['EMR', 'Uses EMR', $assessment['uses_emr'] ?? 'No', 'Yes'],
        ['EMR', 'Interoperable EMR', $assessment['emr_interoperable_his'] ?? 'No', 'Yes'],
        ['HRH', 'Leadership Commitment', $assessment['leadership_commitment'] ?? '', 'High'],
        ['HRH', 'Transition Plan', $assessment['transition_plan'] ?? '', 'Yes - Implemented'],
        ['HRH', 'HIV in AWP', $assessment['hiv_in_awp'] ?? '', 'Fully'],
        ['HRH', 'Multi-skilled Staff', $assessment['staff_multiskilled'] ?? '', 'Yes'],
        ['Financial', 'FIF Collection', $assessment['fif_collection_in_place'] ?? '', 'Yes'],
        ['Financial', 'SHA Capitation', $assessment['sha_capitation_hiv_tb'] ?? '', 'Yes'],
        ['Infrastructure', 'Infrastructure Capacity', $assessment['infrastructure_capacity'] ?? '', 'Adequate'],
        ['Infrastructure', 'Space Adequacy', $assessment['space_adequacy'] ?? '', 'Adequate'],
        ['Data', 'Data Integration Level', $assessment['data_integration_level'] ?? '', 'Fully Integrated']
    ];

    foreach ($gap_indicators as $indicator) {
        list($category, $indicator_name, $current, $target) = $indicator;
        if ($current != $target) {
            $gaps[] = [
                'category' => $category,
                'indicator' => $indicator_name,
                'current' => $current,
                'target' => $target,
                'severity' => $current == 'No' || $current == 'Low' || $current == 'Not Assessed' ? 'High' : 'Medium'
            ];
        }
    }

    return $gaps;
}

function createPhasedTimeline($readiness_score) {
    $score = $readiness_score['score'];
    $level = $readiness_score['level'];

    if ($score >= 80) {
        $months = 6;
        $phases = [
            ['Phase 1: Final Preparation', 1, 2, 'Complete remaining integration activities, final staff training, and establish monitoring systems.'],
            ['Phase 2: Full Integration Launch', 3, 4, 'Launch fully integrated services, transition all IP-supported activities, and begin county-led operations.'],
            ['Phase 3: Monitoring & Optimization', 5, 6, 'Monitor integrated service performance, address gaps, and optimize processes.']
        ];
    } elseif ($score >= 60) {
        $months = 9;
        $phases = [
            ['Phase 1: Foundation Building', 1, 3, 'Establish integration steering committee, develop transition plan, conduct baseline assessment, and train key staff.'],
            ['Phase 2: Service Integration', 4, 6, 'Pilot integration in OPD, expand to other departments, implement EMR integration, and begin FIF collection.'],
            ['Phase 3: Full Transition', 7, 9, 'Complete integration across all departments, transition IP-supported staff, and establish sustainability mechanisms.']
        ];
    } elseif ($score >= 40) {
        $months = 12;
        $phases = [
            ['Phase 1: Readiness Assessment', 1, 3, 'Conduct comprehensive gap analysis, develop detailed workplan, address critical infrastructure gaps, and engage county leadership.'],
            ['Phase 2: Capacity Building', 4, 7, 'Train all staff on integrated service delivery, implement EMR system, establish FIF collection, and strengthen leadership commitment.'],
            ['Phase 3: Phased Integration', 8, 10, 'Integrate HTS/PrEP services first, then ART/PMTCT, finally full service integration with ongoing monitoring.'],
            ['Phase 4: Transition & Handover', 11, 12, 'Transition IP-supported activities, establish county financing, and implement post-handover support.']
        ];
    } else {
        $months = 18;
        $phases = [
            ['Phase 1: Infrastructure & Governance', 1, 4, 'Address critical infrastructure gaps, establish governance structures, and secure county government commitment.'],
            ['Phase 2: Systems Strengthening', 5, 9, 'Implement EMR system, train staff, develop policies, and strengthen HRH capacity.'],
            ['Phase 3: Service Integration', 10, 14, 'Phased integration starting with HTS/PrEP, then ART/PMTCT, then full integration with continuous quality improvement.'],
            ['Phase 4: Sustainability & Handover', 15, 18, 'Ensure financial sustainability, transition to county-led services, and establish monitoring mechanisms.']
        ];
    }

    return [
        'total_months' => $months,
        'start_date' => date('F Y'),
        'end_date' => date('F Y', strtotime("+$months months")),
        'phases' => $phases
    ];
}

function analyzeEMRStatus($assessment, $emr_systems) {
    $uses_emr = $assessment['uses_emr'] ?? 'No';
    $emr_list = [];
    if ($emr_systems && mysqli_num_rows($emr_systems) > 0) {
        while ($emr = mysqli_fetch_assoc($emr_systems)) {
            $emr_list[] = $emr['emr_type'];
        }
    }

    $departments = [];
    $dept_fields = ['opd', 'ipd', 'mnch', 'ccc', 'pmtct', 'lab', 'pharmacy'];
    foreach ($dept_fields as $dept) {
        $field = 'emr_at_' . $dept;
        if (isset($assessment[$field]) && $assessment[$field] == 'Yes') {
            $departments[] = strtoupper($dept);
        }
    }

    return [
        'uses_emr' => $uses_emr,
        'emr_systems' => $emr_list,
        'departments_covered' => $departments,
        'interoperable' => $assessment['emr_interoperable_his'] ?? 'No',
        'single_unified' => $assessment['single_unified_emr'] ?? 'No',
        'lab_manifest' => $assessment['lab_manifest_in_use'] ?? 'No',
        'pharmacy_webadt' => $assessment['pharmacy_webadt_in_use'] ?? 'No'
    ];
}

/**
 * Generate detailed recommendations for each specific question
 * This provides granular, actionable guidance based on assessment responses
 */
function generateDetailedRecommendations($assessment) {
    $recommendations = [];

    // ==================== SECTION 8b: LAB SUPPORT (Q72-Q92) ====================

    // Q72: Specimen Referral System
    if (($assessment['lab_specimen_referral'] ?? '') == 'Yes') {
        $recommendations[] = [
            'question' => 'Q72',
            'category' => 'Lab Support',
            'response' => 'Yes',
            'recommendation' => '? Specimen referral system in place - this will not interrupt integration. Continue technical follow-up for 3 months to ensure smooth transition.',
            'action_items' => [
                'Conduct quarterly system review meetings',
                'Monitor turnaround times for specimen results',
                'Document any bottlenecks in the referral chain'
            ],
            'timeline' => '3 months follow-up',
            'priority' => 'Medium'
        ];
    } else {
        $recommendations[] = [
            'question' => 'Q72',
            'category' => 'Lab Support',
            'response' => 'No/Missing',
            'recommendation' => '? CRITICAL: No integrated specimen referral system. Implement immediately to ensure lab services continuity during transition.',
            'action_items' => [
                'Establish specimen referral protocols with nearby facilities',
                'Train staff on proper specimen handling and transport',
                'Set up tracking system for referred specimens',
                'Engage county health team for support'
            ],
            'timeline' => '1-2 months',
            'priority' => 'Critical'
        ];
    }

    // Q74: ISO 15189 Accreditation
    $accreditation = $assessment['lab_iso15189_accredited'] ?? '';
    if ($accreditation == 'Yes') {
        $recommendations[] = [
            'question' => 'Q74',
            'category' => 'Lab Support',
            'response' => 'Yes',
            'recommendation' => '? Laboratory is ISO 15189 accredited. Maintain accreditation through annual renewals and continuous improvement.',
            'action_items' => [
                'Plan for annual license renewals (budget allocation)',
                'Maintain documentation including bin cards and logs',
                'Prepare for surveillance audits',
                'Document staff training records'
            ],
            'timeline' => 'Ongoing',
            'priority' => 'Medium'
        ];
    } else {
        $recommendations[] = [
            'question' => 'Q74',
            'category' => 'Lab Support',
            'response' => 'No',
            'recommendation' => '? Facility lacks ISO 15189 accreditation. Significant investment needed from county/facility for yearly licenses and renewal practices.',
            'action_items' => [
                'IP to support documentation and material availability (bin cards, SOPs)',
                'Conduct gap analysis for accreditation requirements',
                'If county-funded: support logistics for renewal through accreditation bodies',
                'Develop timeline for accreditation preparation (6-12 months)'
            ],
            'timeline' => '6-12 months',
            'priority' => 'High'
        ];
    }

    // Q76: LCQI Implementing
    $lcqi = $assessment['lab_lcqi_implementing'] ?? '';
    if ($lcqi == 'Yes') {
        $recommendations[] = [
            'question' => 'Q76',
            'category' => 'Lab Support',
            'response' => 'Yes',
            'recommendation' => '? Laboratory Quality Continuous Improvement (LCQI) in place. Continue technical support with trainings and mentorship for 3 months then exit as IP.',
            'action_items' => [
                'Conduct refresher training on LCQI principles',
                'Support monthly quality indicator reviews',
                'Document quality improvement projects',
                'Plan transition of LCQI oversight to facility team by month 3'
            ],
            'timeline' => '3 months (IP support then transition)',
            'priority' => 'Medium'
        ];
    } else {
        $recommendations[] = [
            'question' => 'Q76',
            'category' => 'Lab Support',
            'response' => 'No',
            'recommendation' => '? LCQI not implemented. Quality systems need strengthening before transition.',
            'action_items' => [
                'Establish LCQI committee',
                'Train staff on quality improvement methodologies',
                'Implement monthly quality indicator tracking',
                'Develop quality improvement projects for identified gaps'
            ],
            'timeline' => '3-6 months',
            'priority' => 'High'
        ];
    }

    // Q77: LCQI Internal Audits
    $internal_audits = $assessment['lab_lcqi_internal_audits'] ?? '';
    if ($internal_audits == 'Yes') {
        $recommendations[] = [
            'question' => 'Q77',
            'category' => 'Lab Support',
            'response' => 'Yes',
            'recommendation' => '? Regular internal audits conducted using LCQI checklist. Risks are minimal. Continue support for 3-6 months to build lab manager capacity on CAPA.',
            'action_items' => [
                'Strengthen Corrective and Preventive Action (CAPA) documentation',
                'Conduct joint audits with IP mentors',
                'Review audit findings and closure rates',
                'Train lab managers on root cause analysis'
            ],
            'timeline' => '3-6 months capacity building',
            'priority' => 'Low'
        ];
    } else {
        $recommendations[] = [
            'question' => 'Q77',
            'category' => 'Lab Support',
            'response' => 'No',
            'recommendation' => '? Internal audits not conducted regularly. Quality assurance systems need strengthening.',
            'action_items' => [
                'Establish internal audit schedule (quarterly minimum)',
                'Train internal auditors',
                'Develop audit checklists based on LCQI framework',
                'Implement CAPA tracking system'
            ],
            'timeline' => '2-4 months',
            'priority' => 'High'
        ];
    }

    // Q79: SLA Support
    $sla_support = $assessment['lab_sla_support'] ?? '';
    if ($sla_support != 'County') {
        $recommendations[] = [
            'question' => 'Q79',
            'category' => 'Lab Support',
            'response' => $sla_support ?: 'Not in place',
            'recommendation' => '? Facility should contact County for SLA support. IP exit without proper SLAs will disrupt lab services.',
            'action_items' => [
                'Engage County health department for SLA establishment',
                'Document equipment maintenance requirements',
                'Identify alternative service providers',
                'Ensure SLAs cover critical equipment (GeneXpert, CD4, etc.)'
            ],
            'timeline' => '1-3 months',
            'priority' => 'Critical'
        ];
    }

    // Q81: LIMS in Place
    $lims = $assessment['lab_lims_in_place'] ?? '';
    if ($lims == 'Yes') {
        $recommendations[] = [
            'question' => 'Q81',
            'category' => 'Lab Support',
            'response' => 'Yes',
            'recommendation' => '? LIMS in place. Strengthen utilization and review SOPs and policies.',
            'action_items' => [
                'Conduct LIMS utilization assessment',
                'Review and update LIMS-related SOPs',
                'Train all lab staff on LIMS features',
                'Monitor data completeness and accuracy'
            ],
            'timeline' => '1-3 months',
            'priority' => 'Medium'
        ];
    } else {
        $recommendations[] = [
            'question' => 'Q81',
            'category' => 'Lab Support',
            'response' => 'No',
            'recommendation' => '? No LIMS in place. Digital systems needed for data integration.',
            'action_items' => [
                'Conduct LIMS needs assessment',
                'Identify appropriate LIMS solution',
                'Plan implementation with IT support',
                'Budget for hardware and software'
            ],
            'timeline' => '6-12 months',
            'priority' => 'High'
        ];
    }

    // Q82-Q84: LIMS Integration & Interoperability
    $lims_emr = $assessment['lab_lims_emr_integrated'] ?? '';
    if ($lims_emr != 'Yes') {
        $recommendations[] = [
            'question' => 'Q82-Q84',
            'category' => 'Lab Support',
            'response' => 'Not integrated',
            'recommendation' => '? LIMS not integrated with EMR. Support interoperability from vendors or integrate with facility-wide EMR.',
            'action_items' => [
                'Engage EMR and LIMS vendors for integration',
                'Develop integration specifications (HL7/FHIR)',
                'Test interoperability before full rollout',
                'Train staff on integrated workflows'
            ],
            'timeline' => '4-8 months',
            'priority' => 'High'
        ];
    }

    // Q85: Dedicated HIS Staff
    $his_staff = $assessment['lab_dedicated_his_staff'] ?? '';
    if ($his_staff != 'Yes') {
        $recommendations[] = [
            'question' => 'Q85',
            'category' => 'Lab Support',
            'response' => 'No dedicated staff',
            'recommendation' => '? No dedicated HIS technical staff. IP to support trainings and mentorship for 3 months, or County train more staff to prevent gaps during reshuffles.',
            'action_items' => [
                'Identify and train HIS focal persons (minimum 2)',
                'Develop training plan for County health IT staff',
                'Create knowledge transfer documentation',
                'Establish helpdesk support system'
            ],
            'timeline' => '3 months IP support',
            'priority' => 'High'
        ];
    }

    // Q88: Biosafety Training
    $biosafety = $assessment['lab_biosafety_trained'] ?? '';
    if ($biosafety == 'Yes') {
        $recommendations[] = [
            'question' => 'Q88',
            'category' => 'Lab Support',
            'response' => 'Yes',
            'recommendation' => '? Staff trained in biosafety. Conduct mapping and refresher training by IP, and train more TOTs.',
            'action_items' => [
                'Map all trained staff and identify gaps',
                'Conduct refresher training for existing staff',
                'Train additional TOTs for sustainability',
                'Develop biosafety audit checklist'
            ],
            'timeline' => '1-2 months',
            'priority' => 'Medium'
        ];
    } else {
        $recommendations[] = [
            'question' => 'Q88',
            'category' => 'Lab Support',
            'response' => 'No',
            'recommendation' => '? Staff not trained in biosafety - serious safety risk.',
            'action_items' => [
                'Conduct comprehensive biosafety training for all lab staff',
                'Develop biosafety protocols and SOPs',
                'Ensure PPE availability',
                'Establish biosafety committee'
            ],
            'timeline' => '1-2 months',
            'priority' => 'Critical'
        ];
    }

    // Q89: Hepatitis B Vaccination
    $hep_b = $assessment['lab_hepb_vaccinated'] ?? '';
    if ($hep_b != 'Yes') {
        $recommendations[] = [
            'question' => 'Q89',
            'category' => 'Lab Support',
            'response' => 'No/Partial',
            'recommendation' => '? Staff not vaccinated against Hepatitis B. Make it mandatory for each staff as a safety precaution for their own health.',
            'action_items' => [
                'Conduct hepatitis B vaccination campaign',
                'Document vaccination status for all lab staff',
                'Make vaccination a condition of employment',
                'Provide booster shots as needed'
            ],
            'timeline' => '1 month (urgent)',
            'priority' => 'Critical'
        ];
    }

    // Q90-Q91: IPC Committee & Workplan
    $ipc_committee = $assessment['lab_ipc_committee'] ?? '';
    if ($ipc_committee != 'Yes') {
        $recommendations[] = [
            'question' => 'Q90-Q91',
            'category' => 'Infection Control',
            'response' => 'No committee',
            'recommendation' => '? No active IPC committee. Form immediately on voluntary basis involving management for ownership. IP to provide technical support for 6 months.',
            'action_items' => [
                'Establish IPC committee with clear TOR',
                'Include management representation for ownership',
                'IP to provide technical support until fully functional (6 months)',
                'Develop and implement IPC workplan'
            ],
            'timeline' => '1 month to form, 6 months IP support',
            'priority' => 'High'
        ];
    } else {
        $ipc_workplan = $assessment['lab_ipc_workplan'] ?? '';
        if ($ipc_workplan != 'Yes') {
            $recommendations[] = [
                'question' => 'Q90-Q91',
                'category' => 'Infection Control',
                'response' => 'Committee exists but no workplan',
                'recommendation' => '? IPC committee exists. Review pending action points and support for 3 months before full transition.',
                'action_items' => [
                    'Review existing committee action points',
                    'Develop/update IPC workplan',
                    'Support implementation for 3 months',
                    'Transition full ownership to facility'
                ],
                'timeline' => '3 months transition support',
                'priority' => 'Medium'
            ];
        }
    }

    // Q92: MOH Virtual Academy Access
    $virtual_academy = $assessment['lab_moh_virtual_academy'] ?? '';
    if ($virtual_academy != 'Yes') {
        $recommendations[] = [
            'question' => 'Q92',
            'category' => 'Training',
            'response' => 'No access',
            'recommendation' => '? No access to MOH Virtual Academy. Register all staff at https://elearning.health.go.ke/ and make courses mandatory for appraisal.',
            'action_items' => [
                'Register all lab staff on the MOH Virtual Academy platform',
                'Identify mandatory courses for lab personnel',
                'Integrate course completion into HR appraisal system',
                'Track training progress monthly'
            ],
            'timeline' => '1 month registration, ongoing',
            'priority' => 'High'
        ];
    }

    // ==================== SECTION 9: COMMUNITY ENGAGEMENT (Q93-Q97) ====================

    $community_questions = [
        'comm_hiv_feedback_mechanism' => 'Q93: HIV Feedback Mechanism',
        'comm_roc_feedback_used' => 'Q94: ROC Feedback Used',
        'comm_community_representation' => 'Q96: Community Representation',
        'comm_plhiv_in_discussions' => 'Q97: PLHIV in Discussions'
    ];

    $has_community_gap = false;
    foreach ($community_questions as $field => $label) {
        $response = $assessment[$field] ?? '';
        if ($response != 'Yes') {
            $has_community_gap = true;
            break;
        }
    }

    if ($has_community_gap) {
        $recommendations[] = [
            'question' => 'Q93-Q97',
            'category' => 'Community Engagement',
            'response' => 'Multiple "No" responses',
            'recommendation' => '? Facility lacks community engagement mechanisms. Move with speed through CQI committees to create awareness and involve stakeholders in decision-making about integration.',
            'action_items' => [
                'Activate CQI committees to address community engagement',
                'Conduct community awareness sessions on integration',
                'Establish client feedback channels (suggestion boxes, exit interviews)',
                'Include PLHIV representatives in facility committees'
            ],
            'timeline' => '1-3 months',
            'priority' => 'High'
        ];
    }

    // Q95: Health Talks with PLHIV
    $health_talks = (int)($assessment['comm_health_talks_plhiv'] ?? 0);
    if ($health_talks < 4) {
        $recommendations[] = [
            'question' => 'Q95',
            'category' => 'Community Engagement',
            'response' => $health_talks . ' health talks',
            'recommendation' => '? Insufficient health talks with PLHIV. Increase frequency to at least monthly.',
            'action_items' => [
                'Schedule monthly health talks with PLHIV support groups',
                'Cover topics on integration benefits and service availability',
                'Document attendance and feedback',
                'Use PLHIV peer educators for talks'
            ],
            'timeline' => 'Immediate - ongoing',
            'priority' => 'Medium'
        ];
    }

    // ==================== SECTION 10: SUPPLY CHAIN (Q98-Q102) ====================

    // Q98: KHIS Reports Monthly
    $khis_reports = $assessment['sc_khis_reports_monthly'] ?? '';
    if ($khis_reports != 'Yes') {
        $recommendations[] = [
            'question' => 'Q98',
            'category' => 'Supply Chain',
            'response' => 'No',
            'recommendation' => '? Commodity consumption reports not submitted consistently.',
            'action_items' => [
                'Establish monthly reporting schedule',
                'Train staff on FMARPS (MOH729B) and FCDRR (MOH730B)',
                'Conduct monthly data quality checks',
                'Participate in quarterly commodity security TWG meetings'
            ],
            'timeline' => '1-2 months',
            'priority' => 'High'
        ];
    } else {
        $recommendations[] = [
            'question' => 'Q98',
            'category' => 'Supply Chain',
            'response' => 'Yes',
            'recommendation' => '? Consistent reporting in place. Maintain regular practices through County HPTU, data review and commodity security TWG meetings.',
            'action_items' => [
                'Continue monthly reporting',
                'Participate actively in TWG meetings',
                'Review data for decision-making',
                'Share best practices with other facilities'
            ],
            'timeline' => 'Ongoing',
            'priority' => 'Low'
        ];
    }

    // Stock-out questions
    $stockout_questions = [
        'sc_stockout_arvs' => 'ARVs',
        'sc_stockout_tb_drugs' => 'TB Drugs',
        'sc_stockout_hiv_reagents' => 'HIV Reagents (VL, CD4)',
        'sc_stockout_tb_reagents' => 'TB Testing Reagents (LFA, LAMP, GeneXpert)'
    ];

    foreach ($stockout_questions as $field => $commodity) {
        $response = $assessment[$field] ?? '';
        if ($response == 'Yes') {
            $recommendations[] = [
                'question' => 'Q99-Q102',
                'category' => 'Supply Chain',
                'response' => 'Stock-out occurred for ' . $commodity,
                'recommendation' => "? Stock-out of $commodity detected. Implement corrective measures immediately.",
                'action_items' => [
                    'Conduct monthly/quarterly allocation meetings with County',
                    'Improve forecasting using consumption data',
                    'Maintain good record keeping and inventory control',
                    'Consider redistribution through Sub-County/County mechanisms',
                    'Establish buffer stock system',
                    'Ensure no client misses services during stock-outs'
                ],
                'timeline' => 'Immediate - ongoing',
                'priority' => 'Critical'
            ];
        }
    }

    // ==================== SECTION 11: PRIMARY HEALTH CARE (Q103-Q104) ====================

    $phc_questions = [
        'phc_chp_referrals' => 'CHP Referrals for PLHIV',
        'phc_chwp_tracing' => 'CHWP Tracing for LTFU'
    ];

    $has_phc_gap = false;
    foreach ($phc_questions as $field => $label) {
        $response = $assessment[$field] ?? '';
        if ($response != 'Yes') {
            $has_phc_gap = true;
            break;
        }
    }

    if ($has_phc_gap) {
        $recommendations[] = [
            'question' => 'Q103-Q104',
            'category' => 'Primary Health Care',
            'response' => 'Missing functionality',
            'recommendation' => '? PHC community mechanisms need strengthening. Strengthen community mechanisms through PHC and community health providers.',
            'action_items' => [
                'Map all Community Health Promoters (CHPs) in catchment area',
                'Train CHPs on HIV/TB referral pathways',
                'Establish referral documentation system',
                'Hold quarterly review meetings with CHPs',
                'Integrate tracing into routine PHC activities'
            ],
            'timeline' => '1-3 months',
            'priority' => 'High'
        ];
    }

    // Sort recommendations by priority
    $priority_order = ['Critical' => 1, 'High' => 2, 'Medium' => 3, 'Low' => 4];
    usort($recommendations, function($a, $b) use ($priority_order) {
        return ($priority_order[$a['priority']] ?? 5) <=> ($priority_order[$b['priority']] ?? 5);
    });

    return $recommendations;
}

function getWorkplanHTML($workplan) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Integration Workplan - <?= htmlspecialchars($workplan['facility_name']) ?></title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: white;
                color: #333;
                line-height: 1.6;
                padding: 30px;
            }
            .container { max-width: 1200px; margin: 0 auto; }

            .page-header {
                background: linear-gradient(135deg, #0D1A63 0%, #1a3a9e 100%);
                color: #fff;
                padding: 20px 25px;
                border-radius: 8px;
                margin-bottom: 20px;
                text-align: center;
            }
            .page-header h1 { font-size: 1.8rem; margin-bottom: 5px; }
            .page-header .subtitle { font-size: 0.9rem; opacity: 0.9; }

            .workplan-meta {
                background: #f8fafc;
                border: 1px solid #e0e4f0;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 20px;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 12px;
            }
            .meta-item { text-align: center; padding: 6px; }
            .meta-item .label { font-size: 10px; font-weight: 700; color: #666; text-transform: uppercase; }
            .meta-item .value { font-size: 14px; font-weight: 800; color: #0D1A63; margin-top: 3px; }

            .readiness-badge {
                display: inline-block;
                padding: 5px 12px;
                border-radius: 20px;
                font-weight: 700;
                font-size: 12px;
            }
            .badge-success { background: #d4edda; color: #155724; }
            .badge-warning { background: #fff3cd; color: #856404; }
            .badge-orange { background: #ffe5d0; color: #fd7e14; }
            .badge-danger { background: #f8d7da; color: #721c24; }

            .section-title {
                font-size: 1.1rem;
                font-weight: 700;
                color: #0D1A63;
                margin: 20px 0 12px;
                padding-bottom: 6px;
                border-bottom: 2px solid #0D1A63;
            }

            .card {
                background: #fff;
                border: 1px solid #e0e4f0;
                border-radius: 8px;
                margin-bottom: 18px;
                overflow: hidden;
            }
            .card-header {
                background: #f8fafc;
                padding: 10px 15px;
                border-bottom: 1px solid #e0e4f0;
                font-weight: 700;
                color: #0D1A63;
                font-size: 14px;
            }
            .card-body { padding: 15px; }

            .recommendation-item {
                background: #f8fafc;
                border-left: 4px solid;
                padding: 12px;
                margin-bottom: 12px;
                border-radius: 6px;
            }
            .rec-critical { border-left-color: #dc3545; }
            .rec-high { border-left-color: #fd7e14; }
            .rec-medium { border-left-color: #ffc107; }
            .rec-low { border-left-color: #28a745; }

            .priority-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 9px;
                font-weight: 700;
            }
            .priority-critical { background: #f8d7da; color: #721c24; }
            .priority-high { background: #ffe5d0; color: #fd7e14; }
            .priority-medium { background: #fff3cd; color: #856404; }
            .priority-low { background: #d4edda; color: #155724; }

            .action-list { margin-top: 8px; padding-left: 20px; }
            .action-list li { margin: 3px 0; font-size: 12px; }

            .timeline-table, .gap-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
            }
            .timeline-table th, .gap-table th {
                background: #0D1A63;
                color: #fff;
                padding: 8px;
                text-align: left;
            }
            .timeline-table td, .gap-table td {
                padding: 8px;
                border-bottom: 1px solid #e0e4f0;
            }

            .phase-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 10px;
                font-weight: 600;
            }
            .phase-1 { background: #cfe2ff; color: #004085; }
            .phase-2 { background: #fff3cd; color: #856404; }
            .phase-3 { background: #d4edda; color: #155724; }
            .phase-4 { background: #e2e3e5; color: #383d41; }

            .kpi-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                gap: 12px;
                margin-bottom: 15px;
            }
            .kpi-card {
                background: #f8fafc;
                border-radius: 8px;
                padding: 12px;
                text-align: center;
                border: 1px solid #e0e4f0;
            }
            .kpi-value {
                font-size: 22px;
                font-weight: 800;
                color: #0D1A63;
            }
            .kpi-label {
                font-size: 10px;
                color: #666;
                text-transform: uppercase;
                margin-top: 4px;
            }

            .sub-label {
                font-size: 13px;
                font-weight: 700;
                color: #0D1A63;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                margin: 20px 0 12px;
                padding-bottom: 6px;
                border-bottom: 1px solid #e8edf8;
            }

            .footer-note {
                margin-top: 30px;
                padding: 15px;
                text-align: center;
                font-size: 10px;
                color: #666;
                border-top: 1px solid #e0e4f0;
            }

            @media print {
                body { padding: 0; }
                .no-print { display: none; }
                .page-break { page-break-before: always; }
            }
        </style>
    </head>
    <body>
    <div class="container">
        <div class="page-header">
            <h1>Integration Transition Workplan</h1>
            <div class="subtitle">HIV/TB Service Integration into Routine Health Services</div>
            <div style="margin-top: 8px;"><?= htmlspecialchars($workplan['facility_name']) ?> | MFL: <?= htmlspecialchars($workplan['mflcode']) ?> | <?= htmlspecialchars($workplan['county']) ?> County</div>
        </div>

        <!-- Workplan Meta Information -->
        <div class="workplan-meta">
            <div class="meta-item"><div class="label">Assessment Period</div><div class="value"><?= htmlspecialchars($workplan['assessment_period']) ?></div></div>
            <div class="meta-item"><div class="label">Level of Care</div><div class="value"><?= htmlspecialchars($workplan['level_of_care']) ?></div></div>
            <div class="meta-item"><div class="label">Integration Readiness</div><div class="value"><span class="readiness-badge badge-<?= $workplan['readiness_color'] ?>"><?= $workplan['readiness_level'] ?> (<?= $workplan['readiness_score'] ?>%)</span></div></div>
            <div class="meta-item"><div class="label">Assessment Date</div><div class="value"><?= date('d M Y', strtotime($workplan['collection_date'])) ?></div></div>
            <div class="meta-item"><div class="label">Assessed By</div><div class="value"><?= htmlspecialchars($workplan['collected_by']) ?></div></div>
        </div>

        <!-- Executive Summary -->
        <div class="card">
            <div class="card-header">Executive Summary</div>
            <div class="card-body">
                <p>This integration workplan outlines the transition from vertical HIV/TB services to fully integrated service delivery at <strong><?= htmlspecialchars($workplan['facility_name']) ?></strong>. Based on the integration assessment conducted in <strong><?= $workplan['assessment_period'] ?></strong>, the facility has been classified as <strong><?= $workplan['readiness_level'] ?></strong> with an overall integration readiness score of <strong><?= $workplan['readiness_score'] ?>%</strong>.</p>
                <p style="margin-top: 10px;">The facility currently serves <strong><?= number_format($workplan['key_metrics']['tx_curr']) ?></strong> PLHIV on ART, with <strong><?= number_format($workplan['key_metrics']['plhiv_integrated']) ?></strong> receiving integrated care. The transition period will run from <strong><?= $workplan['timeline']['start_date'] ?></strong> to <strong><?= $workplan['timeline']['end_date'] ?></strong> (<?= $workplan['timeline']['total_months'] ?> months).</p>
            </div>
        </div>

        <!-- Key Metrics -->
        <div class="section-title">Key Performance Indicators</div>
        <div class="kpi-grid">
            <div class="kpi-card"><div class="kpi-value"><?= number_format($workplan['key_metrics']['tx_curr']) ?></div><div class="kpi-label">TX_CURR (PLHIV on ART)</div></div>
            <div class="kpi-card"><div class="kpi-value"><?= number_format($workplan['key_metrics']['plhiv_integrated']) ?></div><div class="kpi-label">PLHIV in Integrated Care</div></div>
            <div class="kpi-card"><div class="kpi-value"><?= number_format($workplan['key_metrics']['plhiv_sha']) ?></div><div class="kpi-label">PLHIV Enrolled SHA</div></div>
            <div class="kpi-card"><div class="kpi-value"><?= number_format($workplan['key_metrics']['hcw_pepfar']) ?></div><div class="kpi-label">HCWs PEPFAR Supported</div></div>
            <div class="kpi-card"><div class="kpi-value"><?= number_format($workplan['key_metrics']['hcw_transitioned']) ?></div><div class="kpi-label">HCWs Transitioned to County</div></div>
            <div class="kpi-card"><div class="kpi-value"><?= number_format($workplan['key_metrics']['ta_visits_total']) ?></div><div class="kpi-label">TA/Mentorship Visits</div></div>
        </div>

        <!-- Integration Model Recommendation -->
        <div class="section-title">Recommended Integration Model</div>
        <div class="card">
            <div class="card-body">
                <?php foreach ($workplan['integration_model'] as $model): ?>
                <div style="margin-bottom: 15px; padding: 12px; background: #f8fafc; border-radius: 6px;">
                    <strong style="color: #0D1A63; font-size: 14px;"><?= htmlspecialchars($model['name']) ?></strong>
                    <span style="display: inline-block; margin-left: 10px; padding: 2px 8px; background: <?= $model['suitability'] == 'High' ? '#d4edda' : ($model['suitability'] == 'Recommended' ? '#fff3cd' : '#e2e3e5') ?>; border-radius: 12px; font-size: 10px; font-weight: 600;"><?= $model['suitability'] ?> Suitability</span>
                    <p style="margin-top: 8px; font-size: 13px; color: #555;"><?= htmlspecialchars($model['description']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Gaps Analysis -->
        <div class="section-title">Gaps Analysis</div>
        <div class="card">
            <div class="card-body">
                <table class="gap-table">
                    <thead>
                        <tr><th>Category</th><th>Indicator</th><th>Current Status</th><th>Target Status</th><th>Severity</th></thead>
                    <tbody>
                        <?php foreach ($workplan['gaps'] as $gap): ?>
                        <tr>
                            <td><?= $gap['category'] ?></td>
                            <td><?= $gap['indicator'] ?></td>
                            <td><span class="priority-badge <?= $gap['severity'] == 'High' ? 'priority-critical' : 'priority-medium' ?>"><?= htmlspecialchars($gap['current']) ?></span></td>
                            <td><span class="priority-badge priority-low"><?= $gap['target'] ?></span></td>
                            <td><span class="priority-badge <?= $gap['severity'] == 'High' ? 'priority-critical' : 'priority-medium' ?>"><?= $gap['severity'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- AI-Powered Recommendations -->
        <div class="section-title">Strategic Recommendations</div>
        <div class="card">
            <div class="card-body">
                <?php foreach ($workplan['recommendations'] as $rec):
                    $rec_class = $rec['priority'] == 'Critical' ? 'rec-critical' : ($rec['priority'] == 'High' ? 'rec-high' : ($rec['priority'] == 'Medium' ? 'rec-medium' : 'rec-low'));
                ?>
                <div class="recommendation-item <?= $rec_class ?>">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;">
                        <strong style="font-size: 13px;"><?= htmlspecialchars($rec['category']) ?>: <?= htmlspecialchars($rec['title']) ?></strong>
                        <div>
                            <span class="priority-badge <?= $rec['priority'] == 'Critical' ? 'priority-critical' : ($rec['priority'] == 'High' ? 'priority-high' : ($rec['priority'] == 'Medium' ? 'priority-medium' : 'priority-low')) ?>">
                                <?= $rec['priority'] ?> Priority
                            </span>
                            <span style="margin-left: 5px; font-size: 10px; color: #666;">Timeline: <?= $rec['timeline'] ?></span>
                        </div>
                    </div>
                    <p style="margin-bottom: 8px; font-size: 12px;"><?= htmlspecialchars($rec['description']) ?></p>
                    <div>
                        <strong>Key Action Items:</strong>
                        <ul class="action-list">
                            <?php foreach ($rec['actions'] as $action): ?>
                            <?php if (!empty($action)): ?>
                            <li><?= htmlspecialchars($action) ?></li>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                        <div style="margin-top: 8px; font-size: 11px; color: #666;">
                            <strong>Responsible:</strong> <?= htmlspecialchars($rec['responsible']) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Phased Transition Timeline -->
        <div class="section-title">Phased Transition Timeline</div>
        <div class="card">
            <div class="card-body">
                <table class="timeline-table">
                    <thead>
                        <tr><th>Phase</th><th>Duration</th><th>Key Activities</th></thead>
                    <tbody>
                        <?php foreach ($workplan['timeline']['phases'] as $phase): ?>
                         <tr>
                             <td><span class="phase-badge phase-<?= $phase[0] == 'Phase 1' ? '1' : ($phase[0] == 'Phase 2' ? '2' : ($phase[0] == 'Phase 3' ? '3' : '4')) ?>"><?= $phase[0] ?></span></td>
                            <td>Months <?= $phase[1] ?>-<?= $phase[2] ?></td>
                            <td><?= htmlspecialchars($phase[3]) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top: 12px; font-size: 12px; color: #666;"><strong>Overall Timeline:</strong> <?= $workplan['timeline']['start_date'] ?> to <?= $workplan['timeline']['end_date'] ?> (<?= $workplan['timeline']['total_months'] ?> months)</p>
            </div>
        </div>

        <!-- EMR Status -->
        <div class="section-title">EMR & Digital Systems Status</div>
        <div class="card">
            <div class="card-body">
                <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));">
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['emr_status']['uses_emr'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">Uses EMR</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= count($workplan['emr_status']['departments_covered']) ?></div><div class="kpi-label">Departments with EMR</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['emr_status']['interoperable'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">Interoperable</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['emr_status']['single_unified'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">Single Unified EMR</div></div>
                </div>
                <?php if (!empty($workplan['emr_status']['emr_systems'])): ?>
                <div style="margin-top: 12px;">
                    <strong>EMR Systems in Use:</strong> <?= implode(', ', $workplan['emr_status']['emr_systems']) ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($workplan['emr_status']['departments_covered'])): ?>
                <div style="margin-top: 5px; font-size: 12px;">
                    <strong>Departments Covered:</strong> <?= implode(', ', $workplan['emr_status']['departments_covered']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- HRH & Financial Status -->
        <div class="section-title">HRH & Financial Sustainability</div>
        <div class="card">
            <div class="card-body">
                <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); margin-bottom: 15px;">
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['hrh_status']['leadership_commitment'] ?></div><div class="kpi-label">Leadership</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['hrh_status']['staff_multiskilled'] ?></div><div class="kpi-label">Multi-skilled Staff</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['hrh_status']['hiv_in_awp'] ?></div><div class="kpi-label">HIV in AWP</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['hrh_status']['hrh_gap'] ?></div><div class="kpi-label">HRH Gap</div></div>
                </div>
                <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));">
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['financial_status']['fif_collection'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">FIF Collection</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['financial_status']['fif_includes_hiv'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">FIF Includes HIV/TB</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['financial_status']['sha_capitation'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">SHA Capitation</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['financial_status']['sha_reimbursements'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">Monthly SHA Reimbursements</div></div>
                </div>
                <?php if (!empty($workplan['barriers'])): ?>
                <div style="margin-top: 15px; padding: 10px; background: #f8fafc; border-radius: 6px;">
                    <strong>Key Barriers:</strong> <?= nl2br(htmlspecialchars($workplan['barriers'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Current Integration Status -->
        <div class="section-title">Current Integration Status</div>
        <div class="card">
            <div class="card-body">
                <div class="kpi-grid" style="grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));">
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['integration_status']['hiv_tb_integrated'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">HIV/TB Integrated</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['integration_status']['pmtct_integrated'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">PMTCT in MNCH</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['integration_status']['hts_opd'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">HTS in OPD</div></div>
                    <div class="kpi-card"><div class="kpi-value"><?= $workplan['integration_status']['prep_opd'] == 'Yes' ? 'Yes' : 'No' ?></div><div class="kpi-label">PrEP in OPD</div></div>
                </div>
                <?php if (!empty($workplan['integration_status']['integration_model'])): ?>
                <div style="margin-top: 12px;">
                    <strong>Current Model:</strong> <?= htmlspecialchars($workplan['integration_status']['integration_model']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Detailed Question-by-Question Recommendations -->
        <div class="section-title">Detailed Actionable Recommendations by Question</div>
        <div class="card">
            <div class="card-body">
                <p style="margin-bottom: 15px; color: #666;">The following recommendations are based on specific responses to each assessment question:</p>

                <?php if (!empty($workplan['detailed_recommendations'])): ?>
                    <?php
                    $current_category = '';
                    foreach ($workplan['detailed_recommendations'] as $rec):
                        $priority_class = $rec['priority'] == 'Critical' ? 'priority-critical' :
                                         ($rec['priority'] == 'High' ? 'priority-high' :
                                         ($rec['priority'] == 'Medium' ? 'priority-medium' : 'priority-low'));
                        $border_class = $rec['priority'] == 'Critical' ? 'rec-critical' :
                                       ($rec['priority'] == 'High' ? 'rec-high' :
                                       ($rec['priority'] == 'Medium' ? 'rec-medium' : 'rec-low'));
                    ?>
                        <?php if ($current_category != $rec['category']): ?>
                            <?php if ($current_category != ''): ?></div><?php endif; ?>
                            <div class="sub-label" style="margin-top: 15px;">
                                <i class="fas fa-folder-open"></i> <?= htmlspecialchars($rec['category']) ?>
                            </div>
                            <div style="margin-top: 10px;">
                            <?php $current_category = $rec['category']; ?>
                        <?php endif; ?>

                        <div class="recommendation-item <?= $border_class ?>" style="margin-bottom: 12px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 8px;">
                                <strong style="font-size: 12px;"><?= htmlspecialchars($rec['question']) ?></strong>
                                <div>
                                    <span class="priority-badge <?= $priority_class ?>"><?= $rec['priority'] ?> Priority</span>
                                    <span style="margin-left: 5px; font-size: 10px; color: #666;">Timeline: <?= $rec['timeline'] ?></span>
                                </div>
                            </div>
                            <p style="margin-bottom: 8px; font-size: 12px; background: #f8f9fa; padding: 6px 10px; border-radius: 4px;">
                                <strong>Response:</strong> <?= htmlspecialchars($rec['response']) ?>
                            </p>
                            <p style="margin-bottom: 8px; font-size: 12px;"><?= htmlspecialchars($rec['recommendation']) ?></p>
                            <div>
                                <strong>Action Items:</strong>
                                <ul class="action-list">
                                    <?php foreach ($rec['action_items'] as $action): ?>
                                        <li><?= htmlspecialchars($action) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>No detailed recommendations available.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="footer-note">
            This integration workplan was generated based on facility assessment data from the Integration Assessment Tool.<br>
            Generated on: <?= date('d F Y H:i:s') ?>
        </div>
    </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

function exportToPDF($workplan, $conn) {
    $html = getWorkplanHTML($workplan);

    try {
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = "Integration_Workplan_" . str_replace(' ', '_', $workplan['facility_name']) . "_" . date('Ymd') . ".pdf";
        $dompdf->stream($filename, array('Attachment' => true));
    } catch (Exception $e) {
        die('Error generating PDF: ' . $e->getMessage());
    }
    exit();
}

function exportToWord($workplan, $conn) {
    $html = getWorkplanHTML($workplan);

    $html = str_replace('</head>',
        '<meta charset="UTF-8">
        <meta name="generator" content="Microsoft Word 15">
        <meta name="ProgId" content="Word">
        <style>
            @page { size: A4; margin: 2.54cm; }
            body { margin: 0; padding: 20px; }
        </style>
        </head>',
        $html);

    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="Integration_Workplan_' . str_replace(' ', '_', $workplan['facility_name']) . '_' . date('Ymd') . '.doc"');
    header('Cache-Control: max-age=0');

    echo $html;
    exit();
}

function renderWorkplan($workplan, $assessment, $conn) {
    $html = getWorkplanHTML($workplan);

    $html = str_replace('</head>',
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        </head>',
        $html);

    $html = str_replace('</body>', '
    <div style="position: fixed; bottom: 20px; right: 20px; display: flex; gap: 10px; z-index: 1000;" class="no-print">
        <a href="?id=' . $assessment['assessment_id'] . '&export=pdf"
           style="background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600;">
            <i class="fas fa-file-pdf"></i> Export PDF
        </a>
        <a href="?id=' . $assessment['assessment_id'] . '&export=word"
           style="background: #0D1A63; color: white; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: 600;">
            <i class="fas fa-file-word"></i> Export Word
        </a>
        <button onclick="window.print()"
                style="background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
    </body>', $html);

    echo $html;
}
?>