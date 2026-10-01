-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 01, 2026 at 07:05 PM
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
-- Database: `examination_seating_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_years`
--

CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL,
  `year_name` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` varchar(255) NOT NULL,
  `icon` varchar(50) NOT NULL,
  `color` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `allocation_batches`
--

CREATE TABLE `allocation_batches` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) DEFAULT NULL,
  `allocation_code` varchar(50) NOT NULL,
  `pattern_type` varchar(50) NOT NULL,
  `pattern_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pattern_settings`)),
  `total_students` int(11) NOT NULL DEFAULT 0,
  `total_halls` int(11) NOT NULL DEFAULT 0,
  `total_capacity` int(11) NOT NULL DEFAULT 0,
  `status` enum('preview','confirmed','cancelled') NOT NULL DEFAULT 'preview',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `confirmed_at` datetime DEFAULT NULL,
  `exam_date` date DEFAULT NULL,
  `exam_time` time DEFAULT NULL,
  `exam_time_to` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `allocation_hall_details`
--

CREATE TABLE `allocation_hall_details` (
  `id` int(11) NOT NULL,
  `allocation_id` int(11) NOT NULL,
  `hall_id` int(11) NOT NULL,
  `invigilator_count` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `allocation_report_details`
--

CREATE TABLE `allocation_report_details` (
  `id` int(11) NOT NULL,
  `allocation_id` int(11) NOT NULL,
  `exam_type` varchar(100) NOT NULL DEFAULT 'Semester Examination',
  `exam_title` varchar(255) DEFAULT NULL,
  `notice_heading` varchar(255) NOT NULL DEFAULT 'NOTICE/DISPLAY',
  `additional_matter` text DEFAULT NULL,
  `signature_footer` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `allocation_seats`
--

CREATE TABLE `allocation_seats` (
  `id` int(11) NOT NULL,
  `allocation_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `hall_id` int(11) NOT NULL,
  `row_number` int(11) NOT NULL,
  `column_number` int(11) NOT NULL,
  `bench_id` int(11) DEFAULT NULL,
  `bench_number` int(11) DEFAULT NULL,
  `bench_capacity` tinyint(3) UNSIGNED DEFAULT NULL,
  `bench_slot` int(11) NOT NULL DEFAULT 1,
  `seat_number` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `allocation_student_seating_details`
--

CREATE TABLE `allocation_student_seating_details` (
  `id` int(11) NOT NULL,
  `allocation_id` int(11) NOT NULL,
  `hall_id` int(11) NOT NULL,
  `footer_matter` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `exam_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `status` enum('Present','Absent') DEFAULT 'Present',
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `department_code` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `examinations`
--

CREATE TABLE `examinations` (
  `id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `subject_name` varchar(150) NOT NULL,
  `subject_code` varchar(50) NOT NULL,
  `exam_date` date NOT NULL,
  `exam_time` time NOT NULL,
  `exam_time_to` time DEFAULT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 180,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `halls`
--

CREATE TABLE `halls` (
  `id` int(11) NOT NULL,
  `hall_name` varchar(100) NOT NULL,
  `hall_code` varchar(20) NOT NULL,
  `rows_count` int(11) NOT NULL DEFAULT 1,
  `columns_count` int(11) NOT NULL DEFAULT 1,
  `column_benches` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`column_benches`)),
  `capacity` int(11) NOT NULL,
  `floor_no` varchar(50) NOT NULL,
  `building_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `hall_benches`
--

CREATE TABLE `hall_benches` (
  `id` int(11) NOT NULL,
  `hall_id` int(11) NOT NULL,
  `row_number` int(11) NOT NULL,
  `column_number` int(11) NOT NULL,
  `bench_number` int(11) NOT NULL,
  `capacity` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` varchar(255) NOT NULL,
  `icon` varchar(50) DEFAULT 'fa-bell',
  `color` varchar(20) DEFAULT 'primary',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0,
  `type` varchar(50) NOT NULL DEFAULT 'student'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `semesters`
--

