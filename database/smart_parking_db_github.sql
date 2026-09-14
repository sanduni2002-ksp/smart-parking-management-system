-- phpMyAdmin SQL Dump
-- GitHub-safe demo database export
-- Personal-looking user/admin details, vehicle registration numbers,
-- password hashes and transaction references have been replaced with demo values.
-- Demo password for seeded admin/user accounts: password
--
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 14, 2026 at 04:31 PM
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
-- Database: `smart_parking_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL,
  `admin_name` varchar(100) NOT NULL,
  `username` varchar(30) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(15) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`admin_id`, `admin_name`, `username`, `email`, `password`, `phone`) VALUES
(1, 'Demo Admin', 'admin1', 'admin1@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0770000001'),
(2, 'Demo Admin 2', 'admin2', 'admin2@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '0770000002');

-- --------------------------------------------------------

--
-- Table structure for table `fines`
--

CREATE TABLE `fines` (
  `fine_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `overstay_minutes` int(10) UNSIGNED NOT NULL,
  `grace_minutes` int(10) UNSIGNED NOT NULL DEFAULT 15,
  `chargeable_minutes` int(10) UNSIGNED NOT NULL,
  `rate_per_hour` decimal(10,2) NOT NULL,
  `fine_amount` decimal(10,2) NOT NULL,
  `status` enum('Pending','Paid','Waived') NOT NULL DEFAULT 'Pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fines`
--

