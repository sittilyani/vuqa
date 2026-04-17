-- ═══════════════════════════════════════════════════════════════════════════════
-- TABLE: integration_assessments
-- Integration Assessment Form — linked to facilities table by facility_id
-- ═══════════════════════════════════════════════════════════════════════════════

DROP TABLE IF EXISTS `integration_assessment_emr_systems`;
DROP TABLE IF EXISTS `integration_assessments`;

CREATE TABLE `integration_assessments` (

    -- ── Identity ──────────────────────────────────────────────────────────────
    `assessment_id`     INT AUTO_INCREMENT PRIMARY KEY,
    `facility_id`       INT NOT NULL,                -- FK → facilities.facility_id
    `assessment_period` VARCHAR(20),                 -- e.g. 'Oct-Dec 2025'

    -- ── Section 1: Facility Profile (snapshot from facilities table) ──────────
    `facility_name`     VARCHAR(255),
    `mflcode`           VARCHAR(20),
    `county_name`       VARCHAR(100),
    `subcounty_name`    VARCHAR(100),
    `owner`             VARCHAR(100),
    `sdp`               VARCHAR(100),
    `agency`            VARCHAR(100),
    `emr`               VARCHAR(100),
    `emrstatus`         VARCHAR(50),
    `infrastructuretype` VARCHAR(100),
    `latitude`          DECIMAL(10,7),
    `longitude`         DECIMAL(10,7),
    `level_of_care_name` VARCHAR(100),

    -- Q7–8
    `supported_by_usdos_ip` ENUM('Yes','No'),
    `is_art_site`            ENUM('Yes','No'),

    -- ── Section 2a: Integration of HIV/TB Services ────────────────────────────
    `hiv_tb_integrated`          ENUM('Yes','No'),
    `hiv_tb_integration_model`   VARCHAR(255),       -- type of integration model
    `tx_curr`                    INT,                -- Q11
    `tx_curr_pmtct`              INT,                -- Q12
    `plhiv_integrated_care`      INT,                -- Q13

    -- ── Section 2b: Integration of Other Services ─────────────────────────────
    `pmtct_integrated_mnch`      ENUM('Yes','No'),   -- Q14
    `hts_integrated_opd`         ENUM('Yes','No'),   -- Q15
    `hts_integrated_ipd`         ENUM('Yes','No'),   -- Q16
    `hts_integrated_mnch`        ENUM('Yes','No'),   -- Q17
    `prep_integrated_opd`        ENUM('Yes','No'),   -- Q18
    `prep_integrated_ipd`        ENUM('Yes','No'),   -- Q19
    `prep_integrated_mnch`       ENUM('Yes','No'),   -- Q20

    -- ── Section 2c: EMR Integration ───────────────────────────────────────────
    `uses_emr`                   ENUM('Yes','No'),   -- Q21
    -- EMR systems stored in child table (repeating, Q22)
    `no_emr_reasons`             SET('No hardware','No internet','No electricity','No trained staff','Other'), -- Q23
    `single_unified_emr`         ENUM('Yes','No'),   -- Q24
    `emr_at_opd`                 ENUM('Yes','No'),   -- Q25
    `emr_opd_other`              VARCHAR(255),       -- Q26
    `emr_at_ipd`                 ENUM('Yes','No'),   -- Q27
    `emr_ipd_other`              VARCHAR(255),       -- Q28
    `emr_at_mnch`                ENUM('Yes','No'),   -- Q29
    `emr_mnch_other`             VARCHAR(255),       -- Q30
    `emr_at_ccc`                 ENUM('Yes','No'),   -- Q31
    `emr_ccc_other`              VARCHAR(255),       -- Q32
    `emr_at_pmtct`               ENUM('Yes','No'),   -- Q33
    `emr_pmtct_other`            VARCHAR(255),       -- Q34
    `emr_at_lab`                 ENUM('Yes','No'),   -- Q35
    `emr_lab_other`              VARCHAR(255),       -- Q36
    `lab_manifest_in_use`        ENUM('Yes','No'),   -- Q37
    `tibu_lite_lims_in_use`      VARCHAR(50),        -- Q38 (Yes/No/Partial)
    `emr_at_pharmacy`            ENUM('Yes','No'),   -- Q39
    `emr_pharmacy_other`         VARCHAR(255),       -- Q40

    -- ── Section 3: HRH Transition (Workforce Absorption) ─────────────────────
    `pharmacy_webadt_in_use`     ENUM('Yes','No'),   -- Q41
    `emr_interoperable_his`      ENUM('Yes','No'),   -- Q42

    -- Total HCWs supported by PEPFAR IP
    `hcw_total_pepfar`           INT,                -- Q43
    `hcw_clinical_pepfar`        INT,                -- Q44
    `hcw_nonclinical_pepfar`     INT,                -- Q45
    `hcw_data_pepfar`            INT,                -- Q46
    `hcw_community_pepfar`       INT,                -- Q47
    `hcw_other_pepfar`           INT,                -- Q48

    -- HCWs transitioned to County (Oct–Dec 25)
    `hcw_transitioned_total`     INT,                -- Q49 heading
    `hcw_transitioned_clinical`  INT,                -- Q50
    `hcw_transitioned_nonclinical` INT,              -- Q51
    `hcw_transitioned_data`      INT,                -- Q52

    -- ── Section 4: PLHIV & PBFW Enrollment into SHA ───────────────────────────
    `hcw_transitioned_community` INT,                -- Q53
    `hcw_transitioned_other`     INT,                -- Q54
    `hcw_transitioned_subtotal`  INT,                -- Q55 total under PEPFAR
    `plhiv_enrolled_sha`         INT,                -- Q56
    `plhiv_sha_premium_paid`     INT,                -- Q57
    `pbfw_enrolled_sha`          INT,                -- Q58

    -- ── Section 5: County Led Technical Assistance / Mentorship ──────────────
    `pbfw_sha_premium_paid`      INT,                -- Q59
    `sha_claims_submitted_ontime` ENUM('Yes','No'),  -- Q60
    `sha_reimbursements_monthly`  ENUM('Yes','No'),  -- Q61

    -- ── Section 6: Financing and Sustainability ───────────────────────────────
    `ta_visits_total`            INT,                -- Q62
    `ta_visits_moh_only`         INT,                -- Q63
    `fif_collection_in_place`    ENUM('Yes','No'),   -- Q64

    -- ── Section 7: Mortality Outcomes ─────────────────────────────────────────
    `fif_includes_hiv_tb_pmtct`  ENUM('Yes','No'),   -- Q65
    `sha_capitation_hiv_tb`      ENUM('Yes','No'),   -- Q66
    `deaths_all_cause`           INT,                -- Q67
    `deaths_hiv_related`         INT,                -- Q68
    `deaths_hiv_pre_art`         INT,                -- Q69
    `deaths_tb`                  INT,                -- Q70
    `deaths_maternal`            INT,                -- Q71
    `deaths_perinatal`           INT,                -- Q72

    -- ── Administration ────────────────────────────────────────────────────────
    `collected_by`               VARCHAR(255),       -- Signed-in user full_name
    `collection_date`            DATE,
    `created_at`                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_facility_id`  (`facility_id`),
    INDEX `idx_period`       (`assessment_period`),
    INDEX `idx_county`       (`county_name`),
    INDEX `idx_collected_by` (`collected_by`),

    CONSTRAINT `fk_ia_facility`
        FOREIGN KEY (`facility_id`) REFERENCES `facilities`(`facility_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ── EMR Systems child table (repeating entry for Q22) ─────────────────────────
CREATE TABLE `integration_assessment_emr_systems` (
    `emr_system_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `assessment_id`   INT NOT NULL,
    `facility_id`     INT NOT NULL,
    `emr_type`        VARCHAR(255),   -- e.g. KenyaEMR, OpenMRS, etc.
    `funded_by`       VARCHAR(255),
    `date_started`    DATE,
    `sort_order`      TINYINT UNSIGNED DEFAULT 1,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_assessment` (`assessment_id`),

    CONSTRAINT `fk_emr_assessment`
        FOREIGN KEY (`assessment_id`) REFERENCES `integration_assessments`(`assessment_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
