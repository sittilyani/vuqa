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
-- Structure for view `v_funder_investment_totals`
--

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_funder_investment_totals`  AS SELECT `f`.`dig_funder_id` AS `dig_funder_id`, `f`.`dig_funder_name` AS `dig_funder_name`, `f`.`funder_type` AS `funder_type`, count(`i`.`invest_id`) AS `total_assets`, round(sum(`i`.`purchase_value`),2) AS `total_purchase_value`, round(sum(`i`.`current_value`),2) AS `total_current_value` FROM (`digital_funders` `f` left join `digital_innovation_investments` `i` on((`f`.`dig_funder_id` = `i`.`dig_funder_id`))) GROUP BY `f`.`dig_funder_id`, `f`.`dig_funder_name`, `f`.`funder_type``funder_type`  ;

--
-- VIEW `v_funder_investment_totals`
-- Data: None
--

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