INSERT INTO `fines` (`fine_id`, `reservation_id`, `overstay_minutes`, `grace_minutes`, `chargeable_minutes`, `rate_per_hour`, `fine_amount`, `status`, `created_at`, `paid_at`) VALUES
(2, 19, 65, 15, 50, 200.00, 200.00, 'Paid', '2026-07-28 17:05:00', '2026-07-28 17:10:00'),
(3, 22, 80, 15, 65, 100.00, 200.00, 'Pending', '2026-07-31 15:20:00', NULL),
(4, 42, 45, 15, 30, 200.00, 200.00, 'Pending', '2026-07-13 17:45:00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `parking_slots`
--

CREATE TABLE `parking_slots` (
  `slot_id` int(11) NOT NULL,
  `slot_number` varchar(10) NOT NULL,
  `floor_no` int(11) NOT NULL,
  `slot_type` varchar(20) NOT NULL,
  `access_type` enum('Public','AdminOnly') NOT NULL DEFAULT 'Public',
  `status` enum('Available','Occupied','Maintenance') NOT NULL DEFAULT 'Available'
) ;

--
-- Dumping data for table `parking_slots`
--

INSERT INTO `parking_slots` (`slot_id`, `slot_number`, `floor_no`, `slot_type`, `access_type`, `status`) VALUES
(27, 'A01', 0, 'Car', 'Public', 'Occupied'),
(28, 'A02', 0, 'Car', 'Public', 'Available'),
(29, 'A03', 0, 'Car', 'Public', 'Available'),
(30, 'M01', 0, 'Motorcycle', 'Public', 'Occupied'),
(31, 'M02', 0, 'Motorcycle', 'Public', 'Available'),
(32, 'V01', 0, 'Van', 'Public', 'Available'),
(33, 'V02', 0, 'Van', 'Public', 'Available'),
(34, 'B01', 1, 'Car', 'Public', 'Available'),
(35, 'B02', 1, 'Car', 'Public', 'Available'),
(36, 'B03', 1, 'Car', 'Public', 'Available'),
(37, 'O01', 2, 'Car', 'AdminOnly', 'Available'),
(38, 'O02', 2, 'Van', 'AdminOnly', 'Available'),
(39, 'O03', 2, 'Motorcycle', 'AdminOnly', 'Available');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('Cash','Card','Online') NOT NULL,
  `payment_date` date NOT NULL,
  `payment_status` enum('Pending','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending',
  `paid_at` datetime DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `transaction_reference` varchar(100) DEFAULT NULL
) ;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `reservation_id`, `amount`, `payment_method`, `payment_date`, `payment_status`, `paid_at`, `verified_by`, `transaction_reference`) VALUES
(6, 16, 400.00, 'Cash', '2026-07-25', 'Paid', '2026-07-25 07:50:00', 1, 'DEMO-PAY-001'),
(7, 17, 200.00, 'Card', '2026-07-26', 'Paid', '2026-07-26 08:45:00', NULL, 'DEMO-PAY-002'),
(8, 18, 900.00, 'Online', '2026-07-27', 'Paid', '2026-07-27 09:40:00', NULL, 'DEMO-PAY-003'),
(9, 19, 400.00, 'Cash', '2026-07-28', 'Paid', '2026-07-28 13:50:00', 1, 'DEMO-PAY-004'),
(10, 20, 200.00, 'Card', '2026-07-29', 'Paid', '2026-07-29 07:50:00', NULL, 'DEMO-PAY-005'),
(11, 22, 200.00, 'Cash', '2026-07-31', 'Paid', '2026-07-31 11:50:00', 1, 'DEMO-PAY-006'),
(12, 23, 1200.00, 'Online', '2026-08-01', 'Paid', '2026-08-01 07:40:00', NULL, 'DEMO-PAY-007'),
(13, 24, 400.00, 'Card', '2026-08-02', 'Paid', '2026-08-02 14:40:00', NULL, 'DEMO-PAY-008'),
(14, 25, 400.00, 'Cash', '2026-08-03', 'Paid', '2026-08-03 08:50:00', 1, 'DEMO-PAY-009'),
(15, 26, 600.00, 'Online', '2026-08-04', 'Paid', '2026-08-04 01:03:17', NULL, 'DEMO-PAY-010'),
(16, 27, 200.00, 'Cash', '2026-08-04', 'Pending', NULL, NULL, 'DEMO-PAY-011'),
(17, 28, 400.00, 'Online', '2026-08-04', 'Paid', '2026-08-04 01:03:17', NULL, 'DEMO-PAY-012'),
(18, 30, 400.00, 'Cash', '2026-08-04', 'Pending', NULL, NULL, 'DEMO-PAY-013'),
(19, 31, 200.00, 'Card', '2026-08-04', 'Paid', '2026-08-04 01:03:17', NULL, 'DEMO-PAY-014'),
(20, 33, 1200.00, 'Online', '2026-08-04', 'Paid', '2026-08-04 01:03:17', NULL, 'DEMO-PAY-015'),
(21, 34, 400.00, 'Cash', '2026-07-05', 'Paid', '2026-07-05 07:50:00', 1, 'DEMO-PAY-016'),
(22, 35, 400.00, 'Online', '2026-08-04', 'Paid', '2026-08-04 01:11:54', NULL, 'DEMO-PAY-017'),
(23, 36, 200.00, 'Card', '2026-07-07', 'Paid', '2026-07-07 08:45:00', NULL, 'DEMO-PAY-018'),
(24, 38, 400.00, 'Online', '2026-07-09', 'Paid', '2026-07-09 11:45:00', NULL, 'DEMO-PAY-019'),
(25, 39, 600.00, 'Card', '2026-08-04', 'Paid', '2026-08-04 01:11:54', NULL, 'DEMO-PAY-020'),
(26, 40, 1200.00, 'Cash', '2026-07-11', 'Paid', '2026-07-11 07:45:00', 1, 'DEMO-PAY-021'),
(27, 41, 900.00, 'Cash', '2026-08-04', 'Pending', NULL, NULL, 'DEMO-PAY-022'),
(28, 42, 400.00, 'Card', '2026-07-13', 'Paid', '2026-07-13 14:45:00', NULL, 'DEMO-PAY-023'),
(29, 44, 200.00, 'Cash', '2026-07-15', 'Paid', '2026-07-15 09:45:00', 1, 'DEMO-PAY-024'),
(30, 45, 200.00, 'Online', '2026-08-04', 'Paid', '2026-08-04 01:11:54', NULL, 'DEMO-PAY-025'),
(31, 46, 400.00, 'Online', '2026-07-17', 'Paid', '2026-07-17 08:45:00', NULL, 'DEMO-PAY-026'),
(32, 47, 400.00, 'Cash', '2026-08-04', 'Pending', NULL, NULL, 'DEMO-PAY-027'),
(33, 48, 900.00, 'Card', '2026-07-19', 'Paid', '2026-07-19 12:45:00', NULL, 'DEMO-PAY-028'),
(34, 50, 800.00, 'Card', '2026-08-04', 'Paid', '2026-08-04 06:56:05', NULL, 'DEMO-PAY-029'),
(35, 29, 900.00, 'Online', '2026-08-04', 'Paid', '2026-08-04 06:56:34', NULL, 'DEMO-PAY-030'),
(36, 51, 200.00, 'Card', '2026-08-04', 'Paid', '2026-08-04 07:05:59', NULL, 'DEMO-PAY-031'),
(37, 52, 200.00, 'Cash', '2026-08-04', 'Pending', NULL, NULL, 'CASH-52-6C5F91');

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `slot_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `actual_exit_at` datetime DEFAULT NULL,
  `checked_out_by` int(11) DEFAULT NULL,
  `status` enum('Pending','Confirmed','Cancelled','Completed') NOT NULL DEFAULT 'Pending'
) ;

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`reservation_id`, `vehicle_id`, `slot_id`, `start_date`, `end_date`, `start_time`, `end_time`, `actual_exit_at`, `checked_out_by`, `status`) VALUES
(16, 10, 27, '2026-07-25', '2026-07-25', '08:00:00', '10:00:00', '2026-07-25 09:55:00', 1, 'Completed'),
(17, 11, 30, '2026-07-26', '2026-07-26', '09:00:00', '11:00:00', '2026-07-26 11:00:00', 1, 'Completed'),
(18, 12, 32, '2026-07-27', '2026-07-27', '10:00:00', '13:00:00', '2026-07-27 13:10:00', 1, 'Completed'),
(19, 13, 34, '2026-07-28', '2026-07-28', '14:00:00', '16:00:00', '2026-07-28 17:05:00', 1, 'Completed'),
(20, 14, 28, '2026-07-29', '2026-07-29', '08:00:00', '09:00:00', '2026-07-29 09:00:00', 1, 'Completed'),
(21, 10, 29, '2026-07-30', '2026-07-30', '10:00:00', '12:00:00', NULL, NULL, 'Cancelled'),
(22, 11, 31, '2026-07-31', '2026-07-31', '12:00:00', '14:00:00', '2026-07-31 15:20:00', 1, 'Completed'),
(23, 12, 33, '2026-08-01', '2026-08-01', '08:00:00', '12:00:00', '2026-08-01 11:50:00', 1, 'Completed'),
(24, 13, 35, '2026-08-02', '2026-08-02', '15:00:00', '17:00:00', '2026-08-02 17:08:00', 1, 'Completed'),
(25, 14, 37, '2026-08-03', '2026-08-03', '09:00:00', '11:00:00', '2026-08-03 11:05:00', 1, 'Completed'),
(26, 10, 27, '2026-08-04', '2026-08-04', '00:03:17', '03:03:17', NULL, NULL, 'Confirmed'),
(27, 11, 30, '2026-08-04', '2026-08-04', '00:33:17', '02:33:17', NULL, NULL, 'Confirmed'),
(28, 13, 28, '2026-08-05', '2026-08-05', '08:00:00', '10:00:00', NULL, NULL, 'Confirmed'),
(29, 12, 32, '2026-08-05', '2026-08-05', '10:00:00', '13:00:00', NULL, NULL, 'Confirmed'),
(30, 14, 34, '2026-08-06', '2026-08-06', '09:00:00', '11:00:00', NULL, NULL, 'Confirmed'),
(31, 11, 31, '2026-08-07', '2026-08-07', '14:00:00', '16:00:00', NULL, NULL, 'Confirmed'),
(32, 10, 29, '2026-08-08', '2026-08-08', '18:00:00', '20:00:00', NULL, NULL, 'Pending'),
(33, 12, 38, '2026-08-09', '2026-08-09', '08:00:00', '12:00:00', NULL, NULL, 'Confirmed'),
(34, 15, 28, '2026-07-05', '2026-07-05', '08:00:00', '10:00:00', '2026-07-05 09:55:00', 1, 'Completed'),
(35, 15, 35, '2026-08-10', '2026-08-10', '08:00:00', '10:00:00', NULL, NULL, 'Confirmed'),
(36, 16, 30, '2026-07-07', '2026-07-07', '09:00:00', '11:00:00', '2026-07-07 11:00:00', 1, 'Completed'),
(37, 16, 31, '2026-08-11', '2026-08-11', '10:00:00', '12:00:00', NULL, NULL, 'Pending'),
(38, 17, 34, '2026-07-09', '2026-07-09', '12:00:00', '14:00:00', '2026-07-09 13:55:00', 1, 'Completed'),
(39, 17, 27, '2026-08-12', '2026-08-12', '08:00:00', '11:00:00', NULL, NULL, 'Confirmed'),
(40, 18, 32, '2026-07-11', '2026-07-11', '08:00:00', '12:00:00', '2026-07-11 11:50:00', 1, 'Completed'),
(41, 18, 33, '2026-08-13', '2026-08-13', '09:00:00', '12:00:00', NULL, NULL, 'Confirmed'),
(42, 19, 29, '2026-07-13', '2026-07-13', '15:00:00', '17:00:00', '2026-07-13 17:45:00', 1, 'Completed'),
(43, 19, 36, '2026-08-14', '2026-08-14', '14:00:00', '16:00:00', NULL, NULL, 'Pending'),
(44, 20, 31, '2026-07-15', '2026-07-15', '10:00:00', '12:00:00', '2026-07-15 12:05:00', 1, 'Completed'),
(45, 20, 30, '2026-08-15', '2026-08-15', '13:00:00', '15:00:00', NULL, NULL, 'Confirmed'),
(46, 21, 35, '2026-07-17', '2026-07-17', '09:00:00', '11:00:00', '2026-07-17 10:55:00', 1, 'Completed'),
(47, 21, 28, '2026-08-16', '2026-08-16', '10:00:00', '12:00:00', NULL, NULL, 'Confirmed'),
(48, 22, 33, '2026-07-19', '2026-07-19', '13:00:00', '16:00:00', '2026-07-19 16:10:00', 1, 'Completed'),
(49, 22, 32, '2026-08-17', '2026-08-17', '08:00:00', '12:00:00', NULL, NULL, 'Pending'),
(50, 13, 36, '2026-08-21', '2026-08-21', '10:00:00', '14:00:00', NULL, NULL, 'Confirmed'),
(51, 13, 35, '2026-08-05', '2026-08-05', '08:00:00', '09:00:00', NULL, NULL, 'Confirmed'),
(52, 13, 29, '2026-08-05', '2026-08-05', '08:00:00', '09:00:00', NULL, NULL, 'Pending'),
(53, 13, 28, '2026-09-07', '2026-09-07', '20:00:00', '21:00:00', NULL, NULL, 'Pending');

-- --------------------------------------------------------

--
-- Table structure for table `reservation_reassignments`
--

CREATE TABLE `reservation_reassignments` (
  `reassignment_id` int(11) NOT NULL,
  `reservation_id` int(11) NOT NULL,
  `old_slot_id` int(11) NOT NULL,
  `new_slot_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `reassigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reservation_reassignments`
