-- ============================================================
--  LVCT Health — Asset Restructure Migration
--  Run ONCE against the `transition` database
--  Generated: 2026-05-01
--
--  Changes:
--   1. asset_master_register  → add category_id FK,
--                               drop asset_name, asset_category (text),
--                               drop depreciation_percentage (now via JOIN)
--   2. digital_investments_assets → simplify to category_id + description only
-- ============================================================

SET SQL_MODE   = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone  = "+03:00";
SET FOREIGN_KEY_CHECKS = 0;

-- ── STEP 1: Add category_id to asset_master_register ─────────────────
ALTER TABLE `asset_master_register`
  ADD COLUMN `category_id` INT UNSIGNED DEFAULT NULL
      COMMENT 'FK → asset_categories.category_id'
      AFTER `asset_id`,
  ADD INDEX `idx_amr_cat_id` (`category_id`);

-- ── STEP 2: Populate category_id from existing text match ─────────────
UPDATE `asset_master_register` amr
JOIN   `asset_categories` ac ON amr.asset_category = ac.category_name
SET    amr.category_id = ac.category_id;

-- ── STEP 3: Add FK constraint ─────────────────────────────────────────
ALTER TABLE `asset_master_register`
  ADD CONSTRAINT `fk_amr_category`
      FOREIGN KEY (`category_id`)
      REFERENCES `asset_categories` (`category_id`)
      ON DELETE SET NULL ON UPDATE CASCADE;

-- ── STEP 4: Drop old denormalised columns ─────────────────────────────
ALTER TABLE `asset_master_register`
  DROP COLUMN `asset_name`,
  DROP COLUMN `asset_category`,
  DROP COLUMN `depreciation_percentage`;
-- NOTE: current_value stays — it is recalculated via JOIN on page load.

-- ── STEP 5: Restructure digital_investments_assets ────────────────────
ALTER TABLE `digital_investments_assets`
  ADD COLUMN `category_id` INT UNSIGNED DEFAULT NULL
      COMMENT 'FK → asset_categories.category_id'
      AFTER `dig_id`,
  ADD INDEX `idx_dia_cat_id` (`category_id`);

-- ── STEP 6: Populate category_id from existing text match ─────────────
UPDATE `digital_investments_assets` dia
JOIN   `asset_categories` ac ON dia.asset_category = ac.category_name
SET    dia.category_id = ac.category_id;

-- ── STEP 7: Add FK constraint ─────────────────────────────────────────
ALTER TABLE `digital_investments_assets`
  ADD CONSTRAINT `fk_dia_category`
      FOREIGN KEY (`category_id`)
      REFERENCES `asset_categories` (`category_id`)
      ON DELETE SET NULL ON UPDATE CASCADE;

-- ── STEP 8: Drop old columns from digital_investments_assets ──────────
ALTER TABLE `digital_investments_assets`
  DROP COLUMN `dit_asset_name`,
  DROP COLUMN `depreciation_percentage`,
  DROP COLUMN `asset_category`,
  DROP COLUMN `is_active`,
  DROP COLUMN `created_at`,
  DROP COLUMN `updated_at`;

-- Final structure of digital_investments_assets:
--   dig_id        INT UNSIGNED  PK AUTO_INCREMENT
--   category_id   INT UNSIGNED  FK → asset_categories
--   description   TEXT          (specific item label, e.g. "HP ProBook 430 G4")

SET FOREIGN_KEY_CHECKS = 1;

-- ── VERIFICATION QUERIES (run after migration) ────────────────────────
-- SELECT category_id, COUNT(*) FROM asset_master_register GROUP BY category_id;
-- SELECT category_id, COUNT(*) FROM digital_investments_assets GROUP BY category_id;
-- SELECT amr.asset_id, amr.description, ac.category_name, ac.depreciation_percentage
--   FROM asset_master_register amr
--   LEFT JOIN asset_categories ac ON amr.category_id = ac.category_id LIMIT 10;
