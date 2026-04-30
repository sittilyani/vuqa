-- ============================================================
--  LVCT Health — Asset Master Register
--  Run this ONCE against the `transition` database
--  Generated: 2026-04-29
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+03:00";  -- Africa/Nairobi

-- ── 1. ASSET MASTER REGISTER TABLE ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `asset_master_register` (
  `asset_id`                int UNSIGNED     NOT NULL AUTO_INCREMENT,
  `asset_name`              varchar(150)     NOT NULL           COMMENT 'e.g. Laptop, Desktop Computer',
  `asset_category`          varchar(100)     DEFAULT NULL       COMMENT 'e.g. Computer and ICT Accessories',
  `description`             varchar(255)     DEFAULT NULL       COMMENT 'e.g. HP Probook 430 G4',
  `model`                   varchar(100)     DEFAULT NULL,
  `serial_number`           varchar(100)     DEFAULT NULL       COMMENT 'Unique hardware serial',
  `date_of_acquisition`     date             DEFAULT NULL       COMMENT 'Purchase / donation date',
  `age_at_acquisition`      decimal(5,1)     DEFAULT NULL       COMMENT 'Years old at time of acquisition',
  `purchase_value`          decimal(15,2)    NOT NULL DEFAULT 0 COMMENT 'Unit purchase value (KES)',
  `depreciation_percentage` decimal(5,2)     NOT NULL DEFAULT 0 COMMENT 'Annual reducing-balance rate (0-100)',
  `current_value`           decimal(15,2)    NOT NULL DEFAULT 0 COMMENT 'Auto-calculated current book value',
  `lpo_number`              varchar(100)     DEFAULT NULL       COMMENT 'Local Purchase Order / reference number',
  `dig_funder_name`         varchar(150)     DEFAULT NULL       COMMENT 'Funding organisation',
  `project_name`            varchar(150)     DEFAULT NULL       COMMENT 'Project / programme name',
  `acquisition_type`        varchar(50)      DEFAULT NULL       COMMENT 'Donation, Purchase, Lease, Transfer, etc.',
  `current_condition`       enum('Good','Fair','Poor','Disposed') NOT NULL DEFAULT 'Good',
  `date_of_disposal`        date             DEFAULT NULL,
  `comments`                text             DEFAULT NULL,
  `is_active`               tinyint(1)       NOT NULL DEFAULT 1,
  `created_by`              varchar(150)     DEFAULT NULL,
  `created_at`              datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`              datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`asset_id`),
  KEY `idx_amr_name`        (`asset_name`),
  KEY `idx_amr_category`    (`asset_category`),
  KEY `idx_amr_serial`      (`serial_number`),
  KEY `idx_amr_funder`      (`dig_funder_name`),
  KEY `idx_amr_active`      (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master register of all physical/digital assets procured by LVCT Health';


-- ── 2. ALTER digital_innovation_investments ─────────────────────────────
--  Add new columns (safe — uses IF NOT EXISTS via IGNORE or conditional)
--  Run each ALTER separately if your MySQL version complains.

ALTER TABLE `digital_innovation_investments`
  ADD COLUMN `asset_id`            int UNSIGNED    DEFAULT NULL
        COMMENT 'FK → asset_master_register.asset_id'
        AFTER `invest_id`,

  ADD COLUMN `tag_name`            varchar(100)    DEFAULT NULL
        COMMENT 'Physical asset tag / barcode label'
        AFTER `asset_name`,

  ADD COLUMN `quantity`            int UNSIGNED    NOT NULL DEFAULT 1
        COMMENT 'Units issued to this facility'
        AFTER `tag_name`,

  ADD COLUMN `total_cost`          decimal(15,2)   NOT NULL DEFAULT 0.00
        COMMENT 'quantity × purchase_value at time of issue'
        AFTER `quantity`,

  ADD COLUMN `latitude`            decimal(10,7)   DEFAULT NULL
        COMMENT 'Facility latitude from facilities table'
        AFTER `subcounty_name`,

  ADD COLUMN `longitude`           decimal(10,7)   DEFAULT NULL
        COMMENT 'Facility longitude from facilities table'
        AFTER `latitude`,

  ADD COLUMN `name_of_user`        varchar(150)    DEFAULT NULL
        COMMENT 'Staff member / user the asset is assigned to',

  ADD COLUMN `department_name`     varchar(100)    DEFAULT NULL
        COMMENT 'Department / service point (e.g. CCC, MCH)',

  ADD COLUMN `date_of_verification` date           DEFAULT NULL
        COMMENT 'Most recent physical verification date',

  ADD COLUMN `date_of_disposal`    date            DEFAULT NULL;


-- ── 3. Add indexes for new columns ─────────────────────────────────────
ALTER TABLE `digital_innovation_investments`
  ADD INDEX IF NOT EXISTS `idx_invest_asset_id`  (`asset_id`),
  ADD INDEX IF NOT EXISTS `idx_invest_tag`       (`tag_name`),
  ADD INDEX IF NOT EXISTS `idx_invest_geo`       (`latitude`, `longitude`);


-- ── 4. Foreign key from investments → asset_master_register ────────────
--  Only add if it doesn't exist yet
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME         = 'digital_innovation_investments'
    AND CONSTRAINT_NAME    = 'fk_invest_asset_register'
);

SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE `digital_innovation_investments`
   ADD CONSTRAINT `fk_invest_asset_register`
   FOREIGN KEY (`asset_id`) REFERENCES `asset_master_register` (`asset_id`)
   ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1 -- FK already exists'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ── 5. Auto-recalculate current_value in asset_master_register (EVENT) ─
--  Requires event_scheduler = ON in MySQL config
DROP EVENT IF EXISTS `evt_amr_recalc_values`;

CREATE EVENT `evt_amr_recalc_values`
  ON SCHEDULE EVERY 1 MONTH
  STARTS (DATE_FORMAT(NOW(), '%Y-%m-01') + INTERVAL 1 MONTH)
  COMMENT 'Monthly reducing-balance recalc for asset_master_register'
  DO
    UPDATE `asset_master_register`
    SET    current_value = GREATEST(0,
             purchase_value * POW(
               1 - (depreciation_percentage / 100),
               TIMESTAMPDIFF(MONTH, date_of_acquisition, NOW()) / 12
             )
           ),
           updated_at = NOW()
    WHERE  is_active = 1
      AND  date_of_acquisition IS NOT NULL
      AND  depreciation_percentage > 0;