--

INSERT INTO `reservation_reassignments` (`reassignment_id`, `reservation_id`, `old_slot_id`, `new_slot_id`, `admin_id`, `reason`, `reassigned_at`) VALUES
(1, 25, 36, 37, 1, 'Previous vehicle did not leave the public slot on time.', '2026-08-03 03:15:00'),
(2, 33, 33, 38, 1, 'Future reservation moved to an administrator-only overflow slot.', '2026-08-03 19:33:17');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(30) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `password` varchar(255) NOT NULL,
  `registration_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `full_name`, `email`, `phone`, `password`, `registration_date`) VALUES
(1, 'demo_user01', 'Demo User 01', 'demo.user01@example.com', '0770000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-07-25'),
(2, 'demo_user02', 'Demo User 02', 'demo.user02@example.com', '0770000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-07-26'),
(3, 'demo_user03', 'Demo User 03', 'demo.user03@example.com', '0770000003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-06-25'),
(4, 'demo_user04', 'Demo User 04', 'demo.user04@example.com', '0770000004', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-06-30'),
(5, 'demo_user05', 'Demo User 05', 'demo.user05@example.com', '0770000005', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-07-07'),
(6, 'demo_user06', 'Demo User 06', 'demo.user06@example.com', '0770000006', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-07-14'),
(7, 'demo_user07', 'Demo User 07', 'demo.user07@example.com', '0770000007', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-07-20'),
(8, 'demo_user08', 'Demo User 08', 'demo.user08@example.com', '0770000008', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-07-25'),
(9, 'demo_user09', 'Demo User 09', 'demo.user09@example.com', '0770000009', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-07-30'),
(10, 'demo_user10', 'Demo User 10', 'demo.user10@example.com', '0770000010', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '2026-08-04');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `vehicle_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `vehicle_type` varchar(20) NOT NULL,
  `vehicle_brand` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`vehicle_id`, `user_id`, `vehicle_number`, `vehicle_type`, `vehicle_brand`) VALUES
