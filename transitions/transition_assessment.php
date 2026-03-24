<?php
// transitions/transition_assessment.php
session_start();
include('../includes/config.php');
include('../includes/session_check.php');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Get parameters
$county_id = isset($_GET['county']) ? (int)$_GET['county'] : 0;
$period = isset($_GET['period']) ? mysqli_real_escape_string($conn, $_GET['period']) : '';
$sections = isset($_GET['sections']) ? explode(',', $_GET['sections']) : [];

if (!$county_id || !$period || empty($sections)) {
    header('Location: transition_index.php');
    exit();
}

// Get county name
$county_result = $conn->query("SELECT county_name FROM counties WHERE county_id = $county_id");
$county_name = $county_result->fetch_assoc()['county_name'];

// Check if this is a new assessment or editing existing
$assessment_id = isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0;
$existing_scores = [];

// -- Load existing RAW scores (sub-indicator level) for pre-filling form ------
// key: "section_indicator_subcode" e.g. "leadership_T1_T1.1"
$existing_raw = [];       // [composite_key => ['cdoh'=>x,'ip'=>x,'comments'=>x]]
$submitted_sections = []; // [section_key => ['submitted_at'=>..., 'sub_count'=>..., 'avg_cdoh'=>...]]

// If no assessment_id in URL, look up by county+period (draft or submitted)
if (!$assessment_id) {
    $chk = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT assessment_id FROM transition_assessments
         WHERE county_id=$county_id AND assessment_period='$period'
         ORDER BY assessment_date DESC LIMIT 1"));
    if ($chk) $assessment_id = (int)$chk['assessment_id'];
}

