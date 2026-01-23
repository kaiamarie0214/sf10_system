<?php
session_start();
require_once "../includes/db.php";
require_once "../includes/logger.php";

// Handle AJAX request to get student data (MUST be before any HTML output)
if (isset($_GET['action']) && $_GET['action'] === 'get_student' && isset($_GET['id'])) {
    $student_id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'student' => $student]);
    exit();
}

// Handle Delete Student
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $student_id = (int)$_GET['id'];
    
    // Get student name before deleting
    $stmt = $conn->prepare("SELECT first_name, last_name, lrn FROM students WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    
    if ($student) {
        $student_name = $student['first_name'] . ' ' . $student['last_name'];
        $lrn = $student['lrn'];
        
        // Delete student
        $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
        $stmt->bind_param("i", $student_id);
        
        if ($stmt->execute()) {
            logActivity($conn, $_SESSION['user']['id'], 'DELETE', 'students', $student_id, 
                       "Deleted student: $student_name (LRN: $lrn)");
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to delete student']);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Student not found']);
    }
    exit();
}

// Handle AJAX request to get school record
if (isset($_GET['action']) && $_GET['action'] === 'get_school_record' && isset($_GET['id'])) {
    $record_id = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT sa.*, CONCAT(s.last_name, ', ', s.first_name, ' (LRN: ', s.lrn, ')') as student_name 
        FROM schools_attended sa
        LEFT JOIN students s ON sa.student_id = s.id
        WHERE sa.id = ?
    ");
    $stmt->bind_param("i", $record_id);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'record' => $record]);
    exit();
}

// Handle Delete School Record
if (isset($_GET['action']) && $_GET['action'] === 'delete_school_record' && isset($_GET['id'])) {
    $record_id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("DELETE FROM schools_attended WHERE id = ?");
    $stmt->bind_param("i", $record_id);
    
    if ($stmt->execute()) {
        logActivity($conn, $_SESSION['user']['id'], 'DELETE', 'schools_attended', $record_id, 
                   "Deleted school attendance record");
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to delete record']);
    }
    exit();
}

// Handle AJAX request to get available sections for a grade level
if (isset($_GET['action']) && $_GET['action'] === 'get_sections' && isset($_GET['grade'])) {
    $grade_level = (int)$_GET['grade'];
    
    // Get sections from schools_attended table
    $stmt = $conn->prepare("SELECT DISTINCT section FROM schools_attended 
                           WHERE grade_level = ? AND section IS NOT NULL AND section != '' 
                           ORDER BY section ASC");
    $stmt->bind_param("i", $grade_level);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $sections = [];
    while ($row = $result->fetch_assoc()) {
        $sections[] = $row['section'];
    }
    
    // Also check classes table if it exists
    $classes_query = "SELECT DISTINCT section FROM classes 
                     WHERE grade_level = ? AND section IS NOT NULL AND section != ''";
    $stmt2 = $conn->prepare($classes_query);
    if ($stmt2) {
        $stmt2->bind_param("i", $grade_level);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        
        while ($row = $result2->fetch_assoc()) {
            if (!in_array($row['section'], $sections)) {
                $sections[] = $row['section'];
            }
        }
    }
    
    // Sort sections
    sort($sections);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'sections' => $sections, 'count' => count($sections)]);
    exit();
}

// Handle AJAX request to get student's school history for Grade Progression modal
if (isset($_GET['action']) && $_GET['action'] === 'get_student_history' && isset($_GET['student_id'])) {
    $student_id = (int)$_GET['student_id'];
    
    // Get student info
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    
    // Get all school records for this student
    $stmt = $conn->prepare("SELECT * FROM schools_attended WHERE student_id = ? ORDER BY school_year DESC, grade_level DESC");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'student' => $student, 'records' => $records]);
    exit();
}

// Handle AJAX request to get quarter locks for all student records
if (isset($_GET['action']) && $_GET['action'] === 'get_all_quarter_locks' && isset($_GET['student_id'])) {
    $student_id = (int)$_GET['student_id'];
    
    // Get all school records for this student
    $stmt = $conn->prepare("SELECT id FROM schools_attended WHERE student_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $school_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $locks = [];
    $auto_locks = [];
    $auto_unlocks = [];
    
    foreach ($school_records as $record) {
        $school_id = $record['id'];
        
        // Get quarter locks
        for ($q = 1; $q <= 4; $q++) {
            $stmt = $conn->prepare("SELECT locked FROM quarter_locks WHERE school_attended_id = ? AND quarter = ?");
            $stmt->bind_param("ii", $school_id, $q);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $locks["q{$q}"] = (bool)$row['locked'];
            } else {
                $locks["q{$q}"] = false;
            }
        }
        
        // Get auto-lock times
        $stmt = $conn->prepare("SELECT quarter, auto_lock_time FROM quarter_auto_locks WHERE school_attended_id = ?");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $auto_locks["q{$row['quarter']}"] = $row['auto_lock_time'];
        }
        
        // Get auto-unlock times
        $stmt = $conn->prepare("SELECT quarter, auto_unlock_time FROM quarter_auto_unlocks WHERE school_attended_id = ?");
        $stmt->bind_param("i", $school_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $auto_unlocks["q{$row['quarter']}"] = $row['auto_unlock_time'];
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'locks' => $locks, 'auto_locks' => $auto_locks, 'auto_unlocks' => $auto_unlocks]);
    exit();
}

