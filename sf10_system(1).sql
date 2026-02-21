-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 20, 2026 at 01:59 PM
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

--
-- Dumping data for table `change_logs`
--

INSERT INTO `change_logs` (`id`, `user_id`, `action`, `table_name`, `record_id`, `timestamp`, `details`) VALUES
(1, 6, 'LOGOUT', 'users', 6, '2026-02-14 21:37:34', 'User logged out: BARON THE 3RD (admin)'),
(2, 6, 'LOGIN', 'users', 6, '2026-02-14 21:37:41', 'Admin logged in without school year: BARON THE 3RD'),
(3, 6, 'LOGOUT', 'users', 6, '2026-02-14 22:07:13', 'User logged out: BARON THE 3RD (admin)'),
(4, 6, 'LOGIN', 'users', 6, '2026-02-14 22:07:29', 'Admin logged in without school year: BARON THE 3RD'),
(5, 6, 'CREATE', 'school_years', 1, '2026-02-14 22:08:31', 'Created school year: 2025-2026'),
(6, 6, 'INSERT', 'students', 1, '2026-02-14 22:09:03', 'Added new student: ASDASDASD ASDASDASD (LRN: 23123123)'),
(7, 6, 'LOGIN', 'users', 6, '2026-02-14 22:11:44', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(8, 6, 'LOGIN', 'users', 6, '2026-02-14 22:12:25', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(9, 6, 'UPDATE', 'school_years', 1, '2026-02-14 22:16:10', 'Updated school year: 2025-2026'),
(10, 6, 'INSERT', 'classes', 1, '2026-02-14 22:24:54', 'Added new class: Grade 1 - brad (SY: 2025-2026)'),
(11, 6, 'LOGOUT', 'users', 6, '2026-02-14 22:27:19', 'User logged out: BARON THE 3RD (admin)'),
(12, 6, 'LOGIN', 'users', 6, '2026-02-14 22:27:29', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(13, 6, 'LOGOUT', 'users', 6, '2026-02-14 22:28:42', 'User logged out: BARON THE 3RD (admin)'),
(14, 6, 'LOGIN', 'users', 6, '2026-02-14 22:29:14', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(15, 6, 'LOGOUT', 'users', 6, '2026-02-14 22:30:25', 'User logged out: BARON THE 3RD (admin)'),
(16, 25, 'LOGIN', 'users', 25, '2026-02-14 22:30:34', 'User logged in: john (teacher) - School Year: 2025-2026'),
(17, 25, 'GRADE_ENTRY', 'grades', 1, '2026-02-14 22:30:58', 'Entered 1 grade(s) for student: ASDASDASD ASDASDASD (SY: 2026-2027)'),
(18, 25, 'GRADE_ENTRY', 'grades', 1, '2026-02-14 22:30:59', 'Entered 1 grade(s) for student: ASDASDASD ASDASDASD (SY: 2026-2027)'),
(19, 25, 'GRADE_ENTRY', 'grades', 1, '2026-02-14 22:31:31', 'Entered 2 grade(s) for student: ASDASDASD ASDASDASD (SY: 2026-2027)'),
(20, 25, 'GRADE_ENTRY', 'grades', 1, '2026-02-14 22:31:31', 'Entered 2 grade(s) for student: ASDASDASD ASDASDASD (SY: 2026-2027)'),
(21, 25, 'GRADE_ENTRY', 'grades', 1, '2026-02-14 22:31:49', 'Entered 1 grade(s) for student: ASDASDASD ASDASDASD (SY: 2026-2027)'),
(22, 25, 'GRADE_ENTRY', 'grades', 1, '2026-02-14 22:31:52', 'Entered 1 grade(s) for student: ASDASDASD ASDASDASD (SY: 2026-2027)'),
(23, 25, 'LOGOUT', 'users', 25, '2026-02-14 22:33:41', 'User logged out: john (teacher)'),
(24, 6, 'LOGIN', 'users', 6, '2026-02-14 22:33:49', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(25, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-14 22:33:56', 'Quarter 1 locked globally for all students'),
(26, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-14 22:33:59', 'Quarter 2 locked globally for all students'),
(27, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-14 22:34:02', 'Quarter 3 locked globally for all students'),
(28, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-14 22:34:06', 'Quarter 4 locked globally for all students'),
(29, 6, 'DELETE', 'schools_attended', 1, '2026-02-14 22:42:00', 'Deleted Grade 1 record for ASDASDASD ASDASDASD (LRN: 23123123)'),
(30, 6, 'LOGOUT', 'users', 6, '2026-02-14 22:42:20', 'User logged out: BARON THE 3RD (admin)'),
(31, 25, 'LOGIN', 'users', 25, '2026-02-14 22:42:28', 'User logged in: john (teacher) - School Year: 2025-2026'),
(32, 25, 'LOGOUT', 'users', 25, '2026-02-14 22:43:03', 'User logged out: john (teacher)'),
(33, 6, 'LOGIN', 'users', 6, '2026-02-14 22:43:13', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(34, 6, 'CREATE', 'school_years', 2, '2026-02-14 22:44:16', 'Created school year: 2024-2025'),
(35, 6, 'LOGOUT', 'users', 6, '2026-02-14 22:44:54', 'User logged out: BARON THE 3RD (admin)'),
(36, 6, 'LOGIN', 'users', 6, '2026-02-14 22:45:07', 'User logged in: BARON THE 3RD (admin) - School Year: 2024-2025'),
(37, 6, 'LOGOUT', 'users', 6, '2026-02-14 22:59:18', 'User logged out: BARON THE 3RD (admin)'),
(38, 25, 'LOGIN', 'users', 25, '2026-02-14 22:59:30', 'User logged in: john (teacher) - School Year: 2024-2025'),
(39, 25, 'LOGOUT', 'users', 25, '2026-02-14 22:59:39', 'User logged out: john (teacher)'),
(40, 25, 'LOGIN', 'users', 25, '2026-02-14 22:59:52', 'User logged in: john (teacher) - School Year: 2025-2026'),
(41, 25, 'LOGOUT', 'users', 25, '2026-02-14 23:01:49', 'User logged out: john (teacher)'),
(42, 25, 'LOGIN', 'users', 25, '2026-02-14 23:02:00', 'User logged in: john (teacher) - School Year: 2024-2025'),
(43, 25, 'LOGOUT', 'users', 25, '2026-02-14 23:02:10', 'User logged out: john (teacher)'),
(44, 6, 'LOGIN', 'users', 6, '2026-02-14 23:02:18', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(45, 6, 'INSERT', 'classes', 2, '2026-02-14 23:03:00', 'Added new class: Grade 2 - lora (SY: 2025-2026)'),
(46, 6, 'LOGOUT', 'users', 6, '2026-02-14 23:03:50', 'User logged out: BARON THE 3RD (admin)'),
(47, 25, 'LOGIN', 'users', 25, '2026-02-14 23:03:59', 'User logged in: john (teacher) - School Year: 2024-2025'),
(48, 25, 'LOGOUT', 'users', 25, '2026-02-14 23:04:10', 'User logged out: john (teacher)'),
(49, 25, 'LOGIN', 'users', 25, '2026-02-14 23:04:25', 'User logged in: john (teacher) - School Year: 2025-2026'),
(50, 25, 'LOGOUT', 'users', 25, '2026-02-14 23:20:42', 'User logged out: john (teacher)'),
(51, 6, 'LOGIN', 'users', 6, '2026-02-14 23:20:53', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(52, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-14 23:26:35', 'Admin switched to school year: 2024-2025'),
(53, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-14 23:26:52', 'Admin switched to school year: 2025-2026'),
(54, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-14 23:27:04', 'Admin switched to school year: 2024-2025'),
(55, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-14 23:27:10', 'Admin switched to school year: 2025-2026'),
(56, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-14 23:27:20', 'Admin switched to school year: 2024-2025'),
(57, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-14 23:27:35', 'Admin switched to school year: 2025-2026'),
(58, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-14 23:27:39', 'Admin switched to school year: 2024-2025'),
(59, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-14 23:29:23', 'Admin switched to school year: 2025-2026'),
(60, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-14 23:29:42', 'Admin switched to school year: 2024-2025'),
(61, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-14 23:36:47', 'Admin switched to school year: 2025-2026'),
(62, 6, 'INSERT', 'students', 2, '2026-02-14 23:59:32', 'Added new student: MARS BELLA (LRN: 123124124121231)'),
(63, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-14 23:59:39', 'Admin switched to school year: 2024-2025'),
(64, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-14 23:59:44', 'Admin switched to school year: 2025-2026'),
(65, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-14 23:59:47', 'Admin switched to school year: 2024-2025'),
(66, 6, 'LOGOUT', 'users', 6, '2026-02-15 00:23:32', 'User logged out: BARON THE 3RD (admin)'),
(67, 25, 'LOGIN', 'users', 25, '2026-02-15 00:23:41', 'User logged in: john (teacher) - School Year: 2025-2026'),
(68, 25, 'LOGOUT', 'users', 25, '2026-02-15 00:24:06', 'User logged out: john (teacher)'),
(69, 25, 'LOGIN', 'users', 25, '2026-02-15 00:24:18', 'User logged in: john (teacher) - School Year: 2024-2025'),
(70, 25, 'LOGOUT', 'users', 25, '2026-02-15 00:24:39', 'User logged out: john (teacher)'),
(71, 6, 'LOGIN', 'users', 6, '2026-02-15 00:24:48', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(72, 6, 'INSERT', 'schools_attended', 0, '2026-02-15 00:35:24', 'Added Grade 1 record for MARS BELLA (LRN: 123124124121231) at NEW SOCIETY - Section Brad (TRANSFER)'),
(73, 6, 'UPDATE', 'schools_attended', 4, '2026-02-15 00:35:40', 'Updated Grade 1 - Section Brad record for MARS BELLA (LRN: 123124124121231) at NEW SOCIETY'),
(74, 6, 'UPDATE', 'schools_attended', 4, '2026-02-15 00:36:52', 'Updated Grade 1 - Section Brad record for MARS BELLA (LRN: 123124124121231) at NEW SOCIETY'),
(75, 6, 'UPDATE', 'schools_attended', 2, '2026-02-15 00:37:08', 'Updated Grade 1 - Section brad record for ASDASDASD ASDASDASD (LRN: 23123123) at NEW MABUHAY ELEMENTARY SCHOOL'),
(76, 6, 'INSERT', 'schools_attended', 0, '2026-02-15 00:46:25', 'Added Grade 3 record for ASDASDASD ASDASDASD (LRN: 23123123) at asdasda - Section asdasd (TRANSFER)'),
(77, 6, 'UPDATE', 'schools_attended', 3, '2026-02-15 00:47:20', 'Updated Grade 2 - Section lora record for ASDASDASD ASDASDASD (LRN: 23123123) at NEW MABUHAY ELEMENTARY SCHOOL'),
(78, 6, 'LOGOUT', 'users', 6, '2026-02-15 00:47:32', 'User logged out: BARON THE 3RD (admin)'),
(79, 25, 'LOGIN', 'users', 25, '2026-02-15 00:47:40', 'User logged in: john (teacher) - School Year: 2024-2025'),
(80, 25, 'LOGOUT', 'users', 25, '2026-02-15 02:28:04', 'User logged out: john (teacher)'),
(81, 6, 'LOGIN', 'users', 6, '2026-02-15 02:28:12', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(82, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 02:28:36', 'Admin switched to school year: 2024-2025'),
(83, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 02:28:39', 'Admin switched to school year: 2025-2026'),
(84, 6, 'INSERT', 'classes', 3, '2026-02-15 02:30:10', 'Added new class: Grade 6 - Diamond (SY: 2025-2026)'),
(85, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 02:31:09', 'Admin switched to school year: 2024-2025'),
(86, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 02:31:15', 'Admin switched to school year: 2025-2026'),
(87, 6, 'LOGOUT', 'users', 6, '2026-02-15 02:31:44', 'User logged out: BARON THE 3RD (admin)'),
(88, 26, 'LOGIN', 'users', 26, '2026-02-15 02:31:55', 'User logged in: tarah (teacher) - School Year: 2025-2026'),
(89, 26, 'LOGOUT', 'users', 26, '2026-02-15 02:36:10', 'User logged out: tarah (teacher)'),
(90, 6, 'LOGIN', 'users', 6, '2026-02-15 02:36:18', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(91, 6, 'LOGOUT', 'users', 6, '2026-02-15 02:38:53', 'User logged out: BARON THE 3RD (admin)'),
(92, 25, 'LOGIN', 'users', 25, '2026-02-15 02:39:03', 'User logged in: john (teacher) - School Year: 2025-2026'),
(93, 25, 'LOGOUT', 'users', 25, '2026-02-15 02:46:26', 'User logged out: john (teacher)'),
(94, 6, 'LOGIN', 'users', 6, '2026-02-15 02:46:34', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(95, 6, 'LOGOUT', 'users', 6, '2026-02-15 02:57:48', 'User logged out: BARON THE 3RD (admin)'),
(96, 25, 'LOGIN', 'users', 25, '2026-02-15 02:57:56', 'User logged in: john (teacher) - School Year: 2025-2026'),
(97, 25, 'LOGOUT', 'users', 25, '2026-02-15 03:00:32', 'User logged out: john (teacher)'),
(98, 26, 'LOGIN', 'users', 26, '2026-02-15 03:00:52', 'User logged in: tarah (teacher) - School Year: 2025-2026'),
(99, 26, 'LOGOUT', 'users', 26, '2026-02-15 03:01:57', 'User logged out: tarah (teacher)'),
(100, 6, 'LOGIN', 'users', 6, '2026-02-15 03:02:04', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(101, 6, 'LOGOUT', 'users', 6, '2026-02-15 03:06:27', 'User logged out: BARON THE 3RD (admin)'),
(102, 25, 'LOGIN', 'users', 25, '2026-02-15 03:06:34', 'User logged in: john (teacher) - School Year: 2025-2026'),
(103, 25, 'LOGOUT', 'users', 25, '2026-02-15 03:09:31', 'User logged out: john (teacher)'),
(104, 6, 'LOGIN', 'users', 6, '2026-02-15 03:09:38', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(105, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 03:10:02', 'Admin switched to school year: 2024-2025'),
(106, 6, 'LOGOUT', 'users', 6, '2026-02-15 03:12:57', 'User logged out: BARON THE 3RD (admin)'),
(107, 25, 'LOGIN', 'users', 25, '2026-02-15 03:13:08', 'User logged in: john (teacher) - School Year: 2025-2026'),
(108, 25, 'LOGOUT', 'users', 25, '2026-02-15 03:13:36', 'User logged out: john (teacher)'),
(109, 6, 'LOGIN', 'users', 6, '2026-02-15 03:13:43', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(110, 6, 'LOGOUT', 'users', 6, '2026-02-15 03:20:35', 'User logged out: BARON THE 3RD (admin)'),
(111, 25, 'LOGIN', 'users', 25, '2026-02-15 03:22:56', 'User logged in: john (teacher) - School Year: 2025-2026'),
(112, 25, 'LOGOUT', 'users', 25, '2026-02-15 03:24:19', 'User logged out: john (teacher)'),
(113, 6, 'LOGIN', 'users', 6, '2026-02-15 03:24:27', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(114, 6, 'INSERT', 'classes', 4, '2026-02-15 03:25:40', 'Added new class: Grade 3 - bird (SY: 2025-2026)'),
(115, 6, 'LOGOUT', 'users', 6, '2026-02-15 03:26:07', 'User logged out: BARON THE 3RD (admin)'),
(116, 6, 'LOGIN', 'users', 6, '2026-02-15 03:26:37', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(117, 6, 'LOGOUT', 'users', 6, '2026-02-15 03:26:57', 'User logged out: BARON THE 3RD (admin)'),
(118, 24, 'LOGIN', 'users', 24, '2026-02-15 03:27:04', 'User logged in: kaia (teacher) - School Year: 2025-2026'),
(119, 24, 'LOGOUT', 'users', 24, '2026-02-15 03:27:54', 'User logged out: kaia (teacher)'),
(120, 26, 'LOGIN', 'users', 26, '2026-02-15 03:28:06', 'User logged in: tarah (teacher) - School Year: 2025-2026'),
(121, 26, 'LOGOUT', 'users', 26, '2026-02-15 03:28:12', 'User logged out: tarah (teacher)'),
(122, 6, 'LOGIN', 'users', 6, '2026-02-15 03:28:20', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(123, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 03:28:45', 'Admin switched to school year: 2024-2025'),
(124, 6, 'LOGOUT', 'users', 6, '2026-02-15 03:29:27', 'User logged out: BARON THE 3RD (admin)'),
(125, 25, 'LOGIN', 'users', 25, '2026-02-15 03:29:45', 'User logged in: john (teacher) - School Year: 2025-2026'),
(126, 25, 'LOGOUT', 'users', 25, '2026-02-15 03:30:04', 'User logged out: john (teacher)'),
(127, 25, 'LOGIN', 'users', 25, '2026-02-15 03:30:16', 'User logged in: john (teacher) - School Year: 2024-2025'),
(128, 25, 'LOGOUT', 'users', 25, '2026-02-15 03:32:51', 'User logged out: john (teacher)'),
(129, 6, 'LOGIN', 'users', 6, '2026-02-15 03:32:58', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(130, 6, 'DELETE', 'schools_attended', 4, '2026-02-15 03:33:19', 'Deleted Grade 1 record for MARS BELLA (LRN: 123124124121231)'),
(131, 6, 'DELETE', 'schools_attended', 6, '2026-02-15 03:33:30', 'Deleted Grade 2 record for MARS BELLA (LRN: 123124124121231)'),
(132, 6, 'LOGOUT', 'users', 6, '2026-02-15 03:33:39', 'User logged out: BARON THE 3RD (admin)'),
(133, 25, 'LOGIN', 'users', 25, '2026-02-15 03:34:00', 'User logged in: john (teacher) - School Year: 2024-2025'),
(134, 25, 'LOGOUT', 'users', 25, '2026-02-15 12:14:30', 'User logged out: john (teacher)'),
(135, 6, 'LOGIN', 'users', 6, '2026-02-15 12:14:38', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(136, 6, 'LOGOUT', 'users', 6, '2026-02-15 12:15:24', 'User logged out: BARON THE 3RD (admin)'),
(137, 25, 'LOGIN', 'users', 25, '2026-02-15 12:15:32', 'User logged in: john (teacher) - School Year: 2025-2026'),
(138, 25, 'LOGOUT', 'users', 25, '2026-02-15 12:17:08', 'User logged out: john (teacher)'),
(139, 6, 'LOGIN', 'users', 6, '2026-02-15 12:17:15', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(140, 6, 'LOGOUT', 'users', 6, '2026-02-15 12:19:34', 'User logged out: BARON THE 3RD (admin)'),
(141, 25, 'LOGIN', 'users', 25, '2026-02-15 12:19:42', 'User logged in: john (teacher) - School Year: 2025-2026'),
(142, 25, 'LOGOUT', 'users', 25, '2026-02-15 12:23:35', 'User logged out: john (teacher)'),
(143, 6, 'LOGIN', 'users', 6, '2026-02-15 12:23:42', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(144, 6, 'LOGOUT', 'users', 6, '2026-02-15 12:46:55', 'User logged out: BARON THE 3RD (admin)'),
(145, 25, 'LOGIN', 'users', 25, '2026-02-15 12:47:05', 'User logged in: john (teacher) - School Year: 2025-2026'),
(146, 25, 'LOGOUT', 'users', 25, '2026-02-15 12:50:29', 'User logged out: john (teacher)'),
(147, 6, 'LOGIN', 'users', 6, '2026-02-15 12:50:39', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(148, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 12:54:56', 'Admin switched to school year: 2024-2025'),
(149, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-15 12:55:58', 'Quarter 1 unlocked for school year 2025-2026'),
(150, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 12:56:06', 'Admin switched to school year: 2024-2025'),
(151, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 12:56:09', 'Admin switched to school year: 2025-2026'),
(152, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 12:56:22', 'Admin switched to school year: 2024-2025'),
(153, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 12:56:26', 'Admin switched to school year: 2025-2026'),
(154, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 12:56:44', 'Admin switched to school year: 2024-2025'),
(155, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 12:56:49', 'Admin switched to school year: 2025-2026'),
(156, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 12:56:58', 'Admin switched to school year: 2024-2025'),
(157, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 12:57:06', 'Admin switched to school year: 2025-2026'),
(158, 6, 'LOGOUT', 'users', 6, '2026-02-15 14:53:13', 'User logged out: BARON THE 3RD (admin)'),
(159, 25, 'LOGIN', 'users', 25, '2026-02-15 14:53:22', 'User logged in: john (teacher) - School Year: 2025-2026'),
(160, 25, 'LOGOUT', 'users', 25, '2026-02-15 15:14:32', 'User logged out: john (teacher)'),
(161, 6, 'LOGIN', 'users', 6, '2026-02-15 15:14:40', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(162, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 15:16:01', 'Admin switched to school year: 2024-2025'),
(163, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 15:20:54', 'Admin switched to school year: 2025-2026'),
(164, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 15:21:17', 'Admin switched to school year: 2024-2025'),
(165, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-15 15:21:22', 'Quarter 1 locked for school year 2025-2026'),
(166, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-15 15:22:33', 'Quarter 1 locked for school year 2025-2026'),
(167, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 15:22:50', 'Admin switched to school year: 2025-2026'),
(168, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-15 15:22:54', 'Quarter 1 unlocked for school year 2025-2026'),
(169, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-15 15:24:19', 'Quarter 1 locked for school year 2025-2026'),
(170, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 15:24:24', 'Admin switched to school year: 2024-2025'),
(171, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-15 15:24:29', 'Quarter 1 locked for school year 2025-2026'),
(172, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-15 15:24:33', 'Quarter 2 locked for school year 2025-2026'),
(173, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-15 15:31:20', 'Quarter 1 locked for school year 2024-2025'),
(174, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-15 15:31:24', 'Quarter 2 locked for school year 2024-2025'),
(175, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 15:31:39', 'Admin switched to school year: 2025-2026'),
(176, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-15 15:31:44', 'Quarter 1 unlocked for school year 2025-2026'),
(177, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 15:31:51', 'Admin switched to school year: 2024-2025'),
(178, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 15:35:14', 'Admin switched to school year: 2025-2026'),
(179, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 15:35:18', 'Admin switched to school year: 2024-2025'),
(180, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-15 15:36:53', 'Admin switched to school year: 2025-2026'),
(181, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-15 15:36:57', 'Admin switched to school year: 2024-2025'),
(182, 6, 'LOGOUT', 'users', 6, '2026-02-15 15:42:23', 'User logged out: BARON THE 3RD (admin)'),
(183, 25, 'LOGIN', 'users', 25, '2026-02-15 15:42:37', 'User logged in: john (teacher) - School Year: 2025-2026'),
(184, 6, 'LOGIN', 'users', 6, '2026-02-16 00:12:26', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(185, 25, 'LOGIN', 'users', 25, '2026-02-16 00:33:27', 'User logged in: john (teacher) - School Year: 2025-2026'),
(186, 6, 'LOGOUT', 'users', 6, '2026-02-16 00:33:53', 'User logged out: BARON THE 3RD (admin)'),
(187, 25, 'LOGIN', 'users', 25, '2026-02-16 00:34:01', 'User logged in: john (teacher) - School Year: 2025-2026'),
(188, 25, 'LOGOUT', 'users', 25, '2026-02-16 00:58:45', 'User logged out: john (teacher)'),
(189, 6, 'LOGIN', 'users', 6, '2026-02-16 00:58:56', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(190, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-16 00:59:30', 'Admin switched to school year: 2024-2025'),
(191, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-16 00:59:44', 'Admin switched to school year: 2025-2026'),
(192, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-16 00:59:49', 'Admin switched to school year: 2024-2025'),
(193, 6, 'LOGOUT', 'users', 6, '2026-02-16 01:00:23', 'User logged out: BARON THE 3RD (admin)'),
(194, 25, 'LOGIN', 'users', 25, '2026-02-16 01:00:36', 'User logged in: john (teacher) - School Year: 2024-2025'),
(195, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:03:06', 'User logged out: john (teacher)'),
(196, 6, 'LOGIN', 'users', 6, '2026-02-16 01:03:13', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(197, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-16 01:03:21', 'Admin switched to school year: 2024-2025'),
(198, 6, 'LOGOUT', 'users', 6, '2026-02-16 01:06:27', 'User logged out: BARON THE 3RD (admin)'),
(199, 25, 'LOGIN', 'users', 25, '2026-02-16 01:06:40', 'User logged in: john (teacher) - School Year: 2024-2025'),
(200, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:10:05', 'User logged out: john (teacher)'),
(201, 25, 'LOGIN', 'users', 25, '2026-02-16 01:10:12', 'User logged in: john (teacher) - School Year: 2025-2026'),
(202, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:10:25', 'User logged out: john (teacher)'),
(203, 25, 'LOGIN', 'users', 25, '2026-02-16 01:10:34', 'User logged in: john (teacher) - School Year: 2025-2026'),
(204, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:10:38', 'User logged out: john (teacher)'),
(205, 6, 'LOGIN', 'users', 6, '2026-02-16 01:10:45', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(206, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-16 01:16:47', 'Admin switched to school year: 2024-2025'),
(207, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-16 01:16:50', 'Admin switched to school year: 2025-2026'),
(208, 6, 'LOGOUT', 'users', 6, '2026-02-16 01:16:54', 'User logged out: BARON THE 3RD (admin)'),
(209, 25, 'LOGIN', 'users', 25, '2026-02-16 01:17:00', 'User logged in: john (teacher) - School Year: 2025-2026'),
(210, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:17:13', 'User logged out: john (teacher)'),
(211, 25, 'LOGIN', 'users', 25, '2026-02-16 01:17:22', 'User logged in: john (teacher) - School Year: 2024-2025'),
(212, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:17:50', 'User logged out: john (teacher)'),
(213, 6, 'LOGIN', 'users', 6, '2026-02-16 01:17:59', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(214, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-16 01:18:05', 'Admin switched to school year: 2024-2025'),
(215, 6, 'LOGOUT', 'users', 6, '2026-02-16 01:18:38', 'User logged out: BARON THE 3RD (admin)'),
(216, 25, 'LOGIN', 'users', 25, '2026-02-16 01:18:48', 'User logged in: john (teacher) - School Year: 2024-2025'),
(217, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:19:05', 'User logged out: john (teacher)'),
(218, 6, 'LOGIN', 'users', 6, '2026-02-16 01:19:15', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(219, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-16 01:19:18', 'Admin switched to school year: 2024-2025'),
(220, 6, 'LOGOUT', 'users', 6, '2026-02-16 01:23:02', 'User logged out: BARON THE 3RD (admin)'),
(221, 25, 'LOGIN', 'users', 25, '2026-02-16 01:23:16', 'User logged in: john (teacher) - School Year: 2024-2025'),
(222, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:26:43', 'User logged out: john (teacher)'),
(223, 25, 'LOGIN', 'users', 25, '2026-02-16 01:26:54', 'User logged in: john (teacher) - School Year: 2025-2026'),
(224, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:28:53', 'User logged out: john (teacher)'),
(225, 6, 'LOGIN', 'users', 6, '2026-02-16 01:29:00', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(226, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-16 01:29:36', 'Admin switched to school year: 2024-2025'),
(227, 6, 'LOGOUT', 'users', 6, '2026-02-16 01:30:52', 'User logged out: BARON THE 3RD (admin)'),
(228, 25, 'LOGIN', 'users', 25, '2026-02-16 01:31:00', 'User logged in: john (teacher) - School Year: 2024-2025'),
(229, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:31:24', 'User logged out: john (teacher)'),
(230, 6, 'LOGIN', 'users', 6, '2026-02-16 01:31:31', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(231, 6, 'INSERT', 'students', 3, '2026-02-16 01:32:01', 'Added new student: QWE QWE (LRN: 1231231441)'),
(232, 6, 'LOGOUT', 'users', 6, '2026-02-16 01:35:18', 'User logged out: BARON THE 3RD (admin)'),
(233, 6, 'LOGIN', 'users', 6, '2026-02-16 01:35:31', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(234, 6, 'UPDATE', 'school_years', 2, '2026-02-16 01:35:40', 'Updated school year: 2024-2025'),
(235, 6, 'LOGOUT', 'users', 6, '2026-02-16 01:35:46', 'User logged out: BARON THE 3RD (admin)'),
(236, 25, 'LOGIN', 'users', 25, '2026-02-16 01:36:01', 'User logged in: john (teacher) - School Year: 2024-2025'),
(237, 25, 'LOGOUT', 'users', 25, '2026-02-16 01:37:51', 'User logged out: john (teacher)'),
(238, 6, 'LOGIN', 'users', 6, '2026-02-16 01:37:58', 'User logged in: BARON THE 3RD (admin) - School Year: 2024-2025'),
(239, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-16 01:38:06', 'Quarter 3 locked for school year 2024-2025'),
(240, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-16 01:38:09', 'Quarter 4 locked for school year 2024-2025'),
(241, 6, 'LOGOUT', 'users', 6, '2026-02-16 01:39:39', 'User logged out: BARON THE 3RD (admin)'),
(242, 25, 'LOGIN', 'users', 25, '2026-02-16 01:39:46', 'User logged in: john (teacher) - School Year: 2024-2025'),
(243, 25, 'LOGOUT', 'users', 25, '2026-02-16 02:02:34', 'User logged out: john (teacher)'),
(244, 25, 'LOGIN', 'users', 25, '2026-02-16 02:02:44', 'User logged in: john (teacher) - School Year: 2024-2025'),
(245, 25, 'LOGOUT', 'users', 25, '2026-02-16 02:03:12', 'User logged out: john (teacher)'),
(246, 6, 'LOGIN', 'users', 6, '2026-02-16 02:03:27', 'User logged in: BARON THE 3RD (admin) - School Year: 2024-2025'),
(247, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-16 02:05:00', 'Quarter 2 unlocked for school year 2024-2025'),
(248, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-16 02:05:00', 'Quarter 1 unlocked for school year 2024-2025'),
(249, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-16 02:05:09', 'Quarter 3 unlocked for school year 2024-2025'),
(250, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-16 02:05:14', 'Quarter 3 locked for school year 2024-2025'),
(251, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-16 02:05:17', 'Quarter 2 locked for school year 2024-2025'),
(252, 25, 'LOGOUT', 'users', 25, '2026-02-16 02:24:48', 'User logged out: john (teacher)'),
(253, 6, 'LOGIN', 'users', 6, '2026-02-16 02:24:54', 'User logged in: BARON THE 3RD (admin) - School Year: 2024-2025'),
(254, 6, 'LOGOUT', 'users', 6, '2026-02-16 02:44:05', 'User logged out: BARON THE 3RD (admin)'),
(255, 25, 'LOGIN', 'users', 25, '2026-02-16 02:44:11', 'User logged in: john (teacher) - School Year: 2024-2025'),
(256, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:52:35', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(257, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:52:35', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(258, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:52:38', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(259, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:52:39', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(260, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:52:53', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(261, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:52:57', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(262, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:54:15', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(263, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:54:15', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(264, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:54:27', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(265, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:54:48', 'Entered 7 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(266, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 03:54:48', 'Entered 7 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(267, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:27', 'Entered 7 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(268, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:27', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(269, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:27', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(270, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:27', 'Entered 5 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(271, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:28', 'Entered 5 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(272, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:28', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(273, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:28', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(274, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:39', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(275, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:41', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(276, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:41', 'Entered 1 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(277, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:02:45', 'Entered 1 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(278, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:08:32', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(279, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:08:32', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(280, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:09:10', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(281, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:09:10', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(282, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:11:06', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(283, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:11:06', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(284, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:11:23', 'Entered 5 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(285, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:11:24', 'Entered 5 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(286, 6, 'GRADE_ENTRY', 'grades', 5, '2026-02-16 04:24:23', 'Entered 1 grade(s) for student: Juan Dela Cruz (SY: 2024-2025)'),
(287, 6, 'GRADE_ENTRY', 'grades', 5, '2026-02-16 04:24:23', 'Entered 1 grade(s) for student: Juan Dela Cruz (SY: 2024-2025)'),
(288, 6, 'GRADE_ENTRY', 'grades', 5, '2026-02-16 04:24:30', 'Entered 1 grade(s) for student: Juan Dela Cruz (SY: 2024-2025)'),
(289, 6, 'GRADE_ENTRY', 'grades', 5, '2026-02-16 04:24:42', 'Entered 2 grade(s) for student: Juan Dela Cruz (SY: 2024-2025)'),
(290, 6, 'GRADE_ENTRY', 'grades', 5, '2026-02-16 04:24:43', 'Entered 2 grade(s) for student: Juan Dela Cruz (SY: 2024-2025)'),
(291, 6, 'GRADE_ENTRY', 'grades', 5, '2026-02-16 04:24:48', 'Entered 2 grade(s) for student: Juan Dela Cruz (SY: 2024-2025)'),
(292, 6, 'GRADE_ENTRY', 'grades', 5, '2026-02-16 04:24:48', 'Entered 1 grade(s) for student: Juan Dela Cruz (SY: 2024-2025)'),
(293, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:25:56', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(294, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:25:57', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(295, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:02', 'Entered 6 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(296, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:03', 'Entered 5 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(297, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:15', 'Entered 5 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(298, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:16', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(299, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:18', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(300, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:18', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(301, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:21', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(302, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:21', 'Entered 1 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(303, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:29', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(304, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:30', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(305, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:31', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(306, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:26:31', 'Entered 1 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(307, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:27:12', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(308, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:27:13', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(309, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:27:20', 'Entered 1 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(310, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:27:21', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(311, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-16 04:27:27', 'Entered 1 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(312, 6, 'LOGIN', 'users', 6, '2026-02-16 13:13:26', 'User logged in: BARON THE 3RD (admin) - School Year: 2024-2025'),
(313, 6, 'LOGIN', 'users', 6, '2026-02-17 23:41:40', 'User logged in: BARON THE 3RD (admin) - School Year: 2024-2025'),
(314, 25, 'LOGIN', 'users', 25, '2026-02-17 23:43:58', 'User logged in: john (teacher) - School Year: 2024-2025'),
(315, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-17 23:45:22', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(316, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-17 23:45:23', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(317, 6, 'GRADE_ENTRY', 'grades', 10, '2026-02-17 23:48:48', 'Entered 4 grade(s) for student: Gabriela Hernandez (SY: 2024-2025)'),
(318, 6, 'GRADE_ENTRY', 'grades', 10, '2026-02-17 23:48:48', 'Entered 4 grade(s) for student: Gabriela Hernandez (SY: 2024-2025)'),
(319, 6, 'GRADE_ENTRY', 'grades', 10, '2026-02-17 23:48:50', 'Entered 4 grade(s) for student: Gabriela Hernandez (SY: 2024-2025)'),
(320, 6, 'GRADE_ENTRY', 'grades', 10, '2026-02-17 23:49:03', 'Entered 4 grade(s) for student: Gabriela Hernandez (SY: 2024-2025)'),
(321, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:01:35', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(322, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:01:35', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(323, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:01:38', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(324, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:01:53', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(325, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:02:14', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(326, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:02:26', 'Entered 2 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(327, 25, 'LOGOUT', 'users', 25, '2026-02-18 00:07:33', 'User logged out: john (teacher)'),
(328, 6, 'LOGIN', 'users', 6, '2026-02-18 00:07:45', 'User logged in: BARON THE 3RD (admin) - School Year: 2024-2025'),
(329, 6, 'LOGOUT', 'users', 6, '2026-02-18 00:07:53', 'User logged out: BARON THE 3RD (admin)'),
(330, 25, 'LOGIN', 'users', 25, '2026-02-18 00:08:06', 'User logged in: john (teacher) - School Year: 2024-2025'),
(331, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:11:32', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(332, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:11:33', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(333, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:11:40', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(334, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:14:49', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(335, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:30:04', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(336, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:30:22', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(337, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:30:32', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(338, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:30:37', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(339, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:30:38', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(340, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:30:44', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(341, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:31:07', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(342, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:38:38', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(343, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:38:45', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(344, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:42:28', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(345, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:42:33', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(346, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:42:34', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(347, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:42:37', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(348, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:48:43', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(349, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:49:00', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(350, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:49:10', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(351, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:49:21', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(352, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:49:25', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(353, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:49:41', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(354, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:53:11', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(355, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:53:22', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(356, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:53:33', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(357, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 00:57:26', 'Entered 3 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(358, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 01:02:30', 'Entered 4 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(359, 6, 'GRADE_ENTRY', 'grades', 3, '2026-02-18 01:04:32', 'Entered 5 grade(s) for student: QWE QWE (SY: 2024-2025)'),
(360, 25, 'LOGOUT', 'users', 25, '2026-02-18 01:15:49', 'User logged out: john (teacher)'),
(361, 6, 'LOGIN', 'users', 6, '2026-02-18 01:15:58', 'User logged in: BARON THE 3RD (admin) - School Year: 2024-2025'),
(362, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-18 01:51:47', 'Admin switched to school year: 2025-2026'),
(363, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-18 01:51:50', 'Admin switched to school year: 2024-2025'),
(364, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-18 02:00:05', 'Admin switched to school year: 2025-2026'),
(365, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-18 02:00:10', 'Admin switched to school year: 2024-2025'),
(366, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-18 02:00:16', 'Quarter 1 locked for school year 2024-2025'),
(367, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-18 02:00:19', 'Admin switched to school year: 2025-2026'),
(368, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-18 02:00:22', 'Admin switched to school year: 2024-2025'),
(369, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-18 03:05:43', 'Admin switched to school year: 2025-2026'),
(370, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-18 03:07:12', 'Admin switched to school year: 2024-2025'),
(371, 6, 'UPDATE', 'school_years', 2, '2026-02-18 03:44:55', 'Updated school year: 2024-2025'),
(372, 6, 'UPDATE', 'school_years', 1, '2026-02-18 03:45:03', 'Updated school year: 2025-2026'),
(373, 6, 'LOGOUT', 'users', 6, '2026-02-18 03:45:46', 'User logged out: BARON THE 3RD (admin)'),
(374, 25, 'LOGIN', 'users', 25, '2026-02-18 03:46:01', 'User logged in: john (teacher) - School Year: 2024-2025'),
(375, 6, 'UPDATE', 'school_years', 2, '2026-02-18 03:46:54', 'Updated school year: 2024-2025'),
(376, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-18 03:47:51', 'Quarter 1 unlocked for school year 2024-2025'),
(377, 6, 'UPDATE', 'school_years', 1, '2026-02-18 03:57:26', 'Updated school year: 2025-2026'),
(378, 6, 'LOGOUT', 'users', 6, '2026-02-18 04:03:48', 'User logged out: BARON THE 3RD (admin)'),
(379, 25, 'LOGIN', 'users', 25, '2026-02-18 04:04:00', 'User logged in: john (teacher) - School Year: 2024-2025'),
(380, 25, 'LOGOUT', 'users', 25, '2026-02-18 04:09:02', 'User logged out: john (teacher)'),
(381, 6, 'LOGIN', 'users', 6, '2026-02-18 04:09:12', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(382, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-18 04:53:34', 'Admin switched to school year: 2024-2025'),
(383, 25, 'LOGOUT', 'users', 25, '2026-02-18 04:56:33', 'User logged out: john (teacher)'),
(384, 25, 'LOGIN', 'users', 25, '2026-02-18 04:56:49', 'User logged in: john (teacher) - School Year: 2025-2026'),
(385, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-18 05:28:25', 'Admin switched to school year: 2025-2026'),
(386, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-18 05:28:31', 'Admin switched to school year: 2024-2025'),
(387, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-18 05:28:34', 'Admin switched to school year: 2025-2026'),
(388, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-18 05:30:08', 'Admin switched to school year: 2024-2025'),
(389, 6, 'LOGIN', 'users', 6, '2026-02-18 05:33:46', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(390, 6, 'LOGIN', 'users', 6, '2026-02-18 09:58:27', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(391, 25, 'LOGIN', 'users', 25, '2026-02-18 11:35:38', 'User logged in: john (teacher) - School Year: 2025-2026'),
(392, 6, 'LOGOUT', 'users', 6, '2026-02-18 11:36:21', 'User logged out: BARON THE 3RD (admin)'),
(393, 25, 'LOGIN', 'users', 25, '2026-02-18 11:36:29', 'User logged in: john (teacher) - School Year: 2025-2026'),
(394, 25, 'LOGOUT', 'users', 25, '2026-02-18 11:37:59', 'User logged out: john (teacher)'),
(395, 6, 'LOGIN', 'users', 6, '2026-02-18 11:38:04', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(396, 25, 'LOGOUT', 'users', 25, '2026-02-18 12:16:56', 'User logged out: john (teacher)'),
(397, 6, 'LOGIN', 'users', 6, '2026-02-18 12:17:04', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(398, 6, 'LOGOUT', 'users', 6, '2026-02-18 12:20:04', 'User logged out: BARON THE 3RD (admin)'),
(399, 25, 'LOGIN', 'users', 25, '2026-02-18 12:20:12', 'User logged in: john (teacher) - School Year: 2025-2026'),
(400, 25, 'LOGOUT', 'users', 25, '2026-02-18 12:22:11', 'User logged out: john (teacher)'),
(401, 6, 'LOGIN', 'users', 6, '2026-02-18 12:22:19', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(402, 6, 'LOGOUT', 'users', 6, '2026-02-18 12:27:42', 'User logged out: BARON THE 3RD (admin)'),
(403, 25, 'LOGIN', 'users', 25, '2026-02-18 12:27:50', 'User logged in: john (teacher) - School Year: 2025-2026'),
(404, 25, 'LOGOUT', 'users', 25, '2026-02-18 12:28:51', 'User logged out: john (teacher)'),
(405, 6, 'LOGIN', 'users', 6, '2026-02-18 12:29:06', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(406, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-18 13:04:38', 'Admin switched to school year: 2024-2025'),
(407, 6, 'LOGOUT', 'users', 6, '2026-02-18 13:08:55', 'User logged out: BARON THE 3RD (admin)'),
(408, 25, 'LOGIN', 'users', 25, '2026-02-18 13:09:04', 'User logged in: john (teacher) - School Year: 2025-2026'),
(409, 25, 'LOGOUT', 'users', 25, '2026-02-18 14:17:27', 'User logged out: john (teacher)'),
(410, 6, 'LOGIN', 'users', 6, '2026-02-18 14:20:31', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(411, 6, 'INSERT', 'schools_attended', 0, '2026-02-18 14:53:49', 'Added Grade 1 record for Diego Lopez (LRN: 202401008) at GSCPO - Section A (TRANSFER)'),
(412, 6, 'LOGOUT', 'users', 6, '2026-02-18 15:05:08', 'User logged out: BARON THE 3RD (admin)'),
(413, 25, 'LOGIN', 'users', 25, '2026-02-18 15:05:17', 'User logged in: john (teacher) - School Year: 2025-2026'),
(414, 25, 'LOGOUT', 'users', 25, '2026-02-18 15:07:38', 'User logged out: john (teacher)'),
(415, 6, 'LOGIN', 'users', 6, '2026-02-18 15:07:45', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(416, 6, 'LOGOUT', 'users', 6, '2026-02-18 15:08:01', 'User logged out: BARON THE 3RD (admin)'),
(417, 25, 'LOGIN', 'users', 25, '2026-02-18 15:08:10', 'User logged in: john (teacher) - School Year: 2025-2026'),
(418, 25, 'LOGOUT', 'users', 25, '2026-02-18 16:24:53', 'User logged out: john (teacher)'),
(419, 6, 'LOGIN', 'users', 6, '2026-02-18 16:25:01', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(420, 6, 'LOGOUT', 'users', 6, '2026-02-18 16:50:36', 'User logged out: BARON THE 3RD (admin)'),
(421, 24, 'LOGIN', 'users', 24, '2026-02-18 16:50:53', 'User logged in: kaia (teacher) - School Year: 2025-2026'),
(422, 24, 'LOGOUT', 'users', 24, '2026-02-18 16:51:57', 'User logged out: kaia (teacher)'),
(423, 6, 'LOGIN', 'users', 6, '2026-02-18 16:52:03', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(424, 6, 'LOGOUT', 'users', 6, '2026-02-18 16:53:50', 'User logged out: BARON THE 3RD (admin)'),
(425, 24, 'LOGIN', 'users', 24, '2026-02-18 16:53:58', 'User logged in: kaia (teacher) - School Year: 2025-2026'),
(426, 6, 'LOGIN', 'users', 6, '2026-02-18 16:56:55', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(427, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-18 16:59:06', 'Admin switched to school year: 2024-2025'),
(428, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-18 17:00:50', 'Admin switched to school year: 2025-2026'),
(429, 24, 'LOGOUT', 'users', 24, '2026-02-18 17:10:59', 'User logged out: kaia (teacher)'),
(430, 6, 'LOGIN', 'users', 6, '2026-02-18 17:11:10', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(431, 6, 'LOGOUT', 'users', 6, '2026-02-18 19:03:28', 'User logged out: BARON THE 3RD (admin)'),
(432, 27, 'LOGIN', 'users', 27, '2026-02-18 19:03:39', 'User logged in: justine (teacher) - School Year: 2025-2026'),
(433, 27, 'LOGOUT', 'users', 27, '2026-02-18 19:04:52', 'User logged out: justine (teacher)'),
(434, 6, 'LOGIN', 'users', 6, '2026-02-18 19:04:59', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(435, 6, 'LOGOUT', 'users', 6, '2026-02-18 19:05:32', 'User logged out: BARON THE 3RD (admin)'),
(436, 27, 'LOGIN', 'users', 27, '2026-02-18 19:05:41', 'User logged in: justine (teacher) - School Year: 2025-2026'),
(437, 27, 'LOGOUT', 'users', 27, '2026-02-18 19:06:08', 'User logged out: justine (teacher)'),
(438, 6, 'LOGIN', 'users', 6, '2026-02-18 19:06:15', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(439, 6, 'INSERT', 'classes', 5, '2026-02-18 19:09:10', 'Added new class: Grade 4 - hello (SY: 2025-2026)'),
(440, 6, 'LOGOUT', 'users', 6, '2026-02-18 19:09:51', 'User logged out: BARON THE 3RD (admin)'),
(441, 28, 'LOGIN', 'users', 28, '2026-02-18 19:09:58', 'User logged in: mary (teacher) - School Year: 2025-2026'),
(442, 28, 'LOGOUT', 'users', 28, '2026-02-18 19:10:34', 'User logged out: mary (teacher)'),
(443, 6, 'LOGIN', 'users', 6, '2026-02-18 19:10:41', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(444, 6, 'LOGOUT', 'users', 6, '2026-02-18 19:11:04', 'User logged out: BARON THE 3RD (admin)'),
(445, 28, 'LOGIN', 'users', 28, '2026-02-18 19:11:18', 'User logged in: mary (teacher) - School Year: 2025-2026'),
(446, 28, 'LOGOUT', 'users', 28, '2026-02-18 19:12:24', 'User logged out: mary (teacher)'),
(447, 6, 'LOGIN', 'users', 6, '2026-02-18 19:12:34', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(448, 6, 'INSERT', 'classes', 6, '2026-02-18 19:32:15', 'Added new class: Grade 5 - Diamond (SY: 2025-2026)'),
(449, 6, 'LOGOUT', 'users', 6, '2026-02-18 19:33:58', 'User logged out: BARON THE 3RD (admin)'),
(450, 29, 'LOGIN', 'users', 29, '2026-02-18 19:34:06', 'User logged in: mat (teacher) - School Year: 2025-2026'),
(451, 29, 'LOGOUT', 'users', 29, '2026-02-18 19:35:02', 'User logged out: mat (teacher)');
INSERT INTO `change_logs` (`id`, `user_id`, `action`, `table_name`, `record_id`, `timestamp`, `details`) VALUES
(452, 6, 'LOGIN', 'users', 6, '2026-02-18 19:35:09', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(453, 6, 'LOGOUT', 'users', 6, '2026-02-18 19:46:04', 'User logged out: BARON THE 3RD (admin)'),
(454, 25, 'LOGIN', 'users', 25, '2026-02-18 19:46:11', 'User logged in: john (teacher) - School Year: 2025-2026'),
(455, 6, 'LOGIN', 'users', 6, '2026-02-18 19:48:46', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(456, 25, 'LOGOUT', 'users', 25, '2026-02-18 19:57:39', 'User logged out: john (teacher)'),
(457, 6, 'LOGIN', 'users', 6, '2026-02-18 19:57:46', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(458, 6, 'LOGOUT', 'users', 6, '2026-02-18 20:01:06', 'User logged out: BARON THE 3RD (admin)'),
(459, 25, 'LOGIN', 'users', 25, '2026-02-18 20:01:11', 'User logged in: john (teacher) - School Year: 2025-2026'),
(460, 6, 'UPDATE', 'quarter_locks', NULL, '2026-02-18 20:37:50', 'Quarter 1 locked for school year 2025-2026'),
(461, 6, 'LOGOUT', 'users', 6, '2026-02-18 21:00:44', 'User logged out: BARON THE 3RD (admin)'),
(462, 30, 'LOGIN', 'users', 30, '2026-02-18 21:16:56', 'User logged in: mars (teacher) - School Year: 2025-2026'),
(463, 30, 'LOGOUT', 'users', 30, '2026-02-18 21:23:30', 'User logged out: mars (teacher)'),
(464, 25, 'LOGIN', 'users', 25, '2026-02-18 21:24:12', 'User logged in: john (teacher) - School Year: 2025-2026'),
(465, 25, 'LOGOUT', 'users', 25, '2026-02-18 21:25:17', 'User logged out: john (teacher)'),
(466, 6, 'LOGIN', 'users', 6, '2026-02-18 21:29:00', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(467, 6, 'INSERT', 'classes', 7, '2026-02-18 21:30:22', 'Added new class: Grade 1 - ads (SY: 2025-2026)'),
(468, 6, 'LOGOUT', 'users', 6, '2026-02-18 23:05:07', 'User logged out: BARON THE 3RD (admin)'),
(469, 6, 'LOGIN', 'users', 6, '2026-02-18 23:07:16', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(470, 6, 'LOGIN', 'users', 6, '2026-02-19 12:04:50', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(471, 6, 'LOGIN', 'users', 6, '2026-02-20 14:27:02', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(472, 6, 'LOGOUT', 'users', 6, '2026-02-20 15:35:32', 'User logged out: BARON THE 3RD (admin)'),
(473, 6, 'LOGIN', 'users', 6, '2026-02-20 16:12:05', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(474, 6, 'LOGOUT', 'users', 6, '2026-02-20 18:04:53', 'User logged out: BARON THE 3RD (admin)'),
(475, 25, 'LOGIN', 'users', 25, '2026-02-20 18:05:01', 'User logged in: john (teacher) - School Year: 2025-2026'),
(476, 25, 'LOGOUT', 'users', 25, '2026-02-20 18:13:39', 'User logged out: john (teacher)'),
(477, 6, 'LOGIN', 'users', 6, '2026-02-20 18:13:47', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(478, 6, 'LOGOUT', 'users', 6, '2026-02-20 18:51:56', 'User logged out: BARON THE 3RD (admin)'),
(479, 25, 'LOGIN', 'users', 25, '2026-02-20 18:52:24', 'User logged in: john (teacher) - School Year: 2025-2026'),
(480, 25, 'LOGOUT', 'users', 25, '2026-02-20 19:01:00', 'User logged out: john (teacher)'),
(481, 6, 'LOGIN', 'users', 6, '2026-02-20 19:01:12', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(482, 31, 'LOGIN', 'users', 31, '2026-02-20 20:06:05', 'User logged in: merry Christmas (teacher) - School Year: 2025-2026'),
(483, 31, 'LOGOUT', 'users', 31, '2026-02-20 20:11:16', 'User logged out: merry Christmas (teacher)'),
(484, 25, 'LOGIN', 'users', 25, '2026-02-20 20:11:25', 'User logged in: john (teacher) - School Year: 2025-2026'),
(485, 25, 'LOGOUT', 'users', 25, '2026-02-20 20:18:20', 'User logged out: john (teacher)'),
(486, 6, 'LOGIN', 'users', 6, '2026-02-20 20:18:28', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(487, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-20 20:19:33', 'Admin switched to school year: 2024-2025'),
(488, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-20 20:20:11', 'Admin switched to school year: 2025-2026'),
(489, 6, 'LOGIN', 'users', 6, '2026-02-20 20:26:45', 'User logged in: BARON THE 3RD (admin) - School Year: 2025-2026'),
(490, 6, 'QUARTER_LOCK', 'quarter_locks', 1, '2026-02-20 20:26:51', 'User admin unlocked Quarter 1 for SY 2025-2026'),
(491, 6, 'QUARTER_LOCK', 'quarter_locks', 1, '2026-02-20 20:27:01', 'User admin locked Quarter 1 for SY 2025-2026'),
(492, 6, 'QUARTER_LOCK', 'quarter_locks', 1, '2026-02-20 20:27:06', 'User admin unlocked Quarter 1 for SY 2025-2026'),
(493, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 2, '2026-02-20 20:27:10', 'Admin switched to school year: 2024-2025'),
(494, 6, 'QUARTER_LOCK', 'quarter_locks', 5, '2026-02-20 20:27:14', 'User admin locked Quarter 1 for SY 2024-2025'),
(495, 6, 'SWITCH_SCHOOL_YEAR', 'school_years', 1, '2026-02-20 20:27:18', 'Admin switched to school year: 2025-2026'),
(496, 6, 'QUARTER_LOCK', 'quarter_locks', 1, '2026-02-20 20:27:57', 'User admin locked Quarter 1 for SY 2025-2026'),
(497, 6, 'QUARTER_LOCK', 'quarter_locks', 1, '2026-02-20 20:28:05', 'User admin unlocked Quarter 1 for SY 2025-2026'),
(498, 6, 'LOGOUT', 'users', 6, '2026-02-20 20:28:15', 'User logged out: BARON THE 3RD (admin)'),
(499, 25, 'LOGIN', 'users', 25, '2026-02-20 20:28:20', 'User logged in: john (teacher) - School Year: 2025-2026');

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

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `grade_level`, `section`, `school_year`, `capacity`, `status`, `created_at`, `updated_at`) VALUES
(1, '1', 'brad', '2025-2026', 40, 'Active', '2026-02-14 14:24:54', '2026-02-14 14:24:54'),
(2, '2', 'lora', '2025-2026', 40, 'Active', '2026-02-14 15:03:00', '2026-02-14 15:03:00'),
(3, '6', 'Diamond', '2025-2026', 40, 'Active', '2026-02-14 18:30:10', '2026-02-14 18:30:10'),
(4, '3', 'bird', '2025-2026', 40, 'Active', '2026-02-14 19:25:40', '2026-02-14 19:25:40'),
(5, '4', 'hello', '2025-2026', 40, 'Active', '2026-02-18 11:09:10', '2026-02-18 11:09:10'),
(6, '5', 'Diamond', '2025-2026', 40, 'Active', '2026-02-18 11:32:15', '2026-02-18 11:32:15'),
(7, '1', 'ads', '2025-2026', 40, 'Active', '2026-02-18 13:30:22', '2026-02-18 13:30:22');

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

--
-- Dumping data for table `grades`
--

INSERT INTO `grades` (`id`, `school_year_id`, `student_id`, `school_attended_id`, `subject_id`, `quarter`, `grade`, `final_rating`, `remarks`, `is_general_average`, `teacher_id`, `school_year`, `created_at`, `updated_at`) VALUES
(301, NULL, 1, 1, 1, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(302, NULL, 1, 1, 1, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(303, NULL, 1, 1, 1, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(304, NULL, 1, 1, 1, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(305, NULL, 1, 1, 2, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(306, NULL, 1, 1, 2, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(307, NULL, 1, 1, 2, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(308, NULL, 1, 1, 2, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(309, NULL, 1, 1, 3, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(310, NULL, 1, 1, 3, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(311, NULL, 1, 1, 3, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(312, NULL, 1, 1, 3, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(313, NULL, 1, 1, 4, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(314, NULL, 1, 1, 4, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(315, NULL, 1, 1, 4, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(316, NULL, 1, 1, 4, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(317, NULL, 1, 1, 5, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(318, NULL, 1, 1, 5, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(319, NULL, 1, 1, 5, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(320, NULL, 1, 1, 5, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(321, NULL, 1, 1, 6, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(322, NULL, 1, 1, 6, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(323, NULL, 1, 1, 6, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(324, NULL, 1, 1, 6, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(325, NULL, 1, 1, 7, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(326, NULL, 1, 1, 7, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(327, NULL, 1, 1, 7, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(328, NULL, 1, 1, 7, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(329, NULL, 1, 1, 8, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(330, NULL, 1, 1, 8, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(331, NULL, 1, 1, 8, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(332, NULL, 1, 1, 8, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(333, NULL, 1, 1, 9, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(334, NULL, 1, 1, 9, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(335, NULL, 1, 1, 9, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(336, NULL, 1, 1, 9, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(337, NULL, 1, 1, 10, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(338, NULL, 1, 1, 10, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(339, NULL, 1, 1, 10, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(340, NULL, 1, 1, 10, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(341, NULL, 1, 1, 11, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(342, NULL, 1, 1, 11, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(343, NULL, 1, 1, 11, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(344, NULL, 1, 1, 11, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(345, NULL, 1, 1, 12, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(346, NULL, 1, 1, 12, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(347, NULL, 1, 1, 12, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(348, NULL, 1, 1, 12, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(349, NULL, 1, 1, 13, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(350, NULL, 1, 1, 13, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(351, NULL, 1, 1, 13, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(352, NULL, 1, 1, 13, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(353, NULL, 1, 1, 14, '1', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(354, NULL, 1, 1, 14, '2', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(355, NULL, 1, 1, 14, '3', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(356, NULL, 1, 1, 14, '4', NULL, NULL, NULL, 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(357, NULL, 1, 1, 15, '1', NULL, 90, 'Passed', 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(358, NULL, 1, 1, 15, '2', NULL, 90, 'Passed', 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(359, NULL, 1, 1, 15, '3', NULL, 90, 'Passed', 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(360, NULL, 1, 1, 15, '4', 90, 90, 'Passed', 0, 25, '2026-2027', '2026-02-14 14:31:52', '2026-02-17 15:53:23'),
(361, 2, 3, 9, 2, '1', 90, NULL, NULL, 0, 25, '2024-2025', '2026-02-15 18:09:49', '2026-02-18 08:21:38'),
(362, 2, 3, 9, 12, '1', 90, NULL, NULL, 0, 25, '2024-2025', '2026-02-15 18:10:23', '2026-02-18 08:21:38'),
(364, 2, 8, 13, 12, '1', NULL, NULL, NULL, 0, 25, '2024-2025', '2026-02-15 19:08:38', '2026-02-18 08:21:38'),
(2170, 2, 8, 13, 2, '1', 90, 90, 'Passed', 0, 25, '2024-2025', '2026-02-15 20:20:48', '2026-02-18 08:21:38'),
(2171, 2, 6, 12, 2, '1', 90, 90, 'Passed', 0, 25, '2024-2025', '2026-02-15 20:21:16', '2026-02-18 08:21:38'),
(2172, 2, 12, 11, 2, '1', 90, 90, 'Passed', 0, 25, '2024-2025', '2026-02-15 20:21:17', '2026-02-18 08:21:38'),
(4097, 2, 10, 10, 1, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:02', '2026-02-17 15:53:23'),
(4098, 2, 10, 10, 1, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:02', '2026-02-17 15:53:23'),
(4099, 2, 10, 10, 1, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:02', '2026-02-17 15:53:23'),
(4100, 2, 10, 10, 1, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:02', '2026-02-17 15:53:23'),
(4101, 2, 10, 10, 2, '1', 90, 90, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:02', '2026-02-17 15:53:23'),
(4102, 2, 10, 10, 2, '2', NULL, 90, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:02', '2026-02-17 15:53:23'),
(4103, 2, 10, 10, 2, '3', NULL, 90, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:02', '2026-02-17 15:53:23'),
(4104, 2, 10, 10, 2, '4', NULL, 90, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:02', '2026-02-17 15:53:23'),
(4105, 2, 10, 10, 3, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:02', '2026-02-17 15:53:23'),
(4106, 2, 10, 10, 3, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:02', '2026-02-17 15:53:23'),
(4107, 2, 10, 10, 3, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4108, 2, 10, 10, 3, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4109, 2, 10, 10, 4, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4110, 2, 10, 10, 4, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4111, 2, 10, 10, 4, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4112, 2, 10, 10, 4, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4113, 2, 10, 10, 5, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4114, 2, 10, 10, 5, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4115, 2, 10, 10, 5, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4116, 2, 10, 10, 5, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4117, 2, 10, 10, 6, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4118, 2, 10, 10, 6, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4119, 2, 10, 10, 6, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4120, 2, 10, 10, 6, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4121, 2, 10, 10, 7, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4122, 2, 10, 10, 7, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4123, 2, 10, 10, 7, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4124, 2, 10, 10, 7, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4125, 2, 10, 10, 8, '1', 91, 91, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4126, 2, 10, 10, 8, '2', NULL, 91, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4127, 2, 10, 10, 8, '3', NULL, 91, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4128, 2, 10, 10, 8, '4', NULL, 91, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4129, 2, 10, 10, 9, '1', 91, 91, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4130, 2, 10, 10, 9, '2', NULL, 91, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4131, 2, 10, 10, 9, '3', NULL, 91, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4132, 2, 10, 10, 9, '4', NULL, 91, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4133, 2, 10, 10, 10, '1', 90, 90, 'Passed', 0, 25, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 19:57:58'),
(4134, 2, 10, 10, 10, '2', NULL, 90, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 19:57:58'),
(4135, 2, 10, 10, 10, '3', NULL, 90, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 19:57:58'),
(4136, 2, 10, 10, 10, '4', NULL, 90, 'Passed', 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 19:57:58'),
(4137, 2, 10, 10, 11, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4138, 2, 10, 10, 11, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4139, 2, 10, 10, 11, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4140, 2, 10, 10, 11, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4141, 2, 10, 10, 12, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4142, 2, 10, 10, 12, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4143, 2, 10, 10, 12, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4144, 2, 10, 10, 12, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4145, 2, 10, 10, 13, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4146, 2, 10, 10, 13, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4147, 2, 10, 10, 13, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4148, 2, 10, 10, 13, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4149, 2, 10, 10, 14, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4150, 2, 10, 10, 14, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4151, 2, 10, 10, 14, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4152, 2, 10, 10, 14, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4153, 2, 10, 10, 15, '1', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4154, 2, 10, 10, 15, '2', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4155, 2, 10, 10, 15, '3', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4156, 2, 10, 10, 15, '4', NULL, NULL, NULL, 0, 6, '2024-2025', '2026-02-17 15:49:03', '2026-02-17 15:53:23'),
(4157, 2, 12, 11, 10, '1', NULL, 90, 'Passed', 0, 25, '2024-2025', '2026-02-17 15:55:15', '2026-02-18 08:21:38'),
(4518, 2, 8, 13, 10, '1', NULL, 90, 'Passed', 0, 25, '2024-2025', '2026-02-17 16:09:43', '2026-02-18 08:21:38'),
(4759, 2, 6, 12, 10, '1', NULL, 90, 'Passed', 0, 25, '2024-2025', '2026-02-17 16:22:31', '2026-02-18 08:21:38'),
(6291, 2, 3, 15, 8, '1', 95, 93, 'PASSED', 0, 6, '2024-2025', '2026-02-17 19:47:21', '2026-02-17 19:47:21'),
(6292, 2, 3, 15, 8, '2', 91, 93, 'PASSED', 0, 6, '2024-2025', '2026-02-17 19:47:21', '2026-02-17 19:47:21'),
(6293, 2, 3, 15, 9, '1', 91, 91, 'PASSED', 0, 6, '2024-2025', '2026-02-17 19:47:21', '2026-02-17 19:47:21'),
(6294, 2, 3, 15, 9, '2', 91, 91, 'PASSED', 0, 6, '2024-2025', '2026-02-17 19:47:21', '2026-02-17 19:47:21'),
(6295, 2, 3, 15, 10, '1', 98, 98, 'Passed', 0, 25, '2024-2025', '2026-02-17 19:47:21', '2026-02-17 19:48:21'),
(6296, 2, 3, 15, 11, '1', 96, 96, 'Passed', 0, 6, '2024-2025', '2026-02-17 19:47:21', '2026-02-17 19:47:21'),
(6297, 2, 3, 15, 12, '1', 95, 95, 'PASSED', 0, 6, '2024-2025', '2026-02-17 19:47:21', '2026-02-17 19:47:21'),
(6319, NULL, 1, 3, 8, '1', 90, NULL, NULL, 0, 6, '2026-2027', '2026-02-17 22:39:20', '2026-02-17 22:39:20'),
(6320, NULL, 1, 3, 8, '2', 90, NULL, NULL, 0, 6, '2026-2027', '2026-02-17 22:39:20', '2026-02-17 22:39:20'),
(6321, NULL, 1, 3, 8, '3', 90, NULL, NULL, 0, 6, '2026-2027', '2026-02-17 22:39:20', '2026-02-17 22:39:20'),
(6322, NULL, 1, 3, 8, '4', 90, NULL, NULL, 0, 6, '2026-2027', '2026-02-17 22:39:20', '2026-02-17 22:39:20'),
(6323, NULL, 1, 3, 9, '1', 90, 90, 'PASSED', 0, 6, '2026-2027', '2026-02-17 22:39:20', '2026-02-17 22:39:20'),
(6324, NULL, 1, 3, 9, '2', 90, 90, 'PASSED', 0, 6, '2026-2027', '2026-02-17 22:39:20', '2026-02-17 22:39:20'),
(6325, NULL, 1, 3, 9, '3', 90, 90, 'PASSED', 0, 6, '2026-2027', '2026-02-17 22:39:20', '2026-02-17 22:39:20'),
(6326, NULL, 1, 3, 9, '4', 90, 90, 'PASSED', 0, 6, '2026-2027', '2026-02-17 22:39:20', '2026-02-17 22:39:20'),
(6327, NULL, 1, 3, 10, '1', 90, NULL, NULL, 0, 6, '2026-2027', '2026-02-17 22:39:20', '2026-02-17 22:39:20'),
(6328, NULL, 1, 3, 10, '2', 90, NULL, NULL, 0, 6, '2026-2027', '2026-02-17 22:39:20', '2026-02-17 22:39:20'),
(6348, 1, 1, 2, 1, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 03:43:48', '2026-02-18 03:43:48'),
(6349, 1, 1, 2, 1, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 03:43:48', '2026-02-18 03:43:48'),
(6350, 1, 1, 2, 2, '1', 93, NULL, NULL, 0, 25, '2025-2026', '2026-02-18 03:43:48', '2026-02-18 03:43:55'),
(6351, 1, 1, 2, 2, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 03:43:48', '2026-02-18 03:43:48'),
(6352, 1, 1, 2, 8, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 03:43:48', '2026-02-18 03:43:48'),
(6353, 1, 1, 2, 9, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 03:43:48', '2026-02-18 03:43:48'),
(6356, 1, 1, 2, 5, '1', 90, NULL, NULL, 0, 25, '2025-2026', '2026-02-18 05:29:37', '2026-02-18 08:21:37'),
(6361, NULL, 11, 18, 1, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6362, NULL, 11, 18, 1, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6363, NULL, 11, 18, 2, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6364, NULL, 11, 18, 2, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6365, NULL, 11, 18, 3, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6366, NULL, 11, 18, 3, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6367, NULL, 11, 18, 4, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6368, NULL, 11, 18, 4, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6369, NULL, 11, 18, 5, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6370, NULL, 11, 18, 5, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6371, NULL, 11, 18, 6, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6372, NULL, 11, 18, 6, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6373, NULL, 11, 18, 7, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6374, NULL, 11, 18, 7, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6375, NULL, 11, 18, 8, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6376, NULL, 11, 18, 8, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6377, NULL, 11, 18, 9, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6378, NULL, 11, 18, 9, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6379, NULL, 11, 18, 10, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6380, NULL, 11, 18, 10, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6381, NULL, 11, 18, 11, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6382, NULL, 11, 18, 11, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6383, NULL, 11, 18, 12, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6384, NULL, 11, 18, 12, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6385, NULL, 11, 18, 13, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6386, NULL, 11, 18, 13, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6387, NULL, 11, 18, 14, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6388, NULL, 11, 18, 14, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6389, NULL, 11, 18, 15, '1', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6390, NULL, 11, 18, 15, '2', 90, NULL, NULL, 0, 6, '2021-2025', '2026-02-18 07:04:29', '2026-02-18 07:04:29'),
(6395, 1, 7, 26, 2, '1', 100, NULL, NULL, 0, 25, '2025-2026', '2026-02-18 07:17:15', '2026-02-18 08:21:37'),
(6396, 1, 9, 24, 2, '1', 90, NULL, NULL, 0, 25, '2025-2026', '2026-02-18 07:17:15', '2026-02-18 08:21:37'),
(6397, 1, 13, 27, 2, '1', 100, NULL, NULL, 0, 25, '2025-2026', '2026-02-18 07:17:15', '2026-02-18 08:21:37'),
(6398, 1, 4, 28, 2, '1', 100, NULL, NULL, 0, 25, '2025-2026', '2026-02-18 07:17:16', '2026-02-18 08:21:37'),
(6399, 1, 1, 5, 1, '1', 90, NULL, NULL, 0, 25, '2025-2026', '2026-02-18 07:29:55', '2026-02-18 07:29:55'),
(6440, 1, 11, 30, 4, '1', 92, NULL, NULL, 0, 24, '2025-2026', '2026-02-18 08:58:48', '2026-02-18 09:06:21'),
(6463, 1, 5, 17, 1, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:57', '2026-02-18 09:05:57'),
(6464, 1, 5, 17, 1, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:57', '2026-02-18 09:05:57'),
(6465, 1, 5, 17, 1, '3', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:57', '2026-02-18 09:05:57'),
(6466, 1, 5, 17, 2, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:57', '2026-02-18 09:05:57'),
(6467, 1, 5, 17, 2, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:57', '2026-02-18 09:05:57'),
(6468, 1, 5, 17, 2, '3', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6469, 1, 5, 17, 3, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6470, 1, 5, 17, 3, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6471, 1, 5, 17, 3, '3', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6472, 1, 5, 17, 4, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6473, 1, 5, 17, 4, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6474, 1, 5, 17, 4, '3', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6475, 1, 5, 17, 5, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6476, 1, 5, 17, 5, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6477, 1, 5, 17, 5, '3', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6478, 1, 5, 17, 6, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6479, 1, 5, 17, 6, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6480, 1, 5, 17, 6, '3', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6481, 1, 5, 17, 7, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6482, 1, 5, 17, 7, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6483, 1, 5, 17, 7, '3', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:05:58', '2026-02-18 09:05:58'),
(6486, 1, 5, 29, 4, '1', 97, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 09:12:05', '2026-02-18 09:12:05'),
(6487, 1, 5, 32, 6, '1', 90, NULL, NULL, 0, 27, '2025', '2026-02-18 11:06:04', '2026-02-18 11:06:04'),
(6488, 1, 5, 34, 7, '1', 90, NULL, NULL, 0, 28, '2025', '2026-02-18 11:11:28', '2026-02-18 11:11:28'),
(6489, 1, 11, 19, 2, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 11:50:39', '2026-02-18 11:50:39'),
(6490, 1, 11, 19, 5, '1', 91, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 11:50:39', '2026-02-18 11:50:39'),
(6506, 1, 2, 8, 1, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6507, 1, 2, 8, 2, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6508, 1, 2, 8, 5, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6509, 1, 2, 8, 8, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6510, 1, 2, 8, 8, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6511, 1, 2, 8, 8, '3', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6512, 1, 2, 8, 9, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6513, 1, 2, 8, 9, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6514, 1, 2, 8, 9, '3', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6515, 1, 2, 8, 10, '1', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6516, 1, 2, 8, 10, '2', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6517, 1, 2, 8, 10, '3', 90, NULL, NULL, 0, 6, '2025-2026', '2026-02-18 12:26:39', '2026-02-18 12:26:39'),
(6518, 1, 5, 17, 10, '1', 92, NULL, NULL, 0, 25, '2025', '2026-02-20 12:28:52', '2026-02-20 12:34:04'),
(6520, 2, 5, 14, 1, '1', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6521, 2, 5, 14, 1, '2', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6522, 2, 5, 14, 2, '1', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6523, 2, 5, 14, 2, '2', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6524, 2, 5, 14, 3, '1', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6525, 2, 5, 14, 3, '2', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6526, 2, 5, 14, 4, '1', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6527, 2, 5, 14, 4, '2', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6528, 2, 5, 14, 5, '1', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6529, 2, 5, 14, 5, '2', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6530, 2, 5, 14, 6, '1', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6531, 2, 5, 14, 6, '2', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6532, 2, 5, 14, 7, '1', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6533, 2, 5, 14, 7, '2', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6534, 2, 5, 14, 8, '1', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17'),
(6535, 2, 5, 14, 10, '1', 90, NULL, NULL, 0, 6, '2024-2025', '2026-02-20 12:33:17', '2026-02-20 12:33:17');

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

--
-- Dumping data for table `grades_history`
--

INSERT INTO `grades_history` (`id`, `grade_id`, `student_id`, `school_attended_id`, `subject_id`, `quarter`, `old_grade`, `new_grade`, `changed_by`, `changed_at`, `change_reason`) VALUES
(1, 5156, 3, 15, 10, 1, 94.00, 95.00, 25, '2026-02-17 16:42:19', NULL),
(2, 5148, 3, 15, 8, 1, 93.00, 93.00, 6, '2026-02-17 16:42:28', NULL),
(3, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:42:28', NULL),
(4, 5156, 3, 15, 10, 1, 95.00, 94.00, 6, '2026-02-17 16:42:28', NULL),
(5, 5148, 3, 15, 8, 1, 93.00, 50.00, 6, '2026-02-17 16:42:33', NULL),
(6, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:42:33', NULL),
(7, 5156, 3, 15, 10, 1, 94.00, 9.00, 6, '2026-02-17 16:42:33', NULL),
(8, 5148, 3, 15, 8, 1, 50.00, 92.00, 6, '2026-02-17 16:42:33', NULL),
(9, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:42:33', NULL),
(10, 5156, 3, 15, 10, 1, 9.00, 93.00, 6, '2026-02-17 16:42:33', NULL),
(11, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:42:37', NULL),
(12, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:42:37', NULL),
(13, 5156, 3, 15, 10, 1, 93.00, 93.00, 6, '2026-02-17 16:42:37', NULL),
(14, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:48:43', NULL),
(15, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:48:43', NULL),
(16, 5156, 3, 15, 10, 1, 93.00, 93.00, 6, '2026-02-17 16:48:43', NULL),
(17, 5156, 3, 15, 10, 1, 93.00, 94.00, 25, '2026-02-17 16:48:56', NULL),
(18, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:49:00', NULL),
(19, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:49:00', NULL),
(20, 5156, 3, 15, 10, 1, 94.00, 93.00, 6, '2026-02-17 16:49:00', NULL),
(21, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:49:10', NULL),
(22, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:49:10', NULL),
(23, 5156, 3, 15, 10, 1, 93.00, 93.00, 6, '2026-02-17 16:49:10', NULL),
(24, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:49:21', NULL),
(25, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:49:21', NULL),
(26, 5156, 3, 15, 10, 1, 93.00, 93.00, 6, '2026-02-17 16:49:21', NULL),
(27, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:49:25', NULL),
(28, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:49:25', NULL),
(29, 5156, 3, 15, 10, 1, 93.00, 93.00, 6, '2026-02-17 16:49:25', NULL),
(30, 5156, 3, 15, 10, 1, 93.00, 94.00, 25, '2026-02-17 16:49:34', NULL),
(31, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:49:41', NULL),
(32, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:49:41', NULL),
(33, 5156, 3, 15, 10, 1, 94.00, 93.00, 6, '2026-02-17 16:49:41', NULL),
(34, 5156, 3, 15, 10, 1, 93.00, 94.00, 25, '2026-02-17 16:53:03', NULL),
(35, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:53:11', NULL),
(36, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:53:11', NULL),
(37, 5156, 3, 15, 10, 1, 94.00, 93.00, 6, '2026-02-17 16:53:11', NULL),
(38, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:53:22', NULL),
(39, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:53:22', NULL),
(40, 5156, 3, 15, 10, 1, 93.00, 93.00, 6, '2026-02-17 16:53:22', NULL),
(41, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:53:33', NULL),
(42, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:53:33', NULL),
(43, 5156, 3, 15, 10, 1, 93.00, 93.00, 6, '2026-02-17 16:53:33', NULL),
(44, 5156, 3, 15, 10, 1, 93.00, 95.00, 25, '2026-02-17 16:57:17', NULL),
(45, 5148, 3, 15, 8, 1, 92.00, 92.00, 6, '2026-02-17 16:57:26', NULL),
(46, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 16:57:26', NULL),
(47, 5156, 3, 15, 10, 1, 95.00, 93.00, 6, '2026-02-17 16:57:26', NULL),
(48, 5156, 3, 15, 10, 1, 93.00, 95.00, 25, '2026-02-17 16:57:41', NULL),
(49, 5156, 3, 15, 10, 1, 95.00, 96.00, 25, '2026-02-17 16:58:03', NULL),
(50, 5148, 3, 15, 8, 1, 92.00, 94.00, 6, '2026-02-17 17:02:30', NULL),
(51, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 17:02:30', NULL),
(52, 5156, 3, 15, 10, 1, 96.00, 96.00, 6, '2026-02-17 17:02:30', NULL),
(53, 5160, 3, 15, 11, 1, NULL, 96.00, 6, '2026-02-17 17:02:30', NULL),
(54, 5148, 3, 15, 8, 1, 94.00, 95.00, 6, '2026-02-17 17:04:31', NULL),
(55, 5152, 3, 15, 9, 1, 91.00, 91.00, 6, '2026-02-17 17:04:31', NULL),
(56, 5156, 3, 15, 10, 1, 96.00, 96.00, 6, '2026-02-17 17:04:32', NULL),
(57, 5160, 3, 15, 11, 1, 96.00, 96.00, 6, '2026-02-17 17:04:32', NULL),
(58, 5164, 3, 15, 12, 1, NULL, 96.00, 6, '2026-02-17 17:04:32', NULL),
(59, 6295, 3, 15, 10, 1, 97.00, 98.00, 25, '2026-02-17 19:48:21', NULL),
(60, 4133, 10, 10, 10, 1, NULL, 90.00, 25, '2026-02-17 19:57:58', NULL),
(61, 6343, 1, 2, 2, 1, 90.00, 91.00, 25, '2026-02-18 03:43:20', NULL),
(62, 6350, 1, 2, 2, 1, 92.00, 93.00, 25, '2026-02-18 03:43:55', NULL),
(63, 6355, 2, 8, 2, 1, NULL, 90.00, 25, '2026-02-18 05:18:53', NULL),
(64, 6356, 1, 2, 5, 1, NULL, 90.00, 25, '2026-02-18 05:29:37', NULL),
(65, 6357, 2, 8, 5, 1, NULL, 90.00, 25, '2026-02-18 05:46:19', NULL),
(66, 6391, 11, 19, 5, 1, NULL, 91.00, 25, '2026-02-18 07:11:43', NULL),
(67, 6391, 11, 19, 5, 1, 91.00, 92.00, 25, '2026-02-18 07:12:24', NULL),
(68, 6391, 11, 19, 5, 1, 92.00, 91.00, 25, '2026-02-18 07:13:27', NULL),
(69, 6394, 5, 17, 2, 1, NULL, 90.00, 25, '2026-02-18 07:17:15', NULL),
(70, 6395, 7, 26, 2, 1, NULL, 100.00, 25, '2026-02-18 07:17:15', NULL),
(71, 6396, 9, 24, 2, 1, NULL, 90.00, 25, '2026-02-18 07:17:15', NULL),
(72, 6397, 13, 27, 2, 1, NULL, 100.00, 25, '2026-02-18 07:17:16', NULL),
(73, 6398, 4, 28, 2, 1, NULL, 100.00, 25, '2026-02-18 07:17:16', NULL),
(74, 6398, 4, 28, 2, 1, 100.00, 100.00, 25, '2026-02-18 08:18:48', NULL),
(75, 6397, 13, 27, 2, 1, 100.00, 100.00, 25, '2026-02-18 08:18:48', NULL),
(76, 6395, 7, 26, 2, 1, 100.00, 100.00, 25, '2026-02-18 08:18:48', NULL),
(77, 6438, 11, 30, 4, 1, NULL, 90.00, 24, '2026-02-18 08:54:19', NULL),
(78, 6439, 5, 29, 4, 1, NULL, 90.00, 24, '2026-02-18 08:54:19', NULL),
(79, 6440, 11, 30, 4, 1, 90.00, NULL, 24, '2026-02-18 09:05:39', NULL),
(80, 6439, 5, 29, 4, 1, 90.00, NULL, 24, '2026-02-18 09:05:39', NULL),
(81, 6439, 5, 29, 4, 1, NULL, 97.00, 24, '2026-02-18 09:06:21', NULL),
(82, 6440, 11, 30, 4, 1, NULL, 92.00, 24, '2026-02-18 09:06:21', NULL),
(83, 6487, 5, 32, 6, 1, NULL, 90.00, 27, '2026-02-18 11:06:04', NULL),
(84, 6488, 5, 34, 7, 1, NULL, 90.00, 28, '2026-02-18 11:11:28', NULL),
(85, 6518, 5, 17, 10, 1, NULL, 90.00, 25, '2026-02-20 12:28:52', NULL),
(86, 6518, 5, 17, 10, 1, 90.00, 91.00, 25, '2026-02-20 12:29:47', NULL),
(87, 6518, 5, 17, 10, 1, 91.00, 92.00, 25, '2026-02-20 12:34:04', NULL);

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

--
-- Dumping data for table `quarter_locks`
--

INSERT INTO `quarter_locks` (`id`, `school_year_id`, `school_attended_id`, `quarter`, `locked`, `school_year`, `created_at`, `updated_at`) VALUES
(1, NULL, NULL, 1, 0, '2025-2026', '2026-02-14 14:33:56', '2026-02-20 12:28:05'),
(2, NULL, NULL, 2, 1, '2025-2026', '2026-02-14 14:33:59', '2026-02-15 04:42:56'),
(3, NULL, NULL, 3, 1, '2025-2026', '2026-02-14 14:34:02', '2026-02-15 04:42:56'),
(4, NULL, NULL, 4, 1, '2025-2026', '2026-02-14 14:34:06', '2026-02-15 04:42:56'),
(5, NULL, NULL, 1, 1, '2024-2025', '2026-02-15 07:31:20', '2026-02-20 12:27:14'),
(6, NULL, NULL, 2, 1, '2024-2025', '2026-02-15 07:31:24', '2026-02-15 18:05:17'),
(7, NULL, NULL, 3, 1, '2024-2025', '2026-02-15 17:38:06', '2026-02-15 18:05:14'),
(8, NULL, NULL, 4, 1, '2024-2025', '2026-02-15 17:38:09', '2026-02-15 17:38:09');

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

--
-- Dumping data for table `remedial_classes`
--

INSERT INTO `remedial_classes` (`id`, `school_year_id`, `student_id`, `school_year`, `grade_level`, `learning_area`, `final_rating`, `remedial_class_mark`, `recomputed_final_grade`, `remarks`, `conducted_from`, `conducted_to`) VALUES
(10, NULL, 1, '2025-2026', '1', 'bisaya', NULL, 100, NULL, NULL, '2026-02-18', '2026-02-18'),
(11, NULL, 1, '2025-2026', '3', 'hey', NULL, 50, NULL, NULL, '2026-03-18', '2026-03-18'),
(12, NULL, 5, '2025-2026', '2', 'MAPEH', NULL, 90, NULL, NULL, '2026-02-18', '2026-02-18');

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

--
-- Dumping data for table `schools_attended`
--

INSERT INTO `schools_attended` (`id`, `student_id`, `school_name`, `school_id`, `district`, `division`, `region`, `grade_level`, `section`, `school_year`, `adviser_name`, `created_at`, `is_transfer`, `active`, `transfer_quarter`) VALUES
(2, 1, 'NEW MABUHAY ELEMENTARY SCHOOL', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-14 14:42:35', 0, 1, NULL),
(3, 1, 'NEW MABUHAY ELEMENTARY SCHOOL', '', '', '', '', 2, 'lora', '2026-2027', 'john', '2026-02-14 16:24:29', 0, 1, NULL),
(5, 1, 'asdasda', '', '', '', '', 3, 'asdasd', '2025-2026', '', '2026-02-14 16:46:25', 1, 1, NULL),
(8, 2, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-15 07:12:35', 0, 1, NULL),
(10, 10, '', '', '', '', '', 1, 'brad', '2024-2025', 'john', '2026-02-15 19:07:34', 0, 1, NULL),
(11, 12, '', '', '', '', '', 1, 'brad', '2024-2025', 'john', '2026-02-15 19:07:34', 0, 1, NULL),
(12, 6, '', '', '', '', '', 1, 'brad', '2024-2025', 'john', '2026-02-15 19:07:34', 0, 1, NULL),
(13, 8, '', '', '', '', '', 1, 'brad', '2024-2025', 'john', '2026-02-15 19:07:34', 0, 1, NULL),
(14, 5, '', '', '', '', '', 1, 'brad', '2024-2025', 'john', '2026-02-15 19:07:34', 0, 1, NULL),
(15, 3, '', '', '', '', '', 1, 'brad', '2024-2025', 'john', '2026-02-15 19:23:40', 0, 1, NULL),
(17, 5, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 05:57:22', NULL, 1, NULL),
(18, 11, 'GSCPO', '123456', 'ASDAS', 'DASD', 'ASD', 1, 'A', '2021-2025', '', '2026-02-18 06:53:49', 1, 1, NULL),
(19, 11, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 07:08:24', 1, 1, 2),
(20, 8, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 07:15:01', 0, 1, NULL),
(21, 6, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 07:15:01', 0, 1, NULL),
(22, 12, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 07:15:01', 0, 1, NULL),
(23, 10, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 07:15:01', 0, 1, NULL),
(24, 9, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 07:15:01', 0, 1, NULL),
(25, 3, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 07:15:01', 0, 1, NULL),
(26, 7, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 07:15:01', 0, 1, NULL),
(27, 13, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 07:15:01', 0, 1, NULL),
(28, 4, '', '', '', '', '', 1, 'brad', '2025-2026', 'john', '2026-02-18 07:15:01', 0, 1, NULL),
(29, 5, '', '', '', '', '', 2, 'lora', '2025-2026', 'kaia', '2026-02-18 08:51:34', 0, 1, NULL),
(30, 11, '', '', '', '', '', 2, 'lora', '2025-2026', 'kaia', '2026-02-18 08:51:34', 0, 1, NULL),
(31, 1, '', '', '', '', '', 3, 'bird', '2025-2026', 'justine', '2026-02-18 11:04:21', 0, 0, NULL),
(32, 5, '', '', '', '', '', 3, 'bird', '2025-2026', 'justine', '2026-02-18 11:04:21', 0, 1, NULL),
(33, 11, '', '', '', '', '', 3, 'bird', '2025-2026', 'justine', '2026-02-18 11:04:21', 0, 1, NULL),
(34, 5, '', '', '', '', '', 4, 'hello', '2025-2026', 'mary', '2026-02-18 11:10:10', 0, 1, NULL),
(35, 11, '', '', '', '', '', 4, 'hello', '2025-2026', 'mary', '2026-02-18 11:10:10', 0, 1, NULL);

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

--
-- Dumping data for table `school_years`
--

INSERT INTO `school_years` (`id`, `year`, `start_date`, `end_date`, `is_active`, `status`, `created_by`, `created_at`, `updated_at`) VALUES
(1, '2025-2026', '2025-06-14', '2026-02-14', 1, 'active', NULL, '2026-02-14 14:08:31', '2026-02-17 19:57:26'),
(2, '2024-2025', '2024-06-14', '2025-05-14', 0, 'active', NULL, '2026-02-14 14:44:16', '2026-02-17 19:57:26');

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

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `lrn`, `last_name`, `first_name`, `middle_name`, `suffix`, `gender`, `birthdate`, `credential_presented`, `credential_other_details`, `pept_rating`, `pept_exam_date`, `pept_testing_center`, `created_at`, `eligibility_school_name`, `eligibility_school_id`, `eligibility_school_address`, `eligibility_remark`, `grade_level`, `section`) VALUES
(1, '23123123', 'ASDASDASD', 'ASDASDASD', 'SDASDASD', '', 'Male', '2026-02-14', 'Kinder Progress Report', '', '', '0000-00-00', '', '2026-02-14 14:09:03', '', '', '', '', NULL, NULL),
(2, '123124124121231', 'BELLA', 'MARS', 'TUMACA', '', 'Male', '2026-02-11', 'Kinder Progress Report', '', '', '0000-00-00', '', '2026-02-14 15:59:32', '', '', 'Purok Nopol, St.therese , Brgy Conel', '', NULL, NULL),
(3, '1231231441', 'QWE', 'QWE', 'QWE', '', 'Female', '2026-02-16', 'Kinder Progress Report', '', '', '0000-00-00', '', '2026-02-15 17:32:01', '', '', '', '', NULL, NULL),
(4, '202401001', 'Santos', 'Maria', 'Cruz', NULL, 'Female', '2015-03-15', NULL, NULL, NULL, NULL, NULL, '2026-02-15 18:19:22', NULL, NULL, NULL, NULL, NULL, NULL),
(5, '202401002', 'Dela Cruz', 'Juan', 'Reyes', NULL, 'Male', '2014-08-22', NULL, NULL, NULL, NULL, NULL, '2026-02-15 18:19:22', NULL, NULL, NULL, NULL, NULL, NULL),
(6, '202401003', 'Garcia', 'Sofia', 'Lopez', NULL, 'Female', '2015-01-10', NULL, NULL, NULL, NULL, NULL, '2026-02-15 18:19:22', NULL, NULL, NULL, NULL, NULL, NULL),
(7, '202401004', 'Ramos', 'Miguel', 'Torres', NULL, 'Male', '2014-11-05', NULL, NULL, NULL, NULL, NULL, '2026-02-15 18:19:22', NULL, NULL, NULL, NULL, NULL, NULL),
(8, '202401005', 'Fernandez', 'Isabella', 'Gomez', NULL, 'Female', '2015-06-18', NULL, NULL, NULL, NULL, NULL, '2026-02-15 18:19:22', NULL, NULL, NULL, NULL, NULL, NULL),
(9, '202401006', 'Martinez', 'Carlos', 'Silva', NULL, 'Male', '2014-09-30', NULL, NULL, NULL, NULL, NULL, '2026-02-15 18:19:22', NULL, NULL, NULL, NULL, NULL, NULL),
(10, '202401007', 'Hernandez', 'Gabriela', 'Morales', NULL, 'Female', '2015-04-12', NULL, NULL, NULL, NULL, NULL, '2026-02-15 18:19:22', NULL, NULL, NULL, NULL, NULL, NULL),
(11, '202401008', 'Lopez', 'Diego', 'Castillo', NULL, 'Male', '2014-07-25', NULL, NULL, NULL, NULL, NULL, '2026-02-15 18:19:22', NULL, NULL, NULL, NULL, NULL, NULL),
(12, '202401009', 'Gonzalez', 'Valentina', 'Rivera', NULL, 'Female', '2015-02-08', NULL, NULL, NULL, NULL, NULL, '2026-02-15 18:19:22', NULL, NULL, NULL, NULL, NULL, NULL),
(13, '202401010', 'Rodriguez', 'Lucas', 'Mendoza', NULL, 'Male', '2014-12-14', NULL, NULL, NULL, NULL, NULL, '2026-02-15 18:19:22', NULL, NULL, NULL, NULL, NULL, NULL);

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

--
-- Dumping data for table `student_custom_subjects`
--

INSERT INTO `student_custom_subjects` (`id`, `student_id`, `school_attended_id`, `subject_id`, `custom_subject_name`, `created_at`, `updated_at`) VALUES
(1, 1, 5, 1, 'hey', '2026-02-17 18:51:54', '2026-02-17 18:55:25'),
(6, 1, 5, 11, '', '2026-02-17 19:03:29', '2026-02-17 19:03:29'),
(7, 1, 5, 12, '', '2026-02-17 19:03:29', '2026-02-17 19:03:29');

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

--
-- Dumping data for table `subject_grade_groups`
--

INSERT INTO `subject_grade_groups` (`id`, `grade_level`, `subject_id`, `subject_name`, `display_order`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'bisaya', 0, '2026-02-14 14:17:42', '2026-02-17 19:04:57'),
(2, 1, 2, 'Filipino', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(3, 1, 3, '', 0, '2026-02-14 14:17:42', '2026-02-18 13:33:17'),
(4, 1, 4, 'Mathematics', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(5, 1, 5, 'Science', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(6, 1, 6, 'Araling Panlipunan', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(7, 1, 7, 'EPP / TLE', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(8, 1, 8, 'MAPEH', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(9, 1, 9, 'Music and Arts', 0, '2026-02-14 14:17:42', '2026-02-18 11:53:48'),
(10, 1, 10, 'Physical Education and Health', 0, '2026-02-14 14:17:42', '2026-02-18 11:53:48'),
(11, 1, 11, '', 0, '2026-02-14 14:17:42', '2026-02-18 11:45:54'),
(12, 1, 12, '', 0, '2026-02-14 14:17:42', '2026-02-18 11:45:54'),
(13, 1, 13, 'Eduk. sa Pagpapakatao', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(14, 1, 14, '*Arabic Language', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(15, 1, 15, '*Islamic Values Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(16, 2, 1, 'Mother Tongue', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(17, 2, 2, 'Filipino', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(18, 2, 3, 'English', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(19, 2, 4, 'Mathematics', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(20, 2, 5, 'Science', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(21, 2, 6, 'Araling Panlipunan', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(22, 2, 7, 'EPP / TLE', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(23, 2, 8, 'MAPEH', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(24, 2, 9, 'Music', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(25, 2, 10, 'Arts', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(26, 2, 11, 'Physical Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(27, 2, 12, 'Health', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(28, 2, 13, 'Eduk. sa Pagpapakatao', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(29, 2, 14, '*Arabic Language', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(30, 2, 15, '*Islamic Values Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(31, 3, 1, 'Mother Tongue', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(32, 3, 2, 'Filipino', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(33, 3, 3, 'English', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(34, 3, 4, 'Mathematics', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(35, 3, 5, 'Science', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(36, 3, 6, 'Araling Panlipunan', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(37, 3, 7, 'EPP / TLE', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(38, 3, 8, 'MAPEH', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(39, 3, 9, 'Music', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(40, 3, 10, 'Arts', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(41, 3, 11, 'Physical Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(42, 3, 12, 'Health', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(43, 3, 13, 'Eduk. sa Pagpapakatao', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(44, 3, 14, '*Arabic Language', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(45, 3, 15, '*Islamic Values Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(46, 4, 1, 'hello', 0, '2026-02-14 14:17:42', '2026-02-18 11:27:00'),
(47, 4, 2, 'Filipino', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(48, 4, 3, 'English', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(49, 4, 4, 'Mathematics', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(50, 4, 5, 'Science', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(51, 4, 6, 'Araling Panlipunan', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(52, 4, 7, 'EPP / TLE', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(53, 4, 8, 'MAPEH', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(54, 4, 9, 'Music', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(55, 4, 10, 'Arts', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(56, 4, 11, 'Physical Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(57, 4, 12, 'Health', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(58, 4, 13, 'Eduk. sa Pagpapakatao', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(59, 4, 14, '*Arabic Language', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(60, 4, 15, '*Islamic Values Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(61, 5, 1, 'Mother Tongue', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(62, 5, 2, 'Filipino', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(63, 5, 3, 'English', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(64, 5, 4, 'Mathematics', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(65, 5, 5, 'Science', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(66, 5, 6, 'Araling Panlipunan', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(67, 5, 7, 'EPP / TLE', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(68, 5, 8, 'MAPEH', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(69, 5, 9, 'Music', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(70, 5, 10, 'Arts', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(71, 5, 11, 'Physical Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(72, 5, 12, '', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(73, 5, 13, 'Eduk. sa Pagpapakatao', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(74, 5, 14, '*Arabic Language', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(75, 5, 15, '*Islamic Values Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(76, 6, 1, 'Mother Tongue', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(77, 6, 2, 'Filipino', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(78, 6, 3, 'English', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(79, 6, 4, 'Mathematics', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(80, 6, 5, 'Science', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(81, 6, 6, 'Araling Panlipunan', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(82, 6, 7, 'EPP / TLE', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(83, 6, 8, 'MAPEH', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(84, 6, 9, 'Music', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(85, 6, 10, 'Arts', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(86, 6, 11, 'Physical Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(87, 6, 12, 'Health', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(88, 6, 13, 'Eduk. sa Pagpapakatao', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(89, 6, 14, '*Arabic Language', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42'),
(90, 6, 15, '*Islamic Values Education', 0, '2026-02-14 14:17:42', '2026-02-14 14:17:42');

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

--
-- Dumping data for table `teacher_assignments`
--

INSERT INTO `teacher_assignments` (`id`, `school_year_id`, `teacher_id`, `assignment_type`, `subject_id`, `grade_level`, `section`, `school_year`, `created_at`) VALUES
(17, 1, 26, 'adviser', NULL, 6, 'Diamond', '2024-2025', '2026-02-14 18:31:03'),
(32, 2, 25, 'adviser', NULL, 1, 'brad', '2024-2025', '2026-02-15 20:19:53'),
(33, 2, 25, 'subject', 8, 1, 'brad', '2024-2025', '2026-02-15 20:19:53'),
(34, 2, 25, 'subject', 2, 1, 'brad', '2024-2025', '2026-02-15 20:19:53'),
(35, 1, 24, 'adviser', NULL, 2, 'lora', '2025-2026', '2026-02-18 08:50:26'),
(36, 1, 24, 'subject', 4, 2, 'lora', '2025-2026', '2026-02-18 08:50:26'),
(39, 1, 27, 'adviser', NULL, 3, 'bird', '2025-2026', '2026-02-18 11:05:28'),
(40, 1, 27, 'subject', 2, 3, 'bird', '2025-2026', '2026-02-18 11:05:28'),
(41, 1, 27, 'subject', 6, 3, 'bird', '2025-2026', '2026-02-18 11:05:28'),
(44, 1, 28, 'adviser', NULL, 4, 'hello', '2025-2026', '2026-02-18 11:10:58'),
(45, 1, 28, 'subject', 7, 4, 'hello', '2025-2026', '2026-02-18 11:10:58'),
(46, 1, 29, 'adviser', NULL, 5, 'Diamond', '2025-2026', '2026-02-18 11:33:53'),
(47, 1, 29, 'subject', 8, 5, 'Diamond', '2025-2026', '2026-02-18 11:33:53'),
(58, 1, 25, 'adviser', NULL, 1, 'brad', '2025-2026', '2026-02-18 12:02:15'),
(59, 1, 25, 'subject', 1, 1, 'brad', '2025-2026', '2026-02-18 12:02:15'),
(60, 1, 25, 'subject', 2, 1, 'brad', '2025-2026', '2026-02-18 12:02:15'),
(61, 1, 25, 'subject', 2, 2, 'lora', '2025-2026', '2026-02-18 12:02:15'),
(62, 1, 25, 'subject', 5, 1, 'brad', '2025-2026', '2026-02-18 12:02:15'),
(63, 1, 25, 'subject', 8, 1, 'brad', '2025-2026', '2026-02-18 12:02:15');

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
(6, 'admin', '$2y$10$usEktFJvXFTIgoUweN/nweBZvumHL.4uaKWkxMpO6B66hwa38opKW', 'BARON THE 3RD', 'admin', 'ha', NULL, NULL, NULL, NULL, NULL, '2025-07-27 05:33:44'),
(20, 'asda', '$2y$10$u8TfJs60jdfHpmXMnAYzVuM2hFB5.T7pgUNDZWR8Xnc.yH/u5JAf6', 'asd', 'admin', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 12:41:07'),
(21, 'afasfa', '$2y$10$HZ7Jglb58GhP7CQOM8mHROJSp.hvsQbrFart.VPzYz7iSXf3P.ZaO', 'asdasf', 'admin', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 12:41:29'),
(22, 'asdasd', '$2y$10$FgsfQAGH0FDPaGUhLXVqceeb4zj8XTULskA3zH4.dT/VqZ7.mTxXq', 'asdasd', 'admin', 'asdasd', NULL, NULL, NULL, NULL, NULL, '2026-02-13 12:42:18'),
(23, 'dasdasd', '$2y$10$ARTByHnhB/4gsCKF5wz.ve/f1Vvfn07VXJCgdR2a89kFh4r7YHIGO', 'asdaasdasd', 'admin', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 12:44:07'),
(24, 'kaia', '$2y$10$Zbid.2V6AfiZpazRIsdhOuvT6Hj9yQ0tC.7sG2Bd7BIj1uGS6xOe6', 'kaia', 'teacher', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 12:45:57'),
(25, 'john', '$2y$10$t0c7JGtGGfL.axvjlRXTeewMT7iny6LoHQJZdzjzC2dnx4BLLDTLe', 'john', 'teacher', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-13 12:49:38'),
(26, 'tarah', '$2y$10$b1.y.mEXzJE6NbAqKX0pou6F/kmmpA2.7nHatCLn9DEnICMf6HimO', 'tarah', 'teacher', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-14 18:29:29'),
(27, 'justine', '$2y$10$oj1LNmdFWnnh7FYKisY2Ke6izjTLV3x1V6FoZ4hapt7HsMYqv.INS', 'justine', 'teacher', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 11:03:20'),
(28, 'mary', '$2y$10$rAWqFrZdck6/5K8D6n6D5OCph813epoyIv4R/2x7XUF/PPvJ4zrsG', 'mary', 'teacher', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 11:09:43'),
(29, 'mat', '$2y$10$ZCou0W/pk77IFbq3pMEYvOe.7Y9j5zTv4fc7WG7F/rgvkrtnrcO8.', 'mat', 'teacher', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 11:33:53'),
(30, 'mars', '$2y$10$O1pRiDsEPxZJkVruAPZ8qOndXRBCyuYBVVUjhJVkaU7SUs4jlR7Lu', 'mars', 'teacher', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-18 13:16:38'),
(31, 'merry', '$2y$10$mfiDGDposZAIPQJkLLaO3ObQwhJdENbq8zirxMvMu3nPvlsx7fBc.', 'merry Christmas', 'teacher', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-20 12:04:19');

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=500;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `classes_per_year`
--
ALTER TABLE `classes_per_year`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6542;

--
-- AUTO_INCREMENT for table `grades_history`
--
ALTER TABLE `grades_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `remedial_classes`
--
ALTER TABLE `remedial_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `schools_attended`
--
ALTER TABLE `schools_attended`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `school_years`
--
ALTER TABLE `school_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `student_custom_subjects`
--
ALTER TABLE `student_custom_subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `subject_grade_groups`
--
ALTER TABLE `subject_grade_groups`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=811;

--
-- AUTO_INCREMENT for table `subject_teacher_assignments`
--
ALTER TABLE `subject_teacher_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teacher_assignments`
--
ALTER TABLE `teacher_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

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
