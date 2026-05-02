-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 01, 2026 at 10:36 PM
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
-- Database: `vuqa`
--

-- --------------------------------------------------------

--
-- Table structure for table `digital_innovation_investments`
--

CREATE TABLE `digital_innovation_investments` (
  `invest_id` int UNSIGNED NOT NULL,
  `asset_id` int UNSIGNED DEFAULT NULL COMMENT 'FK → asset_master_register.asset_id',
  `facility_id` int UNSIGNED NOT NULL COMMENT 'FK → facilities.facility_id',
  `facility_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `mflcode` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `county_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `subcounty_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL COMMENT 'Facility latitude from facilities table',
  `longitude` decimal(10,7) DEFAULT NULL COMMENT 'Facility longitude from facilities table',
  `dig_id` int UNSIGNED NOT NULL COMMENT 'FK → digital_investments_assets.dig_id',
  `asset_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Snapshot of name at time of entry',
  `tag_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Physical asset tag / barcode label',
  `quantity` int UNSIGNED NOT NULL DEFAULT '1' COMMENT 'Units issued to this facility',
  `total_cost` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'quantity × purchase_value at time of issue',
  `depreciation_percentage` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'Annual rate snapshot',
  `purchase_value` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Original cost (KES)',
  `current_value` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Auto-updated monthly via EVENT',
  `issue_date` date NOT NULL COMMENT 'Date asset was issued/deployed',
  `end_date` date DEFAULT NULL COMMENT 'NULL when no_end_date = 1',
  `no_end_date` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = perpetual (no planned end)',
  `dig_funder_id` int UNSIGNED DEFAULT NULL COMMENT 'FK → digital_funders.dig_funder_id',
  `sdp_id` int UNSIGNED DEFAULT NULL COMMENT 'FK → service_delivery_points.sdp_id; NULL when Facility-wide',
  `emr_type_id` int UNSIGNED DEFAULT NULL COMMENT 'FK → emr_types.emr_type_id',
  `service_level` enum('Facility-wide','Service Delivery Point') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Facility-wide',
  `lot_number` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Optional batch/lot identifier',
  `invest_status` enum('Active','Expired') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Active',
  `created_by` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `name_of_user` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Staff member / user the asset is assigned to',
  `department_name` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Department / service point (e.g. CCC, MCH)',
  `date_of_verification` date DEFAULT NULL COMMENT 'Most recent physical verification date',
  `date_of_disposal` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Digital innovation investments per facility — one row per deployed asset';
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