// Handle AJAX request to toggle quarter lock for all student records
if (isset($_POST['action']) && $_POST['action'] === 'toggle_all_quarter_locks' && isset($_POST['student_id']) && isset($_POST['quarter'])) {
    $student_id = (int)$_POST['student_id'];
    $quarter = (int)$_POST['quarter'];
    $locked = isset($_POST['locked']) ? (int)$_POST['locked'] : 0;
    
    // Get all school records for this student
    $stmt = $conn->prepare("SELECT id FROM schools_attended WHERE student_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $school_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $success_count = 0;
    
    foreach ($school_records as $record) {
        $school_id = $record['id'];
        
        // Check if record exists
        $check = $conn->prepare("SELECT id FROM quarter_locks WHERE school_attended_id = ? AND quarter = ?");
        $check->bind_param("ii", $school_id, $quarter);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        
        if ($existing) {
            $stmt = $conn->prepare("UPDATE quarter_locks SET locked = ?, updated_at = NOW() WHERE school_attended_id = ? AND quarter = ?");
            $stmt->bind_param("iii", $locked, $school_id, $quarter);
        } else {
            $stmt = $conn->prepare("INSERT INTO quarter_locks (school_attended_id, quarter, locked) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $school_id, $quarter, $locked);
        }
        
        if ($stmt->execute()) {
            $success_count++;
            $action = $locked ? 'LOCK' : 'UNLOCK';
            logActivity($conn, $_SESSION['user']['id'], $action, 'quarter_locks', $school_id,
                "Quarter {$quarter} " . ($locked ? "locked" : "unlocked") . " for school record {$school_id}");
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => $success_count > 0, 'updated' => $success_count]);
    exit();
}

// Handle AJAX request to set auto-lock time for all student records
if (isset($_POST['action']) && $_POST['action'] === 'set_auto_lock_time' && isset($_POST['student_id']) && isset($_POST['quarter'])) {
    $student_id = (int)$_POST['student_id'];
    $quarter = (int)$_POST['quarter'];
    $auto_lock_time = $_POST['auto_lock_time'] ?? null;
    
    // Get all school records for this student
    $stmt = $conn->prepare("SELECT id FROM schools_attended WHERE student_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $school_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $success_count = 0;
    
    foreach ($school_records as $record) {
        $school_id = $record['id'];
        
        if (empty($auto_lock_time)) {
            // Delete auto-lock setting
            $stmt = $conn->prepare("DELETE FROM quarter_auto_locks WHERE school_attended_id = ? AND quarter = ?");
            $stmt->bind_param("ii", $school_id, $quarter);
            if ($stmt->execute()) {
                $success_count++;
                logActivity($conn, $_SESSION['user']['id'], 'DELETE', 'quarter_auto_locks', $school_id,
                    "Removed auto-lock time for Quarter {$quarter} on school record {$school_id}");
            }
        } else {
            // Check if record exists
            $check = $conn->prepare("SELECT id FROM quarter_auto_locks WHERE school_attended_id = ? AND quarter = ?");
            $check->bind_param("ii", $school_id, $quarter);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            
            if ($existing) {
                $stmt = $conn->prepare("UPDATE quarter_auto_locks SET auto_lock_time = ?, updated_at = NOW() WHERE school_attended_id = ? AND quarter = ?");
                $stmt->bind_param("sii", $auto_lock_time, $school_id, $quarter);
            } else {
                $stmt = $conn->prepare("INSERT INTO quarter_auto_locks (school_attended_id, quarter, auto_lock_time) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $school_id, $quarter, $auto_lock_time);
            }
            
            if ($stmt->execute()) {
                $success_count++;
                logActivity($conn, $_SESSION['user']['id'], 'UPDATE', 'quarter_auto_locks', $school_id,
                    "Set auto-lock time for Quarter {$quarter} to {$auto_lock_time} on school record {$school_id}");
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => $success_count > 0, 'updated' => $success_count]);
    exit();
}

// Handle AJAX request to set auto-unlock time for all student records
if (isset($_POST['action']) && $_POST['action'] === 'set_auto_unlock_time' && isset($_POST['student_id']) && isset($_POST['quarter'])) {
    $student_id = (int)$_POST['student_id'];
    $quarter = (int)$_POST['quarter'];
    $auto_unlock_time = $_POST['auto_unlock_time'] ?? null;
    
    // Get all school records for this student
    $stmt = $conn->prepare("SELECT id FROM schools_attended WHERE student_id = ?");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $school_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $success_count = 0;
    
    foreach ($school_records as $record) {
        $school_id = $record['id'];
        
        if (empty($auto_unlock_time)) {
            // Delete auto-unlock setting
            $stmt = $conn->prepare("DELETE FROM quarter_auto_unlocks WHERE school_attended_id = ? AND quarter = ?");
            $stmt->bind_param("ii", $school_id, $quarter);
            if ($stmt->execute()) {
                $success_count++;
                logActivity($conn, $_SESSION['user']['id'], 'DELETE', 'quarter_auto_unlocks', $school_id,
                    "Removed auto-unlock time for Quarter {$quarter} on school record {$school_id}");
            }
        } else {
            // Check if record exists
            $check = $conn->prepare("SELECT id FROM quarter_auto_unlocks WHERE school_attended_id = ? AND quarter = ?");
            $check->bind_param("ii", $school_id, $quarter);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            
            if ($existing) {
                $stmt = $conn->prepare("UPDATE quarter_auto_unlocks SET auto_unlock_time = ?, updated_at = NOW() WHERE school_attended_id = ? AND quarter = ?");
                $stmt->bind_param("sii", $auto_unlock_time, $school_id, $quarter);
            } else {
                $stmt = $conn->prepare("INSERT INTO quarter_auto_unlocks (school_attended_id, quarter, auto_unlock_time) VALUES (?, ?, ?)");
                $stmt->bind_param("iis", $school_id, $quarter, $auto_unlock_time);
            }
            
            if ($stmt->execute()) {
                $success_count++;
                logActivity($conn, $_SESSION['user']['id'], 'UPDATE', 'quarter_auto_unlocks', $school_id,
                    "Set auto-unlock time for Quarter {$quarter} to {$auto_unlock_time} on school record {$school_id}");
            }
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => $success_count > 0, 'updated' => $success_count]);
    exit();
}

// Handle Add Student (MUST be before header.php to allow redirects)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    // Convert names to uppercase
    $_POST['last_name'] = strtoupper($_POST['last_name']);
    $_POST['first_name'] = strtoupper($_POST['first_name']);
    $_POST['middle_name'] = !empty($_POST['middle_name']) ? strtoupper($_POST['middle_name']) : '';
    $_POST['suffix'] = !empty($_POST['suffix']) ? strtoupper($_POST['suffix']) : '';
    
    // Check for duplicate LRN
    $check_lrn = $conn->prepare("SELECT id FROM students WHERE lrn = ?");
    $check_lrn->bind_param("s", $_POST['lrn']);
    $check_lrn->execute();
    $check_lrn->store_result();

    // Check for duplicate name (first name + last name + middle name) - handle empty middle names
    $middle_name = $_POST['middle_name'];
    $check_name = $conn->prepare("SELECT id, CONCAT(first_name, ' ', middle_name, ' ', last_name) as full_name 
                                  FROM students 
                                  WHERE LOWER(first_name) = LOWER(?) 
                                  AND LOWER(last_name) = LOWER(?) 
                                  AND (LOWER(middle_name) = LOWER(?) OR (middle_name = '' AND ? = '') OR (middle_name IS NULL AND ? = ''))");
    $check_name->bind_param("sssss", $_POST['first_name'], $_POST['last_name'], $middle_name, $middle_name, $middle_name);
    $check_name->execute();
    $check_name->store_result();

    if ($check_lrn->num_rows > 0) {
        $_SESSION['error_message'] = "Student with LRN '{$_POST['lrn']}' already exists. Please check the student's record.";
        header("Location: records.php");
        exit();
    } elseif ($check_name->num_rows > 0) {
        $_SESSION['error_message'] = "A student with a similar name already exists: '{$_POST['first_name']} {$middle_name} {$_POST['last_name']}'. Please verify the student information or check if they are already enrolled.";
        header("Location: records.php");
        exit();
    } else {
        $stmt = $conn->prepare("INSERT INTO students 
            (lrn, last_name, first_name, middle_name, suffix, gender, birthdate,
             credential_presented, eligibility_school_name, eligibility_school_id, eligibility_school_address,
             pept_rating, pept_exam_date, pept_testing_center, credential_other_details, eligibility_remark)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->bind_param(
            "ssssssssssssssss",
            $_POST['lrn'], $_POST['last_name'], $_POST['first_name'], $_POST['middle_name'], $_POST['suffix'],
            $_POST['gender'], $_POST['birthdate'], $_POST['credential_presented'],
            $_POST['eligibility_school_name'], $_POST['eligibility_school_id'], $_POST['eligibility_school_address'],
            $_POST['pept_rating'], $_POST['pept_exam_date'], $_POST['pept_testing_center'],
            $_POST['credential_other_details'], $_POST['eligibility_remark']
        );

        if ($stmt->execute()) {
            $new_student_id = $conn->insert_id;
            $student_name = $_POST['first_name'] . ' ' . $_POST['last_name'];
            
            // Log the activity
            logActivity($conn, $_SESSION['user']['id'], 'INSERT', 'students', $new_student_id, 
                       "Added new student: $student_name (LRN: {$_POST['lrn']})");
            
            // Set session flag to open modal on next page load
            $_SESSION['open_progression_modal'] = $new_student_id;
            $_SESSION['success_message'] = "Student added successfully. You can now add school records.";
            
            // Redirect to prevent form resubmission and ensure clean state
            header("Location: records.php");
            exit();
        }
    }
}

// Handle Edit Student (MUST be before header.php to allow redirects)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $student_id = (int)$_POST['student_id'];
    
    // Convert names to uppercase
    $_POST['last_name'] = strtoupper($_POST['last_name']);
    $_POST['first_name'] = strtoupper($_POST['first_name']);
    $_POST['middle_name'] = !empty($_POST['middle_name']) ? strtoupper($_POST['middle_name']) : '';
    $_POST['suffix'] = !empty($_POST['suffix']) ? strtoupper($_POST['suffix']) : '';
    
    $stmt = $conn->prepare("UPDATE students SET 
      lrn = ?, last_name = ?, first_name = ?, middle_name = ?, suffix = ?,
      gender = ?, birthdate = ?, credential_presented = ?,
      eligibility_school_name = ?, eligibility_school_id = ?, eligibility_school_address = ?,
      pept_rating = ?, pept_exam_date = ?, pept_testing_center = ?, credential_other_details = ?, eligibility_remark = ?
      WHERE id = ?");
    
    $stmt->bind_param("ssssssssssssssssi",
      $_POST['lrn'], $_POST['last_name'], $_POST['first_name'], $_POST['middle_name'], $_POST['suffix'],
      $_POST['gender'], $_POST['birthdate'], $_POST['credential_presented'],
      $_POST['eligibility_school_name'], $_POST['eligibility_school_id'], $_POST['eligibility_school_address'],
      $_POST['pept_rating'], $_POST['pept_exam_date'], $_POST['pept_testing_center'], $_POST['credential_other_details'], $_POST['eligibility_remark'],
      $student_id
    );
    
    if ($stmt->execute()) {
        $student_name = $_POST['first_name'] . ' ' . $_POST['last_name'];
        logActivity($conn, $_SESSION['user']['id'], 'UPDATE', 'students', $student_id, 
                   "Updated student: $student_name (LRN: {$_POST['lrn']})");
        
        $_SESSION['success_message'] = "Student updated successfully.";
        header("Location: records.php");
        exit();
    }
}

// Handle Add School Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_school_record') {
  // Permission check: only admins may add school records
  $current_user = $_SESSION['user'] ?? null;
  if (!$current_user || ($current_user['role'] ?? '') !== 'admin') {
    $_SESSION['error_message'] = 'You do not have permission to add school records.';
    header('Location: records.php');
    exit();
  }
  // Build school_year range from the two year inputs
  $school_year = $_POST['school_year_from'] . '-' . $_POST['school_year_to'];

  // Check for duplicate record (same student, grade level, and school year)
  $check_duplicate = $conn->prepare("SELECT id, section FROM schools_attended 
                     WHERE student_id = ? AND grade_level = ? AND school_year = ?");
  $check_duplicate->bind_param("iis", $_POST['student_id'], $_POST['grade_level'], $school_year);
  $check_duplicate->execute();
  $result = $check_duplicate->get_result();

  if ($result->num_rows > 0) {
    $existing = $result->fetch_assoc();
    $_SESSION['error_message'] = "A school record already exists for this student in Grade {$_POST['grade_level']} - {$existing['section']} for School Year {$school_year}.";
  } else {
    // Always set is_transfer = 1 (transfer) by default when adding a new school record
    $stmt = $conn->prepare("INSERT INTO schools_attended 
      (student_id, school_name, school_id, district, division, region, grade_level, section, school_year, adviser_name, is_transfer)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");

    $stmt->bind_param("isssssisss",
      $_POST['student_id'], $_POST['school_name'], $_POST['school_id'], 
      $_POST['district'], $_POST['division'], $_POST['region'],
      $_POST['grade_level'], $_POST['section'], $school_year, $_POST['adviser_name']
    );

    if ($stmt->execute()) {
      // Get student info for detailed log
      $student_query = $conn->prepare("SELECT first_name, last_name, lrn FROM students WHERE id = ?");
      $student_query->bind_param("i", $_POST['student_id']);
      $student_query->execute();
      $student_info = $student_query->get_result()->fetch_assoc();
      $student_name = $student_info['first_name'] . ' ' . $student_info['last_name'];

      logActivity($conn, $_SESSION['user']['id'], 'INSERT', 'schools_attended', $conn->insert_id, 
             "Added Grade {$_POST['grade_level']} record for $student_name (LRN: {$student_info['lrn']}) at {$_POST['school_name']} - Section {$_POST['section']} (TRANSFER)");
      $_SESSION['success_message'] = "School record added successfully as TRANSFER.";
    } else {
      $_SESSION['error_message'] = "Failed to add school record.";
    }
  }
  header("Location: records.php");
  exit();
}

// Handle Edit School Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_school_record') {
    $record_id = (int)$_POST['record_id'];
    
    // Build school_year range from the two year inputs
    $school_year = $_POST['school_year_from'] . '-' . $_POST['school_year_to'];
    
    // Check for duplicate record (excluding current record)
    $check_duplicate = $conn->prepare("SELECT id, section FROM schools_attended 
                                       WHERE student_id = ? AND grade_level = ? AND school_year = ? AND id != ?");
    $check_duplicate->bind_param("iisi", $_POST['student_id'], $_POST['grade_level'], $school_year, $record_id);
    $check_duplicate->execute();
    $result = $check_duplicate->get_result();
    
    if ($result->num_rows > 0) {
        $existing = $result->fetch_assoc();
        $_SESSION['error_message'] = "A school record already exists for this student in Grade {$_POST['grade_level']} - {$existing['section']} for School Year {$school_year}.";
    } else {
        $stmt = $conn->prepare("UPDATE schools_attended SET 
            student_id = ?, school_name = ?, school_id = ?, district = ?, division = ?, region = ?,
            grade_level = ?, section = ?, school_year = ?, adviser_name = ?
            WHERE id = ?");
        
        $stmt->bind_param("isssssisssi",
            $_POST['student_id'], $_POST['school_name'], $_POST['school_id'], 
            $_POST['district'], $_POST['division'], $_POST['region'],
            $_POST['grade_level'], $_POST['section'], $school_year, $_POST['adviser_name'],
            $record_id
        );
        
        if ($stmt->execute()) {
            // Get student name for log
            $student_query = $conn->prepare("SELECT first_name, last_name, lrn FROM students WHERE id = ?");
            $student_query->bind_param("i", $_POST['student_id']);
            $student_query->execute();
            $student_info = $student_query->get_result()->fetch_assoc();
            $student_name = $student_info['first_name'] . ' ' . $student_info['last_name'];
            
            logActivity($conn, $_SESSION['user']['id'], 'UPDATE', 'schools_attended', $record_id, 
                       "Updated Grade {$_POST['grade_level']} - Section {$_POST['section']} record for $student_name (LRN: {$student_info['lrn']}) at {$_POST['school_name']}");
            $_SESSION['success_message'] = "School record updated successfully.";
        } else {
            $_SESSION['error_message'] = "Failed to update school record.";
        }
    }
    header("Location: records.php");
    exit();
}

include "../templates/header.php";

$user = $_SESSION['user'];
$error = "";
$success = "";

// Get success/error messages from session if they exist
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Get all students with their current grade/section from schools_attended
// Order by: students without school records first (NEW), then by creation date
$sort = $_GET['sort'] ?? '';

$order_clause = "has_record ASC, s.created_at DESC"; // default
switch ($sort) {
  case 'name-asc':
    $order_clause = "s.last_name ASC, s.first_name ASC";
    break;
  case 'name-desc':
    $order_clause = "s.last_name DESC, s.first_name DESC";
    break;
  case 'grade-asc':
    $order_clause = "sa.grade_level ASC, sa.section ASC";
    break;
  case 'grade-desc':
    $order_clause = "sa.grade_level DESC, sa.section DESC";
    break;
  case 'gender-male':
    $order_clause = "(CASE WHEN s.gender = 'Male' THEN 0 WHEN s.gender = 'Female' THEN 1 ELSE 2 END), s.last_name ASC";
    break;
  case 'gender-female':
    $order_clause = "(CASE WHEN s.gender = 'Female' THEN 0 WHEN s.gender = 'Male' THEN 1 ELSE 2 END), s.last_name ASC";
    break;
  case 'filter-male':
  case 'filter-female':
    // filtering handled client-side/JS, keep default ordering
    $order_clause = "s.last_name ASC";
    break;
}

$students_query = "SELECT s.*, 
           sa.id as school_attended_id,
           sa.grade_level, 
           sa.section,
           sa.school_year,
           CASE WHEN sa.id IS NULL THEN 0 ELSE 1 END as has_record
           FROM students s
           LEFT JOIN schools_attended sa ON s.id = sa.student_id 
           AND sa.id = (
             SELECT id 
             FROM schools_attended 
             WHERE student_id = s.id
             ORDER BY grade_level DESC, school_year DESC
             LIMIT 1
           )
           ORDER BY {$order_clause}";
$students = $conn->query($students_query);

$is_admin = $user['role'] === 'admin';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="page-header mb-0">
        <h2><i class="bi bi-people-fill"></i> All Students</h2>
        <p class="subtitle">View and manage all student records</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" onclick="openAddStudentModal()">
            <i class="bi bi-person-plus"></i> Add New Student
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-danger alert-dismissible fade show" id="errorAlert">
    <i class="bi bi-exclamation-circle"></i> <?= $error ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if (!empty($success)): ?>
  <div class="alert alert-success alert-dismissible fade show" id="successAlert">
    <i class="bi bi-check-circle"></i> <?= $success ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<script>
// Auto-dismiss alerts with fade out
document.addEventListener('DOMContentLoaded', function() {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    if (successAlert) {
        setTimeout(() => {
            successAlert.style.transition = 'opacity 0.5s ease-out';
            successAlert.style.opacity = '0';
            setTimeout(() => successAlert.remove(), 500);
        }, 3000);
    }
    
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.style.transition = 'opacity 0.5s ease-out';
            errorAlert.style.opacity = '0';
            setTimeout(() => errorAlert.remove(), 500);
        }, 7000);
    }
    
    // Auto-open Grade Progression modal if student was just created
    <?php 
    if (isset($_SESSION['open_progression_modal'])): 
        $modal_student_id = $_SESSION['open_progression_modal'];
        unset($_SESSION['open_progression_modal']); // Clear it immediately
    ?>
    setTimeout(() => {
        viewGradeProgression(<?= $modal_student_id ?>);
    }, 300); // Quick delay to show success message
    <?php endif; ?>
});

// Sorting dropdown behavior: navigate with `sort` param
document.addEventListener('DOMContentLoaded', function() {
  const sortSelect = document.getElementById('sortStudents');
  if (sortSelect) {
    sortSelect.addEventListener('change', function() {
      const val = this.value;
      const url = new URL(window.location.href);
      if (val === 'all') {
        url.searchParams.delete('sort');
      } else {
        url.searchParams.set('sort', val);
      }
      window.location.href = url.toString();
    });
  }

  // Filter options 'filter-male' and 'filter-female' can be handled client-side after load
  const studentSearch = document.getElementById('studentSearch');
  const clearBtn = document.getElementById('clearStudentSearch');
  if (studentSearch) {
    studentSearch.addEventListener('input', function() {
      clearBtn.style.display = this.value ? 'inline-block' : 'none';
      const q = this.value.toLowerCase();
      document.querySelectorAll('.student-row').forEach(row => {
        const name = row.getAttribute('data-name').toLowerCase();
        const lrn = row.getAttribute('data-lrn').toLowerCase();
        row.style.display = (name.includes(q) || lrn.includes(q)) ? '' : 'none';
      });
      updateStudentCount();
    });
    if (clearBtn) {
      clearBtn.addEventListener('click', function() {
        studentSearch.value = '';
        this.style.display = 'none';
        document.querySelectorAll('.student-row').forEach(row => row.style.display = '');
        updateStudentCount();
      });
    }
  }
  // Apply initial filter if URL contains filter param
  const params = new URL(window.location.href).searchParams;
  const initialSort = params.get('sort');
  if (initialSort === 'filter-male' || initialSort === 'filter-female') {
    const wanted = initialSort === 'filter-male' ? 'male' : 'female';
    document.querySelectorAll('.student-row').forEach(row => {
      const gender = (row.getAttribute('data-gender') || '').toLowerCase();
      row.style.display = gender === wanted ? '' : 'none';
    });
    // set select value visually
    const sortSelect2 = document.getElementById('sortStudents');
    if (sortSelect2) sortSelect2.value = initialSort;
    updateStudentCount();
  }
  // initial count
  updateStudentCount();
});
</script>

<script>
function updateStudentCount() {
  const rows = Array.from(document.querySelectorAll('.student-row'));
  const visible = rows.filter(r => r.style.display !== 'none').length;
  const badge = document.getElementById('studentCount');
  if (badge) badge.textContent = visible;
}
</script>

<!-- Students List -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>
      <i class="bi bi-people"></i> All Students
      <span class="badge bg-secondary ms-2" id="studentCount">0</span>
    </span>
    <div class="d-flex gap-2">
      <select id="sortStudents" class="form-select form-select-sm" style="width: auto;">
        <option value="all" <?= ($sort === 'all' || $sort === '') ? 'selected' : '' ?>>All Students</option>
        <option value="name-asc" <?= ($sort === 'name-asc') ? 'selected' : '' ?>>Name (A-Z)</option>
        <option value="name-desc" <?= ($sort === 'name-desc') ? 'selected' : '' ?>>Name (Z-A)</option>
        <option value="grade-asc" <?= ($sort === 'grade-asc') ? 'selected' : '' ?>>Grade Level (1-6)</option>
        <option value="grade-desc" <?= ($sort === 'grade-desc') ? 'selected' : '' ?>>Grade Level (6-1)</option>
        <option value="gender-male" <?= ($sort === 'gender-male') ? 'selected' : '' ?>>Gender (Male First)</option>
        <option value="gender-female" <?= ($sort === 'gender-female') ? 'selected' : '' ?>>Gender (Female First)</option>
        <option value="filter-male" <?= ($sort === 'filter-male') ? 'selected' : '' ?>>All Male</option>
        <option value="filter-female" <?= ($sort === 'filter-female') ? 'selected' : '' ?>>All Female</option>
      </select>
      <div style="position: relative; width: 250px;">
        <i class="bi bi-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #6c757d; pointer-events: none;"></i>
        <input type="text" class="form-control form-control-sm" id="studentSearch" placeholder="Search by name or LRN..." style="padding-left: 35px; padding-right: 30px;">
        <button type="button" id="clearStudentSearch" class="btn btn-sm" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); padding: 0; width: 20px; height: 20px; border: none; background: transparent; display: none;">
          <i class="bi bi-x-circle-fill" style="color: #6c757d;"></i>
        </button>
      </div>
    </div>
  </div>
  <div class="card-body" style="padding: 0;">
    <div class="table-responsive" style="overflow-x: auto; -webkit-overflow-scrolling: touch; position: relative;">
      <table class="table table-hover mb-0" id="studentsTable" style="min-width: 700px; position: relative;">
        <thead>
          <tr>
            <th>LRN</th>
            <th>Name</th>
            <th>Gender</th>
            <th>Birthdate</th>
            <th>Grade/Section</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while($student = $students->fetch_assoc()): 
            $isNew = ($student['has_record'] == 0); // Student has no school records
          ?>
          <tr class="student-row" 
              style="cursor: pointer;" 
              data-student-id="<?= $student['id'] ?>"
              data-name="<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>"
              data-lrn="<?= htmlspecialchars($student['lrn']) ?>"
              data-grade="<?= $student['grade_level'] ?? '0' ?>"
              data-gender="<?= htmlspecialchars($student['gender']) ?>"
              data-new="<?= $isNew ? '1' : '0' ?>">
            <td><?= htmlspecialchars($student['lrn']) ?></td>
            <td style="<?= $isNew ? 'font-weight: 600;' : '' ?>">
              <i class="bi bi-person-circle"></i> <?= htmlspecialchars(strtoupper($student['last_name'] . ', ' . $student['first_name'])) ?>
              <?php if ($isNew): ?>
                <span class="badge bg-success ms-2">NEW</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($student['gender']) ?></td>
            <td><?= date('M d, Y', strtotime($student['birthdate'])) ?></td>
            <td>
              <?php if ($student['grade_level']): ?>
                <span class="badge bg-primary">Grade <?= htmlspecialchars($student['grade_level'] . ' - ' . ($student['section'] ?? '-')) ?></span>
              <?php else: ?>
                <span class="badge bg-secondary">No records</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="dropdown">
                <button class="btn btn-sm btn-secondary dropdown-toggle" type="button" id="actionsDropdown<?= $student['id'] ?>" data-bs-toggle="dropdown" aria-expanded="false">
                  <i class="bi bi-three-dots-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="actionsDropdown<?= $student['id'] ?>">
                  <li>
                    <a class="dropdown-item" href="grades.php?student_id=<?= $student['id'] ?><?= $student['school_attended_id'] ? '&school_attended_id=' . $student['school_attended_id'] . '&school_year=' . urlencode($student['school_year']) : '' ?>&open_new_tab=1">
                      <i class="bi bi-clipboard-check text-info me-2"></i>View Grades
                    </a>
                  </li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item" href="javascript:void(0)" onclick="viewGradeProgression(<?= $student['id'] ?>)">
                    <i class="bi bi-plus-circle text-success me-2"></i>Add Record
                  </a></li>
                  <li>
                    <a class="dropdown-item" href="sf10_preview.php?student_id=<?= $student['id'] ?>">
                      <i class="bi bi-file-earmark-pdf text-success me-2"></i>Preview SF10
                    </a>
                  </li>
                  <li><a class="dropdown-item" href="#" onclick="viewStudent(<?= $student['id'] ?>); return false;">
                    <i class="bi bi-eye text-primary me-2"></i>View Details
                  </a></li>
                  <?php if ($is_admin): ?>
                  <li><a class="dropdown-item" href="#" onclick="editStudent(<?= $student['id'] ?>); return false;">
                    <i class="bi bi-pencil text-warning me-2"></i>Edit Student
                  </a></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item text-danger" href="#" onclick="deleteStudent(<?= $student['id'] ?>, '<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>'); return false;">
                    <i class="bi bi-trash me-2"></i>Delete Student
                  </a></li>
                  <?php endif; ?>
                </ul>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<style>
/* Style for dropdown form to look like dropdown item */
.dropdown-item-form {
  margin: 0;
  padding: 0;
}

.dropdown-item-form .dropdown-item {
  width: 100%;
  text-align: left;
  background: none;
  border: none;
  padding: 0.35rem 0.75rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  font-size: 0.875rem;
}

.dropdown-item-form .dropdown-item:hover {
  background-color: #f8f9fa;
}

/* Ensure dropdown menu has proper width and styling */
.dropdown-menu {
  min-width: 160px;
  font-size: 0.875rem;
  z-index: 9999;
  position: fixed !important;
}

/* Fix dropdown positioning on mobile */
@media (max-width: 768px) {
  .dropdown {
    position: static !important;
  }
  
  .dropdown-menu {
    position: fixed !important;
    z-index: 10000 !important;
  }
}

.dropdown-item {
  display: flex;
  align-items: center;
  padding: 0.35rem 0.75rem;
  font-size: 0.875rem;
}

.dropdown-item i {
  width: 18px;
  text-align: center;
}

.dropdown-divider {
  margin: 0.25rem 0;
}

/* Hover effect for clickable rows */
.student-row:hover {
  background-color: rgba(255, 255, 255, 0.05);
}

/* Mobile responsive header controls */
@media (max-width: 768px) {
  .card-header {
    flex-direction: column !important;
    align-items: stretch !important;
    gap: 0.75rem;
  }
  
  .card-header .d-flex.gap-2 {
    flex-direction: column;
    width: 100%;
    gap: 0.5rem !important;
  }
  
  .card-header .d-flex.gap-2 > div {
    width: 100% !important;
  }
  
  #sortStudents {
    width: 100% !important;
  }
  
  .table-responsive {
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch;
  }
  
  #studentsTable {
    min-width: 700px;
  }
}

/* Mobile modal - make footer sticky */
@media (max-width: 768px) {
  .modal-dialog:not(#deleteStudentModal .modal-dialog):not(#deleteSchoolRecordModal .modal-dialog) {
    margin: 0;
    max-width: 100%;
    width: 100%;
    height: 100vh;
  }
  
  .modal-content:not(#deleteStudentModal .modal-content):not(#deleteSchoolRecordModal .modal-content) {
    height: 100vh;
    border-radius: 0;
  }
  
  .modal-header {
    position: sticky;
    top: 0;
    z-index: 1050;
    background-color: var(--bs-modal-bg);
    border-bottom: 1px solid var(--bs-border-color);
  }
  
  .modal-footer {
    position: sticky;
    bottom: 0;
    z-index: 1050;
    background-color: var(--bs-modal-bg);
    border-top: 1px solid var(--bs-border-color);
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.1);
  }
  
  .modal-body {
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }
}

/* Mobile delete modal - centered with auto height and stacked buttons */
@media (max-width: 768px) {
  #deleteStudentModal .modal-dialog,
  #deleteSchoolRecordModal .modal-dialog {
    margin: 1.75rem auto;
    max-width: calc(100% - 2rem);
  }
  
  #deleteStudentModal .modal-footer,
  #deleteSchoolRecordModal .modal-footer {
    flex-direction: column;
    gap: 0.5rem;
  }
  
  #deleteStudentModal .modal-footer .btn,
  #deleteSchoolRecordModal .modal-footer .btn {
    width: 100%;
    margin: 0;
  }
}
</style>

