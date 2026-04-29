-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Apr 29, 2026 at 06:14 PM
-- Server version: 8.0.30
-- PHP Version: 8.1.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `transition`
--

-- --------------------------------------------------------

--
-- Structure for view `v_digital_investments_summary`
--

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_digital_investments_summary`  AS SELECT `i`.`invest_id` AS `invest_id`, `i`.`facility_id` AS `facility_id`, `i`.`facility_name` AS `facility_name`, `i`.`mflcode` AS `mflcode`, `i`.`county_name` AS `county_name`, `i`.`subcounty_name` AS `subcounty_name`, `a`.`dit_asset_name` AS `asset_name`, `a`.`asset_category` AS `asset_category`, `i`.`depreciation_percentage` AS `depreciation_percentage`, `i`.`purchase_value` AS `purchase_value`, `i`.`current_value` AS `current_value`, round((`i`.`purchase_value` - `i`.`current_value`),2) AS `total_depreciation`, round((((`i`.`purchase_value` - `i`.`current_value`) / nullif(`i`.`purchase_value`,0)) * 100),2) AS `depreciation_pct_realised`, `i`.`issue_date` AS `issue_date`, `i`.`end_date` AS `end_date`, `i`.`no_end_date` AS `no_end_date`, timestampdiff(MONTH,`i`.`issue_date`,now()) AS `months_in_service`, `i`.`service_level` AS `service_level`, `f`.`dig_funder_name` AS `dig_funder_name`, `f`.`funder_type` AS `funder_type`, `s`.`sdp_name` AS `sdp_name`, `e`.`emr_type_name` AS `emr_type_name`, `i`.`lot_number` AS `lot_number`, `i`.`invest_status` AS `invest_status`, `i`.`created_by` AS `created_by`, `i`.`created_at` AS `created_at`, `i`.`updated_at` AS `updated_at` FROM ((((`digital_innovation_investments` `i` join `digital_investments_assets` `a` on((`i`.`dig_id` = `a`.`dig_id`))) left join `digital_funders` `f` on((`i`.`dig_funder_id` = `f`.`dig_funder_id`))) left join `service_delivery_points` `s` on((`i`.`sdp_id` = `s`.`sdp_id`))) left join `emr_types` `e` on((`i`.`emr_type_id` = `e`.`emr_type_id`)))  ;

--
-- VIEW `v_digital_investments_summary`
-- Data: None
--

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
