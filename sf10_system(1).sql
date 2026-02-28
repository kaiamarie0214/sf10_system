-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 28, 2026 at 03:26 AM
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

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_new_school_year` (IN `p_year` VARCHAR(15), IN `p_start_date` DATE, IN `p_end_date` DATE, IN `p_created_by` INT, IN `p_make_default` TINYINT)   BEGIN
    DECLARE v_school_year_id INT;
    DECLARE v_error_msg VARCHAR(255);
    
    
    IF EXISTS(SELECT 1 FROM school_years WHERE year = p_year) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'School year already exists';
    END IF;
    
    
    START TRANSACTION;
    
    
    IF p_make_default = 1 THEN
        UPDATE school_years SET is_active = 0 WHERE is_active = 1;
    END IF;
    
    
    INSERT INTO school_years (year, start_date, end_date, is_active, status, created_by)
    VALUES (p_year, p_start_date, p_end_date, p_make_default, 'active', p_created_by);
    
    SET v_school_year_id = LAST_INSERT_ID();
    
    
    INSERT INTO change_logs (user_id, action, table_name, record_id, details)
    VALUES (p_created_by, 'CREATE_SCHOOL_YEAR', 'school_years', v_school_year_id, 
            CONCAT('Created new school year: ', p_year));
    
    COMMIT;
    
    
    SELECT v_school_year_id AS school_year_id, p_year AS year, 'School year created successfully' AS message;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_enroll_student` (IN `p_school_year_id` INT, IN `p_student_id` INT, IN `p_school_attended_id` INT, IN `p_grade_level` INT, IN `p_section` VARCHAR(50), IN `p_enrollment_date` DATE, IN `p_created_by` INT)   BEGIN
    DECLARE v_enrollment_id INT;
    
    
    IF EXISTS(SELECT 1 FROM school_year_enrollments 
              WHERE school_year_id = p_school_year_id AND student_id = p_student_id) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Student already enrolled in this school year';
    END IF;
    
    
    IF NOT EXISTS(SELECT 1 FROM classes_per_year 
                  WHERE school_year_id = p_school_year_id 
                  AND grade_level = p_grade_level 
                  AND section = p_section) THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Class section does not exist for this school year';
    END IF;
    
    START TRANSACTION;
    
    
    INSERT INTO school_year_enrollments 
        (school_year_id, student_id, school_attended_id, grade_level, section, 
         enrollment_status, enrollment_date, created_by)
    VALUES 
        (p_school_year_id, p_student_id, p_school_attended_id, p_grade_level, p_section,
         'enrolled', p_enrollment_date, p_created_by);
    
    SET v_enrollment_id = LAST_INSERT_ID();
    
    
    UPDATE classes_per_year 
    SET current_count = current_count + 1
    WHERE school_year_id = p_school_year_id 
    AND grade_level = p_grade_level 
    AND section = p_section;
    
    
    INSERT INTO change_logs (user_id, action, table_name, record_id, details)
    VALUES (p_created_by, 'ENROLL_STUDENT', 'school_year_enrollments', v_enrollment_id,
            CONCAT('Enrolled student ID ', p_student_id, ' to Grade ', p_grade_level, '-', p_section));
    
    COMMIT;
    
    SELECT v_enrollment_id AS enrollment_id, 'Student enrolled successfully' AS message;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `fn_can_teacher_grade_subject` (`p_teacher_id` INT, `p_subject_id` INT, `p_grade_level` INT, `p_section` VARCHAR(50), `p_school_year_id` INT) RETURNS TINYINT(1) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE v_can_grade TINYINT(1);
    
    
    SELECT COUNT(*) INTO v_can_grade
    FROM subject_teacher_assignments
    WHERE teacher_id = p_teacher_id
    AND subject_id = p_subject_id
    AND grade_level = p_grade_level
    AND section = p_section
    AND school_year_id = p_school_year_id;
    
    RETURN v_can_grade > 0;
END$$

DELIMITER ;

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
-- Table structure for table `classes_per_year`
--