<script>
// Use event delegation - attach to document for maximum reliability
(function() {
    const searchInput = document.getElementById('studentSearch');
    const clearSearchBtn = document.getElementById('clearStudentSearch');
    const sortSelect = document.getElementById('sortStudents');
    const tableBody = document.querySelector('#studentsTable tbody');
    const studentRows = Array.from(tableBody.querySelectorAll('.student-row'));
    const studentCount = document.getElementById('studentCount');
    
    // Function to update visible student count
    function updateStudentCount() {
        const visibleRows = studentRows.filter(row => row.style.display !== 'none');
        const count = visibleRows.length;
        
        studentCount.textContent = count;
        
        if (count === 0) {
            studentCount.className = 'badge bg-secondary ms-2';
        } else {
            studentCount.className = 'badge bg-primary ms-2';
        }
    }
    
    // Initialize count on page load
    updateStudentCount();
    
    document.addEventListener('click', function(e) {
        const row = e.target.closest('tr.student-row');
        
        // If clicked on a row but not on the dropdown itself
        if (row && !e.target.closest('.dropdown')) {
            const studentId = row.getAttribute('data-student-id');
            const dropdownBtn = document.getElementById('actionsDropdown' + studentId);
            
            if (dropdownBtn) {
                const dropdown = bootstrap.Dropdown.getOrCreateInstance(dropdownBtn);
                dropdown.toggle();
            }
        }
    });
    
    // Search and Sort functionality
    
    if (searchInput && clearSearchBtn) {
        // Show/hide clear button based on input value
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            clearSearchBtn.style.display = this.value ? 'block' : 'none';
            
            studentRows.forEach(row => {
                const name = row.getAttribute('data-name').toLowerCase();
                const lrn = row.getAttribute('data-lrn').toLowerCase();
                
                if (name.includes(searchTerm) || lrn.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            
            updateStudentCount();
        });
        
        // Clear search input when X button is clicked
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            clearSearchBtn.style.display = 'none';
            studentRows.forEach(row => row.style.display = '');
            updateStudentCount();
        });
    }
    
    // Sort functionality
    if (sortSelect) {
        sortSelect.addEventListener('change', function() {
            const sortType = this.value;
            let sortedRows = [...studentRows];
            
            // Handle filtering first
            if (sortType === 'filter-male') {
                // Show only male students
                studentRows.forEach(row => {
                    const gender = row.getAttribute('data-gender');
                    row.style.display = gender === 'Male' ? '' : 'none';
                });
                updateStudentCount();
                return;
            } else if (sortType === 'filter-female') {
                // Show only female students
                studentRows.forEach(row => {
                    const gender = row.getAttribute('data-gender');
                    row.style.display = gender === 'Female' ? '' : 'none';
                });
                updateStudentCount();
                return;
            } else if (sortType === 'all') {
                // Show all students
                studentRows.forEach(row => row.style.display = '');
                updateStudentCount();
                return;
            }
            
            // Show all rows before sorting
            studentRows.forEach(row => row.style.display = '');
            
            sortedRows.sort((a, b) => {
                // Always keep NEW students at top
                const aIsNew = a.getAttribute('data-new') === '1';
                const bIsNew = b.getAttribute('data-new') === '1';
                
                if (aIsNew && !bIsNew) return -1;
                if (!aIsNew && bIsNew) return 1;
                
                // Then sort by selected criteria
                if (sortType === 'name-asc') {
                    return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                } else if (sortType === 'name-desc') {
                    return b.getAttribute('data-name').localeCompare(a.getAttribute('data-name'));
                } else if (sortType === 'grade-asc') {
                    return parseInt(a.getAttribute('data-grade')) - parseInt(b.getAttribute('data-grade'));
                } else if (sortType === 'grade-desc') {
                    return parseInt(b.getAttribute('data-grade')) - parseInt(a.getAttribute('data-grade'));
                } else if (sortType === 'gender-male') {
                    const aGender = a.getAttribute('data-gender');
                    const bGender = b.getAttribute('data-gender');
                    if (aGender === 'Male' && bGender !== 'Male') return -1;
                    if (aGender !== 'Male' && bGender === 'Male') return 1;
                    return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                } else if (sortType === 'gender-female') {
                    const aGender = a.getAttribute('data-gender');
                    const bGender = b.getAttribute('data-gender');
                    if (aGender === 'Female' && bGender !== 'Female') return -1;
                    if (aGender !== 'Female' && bGender === 'Female') return 1;
                    return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
                }
            });
            
            // Re-append rows in sorted order
            sortedRows.forEach(row => tableBody.appendChild(row));
        });
    }
})();
</script>

