-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 08, 2026 at 10:01 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `school_system_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_years`
--

CREATE TABLE `academic_years` (
  `year_id` int(11) NOT NULL,
  `year_name` varchar(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `academic_years`
--

INSERT INTO `academic_years` (`year_id`, `year_name`, `start_date`, `end_date`, `is_current`) VALUES
(1, '2025-2026', '2025-12-21', '2028-10-18', 0),
(2, '2025-2028', '2025-12-22', '2028-10-18', 0),
(3, '2039', '2026-01-15', '2039-05-17', 1),
(4, '2025-2027', '2026-01-16', '2030-02-28', 0);

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `log_id` bigint(20) NOT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `action` varchar(60) NOT NULL,
  `entity` varchar(60) DEFAULT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`log_id`, `user_id`, `action`, `entity`, `entity_id`, `details`, `created_at`) VALUES
(1, NULL, 'CREATE_USER', 'users', 1, 'Created FIRST ADMIN user: siis cadde', '2025-12-20 11:00:36'),
(2, 1, 'UPDATE', 'grades', 1, 'Updated grade: Class 1', '2025-12-21 12:36:16'),
(3, 1, 'UPDATE', 'grades', 9, 'Updated grade: Form 1', '2025-12-21 12:45:50'),
(4, 1, 'CREATE', 'sections', 1, 'Added section: A (grade_id=6)', '2025-12-21 12:46:05'),
(5, 1, 'UPDATE', 'sections', 1, 'Updated section: A', '2025-12-21 12:48:11'),
(6, 1, 'UPDATE', 'sections', 1, 'Updated section: A', '2025-12-21 12:55:45'),
(7, 1, 'UPDATE', 'sections', 1, 'Updated section: A', '2025-12-21 13:45:55'),
(8, 1, 'CREATE', 'grades', 13, 'Added grade: Class 1A', '2025-12-21 15:13:19'),
(9, 1, 'CREATE', 'sections', 2, 'Added section: A (grade_id=13)', '2025-12-21 15:13:39'),
(10, 1, 'UPDATE', 'sections', 2, 'Updated section: A', '2025-12-21 15:32:00'),
(11, 1, 'UPDATE', 'sections', 2, 'Updated section: A', '2025-12-21 15:40:54'),
(12, 1, 'UPDATE', 'sections', 2, 'Updated section: A', '2025-12-21 16:13:37'),
(13, 1, 'UPDATE', 'sections', 1, 'Updated section: A', '2025-12-21 16:17:53'),
(14, 1, 'UPDATE', 'sections', 2, 'Updated section: A', '2025-12-21 16:18:06'),
(15, 1, 'UPDATE', 'sections', 2, 'Updated section: A', '2025-12-21 16:19:59'),
(16, 1, 'UPDATE', 'sections', 2, 'Updated section: A', '2025-12-21 16:39:17'),
(17, 1, 'UPDATE', 'sections', 1, 'Updated section: A', '2025-12-21 18:29:56'),
(18, 1, 'UPDATE', 'sections', 1, 'Updated section: A', '2025-12-22 12:22:20'),
(19, 1, 'UPDATE', 'grades', 12, 'Updated grade: Form 4', '2025-12-23 07:54:34'),
(20, 1, 'UPDATE_SUBJECTS', 'sections', 1, 'section_id=1, subjects=1', '2025-12-26 08:12:43'),
(21, 1, 'UPDATE_SUBJECTS', 'sections', 2, 'section_id=2, subjects=1', '2025-12-26 08:13:03'),
(22, 1, 'CREATE_TIMETABLE', 'timetables', 1, 'year_id=2, section_id=1', '2025-12-26 08:38:22'),
(23, 1, 'CREATE_TIMETABLE_ENTRY', 'timetable_entries', 1, 'entry_id=1, day_id=1, slot_id=1, subject_id=1, teacher_id=1', '2025-12-26 08:38:43'),
(24, 1, 'ASSIGN_SUBJECTS', 'sections', 1, 'section_id=1, subjects=7', '2025-12-26 08:46:18'),
(25, 1, 'GENERATE_TIMETABLE_CLASS', 'timetables', 1, 'year_id=2, section_id=1, subjects_count=7, teacher_id=1', '2025-12-26 08:49:34'),
(26, 1, 'GENERATE_TIMETABLE_CLASS', 'timetables', 1, 'year_id=2, section_id=1, days=1,2,3,4,5, lessons_per_day=6, subjects_count=7, teacher_id=1, shuffle=1', '2025-12-26 09:08:42'),
(27, 1, 'UPDATE_TIMETABLE_ENTRY', 'timetable_entries', 40, 'entry_id=40, day_id=2, slot_id=3, subject_id=5, teacher_id=1', '2025-12-26 15:28:03'),
(28, 1, 'UPDATE_TIMETABLE_ENTRY', 'timetable_entries', 40, 'entry_id=40, day_id=2, slot_id=3, subject_id=2, teacher_id=1', '2025-12-26 15:28:25'),
(29, 1, 'CREATE_ATTENDANCE', 'attendance_sessions', 1, 'session_id=1, year_id=2, section_id=1, date=2025-12-06, slot_id=1, subject_id=8, teacher_id=1', '2025-12-26 16:18:05'),
(30, 1, 'UPDATE_SUBJECTS', 'sections', 1, 'section_id=1, subjects=7', '2026-01-11 06:04:07'),
(31, 1, 'UPDATE_SUBJECTS', 'sections', 1, 'section_id=1, subjects=7', '2026-01-11 06:04:25'),
(32, 1, 'CREATE_ATTENDANCE', 'attendance_sessions', 2, 'session_id=2, year_id=2, section_id=2, date=2026-01-17, slot_id=1, subject_id=4, teacher_id=1', '2026-01-15 12:14:19'),
(33, 1, 'ASSIGN_SUBJECTS', 'sections', 3, 'section_id=3, subjects=2', '2026-01-15 18:47:17'),
(34, 1, 'UPDATE_SUBJECTS', 'sections', 3, 'section_id=3, subjects=2', '2026-01-15 18:47:31'),
(35, 1, 'CREATE_TIMETABLE', 'timetables', 2, 'year_id=3, section_id=3', '2026-01-15 18:50:43'),
(36, 1, 'GENERATE_TIMETABLE_CLASS', 'timetables', 2, 'year_id=3, section_id=3, days=1,2,3,4,5, lessons_per_day=6, subjects_count=2, teacher_id=1, shuffle=1', '2026-01-15 18:52:15'),
(37, 1, 'GENERATE_TIMETABLE_CLASS', 'timetables', 2, 'year_id=3, section_id=3, days=1,2,3,4,5, lessons_per_day=6, subjects_count=2, teacher_id=1, shuffle=1', '2026-01-15 18:53:41'),
(38, 1, 'GENERATE_TIMETABLE_CLASS', 'timetables', 2, 'year_id=3, section_id=3, days=1,2,3,4,5, lessons_per_day=6, subjects_count=2, teacher_id=1, shuffle=1', '2026-01-15 18:54:12'),
(39, 1, 'CREATE_ATTENDANCE', 'attendance_sessions', 3, 'session_id=3, year_id=2, section_id=1, date=2026-01-17, slot_id=1, subject_id=4, teacher_id=1', '2026-01-15 18:59:45'),
(40, 1, 'UPDATE_ATTENDANCE', 'attendance_sessions', 3, 'session_id=3, year_id=2, section_id=1, date=2026-01-17, slot_id=1, subject_id=4, teacher_id=1', '2026-01-15 19:00:14'),
(41, 1, 'UPDATE_ATTENDANCE', 'attendance_sessions', 3, 'session_id=3, year_id=2, section_id=1, date=2026-01-17, slot_id=1, subject_id=4, teacher_id=1', '2026-01-15 19:00:27'),
(42, 1, 'UPDATE_SUBJECTS', 'sections', 3, 'section_id=3, subjects=2', '2026-01-15 19:04:17'),
(43, 1, 'CREATE_USER', 'users', 3, 'Created ADMIN user: faizo', '2026-01-16 04:43:51'),
(44, 3, 'CREATE_TIMETABLE', 'timetables', 3, 'year_id=3, section_id=2', '2026-01-16 05:12:39'),
(45, 3, 'GENERATE_TIMETABLE_CLASS', 'timetables', 3, 'year_id=3, section_id=2, days=1,2,3,4,5, lessons_per_day=6, subjects_count=1, teacher_id=1, shuffle=1', '2026-01-16 05:13:29'),
(46, 3, 'UPDATE_SUBJECTS', 'sections', 1, 'section_id=1, subjects=7', '2026-01-16 05:27:32'),
(47, 3, 'ASSIGN_SUBJECTS', 'sections', 1, 'section_id=1, subjects=1', '2026-01-16 06:22:33'),
(48, 3, 'ASSIGN_SUBJECTS', 'sections', 1, 'section_id=1, subjects=1', '2026-01-16 06:23:39'),
(49, 3, 'CREATE_TIMETABLE', 'timetables', 4, 'year_id=4, section_id=1', '2026-01-16 06:26:35'),
(50, 3, 'GENERATE_TIMETABLE_CLASS', 'timetables', 4, 'year_id=4, section_id=1, days=1,2,3,4,5, lessons_per_day=6, subjects_count=1, teacher_id=1, shuffle=1', '2026-01-16 06:28:04'),
(51, 3, 'CREATE_USER', 'users', 4, 'Created ADMIN user: Faiza', '2026-01-16 06:36:06'),
(52, NULL, 'CREATE_USER', 'users', 5, 'Created FIRST ADMIN user: ENG RUWAYDO', '2026-02-03 06:19:17'),
(53, NULL, 'CREATE_USER', 'users', 6, 'Created FIRST ADMIN user: cali', '2026-02-07 17:48:28'),
(54, 6, 'CREATE_USER', 'users', 7, 'Created ADMIN user: xaawo', '2026-02-08 04:16:00');

-- --------------------------------------------------------

--
-- Table structure for table `admission_sequences`
--

CREATE TABLE `admission_sequences` (
  `year` int(11) NOT NULL,
  `last_no` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admission_sequences`
--

INSERT INTO `admission_sequences` (`year`, `last_no`, `updated_at`) VALUES
(2025, 8, '2025-12-25 11:30:20'),
(2026, 3, '2026-01-15 19:37:57');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `session_id` bigint(20) NOT NULL,
  `enrollment_id` bigint(20) NOT NULL,
  `status` enum('P','A','L') NOT NULL DEFAULT 'A',
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance_records`
--

INSERT INTO `attendance_records` (`session_id`, `enrollment_id`, `status`, `marked_at`) VALUES
(1, 36, 'P', '2025-12-26 16:18:05'),
(2, 33, 'P', '2026-01-15 12:14:19'),
(2, 38, 'P', '2026-01-15 12:14:19'),
(3, 36, 'A', '2026-01-15 19:00:27'),
(3, 39, 'A', '2026-01-15 19:00:27');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_sessions`
--

CREATE TABLE `attendance_sessions` (
  `session_id` bigint(20) NOT NULL,
  `year_id` int(11) NOT NULL,
  `section_id` bigint(20) NOT NULL,
  `session_date` date NOT NULL,
  `day_id` int(11) NOT NULL,
  `slot_id` int(11) NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `teacher_id` bigint(20) NOT NULL,
  `status` enum('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `attendance_sessions`
--

INSERT INTO `attendance_sessions` (`session_id`, `year_id`, `section_id`, `session_date`, `day_id`, `slot_id`, `subject_id`, `teacher_id`, `status`, `created_at`) VALUES
(1, 2, 1, '2025-12-06', 1, 1, 8, 1, 'OPEN', '2025-12-26 16:18:05'),
(2, 2, 2, '2026-01-17', 1, 1, 4, 1, 'OPEN', '2026-01-15 12:14:19'),
(3, 2, 1, '2026-01-17', 1, 1, 4, 1, 'OPEN', '2026-01-15 18:59:45');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_summary_cache`
--

CREATE TABLE `attendance_summary_cache` (
  `summary_id` bigint(20) NOT NULL,
  `enrollment_id` bigint(20) NOT NULL,
  `year_id` int(11) NOT NULL,
  `total_sessions` int(11) NOT NULL DEFAULT 0,
  `total_absent` int(11) NOT NULL DEFAULT 0,
  `total_late` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `content_blocks`
--

CREATE TABLE `content_blocks` (
  `block_key` varchar(120) NOT NULL,
  `content` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `content_blocks`
--

INSERT INTO `content_blocks` (`block_key`, `content`, `updated_at`) VALUES
('users_roles_main', '<div class=\"small\">Users & Roles main content. Click Edit to customize.</div>', '2026-02-07 19:11:09');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `employee_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `gender` enum('M','F') DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `salary_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `hired_date` date DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`employee_id`, `user_id`, `full_name`, `phone`, `gender`, `photo_url`, `salary_amount`, `hired_date`, `status`, `created_at`) VALUES
(1, 1, 'cabdi caziiz mohamed', '0615264847', 'M', 'https://media.licdn.com/dms/image/v2/D4E16AQGkFVJ2QYVe1A/profile-displaybackgroundimage-shrink_350_1400/B4EZqc.f3PHgAY-/0/1763570227671?e=1767830400&v=beta&t=Y0SwArMqcpetbMui4dUQc9OJZHdaVVEgal0GzkJNluE', 300.00, '2025-12-20', 'ACTIVE', '2025-12-20 11:00:36'),
(2, 2, 'cabdicaziiz', '+252615270078', 'M', 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxITEhUQEhIVFRUWGBcVFRgVFRUWFhgVFxUXFhUVFhUYHSggGBolHRUVITEhJSkrLi4uFx8zODMsNygtLisBCgoKDg0OGhAQGy0lHyYtLSstLS0vLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAL0BCgMBIgACEQEDEQH/', 300.00, '2025-12-22', 'ACTIVE', '2025-12-22 15:02:06'),
(3, 3, 'faizo cabdi mkaaran', '615242637', 'F', '', 500.00, '2026-01-16', 'ACTIVE', '2026-01-16 04:43:51'),
(4, 4, 'Faiza Abdulkadir Makaran', '', 'F', '', 100.00, '2026-01-16', 'ACTIVE', '2026-01-16 06:36:06'),
(5, 5, 'ENG RUWAYDO ABDULAKDIR', '615270078', 'M', '', 100.00, '2026-02-03', 'ACTIVE', '2026-02-03 06:19:17'),
(6, 6, 'cali maxamed nuur', '0615263658', 'M', '', 100.00, '2026-02-07', 'ACTIVE', '2026-02-07 17:48:28'),
(7, 7, 'xawo maxamed nuur', '0615264847', 'F', '', 100.00, NULL, 'ACTIVE', '2026-02-08 04:16:00');

-- --------------------------------------------------------

--
-- Table structure for table `employee_documents`
--

CREATE TABLE `employee_documents` (
  `doc_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `doc_type` varchar(60) NOT NULL,
  `file_url` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `enrollment_id` bigint(20) NOT NULL,
  `student_id` bigint(20) NOT NULL,
  `year_id` int(11) NOT NULL,
  `section_id` bigint(20) NOT NULL,
  `roll_no` varchar(30) DEFAULT NULL,
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('ENROLLED','TRANSFERRED','GRADUATED','DROPPED') NOT NULL DEFAULT 'ENROLLED'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`enrollment_id`, `student_id`, `year_id`, `section_id`, `roll_no`, `enrolled_at`, `status`) VALUES
(33, 12, 2, 2, NULL, '2025-12-25 12:46:07', 'ENROLLED'),
(34, 12, 1, 1, '', '2025-12-25 12:46:35', 'DROPPED'),
(36, 13, 2, 1, '8A', '2025-12-25 12:54:26', 'ENROLLED'),
(37, 13, 1, 2, NULL, '2025-12-25 15:35:41', 'DROPPED'),
(38, 14, 2, 2, NULL, '2026-01-13 12:41:49', 'ENROLLED'),
(39, 15, 2, 2, NULL, '2026-01-15 18:30:10', 'ENROLLED'),
(40, 16, 2, 1, NULL, '2026-01-15 19:37:57', 'ENROLLED');

--
-- Triggers `enrollments`
--
DELIMITER $$
CREATE TRIGGER `trg_enrollments_capacity` BEFORE INSERT ON `enrollments` FOR EACH ROW BEGIN
  DECLARE cap INT;
  DECLARE cur_count INT;

  SELECT capacity_max INTO cap
  FROM sections
  WHERE section_id = NEW.section_id;

  SELECT COUNT(*) INTO cur_count
  FROM enrollments
  WHERE section_id = NEW.section_id
    AND year_id = NEW.year_id
    AND status = 'ENROLLED';

  IF cur_count >= cap THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Section capacity reached. Cannot enroll more students.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `exams`
--

CREATE TABLE `exams` (
  `exam_id` bigint(20) NOT NULL,
  `year_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `exam_name` varchar(80) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `exams`
--

INSERT INTO `exams` (`exam_id`, `year_id`, `term_id`, `exam_name`, `start_date`, `end_date`) VALUES
(1, 2, 1, 'Somali', '2026-12-11', '2026-12-11'),
(2, 3, 1, 'mid-term', '2026-01-15', '2026-01-21'),
(3, 2, 2, 'mid-term', '2026-01-16', '2026-01-18'),
(4, 2, 2, 'mid-term', '2026-12-02', '2028-12-05');

-- --------------------------------------------------------

--
-- Table structure for table `exam_papers`
--

CREATE TABLE `exam_papers` (
  `paper_id` bigint(20) NOT NULL,
  `exam_id` bigint(20) NOT NULL,
  `section_id` bigint(20) NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `max_mark` int(11) NOT NULL DEFAULT 100,
  `min_pass` int(11) NOT NULL DEFAULT 50
) ;

--
-- Dumping data for table `exam_papers`
--

INSERT INTO `exam_papers` (`paper_id`, `exam_id`, `section_id`, `subject_id`, `max_mark`, `min_pass`) VALUES
(1, 1, 1, 8, 100, 50),
(2, 2, 3, 4, 100, 50),
(3, 3, 1, 5, 100, 50),
(4, 4, 1, 3, 100, 50);

-- --------------------------------------------------------

--
-- Table structure for table `exam_terms`
--

CREATE TABLE `exam_terms` (
  `term_id` int(11) NOT NULL,
  `term_name` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `exam_terms`
--

INSERT INTO `exam_terms` (`term_id`, `term_name`) VALUES
(3, 'Final'),
(1, 'Term1'),
(2, 'Term2');

-- --------------------------------------------------------

--
-- Table structure for table `fee_structures`
--

CREATE TABLE `fee_structures` (
  `fee_structure_id` bigint(20) NOT NULL,
  `year_id` int(11) NOT NULL,
  `grade_id` int(11) NOT NULL,
  `fee_type_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fee_structures`
--

INSERT INTO `fee_structures` (`fee_structure_id`, `year_id`, `grade_id`, `fee_type_id`, `amount`) VALUES
(1, 2, 13, 3, 25.00),
(2, 3, 12, 2, 25.00);

-- --------------------------------------------------------

--
-- Table structure for table `fee_types`
--

CREATE TABLE `fee_types` (
  `fee_type_id` int(11) NOT NULL,
  `fee_type_name` varchar(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fee_types`
--

INSERT INTO `fee_types` (`fee_type_id`, `fee_type_name`) VALUES
(2, 'EXAM_FEE'),
(3, 'REGISTRATION'),
(4, 'TRANSPORT'),
(1, 'TUITION');

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `grade_id` int(11) NOT NULL,
  `level_id` int(11) NOT NULL,
  `grade_name` varchar(30) NOT NULL,
  `sort_order` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `grades`
--

INSERT INTO `grades` (`grade_id`, `level_id`, `grade_name`, `sort_order`) VALUES
(1, 2, 'Class 1', 1),
(2, 1, 'Class 2', 2),
(3, 1, 'Class 3', 3),
(4, 1, 'Class 4', 4),
(5, 1, 'Class 5', 5),
(6, 1, 'Class 6', 6),
(7, 1, 'Class 7', 7),
(8, 1, 'Class 8', 8),
(9, 2, 'Form 1', 9),
(10, 2, 'Form 2', 10),
(11, 2, 'Form 3', 11),
(12, 2, 'Form 4', 12),
(13, 1, 'Class 1A', 12),
(14, 1, 'form c', 1);

-- --------------------------------------------------------

--
-- Table structure for table `grade_system`
--

CREATE TABLE `grade_system` (
  `grade_sys_id` int(11) NOT NULL,
  `grade_label` varchar(5) NOT NULL,
  `min_score` int(11) NOT NULL,
  `max_score` int(11) NOT NULL
) ;

--
-- Dumping data for table `grade_system`
--

INSERT INTO `grade_system` (`grade_sys_id`, `grade_label`, `min_score`, `max_score`) VALUES
(1, 'A', 90, 100),
(2, 'B', 80, 89),
(3, 'C', 70, 79),
(4, 'D', 60, 69),
(5, 'E', 50, 59),
(6, 'F', 0, 49);

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `item_id` bigint(20) NOT NULL,
  `invoice_id` bigint(20) NOT NULL,
  `fee_type_id` int(11) NOT NULL,
  `description` varchar(120) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoice_items`
--

INSERT INTO `invoice_items` (`item_id`, `invoice_id`, `fee_type_id`, `description`, `amount`) VALUES
(17, 17, 1, 'Tuition Fee', 20.00);

-- --------------------------------------------------------

--
-- Table structure for table `marks`
--

CREATE TABLE `marks` (
  `paper_id` bigint(20) NOT NULL,
  `enrollment_id` bigint(20) NOT NULL,
  `mark_value` int(11) NOT NULL,
  `result` enum('PASS','FAIL') NOT NULL DEFAULT 'FAIL',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Dumping data for table `marks`
--

INSERT INTO `marks` (`paper_id`, `enrollment_id`, `mark_value`, `result`, `created_at`) VALUES
(1, 36, 50, 'PASS', '2026-01-15 16:10:33'),
(2, 39, 100, 'PASS', '2026-01-15 19:06:02'),
(3, 36, 50, 'PASS', '2026-01-16 05:18:33'),
(3, 40, 89, 'PASS', '2026-01-16 05:18:33'),
(4, 36, 70, 'PASS', '2026-01-16 06:07:25'),
(4, 40, 10, 'FAIL', '2026-01-16 06:07:25');

--
-- Triggers `marks`
--
DELIMITER $$
CREATE TRIGGER `trg_marks_auto_result_ins` BEFORE INSERT ON `marks` FOR EACH ROW BEGIN
  IF NEW.mark_value < 0 OR NEW.mark_value > 100 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mark must be between 0 and 100.';
  END IF;

  IF NEW.mark_value >= 50 THEN
    SET NEW.result = 'PASS';
  ELSE
    SET NEW.result = 'FAIL';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_marks_auto_result_upd` BEFORE UPDATE ON `marks` FOR EACH ROW BEGIN
  IF NEW.mark_value < 0 OR NEW.mark_value > 100 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mark must be between 0 and 100.';
  END IF;

  IF NEW.mark_value >= 50 THEN
    SET NEW.result = 'PASS';
  ELSE
    SET NEW.result = 'FAIL';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `parents`
--

CREATE TABLE `parents` (
  `parent_id` bigint(20) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `relation_type` enum('MOTHER','FATHER','GUARDIAN') NOT NULL DEFAULT 'GUARDIAN'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` bigint(20) NOT NULL,
  `invoice_id` bigint(20) NOT NULL,
  `method_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `paid_date` datetime NOT NULL DEFAULT current_timestamp(),
  `reference_no` varchar(80) DEFAULT NULL,
  `received_by` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `invoice_id`, `method_id`, `amount`, `paid_date`, `reference_no`, `received_by`) VALUES
(9, 17, 2, 20.00, '2026-01-13 15:41:49', 'Evc', 1),
(10, 17, 4, 50.00, '2026-01-15 22:17:32', 'evc', 1);

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `method_id` int(11) NOT NULL,
  `method_name` varchar(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_methods`
--

INSERT INTO `payment_methods` (`method_id`, `method_name`) VALUES
(4, 'BANK'),
(1, 'CASH'),
(3, 'EDAHAB'),
(2, 'EVCPLUS');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `permission_id` int(11) NOT NULL,
  `permission_key` varchar(60) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`permission_id`, `permission_key`, `description`) VALUES
(1, 'finance.invoices.create', 'Create invoices'),
(2, 'finance.invoices.view', 'View invoices'),
(3, 'sms.send', 'Send SMS messages'),
(4, 'students.create', 'Add new students'),
(5, 'dashboard.view', 'View dashboard and charts'),
(6, 'students.view', 'View student list and profiles'),
(7, 'users.manage', 'Manage user accounts (create/delete)'),
(8, 'marks.view', 'View marks and results'),
(9, 'marks.edit', 'Edit exam marks'),
(10, 'attendance.view', 'View attendance reports'),
(11, 'attendance.mark', 'Mark attendance for sessions'),
(12, 'settings.manage', 'Access system settings');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int(11) NOT NULL,
  `role_name` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`) VALUES
(1, 'ADMIN'),
(4, 'FINANCE'),
(3, 'RECEPTION'),
(5, 'STUDENT'),
(2, 'TEACHER');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(3, 4),
(4, 1),
(4, 2),
(4, 3);

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `room_id` bigint(20) NOT NULL,
  `room_name` varchar(50) NOT NULL,
  `capacity` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_payments`
--

CREATE TABLE `salary_payments` (
  `salary_payment_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `paid_date` date NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `school_levels`
--

CREATE TABLE `school_levels` (
  `level_id` int(11) NOT NULL,
  `level_name` enum('PRIMARY','SECONDARY') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `school_levels`
--

INSERT INTO `school_levels` (`level_id`, `level_name`) VALUES
(1, 'PRIMARY'),
(2, 'SECONDARY');

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `section_id` bigint(20) NOT NULL,
  `grade_id` int(11) NOT NULL,
  `section_name` varchar(10) NOT NULL,
  `capacity_max` int(11) NOT NULL DEFAULT 50
) ;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`section_id`, `grade_id`, `section_name`, `capacity_max`) VALUES
(1, 8, 'A', 3),
(2, 13, 'A', 50),
(3, 12, 'A', 30),
(4, 9, 'D', 20),
(5, 14, 'C', 1);

-- --------------------------------------------------------

--
-- Table structure for table `section_subjects`
--

CREATE TABLE `section_subjects` (
  `section_id` bigint(20) NOT NULL,
  `subject_id` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `section_subjects`
--

INSERT INTO `section_subjects` (`section_id`, `subject_id`) VALUES
(1, 4),
(2, 1),
(3, 3),
(3, 4);

-- --------------------------------------------------------

--
-- Table structure for table `section_subject_details`
--

CREATE TABLE `section_subject_details` (
  `section_id` bigint(20) NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `max_mark` int(11) NOT NULL DEFAULT 100
) ;

--
-- Dumping data for table `section_subject_details`
--

INSERT INTO `section_subject_details` (`section_id`, `subject_id`, `max_mark`) VALUES
(1, 4, 100),
(2, 1, 100),
(3, 3, 100),
(3, 4, 100);

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `sms_log_id` bigint(20) NOT NULL,
  `sms_id` bigint(20) NOT NULL,
  `provider` varchar(60) DEFAULT NULL,
  `provider_message_id` varchar(120) DEFAULT NULL,
  `response_text` text DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sms_logs`
--

INSERT INTO `sms_logs` (`sms_log_id`, `sms_id`, `provider`, `provider_message_id`, `response_text`, `logged_at`) VALUES
(1, 1, 'MANUAL', 'LOCAL-1-20260115182930', 'Status manually changed to: SENT', '2026-01-15 17:29:30');

-- --------------------------------------------------------

--
-- Table structure for table `sms_outbox`
--

CREATE TABLE `sms_outbox` (
  `sms_id` bigint(20) NOT NULL,
  `to_phone` varchar(30) NOT NULL,
  `message` text NOT NULL,
  `status` enum('PENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING',
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sms_outbox`
--

INSERT INTO `sms_outbox` (`sms_id`, `to_phone`, `message`, `status`, `created_by`, `created_at`) VALUES
(1, '0615270078', 'lacagata bisha halosidro', 'SENT', 1, '2026-01-15 17:29:23');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `student_id` bigint(20) NOT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `admission_no` varchar(40) DEFAULT NULL,
  `first_name` varchar(60) NOT NULL,
  `middle_name` varchar(60) NOT NULL,
  `last_name` varchar(60) NOT NULL,
  `mother_full_name` varchar(150) NOT NULL,
  `gender` enum('M','F') DEFAULT NULL,
  `dob` date DEFAULT NULL,
  `nationality` varchar(80) DEFAULT NULL,
  `place_of_birth` varchar(120) DEFAULT NULL,
  `profile_photo_url` text DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_phone` varchar(30) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `user_id`, `admission_no`, `first_name`, `middle_name`, `last_name`, `mother_full_name`, `gender`, `dob`, `nationality`, `place_of_birth`, `profile_photo_url`, `phone`, `address`, `emergency_contact_name`, `emergency_contact_phone`, `status`, `created_at`) VALUES
(12, NULL, 'ADM-2025-0007', 'NUURO', 'FAARX', 'CABDI', 'AMINA JAYLAANI MAXAMAED', 'F', '2011-06-30', 'SOMALIA', 'BANADIR', '', '0616536356', '03215', 'AMINA JAYLAANI MAXAMAED', '0616536356', 'ACTIVE', '2025-12-25 11:07:57'),
(13, NULL, 'ADM-2025-0008', 'ABDIAZIZ', 'MOHAMED', 'ALI', 'ABDIAZIZ MOHAMED ALI', 'M', '2013-06-18', 'SOMALI', 'MOGDHSIO', 'uploads/students/std_20251225_132557_f015015d70ef.jpg', '0615270078', '21november', 'ABDIAZIZ MOHAMED ALI', '0615270078', 'ACTIVE', '2025-12-25 11:30:20'),
(14, NULL, 'ADM-2026-0001', 'maxamed', 'farax', 'caydiid', 'kaltuun farax nuur', 'M', '2010-06-15', 'soamlia', 'Mogadishu', '', '0612365957', 'holwadg', 'kaltuun farax nuur', '0612365957', 'ACTIVE', '2026-01-13 12:41:49'),
(15, NULL, 'ADM-2026-0002', 'maxamed', 'iskac', 'idow', 'xaawo nuur farax', 'M', '2006-06-13', 'somali', 'Mogadishu', '', '0612365958', 'hodan', 'xaawo nuur farax', '0612365958', 'ACTIVE', '2026-01-15 18:30:10'),
(16, NULL, 'ADM-2026-0003', 'ruwaydo', 'cali', 'maxamed', 'nuuro farax maxamed', 'F', '2003-02-04', 'somali', 'mogdisho', '', '0612454785', 'holwadg', 'cabdi caziiz mohamed', '0615262549', 'ACTIVE', '2026-01-15 19:37:57');

--
-- Triggers `students`
--
DELIMITER $$
CREATE TRIGGER `trg_students_mothername_check` BEFORE INSERT ON `students` FOR EACH ROW BEGIN
  IF NEW.mother_full_name IS NULL OR CHAR_LENGTH(TRIM(NEW.mother_full_name)) = 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mother full name is required.';
  END IF;

  -- require at least 3 words for mother name (2 spaces)
  IF (LENGTH(TRIM(NEW.mother_full_name)) - LENGTH(REPLACE(TRIM(NEW.mother_full_name), ' ', ''))) < 2 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Mother full name must be at least 3 words.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `student_documents`
--

CREATE TABLE `student_documents` (
  `doc_id` bigint(20) NOT NULL,
  `student_id` bigint(20) NOT NULL,
  `doc_type` varchar(60) NOT NULL,
  `file_url` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_invoices`
--

CREATE TABLE `student_invoices` (
  `invoice_id` bigint(20) NOT NULL,
  `enrollment_id` bigint(20) NOT NULL,
  `invoice_no` varchar(40) NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('DRAFT','ISSUED','PAID','PARTIAL','CANCELLED') NOT NULL DEFAULT 'ISSUED',
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_invoices`
--

INSERT INTO `student_invoices` (`invoice_id`, `enrollment_id`, `invoice_no`, `issue_date`, `due_date`, `status`, `created_by`, `created_at`) VALUES
(17, 38, 'INV-38-20260113134149', '2026-01-13', '2026-02-12', 'PAID', 1, '2026-01-13 12:41:49');

-- --------------------------------------------------------

--
-- Table structure for table `student_parents`
--

CREATE TABLE `student_parents` (
  `student_id` bigint(20) NOT NULL,
  `parent_id` bigint(20) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_result_summary`
--

CREATE TABLE `student_result_summary` (
  `summary_id` bigint(20) NOT NULL,
  `exam_id` bigint(20) NOT NULL,
  `enrollment_id` bigint(20) NOT NULL,
  `total_subjects` int(11) NOT NULL DEFAULT 0,
  `total_pass` int(11) NOT NULL DEFAULT 0,
  `total_fail` int(11) NOT NULL DEFAULT 0,
  `average_score` decimal(5,2) DEFAULT NULL,
  `status` enum('PASS','FAIL') NOT NULL DEFAULT 'FAIL'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `student_result_summary`
--

INSERT INTO `student_result_summary` (`summary_id`, `exam_id`, `enrollment_id`, `total_subjects`, `total_pass`, `total_fail`, `average_score`, `status`) VALUES
(2, 1, 36, 1, 1, 0, 50.00, 'PASS'),
(3, 2, 39, 1, 0, 1, 25.00, 'FAIL'),
(7, 3, 36, 1, 1, 0, 50.00, 'PASS'),
(8, 3, 40, 1, 1, 0, 89.00, 'PASS'),
(10, 4, 36, 1, 0, 1, 10.00, 'FAIL'),
(11, 4, 40, 1, 0, 1, 10.00, 'FAIL');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `subject_id` bigint(20) NOT NULL,
  `subject_name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`subject_id`, `subject_name`, `is_active`) VALUES
(1, 'Math', 1),
(2, 'English', 1),
(3, 'Somali', 1),
(4, 'Arabia', 1),
(5, 'science', 1),
(6, 'cilmiga bulsho', 1),
(7, 'technology', 1),
(8, 'business', 1),
(9, 'philosophy', 1);

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `teacher_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `qualification` varchar(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `teachers`
--

INSERT INTO `teachers` (`teacher_id`, `employee_id`, `specialization`, `qualification`) VALUES
(1, 2, 'Somalia', 'bachelor\'s degree');

-- --------------------------------------------------------

--
-- Table structure for table `teacher_subjects`
--

CREATE TABLE `teacher_subjects` (
  `teacher_id` bigint(20) NOT NULL,
  `subject_name` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `teacher_subjects`
--

INSERT INTO `teacher_subjects` (`teacher_id`, `subject_name`) VALUES
(1, 'English xisaab business');

-- --------------------------------------------------------

--
-- Table structure for table `timetables`
--

CREATE TABLE `timetables` (
  `timetable_id` bigint(20) NOT NULL,
  `year_id` int(11) NOT NULL,
  `section_id` bigint(20) NOT NULL,
  `name` varchar(80) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `timetables`
--

INSERT INTO `timetables` (`timetable_id`, `year_id`, `section_id`, `name`, `created_at`) VALUES
(1, 2, 1, 'Timetable', '2025-12-26 08:38:22'),
(2, 3, 3, 'Timetable', '2026-01-15 18:50:43'),
(3, 3, 2, 'Timetable', '2026-01-16 05:12:39'),
(4, 4, 1, 'Timetable', '2026-01-16 06:26:35');

-- --------------------------------------------------------

--
-- Table structure for table `timetable_entries`
--

CREATE TABLE `timetable_entries` (
  `entry_id` bigint(20) NOT NULL,
  `timetable_id` bigint(20) NOT NULL,
  `day_id` int(11) NOT NULL,
  `slot_id` int(11) NOT NULL,
  `subject_id` bigint(20) NOT NULL,
  `teacher_id` bigint(20) NOT NULL,
  `room_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `timetable_entries`
--

INSERT INTO `timetable_entries` (`entry_id`, `timetable_id`, `day_id`, `slot_id`, `subject_id`, `teacher_id`, `room_id`) VALUES
(32, 1, 1, 1, 4, 1, NULL),
(33, 1, 1, 2, 6, 1, NULL),
(34, 1, 1, 3, 3, 1, NULL),
(35, 1, 1, 5, 5, 1, NULL),
(36, 1, 1, 6, 7, 1, NULL),
(37, 1, 1, 7, 8, 1, NULL),
(38, 1, 2, 1, 2, 1, NULL),
(39, 1, 2, 2, 4, 1, NULL),
(40, 1, 2, 3, 2, 1, NULL),
(41, 1, 2, 5, 3, 1, NULL),
(42, 1, 2, 6, 5, 1, NULL),
(43, 1, 2, 7, 7, 1, NULL),
(44, 1, 3, 1, 8, 1, NULL),
(45, 1, 3, 2, 2, 1, NULL),
(46, 1, 3, 3, 4, 1, NULL),
(47, 1, 3, 5, 6, 1, NULL),
(48, 1, 3, 6, 3, 1, NULL),
(49, 1, 3, 7, 5, 1, NULL),
(50, 1, 4, 1, 7, 1, NULL),
(51, 1, 4, 2, 8, 1, NULL),
(52, 1, 4, 3, 2, 1, NULL),
(53, 1, 4, 5, 4, 1, NULL),
(54, 1, 4, 6, 6, 1, NULL),
(55, 1, 4, 7, 3, 1, NULL),
(56, 1, 5, 1, 5, 1, NULL),
(57, 1, 5, 2, 7, 1, NULL),
(58, 1, 5, 3, 8, 1, NULL),
(59, 1, 5, 5, 2, 1, NULL),
(60, 1, 5, 6, 4, 1, NULL),
(61, 1, 5, 7, 6, 1, NULL),
(122, 2, 1, 1, 4, 1, NULL),
(123, 2, 1, 2, 3, 1, NULL),
(124, 2, 1, 3, 4, 1, NULL),
(125, 2, 1, 5, 3, 1, NULL),
(126, 2, 1, 6, 4, 1, NULL),
(127, 2, 1, 7, 3, 1, NULL),
(128, 2, 2, 1, 4, 1, NULL),
(129, 2, 2, 2, 3, 1, NULL),
(130, 2, 2, 3, 4, 1, NULL),
(131, 2, 2, 5, 3, 1, NULL),
(132, 2, 2, 6, 4, 1, NULL),
(133, 2, 2, 7, 3, 1, NULL),
(134, 2, 3, 1, 4, 1, NULL),
(135, 2, 3, 2, 3, 1, NULL),
(136, 2, 3, 3, 4, 1, NULL),
(137, 2, 3, 5, 3, 1, NULL),
(138, 2, 3, 6, 4, 1, NULL),
(139, 2, 3, 7, 3, 1, NULL),
(140, 2, 4, 1, 4, 1, NULL),
(141, 2, 4, 2, 3, 1, NULL),
(142, 2, 4, 3, 4, 1, NULL),
(143, 2, 4, 5, 3, 1, NULL),
(144, 2, 4, 6, 4, 1, NULL),
(145, 2, 4, 7, 3, 1, NULL),
(146, 2, 5, 1, 4, 1, NULL),
(147, 2, 5, 2, 3, 1, NULL),
(148, 2, 5, 3, 4, 1, NULL),
(149, 2, 5, 5, 3, 1, NULL),
(150, 2, 5, 6, 4, 1, NULL),
(151, 2, 5, 7, 3, 1, NULL),
(152, 3, 1, 1, 1, 1, NULL),
(153, 3, 1, 2, 1, 1, NULL),
(154, 3, 1, 3, 1, 1, NULL),
(155, 3, 1, 5, 1, 1, NULL),
(156, 3, 1, 6, 1, 1, NULL),
(157, 3, 1, 7, 1, 1, NULL),
(158, 3, 2, 1, 1, 1, NULL),
(159, 3, 2, 2, 1, 1, NULL),
(160, 3, 2, 3, 1, 1, NULL),
(161, 3, 2, 5, 1, 1, NULL),
(162, 3, 2, 6, 1, 1, NULL),
(163, 3, 2, 7, 1, 1, NULL),
(164, 3, 3, 1, 1, 1, NULL),
(165, 3, 3, 2, 1, 1, NULL),
(166, 3, 3, 3, 1, 1, NULL),
(167, 3, 3, 5, 1, 1, NULL),
(168, 3, 3, 6, 1, 1, NULL),
(169, 3, 3, 7, 1, 1, NULL),
(170, 3, 4, 1, 1, 1, NULL),
(171, 3, 4, 2, 1, 1, NULL),
(172, 3, 4, 3, 1, 1, NULL),
(173, 3, 4, 5, 1, 1, NULL),
(174, 3, 4, 6, 1, 1, NULL),
(175, 3, 4, 7, 1, 1, NULL),
(176, 3, 5, 1, 1, 1, NULL),
(177, 3, 5, 2, 1, 1, NULL),
(178, 3, 5, 3, 1, 1, NULL),
(179, 3, 5, 5, 1, 1, NULL),
(180, 3, 5, 6, 1, 1, NULL),
(181, 3, 5, 7, 1, 1, NULL),
(182, 4, 1, 1, 4, 1, NULL),
(183, 4, 1, 2, 4, 1, NULL),
(184, 4, 1, 3, 4, 1, NULL),
(185, 4, 1, 5, 4, 1, NULL),
(186, 4, 1, 6, 4, 1, NULL),
(187, 4, 1, 7, 4, 1, NULL),
(188, 4, 2, 1, 4, 1, NULL),
(189, 4, 2, 2, 4, 1, NULL),
(190, 4, 2, 3, 4, 1, NULL),
(191, 4, 2, 5, 4, 1, NULL),
(192, 4, 2, 6, 4, 1, NULL),
(193, 4, 2, 7, 4, 1, NULL),
(194, 4, 3, 1, 4, 1, NULL),
(195, 4, 3, 2, 4, 1, NULL),
(196, 4, 3, 3, 4, 1, NULL),
(197, 4, 3, 5, 4, 1, NULL),
(198, 4, 3, 6, 4, 1, NULL),
(199, 4, 3, 7, 4, 1, NULL),
(200, 4, 4, 1, 4, 1, NULL),
(201, 4, 4, 2, 4, 1, NULL),
(202, 4, 4, 3, 4, 1, NULL),
(203, 4, 4, 5, 4, 1, NULL),
(204, 4, 4, 6, 4, 1, NULL),
(205, 4, 4, 7, 4, 1, NULL),
(206, 4, 5, 1, 4, 1, NULL),
(207, 4, 5, 2, 4, 1, NULL),
(208, 4, 5, 3, 4, 1, NULL),
(209, 4, 5, 5, 4, 1, NULL),
(210, 4, 5, 6, 4, 1, NULL),
(211, 4, 5, 7, 4, 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `time_slots`
--

CREATE TABLE `time_slots` (
  `slot_id` int(11) NOT NULL,
  `slot_no` int(11) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_break` tinyint(1) NOT NULL DEFAULT 0
) ;

--
-- Dumping data for table `time_slots`
--

INSERT INTO `time_slots` (`slot_id`, `slot_no`, `start_time`, `end_time`, `is_break`) VALUES
(1, 1, '01:30:00', '03:00:00', 0),
(2, 2, '03:00:00', '04:30:00', 0),
(3, 3, '04:30:00', '09:00:00', 0),
(4, 0, '09:00:00', '09:30:00', 1),
(5, 4, '09:30:00', '10:30:00', 0),
(6, 5, '10:30:00', '11:15:00', 0),
(7, 6, '11:15:00', '12:00:00', 0);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` bigint(20) NOT NULL,
  `username` varchar(80) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `phone`, `email`, `is_active`, `created_at`) VALUES
(1, 'siis cadde', '$2y$10$94ev8eMNuK0pnyxP.1H3lez1ZPNwroVxVB7lVFbRagWJ/MmGfPg/2', '0615264847', 'siiscadde16@gmail.com', 1, '2025-12-20 11:00:36'),
(2, 'maxamed', '$2y$10$jS44.wZpVGv2SOGmsVAzPukpnXZLgvmW9xwAlCLB3O/mzrPBCwVGG', '+252615270078', 'siiscadde16@gmail.com', 1, '2025-12-22 15:02:06'),
(3, 'faizo', '$2y$10$xxJrwnfWbJAs0iX0eKlQL.j90mJYY6VuN7albo8pX1H.cHwC0qrQS', '615242637', 'faizo@gmail.com', 1, '2026-01-16 04:43:51'),
(4, 'Faiza', '$2y$10$L4SfV3WIqqjmneAzt0nt0ea2rZCTd3oKkUxjlsX8yytghEe6WEblO', '', 'faiza@gmail.com', 1, '2026-01-16 06:36:06'),
(5, 'ENG RUWAYDO', '$2y$10$xYDmM4GjaiuSwTIoRB9yi.tns4zJ9sqjM9oBpLnYLHdo9D.wjQWwK', '615270078', 'siiscadde16@gmail.com', 1, '2026-02-03 06:19:17'),
(6, 'cali', '$2y$10$MHXHcbRiIC86xyQ4cRX87ufn02w0uAIORpFLFRAcb.7TH2qT3Hpna', '0615263658', '', 1, '2026-02-07 17:48:28'),
(7, 'xaawo', '$2y$10$nnX456hsrubleoXTMnqEUeJClYcL.U.VOZXt8j8GYo66nnrQpBva6', '0615264847', 'daryeelc122@gmail.com', 1, '2026-02-08 04:16:00');

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `granted_by` int(11) DEFAULT NULL,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_permissions`
--

INSERT INTO `user_permissions` (`id`, `user_id`, `permission_id`, `granted_by`, `granted_at`) VALUES
(1, 7, 11, 6, '2026-02-08 04:17:41'),
(2, 7, 10, 6, '2026-02-08 04:17:41'),
(3, 7, 9, 6, '2026-02-08 04:17:41'),
(4, 7, 8, 6, '2026-02-08 04:17:41'),
(5, 7, 12, 6, '2026-02-08 04:17:41'),
(6, 7, 4, 6, '2026-02-08 04:17:41'),
(7, 7, 6, 6, '2026-02-08 04:17:41');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` bigint(20) NOT NULL,
  `role_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(1, 1),
(2, 1),
(3, 1),
(4, 1),
(5, 2),
(6, 1),
(7, 3);

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `session_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `is_revoked` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `week_days`
--

CREATE TABLE `week_days` (
  `day_id` int(11) NOT NULL,
  `day_name` enum('SAT','SUN','MON','TUE','WED') NOT NULL,
  `sort_order` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `week_days`
--

INSERT INTO `week_days` (`day_id`, `day_name`, `sort_order`) VALUES
(1, 'SAT', 1),
(2, 'SUN', 2),
(3, 'MON', 3),
(4, 'TUE', 4),
(5, 'WED', 5);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_years`
--
ALTER TABLE `academic_years`
  ADD PRIMARY KEY (`year_id`),
  ADD UNIQUE KEY `year_name` (`year_name`);

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `fk_al_user` (`user_id`);

--
-- Indexes for table `admission_sequences`
--
ALTER TABLE `admission_sequences`
  ADD PRIMARY KEY (`year`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`session_id`,`enrollment_id`),
  ADD KEY `fk_ar_enroll` (`enrollment_id`);

--
-- Indexes for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD UNIQUE KEY `uq_att_sess` (`section_id`,`session_date`,`slot_id`,`subject_id`),
  ADD KEY `idx_att_teacher_date` (`teacher_id`,`session_date`),
  ADD KEY `fk_as_year` (`year_id`),
  ADD KEY `fk_as_day` (`day_id`),
  ADD KEY `fk_as_slot` (`slot_id`),
  ADD KEY `fk_as_subject` (`subject_id`);

--
-- Indexes for table `attendance_summary_cache`
--
ALTER TABLE `attendance_summary_cache`
  ADD PRIMARY KEY (`summary_id`),
  ADD UNIQUE KEY `uq_att_sum` (`enrollment_id`,`year_id`),
  ADD KEY `fk_asc_year` (`year_id`);

--
-- Indexes for table `content_blocks`
--
ALTER TABLE `content_blocks`
  ADD PRIMARY KEY (`block_key`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`employee_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `employee_documents`
--
ALTER TABLE `employee_documents`
  ADD PRIMARY KEY (`doc_id`),
  ADD KEY `fk_empdoc_emp` (`employee_id`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`enrollment_id`),
  ADD UNIQUE KEY `uq_student_year` (`student_id`,`year_id`),
  ADD KEY `idx_enr_section_year` (`section_id`,`year_id`),
  ADD KEY `fk_enr_year` (`year_id`);

--
-- Indexes for table `exams`
--
ALTER TABLE `exams`
  ADD PRIMARY KEY (`exam_id`),
  ADD KEY `fk_exam_year` (`year_id`),
  ADD KEY `fk_exam_term` (`term_id`);

--
-- Indexes for table `exam_papers`
--
ALTER TABLE `exam_papers`
  ADD PRIMARY KEY (`paper_id`),
  ADD UNIQUE KEY `uq_paper` (`exam_id`,`section_id`,`subject_id`),
  ADD KEY `fk_paper_section` (`section_id`),
  ADD KEY `fk_paper_subject` (`subject_id`);

--
-- Indexes for table `exam_terms`
--
ALTER TABLE `exam_terms`
  ADD PRIMARY KEY (`term_id`),
  ADD UNIQUE KEY `term_name` (`term_name`);

--
-- Indexes for table `fee_structures`
--
ALTER TABLE `fee_structures`
  ADD PRIMARY KEY (`fee_structure_id`),
  ADD UNIQUE KEY `uq_fee_structure` (`year_id`,`grade_id`,`fee_type_id`),
  ADD KEY `fk_fs_grade` (`grade_id`),
  ADD KEY `fk_fs_fee_type` (`fee_type_id`);

--
-- Indexes for table `fee_types`
--
ALTER TABLE `fee_types`
  ADD PRIMARY KEY (`fee_type_id`),
  ADD UNIQUE KEY `fee_type_name` (`fee_type_name`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`grade_id`),
  ADD UNIQUE KEY `grade_name` (`grade_name`),
  ADD KEY `fk_grade_level` (`level_id`);

--
-- Indexes for table `grade_system`
--
ALTER TABLE `grade_system`
  ADD PRIMARY KEY (`grade_sys_id`),
  ADD UNIQUE KEY `grade_label` (`grade_label`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `fk_item_inv` (`invoice_id`),
  ADD KEY `fk_item_fee` (`fee_type_id`);

--
-- Indexes for table `marks`
--
ALTER TABLE `marks`
  ADD PRIMARY KEY (`paper_id`,`enrollment_id`),
  ADD KEY `fk_mark_enr` (`enrollment_id`);

--
-- Indexes for table `parents`
--
ALTER TABLE `parents`
  ADD PRIMARY KEY (`parent_id`),
  ADD UNIQUE KEY `uq_parent_phone` (`phone`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `fk_pay_invoice` (`invoice_id`),
  ADD KEY `fk_pay_method` (`method_id`),
  ADD KEY `fk_pay_user` (`received_by`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`method_id`),
  ADD UNIQUE KEY `method_name` (`method_name`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`permission_id`),
  ADD UNIQUE KEY `permission_key` (`permission_key`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `fk_rp_perm` (`permission_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`room_id`),
  ADD UNIQUE KEY `room_name` (`room_name`);

--
-- Indexes for table `salary_payments`
--
ALTER TABLE `salary_payments`
  ADD PRIMARY KEY (`salary_payment_id`),
  ADD KEY `fk_sal_emp` (`employee_id`),
  ADD KEY `fk_sal_createdby` (`created_by`);

--
-- Indexes for table `school_levels`
--
ALTER TABLE `school_levels`
  ADD PRIMARY KEY (`level_id`),
  ADD UNIQUE KEY `level_name` (`level_name`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`section_id`),
  ADD UNIQUE KEY `uq_section` (`grade_id`,`section_name`);

--
-- Indexes for table `section_subjects`
--
ALTER TABLE `section_subjects`
  ADD PRIMARY KEY (`section_id`,`subject_id`),
  ADD KEY `fk_ss_subject` (`subject_id`);

--
-- Indexes for table `section_subject_details`
--
ALTER TABLE `section_subject_details`
  ADD PRIMARY KEY (`section_id`,`subject_id`),
  ADD KEY `fk_ssd_subject` (`subject_id`);

--
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`sms_log_id`),
  ADD KEY `fk_smslog_sms` (`sms_id`);

--
-- Indexes for table `sms_outbox`
--
ALTER TABLE `sms_outbox`
  ADD PRIMARY KEY (`sms_id`),
  ADD KEY `fk_sms_user` (`created_by`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `admission_no` (`admission_no`);

--
-- Indexes for table `student_documents`
--
ALTER TABLE `student_documents`
  ADD PRIMARY KEY (`doc_id`),
  ADD KEY `fk_studoc_stu` (`student_id`);

--
-- Indexes for table `student_invoices`
--
ALTER TABLE `student_invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `invoice_no` (`invoice_no`),
  ADD KEY `fk_inv_enr` (`enrollment_id`),
  ADD KEY `fk_inv_createdby` (`created_by`);

--
-- Indexes for table `student_parents`
--
ALTER TABLE `student_parents`
  ADD PRIMARY KEY (`student_id`,`parent_id`),
  ADD KEY `fk_sp_parent` (`parent_id`);

--
-- Indexes for table `student_result_summary`
--
ALTER TABLE `student_result_summary`
  ADD PRIMARY KEY (`summary_id`),
  ADD UNIQUE KEY `uq_exam_student` (`exam_id`,`enrollment_id`),
  ADD KEY `fk_srs_enr` (`enrollment_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`subject_id`),
  ADD UNIQUE KEY `subject_name` (`subject_name`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`teacher_id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`);

--
-- Indexes for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  ADD PRIMARY KEY (`teacher_id`,`subject_name`);

--
-- Indexes for table `timetables`
--
ALTER TABLE `timetables`
  ADD PRIMARY KEY (`timetable_id`),
  ADD UNIQUE KEY `uq_tt_year_section` (`year_id`,`section_id`),
  ADD KEY `fk_tt_section` (`section_id`);

--
-- Indexes for table `timetable_entries`
--
ALTER TABLE `timetable_entries`
  ADD PRIMARY KEY (`entry_id`),
  ADD UNIQUE KEY `uq_tt_slot` (`timetable_id`,`day_id`,`slot_id`),
  ADD KEY `idx_teacher_day` (`teacher_id`,`day_id`,`slot_id`),
  ADD KEY `fk_tte_day` (`day_id`),
  ADD KEY `fk_tte_slot` (`slot_id`),
  ADD KEY `fk_tte_subject` (`subject_id`),
  ADD KEY `fk_tte_room` (`room_id`);

--
-- Indexes for table `time_slots`
--
ALTER TABLE `time_slots`
  ADD PRIMARY KEY (`slot_id`),
  ADD UNIQUE KEY `uq_slot` (`slot_no`,`start_time`,`end_time`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_user_permission` (`user_id`,`permission_id`),
  ADD KEY `idx_up_user` (`user_id`),
  ADD KEY `idx_up_permission` (`permission_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `fk_ur_role` (`role_id`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `fk_us_user` (`user_id`);

--
-- Indexes for table `week_days`
--
ALTER TABLE `week_days`
  ADD PRIMARY KEY (`day_id`),
  ADD UNIQUE KEY `day_name` (`day_name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_years`
--
ALTER TABLE `academic_years`
  MODIFY `year_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `log_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  MODIFY `session_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `attendance_summary_cache`
--
ALTER TABLE `attendance_summary_cache`
  MODIFY `summary_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `employee_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `employee_documents`
--
ALTER TABLE `employee_documents`
  MODIFY `doc_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `enrollment_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `exams`
--
ALTER TABLE `exams`
  MODIFY `exam_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `exam_papers`
--
ALTER TABLE `exam_papers`
  MODIFY `paper_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `exam_terms`
--
ALTER TABLE `exam_terms`
  MODIFY `term_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `fee_structures`
--
ALTER TABLE `fee_structures`
  MODIFY `fee_structure_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `fee_types`
--
ALTER TABLE `fee_types`
  MODIFY `fee_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `grade_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `grade_system`
--
ALTER TABLE `grade_system`
  MODIFY `grade_sys_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `item_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `parents`
--
ALTER TABLE `parents`
  MODIFY `parent_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `method_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `permission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `room_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `salary_payments`
--
ALTER TABLE `salary_payments`
  MODIFY `salary_payment_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `school_levels`
--
ALTER TABLE `school_levels`
  MODIFY `level_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `section_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `sms_log_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sms_outbox`
--
ALTER TABLE `sms_outbox`
  MODIFY `sms_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `student_documents`
--
ALTER TABLE `student_documents`
  MODIFY `doc_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_invoices`
--
ALTER TABLE `student_invoices`
  MODIFY `invoice_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `student_result_summary`
--
ALTER TABLE `student_result_summary`
  MODIFY `summary_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `subject_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `teacher_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `timetables`
--
ALTER TABLE `timetables`
  MODIFY `timetable_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `timetable_entries`
--
ALTER TABLE `timetable_entries`
  MODIFY `entry_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=212;

--
-- AUTO_INCREMENT for table `time_slots`
--
ALTER TABLE `time_slots`
  MODIFY `slot_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `session_id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `week_days`
--
ALTER TABLE `week_days`
  MODIFY `day_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD CONSTRAINT `fk_ar_enroll` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`enrollment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ar_session` FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions` (`session_id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD CONSTRAINT `fk_as_day` FOREIGN KEY (`day_id`) REFERENCES `week_days` (`day_id`),
  ADD CONSTRAINT `fk_as_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`),
  ADD CONSTRAINT `fk_as_slot` FOREIGN KEY (`slot_id`) REFERENCES `time_slots` (`slot_id`),
  ADD CONSTRAINT `fk_as_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`),
  ADD CONSTRAINT `fk_as_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`),
  ADD CONSTRAINT `fk_as_year` FOREIGN KEY (`year_id`) REFERENCES `academic_years` (`year_id`);

--
-- Constraints for table `attendance_summary_cache`
--
ALTER TABLE `attendance_summary_cache`
  ADD CONSTRAINT `fk_asc_enr` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`enrollment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_asc_year` FOREIGN KEY (`year_id`) REFERENCES `academic_years` (`year_id`);

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_emp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `employee_documents`
--
ALTER TABLE `employee_documents`
  ADD CONSTRAINT `fk_empdoc_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `fk_enr_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`),
  ADD CONSTRAINT `fk_enr_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_enr_year` FOREIGN KEY (`year_id`) REFERENCES `academic_years` (`year_id`);

--
-- Constraints for table `exams`
--
ALTER TABLE `exams`
  ADD CONSTRAINT `fk_exam_term` FOREIGN KEY (`term_id`) REFERENCES `exam_terms` (`term_id`),
  ADD CONSTRAINT `fk_exam_year` FOREIGN KEY (`year_id`) REFERENCES `academic_years` (`year_id`);

--
-- Constraints for table `exam_papers`
--
ALTER TABLE `exam_papers`
  ADD CONSTRAINT `fk_paper_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`exam_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_paper_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`),
  ADD CONSTRAINT `fk_paper_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`);

--
-- Constraints for table `fee_structures`
--
ALTER TABLE `fee_structures`
  ADD CONSTRAINT `fk_fs_fee_type` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`fee_type_id`),
  ADD CONSTRAINT `fk_fs_grade` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`grade_id`),
  ADD CONSTRAINT `fk_fs_year` FOREIGN KEY (`year_id`) REFERENCES `academic_years` (`year_id`);

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `fk_grade_level` FOREIGN KEY (`level_id`) REFERENCES `school_levels` (`level_id`);

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_item_fee` FOREIGN KEY (`fee_type_id`) REFERENCES `fee_types` (`fee_type_id`),
  ADD CONSTRAINT `fk_item_inv` FOREIGN KEY (`invoice_id`) REFERENCES `student_invoices` (`invoice_id`) ON DELETE CASCADE;

--
-- Constraints for table `marks`
--
ALTER TABLE `marks`
  ADD CONSTRAINT `fk_mark_enr` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`enrollment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_mark_paper` FOREIGN KEY (`paper_id`) REFERENCES `exam_papers` (`paper_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_pay_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `student_invoices` (`invoice_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pay_method` FOREIGN KEY (`method_id`) REFERENCES `payment_methods` (`method_id`),
  ADD CONSTRAINT `fk_pay_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE;

--
-- Constraints for table `salary_payments`
--
ALTER TABLE `salary_payments`
  ADD CONSTRAINT `fk_sal_createdby` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sal_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `sections`
--
ALTER TABLE `sections`
  ADD CONSTRAINT `fk_section_grade` FOREIGN KEY (`grade_id`) REFERENCES `grades` (`grade_id`);

--
-- Constraints for table `section_subjects`
--
ALTER TABLE `section_subjects`
  ADD CONSTRAINT `fk_ss_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ss_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE;

--
-- Constraints for table `section_subject_details`
--
ALTER TABLE `section_subject_details`
  ADD CONSTRAINT `fk_ssd_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ssd_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE;

--
-- Constraints for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD CONSTRAINT `fk_smslog_sms` FOREIGN KEY (`sms_id`) REFERENCES `sms_outbox` (`sms_id`) ON DELETE CASCADE;

--
-- Constraints for table `sms_outbox`
--
ALTER TABLE `sms_outbox`
  ADD CONSTRAINT `fk_sms_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_student_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `student_documents`
--
ALTER TABLE `student_documents`
  ADD CONSTRAINT `fk_studoc_stu` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE;

--
-- Constraints for table `student_invoices`
--
ALTER TABLE `student_invoices`
  ADD CONSTRAINT `fk_inv_createdby` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inv_enr` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`enrollment_id`) ON DELETE CASCADE;

--
-- Constraints for table `student_parents`
--
ALTER TABLE `student_parents`
  ADD CONSTRAINT `fk_sp_parent` FOREIGN KEY (`parent_id`) REFERENCES `parents` (`parent_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sp_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE;

--
-- Constraints for table `student_result_summary`
--
ALTER TABLE `student_result_summary`
  ADD CONSTRAINT `fk_srs_enr` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`enrollment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_srs_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`exam_id`) ON DELETE CASCADE;

--
-- Constraints for table `teachers`
--
ALTER TABLE `teachers`
  ADD CONSTRAINT `fk_teacher_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `teacher_subjects`
--
ALTER TABLE `teacher_subjects`
  ADD CONSTRAINT `fk_ts_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE;

--
-- Constraints for table `timetables`
--
ALTER TABLE `timetables`
  ADD CONSTRAINT `fk_tt_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tt_year` FOREIGN KEY (`year_id`) REFERENCES `academic_years` (`year_id`);

--
-- Constraints for table `timetable_entries`
--
ALTER TABLE `timetable_entries`
  ADD CONSTRAINT `fk_tte_day` FOREIGN KEY (`day_id`) REFERENCES `week_days` (`day_id`),
  ADD CONSTRAINT `fk_tte_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`room_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_tte_slot` FOREIGN KEY (`slot_id`) REFERENCES `time_slots` (`slot_id`),
  ADD CONSTRAINT `fk_tte_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`),
  ADD CONSTRAINT `fk_tte_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`),
  ADD CONSTRAINT `fk_tte_tt` FOREIGN KEY (`timetable_id`) REFERENCES `timetables` (`timetable_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `fk_us_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
