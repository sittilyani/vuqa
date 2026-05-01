-- ============================================================
--  LVCT Health — New Lookup Tables
--  Run ONCE against the `transition` database
--  Generated: 2026-05-01
-- ============================================================

SET SQL_MODE   = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone  = "+03:00";

-- ── 1. ASSET CATEGORIES (with depreciation rate) ─────────────
CREATE TABLE IF NOT EXISTS `categories` (
  `category_id`          int UNSIGNED   NOT NULL AUTO_INCREMENT,
  `category_name`        varchar(120)   NOT NULL,
  `depreciation_percent` decimal(5,2)   NOT NULL DEFAULT 0.00
                         COMMENT 'Annual reducing-balance rate (0-100)',
  `created_at`           datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uq_cat_name` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Asset categories with standard depreciation rates';

INSERT IGNORE INTO `categories` (`category_name`, `depreciation_percent`) VALUES
  ('Computer and ICT Accessories', 33.33),
  ('Network Equipment',            25.00),
  ('Power Equipment',              20.00),
  ('Security Equipment',           20.00),
  ('Peripheral Devices',           33.33),
  ('Infrastructure',               10.00),
  ('Software License',             33.33),
  ('Server / NAS',                 20.00),
  ('Mobile Devices',               33.33),
  ('Printers / Scanners',          25.00),
  ('Other',                        20.00);


-- ── 2. ORGANIZATIONS (project / programme names) ─────────────
CREATE TABLE IF NOT EXISTS `organizations` (
  `org_id`     int UNSIGNED NOT NULL AUTO_INCREMENT,
  `org_name`   varchar(200) NOT NULL,
  `org_type`   varchar(100) DEFAULT NULL
               COMMENT 'e.g. Donor, Implementing Partner, Government',
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`org_id`),
  UNIQUE KEY `uq_org_name` (`org_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Organisations / funders / projects for asset procurement';

-- Seed with LVCT common projects (add more as needed)
INSERT IGNORE INTO `organizations` (`org_name`, `org_type`) VALUES
  ('LVCT Health',            'Implementing Partner'),
  ('USAID / PEPFAR',         'Donor'),
  ('Global Fund',            'Donor'),
  ('CDC Kenya',              'Donor'),
  ('MOH Kenya',              'Government'),
  ('County Government',      'Government'),
  ('UNITAID',                'Donor'),
  ('ELMA Philanthropies',    'Donor'),
  ('Stawisha',               'Programme'),
  ('Afya Nyota',             'Programme'),
  ('Other',                  NULL);


-- ── 3. ACQUISITION TYPES ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `acquisitions` (
  `acq_id`     int UNSIGNED NOT NULL AUTO_INCREMENT,
  `acq_name`   varchar(80)  NOT NULL,
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`acq_id`),
  UNIQUE KEY `uq_acq_name` (`acq_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Asset acquisition method types';

INSERT IGNORE INTO `acquisitions` (`acq_name`) VALUES
  ('Purchase'),
  ('Donation'),
  ('Grant'),
  ('Lease'),
  ('Transfer'),
  ('Loan'),
  ('Other');