<!-- View Student Modal -->
<div class="modal fade" id="viewStudentModal" tabindex="-1" style="margin-top: 80px;">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="max-height: 85vh; display: flex; flex-direction: column;">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-eye"></i> Student Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="overflow-y: auto; flex: 1; padding: 1.5rem;">
        <div class="row">
          <div class="col-md-6">
            <h6 class="mb-3" style="color: var(--text-color);">Basic Information</h6>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">LRN</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_lrn"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">Last Name</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_last_name"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">First Name</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_first_name"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">Middle Name</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_middle_name"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">Suffix</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_suffix"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">Gender</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_gender"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">Birthdate</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_birthdate"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">Others (Pls. Specify)</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_credential_other_details"></div>
            </div>
          </div>
          <div class="col-md-6">
            <h6 class="mb-3" style="color: var(--text-color);">Eligibility for Elementary Enrollment</h6>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">Credential Presented</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_credential"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">School Name</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_elig_school_name"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">School ID</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_elig_school_id"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">School Address</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_elig_school_address"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">PEPT Rating</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_pept_rating"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">Exam Date</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_pept_date"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">Testing Center</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_pept_center"></div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-bold" style="color: var(--text-color);">Remarks</label>
              <div class="form-control-plaintext" style="color: var(--text-color);" id="view_eligibility_remark"></div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
  <div class="modal-dialog modal-xl" style="margin-top: 80px;">
    <div class="modal-content">
      <form method="POST" style="display: flex; flex-direction: column; max-height: 85vh;">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-plus"></i> Add New Student</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" style="overflow-y: auto; flex: 1;">
          <h6 class="mb-3">Personal Information</h6>
          <div class="row mb-3">
            <div class="col-md-2">
              <label class="form-label">LRN</label>
              <input type="text" name="lrn" class="form-control" placeholder="LRN" pattern="[0-9]{12}" maxlength="12" inputmode="numeric" title="LRN must be 12 digits" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Last Name</label>
              <input name="last_name" class="form-control" placeholder="Last Name" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">First Name</label>
              <input name="first_name" class="form-control" placeholder="First Name" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Middle Name</label>
              <input name="middle_name" class="form-control" placeholder="Middle Name">
            </div>
            <div class="col-md-1">
              <label class="form-label">Ext.</label>
              <input name="suffix" class="form-control" placeholder="Jr, II">
            </div>
          </div>
          <div class="row mb-3">
            <div class="col-md-3">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-control" required>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Birthdate</label>
              <input type="date" name="birthdate" class="form-control" required>
            </div>
          </div>

          <h6 class="mt-4 mb-3">Eligibility for Elementary Enrollment</h6>
          <div class="mb-3">
            <label class="form-label">Credential Presented for Grade 1:</label><br>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="credential_presented" value="Kinder Progress Report">
              <label class="form-check-label">Kinder Progress Report</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="credential_presented" value="ECCD Checklist">
              <label class="form-check-label">ECCD Checklist</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="credential_presented" value="Kindergarten Certificate of Completion">
              <label class="form-check-label">Kindergarten Certificate</label>
            </div>
          </div>
          <div class="row mb-3">
            <div class="col-md-4">
              <label class="form-label">Name of School</label>
              <input name="eligibility_school_name" class="form-control" placeholder="School Name">
            </div>
            <div class="col-md-4">
              <label class="form-label">School ID</label>
              <input name="eligibility_school_id" class="form-control" placeholder="School ID">
            </div>
            <div class="col-md-4">
              <label class="form-label">School Address</label>
              <input name="eligibility_school_address" class="form-control" placeholder="School Address">
            </div>
          </div>

          <h6 class="mt-4 mb-3">Other Credentials</h6>
          <div class="row mb-3">
            <div class="col-md-3">
              <label class="form-label">PEPT Rating</label>
              <input name="pept_rating" class="form-control" placeholder="PEPT Rating">
            </div>
            <div class="col-md-3">
              <label class="form-label">Exam Date</label>
              <input type="date" name="pept_exam_date" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label">Testing Center</label>
              <input name="pept_testing_center" class="form-control" placeholder="Testing Center">
            </div>
            <div class="col-md-3">
              <label class="form-label">Others (Pls. Specify):</label>
              <input name="credential_other_details" class="form-control" placeholder="Other Details">
            </div>
          </div>
          <div class="row mb-3">
            <div class="col-md-12">
              <label class="form-label">Remarks</label>
              <input name="eligibility_remark" class="form-control" placeholder="Remarks">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save"></i> Save Student
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1">
  <div class="modal-dialog modal-lg" style="margin-top: 80px;">
    <div class="modal-content">
      <form method="POST" action="records.php" id="editStudentForm" style="display: flex; flex-direction: column; max-height: 85vh;">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Student</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="student_id" id="edit_student_form_id">
        <div class="modal-body" style="overflow-y: auto; flex: 1;">
          <div class="row">
            <div class="col-md-6">
              <h6 class="text-muted mb-3">Basic Information</h6>
              <div class="mb-3">
                <label class="form-label">LRN <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="lrn" id="edit_lrn" pattern="[0-9]{12}" maxlength="12" inputmode="numeric" title="LRN must be 12 digits" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="last_name" id="edit_last_name" required>
              </div>
              <div class="mb-3">
                <label class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="first_name" id="edit_first_name" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name" id="edit_middle_name">
              </div>
              <div class="mb-3">
                <label class="form-label">Suffix</label>
                <input type="text" class="form-control" name="suffix" id="edit_suffix" placeholder="Jr., Sr., III, etc.">
              </div>
              <div class="mb-3">
                <label class="form-label">Gender <span class="text-danger">*</span></label>
                <select class="form-select" name="gender" id="edit_gender" required>
                  <option value="">Select Gender</option>
                  <option value="Male">Male</option>
                  <option value="Female">Female</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Birthdate <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="birthdate" id="edit_birthdate" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Others (Pls. Specify):</label>
                <input name="credential_other_details" class="form-control" id="edit_credential_other_details" placeholder="Other Details">
              </div>
            </div>
            <div class="col-md-6">
              <h6 class="text-muted mb-3">Eligibility for Elementary Enrollment</h6>
              <div class="mb-3">
                <label class="form-label">Credential Presented</label>
                <input type="text" class="form-control" name="credential_presented" id="edit_credential">
              </div>
              <div class="mb-3">
                <label class="form-label">School Name</label>
                <input type="text" class="form-control" name="eligibility_school_name" id="edit_elig_school_name">
              </div>
              <div class="mb-3">
                <label class="form-label">School ID</label>
                <input type="text" class="form-control" name="eligibility_school_id" id="edit_elig_school_id">
              </div>
              <div class="mb-3">
                <label class="form-label">School Address</label>
                <input type="text" class="form-control" name="eligibility_school_address" id="edit_elig_school_address">
              </div>
              <div class="mb-3">
                <label class="form-label">PEPT Rating</label>
                <input type="text" class="form-control" name="pept_rating" id="edit_pept_rating">
              </div>
              <div class="mb-3">
                <label class="form-label">Exam Date</label>
                <input type="date" class="form-control" name="pept_exam_date" id="edit_pept_date">
              </div>
              <div class="mb-3">
                <label class="form-label">Testing Center</label>
                <input type="text" class="form-control" name="pept_testing_center" id="edit_pept_center">
              </div>
              <div class="mb-3">
                <label class="form-label">Remarks</label>
                <input name="eligibility_remark" class="form-control" id="edit_eligibility_remark" placeholder="Remarks">
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteStudentModal" tabindex="-1" style="margin-top: 80px;">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Confirm Delete</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete student <strong id="delete_student_name"></strong>?</p>
        <p class="text-danger"><i class="bi bi-info-circle"></i> This action cannot be undone. All associated records will be deleted.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete Student</button>
      </div>
    </div>
  </div>
