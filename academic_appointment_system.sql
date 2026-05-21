-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 21, 2026 at 02:21 AM
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
-- Database: `academic_appointment_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `app_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('Pending','Accepted','Active','Completed','Cancelled','No-Show','Waitlist') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `appointment_date` date DEFAULT NULL,
  `time_slot` varchar(50) DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`app_id`, `student_id`, `faculty_id`, `reason`, `status`, `created_at`, `appointment_date`, `time_slot`, `cancel_reason`, `cancellation_reason`, `cancelled_by`) VALUES
(1, 1, 2, 'Secret', 'Completed', '2026-05-10 13:16:08', NULL, NULL, NULL, NULL, NULL),
(2, 1, 2, 'Ano po kasi\r\n', 'Completed', '2026-05-10 13:54:14', NULL, NULL, NULL, NULL, NULL),
(3, 4, 2, 'Kasi ano po', 'Completed', '2026-05-10 13:54:57', NULL, NULL, NULL, NULL, NULL),
(4, 1, 2, 'May nakalimutan po ako itanong, Sir', 'Completed', '2026-05-10 13:59:22', NULL, NULL, NULL, NULL, NULL),
(5, 1, 2, 'Thesis', 'Cancelled', '2026-05-11 14:14:09', NULL, NULL, NULL, NULL, NULL),
(10, 1, 2, 'Thesis', 'Completed', '2026-05-16 06:01:37', '2026-05-16', '09:00 AM - 10:00 AM', NULL, NULL, NULL),
(11, 4, 2, 'thesis', 'Completed', '2026-05-16 06:04:21', '2026-05-17', '09:00 AM - 10:00 AM', NULL, NULL, NULL),
(12, 4, 2, 'For Capstone ', 'Cancelled', '2026-05-16 12:33:20', '2026-05-17', '09:00 AM - 10:00 AM', 'No reason provided', NULL, 0),
(13, 4, 2, 'Capstone', 'Cancelled', '2026-05-16 12:45:04', '2026-05-17', '02:00 PM - 03:00 PM', NULL, NULL, NULL),
(14, 4, 2, 'OJT', 'Cancelled', '2026-05-16 13:35:03', '2026-05-17', '04:00 PM - 05:00 PM', 'No reason provided', NULL, 0),
(15, 4, 2, 'Capstone', 'Cancelled', '2026-05-17 14:48:07', '2026-05-20', '11:00 AM - 12:00 PM', 'No reason provided', NULL, 0),
(16, 4, 2, 'Thesis', 'Cancelled', '2026-05-17 14:55:06', '2026-07-18', '09:00 AM - 10:00 AM', 'No reason provided', NULL, 0),
(17, 4, 2, 'Secret', 'Cancelled', '2026-05-17 15:01:33', '2026-05-18', '09:00 AM - 10:00 AM', 'No reason provided', NULL, 0),
(18, 4, 2, 'Capstone Project', 'Cancelled', '2026-05-18 02:18:58', '2026-05-18', '01:00 PM - 02:00 PM', 'No reason provided', NULL, 0),
(19, 4, 2, 'Capstone', 'Cancelled', '2026-05-18 02:21:07', '2026-05-18', '04:00 PM - 05:00 PM', 'No reason provided', NULL, 0),
(20, 4, 2, 'OJT\r\n', 'Cancelled', '2026-05-18 15:09:00', '2026-05-19', '09:00 AM - 10:00 AM', 'No reason provided', NULL, 0),
(21, 4, 2, 'Secret\r\n', 'Cancelled', '2026-05-18 15:42:06', '2026-05-19', '09:00 AM - 10:00 AM', 'idk man\r\n', NULL, 0),
(22, 4, 2, 'Chapter 2\r\n', '', '2026-05-19 16:02:54', '2026-05-20', '09:00 AM - 10:00 AM', NULL, NULL, NULL),
(23, 4, 2, 'Hosting', '', '2026-05-20 13:10:07', '2026-05-21', '09:00 AM - 10:00 AM', NULL, NULL, NULL),
(24, 4, 2, '.....', 'Cancelled', '2026-05-20 13:38:32', '2026-05-21', '10:00 AM - 11:00 AM', '....', NULL, 0),
(25, 4, 2, ',,,', 'Cancelled', '2026-05-20 14:43:31', '2026-05-21', '09:00 AM - 10:00 AM', '....', NULL, 0),
(26, 4, 2, 'ojt\r\n', 'Cancelled', '2026-05-20 15:30:27', '2026-05-21', '09:00 AM - 10:00 AM', '...', NULL, 0),
(27, 4, 2, 'Bruh\r\n', 'Cancelled', '2026-05-20 15:38:24', '2026-05-21', '09:00 AM - 10:00 AM', '...', NULL, 0),
(28, 4, 2, '...', 'Cancelled', '2026-05-20 15:49:35', '2026-05-21', '10:00 AM - 11:00 AM', '....', NULL, 0),
(29, 4, 2, '.....', 'Cancelled', '2026-05-20 15:50:02', '2026-05-21', '09:00 AM - 10:00 AM', '....', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `faculty_availability`
--

CREATE TABLE `faculty_availability` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `unavailable_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faculty_availability`
--

INSERT INTO `faculty_availability` (`id`, `faculty_id`, `unavailable_date`, `start_time`, `end_time`, `reason`, `created_at`) VALUES
(1, 2, '2026-05-17', '11:00:00', '12:00:00', 'Meeting', '2026-05-16 12:18:23');

-- --------------------------------------------------------

--
-- Table structure for table `faculty_schedules`
--

CREATE TABLE `faculty_schedules` (
  `sched_id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `queue_logs`
--

CREATE TABLE `queue_logs` (
  `log_id` int(11) NOT NULL,
  `app_id` int(11) NOT NULL,
  `call_time` datetime DEFAULT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `duration` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `queue_logs`
--

INSERT INTO `queue_logs` (`log_id`, `app_id`, `call_time`, `start_time`, `end_time`, `duration`) VALUES
(1, 1, '2026-05-10 21:16:38', '2026-05-10 21:16:38', '2026-05-10 21:16:56', 0),
(2, 2, '2026-05-10 21:55:15', '2026-05-10 21:55:15', '2026-05-10 21:57:59', 2),
(3, 3, '2026-05-10 21:58:02', '2026-05-10 21:58:02', '2026-05-10 22:16:25', 18),
(4, 4, '2026-05-10 22:16:53', '2026-05-10 22:16:53', '2026-05-11 22:14:23', 1437),
(5, 10, '2026-05-16 14:05:07', '2026-05-16 14:05:07', '2026-05-16 14:05:15', 0),
(6, 11, '2026-05-16 20:18:32', '2026-05-16 20:18:32', '2026-05-16 20:18:39', 0);

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('maintenance_mode', '0');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `school_id` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('Admin','Faculty','Student') NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone_num` varchar(15) DEFAULT NULL,
  `current_status` enum('Available','On Break','Out of Office') DEFAULT 'Available',
  `contact_number` varchar(20) DEFAULT NULL,
  `social_link` text DEFAULT NULL,
  `busy_until` datetime DEFAULT NULL,
  `biography` text DEFAULT NULL,
  `specialization` varchar(255) DEFAULT NULL,
  `office_hours` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `school_id`, `password`, `full_name`, `role`, `email`, `phone_num`, `current_status`, `contact_number`, `social_link`, `busy_until`, `biography`, `specialization`, `office_hours`) VALUES
(1, '2023-1108', '$2y$10$ZxEvtoifrezEnyc3z2R1qeV8kN./TcBRuemktEgOUoTC7K5uhdUMq', 'Shaquiel Umandap', 'Student', 'shaquiel@example.com', '09123456789', 'Available', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'FAC-2026-001', 'password123', 'Prof. Dela Cruz', 'Faculty', 'shaquieldaniel21@gmail.com', '09987654321', '', '095552609713', 'https://www.facebook.com/eeigoorf#', NULL, 'Senior Scholar and clinical supervisor with over 15 years of experience in academic counseling and practitioner mentorship. Specializes in cognitive-behavioral development, institutional strategy, and professional communication workflows.', 'Clinical Counseling & Practitioner Mentorship', 'Mon - Thu, 09:00 AM - 04:00 PM'),
(3, 'ADMIN-001', 'password123', 'System Admin', 'Admin', 'admin@example.com', '09000000000', 'Available', NULL, NULL, NULL, NULL, NULL, NULL),
(4, '2023-8011', '123password', 'Shaquiel Daniel', 'Student', 'shaquiel21@example.com', '09123456789', 'Available', NULL, NULL, NULL, NULL, NULL, NULL),
(5, '', 'password123', 'Shaquiel Demo Student', 'Student', NULL, NULL, 'Available', NULL, NULL, NULL, NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`app_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `faculty_availability`
--
ALTER TABLE `faculty_availability`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `faculty_schedules`
--
ALTER TABLE `faculty_schedules`
  ADD PRIMARY KEY (`sched_id`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `queue_logs`
--
ALTER TABLE `queue_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `app_id` (`app_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `school_id` (`school_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `app_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `faculty_availability`
--
ALTER TABLE `faculty_availability`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `faculty_schedules`
--
ALTER TABLE `faculty_schedules`
  MODIFY `sched_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `queue_logs`
--
ALTER TABLE `queue_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `faculty_schedules`
--
ALTER TABLE `faculty_schedules`
  ADD CONSTRAINT `faculty_schedules_ibfk_1` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `queue_logs`
--
ALTER TABLE `queue_logs`
  ADD CONSTRAINT `queue_logs_ibfk_1` FOREIGN KEY (`app_id`) REFERENCES `appointments` (`app_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
