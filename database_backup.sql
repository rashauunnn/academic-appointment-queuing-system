-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 12, 2026 at 05:47 AM
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`app_id`, `student_id`, `faculty_id`, `reason`, `status`, `created_at`) VALUES
(1, 1, 2, 'Secret', 'Completed', '2026-05-10 13:16:08'),
(2, 1, 2, 'Ano po kasi\r\n', 'Completed', '2026-05-10 13:54:14'),
(3, 4, 2, 'Kasi ano po', 'Completed', '2026-05-10 13:54:57'),
(4, 1, 2, 'May nakalimutan po ako itanong, Sir', 'Completed', '2026-05-10 13:59:22'),
(5, 1, 2, 'Thesis', 'Cancelled', '2026-05-11 14:14:09');

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
(4, 4, '2026-05-10 22:16:53', '2026-05-10 22:16:53', '2026-05-11 22:14:23', 1437);

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
  `current_status` enum('Available','On Break','Out of Office') DEFAULT 'Available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `school_id`, `password`, `full_name`, `role`, `email`, `phone_num`, `current_status`) VALUES
(1, '2023-1108', 'password123', 'Shaquiel Umandap', 'Student', 'shaquiel@example.com', '09123456789', 'Available'),
(2, 'FAC-2026-001', 'password123', 'Prof. Dela Cruz', 'Faculty', 'profdc@example.com', '09987654321', 'Available'),
(3, 'ADMIN-001', 'password123', 'System Admin', 'Admin', 'admin@example.com', '09000000000', 'Available'),
(4, '2023-8011', '123password', 'Shaquiel Daniel', 'Student', 'shaquiel21@example.com', '09123456789', 'Available'),
(5, '', 'password123', 'Shaquiel Demo Student', 'Student', NULL, NULL, 'Available');

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
  MODIFY `app_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `faculty_schedules`
--
ALTER TABLE `faculty_schedules`
  MODIFY `sched_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `queue_logs`
--
ALTER TABLE `queue_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

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
