-- ============================================================
--  LVCT Health — Asset Status Tracking Migration v3
--  Run ONCE against the transition / vuqa database
--  Generated: 2026-05-02
--
--  Changes to asset_master_register:
--   • ADD  asset_status  ENUM('In Stock','Issued','Disposed') DEFAULT 'In Stock'
--   • ADD  date_of_issue DATE NULL
--
--  After running this:
--   1. All existing records default to 'In Stock'
--   2. Run the UPDATE below to back-fill assets that appear
--      in assets_issuance_register (or digital_innovation_investments)
-- ============================================================

SET SQL_MODE   = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone  = "+03:00";
SET FOREIGN_KEY_CHECKS = 0;

-- ── STEP 1: Add asset_status column ──────────────────────────────────────────
ALTER TABLE `asset_master_register`
  ADD COLUMN `asset_status` VARCHAR (100) NOT NULL DEFAULT 'In Stock'
      COMMENT 'Tracks whether asset is available, issued, or disposed'
      AFTER `current_condition`;

-- ── STEP 2: Add date_of_issue column ─────────────────────────────────────────
ALTER TABLE `asset_master_register`
  ADD COLUMN `date_of_issue` DATE DEFAULT NULL
      COMMENT 'Date asset was last issued (from issuance register)'
      AFTER `asset_status`;

-- ── STEP 3: Back-fill asset_status for already-issued assets ─────────────────
--  Works whether table is still named digital_innovation_investments or
--  already renamed to assets_issuance_register.
--  Run whichever applies to your current state:

-- If you have already run migration_v2.sql (table renamed):
UPDATE `asset_master_register` amr
JOIN (
    SELECT asset_id, MIN(issue_date) AS first_issue
    FROM `assets_issuance_register`
    WHERE asset_id IS NOT NULL
    GROUP BY asset_id
) air ON amr.asset_id = air.asset_id
SET amr.asset_status  = 'Issued',
    amr.date_of_issue = air.first_issue,
    amr.updated_at    = NOW();

-- If you have NOT yet run migration_v2.sql (old table name):
-- UPDATE `asset_master_register` amr
-- JOIN (
--     SELECT asset_id, MIN(issue_date) AS first_issue
--     FROM `digital_innovation_investments`
--     WHERE asset_id IS NOT NULL
--     GROUP BY asset_id
-- ) dii ON amr.asset_id = dii.asset_id
-- SET amr.asset_status  = 'Issued',
--     amr.date_of_issue = dii.first_issue,
--     amr.updated_at    = NOW();

SET FOREIGN_KEY_CHECKS = 1;

-- ── VERIFICATION ─────────────────────────────────────────────────────────────
-- SELECT asset_status, COUNT(*) AS cnt FROM asset_master_register GROUP BY asset_status;
-- SELECT asset_id, description, asset_status, date_of_issue
-- FROM asset_master_register WHERE asset_status = 'Issued' LIMIT 10;