if ($assessment_id) {
    // Load raw scores
    $rr = mysqli_query($conn,
        "SELECT composite_key, cdoh_score, ip_score, comments
         FROM transition_raw_scores WHERE assessment_id = $assessment_id");
    if ($rr) while ($row = mysqli_fetch_assoc($rr)) {
        $existing_raw[$row['composite_key']] = $row;
    }
    // Load section submission log
    $sr = mysqli_query($conn,
        "SELECT section_key, submitted_at, sub_count, avg_cdoh, avg_ip
         FROM transition_section_submissions WHERE assessment_id = $assessment_id");
    if ($sr) while ($row = mysqli_fetch_assoc($sr)) {
        $submitted_sections[$row['section_key']] = $row;
    }
}

// Define scoring criteria for each level
$scoring_criteria = [
    4 => ['label' => 'Fully adequate with evidence', 'class' => 'level-4'],
    3 => ['label' => 'Partially adequate with evidence', 'class' => 'level-3'],
    2 => ['label' => 'Structures/functions defined some evidence', 'class' => 'level-2'],
    1 => ['label' => 'Structures/functions defined NO evidence', 'class' => 'level-1'],
    0 => ['label' => 'Inadequate structures/functions', 'class' => 'level-0']
];

// Define all sections with their detailed indicators
$all_sections = [
    'leadership' => [
        'title' => 'COUNTY LEVEL LEADERSHIP AND GOVERNANCE',
        'icon' => 'fa-landmark',
        'color' => '#0D1A63',
        'has_ip' => false, // T1, T2, T3 are CDOH only
        'indicators' => [
            'T1' => [
                'code' => 'T1',
                'name' => 'Transition of County Legislature Health Leadership and Governance',
                'sub_indicators' => [
                    'T1.1' => 'Does the county have a legally constituted mechanism that oversees the health department? (e.g. County assembly health committee)',
                    'T1.2' => 'Does the county have an overall vision for the County Department of Health (CDOH) that is overseen by the County assembly health committee?',
                    'T1.3' => 'Are the roles of the County assembly health committee well-defined in the county health system?',
                    'T1.4' => 'Are County assembly health committee meetings held regularly as stipulated; decisions documented; and reflect accountability and resource stewardship?',
                    'T1.5' => 'Does the County assembly health committee composition include members who are recognized for leadership and/or area of expertise and are representative of stakeholders including PLHIV/TB patients?',
                    'T1.6' => 'Does the County assembly health committee ensure that public interest is considered in decision making?',
                    'T1.7' => 'How committed and accountable is the County assembly health committee in following up on agreed action items?',
                    'T1.8' => 'Does the County assembly health committee have a risk management policy/framework?',
                    'T1.9' => 'How much oversight is given to HIV/TB activities in the county by the health committee of the county assembly?',
                    'T1.10' => 'Is the leadership arrangement/structure for the HIV/TB program adequate to increase coverage and quality of HIV/TB services?',
                    'T1.11' => 'Does the HIV/TB program planning and funding allow for sustainability?'
                ]
            ],
            'T2' => [
                'code' => 'T2',
                'name' => 'Transition of County Executive (CHMT) in Health Leadership and Governance',
                'sub_indicators' => [
                    'T2.1' => 'Is the CHMT responsive to the requirements of the County\'s Oversight structures, i.e. County assembly health committee?',
                    'T2.2' => 'Is the CHMT accountable to clients/patients seeking services within the county?',
                    'T2.3' => 'Is the CHMT involving the private sector and community based organizations in the planning of health services including HIV/TB services?',
                    'T2.4' => 'Are CHMT meetings held regularly as stipulated; decisions documented including for the HIV/TB program; and reflect accountability and resource stewardship?',
                    'T2.5' => 'Is the CHMT implementing policies and regulations set by national level?',
                    'T2.6' => 'Does the CHMT hold joint monitoring teams and joint high-level meetings with development partners supporting the county?',
                    'T2.7' => 'Does the CHMT plan and manage health services to meet local needs?',
                    'T2.8' => 'Does the CHMT mobilize local resources for the HIV/TB program?',
                    'T2.9' => 'Is the CHMT involved in the supervision of HIV/TB services in the county?',
                    'T2.10' => 'Has the CHMT ensured that the leadership arrangement/structure for the HIV/TB program is adequate?',
                    'T2.11' => 'Has the CHMT ensured that the HIV/TB program planning and funding allow for sustainability?'
                ]
            ],
            'T3' => [
                'code' => 'T3',
                'name' => 'Transition of County Health Planning: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T3.1' => 'Creating a costed county annual work plan for HIV/TB services',
                    'T3.2' => 'Identifying key HIV program priorities that sustains good coverage and high HIV service quality',
                    'T3.3' => 'Track implementation of the costed county annual work plan for HIV/TB services',
                    'T3.4' => 'Identifying HRH needs for HIV/TB that will support the delivery of the agreed package of activities',
                    'T3.5' => 'Having in place a system for forecasting, including HRH needs for HIV/TB',
                    'T3.6' => 'Coordinating the scope of activities and resource contributions of all partners for HIV/TB in county',
                    'T3.7' => 'Convening meetings with key county HIV/TB services program staff and implementing partners to review performance',
                    'T3.8' => 'Convening meetings with community HIV/TB stakeholders to review community needs',
                    'T3.9' => 'Convening to review program performance for HIV/TB',
                    'T3.10' => 'Providing technical guidance for county AIDS/TB coordination',
                    'T3.11' => 'Providing support to the County AIDS Committee'
                ]
            ]
        ]
    ],
    'supervision' => [
        'title' => 'COUNTY LEVEL ROUTINE SUPERVISION AND MENTORSHIP',
        'icon' => 'fa-clipboard-check',
        'color' => '#1a3a9e',
        'has_ip' => true,
        'indicators' => [
            'T4A' => [
                'code' => 'T4A',
                'name' => 'Transition of routine Supervision and Mentorship: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T4A.1' => 'Developing the county HIV/TB programme routine supervision plan',
                    'T4A.2' => 'Arranging logistics, including vehicle and/or fuel',
                    'T4A.3' => 'Conducting routine supervision visits to county (public)/private/faith-based facilities',
                    'T4A.4' => 'Completing supervision checklist',
                    'T4A.5' => 'Mobilizing support to address issues identified during supervision',
                    'T4A.6' => 'Financial facilitation for county supervision (paying allowances to supervisors)',
                    'T4A.7' => 'Developing the action plan and following up on issues identified during the supervision',
                    'T4A.8' => 'Planning for staff mentorship including cross learning visits',
                    'T4A.9' => 'Spending time with staff to identify individual\'s strengths',
                    'T4A.10' => 'Identifying and working with facility staff to pursue mentorship goals',
                    'T4A.11' => 'Paying for mentorship activities',
                    'T4A.12' => 'Documenting outcomes of the mentorship'
                ]
            ],
            'T4B' => [
                'code' => 'T4B',
                'name' => 'Transition of routine Supervision and mentorship: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T4B.1' => 'Developing the county HIV/TB supervision plan',
                    'T4B.2' => 'Arranging logistics, including vehicle and/or fuel',
                    'T4B.3' => 'Conducting supervision visits to county (public)/private/faith-based facilities',
                    'T4B.4' => 'Completing supervision forms',
                    'T4B.5' => 'Mobilizing support to address issues identified during supervision',
                    'T4B.6' => 'Financial facilitation for county supervision (paying allowances to supervisors)',
                    'T4B.7' => 'Developing the action plan and following up on issues identified during the supervision',
                    'T4B.8' => 'Planning for staff mentorship including cross learning visits',
                    'T4B.9' => 'Spending time with staff to identify individual\'s strengths',
                    'T4B.10' => 'Identifying and working with facility staff to pursue mentorship goals',
                    'T4B.11' => 'Paying for mentorship activities',
                    'T4B.12' => 'Documenting outcomes of the mentorship'
                ]
            ]
        ]
    ],
    'special_initiatives' => [
        'title' => 'COUNTY LEVEL HIV/TB PROGRAM SPECIAL INITIATIVES (RRI, Leap, Surge, SIMS)',
        'icon' => 'fa-bolt',
        'color' => '#2a4ab0',
        'has_ip' => true,
        'indicators' => [
            'T5A' => [
                'code' => 'T5A',
                'name' => 'Transition of HIV/TB program special initiatives: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T5A.1' => 'Developing the county RRI, LEAP, Surge or SIMS plan or any other initiative',
                    'T5A.2' => 'Arranging logistics, including vehicle and/or fuel',
                    'T5A.3' => 'Conducting LEAP, SURGE, SIMS or RRI visits to public/private/faith based facilities',
                    'T5A.4' => 'Completing relevant initiative tools / reporting templates',
                    'T5A.5' => 'Mobilizing support to address issues identified during site visits',
                    'T5A.6' => 'Financial facilitation for site visits (paying allowances to the team)',
                    'T5A.7' => 'Developing the action plan and following up on issues identified during site visits',
                    'T5A.8' => 'Reporting special initiative implementation progress to higher levels'
                ]
            ],
            'T5B' => [
                'code' => 'T5B',
                'name' => 'Transition of HIV program special initiatives: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T5B.1' => 'Developing the county RRI, LEAP, Surge or SIMS plan or any other initiative',
                    'T5B.2' => 'Arranging logistics, including vehicle and/or fuel',
                    'T5B.3' => 'Conducting LEAP, SURGE, SIMS or RRI visits to public/private/faith based facilities',
                    'T5B.4' => 'Completing relevant initiative tools/ reporting templates',
                    'T5B.5' => 'Mobilizing support to address issues identified during site visits',
                    'T5B.6' => 'Financial facilitation for site visits (paying allowances to the team)',
                    'T5B.7' => 'Developing the action plan and following up on issues identified during site visits',
                    'T5B.8' => 'Reporting special initiative implementation progress to higher levels'
                ]
            ]
        ]
    ],
    'quality_improvement' => [
        'title' => 'COUNTY LEVEL QUALITY IMPROVEMENT',
        'icon' => 'fa-chart-line',
        'color' => '#3a5ac8',
        'has_ip' => true,
        'indicators' => [
            'T6A' => [
                'code' => 'T6A',
                'name' => 'Transition of Quality Improvement (QI): Level of Involvement of the IP',
                'sub_indicators' => [
                    'T6A.1' => 'Selecting priorities and developing / adapting QI plan',
                    'T6A.2' => 'Training facility staff',
                    'T6A.3' => 'Providing technical support to QI teams',
                    'T6A.4' => 'Reviewing/tracking facility QI reports',
                    'T6A.5' => 'Funding QI Initiatives',
                    'T6A.6' => 'Other support QI activities',
                    'T6A.7' => 'Convening/managing county-wide QI forum'
                ]
            ],
            'T6B' => [
                'code' => 'T6B',
                'name' => 'Transition of Quality Improvement: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T6B.1' => 'Selecting priorities and developing/adapting QI plan',
                    'T6B.2' => 'Training facility staff',
                    'T6B.3' => 'Providing technical support to QI teams',
                    'T6B.4' => 'Reviewing/tracking facility QI reports',
                    'T6B.5' => 'Funding QI Initiatives',
                    'T6B.6' => 'Other support QI activities',
                    'T6B.7' => 'Convening/managing county-wide QI forum'
                ]
            ]
        ]
    ],
    'identification_linkage' => [
        'title' => 'COUNTY LEVEL HIV/TB PATIENT IDENTIFICATION AND LINKAGE TO TREATMENT',
        'icon' => 'fa-user-plus',
        'color' => '#4a6ae0',
        'has_ip' => true,
        'indicators' => [
            'T7A' => [
                'code' => 'T7A',
                'name' => 'Transition of Patient identification and linkage to treatment: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T7A.1' => 'Recruitment of HIV testing services (HTS) counselors',
                    'T7A.2' => 'Remuneration of HIV testing counselors (Funds for paying HTS Counselors)',
                    'T7A.3' => 'Ensuring that HTS eligibility screening registers and SOPS are available',
                    'T7A.4' => 'Ensuring that HIV testing consumables/supplies are available',
                    'T7A.5' => 'Ensuring availability of adequate and appropriate HIV testing space/environment',
                    'T7A.6' => 'Ensuring effective procedures of linkage of HIV positive patients',
                    'T7A.7' => 'Ensuring documentation of linkage of HIV positive patients',
                    'T7A.8' => 'Training and providing refresher training to HIV testing counsellors',
                    'T7A.9' => 'HTS quality monitoring including conducting observed practices for HTS counsellors',
                    'T7A.10' => 'Providing transport and airtime for follow up and testing of sexual and other contacts',
                    'T7A.11' => 'Documenting, tracking and reporting ART, PEP and PrEP among those eligible'
                ]
            ],
            'T7B' => [
                'code' => 'T7B',
                'name' => 'Transition of Patient identification and linkage to treatment: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T7B.1' => 'Recruitment of HIV testing services (HTS) counselors',
                    'T7B.2' => 'Remuneration of HIV testing counselors (Funds for paying HTS Counselors)',
                    'T7B.3' => 'Ensuring that HTS eligibility screening registers and SOPS are available',
                    'T7B.4' => 'Ensuring that HIV testing consumables/supplies are available',
                    'T7B.5' => 'Ensuring availability of adequate and appropriate HIV testing space/environment',
                    'T7B.6' => 'Ensuring effective procedures of linkage of HIV positive patients',
                    'T7B.7' => 'Ensuring documentation of linkage of HIV positive patients',
                    'T7B.8' => 'Training and providing refresher training to HIV testing counsellors',
                    'T7B.9' => 'HTS quality monitoring including conducting observed practices for HTS counsellors',
                    'T7B.10' => 'Providing transport and airtime for follow up and testing of sexual and other contacts',
                    'T7B.11' => 'Documenting, tracking and reporting ART, PEP and PrEP among those eligible'
                ]
            ]
        ]
    ],
    'retention_suppression' => [
        'title' => 'COUNTY LEVEL PATIENT RETENTION, ADHERENCE AND VIRAL SUPPRESSION SERVICES',
        'icon' => 'fa-heartbeat',
        'color' => '#5a7af8',
        'has_ip' => true,
        'indicators' => [
            'T8A' => [
                'code' => 'T8A',
                'name' => 'Transition of Patient retention, adherence and Viral suppression services: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T8A.1' => 'Provision of patient referral forms, appointment diaries and defaulter management tools to facilities',
                    'T8A.2' => 'Ensuring effective procedure to track missed appointments of patients on treatment',
                    'T8A.3' => 'Ensuring effective procedures and tracking of referrals and transfers between health facilities',
                    'T8A.4' => 'Ensuring effective procedures and tracking of referrals between different units within the same health facility',
                    'T8A.5' => 'Paying allowances to track and bring patients with missed appointments or lost to follow-up back to care',
                    'T8A.6' => 'Paying allowances to community health volunteers for HIV/TB related activities',
                    'T8A.7' => 'Supporting of patient support groups for HIV/TB related activities',
                    'T8A.8' => 'Linking facilities with community groups supporting PLHIV/TB for patient follow-up and support',
                    'T8A.9' => 'Strengthening on patient cohort analysis and reporting',
                    'T8A.10' => 'Ensure dissemination/updates of the most updated treatment guidelines',
                    'T8A.11' => 'Supporting enhanced adherence counselling for patients with poor adherence',
                    'T8A.12' => 'Supporting HIV/TB treatment optimization ensuring all cases are on an appropriate regimen',
                    'T8A.13' => 'Funding /Supporting MDT meetings to discuss difficult HIV/TB cases',
                    'T8A.14' => 'Tracking Viral suppression rates by population at site level'
                ]
            ],
            'T8B' => [
                'code' => 'T8B',
                'name' => 'Transition of Patient retention, adherence and Viral suppression services: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T8B.1' => 'Provision of patient referral forms, appointment diaries and defaulter management tools to facilities',
                    'T8B.2' => 'Ensuring effective procedure to track missed appointments of patients on treatment',
                    'T8B.3' => 'Ensuring effective procedures and tracking of referrals and transfers between health facilities',
                    'T8B.4' => 'Ensuring effective procedures and tracking of referrals between different units within the same health facility',
                    'T8B.5' => 'Paying for processes to track and bring patients with missed appointments or lost to follow-up back to care',
                    'T8B.6' => 'Funding community health volunteers and patient support groups',
                    'T8B.7' => 'Linking facilities with community groups supporting PLHIV for patient follow-up and support',
                    'T8B.8' => 'Provide funding for community visits to track patients',
                    'T8B.9' => 'Strengthening on patient cohort analysis and reporting',
                    'T8B.10' => 'Ensure dissemination/updates of the most updated treatment guidelines',
                    'T8B.11' => 'Supporting enhanced adherence counselling for patients with poor adherence',
                    'T8B.12' => 'Supporting HIV treatment optimization ensuring all cases are on an appropriate regimen',
                    'T8B.13' => 'Funding /Supporting MDT meetings to discuss difficult HIV cases',
                    'T8B.14' => 'Tracking Viral suppression rates by population at site level'
                ]
            ]
        ]
    ],
    'prevention_kp' => [
        'title' => 'COUNTY LEVEL HIV PREVENTION AND KEY POPULATION SERVICES',
        'icon' => 'fa-shield-alt',
        'color' => '#6a8aff',
        'has_ip' => true,
        'indicators' => [
            'T9A' => [
                'code' => 'T9A',
                'name' => 'Transition of HIV/TB prevention and Key population services: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T9A.1' => 'Conducting targeted HIV testing of Members of Key population groups',
                    'T9A.2' => 'Providing AGYW services for HIV prevention in safe spaces or Youth friendly settings',
                    'T9A.3' => 'Providing VMMC services for HIV prevention',
                    'T9A.4' => 'Providing condoms and lubricants to members of Key populations',
                    'T9A.5' => 'Provision of KP friendly services including provision of safe spaces',
                    'T9A.6' => 'Providing PrEP to all HIV negative clients at risk of HIV',
                    'T9A.7' => 'Provision of Post Exposure Prophylaxis',
                    'T9A.8' => 'Conducting outreach to Key population hot spots to increase enrollment',
                    'T9A.9' => 'Tracking of enrollment into HIV prevention services and outcomes in Key populations'
                ]
            ],
            'T9B' => [
                'code' => 'T9B',
                'name' => 'Transition of HIV prevention and Key population services: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T9B.1' => 'Conducting targeted HIV testing of Members of priority population groups',
                    'T9B.2' => 'Providing AGYW services for HIV prevention in safe spaces or Youth friendly settings',
                    'T9B.3' => 'Providing VMMC services for HIV prevention',
                    'T9B.4' => 'Providing condoms and lubricants to members of Key populations',
                    'T9B.5' => 'Provision of KP friendly services including provision of safe spaces',
                    'T9B.6' => 'Providing PrEP to all HIV negative clients at risk of HIV',
                    'T9B.7' => 'Provision of Post Exposure Prophylaxis',
                    'T9B.8' => 'Conducting outreach to Key population hot spots to increase enrollment',
                    'T9B.9' => 'Tracking of enrollment into HIV prevention services and outcomes by populations'
                ]
            ]
        ]
    ],
    'finance' => [
        'title' => 'COUNTY LEVEL FINANCE MANAGEMENT',
        'icon' => 'fa-coins',
        'color' => '#7a9aff',
        'has_ip' => true,
        'indicators' => [
            'T10A' => [
                'code' => 'T10A',
                'name' => 'Transition of Financial Management: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T10A.1' => 'Preparing an annual county budget which integrates HIV care & treatment',
                    'T10A.2' => 'Allocating available program resources',
                    'T10A.3' => 'Tracking program expenditures and income',
                    'T10A.4' => 'Producing financial reports',
                    'T10A.5' => 'Reallocating funding to respond to budget variances and program needs',
                    'T10A.6' => 'Conducts external audit',
                    'T10A.7' => 'Responding to audits/reviews',
                    'T10A.8' => 'Funding the overall county HIV/TB response (HIV/TB funding for the past 5 years)',
                    'T10A.9' => 'Reducing the HIV/TB response funding as a result of the county?s domestic resource mobilization (HIV/TB funding for the last FY)'
                ]
            ],
            'T10B' => [
                'code' => 'T10B',
                'name' => 'Transition of Financial Management: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T10B.1' => 'Preparing an annual county budget which integrates HIV care & treatment',
                    'T10B.2' => 'Allocating available program resources',
                    'T10B.3' => 'Tracking program expenditures and income',
                    'T10B.4' => 'Producing financial reports',
                    'T10B.5' => 'Reallocating funding to respond to budget variances and program needs',
                    'T10B.6' => 'Conducts external audit',
                    'T10B.7' => 'Responding to audits/reviews',
                    'T10B.8' => 'Funding the overall county HIV/TB response (HIV/TB funding for the past 5 years)',
                    'T10B.9' => 'Reducing the HIV/TB response funding as a result of the county?s domestic resource mobilization (HIV/TB funding for the last FY)'
                ]
            ]
        ]
    ],
    'sub_grants' => [
        'title' => 'COUNTY LEVEL MANAGING SUB-GRANTS OR OTHER GRANTS/COOPERATIVE AGREEMENTS',
        'icon' => 'fa-file-invoice',
        'color' => '#8a5cf6',
        'has_ip' => true,
        'indicators' => [
            'T11A' => [
                'code' => 'T11A',
                'name' => 'Transition of Managing Sub-Grants: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T11A.1' => 'Defining the TOR for last/renewed sub-grant',
                    'T11A.2' => 'Planning and developing the budget',
                    'T11A.3' => 'Managing the competitive bidding process for procurements/purchases',
                    'T11A.4' => 'Tracking sub-grant expenditures',
                    'T11A.5' => 'Disbursing funds for procurements/purchases',
                    'T11A.6' => 'Reporting results'
                ]
            ],
            'T11B' => [
                'code' => 'T11B',
                'name' => 'Transition of Managing Sub-Grants: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T11B.1' => 'Defining the TOR for last/renewed sub-grant',
                    'T11B.2' => 'Planning and developing the budget',
                    'T11B.3' => 'Managing the competitive bidding process for procurements/purchases',
                    'T11B.4' => 'Tracking sub-grant expenditures',
                    'T11B.5' => 'Disbursing funds for procurements/purchases',
                    'T11B.6' => 'Reporting results'
                ]
            ]
        ]
    ],
    'commodities' => [
        'title' => 'COUNTY LEVEL COMMODITIES MANAGEMENT',
        'icon' => 'fa-boxes',
        'color' => '#9b6cf6',
        'has_ip' => true,
        'indicators' => [
            'T12A' => [
                'code' => 'T12A',
                'name' => 'Transition of Commodities Management: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T12A.1' => 'Developing/adapting commodity supply chain SOPs',
                    'T12A.2' => 'Monitoring consumption of ARVs, anti-TB drugs, Cotrimoxazole, HIV test kits, phlebotomy supplies, cryovials for HIV VL, DBS bundles, GeneXpert catrigdes, sputum mugs (other specific laboratory commodities?)',
                    'T12A.3' => 'Monthly commodities reporting',
                    'T12A.4' => 'Building capacity/training of HF pharmacy and laboratory staff in commodity management',
                    'T12A.5' => 'Managing commodity storage spaces within the facilities',
                    'T12A.6' => 'Submitting stock orders to National level supply chain organization e.g. NASCOP, KEMSA, etc',
                    'T12A.7' => 'Distributing supplies to testing sites, treatment facilities and labs'
                ]
            ],
            'T12B' => [
                'code' => 'T12B',
                'name' => 'Transition of Commodities Management: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T12B.1' => 'Developing/adapting commodity supply chain SOPs',
                    'T12B.2' => 'Monitoring consumption of ARVs, anti-TB drugs, Cotrimoxazole, HIV test kits, phlebotomy supplies, cryovials for HIV VL, DBS bundles, GeneXpert catrigdes, sputum mugs (other specific laboratory commodities?)',
                    'T12B.3' => 'Monthly commodities reporting',
                    'T12B.4' => 'Building capacity/training of HF pharmacy and laboratory staff in commodity management',
                    'T12B.5' => 'Managing commodity storage spaces within the facilities',
                    'T12B.6' => 'Submitting stock orders to National level supply chain organization e.g. NASCOP, KEMSA, etc',
                    'T12B.7' => 'Distributing supplies to testing sites, treatment facilities and labs'
                ]
            ]
        ]
    ],
    'equipment' => [
        'title' => 'COUNTY LEVEL EQUIPMENT PROCUREMENT AND USE',
        'icon' => 'fa-tools',
        'color' => '#ac7cf6',
        'has_ip' => true,
        'indicators' => [
            'T13A' => [
                'code' => 'T13A',
                'name' => 'Transition of Equipment Procurement and Use: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T13A.1' => 'Determining the need for key HIV/TB specific equipment (Fridges, freezers, centrifuges, Biosafety cabinets, CD4 machines, Gene Xpert machines, etc.)',
                    'T13A.2' => 'Establishing equipment quantification and need based Prioritization of equipment',
                    'T13A.3' => 'Development of specifications, ordering/procuring equipments',
                    'T13A.4' => 'Funding equipment procurement',
                    'T13A.5' => 'Maintaining and calibrating/certifying equipments',
                    'T13A.6' => 'Equipment inventory, supervising and training use of equipments'
                ]
            ],
            'T13B' => [
                'code' => 'T13B',
                'name' => 'Transition of Procurement and Use: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T13B.1' => 'Determining the need for key HIV/TB specific equipment (Fridges, freezers, centrifuges, Biosafety cabinets, CD4 machines, Gene Xpert machines, etc.)',
                    'T13B.2' => 'Establishing equipment quantification and need based Prioritization of equipment',
                    'T13B.3' => 'Development of specifications, ordering/procuring equipments',
                    'T13B.4' => 'Funding equipment procurement',
                    'T13B.5' => 'Maintaining and calibrating/certifying equipments',
                    'T13B.6' => 'Equipment inventory, supervising and training use of equipments'
                ]
            ]
        ]
    ],
    'laboratory' => [
        'title' => 'COUNTY LEVEL LABORATORY SERVICES',
        'icon' => 'fa-flask',
        'color' => '#bd8cf6',
        'has_ip' => true,
        'indicators' => [
            'T14A' => [
                'code' => 'T14A',
                'name' => 'Transition of Laboratory Services: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T14A.1' => 'Distributing QC proficiency testing panels and proficiency testing kits (GeneXpert and RHT)',
                    'T14A.2' => 'Re-Distributing EQA proficiency testing panels and proficiency testing kits (GeneXpert and RHT)',
                    'T14A.3' => 'Compiling and reporting on proficiency testing results',
                    'T14A.4' => 'Conducting supervision/CAPA visits to laboratories',
                    'T14A.5' => 'Training laboratory and HTS staff on good practices',
                    'T14A.6' => 'Ordering laboratory reagents',
                    'T14A.7' => 'Ordering laboratory consumables',
                    'T14A.8' => 'Funding and Managing specimen transport systems (CD4, EID, VL, Gene Xpert, DST)',
                    'T14A.9' => 'Monitoring TAT for test results (CD4, EID, VL, Gene Xpert, DST)',
                    'T14A.10' => 'Implementing laboratory quality management systems (QMS) for HIV/TB',
                    'T14A.11' => 'Supporting biosafety activities and health care waste management',
                    'T14A.12' => 'Supporting service and maintenance contracts for laboratory equipment'
                ]
            ],
            'T14B' => [
                'code' => 'T14B',
                'name' => 'Transition of Laboratory Services: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T14B.1' => 'Distributing QC proficiency testing panels and proficiency testing kits (GeneXpert and RHT)',
                    'T14B.2' => 'Re-Distributing EQA proficiency testing panels and proficiency testing kits (GeneXpert and RHT)',
                    'T14B.3' => 'Compiling and reporting on proficiency testing results',
                    'T14B.4' => 'Conducting supervision/CAPA visits to laboratories',
                    'T14B.5' => 'Training laboratory and HTS staff on good practices',
                    'T14B.6' => 'Ordering laboratory reagents',
                    'T14B.7' => 'Ordering laboratory consumables',
                    'T14B.8' => 'Funding and Managing specimen transport systems (CD4, EID, VL, Gene Xpert, DST)',
                    'T14B.9' => 'Monitoring TAT for test results (CD4, EID, VL, Gene Xpert, DST)',
                    'T14B.10' => 'Implementing laboratory quality management systems (QMS) for HIV/TB',
                    'T14B.11' => 'Supporting biosafety activities and health care waste management',
                    'T14B.12' => 'Supporting service and maintenance contracts for laboratory equipment'
                ]
            ]
        ]
    ],
    'inventory' => [
        'title' => 'COUNTY LEVEL INVENTORY MANAGEMENT',
        'icon' => 'fa-clipboard-list',
        'color' => '#ce9cf6',
        'has_ip' => true,
        'indicators' => [
            'T15A' => [
                'code' => 'T15A',
                'name' => 'Transition of Inventory Management for Equipment & Commodities: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T15A.1' => 'Needs determination function which develops quantity and resource requirements, consisting of Inventory Planning and Budgeting',
                    'T15A.2' => 'Inventory in storage function including Receipt and Inspection process, and Storing process ? (verify Ordering and Commodities/ Stores list updated on transactional basis)',
                    'T15A.3' => 'Inventory Disposition Function including Loaning, Issuing and, Disposal Processes ? (Check USG Assets & Equipment Disposal Guidelines)',
                    'T15A.4' => 'Program monitoring function of Inventory control which provides sufficient transaction audit trails to support balances of inventory on the IP?s General Ledger ? (verify annual Assets Inventory Audit Report)',
                    'T15A.5' => 'Designated qualified and certified Supply Chain Management professional and, membership',
                    'T15A.6' => 'Oversight Supervision of the Inventory Management functions'
                ]
            ],
            'T15B' => [
                'code' => 'T15B',
                'name' => 'Transition of Inventory Management for Equipment & Commodities: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T15B.1' => 'Needs determination function which develops quantity and resource requirements, consisting of Inventory Planning and Budgeting',
                    'T15B.2' => 'Inventory in storage function including Receipt and Inspection process, and Storing process ? (verify Ordering and Commodities/ Stores list updated on transactional basis)',
                    'T15B.3' => 'Inventory Disposition Function including Loaning, Issuing and, Disposal Processes ? (Check USG Assets & Equipment Disposal Guidelines)',
                    'T15B.4' => 'Program monitoring function of Inventory control which provides sufficient transaction audit trails to support balances of inventory on the IP?s General Ledger ? (verify annual Assets Inventory Audit Report)',
                    'T15B.5' => 'Designated qualified and certified Supply Chain Management professional and, membership',
                    'T15B.6' => 'Oversight Supervision of the Inventory Management functions'
                ]
            ]
        ]
    ],
    'training' => [
        'title' => 'COUNTY LEVEL IN-SERVICE TRAINING',
        'icon' => 'fa-chalkboard-teacher',
        'color' => '#dfacf6',
        'has_ip' => true,
        'indicators' => [
            'T16A' => [
                'code' => 'T16A',
                'name' => 'Transition of In-service Training: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T16A.1' => 'Assessing staff training needs',
                    'T16A.2' => 'Selecting/adapting curricula',
                    'T16A.3' => 'Planning training schedule',
                    'T16A.4' => 'Arranging/funding/providing training venue',
                    'T16A.5' => 'Providing or paying trainers/facilitators',
                    'T16A.6' => 'Paying participant per diem',
                    'T16A.7' => 'Use of integrated human resource information system (iHRIS Train)'
                ]
            ],
            'T16B' => [
                'code' => 'T16B',
                'name' => 'Transition of In-service Training: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T16B.1' => 'Assessing staff training needs',
                    'T16B.2' => 'Selecting/adapting curricula',
                    'T16B.3' => 'Planning training schedule',
                    'T16B.4' => 'Arranging/funding/providing training venue',
                    'T16B.5' => 'Providing or paying trainers/facilitators',
                    'T16B.6' => 'Paying participant per diem',
                    'T16B.7' => 'Use of integrated human resource information system (iHRIS Train)'
                ]
            ]
        ]
    ],
    'hr_management' => [
        'title' => 'COUNTY LEVEL HUMAN RESOURCE MANAGEMENT',
        'icon' => 'fa-users',
        'color' => '#f0bcf6',
        'has_ip' => true,
        'indicators' => [
            'T17A' => [
                'code' => 'T17A',
                'name' => 'Transition of HIV/TB Human Resource Management: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T17A.1' => 'Presence of active of county public service board that recruits HIV/TB services staff (check: Gazette notice, minutes of meeting proceedings)',
                    'T17A.2' => 'Determining staffing needs for the HIV/TB program',
                    'T17A.3' => 'Advertising/posting positions for the HIV/TB program',
                    'T17A.4' => 'Shortlisting/interviewing candidates for the HIV/TB program',
                    'T17A.5' => 'Performance appraisal for the HIV/TB program',
                    'T17A.6' => 'Paying staff salaries for the HIV/TB program',
                    'T17A.7' => 'Appointing HIV/TB program staff (recruitment)',
                    'T17A.8' => 'Absorbing previously IP recruited staff through the county public service board (transitioned staff)',
                    'T17A.9' => 'Supporting facilities to effectively utilize the few available staff to execute health facility roles e.g. development of task shifting plans at health facilities',
                    'T17A.10' => 'Use of integrated human resource information system (iHRS) (government HRH management and development)'
                ]
            ],
            'T17B' => [
                'code' => 'T17B',
                'name' => 'Transition of HIV/TB Human Resource Management: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T17B.1' => 'Presence of active of county public service board that recruits HIV/TB services staff (check: Gazette notice, minutes of meeting proceedings)',
                    'T17B.2' => 'Determining staffing needs for the HIV/TB program',
                    'T17B.3' => 'Advertising/posting positions for the HIV/TB program',
                    'T17B.4' => 'Shortlisting/interviewing candidates for the HIV/TB program',
                    'T17B.5' => 'Performance appraisal for the HIV/TB program',
                    'T17B.6' => 'Paying staff salaries for the HIV/TB program',
                    'T17B.7' => 'Appointing HIV/TB program staff (recruitment)',
                    'T17B.8' => 'Absorbing previously IP recruited staff through the county public service board (transitioned staff)',
                    'T17B.9' => 'Supporting facilities to effectively utilize the few available staff to execute health facility roles e.g. development of task shifting plans at health facilities',
                    'T17B.10' => 'Use of integrated human resource information system (iHRS) (government HRH management and development)'
                ]
            ]
        ]
    ],
    'data_management' => [
        'title' => 'COUNTY LEVEL HIV/TB PROGRAM DATA MANAGEMENT',
        'icon' => 'fa-database',
        'color' => '#0ABFBC',
        'has_ip' => true,
        'indicators' => [
            'T18A' => [
                'code' => 'T18A',
                'name' => 'Transition of HIV/TB Program Data Management: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T18A.1' => 'Collecting and entering data from facilities in to DHIS2',
                    'T18A.2' => 'Collecting and entering data from facilities in to DATIM',
                    'T18A.3' => 'Checking completeness and accuracy',
                    'T18A.4' => 'Conduct DQA on regular basis',
                    'T18A.5' => 'Giving feedback and support to facilities for data quality',
                    'T18A.6' => 'Analyzing data and producing reports sent to MOH',
                    'T18A.7' => 'Monitoring results and determining remedial actions',
                    'T18A.8' => 'Managing IT infrastructure for HIV/TB data management',
                    'T18A.9' => 'Training & mentorship of health facility staff in data management'
                ]
            ],
            'T18B' => [
                'code' => 'T18B',
                'name' => 'Transition of HIV/TB Program Data Management: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T18B.1' => 'Collecting and entering data from facilities in to DHIS2',
                    'T18B.2' => 'Collecting and entering data from facilities in to DATIM',
                    'T18B.3' => 'Checking completeness and accuracy',
                    'T18B.4' => 'Conduct DQA on regular basis',
                    'T18B.5' => 'Giving feedback and support to facilities for data quality',
                    'T18B.6' => 'Analyzing data and producing reports sent to MOH',
                    'T18B.7' => 'Monitoring results and determining remedial actions',
                    'T18B.8' => 'Managing IT infrastructure for HIV/TB data management',
                    'T18B.9' => 'Training & mentorship of health facility staff in data management'
                ]
            ]
        ]
    ],
    'patient_monitoring' => [
        'title' => 'COUNTY LEVEL PATIENT MONITORING SYSTEM',
        'icon' => 'fa-chart-pie',
        'color' => '#27AE60',
        'has_ip' => true,
        'indicators' => [
            'T19A' => [
                'code' => 'T19A',
                'name' => 'Transition of Patient Monitoring System: Level of Involvement of the IP',
                'sub_indicators' => [
                    'T19A.1' => 'Providing patient monitoring system/tools',
                    'T19A.2' => 'Entering patient data into the system/tools',
                    'T19A.3' => 'Checking completeness and accuracy',
                    'T19A.4' => 'Analyzing data and producing reports',
                    'T19A.5' => 'Tracking overall county lost-to-follow up, transfer, death & retention rates',
                    'T19A.6' => 'Managing Electronic Medical Record systems for patient monitoring',
                    'T19A.7' => 'Training Health facility staff in monitoring, evaluation & reporting (at least 2 of the three)'
                ]
            ],
            'T19B' => [
                'code' => 'T19B',
                'name' => 'Transition of Patient Monitoring System: Level of Autonomy of the CDOH',
                'sub_indicators' => [
                    'T19B.1' => 'Providing patient monitoring system/tools',
                    'T19B.2' => 'Entering patient data into the system/tools',
                    'T19B.3' => 'Checking completeness and accuracy',
                    'T19B.4' => 'Analyzing data and producing reports',
                    'T19B.5' => 'Tracking overall county lost-to-follow up, transfer, death & retention rates',
                    'T19B.6' => 'Managing Electronic Medical Record systems for patient monitoring',
                    'T19B.7' => 'Training Health facility staff in monitoring, evaluation & reporting (at least 2 of the three)'
                ]
            ]
        ]
    ],
    'institutional_ownership' => [
        'title' => 'COUNTY LEVEL INSTITUTIONAL OWNERSHIP INDICATOR',
        'icon' => 'fa-building',
        'color' => '#F5A623',
        'has_ip' => false, // IO indicators are CDOH only
        'indicators' => [
            'IO1' => [
                'code' => 'IO1',
                'name' => 'Operationalization of national HIV/TB plan at institutional level',
                'sub_indicators' => [
                    'IO1.1' => 'Does the county routinely develop HIV/TB AWPs that are based on the CIDP?',
                    'IO1.2' => 'Has the county costed its HIV/TB AWP and integrated it with the last national budget request?',
                    'IO1.3' => 'Are different levels of HIV/TB treatment staff involved in the development of the HIV/TB AWP?',
                    'IO1.4' => 'Are stakeholders from HIV/TB programs and PLHIV/TB involved in the development of HIV/TB AWPs?',
                    'IO1.5' => 'Is the implementation of the county HIV/TB work plan monitored and tracked by the County health team?'
                ]
            ],
            'IO2' => [
                'code' => 'IO2',
                'name' => 'Institutional coordination of HIV/TB prevention, care and treatment activities',
                'sub_indicators' => [
                    'IO2.1' => 'Does the CDOH have a list of all active HIV/TB services CSOs and implementing partners in the county with contact information?',
                    'IO2.2' => 'Does the county provide a functional forum for experience exchange on at least a quarterly basis?',
                    'IO2.3' => 'Does the county disseminate information, standards and best practices to implementers and stakeholders in a timely manner?',
                    'IO2.4' => 'Does the county work to ensure a rational geographic distribution, program coverage and scale-up of HIV/TB services?'
                ]
            ],
            'IO3' => [
                'code' => 'IO3',
                'name' => 'Congruence of expectations between levels of the health system',
                'sub_indicators' => [
                    'IO3.1' => 'Is the county strategic plan aligned to the National HIV/TB framework developed by NACC?',
                    'IO3.2' => 'Does the county team perceive the national framework for HIV/TB care and treatment programs is relevant to their county needs?',
                    'IO3.3' => 'Is the policy formulation and capacity building functions of NACC/NASCOP to the county helpful in resolving implementation challenges?',
                    'IO3.4' => 'Is the county team aware of its HIV/TB program service targets? If yes, are they using this data to inform annual HIV/TB plans?',
                    'IO3.5' => 'Does the county team perceive that the HIV service targets/objectives expected of their county are realistic?',
                    'IO3.6' => 'Is the financial grant from the national level adequate to meet the HIV/TB service targets expected of the county team?'
                ]
            ]
        ]
    ]
];

