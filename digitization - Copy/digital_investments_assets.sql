-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Apr 29, 2026 at 05:59 PM
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
-- Table structure for table `digital_investments_assets`
--

CREATE TABLE `digital_investments_assets` (
  `dig_id` int UNSIGNED NOT NULL,
  `dit_asset_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. Desktop Computer, Router, Smartphone',
  `depreciation_percentage` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'Annual reducing-balance rate  (0-100)',
  `asset_category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional grouping (Hardware, Network, etc.)',
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Catalogue of digital asset types with depreciation rates';

--
-- Dumping data for table `digital_investments_assets`
--

INSERT INTO `digital_investments_assets` (`dig_id`, `dit_asset_name`, `depreciation_percentage`, `asset_category`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Desktop Computer', '33.33', 'Hardware', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(2, 'Laptop Computer', '33.33', 'Hardware', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(3, 'Tablet / iPad', '33.33', 'Hardware', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(4, 'Smartphone / Feature Phone', '33.33', 'Hardware', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(5, 'Network Router', '25.00', 'Network', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(6, 'Network Switch', '25.00', 'Network', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(7, 'Wireless Access Point', '25.00', 'Network', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(8, 'UPS / Battery Backup', '20.00', 'Power', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(9, 'Printer', '25.00', 'Hardware', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(10, 'Scanner', '25.00', 'Hardware', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(11, 'Projector', '20.00', 'Hardware', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(12, 'Server (Physical)', '20.00', 'Infrastructure', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(13, 'External Hard Drive', '33.33', 'Storage', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(14, 'Internet Modem (USB)', '33.33', 'Network', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(15, 'Solar Panel / Inverter', '10.00', 'Power', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(16, 'CCTV / Security Camera', '20.00', 'Security', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(17, 'Barcode / QR Scanner', '25.00', 'Hardware', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07'),
(18, 'Other Digital Asset', '20.00', 'Other', NULL, 1, '2026-04-24 04:15:07', '2026-04-24 04:15:07');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `digital_investments_assets`
--
ALTER TABLE `digital_investments_assets`
  ADD PRIMARY KEY (`dig_id`),
  ADD KEY `idx_asset_name` (`dit_asset_name`),
  ADD KEY `idx_active` (`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `digital_investments_assets`
--
ALTER TABLE `digital_investments_assets`
  MODIFY `dig_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