(10, 1, 'DEMO-001', 'Car', 'Toyota'),
(11, 1, 'DEMO-002', 'Motorcycle', 'Honda'),
(12, 2, 'DEMO-003', 'Van', 'Toyota'),
(13, 2, 'DEMO-004', 'Car', 'Honda'),
(14, 1, 'DEMO-005', 'Car', 'Nissan'),
(15, 3, 'DEMO-006', 'Car', 'Toyota'),
(16, 4, 'DEMO-007', 'Motorcycle', 'Honda'),
(17, 5, 'DEMO-008', 'Car', 'Suzuki'),
(18, 6, 'DEMO-009', 'Van', 'Toyota'),
(19, 7, 'DEMO-010', 'Car', 'Nissan'),
(20, 8, 'DEMO-011', 'Motorcycle', 'Yamaha'),
(21, 9, 'DEMO-012', 'Car', 'Honda'),
(22, 10, 'DEMO-013', 'Van', 'Nissan');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `uq_admin_email` (`email`),
  ADD UNIQUE KEY `uq_admin_username` (`username`);

--
-- Indexes for table `fines`
--
ALTER TABLE `fines`
  ADD PRIMARY KEY (`fine_id`),
  ADD UNIQUE KEY `uq_fine_reservation` (`reservation_id`);