CREATE TABLE `semesters` (
  `id` int(11) NOT NULL,
  `semester_number` tinyint(4) NOT NULL,
  `semester_name` varchar(50) NOT NULL,
  `year_number` tinyint(4) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `pin_no` varchar(30) NOT NULL,
  `student_name` varchar(150) NOT NULL,
  `department_id` int(11) NOT NULL,
  `academic_year` int(11) NOT NULL,
  `semester_id` int(11) DEFAULT NULL,
  `contact_no` varchar(15) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `mobile` varchar(15) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `otp` varchar(6) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `profile_photo` varchar(255) DEFAULT 'default.png'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_years`
--
ALTER TABLE `academic_years`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `allocation_batches`
--
ALTER TABLE `allocation_batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_allocation_code` (`allocation_code`),
  ADD KEY `fk_allocation_exam` (`exam_id`);

--
-- Indexes for table `allocation_hall_details`
--
ALTER TABLE `allocation_hall_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_allocation_hall` (`allocation_id`,`hall_id`);

--
-- Indexes for table `allocation_report_details`
--
ALTER TABLE `allocation_report_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_allocation_report` (`allocation_id`);

--
-- Indexes for table `allocation_seats`
--
ALTER TABLE `allocation_seats`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_allocation_student` (`allocation_id`,`student_id`),
  ADD UNIQUE KEY `uq_allocation_seat` (`allocation_id`,`hall_id`,`row_number`,`column_number`,`bench_slot`),
  ADD KEY `fk_allocation_seats_student` (`student_id`),
  ADD KEY `fk_allocation_seats_hall` (`hall_id`),
  ADD KEY `fk_allocation_seats_bench` (`bench_id`);

--
-- Indexes for table `allocation_student_seating_details`
--
ALTER TABLE `allocation_student_seating_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_allocation_student_hall` (`allocation_id`,`hall_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `exam_id` (`exam_id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `department_code` (`department_code`);

--
-- Indexes for table `examinations`
--
ALTER TABLE `examinations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_exam_subject` (`department_id`,`academic_year_id`,`semester_id`,`subject_code`),
  ADD KEY `idx_department_id` (`department_id`),
  ADD KEY `idx_academic_year_id` (`academic_year_id`),
  ADD KEY `idx_semester_id` (`semester_id`),
  ADD KEY `idx_exam_date` (`exam_date`);

--
-- Indexes for table `halls`
--
ALTER TABLE `halls`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `hall_code` (`hall_code`);

--
-- Indexes for table `hall_benches`
--
ALTER TABLE `hall_benches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_hall_bench_number` (`hall_id`,`bench_number`),
  ADD UNIQUE KEY `uq_hall_bench_position` (`hall_id`,`row_number`,`column_number`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `semesters`
--
ALTER TABLE `semesters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `semester_number` (`semester_number`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `register_number` (`pin_no`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `year_id` (`academic_year`),
  ADD KEY `idx_students_semester_id` (`semester_id`),
  ADD KEY `semester_id` (`semester_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_system_settings_key` (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `mobile` (`mobile`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_years`
--
ALTER TABLE `academic_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `allocation_batches`
--
ALTER TABLE `allocation_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `allocation_hall_details`
--
ALTER TABLE `allocation_hall_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `allocation_report_details`
--
ALTER TABLE `allocation_report_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `allocation_seats`
--
ALTER TABLE `allocation_seats`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `allocation_student_seating_details`
--
ALTER TABLE `allocation_student_seating_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `examinations`
--
ALTER TABLE `examinations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `halls`
--
ALTER TABLE `halls`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `hall_benches`
--
ALTER TABLE `hall_benches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `semesters`
--
ALTER TABLE `semesters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `allocation_batches`
--
ALTER TABLE `allocation_batches`
  ADD CONSTRAINT `fk_allocation_exam` FOREIGN KEY (`exam_id`) REFERENCES `examinations` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `allocation_seats`
--
ALTER TABLE `allocation_seats`
  ADD CONSTRAINT `fk_allocation_seats_batch` FOREIGN KEY (`allocation_id`) REFERENCES `allocation_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_allocation_seats_bench` FOREIGN KEY (`bench_id`) REFERENCES `hall_benches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_allocation_seats_hall` FOREIGN KEY (`hall_id`) REFERENCES `halls` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_allocation_seats_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `examinations` (`id`),
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`);

--
-- Constraints for table `examinations`
--
ALTER TABLE `examinations`
  ADD CONSTRAINT `examinations_academic_year_fk` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `examinations_department_fk` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `examinations_semester_fk` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `hall_benches`
--
ALTER TABLE `hall_benches`
  ADD CONSTRAINT `fk_hall_benches_hall` FOREIGN KEY (`hall_id`) REFERENCES `halls` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`),
  ADD CONSTRAINT `students_ibfk_2` FOREIGN KEY (`academic_year`) REFERENCES `academic_years` (`id`),
  ADD CONSTRAINT `students_ibfk_3` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
