-- ============================================================
--  LVCT Health — Asset Master Register Restructure Migration
--  Run ONCE against the `transition` database
--  Generated: 2026-05-01
--
--  Change summary for asset_master_register:
--   • ADD  category_id  INT FK → asset_categories
--   • DROP asset_name   only
--   • KEEP asset_category (text), depreciation_percentage,
--          and all other existing columns unchanged
-- ============================================================

SET SQL_MODE   = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone  = "+03:00";
SET FOREIGN_KEY_CHECKS = 0;

-- ── STEP 1: Add category_id column ───────────────────────────────────
ALTER TABLE `asset_master_register`
  ADD COLUMN `category_id` INT UNSIGNED DEFAULT NULL
      COMMENT 'FK → asset_categories.category_id'
      AFTER `asset_id`,
  ADD INDEX `idx_amr_cat_id` (`category_id`);

-- ── STEP 2: Populate category_id from existing asset_category text ───
UPDATE `asset_master_register` amr
JOIN   `asset_categories` ac ON amr.asset_category = ac.category_name
SET    amr.category_id = ac.category_id;

-- ── STEP 3: Also sync depreciation_percentage from asset_categories ──
--  (ensures stored value matches the category rate)
UPDATE `asset_master_register` amr
JOIN   `asset_categories` ac ON amr.category_id = ac.category_id
SET    amr.depreciation_percentage = ac.depreciation_percentage;

-- ── STEP 4: Add FK constraint ─────────────────────────────────────────
ALTER TABLE `asset_master_register`
  ADD CONSTRAINT `fk_amr_category`
      FOREIGN KEY (`category_id`)
      REFERENCES `asset_categories` (`category_id`)
      ON DELETE SET NULL ON UPDATE CASCADE;

-- ── STEP 5: Drop asset_name only ─────────────────────────────────────
ALTER TABLE `asset_master_register`
  DROP COLUMN `asset_name`;

-- All other columns remain:
--   asset_id, category_id (new FK), asset_category (text kept),
--   description, model, serial_number, date_of_acquisition,
--   age_at_acquisition, purchase_value, depreciation_percentage (kept),
--   current_value, lpo_number, dig_funder_name, project_name,
--   acquisition_type, current_condition, date_of_disposal,
--   comments, is_active, created_by, created_at, updated_at

SET FOREIGN_KEY_CHECKS = 1;

-- ── VERIFICATION (run after migration) ───────────────────────────────
-- SELECT amr.asset_id, amr.category_id, ac.category_name,
--        amr.asset_category, amr.depreciation_percentage, amr.description
-- FROM asset_master_register amr
-- LEFT JOIN asset_categories ac ON amr.category_id = ac.category_id
-- LIMIT 10;