--
-- Indexes for table `parking_slots`
--
ALTER TABLE `parking_slots`
  ADD PRIMARY KEY (`slot_id`),
  ADD UNIQUE KEY `uq_floor_slot` (`floor_no`,`slot_number`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `uq_payment_reservation` (`reservation_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `fk_reservation_vehicle` (`vehicle_id`),
  ADD KEY `idx_slot_reservation_time` (`slot_id`,`start_date`,`start_time`,`end_time`);

--
-- Indexes for table `reservation_reassignments`
--
ALTER TABLE `reservation_reassignments`
  ADD PRIMARY KEY (`reassignment_id`),
  ADD KEY `fk_reassignment_reservation` (`reservation_id`),
  ADD KEY `fk_reassignment_old_slot` (`old_slot_id`),
  ADD KEY `fk_reassignment_new_slot` (`new_slot_id`),
  ADD KEY `fk_reassignment_admin` (`admin_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_user_email` (`email`),
  ADD UNIQUE KEY `uq_user_username` (`username`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`vehicle_id`),
  ADD UNIQUE KEY `uq_vehicle_number` (`vehicle_number`),
  ADD KEY `fk_vehicle_user` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `fines`
--
ALTER TABLE `fines`
  MODIFY `fine_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `parking_slots`
--
ALTER TABLE `parking_slots`
  MODIFY `slot_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservation_reassignments`
--
ALTER TABLE `reservation_reassignments`
  MODIFY `reassignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `vehicle_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `fines`
--
ALTER TABLE `fines`
  ADD CONSTRAINT `fk_fine_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payment_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON UPDATE CASCADE;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `fk_reservation_slot` FOREIGN KEY (`slot_id`) REFERENCES `parking_slots` (`slot_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reservation_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON UPDATE CASCADE;

--
-- Constraints for table `reservation_reassignments`
--
ALTER TABLE `reservation_reassignments`
  ADD CONSTRAINT `fk_reassignment_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`),
  ADD CONSTRAINT `fk_reassignment_new_slot` FOREIGN KEY (`new_slot_id`) REFERENCES `parking_slots` (`slot_id`),
  ADD CONSTRAINT `fk_reassignment_old_slot` FOREIGN KEY (`old_slot_id`) REFERENCES `parking_slots` (`slot_id`),
  ADD CONSTRAINT `fk_reassignment_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`);

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicle_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
