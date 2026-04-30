-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Apr 29, 2026 at 06:15 PM
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
-- Structure for view `v_facility_investment_totals`
--

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_facility_investment_totals`  AS SELECT `digital_innovation_investments`.`facility_id` AS `facility_id`, `digital_innovation_investments`.`facility_name` AS `facility_name`, `digital_innovation_investments`.`mflcode` AS `mflcode`, `digital_innovation_investments`.`county_name` AS `county_name`, `digital_innovation_investments`.`subcounty_name` AS `subcounty_name`, count(0) AS `total_assets`, sum((`digital_innovation_investments`.`invest_status` = 'Active')) AS `active_assets`, sum((`digital_innovation_investments`.`invest_status` = 'Expired')) AS `expired_assets`, round(sum(`digital_innovation_investments`.`purchase_value`),2) AS `total_purchase_value`, round(sum(`digital_innovation_investments`.`current_value`),2) AS `total_current_value`, round((sum(`digital_innovation_investments`.`purchase_value`) - sum(`digital_innovation_investments`.`current_value`)),2) AS `total_depreciation` FROM `digital_innovation_investments` GROUP BY `digital_innovation_investments`.`facility_id`, `digital_innovation_investments`.`facility_name`, `digital_innovation_investments`.`mflcode`, `digital_innovation_investments`.`county_name`, `digital_innovation_investments`.`subcounty_name``subcounty_name`  ;

--
-- VIEW `v_facility_investment_totals`
-- Data: None
--

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
