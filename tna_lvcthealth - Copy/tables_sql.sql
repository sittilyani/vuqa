-- Database tables for Training Needs Assessment System

-- Main staff profile table (central reference)
CREATE TABLE IF NOT EXISTS staff_profile (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    other_name VARCHAR(100),
    sex ENUM('Male', 'Female') NOT NULL,
    department_name VARCHAR(200),
    division_name VARCHAR(200),
    project_name VARCHAR(200),
    is_supervisor ENUM('Yes', 'No') DEFAULT 'No',
    date_of_birth DATE,
    date_of_joining DATE,
    experience_years INT,
    years_in_current_role INT,
    resume_path VARCHAR(500),
    completion_percentage INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_staff_id (staff_id),
    INDEX idx_project (project_name)
);

-- Academic qualifications table
CREATE TABLE IF NOT EXISTS academic_qualifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL,
    degree VARCHAR(200) NOT NULL,
    institution VARCHAR(200) NOT NULL,
    is_ongoing TINYINT DEFAULT 0,
    expected_date DATE,
    certificate_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff_profile(staff_id) ON DELETE CASCADE,
    INDEX idx_staff_id (staff_id)
);

-- Professional memberships table
CREATE TABLE IF NOT EXISTS professional_memberships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL,
    membership_body VARCHAR(200) NOT NULL,
    membership_id VARCHAR(100),
    status ENUM('Active', 'Inactive', 'Expired', 'Others') DEFAULT 'Active',
    attachment_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff_profile(staff_id) ON DELETE CASCADE,
    INDEX idx_staff_id (staff_id)
);

-- Roles and responsibilities table
CREATE TABLE IF NOT EXISTS roles_responsibilities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL,
    role_description TEXT NOT NULL,
    required_qualifications TEXT,
    required_experience VARCHAR(100),
    role_understanding ENUM('Very Clear', 'Moderately Clear', 'Unclear'),
    confident_aspects TEXT,
    challenging_tasks TEXT,
    jd_attachment_path VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff_profile(staff_id) ON DELETE CASCADE,
    INDEX idx_staff_id (staff_id)
);

-- Supervisor assessment table (only for supervisors)
CREATE TABLE IF NOT EXISTS supervisor_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL,
    position_supervised VARCHAR(200) NOT NULL,
    skills_gap TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff_profile(staff_id) ON DELETE CASCADE,
    INDEX idx_staff_id (staff_id)
);

-- Skills self-assessment table
CREATE TABLE IF NOT EXISTS skills_assessment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL,
    technical_skill INT CHECK (technical_skill BETWEEN 1 AND 5),
    communication_skill INT CHECK (communication_skill BETWEEN 1 AND 5),
    leadership_skill INT CHECK (leadership_skill BETWEEN 1 AND 5),
    teamwork_skill INT CHECK (teamwork_skill BETWEEN 1 AND 5),
    problem_solving_skill INT CHECK (problem_solving_skill BETWEEN 1 AND 5),
    skills_improvement TEXT,
    future_skills TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff_profile(staff_id) ON DELETE CASCADE,
    INDEX idx_staff_id (staff_id)
);

-- Training preferences table
CREATE TABLE IF NOT EXISTS training_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL,
    effective_training TEXT,
    learning_methods VARCHAR(500),
    barriers VARCHAR(500),
    support_needed TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff_profile(staff_id) ON DELETE CASCADE,
    INDEX idx_staff_id (staff_id)
);

-- Career development table
CREATE TABLE IF NOT EXISTS career_development (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL,
    short_term_goals TEXT,
    long_term_goals TEXT,
    development_opportunities TEXT,
    leadership_roles TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff_profile(staff_id) ON DELETE CASCADE,
    INDEX idx_staff_id (staff_id)
);

-- Organizational alignment table
CREATE TABLE IF NOT EXISTS organizational_alignment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(50) NOT NULL,
    role_contribution TEXT,
    org_changes TEXT,
    org_skills TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staff_profile(staff_id) ON DELETE CASCADE,
    INDEX idx_staff_id (staff_id)
);

-- Create view for progress tracking
CREATE OR REPLACE VIEW staff_progress_view AS
SELECT 
    sp.id,
    sp.staff_id,
    sp.first_name,
    sp.last_name,
    sp.completion_percentage,
    CASE WHEN aq.staff_id IS NOT NULL THEN 5 ELSE 0 END as academic_score,
    CASE WHEN pm.staff_id IS NOT NULL THEN 5 ELSE 0 END as membership_score,
    CASE WHEN rr.staff_id IS NOT NULL THEN 10 ELSE 0 END as roles_score,
    CASE WHEN sa.staff_id IS NOT NULL OR sp.is_supervisor = 'No' THEN 10 ELSE 0 END as supervisor_score,
    CASE WHEN sk.staff_id IS NOT NULL THEN 10 ELSE 0 END as skills_score,
    CASE WHEN tp.staff_id IS NOT NULL THEN 10 ELSE 0 END as training_score,
    CASE WHEN cd.staff_id IS NOT NULL THEN 10 ELSE 0 END as career_score,
    CASE WHEN oa.staff_id IS NOT NULL THEN 10 ELSE 0 END as org_score
FROM staff_profile sp
LEFT JOIN academic_qualifications aq ON sp.staff_id = aq.staff_id
LEFT JOIN professional_memberships pm ON sp.staff_id = pm.staff_id
LEFT JOIN roles_responsibilities rr ON sp.staff_id = rr.staff_id
LEFT JOIN supervisor_assessments sa ON sp.staff_id = sa.staff_id
LEFT JOIN skills_assessment sk ON sp.staff_id = sk.staff_id
LEFT JOIN training_preferences tp ON sp.staff_id = tp.staff_id
LEFT JOIN career_development cd ON sp.staff_id = cd.staff_id
LEFT JOIN organizational_alignment oa ON sp.staff_id = oa.staff_id
GROUP BY sp.staff_id;



CREATE TABLE IF NOT EXISTS divisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    division_name VARCHAR(200) UNIQUE NOT NULL
);

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(200) UNIQUE NOT NULL
);

CREATE TABLE IF NOT EXISTS learning_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method_name VARCHAR(200) UNIQUE NOT NULL
);

CREATE TABLE IF NOT EXISTS barriers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barrier_name VARCHAR(200) UNIQUE NOT NULL
);

-- Insert sample data
INSERT INTO departments (department_name) VALUES 
('Human Resources'), ('Finance'), ('Programs'), ('Monitoring & Evaluation'), ('Administration'), ('Care and Treatment')
ON DUPLICATE KEY UPDATE department_name=VALUES(department_name);

INSERT INTO divisions (division_name) VALUES 
('Management'), ('CMT'), ('SMT'), ('Board')
ON DUPLICATE KEY UPDATE division_name=VALUES(division_name);

INSERT INTO projects (project_name) VALUES 
('Stawisha Pwani'), ('One2one'), ('MindSkillz'), ('Dhibiti'), ('Vukisha-95'), ('SHINE'), ('CitDoit')
ON DUPLICATE KEY UPDATE project_name=VALUES(project_name);

INSERT INTO learning_methods (method_name) VALUES 
('Classroom Training'), ('Online Learning'), ('On-the-job Training'), 
('Workshops'), ('Seminars'), ('Mentorship') 
ON DUPLICATE KEY UPDATE method_name=VALUES(method_name);

INSERT INTO barriers (barrier_name) VALUES 
('Time Constraints'), ('Budget Limitations'), ('Workload'), 
('Lack of Relevant Programs'), ('Distance to Training Centers') 
ON DUPLICATE KEY UPDATE barrier_name=VALUES(barrier_name);