</div>

<!-- View School Record Modal -->
<div class="modal fade" id="viewSchoolRecordModal" tabindex="-1" style="margin-top: 80px;">
  <div class="modal-dialog modal-lg">
    <div class="modal-content" style="max-height: 85vh; display: flex; flex-direction: column;">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-eye"></i> View School Attendance Record</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="overflow-y: auto; flex: 1; padding: 1.5rem;">
        <div class="row mb-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">Student Name:</label>
            <p id="view_student_name_text"></p>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-bold">LRN:</label>
            <p id="view_lrn_text"></p>
          </div>
        </div>
        <hr>
        <div class="row mb-3">
          <div class="col-md-6">
            <label class="form-label fw-bold">School Name:</label>
            <p id="view_school_name_text"></p>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-bold">School ID:</label>
            <p id="view_school_id_text"></p>
          </div>
        </div>
        <div class="row mb-3">
          <div class="col-md-4">
            <label class="form-label fw-bold">District:</label>
            <p id="view_district_text"></p>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-bold">Division:</label>
            <p id="view_division_text"></p>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-bold">Region:</label>
            <p id="view_region_text"></p>
          </div>
        </div>
        <hr>
        <div class="row mb-3">
          <div class="col-md-3">
            <label class="form-label fw-bold">Grade Level:</label>
            <p id="view_grade_level_text"></p>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-bold">Section:</label>
            <p id="view_section_text"></p>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-bold">School Year:</label>
            <p id="view_school_year_text"></p>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-bold">Adviser:</label>
            <p id="view_adviser_text"></p>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Add School Record Modal -->
