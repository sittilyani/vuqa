-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 01, 2026 at 10:37 PM
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
-- Table structure for table `asset_master_register`
--

CREATE TABLE `asset_master_register` (
  `asset_id` int UNSIGNED NOT NULL,
  `category_id` int UNSIGNED DEFAULT NULL COMMENT 'FK → asset_categories.category_id',
  `asset_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'e.g. Laptop, Desktop Computer',
  `asset_category` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'e.g. Computer and ICT Accessories',
  `description` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'e.g. HP Probook 430 G4',
  `model` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `serial_number` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Unique hardware serial',
  `date_of_acquisition` date DEFAULT NULL COMMENT 'Purchase / donation date',
  `age_at_acquisition` decimal(5,1) DEFAULT NULL COMMENT 'Years old at time of acquisition',
  `purchase_value` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Unit purchase value (KES)',
  `depreciation_percentage` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'Annual reducing-balance rate (0-100)',
  `current_value` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Auto-calculated current book value',
  `lpo_number` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Local Purchase Order / reference number',
  `dig_funder_name` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Funding organisation',
  `project_name` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Project / programme name',
  `acquisition_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Donation, Purchase, Lease, Transfer, etc.',
  `current_condition` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Good',
  `date_of_disposal` date DEFAULT NULL,
  `comments` text COLLATE utf8mb4_general_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Master register of all physical/digital assets procured by LVCT Health';

--
-- Dumping data for table `asset_master_register`
--

INSERT INTO `asset_master_register` (`asset_id`, `category_id`, `asset_name`, `asset_category`, `description`, `model`, `serial_number`, `date_of_acquisition`, `age_at_acquisition`, `purchase_value`, `depreciation_percentage`, `current_value`, `lpo_number`, `dig_funder_name`, `project_name`, `acquisition_type`, `current_condition`, `date_of_disposal`, `comments`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 14, 'Digital Chest X-Ray machine', 'Office Equipment', 'Digital Chest X-Ray machine', '14IRH10', '48151', '2025-09-22', '0.6', '13911620.38', '13.00', '12826178.58', 'PO-25-01509', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'Health Masters', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(2, 14, 'Digital Chest X-Ray machine', 'Office Equipment', 'Digital Chest X-Ray machine', '14IRH10', '48485', '2025-09-22', '0.6', '13911620.38', '13.00', '12826178.58', 'PO-25-01509', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'Health Masters', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(3, 14, 'Dayliff Power Generator', 'Office Equipment', 'Dayliff Power Generator', 'P50P2', '1612000005', '2021-07-01', '4.8', '2441308.00', '13.00', '1245371.61', 'N/A', 'Pathfinder International', 'Stawisha', 'Donation', 'Good', NULL, 'Pathfinder International', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(4, 19, 'TP Link M7200 4G LTE MiFi', 'Power Backups', 'TP Link M7200 4G LTE MiFi', 'M7200 4G', '2233323000205', '2024-02-29', '2.2', '9048.00', '10.00', '7201.31', 'PO-24-00613', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'BRIGHT TECHNOLOGIES', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(5, 19, 'TP Link M7200 4G LTE MiFi', 'Power Backups', 'TP Link M7200 4G LTE MiFi', 'M7200 4G', '2233323000968', '2024-02-29', '2.2', '9048.00', '10.00', '7201.31', 'PO-24-00613', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'BRIGHT TECHNOLOGIES', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(6, 19, 'TP Link M7200 4G LTE MiFi', 'Power Backups', 'TP Link M7200 4G LTE MiFi', 'M7200 4G', '2233323000974', '2024-02-29', '2.2', '9048.00', '10.00', '7201.31', 'PO-24-00613', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'BRIGHT TECHNOLOGIES', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(7, 19, 'TP Link M7200 4G LTE MiFi', 'Power Backups', 'TP Link M7200 4G LTE MiFi', 'CPH2531', '2233323002351', '2024-02-29', '2.2', '9048.00', '10.00', '7201.31', 'PO-24-00613', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'BRIGHT TECHNOLOGIES', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(8, 19, 'TP Link M7200 4G LTE MiFi', 'Power Backups', 'TP Link M7200 4G LTE MiFi', 'CPH2531', '2233323002352', '2024-02-29', '2.2', '9048.00', '10.00', '7201.31', 'PO-24-00613', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'BRIGHT TECHNOLOGIES', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(9, 14, 'Von Microwave', 'Office Equipment', 'Von Microwave', 'SM-T225', '6161106504893', '2022-08-17', '3.7', '10995.00', '13.00', '6598.31', 'N/A', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'NAIVAS SUPERMARKET', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(10, 14, 'Ramtons Fridge', 'Office Equipment', 'Ramtons Fridge', 'N/A', '6162003202301', '2022-08-17', '3.7', '37300.00', '13.00', '22384.43', 'N/A', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'NAIVAS SUPERMARKET', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(11, 14, 'SECA Weighing Scales', 'Office Equipment', 'SECA Weighing Scales', 'M7200 4G', '10000001240063', '2024-04-30', '2.0', '55680.00', '13.00', '42144.19', 'PO-24-01361', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'CROWN HEALTHCARE', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(12, 14, 'SECA Weighing Scales', 'Office Equipment', 'SECA Weighing Scales', 'CPH2603', '10000001247527', '2024-04-30', '2.0', '55680.00', '13.00', '42144.19', 'PO-24-01361', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'CROWN HEALTHCARE', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(13, 14, 'Von cooker', 'Office Equipment', 'Von cooker', 'VAAN184CMWR', '21076775150100', '2021-09-13', '4.6', '27995.00', '13.00', '14786.90', 'Cash', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'Anand Supermarket', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(14, 14, 'Von Air Conditioner', 'Office Equipment', 'Von Air Conditioner', 'CPH2505', '34008536012160', '2023-04-01', '3.1', '151000.00', '13.00', '98286.67', 'PO-23-00839', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'HOT POINT APPLIANCES', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(15, 4, 'Samsung Galaxy A11 Tab', 'Tablet', 'Samsung Galaxy A11 Tab', 'SM-X135', '357778642794408', '2026-03-18', '0.1', '22388.00', '20.00', '21975.54', 'PO-26-00143', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'COMPUTERWAYS LIMITED', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(16, 4, 'Samsung Galaxy A11 Tab', 'Tablet', 'Samsung Galaxy A11 Tab', 'SM-X135', '357778642794416', '2026-03-18', '0.1', '22388.00', '20.00', '21975.54', 'PO-26-00143', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'COMPUTERWAYS LIMITED', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(17, 4, 'Samsung Galaxy A11 Tab', 'Tablet', 'Samsung Galaxy A11 Tab', 'SM-X135', '357778642794457', '2026-03-18', '0.1', '22388.00', '20.00', '21975.54', 'PO-26-00143', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'COMPUTERWAYS LIMITED', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(18, 4, 'Samsung Galaxy A11 Tab', 'Tablet', 'Samsung Galaxy A11 Tab', 'SM-X135', '357778642794473', '2026-03-18', '0.1', '22388.00', '20.00', '21975.54', 'PO-26-00143', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'COMPUTERWAYS LIMITED', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(19, 4, 'Samsung Galaxy A11 Tab', 'Tablet', 'Samsung Galaxy A11 Tab', 'SM-X135', '357778642794614', '2026-03-18', '0.1', '22388.00', '20.00', '21975.54', 'PO-26-00143', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'COMPUTERWAYS LIMITED', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14'),
(20, 4, 'Samsung Galaxy A11 Tab', 'Tablet', 'Samsung Galaxy A11 Tab', 'SM-X135', '357778642794630', '2026-03-18', '0.1', '22388.00', '20.00', '21975.54', 'PO-26-00143', 'LVCT Health', 'Stawisha', 'Purchase', 'Good', NULL, 'COMPUTERWAYS LIMITED', 1, 'Super Admin', '2026-05-01 01:35:28', '2026-05-02 01:20:14');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `asset_master_register`
--
ALTER TABLE `asset_master_register`
  ADD PRIMARY KEY (`asset_id`),
  ADD KEY `idx_amr_cat_id` (`category_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `asset_master_register`
--
ALTER TABLE `asset_master_register`
  MODIFY `asset_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3035;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