// Filter sections based on selection
$active_sections = array_intersect_key($all_sections, array_flip($sections));

// -- Handle SECTION save (AJAX per-section) ------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_section'])) {
    $section_key     = mysqli_real_escape_string($conn, $_POST['section_key'] ?? '');
    $assessed_by     = mysqli_real_escape_string($conn, $_SESSION['full_name'] ?? '');
    $assessment_date = mysqli_real_escape_string($conn, $_POST['assessment_date'] ?? date('Y-m-d'));

    mysqli_begin_transaction($conn);
    try {
        // Create or reuse assessment record
        if (!$assessment_id) {
            $ex = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT assessment_id FROM transition_assessments
                 WHERE county_id=$county_id AND assessment_period='$period' LIMIT 1"));
            if ($ex) {
                $assessment_id = (int)$ex['assessment_id'];
            } else {
                mysqli_query($conn,
                    "INSERT INTO transition_assessments
                     (county_id, assessment_period, assessment_date, assessed_by, assessment_status)
                     VALUES ($county_id,'$period','$assessment_date','$assessed_by','draft')");
                $assessment_id = (int)mysqli_insert_id($conn);
            }
        }

        // Delete existing raw scores for this section only
        mysqli_query($conn,
            "DELETE FROM transition_raw_scores
             WHERE assessment_id=$assessment_id AND section_key='$section_key'");

        $saved = 0;
        $sum_cdoh = 0; $cnt_cdoh = 0;
        $sum_ip   = 0; $cnt_ip   = 0;

        // Scores arrive as scores[section_indicator_subcode][cdoh/ip/comments]
        if (!empty($_POST['scores'])) {
            foreach ($_POST['scores'] as $composite_key => $vals) {
                $ck_safe  = mysqli_real_escape_string($conn, $composite_key);
                // Parse composite key e.g. "leadership_T1_T1.1"
                $parts    = explode('_', $composite_key);
                $sub_code = end($parts);                          // T1.1
                $sub_safe = mysqli_real_escape_string($conn, $sub_code);
                $ind_code = preg_replace('/\.\d+$/', '', $sub_code); // T1
                $ind_safe = mysqli_real_escape_string($conn, $ind_code);

                $cdoh  = isset($vals['cdoh']) && $vals['cdoh'] !== '' ? (int)$vals['cdoh'] : 'NULL';
                $ip    = isset($vals['ip'])   && $vals['ip']   !== '' ? (int)$vals['ip']   : 'NULL';
                $comm  = mysqli_real_escape_string($conn, $vals['comments'] ?? '');

                if ($cdoh === 'NULL' && $ip === 'NULL') continue;

                mysqli_query($conn,
                    "INSERT INTO transition_raw_scores
                     (assessment_id, section_key, indicator_code, sub_indicator_code,
                      composite_key, cdoh_score, ip_score, comments, scored_by)
                     VALUES ($assessment_id,'$section_key','$ind_safe','$sub_safe',
                             '$ck_safe',$cdoh,$ip,'$comm','$assessed_by')
                     ON DUPLICATE KEY UPDATE
                       cdoh_score=VALUES(cdoh_score), ip_score=VALUES(ip_score),
                       comments=VALUES(comments), scored_at=NOW()");
                $saved++;
                if ($cdoh !== 'NULL') { $sum_cdoh += $cdoh; $cnt_cdoh++; }
                if ($ip   !== 'NULL') { $sum_ip   += $ip;   $cnt_ip++;   }
            }
        }

        $avg_c_val = $cnt_cdoh > 0 ? round($sum_cdoh/$cnt_cdoh, 2) : 'NULL';
        $avg_i_val = $cnt_ip   > 0 ? round($sum_ip/$cnt_ip,     2) : 'NULL';

        // Upsert section submission record
        mysqli_query($conn,
            "INSERT INTO transition_section_submissions
             (assessment_id, section_key, submitted_by, sub_count, avg_cdoh, avg_ip)
             VALUES ($assessment_id,'$section_key','$assessed_by',$saved,$avg_c_val,$avg_i_val)
             ON DUPLICATE KEY UPDATE
               submitted_by='$assessed_by', submitted_at=NOW(),
               sub_count=$saved, avg_cdoh=$avg_c_val, avg_ip=$avg_i_val");

        // Mark assessment as draft with updated readiness
        $ov = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT AVG(cdoh_percent) oc, AVG(ip_percent) oi
             FROM transition_section_submissions WHERE assessment_id=$assessment_id"));
        $oc = $ov['oc'] !== null ? round((float)$ov['oc']) : 0;
        $rd = $oc>=70?'Transition':($oc>=50?'Support and Monitor':'Not Ready');
        mysqli_query($conn,
            "UPDATE transition_assessments SET assessment_status='draft', readiness_level='$rd'
             WHERE assessment_id=$assessment_id");

        mysqli_commit($conn);

        echo json_encode([
            'success'        => true,
            'assessment_id'  => $assessment_id,
            'section_key'    => $section_key,
            'saved'          => $saved,
            'submitted_at'   => date('d M Y H:i'),
            'avg_cdoh_pct'   => $avg_c_val !== 'NULL' ? round((float)$avg_c_val/4*100) : 0,
            'avg_ip_pct'     => $avg_i_val !== 'NULL' ? round((float)$avg_i_val/4*100) : 0,
        ]);
        exit();

    } catch (Exception $e) {
        mysqli_rollback($conn);
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
        exit();
    }
}

// -- Handle full SUBMIT ALL (final) --------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assessment'])) {
    $assessed_by = mysqli_real_escape_string($conn, $_SESSION['full_name'] ?? '');
    $assessment_date = mysqli_real_escape_string($conn, $_POST['assessment_date'] ?? date('Y-m-d'));

    // Begin transaction
    mysqli_begin_transaction($conn);

    try {
        // Create new assessment or update existing
        if ($assessment_id) {
            $update_query = "UPDATE transition_assessments SET
                assessment_date = '$assessment_date',
                assessed_by = '$assessed_by',
                assessment_status = 'submitted'
                WHERE assessment_id = $assessment_id";
            if (!mysqli_query($conn, $update_query)) {
                throw new Exception("Error updating assessment: " . mysqli_error($conn));
            }
            // (transition_scores dropped — raw scores in transition_raw_scores)
        } else {
            $insert_query = "INSERT INTO transition_assessments
                (county_id, assessment_period, assessment_date, assessed_by, assessment_status)
                VALUES ($county_id, '$period', '$assessment_date', '$assessed_by', 'submitted')";
            if (!mysqli_query($conn, $insert_query)) {
                throw new Exception("Error creating assessment: " . mysqli_error($conn));
            }
            $assessment_id = mysqli_insert_id($conn);
        }

        // Save raw scores + upsert section submissions from POST
        $total_cdoh = 0;
        $total_ip = 0;
        $indicator_count = 0;
        $section_agg = []; // [section_key => [sum_cdoh,cnt_cdoh,sum_ip,cnt_ip,count]]

        foreach ($_POST['scores'] as $composite_key => $scores) {
            $parts         = explode('_', $composite_key);
            $sub_code      = end($parts);
            $ind_code      = preg_replace('/\.\d+$/', '', $sub_code);
            $section_key_p = $parts[0] ?? '';

            $cdoh_score = isset($scores['cdoh']) && $scores['cdoh'] !== '' ? (int)$scores['cdoh'] : null;
            $ip_score   = isset($scores['ip'])   && $scores['ip']   !== '' ? (int)$scores['ip']   : null;
            $comments   = mysqli_real_escape_string($conn, $scores['comments'] ?? '');

            if ($cdoh_score === null && $ip_score === null) continue;

            // Save raw score
            $ck_safe  = mysqli_real_escape_string($conn, $composite_key);
            $sub_safe = mysqli_real_escape_string($conn, $sub_code);
            $ind_safe = mysqli_real_escape_string($conn, $ind_code);
            $sk_safe  = mysqli_real_escape_string($conn, $section_key_p);
            $cdoh_sql = $cdoh_score !== null ? $cdoh_score : 'NULL';
            $ip_sql   = $ip_score   !== null ? $ip_score   : 'NULL';
            $by_safe  = mysqli_real_escape_string($conn, $_SESSION['full_name'] ?? '');
            mysqli_query($conn,
                "INSERT INTO transition_raw_scores
                 (assessment_id, section_key, indicator_code, sub_indicator_code,
                  composite_key, cdoh_score, ip_score, comments, scored_by)
                 VALUES ($assessment_id,'$sk_safe','$ind_safe','$sub_safe',
                         '$ck_safe',$cdoh_sql,$ip_sql,'$comments','$by_safe')
                 ON DUPLICATE KEY UPDATE
                   cdoh_score=VALUES(cdoh_score),ip_score=VALUES(ip_score),
                   comments=VALUES(comments),scored_at=NOW()");

            // Accumulate for section submissions upsert
            if (!isset($section_agg[$section_key_p]))
                $section_agg[$section_key_p] = ['sum_c'=>0,'cnt_c'=>0,'sum_i'=>0,'cnt_i'=>0,'n'=>0];
            if ($cdoh_score !== null) { $section_agg[$section_key_p]['sum_c']+=$cdoh_score; $section_agg[$section_key_p]['cnt_c']++; }
            if ($ip_score   !== null) { $section_agg[$section_key_p]['sum_i']+=$ip_score;   $section_agg[$section_key_p]['cnt_i']++; }
            $section_agg[$section_key_p]['n']++;

            if ($cdoh_score !== null) $total_cdoh += $cdoh_score;
            if ($ip_score   !== null) $total_ip   += $ip_score;
            $indicator_count++;
        }

        // Upsert section_submissions for each section found in POST
        $period_safe   = mysqli_real_escape_string($conn, $period);
        $assessed_safe = mysqli_real_escape_string($conn, $assessed_by);
        foreach ($section_agg as $sk_p => $agg) {
            $sk_p_safe  = mysqli_real_escape_string($conn, $sk_p);
            $avg_c_full = $agg['cnt_c'] > 0 ? round($agg['sum_c']/$agg['cnt_c'], 2) : 'NULL';
            $avg_i_full = $agg['cnt_i'] > 0 ? round($agg['sum_i']/$agg['cnt_i'], 2) : 'NULL';
            mysqli_query($conn,
                "INSERT INTO transition_section_submissions
                 (assessment_id, county_id, assessment_period, section_key,
                  submitted_by, sub_count, avg_cdoh, avg_ip)
                 VALUES ($assessment_id,$county_id,'$period_safe','$sk_p_safe',
                         '$assessed_safe',{$agg['n']},$avg_c_full,$avg_i_full)
                 ON DUPLICATE KEY UPDATE
                   county_id=$county_id, assessment_period='$period_safe',
                   submitted_by='$assessed_safe', submitted_at=NOW(),
                   sub_count={$agg['n']}, avg_cdoh=$avg_c_full, avg_ip=$avg_i_full");
        }

        $avg_cdoh  = $indicator_count > 0 ? round(($total_cdoh / ($indicator_count * 4)) * 100) : 0;
        $avg_ip    = $indicator_count > 0 ? round(($total_ip   / ($indicator_count * 4)) * 100) : 0;
        $readiness = $avg_cdoh >= 70 ? 'Transition' : ($avg_cdoh >= 50 ? 'Support and Monitor' : 'Not Ready');

        // Mark assessment as submitted (scores live in transition_section_submissions)
        if (!mysqli_query($conn,
            "UPDATE transition_assessments SET
             assessment_status='submitted', readiness_level='$readiness'
             WHERE assessment_id=$assessment_id")) {
            throw new Exception("Error updating assessment status: " . mysqli_error($conn));
        }

        mysqli_commit($conn);
        $_SESSION['success_msg'] = 'Assessment saved successfully!';
        header('Location: transition_dashboard.php?county=' . $county_id);
        exit();

    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = 'Error saving assessment: ' . $e->getMessage();
    }
}

// Calculate total indicators for progress tracking
$total_indicators = 0;
foreach ($active_sections as $section) {
    foreach ($section['indicators'] as $indicator) {
        $total_indicators += count($indicator['sub_indicators']);
    }
}

// Build submitted sections data for JS
$submitted_sections_json = json_encode($submitted_sections);
$assessment_id_js = (int)$assessment_id;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transition Assessment - <?= htmlspecialchars($county_name) ?></title>
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
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
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

        .progress-tracker {
            background: #fff;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-tabs {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .section-tab {
            padding: 10px 20px;
            background: #fff;
            border-radius: 30px;
            border: 2px solid #e0e4f0;
            font-size: 13px;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            white-space: nowrap;
            transition: all .2s;
        }

        .section-tab.active {
            background: #0D1A63;
            color: #fff;
            border-color: #0D1A63;
        }

        .section-tab.completed {
            border-color: #28a745;
            background: #d4edda;
            color: #155724;
        }

        .assessment-form {
            background: #fff;
            border-radius: 14px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 14px rgba(0,0,0,.07);
        }

        /* Indicator Card Styles */
        .indicator-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border-left: 4px solid var(--color);
        }

        .indicator-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .indicator-code {
            background: #0D1A63;
            color: #fff;
            padding: 5px 15px;
            border-radius: 30px;
            font-weight: 700;
            font-size: 14px;
        }

        .indicator-title {
            font-size: 16px;
            font-weight: 700;
            color: #0D1A63;
            margin-bottom: 15px;
        }

        .sub-indicator {
            background: #fff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #e0e4f0;
        }

        .sub-indicator-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .sub-indicator-code {
            font-weight: 700;
            color: #0D1A63;
            background: #e8edf8;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
        }

        .sub-indicator-text {
            font-size: 13px;
            color: #555;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .score-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .score-column {
            background: #f8f9fc;
            border-radius: 8px;
            padding: 15px;
        }

        .score-column h4 {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .score-column.cdoh h4 { color: #0D1A63; }
        .score-column.ip h4 { color: #FFC107; }

        .radio-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .radio-option {
            flex: 1;
            min-width: 60px;
        }

        .radio-option input[type="radio"] {
            display: none;
        }

        .radio-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 5px;
            background: #fff;
            border: 2px solid #e0e4f0;
            border-radius: 8px;
            cursor: pointer;
            transition: all .2s;
        }

        .radio-option input[type="radio"]:checked + label {
            border-color: var(--color);
            background: var(--bg-color);
        }

        .radio-option .score {
            font-weight: 700;
            font-size: 16px;
        }

        .radio-option .label {
            font-size: 9px;
            text-align: center;
            color: #666;
            margin-top: 3px;
        }

        .level-4 { --color: #28a745; --bg-color: #d4edda; }
        .level-3 { --color: #17a2b8; --bg-color: #d1ecf1; }
        .level-2 { --color: #ffc107; --bg-color: #fff3cd; }
        .level-1 { --color: #fd7e14; --bg-color: #ffe5d0; }
        .level-0 { --color: #dc3545; --bg-color: #f8d7da; }

        .comments-section {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px dashed #e0e4f0;
        }

        .comments-section textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e0e4f0;
            border-radius: 8px;
            font-size: 12px;
            resize: vertical;
        }

        .section-summary {
            background: #0D1A63;
            color: #fff;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .summary-badge {
            background: rgba(255,255,255,.2);
            padding: 5px 15px;
            border-radius: 30px;
            font-weight: 600;
        }

        .save-progress {
            position: sticky;
            bottom: 20px;
            background: #0D1A63;
            color: #fff;
            padding: 15px 25px;
            border-radius: 50px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(13,26,99,.3);
            max-width: 400px;
            margin: 0 auto;
        }

        .btn-save {
            background: #fff;
            color: #0D1A63;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            cursor: pointer;
            transition: all .2s;
        }
        .btn-save:hover {
            transform: scale(1.05);
        }

        .btn-submit {
            background: #28a745;
            color: #fff;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: all .2s;
            display: block;
            margin: 30px auto;
        }
        .btn-submit:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .progress-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .progress-bar {
            width: 200px;
            height: 8px;
            background: #e0e4f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: #28a745;
            border-radius: 10px;
            transition: width 0.3s;
        }

        .ip-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 10px;
        }
        .ip-badge.yes {
            background: #FFC107;
            color: #000;
        }
        .ip-badge.no {
            background: #6c757d;
            color: #fff;
        }

        /* -- Section save button -- */
        .section-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid #e0e4f0;
        }
        .btn-save-section {
            background: #0D1A63;
            color: #fff;
            border: none;
            padding: 11px 26px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-save-section:hover { background: #1a3a9e; transform: translateY(-1px); }
        .btn-save-section.saving { opacity: .65; cursor: wait; }

        /* Submitted tag inside section header */
        .submitted-tag {
            background: rgba(39,174,96,.25);
            border: 1px solid rgba(39,174,96,.4);
            color: #d4edda;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        /* -- Already-submitted Modal -- */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.show { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 16px;
            padding: 34px 32px;
            max-width: 460px;
            width: 92%;
            box-shadow: 0 24px 64px rgba(0,0,0,.22);
            text-align: center;
        }
        .modal-icon { font-size: 52px; margin-bottom: 14px; }
        .modal-box h3 { font-size: 20px; font-weight: 800; color: #0D1A63; margin-bottom: 10px; }
        .modal-box p  { color: #555; font-size: 14px; line-height: 1.6; margin-bottom: 6px; }
        .modal-info {
            background: #f0f4ff;
            border-radius: 10px;
            padding: 12px 16px;
            margin: 14px 0;
            font-size: 13px;
            text-align: left;
            line-height: 1.8;
        }
        .modal-info strong { color: #0D1A63; }
        .modal-actions { display: flex; gap: 12px; justify-content: center; margin-top: 22px; }
        .modal-btn {
            padding: 11px 26px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all .2s;
        }
        .modal-btn-yes { background: #0D1A63; color: #fff; }
        .modal-btn-yes:hover { background: #1a3a9e; }
        .modal-btn-no  { background: #e0e4f0; color: #333; }
        .modal-btn-no:hover { background: #d0d4e0; }
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1>
            <i class="fas fa-clipboard-check"></i>
            Transition Assessment: <?= htmlspecialchars($county_name) ?>
        </h1>
        <div class="hdr-links">
            <a href="transition_index.php"><i class="fas fa-arrow-left"></i> Back to Sections</a>
            <a href="transition_dashboard.php"><i class="fas fa-chart-bar"></i> Dashboard</a>
        </div>
    </div>

    <?php if (isset($error)): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="assessmentForm">
        <input type="hidden" name="save_assessment" value="1">
        <input type="hidden" name="assessment_date" value="<?= date('Y-m-d') ?>">

        <!-- Progress Tracker -->
        <div class="progress-tracker">
            <div>
                <p style="color: #666; font-size: 13px;">
                    <i class="fas fa-calendar"></i> Period: <?= htmlspecialchars($period) ?> |
                    <i class="fas fa-layer-group"></i> Sections: <?= count($active_sections) ?> |
                    <i class="fas fa-tasks"></i> Total Indicators: <span id="totalIndicators"><?= $total_indicators ?></span>
                </p>
            </div>
            <div class="progress-indicator">
                <span style="font-size: 13px; color: #666;">Overall Progress</span>
                <div class="progress-bar">
                    <div class="progress-fill" id="overallProgress" style="width: 0%;"></div>
                </div>
                <span id="progressPercent" style="font-weight: 700; color: #0D1A63;">0%</span>
            </div>
        </div>

        <!-- Section Tabs -->
        <div class="section-tabs" id="sectionTabs">
            <?php
            $index = 1;
            foreach ($active_sections as $key => $section):
            ?>
            <div class="section-tab" id="tab_<?= $key ?>" data-section="<?= $key ?>" onclick="handleTabClick('<?= $key ?>')">
                <i class="fas <?= $section['icon'] ?? 'fa-file' ?>"></i> <?= $section['title'] ?>
            </div>
            <?php
            $index++;
            endforeach;
            ?>
        </div>

        <!-- Assessment Forms Container -->
        <div id="formsContainer">
            <?php
            foreach ($active_sections as $key => $section):
                $section_total = 0;
                foreach ($section['indicators'] as $indicator) {
                    $section_total += count($indicator['sub_indicators']);
                }
            ?>
            <div class="assessment-form" id="form_<?= $key ?>" style="display: <?= $key === array_key_first($active_sections) ? 'block' : 'none' ?>;"
                 data-section="<?= $key ?>">
                <div class="section-summary">
                    <div>
                        <i class="fas <?= $section['icon'] ?? 'fa-file' ?>"></i>
                        <strong><?= $section['title'] ?></strong>
                        <?php if (!$section['has_ip']): ?>
                        <span class="ip-badge no">CDOH Only</span>
                        <?php elseif (preg_match('/^IO/', array_key_first($section['indicators']))): ?>
                        <span class="ip-badge yes">CDOH + IP</span>
                        <?php else: ?>
                        <span class="ip-badge" style="background:#e0e8ff;color:#0D1A63;">A=IP ? B=CDOH</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <?php if (isset($submitted_sections[$key])): $ss = $submitted_sections[$key]; ?>
                        <span class="submitted-tag" id="stag_<?= $key ?>">
                            <i class="fas fa-check-circle"></i>
                            Submitted <?= date('d M Y H:i', strtotime($ss['submitted_at'])) ?>
                            &nbsp;?&nbsp; <?= $ss['sub_count'] ?> indicators
                            <?php if ($ss['avg_cdoh'] !== null): ?>
                            &nbsp;?&nbsp; CDOH: <?= round($ss['avg_cdoh']/4*100) ?>%
                            <?php endif; ?>
                        </span>
                        <?php else: ?>
                        <span class="submitted-tag" id="stag_<?= $key ?>" style="display:none"></span>
                        <?php endif; ?>
                        <div class="summary-badge" id="section_progress_<?= $key ?>">0% Complete</div>
                    </div>
                </div>

                <?php foreach ($section['indicators'] as $indicator_code => $indicator): ?>
                <div class="indicator-card" style="--color: <?= $section['color'] ?? '#0D1A63' ?>">
                    <div class="indicator-header">
                        <span class="indicator-code"><?= $indicator_code ?></span>
                        <span style="font-size: 12px; color: #666;"><?= count($indicator['sub_indicators']) ?> sub-indicators</span>
                    </div>
                    <div class="indicator-title"><?= $indicator['name'] ?></div>

                    <?php foreach ($indicator['sub_indicators'] as $sub_code => $sub_text):
                        $indicator_key = $key . '_' . $indicator_code . '_' . $sub_code;
                        $ex = $existing_raw[$indicator_key] ?? [];

                        // Determine which score column(s) to show based on indicator_code:
                        // T_A = IP only | T_B = CDOH only (autonomy) | T1/T2 = CDOH only (adequacy)
                        // T3 = CDOH only (autonomy labels) | IO = both CDOH + IP (component labels)
                        $is_ip_only      = (bool)preg_match('/^T\d+A/', $indicator_code);
                        $is_cdoh_b       = (bool)preg_match('/^T\d+B/', $indicator_code);
                        $is_leadership   = in_array($indicator_code, ['T1','T2']);
                        $is_planning     = ($indicator_code === 'T3');
                        $is_io           = (bool)preg_match('/^IO/', $indicator_code);

                        $labels_ip       = [4=>'Dominates',3=>'Supportive',2=>'Involved',1=>'Partial',0=>'Not involved'];
                        $labels_autonomy = [4=>'Independent',3=>'Mostly indep.',2=>'Not indep.',1=>'Minimally',0=>'Not involved'];
                        $labels_adequacy = [4=>'Fully',3=>'Partially',2=>'Some evid.',1=>'No evid.',0=>'Inadequate'];
                        $labels_io       = [4=>'Complete',3=>'Most',2=>'About half',1=>'Few',0=>'No/N/A'];
                    ?>
                    <div class="sub-indicator">
                        <div class="sub-indicator-header">
                            <span class="sub-indicator-code"><?= $sub_code ?></span>
                        </div>
                        <div class="sub-indicator-text"><?= $sub_text ?></div>

                        <?php if ($is_ip_only): ?>
                        <!-- IP score only (A-type indicators) -->
                        <div class="score-grid" style="grid-template-columns:1fr;">
                            <div class="score-column ip">
                                <h4><i class="fas fa-handshake"></i> IP Involvement Score</h4>
                                <div class="radio-group">
                                    <?php foreach ($scoring_criteria as $score => $criteria): ?>
                                    <div class="radio-option <?= $criteria['class'] ?>">
                                        <input type="radio"
                                               name="scores[<?= $indicator_key ?>][ip]"
                                               value="<?= $score ?>"
                                               id="ip_<?= $indicator_key ?>_<?= $score ?>"
                                               data-section="<?= $key ?>"
                                               onchange="updateProgress()"
                                               <?= isset($ex['ip_score']) && $ex['ip_score'] !== null && (string)$ex['ip_score'] === (string)$score ? 'checked' : '' ?>>
                                        <label for="ip_<?= $indicator_key ?>_<?= $score ?>">
                                            <span class="score"><?= $score ?></span>
                                            <span class="label"><?= $labels_ip[$score] ?></span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <?php elseif ($is_cdoh_b || $is_planning): ?>
                        <!-- CDOH score only, autonomy labels (B-type + T3) -->
                        <div class="score-grid" style="grid-template-columns:1fr;">
                            <div class="score-column cdoh">
                                <h4><i class="fas fa-building"></i> CDOH Autonomy Score</h4>
                                <div class="radio-group">
                                    <?php foreach ($scoring_criteria as $score => $criteria): ?>
                                    <div class="radio-option <?= $criteria['class'] ?>">
                                        <input type="radio"
                                               name="scores[<?= $indicator_key ?>][cdoh]"
                                               value="<?= $score ?>"
                                               id="cdoh_<?= $indicator_key ?>_<?= $score ?>"
                                               data-section="<?= $key ?>"
                                               onchange="updateProgress()"
                                               <?= isset($ex['cdoh_score']) && (string)$ex['cdoh_score'] === (string)$score ? 'checked' : '' ?>>
                                        <label for="cdoh_<?= $indicator_key ?>_<?= $score ?>">
                                            <span class="score"><?= $score ?></span>
                                            <span class="label"><?= $labels_autonomy[$score] ?></span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <?php elseif ($is_leadership): ?>
                        <!-- CDOH score only, adequacy labels (T1, T2) -->
                        <div class="score-grid" style="grid-template-columns:1fr;">
                            <div class="score-column cdoh">
                                <h4><i class="fas fa-building"></i> CDOH Score</h4>
                                <div class="radio-group">
                                    <?php foreach ($scoring_criteria as $score => $criteria): ?>
                                    <div class="radio-option <?= $criteria['class'] ?>">
                                        <input type="radio"
                                               name="scores[<?= $indicator_key ?>][cdoh]"
                                               value="<?= $score ?>"
                                               id="cdoh_<?= $indicator_key ?>_<?= $score ?>"
                                               data-section="<?= $key ?>"
                                               onchange="updateProgress()"
                                               <?= isset($ex['cdoh_score']) && (string)$ex['cdoh_score'] === (string)$score ? 'checked' : '' ?>>
                                        <label for="cdoh_<?= $indicator_key ?>_<?= $score ?>">
                                            <span class="score"><?= $score ?></span>
                                            <span class="label"><?= $labels_adequacy[$score] ?></span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <?php elseif ($is_io): ?>
                        <!-- IO: both CDOH and IP with component labels -->
                        <div class="score-grid">
                            <div class="score-column cdoh">
                                <h4><i class="fas fa-building"></i> CDOH Score</h4>
                                <div class="radio-group">
                                    <?php foreach ($scoring_criteria as $score => $criteria): ?>
                                    <div class="radio-option <?= $criteria['class'] ?>">
                                        <input type="radio"
                                               name="scores[<?= $indicator_key ?>][cdoh]"
                                               value="<?= $score ?>"
                                               id="cdoh_<?= $indicator_key ?>_<?= $score ?>"
                                               data-section="<?= $key ?>"
                                               onchange="updateProgress()"
                                               <?= isset($ex['cdoh_score']) && (string)$ex['cdoh_score'] === (string)$score ? 'checked' : '' ?>>
                                        <label for="cdoh_<?= $indicator_key ?>_<?= $score ?>">
                                            <span class="score"><?= $score ?></span>
                                            <span class="label"><?= $labels_io[$score] ?></span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="score-column ip">
                                <h4><i class="fas fa-handshake"></i> IP Score</h4>
                                <div class="radio-group">
                                    <?php foreach ($scoring_criteria as $score => $criteria): ?>
                                    <div class="radio-option <?= $criteria['class'] ?>">
                                        <input type="radio"
                                               name="scores[<?= $indicator_key ?>][ip]"
                                               value="<?= $score ?>"
                                               id="ip_<?= $indicator_key ?>_<?= $score ?>"
                                               data-section="<?= $key ?>"
                                               onchange="updateProgress()"
                                               <?= isset($ex['ip_score']) && $ex['ip_score'] !== null && (string)$ex['ip_score'] === (string)$score ? 'checked' : '' ?>>
                                        <label for="ip_<?= $indicator_key ?>_<?= $score ?>">
                                            <span class="score"><?= $score ?></span>
                                            <span class="label"><?= $labels_io[$score] ?></span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <?php else: ?>
                        <!-- Fallback: CDOH only, adequacy labels -->
                        <div class="score-grid" style="grid-template-columns:1fr;">
                            <div class="score-column cdoh">
                                <h4><i class="fas fa-building"></i> CDOH Score</h4>
                                <div class="radio-group">
                                    <?php foreach ($scoring_criteria as $score => $criteria): ?>
                                    <div class="radio-option <?= $criteria['class'] ?>">
                                        <input type="radio"
                                               name="scores[<?= $indicator_key ?>][cdoh]"
                                               value="<?= $score ?>"
                                               id="cdoh_<?= $indicator_key ?>_<?= $score ?>"
                                               data-section="<?= $key ?>"
                                               onchange="updateProgress()"
                                               <?= isset($ex['cdoh_score']) && (string)$ex['cdoh_score'] === (string)$score ? 'checked' : '' ?>>
                                        <label for="cdoh_<?= $indicator_key ?>_<?= $score ?>">
                                            <span class="score"><?= $score ?></span>
                                            <span class="label"><?= $labels_adequacy[$score] ?></span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Comments Section -->
                        <div class="comments-section">
                            <textarea name="scores[<?= $indicator_key ?>][comments]"
                                      placeholder="Add comments or verification notes for this indicator..."
                                      rows="2"><?= htmlspecialchars($ex['comments'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <!-- Save This Section button -->
                <div class="section-actions">
                    <button type="button" class="btn-save-section" onclick="saveSection('<?= $key ?>')">
                        <i class="fas fa-save"></i> Save This Section
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Save & Submit -->
        <button type="submit" class="btn-submit">
            <i class="fas fa-save"></i> Save Assessment
        </button>
    </form>

    <!-- Save Progress Bar -->
    <div class="save-progress">
        <span><i class="fas fa-sync-alt fa-spin" id="saveSpinner" style="display: none;"></i> <span id="saveStatus">All changes saved</span></span>
        <span id="completionBadge" style="background: rgba(255,255,255,.2); padding: 5px 15px; border-radius: 30px;">0% complete</span>
    </div>
</div>

<!-- Already-submitted modal -->
<div class="modal-overlay" id="sectionModal">
    <div class="modal-box">
        <div class="modal-icon">??</div>
        <h3>Section Already Submitted</h3>
        <p>This section has already been filled for:</p>
        <div class="modal-info">
            <strong>County:</strong> <?= htmlspecialchars($county_name) ?><br>
            <strong>Period:</strong> <?= htmlspecialchars($period) ?><br>
            <strong>Submitted:</strong> <span id="modalDate">?</span><br>
            <strong>Indicators scored:</strong> <span id="modalCount">?</span><br>
            <strong>CDOH Score:</strong> <span id="modalCdoh">?</span>
        </div>
        <p>Would you like to <strong>fill another sheet</strong> (update this section)?</p>
        <div class="modal-actions">
            <button class="modal-btn modal-btn-yes" onclick="proceedToSection()">
                <i class="fas fa-edit"></i> Yes, Fill Again
            </button>
            <button class="modal-btn modal-btn-no" onclick="closeModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>

<script>
let currentSection = '<?= array_key_first($active_sections) ?>';
let sectionKeys = <?= json_encode(array_keys($active_sections)) ?>;
let autoSaveTimer;
let totalIndicators = <?= $total_indicators ?>;
let globalAssessmentId = <?= $assessment_id_js ?>;
let submittedSections = <?= $submitted_sections_json ?>;
let pendingSection = null;

// -- Tab click: intercept if section already submitted -------------------------
function handleTabClick(sectionKey) {
    if (sectionKey === currentSection) return;

    if (submittedSections[sectionKey]) {
        const s = submittedSections[sectionKey];
        document.getElementById('modalDate').textContent  = s.submitted_at || '?';
        document.getElementById('modalCount').textContent = s.sub_count    || '?';
        const cdohPct = s.avg_cdoh !== null ? Math.round(s.avg_cdoh / 4 * 100) + '%' : '?';
        document.getElementById('modalCdoh').textContent  = cdohPct;
        pendingSection = sectionKey;
        document.getElementById('sectionModal').classList.add('show');
    } else {
        showSection(sectionKey);
    }
}

function proceedToSection() {
    closeModal();
    if (pendingSection) {
        showSection(pendingSection);
        pendingSection = null;
    }
}

function closeModal() {
    document.getElementById('sectionModal').classList.remove('show');
}

// Close on overlay click
document.getElementById('sectionModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// -- Show a section ------------------------------------------------------------
function showSection(sectionKey) {
    document.querySelectorAll('.assessment-form').forEach(form => {
        form.style.display = 'none';
    });
    document.getElementById('form_' + sectionKey).style.display = 'block';

    document.querySelectorAll('.section-tab').forEach(tab => {
        tab.classList.remove('active');
        if (tab.dataset.section === sectionKey) tab.classList.add('active');
    });

    currentSection = sectionKey;
    updateSectionProgress(sectionKey);
}

// -- Progress tracking ---------------------------------------------------------
function updateProgress() {
    let totalScored   = 0;
    let totalPossible = 0;

    document.querySelectorAll('.sub-indicator').forEach(subIndicator => {
        const cdohRadios = subIndicator.querySelectorAll('.score-column.cdoh input[type="radio"]');
        const ipRadios   = subIndicator.querySelectorAll('.score-column.ip input[type="radio"]');
        if (cdohRadios.length > 0) totalPossible++;
        if (ipRadios.length   > 0) totalPossible++;
    });

    document.querySelectorAll('input[type="radio"]:checked').forEach(() => totalScored++);

    let percent = totalPossible > 0 ? Math.round((totalScored / totalPossible) * 100) : 0;
    document.getElementById('overallProgress').style.width  = percent + '%';
    document.getElementById('progressPercent').textContent  = percent + '%';
    document.getElementById('completionBadge').textContent  = percent + '% complete';

    sectionKeys.forEach(section => updateSectionProgress(section));

    clearTimeout(autoSaveTimer);
    autoSaveTimer = setTimeout(autoSave, 3000);
    document.getElementById('saveStatus').textContent = 'Saving...';
    document.getElementById('saveSpinner').style.display = 'inline-block';
}

function updateSectionProgress(sectionKey) {
    const sectionForm = document.getElementById('form_' + sectionKey);
    if (!sectionForm) return;

    const sectionRadios        = sectionForm.querySelectorAll('input[type="radio"]');
    const totalSectionRadios   = sectionRadios.length;
    const checkedSectionRadios = sectionForm.querySelectorAll('input[type="radio"]:checked').length;
    let   sectionPercent = totalSectionRadios > 0
        ? Math.round((checkedSectionRadios / totalSectionRadios) * 100) : 0;

    const progressSpan = document.getElementById('section_progress_' + sectionKey);
    if (progressSpan) {
        progressSpan.textContent = sectionPercent + '% Complete';
        const tab = document.querySelector(`.section-tab[data-section="${sectionKey}"]`);
        if (tab) tab.classList.toggle('completed', sectionPercent === 100);
    }
}

function autoSave() {
    document.getElementById('saveStatus').textContent = 'All changes saved';
    document.getElementById('saveSpinner').style.display = 'none';
}

// -- AJAX: Save a single section -----------------------------------------------
function saveSection(sectionKey) {
    const form = document.getElementById('form_' + sectionKey);
    if (!form) return;

    const btn = form.querySelector('.btn-save-section');
    btn.classList.add('saving');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving?';
    document.getElementById('saveStatus').textContent = 'Saving section?';
    document.getElementById('saveSpinner').style.display = 'inline-block';

    // Build FormData from just this section's radios + textareas
    const fd = new FormData();
    fd.append('save_section', '1');
    fd.append('section_key', sectionKey);
    fd.append('assessment_date', '<?= date('Y-m-d') ?>');
    fd.append('county_id', '<?= $county_id ?>');
    fd.append('period', '<?= addslashes($period) ?>');
    fd.append('assessment_id', globalAssessmentId);

    // Collect all radio + textarea inputs from this section form
    form.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
        fd.append(radio.name, radio.value);
    });
    form.querySelectorAll('textarea').forEach(ta => {
        fd.append(ta.name, ta.value);
    });

    const url = window.location.pathname
        + '?county=<?= $county_id ?>'
        + '&period=<?= urlencode($period) ?>'
        + '&sections=<?= implode(',', $sections) ?>';

    fetch(url, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.classList.remove('saving');
            if (data.success) {
                // Store new assessment_id globally
                globalAssessmentId = data.assessment_id;

                // Update submitted sections registry
                const now = new Date();
                const fmt = now.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
                    + ' ' + now.toTimeString().slice(0,5);
                submittedSections[sectionKey] = {
                    submitted_at : fmt,
                    sub_count    : data.saved,
                    avg_cdoh     : data.avg_cdoh_pct * 4 / 100
                };

                // Update the submitted tag inside the section header
                const tag = document.getElementById('stag_' + sectionKey);
                if (tag) {
                    tag.style.display = '';
                    tag.innerHTML = `<i class="fas fa-check-circle"></i> Submitted ${fmt}`
                        + ` &nbsp;?&nbsp; ${data.saved} indicators`
                        + (data.avg_cdoh_pct ? ` &nbsp;?&nbsp; CDOH: ${data.avg_cdoh_pct}%` : '');
                }

                // Mark tab as completed
                const tab = document.querySelector(`.section-tab[data-section="${sectionKey}"]`);
                if (tab) tab.classList.add('completed');

                btn.innerHTML = '<i class="fas fa-check"></i> Saved!';
                document.getElementById('saveStatus').textContent = 'Section saved ?';
                document.getElementById('saveSpinner').style.display = 'none';
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-save"></i> Save This Section';
                }, 2500);
            } else {
                btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error ? Retry';
                document.getElementById('saveStatus').textContent = 'Error: ' + (data.error || 'unknown');
                document.getElementById('saveSpinner').style.display = 'none';
            }
        })
        .catch(err => {
            btn.classList.remove('saving');
            btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Network Error';
            document.getElementById('saveStatus').textContent = 'Network error';
            document.getElementById('saveSpinner').style.display = 'none';
            console.error(err);
        });
}

// -- Keyboard nav --------------------------------------------------------------
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'ArrowRight') {
        e.preventDefault();
        let i = sectionKeys.indexOf(currentSection);
        if (i < sectionKeys.length - 1) handleTabClick(sectionKeys[i + 1]);
    } else if (e.ctrlKey && e.key === 'ArrowLeft') {
        e.preventDefault();
        let i = sectionKeys.indexOf(currentSection);
        if (i > 0) handleTabClick(sectionKeys[i - 1]);
    }
});

// -- Init ----------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', function() {
    showSection(currentSection);
    updateProgress();

    // Mark already-submitted tabs with completed class on load
    Object.keys(submittedSections).forEach(k => {
        const tab = document.querySelector(`.section-tab[data-section="${k}"]`);
        if (tab) tab.classList.add('completed');
    });
});

// Form validation before full submit
document.getElementById('assessmentForm').addEventListener('submit', function(e) {
    const totalRadios   = document.querySelectorAll('.sub-indicator').length * 2;
    const checkedRadios = document.querySelectorAll('input[type="radio"]:checked').length;
    if (checkedRadios < totalRadios * 0.5) {
        if (!confirm('You have completed less than 50% of the indicators. Are you sure you want to submit?')) {
            e.preventDefault();
        }
    }
});
</script>
</body>
</html>