<div class="modal fade" id="addSchoolRecordModal" tabindex="-1">
  <div class="modal-dialog modal-lg" style="margin-top: 80px;">
    <div class="modal-content">
      <form method="POST" action="records.php" style="display: flex; flex-direction: column; max-height: 85vh;">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add School Attended Record</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <input type="hidden" name="action" value="add_school_record">
        <input type="hidden" name="from_records" value="1">
        <div class="modal-body" style="overflow-y: auto; flex: 1;">
          <div class="row mb-3">
            <div class="col-md-12">
              <label class="form-label">Student <span class="text-danger">*</span></label>
              <input type="text" id="addStudentDisplay" class="form-control" readonly style="display: none;">
              <select name="student_id" id="addStudentSelect" class="form-select" required>
                <option value="">-- Select Student --</option>
                <?php
                $students_list_add = $conn->query("SELECT id, lrn, first_name, last_name FROM students ORDER BY last_name");
                while($s = $students_list_add->fetch_assoc()) {
                    echo "<option value='{$s['id']}'>{$s['last_name']}, {$s['first_name']} (LRN: {$s['lrn']})</option>";
                }
                ?>
              </select>
            </div>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label">School Name <span class="text-danger">*</span></label>
              <input type="text" name="school_name" class="form-control" placeholder="School Name">
            </div>
            <div class="col-md-6">
              <label class="form-label">School ID</label>
              <input type="text" name="school_id" class="form-control" placeholder="School ID">
            </div>
          </div>
          
          <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> <strong>Note:</strong> Leave District, Division, and Region empty to use your profile's default school information.
          </div>
          
          <div class="row mb-3">
            <div class="col-md-4">
              <label class="form-label">District <span class="text-muted">(Optional)</span></label>
              <input type="text" name="district" class="form-control" placeholder="Leave empty for default">
            </div>
            <div class="col-md-4">
              <label class="form-label">Division <span class="text-muted">(Optional)</span></label>
              <input type="text" name="division" class="form-control" placeholder="Leave empty for default">
            </div>
            <div class="col-md-4">
              <label class="form-label">Region <span class="text-muted">(Optional)</span></label>
              <input type="text" name="region" class="form-control" placeholder="Leave empty for default">
            </div>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-2">
              <label class="form-label">Grade Level <span class="text-danger">*</span></label>
              <input type="text" id="addGradeLevelDisplay" class="form-control" readonly style="display: none;">
              <select name="grade_level" id="addGradeLevel" class="form-select" required onchange="loadSectionsForGrade(this.value)">
                <option value="">-- Grade Level --</option>
                <option value="1">Grade 1</option>
                <option value="2">Grade 2</option>
                <option value="3">Grade 3</option>
                <option value="4">Grade 4</option>
                <option value="5">Grade 5</option>
                <option value="6">Grade 6</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Section <span class="text-danger">*</span></label>
              <input type="text" name="section" id="addSection" class="form-control" placeholder="Select grade first" list="sectionsList" autocomplete="off" required>
              <datalist id="sectionsList">
                <!-- Sections will be populated by JavaScript -->
              </datalist>
              <small class="text-muted" id="sectionLoadingText" style="display: none;">Loading sections...</small>
            </div>
            <div class="col-md-2">
              <label class="form-label">School Year<br/>(From) <span class="text-danger">*</span></label>
              <input type="text" name="school_year_from" class="form-control" placeholder="2025" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">School Year<br/>(To) <span class="text-danger">*</span></label>
              <input type="text" name="school_year_to" class="form-control" placeholder="2026" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Adviser Name</label>
              <input type="text" name="adviser_name" class="form-control" placeholder="Adviser Name">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" <?php if (!($is_admin ?? false)) echo 'disabled title="Only administrators can add records"'; ?>>Add Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit School Record Modal -->
<div class="modal fade" id="editSchoolRecordModal" tabindex="-1">
  <div class="modal-dialog modal-lg" style="margin-top: 80px;">
    <div class="modal-content">
      <form method="POST" action="records.php" style="display: flex; flex-direction: column; max-height: 85vh;">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit School Attended Record</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <input type="hidden" name="action" value="edit_school_record">
        <input type="hidden" name="record_id" id="edit_record_id">
        <input type="hidden" name="from_records" value="1">
        <input type="hidden" name="student_id" id="edit_student_id_hidden">
        <div class="modal-body" style="overflow-y: auto; flex: 1;">
          <div class="row mb-3">
            <div class="col-md-12">
              <label class="form-label">Student <span class="text-danger">*</span></label>
              <input type="text" id="edit_student_display" class="form-control" readonly>
            </div>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label">School Name <span class="text-danger">*</span></label>
              <input type="text" name="school_name" id="edit_school_name" class="form-control" placeholder="School Name">
            </div>
            <div class="col-md-6">
              <label class="form-label">School ID</label>
              <input type="text" name="school_id" id="edit_school_id" class="form-control" placeholder="School ID">
            </div>
          </div>
          
          <div class="alert alert-info">
            <i class="bi bi-info-circle"></i> <strong>Note:</strong> Leave District, Division, and Region empty to use your profile's default school information.
          </div>
          
          <div class="row mb-3">
            <div class="col-md-4">
              <label class="form-label">District <span class="text-muted">(Optional)</span></label>
              <input type="text" name="district" id="edit_district" class="form-control" placeholder="Leave empty for default">
            </div>
            <div class="col-md-4">
              <label class="form-label">Division <span class="text-muted">(Optional)</span></label>
              <input type="text" name="division" id="edit_division" class="form-control" placeholder="Leave empty for default">
            </div>
            <div class="col-md-4">
              <label class="form-label">Region <span class="text-muted">(Optional)</span></label>
              <input type="text" name="region" id="edit_region" class="form-control" placeholder="Leave empty for default">
            </div>
          </div>
          
          <div class="row mb-3">
            <div class="col-md-2">
              <label class="form-label">Grade Level <span class="text-danger">*</span></label>
              <input type="text" id="edit_grade_level_display" class="form-control" readonly>
              <input type="hidden" name="grade_level" id="edit_grade_level">
            </div>
            <div class="col-md-2">
              <label class="form-label">Section <span class="text-danger">*</span></label>
              <input type="text" name="section" id="edit_section" class="form-control" placeholder="Section" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">School Year<br/>(From) <span class="text-danger">*</span></label>
              <input type="text" name="school_year_from" id="edit_school_year_from" class="form-control" placeholder="2025" required>
            </div>
            <div class="col-md-2">
              <label class="form-label">School Year<br/>(To) <span class="text-danger">*</span></label>
              <input type="text" name="school_year_to" id="edit_school_year_to" class="form-control" placeholder="2026" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Adviser Name</label>
              <input type="text" name="adviser_name" id="edit_adviser_name" class="form-control" placeholder="Adviser Name">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete School Record Modal -->
<div class="modal fade" id="deleteSchoolRecordModal" tabindex="-1" style="margin-top: 80px;">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Confirm Delete</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete this school attendance record?</p>
        <p class="text-danger"><i class="bi bi-info-circle"></i> This action cannot be undone.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteRecordBtn">Delete Record</button>
      </div>
    </div>
  </div>
</div>

<!-- Grade Progression Modal -->
<div class="modal fade" id="gradeProgressionModal" tabindex="-1" style="margin-top: 80px;">
  <div class="modal-dialog modal-xl">
    <div class="modal-content" style="max-height: 85vh; display: flex; flex-direction: column;">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-bar-chart-steps"></i> Grade Progression</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="overflow-y: auto; flex: 1; will-change: scroll-position;">
        <!-- Student Info Header -->
        <div class="card mb-3">
          <div class="card-body">
            <div class="row">
              <div class="col-md-4">
                <strong>Student Name:</strong>
                <p id="progression_student_name" class="mb-0"></p>
              </div>
              <div class="col-md-2">
                <strong>LRN:</strong>
                <p id="progression_student_lrn" class="mb-0"></p>
              </div>
              <div class="col-md-2">
                <strong>Gender:</strong>
                <p id="progression_student_gender" class="mb-0"></p>
              </div>
              <div class="col-md-4">
                <strong>Birthdate:</strong>
                <p id="progression_student_birthdate" class="mb-0"></p>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Grade Progression Cards -->
        <h6 class="mb-3"><i class="bi bi-ladder"></i> Elementary School Progress (Grade 1-6)</h6>
        <div id="grade_progression_container">
          <!-- Grade cards will be populated here -->
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
let deleteStudentId = null;
const isAdmin = <?= json_encode($is_admin) ?>;
let currentStudentId = null;

// Open Add Student Modal
function openAddStudentModal() {
  new bootstrap.Modal(document.getElementById('addStudentModal')).show();
}

// Quarter Lock Functions
function openQuarterLockModal() {
  if (!currentStudentId) {
    alert('Please select a student first by clicking \"View Details\" on a student record.');
    return;
  }
  
  document.getElementById('lock_student_id').value = currentStudentId;
  loadQuarterLocks(currentStudentId);
  
  const modal = new bootstrap.Modal(document.getElementById('quarterLockModal'));
  modal.show();
}