CREATE TABLE `classes_per_year` (
  `id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `grade_level` int(11) NOT NULL,
  `section` varchar(50) NOT NULL,
  `adviser_id` int(11) DEFAULT NULL COMMENT 'Teacher assigned as class adviser',
  `room_number` varchar(20) DEFAULT NULL,
  `capacity` int(11) NOT NULL DEFAULT 40,
  `current_count` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Class sections defined per school year';

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grades_history`
--

CREATE TABLE `grades_history` (
  `id` int(11) NOT NULL,
  `grade_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `school_attended_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `quarter` int(11) NOT NULL,
  `old_grade` decimal(5,2) DEFAULT NULL,
  `new_grade` decimal(5,2) DEFAULT NULL,
  `changed_by` int(11) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `change_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quarter_auto_locks`
--

CREATE TABLE `quarter_auto_locks` (
  `id` int(11) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `school_attended_id` int(11) DEFAULT NULL,
  `quarter` tinyint(4) NOT NULL COMMENT '1-4 for quarters',
  `auto_lock_time` datetime NOT NULL COMMENT 'When to automatically lock this quarter',
  `school_year` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quarter_auto_unlocks`
--

CREATE TABLE `quarter_auto_unlocks` (
  `id` int(11) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `school_attended_id` int(11) DEFAULT NULL,
  `quarter` tinyint(4) NOT NULL COMMENT '1-4 for quarters',
  `auto_unlock_time` datetime NOT NULL COMMENT 'When to automatically unlock this quarter',
  `school_year` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quarter_locks`
--

CREATE TABLE `quarter_locks` (
  `id` int(11) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `school_attended_id` int(11) DEFAULT NULL,
  `quarter` tinyint(4) NOT NULL COMMENT '1-4 for quarters',
  `locked` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=unlocked, 1=locked',
  `school_year` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `remedial_classes`
--

CREATE TABLE `remedial_classes` (
  `id` int(11) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `school_year` varchar(15) DEFAULT NULL,
  `grade_level` varchar(20) DEFAULT NULL,
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
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `transfer_quarter` tinyint(1) DEFAULT NULL COMMENT '1=Q1, 2=Q2, 3=Q3, 4=Q4'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `school_years`
--

CREATE TABLE `school_years` (
  `id` int(11) NOT NULL,
  `year` varchar(15) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  `status` enum('upcoming','active','archived') DEFAULT 'upcoming',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
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
  `grade_level` int(11) DEFAULT NULL,
  `school_year` varchar(15) DEFAULT NULL,
  `is_global` tinyint(1) NOT NULL DEFAULT 1,
  `min_grade` int(11) DEFAULT NULL,
  `max_grade` int(11) DEFAULT NULL,
  `is_core` tinyint(1) DEFAULT 0,
  `is_mapeh_component` tinyint(1) DEFAULT 0,
  `display_order` int(11) DEFAULT NULL,
  `subject_code` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `subject_name`, `grade_level`, `school_year`, `is_global`, `min_grade`, `max_grade`, `is_core`, `is_mapeh_component`, `display_order`, `subject_code`, `description`) VALUES
(1, 'Mother Tongue', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(2, 'Filipino', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(3, 'English', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(4, 'Mathematics', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(5, 'Science', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(6, 'Araling Panlipunan', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(7, 'EPP / TLE', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(8, 'MAPEH', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(9, 'Music', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(10, 'Arts', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(11, 'Physical Education', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(12, 'Health', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(13, 'Eduk. sa Pagpapakatao', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(14, '*Arabic Language', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL),
(15, '*Islamic Values Education', NULL, NULL, 1, NULL, NULL, 0, 0, NULL, NULL, NULL);

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
-- Table structure for table `subject_teacher_assignments`
--

CREATE TABLE `subject_teacher_assignments` (
  `id` int(11) NOT NULL,
  `school_year_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `grade_level` int(11) NOT NULL,
  `section` varchar(50) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Teachers assigned to teach specific subjects per grade/section/year';

-- --------------------------------------------------------

--
-- Table structure for table `teacher_assignments`
--

CREATE TABLE `teacher_assignments` (
  `id` int(11) NOT NULL,
  `school_year_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) NOT NULL,
  `assignment_type` enum('adviser','subject') NOT NULL,
  `subject_id` int(11) DEFAULT NULL COMMENT 'NULL for adviser assignments',
  `grade_level` int(11) NOT NULL,
  `section` varchar(50) NOT NULL,
  `school_year` varchar(15) NOT NULL DEFAULT '2024-2025',
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `totp_secret` varchar(255) DEFAULT NULL,
  `2fa_on_login` tinyint(1) DEFAULT 0,
  `remember_token` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `role`, `school_name`, `school_id`, `district`, `division`, `region`, `subject_id`, `created_at`, `totp_secret`, `2fa_on_login`, `remember_token`) VALUES
(6, 'admin', '$2y$10$wGVeCzKzzyTHpTF7PFnjme6hcgUuTB4pEKTsghzXYUB1t0N2qHsYW', 'BARON THE 3RD', 'admin', 'ha', NULL, NULL, NULL, NULL, NULL, '2025-07-27 05:33:44', 'GRLEBDHINZUEVFTT', 1, '05c3de4d36839c6edd2323b1b664503c6cceca73c82114357f19b4d683e0e520'),
(20, 'asda', '$2y$10$u8TfJs60jdfHpmXMnAYzVuM2hFB5.T7pgUNDZWR8Xnc.yH/u5JAf6', 'asd', 'admin', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 12:41:07', NULL, 0, NULL),
(21, 'afasfa', '$2y$10$HZ7Jglb58GhP7CQOM8mHROJSp.hvsQbrFart.VPzYz7iSXf3P.ZaO', 'asdasf', 'admin', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 12:41:29', NULL, 0, NULL),
(22, 'asdasd', '$2y$10$FgsfQAGH0FDPaGUhLXVqceeb4zj8XTULskA3zH4.dT/VqZ7.mTxXq', 'asdasd', 'admin', 'asdasd', NULL, NULL, NULL, NULL, NULL, '2026-02-13 12:42:18', NULL, 0, NULL),
(23, 'dasdasd', '$2y$10$ARTByHnhB/4gsCKF5wz.ve/f1Vvfn07VXJCgdR2a89kFh4r7YHIGO', 'asdaasdasd', 'admin', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 12:44:07', NULL, 0, NULL);

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
-- Indexes for table `classes_per_year`
--
ALTER TABLE `classes_per_year`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_class_per_year` (`school_year_id`,`grade_level`,`section`),
  ADD KEY `idx_school_year` (`school_year_id`),
  ADD KEY `idx_adviser` (`adviser_id`),
  ADD KEY `idx_grade_level` (`grade_level`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_grade` (`student_id`,`school_attended_id`,`subject_id`,`quarter`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `idx_school_year_id` (`school_year_id`),
  ADD KEY `idx_student_year_quarter` (`student_id`,`school_year_id`,`quarter`);

--
-- Indexes for table `grades_history`
--
ALTER TABLE `grades_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_grade_id` (`grade_id`),
  ADD KEY `idx_student` (`student_id`),
  ADD KEY `idx_changed_at` (`changed_at`);

--
-- Indexes for table `quarter_auto_locks`
--
ALTER TABLE `quarter_auto_locks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_school_quarter_auto_year` (`school_attended_id`,`quarter`,`school_year_id`),
  ADD KEY `idx_auto_lock_school_year` (`school_year_id`),
  ADD KEY `idx_school_year` (`school_year`);

--
-- Indexes for table `quarter_auto_unlocks`
--
ALTER TABLE `quarter_auto_unlocks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_school_quarter_unlock_year` (`school_attended_id`,`quarter`,`school_year_id`),
  ADD KEY `idx_auto_unlock_school_year` (`school_year_id`),
  ADD KEY `idx_school_year` (`school_year`);

--
-- Indexes for table `quarter_locks`
--
ALTER TABLE `quarter_locks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_school_quarter_year` (`school_attended_id`,`quarter`,`school_year_id`),
  ADD KEY `idx_quarter_lock_school_year` (`school_year_id`),
  ADD KEY `idx_school_year` (`school_year`);

--
-- Indexes for table `remedial_classes`
--
ALTER TABLE `remedial_classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_remedial_school_year` (`school_year_id`),
  ADD KEY `idx_student_grade` (`student_id`,`grade_level`);

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
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_year` (`year`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `fk_school_years_created_by` (`created_by`);

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
-- Indexes for table `subject_teacher_assignments`
--
ALTER TABLE `subject_teacher_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_subject_assignment` (`school_year_id`,`subject_id`,`grade_level`,`section`),
  ADD KEY `idx_teacher` (`teacher_id`),
  ADD KEY `idx_subject` (`subject_id`),
  ADD KEY `idx_school_year` (`school_year_id`),
  ADD KEY `fk_subj_assign_created_by` (`created_by`),
  ADD KEY `idx_teacher_year` (`teacher_id`,`school_year_id`);

--
-- Indexes for table `teacher_assignments`
--
ALTER TABLE `teacher_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_teacher_subject_year` (`teacher_id`,`subject_id`,`grade_level`,`section`,`school_year_id`),
  ADD KEY `idx_teacher` (`teacher_id`),
  ADD KEY `idx_assignment_type` (`assignment_type`),
  ADD KEY `idx_subject` (`subject_id`),
  ADD KEY `idx_grade_section` (`grade_level`,`section`),
  ADD KEY `idx_school_year_id` (`school_year_id`);

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
-- AUTO_INCREMENT for table `classes_per_year`
--
ALTER TABLE `classes_per_year`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grades_history`
--
ALTER TABLE `grades_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quarter_auto_locks`
--
ALTER TABLE `quarter_auto_locks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quarter_auto_unlocks`
--
ALTER TABLE `quarter_auto_unlocks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quarter_locks`
--
ALTER TABLE `quarter_locks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `remedial_classes`
--
ALTER TABLE `remedial_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schools_attended`
--
ALTER TABLE `schools_attended`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `school_years`
--
ALTER TABLE `school_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_custom_subjects`
--
ALTER TABLE `student_custom_subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `subject_grade_groups`
--
ALTER TABLE `subject_grade_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `subject_teacher_assignments`
--
ALTER TABLE `subject_teacher_assignments`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `change_logs`
--
ALTER TABLE `change_logs`
  ADD CONSTRAINT `change_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `classes_per_year`
--
ALTER TABLE `classes_per_year`
  ADD CONSTRAINT `fk_classes_adviser` FOREIGN KEY (`adviser_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_classes_school_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `fk_grades_school_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `grades_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `quarter_auto_locks`
--
ALTER TABLE `quarter_auto_locks`
  ADD CONSTRAINT `fk_auto_lock_school_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_auto_locks_school` FOREIGN KEY (`school_attended_id`) REFERENCES `schools_attended` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quarter_auto_unlocks`
--
ALTER TABLE `quarter_auto_unlocks`
  ADD CONSTRAINT `fk_auto_unlock_school_year_new` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_auto_unlocks_school` FOREIGN KEY (`school_attended_id`) REFERENCES `schools_attended` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quarter_locks`
--
ALTER TABLE `quarter_locks`
  ADD CONSTRAINT `fk_quarter_lock_school_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_quarter_locks_school` FOREIGN KEY (`school_attended_id`) REFERENCES `schools_attended` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `remedial_classes`
--
ALTER TABLE `remedial_classes`
  ADD CONSTRAINT `fk_remedial_school_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `remedial_classes_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schools_attended`
--
ALTER TABLE `schools_attended`
  ADD CONSTRAINT `schools_attended_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `school_years`
--
ALTER TABLE `school_years`
  ADD CONSTRAINT `fk_school_years_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

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
-- Constraints for table `subject_teacher_assignments`
--
ALTER TABLE `subject_teacher_assignments`
  ADD CONSTRAINT `fk_subj_assign_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_subj_assign_school_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_subj_assign_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_subj_assign_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teacher_assignments`
--
ALTER TABLE `teacher_assignments`
  ADD CONSTRAINT `fk_teacher_assign_school_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`) ON DELETE CASCADE,
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
