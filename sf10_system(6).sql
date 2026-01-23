-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 19, 2026 at 09:04 PM
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
-- Database: `sf10_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `change_logs`
--

CREATE TABLE `change_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `details` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `grade_level` varchar(10) NOT NULL,
  `section` varchar(50) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `capacity` int(11) NOT NULL DEFAULT 40,
  `status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `school_attended_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `quarter` enum('1','2','3','4') DEFAULT NULL,
  `grade` int(11) DEFAULT NULL,
  `final_rating` int(11) DEFAULT NULL,
  `remarks` varchar(100) DEFAULT NULL,
  `is_general_average` tinyint(1) DEFAULT 0,
  `teacher_id` int(11) DEFAULT NULL,
  `school_year` varchar(15) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quarter_access`
--

CREATE TABLE `quarter_access` (
  `id` int(11) NOT NULL,
  `grade_level` int(11) DEFAULT NULL,
  `quarter` enum('1','2','3','4') DEFAULT NULL,
  `is_locked` tinyint(1) DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quarter_auto_locks`
--

CREATE TABLE `quarter_auto_locks` (
  `id` int(11) NOT NULL,
  `school_attended_id` int(11) DEFAULT NULL,
  `quarter` tinyint(4) NOT NULL COMMENT '1-4 for quarters',
  `auto_lock_time` datetime NOT NULL COMMENT 'When to automatically lock this quarter',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quarter_auto_unlocks`
--

CREATE TABLE `quarter_auto_unlocks` (
  `id` int(11) NOT NULL,
  `school_attended_id` int(11) DEFAULT NULL,
  `quarter` tinyint(4) NOT NULL COMMENT '1-4 for quarters',
  `auto_unlock_time` datetime NOT NULL COMMENT 'When to automatically unlock this quarter',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quarter_locks`
--

CREATE TABLE `quarter_locks` (
  `id` int(11) NOT NULL,
  `school_attended_id` int(11) DEFAULT NULL,
  `quarter` tinyint(4) NOT NULL COMMENT '1-4 for quarters',
  `locked` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=unlocked, 1=locked',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `remedial_classes`
--

CREATE TABLE `remedial_classes` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `school_year` varchar(15) DEFAULT NULL,
  `learning_area` varchar(100) DEFAULT NULL,
  `final_rating` int(11) DEFAULT NULL,
  `remedial_class_mark` int(11) DEFAULT NULL,
  `recomputed_final_grade` int(11) DEFAULT NULL,
  `remarks` varchar(100) DEFAULT NULL,
  `conducted_from` date DEFAULT NULL,
  `conducted_to` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schools_attended`
--

CREATE TABLE `schools_attended` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `school_name` varchar(100) DEFAULT NULL,
  `school_id` varchar(50) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `division` varchar(100) DEFAULT NULL,
  `region` varchar(50) DEFAULT NULL,
  `grade_level` int(11) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL,
  `school_year` varchar(15) DEFAULT NULL,
  `adviser_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_transfer` tinyint(1) DEFAULT 0 COMMENT '1=Transfer student, 0=Regular student',
  `active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `school_years`
--

CREATE TABLE `school_years` (
  `id` int(11) NOT NULL,
  `year` varchar(15) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `lrn` varchar(20) NOT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  `suffix` varchar(10) DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `credential_presented` enum('Kinder Progress Report','ECCD Checklist','Kindergarten Certificate of Completion','PEPT','Others') DEFAULT NULL,
  `credential_other_details` text DEFAULT NULL,
  `pept_rating` varchar(20) DEFAULT NULL,
  `pept_exam_date` date DEFAULT NULL,
  `pept_testing_center` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `eligibility_school_name` varchar(100) DEFAULT NULL,
  `eligibility_school_id` varchar(50) DEFAULT NULL,
  `eligibility_school_address` varchar(255) DEFAULT NULL,
  `eligibility_remark` varchar(255) DEFAULT NULL,
  `grade_level` varchar(50) DEFAULT NULL,
  `section` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_custom_subjects`
--

CREATE TABLE `student_custom_subjects` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `school_attended_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `custom_subject_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `subject_name` varchar(100) DEFAULT NULL,
  `grade_level` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subject_grade_groups`
--

CREATE TABLE `subject_grade_groups` (
  `id` int(11) NOT NULL,
  `grade_level` int(11) NOT NULL COMMENT 'Grade level: 1, 2, 3, 4, 5, or 6',
  `subject_id` int(11) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_assignments`
--

CREATE TABLE `teacher_assignments` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `assignment_type` enum('adviser','subject') NOT NULL,
  `subject_id` int(11) DEFAULT NULL COMMENT 'NULL for adviser assignments',
  `grade_level` int(11) NOT NULL,
  `section` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Teacher assignments for subjects and adviser roles';

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','teacher') NOT NULL,
  `school_name` varchar(255) DEFAULT NULL,
  `school_id` varchar(50) DEFAULT NULL,
  `district` varchar(100) DEFAULT NULL,
  `division` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL COMMENT 'For subject teachers - which subject they teach',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `role`, `school_name`, `school_id`, `district`, `division`, `region`, `subject_id`, `created_at`) VALUES
(2, 'tarah', '$2y$10$qazcTrtOwtZcAvPZ3XWYSeRJ9nhRS3EyBJYqL.eWeqsf0N9AIgIIG', 'Tarah Padios Aquino', 'teacher', 'NEW MABUHAY', NULL, NULL, NULL, NULL, NULL, '2025-07-26 13:13:41'),
(4, 'john', '$2y$10$MGi5d3s0cPxcG3Ccg0sB0OQT28BkK9L0sMEkD/QN/I2Mi5yuu4EaC', 'john marcel', 'teacher', 'NEW MABUHAY', '123456', 'DAVAO', 'GENSAN', '12', NULL, '2025-07-26 13:30:04'),
(6, 'admin', '$2y$10$usEktFJvXFTIgoUweN/nweBZvumHL.4uaKWkxMpO6B66hwa38opKW', 'BARON THE 3RD', 'admin', 'ha', NULL, NULL, NULL, NULL, NULL, '2025-07-26 14:33:44'),
(13, 'kaia', '$2y$10$C/ZKs2ydv0JRcF7JRpK6eeVAhoI96nMButufx2VvZgTa6PJeuzxVi', 'kaia marie', 'teacher', 'NEW MABUHAY ', '123456', 'gensan', 'gensan', NULL, NULL, '2026-01-01 14:44:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `change_logs`
--
ALTER TABLE `change_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_class` (`grade_level`,`section`,`school_year`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `receiver_id` (`receiver_id`),
  ADD KEY `is_read` (`is_read`),
  ADD KEY `created_at` (`created_at`),
  ADD KEY `idx_conversation` (`sender_id`,`receiver_id`,`created_at`);

--
-- Indexes for table `quarter_access`
--
ALTER TABLE `quarter_access`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `quarter_auto_locks`
--
ALTER TABLE `quarter_auto_locks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_school_quarter_auto` (`school_attended_id`,`quarter`);

--
-- Indexes for table `quarter_auto_unlocks`
--
ALTER TABLE `quarter_auto_unlocks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_school_quarter_unlock` (`school_attended_id`,`quarter`);

--
-- Indexes for table `quarter_locks`
--
ALTER TABLE `quarter_locks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_school_quarter` (`school_attended_id`,`quarter`);

--
-- Indexes for table `remedial_classes`
--
ALTER TABLE `remedial_classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `schools_attended`
--
ALTER TABLE `schools_attended`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `school_years`
--
ALTER TABLE `school_years`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `lrn` (`lrn`),
  ADD UNIQUE KEY `lrn_2` (`lrn`);

--
-- Indexes for table `student_custom_subjects`
--
ALTER TABLE `student_custom_subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_school_subject` (`student_id`,`school_attended_id`,`subject_id`),
  ADD KEY `school_attended_id` (`school_attended_id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `subject_grade_groups`
--
ALTER TABLE `subject_grade_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `grade_subject` (`grade_level`,`subject_id`),
  ADD KEY `subject_id` (`subject_id`);

--
-- Indexes for table `teacher_assignments`
--
ALTER TABLE `teacher_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_teacher` (`teacher_id`),
  ADD KEY `idx_assignment_type` (`assignment_type`),
  ADD KEY `idx_subject` (`subject_id`),
  ADD KEY `idx_grade_section` (`grade_level`,`section`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_users_subject` (`subject_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `change_logs`
--
ALTER TABLE `change_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quarter_access`
--
ALTER TABLE `quarter_access`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quarter_auto_locks`
--
ALTER TABLE `quarter_auto_locks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `quarter_auto_unlocks`
--
ALTER TABLE `quarter_auto_unlocks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quarter_locks`
--
ALTER TABLE `quarter_locks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `remedial_classes`
--
ALTER TABLE `remedial_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schools_attended`
--
ALTER TABLE `schools_attended`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `school_years`
--
ALTER TABLE `school_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `student_custom_subjects`
--
ALTER TABLE `student_custom_subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subject_grade_groups`
--
ALTER TABLE `subject_grade_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teacher_assignments`
--
ALTER TABLE `teacher_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `change_logs`
--
ALTER TABLE `change_logs`
  ADD CONSTRAINT `change_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quarter_auto_locks`
--
ALTER TABLE `quarter_auto_locks`
  ADD CONSTRAINT `fk_auto_locks_school` FOREIGN KEY (`school_attended_id`) REFERENCES `schools_attended` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quarter_auto_unlocks`
--
ALTER TABLE `quarter_auto_unlocks`
  ADD CONSTRAINT `fk_auto_unlocks_school` FOREIGN KEY (`school_attended_id`) REFERENCES `schools_attended` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quarter_locks`
--
ALTER TABLE `quarter_locks`
  ADD CONSTRAINT `fk_quarter_locks_school` FOREIGN KEY (`school_attended_id`) REFERENCES `schools_attended` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `remedial_classes`
--
ALTER TABLE `remedial_classes`
  ADD CONSTRAINT `remedial_classes_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schools_attended`
--
ALTER TABLE `schools_attended`
  ADD CONSTRAINT `schools_attended_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_custom_subjects`
--
ALTER TABLE `student_custom_subjects`
  ADD CONSTRAINT `student_custom_subjects_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `student_custom_subjects_ibfk_2` FOREIGN KEY (`school_attended_id`) REFERENCES `schools_attended` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `student_custom_subjects_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subject_grade_groups`
--
ALTER TABLE `subject_grade_groups`
  ADD CONSTRAINT `subject_grade_groups_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teacher_assignments`
--
ALTER TABLE `teacher_assignments`
  ADD CONSTRAINT `fk_teacher_assignments_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_teacher_assignments_user` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
