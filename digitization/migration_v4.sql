-- ============================================================
--  LVCT Health — Asset Issuance Actions & Soft-Delete Migration v4
--  Run ONCE against the transition / vuqa database
--  Generated: 2026-05-02
--
--  Changes to assets_issuance_register:
--   • ADD  action_notes    TEXT NULL            — free-text notes on action taken
--   • ADD  action_date     DATETIME NULL        — when the action was taken
--   • MODIFY invest_status to allow new statuses via VARCHAR(50)
--
--  New table:
--   • deleted_issued_items — soft-delete archive of removed issuance records
-- ============================================================

SET SQL_MODE   = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone  = "+03:00";
SET FOREIGN_KEY_CHECKS = 0;

-- ── STEP 1: Add action_notes to assets_issuance_register ─────────────────────
ALTER TABLE `assets_issuance_register`
  ADD COLUMN `action_notes` TEXT NULL
      COMMENT 'Notes captured when an action (return/lost/disposed etc.) is taken'
      AFTER `invest_status`;

-- ── STEP 2: Add action_date to assets_issuance_register ──────────────────────
ALTER TABLE `assets_issuance_register`
  ADD COLUMN `action_date` DATETIME NULL DEFAULT NULL
      COMMENT 'Timestamp when the last status-changing action was performed'
      AFTER `action_notes`;

-- ── STEP 3: Widen invest_status to VARCHAR(50) if it is currently an ENUM ────
--  Safe to run even if already VARCHAR — ALTER just ensures the type is correct.
ALTER TABLE `assets_issuance_register`
  MODIFY COLUMN `invest_status` VARCHAR(50) NOT NULL DEFAULT 'Active'
      COMMENT 'Active | Expired | Returned-Good | Returned-Faulty | Lost | Obsolete | Damaged | Disposed';

-- ── STEP 4: Create deleted_issued_items (soft-delete archive) ─────────────────
CREATE TABLE IF NOT EXISTS `deleted_issued_items` (
  `del_id`                INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `invest_id`             INT UNSIGNED      NOT NULL COMMENT 'Original invest_id',
  `asset_id`              INT UNSIGNED      NULL,
  `asset_name`            VARCHAR(255)      NULL,
  `tag_name`              VARCHAR(100)      NULL,
  `facility_name`         VARCHAR(255)      NULL,
  `mflcode`               VARCHAR(30)       NULL,
  `county_name`           VARCHAR(100)      NULL,
  `subcounty_name`        VARCHAR(100)      NULL,
  `name_of_user`          VARCHAR(255)      NULL,
  `department_name`       VARCHAR(150)      NULL,
  `department_id`         INT               NULL,
  `issue_date`            DATE              NULL,
  `end_date`              DATE              NULL,
  `no_end_date`           TINYINT(1)        NOT NULL DEFAULT 0,
  `invest_status`         VARCHAR(50)       NOT NULL DEFAULT 'Active',
  `action_notes`          TEXT              NULL,
  `action_date`           DATETIME          NULL,
  `service_level`         VARCHAR(100)      NULL,
  `emr_type_id`           INT               NULL,
  `lot_number`            VARCHAR(100)      NULL,
  `purchase_value`        DECIMAL(15,2)     NOT NULL DEFAULT 0.00,
  `current_value`         DECIMAL(15,2)     NOT NULL DEFAULT 0.00,
  `depreciation_percentage` DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
  `date_of_verification`  DATE              NULL,
  `date_of_disposal`      DATE              NULL,
  `dig_funder_id`         INT               NULL,
  `created_by`            VARCHAR(150)      NULL,
  `created_at`            DATETIME          NULL,
  `updated_at`            DATETIME          NULL,
  -- Soft-delete metadata
  `deleted_by`            VARCHAR(150)      NOT NULL COMMENT 'Username of the user who deleted this record',
  `deleted_at`            DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`del_id`),
  KEY `idx_invest_id` (`invest_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Soft-delete archive — records moved here from assets_issuance_register';

SET FOREIGN_KEY_CHECKS = 1;

-- ── VERIFICATION ─────────────────────────────────────────────────────────────
-- DESCRIBE assets_issuance_register;
-- SHOW COLUMNS FROM deleted_issued_items;
-- SELECT invest_status, COUNT(*) FROM assets_issuance_register GROUP BY invest_status;