function loadQuarterLocks(studentId) {
  fetch(`records.php?action=get_all_quarter_locks&student_id=${studentId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        // Update lock toggles
        for (let q = 1; q <= 4; q++) {
          const checkbox = document.getElementById(`lockQ${q}`);
          const label = document.getElementById(`lockQ${q}Label`);
          const isLocked = data.locks[`q${q}`] || false;
          
          checkbox.checked = isLocked;
          label.textContent = isLocked ? 'Locked' : 'Unlocked';
          label.className = isLocked ? 'text-danger fw-bold' : '';
        }
        
        // Update auto-lock times
        for (let q = 1; q <= 4; q++) {
          const lockInput = document.getElementById(`autoLockQ${q}`);
          if (data.auto_locks && data.auto_locks[`q${q}`]) {
            lockInput.value = data.auto_locks[`q${q}`].replace(' ', 'T');
          } else {
            lockInput.value = '';
          }
          
          // Update auto-unlock times
          const unlockInput = document.getElementById(`autoUnlockQ${q}`);
          if (data.auto_unlocks && data.auto_unlocks[`q${q}`]) {
            unlockInput.value = data.auto_unlocks[`q${q}`].replace(' ', 'T');
          } else {
            unlockInput.value = '';
          }
        }
      }
    })
    .catch(error => console.error('Error loading quarter locks:', error));
}

function toggleQuarterLock(quarter) {
  const studentId = document.getElementById('lock_student_id').value;
  const checkbox = document.getElementById(`lockQ${quarter}`);
  const label = document.getElementById(`lockQ${quarter}Label`);
  const locked = checkbox.checked ? 1 : 0;
  
  const formData = new FormData();
  formData.append('action', 'toggle_all_quarter_locks');
  formData.append('student_id', studentId);
  formData.append('quarter', quarter);
  formData.append('locked', locked);
  
  fetch('records.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      label.textContent = locked ? 'Locked' : 'Unlocked';
      label.className = locked ? 'text-danger fw-bold' : '';
    } else {
      // Revert checkbox if failed
      checkbox.checked = !checkbox.checked;
      alert('Failed to update quarter lock');
    }
  })
  .catch(error => {
    console.error('Error toggling quarter lock:', error);
    checkbox.checked = !checkbox.checked;
    alert('Failed to update quarter lock');
  });
}

function setAutoLockTime(quarter) {
  const studentId = document.getElementById('lock_student_id').value;
  const input = document.getElementById(`autoLockQ${quarter}`);
  const autoLockTime = input.value.replace('T', ' ');
  
  const formData = new FormData();
  formData.append('action', 'set_auto_lock_time');
  formData.append('student_id', studentId);
  formData.append('quarter', quarter);
  formData.append('auto_lock_time', autoLockTime);
  
  fetch('records.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      // Show success feedback
      const card = input.closest('.card');
      card.style.backgroundColor = '#d4edda';
      setTimeout(() => {
        card.style.backgroundColor = '';
      }, 1000);
    } else {
      alert('Failed to set auto-lock time');
    }
  })
  .catch(error => {
    console.error('Error setting auto-lock time:', error);
    alert('Failed to set auto-lock time');
  });
}

function clearAutoLock(quarter) {
  const input = document.getElementById(`autoLockQ${quarter}`);
  input.value = '';
  setAutoLockTime(quarter);
}

function setAutoUnlockTime(quarter) {
  const studentId = document.getElementById('lock_student_id').value;
  const input = document.getElementById(`autoUnlockQ${quarter}`);
  const autoUnlockTime = input.value.replace('T', ' ');
  
  const formData = new FormData();
  formData.append('action', 'set_auto_unlock_time');
  formData.append('student_id', studentId);
  formData.append('quarter', quarter);
  formData.append('auto_unlock_time', autoUnlockTime);
  
  fetch('records.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      // Show success feedback
      const card = input.closest('.card');
      card.style.backgroundColor = '#d4edda';
      setTimeout(() => {
        card.style.backgroundColor = '';
      }, 1000);
    } else {
      alert('Failed to set auto-unlock time');
    }
  })
  .catch(error => {
    console.error('Error setting auto-unlock time:', error);
    alert('Failed to set auto-unlock time');
  });
}

function clearAutoUnlock(quarter) {
  const input = document.getElementById(`autoUnlockQ${quarter}`);
  input.value = '';
  setAutoUnlockTime(quarter);
}

// Delete Student
function deleteStudent(id, name) {
  deleteStudentId = id;
  document.getElementById('delete_student_name').textContent = name;
  new bootstrap.Modal(document.getElementById('deleteStudentModal')).show();
}

document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
  if (deleteStudentId) {
    fetch(`records.php?action=delete&id=${deleteStudentId}`)
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          location.reload();
        } else {
          alert('Error: ' + (data.message || 'Failed to delete student'));
        }
      });
  }
});

function viewStudent(id) {
  fetch(`records.php?action=get_student&id=${id}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const s = data.student;
        document.getElementById('view_lrn').textContent = s.lrn || '-';
        document.getElementById('view_last_name').textContent = s.last_name || '-';
        document.getElementById('view_first_name').textContent = s.first_name || '-';
        document.getElementById('view_middle_name').textContent = s.middle_name || '-';
        document.getElementById('view_suffix').textContent = s.suffix || '-';
        document.getElementById('view_gender').textContent = s.gender || '-';
        document.getElementById('view_birthdate').textContent = s.birthdate || '-';
        document.getElementById('view_credential').textContent = s.credential_presented || '-';
        document.getElementById('view_elig_school_name').textContent = s.eligibility_school_name || '-';
        document.getElementById('view_elig_school_id').textContent = s.eligibility_school_id || '-';
        document.getElementById('view_elig_school_address').textContent = s.eligibility_school_address || '-';
        document.getElementById('view_pept_rating').textContent = s.pept_rating || '-';
        document.getElementById('view_pept_date').textContent = s.pept_exam_date || '-';
        document.getElementById('view_pept_center').textContent = s.pept_testing_center || '-';
        document.getElementById('view_credential_other_details').textContent = s.credential_other_details || '-';
        document.getElementById('view_eligibility_remark').textContent = s.eligibility_remark || '-';
        
        new bootstrap.Modal(document.getElementById('viewStudentModal')).show();
      }
    });
}

function editStudent(id) {
  fetch(`records.php?action=get_student&id=${id}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const s = data.student;
        document.getElementById('edit_student_form_id').value = s.id;
        document.getElementById('edit_lrn').value = s.lrn || '';
        document.getElementById('edit_last_name').value = s.last_name || '';
        document.getElementById('edit_first_name').value = s.first_name || '';
        document.getElementById('edit_middle_name').value = s.middle_name || '';
        document.getElementById('edit_suffix').value = s.suffix || '';
        document.getElementById('edit_gender').value = s.gender || '';
        document.getElementById('edit_birthdate').value = s.birthdate || '';
        document.getElementById('edit_credential').value = s.credential_presented || '';
        document.getElementById('edit_elig_school_name').value = s.eligibility_school_name || '';
        document.getElementById('edit_elig_school_id').value = s.eligibility_school_id || '';
        document.getElementById('edit_elig_school_address').value = s.eligibility_school_address || '';
        document.getElementById('edit_pept_rating').value = s.pept_rating || '';
        document.getElementById('edit_pept_date').value = s.pept_exam_date || '';
        document.getElementById('edit_pept_center').value = s.pept_testing_center || '';
        document.getElementById('edit_credential_other_details').value = s.credential_other_details || '';
        document.getElementById('edit_eligibility_remark').value = s.eligibility_remark || '';
        
        new bootstrap.Modal(document.getElementById('editStudentModal')).show();
      }
    });
}

// View Grade Progression (for newly created students)
function viewGradeProgression(studentId) {
  // Store current student ID
  currentStudentId = studentId;
  
  fetch(`records.php?action=get_student_history&student_id=${studentId}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const student = data.student;
        const records = data.records;
        
        // Populate student info
        document.getElementById('progression_student_name').textContent = 
          `${student.first_name} ${student.middle_name || ''} ${student.last_name}`.trim().toUpperCase();
        document.getElementById('progression_student_lrn').textContent = student.lrn || '-';
        document.getElementById('progression_student_gender').textContent = student.gender || '-';
        document.getElementById('progression_student_birthdate').textContent = 
          student.birthdate ? new Date(student.birthdate).toLocaleDateString('en-US', { 
            year: 'numeric', month: 'long', day: 'numeric' 
          }) : '-';
        
        // Create grade progression cards (Grade 1-6)
        const container = document.getElementById('grade_progression_container');
        container.innerHTML = '';
        
        for (let grade = 1; grade <= 6; grade++) {
          // Find record for this grade
          const gradeRecord = records.find(r => parseInt(r.grade_level) === grade);
          
          const card = document.createElement('div');
          card.className = 'card mb-3';
          
          if (gradeRecord) {
            // Student has record for this grade
            const actionButtons = isAdmin ? `
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-primary" style="width: 130px;" onclick="viewSchoolRecordFromProgression(${gradeRecord.id})">
                  <i class="bi bi-eye"></i> View Details
                </button>
                <button class="btn btn-sm btn-warning" style="width: 130px;" onclick="editSchoolRecordFromProgression(${gradeRecord.id})">
                  <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="btn btn-sm btn-danger" style="width: 130px;" onclick="deleteSchoolRecordFromProgression(${gradeRecord.id}, ${studentId})">
                  <i class="bi bi-trash"></i> Delete
                </button>
              </div>
            ` : `
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-primary" style="width: 130px;" onclick="viewSchoolRecordFromProgression(${gradeRecord.id})">
                  <i class="bi bi-eye"></i> View Details
                </button>
              </div>
            `;
            
            card.innerHTML = `
              <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-check-circle-fill"></i> Grade ${grade}</strong>
                <span class="badge bg-light text-dark">${gradeRecord.school_year || '-'}</span>
              </div>
              <div class="card-body">
                <div class="row mb-3">
                  <div class="col-md-3">
                    <small class="text-muted">School Name:</small>
                    <p class="mb-0">${gradeRecord.school_name || '-'}</p>
                  </div>
                  <div class="col-md-2">
                    <small class="text-muted">Section:</small>
                    <p class="mb-0">${(gradeRecord.section || '-').toUpperCase()}</p>
                  </div>
                  <div class="col-md-2">
                    <small class="text-muted">District:</small>
                    <p class="mb-0">${gradeRecord.district || '-'}</p>
                  </div>
                  <div class="col-md-2">
                    <small class="text-muted">Division:</small>
                    <p class="mb-0">${gradeRecord.division || '-'}</p>
                  </div>
                  <div class="col-md-3">
                    <small class="text-muted">Adviser:</small>
                    <p class="mb-0">${(gradeRecord.adviser_name || '-').toUpperCase()}</p>
                  </div>
                </div>
                ${actionButtons}
              </div>
            `;
          } else {
            // Student doesn't have record for this grade - show "Add School Record" button
            card.innerHTML = `
              <div class="card-header bg-secondary text-white">
                <strong><i class="bi bi-dash-circle"></i> Grade ${grade}</strong>
              </div>
              <div class="card-body text-center">
                <p class="text-muted mb-3"><i class="bi bi-info-circle"></i> No record for this grade level</p>
                ${isAdmin ? `
                <button class="btn btn-primary btn-sm" onclick="addSchoolRecordForGrade(${studentId}, ${grade})">
                  <i class="bi bi-plus-circle"></i> Add School Record
                </button>
                ` : `
                <button class="btn btn-secondary btn-sm" disabled title="Only administrators can add school records">
                  <i class="bi bi-lock"></i> Admins Only
                </button>
                `}
              </div>
            `;
          }
          
          container.appendChild(card);
        }
        
        // Show modal
        new bootstrap.Modal(document.getElementById('gradeProgressionModal')).show();
      }
    })
    .catch(error => {
      console.error('Error fetching grade progression:', error);
      alert('Failed to load grade progression');
    });
}

