<?php
require_once '../includes/db.php';
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['student_id'])) {
    header("Location: sf10_form.php?error=" . urlencode("Invalid request"));
    exit();
}

$student_id = (int)$_POST['student_id'];

// Get current user's school information (as default/fallback)
$current_user = $_SESSION['user'];
$user_query = "SELECT school_name, school_id, district, division, region FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $current_user['id']);
$stmt->execute();
$user_school_info = $stmt->get_result()->fetch_assoc();

// Get student information
$student_query = "SELECT * FROM students WHERE id = ?";
$stmt = $conn->prepare($student_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    header("Location: sf10_form.php?error=" . urlencode("Student not found"));
    exit();
}

// Get all school records for this student (all grade levels)
$schools_query = "SELECT * FROM schools_attended 
                  WHERE student_id = ?
                  ORDER BY grade_level ASC, school_year ASC";
$stmt = $conn->prepare($schools_query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$schools_result = $stmt->get_result();

$school_records = [];
while ($school = $schools_result->fetch_assoc()) {
    $school_records[] = $school;
}

// Get all grades for this student
$all_grades = [];
$all_remedial = [];
foreach ($school_records as $school) {
    // Get the most recent grade for each subject/quarter combination using MAX(id)
    $grades_query = "SELECT g.*, s.subject_name, s.id as subject_id
                     FROM grades g
                     INNER JOIN (
                         SELECT subject_id, quarter, MAX(id) as max_id
                         FROM grades
                         WHERE student_id = ? 
                         AND school_attended_id = ?
                         GROUP BY subject_id, quarter
                     ) AS latest ON g.id = latest.max_id
                     JOIN subjects s ON g.subject_id = s.id
                     WHERE s.subject_name != 'General Average'
                     ORDER BY s.id, g.quarter";
    $stmt = $conn->prepare($grades_query);
    $stmt->bind_param("ii", $student_id, $school['id']);
    $stmt->execute();
    $grades_result = $stmt->get_result();
    
    $key = $school['grade_level'] . '_' . $school['school_year'];
    $all_grades[$key] = [
        'school' => $school,
        'grades' => [],
        'general_average' => ['q1' => '', 'q2' => '', 'q3' => '', 'q4' => '', 'final_rating' => '', 'remarks' => '']
    ];
    
    while ($grade = $grades_result->fetch_assoc()) {
        $sid = $grade['subject_id'];
        $q = $grade['quarter'];
        
        if (!isset($all_grades[$key]['grades'][$sid])) {
            $all_grades[$key]['grades'][$sid] = [
                'subject_name' => $grade['subject_name'],
                'q1' => '', 'q2' => '', 'q3' => '', 'q4' => '',
                'final_rating' => '', 'remarks' => ''
            ];
        }
        
        // Store the grade for this quarter
        $all_grades[$key]['grades'][$sid]['q' . $q] = $grade['grade'];
        
        // Always update final_rating and remarks from the last row processed
        $all_grades[$key]['grades'][$sid]['final_rating'] = $grade['final_rating'] ?? '';
        $all_grades[$key]['grades'][$sid]['remarks'] = $grade['remarks'] ?? '';
    }
    
    // Get General Average
    $gen_avg_query = "SELECT g.*
                      FROM grades g
                      INNER JOIN (
                          SELECT quarter, MAX(id) as max_id
                          FROM grades
                          WHERE student_id = ? 
                          AND school_attended_id = ?
                          AND subject_id = (SELECT id FROM subjects WHERE subject_name = 'General Average' LIMIT 1)
                          GROUP BY quarter
                      ) AS latest ON g.id = latest.max_id
                      ORDER BY g.quarter";
    $stmt = $conn->prepare($gen_avg_query);
    $stmt->bind_param("ii", $student_id, $school['id']);
    $stmt->execute();
    $gen_avg_result = $stmt->get_result();
    
    while ($avg = $gen_avg_result->fetch_assoc()) {
        $q = $avg['quarter'];
        $all_grades[$key]['general_average']['q' . $q] = $avg['grade'];
        $all_grades[$key]['general_average']['final_rating'] = $avg['final_rating'] ?? '';
        $all_grades[$key]['general_average']['remarks'] = $avg['remarks'] ?? '';
    }
    
    // Get Remedial Classes
    $remedial_query = "SELECT rc.*
                       FROM remedial_classes rc
                       WHERE rc.student_id = ?
                       AND rc.school_year = ?
                       ORDER BY rc.id";
    $stmt = $conn->prepare($remedial_query);
    $stmt->bind_param("is", $student_id, $school['school_year']);
    $stmt->execute();
    $remedial_result = $stmt->get_result();
    
    $all_remedial[$key] = [];
    while ($remedial = $remedial_result->fetch_assoc()) {
        $all_remedial[$key][] = [
            'subject_name' => $remedial['learning_area'],
            'final_rating' => $remedial['final_rating'],
            'remedial_mark' => $remedial['remedial_class_mark'],
            'recomputed_grade' => $remedial['recomputed_final_grade'],
            'remarks' => $remedial['remarks'],
            'date_from' => $remedial['conducted_from'],
            'date_to' => $remedial['conducted_to']
        ];
    }
}

// Load PHPSpreadsheet
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// Function to get subject name for a specific student (same as sf10_preview.php)
function getSubjectNameForStudent($conn, $subject_id, $student_id, $school_attended_id) {
    // First, determine if this is a transfer student
    $is_transfer = false;
    $grade_level_raw = null;
    
    $school_info = $conn->query("SELECT grade_level, is_transfer FROM schools_attended WHERE id = $school_attended_id");
    if ($school_info && $school_info->num_rows > 0) {
        $school_data = $school_info->fetch_assoc();
        $grade_level_raw = $school_data['grade_level'];
        $is_transfer = intval($school_data['is_transfer'] ?? 0) === 1;
    }

    // Extract numeric grade level for lookup (e.g., "Grade 1" -> 1, "IV" -> 4)
    $grade_level_num = null;
    if ($grade_level_raw) {
        if (preg_match('/(\d+)/', $grade_level_raw, $m)) {
            $grade_level_num = intval($m[1]);
        } else {
            // Roman numeral fallback for "IV", "V", "VI"
            $roman_map = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6];
            $clean_grade = strtoupper(trim($grade_level_raw));
            if (isset($roman_map[$clean_grade])) {
                $grade_level_num = $roman_map[$clean_grade];
            }
        }
    }
    
    // First check if there's a custom subject name for this transfer student
    $table_check = $conn->query("SHOW TABLES LIKE 'student_custom_subjects'");
    if ($table_check && $table_check->num_rows > 0) {
        $custom_query = $conn->query("SELECT custom_subject_name 
                                      FROM student_custom_subjects 
                                      WHERE student_id = $student_id 
                                      AND school_attended_id = $school_attended_id 
                                      AND subject_id = $subject_id");
        if ($custom_query && $custom_query->num_rows > 0) {
            $custom_result = $custom_query->fetch_assoc();
            // Return the custom name (even if empty) to respect user override
            return $custom_result['custom_subject_name'];
        }
    }
    
    // IMPORTANT: Only use grade-level config for regular students (non-transfer)
    if (!$is_transfer && $grade_level_num) {
        $table_check = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'");
        
        if ($table_check && $table_check->num_rows > 0) {
            $group_query = $conn->query("SELECT subject_name 
                                         FROM subject_grade_groups 
                                         WHERE grade_level = $grade_level_num 
                                         AND subject_id = $subject_id");
            if ($group_query && $group_query->num_rows > 0) {
                $group_result = $group_query->fetch_assoc();
                // Return the alias name (even if empty) to respect grade-level configuration
                return $group_result['subject_name'];
            }
        }
    }
    
    // Fall back to default subject name
    $default_query = $conn->query("SELECT subject_name FROM subjects WHERE id = $subject_id");
    if ($default_query && $default_query->num_rows > 0) {
        $default_result = $default_query->fetch_assoc();
        return $default_result['subject_name'];
    }
    
    return 'Unknown Subject';
}

try {
    // Load the template
    $templatePath = '../SF10_ELEMENTARY_2017.xlsx';
    
    if (!file_exists($templatePath)) {
        throw new Exception("SF10 template file not found");
    }
    
    $spreadsheet = IOFactory::load($templatePath);
    
    // FRONT PAGE (Sheet 1)
    $sheet = $spreadsheet->getSheet(0);
    $sheet->setTitle('Front');
    
    // Fill in student personal information (Row 9)
    $sheet->setCellValue('E9', strtoupper($student['last_name'])); // Last Name
    $sheet->setCellValue('R9', strtoupper($student['first_name'])); // First Name
    $sheet->setCellValue('AD9', ''); // Name Extension (Jr, I, II) - not in database
    $sheet->setCellValue('AQ9', strtoupper($student['middle_name'] ?? '')); // Middle Name
    
    // Row 10 - LRN, Birthdate, Sex
    $sheet->setCellValue('J10', $student['lrn']); // LRN
    $sheet->setCellValue('V10', date('m/d/Y', strtotime($student['birthdate']))); // Birthdate
    $sheet->setCellValue('AT10', strtoupper($student['gender'])); // Sex
    
    // ELIGIBILITY FOR ELEMENTARY SCHOOL ENROLLMENT (Row 15)
    // Leave blank - school will fill this information manually
    $sheet->setCellValue('F15', ''); // Name of School - to be filled by school
    $sheet->setCellValue('T15', ''); // School ID - to be filled by school
    $sheet->setCellValue('Z15', ''); // Address of School - to be filled by school
    
    // OTHER CREDENTIAL PRESENTED section (Row 18-19)
    $sheet->setCellValue('J18', ''); // PEPT Passer Rating
    $sheet->setCellValue('W18', ''); // Date of Examination/Assessment
    $sheet->setCellValue('AQ18', ''); // Others (Pls. Specify)
    $sheet->setCellValue('L19', ''); // Name and Address of Testing Center
    $sheet->setCellValue('AJ19', ''); // Remark
    
    // Get first 4 grade levels for FRONT page (Boxes 1-4)
    $front_records = array_slice($school_records, 0, 4);
    $back_records = array_slice($school_records, 4, 4);
    
    // BOX 1 - GRADE 1 (Top-Left) - Row 23
    if (isset($front_records[0])) {
        $record = $front_records[0];
        // Use user's school info as DEFAULT, override with school record if explicitly set (for transfer students)
        $sheet->setCellValue('D23', !empty($record['school_name']) ? $record['school_name'] : ($user_school_info['school_name'] ?? '')); // School
        $sheet->setCellValue('S23', !empty($record['school_id']) ? $record['school_id'] : ($user_school_info['school_id'] ?? '')); // School ID
        $sheet->setCellValue('D24', !empty($record['district']) ? $record['district'] : ($user_school_info['district'] ?? '')); // District
        $sheet->setCellValue('I24', !empty($record['division']) ? $record['division'] : ($user_school_info['division'] ?? '')); // Division
        $sheet->setCellValue('T24', !empty($record['region']) ? $record['region'] : ($user_school_info['region'] ?? '')); // Region
        $sheet->setCellValue('F25', $record['grade_level']); // Classified as Grade
        $sheet->setCellValue('J25', $record['section'] ?? ''); // Section
        $sheet->setCellValue('S25', $record['school_year']); // School Year
        $sheet->setCellValue('H26', $record['adviser_name'] ?? ''); // Name of Adviser/Teacher
        
        // Get grades for this grade level
        $key = $record['grade_level'] . '_' . $record['school_year'];
        if (isset($all_grades[$key])) {
            $grades = $all_grades[$key]['grades'];
            
            // Get all subjects from database in order (same as preview)
            $subjects_query = $conn->query("SELECT id, subject_name FROM subjects WHERE subject_name != 'General Average' ORDER BY display_order ASC, id ASC");
            $row = 30; // Start at row 30
            
            while ($subject = $subjects_query->fetch_assoc()) {
                $sid = $subject['id'];
                
                // Get the proper subject name for this student/school (same as preview)
                $display_name = getSubjectNameForStudent($conn, $sid, $student_id, $record['id']);
                
                // Write subject name with Arial Narrow font size 11
                $sheet->setCellValue('B' . $row, $display_name);
                $sheet->getStyle('B' . $row)->getFont()->setName('Arial Narrow')->setSize(11);
                
                // Get grades for this subject (same as preview)
                $g = $grades[$sid] ?? null;
                
                if ($g) {
                    // Write grades exactly as shown in preview
                    $sheet->setCellValue('K' . $row, ($g['q1'] !== '' && $g['q1'] !== null) ? round($g['q1']) : ''); // Q1
                    $sheet->setCellValue('L' . $row, ($g['q2'] !== '' && $g['q2'] !== null) ? round($g['q2']) : ''); // Q2
                    $sheet->setCellValue('N' . $row, ($g['q3'] !== '' && $g['q3'] !== null) ? round($g['q3']) : ''); // Q3
                    $sheet->setCellValue('O' . $row, ($g['q4'] !== '' && $g['q4'] !== null) ? round($g['q4']) : ''); // Q4
                    $sheet->setCellValue('P' . $row, ($g['final_rating'] !== '' && $g['final_rating'] !== null) ? round($g['final_rating']) : ''); // Final Rating
                    $sheet->setCellValue('S' . $row, ($g['remarks'] !== '' && $g['remarks'] !== null) ? $g['remarks'] : ''); // Remarks
                }
                
                $row++;
                if ($row > 44) break; // Stop at row 44 (last subject row before General Average)
            }
            
            // Add General Average at row 45 (same as preview)
            $gen_avg = $all_grades[$key]['general_average'] ?? null;
            if ($gen_avg) {
                $sheet->setCellValue('K45', ($gen_avg['q1'] !== '' && $gen_avg['q1'] !== null) ? round($gen_avg['q1']) : '');
                $sheet->setCellValue('L45', ($gen_avg['q2'] !== '' && $gen_avg['q2'] !== null) ? round($gen_avg['q2']) : '');
                $sheet->setCellValue('N45', ($gen_avg['q3'] !== '' && $gen_avg['q3'] !== null) ? round($gen_avg['q3']) : '');
                $sheet->setCellValue('O45', ($gen_avg['q4'] !== '' && $gen_avg['q4'] !== null) ? round($gen_avg['q4']) : '');
                $sheet->setCellValue('P45', ($gen_avg['final_rating'] !== '' && $gen_avg['final_rating'] !== null) ? round($gen_avg['final_rating']) : '');
                $sheet->setCellValue('S45', ($gen_avg['remarks'] !== '' && $gen_avg['remarks'] !== null) ? $gen_avg['remarks'] : '');
            }
            
            // Add Remedial Classes (rows 49-50)
            if (isset($all_remedial[$key]) && count($all_remedial[$key]) > 0) {
                $remedial_row = 49;
                $date_written = false;
                
                foreach ($all_remedial[$key] as $index => $remedial) {
                    if ($index >= 2) break; // Only 2 remedial rows available
                    
                    // Write date range only once in G47
                    if (!$date_written && !empty($remedial['date_from']) && !empty($remedial['date_to'])) {
                        $date_range = date('m/d/Y', strtotime($remedial['date_from'])) . ' to ' . date('m/d/Y', strtotime($remedial['date_to']));
                        $sheet->setCellValue('G47', $date_range);
                        $date_written = true;
                    }
                    
                    // Write remedial subject name with Arial Narrow font size 11
                    $sheet->setCellValue('B' . $remedial_row, $remedial['subject_name']);
                    $sheet->getStyle('B' . $remedial_row)->getFont()->setName('Arial Narrow')->setSize(11);
                    
                    // Write remedial grades
                    $sheet->setCellValue('G' . $remedial_row, !empty($remedial['final_rating']) ? round($remedial['final_rating']) : ''); // Final Rating
                    $sheet->setCellValue('K' . $remedial_row, !empty($remedial['remedial_mark']) ? round($remedial['remedial_mark']) : ''); // Remedial Class Mark
                    $sheet->setCellValue('O' . $remedial_row, !empty($remedial['recomputed_grade']) ? round($remedial['recomputed_grade']) : ''); // Recomputed Final Grade
                    $sheet->setCellValue('S' . $remedial_row, $remedial['remarks'] ?? ''); // Remarks
                    
                    $remedial_row++;
                }
            }
        }
    }
    
        // BOX 2 - GRADE 2 (Top-Right)
        if (isset($front_records[1])) {
            $record = $front_records[1];
            $sheet->setCellValue('K23', !empty($record['school_name']) ? $record['school_name'] : ($user_school_info['school_name'] ?? '')); // School
            $sheet->setCellValue('N23', !empty($record['school_id']) ? $record['school_id'] : ($user_school_info['school_id'] ?? '')); // School ID
            $sheet->setCellValue('K24', !empty($record['district']) ? $record['district'] : ($user_school_info['district'] ?? '')); // District
            $sheet->setCellValue('N24', !empty($record['division']) ? $record['division'] : ($user_school_info['division'] ?? '')); // Division
            $sheet->setCellValue('K25', $record['grade_level']); // Grade
            $sheet->setCellValue('N25', $record['section'] ?? ''); // Section
            $sheet->setCellValue('K26', $record['adviser_name'] ?? ''); // Adviser
            
            // Get grades for this grade level
            $key = $record['grade_level'] . '_' . $record['school_year'];
            if (isset($all_grades[$key])) {
                $grades = $all_grades[$key]['grades'];
                
                // Get all subjects from database in order (same as preview)
                $subjects_query = $conn->query("SELECT id, subject_name FROM subjects WHERE subject_name != 'General Average' ORDER BY display_order ASC, id ASC");
                $row = 30; // Start at row 30
                
                while ($subject = $subjects_query->fetch_assoc()) {
                    $sid = $subject['id'];
                    $display_name = getSubjectNameForStudent($conn, $sid, $student_id, $record['id']);
                    
                    // Write subject name with Arial Narrow font size 11
                    $sheet->setCellValue('I' . $row, $display_name);
                    $sheet->getStyle('I' . $row)->getFont()->setName('Arial Narrow')->setSize(11);
                    
                    $g = $grades[$sid] ?? null;
                    if ($g) {
                        $sheet->setCellValue('L' . $row, ($g['q1'] !== '' && $g['q1'] !== null) ? round($g['q1']) : '');
                        $sheet->setCellValue('M' . $row, ($g['q2'] !== '' && $g['q2'] !== null) ? round($g['q2']) : '');
                        $sheet->setCellValue('N' . $row, ($g['q3'] !== '' && $g['q3'] !== null) ? round($g['q3']) : '');
                        $sheet->setCellValue('O' . $row, ($g['q4'] !== '' && $g['q4'] !== null) ? round($g['q4']) : '');
                        $sheet->setCellValue('P' . $row, ($g['final_rating'] !== '' && $g['final_rating'] !== null) ? round($g['final_rating']) : '');
                        $sheet->setCellValue('Q' . $row, ($g['remarks'] !== '' && $g['remarks'] !== null) ? $g['remarks'] : '');
                    }
                    
                    $row++;
                    if ($row > 44) break;
                }
                
                // General Average for Box 2
                $gen_avg = $all_grades[$key]['general_average'] ?? null;
                if ($gen_avg) {
                    $sheet->setCellValue('L45', ($gen_avg['q1'] !== '' && $gen_avg['q1'] !== null) ? round($gen_avg['q1']) : '');
                    $sheet->setCellValue('M45', ($gen_avg['q2'] !== '' && $gen_avg['q2'] !== null) ? round($gen_avg['q2']) : '');
                    $sheet->setCellValue('N45', ($gen_avg['q3'] !== '' && $gen_avg['q3'] !== null) ? round($gen_avg['q3']) : '');
                    $sheet->setCellValue('O45', ($gen_avg['q4'] !== '' && $gen_avg['q4'] !== null) ? round($gen_avg['q4']) : '');
                    $sheet->setCellValue('P45', ($gen_avg['final_rating'] !== '' && $gen_avg['final_rating'] !== null) ? round($gen_avg['final_rating']) : '');
                    $sheet->setCellValue('Q45', ($gen_avg['remarks'] !== '' && $gen_avg['remarks'] !== null) ? $gen_avg['remarks'] : '');
                }
            }
        }
    }
    
    // BACK PAGE (Sheet 2) - Box 3 and Box 4
    if (count($spreadsheet->getAllSheets()) > 1) {
        $sheetBack = $spreadsheet->getSheet(1);
        $sheetBack->setTitle('Back');
        
        // Copy student info to back page
        $sheetBack->setCellValue('B9', strtoupper($student['last_name']));
        $sheetBack->setCellValue('E9', strtoupper($student['first_name']));
        $sheetBack->setCellValue('U9', strtoupper($student['middle_name'] ?? ''));
        $sheetBack->setCellValue('B10', $student['lrn']);
        
        // BOX 3 - GRADE 3 (Bottom-Left)
        if (isset($back_records[0])) {
            $record = $back_records[0];
            $sheetBack->setCellValue('B23', !empty($record['school_name']) ? $record['school_name'] : ($user_school_info['school_name'] ?? ''));
            $sheetBack->setCellValue('E23', !empty($record['school_id']) ? $record['school_id'] : ($user_school_info['school_id'] ?? ''));
            $sheetBack->setCellValue('B24', !empty($record['district']) ? $record['district'] : ($user_school_info['district'] ?? ''));
            $sheetBack->setCellValue('E24', !empty($record['division']) ? $record['division'] : ($user_school_info['division'] ?? ''));
            $sheetBack->setCellValue('B25', $record['grade_level']);
            $sheetBack->setCellValue('E25', $record['section'] ?? '');
            $sheetBack->setCellValue('B26', $record['adviser_name'] ?? '');
            
            $key = $record['grade_level'] . '_' . $record['school_year'];
            if (isset($all_grades[$key])) {
                $grades = $all_grades[$key]['grades'];
                
                // Get all subjects
                $subjects_query = $conn->query("SELECT id, subject_name FROM subjects WHERE subject_name != 'General Average' ORDER BY display_order ASC, id ASC");
                $row = 29;
                
                while ($subject = $subjects_query->fetch_assoc()) {
                    $sid = $subject['id'];
                    $display_name = getSubjectNameForStudent($conn, $sid, $student_id, $record['id']);
                    
                    $sheetBack->setCellValue('B' . $row, $display_name);
                    $sheetBack->getStyle('B' . $row)->getFont()->setName('Arial Narrow')->setSize(11);
                    
                    $g = $grades[$sid] ?? null;
                    if ($g) {
                        $sheetBack->setCellValue('C' . $row, ($g['q1'] !== '' && $g['q1'] !== null) ? round($g['q1']) : '');
                        $sheetBack->setCellValue('D' . $row, ($g['q2'] !== '' && $g['q2'] !== null) ? round($g['q2']) : '');
                        $sheetBack->setCellValue('E' . $row, ($g['q3'] !== '' && $g['q3'] !== null) ? round($g['q3']) : '');
                        $sheetBack->setCellValue('F' . $row, ($g['q4'] !== '' && $g['q4'] !== null) ? round($g['q4']) : '');
                        $sheetBack->setCellValue('G' . $row, ($g['final_rating'] !== '' && $g['final_rating'] !== null) ? round($g['final_rating']) : '');
                        $sheetBack->setCellValue('H' . $row, ($g['remarks'] !== '' && $g['remarks'] !== null) ? $g['remarks'] : '');
                    }
                    
                    $row++;
                    if ($row > 43) break;
                }
                
                // General Average for Box 3
                $gen_avg = $all_grades[$key]['general_average'] ?? null;
                if ($gen_avg) {
                    $sheetBack->setCellValue('C44', ($gen_avg['q1'] !== '' && $gen_avg['q1'] !== null) ? round($gen_avg['q1']) : '');
                    $sheetBack->setCellValue('D44', ($gen_avg['q2'] !== '' && $gen_avg['q2'] !== null) ? round($gen_avg['q2']) : '');
                    $sheetBack->setCellValue('E44', ($gen_avg['q3'] !== '' && $gen_avg['q3'] !== null) ? round($gen_avg['q3']) : '');
                    $sheetBack->setCellValue('F44', ($gen_avg['q4'] !== '' && $gen_avg['q4'] !== null) ? round($gen_avg['q4']) : '');
                    $sheetBack->setCellValue('G44', ($gen_avg['final_rating'] !== '' && $gen_avg['final_rating'] !== null) ? round($gen_avg['final_rating']) : '');
                    $sheetBack->setCellValue('H44', ($gen_avg['remarks'] !== '' && $gen_avg['remarks'] !== null) ? $gen_avg['remarks'] : '');
                }
            }
        }
        
        // BOX 4 - GRADE 4 (Bottom-Right)
        if (isset($back_records[1])) {
            $record = $back_records[1];
            $sheetBack->setCellValue('K23', !empty($record['school_name']) ? $record['school_name'] : ($user_school_info['school_name'] ?? ''));
            $sheetBack->setCellValue('N23', !empty($record['school_id']) ? $record['school_id'] : ($user_school_info['school_id'] ?? ''));
            $sheetBack->setCellValue('K24', !empty($record['district']) ? $record['district'] : ($user_school_info['district'] ?? ''));
            $sheetBack->setCellValue('N24', !empty($record['division']) ? $record['division'] : ($user_school_info['division'] ?? ''));
            $sheetBack->setCellValue('K25', $record['grade_level']);
            $sheetBack->setCellValue('N25', $record['section'] ?? '');
            $sheetBack->setCellValue('K26', $record['adviser_name'] ?? '');
            
            $key = $record['grade_level'] . '_' . $record['school_year'];
            if (isset($all_grades[$key])) {
                $grades = $all_grades[$key]['grades'];
                
                // Get all subjects
                $subjects_query = $conn->query("SELECT id, subject_name FROM subjects WHERE subject_name != 'General Average' ORDER BY display_order ASC, id ASC");
                $row = 29;
                
                while ($subject = $subjects_query->fetch_assoc()) {
                    $sid = $subject['id'];
                    $display_name = getSubjectNameForStudent($conn, $sid, $student_id, $record['id']);
                    
                    $sheetBack->setCellValue('I' . $row, $display_name);
                    $sheetBack->getStyle('I' . $row)->getFont()->setName('Arial Narrow')->setSize(11);
                    
                    $g = $grades[$sid] ?? null;
                    if ($g) {
                        $sheetBack->setCellValue('L' . $row, ($g['q1'] !== '' && $g['q1'] !== null) ? round($g['q1']) : '');
                        $sheetBack->setCellValue('M' . $row, ($g['q2'] !== '' && $g['q2'] !== null) ? round($g['q2']) : '');
                        $sheetBack->setCellValue('N' . $row, ($g['q3'] !== '' && $g['q3'] !== null) ? round($g['q3']) : '');
                        $sheetBack->setCellValue('O' . $row, ($g['q4'] !== '' && $g['q4'] !== null) ? round($g['q4']) : '');
                        $sheetBack->setCellValue('P' . $row, ($g['final_rating'] !== '' && $g['final_rating'] !== null) ? round($g['final_rating']) : '');
                        $sheetBack->setCellValue('Q' . $row, ($g['remarks'] !== '' && $g['remarks'] !== null) ? $g['remarks'] : '');
                    }
                    
                    $row++;
                    if ($row > 43) break;
                }
                
                // General Average for Box 4
                $gen_avg = $all_grades[$key]['general_average'] ?? null;
                if ($gen_avg) {
                    $sheetBack->setCellValue('L44', ($gen_avg['q1'] !== '' && $gen_avg['q1'] !== null) ? round($gen_avg['q1']) : '');
                    $sheetBack->setCellValue('M44', ($gen_avg['q2'] !== '' && $gen_avg['q2'] !== null) ? round($gen_avg['q2']) : '');
                    $sheetBack->setCellValue('N44', ($gen_avg['q3'] !== '' && $gen_avg['q3'] !== null) ? round($gen_avg['q3']) : '');
                    $sheetBack->setCellValue('O44', ($gen_avg['q4'] !== '' && $gen_avg['q4'] !== null) ? round($gen_avg['q4']) : '');
                    $sheetBack->setCellValue('P44', ($gen_avg['final_rating'] !== '' && $gen_avg['final_rating'] !== null) ? round($gen_avg['final_rating']) : '');
                    $sheetBack->setCellValue('Q44', ($gen_avg['remarks'] !== '' && $gen_avg['remarks'] !== null) ? $gen_avg['remarks'] : '');
                }
            }
        }
    }
    
    // Configure print settings for all sheets
    foreach ($spreadsheet->getAllSheets() as $sheet) {
        $sheet->getPageSetup()
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_LEGAL)
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(1);
        
        $sheet->getPageMargins()
            ->setTop(0.5)
            ->setRight(0.5)
            ->setBottom(0.5)
            ->setLeft(0.5);
    }
    
    // Save the filled spreadsheet
    $filename = 'SF10_' . $student['lrn'] . '_' . $student['last_name'] . '.xlsx';
    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    
    // Output to browser
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit();
    
} catch (Exception $e) {
    header("Location: sf10_form.php?error=" . urlencode("Error generating SF10: " . $e->getMessage()));
    exit();
}
?>
