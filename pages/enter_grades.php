<?php
session_start();
require_once "../includes/db.php";

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit();
}

$user = $_SESSION['user'];
$is_admin = ($user['role'] === 'admin');

// Handle AJAX request for getting grades (BEFORE any HTML output)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_grades') {
    header('Content-Type: application/json');
    
    $student_id = intval($_GET['student_id']);
    $school_attended_id = intval($_GET['school_attended_id']);
    
    try {
      // Check if school record is for transfer student
      $columns = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'is_transfer'");
      $has_is_transfer = ($columns && $columns->num_rows > 0);
      if ($has_is_transfer) {
        $school_info = $conn->prepare("SELECT is_transfer FROM schools_attended WHERE id = ?");
        $school_info->bind_param("i", $school_attended_id);
        $school_info->execute();
        $school_data = $school_info->get_result()->fetch_assoc();
        $is_transfer = intval($school_data['is_transfer'] ?? 0) === 1;
      } else {
        $is_transfer = false;
      }

      // Load grades
      $grades_query = $conn->prepare("SELECT subject_id, quarter, grade, final_rating, remarks 
                      FROM grades 
                      WHERE student_id = ? AND school_attended_id = ?");
      $grades_query->bind_param("ii", $student_id, $school_attended_id);
      $grades_query->execute();
      $grades_result = $grades_query->get_result();

      $grades = [];
      while ($row = $grades_result->fetch_assoc()) {
        $subject_id = $row['subject_id'];
        $quarter = $row['quarter'];

        if (!isset($grades[$subject_id])) {
          $grades[$subject_id] = [
            'q1' => '',
            'q2' => '',
            'q3' => '',
            'q4' => '',
            'final_rating' => '',
            'remarks' => ''
          ];
        }

        if ($quarter >= 1 && $quarter <= 4) {
          $grades[$subject_id]['q' . $quarter] = $row['grade'];
        }

        if (!empty($row['final_rating'])) {
          $grades[$subject_id]['final_rating'] = $row['final_rating'];
        }
        if (!empty($row['remarks'])) {
          $grades[$subject_id]['remarks'] = $row['remarks'];
        }
      }

      // Ensure final_rating and remarks are only shown if all 4 quarters are filled
      foreach ($grades as $sid => &$gdata) {
        $allFourFilled = $gdata['q1'] !== '' && $gdata['q2'] !== '' && $gdata['q3'] !== '' && $gdata['q4'] !== '';
        if (!$allFourFilled) {
          $gdata['final_rating'] = '';
          $gdata['remarks'] = '';
        }
      }
      unset($gdata);

      // Load custom subject names independently (works even if no grades yet)
      if ($is_transfer) {
        $custom_query = $conn->prepare("SELECT subject_id, custom_subject_name
                        FROM student_custom_subjects
                        WHERE student_id = ? AND school_attended_id = ?");
        $custom_query->bind_param("ii", $student_id, $school_attended_id);
        $custom_query->execute();
        $custom_result = $custom_query->get_result();

        while ($custom_row = $custom_result->fetch_assoc()) {
          $subject_id = $custom_row['subject_id'];
          if (!isset($grades[$subject_id])) {
            $grades[$subject_id] = [
              'q1' => '',
              'q2' => '',
              'q3' => '',
              'q4' => '',
              'final_rating' => '',
              'remarks' => ''
            ];
          }
          $grades[$subject_id]['custom_subject_name'] = $custom_row['custom_subject_name'];
        }
      }

      // Load remedial classes (scoped precisely to avoid cross-grade bleeding)
      $remedial = [];
      $remedial_table_exists = $conn->query("SHOW TABLES LIKE 'remedial_classes'");
      if ($remedial_table_exists && $remedial_table_exists->num_rows > 0) {
        $col_check = $conn->query("SHOW COLUMNS FROM remedial_classes LIKE 'school_attended_id'");
        $has_school_attended = ($col_check && $col_check->num_rows > 0);
        $col_check_gl = $conn->query("SHOW COLUMNS FROM remedial_classes LIKE 'grade_level'");
        $has_grade_level_col = ($col_check_gl && $col_check_gl->num_rows > 0);

        if ($has_school_attended) {
          // Most precise: scoped to exact school_attended record
          $remedial_query = $conn->prepare("SELECT learning_area, final_rating, remedial_class_mark,
                                                   recomputed_final_grade, remarks, conducted_from, conducted_to
                                            FROM remedial_classes
                                            WHERE student_id = ? AND school_attended_id = ?
                                            ORDER BY id LIMIT 2");
          $remedial_query->bind_param("ii", $student_id, $school_attended_id);
        } else {
          // Fetch school_year AND grade_level from this school record
          $school_info_stmt = $conn->prepare("SELECT school_year, grade_level FROM schools_attended WHERE id = ? LIMIT 1");
          $school_info_stmt->bind_param("i", $school_attended_id);
          $school_info_stmt->execute();
          $school_info_row = $school_info_stmt->get_result()->fetch_assoc();
          $sy_for_load   = $school_info_row['school_year'] ?? '';
          $gl_for_load   = $school_info_row['grade_level'] ?? '';

          if ($has_grade_level_col && $gl_for_load !== '') {
            // Scope by both school_year AND grade_level — prevents cross-grade bleeding
            $remedial_query = $conn->prepare("SELECT learning_area, final_rating, remedial_class_mark,
                                                     recomputed_final_grade, remarks, conducted_from, conducted_to
                                              FROM remedial_classes
                                              WHERE student_id = ? AND school_year = ? AND grade_level = ?
                                              ORDER BY id LIMIT 2");
            $remedial_query->bind_param("iss", $student_id, $sy_for_load, $gl_for_load);
          } else {
            // Legacy fallback — only school_year (old records without grade_level)
            $remedial_query = $conn->prepare("SELECT learning_area, final_rating, remedial_class_mark,
                                                     recomputed_final_grade, remarks, conducted_from, conducted_to
                                              FROM remedial_classes
                                              WHERE student_id = ? AND school_year = ?
                                              ORDER BY id LIMIT 2");
            $remedial_query->bind_param("is", $student_id, $sy_for_load);
          }
        }

        $remedial_query->execute();
        $remedial_result = $remedial_query->get_result();
        while ($r = $remedial_result->fetch_assoc()) {
          $remedial[] = $r;
        }
      }
        
        echo json_encode([
            'success' => true, 
            'grades' => $grades,
            'is_transfer' => $is_transfer,
            'remedial' => $remedial
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error loading grades: ' . $e->getMessage()]);
    }
    exit;
}

// Handle POST request for saving grades (BEFORE any HTML output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grades'])) {
    $student_id = intval($_POST['student_id']);
    $school_attended_id = intval($_POST['school_attended_id']);
    
  // Determine transfer status from database (do not trust client input)
  $columns = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'is_transfer'");
  $has_is_transfer = ($columns && $columns->num_rows > 0);
  if ($has_is_transfer) {
    $transfer_query = $conn->prepare("SELECT is_transfer FROM schools_attended WHERE id = ?");
    $transfer_query->bind_param("i", $school_attended_id);
    $transfer_query->execute();
    $transfer_row = $transfer_query->get_result()->fetch_assoc();
    $is_transfer = intval($transfer_row['is_transfer'] ?? 0) === 1;
  } else {
    $is_transfer = false;
  }
    
    // Get school_year and grade_level from schools_attended
    $school_info_query = $conn->prepare("SELECT school_year, grade_level FROM schools_attended WHERE id = ?");
    $school_info_query->bind_param("i", $school_attended_id);
    $school_info_query->execute();
    $school_info = $school_info_query->get_result()->fetch_assoc();
    $school_year = $school_info['school_year'];
    $grade_level_for_remedial = $school_info['grade_level'] ?? null;
    
    // Get school_year_id
    $sy_query = $conn->prepare("SELECT id FROM school_years WHERE year = ?");
    $sy_query->bind_param("s", $school_year);
    $sy_query->execute();
    $sy_result = $sy_query->get_result()->fetch_assoc();
    $school_year_id = $sy_result['id'] ?? null;
    
    try {
        // Save custom subject names for transfer students
        if ($is_transfer && isset($_POST['custom_subject_names'])) {
            $custom_stmt = $conn->prepare("INSERT INTO student_custom_subjects 
                                          (student_id, school_attended_id, subject_id, custom_subject_name)
                                          VALUES (?, ?, ?, ?)
                                          ON DUPLICATE KEY UPDATE custom_subject_name = VALUES(custom_subject_name)");
          $delete_custom = $conn->prepare("DELETE FROM student_custom_subjects 
                           WHERE student_id = ? AND school_attended_id = ? AND subject_id = ?");
            
            foreach ($_POST['custom_subject_names'] as $subject_id => $custom_name) {
                $custom_name = trim($custom_name);

            // Get default subject name
            $default_query = $conn->prepare("SELECT subject_name FROM subjects WHERE id = ?");
            $default_query->bind_param("i", $subject_id);
            $default_query->execute();
            $default_result = $default_query->get_result()->fetch_assoc();

            // If same as default, remove override; otherwise save override (including empty string)
            if ($default_result && $custom_name === $default_result['subject_name']) {
              $delete_custom->bind_param("iii", $student_id, $school_attended_id, $subject_id);
              $delete_custom->execute();
            } else {
              $custom_stmt->bind_param("iiis", $student_id, $school_attended_id, $subject_id, $custom_name);
              $custom_stmt->execute();
                }
            }
        }
        
        // Delete existing grades for this student and school record
        $delete_stmt = $conn->prepare("DELETE FROM grades WHERE student_id = ? AND school_attended_id = ?");
        $delete_stmt->bind_param("ii", $student_id, $school_attended_id);
        $delete_stmt->execute();
        
        // Insert new grades
        $insert_stmt = $conn->prepare("INSERT INTO grades 
            (student_id, school_attended_id, subject_id, quarter, grade, final_rating, remarks, teacher_id, school_year, school_year_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        foreach ($_POST['grades'] as $subject_id => $data) {
          $final_rating = !empty($data['final_rating']) ? floatval($data['final_rating']) : null;
          if ($final_rating !== null) {
            $final_rating = max(0, min(100, $final_rating));
          }
            $remarks = !empty($data['remarks']) ? $data['remarks'] : null;
            
            // Insert each quarter grade
            for ($q = 1; $q <= 4; $q++) {
                $quarterKey = 'q' . $q;
                if (isset($data[$quarterKey]) && $data[$quarterKey] !== '') {
              $grade = floatval($data[$quarterKey]);
              $grade = max(0, min(100, $grade));
                    $insert_stmt->bind_param("iiiiddsisi", 
                        $student_id, $school_attended_id, $subject_id, $q, 
                        $grade, $final_rating, $remarks, $user['id'], 
                        $school_year, $school_year_id);
                    $insert_stmt->execute();
                }
            }
        }

            // Save remedial classes
            if (isset($_POST['remedial']) && is_array($_POST['remedial'])) {
              $remedial_table_exists = $conn->query("SHOW TABLES LIKE 'remedial_classes'");
              if ($remedial_table_exists && $remedial_table_exists->num_rows > 0) {
                $col_check = $conn->query("SHOW COLUMNS FROM remedial_classes LIKE 'school_attended_id'");
                $has_school_attended = ($col_check && $col_check->num_rows > 0);
                $col_check_gl = $conn->query("SHOW COLUMNS FROM remedial_classes LIKE 'grade_level'");
                $has_grade_level_col = ($col_check_gl && $col_check_gl->num_rows > 0);

                // Delete existing remedial classes for this specific grade scope only
                if ($has_school_attended) {
                  $delete_remedial = $conn->prepare("DELETE FROM remedial_classes WHERE student_id = ? AND school_attended_id = ?");
                  $delete_remedial->bind_param("ii", $student_id, $school_attended_id);
                } elseif ($has_grade_level_col && $grade_level_for_remedial) {
                  // Scope delete by grade_level so we don't wipe remedial from other grades
                  $delete_remedial = $conn->prepare("DELETE FROM remedial_classes WHERE student_id = ? AND school_year = ? AND grade_level = ?");
                  $delete_remedial->bind_param("iss", $student_id, $school_year, $grade_level_for_remedial);
                } else {
                  $delete_remedial = $conn->prepare("DELETE FROM remedial_classes WHERE student_id = ? AND school_year = ?");
                  $delete_remedial->bind_param("is", $student_id, $school_year);
                }
                $delete_remedial->execute();

                foreach ($_POST['remedial'] as $remedial_data) {
                  if (!empty($remedial_data['learning_area'])) {
                    $learning_area = trim($remedial_data['learning_area']);
                    $r_final_rating = !empty($remedial_data['final_rating']) ? round(floatval($remedial_data['final_rating'])) : null;
                    $remedial_mark = !empty($remedial_data['remedial_class_mark']) ? round(floatval($remedial_data['remedial_class_mark'])) : null;
                    if ($r_final_rating !== null) $r_final_rating = max(0, min(100, $r_final_rating));
                    if ($remedial_mark !== null) $remedial_mark = max(0, min(100, $remedial_mark));

                    // Auto-calculate recomputed grade and remarks from final + remedial marks
                    $recomputed = null;
                    $r_remarks = null;
                    if ($r_final_rating !== null && $remedial_mark !== null) {
                      $recomputed = round(($r_final_rating + $remedial_mark) / 2);
                      $r_remarks = $recomputed >= 75 ? 'Passed' : 'Failed';
                    }
                    $conducted_from = !empty($remedial_data['conducted_from']) ? $remedial_data['conducted_from'] : null;
                    $conducted_to = !empty($remedial_data['conducted_to']) ? $remedial_data['conducted_to'] : null;

                    if ($has_school_attended) {
                      $insert_remedial = $conn->prepare("INSERT INTO remedial_classes
                        (student_id, school_attended_id, school_year, grade_level, learning_area, final_rating, remedial_class_mark,
                         recomputed_final_grade, remarks, conducted_from, conducted_to)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                      $insert_remedial->bind_param(
                        "iisssiiisss",
                        $student_id,
                        $school_attended_id,
                        $school_year,
                        $grade_level_for_remedial,
                        $learning_area,
                        $r_final_rating,
                        $remedial_mark,
                        $recomputed,
                        $r_remarks,
                        $conducted_from,
                        $conducted_to
                      );
                    } elseif ($has_grade_level_col) {
                      $insert_remedial = $conn->prepare("INSERT INTO remedial_classes
                        (student_id, school_year, grade_level, learning_area, final_rating, remedial_class_mark,
                         recomputed_final_grade, remarks, conducted_from, conducted_to)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                      $insert_remedial->bind_param(
                        "isssiiisss",
                        $student_id,
                        $school_year,
                        $grade_level_for_remedial,
                        $learning_area,
                        $r_final_rating,
                        $remedial_mark,
                        $recomputed,
                        $r_remarks,
                        $conducted_from,
                        $conducted_to
                      );
                    } else {
                      $insert_remedial = $conn->prepare("INSERT INTO remedial_classes
                        (student_id, school_year, learning_area, final_rating, remedial_class_mark,
                         recomputed_final_grade, remarks, conducted_from, conducted_to)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                      $insert_remedial->bind_param(
                        "issiiisss",
                        $student_id,
                        $school_year,
                        $learning_area,
                        $r_final_rating,
                        $remedial_mark,
                        $recomputed,
                        $r_remarks,
                        $conducted_from,
                        $conducted_to
                      );
                    }
                    $insert_remedial->execute();
                  }
                }
              }
            }
        
        $_SESSION['success_message'] = "Grades saved successfully!";
        header("Location: enter_grades.php?student_id=$student_id&school_attended_id=$school_attended_id&student_name=" . urlencode($_POST['student_name'] ?? ''));
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error saving grades: " . $e->getMessage();
        header("Location: enter_grades.php?student_id=$student_id&school_attended_id=$school_attended_id&student_name=" . urlencode($_POST['student_name'] ?? ''));
        exit;
    }
}

// Get student_id from URL
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$student_name = isset($_GET['student_name']) ? $_GET['student_name'] : '';
$preselected_school_attended_id = isset($_GET['school_attended_id']) ? intval($_GET['school_attended_id']) : 0;

if (!$student_id) {
    $_SESSION['error_message'] = "No student selected.";
    header("Location: " . ($is_admin ? "grade_entry.php" : "input_grades.php"));
    exit();
}

// Get student information
$student_query = $conn->prepare("SELECT id, UPPER(CONCAT(last_name, ', ', first_name)) AS fullname, lrn, gender, birthdate 
                                 FROM students WHERE id = ?");
$student_query->bind_param("i", $student_id);
$student_query->execute();
$student_result = $student_query->get_result();
$student = $student_result->fetch_assoc();

if (!$student) {
    $_SESSION['error_message'] = "Student not found.";
    header("Location: " . ($is_admin ? "grade_entry.php" : "input_grades.php"));
    exit();
}

// Get student's school records - check if is_transfer column exists
$columns = $conn->query("SHOW COLUMNS FROM schools_attended LIKE 'is_transfer'");
$has_is_transfer = ($columns && $columns->num_rows > 0);

if ($has_is_transfer) {
    $school_query = $conn->prepare("SELECT id, grade_level, section, school_year, school_name, is_transfer 
                                    FROM schools_attended 
                                    WHERE student_id = ? 
                  ORDER BY 
                    CASE 
                      WHEN grade_level REGEXP '^[0-9]+$' THEN CAST(grade_level AS UNSIGNED)
                      WHEN grade_level LIKE 'Grade %' THEN CAST(SUBSTRING_INDEX(grade_level, ' ', -1) AS UNSIGNED)
                      ELSE 999
                    END ASC,
                    school_year ASC,
                    id ASC");
} else {
    $school_query = $conn->prepare("SELECT id, grade_level, section, school_year, school_name, 0 as is_transfer 
                                    FROM schools_attended 
                                    WHERE student_id = ? 
                  ORDER BY 
                    CASE 
                      WHEN grade_level REGEXP '^[0-9]+$' THEN CAST(grade_level AS UNSIGNED)
                      WHEN grade_level LIKE 'Grade %' THEN CAST(SUBSTRING_INDEX(grade_level, ' ', -1) AS UNSIGNED)
                      ELSE 999
                    END ASC,
                    school_year ASC,
                    id ASC");
}

if (!$school_query) {
    die("Error preparing school query: " . $conn->error);
}

$school_query->bind_param("i", $student_id);
$school_query->execute();
$school_records = $school_query->get_result();

if (!$school_records) {
    die("Error executing school query: " . $conn->error);
}

// Get all subjects
$subjects_query = "SELECT id, subject_name FROM subjects ORDER BY id";
$subjects_result = $conn->query($subjects_query);
$subjects = [];
while ($row = $subjects_result->fetch_assoc()) {
    $subjects[] = $row;
}

// Load grade-level subject display names from manage_subjects config
$subject_display_by_grade = [];
$subject_grade_groups_exists = $conn->query("SHOW TABLES LIKE 'subject_grade_groups'");
if ($subject_grade_groups_exists && $subject_grade_groups_exists->num_rows > 0) {
  $display_query = $conn->query("SELECT grade_level, subject_id, subject_name FROM subject_grade_groups");
  if ($display_query) {
    while ($drow = $display_query->fetch_assoc()) {
      $g = strval($drow['grade_level']);
      if (!isset($subject_display_by_grade[$g])) {
        $subject_display_by_grade[$g] = [];
      }
      $subject_display_by_grade[$g][strval($drow['subject_id'])] = $drow['subject_name'];
    }
  }
}

include "../templates/header.php";
?>

<style>
  .grade-entry-container {
    max-width: 1400px;
    margin: 0 auto;
  }
  
  .student-info-card {
    background: var(--card-bg);
    border-radius: 0.5rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
  }
  
  /* Table styling matching grades.php */
  .grades-table {
    color: var(--content-text);
    table-layout: fixed;
    width: 100%;
    border-collapse: collapse;
  }
  
  .grades-table.table-bordered {
    border: 1px solid var(--table-border) !important;
  }
  
  .grades-table thead th {
    background-color: var(--card-bg) !important;
    color: var(--content-text);
    font-weight: 600;
    text-align: center;
    vertical-align: middle;
    padding: 0.75rem 0.5rem;
    border: 1px solid var(--table-border) !important;
  }
  
  .grades-table tbody td {
    vertical-align: middle;
    padding: 0.5rem;
    border: 1px solid var(--table-border) !important;
    color: var(--content-text);
  }
  
  /* Grade input styling matching grades.php */
  .grades-table input[type="number"],
  .grades-table input[type="text"],
  .grades-table .form-control {
    background-color: var(--input-bg) !important;
    border-color: var(--input-border) !important;
    color: var(--input-text) !important;
    text-align: center;
    height: 38px;
    font-size: 1rem;
    font-weight: 400;
    width: 100%;
    padding: 0.375rem 0.75rem;
    border-radius: 0.375rem;
    border: 1px solid var(--input-border);
  }
  
  /* Remove spinner arrows from number inputs */
  .grades-table input[type="number"]::-webkit-inner-spin-button,
  .grades-table input[type="number"]::-webkit-outer-spin-button {
    -webkit-appearance: none;
    margin: 0;
  }
  
  .grades-table input[type="number"] {
    -moz-appearance: textfield;
    appearance: textfield;
  }
  
  .grades-table input:focus {
    background-color: var(--input-bg) !important;
    border-color: var(--primary-teal) !important;
    color: var(--input-text) !important;
    outline: none;
    box-shadow: 0 0 0 0.2rem rgba(13, 202, 240, 0.25);
  }
  
  .grades-table input[readonly],
  .grades-table .form-control[readonly] {
    background-color: var(--input-readonly-bg) !important;
    color: #6c757d !important;
    cursor: not-allowed;
  }
  
  /* MAPEH row styling - gray background to indicate non-editable */
  .grades-table tr.mapeh-row {
    background-color: rgba(108, 117, 125, 0.1) !important;
  }
  
  body.dark-theme .grades-table tr.mapeh-row {
    background-color: rgba(108, 117, 125, 0.2) !important;
  }
  
  .grades-table tr.mapeh-row td {
    background-color: transparent !important;
  }
  
  .grades-table tr.mapeh-row .subject-name-col {
    font-weight: 600;
    color: var(--content-text);
  }
  
  /* Transfer student editable subject names */
  .subject-name-col .form-control {
    font-weight: 600;
    border: 1px solid var(--input-border);
  }
  
  .subject-name-col .form-control:focus {
    border-color: var(--primary-teal);
    box-shadow: 0 0 0 0.2rem rgba(13, 202, 240, 0.25);
  }
  
  /* MAPEH readonly cells with box styling */
  .grades-table td.mapeh-cell {
    padding: 0.35rem 0.4rem !important;
  }

  .grades-table .mapeh-box,
  .grades-table .mapeh-q1,
  .grades-table .mapeh-q2,
  .grades-table .mapeh-q3,
  .grades-table .mapeh-q4 {
    background-color: var(--input-readonly-bg) !important;
    border: 1px solid var(--input-border);
    border-radius: 0.375rem;
    padding: 0.375rem 0.75rem;
    height: 38px;
    min-height: 38px;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--input-text);
    font-weight: 600;
    font-size: 1rem;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04);
  }

  body.dark-theme .grades-table .mapeh-box,
  body.dark-theme .grades-table .mapeh-q1,
  body.dark-theme .grades-table .mapeh-q2,
  body.dark-theme .grades-table .mapeh-q3,
  body.dark-theme .grades-table .mapeh-q4 {
    border-color: rgba(255, 255, 255, 0.22) !important;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
  }
  
  .subject-name-col {
    font-weight: 500;
    text-align: left !important;
    padding-left: 1rem !important;
  }
  
  /* Column widths - all cells same size for consistency */
  .grades-table thead th:first-child,
  .grades-table tbody td:first-child {
    width: 250px;
    min-width: 250px;
  }
  
  .grades-table thead th:nth-child(2),
  .grades-table thead th:nth-child(3),
  .grades-table thead th:nth-child(4),
  .grades-table thead th:nth-child(5),
  .grades-table thead th:nth-child(6),
  .grades-table thead th:nth-child(7),
  .grades-table tbody td:nth-child(2),
  .grades-table tbody td:nth-child(3),
  .grades-table tbody td:nth-child(4),
  .grades-table tbody td:nth-child(5),
  .grades-table tbody td:nth-child(6),
  .grades-table tbody td:nth-child(7) {
    width: 120px;
    min-width: 120px;
  }
  
  @media (max-width: 768px) {
    .grades-table {
      font-size: 0.85rem;
    }
    
    .grades-table input[type="number"],
    .grades-table input[type="text"] {
      font-size: 0.85rem;
      height: 32px;
    }
  }
</style>

<div class="grade-entry-container">
  <!-- Page Header with Back Button -->
  <div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
      <h2><i class="bi bi-pencil-square"></i> Grade Entry</h2>
      <p class="subtitle">Enter and manage grades for <?= htmlspecialchars($student['fullname']) ?></p>
    </div>
    <a href="<?= $is_admin ? 'grade_entry.php' : 'input_grades.php' ?>" class="btn btn-info">
      <i class="bi bi-arrow-left"></i> Back to Student List
    </a>
  </div>

  <?php if (isset($_SESSION['success_message'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <i class="bi bi-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
  <?php endif; ?>

  <?php if (isset($_SESSION['error_message'])): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
  <?php endif; ?>

  <!-- Student Information Card -->
  <div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="bi bi-person-circle"></i> Student Information</h5>
      <a href="sf10_preview.php?student_id=<?= $student_id ?>" class="btn btn-warning btn-sm text-white" style="border-radius: 8px; font-weight: 700; background-color: #f39c12; border-color: #f39c12;">
        <i class="bi bi-file-earmark-pdf me-1"></i> View SF10 Preview
      </a>
    </div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-3">
          <strong>Name:</strong><br>
          <?= htmlspecialchars($student['fullname']) ?>
        </div>
        <div class="col-md-3">
          <strong>LRN:</strong><br>
          <?= htmlspecialchars($student['lrn']) ?>
        </div>
        <div class="col-md-3">
          <strong>Gender:</strong><br>
          <?= htmlspecialchars($student['gender'] ?? 'N/A') ?>
        </div>
        <div class="col-md-3">
          <strong>Birthdate:</strong><br>
          <?= !empty($student['birthdate']) ? date('F d, Y', strtotime($student['birthdate'])) : 'N/A' ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Grade Entry Form -->
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> Grades</h5>
      <div>
        <label for="schoolRecordSelect" class="me-2">Select School Record:</label>
        <select id="schoolRecordSelect" class="form-select form-select-sm d-inline-block" style="width: auto;" onchange="loadGrades()">
          <option value="">-- Select School Year --</option>
          <?php while ($record = $school_records->fetch_assoc()): ?>
            <option value="<?= $record['id'] ?>" 
                    data-grade="<?= $record['grade_level'] ?>" 
                    data-section="<?= $record['section'] ?>" 
                    data-year="<?= $record['school_year'] ?>">
              SY <?= htmlspecialchars($record['school_year']) ?> - Grade <?= $record['grade_level'] ?> - <?= htmlspecialchars($record['section']) ?>
            </option>
          <?php endwhile; ?>
        </select>
      </div>
    </div>
    <div class="card-body">
      <div id="gradesContainer">
        <div class="text-center text-muted py-5">
          <i class="bi bi-info-circle" style="font-size: 3rem;"></i>
          <p class="mt-3">Please select a school record to view and enter grades.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const studentId = <?= $student_id ?>;
const preselectedSchoolAttendedId = <?= $preselected_school_attended_id ?>;
const schoolSubjectsByGrade = <?= json_encode($subject_display_by_grade) ?>;

function loadGrades() {
  const select = document.getElementById('schoolRecordSelect');
  const schoolAttendedId = select.value;
  const selectedOption = select.options[select.selectedIndex];
  const selectedGrade = selectedOption ? (selectedOption.dataset.grade || '') : '';
  const container = document.getElementById('gradesContainer');
  
  if (!schoolAttendedId) {
    container.innerHTML = `
      <div class="text-center text-muted py-5">
        <i class="bi bi-info-circle" style="font-size: 3rem;"></i>
        <p class="mt-3">Please select a school record to view and enter grades.</p>
      </div>
    `;
    return;
  }
  
  // Show loading
  container.innerHTML = `
    <div class="text-center py-5">
      <div class="spinner-border text-primary" role="status"></div>
      <p class="mt-3">Loading grades...</p>
    </div>
  `;
  
  // Fetch grades
  console.log('Fetching grades for student:', studentId, 'school_attended:', schoolAttendedId);
  
  fetch(`enter_grades.php?ajax=get_grades&student_id=${studentId}&school_attended_id=${schoolAttendedId}`)
    .then(response => {
      console.log('Response status:', response.status);
      return response.json();
    })
    .then(data => {
      console.log('Received data:', data);
      if (data.success) {
        renderGradesTable(data.grades, schoolAttendedId, data.is_transfer, selectedGrade, data.remedial || []);
      } else {
        container.innerHTML = `<div class="alert alert-danger">${data.message || 'Unknown error'}</div>`;
      }
    })
    .catch(error => {
      console.error('Error:', error);
      container.innerHTML = `<div class="alert alert-danger">An error occurred while loading grades: ${error.message}</div>`;
    });
}

function renderGradesTable(grades, schoolAttendedId, isTransfer = false, selectedGrade = '', remedial = []) {
  const subjects = <?= json_encode($subjects) ?>;
  const container = document.getElementById('gradesContainer');
  const studentName = '<?= addslashes($student['fullname']) ?>';
  const gradeSubjectNames = schoolSubjectsByGrade[String(parseInt(selectedGrade, 10))] || {};
  
  let html = `
    <form method="POST" action="enter_grades.php" id="gradesForm">
      <input type="hidden" name="student_id" value="${studentId}">
      <input type="hidden" name="is_transfer" value="${isTransfer ? 1 : 0}">
      <input type="hidden" name="school_attended_id" value="${schoolAttendedId}">
      <input type="hidden" name="student_name" value="${studentName}">
      ${isTransfer ? `
      <div class="alert alert-info d-flex justify-content-between align-items-center mb-3">
        <div>
          <i class="bi bi-info-circle"></i> <strong>Transfer Student:</strong> You can customize subject names for this student's previous school.
        </div>
      </div>
      ` : ''}
      <div class="table-responsive">
        <table class="table table-bordered grades-table">
          <thead>
            <tr>
              <th>Learning Area</th>
              <th>Q1</th>
              <th>Q2</th>
              <th>Q3</th>
              <th>Q4</th>
              <th>Final Rating</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
  `;
  
  // MAPEH component subjects
  const mapehComponents = ['Music', 'Arts', 'Physical Education', 'Health'];
  let hasGeneralAverageRow = false;
  
  subjects.forEach(subject => {
    const gradeData = grades[subject.id] || {};
    const q1 = gradeData.q1 || '';
    const q2 = gradeData.q2 || '';
    const q3 = gradeData.q3 || '';
    const q4 = gradeData.q4 || '';
    const finalRating = gradeData.final_rating || '';
    const remarks = gradeData.remarks || '';
    const customSubjectName = Object.prototype.hasOwnProperty.call(gradeData, 'custom_subject_name')
      ? gradeData.custom_subject_name
      : subject.subject_name;
    const regularSubjectName = Object.prototype.hasOwnProperty.call(gradeSubjectNames, String(subject.id))
      ? gradeSubjectNames[String(subject.id)]
      : subject.subject_name;
    
    const isMapeh = subject.subject_name === 'MAPEH';
    const isMapehComponent = mapehComponents.includes(subject.subject_name);
    const isGeneralAverage = subject.subject_name === 'General Average';
    if (isGeneralAverage) hasGeneralAverageRow = true;

    // A MAPEH component is "not configured" if subject_grade_groups has it with an empty name
    // (key exists but value is empty string means admin left it blank in manage_subjects)
    const configuredName = gradeSubjectNames[String(subject.id)];
    const isUnconfiguredMapehComponent = isMapehComponent &&
      Object.prototype.hasOwnProperty.call(gradeSubjectNames, String(subject.id)) &&
      configuredName === '';
    
    // Add visual class for special computed rows
    const rowClass = isMapeh ? 'mapeh-row' : (isGeneralAverage ? 'general-average-row' : '');
    
    html += `
      <tr class="${rowClass}" data-subject-id="${subject.id}" data-subject-name="${subject.subject_name}">
        <td class="subject-name-col">
          ${isTransfer ? `
            <input type="text" 
                   name="custom_subject_names[${subject.id}]" 
                   value="${customSubjectName}" 
                   class="form-control form-control-sm"
                   placeholder="${subject.subject_name}"
                   style="min-width: 200px;">
          ` : regularSubjectName}
        </td>`;
    
    if (isMapeh) {
      // MAPEH row - readonly calculated fields with visible boxes
      const displayQ1 = q1 || '—';
      const displayQ2 = q2 || '—';
      const displayQ3 = q3 || '—';
      const displayQ4 = q4 || '—';
      
      html += `
        <td class="mapeh-cell"><div class="mapeh-box mapeh-q1">${displayQ1}</div>
            <input type="hidden" name="grades[${subject.id}][q1]" value="${q1}" class="mapeh-input-q1"></td>
        <td class="mapeh-cell"><div class="mapeh-box mapeh-q2">${displayQ2}</div>
            <input type="hidden" name="grades[${subject.id}][q2]" value="${q2}" class="mapeh-input-q2"></td>
        <td class="mapeh-cell"><div class="mapeh-box mapeh-q3">${displayQ3}</div>
            <input type="hidden" name="grades[${subject.id}][q3]" value="${q3}" class="mapeh-input-q3"></td>
        <td class="mapeh-cell"><div class="mapeh-box mapeh-q4">${displayQ4}</div>
            <input type="hidden" name="grades[${subject.id}][q4]" value="${q4}" class="mapeh-input-q4"></td>`;
    } else if (isGeneralAverage) {
      // General Average row - computed from subject final ratings, readonly display
      html += `
        <td class="mapeh-cell"><div class="mapeh-box">—</div><input type="hidden" name="grades[${subject.id}][q1]" value=""></td>
        <td class="mapeh-cell"><div class="mapeh-box">—</div><input type="hidden" name="grades[${subject.id}][q2]" value=""></td>
        <td class="mapeh-cell"><div class="mapeh-box">—</div><input type="hidden" name="grades[${subject.id}][q3]" value=""></td>
        <td class="mapeh-cell"><div class="mapeh-box">—</div><input type="hidden" name="grades[${subject.id}][q4]" value=""></td>`;
    } else {
      // Regular subjects - editable (disabled if MAPEH component not configured in manage_subjects)
      const onInputEvent = isMapehComponent ? 
        `sanitizeGradeInput(this); calculateFinalRating(this, ${subject.id}); calculateMapeh();` : 
        `sanitizeGradeInput(this); calculateFinalRating(this, ${subject.id});`;

      const disabledAttr = isUnconfiguredMapehComponent ? 'disabled style="background:#e9ecef; cursor:not-allowed;" title="Not configured in Manage Subjects"' : '';
      
      html += `
        <td>
          <input type="number" name="grades[${subject.id}][q1]" class="form-control quarter-input" 
                 min="0" max="100" step="0.01" value="${isUnconfiguredMapehComponent ? '' : q1}" data-quarter="1" data-subject-id="${subject.id}"
                 oninput="${onInputEvent}" ${disabledAttr}>
        </td>
        <td>
          <input type="number" name="grades[${subject.id}][q2]" class="form-control quarter-input" 
                 min="0" max="100" step="0.01" value="${isUnconfiguredMapehComponent ? '' : q2}" data-quarter="2" data-subject-id="${subject.id}"
                 oninput="${onInputEvent}" ${disabledAttr}>
        </td>
        <td>
          <input type="number" name="grades[${subject.id}][q3]" class="form-control quarter-input" 
                 min="0" max="100" step="0.01" value="${isUnconfiguredMapehComponent ? '' : q3}" data-quarter="3" data-subject-id="${subject.id}"
                 oninput="${onInputEvent}" ${disabledAttr}>
        </td>
        <td>
          <input type="number" name="grades[${subject.id}][q4]" class="form-control quarter-input" 
                 min="0" max="100" step="0.01" value="${isUnconfiguredMapehComponent ? '' : q4}" data-quarter="4" data-subject-id="${subject.id}"
                 oninput="${onInputEvent}" ${disabledAttr}>
        </td>`;
    }
    
    html += `
        <td>
          <input type="number" name="grades[${subject.id}][final_rating]" 
                 class="form-control final-rating-${subject.id} ${isGeneralAverage ? 'general-average-final' : ''}" 
                 value="${isUnconfiguredMapehComponent ? '' : finalRating}" readonly
                 ${isUnconfiguredMapehComponent ? 'style="background:#e9ecef; cursor:not-allowed;"' : ''}>
        </td>
        <td>
          <input type="text" name="grades[${subject.id}][remarks]" 
                 class="form-control remarks-${subject.id} ${isGeneralAverage ? 'general-average-remarks' : ''}" 
                 value="${isUnconfiguredMapehComponent ? '' : remarks}" readonly
                 ${isUnconfiguredMapehComponent ? 'style="background:#e9ecef; cursor:not-allowed;"' : ''}>
        </td>
      </tr>
    `;
  });

  // Ensure General Average is always visible at the bottom even if not present in subjects table
  if (!hasGeneralAverageRow) {
    html += `
      <tr class="general-average-row" data-subject-id="general_average" data-subject-name="General Average">
        <td class="subject-name-col">General Average</td>
        <td class="mapeh-cell"><div class="mapeh-box">—</div></td>
        <td class="mapeh-cell"><div class="mapeh-box">—</div></td>
        <td class="mapeh-cell"><div class="mapeh-box">—</div></td>
        <td class="mapeh-cell"><div class="mapeh-box">—</div></td>
        <td><input type="number" class="form-control general-average-final" value="" readonly></td>
        <td><input type="text" class="form-control general-average-remarks" value="" readonly></td>
      </tr>
    `;
  }
  
  html += `
          </tbody>
        </table>
      </div>

      <div class="mt-4">
        <h6 class="mb-2">Remedial Classes</h6>
        <div class="table-responsive">
          <table class="table table-bordered grades-table">
            <thead>
              <tr>
                <th>Learning Area</th>
                <th>Final Rating</th>
                <th>Remedial Class Mark</th>
                <th>Recomputed Final Grade</th>
                <th>Remarks</th>
                <th>Conducted From</th>
                <th>Conducted To</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>
                  <select name="remedial[1][learning_area]" class="form-select remedial-subject-select" id="remedial-subject-1" onchange="autoFillRemedialFinalRating(1)">
                    <option value="">-- Select Subject --</option>
                  </select>
                </td>
                <td><input type="number" name="remedial[1][final_rating]" class="form-control" min="0" max="100" step="0.01" readonly></td>
                <td><input type="number" name="remedial[1][remedial_class_mark]" class="form-control" min="0" max="100" step="0.01" oninput="sanitizeGradeInput(this); calculateRecomputedGrade(1)"></td>
                <td><input type="number" name="remedial[1][recomputed_final_grade]" class="form-control" readonly></td>
                <td><input type="text" name="remedial[1][remarks]" class="form-control" readonly></td>
                <td><input type="date" name="remedial[1][conducted_from]" class="form-control"></td>
                <td><input type="date" name="remedial[1][conducted_to]" class="form-control"></td>
              </tr>
              <tr>
                <td>
                  <select name="remedial[2][learning_area]" class="form-select remedial-subject-select" id="remedial-subject-2" onchange="autoFillRemedialFinalRating(2)">
                    <option value="">-- Select Subject --</option>
                  </select>
                </td>
                <td><input type="number" name="remedial[2][final_rating]" class="form-control" min="0" max="100" step="0.01" readonly></td>
                <td><input type="number" name="remedial[2][remedial_class_mark]" class="form-control" min="0" max="100" step="0.01" oninput="sanitizeGradeInput(this); calculateRecomputedGrade(2)"></td>
                <td><input type="number" name="remedial[2][recomputed_final_grade]" class="form-control" readonly></td>
                <td><input type="text" name="remedial[2][remarks]" class="form-control" readonly></td>
                <td>
                  <input type="hidden" name="remedial[2][conducted_from]" value="">
                  <span class="text-muted">(same as row 1)</span>
                </td>
                <td>
                  <input type="hidden" name="remedial[2][conducted_to]" value="">
                  <span class="text-muted">(same as row 1)</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      
      <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="button" class="btn btn-secondary" onclick="loadGrades()">
          <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
        <button type="submit" class="btn btn-primary">
          <i class="bi bi-save"></i> Save Grades
        </button>
      </div>
    </form>
  `;
  
  container.innerHTML = html;

  // Populate remedial dropdown from currently displayed subject names
  populateRemedialSubjects();

  // Restore remedial values from database
  if (Array.isArray(remedial)) {
    remedial.slice(0, 2).forEach((row, idx) => {
      const n = idx + 1;
      const learning = document.querySelector(`[name="remedial[${n}][learning_area]"]`);
      const finalRating = document.querySelector(`[name="remedial[${n}][final_rating]"]`);
      const mark = document.querySelector(`[name="remedial[${n}][remedial_class_mark]"]`);
      const recomputed = document.querySelector(`[name="remedial[${n}][recomputed_final_grade]"]`);
      const remarks = document.querySelector(`[name="remedial[${n}][remarks]"]`);
      const from = document.querySelector(`[name="remedial[${n}][conducted_from]"]`);
      const to = document.querySelector(`[name="remedial[${n}][conducted_to]"]`);

      if (learning) learning.value = row.learning_area || '';
      if (finalRating) finalRating.value = row.final_rating || '';
      if (mark) mark.value = row.remedial_class_mark || '';
      if (recomputed) recomputed.value = row.recomputed_final_grade || '';
      if (remarks) remarks.value = row.remarks || '';
      if (from) from.value = row.conducted_from || '';
      if (to) to.value = row.conducted_to || '';
    });
  }

  // Share conducted dates for both remedial rows (single visible inputs from row 1)
  const r1From = document.querySelector('[name="remedial[1][conducted_from]"]');
  const r1To = document.querySelector('[name="remedial[1][conducted_to]"]');
  const r2From = document.querySelector('[name="remedial[2][conducted_from]"]');
  const r2To = document.querySelector('[name="remedial[2][conducted_to]"]');
  
  // If row 1 dates are empty but row 2 has values from DB, use row 2 values as shared dates
  if (r1From && r2From && !r1From.value && r2From.value) r1From.value = r2From.value;
  if (r1To && r2To && !r1To.value && r2To.value) r1To.value = r2To.value;

  syncRemedialConductedDates();
  if (r1From) r1From.addEventListener('input', syncRemedialConductedDates);
  if (r1To) r1To.addEventListener('input', syncRemedialConductedDates);

  // Ensure remedial final rating / recomputed grade are auto-calculated on load
  refreshRemedialDependentCalculations();
  
  // Initialize MAPEH calculation after rendering
  calculateMapeh();
  calculateGeneralAverage();
}

function calculateMapeh() {
  const mapehComponents = ['Music', 'Arts', 'Physical Education', 'Health'];
  const subjects = <?= json_encode($subjects) ?>;
  
  // Find MAPEH subject ID
  const mapehSubject = subjects.find(s => s.subject_name === 'MAPEH');
  if (!mapehSubject) return;
  
  // Find component subject IDs that are actually enabled (not disabled by manage_subjects config)
  const componentIds = [];
  subjects.forEach(s => {
    if (mapehComponents.includes(s.subject_name)) {
      // Only include if Q1 input exists and is not disabled
      const q1Input = document.querySelector(`input[name="grades[${s.id}][q1]"]`);
      if (q1Input && !q1Input.disabled) {
        componentIds.push(s.id);
      }
    }
  });
  
  if (componentIds.length === 0) return;
  
  // Calculate for each quarter — show average whenever any components are filled
  const quarterValues = [];
  for (let q = 1; q <= 4; q++) {
    let total = 0;
    let count = 0;
    
    componentIds.forEach(id => {
      const input = document.querySelector(`input[name="grades[${id}][q${q}]"]`);
      const val = parseFloat(input?.value);
      if (!isNaN(val) && val > 0) {
        total += val;
        count++;
      }
    });
    
    const average = count > 0 ? Math.round(total / count) : '';
    // Track whether ALL active components filled this quarter (needed for final rating gate)
    quarterValues.push(count === componentIds.length ? Math.round(total / count) : 0);
    
    // Update MAPEH display and hidden input
    const mapehDisplay = document.querySelector(`.mapeh-q${q}`);
    const mapehInput = document.querySelector(`.mapeh-input-q${q}`);
    
    if (mapehDisplay) mapehDisplay.textContent = average || '—';
    if (mapehInput) mapehInput.value = average;
  }
  
  // Calculate MAPEH final rating ONLY when ALL 4 quarters have all active components filled
  const finalRatingInput = document.querySelector(`.final-rating-${mapehSubject.id}`);
  const remarksInput = document.querySelector(`.remarks-${mapehSubject.id}`);
  
  const allFourQuartersDone = quarterValues.every(v => v > 0);
  if (allFourQuartersDone) {
    const finalAverage = Math.round(quarterValues.reduce((a, b) => a + b, 0) / 4);
    if (finalRatingInput) finalRatingInput.value = finalAverage;
    if (remarksInput) remarksInput.value = finalAverage >= 75 ? 'PASSED' : 'FAILED';
  } else {
    if (finalRatingInput) finalRatingInput.value = '';
    if (remarksInput) remarksInput.value = '';
  }

  refreshRemedialDependentCalculations();
  calculateGeneralAverage();
}

function calculateFinalRating(input, subjectId) {
  const row = input.closest('tr');
  const inputs = row.querySelectorAll('input[type="number"]:not([readonly])');
  let sum = 0;
  let count = 0;
  
  inputs.forEach(inp => {
    const val = parseFloat(inp.value);
    if (!isNaN(val) && val > 0) {
      sum += val;
      count++;
    }
  });
  
  const finalRatingInput = row.querySelector(`.final-rating-${subjectId}`);
  const remarksInput = row.querySelector(`.remarks-${subjectId}`);
  
  // Only calculate final rating when ALL 4 quarters are filled
  if (count === 4) {
    const average = Math.round(sum / count);
    finalRatingInput.value = average;
    remarksInput.value = average >= 75 ? 'PASSED' : 'FAILED';
  } else {
    finalRatingInput.value = '';
    remarksInput.value = '';
  }

  refreshRemedialDependentCalculations();
  calculateGeneralAverage();
}

function sanitizeGradeInput(input) {
  if (!input) return;
  const val = parseFloat(input.value);
  if (isNaN(val)) return;
  if (val > 100) input.value = 100;
  if (val < 0) input.value = 0;
}

function populateRemedialSubjects() {
  const firstColCells = document.querySelectorAll('.grades-table tbody tr td.subject-name-col');
  const mapehComponents = ['Music', 'Arts', 'Physical Education', 'Health'];
  const names = [];

  firstColCells.forEach(cell => {
    const input = cell.querySelector('input[name^="custom_subject_names"]');
    let name = '';
    if (input) {
      name = (input.value || '').trim();
    } else {
      name = (cell.textContent || '').trim();
    }

    if (!name) return;
    if (mapehComponents.includes(name)) return;
    if (name === 'General Average') return;
    if (!names.includes(name)) names.push(name);
  });

  const options = ['<option value="">-- Select Subject --</option>']
    .concat(names.map(n => `<option value="${n}">${n}</option>`))
    .join('');

  const select1 = document.getElementById('remedial-subject-1');
  const select2 = document.getElementById('remedial-subject-2');
  if (select1) select1.innerHTML = options;
  if (select2) select2.innerHTML = options;
}

function autoFillRemedialFinalRating(remedialNum) {
  const select = document.querySelector(`[name="remedial[${remedialNum}][learning_area]"]`);
  const finalRatingInput = document.querySelector(`[name="remedial[${remedialNum}][final_rating]"]`);
  const selectedName = select ? (select.value || '').trim() : '';

  if (!selectedName || !finalRatingInput) {
    if (finalRatingInput) finalRatingInput.value = '';
    calculateRecomputedGrade(remedialNum);
    return;
  }

  // Find matching subject row by displayed name (supports transfer custom names)
  const rows = document.querySelectorAll('.grades-table tbody tr');
  let matchedFinal = '';

  rows.forEach(row => {
    const subjectCell = row.querySelector('td.subject-name-col');
    if (!subjectCell) return;
    const input = subjectCell.querySelector('input[name^="custom_subject_names"]');
    const displayName = input ? (input.value || '').trim() : (subjectCell.textContent || '').trim();
    if (displayName === selectedName) {
      const finalInput = row.querySelector('input[name*="[final_rating]"]');
      matchedFinal = finalInput ? finalInput.value : '';
    }
  });

  finalRatingInput.value = matchedFinal || '';
  calculateRecomputedGrade(remedialNum);
}

function refreshRemedialDependentCalculations() {
  [1, 2].forEach(n => autoFillRemedialFinalRating(n));
}

function calculateGeneralAverage() {
  const rows = document.querySelectorAll('.grades-table tbody tr');
  const finalsByName = {};

  rows.forEach(row => {
    const subjectName = (row.getAttribute('data-subject-name') || '').trim();
    if (!subjectName) return;
    const finalInput = row.querySelector('input[name*="[final_rating]"]');
    const finalVal = parseFloat(finalInput?.value);
    if (!isNaN(finalVal)) finalsByName[subjectName] = finalVal;
  });

  const mapehComponents = ['Music', 'Arts', 'Physical Education', 'Health'];
  const values = [];

  // Use MAPEH aggregate if available; otherwise use average of components
  if (Object.prototype.hasOwnProperty.call(finalsByName, 'MAPEH')) {
    values.push(finalsByName['MAPEH']);
  } else {
    const componentVals = mapehComponents
      .map(n => finalsByName[n])
      .filter(v => typeof v === 'number' && !isNaN(v));
    // Only include MAPEH in GA when ALL 4 components have a final rating
    if (componentVals.length === mapehComponents.length) {
      values.push(Math.round(componentVals.reduce((a, b) => a + b, 0) / componentVals.length));
    }
  }

  // Include all other subjects except components and General Average
  Object.keys(finalsByName).forEach(name => {
    if (name === 'General Average') return;
    if (name === 'MAPEH') return;
    if (mapehComponents.includes(name)) return;
    values.push(finalsByName[name]);
  });

  const gaFinalInput = document.querySelector('.general-average-final');
  const gaRemarksInput = document.querySelector('.general-average-remarks');
  if (!gaFinalInput || !gaRemarksInput) return;

  if (values.length > 0) {
    const ga = Math.round(values.reduce((a, b) => a + b, 0) / values.length);
    gaFinalInput.value = ga;
    gaRemarksInput.value = ga >= 75 ? 'PASSED' : 'FAILED';
  } else {
    gaFinalInput.value = '';
    gaRemarksInput.value = '';
  }
}

function calculateRecomputedGrade(remedialNum) {
  const finalRatingInput = document.querySelector(`[name="remedial[${remedialNum}][final_rating]"]`);
  const remedialMarkInput = document.querySelector(`[name="remedial[${remedialNum}][remedial_class_mark]"]`);
  const recomputedInput = document.querySelector(`[name="remedial[${remedialNum}][recomputed_final_grade]"]`);
  const remarksInput = document.querySelector(`[name="remedial[${remedialNum}][remarks]"]`);

  const finalRating = parseFloat(finalRatingInput?.value) || 0;
  const remedialMark = parseFloat(remedialMarkInput?.value) || 0;

  if (finalRating > 0 && remedialMark > 0) {
    const recomputed = Math.round((finalRating + remedialMark) / 2);
    if (recomputedInput) recomputedInput.value = recomputed;
    if (remarksInput) remarksInput.value = recomputed >= 75 ? 'Passed' : 'Failed';
  } else {
    if (recomputedInput) recomputedInput.value = '';
    if (remarksInput) remarksInput.value = '';
  }
}

function syncRemedialConductedDates() {
  const from1 = document.querySelector('[name="remedial[1][conducted_from]"]')?.value || '';
  const to1 = document.querySelector('[name="remedial[1][conducted_to]"]')?.value || '';
  const from2 = document.querySelector('[name="remedial[2][conducted_from]"]');
  const to2 = document.querySelector('[name="remedial[2][conducted_to]"]');

  if (from2) from2.value = from1;
  if (to2) to2.value = to1;
}

// Auto-hide alerts
document.addEventListener('DOMContentLoaded', function() {
  const schoolSelect = document.getElementById('schoolRecordSelect');
  if (schoolSelect && preselectedSchoolAttendedId > 0) {
    const targetValue = String(preselectedSchoolAttendedId);
    const hasOption = Array.from(schoolSelect.options).some(opt => opt.value === targetValue);
    if (hasOption) {
      schoolSelect.value = targetValue;
      loadGrades();
    }
  }

  const alerts = document.querySelectorAll('.alert');
  alerts.forEach(alert => {
    setTimeout(() => {
      const bsAlert = new bootstrap.Alert(alert);
      bsAlert.close();
    }, 5000);
  });
});
</script>

<?php include "../templates/footer.php"; ?>