// Load sections for selected grade level
function loadSectionsForGrade(gradeLevel) {
  console.log('Loading sections for grade:', gradeLevel);
  const sectionsList = document.getElementById('sectionsList');
  const sectionInput = document.getElementById('addSection');
  const loadingText = document.getElementById('sectionLoadingText');
  
  if (!sectionsList) {
    console.error('sectionsList datalist not found');
    return;
  }
  if (!gradeLevel) {
    console.log('No grade level selected');
    if (sectionInput) sectionInput.placeholder = 'Select grade first';
    return;
  }
  
  // Show loading indicator
  if (loadingText) loadingText.style.display = 'block';
  if (sectionInput) sectionInput.placeholder = 'Loading...';
  
  // Clear existing options
  sectionsList.innerHTML = '';
  
  // Fetch sections from database
  fetch(`records.php?action=get_sections&grade=${gradeLevel}`)
    .then(response => {
      console.log('Response status:', response.status);
      return response.json();
    })
    .then(data => {
      console.log('Received data:', data);
      // Hide loading indicator
      if (loadingText) loadingText.style.display = 'none';
      
      if (data.success && data.sections && data.sections.length > 0) {
        console.log('Adding', data.sections.length, 'sections to datalist');
        data.sections.forEach(section => {
          const option = document.createElement('option');
          option.value = section;
          sectionsList.appendChild(option);
        });
        console.log('Datalist now has', sectionsList.options.length, 'options');
        if (sectionInput) sectionInput.placeholder = 'Type or select section';
      } else {
        console.log('No sections found for grade', gradeLevel);
        if (sectionInput) sectionInput.placeholder = 'Type custom section';
      }
    })
    .catch(error => {
      console.error('Error loading sections:', error);
      if (loadingText) loadingText.style.display = 'none';
      if (sectionInput) sectionInput.placeholder = 'Type custom section';
    });
}

// Setup grade level change listener for add modal
function setupAddModalListeners() {
  const gradeSelect = document.getElementById('addGradeLevel');
  console.log('Setting up add modal listeners, gradeSelect:', gradeSelect);
  if (gradeSelect) {
    // Remove existing listener to avoid duplicates
    gradeSelect.removeEventListener('change', gradeChangeHandler);
    // Add new listener
    gradeSelect.addEventListener('change', gradeChangeHandler);
    console.log('Grade change listener attached to addGradeLevel');
  } else {
    console.error('addGradeLevel select not found');
  }
}

function gradeChangeHandler() {
  console.log('Grade changed in add modal to:', this.value);
  loadSectionsForGrade(this.value);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
  console.log('Page loaded, setting up add modal listeners');
  setupAddModalListeners();
  
  // Re-setup when modal is shown
  const addModal = document.getElementById('addSchoolRecordModal');
  console.log('Add modal element:', addModal);
  if (addModal) {
    addModal.addEventListener('shown.bs.modal', function() {
      console.log('Add School Attended Record modal shown, re-setting up listeners');
      setupAddModalListeners();
    });
    
    // Re-enable fields when modal is hidden (for normal use)
    addModal.addEventListener('hidden.bs.modal', function() {
      const studentSelect = document.getElementById('addStudentSelect');
      const studentDisplay = document.getElementById('addStudentDisplay');
      const gradeSelect = document.getElementById('addGradeLevel');
      const gradeDisplay = document.getElementById('addGradeLevelDisplay');
      
      if (studentSelect && studentDisplay) {
        // Show select, hide readonly input
        studentSelect.style.display = 'block';
        studentDisplay.style.display = 'none';
        studentSelect.value = '';
      }
      if (gradeSelect && gradeDisplay) {
        // Show select, hide readonly input
        gradeSelect.style.display = 'block';
        gradeDisplay.style.display = 'none';
        gradeSelect.value = '';
      }
    });
  }
});

// Open add school record modal from progression modal
function addSchoolRecordForGrade(studentId, grade) {
  // Pre-select the student and grade
  const studentSelect = document.getElementById('addStudentSelect');
  const studentDisplay = document.getElementById('addStudentDisplay');
  const gradeSelect = document.getElementById('addGradeLevel');
  const gradeDisplay = document.getElementById('addGradeLevelDisplay');
  
  if (studentSelect && studentDisplay) {
    // Get student name from the select option
    const selectedOption = studentSelect.querySelector(`option[value="${studentId}"]`);
    if (selectedOption) {
      studentDisplay.value = selectedOption.textContent;
    }
    studentSelect.value = studentId;
    // Hide select, show readonly input
    studentSelect.style.display = 'none';
    studentDisplay.style.display = 'block';
  }
  if (gradeSelect && gradeDisplay) {
    // Hide select, show readonly input
    gradeSelect.style.display = 'none';
    gradeDisplay.style.display = 'block';
    gradeDisplay.value = 'Grade ' + grade;
    gradeSelect.value = grade;
    // Load sections for the pre-selected grade
    loadSectionsForGrade(grade);
  }
  
  // Open the modal on top of progression modal
  const addModal = new bootstrap.Modal(document.getElementById('addSchoolRecordModal'));
  addModal.show();
  setTimeout(() => {
    document.getElementById('addSchoolRecordModal').style.zIndex = '1060';
    const backdrop = document.querySelector('.modal-backdrop.show:last-of-type');
    if (backdrop) backdrop.style.zIndex = '1059';
    
    // Setup listeners after modal is shown
    setupAddModalListeners();
  }, 10);
}

// View school record from progression modal
function viewSchoolRecordFromProgression(id) {
  fetch(`records.php?action=get_school_record&id=${id}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const r = data.record;
        
        // Fetch student name
        fetch(`records.php?action=get_student&id=${r.student_id}`)
          .then(response => response.json())
          .then(studentData => {
            if (studentData.success) {
              const s = studentData.student;
              document.getElementById('view_student_name_text').textContent = `${s.last_name}, ${s.first_name} ${s.middle_name || ''}`;
              document.getElementById('view_lrn_text').textContent = s.lrn || '-';
            }
          });
        
        document.getElementById('view_school_name_text').textContent = r.school_name || '-';
        document.getElementById('view_school_id_text').textContent = r.school_id || '-';
        document.getElementById('view_district_text').textContent = r.district || '-';
        document.getElementById('view_division_text').textContent = r.division || '-';
        document.getElementById('view_region_text').textContent = r.region || '-';
        document.getElementById('view_grade_level_text').textContent = r.grade_level || '-';
        document.getElementById('view_section_text').textContent = r.section || '-';
        document.getElementById('view_school_year_text').textContent = r.school_year || '-';
        document.getElementById('view_adviser_text').textContent = r.adviser_name || '-';
        
        const viewModal = new bootstrap.Modal(document.getElementById('viewSchoolRecordModal'));
        viewModal.show();
        setTimeout(() => {
          document.getElementById('viewSchoolRecordModal').style.zIndex = '1060';
          const backdrop = document.querySelector('.modal-backdrop.show:last-of-type');
          if (backdrop) backdrop.style.zIndex = '1059';
        }, 10);
      }
    });
}

// Edit school record from progression modal
function editSchoolRecordFromProgression(id) {
  fetch(`records.php?action=get_school_record&id=${id}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const r = data.record;
        
        // Set all values BEFORE opening modal
        document.getElementById('edit_record_id').value = r.id;
        
        // Set student (hidden field and display field)
        const studentHidden = document.getElementById('edit_student_id_hidden');
        const studentDisplay = document.getElementById('edit_student_display');
        if (studentHidden) {
          studentHidden.value = r.student_id;
        }
        if (studentDisplay && r.student_name) {
          studentDisplay.value = r.student_name;
        }
        
        document.getElementById('edit_school_name').value = r.school_name || '';
        document.getElementById('edit_school_id').value = r.school_id || '';
        document.getElementById('edit_district').value = r.district || '';
        document.getElementById('edit_division').value = r.division || '';
        document.getElementById('edit_region').value = r.region || '';
        
        const gradeSelect = document.getElementById('edit_grade_level');
        if (gradeSelect) {
          gradeSelect.value = r.grade_level || '';
        }
        
        const gradeDisplay = document.getElementById('edit_grade_level_display');
        if (gradeDisplay) {
          gradeDisplay.value = r.grade_level ? `Grade ${r.grade_level}` : '';
        }
        
        document.getElementById('edit_section').value = r.section || '';
        document.getElementById('edit_school_year_from').value = r.school_year ? r.school_year.split('-')[0] : '';
        document.getElementById('edit_school_year_to').value = r.school_year ? r.school_year.split('-')[1] : '';
        document.getElementById('edit_adviser_name').value = r.adviser_name || '';
        
        // Open the modal on top of progression modal
        const editModal = new bootstrap.Modal(document.getElementById('editSchoolRecordModal'));
        editModal.show();
        setTimeout(() => {
          document.getElementById('editSchoolRecordModal').style.zIndex = '1060';
          const backdrop = document.querySelector('.modal-backdrop.show:last-of-type');
          if (backdrop) backdrop.style.zIndex = '1059';
        }, 10);
      }
    });
}

// Delete school record from progression modal
let deleteRecordId = null;
function deleteSchoolRecordFromProgression(recordId, studentId) {
  deleteRecordId = recordId;
  const deleteModal = new bootstrap.Modal(document.getElementById('deleteSchoolRecordModal'));
  deleteModal.show();
  setTimeout(() => {
    document.getElementById('deleteSchoolRecordModal').style.zIndex = '1060';
    const backdrop = document.querySelector('.modal-backdrop.show:last-of-type');
    if (backdrop) backdrop.style.zIndex = '1059';
  }, 10);
  
  // Store studentId for refresh after delete
  document.getElementById('confirmDeleteRecordBtn').setAttribute('data-student-id', studentId);
}

document.getElementById('confirmDeleteRecordBtn').addEventListener('click', function() {
  if (deleteRecordId) {
    const studentId = this.getAttribute('data-student-id');
    fetch(`records.php?action=delete_school_record&id=${deleteRecordId}`)
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          const deleteModal = bootstrap.Modal.getInstance(document.getElementById('deleteSchoolRecordModal'));
          deleteModal.hide();
          
          setTimeout(() => {
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // Refresh the grade progression modal
            if (studentId) {
              viewGradeProgression(studentId);
            }
          }, 300);
        } else {
          alert('Error: ' + (data.message || 'Failed to delete record'));
        }
      })
      .catch(error => {
        console.error('Error deleting record:', error);
        alert('Failed to delete record');
      });
  }
});
</script>

<?php include '../templates/footer.php'; ?>
