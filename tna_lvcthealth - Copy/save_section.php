<?php
session_start();
header('Content-Type: application/json');
include '../includes/config.php';

$response = ['success' => false, 'message' => ''];

try {
    $section = $_POST['section'] ?? '';
    $staff_id = $_POST['staff_id'] ?? $_SESSION['temp_staff_id'];

    // Create upload directories if not exist
    $uploadDirs = ['resumes', 'prof_qualifications', 'certifications', 'pro_membership'];
    foreach($uploadDirs as $dir) {
        if(!file_exists("../uploads/$dir")) mkdir("../uploads/$dir", 0777, true);
    }

    switch($section) {
        case 'section1':
            // Check if staff exists
            $check = mysqli_query($conn, "SELECT staff_id FROM staff_profile WHERE staff_id='$staff_id'");
            $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
            $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
            $other_name = mysqli_real_escape_string($conn, $_POST['other_name']);
            $sex = $_POST['sex'];
            $department_name = mysqli_real_escape_string($conn, $_POST['department_name']);
            $division_name = mysqli_real_escape_string($conn, $_POST['division_name']);
            $project_name = mysqli_real_escape_string($conn, $_POST['project_name']);
            $is_supervisor = $_POST['is_supervisor'];
            $date_of_birth = $_POST['date_of_birth'] ?: null;
            $date_of_joining = $_POST['date_of_joining'] ?: null;
            $experience_years = $_POST['experience_years'] ?: null;
            $years_in_current_role = $_POST['years_in_current_role'] ?: null;

            // Handle file upload
            $resume_path = null;
            if(isset($_FILES['resume']) && $_FILES['resume']['error'] == 0) {
                $allowed = ['application/pdf'];
                if(in_array($_FILES['resume']['type'], $allowed) && $_FILES['resume']['size'] <= 1048576) {
                    $filename = $first_name . '_' . $last_name . '_' . date('Ymd_His') . '.pdf';
                    $target = "../uploads/resumes/" . $filename;
                    if(move_uploaded_file($_FILES['resume']['tmp_name'], $target)) {
                        $resume_path = "uploads/resumes/" . $filename;
                    }
                }
            }

            if(mysqli_num_rows($check) > 0) {
                $query = "UPDATE staff_profile SET first_name='$first_name', last_name='$last_name', other_name='$other_name', sex='$sex', department_name='$department_name', division_name='$division_name', project_name='$project_name', is_supervisor='$is_supervisor', date_of_birth='$date_of_birth', date_of_joining='$date_of_joining', experience_years='$experience_years', years_in_current_role='$years_in_current_role'";
                if($resume_path) $query .= ", resume_path='$resume_path'";
                $query .= " WHERE staff_id='$staff_id'";
                mysqli_query($conn, $query);
            } else {
                $query = "INSERT INTO staff_profile (staff_id, first_name, last_name, other_name, sex, department_name, division_name, project_name, is_supervisor, date_of_birth, date_of_joining, experience_years, years_in_current_role, resume_path) VALUES ('$staff_id', '$first_name', '$last_name', '$other_name', '$sex', '$department_name', '$division_name', '$project_name', '$is_supervisor', '$date_of_birth', '$date_of_joining', '$experience_years', '$years_in_current_role', '$resume_path')";
                mysqli_query($conn, $query);
            }
            updateProgress($conn, $staff_id);
            $response['success'] = true;
            $response['message'] = 'Profile saved successfully';
            break;

        case 'section2':
            // Delete existing
            mysqli_query($conn, "DELETE FROM academic_qualifications WHERE staff_id='$staff_id'");
            mysqli_query($conn, "DELETE FROM professional_memberships WHERE staff_id='$staff_id'");

            // Academic qualifications
            if(isset($_POST['academic_degree'])) {
                foreach($_POST['academic_degree'] as $idx => $degree) {
                    if(empty($degree)) continue;
                    $institution = mysqli_real_escape_string($conn, $_POST['academic_institution'][$idx]);
                    $is_ongoing = isset($_POST['academic_ongoing'][$idx]) ? 1 : 0;
                    $expected_date = $_POST['academic_date'][$idx] ? $_POST['academic_date'][$idx] . '-01' : null;

                    $cert_path = null;
                    if(isset($_FILES['academic_cert']['tmp_name'][$idx]) && $_FILES['academic_cert']['error'][$idx] == 0) {
                        $filename = $staff_id . '_academic_' . time() . '_' . $idx . '.pdf';
                        $target = "../uploads/prof_qualifications/" . $filename;
                        if(move_uploaded_file($_FILES['academic_cert']['tmp_name'][$idx], $target)) {
                            $cert_path = "uploads/prof_qualifications/" . $filename;
                        }
                    }

                    $query = "INSERT INTO academic_qualifications (staff_id, degree, institution, is_ongoing, expected_date, certificate_path) VALUES ('$staff_id', '$degree', '$institution', $is_ongoing, " . ($expected_date ? "'$expected_date'" : "NULL") . ", " . ($cert_path ? "'$cert_path'" : "NULL") . ")";
                    mysqli_query($conn, $query);
                }
            }

            // Professional memberships
            if(isset($_POST['pro_body'])) {
                foreach($_POST['pro_body'] as $idx => $body) {
                    if(empty($body)) continue;
                    $membership_id = mysqli_real_escape_string($conn, $_POST['pro_id'][$idx]);
                    $status = $_POST['pro_status'][$idx];

                    $attachment_path = null;
                    if(isset($_FILES['pro_cert']['tmp_name'][$idx]) && $_FILES['pro_cert']['error'][$idx] == 0) {
                        $filename = $staff_id . '_membership_' . time() . '_' . $idx . '.pdf';
                        $target = "../uploads/pro_membership/" . $filename;
                        if(move_uploaded_file($_FILES['pro_cert']['tmp_name'][$idx], $target)) {
                            $attachment_path = "uploads/pro_membership/" . $filename;
                        }
                    }

                    $query = "INSERT INTO professional_memberships (staff_id, membership_body, membership_id, status, attachment_path) VALUES ('$staff_id', '$body', '$membership_id', '$status', " . ($attachment_path ? "'$attachment_path'" : "NULL") . ")";
                    mysqli_query($conn, $query);
                }
            }
            updateProgress($conn, $staff_id);
            $response['success'] = true;
            $response['message'] = 'Qualifications saved successfully';
            break;

        case 'section3':
            mysqli_query($conn, "DELETE FROM roles_responsibilities WHERE staff_id='$staff_id'");

            if(isset($_POST['roles_responsibilities'])) {
                foreach($_POST['roles_responsibilities'] as $idx => $role) {
                    if(empty($role)) continue;
                    $qualifications = mysqli_real_escape_string($conn, $_POST['qualifications'][$idx] ?? '');
                    $years_exp = mysqli_real_escape_string($conn, $_POST['years_experience'][$idx] ?? '');
                    $understanding = $_POST['role_understanding'][$idx] ?? 'Moderately Clear';
                    $confident = mysqli_real_escape_string($conn, $_POST['confident_aspects'][$idx] ?? '');
                    $challenging = mysqli_real_escape_string($conn, $_POST['challenging_tasks'][$idx] ?? '');

                    $jd_path = null;
                    if(isset($_FILES['jd_attachment']['tmp_name'][$idx]) && $_FILES['jd_attachment']['error'][$idx] == 0) {
                        $filename = $staff_id . '_jd_' . time() . '_' . $idx . '.pdf';
                        $target = "../uploads/certifications/" . $filename;
                        if(move_uploaded_file($_FILES['jd_attachment']['tmp_name'][$idx], $target)) {
                            $jd_path = "uploads/certifications/" . $filename;
                        }
                    }

                    $query = "INSERT INTO roles_responsibilities (staff_id, role_description, required_qualifications, required_experience, role_understanding, confident_aspects, challenging_tasks, jd_attachment_path) VALUES ('$staff_id', '$role', '$qualifications', '$years_exp', '$understanding', '$confident', '$challenging', " . ($jd_path ? "'$jd_path'" : "NULL") . ")";
                    mysqli_query($conn, $query);
                }
            }
            updateProgress($conn, $staff_id);
            $response['success'] = true;
            $response['message'] = 'Roles saved successfully';
            break;

        case 'section4':
            mysqli_query($conn, "DELETE FROM supervisor_assessments WHERE staff_id='$staff_id'");

            if(isset($_POST['position'])) {
                foreach($_POST['position'] as $idx => $position) {
                    if(empty($position)) continue;
                    $skills_gap = mysqli_real_escape_string($conn, $_POST['skills_gap'][$idx] ?? '');

                    $query = "INSERT INTO supervisor_assessments (staff_id, position_supervised, skills_gap) VALUES ('$staff_id', '$position', '$skills_gap')";
                    mysqli_query($conn, $query);
                }
            }
            updateProgress($conn, $staff_id);
            $response['success'] = true;
            $response['message'] = 'Supervisor assessment saved successfully';
            break;

        case 'section5':
            $check = mysqli_query($conn, "SELECT id FROM skills_assessment WHERE staff_id='$staff_id'");
            $technical = $_POST['technical'] ?? 0;
            $communication = $_POST['communication'] ?? 0;
            $leadership = $_POST['leadership'] ?? 0;
            $teamwork = $_POST['teamwork'] ?? 0;
            $problem_solving = $_POST['problem_solving'] ?? 0;
            $skills_improvement = mysqli_real_escape_string($conn, $_POST['skills_gap'] ?? '');
            $future_skills = mysqli_real_escape_string($conn, $_POST['future_skills'] ?? '');

            if(mysqli_num_rows($check) > 0) {
                $query = "UPDATE skills_assessment SET technical_skill=$technical, communication_skill=$communication, leadership_skill=$leadership, teamwork_skill=$teamwork, problem_solving_skill=$problem_solving, skills_improvement='$skills_improvement', future_skills='$future_skills' WHERE staff_id='$staff_id'";
            } else {
                $query = "INSERT INTO skills_assessment (staff_id, technical_skill, communication_skill, leadership_skill, teamwork_skill, problem_solving_skill, skills_improvement, future_skills) VALUES ('$staff_id', $technical, $communication, $leadership, $teamwork, $problem_solving, '$skills_improvement', '$future_skills')";
            }
            mysqli_query($conn, $query);
            updateProgress($conn, $staff_id);
            $response['success'] = true;
            $response['message'] = 'Skills assessment saved successfully';
            break;

        case 'section6':
            $check = mysqli_query($conn, "SELECT id FROM training_preferences WHERE staff_id='$staff_id'");
            $effective_training = mysqli_real_escape_string($conn, $_POST['effective_training'] ?? '');
            $learning_methods = isset($_POST['learning_methods']) ? implode(',', $_POST['learning_methods']) : '';
            $barriers = isset($_POST['barriers']) ? implode(',', $_POST['barriers']) : '';
            $support_needed = mysqli_real_escape_string($conn, $_POST['support_needed'] ?? '');

            if(mysqli_num_rows($check) > 0) {
                $query = "UPDATE training_preferences SET effective_training='$effective_training', learning_methods='$learning_methods', barriers='$barriers', support_needed='$support_needed' WHERE staff_id='$staff_id'";
            } else {
                $query = "INSERT INTO training_preferences (staff_id, effective_training, learning_methods, barriers, support_needed) VALUES ('$staff_id', '$effective_training', '$learning_methods', '$barriers', '$support_needed')";
            }
            mysqli_query($conn, $query);
            updateProgress($conn, $staff_id);
            $response['success'] = true;
            $response['message'] = 'Training preferences saved successfully';
            break;

        case 'section7':
            $check = mysqli_query($conn, "SELECT id FROM career_development WHERE staff_id='$staff_id'");
            $short_term = mysqli_real_escape_string($conn, $_POST['short_term'] ?? '');
            $long_term = mysqli_real_escape_string($conn, $_POST['long_term'] ?? '');
            $development_opp = mysqli_real_escape_string($conn, $_POST['development_opportunities'] ?? '');
            $leadership_roles = mysqli_real_escape_string($conn, $_POST['leadership_roles'] ?? '');

            if(mysqli_num_rows($check) > 0) {
                $query = "UPDATE career_development SET short_term_goals='$short_term', long_term_goals='$long_term', development_opportunities='$development_opp', leadership_roles='$leadership_roles' WHERE staff_id='$staff_id'";
            } else {
                $query = "INSERT INTO career_development (staff_id, short_term_goals, long_term_goals, development_opportunities, leadership_roles) VALUES ('$staff_id', '$short_term', '$long_term', '$development_opp', '$leadership_roles')";
            }
            mysqli_query($conn, $query);
            updateProgress($conn, $staff_id);
            $response['success'] = true;
            $response['message'] = 'Career development saved successfully';
            break;

        case 'section8':
            $check = mysqli_query($conn, "SELECT id FROM organizational_alignment WHERE staff_id='$staff_id'");
            $role_contribution = mysqli_real_escape_string($conn, $_POST['role_contribution'] ?? '');
            $org_changes = mysqli_real_escape_string($conn, $_POST['org_changes'] ?? '');
            $org_skills = mysqli_real_escape_string($conn, $_POST['org_skills'] ?? '');

            if(mysqli_num_rows($check) > 0) {
                $query = "UPDATE organizational_alignment SET role_contribution='$role_contribution', org_changes='$org_changes', org_skills='$org_skills' WHERE staff_id='$staff_id'";
            } else {
                $query = "INSERT INTO organizational_alignment (staff_id, role_contribution, org_changes, org_skills) VALUES ('$staff_id', '$role_contribution', '$org_changes', '$org_skills')";
            }
            mysqli_query($conn, $query);
            updateProgress($conn, $staff_id);
            $response['success'] = true;
            $response['message'] = 'Organizational alignment saved successfully';
            break;
    }
} catch(Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

function updateProgress($conn, $staff_id) {
    // Calculate completion percentage
    $total_weight = 100;
    $earned = 0;

    // Section 1: Staff profile (15%)
    $check = mysqli_query($conn, "SELECT staff_id FROM staff_profile WHERE staff_id='$staff_id'");
    if(mysqli_num_rows($check) > 0) $earned += 15;

    // Section 2: Qualifications (15%)
    $check = mysqli_query($conn, "SELECT id FROM academic_qualifications WHERE staff_id='$staff_id' LIMIT 1");
    if(mysqli_num_rows($check) > 0) $earned += 15;

    // Section 3: Roles (15%)
    $check = mysqli_query($conn, "SELECT id FROM roles_responsibilities WHERE staff_id='$staff_id' LIMIT 1");
    if(mysqli_num_rows($check) > 0) $earned += 15;

    // Section 4: Supervisor (10%) - only if supervisor
    $staff = mysqli_fetch_assoc(mysqli_query($conn, "SELECT is_supervisor FROM staff_profile WHERE staff_id='$staff_id'"));
    if($staff && $staff['is_supervisor'] == 'Yes') {
        $check = mysqli_query($conn, "SELECT id FROM supervisor_assessments WHERE staff_id='$staff_id' LIMIT 1");
        if(mysqli_num_rows($check) > 0) $earned += 10;
    } else {
        $earned += 10; // Auto-complete for non-supervisors
    }

    // Section 5: Skills (10%)
    $check = mysqli_query($conn, "SELECT id FROM skills_assessment WHERE staff_id='$staff_id'");
    if(mysqli_num_rows($check) > 0) $earned += 10;

    // Section 6: Training (10%)
    $check = mysqli_query($conn, "SELECT id FROM training_preferences WHERE staff_id='$staff_id'");
    if(mysqli_num_rows($check) > 0) $earned += 10;

    // Section 7: Career (10%)
    $check = mysqli_query($conn, "SELECT id FROM career_development WHERE staff_id='$staff_id'");
    if(mysqli_num_rows($check) > 0) $earned += 10;

    // Section 8: Organizational (5%)
    $check = mysqli_query($conn, "SELECT id FROM organizational_alignment WHERE staff_id='$staff_id'");
    if(mysqli_num_rows($check) > 0) $earned += 5;

    $percentage = min(100, $earned);
    mysqli_query($conn, "UPDATE staff_profile SET completion_percentage = $percentage WHERE staff_id='$staff_id'");
}
?>