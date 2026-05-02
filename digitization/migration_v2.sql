-- ============================================================
--  LVCT Health — Asset Management Migration v2
--  Run ONCE against the `transition` / `vuqa` database
--  Generated: 2026-05-02
--
--  Changes:
--   1. Make asset_name nullable in asset_master_register
--      (column retained for backward-compat; drop later if desired)
--   2. CREATE departments table
--   3. DROP digital_investments_assets table
--   4. RENAME digital_innovation_investments → assets_issuance_register
--   5. Alter assets_issuance_register:
--        • Add department_id FK → departments
--        • Remove dig_id FK constraint (was → dropped table)
--        • Nullify dig_id column (kept but un-constrained)
--   6. Update view v_digital_investments_summary to reference new name
-- ============================================================

SET SQL_MODE   = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone  = "+03:00";
SET FOREIGN_KEY_CHECKS = 0;

-- ── STEP 1: Make asset_name nullable in asset_master_register ─────────────
--  (the column is kept for now; it mirrors description; drop when safe)
ALTER TABLE `asset_master_register`
  MODIFY COLUMN `asset_name` VARCHAR(150) DEFAULT NULL
    COMMENT 'Legacy — mirrors description. Drop after migration confirmed.';

-- ── STEP 2: Create departments lookup table ───────────────────────────────
CREATE TABLE IF NOT EXISTS `departments` (
  `department_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_name` VARCHAR(100) NOT NULL,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `uq_dept_name` (`department_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Departments / service points for asset assignment';

-- Seed common departments (edit as needed)
INSERT IGNORE INTO `departments` (`department_name`) VALUES
  ('Comprehensive Care Centre (CCC)'),
  ('Maternal & Child Health (MCH)'),
  ('Out-Patient Department (OPD)'),
  ('Laboratory'),
  ('Pharmacy'),
  ('Administration'),
  ('Finance'),
  ('ICT / HIS'),
  ('Nursing'),
  ('Records');

-- ── STEP 3: Drop digital_investments_assets (old generic asset-type table) ─
DROP TABLE IF EXISTS `digital_investments_assets`;

-- ── STEP 4: Rename digital_innovation_investments → assets_issuance_register
RENAME TABLE `digital_innovation_investments` TO `assets_issuance_register`;

-- ── STEP 5a: Add department_id FK column to assets_issuance_register ─────
ALTER TABLE `assets_issuance_register`
  ADD COLUMN `department_id` INT UNSIGNED DEFAULT NULL
      COMMENT 'FK → departments.department_id'
      AFTER `department_name`;

-- ── STEP 5b: Populate department_id from existing department_name text ────
UPDATE `assets_issuance_register` asr
JOIN   `departments` d ON TRIM(asr.department_name) = TRIM(d.department_name)
SET    asr.department_id = d.department_id;

-- ── STEP 5c: Add FK constraint for department_id ─────────────────────────
ALTER TABLE `assets_issuance_register`
  ADD CONSTRAINT `fk_air_department`
      FOREIGN KEY (`department_id`)
      REFERENCES `departments` (`department_id`)
      ON DELETE SET NULL ON UPDATE CASCADE;

-- ── STEP 5d: Drop FK constraint on dig_id (was → digital_investments_assets)
--  Find the constraint name first if different; common generated names below.
--  Try both names — only one will succeed.
ALTER TABLE `assets_issuance_register`
  DROP FOREIGN KEY IF EXISTS `digital_innovation_investments_ibfk_2`;
ALTER TABLE `assets_issuance_register`
  DROP FOREIGN KEY IF EXISTS `fk_diinv_dig`;

-- Nullify dig_id column (no longer references a real table)
ALTER TABLE `assets_issuance_register`
  MODIFY COLUMN `dig_id` INT UNSIGNED DEFAULT NULL
    COMMENT 'Legacy dig_id — no longer FK constrained (table dropped)';

-- ── STEP 6: Recreate views with renamed table ─────────────────────────────

DROP VIEW IF EXISTS `v_digital_investments_summary`;
CREATE VIEW `v_digital_investments_summary` AS
SELECT
    asr.invest_id,
    asr.facility_name,
    asr.mflcode,
    asr.county_name,
    amr.asset_category,
    amr.description          AS asset_description,
    asr.tag_name,
    asr.quantity,
    asr.purchase_value,
    asr.current_value,
    asr.depreciation_percentage,
    asr.issue_date,
    asr.invest_status,
    asr.name_of_user,
    asr.department_name,
    asr.created_by,
    asr.created_at
FROM `assets_issuance_register` asr
LEFT JOIN `asset_master_register` amr ON asr.asset_id = amr.asset_id;

DROP VIEW IF EXISTS `v_facility_investment_totals`;
CREATE VIEW `v_facility_investment_totals` AS
SELECT
    facility_id,
    facility_name,
    county_name,
    COUNT(*)                            AS total_records,
    SUM(purchase_value)                 AS total_purchase_value,
    SUM(current_value)                  AS total_current_value,
    SUM(CASE WHEN invest_status='Active' THEN 1 ELSE 0 END) AS active_count
FROM `assets_issuance_register`
GROUP BY facility_id, facility_name, county_name;

DROP VIEW IF EXISTS `v_funder_investment_totals`;
CREATE VIEW `v_funder_investment_totals` AS
SELECT
    df.dig_funder_name,
    COUNT(asr.invest_id)                AS total_records,
    SUM(asr.purchase_value)             AS total_purchase_value,
    SUM(asr.current_value)              AS total_current_value
FROM `assets_issuance_register` asr
LEFT JOIN `digital_funders` df ON asr.dig_funder_id = df.dig_funder_id
GROUP BY df.dig_funder_name;

SET FOREIGN_KEY_CHECKS = 1;

-- ── VERIFICATION (run after migration) ───────────────────────────────────
-- SHOW TABLES;
-- DESCRIBE assets_issuance_register;
-- DESCRIBE departments;
-- SELECT department_id, department_name FROM departments;
-- SELECT COUNT(*) FROM assets_issuance_register;
-- SELECT * FROM v_digital_investments_summary LIMIT 5;
