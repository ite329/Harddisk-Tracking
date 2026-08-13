-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 07, 2026 at 09:09 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `harddisk_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `harddisk_claim_returns`
--

CREATE TABLE `harddisk_claim_returns` (
  `id` int(10) UNSIGNED NOT NULL,
  `return_no` varchar(30) NOT NULL,
  `delivery_request_id` int(10) UNSIGNED DEFAULT NULL,
  `request_no` varchar(50) DEFAULT NULL,
  `main_branch_code` varchar(10) NOT NULL,
  `branch_code` varchar(50) NOT NULL,
  `branch_name` varchar(255) NOT NULL,
  `hdd_serial` varchar(100) NOT NULL,
  `claim_reason` varchar(255) NOT NULL,
  `hdd_condition` varchar(255) DEFAULT NULL,
  `return_tracking_no` varchar(100) DEFAULT NULL,
  `received_by` varchar(255) NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` varchar(30) NOT NULL DEFAULT 'received',
  `sent_claim_at` datetime DEFAULT NULL,
  `claim_result` varchar(255) DEFAULT NULL,
  `remark` text DEFAULT NULL,
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `harddisk_claim_returns`
--
ALTER TABLE `harddisk_claim_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_return_no` (`return_no`),
  ADD KEY `idx_branch_code` (`branch_code`),
  ADD KEY `idx_main_branch_code` (`main_branch_code`),
  ADD KEY `idx_hdd_serial` (`hdd_serial`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_received_at` (`received_at`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `harddisk_claim_returns`
--
ALTER TABLE `harddisk_claim_returns`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
